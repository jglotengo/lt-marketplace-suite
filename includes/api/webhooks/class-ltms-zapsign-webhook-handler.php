<?php
/**
 * LTMS ZapSign Webhook Handler.
 *
 * Procesa webhooks de ZapSign (firma de contratos de vendedor).
 *
 * FU1 FIX (v2.9.1) CRÍTICO:
 *  - init() ahora registra la ruta REST (antes estaba vacío → webhooks nunca llegaban).
 *  - Fail-closed: si no hay token configurado, RECHAZA el webhook (antes era fail-open).
 *  - Rate limiting per-IP (igual que los demás handlers).
 *  - Idempotency transient (ZapSign reintenta 3x).
 *
 * @package LTMS
 * @version 2.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Zapsign_Webhook_Handler {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    /**
     * Registra la ruta REST de ZapSign Webhooks.
     */
    public static function register_route(): void {
        register_rest_route(
            'ltms/v1',
            '/webhooks/zapsign',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle' ],
                // SEC-H2: '__return_true' es intencional — webhooks deben ser públicamente
                // accesibles. La autenticación se aplica dentro de handle() via token.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        // FU1: rate limiting per-IP (max 100/min).
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        $body = $request->get_json_params();

        $doc_token = sanitize_text_field( $body['token'] ?? '' );
        $event     = sanitize_key( $body['event_type'] ?? '' );
        $signer    = $body['signer'] ?? [];

        if ( ! $doc_token || ! $event ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // Verificar token de seguridad ZapSign.
        // ZapSign puede enviar el api_token O un webhook_secret separado en x-zapsign-token.
        // La opción ltms_zapsign_webhook_secret tiene prioridad; si no está configurada,
        // se usa el api_token descifrado como fallback.
        //
        // FU1 FIX (v2.9.1) CRÍTICO: fail-closed. Si NO hay token configurado, RECHAZAR
        // el webhook. Antes, si no había token, se aceptaba cualquier webhook →
        // atacantes podían auto-aprobar KYC de cualquier vendor forjando un webhook
        // 'doc_signed' con external_id = vendor_id víctima.
        $webhook_secret = LTMS_Core_Config::get( 'ltms_zapsign_webhook_secret', '' );
        if ( $webhook_secret ) {
            $expected_token = str_starts_with( $webhook_secret, 'v1:' ) && class_exists( 'LTMS_Core_Security' )
                ? LTMS_Core_Security::decrypt( $webhook_secret )
                : $webhook_secret;
        } else {
            $raw_api_token  = LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' );
            $expected_token = str_starts_with( $raw_api_token, 'v1:' ) && class_exists( 'LTMS_Core_Security' )
                ? LTMS_Core_Security::decrypt( $raw_api_token )
                : $raw_api_token;
        }

        if ( empty( $expected_token ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'ZAPSIGN_WEBHOOK_NO_TOKEN',
                    'ZapSign token no configurado. Webhook rechazado (fail-closed).'
                );
            }
            return new WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
        }

        $req_token = $request->get_header( 'x-zapsign-token' ) ?: '';
        if ( $expected_token && ! hash_equals( $expected_token, $req_token ) ) {
            // BC-01-DEBUG: log enmascarado para diagnosticar mismatch de token sin exponer el secreto completo.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                $mask = static function ( string $s ): string {
                    $len = strlen( $s );
                    if ( $len === 0 ) { return '(vacio)'; }
                    if ( $len <= 8 ) { return str_repeat( '*', $len ) . " (len={$len})"; }
                    return substr( $s, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $s, -4 ) . " (len={$len})";
                };
                $all_headers = $request->get_headers();
                $header_keys = array_keys( $all_headers );
                LTMS_Core_Logger::warning( 'ZAPSIGN_WEBHOOK_401_DEBUG', sprintf(
                    'expected_token=%s | received_token=%s | source=%s | headers_recibidos=%s | body_keys=%s',
                    $mask( $expected_token ),
                    $mask( $req_token ),
                    $webhook_secret ? 'ltms_zapsign_webhook_secret' : 'ltms_zapsign_api_token(fallback)',
                    wp_json_encode( $header_keys ),
                    wp_json_encode( array_keys( (array) $body ) )
                ) );
            }
            return new WP_REST_Response( [ 'error' => 'Invalid token' ], 401 );
        }

        // FU1: idempotency transient (ZapSign reintenta 3x).
        $event_id = 'zapsign_' . $event . '_' . $doc_token;
        $seen_key = 'ltms_wh_seen_zapsign_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'ZAPSIGN_WEBHOOK_REPLAY',
                    sprintf( 'Duplicate ZapSign event ignored: event=%s, doc=%s', $event, $doc_token )
                );
            }
            return new WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        // El external_id almacena el vendor_id
        $vendor_id = absint( $signer['external_id'] ?? 0 );

        if ( $vendor_id && 'doc_signed' === $event ) {
            update_user_meta( $vendor_id, '_ltms_zapsign_doc_token', $doc_token );
            update_user_meta( $vendor_id, '_ltms_zapsign_signed_at', gmdate( 'Y-m-d H:i:s' ) );

            // BC-01: respaldar el PDF firmado en Backblaze B2 (continuidad de negocio).
            // No-bloqueante: cualquier fallo se loguea dentro del método y nunca debe
            // impedir que el KYC avance a 'approved' más abajo.
            if ( class_exists( 'LTMS_ZapSign_Manager' ) ) {
                try {
                    LTMS_ZapSign_Manager::backup_signed_contract( $vendor_id, $doc_token );
                } catch ( \Throwable $e ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning( 'B2_CONTRACT_BACKUP_UNEXPECTED',
                            sprintf( 'Excepción inesperada respaldando contrato de vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
                    }
                }
            }

            // Avanzar KYC si el documento era el contrato de vendedor
            $doc_type = sanitize_key( $body['document_type'] ?? 'vendor_contract' );
            if ( 'vendor_contract' === $doc_type ) {
                // M-99: usar 'ltms_kyc_status' sin guion bajo — '_ltms_kyc_status' era key incorrecta
                $current_kyc = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
                if ( in_array( $current_kyc, [ 'pending', 'pending_signature' ], true ) ) {
                    update_user_meta( $vendor_id, 'ltms_kyc_status', 'approved' );
                    update_user_meta( $vendor_id, 'ltms_kyc_approved_at', gmdate( 'Y-m-d H:i:s' ) );
                    // Disparar la misma acción que el flujo manual de admin
                    do_action( 'ltms_vendor_approved', $vendor_id );
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::info( 'ZAPSIGN_KYC_AUTO_APPROVED',
                            sprintf( 'Vendor #%d KYC auto-aprobado por firma ZapSign', $vendor_id )
                        );
                    }
                }
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'ZAPSIGN_WEBHOOK', "event={$event}, vendor={$vendor_id}, doc={$doc_token}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Resuelve la IP del cliente para rate limiting.
     * FU1: delega a LTMS_Core_Security::get_client_ip_safe() (trusted proxies).
     */
    private static function client_ip(): string {
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            return LTMS_Core_Security::get_client_ip_safe();
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
