<?php
class LTMS_Siigo_Webhook_Handler {

    public static function init(): void {}

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        $invoice_id = sanitize_text_field( $body['invoiceId'] ?? '' );
        $status     = sanitize_key( $body['status'] ?? '' );

        if ( ! $invoice_id || ! $status ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

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
}

// ─────────────────────────────────────────────────────────────────────────────
// AVEONLINE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Aveonline_Webhook_Handler
 *
 * Procesa eventos de estado de envío de Aveonline.
 */
