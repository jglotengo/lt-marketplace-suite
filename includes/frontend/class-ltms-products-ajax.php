<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Products_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ltms_get_products_data',    [ $this, 'get_products_data' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings', [ $this, 'save_vendor_settings' ] );
        add_action( 'wp_ajax_ltms_upload_store_logo',    [ $this, 'upload_store_logo' ] ); // v2.9.99
        // C5-1 FIX: ltms_get_vendor_settings eliminado — lo maneja LTMS_Vendor_Settings_Saver
        // con respuesta más completa (bank_info, delivery_zone, store_address, etc).
        add_action( 'wp_ajax_ltms_create_product',        [ $this, 'create_product' ] );
        add_action( 'wp_ajax_ltms_get_categories',        [ $this, 'get_categories' ] );
        add_action( 'wp_ajax_ltms_upload_product_image',  [ $this, 'upload_product_image' ] );
        add_action( 'wp_ajax_ltms_get_product',           [ $this, 'get_product' ] );
        add_action( 'wp_ajax_ltms_update_product',        [ $this, 'update_product' ] );
        add_action( 'wp_ajax_ltms_delete_product',        [ $this, 'delete_product' ] );
        add_action( 'wp_ajax_ltms_toggle_product_status', [ $this, 'toggle_product_status' ] );
    }

    private function check_nonce() {
        if ( ! check_ajax_referer( 'ltms_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in', 401 );
        }
        // HI-2 FIX: most product handlers (update_product, create_product,
        // delete_product, upload_product_image, ...) mutate vendor data. Without
        // a capability check, any logged-in user (subscriber, customer) could
        // call them. Require ltms_vendor or manage_options.
        if ( ! ( class_exists( 'LTMS_Utils' ) && LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
        }
    }

    public function get_products_data() {
        $this->check_nonce();
        $user_id  = get_current_user_id();
        // v2.9.85 P2: Paginación configurable (antes hardcodeado a 50).
        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) ); // phpcs:ignore
        $per_page = min( 100, max( 10, (int) ( $_POST['per_page'] ?? 20 ) ) ); // phpcs:ignore
        $search   = sanitize_text_field( $_POST['search'] ?? '' ); // phpcs:ignore
        $args     = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }
        $query    = new WP_Query( $args );
        $products = [];
        foreach ( $query->posts as $p ) {
            $product    = wc_get_product( $p->ID );
            $products[] = [
                'id'       => $p->ID,
                'name'     => $p->post_title,
                'status'   => $p->post_status,
                'price'    => $product ? (float) $product->get_price() : 0,
                'stock'    => $product ? $product->get_stock_quantity() : null,
                    'image'        => ( $product && $product->get_image_id() ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
                    'edit_url'     => get_edit_post_link( $p->ID, 'raw' ),
                    'product_type' => get_post_meta( $p->ID, '_ltms_product_type', true ) ?: 'product',
            ];
        }
        // v2.9.85 P2: Incluir info de paginación en la respuesta.
        wp_send_json_success( [
            'products'    => $products,
            'total'       => (int) $query->found_posts,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) $query->max_num_pages,
        ] );
    }

    public function get_vendor_settings() {
        $this->check_nonce();
        $user_id    = get_current_user_id();
        $kyc_status = get_user_meta( $user_id, 'ltms_kyc_status', true ) ?: 'pending';
        $dz_raw = get_user_meta( $user_id, '_ltms_delivery_zone', true );
        $store  = [
            'name'             => get_user_meta( $user_id, 'ltms_store_name',        true ),
            'phone'            => get_user_meta( $user_id, 'ltms_store_phone',       true ),
            'description'      => get_user_meta( $user_id, 'ltms_store_description', true ),
            'bank_info'        => get_user_meta( $user_id, 'ltms_bank_info',         true ),
            // Extended profile fields (Vendor_Settings_Saver)
            'store_name'       => get_user_meta( $user_id, 'ltms_store_name',        true ),
            'store_phone'      => get_user_meta( $user_id, 'ltms_store_phone',       true ),
            'store_address'    => get_user_meta( $user_id, 'ltms_store_address',     true ),
            'store_city'       => get_user_meta( $user_id, 'ltms_store_city',        true ),
            'store_schedule'   => get_user_meta( $user_id, 'ltms_store_schedule',    true ),
            'store_categories' => get_user_meta( $user_id, 'ltms_store_categories',  true ),
            'delivery_zone'    => $dz_raw ? json_decode( $dz_raw, true ) : [ 'cities' => [], 'radius_km' => 0, 'free_from' => 0 ],
        ];
        wp_send_json_success( [
            'kyc_status'           => $kyc_status,
            'store'                => $store,
            // v2.3.0 — Analytics por vendedor
            'vendor_ga4_enabled'   => get_option( 'ltms_vendor_ga4_enabled',   'yes' ) === 'yes',
            'vendor_pixel_enabled' => get_option( 'ltms_vendor_pixel_enabled', 'yes' ) === 'yes',
            'vendor_ga4_id'        => get_user_meta( $user_id, 'ltms_vendor_ga4_id',   true ),
            'vendor_pixel_id'      => get_user_meta( $user_id, 'ltms_vendor_pixel_id', true ),
        ] );
    }

    public function save_vendor_settings() {
        $this->check_nonce();
        $user_id = get_current_user_id();

        // Support two call formats:
        // 1. Flat POST fields: store_name, store_phone, store_description, bank_info (from renderSettingsView JS)
        // 2. Nested settings object: settings[ltms_store_name], etc. (from view-settings.php inline JS)
        $settings_map = [
            'ltms_store_name'        => $_POST['store_name']        ?? ( $_POST['settings']['ltms_store_name']        ?? null ), // phpcs:ignore
            'ltms_store_phone'       => $_POST['store_phone']       ?? ( $_POST['settings']['ltms_store_phone']       ?? null ), // phpcs:ignore
            'ltms_store_description' => $_POST['store_description'] ?? ( $_POST['settings']['ltms_store_description'] ?? null ), // phpcs:ignore
            'ltms_bank_info'         => $_POST['bank_info']         ?? ( $_POST['settings']['ltms_bank_info']         ?? null ), // phpcs:ignore
            'ltms_bank_name'         => null,
            'ltms_bank_account_type' => null,
            'ltms_shipping_policy'   => null,
            'ltms_return_policy'     => null,
        ];

        // Also handle any remaining ltms_* fields from the nested settings object
        if ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) { // phpcs:ignore
            // M-101: campos fiscales agregados a la lista de permitidos
            $allowed = [
                'ltms_bank_name', 'ltms_bank_account_type', 'ltms_bank_account_holder', 'ltms_payment_method',
                'ltms_shipping_policy', 'ltms_return_policy',
                'ltms_tax_regime', 'ltms_nit', 'ltms_ciiu_code', 'ltms_municipality',
                // v2.3.0 — Analytics por vendedor
                'ltms_vendor_ga4_id', 'ltms_vendor_pixel_id',
                // v2.9.99 P0-2 FIX: 7 campos de view-settings.php que se descartaban silenciosamente.
                'ltms_vacation_mode', 'ltms_vacation_message',
                'ltms_store_logo_id',
                'ltms_store_schedule',
                'ltms_store_instagram', 'ltms_store_facebook', 'ltms_store_whatsapp',
            ];
            foreach ( $allowed as $field ) {
                if ( isset( $_POST['settings'][ $field ] ) ) { // phpcs:ignore
                    $raw = wp_unslash( $_POST['settings'][ $field ] ); // phpcs:ignore
                    // v2.9.99: sanitization per-field type.
                    // INTEGRATIONS-AUDIT P0 FIX: removed dead-code first branch
                    // that bypassed IDOR protection on ltms_store_logo_id. The
                    // ownership check (post_author === $user_id) now applies.
                    if ( $field === 'ltms_store_logo_id' ) {
                        // v2.9.99 REG-2 FIX: IDOR protection — verify attachment ownership.
                        $logo_id = absint( $raw );
                        $attach  = get_post( $logo_id );
                        if ( $attach && $attach->post_type === 'attachment' && (int) $attach->post_author === $user_id ) {
                            $settings_map[ $field ] = $logo_id;
                        } else {
                            // Vendor tried to set someone else's attachment — silently ignore.
                            continue;
                        }
                    } elseif ( $field === 'ltms_store_schedule' ) {
                        // JSON array of per-day open/close — sanitize as wp_json_encode after decoding.
                        $decoded = json_decode( $raw, true );
                        $settings_map[ $field ] = is_array( $decoded ) ? wp_json_encode( $decoded ) : '';
                    } elseif ( $field === 'ltms_vacation_message' ) {
                        $settings_map[ $field ] = sanitize_textarea_field( $raw );
                    } elseif ( in_array( $field, [ 'ltms_store_instagram', 'ltms_store_facebook', 'ltms_store_whatsapp' ], true ) ) {
                        $settings_map[ $field ] = esc_url_raw( $raw );
                    } else {
                        $settings_map[ $field ] = sanitize_text_field( $raw );
                    }
                }
            }
            // Handle encrypted bank account number
            if ( ! empty( $_POST['settings']['ltms_bank_account_number'] ) ) { // phpcs:ignore
                update_user_meta(
                    $user_id,
                    'ltms_bank_account_number',
                    LTMS_Core_Security::encrypt( sanitize_text_field( $_POST['settings']['ltms_bank_account_number'] ) ) // phpcs:ignore
                );
            }
        }

        foreach ( $settings_map as $meta_key => $value ) {
            if ( $value === null ) {
                continue;
            }
            // v2.9.99: respetar el tipo de dato ya sanitizado arriba para los campos especiales.
            if ( $meta_key === 'ltms_store_logo_id' ) {
                update_user_meta( $user_id, $meta_key, absint( $value ) );
            } elseif ( $meta_key === 'ltms_store_schedule' ) {
                update_user_meta( $user_id, $meta_key, $value ); // ya viene como wp_json_encode
            } elseif ( $meta_key === 'ltms_vacation_message' ) {
                update_user_meta( $user_id, $meta_key, sanitize_textarea_field( wp_unslash( $value ) ) );
            } elseif ( in_array( $meta_key, [ 'ltms_store_instagram', 'ltms_store_facebook', 'ltms_store_whatsapp' ], true ) ) {
                update_user_meta( $user_id, $meta_key, esc_url_raw( $value ) );
            } else {
                update_user_meta( $user_id, $meta_key, sanitize_text_field( wp_unslash( $value ) ) );
            }
        }

        // M-101: manejar checkbox gran contribuyente (solo llega si está marcado)
        if ( isset( $_POST['settings']['ltms_is_gran_contribuyente'] ) ) { // phpcs:ignore
            update_user_meta( $user_id, 'ltms_is_gran_contribuyente', 1 );
        } else {
            update_user_meta( $user_id, 'ltms_is_gran_contribuyente', 0 );
        }

        wp_send_json_success( [ 'message' => __( 'Configuración guardada exitosamente.', 'ltms' ) ] );
    }

    public function get_product() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado', 404 );
        }
        $cats = $product->get_category_ids();
        wp_send_json_success( [
            'id'                  => $product_id,
            'name'                => $product->get_name(),
            'description'         => $product->get_description(),
            'price'               => $product->get_regular_price(),
            'sale_price'          => $product->get_sale_price(),
            'stock'               => $product->get_stock_quantity(),
            'status'              => $product->get_status(),
            'catalog_visibility'  => $product->get_catalog_visibility(),
            'weight'              => $product->get_weight(),
            'length'              => $product->get_length(),
            'width'               => $product->get_width(),
            'height'              => $product->get_height(),
            'category_id'         => ! empty( $cats ) ? $cats[0] : 0,
            'image_id'            => $product->get_image_id(),
            'image_url'           => $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
            'gallery_ids'         => $product->get_gallery_image_ids(),
            'gallery_urls'        => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
            // CS-07: tipo para pre-llenar selector en edición (mapeo legacy)
            'product_type'        => ( function( $t ) { return ( $t === 'product' || $t === '' ) ? 'physical' : $t; } )( get_post_meta( $product_id, '_ltms_product_type', true ) ),
            // CS-08: ReDi
            'redi_enabled'        => get_post_meta( $product_id, '_ltms_redi_enabled', true ) ?: 'no',
            'redi_rate'           => (float) get_post_meta( $product_id, '_ltms_redi_rate', true ) * 100,
            // AUDIT-PROD-044: devolver campos de booking para poblar el modal Edit
            // (paridad con create_product — antes el modal Edit no recibía estos valores).
            'booking_type'        => get_post_meta( $product_id, '_ltms_booking_type', true ) ?: 'accommodation',
            'min_nights'          => (int) get_post_meta( $product_id, '_ltms_min_nights', true ) ?: 1,
            'max_nights'          => (int) get_post_meta( $product_id, '_ltms_max_nights', true ) ?: 0,
            'booking_capacity'    => (int) get_post_meta( $product_id, '_ltms_capacity', true ) ?: 1,
            'checkin_time'        => get_post_meta( $product_id, '_ltms_checkin_time', true ) ?: '15:00',
            'checkout_time'       => get_post_meta( $product_id, '_ltms_checkout_time', true ) ?: '11:00',
            'payment_mode'        => get_post_meta( $product_id, '_ltms_payment_mode', true ) ?: 'full',
            'deposit_pct'         => (float) get_post_meta( $product_id, '_ltms_deposit_pct', true ) ?: 0,
            // AUDIT-PROD-044: devolver short_desc, sku, tags y atributos de variaciones para
            // poblar el modal Edit con el mismo nivel de detalle que create_product acepta.
            'short_description'   => $product->get_short_description(),
            'sku'                 => $product->get_sku(),
            'shipping_class_id'   => $product->get_shipping_class_id(),
            // AUDIT-PROD-H7 (re-auditoría): devolver tags como CSV para poblar #ltms-ep-tags.
            // ANTES el campo no se devolvía → el JS no poblaba el input → el modal always enviaba
            // `tags: ''` → `update_product` ejecutaba `wp_set_post_terms( $pid, [], 'product_tag', false )`
            // → TODOS los tags existentes se borraban al editar el producto sin tocarlos. Bug silencioso.
            'tags'                => implode( ',', wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] ) ),
        ] );
    }

    public function update_product() {
        $this->check_nonce();
        $product_id  = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado', 404 );
        }
        $name               = sanitize_text_field( $_POST['name'] ?? '' );
        $description        = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price              = floatval( $_POST['price'] ?? 0 );
        $sale_price_raw     = isset( $_POST['sale_price'] ) && $_POST['sale_price'] !== '' ? floatval( $_POST['sale_price'] ) : null;
        $stock              = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id        = intval( $_POST['category_id'] ?? 0 );
        $image_id           = intval( $_POST['image_id'] ?? 0 );
        $status             = sanitize_text_field( $_POST['status'] ?? $product->get_status() );
        $catalog_visibility = sanitize_key( $_POST['catalog_visibility'] ?? '' );
        $weight             = isset( $_POST['weight'] ) && $_POST['weight'] !== '' ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : null;
        $dim_length         = isset( $_POST['dim_length'] ) && $_POST['dim_length'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_length'] ) ) : null;
        $dim_width          = isset( $_POST['dim_width'] )  && $_POST['dim_width']  !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_width'] ) )  : null;
        $dim_height         = isset( $_POST['dim_height'] ) && $_POST['dim_height'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_height'] ) ) : null;

        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }

        // v2.9.62 DEEP-AUDIT-002 P2-6: Validar que la categoría existe.
        if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
            wp_send_json_error( 'Categoría inválida', 400 );
        }

        // HI-1 FIX: validate status against an allowlist before applying it.
        $allowed_statuses = [ 'publish', 'pending', 'draft' ];
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product status', 'ltms' ) ], 400 );
        }

        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        // CS-09: precio de oferta — vacío = sin oferta activa
        if ( $sale_price_raw !== null && $sale_price_raw > 0 && $sale_price_raw < $price ) {
            $product->set_sale_price( (string) $sale_price_raw );
        } else {
            $product->set_sale_price( '' ); // limpiar oferta si se dejó vacío
        }
        $product->set_status( $status );
        if ( in_array( $catalog_visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $product->set_catalog_visibility( $catalog_visibility );
        }
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
        if ( $weight !== null )     $product->set_weight( $weight );
        if ( $dim_length !== null ) $product->set_length( $dim_length );
        if ( $dim_width  !== null ) $product->set_width( $dim_width );
        if ( $dim_height !== null ) $product->set_height( $dim_height );
        if ( $category_id ) $product->set_category_ids( [ $category_id ] );
        if ( $image_id )    $product->set_image_id( $image_id );
        $gallery_ids = isset( $_POST['gallery_ids'] ) ? array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) ) : null;
        if ( $gallery_ids !== null ) { $product->set_gallery_image_ids( $gallery_ids ); }
        $product->save();

        // M-23 FIX: re-guardar _ltms_vendor_id después de cada actualización.
        // $product->save() de WooCommerce puede eliminar metas no gestionadas
        // por WC, lo que haría que el producto dejara de aparecer en pedidos del dashboard.
        update_post_meta( $product_id, '_ltms_vendor_id', get_current_user_id() );

        // CS-05: actualizar tipo si viene en la petición
        // AUDIT-PROD-044: extender allowlist con 'restaurant' y 'variable' (paridad con create_product).
        $product_type_for_update = null;
        if ( isset( $_POST['product_type'] ) ) { // phpcs:ignore
            $upd_type = sanitize_key( $_POST['product_type'] ); // phpcs:ignore
            // Mapeo legacy: 'product' → 'physical'
            if ( $upd_type === 'product' ) { $upd_type = 'physical'; }
            if ( in_array( $upd_type, [ 'physical', 'digital', 'service', 'booking', 'restaurant', 'variable' ], true ) ) {
                update_post_meta( $product_id, '_ltms_product_type', $upd_type );
                $product_type_for_update = $upd_type;
            }
        }

        // CS-08: ReDi toggle + tasa con validación de rango (independiente de CS-05)
        if ( 'yes' === get_option( 'ltms_redi_enabled' ) ) {
            $redi_enabled = ( isset( $_POST['redi_enabled'] ) && 'yes' === sanitize_key( $_POST['redi_enabled'] ) ) // phpcs:ignore
                ? 'yes' : 'no';
            update_post_meta( $product_id, '_ltms_redi_enabled', $redi_enabled );
            if ( isset( $_POST['redi_rate'] ) ) { // phpcs:ignore
                $redi_rate_pct = (float) sanitize_text_field( wp_unslash( $_POST['redi_rate'] ) ); // phpcs:ignore
                // redi_rate llega en % desde el frontend (ej: 15), convertir a decimal y clampar
                $redi_rate = LTMS_Business_Redi_Manager::clamp_redi_rate( $redi_rate_pct / 100 );
                update_post_meta( $product_id, '_ltms_redi_rate', $redi_rate );
            }
        }

        // CS-07: commission_rate es de exclusiva gestión del admin — no se acepta desde el frontend.

        // AUDIT-PROD-044: persistir campos de booking en edición (paridad con create_product).
        if ( $product_type_for_update === 'booking' ) {
            $booking_type  = sanitize_text_field( wp_unslash( $_POST['booking_type'] ?? 'accommodation' ) ); // phpcs:ignore
            $min_nights    = (int) ( $_POST['min_nights'] ?? 1 ); // phpcs:ignore
            $max_nights    = (int) ( $_POST['max_nights'] ?? 0 ); // phpcs:ignore
            $capacity      = (int) ( $_POST['booking_capacity'] ?? 1 ); // phpcs:ignore
            $checkin_time  = sanitize_text_field( wp_unslash( $_POST['checkin_time'] ?? '15:00' ) ); // phpcs:ignore
            $checkout_time = sanitize_text_field( wp_unslash( $_POST['checkout_time'] ?? '11:00' ) ); // phpcs:ignore
            $payment_mode  = sanitize_text_field( wp_unslash( $_POST['payment_mode'] ?? 'full' ) ); // phpcs:ignore
            $deposit_pct   = (float) ( $_POST['deposit_pct'] ?? 0 ); // phpcs:ignore

            // Actualizar el WC product type para que el calendario de reservas se renderice.
            wp_set_object_terms( $product_id, [ 'ltms_bookable' ], 'product_type' );

            update_post_meta( $product_id, '_ltms_booking_type', $booking_type );
            update_post_meta( $product_id, '_ltms_min_nights', max( 1, $min_nights ) );
            update_post_meta( $product_id, '_ltms_max_nights', $max_nights );
            update_post_meta( $product_id, '_ltms_capacity', max( 1, $capacity ) );
            update_post_meta( $product_id, '_ltms_checkin_time', $checkin_time );
            update_post_meta( $product_id, '_ltms_checkout_time', $checkout_time );
            update_post_meta( $product_id, '_ltms_payment_mode', $payment_mode );
            update_post_meta( $product_id, '_ltms_deposit_pct', $deposit_pct );
        } elseif ( $product_type_for_update && $product_type_for_update !== 'booking' ) {
            // Si el producto cambia de booking a otro tipo, limpiar metas de booking
            // y restaurar el WC product type a 'simple' para que el calendario no se renderice.
            $booking_metas = [ '_ltms_booking_type', '_ltms_min_nights', '_ltms_max_nights', '_ltms_capacity', '_ltms_checkin_time', '_ltms_checkout_time', '_ltms_payment_mode', '_ltms_deposit_pct' ];
            foreach ( $booking_metas as $bk_meta ) { delete_post_meta( $product_id, $bk_meta ); }
            if ( get_post_meta( $product_id, '_ltms_product_type', true ) === 'booking' ) {
                wp_set_object_terms( $product_id, [ 'simple' ], 'product_type' );
            }
        }

        // AUDIT-PROD-044: persistir variation_attributes en edición (paridad con create_product).
        // Si el tipo es 'variable' y llegan atributos, reconstruir variaciones; si era variable y
        // cambió a otro tipo, limpiar la taxonomía 'variable' del WC product type.
        $variation_attrs_raw_upd = isset( $_POST['variation_attributes'] ) ? wp_unslash( $_POST['variation_attributes'] ) : ''; // phpcs:ignore
        if ( $product_type_for_update === 'variable' && ! empty( $variation_attrs_raw_upd ) ) {
            $this->sync_variable_product( $product_id, $variation_attrs_raw_upd, (float) $price );
        } elseif ( $product_type_for_update && $product_type_for_update !== 'variable' ) {
            // Si era variable y se cambia a simple/bookable, restaurar el WC product type.
            $current_terms = wp_get_post_terms( $product_id, 'product_type', [ 'fields' => 'names' ] );
            if ( is_array( $current_terms ) && in_array( 'variable', $current_terms, true ) ) {
                $restore_type = ( $product_type_for_update === 'booking' ) ? 'ltms_bookable' : 'simple';
                wp_set_object_terms( $product_id, [ $restore_type ], 'product_type' );
            }
        }

        // AUDIT-PROD-H1 (re-auditoría): paridad digital/service en update_product (paridad con create_product lineas ~990-1011).
        // Si el tipo pasa a 'digital', marcar downloadable+virtual y procesar download_url.
        // Si pasa a 'service', marcar virtual.
        // Si cambia fuera de digital/service, limpiar esos flags (preservar si ya era el mismo tipo).
        $product_refreshed = wc_get_product( $product_id );

        // AUDIT-PROD-H3 (re-auditoría): persistir sku, short_description y shipping_class_id en
        // edición (paridad con create_product lineas ~960-1014). Antes el modal Edit no los
        // enviaba, así que cualquier valor creado con create NO se podía actualizar ni tampoco
        // se preservaba correctamente al cambiar otro campo.
        if ( $product_refreshed ) {
            // short_description (PROD-09)
            if ( isset( $_POST['short_description'] ) ) { // phpcs:ignore
                $upd_short_desc = sanitize_textarea_field( wp_unslash( $_POST['short_description'] ) ); // phpcs:ignore
                $product_refreshed->set_short_description( $upd_short_desc );
            }
            // sku (PROD-06) — try/catch para SKU duplicado, como en create_product.
            if ( isset( $_POST['sku'] ) ) { // phpcs:ignore
                $upd_sku = sanitize_text_field( wp_unslash( $_POST['sku'] ) ); // phpcs:ignore
                if ( $upd_sku === '' ) {
                    $product_refreshed->set_sku( '' );
                } else {
                    try {
                        $product_refreshed->set_sku( $upd_sku );
                    } catch ( \Throwable $e ) { /* SKU duplicado — ignorar como en create_product */ }
                }
            }
            // shipping_class_id (PROD-07)
            if ( isset( $_POST['shipping_class_id'] ) ) { // phpcs:ignore
                $upd_sc = absint( $_POST['shipping_class_id'] ); // phpcs:ignore
                $product_refreshed->set_shipping_class_id( $upd_sc );
            }
            // AUDIT-PROD-H6 (re-auditoría): NO re-aplicar set_weight() aquí. El peso ya se
            // persiste líneas arriba (línea 314: `if ( $weight !== null ) $product->set_weight( $weight )`)
            // sobre la misma entidad WC, y se persiste con $product->save() en línea 322.
            // El bloque previo `set_weight( $weight ?? '' )` era una regresión introducida por
            // el fix H3: cuando $_POST['weight'] llegaba vacío, $weight es null → escribía '' →
            // borraba el peso del producto. Eliminado.
            // tags (PROD-08): wp_set_post_terms con product_tag.
            if ( isset( $_POST['tags'] ) ) { // phpcs:ignore
                $upd_tags_raw = sanitize_text_field( wp_unslash( $_POST['tags'] ) ); // phpcs:ignore
                $upd_tag_slugs = array_filter( array_map( 'trim', explode( ',', $upd_tags_raw ) ) );
                if ( empty( $upd_tag_slugs ) ) {
                    wp_set_post_terms( $product_id, [], 'product_tag', false );
                } else {
                    // wp_set_post_terms acepta slugs/names en append=false (reemplaza).
                    wp_set_post_terms( $product_id, $upd_tag_slugs, 'product_tag', false );
                }
            }
            $product_refreshed->save();
        }

        if ( $product_refreshed ) {
            if ( $product_type_for_update === 'digital' ) {
                $product_refreshed->set_virtual( true );
                $product_refreshed->set_downloadable( true );
                $download_url_upd = isset( $_POST['download_url'] ) ? esc_url_raw( wp_unslash( $_POST['download_url'] ) ) : ''; // phpcs:ignore
                if ( $download_url_upd ) {
                    $download = new WC_Product_Download();
                    $download->set_id( md5( $download_url_upd ) );
                    $download->set_name( $product_refreshed->get_name() );
                    $download->set_file( $download_url_upd );
                    $product_refreshed->set_downloads( [ $download ] );

                    $download_limit_upd  = isset( $_POST['download_limit'] ) && $_POST['download_limit'] !== '' ? (int) $_POST['download_limit'] : -1; // phpcs:ignore
                    $download_expiry_upd = isset( $_POST['download_expiry'] ) && $_POST['download_expiry'] !== '' ? (int) $_POST['download_expiry'] : -1; // phpcs:ignore
                    $product_refreshed->set_download_limit( $download_limit_upd );
                    $product_refreshed->set_download_expiry( $download_expiry_upd );
                }
                $product_refreshed->save();
            } elseif ( $product_type_for_update === 'service' ) {
                $product_refreshed->set_virtual( true );
                // Un servicio no es descargable: si era digital antes, limpiar el flag.
                $product_refreshed->set_downloadable( false );
                $product_refreshed->set_downloads( [] );
                $product_refreshed->save();
            } elseif ( $product_type_for_update && $product_type_for_update !== 'digital' && $product_type_for_update !== 'service' ) {
                // Cambió de digital/service a otro tipo: limpiar virtual+downloadable+downloads.
                $old_meta_type = get_post_meta( $product_id, '_ltms_product_type', true );
                // Solo limpiar si antes era digital o service (evitar limpiar si el producto ya era physical/booking/...)
                if ( $old_meta_type === 'digital' || $old_meta_type === 'service' ) {
                    $product_refreshed->set_virtual( false );
                    $product_refreshed->set_downloadable( false );
                    $product_refreshed->set_downloads( [] );
                    $product_refreshed->save();
                }
            }
        }

        wp_send_json_success( [ 'message' => 'Producto actualizado' ] );
    }

    public function get_categories() {
        $this->check_nonce();
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ] );
        $cats  = [];
        foreach ( $terms as $t ) {
            $cats[] = [ 'id' => $t->term_id, 'name' => $t->name ];
        }
        wp_send_json_success( [ 'categories' => $cats ] );
    }

    /**
     * AUDIT-PROD-044: Sincroniza un producto variable con sus attributes + variations.
     *
     * Helper compartido por create_product() y update_product() para evitar duplicar
     * ~60 líneas de lógica de WC_Product_Attribute + wp_set_object_terms. Sobre-escribe
     * los atributos del producto y recrea las variaciones para el primer atributo,
     * asignando el precio del producto padre a cada variación (paridad con el path
     * original de create_product).
     *
     * @param int    $product_id         ID del producto padre.
     * @param string $variation_attrs_raw JSON string: [{ "name": "Talla", "values": ["S","M","L"] }, ...]
     * @param float  $base_price         Precio regular a asignar a cada variación.
     * @return void
     */
    private function sync_variable_product( $product_id, $variation_attrs_raw, $base_price ) {
        $variation_attrs = json_decode( $variation_attrs_raw, true );
        if ( ! is_array( $variation_attrs ) || empty( $variation_attrs ) ) {
            return;
        }

        // Convertir el producto a tipo 'variable' a nivel de WC.
        wp_set_object_terms( $product_id, [ 'variable' ], 'product_type' );

        $attributes = [];
        foreach ( $variation_attrs as $attr ) {
            $attr_name   = sanitize_text_field( $attr['name'] ?? '' );
            $attr_values = array_map( 'sanitize_text_field', (array) ( $attr['values'] ?? [] ) );
            if ( empty( $attr_name ) || empty( $attr_values ) ) {
                continue;
            }

            // Crear o reusar la taxonomía del atributo.
            $taxonomy = wc_attribute_taxonomy_name( $attr_name );
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $attr_id = wc_create_attribute( [
                    'name'  => $attr_name,
                    'slug'  => sanitize_title( $attr_name ),
                    'type'  => 'select',
                ] );
                if ( is_wp_error( $attr_id ) ) {
                    continue;
                }
                register_taxonomy( $taxonomy, 'product_variation', [ 'hierarchical' => false ] );
            }

            // Asignar términos al producto.
            $term_ids = [];
            foreach ( $attr_values as $val ) {
                $term = get_term_by( 'name', $val, $taxonomy );
                if ( ! $term ) {
                    $term = wp_insert_term( $val, $taxonomy );
                    if ( is_wp_error( $term ) ) {
                        continue;
                    }
                    $term_ids[] = $term['term_id'];
                } else {
                    $term_ids[] = $term->term_id;
                }
            }
            wp_set_post_terms( $product_id, $term_ids, $taxonomy );

            // Configurar el atributo en el producto.
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( wc_attribute_taxonomy_id_by_name( $attr_name ) );
            $attribute->set_name( $taxonomy );
            $attribute->set_options( $term_ids );
            $attribute->set_position( 0 );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $attributes[ $taxonomy ] = $attribute;
        }

        if ( empty( $attributes ) ) {
            return;
        }

        $variable_product = wc_get_product( $product_id );
        if ( ! $variable_product ) {
            return;
        }

        // AUDIT-PROD-H2 (re-auditoría): NO recrear variaciones si los atributos entrantes
        // son idénticos a los que ya tiene el producto — esto evita perder stock propio,
        // SKU y referencias en pedidos históricos al editar un producto variable sin
        // tocar los atributos (ej. solo cambia el título). Comparamos firmas canónicas:
        // [ 'taxonomy' => [ sorted term names ] ].
        $incoming_signature = [];
        foreach ( $variation_attrs as $attr ) {
            $name   = sanitize_text_field( $attr['name'] ?? '' );
            $values = array_map( 'sanitize_text_field', (array) ( $attr['values'] ?? [] ) );
            if ( empty( $name ) || empty( $values ) ) {
                continue;
            }
            $incoming_taxonomy = wc_attribute_taxonomy_name( $name );
            $sorted_values     = array_values( array_unique( $values ) );
            sort( $sorted_values );
            $incoming_signature[ $incoming_taxonomy ] = $sorted_values;
        }
        ksort( $incoming_signature );

        $existing_signature = [];
        if ( class_exists( 'WC_Product' ) && method_exists( $variable_product, 'get_attributes' ) ) {
            $existing_attrs = $variable_product->get_attributes();
            if ( is_array( $existing_attrs ) ) {
                foreach ( $existing_attrs as $taxonomy => $attr_obj ) {
                    // Solo nos interesan los atributos marcados para variación (set_variation(true)).
                    if ( $attr_obj instanceof \WC_Product_Attribute && $attr_obj->get_variation() ) {
                        $term_ids = $attr_obj->get_options();
                        $term_names = [];
                        if ( is_array( $term_ids ) ) {
                            foreach ( $term_ids as $tid ) {
                                $term = get_term_by( 'id', $tid, $taxonomy );
                                if ( $term && ! is_wp_error( $term ) ) {
                                    $term_names[] = $term->name;
                                }
                            }
                        }
                        sort( $term_names );
                        $existing_signature[ $taxonomy ] = $term_names;
                    }
                }
            }
        }
        ksort( $existing_signature );

        $variable_product->set_attributes( $attributes );
        $variable_product->save();

        // AUDIT-PROD-044 (update): si el producto ya tenía variaciones, eliminarlas antes
        // de recrearlas, para evitar variaciones huérfanas al cambiar los atributos.
        // AUDIT-PROD-H2 FIJ: SOLO recrear si los atributos realmente cambiaron.
        if ( $incoming_signature === $existing_signature ) {
            return; // Atributos idénticos: preservar variaciones existentes (stock, SKU, refs en pedidos).
        }

        $existing_variations = $variable_product->get_children();
        if ( is_array( $existing_variations ) ) {
            foreach ( $existing_variations as $var_id ) {
                wp_delete_post( $var_id, true );
            }
        }

        // Crear una variación por cada valor del primer atributo (paridad con create_product).
        $first_attr      = reset( $variation_attrs );
        $first_taxonomy  = wc_attribute_taxonomy_name( sanitize_text_field( $first_attr['name'] ?? '' ) );
        foreach ( $first_attr['values'] as $val ) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_regular_price( (string) $base_price );
            $variation->set_status( 'publish' );

            $term = get_term_by( 'name', sanitize_text_field( $val ), $first_taxonomy );
            if ( $term ) {
                $variation->set_attributes( [ $first_taxonomy => sanitize_title( $term->slug ) ] );
            }
            $variation->save();
        }
    }

    /**
     * Sube y optimiza una imagen de producto antes de guardarla en la Media Library.
     *
     * Proceso:
     *   1. Valida tipo MIME y tamaño (máx 10 MB)
     *   2. Redimensiona a máx 1200px de ancho manteniendo proporción
     *   3. Convierte a WebP si el servidor lo soporta (GD o Imagick)
     *   4. Si no soporta WebP, comprime JPEG/PNG al 82%
     *   5. Guarda el archivo optimizado y crea el attachment en WordPress
     *
     * Reducción típica: de 500 KB → 80-120 KB (75-85% menos peso)
     *
     * @return void
     */
    public function upload_product_image(): void {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        if ( empty( $_FILES['image'] ) || $_FILES['image']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( 'No se recibió ninguna imagen.', 400 );
        }

        $file = $_FILES['image']; // phpcs:ignore

        // ── 1. Validar tipo MIME ──────────────────────────────────────────────
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $finfo         = new finfo( FILEINFO_MIME_TYPE );
        $real_mime     = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $real_mime, $allowed_mimes, true ) ) {
            wp_send_json_error( sprintf( 'Tipo de archivo no permitido: %s', esc_html( $real_mime ) ), 415 );
        }

        // ── 2. Validar tamaño (máx 10 MB) ───────────────────────────────────
        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( 'La imagen supera el límite de 10 MB.', 413 );
        }

        // ── 3. Optimizar con GD o Imagick ───────────────────────────────────
        $optimized_path = $this->optimize_image( $file['tmp_name'], $real_mime );

        if ( $optimized_path && $optimized_path !== $file['tmp_name'] ) {
            // Reemplazar el archivo temporal con la versión optimizada
            // para que media_handle_upload procese la imagen comprimida.
            $original_tmp  = $file['tmp_name'];
            $original_name = $file['name'];

            // Cambiar extensión a .webp si se convirtió
            $new_ext = pathinfo( $optimized_path, PATHINFO_EXTENSION );
            if ( $new_ext === 'webp' ) {
                $file['name'] = pathinfo( $original_name, PATHINFO_FILENAME ) . '.webp';
            }

            // Sobrescribir el tmp_name para que WordPress lea el archivo optimizado
            copy( $optimized_path, $original_tmp );
            @unlink( $optimized_path ); // phpcs:ignore
            $file['size'] = filesize( $original_tmp );
            $_FILES['image'] = $file; // phpcs:ignore
        }

        // ── 4. Guardar en Media Library ──────────────────────────────────────
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'image', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            // HI-9 FIX: do not expose the raw WP_Error message — can leak
            // server paths (e.g. wp-content/uploads/...). Log server-side and
            // return a generic message.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'PRODUCT_IMAGE_UPLOAD_ERROR',
                    $attachment_id->get_error_message()
                );
            }
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
        }

        $final_url  = wp_get_attachment_url( $attachment_id );
        $final_size = filesize( get_attached_file( $attachment_id ) );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $final_url,
            'size_kb'       => round( $final_size / 1024 ),
        ] );
    }

    /**
     * v2.9.99: Upload store logo via AJAX (fallback when wp.media not available).
     * Reuses the same validation/optimization as upload_product_image but with field name 'file'.
     */
    public function upload_store_logo(): void {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( 'No se recibió ninguna imagen.', 400 );
        }

        $file = $_FILES['file']; // phpcs:ignore

        // Validar tipo MIME.
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $finfo         = new finfo( FILEINFO_MIME_TYPE );
        $real_mime     = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $real_mime, $allowed_mimes, true ) ) {
            wp_send_json_error( sprintf( 'Tipo de archivo no permitido: %s', esc_html( $real_mime ) ), 415 );
        }

        // Validar tamaño (máx 2 MB para logos).
        if ( $file['size'] > 2 * 1024 * 1024 ) {
            wp_send_json_error( 'El logo supera el límite de 2 MB.', 413 );
        }

        // Optimizar.
        $optimized_path = $this->optimize_image( $file['tmp_name'], $real_mime );
        if ( $optimized_path && $optimized_path !== $file['tmp_name'] ) {
            $original_tmp = $file['tmp_name'];
            $new_ext = pathinfo( $optimized_path, PATHINFO_EXTENSION );
            if ( 'webp' === $new_ext ) {
                $file['name'] = pathinfo( $file['name'], PATHINFO_FILENAME ) . '.webp';
            }
            copy( $optimized_path, $original_tmp );
            @unlink( $optimized_path ); // phpcs:ignore
            $file['size'] = filesize( $original_tmp );
            $_FILES['file'] = $file; // phpcs:ignore
        }

        // Guardar en Media Library.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Error al subir el logo.', 'ltms' ) ], 500 );
        }

        $final_url = wp_get_attachment_url( $attachment_id );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $final_url,
        ] );
    }

    /**
     * Optimiza una imagen: redimensiona a máx 1200px y convierte a WebP (o comprime JPEG).
     *
     * @param string $src_path  Ruta del archivo original.
     * @param string $mime      Tipo MIME original.
     * @return string|null      Ruta del archivo optimizado, o null si no fue posible.
     */
    private function optimize_image( string $src_path, string $mime ): ?string {
        $max_width   = 1200;
        $max_height  = 1200;
        $jpeg_quality = 82;
        $webp_quality = 82;

        // ── Intentar con Imagick (mejor calidad) ─────────────────────────────
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $imagick = new \Imagick( $src_path );
                $imagick->setImageOrientation( \Imagick::ORIENTATION_UNDEFINED );
                $imagick->autoOrient();

                $w = $imagick->getImageWidth();
                $h = $imagick->getImageHeight();

                // Redimensionar solo si supera el máximo
                if ( $w > $max_width || $h > $max_height ) {
                    $imagick->thumbnailImage( $max_width, $max_height, true, false );
                }

                $imagick->stripImage(); // Eliminar EXIF, GPS, etc.

                // Intentar WebP
                if ( $imagick->queryFormats( 'WEBP' ) ) {
                    $out_path = $src_path . '_opt.webp';
                    $imagick->setImageFormat( 'webp' );
                    $imagick->setImageCompressionQuality( $webp_quality );
                    $imagick->writeImage( $out_path );
                    $imagick->clear();
                    return $out_path;
                }

                // Fallback: comprimir JPEG
                $out_path = $src_path . '_opt.jpg';
                $imagick->setImageFormat( 'jpeg' );
                $imagick->setImageCompressionQuality( $jpeg_quality );
                $imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
                $imagick->writeImage( $out_path );
                $imagick->clear();
                return $out_path;

            } catch ( \Throwable $e ) {
                // Si Imagick falla, intentar con GD
            }
        }

        // ── Fallback: GD ─────────────────────────────────────────────────────
        if ( ! extension_loaded( 'gd' ) ) {
            return null; // Sin GD ni Imagick — no optimizar
        }

        $src_image = null;
        switch ( $mime ) {
            case 'image/jpeg': $src_image = @imagecreatefromjpeg( $src_path ); break; // phpcs:ignore
            case 'image/png':  $src_image = @imagecreatefrompng( $src_path );  break; // phpcs:ignore
            case 'image/gif':  $src_image = @imagecreatefromgif( $src_path );  break; // phpcs:ignore
            case 'image/webp': $src_image = @imagecreatefromwebp( $src_path ); break; // phpcs:ignore
        }

        if ( ! $src_image ) {
            return null;
        }

        $orig_w = imagesx( $src_image );
        $orig_h = imagesy( $src_image );

        // Calcular nuevas dimensiones manteniendo proporción
        $ratio  = min( $max_width / $orig_w, $max_height / $orig_h, 1.0 );
        $new_w  = (int) round( $orig_w * $ratio );
        $new_h  = (int) round( $orig_h * $ratio );

        $dst_image = imagecreatetruecolor( $new_w, $new_h );

        // Preservar transparencia para PNG
        if ( $mime === 'image/png' ) {
            imagealphablending( $dst_image, false );
            imagesavealpha( $dst_image, true );
            $transparent = imagecolorallocatealpha( $dst_image, 255, 255, 255, 127 );
            imagefilledrectangle( $dst_image, 0, 0, $new_w, $new_h, $transparent );
        }

        imagecopyresampled( $dst_image, $src_image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
        imagedestroy( $src_image );

        // Guardar como WebP si GD lo soporta
        if ( function_exists( 'imagewebp' ) ) {
            $out_path = $src_path . '_opt.webp';
            imagewebp( $dst_image, $out_path, $webp_quality );
            imagedestroy( $dst_image );
            return $out_path;
        }

        // Fallback final: JPEG comprimido
        $out_path = $src_path . '_opt.jpg';
        imagejpeg( $dst_image, $out_path, $jpeg_quality );
        imagedestroy( $dst_image );
        return $out_path;
    }

    public function create_product() {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        $name               = sanitize_text_field( $_POST['name'] ?? '' );
        $description        = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price              = floatval( $_POST['price'] ?? 0 );
        $sale_price_raw     = isset( $_POST['sale_price'] ) && $_POST['sale_price'] !== '' ? floatval( $_POST['sale_price'] ) : null;
        $stock              = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id        = intval( $_POST['category_id'] ?? 0 );
        $image_id           = intval( $_POST['image_id'] ?? 0 );
        $status             = sanitize_text_field( $_POST['status'] ?? 'pending' ); // phpcs:ignore
        // PROD-QA-01 FIX: si el vendor tiene KYC aprobado y solicita 'publish',
        // permitirlo. Si no tiene KYC, forzar 'pending' (no puede publicar directamente).
        // Antes, el JS siempre enviaba 'pending' incluso cuando el botón decía
        // "Publicar Producto", causando que los productos no se vieran en el storefront.
        $kyc_status = get_user_meta( get_current_user_id(), 'ltms_kyc_status', true );
        if ( $status === 'publish' && $kyc_status !== 'approved' ) {
            $status = 'pending'; // Sin KYC aprobado, no puede publicar directamente.
        }
        $catalog_visibility = sanitize_key( $_POST['catalog_visibility'] ?? 'visible' );
        $weight             = isset( $_POST['weight'] ) && $_POST['weight'] !== '' ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : null;
        $dim_length         = isset( $_POST['dim_length'] ) && $_POST['dim_length'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_length'] ) ) : null;
        $dim_width          = isset( $_POST['dim_width'] )  && $_POST['dim_width']  !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_width'] ) )  : null;
        $dim_height         = isset( $_POST['dim_height'] ) && $_POST['dim_height'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_height'] ) ) : null;

        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }

        // v2.9.62 DEEP-AUDIT-002 P2-6: Validar que la categoría existe.
        if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
            wp_send_json_error( 'Categoría inválida', 400 );
        }

        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        // CS-09: precio de oferta
        if ( $sale_price_raw !== null && $sale_price_raw > 0 && $sale_price_raw < $price ) {
            $product->set_sale_price( (string) $sale_price_raw );
        }
        $product->set_status( $status );
        if ( in_array( $catalog_visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $product->set_catalog_visibility( $catalog_visibility );
        }
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
        if ( $weight !== null )     $product->set_weight( $weight );
        if ( $dim_length !== null ) $product->set_length( $dim_length );
        if ( $dim_width  !== null ) $product->set_width( $dim_width );
        if ( $dim_height !== null ) $product->set_height( $dim_height );
        if ( $category_id ) {
            $product->set_category_ids( [ $category_id ] );
        }
        if ( $image_id ) {
            $product->set_image_id( $image_id );
        }
        $gallery_ids = isset( $_POST['gallery_ids'] ) ? array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) ) : [];
        if ( ! empty( $gallery_ids ) ) { $product->set_gallery_image_ids( $gallery_ids ); }
        // Asignar al vendedor actual
        $product_id = $product->save();
        $current_user_id = get_current_user_id();
        wp_update_post( [ 'ID' => $product_id, 'post_author' => $current_user_id ] );
        // M-12 FIX: guardar _ltms_vendor_id para que los pedidos del producto
        // aparezcan en el dashboard del vendedor (get_vendor_orders filtra por esta meta).
        update_post_meta( $product_id, '_ltms_vendor_id', $current_user_id );

        // CS-05 + PROD-02: guardar tipo (physical/digital/service/booking/restaurant/variable)
        $product_type = sanitize_key( $_POST['product_type'] ?? 'physical' ); // phpcs:ignore
        if ( $product_type === 'product' || ! in_array( $product_type, [ 'physical', 'digital', 'service', 'booking', 'restaurant', 'variable' ], true ) ) {
            $product_type = 'physical';
        }
        update_post_meta( $product_id, '_ltms_product_type', $product_type );

        // v2.9.285 FIX: si el tipo es 'booking' (turismo), convertir el producto WC
        // a tipo 'ltms_bookable' para que el calendario de reservas aparezca.
        // Antes solo se guardaba la meta _ltms_product_type pero el WC product type
        // seguía siendo 'simple', por lo que el calendario nunca se renderizaba.
        if ( $product_type === 'booking' ) {
            wp_set_object_terms( $product_id, [ 'ltms_bookable' ], 'product_type' );

            // Guardar campos de booking si vienen en la petición
            $booking_type    = sanitize_text_field( wp_unslash( $_POST['booking_type'] ?? 'accommodation' ) ); // phpcs:ignore
            $min_nights      = (int) ( $_POST['min_nights'] ?? 1 ); // phpcs:ignore
            $max_nights      = (int) ( $_POST['max_nights'] ?? 0 ); // phpcs:ignore
            $capacity        = (int) ( $_POST['booking_capacity'] ?? 1 ); // phpcs:ignore
            $checkin_time    = sanitize_text_field( wp_unslash( $_POST['checkin_time'] ?? '15:00' ) ); // phpcs:ignore
            $checkout_time   = sanitize_text_field( wp_unslash( $_POST['checkout_time'] ?? '11:00' ) ); // phpcs:ignore
            $payment_mode    = sanitize_text_field( wp_unslash( $_POST['payment_mode'] ?? 'full' ) ); // phpcs:ignore
            $deposit_pct     = (float) ( $_POST['deposit_pct'] ?? 0 ); // phpcs:ignore

            update_post_meta( $product_id, '_ltms_booking_type', $booking_type );
            update_post_meta( $product_id, '_ltms_min_nights', max( 1, $min_nights ) );
            update_post_meta( $product_id, '_ltms_max_nights', $max_nights );
            update_post_meta( $product_id, '_ltms_capacity', max( 1, $capacity ) );
            update_post_meta( $product_id, '_ltms_checkin_time', $checkin_time );
            update_post_meta( $product_id, '_ltms_checkout_time', $checkout_time );
            update_post_meta( $product_id, '_ltms_payment_mode', $payment_mode );
            update_post_meta( $product_id, '_ltms_deposit_pct', $deposit_pct );
        }

        // PROD-02: Crear variaciones si el tipo es 'variable'
        $variation_attrs_raw = isset( $_POST['variation_attributes'] ) ? wp_unslash( $_POST['variation_attributes'] ) : ''; // phpcs:ignore
        if ( $product_type === 'variable' && ! empty( $variation_attrs_raw ) ) {
            $variation_attrs = json_decode( $variation_attrs_raw, true );
            if ( is_array( $variation_attrs ) && ! empty( $variation_attrs ) ) {
                // Convertir el producto simple a variable
                // WC no permite cambiar el tipo directamente, pero podemos usar wp_set_object_terms
                wp_set_object_terms( $product_id, [ 'variable' ], 'product_type' );

                // Crear atributos
                $attributes = [];
                foreach ( $variation_attrs as $attr ) {
                    $attr_name = sanitize_text_field( $attr['name'] );
                    $attr_values = array_map( 'sanitize_text_field', $attr['values'] );
                    if ( empty( $attr_name ) || empty( $attr_values ) ) continue;

                    // Crear o obtener la taxonomía de atributo
                    $taxonomy = wc_attribute_taxonomy_name( $attr_name );
                    if ( ! taxonomy_exists( $taxonomy ) ) {
                        $attr_id = wc_create_attribute( [ 'name' => $attr_name, 'slug' => sanitize_title( $attr_name ), 'type' => 'select' ] );
                        if ( is_wp_error( $attr_id ) ) continue;
                        register_taxonomy( $taxonomy, 'product_variation', [ 'hierarchical' => false ] );
                    }

                    // Asignar términos al producto
                    $term_ids = [];
                    foreach ( $attr_values as $val ) {
                        $term = get_term_by( 'name', $val, $taxonomy );
                        if ( ! $term ) {
                            $term = wp_insert_term( $val, $taxonomy );
                            if ( is_wp_error( $term ) ) continue;
                            $term_ids[] = $term['term_id'];
                        } else {
                            $term_ids[] = $term->term_id;
                        }
                    }
                    wp_set_post_terms( $product_id, $term_ids, $taxonomy );

                    // Configurar el atributo en el producto
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_id( wc_attribute_taxonomy_id_by_name( $attr_name ) );
                    $attribute->set_name( $taxonomy );
                    $attribute->set_options( $term_ids );
                    $attribute->set_position( 0 );
                    $attribute->set_visible( true );
                    $attribute->set_variation( true );
                    $attributes[ $taxonomy ] = $attribute;
                }

                if ( ! empty( $attributes ) ) {
                    $variable_product = wc_get_product( $product_id );
                    if ( $variable_product ) {
                        $variable_product->set_attributes( $attributes );
                        $variable_product->save();

                        // Crear variaciones (una por combinación de valores)
                        $base_price = (float) $price;
                        // Para simplicidad: crear una variación por cada valor del primer atributo
                        $first_attr = reset( $variation_attrs );
                        $first_taxonomy = wc_attribute_taxonomy_name( sanitize_text_field( $first_attr['name'] ) );
                        foreach ( $first_attr['values'] as $val ) {
                            $variation = new WC_Product_Variation();
                            $variation->set_parent_id( $product_id );
                            $variation->set_regular_price( (string) $base_price );
                            $variation->set_status( 'publish' );

                            // Asignar el atributo a la variación
                            $term = get_term_by( 'name', sanitize_text_field( $val ), $first_taxonomy );
                            if ( $term ) {
                                $variation->set_attributes( [ $first_taxonomy => sanitize_title( $term->slug ) ] );
                            }
                            $variation->save();
                        }
                    }
                }
            }
        }

        // PROD-09: Short description (excerpt)
        $short_desc = isset( $_POST['short_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['short_description'] ) ) : ''; // phpcs:ignore
        if ( $short_desc ) {
            $product->set_short_description( $short_desc );
            $product->save();
        }

        // PROD-06: SKU
        $sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : ''; // phpcs:ignore
        if ( $sku ) {
            try { $product->set_sku( $sku ); $product->save(); } catch ( \Throwable $e ) { /* SKU duplicado — ignorar */ }
        }

        // PROD-07: Shipping class
        $shipping_class_id = isset( $_POST['shipping_class_id'] ) ? absint( $_POST['shipping_class_id'] ) : 0; // phpcs:ignore
        if ( $shipping_class_id ) {
            $product->set_shipping_class_id( $shipping_class_id );
            $product->save();
        }

        // PROD-12: Virtual para servicios
        if ( $product_type === 'service' ) {
            $product->set_virtual( true );
            $product->save();
        }

        // PROD-03: Archivo descargable para productos digitales
        if ( $product_type === 'digital' ) {
            $download_url = isset( $_POST['download_url'] ) ? esc_url_raw( wp_unslash( $_POST['download_url'] ) ) : ''; // phpcs:ignore
            if ( $download_url ) {
                $product->set_downloadable( true );
                $download = new WC_Product_Download();
                $download->set_id( md5( $download_url ) );
                $download->set_name( $name );
                $download->set_file( $download_url );
                $product->set_downloads( [ $download ] );
                $download_limit = isset( $_POST['download_limit'] ) ? absint( $_POST['download_limit'] ) : 0; // phpcs:ignore
                $download_expiry = isset( $_POST['download_expiry'] ) ? absint( $_POST['download_expiry'] ) : 0; // phpcs:ignore
                $product->set_download_limit( $download_limit );
                $product->set_download_expiry( $download_expiry );
                $product->set_virtual( true );
                $product->save();
            }
        }

        // PROD-08: Tags
        $tags_raw = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : ''; // phpcs:ignore
        if ( $tags_raw ) {
            $tags = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
            if ( ! empty( $tags ) ) {
                wp_set_object_terms( $product_id, $tags, 'product_tag' );
            }
        }

        // CS-08: ReDi toggle + tasa con validación de rango
        if ( 'yes' === get_option( 'ltms_redi_enabled' ) ) {
            $redi_enabled = ( isset( $_POST['redi_enabled'] ) && 'yes' === sanitize_key( $_POST['redi_enabled'] ) ) // phpcs:ignore
                ? 'yes' : 'no';
            update_post_meta( $product_id, '_ltms_redi_enabled', $redi_enabled );
            if ( 'yes' === $redi_enabled && isset( $_POST['redi_rate'] ) ) { // phpcs:ignore
                $redi_rate_pct = (float) sanitize_text_field( wp_unslash( $_POST['redi_rate'] ) ); // phpcs:ignore
                $redi_rate = LTMS_Business_Redi_Manager::clamp_redi_rate( $redi_rate_pct / 100 );
                update_post_meta( $product_id, '_ltms_redi_rate', $redi_rate );
            }
        }

        // CS-07: commission_rate solo configurable por admin (LTMS_Commission_Strategy),
        // nunca desde el panel del vendedor. Se elimina la escritura desde el frontend.

        wp_send_json_success( [
            'product_id'   => $product_id,
            'product_type' => $product_type,
            'message'      => 'Producto creado exitosamente',
        ] );
    }


    public function toggle_product_status() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $new_status = sanitize_text_field( $_POST['new_status'] ?? '' );
        if ( ! in_array( $new_status, [ 'publish', 'draft', 'pending' ] ) ) {
            wp_send_json_error( 'Estado no valido', 400 );
        }
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado o sin permiso', 403 );
        }
        $product->set_status( $new_status );
        $product->save();
        wp_send_json_success( [ 'message' => 'Estado actualizado', 'status' => $new_status ] );
    }

    public function delete_product() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado o sin permiso', 403 );
        }
        // v2.9.62 DEEP-AUDIT-002 P2-7: Usar wp_trash_post en vez de wp_delete_post(true).
        // Antes se hacía force-delete (true) lo que borraba permanentemente el producto
        // sin posibilidad de recuperación. Ahora va a la papelera de reciclaje.
        $result = wp_trash_post( $product_id );
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Producto movido a papelera' ] );
        } else {
            wp_send_json_error( 'No se pudo eliminar el producto' );
        }
    }

}

// Nota: LTMS_Products_Ajax se instancia en LTMS_Core_Kernel::boot_frontend().
// No instanciar aquí para evitar el registro triple de hooks AJAX.

// C5-2 FIX: Solo añadir 'read' para que WP procese el AJAX de productos.
// NO permitir acceso al wp-admin UI — solo wp-admin/admin-ajax.php.
add_filter( 'user_has_cap', function( $caps, $cap_list, $args ) {
    if ( ! empty( $caps['edit_products'] ) && LTMS_Utils::is_ltms_vendor( $args[1] ?? 0 ) ) {
        $caps['read'] = true;
    }
    return $caps;
}, 10, 3 );

// C5-2 FIX: Bloquear acceso al wp-admin para vendedores LTMS.
// WooCommerce llama a este filtro en admin_init; si devuelve true, redirige al frontend.
// Permitimos únicamente las peticiones AJAX (wp-admin/admin-ajax.php).
add_filter( 'woocommerce_prevent_admin_access', function( $prevent ) {
    if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
        // No es AJAX — si el usuario es vendedor LTMS, bloquear acceso al panel.
        if ( LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            return true; // Prevenir acceso al wp-admin
        }
    }
    return $prevent;
} );

