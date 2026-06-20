<?php
/**
 * LTMS Frontend Deposit Handler - AJAX de Depósitos del Vendedor
 *
 * Maneja las acciones AJAX que el vendedor puede realizar sobre sus depósitos:
 *   - Crear solicitud de depósito
 *   - Subir comprobante
 *   - Listar historial de depósitos propios
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Deposit_Handler
 */
final class LTMS_Frontend_Deposit_Handler {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks AJAX del handler.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_create_deposit',      [ $instance, 'ajax_create_deposit' ] );
        add_action( 'wp_ajax_ltms_upload_receipt',      [ $instance, 'ajax_upload_receipt' ] );
        add_action( 'wp_ajax_ltms_get_my_deposits',     [ $instance, 'ajax_get_my_deposits' ] );
    }

    /**
     * AJAX: Crear solicitud de depósito manual.
     *
     * POST params: amount, method, reference, receipt_url, notes, nonce
     *
     * @return void
     */
    public function ajax_create_deposit(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Debes iniciar sesión.', 'ltms' ), 401 );
        }

        $vendor_id   = get_current_user_id();
        $amount      = (float) ( $_POST['amount'] ?? 0 ); // phpcs:ignore
        $method      = sanitize_text_field( wp_unslash( $_POST['method'] ?? '' ) ); // phpcs:ignore
        $reference   = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ); // phpcs:ignore
        $receipt_url = esc_url_raw( wp_unslash( $_POST['receipt_url'] ?? '' ) ); // phpcs:ignore
        $notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ); // phpcs:ignore

        if ( $amount <= 0 ) {
            wp_send_json_error( __( 'El monto debe ser mayor a cero.', 'ltms' ) );
        }

        if ( empty( $method ) ) {
            wp_send_json_error( __( 'Selecciona un método de pago.', 'ltms' ) );
        }

        try {
            $deposit_id = LTMS_Deposit::create(
                $vendor_id,
                $amount,
                $method,
                $reference,
                $receipt_url,
                $notes
            );

            wp_send_json_success( [
                'deposit_id' => $deposit_id,
                'message'    => __( 'Tu solicitud de depósito fue recibida. El equipo la revisará en menos de 24 horas.', 'ltms' ),
            ] );

        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Subir comprobante de pago.
     *
     * Acepta imagen o PDF. Retorna la URL del archivo subido.
     *
     * @return void
     */
    public function ajax_upload_receipt(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Debes iniciar sesión.', 'ltms' ), 401 );
        }

        if ( empty( $_FILES['receipt'] ) ) { // phpcs:ignore
            wp_send_json_error( __( 'No se recibió ningún archivo.', 'ltms' ) );
        }

        // Validar tipo de archivo
        $file     = $_FILES['receipt']; // phpcs:ignore
        $allowed  = [ 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ];
        $finfo    = finfo_open( FILEINFO_MIME_TYPE );
        $mime     = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( __( 'Tipo de archivo no permitido. Usa JPG, PNG, WEBP o PDF.', 'ltms' ) );
        }

        // Límite 5MB
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( __( 'El archivo no puede superar 5 MB.', 'ltms' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'receipt', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( $attachment_id->get_error_message() );
        }

        $url = wp_get_attachment_url( $attachment_id );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $url,
        ] );
    }

    /**
     * AJAX: Obtener historial de depósitos del vendedor actual.
     *
     * @return void
     */
    public function ajax_get_my_deposits(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'No autenticado.', 'ltms' ), 401 );
        }

        $vendor_id = get_current_user_id();
        $page      = max( 1, (int) ( $_POST['page'] ?? 1 ) ); // phpcs:ignore
        $limit     = 10;
        $offset    = ( $page - 1 ) * $limit;

        $deposits = LTMS_Deposit::get_by_vendor( $vendor_id, '', $limit, $offset );

        // Formatear para el frontend
        $formatted = array_map( function( $d ) {
            return [
                'id'          => (int) $d['id'],
                'amount'      => LTMS_Utils::format_money( (float) $d['amount'], $d['currency'] ),
                'method'      => strtoupper( $d['method'] ),
                'reference'   => $d['reference'] ?: '—',
                'status'      => $d['status'],
                'status_label' => [
                    'pending'  => '⏳ Pendiente',
                    'approved' => '✅ Aprobado',
                    'rejected' => '❌ Rechazado',
                ][ $d['status'] ] ?? $d['status'],
                'receipt_url' => $d['receipt_url'] ?: null,
                'reject_reason' => $d['reject_reason'] ?? null,
                'created_at'  => substr( $d['created_at'], 0, 10 ),
            ];
        }, $deposits );

        wp_send_json_success( [ 'deposits' => $formatted ] );
    }
}
