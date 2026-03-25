<?php
/**
 * LTMS Admin Payouts - Controlador AJAX de Retiros
 *
 * Gestiona las acciones AJAX para la administración de retiros:
 * - Aprobar solicitudes de retiro
 * - Rechazar solicitudes de retiro
 * - Aprobar KYC de vendedores
 * - Congelar/descongelar billeteras
 * - Exportar reportes de retiros
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Payouts
 */
final class LTMS_Admin_Payouts {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks AJAX del controlador de retiros.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_approve_payout',  [ $instance, 'ajax_approve_payout' ] );
        add_action( 'wp_ajax_ltms_reject_payout',   [ $instance, 'ajax_reject_payout' ] );
        add_action( 'wp_ajax_ltms_approve_kyc',     [ $instance, 'ajax_approve_kyc' ] );
        add_action( 'wp_ajax_ltms_reject_kyc',      [ $instance, 'ajax_reject_kyc' ] );
        add_action( 'wp_ajax_ltms_freeze_wallet',   [ $instance, 'ajax_freeze_wallet' ] );
        add_action( 'wp_ajax_ltms_unfreeze_wallet', [ $instance, 'ajax_unfreeze_wallet' ] );
        add_action( 'wp_ajax_ltms_export_payouts',  [ $instance, 'ajax_export_payouts' ] );
    }

    /**
     * AJAX: Aprobar solicitud de retiro.
     *
     * @return void
     */
    public function ajax_approve_payout(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = (int) ( $_POST['payout_id'] ?? 0 ); // phpcs:ignore
        if ( ! $payout_id ) {
            wp_send_json_error( __( 'ID de retiro inválido.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::approve( $payout_id, get_current_user_id() );
        wp_send_json( $result );
    }

    /**
     * AJAX: Rechazar solicitud de retiro.
     *
     * @return void
     */
    public function ajax_reject_payout(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = (int) ( $_POST['payout_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore

        if ( ! $payout_id || ! $reason ) {
            wp_send_json_error( __( 'Datos inválidos. El motivo es requerido.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::reject( $payout_id, $reason, get_current_user_id() );
        wp_send_json( $result );
    }

    /**
     * AJAX: Aprobar verificación KYC de un vendedor.
     *
     * @return void
     */
    public function ajax_approve_kyc(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $kyc_id    = (int) ( $_POST['kyc_id'] ?? 0 ); // phpcs:ignore
        $vendor_id = $this->get_vendor_id_by_kyc( $kyc_id );

        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'KYC no encontrado.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $kyc_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        update_user_meta( $vendor_id, 'ltms_kyc_status', 'approved' );
        update_user_meta( $vendor_id, 'ltms_kyc_approved_at', LTMS_Utils::now_utc() );

        LTMS_Core_Logger::security(
            'KYC_APPROVED',
            sprintf( 'KYC #%d del vendedor #%d aprobado por admin #%d', $kyc_id, $vendor_id, get_current_user_id() )
        );

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: ID del vendedor */
                __( 'KYC del vendedor #%d aprobado exitosamente.', 'ltms' ),
                $vendor_id
            ),
        ]);
    }

    /**
     * AJAX: Rechazar verificación KYC.
     *
     * @return void
     */
    public function ajax_reject_kyc(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $kyc_id    = (int) ( $_POST['kyc_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore
        $vendor_id = $this->get_vendor_id_by_kyc( $kyc_id );

        if ( ! $kyc_id || ! $reason || ! $vendor_id ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'       => 'rejected',
                'notes'        => $reason,
                'reviewed_by'  => get_current_user_id(),
                'reviewed_at'  => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $kyc_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        update_user_meta( $vendor_id, 'ltms_kyc_status', 'rejected' );

        wp_send_json_success([
            'message' => __( 'KYC rechazado y vendedor notificado.', 'ltms' ),
        ]);
    }

    /**
     * AJAX: Congelar billetera de un vendedor por cumplimiento.
     *
     * @return void
     */
    public function ajax_freeze_wallet(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_compliance' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore

        if ( ! $vendor_id || ! $reason ) {
            wp_send_json_error( __( 'Datos inválidos. El motivo es requerido.', 'ltms' ) );
        }

        try {
            LTMS_Wallet::freeze( $vendor_id, $reason );
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: ID del vendedor */
                    __( 'Billetera del vendedor #%d congelada.', 'ltms' ),
                    $vendor_id
                ),
            ]);
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Descongelar billetera de un vendedor.
     *
     * @return void
     */
    public function ajax_unfreeze_wallet(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_compliance' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'ID de vendedor inválido.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'is_frozen' => 0, 'freeze_reason' => null ],
            [ 'user_id' => $vendor_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        LTMS_Core_Logger::security(
            'WALLET_UNFROZEN',
            sprintf( 'Billetera del vendedor #%d descongelada por admin #%d', $vendor_id, get_current_user_id() )
        );

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: ID del vendedor */
                __( 'Billetera del vendedor #%d descongelada.', 'ltms' ),
                $vendor_id
            ),
        ]);
    }

    /**
     * AJAX: Exportar retiros a CSV.
     *
     * @return void
     */
    public function ajax_export_payouts(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'lt_payout_requests';
        $status = sanitize_key( $_POST['status'] ?? '' ); // phpcs:ignore

        $where_sql = '';
        $args      = [];

        if ( $status ) {
            $where_sql = 'WHERE p.status = %s';
            $args[]    = $status;
        }

        $args[] = 1000; // LIMIT placeholder

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.user_email, u.display_name FROM `{$table}` p LEFT JOIN `{$wpdb->users}` u ON u.ID = p.vendor_id {$where_sql} ORDER BY p.created_at DESC LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );

        // Sanitize a CSV field: escape formula injection chars (=, +, -, @, TAB, CR).
        $csv_field = static function ( $v ): string {
            $v = (string) $v;
            if ( '' !== $v && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
                $v = "'" . $v;
            }
            return $v;
        };

        $csv  = "ID,Vendedor,Email,Monto,Fee,Neto,Método,Estado,Referencia,Fecha\n";
        foreach ( $payouts as $row ) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $csv_field( $row['id'] ),
                $csv_field( $row['display_name'] ),
                $csv_field( $row['user_email'] ),
                $csv_field( $row['amount'] ),
                $csv_field( $row['fee'] ),
                $csv_field( $row['net_amount'] ),
                $csv_field( $row['method'] ),
                $csv_field( $row['status'] ),
                $csv_field( $row['reference'] ),
                $csv_field( $row['created_at'] )
            );
        }

        LTMS_Core_Logger::info(
            'PAYOUTS_EXPORTED',
            sprintf( 'Exportación de %d retiros por admin #%d', count( $payouts ), get_current_user_id() )
        );

        wp_send_json_success([
            'csv'      => base64_encode( $csv ), // phpcs:ignore
            'filename' => 'ltms-retiros-' . gmdate( 'Y-m-d' ) . '.csv',
            'count'    => count( $payouts ),
        ]);
    }

    /**
     * Obtiene el vendor_id a partir de un ID de registro KYC.
     *
     * @param int $kyc_id ID del registro KYC.
     * @return int
     */
    private function get_vendor_id_by_kyc( int $kyc_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $vendor_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT vendor_id FROM `{$table}` WHERE id = %d", $kyc_id )
        );

        return (int) $vendor_id;
    }
}
