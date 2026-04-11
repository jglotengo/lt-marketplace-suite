<?php
class LTMS_Bank_Reconciler {

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
        add_action( 'wp_ajax_ltms_import_bank_statement', [ $instance, 'ajax_import_statement' ] );
        add_action( 'wp_ajax_ltms_get_reconciliation',    [ $instance, 'ajax_get_reconciliation' ] );
        add_action( 'wp_ajax_ltms_mark_reconciled',       [ $instance, 'ajax_mark_reconciled' ] );
    }

    /**
     * AJAX: importa extracto bancario en CSV.
     *
     * @return void
     */
    public function ajax_import_statement(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        if ( empty( $_FILES['csv_file'] ) ) { // phpcs:ignore
            wp_send_json_error( __( 'No se recibió archivo CSV.', 'ltms' ) );
        }

        $tmp = sanitize_text_field( $_FILES['csv_file']['tmp_name'] ); // phpcs:ignore
        if ( ! file_exists( $tmp ) ) {
            wp_send_json_error( __( 'Error al subir el archivo.', 'ltms' ) );
        }

        $rows    = [];
        $handle  = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        $headers = fgetcsv( $handle );

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) >= 3 ) {
                $rows[] = [
                    'date'        => sanitize_text_field( $row[0] ?? '' ),
                    'description' => sanitize_text_field( $row[1] ?? '' ),
                    'amount'      => (float) str_replace( [ '.', ',' ], [ '', '.' ], $row[2] ?? '0' ),
                    'reference'   => sanitize_text_field( $row[3] ?? '' ),
                ];
            }
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

        // Almacenar en sesión transitoria para la reconciliación
        set_transient( 'ltms_bank_import_' . get_current_user_id(), $rows, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'rows_imported' => count( $rows ),
            'message'       => sprintf( __( '%d transacciones importadas.', 'ltms' ), count( $rows ) ),
        ] );
    }

    /**
     * AJAX: ejecuta la reconciliación y devuelve el reporte de diferencias.
     *
     * @return void
     */
    public function ajax_get_reconciliation(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $bank_rows = get_transient( 'ltms_bank_import_' . get_current_user_id() );
        if ( ! $bank_rows ) {
            wp_send_json_error( __( 'No hay datos bancarios importados. Sube un CSV primero.', 'ltms' ) );
        }

        $date_from = sanitize_text_field( $_POST['date_from'] ?? gmdate( 'Y-m-01' ) );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? gmdate( 'Y-m-d' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ltms_payouts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'paid' AND paid_at BETWEEN %s AND %s",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ) );

        $matched   = [];
        $unmatched = [];
        $extra     = [];

        foreach ( $ltms_payouts as $payout ) {
            $found = false;
            foreach ( $bank_rows as $row ) {
                if ( abs( (float) $payout->amount - abs( $row['amount'] ) ) < 1 ) {
                    $matched[]  = [ 'payout' => $payout, 'bank_row' => $row ];
                    $found      = true;
                    break;
                }
            }
            if ( ! $found ) {
                $unmatched[] = $payout;
            }
        }

        wp_send_json_success( [
            'matched'     => count( $matched ),
            'unmatched'   => count( $unmatched ),
            'unmatched_items' => $unmatched,
            'summary'     => sprintf(
                __( '%d de %d pagos conciliados. %d sin conciliar.', 'ltms' ),
                count( $matched ),
                count( $ltms_payouts ),
                count( $unmatched )
            ),
        ] );
    }

    /**
     * AJAX: marca un pago como conciliado manualmente.
     *
     * @return void
     */
    public function ajax_mark_reconciled(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = absint( $_POST['payout_id'] ?? 0 );
        if ( ! $payout_id ) {
            wp_send_json_error( __( 'ID de pago requerido.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, [ 'reconciled' => 1, 'reconciled_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $payout_id ], [ '%d', '%s' ], [ '%d' ] );

        wp_send_json_success( [ 'message' => __( 'Pago marcado como conciliado.', 'ltms' ) ] );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// LEGAL EVIDENCE HANDLER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Legal_Evidence_Handler
 *
 * Gestiona la recopilación y almacenamiento de evidencias legales:
 * snapshots de pedidos, contratos firmados, logs de acceso y exportaciones
 * para cumplimiento de la Superintendencia de Industria y Comercio (SIC).
 */
