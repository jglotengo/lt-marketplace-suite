<?php
class LTMS_Addi_Webhook_Handler {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    public static function register_route(): void {
        register_rest_route(
            'ltms/v1',
            '/webhooks/addi',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Maneja la petición entrante de Addi.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        // API-BUG-19 FIX: per-IP rate limit (max 100 webhooks/min) — protects DB.
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        $body = $request->get_json_params();

        if ( empty( $body['orderId'] ) || empty( $body['status'] ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        $order_id = absint( $body['orderId'] );
        $status   = sanitize_key( $body['status'] );

        // Verificar token de seguridad.
        // WH2 FIX (v2.8.9) CRÍTICO: si expected_token está vacío, RECHAZAR el webhook
        // (fail-closed). Antes, si no había token configurado, se omitía la validación
        // → cualquier atacante podía forjar webhooks Addi y aprobar pedidos BNPL falsos.
        $token          = $request->get_header( 'x-addi-signature' ) ?: '';
        $expected_token = LTMS_Core_Config::get( 'ltms_addi_webhook_token', '' );

        if ( empty( $expected_token ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'ADDI_WEBHOOK_NO_TOKEN',
                    'Addi webhook token no configurado. Webhook rechazado (fail-closed).'
                );
            }
            return new WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
        }

        if ( ! hash_equals( $expected_token, $token ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'ADDI_WEBHOOK_INVALID_TOKEN',
                    'Token de webhook Addi inválido.',
                    [ 'received_token' => substr( $token, 0, 20 ) ]
                );
            }
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
        }

        // API-BUG-11 FIX: event_id idempotency. Addi may retry the same webhook on
        // timeout. Without dedup, payment_complete() could fire multiple times for
        // the same orderId+status. Key scoped to (orderId, status, transactionId).
        // API-BUG-18 FIX: return 200 immediately if already processed.
        $txn_id    = sanitize_text_field( $body['transactionId'] ?? '' );
        $event_id  = 'addi_' . $order_id . '_' . $status . '_' . $txn_id;
        $seen_key  = 'ltms_wh_seen_addi_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'ADDI_WEBHOOK_REPLAY', "Duplicate Addi webhook ignored: order={$order_id} status={$status} txn={$txn_id}" );
            }
            return new WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
        }

        switch ( $status ) {
            case 'APPROVED':
                if ( $order->needs_payment() ) {
                    $order->payment_complete( sanitize_text_field( $body['transactionId'] ?? '' ) );
                    $order->add_order_note( __( 'Pago Addi aprobado vía webhook.', 'ltms' ) );
                }
                break;

            case 'REJECTED':
            case 'CANCELLED':
                if ( ! $order->has_status( [ 'cancelled', 'refunded' ] ) ) {
                    $order->update_status( 'cancelled', __( 'Pago Addi rechazado/cancelado vía webhook.', 'ltms' ) );
                }
                break;
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'ADDI_WEBHOOK', "Pedido #{$order_id} → status={$status}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * WH3 FIX (v2.8.9): delegar a LTMS_Core_Security::get_client_ip_safe()
     * que valida trusted proxies antes de confiar en X-Forwarded-For.
     *
     * @return string
     */
    private static function client_ip(): string {
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            return LTMS_Core_Security::get_client_ip_safe();
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// OPENPAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Openpay_Webhook_Handler
 *
 * Procesa notificaciones de Openpay (charge.succeeded, charge.failed, refund.*).
 */
