<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Admin_Redi {
    use LTMS_Logger_Aware;

    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_approve_redi_agreement',  [ $instance, 'ajax_approve_agreement' ] );
        add_action( 'wp_ajax_ltms_revoke_redi_agreement',   [ $instance, 'ajax_revoke_agreement' ] );
        add_action( 'wp_ajax_ltms_export_redi_commissions', [ $instance, 'ajax_export_redi_commissions' ] );
        add_action( 'wp_ajax_ltms_mark_pickup_completed',   [ $instance, 'ajax_mark_pickup_completed' ] );
    }

    public function ajax_approve_agreement(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_all_vendors' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        global $wpdb;
        $id = absint( $_POST['agreement_id'] ?? 0 ); // phpcs:ignore
        if ( ! $id ) { wp_send_json_error( 'ID inválido.' ); }
        $wpdb->update( // phpcs:ignore
            $wpdb->prefix . 'lt_redi_agreements',
            [ 'status' => 'active', 'updated_at' => LTMS_Utils::now_utc() ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        LTMS_Core_Logger::info( 'REDI_AGREEMENT_APPROVED', "Agreement #$id approved by admin #" . get_current_user_id() );
        wp_send_json_success( [ 'message' => __( 'Acuerdo aprobado.', 'ltms' ) ] );
    }

    public function ajax_revoke_agreement(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_all_vendors' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        global $wpdb;
        $id     = absint( $_POST['agreement_id'] ?? 0 ); // phpcs:ignore
        $reason = sanitize_text_field( $_POST['reason'] ?? '' ); // phpcs:ignore
        if ( ! $id ) { wp_send_json_error( 'ID inválido.' ); }
        $wpdb->update( // phpcs:ignore
            $wpdb->prefix . 'lt_redi_agreements',
            [ 'status' => 'revoked', 'revoked_at' => LTMS_Utils::now_utc(), 'revocation_reason' => $reason, 'updated_at' => LTMS_Utils::now_utc() ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        LTMS_Core_Logger::info( 'REDI_AGREEMENT_REVOKED', "Agreement #$id revoked. Reason: $reason" );
        wp_send_json_success( [ 'message' => __( 'Acuerdo revocado.', 'ltms' ) ] );
    }

    public function ajax_export_redi_commissions(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_view_tax_reports' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT * FROM `{$wpdb->prefix}lt_redi_commissions` ORDER BY created_at DESC LIMIT 1000"
        );
        // Build CSV
        $csv = "ID,Pedido,Origen,Revendedor,Bruto,Fee,Comision,NetoOrigen,Retencion,Estado,Fecha\n";
        foreach ( $rows as $r ) {
            $csv .= implode( ',', [
                $r->id, $r->order_id, $r->origin_vendor_id, $r->reseller_vendor_id,
                $r->gross_amount, $r->platform_fee, $r->reseller_commission,
                $r->origin_vendor_net, $r->tax_withholding, $r->status, $r->created_at
            ] ) . "\n";
        }
        wp_send_json_success( [ 'csv' => $csv ] );
    }

    public function ajax_mark_pickup_completed( ): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_view_all_orders' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        $order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore
        $order    = wc_get_order( $order_id );
        if ( ! $order ) { wp_send_json_error( __( 'Pedido no encontrado.', 'ltms' ) ); }
        $order->update_status( 'completed', __( 'Recogido por el cliente.', 'ltms' ) );
        wp_send_json_success( [ 'message' => __( 'Pedido marcado como completado.', 'ltms' ) ] );
    }
}
