<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Products_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ltms_get_products_data',    [ $this, 'get_products_data' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings', [ $this, 'save_vendor_settings' ] );
        add_action( 'wp_ajax_ltms_get_vendor_settings',  [ $this, 'get_vendor_settings' ] );
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
}

new LTMS_Products_Ajax();
