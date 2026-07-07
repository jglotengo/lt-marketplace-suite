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

        // HI-3 FIX: capability check — deposit creation is a vendor-only action.
        // Without this, any authenticated user (subscriber, customer) could
        // create deposit requests on their own user_id, polluting the deposit queue.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
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

        // v2.9.68 DEEP-AUDIT-002 P2-15: Validar monto máximo configurable.
        $max_deposit = (float) LTMS_Core_Config::get( 'ltms_max_deposit_amount', 100000000 );
        if ( $amount > $max_deposit ) {
            wp_send_json_error( sprintf(
                /* translators: %s: monto máximo */
                __( 'El monto excede el máximo permitido (%s).', 'ltms' ),
                LTMS_Utils::format_money( $max_deposit )
            ) );
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
            // HI-9 FIX: do not leak the raw exception message to the client — it
            // can expose DB internals, SQL fragments, or stack-trace details.
            // Log the real error server-side, return a generic message.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'DEPOSIT_HANDLER_ERROR',
                    $e->getMessage(),
                    [ 'trace' => $e->getTraceAsString() ]
                );
            }
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
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

        // HI-4 FIX: vendor role check — receipt uploads are a vendor-only action.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
        }

        // HI-4 FIX: verify the upload completed cleanly before processing.
        // Without this, partial / failed uploads (e.g. network errors mid-upload)
        // would be passed to media_handle_upload, which can produce confusing
        // error messages or zero-byte attachments.
        if ( ! isset( $_FILES['receipt'] ) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( [ 'message' => __( 'Upload failed', 'ltms' ) ], 400 );
        }

        // Validar tipo de archivo
        $file     = $_FILES['receipt']; // phpcs:ignore
        // HI-4 FIX: explicit MIME allowlist (PDF, JPEG, PNG). webp was previously
        // accepted but is not a common receipt format and broadens attack surface.
        $allowed  = [ 'application/pdf', 'image/jpeg', 'image/png' ];
        $finfo    = finfo_open( FILEINFO_MIME_TYPE );
        $mime     = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid file type', 'ltms' ) ], 400 );
        }

        // HI-4 FIX: explicit size limit (5 MB) — already present but kept for clarity.
        // Límite 5MB
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => __( 'File too large (max 5MB)', 'ltms' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'receipt', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            // HI-9 FIX: do not expose the raw WP_Error message — log server-side.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'DEPOSIT_RECEIPT_UPLOAD_ERROR',
                    $attachment_id->get_error_message()
                );
            }
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
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

        // HI-3 FIX: capability check — vendor deposit history is vendor-only.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
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
