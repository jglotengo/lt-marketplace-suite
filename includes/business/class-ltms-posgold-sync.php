<?php
/**
 * Sync Engine: PosGold → WooCommerce.
 *
 * Sincroniza los productos del catálogo PosGold de un vendor hacia su
 * catálogo de WooCommerce en el marketplace. Para cada producto PosGold:
 *
 * 1. Verifica si ya existe en WooCommerce (por SKU = código PosGold).
 * 2. Si no existe: crea un producto WC nuevo (tipo 'simple').
 * 3. Si existe: actualiza los campos sincronizados.
 * 4. Descarga la imagen del producto si existe URL.
 * 5. Mapea categorías PosGold (categoria/grupo/subgrupo) a categorías WC.
 * 6. Actualiza el stock desde la bodega configurada.
 *
 * @package LTMS
 * @version 2.9.31
 * @since 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_PosGold_Sync {

    /**
     * Meta key que marca un producto WC como sincronizado desde PosGold.
     */
    const SYNC_META_KEY = '_ltms_posgold_synced';

    /**
     * Meta key que guarda el código PosGold original del producto.
     */
    const CODE_META_KEY = '_ltms_posgold_code';

    /**
     * v2.9.72 P3-11: Cron hook para sync en background.
     */
    const CRON_HOOK = 'ltms_posgold_sync_cron';

    /**
     * v2.9.72 P3-11: Registra el cron hook.
     */
    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_sync' ] );
    }

    /**
     * v2.9.72 P3-11: Programa una sync en background via WP-Cron.
     * El vendor puede cerrar la pestaña y la sync continuará.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{success: bool, message: string}
     */
    public static function schedule_sync( int $vendor_id ): array {
        // Verificar que el vendor tenga credenciales configuradas.
        $creds = self::get_vendor_credentials( $vendor_id );
        if ( ! $creds['configured'] ) {
            return [
                'success' => false,
                'message' => __( 'No has configurado tus credenciales de PosGold.', 'ltms' ),
            ];
        }

        // Verificar si ya hay una sync en curso para este vendor.
        $in_progress = get_user_meta( $vendor_id, '_ltms_posgold_sync_in_progress', true );
        if ( $in_progress && ( time() - (int) $in_progress ) < 600 ) {
            return [
                'success' => false,
                'message' => __( 'Ya tienes una sincronización en curso. Espera a que termine.', 'ltms' ),
            ];
        }

        // Marcar como en progreso.
        update_user_meta( $vendor_id, '_ltms_posgold_sync_in_progress', time() );

        // Programar el cron event (single event, se ejecuta en próximo cron tick).
        wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $vendor_id ] );

        return [
            'success' => true,
            'message' => __( 'Sincronización programada. Recibirás una notificación cuando termine. Puedes cerrar esta página.', 'ltms' ),
        ];
    }

    /**
     * v2.9.72 P3-11: Ejecuta la sync programada por WP-Cron.
     * Este método se ejecuta en background, sin conexión al navegador del vendor.
     */
    public static function run_scheduled_sync( int $vendor_id ): void {
        // Aumentar tiempo límite.
        $max_exec = (int) ini_get( 'max_execution_time' );
        $desired = $max_exec > 0 ? max( 30, $max_exec - 5 ) : 600;
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( $desired );
        }

        // Ejecutar sync.
        $result = self::sync_vendor_products( $vendor_id );

        // Limpiar flag de en progreso.
        delete_user_meta( $vendor_id, '_ltms_posgold_sync_in_progress' );

        // Guardar resultado para que el vendor lo vea.
        update_user_meta( $vendor_id, '_ltms_posgold_sync_last_result', [
            'completed_at' => current_time( 'mysql', true ),
            'success'      => $result['success'] ?? false,
            'created'      => $result['created'] ?? 0,
            'updated'      => $result['updated'] ?? 0,
            'skipped'      => $result['skipped'] ?? 0,
            'errors'       => $result['errors'] ?? [],
            'message'      => $result['message'] ?? '',
        ] );

        // Enviar notificación al vendor.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'POSGOLD_SYNC_COMPLETE',
                sprintf( 'Vendor #%d sync completed: %d created, %d updated', $vendor_id, $result['created'] ?? 0, $result['updated'] ?? 0 )
            );
        }

        // Notificación in-dashboard.
        global $wpdb;
        $notifications_table = $wpdb->prefix . 'lt_notifications';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$notifications_table}'" ) === $notifications_table ) {
            $wpdb->insert(
                $notifications_table,
                [
                    'user_id'    => $vendor_id,
                    'type'       => 'posgold_sync',
                    'title'      => __( 'Sincronización PosGold completada', 'ltms' ),
                    'message'    => sprintf(
                        __( 'Sincronización completada: %d productos creados, %d actualizados, %d omitidos.', 'ltms' ),
                        $result['created'] ?? 0,
                        $result['updated'] ?? 0,
                        $result['skipped'] ?? 0
                    ),
                    'is_read'    => 0,
                    'created_at' => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%s' ]
            );
        }
    }

    /**
     * Sincroniza todos los productos de PosGold hacia WooCommerce para un vendor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{success: bool, message: string, created: int, updated: int, skipped: int, errors: array}
     */
    public static function sync_vendor_products( int $vendor_id ): array {
        // 1. Obtener credenciales del vendor.
        $creds = self::get_vendor_credentials( $vendor_id );
        if ( ! $creds['configured'] ) {
            return [
                'success' => false,
                'message' => __( 'No has configurado tus credenciales de PosGold. Ve a Configuración → PosGold.', 'ltms' ),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
            ];
        }

        // 2. Rate limit: máximo 1 sync cada 2 minutos por vendor.
        $last_sync = (int) get_user_meta( $vendor_id, 'ltms_posgold_last_sync', true );
        $min_interval = 2 * MINUTE_IN_SECONDS;
        if ( $last_sync && ( time() - $last_sync ) < $min_interval ) {
            $remaining = $min_interval - ( time() - $last_sync );
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d: segundos restantes */
                    __( 'Debes esperar %d segundos antes de sincronizar nuevamente.', 'ltms' ),
                    $remaining
                ),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
            ];
        }

        // 3. Llamar a la API de PosGold.
        $result = LTMS_Api_PosGold::get_products(
            $creds['subdomain'],
            $creds['token'],
            [
                'empresaid' => $creds['empresaid'],
                'usuarioid' => $creds['usuarioid'],
                'bodegaid'  => $creds['bodegaid'],
            ]
        );

        if ( ! $result['success'] ) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: mensaje de error */
                    __( 'Error al conectar con PosGold: %s', 'ltms' ),
                    $result['error']
                ),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [ $result['error'] ],
            ];
        }

        $products = $result['data'];
        if ( empty( $products ) ) {
            // Marcar sync aunque no haya productos.
            update_user_meta( $vendor_id, 'ltms_posgold_last_sync', time() );
            update_user_meta( $vendor_id, 'ltms_posgold_last_sync_count', 0 );

            return [
                'success' => true,
                'message' => __( 'No se encontraron productos en tu catálogo PosGold.', 'ltms' ),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
            ];
        }

        // 4. Normalizar todos los productos.
        $normalized = [];
        foreach ( $products as $raw_product ) {
            $normalized[] = LTMS_Api_PosGold::normalize_product( $raw_product );
        }

        // 5. Filtrar por categoriaid si el vendor configuró categorías.
        $category_filter = (string) get_user_meta( $vendor_id, 'ltms_posgold_category_ids', true );
        if ( ! empty( $category_filter ) && class_exists( 'LTMS_PosGold_Price_Calculator' ) ) {
            $before_count = count( $normalized );
            $normalized = LTMS_PosGold_Price_Calculator::filter_by_category( $normalized, $category_filter );
            $filtered_out = $before_count - count( $normalized );
        } else {
            $filtered_out = 0;
        }

        // 6. Depurar duplicados por SKU.
        $dedup_result = LTMS_PosGold_Price_Calculator::deduplicate_by_sku( $normalized );
        $normalized   = $dedup_result['unique'];
        $duplicates   = $dedup_result['duplicates'];

        // 7. Obtener reglas de precio del vendor.
        $price_rules = LTMS_PosGold_Price_Calculator::get_vendor_rules( $vendor_id );

        // 8. Obtener plantilla SEO del vendor.
        $seo_template = (string) get_user_meta( $vendor_id, 'ltms_posgold_seo_template', true );

        // 9. Sincronizar cada producto.
        $created         = 0;
        $updated         = 0;
        $skipped         = 0;
        $skipped_incomplete = 0;
        $errors          = [];

        foreach ( $normalized as $product ) {
            // 9a. Validar completitud.
            $validation = LTMS_PosGold_Price_Calculator::validate_product_completeness( $product );
            if ( ! $validation['complete'] ) {
                $skipped++;
                $skipped_incomplete++;
                continue;
            }

            // 9b. Calcular precio final con reglas del vendor.
            $price_calc = LTMS_PosGold_Price_Calculator::calculate( (float) $product['regular_price'], $price_rules );
            $product['regular_price'] = $price_calc['price'];

            // 9c. Generar título SEO.
            $product['name'] = LTMS_PosGold_Price_Calculator::generate_seo_title( $product, $seo_template );

            try {
                $sync_result = self::sync_single_product( $vendor_id, $product );
                if ( $sync_result === 'created' ) {
                    $created++;
                } elseif ( $sync_result === 'updated' ) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch ( \Throwable $e ) {
                $errors[] = sprintf(
                    'Producto código %s: %s',
                    $product['codigo'],
                    $e->getMessage()
                );
            }
        }

        // 10. Actualizar metadata de sync.
        update_user_meta( $vendor_id, 'ltms_posgold_last_sync', time() );
        update_user_meta( $vendor_id, 'ltms_posgold_last_sync_count', count( $normalized ) );

        // 11. Log.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'POSGOLD_SYNC',
                sprintf(
                    'Vendor #%d sync: %d created, %d updated, %d skipped (%d incomplete), %d duplicates, %d filtered, %d errors',
                    $vendor_id, $created, $updated, $skipped, $skipped_incomplete, count( $duplicates ), $filtered_out, count( $errors )
                ),
                [
                    'vendor_id'          => $vendor_id,
                    'total_raw'          => count( $products ),
                    'total_normalized'   => count( $normalized ),
                    'filtered_out'       => $filtered_out,
                    'duplicates'         => count( $duplicates ),
                    'created'            => $created,
                    'updated'            => $updated,
                    'skipped'            => $skipped,
                    'skipped_incomplete' => $skipped_incomplete,
                    'errors'             => $errors,
                ]
            );
        }

        // 12. Mensaje de resultado.
        $message_parts = [
            sprintf(
                /* translators: 1: created, 2: updated */
                __( '%1$d creados, %2$d actualizados', 'ltms' ),
                $created, $updated
            ),
        ];
        if ( $skipped > 0 ) {
            $message_parts[] = sprintf(
                /* translators: %d: skipped */
                __( '%d omitidos', 'ltms' ),
                $skipped
            );
        }
        if ( $duplicates ) {
            $message_parts[] = sprintf(
                /* translators: %d: duplicates */
                __( '%d duplicados', 'ltms' ),
                count( $duplicates )
            );
        }
        if ( $filtered_out > 0 ) {
            $message_parts[] = sprintf(
                /* translators: %d: filtered */
                __( '%d fuera de categoría', 'ltms' ),
                $filtered_out
            );
        }

        return [
            'success' => true,
            'message' => __( 'Sincronización completa: ', 'ltms' ) . implode( ', ', $message_parts ) . '.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'duplicates'   => count( $duplicates ),
            'filtered_out' => $filtered_out,
            'errors'  => $errors,
        ];
    }

    /**
     * Obtiene las credenciales PosGold de un vendor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{configured: bool, subdomain: string, token: string, empresaid: int, usuarioid: int, bodegaid: int}
     */
    public static function get_vendor_credentials( int $vendor_id ): array {
        $subdomain  = (string) get_user_meta( $vendor_id, 'ltms_posgold_subdomain', true );
        $token      = (string) get_user_meta( $vendor_id, 'ltms_posgold_token', true );
        $empresaid  = (int)    get_user_meta( $vendor_id, 'ltms_posgold_empresaid', true ) ?: 1;
        $usuarioid  = (int)    get_user_meta( $vendor_id, 'ltms_posgold_usuarioid', true ) ?: 1;
        $bodegaid   = (int)    get_user_meta( $vendor_id, 'ltms_posgold_bodegaid',  true ) ?: 1;

        // Desencriptar token si está cifrado.
        if ( $token && class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'decrypt' ) ) {
            $decrypted = LTMS_Core_Security::decrypt( $token );
            if ( $decrypted ) {
                $token = $decrypted;
            }
        }

        $configured = ! empty( $subdomain ) && ! empty( $token );

        return [
            'configured' => $configured,
            'subdomain'  => $subdomain,
            'token'      => $token,
            'empresaid'  => $empresaid,
            'usuarioid'  => $usuarioid,
            'bodegaid'   => $bodegaid,
        ];
    }

    /**
     * Sincroniza un solo producto PosGold hacia WooCommerce.
     *
     * @param int   $vendor_id ID del vendedor (autor del producto WC).
     * @param array $product   Producto normalizado de PosGold.
     * @return string 'created' | 'updated' | 'skipped'
     */
    private static function sync_single_product( int $vendor_id, array $product ): string {
        // 1. Buscar producto WC existente por SKU (código PosGold).
        $existing_id = wc_get_product_id_by_sku( $product['sku'] );

        if ( $existing_id ) {
            // Verificar que el producto pertenezca a este vendor.
            $post = get_post( $existing_id );
            if ( $post && (int) $post->post_author === $vendor_id ) {
                $wc_product = wc_get_product( $existing_id );
                self::update_product_fields( $wc_product, $product, $vendor_id );
                return 'updated';
            }
            // Si el SKU existe pero pertenece a otro vendor, omitir para no sobreescribir.
            return 'skipped';
        }

        // 2. Crear producto nuevo.
        self::create_product( $vendor_id, $product );
        return 'created';
    }

    /**
     * Crea un nuevo producto WooCommerce desde un producto PosGold.
     *
     * @param int   $vendor_id ID del vendedor.
     * @param array $product   Producto normalizado.
     * @return int ID del producto creado.
     */
    private static function create_product( int $vendor_id, array $product ): int {
        $wc_product = new \WC_Product_Simple();
        $wc_product->set_name( $product['name'] ?: 'Producto ' . $product['codigo'] );
        $wc_product->set_status( 'publish' );
        $wc_product->set_catalog_visibility( 'visible' );
        $wc_product->set_sku( $product['sku'] );
        $wc_product->set_regular_price( self::format_price( $product['regular_price'] ) );
        $wc_product->set_description( $product['descripcion'] ?? '' );
        $wc_product->set_short_description( $product['descripcion'] ?? '' );

        // Stock
        $wc_product->set_manage_stock( true );
        $wc_product->set_stock_quantity( $product['stock_quantity'] );
        $wc_product->set_stock_status( $product['stock_quantity'] > 0 ? 'instock' : 'outofstock' );

        // Barcode
        if ( ! empty( $product['barcode'] ) ) {
            $wc_product->set_global_unique_id( $product['barcode'] );
        }

        // Marcar como sincronizado desde PosGold.
        $wc_product->update_meta_data( self::SYNC_META_KEY, current_time( 'mysql', true ) );
        $wc_product->update_meta_data( self::CODE_META_KEY, $product['codigo'] );

        // Atributos (marca, modelo).
        self::set_product_attributes( $wc_product, $product );

        // Categorías.
        $category_ids = self::resolve_categories( $product );
        if ( ! empty( $category_ids ) ) {
            $wc_product->set_category_ids( $category_ids );
        }

        // El producto se asigna al vendor.
        $wc_product->save();

        // Asignar post_author al vendor (WooCommerce no lo hace por defecto).
        wp_update_post( [
            'ID'          => $wc_product->get_id(),
            'post_author' => $vendor_id,
        ] );

        // Descargar imagen (después de save para tener el ID).
        if ( ! empty( $product['imagen_url'] ) ) {
            self::download_and_attach_image( $product['imagen_url'], $wc_product->get_id() );
        }

        return $wc_product->get_id();
    }

    /**
     * Actualiza los campos de un producto WC existente con datos de PosGold.
     *
     * @param \WC_Product $wc_product Producto WC existente.
     * @param array       $product    Producto PosGold normalizado.
     * @param int         $vendor_id  ID del vendor (para verificación).
     */
    private static function update_product_fields( \WC_Product $wc_product, array $product, int $vendor_id ): void {
        // Solo actualizar productos marcados como sincronizados desde PosGold,
        // o productos sin la meta (por si se actualizan productos preexistentes).
        $is_synced = $wc_product->get_meta( self::SYNC_META_KEY );

        // Nombre
        if ( ! empty( $product['name'] ) ) {
            $wc_product->set_name( $product['name'] );
        }

        // Precio
        if ( $product['regular_price'] > 0 ) {
            $wc_product->set_regular_price( self::format_price( $product['regular_price'] ) );
        }

        // Descripción
        if ( ! empty( $product['descripcion'] ) ) {
            $wc_product->set_description( $product['descripcion'] );
        }

        // Stock
        $wc_product->set_manage_stock( true );
        $wc_product->set_stock_quantity( $product['stock_quantity'] );
        $wc_product->set_stock_status( $product['stock_quantity'] > 0 ? 'instock' : 'outofstock' );

        // Barcode
        if ( ! empty( $product['barcode'] ) ) {
            $wc_product->set_global_unique_id( $product['barcode'] );
        }

        // Marcar sync actualizado.
        $wc_product->update_meta_data( self::SYNC_META_KEY, current_time( 'mysql', true ) );
        $wc_product->update_meta_data( self::CODE_META_KEY, $product['codigo'] );

        // Atributos
        self::set_product_attributes( $wc_product, $product );

        // Categorías
        $category_ids = self::resolve_categories( $product );
        if ( ! empty( $category_ids ) ) {
            $wc_product->set_category_ids( $category_ids );
        }

        $wc_product->save();

        // Imagen: solo descargar si el producto no tiene imagen destacada.
        if ( ! empty( $product['imagen_url'] ) && ! has_post_thumbnail( $wc_product->get_id() ) ) {
            self::download_and_attach_image( $product['imagen_url'], $wc_product->get_id() );
        }
    }

    /**
     * Asigna atributos (marca, modelo) al producto WC.
     *
     * @param \WC_Product $wc_product Producto WC.
     * @param array       $product    Producto PosGold normalizado.
     */
    private static function set_product_attributes( \WC_Product $wc_product, array $product ): void {
        $attributes = [];

        if ( ! empty( $product['marca'] ) ) {
            $attr = new \WC_Product_Attribute();
            $attr->set_name( __( 'Marca', 'ltms' ) );
            $attr->set_options( [ $product['marca'] ] );
            $attr->set_position( 0 );
            $attr->set_visible( true );
            $attr->set_variation( false );
            $attributes[] = $attr;
        }

        if ( ! empty( $product['modelo'] ) ) {
            $attr = new \WC_Product_Attribute();
            $attr->set_name( __( 'Modelo', 'ltms' ) );
            $attr->set_options( [ $product['modelo'] ] );
            $attr->set_position( 1 );
            $attr->set_visible( true );
            $attr->set_variation( false );
            $attributes[] = $attr;
        }

        if ( ! empty( $attributes ) ) {
            $wc_product->set_attributes( $attributes );
        }
    }

    /**
     * Resuelve las categorías PosGold a IDs de categorías WooCommerce.
     *
     * Crea las categorías si no existen. Estructura jerárquica:
     *   Categoria > Grupo > Subgrupo
     *
     * @param array $product Producto PosGold normalizado.
     * @return array IDs de categorías WC.
     */
    private static function resolve_categories( array $product ): array {
        $cat_ids = [];

        // Categoría principal.
        if ( ! empty( $product['categoria'] ) ) {
            $cat_ids[] = self::get_or_create_category( $product['categoria'] );

            // Grupo (hijo de categoría).
            if ( ! empty( $product['grupo'] ) ) {
                $parent_id = end( $cat_ids );
                $cat_ids[] = self::get_or_create_category( $product['grupo'], $parent_id );

                // Subgrupo (hijo de grupo).
                if ( ! empty( $product['subgrupo'] ) ) {
                    $parent_id = end( $cat_ids );
                    $cat_ids[] = self::get_or_create_category( $product['subgrupo'], $parent_id );
                }
            }
        }

        return array_filter( $cat_ids );
    }

    /**
     * Obtiene o crea una categoría de WooCommerce por nombre y padre.
     *
     * @param string $name     Nombre de la categoría.
     * @param int    $parent_id ID de la categoría padre (0 para raíz).
     * @return int ID de la categoría.
     */
    private static function get_or_create_category( string $name, int $parent_id = 0 ): int {
        $slug = sanitize_title( $name );

        // Buscar categoría existente por slug + parent.
        $existing = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $existing && (int) $existing->parent === $parent_id ) {
            return (int) $existing->term_id;
        }

        // Crear categoría nueva.
        $result = wp_insert_term( $name, 'product_cat', [
            'slug'   => $slug . '-' . wp_rand( 100, 999 ),
            'parent' => $parent_id,
        ] );

        if ( is_wp_error( $result ) ) {
            return 0;
        }

        return (int) $result['term_id'];
    }

    /**
     * Descarga una imagen desde PosGold y la adjunta al producto WC.
     *
     * @param string $image_url   URL de la imagen en PosGold.
     * @param int    $product_id  ID del producto WC.
     * @return int|false Attachment ID o false si falla.
     */
    private static function download_and_attach_image( string $image_url, int $product_id ) {
        if ( empty( $image_url ) ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        // Determinar extensión y nombre del archivo.
        $url_path     = wp_parse_url( $image_url, PHP_URL_PATH );
        $file_name    = basename( $url_path ) ?: 'posgold-' . $product_id . '.jpg';
        $file_array   = [
            'name'     => sanitize_file_name( $file_name ),
            'tmp_name' => $tmp,
        ];

        // Validar tipo de archivo.
        $allowed_types = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
        $ext = strtolower( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_types, true ) ) {
            $file_array['name'] .= '.jpg';
        }

        $attachment_id = media_handle_sideload( $file_array, $product_id, 'Imagen PosGold producto #' . $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return false;
        }

        // Asignar como imagen destacada del producto.
        set_post_thumbnail( $product_id, $attachment_id );

        return (int) $attachment_id;
    }

    /**
     * Formatea un precio para WooCommerce (string con 2 decimales).
     *
     * @param float $price Precio.
     * @return string
     */
    private static function format_price( float $price ): string {
        return number_format( max( 0, $price ), 2, '.', '' );
    }
}
