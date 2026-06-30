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

        // M-ZAPSIGN-GAP-01: ltms_vendor_approved se dispara tanto en aprobación manual
        // (class-ltms-admin-payouts.php) como en aprobación automática vía webhook
        // (class-ltms-zapsign-webhook-handler.php). La aprobación manual nunca disparaba
        // el envío del contrato porque este listener no existía — on_vendor_approved()
        // solo actúa si el vendedor aún no tiene ltms_contract_token, así que es un no-op
        // seguro cuando la aprobación ya vino de una firma (el token ya existe).
        add_action( 'ltms_vendor_approved', [ $instance, 'on_vendor_approved' ], 30 );

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
     * M-ZAPSIGN-GAP-01: cubre la aprobación manual de KYC desde el panel admin
     * (ajax_approve_kyc / ajax_quick_approve_kyc en class-ltms-admin-payouts.php),
     * que dispara 'ltms_vendor_approved' pero nunca enviaba el contrato.
     *
     * No-op seguro cuando 'ltms_vendor_approved' llega desde el webhook de ZapSign
     * (el vendedor ya firmó y ya tiene ltms_contract_token).
     */
    public function on_vendor_approved( int $vendor_id ): void {
        if ( get_user_meta( $vendor_id, 'ltms_contract_token', true ) ) {
            return;
        }

        try {
            $result = $this->send_contract( $vendor_id );
            if ( $result['success'] ) {
                update_user_meta( $vendor_id, 'ltms_kyc_status', 'pending_signature' );
                $this->log_info( 'zapsign_contract_sent',
                    sprintf( 'Contrato enviado a vendedor #%d tras aprobación manual de KYC', $vendor_id ) );
            } else {
                $this->log_warning( 'zapsign_contract_failed',
                    sprintf( 'No se pudo enviar contrato a vendedor #%d tras aprobación manual: %s',
                        $vendor_id, $result['error'] ?? 'error desconocido' ) );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning( 'zapsign_contract_failed',
                sprintf( 'Excepción enviando contrato a vendedor #%d tras aprobación manual: %s',
                    $vendor_id, $e->getMessage() ) );
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
            // ── B. Generación dinámica de PDF con datos del vendedor ──────
            // Usa LTMS_Contract_PDF_Generator (DOMPDF) para producir el PDF
            // con todos los datos KYC del vendedor (comisión personalizada,
            // régimen tributario, DANE, etc.) y enviarlo a ZapSign como
            // base64_pdf — sin necesidad de URL pública ni bucket S3.
            try {
                $generator  = new LTMS_Contract_PDF_Generator();
                $pdf_base64 = $generator->generate_base64( $vendor_id );
            } catch ( \Throwable $e ) {
                $this->log_warning( 'zapsign_pdf_gen_failed',
                    sprintf( 'DOMPDF falló para vendedor #%d: %s. Usando fallback URL.', $vendor_id, $e->getMessage() ) );
                $pdf_base64 = '';
            }

            if ( ! empty( $pdf_base64 ) ) {
                // PDF dinámico generado correctamente — enviar como base64
                $result = $client->create_document( [
                    'name'       => sprintf( 'Contrato Vendedor - %s - %s', $user->display_name, gmdate( 'Y' ) ),
                    'pdf_base64' => $pdf_base64,
                    'signers'    => [
                        [
                            'name'        => $user->display_name,
                            'email'       => $user->user_email,
                            'phone'       => preg_replace( '/\D/', '', (string) get_user_meta( $vendor_id, 'ltms_phone', true ) ?: get_user_meta( $vendor_id, 'billing_phone', true ) ?: '' ),
                            'external_id' => (string) $vendor_id,
                            'auth_mode'   => 'assinaturaTela',
                        ],
                    ],
                    'lang'                 => 'es',
                    'send_automatic_email' => true,
                    'sandbox'              => $client->is_sandbox(),
                    'external_id'          => (string) $vendor_id,
                    'folder'               => 'Contratos/' . gmdate( 'Y' ),
                ] );
            } else {
                // Fallback: PDF URL estático configurado en ajustes
                $pdf_url = LTMS_Core_Config::get( 'ltms_zapsign_contract_pdf_url', '' );

                if ( empty( $pdf_url ) ) {
                    return [
                        'success' => false,
                        'error'   => 'No se pudo generar el PDF y no hay PDF URL configurado. Ve a Configuración → ZapSign.',
                    ];
                }

                $result = $client->send_vendor_contract( $vendor_id, $pdf_url );
            }
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
            'sandbox'              => $client->is_sandbox(),
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
                    // L-7: enmascarar documento antes de enviar a ZapSign (solo últimos 4 dígitos)
                    [ 'de' => 'vendedor_documento', 'para' => (function( $num ) use ( $vendor_id ) {
                        $raw = get_user_meta( $vendor_id, 'ltms_document_number', true ) ?: '';
                        if ( class_exists( 'LTMS_Core_Security' ) && ! empty( $raw ) ) {
                            try { $raw = LTMS_Core_Security::decrypt( $raw ); } catch ( \Throwable $e ) {}
                        }
                        return strlen( $raw ) > 4 ? str_repeat( '*', strlen( $raw ) - 4 ) . substr( $raw, -4 ) : $raw;
                    })( get_user_meta( $vendor_id, 'ltms_document_number', true ) ?: '' ) ],
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
     * BC-01 (Continuidad de negocio): respalda el PDF firmado en Backblaze B2.
     *
     * ZapSign es la fuente operativa para firmar, pero el documento final
     * NUNCA se guardaba en infraestructura propia — si ZapSign pierde acceso
     * a la cuenta, se cae el servicio, o hay una disputa legal con un vendedor,
     * no había ninguna copia recuperable del contrato firmado.
     *
     * Se llama desde el webhook al recibir 'doc_signed'. Es no-bloqueante:
     * cualquier fallo se loguea pero nunca debe interrumpir el flujo de
     * aprobación de KYC del vendedor.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $doc_token Token del documento en ZapSign.
     * @return void
     */
    public static function backup_signed_contract( int $vendor_id, string $doc_token ): void {
        if ( LTMS_Core_Config::get( 'ltms_backblaze_enabled', 'no' ) !== 'yes' ) {
            LTMS_Core_Logger::info( 'B2_CONTRACT_BACKUP_SKIPPED',
                sprintf( 'Backblaze B2 desactivado — contrato de vendedor #%d no respaldado.', $vendor_id ) );
            return;
        }

        try {
            $zapsign_client = LTMS_Api_Factory::get( 'zapsign' );
            $pdf_base64     = $zapsign_client->download_signed_document( $doc_token );

            if ( empty( $pdf_base64 ) ) {
                LTMS_Core_Logger::warning( 'B2_CONTRACT_BACKUP_EMPTY',
                    sprintf( 'ZapSign no devolvió PDF para doc_token=%s (vendedor #%d).', $doc_token, $vendor_id ) );
                return;
            }

            $pdf_binary = base64_decode( $pdf_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
            if ( false === $pdf_binary ) {
                LTMS_Core_Logger::warning( 'B2_CONTRACT_BACKUP_DECODE_FAIL',
                    sprintf( 'base64 inválido para doc_token=%s (vendedor #%d).', $doc_token, $vendor_id ) );
                return;
            }

            $b2     = LTMS_Api_Factory::get( 'backblaze' );
            $bucket = LTMS_Core_Config::get( 'ltms_backblaze_contratos_bucket', 'lotengo-contratos' ) ?: 'lotengo-contratos'; // BC-01-FIX: opcion guardada vacia en BD no debe pasar isset()
            $key    = sprintf( 'contratos/%s/vendedor-%d-%s.pdf', gmdate( 'Y/m' ), $vendor_id, $doc_token );

            $b2->upload_file( $bucket, $key, $pdf_binary, 'application/pdf', [
                'vendor_id' => (string) $vendor_id,
                'doc_token' => $doc_token,
            ] );

            update_user_meta( $vendor_id, 'ltms_contract_b2_bucket', $bucket );
            update_user_meta( $vendor_id, 'ltms_contract_b2_key', $key );
            update_user_meta( $vendor_id, 'ltms_contract_backed_up_at', LTMS_Utils::now_utc() );

            LTMS_Core_Logger::info( 'B2_CONTRACT_BACKUP_OK',
                sprintf( 'Contrato de vendedor #%d respaldado en B2: %s/%s', $vendor_id, $bucket, $key ) );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'B2_CONTRACT_BACKUP_FAIL',
                sprintf( 'No se pudo respaldar contrato de vendedor #%d (doc_token=%s): %s',
                    $vendor_id, $doc_token, $e->getMessage() ) );
        }
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

