<?php
/**
 * LTMS Business Sync Visibility Gate — Gate de vendibilidad pública
 *
 * SYNC-VIS-GATE (2026-09-28): regla de negocio del marketplace — un producto
 * sincronizado (PosGold/VTEX) o creado desde el panel del vendedor que NO
 * cumpla TODAS las condiciones de vendibilidad (stock disponible, imagen
 * destacada, precio > 0) NO debe aparecer en NINGUNA página pública (shop,
 * categorías, búsqueda, home, vitrina del vendedor, vista rápida, URL
 * directa), solo debe visualizarse en el panel del vendedor.
 *
 * Mecanismo:
 *  1. apply_gate() evalúa el producto y lo oculta vía catalog_visibility
 *     'hidden' (WC estándar lo excluye de catálogo + búsqueda en las queries
 *     principales) + meta _ltms_visibility_blocked con los motivos faltantes
 *     (diagnóstico/observabilidad).
 *  2. Cuando el producto vuelve a cumplir todo (la sync trae stock/imagen/
 *     precio), el gate lo restaura a 'visible' SOLO si lo ocultó el propio
 *     gate (meta presente) — nunca sobreescribe un ocultamiento manual de
 *     un producto vendible.
 *  3. Hooks woocommerce_new_product + woocommerce_update_product → el gate
 *     se re-aplica en cada guardado (sync, panel del vendedor, órdenes que
 *     reducen stock, edición admin). Los syncs además llaman apply_gate()
 *     explícito después de descargar la imagen, porque la imagen se adjunta
 *     POST-save y no dispara hooks de producto.
 *  4. guard_single_product(): la URL directa de un producto no vendible
 *     redirige a la tienda (302) para cualquier visitante que no sea el
 *     vendedor dueño o un gestor (manage_woocommerce).
 *  5. visibility_exclude_clause(): cláusula tax_query para queries WP_Query
 *     custom (vitrina del vendedor) que no pasan por WC_Query.
 *  6. sweep_all(): backfill idempotente para productos existentes.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Business_Sync_Visibility_Gate {

    /** Meta que marca productos ocultados POR EL GATE (JSON con motivos). */
    const BLOCKED_META_KEY = '_ltms_visibility_blocked';

    /** Flag anti-recursión por product_id (apply_gate hace save() → dispara el hook). */
    private static array $in_progress = [];

    public static function init(): void {
        add_action( 'woocommerce_new_product', [ self::class, 'apply_gate' ], 20, 1 );
        add_action( 'woocommerce_update_product', [ self::class, 'apply_gate' ], 20, 1 );
        add_action( 'template_redirect', [ self::class, 'guard_single_product' ] );
    }

    /**
     * Condiciones de vendibilidad faltantes del producto.
     *
     * @param \WC_Product $product Producto WC.
     * @return string[] Subconjunto de ['stock', 'image', 'price'].
     */
    public static function missing_conditions( $product ): array {
        $missing = [];
        if ( ! $product || ! method_exists( $product, 'is_in_stock' ) ) {
            return $missing;
        }

        // Stock: agotado (y sin backorders activos — paridad con la barra de
        // stock del single-product.php, que muestra "Disponible bajo pedido").
        $backordered = method_exists( $product, 'get_backorders' )
            && in_array( $product->get_backorders(), [ 'yes', 'notify' ], true );
        if ( ! $product->is_in_stock() && ! $backordered ) {
            $missing[] = 'stock';
        }

        // Imagen: sin imagen destacada (los cards públicos muestran placeholder).
        $image_id = method_exists( $product, 'get_image_id' ) ? $product->get_image_id() : '';
        if ( empty( $image_id ) ) {
            $missing[] = 'image';
        }

        // Precio: vacío o <= 0 (get_price() devuelve '' cuando no hay precio).
        $price = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
        if ( '' === (string) $price || (float) $price <= 0 ) {
            $missing[] = 'price';
        }

        return $missing;
    }

    /**
     * ¿El producto es invendible (falta stock, imagen o precio)?
     *
     * @param \WC_Product $product Producto WC.
     * @return bool
     */
    public static function is_unsellable( $product ): bool {
        return ! empty( self::missing_conditions( $product ) );
    }

    /**
     * Aplica el gate sobre un producto.
     *
     * - Faltan condiciones + publish → oculta (visibility 'hidden' + meta motivos).
     * - Faltan condiciones + ya oculto → refresca motivos si cambiaron (sin re-ocultar).
     * - Cumple todo + lo ocultó el gate → restaura 'visible' + borra meta.
     * - Cumple todo + nunca oculto por el gate → sin cambios (respeta ocultamiento manual).
     *
     * Idempotente y anti-recursivo: el save() interno dispara el hook
     * woocommerce_update_product, que re-entra aquí — el flag $in_progress
     * corta la recursión.
     *
     * @param int $product_id ID del producto.
     * @return string 'hidden' | 'restored' | 'unchanged'
     */
    public static function apply_gate( $product_id ): string {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 || isset( self::$in_progress[ $product_id ] ) ) {
            return 'unchanged';
        }
        $product = ( function_exists( 'wc_get_product' ) ) ? wc_get_product( $product_id ) : null;
        if ( ! $product || ! method_exists( $product, 'get_catalog_visibility' ) ) {
            return 'unchanged';
        }

        self::$in_progress[ $product_id ] = true;
        try {
            $missing     = self::missing_conditions( $product );
            $was_blocked = ! empty( $product->get_meta( self::BLOCKED_META_KEY ) );

            if ( ! empty( $missing ) && 'publish' === $product->get_status() ) {
                $reasons = wp_json_encode( array_values( $missing ) );

                if ( 'hidden' !== $product->get_catalog_visibility() ) {
                    $product->update_meta_data( self::BLOCKED_META_KEY, $reasons );
                    $product->set_catalog_visibility( 'hidden' );
                    $product->save();
                    LTMS_Core_Logger::info(
                        'SYNC-VIS-GATE',
                        sprintf( 'Producto %d ocultado (faltan: %s)', $product_id, implode( ', ', $missing ) )
                    );
                    return 'hidden';
                }

                // Ya oculto — refrescar motivos si cambiaron (ej. imagen llegó pero falta precio).
                if ( $reasons !== (string) $product->get_meta( self::BLOCKED_META_KEY ) ) {
                    $product->update_meta_data( self::BLOCKED_META_KEY, $reasons );
                    $product->save();
                }
                return 'unchanged';
            }

            if ( empty( $missing ) && $was_blocked ) {
                $product->delete_meta_data( self::BLOCKED_META_KEY );
                $product->set_catalog_visibility( 'visible' );
                $product->save();
                LTMS_Core_Logger::info(
                    'SYNC-VIS-GATE',
                    sprintf( 'Producto %d restaurado a visible (cumple stock/imagen/precio)', $product_id )
                );
                return 'restored';
            }

            return 'unchanged';
        } finally {
            unset( self::$in_progress[ $product_id ] );
        }
    }

    /**
     * ¿El producto está bloqueado PARA ESTE USUARIO en páginas públicas?
     *
     * Lógica pura testeable: el gate bloquea productos invendibles para todos,
     * EXCEPTO el vendedor dueño y los gestores (manage_woocommerce).
     *
     * @param \WC_Product $product         Producto WC (o null).
     * @param int         $user_id         ID del usuario actual (0 = guest).
     * @param bool        $user_is_manager ¿El usuario tiene manage_woocommerce?
     * @return bool
     */
    public static function is_blocked_for_user( $product, int $user_id = 0, bool $user_is_manager = false ): bool {
        if ( ! $product || ! self::is_unsellable( $product ) ) {
            return false;
        }
        if ( $user_is_manager ) {
            return false;
        }
        $owner_id = 0;
        if ( function_exists( 'get_post_field' ) ) {
            $owner_id = (int) get_post_field( 'post_author', $product->get_id() );
        }
        if ( $owner_id > 0 && $owner_id === $user_id ) {
            return false;
        }
        return true;
    }

    /**
     * Guard frontend: la URL directa de un producto no vendible redirige a la
     * tienda (302) para cualquier visitante que no sea el dueño o un gestor.
     * El vendedor sigue pudiendo ver su propio producto por URL; el listado
     * completo de sus productos vive en el panel.
     *
     * @return void
     */
    public static function guard_single_product(): void {
        if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'is_singular' ) ) {
            return;
        }
        if ( ! is_singular( 'product' ) ) {
            return;
        }
        $user_is_manager = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
        if ( $user_is_manager ) {
            return;
        }
        $product_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
        if ( $product_id <= 0 ) {
            return;
        }
        $product = wc_get_product( $product_id );
        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( ! self::is_blocked_for_user( $product, $user_id, $user_is_manager ) ) {
            return;
        }
        $shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
        if ( ! $shop_url ) {
            $shop_url = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
        }
        if ( function_exists( 'wp_safe_redirect' ) ) {
            wp_safe_redirect( $shop_url, 302 );
        }
        exit;
    }

    /**
     * Cláusula tax_query que excluye productos ocultados por el gate (o
     * manualmente) del catálogo. Para queries WP_Query custom (vitrina del
     * vendedor) que no pasan por WC_Query.
     *
     * @return array Cláusula lista para agregar a tax_query ([] si WC no está).
     */
    public static function visibility_exclude_clause(): array {
        if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
            return [];
        }
        $term_ids = wc_get_product_visibility_term_ids();
        $term_id  = (int) ( $term_ids['exclude-from-catalog'] ?? 0 );
        if ( $term_id <= 0 ) {
            return [];
        }
        return [
            'taxonomy' => 'product_visibility',
            'field'    => 'term_taxonomy_id',
            'terms'    => $term_id,
            'operator' => 'NOT IN',
        ];
    }

    /**
     * Barrido masivo: aplica el gate a todos los productos publicados.
     *
     * Para backfill de productos existentes (tras deploy) — vía wp eval o
     * WP-Cron. Idempotente: productos ya evaluados sin cambios → sin save.
     *
     * @param int $batch_size Límite del query (0 = sin límite).
     * @return array{hidden:int, restored:int, unchanged:int}
     */
    public static function sweep_all( int $batch_size = 0 ): array {
        $result = [ 'hidden' => 0, 'restored' => 0, 'unchanged' => 0 ];
        if ( ! function_exists( 'wc_get_products' ) ) {
            return $result;
        }
        $args = [
            'status' => 'publish',
            'limit'  => $batch_size > 0 ? $batch_size : -1,
            'return' => 'ids',
        ];
        foreach ( (array) wc_get_products( $args ) as $pid ) {
            $outcome = self::apply_gate( (int) $pid );
            if ( isset( $result[ $outcome ] ) ) {
                $result[ $outcome ]++;
            }
        }
        return $result;
    }
}
