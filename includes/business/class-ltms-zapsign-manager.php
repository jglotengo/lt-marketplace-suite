<?php
/**
 * LTMS ZapSign Manager — Gestión completa del ciclo de vida de contratos de vendedores
 *
 * Responsabilidades:
 *  1. Enviar contrato a nuevo vendedor tras completar KYC (hook ltms_vendor_registered)
 *  2. Enviar contrato cuando admin aprueba manualmente KYC (hook ltms_kyc_contract_required)
 *  3. Generar PDF del contrato a partir de la plantilla HTML o del PDF estático cargado
 *  4. Usar template_id de ZapSign si está configurado (evita resubir el PDF en cada envío)
 *  5. Registrar todos los estados en user_meta para trazabilidad
 *  6. Reenviar contrato si el vendedor lo solicita desde el dashboard
 *  7. Marcar KYC como aprobado automáticamente cuando el webhook llega (delegado al webhook handler)
 *
 * Meta keys de usuario:
 *   ltms_contract_token        — Token del documento en ZapSign
 *   ltms_contract_status       — pending | signed | expired | cancelled
 *   ltms_contract_sent_at      — Fecha de envío
 *   ltms_contract_signed_at    — Fecha de firma
 *   ltms_contract_sign_url     — URL de firma pública (para mostrar al vendedor)
 *   ltms_kyc_status            — pending | pending_signature | approved | rejected
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_ZapSign_Manager {

    use LTMS_Logger_Aware;

    // ── Boot ──────────────────────────────────────────────────────

    public static function init(): void {
        if ( LTMS_Core_Config::get( 'ltms_zapsign_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        $instance = new self();

        // Enviar contrato cuando el vendedor completa el registro
        add_action( 'ltms_vendor_registered', [ $instance, 'on_vendor_registered' ], 30 );

        // Enviar contrato cuando admin aprueba KYC manualmente y falta la firma
        add_action( 'ltms_kyc_approved_requires_signature', [ $instance, 'send_contract' ], 10, 1 );

        // AJAX: reenviar contrato desde el dashboard del vendedor
        add_action( 'wp_ajax_ltms_resend_contract', [ $instance, 'ajax_resend_contract' ] );

        // AJAX admin: enviar contrato manualmente desde el panel
        add_action( 'wp_ajax_ltms_admin_send_contract', [ $instance, 'ajax_admin_send_contract' ] );
    }

    // ── Handlers de eventos ───────────────────────────────────────

    /**
     * Cuando el vendedor se registra, enviar el contrato automáticamente.
     */
    public function on_vendor_registered( int $vendor_id ): void {
        // Solo si KYC auto-aprobación vía ZapSign está activo
        if ( LTMS_Core_Config::get( 'ltms_kyc_zapsign_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        // No reenviar si ya tiene contrato
        if ( get_user_meta( $vendor_id, 'ltms_contract_token', true ) ) {
            return;
        }

        try {
            $result = $this->send_contract( $vendor_id );
            if ( $result['success'] ) {
                // Poner KYC en estado "pending_signature" hasta que firme
                update_user_meta( $vendor_id, 'ltms_kyc_status', 'pending_signature' );
                $this->log_info( 'zapsign_contract_sent',
                    sprintf( 'Contrato enviado automáticamente al vendedor #%d', $vendor_id ) );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning( 'zapsign_contract_failed',
                sprintf( 'No se pudo enviar contrato a vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
        }
    }

    /**
     * Envía el contrato a un vendedor.
     * Usa template_id si está configurado (más eficiente: no requiere URL de PDF).
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{success: bool, doc_token: string, sign_url: string}
     */
    public function send_contract( int $vendor_id ): array {
        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            return [ 'success' => false, 'error' => "Vendedor #$vendor_id no encontrado" ];
        }

        $client = LTMS_Api_Factory::get( 'zapsign' );

        $template_id = LTMS_Core_Config::get( 'ltms_zapsign_vendor_template_id', '' );

        if ( ! empty( $template_id ) ) {
            // ── A. Crear desde plantilla (método preferido) ──────────
            $result = $this->create_from_template( $client, $template_id, $user, $vendor_id );
        } else {
            // ── B. Crear desde URL de PDF estático ───────────────────
            $pdf_url = LTMS_Core_Config::get( 'ltms_zapsign_contract_pdf_url', '' );

            if ( empty( $pdf_url ) ) {
                // Intentar generar PDF dinámico como último recurso
                $pdf_url = $this->generate_contract_pdf_url( $vendor_id );
            }

            if ( empty( $pdf_url ) ) {
                return [
                    'success' => false,
                    'error'   => 'No hay template_id ni PDF URL configurado. Ve a Configuración → ZapSign.',
                ];
            }

            $result = $client->send_vendor_contract( $vendor_id, $pdf_url );
        }

        if ( $result['success'] ) {
            update_user_meta( $vendor_id, 'ltms_contract_token',    $result['doc_token'] );
            update_user_meta( $vendor_id, 'ltms_contract_sign_url', $result['sign_url'] );
            update_user_meta( $vendor_id, 'ltms_contract_status',   'pending' );
            update_user_meta( $vendor_id, 'ltms_contract_sent_at',  LTMS_Utils::now_utc() );
        }

        return $result;
    }

    /**
     * Crea un documento desde una plantilla ZapSign (template_id).
     * Con plantilla no se requiere subir el PDF en cada envío — ZapSign lo toma de la plantilla.
     */
    private function create_from_template(
        LTMS_Api_Zapsign $client,
        string $template_id,
        \WP_User $user,
        int $vendor_id
    ): array {
        $phone   = get_user_meta( $vendor_id, 'ltms_phone', true )
                ?: get_user_meta( $vendor_id, 'billing_phone', true )
                ?: '';

        $payload = [
            'template_id'          => $template_id,
            'name'                 => sprintf(
                'Contrato Vendedor — %s — %s',
                $user->display_name,
                gmdate( 'd/m/Y' )
            ),
            'external_id'          => (string) $vendor_id,
            'lang'                 => 'es',
            'send_automatic_email' => true,
            'signers'              => [[
                'name'        => $user->display_name,
                'email'       => $user->user_email,
                'phone'       => preg_replace( '/\D/', '', $phone ),
                'auth_mode'   => 'assinaturaTela',
                'external_id' => (string) $vendor_id,
                // Pre-llenar campos del formulario de la plantilla
                'data'        => [
                    [ 'de' => 'vendedor_nombre',    'para' => $user->display_name ],
                    [ 'de' => 'vendedor_email',     'para' => $user->user_email ],
                    [ 'de' => 'vendedor_documento', 'para' => get_user_meta( $vendor_id, 'ltms_document_number', true ) ?: '' ],
                    [ 'de' => 'vendedor_ciudad',    'para' => get_user_meta( $vendor_id, 'billing_city', true ) ?: '' ],
                    [ 'de' => 'vendedor_tienda',    'para' => get_user_meta( $vendor_id, 'ltms_store_name', true ) ?: '' ],
                    [ 'de' => 'fecha_contrato',     'para' => gmdate( 'd/m/Y' ) ],
                ],
            ]],
        ];

        $response = $client->perform_request( 'POST', '/models/' . $template_id . '/create-doc/', $payload );

        if ( empty( $response['token'] ) ) {
            return [
                'success'   => false,
                'doc_token' => '',
                'sign_url'  => '',
                'error'     => $response['error'] ?? wp_json_encode( $response ),
            ];
        }

        return [
            'success'   => true,
            'doc_token' => $response['token'],
            'sign_url'  => $response['signers'][0]['sign_url'] ?? '',
            'open_id'   => $response['open_id'] ?? '',
            'status'    => $response['status'] ?? 'pending',
        ];
    }

    /**
     * Genera la URL pública del PDF del contrato para enviarlo a ZapSign.
     * Usa el adjunto de WordPress si existe, o genera HTML on-the-fly.
     */
    private function generate_contract_pdf_url( int $vendor_id ): string {
        // 1. PDF cargado en media library
        $attachment_id = (int) LTMS_Core_Config::get( 'ltms_zapsign_contract_attachment_id', 0 );
        if ( $attachment_id ) {
            $url = wp_get_attachment_url( $attachment_id );
            if ( $url ) {
                return $url;
            }
        }

        // 2. URL REST que genera el contrato dinámicamente con los datos del vendedor
        $rest_url = rest_url( 'ltms/v1/vendor-contract/' . $vendor_id );
        if ( filter_var( $rest_url, FILTER_VALIDATE_URL ) ) {
            return $rest_url;
        }

        return '';
    }

    // ── AJAX handlers ─────────────────────────────────────────────

    /**
     * Reenviar contrato desde el dashboard del vendedor.
     */
    public function ajax_resend_contract(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'Acceso restringido.', 'ltms' ), 403 );
        }

        // Rate limit: no reenviar más de 1 vez por hora
        $last_sent = get_user_meta( $vendor_id, 'ltms_contract_sent_at', true );
        if ( $last_sent && ( time() - strtotime( $last_sent ) ) < 3600 ) {
            wp_send_json_error( __( 'Ya se envió un contrato recientemente. Espera 1 hora antes de reenviar.', 'ltms' ) );
        }

        try {
            $result = $this->send_contract( $vendor_id );
            if ( $result['success'] ) {
                wp_send_json_success( [
                    'message'  => __( 'Contrato enviado a tu correo electrónico.', 'ltms' ),
                    'sign_url' => $result['sign_url'],
                ] );
            } else {
                wp_send_json_error( $result['error'] ?? __( 'No se pudo enviar el contrato.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * Enviar contrato desde el panel admin.
     */
    public function ajax_admin_send_contract(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Sin permiso.', 'ltms' ), 403 );
        }

        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'vendor_id requerido.', 'ltms' ) );
        }

        try {
            $result = $this->send_contract( $vendor_id );
            if ( $result['success'] ) {
                wp_send_json_success( [
                    'message'   => sprintf( __( 'Contrato enviado al vendedor #%d.', 'ltms' ), $vendor_id ),
                    'doc_token' => $result['doc_token'],
                    'sign_url'  => $result['sign_url'],
                ] );
            } else {
                wp_send_json_error( $result['error'] ?? __( 'Error enviando contrato.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * Verifica el estado del contrato de un vendedor consultando ZapSign en tiempo real.
     */
    public static function get_contract_status( int $vendor_id ): array {
        $token = get_user_meta( $vendor_id, 'ltms_contract_token', true );
        if ( ! $token ) {
            return [ 'status' => 'no_contract', 'sent' => false ];
        }

        $cached_status = get_user_meta( $vendor_id, 'ltms_contract_status', true );

        // Si ya está firmado en caché, no consultar ZapSign de nuevo
        if ( 'signed' === $cached_status ) {
            return [
                'status'    => 'signed',
                'sent'      => true,
                'signed_at' => get_user_meta( $vendor_id, 'ltms_contract_signed_at', true ),
            ];
        }

        // Consultar estado en tiempo real
        try {
            $client = LTMS_Api_Factory::get( 'zapsign' );
            $status = $client->get_document_status( $token );

            if ( 'completed' === $status['status'] ) {
                update_user_meta( $vendor_id, 'ltms_contract_status', 'signed' );
                update_user_meta( $vendor_id, 'ltms_contract_signed_at', LTMS_Utils::now_utc() );
            }

            return array_merge( $status, [
                'sent'     => true,
                'sign_url' => get_user_meta( $vendor_id, 'ltms_contract_sign_url', true ),
            ] );
        } catch ( \Throwable $e ) {
            return [
                'status'   => $cached_status ?: 'unknown',
                'sent'     => true,
                'sign_url' => get_user_meta( $vendor_id, 'ltms_contract_sign_url', true ),
                'error'    => $e->getMessage(),
            ];
        }
    }
}
