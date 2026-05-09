<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Products_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ltms_get_products_data',    [ $this, 'get_products_data' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings', [ $this, 'save_vendor_settings' ] );
        add_action( 'wp_ajax_ltms_get_vendor_settings',  [ $this, 'get_vendor_settings' ] );
        add_action( 'wp_ajax_ltms_create_product',        [ $this, 'create_product' ] );
        add_action( 'wp_ajax_ltms_get_categories',        [ $this, 'get_categories' ] );
        add_action( 'wp_ajax_ltms_upload_product_image',  [ $this, 'upload_product_image' ] );
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
                'edit_url' => get_edit_post_link( $p->ID, 'raw' ),
            ];
        }
        wp_send_json_success( [ 'products' => $products ] );
    }

    public function get_vendor_settings() {
        $this->check_nonce();
        $user_id    = get_current_user_id();
        $kyc_status = get_user_meta( $user_id, 'ltms_kyc_status', true ) ?: 'pending';
        $store      = [
            'name'        => get_user_meta( $user_id, 'ltms_store_name', true ),
            'phone'       => get_user_meta( $user_id, 'ltms_store_phone', true ),
            'description' => get_user_meta( $user_id, 'ltms_store_description', true ),
            'bank_info'   => get_user_meta( $user_id, 'ltms_bank_info', true ),
        ];
        wp_send_json_success( [ 'kyc_status' => $kyc_status, 'store' => $store ] );
    }

    public function save_vendor_settings() {
        $this->check_nonce();
        $user_id = get_current_user_id();
        $fields  = [ 'store_name', 'store_phone', 'store_description', 'bank_info' ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_user_meta( $user_id, 'ltms_' . $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }
        wp_send_json_success( [ 'message' => 'Guardado' ] );
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
        if ( ! current_user_can( 'edit_products' ) ) {
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
        if ( ! current_user_can( 'edit_products' ) ) {
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
        // Asignar al vendedor actual
        $product_id = $product->save();
        wp_update_post( [ 'ID' => $product_id, 'post_author' => get_current_user_id() ] );

        wp_send_json_success( [
            'product_id' => $product_id,
            'message'    => 'Producto creado exitosamente',
        ] );
    }

}

add_action( 'plugins_loaded', function() { new LTMS_Products_Ajax(); }, 20 );

// Permitir acceso al wp-admin para crear/editar productos
add_filter('user_has_cap', function($caps, $cap_list, $args) {
    if (!empty($caps['edit_products'])) {
        $caps['read'] = true;
    }
    return $caps;
}, 10, 3);

add_filter('woocommerce_prevent_admin_access', function($prevent) {
    if (current_user_can('edit_products')) {
        return false;
    }
    return $prevent;
});
