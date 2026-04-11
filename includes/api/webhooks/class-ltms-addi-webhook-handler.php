<?php
class LTMS_Addi_Webhook_Handler {

    public static function init(): void {
        // El router ya gestiona /webhooks/addi; este método es por compatibilidad con el kernel.
    }

    /**
     * Maneja la petición entrante de Addi.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body['orderId'] ) || empty( $body['status'] ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        $order_id = absint( $body['orderId'] );
        $status   = sanitize_key( $body['status'] );

        // Verificar token de seguridad
        $token          = $request->get_header( 'x-addi-signature' ) ?: '';
        $expected_token = LTMS_Core_Config::get( 'ltms_addi_webhook_token', '' );
        if ( $expected_token && ! hash_equals( $expected_token, $token ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
        }

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
}

// ─────────────────────────────────────────────────────────────────────────────
// OPENPAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Openpay_Webhook_Handler
 *
 * Procesa notificaciones de Openpay (charge.succeeded, charge.failed, refund.*).
 */
