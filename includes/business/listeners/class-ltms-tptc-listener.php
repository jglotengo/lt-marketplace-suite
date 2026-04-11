<?php
class LTMS_TPTC_Listener {

    use LTMS_Logger_Aware;

    /**
     * Registra el hook sobre el pago completado.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
    }

    /**
     * Maneja el evento de pago completado.
     *
     * @param int $order_id
     * @return void
     */
    public static function on_order_paid( int $order_id ): void {
        $tptc_enabled = LTMS_Core_Config::get( 'ltms_tptc_enabled', 'no' );
        if ( 'yes' !== $tptc_enabled ) {
            return;
        }

        // Prevenir doble procesamiento
        if ( get_post_meta( $order_id, '_ltms_tptc_synced', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Solo pedidos con vendor LTMS
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            return;
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Tptc' ) ) {
                return;
            }

            $client = LTMS_Api_Factory::get( 'tptc' );
            $result = $client->register_sale( [
                'order_id'     => $order_id,
                'vendor_id'    => $vendor_id,
                'customer_email' => $order->get_billing_email(),
                'amount'       => (float) $order->get_total(),
                'currency'     => get_woocommerce_currency(),
                'date'         => $order->get_date_paid() ? $order->get_date_paid()->format( 'c' ) : gmdate( 'c' ),
                'items'        => array_map( fn( $item ) => [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total'    => (float) $item->get_total(),
                ], $order->get_items() ),
            ] );

            update_post_meta( $order_id, '_ltms_tptc_synced', 1 );
            update_post_meta( $order_id, '_ltms_tptc_transaction_id', $result['transaction_id'] ?? '' );

            self::log_info( 'TPTC_SYNCED', "Pedido #{$order_id} sincronizado con TPTC." );

        } catch ( \Throwable $e ) {
            self::log_error( 'TPTC_SYNC_FAILED', "Pedido #{$order_id}: " . $e->getMessage() );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// COUPON ATTRIBUTION LISTENER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Coupon_Attribution_Listener
 *
 * Registra la atribución de cupones y códigos de referido en cada pedido.
 * Permite al sistema MLM calcular comisiones por ventas atribuidas.
 */
