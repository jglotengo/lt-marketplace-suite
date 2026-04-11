<?php
class LTMS_Admin_Marketing_Manager {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        $instance = new self();
        add_action( 'wp_ajax_ltms_create_promo_coupon',    [ $instance, 'ajax_create_promo_coupon' ] );
        add_action( 'wp_ajax_ltms_get_mlm_tree_data',      [ $instance, 'ajax_get_mlm_tree_data' ] );
        add_action( 'wp_ajax_ltms_toggle_mlm_enabled',     [ $instance, 'ajax_toggle_mlm' ] );
        add_action( 'wp_ajax_ltms_get_campaign_stats',     [ $instance, 'ajax_get_campaign_stats' ] );
    }

    /**
     * AJAX: crea un cupón promocional masivo.
     *
     * @return void
     */
    public function ajax_create_promo_coupon(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $code        = strtoupper( sanitize_text_field( $_POST['code'] ?? '' ) );
        $discount    = (float) ( $_POST['discount'] ?? 0 );
        $type        = sanitize_key( $_POST['type'] ?? 'percent' ); // percent | fixed_cart
        $expires     = sanitize_text_field( $_POST['expires'] ?? '' );
        $usage_limit = absint( $_POST['usage_limit'] ?? 0 );

        if ( ! $code || $discount <= 0 ) {
            wp_send_json_error( __( 'Código y descuento son requeridos.', 'ltms' ) );
        }

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $discount );
        if ( $expires ) {
            $coupon->set_date_expires( strtotime( $expires ) );
        }
        if ( $usage_limit ) {
            $coupon->set_usage_limit( $usage_limit );
        }
        $coupon->save();

        wp_send_json_success( [
            'coupon_id' => $coupon->get_id(),
            'code'      => $code,
            'message'   => __( 'Cupón creado exitosamente.', 'ltms' ),
        ] );
    }

    /**
     * AJAX: devuelve datos del árbol MLM para visualización.
     *
     * @return void
     */
    public function ajax_get_mlm_tree_data(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );

        if ( class_exists( 'LTMS_Referral_Tree' ) ) {
            $tree = LTMS_Referral_Tree::get_tree( $vendor_id );
            wp_send_json_success( [ 'tree' => $tree ] );
        }

        wp_send_json_success( [ 'tree' => [] ] );
    }

    /**
     * AJAX: activa/desactiva el sistema MLM.
     *
     * @return void
     */
    public function ajax_toggle_mlm(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        $enabled = sanitize_key( $_POST['enabled'] ?? 'no' );
        update_option( 'ltms_mlm_enabled', in_array( $enabled, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no' );
        wp_send_json_success( [ 'mlm_enabled' => get_option( 'ltms_mlm_enabled' ) ] );
    }

    /**
     * AJAX: estadísticas de campaña.
     *
     * @return void
     */
    public function ajax_get_campaign_stats(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $coupon_code = sanitize_text_field( $_POST['coupon_code'] ?? '' );
        $coupon      = new WC_Coupon( $coupon_code );

        wp_send_json_success( [
            'usage_count' => $coupon->get_usage_count(),
            'usage_limit' => $coupon->get_usage_limit(),
            'amount'      => $coupon->get_amount(),
            'type'        => $coupon->get_discount_type(),
        ] );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BANK RECONCILER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Bank_Reconciler
 *
 * Conciliación bancaria: compara las transacciones registradas en LTMS
 * con extractos bancarios importados (CSV) para detectar inconsistencias.
 */
