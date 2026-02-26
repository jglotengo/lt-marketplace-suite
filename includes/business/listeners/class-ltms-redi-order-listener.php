<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_Redi_Order_Listener
 * Listens for payment complete and processes ReDi item splits (priority 20).
 */
class LTMS_Redi_Order_Listener {

    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'on_order_cancelled' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'on_order_cancelled' ], 10 );
    }

    public static function on_order_paid( int $order_id ): void {
        // Idempotency guard
        if ( get_post_meta( $order_id, '_ltms_redi_processed', true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( ! class_exists( 'LTMS_Business_Redi_Manager' ) ) return;

        $redi_items = LTMS_Business_Redi_Manager::detect_redi_items( $order );
        if ( empty( $redi_items ) ) return;

        update_post_meta( $order_id, '_ltms_redi_processed', true );

        LTMS_Business_Redi_Order_Split::process( $order, $redi_items );
        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        LTMS_Core_Logger::info(
            'REDI_ORDER_PROCESSED',
            sprintf( 'ReDi processed for order #%d: %d items', $order_id, count( $redi_items ) )
        );
    }

    public static function on_order_cancelled( int $order_id ): void {
        if ( ! get_post_meta( $order_id, '_ltms_redi_processed', true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Create reversal entries and restore origin stock
        global $wpdb;
        $commissions = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM `{$wpdb->prefix}lt_redi_commissions` WHERE order_id = %d AND status = 'paid'",
            $order_id
        ) );

        foreach ( $commissions as $commission ) {
            // Reversal wallet entries
            try {
                LTMS_Wallet::debit(
                    (int) $commission->origin_vendor_id,
                    (float) $commission->origin_vendor_net,
                    'reversal',
                    sprintf( __( 'Reversión ReDi pedido #%s', 'ltms' ), $order->get_order_number() ),
                    [ 'order_id' => $order_id, 'redi_commission_id' => $commission->id ]
                );
                LTMS_Wallet::debit(
                    (int) $commission->reseller_vendor_id,
                    (float) $commission->reseller_commission,
                    'reversal',
                    sprintf( __( 'Reversión ReDi pedido #%s (revendedor)', 'ltms' ), $order->get_order_number() ),
                    [ 'order_id' => $order_id ]
                );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error( 'REDI_REVERSAL_FAILED', $e->getMessage() );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_redi_commissions',
                [ 'status' => 'reversed' ],
                [ 'id' => $commission->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        // Restore origin stock
        if ( class_exists( 'LTMS_Business_Redi_Manager' ) ) {
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                if ( LTMS_Business_Redi_Manager::is_redi_product( $pid ) ) {
                    $origin_pid = LTMS_Business_Redi_Manager::get_origin_product_id( $pid );
                    if ( $origin_pid ) {
                        $origin_product = wc_get_product( $origin_pid );
                        if ( $origin_product && $origin_product->managing_stock() ) {
                            $origin_product->set_stock_quantity( $origin_product->get_stock_quantity() + $item->get_quantity() );
                            $origin_product->save();
                        }
                    }
                }
            }
        }

        LTMS_Core_Logger::info( 'REDI_ORDER_REVERSED', sprintf( 'ReDi reversed for order #%d', $order_id ) );
    }
}
