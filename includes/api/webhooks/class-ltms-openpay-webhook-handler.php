<?php
class LTMS_Openpay_Webhook_Handler {

    public static function init(): void {}

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        $event_type = sanitize_key( $body['type'] ?? '' );
        $charge     = $body['transaction'] ?? [];

        if ( empty( $event_type ) || empty( $charge ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // Buscar el pedido por el order_id almacenado en metadata de la transacción
        $order_id = absint( $charge['order_id'] ?? 0 );
        if ( ! $order_id ) {
            // Buscar por transaction_id
            $order_id = (int) self::find_order_by_txn( sanitize_text_field( $charge['id'] ?? '' ) );
        }

        if ( ! $order_id ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 ); // No error, solo ignorar
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
        }

        switch ( $event_type ) {
            case 'charge.succeeded':
                if ( $order->needs_payment() ) {
                    $order->payment_complete( sanitize_text_field( $charge['id'] ?? '' ) );
                    $order->add_order_note( __( 'Pago Openpay confirmado vía webhook.', 'ltms' ) );
                }
                break;
            case 'charge.failed':
                $order->update_status( 'failed', __( 'Pago Openpay fallido vía webhook.', 'ltms' ) );
                break;
            case 'refund.succeeded':
                $order->update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook.', 'ltms' ) );
                break;
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'OPENPAY_WEBHOOK', "Evento={$event_type}, pedido=#{$order_id}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    private static function find_order_by_txn( string $txn_id ): int {
        if ( ! $txn_id ) return 0;
        global $wpdb;
        // HPOS-aware: intentar primero meta de órdenes
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_openpay_transaction_id' AND meta_value = %s LIMIT 1",
            $txn_id
        ) );
        if ( ! $order_id ) {
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_openpay_transaction_id' AND meta_value = %s LIMIT 1",
                $txn_id
            ) );
        }
        return $order_id;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SIIGO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Siigo_Webhook_Handler
 *
 * Procesa callbacks de Siigo (estado de facturas electrónicas DIAN).
 */
