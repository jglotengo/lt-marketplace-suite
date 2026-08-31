<?php
/**
 * Sync Engine: VTEX → WooCommerce.
 *
 * Sincroniza los productos del catálogo VTEX de un vendor hacia su catálogo
 * de WooCommerce en el marketplace. Para cada SKU VTEX:
 *
 * 1. Descarga el catálogo vía Search API de VTEX (paginado, ~50 items/página),
 *    que agrega catalog + pricing + inventory + imágenes en una sola respuesta.
 * 2. Normaliza cada item (SKU) a un formato canónico.
 * 3. Filtra por categorías configuradas (si el vendor seleccionó alguna).
 * 4. Depura duplicados por SKU.
 * 5. Aplica reglas de precio (mismas que PosGold) + plantilla SEO.
 * 6. Crea o actualiza el producto WooCommerce (por SKU).
 *
 * @package LTMS
 * @version 2.9.323
 * @since 2.9.323
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Vtex_Sync {

    /** Meta key que marca un producto WC como sincronizado desde VTEX. */
    const SYNC_META_KEY = '_ltms_vtex_synced';

    /** Meta key que guarda el SKU VTEX original del producto. */
    const CODE_META_KEY = '_ltms_vtex_code';

    /** Meta key que guarda el itemId VTEX del producto. */
    const SKU_ID_META_KEY = '_ltms_vtex_sku_id';

    /** Cron hook para sync en background. */
    const CRON_HOOK = 'ltms_vtex_sync_cron';

    /** Cron hook para el re-sync automático periódico (VTEX → WooCommerce). */
    const AUTO_SYNC_CRON_HOOK = 'ltms_vtex_auto_sync';

    /** Recurrencia del re-sync automático. 'daily' es un intervalo core de WP. */
    const AUTO_SYNC_INTERVAL = 'daily';

    /** Página del Search API (items por página, máx 50). */
    const PAGE_SIZE = 50;

    /** Máximo de páginas a recorrer por sync (200 = 10.000 SKUs). */
    const MAX_PAGES = 200;

    /**
     * Registra el cron hook y programa el re-sync automático diario.
     *
     * VTEX-AUTOSYNC: el re-sync periódico re-consulta el catálogo VTEX de todos
     * los vendors con credenciales configuradas, sin que el vendor tenga que
     * pulsar "Sincronizar" a mano. La programación es idempotente (guard
     * wp_next_scheduled) y se dispara desde el boot del kernel, que corre en
     * cada request del frontend.
     */
    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_sync' ] );
        add_action( self::AUTO_SYNC_CRON_HOOK, [ __CLASS__, 'run_auto_sync' ] );

        if ( ! wp_next_scheduled( self::AUTO_SYNC_CRON_HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00' ), self::AUTO_SYNC_INTERVAL, self::AUTO_SYNC_CRON_HOOK );
        }
    }

    /**
     * Programa una sync en background via WP-Cron.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{success: bool, message: string}
     */
    public static function schedule_sync( int $vendor_id ): array {
        $creds = self::get_vendor_credentials( $vendor_id );
        if ( ! $creds['configured'] ) {
            return [
                'success' => false,
                'message' => __( 'No has configurado tus credenciales de VTEX.', 'ltms' ),
            ];
        }

        $in_progress = get_user_meta( $vendor_id, '_ltms_vtex_sync_in_progress', true );
        if ( $in_progress && ( time() - (int) $in_progress ) < 600 ) {
            return [
                'success' => false,
                'message' => __( 'Ya tienes una sincronización en curso. Espera a que termine.', 'ltms' ),
            ];
        }

        update_user_meta( $vendor_id, '_ltms_vtex_sync_in_progress', time() );
        wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $vendor_id ] );

        return [
            'success' => true,
            'message' => __( 'Sincronización programada. Recibirás una notificación cuando termine. Puedes cerrar esta página.', 'ltms' ),
        ];
    }

    /**
     * Ejecuta la sync programada por WP-Cron (background).
     */
    public static function run_scheduled_sync( int $vendor_id ): void {
        $max_exec = (int) ini_get( 'max_execution_time' );
        $desired  = $max_exec > 0 ? max( 30, $max_exec - 5 ) : 600;
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( $desired );
        }

        $result = self::sync_vendor_products( $vendor_id );

        delete_user_meta( $vendor_id, '_ltms_vtex_sync_in_progress' );
        update_user_meta( $vendor_id, '_ltms_vtex_sync_last_result', [
            'completed_at' => current_time( 'mysql', true ),
            'success'      => $result['success'] ?? false,
            'created'      => $result['created'] ?? 0,
            'updated'      => $result['updated'] ?? 0,
            'skipped'      => $result['skipped'] ?? 0,
            'errors'       => $result['errors'] ?? [],
            'message'      => $result['message'] ?? '',
        ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'VTEX_SYNC_COMPLETE',
                sprintf( 'Vendor #%d VTEX sync completed: %d created, %d updated', $vendor_id, $result['created'] ?? 0, $result['updated'] ?? 0 )
            );
        }

        // Notificación in-dashboard.
        global $wpdb;
        $notifications_table = $wpdb->prefix . 'lt_notifications';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notifications_table ) ) === $notifications_table ) {
            $wpdb->insert(
                $notifications_table,
                [
                    'user_id'    => $vendor_id,
                    'type'       => 'vtex_sync',
                    'title'      => __( 'Sincronización VTEX completada', 'ltms' ),
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
     * Re-sync automático periódico (VTEX → WooCommerce).
     *
     * VTEX-AUTOSYNC FIX: el catálogo/precios de VTEX cambian en la cuenta del
     * vendor y el marketplace debe reflejarlos sin que el vendor pulse
     * "Sincronizar" a mano. Este handler (disparado por AUTO_SYNC_CRON_HOOK,
     * recurrencia diaria) enlista los vendors con credenciales VTEX válidas y
     * programa un single-event por vendor (reusando CRON_HOOK →
     * run_scheduled_sync) escalonado +5s para no saturar WP-Cron con todos los
     * catálogos en el mismo tick.
     *
     * @return void
     */
    public static function run_auto_sync(): void {
        $vendors = self::get_vtex_configured_vendors();
        if ( empty( $vendors ) ) {
            return;
        }

        $offset = 5;
        foreach ( $vendors as $vendor_id ) {
            if ( ! self::auto_sync_allowed( $vendor_id ) ) {
                continue;
            }

            wp_schedule_single_event( time() + $offset, self::CRON_HOOK, [ $vendor_id ] );
            update_user_meta( $vendor_id, '_ltms_vtex_sync_in_progress', time() );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'VTEX_AUTO_SYNC_SCHEDULED',
                    sprintf( 'Vendor #%d auto-sync programado (+%ds)', $vendor_id, $offset )
                );
            }

            $offset += 5;
        }
    }

    /**
     * Enlista los vendors con credenciales VTEX configuradas y completas.
     *
     * Usa la presencia de la meta ltms_vtex_account_name como proxy y luego
     * valida con get_vendor_credentials() (account + appKey + appToken).
     * Filtra por LTMS_Utils::is_ltms_vendor() para no disparar sync sobre
     * usuarios con meta huérfana (admin/editor que probaron credenciales).
     *
     * @return int[] IDs de vendors con VTEX configurado.
     */
    private static function get_vtex_configured_vendors(): array {
        $vendors = [];

        $users = get_users( [
            'fields'     => 'ID',
            'meta_query' => [
                [
                    'key'     => 'ltms_vtex_account_name',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ] );

        foreach ( (array) $users as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) {
                continue;
            }
            if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
                continue;
            }
            $creds = self::get_vendor_credentials( $user_id );
            if ( $creds['configured'] ) {
                $vendors[] = $user_id;
            }
        }

        return $vendors;
    }

    /**
     * Guard para el auto-sync de un vendor.
     *
     * Evita solapar con un sync manual en curso (_ltms_vtex_sync_in_progress
     * <10 min) y respeta el rate-limit de 2 minutos que sync_vendor_products()
     * impone por vendor (ltms_vtex_last_sync). Si el cron diario cayera justo
     * después de una sync manual, el single-event se omite y se reintenta al día
     * siguiente en lugar de chocar con el rate-limit.
     *
     * @param int $vendor_id ID del vendedor.
     * @return bool
     */
    private static function auto_sync_allowed( int $vendor_id ): bool {
        $in_progress = (int) get_user_meta( $vendor_id, '_ltms_vtex_sync_in_progress', true );
        if ( $in_progress && ( time() - $in_progress ) < 600 ) {
            return false;
        }

        $last_sync = (int) get_user_meta( $vendor_id, 'ltms_vtex_last_sync', true );
        if ( $last_sync && ( time() - $last_sync ) < ( 2 * MINUTE_IN_SECONDS ) ) {
            return false;
        }

        return true;
    }

    /**
     * Sincroniza todos los productos de VTEX hacia WooCommerce para un vendor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{success: bool, message: string, created: int, updated: int, skipped: int, errors: array}
     */
    public static function sync_vendor_products( int $vendor_id ): array {
        $creds = self::get_vendor_credentials( $vendor_id );
        if ( ! $creds['configured'] ) {
            return [
                'success' => false,
                'message' => __( 'No has configurado tus credenciales de VTEX. Ve a Configuración → VTEX.', 'ltms' ),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [],
            ];
        }

        // Rate limit: máximo 1 sync cada 2 minutos por vendor.
        $last_sync    = (int) get_user_meta( $vendor_id, 'ltms_vtex_last_sync', true );
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

        // Filtro de categorías (ids seleccionados por el vendor).
        $category_filter = (string) get_user_meta( $vendor_id, 'ltms_vtex_category_ids_csv', true );

        // Reglas de precio + plantilla SEO del vendor.
        $price_rules  = LTMS_Vtex_Price_Calculator::get_vendor_rules( $vendor_id );
        $seo_template = (string) get_user_meta( $vendor_id, 'ltms_vtex_seo_template', true );

        $created         = 0;
        $updated         = 0;
        $skipped         = 0;
        $skipped_incomplete = 0;
        $errors          = [];
        $total_products  = 0;
        $filtered_out    = 0;

        // Descargar catálogo paginado.
        $page = 0;
        while ( $page < self::MAX_PAGES ) {
            $from = $page * self::PAGE_SIZE;
            $to   = $from + self::PAGE_SIZE - 1;

            $result = LTMS_Api_Vtex::get_products_search(
                $creds['account_name'],
                $creds['app_key'],
                $creds['app_token'],
                $from,
                $to,
                $creds['environment']
            );

            if ( ! $result['success'] ) {
                // Error en la primera página → fallar; en páginas siguientes,
                // cortar con error acumulado.
                if ( 0 === $page ) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            /* translators: %s: mensaje de error */
                            __( 'Error al conectar con VTEX: %s', 'ltms' ),
                            $result['error']
                        ),
                        'created' => 0,
                        'updated' => 0,
                        'skipped' => 0,
                        'errors'  => [ $result['error'] ],
                    ];
                }
                $errors[] = sprintf( 'Página %d: %s', $page + 1, $result['error'] );
                break;
            }

            $products = $result['data'];
            if ( empty( $products ) ) {
                break; // No hay más productos.
            }
            $total_products += count( $products );

            foreach ( $products as $raw_product ) {
                $items = is_array( $raw_product['items'] ?? null ) ? $raw_product['items'] : [];
                foreach ( $items as $item ) {
                    $product = LTMS_Api_Vtex::normalize_search_item( $raw_product, $item );

                    // Filtro por categoría.
                    if ( ! empty( $category_filter ) ) {
                        $before = count( [ $product ] );
                        $filtered = LTMS_Vtex_Price_Calculator::filter_by_category( [ $product ], $category_filter );
                        if ( empty( $filtered ) ) {
                            $filtered_out++;
                            continue;
                        }
                        unset( $before );
                    }

                    // Completitud.
                    $validation = LTMS_Vtex_Price_Calculator::validate_product_completeness( $product );
                    if ( ! $validation['complete'] ) {
                        $skipped++;
                        $skipped_incomplete++;
                        continue;
                    }

                    // Precio final con reglas del vendor.
                    $price_calc = LTMS_Vtex_Price_Calculator::calculate( (float) $product['regular_price'], $price_rules );
                    $product['regular_price'] = $price_calc['price'];

                    // Título SEO.
                    $product['name'] = LTMS_Vtex_Price_Calculator::generate_seo_title( $product, $seo_template );

                    try {
                        $sync_result = self::sync_single_product( $vendor_id, $product );
                        if ( 'created' === $sync_result ) {
                            $created++;
                        } elseif ( 'updated' === $sync_result ) {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } catch ( \Throwable $e ) {
                        $errors[] = sprintf(
                            'SKU %s: %s',
                            $product['sku'],
                            $e->getMessage()
                        );
                    }
                }
            }

            // Si la página trajo menos de PAGE_SIZE, no hay más.
            if ( count( $products ) < self::PAGE_SIZE ) {
                break;
            }
            $page++;
        }

        update_user_meta( $vendor_id, 'ltms_vtex_last_sync', time() );
        update_user_meta( $vendor_id, 'ltms_vtex_last_sync_count', $total_products );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'VTEX_SYNC',
                sprintf(
                    'Vendor #%d VTEX sync: %d created, %d updated, %d skipped (%d incomplete), %d filtered, %d errors',
                    $vendor_id, $created, $updated, $skipped, $skipped_incomplete, $filtered_out, count( $errors )
                ),
                [
                    'vendor_id'          => $vendor_id,
                    'total_raw'          => $total_products,
                    'filtered_out'       => $filtered_out,
                    'created'            => $created,
                    'updated'            => $updated,
                    'skipped'            => $skipped,
                    'skipped_incomplete' => $skipped_incomplete,
                    'errors'             => $errors,
                ]
            );
        }

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
        if ( $filtered_out > 0 ) {
            $message_parts[] = sprintf(
                /* translators: %d: filtered */
                __( '%d fuera de categoría', 'ltms' ),
                $filtered_out
            );
        }

        return [
            'success'      => true,
            'message'      => __( 'Sincronización completa: ', 'ltms' ) . implode( ', ', $message_parts ) . '.',
            'created'      => $created,
            'updated'      => $updated,
            'skipped'      => $skipped,
            'duplicates'   => 0,
            'filtered_out' => $filtered_out,
            'errors'       => $errors,
        ];
    }

    /**
     * Obtiene las credenciales VTEX de un vendor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{configured: bool, account_name: string, environment: string, app_key: string, app_token: string}
     */
    public static function get_vendor_credentials( int $vendor_id ): array {
        $account_name = (string) get_user_meta( $vendor_id, 'ltms_vtex_account_name', true );
        $environment  = (string) get_user_meta( $vendor_id, 'ltms_vtex_environment', true );
        if ( '' === $environment ) {
            $environment = LTMS_Api_Vtex::ENV_DEFAULT;
        }
        $app_key   = (string) get_user_meta( $vendor_id, 'ltms_vtex_app_key', true );
        $app_token = (string) get_user_meta( $vendor_id, 'ltms_vtex_app_token', true );

        // Desencriptar appKey/appToken si están cifrados.
        // QA-VTEX FIX: try/catch — LTMS_Core_Security::decrypt() LANZA
        // InvalidArgumentException si el valor no es ciphertext válido (token
        // corrupto o guardado en texto plano legacy). Sin el catch, una sync
        // con credenciales planas/corruptas crasheaba en lugar de degradar.
        foreach ( [ 'app_key', 'app_token' ] as $field ) {
            $raw = $field === 'app_key' ? $app_key : $app_token;
            if ( $raw && class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'decrypt' ) ) {
                try {
                    $decrypted = LTMS_Core_Security::decrypt( $raw );
                } catch ( \Throwable $e ) {
                    $decrypted = false;
                }
                if ( $decrypted ) {
                    if ( 'app_key' === $field ) {
                        $app_key = $decrypted;
                    } else {
                        $app_token = $decrypted;
                    }
                }
            }
        }

        $configured = ! empty( $account_name ) && ! empty( $app_key ) && ! empty( $app_token );

        return [
            'configured'   => $configured,
            'account_name' => $account_name,
            'environment'  => $environment,
            'app_key'      => $app_key,
            'app_token'    => $app_token,
        ];
    }

    /**
     * Sincroniza un solo producto VTEX hacia WooCommerce.
     *
     * @param int   $vendor_id ID del vendedor (autor del producto WC).
     * @param array $product   Producto normalizado de VTEX.
     * @return string 'created' | 'updated' | 'skipped'
     */
    private static function sync_single_product( int $vendor_id, array $product ): string {
        $existing_id = wc_get_product_id_by_sku( $product['sku'] );

        if ( $existing_id ) {
            $post = get_post( $existing_id );
            if ( $post && (int) $post->post_author === $vendor_id ) {
                $wc_product = wc_get_product( $existing_id );
                self::update_product_fields( $wc_product, $product, $vendor_id );
                return 'updated';
            }
            return 'skipped'; // SKU existe pero pertenece a otro vendor.
        }

        self::create_product( $vendor_id, $product );
        return 'created';
    }

    /**
     * Crea un nuevo producto WooCommerce desde un producto VTEX.
     *
     * @param int   $vendor_id ID del vendedor.
     * @param array $product   Producto normalizado.
     * @return int ID del producto creado.
     */
    private static function create_product( int $vendor_id, array $product ): int {
        $wc_product = new \WC_Product_Simple();
        $wc_product->set_name( $product['name'] ?: ( 'Producto ' . $product['codigo'] ) );
        $wc_product->set_status( 'publish' );
        $wc_product->set_catalog_visibility( 'visible' );
        $wc_product->set_sku( $product['sku'] );
        $wc_product->set_regular_price( self::format_price( $product['regular_price'] ) );
        $wc_product->set_description( $product['descripcion'] ?? '' );
        $wc_product->set_short_description( $product['descripcion'] ?? '' );

        $wc_product->set_manage_stock( true );
        $wc_product->set_stock_quantity( $product['stock_quantity'] );
        $wc_product->set_stock_status( $product['stock_quantity'] > 0 ? 'instock' : 'outofstock' );

        if ( ! empty( $product['barcode'] ) ) {
            $wc_product->set_global_unique_id( $product['barcode'] );
        }

        $wc_product->update_meta_data( self::SYNC_META_KEY, current_time( 'mysql', true ) );
        $wc_product->update_meta_data( self::CODE_META_KEY, $product['codigo'] );
        if ( ! empty( $product['vtex_sku_id'] ) ) {
            $wc_product->update_meta_data( self::SKU_ID_META_KEY, $product['vtex_sku_id'] );
        }

        self::set_product_attributes( $wc_product, $product );

        $category_ids = self::resolve_categories( $product );
        if ( ! empty( $category_ids ) ) {
            $wc_product->set_category_ids( $category_ids );
        }

        $wc_product->save();
        $product_id = $wc_product->get_id();

        wp_update_post( [
            'ID'          => $product_id,
            'post_author' => $vendor_id,
        ] );

        if ( ! empty( $product['imagen_url'] ) ) {
            self::download_and_attach_image( $product['imagen_url'], $product_id );
        }

        return $product_id;
    }

    /**
     * Actualiza los campos de un producto WC existente con datos de VTEX.
     */
    private static function update_product_fields( \WC_Product $wc_product, array $product, int $vendor_id ): void {
        if ( ! empty( $product['name'] ) ) {
            $wc_product->set_name( $product['name'] );
        }
        if ( $product['regular_price'] > 0 ) {
            $wc_product->set_regular_price( self::format_price( $product['regular_price'] ) );
        }
        if ( ! empty( $product['descripcion'] ) ) {
            $wc_product->set_description( $product['descripcion'] );
        }

        $wc_product->set_manage_stock( true );
        $wc_product->set_stock_quantity( $product['stock_quantity'] );
        $wc_product->set_stock_status( $product['stock_quantity'] > 0 ? 'instock' : 'outofstock' );

        if ( ! empty( $product['barcode'] ) ) {
            $wc_product->set_global_unique_id( $product['barcode'] );
        }

        $wc_product->update_meta_data( self::SYNC_META_KEY, current_time( 'mysql', true ) );
        $wc_product->update_meta_data( self::CODE_META_KEY, $product['codigo'] );
        if ( ! empty( $product['vtex_sku_id'] ) ) {
            $wc_product->update_meta_data( self::SKU_ID_META_KEY, $product['vtex_sku_id'] );
        }

        self::set_product_attributes( $wc_product, $product );

        $category_ids = self::resolve_categories( $product );
        if ( ! empty( $category_ids ) ) {
            $wc_product->set_category_ids( $category_ids );
        }

        $wc_product->save();

        if ( ! empty( $product['imagen_url'] ) && ! has_post_thumbnail( $wc_product->get_id() ) ) {
            self::download_and_attach_image( $product['imagen_url'], $wc_product->get_id() );
        }
    }

    /**
     * Asigna atributos (marca) al producto WC.
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

        if ( ! empty( $attributes ) ) {
            $wc_product->set_attributes( $attributes );
        }
    }

    /**
     * Resuelve las categorías VTEX (categoria > grupo > subgrupo) a IDs WC.
     */
    private static function resolve_categories( array $product ): array {
        $cat_ids = [];

        if ( ! empty( $product['categoria'] ) ) {
            $cat_ids[] = self::get_or_create_category( $product['categoria'] );

            if ( ! empty( $product['grupo'] ) ) {
                $parent_id = end( $cat_ids );
                $cat_ids[] = self::get_or_create_category( $product['grupo'], $parent_id );

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
     */
    private static function get_or_create_category( string $name, int $parent_id = 0 ): int {
        $slug = sanitize_title( $name );

        $existing = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $existing && (int) $existing->parent === $parent_id ) {
            return (int) $existing->term_id;
        }

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
     * Descarga una imagen desde VTEX y la adjunta al producto WC.
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

        $url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
        $file_name = basename( $url_path ) ?: ( 'vtex-' . $product_id . '.jpg' );
        $file_array = [
            'name'     => sanitize_file_name( $file_name ),
            'tmp_name' => $tmp,
        ];

        $allowed_types = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
        $ext = strtolower( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_types, true ) ) {
            $file_array['name'] .= '.jpg';
        }

        $attachment_id = media_handle_sideload( $file_array, $product_id, 'Imagen VTEX producto #' . $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return false;
        }

        set_post_thumbnail( $product_id, $attachment_id );
        return (int) $attachment_id;
    }

    /**
     * Formatea un precio para WooCommerce (string con 2 decimales).
     */
    private static function format_price( float $price ): string {
        return number_format( max( 0, $price ), 2, '.', '' );
    }
}