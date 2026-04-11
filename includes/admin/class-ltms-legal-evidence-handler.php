<?php
class LTMS_Legal_Evidence_Handler {

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
        add_action( 'wp_ajax_ltms_export_legal_evidence', [ $instance, 'ajax_export_evidence' ] );
        add_action( 'wp_ajax_ltms_snapshot_order',        [ $instance, 'ajax_snapshot_order' ] );

        // Auto-snapshot en cambios de estado relevantes
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'auto_snapshot_on_status_change' ], 10, 3 );
    }

    /**
     * AJAX: exporta el paquete de evidencias de un pedido en ZIP.
     *
     * @return void
     */
    public function ajax_export_evidence(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( __( 'ID de pedido requerido.', 'ltms' ) );
        }

        // Generar token de descarga del ZIP de evidencias
        $token = wp_generate_password( 48, false );
        set_transient( 'ltms_evidence_' . md5( $token ), [
            'order_id' => $order_id,
            'user_id'  => get_current_user_id(),
        ], 300 );

        wp_send_json_success( [
            'download_url' => add_query_arg( [ 'ltms_evidence' => $token ], admin_url( 'admin-ajax.php' ) ),
            'message'      => __( 'Paquete de evidencias listo para descargar.', 'ltms' ),
        ] );
    }

    /**
     * AJAX: crea un snapshot del estado actual del pedido.
     *
     * @return void
     */
    public function ajax_snapshot_order(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        self::create_snapshot( $order_id, 'manual', get_current_user_id() );

        wp_send_json_success( [ 'message' => __( 'Snapshot creado.', 'ltms' ) ] );
    }

    /**
     * Crea un snapshot legal del pedido.
     *
     * @param int    $order_id  ID del pedido.
     * @param string $trigger   Motivo (manual, status_change, dispute).
     * @param int    $actor_id  Usuario que desencadenó el snapshot.
     * @return void
     */
    public static function create_snapshot( int $order_id, string $trigger = 'auto', int $actor_id = 0 ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_legal_snapshots';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return;
        }

        $snapshot = [
            'order_id'        => $order_id,
            'status'          => $order->get_status(),
            'total'           => $order->get_total(),
            'vendor_id'       => (int) $order->get_meta( '_ltms_vendor_id' ),
            'customer_email'  => $order->get_billing_email(),
            'items_json'      => wp_json_encode( array_map( fn($i) => [ 'name' => $i->get_name(), 'qty' => $i->get_quantity(), 'total' => $i->get_total() ], $order->get_items() ) ),
            'meta_json'       => wp_json_encode( $order->get_meta_data() ),
            'trigger'         => sanitize_key( $trigger ),
            'actor_id'        => $actor_id,
            'ip_address'      => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, $snapshot );
    }

    /**
     * Hook: auto-snapshot en cambios de estado clave.
     *
     * @param int    $order_id
     * @param string $old_status
     * @param string $new_status
     * @return void
     */
    public static function auto_snapshot_on_status_change( int $order_id, string $old_status, string $new_status ): void {
        $key_statuses = [ 'completed', 'refunded', 'cancelled', 'on-hold' ];
        if ( in_array( $new_status, $key_statuses, true ) ) {
            self::create_snapshot( $order_id, 'status_change_' . $new_status );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN SAT REPORT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Admin_SAT_Report
 *
 * Genera reportes fiscales para el SAT de México y para la DIAN de Colombia
 * en formato XML y CSV según los estándares de cada entidad.
 */
