<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Products_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ltms_get_products_data',    [ $this, 'get_products_data' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings', [ $this, 'save_vendor_settings' ] );
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
    }

    public function get_products_data() {
        $this->check_nonce();
        $user_id  = get_current_user_id();
        $args     = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => 50,
        ];
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
                    'image'   => ( $product && $product->get_image_id() ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
                    'edit_url' => get_edit_post_link( $p->ID, 'raw' ),
            ];
        }
        wp_send_json_success( [ 'products' => $products ] );
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
        wp_send_json_success( [ 'kyc_status' => $kyc_status, 'store' => $store ] );
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
                'ltms_bank_name', 'ltms_bank_account_type', 'ltms_payment_method',
                'ltms_shipping_policy', 'ltms_return_policy',
                'ltms_tax_regime', 'ltms_nit', 'ltms_ciiu_code', 'ltms_municipality',
            ];
            foreach ( $allowed as $field ) {
                if ( isset( $_POST['settings'][ $field ] ) ) { // phpcs:ignore
                    $settings_map[ $field ] = $_POST['settings'][ $field ]; // phpcs:ignore
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
            if ( $value !== null ) {
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
            'id'          => $product_id,
            'name'        => $product->get_name(),
            'description' => $product->get_description(),
            'price'       => $product->get_regular_price(),
            'stock'       => $product->get_stock_quantity(),
            'status'      => $product->get_status(),
            'category_id' => ! empty( $cats ) ? $cats[0] : 0,
            'image_id'    => $product->get_image_id(),
            'image_url'   => $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
            'gallery_ids' => $product->get_gallery_image_ids(),
            'gallery_urls'=> array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
        ] );
    }

    public function update_product() {
        $this->check_nonce();
        $product_id  = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado', 404 );
        }
        $name        = sanitize_text_field( $_POST['name'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price       = floatval( $_POST['price'] ?? 0 );
        $stock       = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id = intval( $_POST['category_id'] ?? 0 );
        $image_id    = intval( $_POST['image_id'] ?? 0 );
        $status      = sanitize_text_field( $_POST['status'] ?? $product->get_status() );
        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }
        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        $product->set_status( $status );
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
        if ( $category_id ) $product->set_category_ids( [ $category_id ] );
        if ( $image_id )    $product->set_image_id( $image_id );
        $gallery_ids = isset( $_POST['gallery_ids'] ) ? array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) ) : null;
        if ( $gallery_ids !== null ) { $product->set_gallery_image_ids( $gallery_ids ); }
        $product->save();

        // M-23 FIX: re-guardar _ltms_vendor_id después de cada actualización.
        // $product->save() de WooCommerce puede eliminar metas no gestionadas
        // por WC, lo que haría que el producto dejara de aparecer en pedidos del dashboard.
        update_post_meta( $product_id, '_ltms_vendor_id', get_current_user_id() );

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

    public function upload_product_image() {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( 'No image', 400 );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'image', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( $attachment_id->get_error_message(), 500 );
        }
        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    public function create_product() {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        $name        = sanitize_text_field( $_POST['name'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price       = floatval( $_POST['price'] ?? 0 );
        $stock       = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id = intval( $_POST['category_id'] ?? 0 );
        $image_id    = intval( $_POST['image_id'] ?? 0 );
        $status      = sanitize_text_field( $_POST['status'] ?? 'pending' );

        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }

        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        $product->set_status( $status );
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
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

        wp_send_json_success( [
            'product_id' => $product_id,
            'message'    => 'Producto creado exitosamente',
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
        $result = wp_delete_post( $product_id, true );
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Producto eliminado' ] );
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
