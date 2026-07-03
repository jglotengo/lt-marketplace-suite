<?php
class LTMS_Siigo_Webhook_Handler {

    /** Valores válidos de estado que Siigo puede enviar. */
    private const ALLOWED_STATUSES = [ 'emitted', 'accepted', 'rejected', 'cancelled', 'pending' ];

    public static function init(): void {}

    /**
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

        // M-104: Verificar token compartido (configurable en Ajustes → APIs → Siigo)
        $expected = LTMS_Core_Config::get( 'ltms_siigo_webhook_token', '' );
        $received = $request->get_header( 'x-siigo-token' ) ?: (string) $request->get_param( 'token' );
        if ( $expected && ! hash_equals( $expected, $received ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning( 'SIIGO_WEBHOOK_AUTH', 'Token inválido o ausente en webhook Siigo' );
            }
            return new WP_REST_Response( [ 'error' => 'Invalid token' ], 401 );
        }

        $body = $request->get_json_params();

        $invoice_id = sanitize_text_field( $body['invoiceId'] ?? '' );
        $status     = sanitize_key( $body['status'] ?? '' );

        if ( ! $invoice_id || ! $status ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // Validar status contra lista blanca
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid status' ], 422 );
        }

        // API-BUG-11 FIX: event_id idempotency. Siigo may retry the same webhook on
        // timeout. Without dedup, the same order note + meta update would fire multiple
        // times. Key scoped to (invoiceId, status).
        // API-BUG-18 FIX: return 200 immediately if already processed.
        $event_id = 'siigo_' . $invoice_id . '_' . $status;
        $seen_key = 'ltms_wh_seen_siigo_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'SIIGO_WEBHOOK_REPLAY', "Duplicate Siigo webhook ignored: invoice={$invoice_id} status={$status}" );
            }
            return new WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        // Buscar el pedido asociado
        global $wpdb;
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ltms_siigo_invoice_id' AND meta_value = %s LIMIT 1",
            $invoice_id
        ) );

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_siigo_invoice_status', $status );
                $order->add_order_note( sprintf( __( 'Factura Siigo %s — estado: %s', 'ltms' ), $invoice_id, $status ) );
                $order->save();
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'SIIGO_WEBHOOK', "Factura {$invoice_id} → {$status}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * @return string
     */
    private static function client_ip(): string {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
            if ( ! empty( $forwarded ) ) {
                $ip = end( $forwarded );
            }
        }
        return $ip;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AVEONLINE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Aveonline_Webhook_Handler
 *
 * Procesa eventos de estado de envío de Aveonline.
 */
