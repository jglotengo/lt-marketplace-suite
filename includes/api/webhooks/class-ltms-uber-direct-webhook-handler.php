<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Uber_Direct_Webhook_Handler {
    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    public static function register_route(): void {
        register_rest_route( 'ltms/v1', '/webhooks/uber-direct', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle' ],
            // SEC-H2: '__return_true' is intentional — Uber Direct webhooks must be publicly reachable.
            // Authentication is enforced inside handle() via HMAC-SHA256 signature verification
            // using the x-postmates-signature / x-uber-signature header and ltms_uber_direct_webhook_secret.
            // Requests with a missing secret or invalid signature are rejected with HTTP 401/400.
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // API-BUG-19 FIX: per-IP rate limit (max 100 webhooks/min) — protects DB.
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new \WP_REST_Response( [ 'error' => 'Too many requests' ], 429 );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        $payload   = $request->get_body();
        $signature = $request->get_header( 'x-postmates-signature' ) ?: $request->get_header( 'x-uber-signature' ) ?: '';
        $secret    = LTMS_Core_Config::get( 'ltms_uber_direct_webhook_secret', '' );

        // FIX C-02: Reject immediately when no webhook secret is configured.
        // An empty secret would skip signature validation entirely, allowing
        // unauthenticated callers to forge delivery events and complete orders.
        if ( empty( $secret ) ) {
            LTMS_Core_Logger::warning(
                'UBER_WEBHOOK_NO_SECRET',
                'Uber Direct webhook secret is not configured. Request rejected to prevent unsigned webhook acceptance.'
            );
            return new \WP_REST_Response( [ 'error' => 'Webhook endpoint not configured' ], 401 );
        }

        if ( ! self::validate_signature( $payload, $signature, $secret ) ) {
            LTMS_Core_Logger::warning( 'UBER_WEBHOOK_INVALID_SIG', 'Invalid signature received', [ 'sig' => $signature ] );
            return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
        }

        $data       = json_decode( $payload, true );
        $event_type = $data['kind'] ?? $data['event_type'] ?? 'unknown';
        $delivery   = $data['data'] ?? [];
        $ext_id     = $delivery['external_id'] ?? $data['external_id'] ?? '';

        // API-BUG-11 FIX: event_id idempotency. Uber Direct may retry the same
        // webhook on timeout. Without dedup, the same status update would fire
        // multiple times (e.g., do_action('ltms_shipping_delivered') twice).
        // API-BUG-18 FIX: return 200 immediately if already processed.
        //
        // UB-BUG-1 FIX: the event_id must be UNIQUE PER WEBHOOK EVENT, not per
        // delivery. Using delivery_id (constant across all status updates of the
        // same delivery) caused every subsequent status webhook
        // (pickup_complete → en_route_to_dropoff → delivered) to be deduplicated
        // against the first one, so orders were stuck in the first status and
        // `delivered` webhooks were silently dropped (Consumer Protection holds
        // never released). Prefer Uber's own top-level event_id/webhook_id when
        // present; otherwise derive a unique id from delivery_id + status +
        // timestamp (with a random suffix to guarantee uniqueness when the
        // timestamp is missing or repeated).
        $delivery_id = $delivery['id'] ?? $delivery['delivery_id'] ?? '';
        $status      = $delivery['status'] ?? $data['status'] ?? '';
        $timestamp   = $data['timestamp'] ?? $delivery['timestamp'] ?? '';
        // Note: $payload is the raw request body string (see $request->get_body()
        // above); top-level webhook fields live in the decoded $data array.
        $event_id    = $data['event_id']
            ?? $data['webhook_id']
            ?? md5( implode( '|', [
                $delivery_id,
                $status,
                $timestamp,
                wp_generate_password( 8, false ),
            ] ) );
        $seen_key    = 'ltms_wh_seen_uber_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            LTMS_Core_Logger::info(
                'UBER_WEBHOOK_REPLAY',
                sprintf( 'Duplicate Uber Direct event %s ignored (already processed).', $event_id ),
                [ 'event_id' => $event_id ]
            );
            return new \WP_REST_Response( [ 'message' => 'Already processed' ], 200 );
        }
        set_transient( $seen_key, 1, HOUR_IN_SECONDS );

        // Log webhook
        global $wpdb;
        $order_id = (int) $ext_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $wpdb->prefix . 'lt_webhook_logs', [
            'provider'   => 'uber',
            'event_type' => $event_type,
            'payload'    => $payload,
            'signature'  => $signature,
            'is_valid'   => 1,
            'status'     => 'processing',
            'order_id'   => $order_id ?: null,
            'created_at' => LTMS_Utils::now_utc(),
        ], [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ] );

        $log_id = (int) $wpdb->insert_id;

        // Process event
        try {
            self::process_event( $event_type, $delivery, $order_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_webhook_logs',
                [ 'status' => 'processed', 'processed_at' => LTMS_Utils::now_utc() ],
                [ 'id' => $log_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'UBER_WEBHOOK_PROCESS_ERROR', $e->getMessage() );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_webhook_logs',
                [ 'status' => 'failed', 'error_message' => $e->getMessage() ],
                [ 'id' => $log_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }

        return new \WP_REST_Response( [ 'received' => true ], 200 );
    }

    private static function process_event( string $event_type, array $delivery, int $order_id ): void {
        if ( ! $order_id ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $tracking_url = $delivery['tracking_url'] ?? '';
        $status       = $delivery['status'] ?? '';

        switch ( $event_type ) {
            case 'delivery.status.changed':
            case 'eats.delivery.status_changed':
                $order->update_meta_data( '_ltms_uber_delivery_status', $status );
                if ( $tracking_url ) {
                    $order->update_meta_data( '_ltms_uber_tracking_url', $tracking_url );
                }
                if ( in_array( $status, [ 'delivered', 'dropoff_complete' ], true ) ) {
                    $order->update_status( 'completed', __( 'Entregado por Uber Direct.', 'ltms' ) );
                    // M-202: notifica al módulo Consumer_Protection para extender hold y marcar delivered_at.
                    do_action( 'ltms_shipping_delivered', $order_id, 'uber_direct' );
                } elseif ( in_array( $status, [ 'pickup_complete', 'en_route_to_dropoff' ], true ) ) {
                    $order->update_status( 'wc-shipped', __( 'En camino — Uber Direct.', 'ltms' ) );
                } elseif ( in_array( $status, [ 'returned', 'canceled', 'cancelled', 'failed' ], true ) ) {
                    // M-202: shipping fallido — congela el hold para revisión manual.
                    do_action( 'ltms_shipping_failed', $order_id, 'uber_direct:' . $status );
                }
                $order->save();
                break;
        }

        LTMS_Core_Logger::info( 'UBER_WEBHOOK_PROCESSED', sprintf( 'Evento %s para pedido #%d procesado.', $event_type, $order_id ) );
    }

    private static function validate_signature( string $payload, string $signature, string $secret ): bool {
        // AUDIT-SHIPPING-ENGINE #23 FIX: Uber Direct envía la firma HMAC-SHA256
        // en formato HEX (no base64). El código anterior comparaba hex contra
        // hex lo cual es correcto, PERO si la firma viene en base64 (algunos
        // setups de Uber), la comparación siempre fallaba → TODOS los webhooks
        // eran rechazados con 401.
        // Ahora intentamos ambos formatos: hex y base64.
        $expected_hex = hash_hmac( 'sha256', $payload, $secret );
        $expected_b64 = base64_encode( hex2bin( $expected_hex ) );

        // Try hex comparison first (standard Uber Direct).
        if ( hash_equals( $expected_hex, $signature ) ) {
            return true;
        }
        // Try base64 comparison (alternative format).
        if ( hash_equals( $expected_b64, $signature ) ) {
            return true;
        }
        // Try case-insensitive hex comparison (some proxies lowercase the header).
        if ( hash_equals( strtolower( $expected_hex ), strtolower( $signature ) ) ) {
            return true;
        }

        return false;
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * @return string
     */
    private static function client_ip(): string {
        // WH3 FIX (v2.8.9): delegar a LTMS_Core_Security::get_client_ip_safe().
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            return LTMS_Core_Security::get_client_ip_safe();
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
