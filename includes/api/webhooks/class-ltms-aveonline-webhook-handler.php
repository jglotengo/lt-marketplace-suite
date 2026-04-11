<?php
class LTMS_Aveonline_Webhook_Handler {

    public static function init(): void {}

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        $tracking = sanitize_text_field( $body['tracking_number'] ?? '' );
        $status   = sanitize_key( $body['status'] ?? '' );

        if ( ! $tracking || ! $status ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        global $wpdb;
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ltms_aveonline_tracking' AND meta_value = %s LIMIT 1",
            $tracking
        ) );

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_shipping_status', $status );
                $order->add_order_note( sprintf( __( 'Aveonline tracking %s — estado: %s', 'ltms' ), $tracking, $status ) );
                if ( 'DELIVERED' === strtoupper( $status ) ) {
                    $order->update_status( 'completed', __( 'Entregado por Aveonline.', 'ltms' ) );
                }
                $order->save();
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'AVEONLINE_WEBHOOK', "Tracking={$tracking}, status={$status}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ZAPSIGN
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Zapsign_Webhook_Handler
 *
 * Procesa eventos de firma electrónica de ZapSign.
 * Actualiza el estado KYC del vendedor cuando el documento es firmado.
 */
