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
            'permission_callback' => '__return_true', // Validation done inside handle()
        ] );
    }

    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
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
                } elseif ( in_array( $status, [ 'pickup_complete', 'en_route_to_dropoff' ], true ) ) {
                    $order->update_status( 'wc-shipped', __( 'En camino — Uber Direct.', 'ltms' ) );
                }
                $order->save();
                break;
        }

        LTMS_Core_Logger::info( 'UBER_WEBHOOK_PROCESSED', sprintf( 'Evento %s para pedido #%d procesado.', $event_type, $order_id ) );
    }

    private static function validate_signature( string $payload, string $signature, string $secret ): bool {
        $expected = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, $signature );
    }
}
