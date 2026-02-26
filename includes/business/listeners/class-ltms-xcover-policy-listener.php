<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_XCover_Policy_Listener
 * Creates and cancels XCover insurance policies on order events.
 */
class LTMS_XCover_Policy_Listener {

    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'on_order_cancelled' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'on_order_cancelled' ], 10 );
    }

    public static function on_order_paid( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Idempotency guard
        if ( $order->get_meta( '_ltms_insurance_policy_created' ) ) return;

        if ( $order->get_meta( '_ltms_insurance_selected' ) !== 'yes' ) return;

        $quote_id = $order->get_meta( '_ltms_insurance_quote_id' );
        if ( ! $quote_id ) return;

        $policy_data = self::build_policy_data( $order );

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );
            $result = $xcover->create_policy( $policy_data );

            $policy_id     = $result['policy_id'] ?? $result['id'] ?? '';
            $policy_number = $result['policy_number'] ?? $result['certificate_number'] ?? '';
            $cert_url      = $result['certificate_url'] ?? $result['certificate_download_url'] ?? '';
            $premium       = (float) ( $result['premium'] ?? 0 );

            $order->update_meta_data( '_ltms_insurance_policy_created', true );
            $order->update_meta_data( '_ltms_insurance_policy_id', $policy_id );
            $order->update_meta_data( '_ltms_insurance_policy_number', $policy_number );
            $order->update_meta_data( '_ltms_insurance_certificate_url', $cert_url );
            $order->save();

            $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            self::record_policy( $order_id, $vendor_id, $result, $premium );

            LTMS_Core_Logger::info( 'XCOVER_POLICY_CREATED', sprintf( 'Policy %s created for order #%d', $policy_id, $order_id ) );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'XCOVER_POLICY_CREATE_FAILED', sprintf( 'Order #%d: %s', $order_id, $e->getMessage() ) );
        }
    }

    public static function on_order_cancelled( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $policy_id = $order->get_meta( '_ltms_insurance_policy_id' );
        if ( ! $policy_id ) return;

        // Already cancelled?
        if ( $order->get_meta( '_ltms_insurance_policy_cancelled' ) ) return;

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );
            $result = $xcover->cancel_policy( $policy_id );

            $order->update_meta_data( '_ltms_insurance_policy_cancelled', true );
            $order->save();

            // Update lt_insurance_policies
            global $wpdb;
            $refund = (float) ( $result['refund_amount'] ?? 0 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_insurance_policies',
                [
                    'status'           => 'cancelled',
                    'cancellation_ref' => $result['cancellation_id'] ?? '',
                    'cancelled_at'     => LTMS_Utils::now_utc(),
                    'cancel_reason'    => 'order_cancelled',
                    'refund_amount'    => $refund,
                    'updated_at'       => LTMS_Utils::now_utc(),
                ],
                [ 'policy_id' => $policy_id ],
                [ '%s', '%s', '%s', '%s', '%f', '%s' ],
                [ '%s' ]
            );

            LTMS_Core_Logger::info( 'XCOVER_POLICY_CANCELLED', sprintf( 'Policy %s cancelled for order #%d', $policy_id, $order_id ) );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'XCOVER_POLICY_CANCEL_FAILED', sprintf( 'Order #%d: %s', $order_id, $e->getMessage() ) );
        }
    }

    public static function build_policy_data( \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [
                'name'  => $item->get_name(),
                'price' => (float) $item->get_total(),
                'qty'   => $item->get_quantity(),
            ];
        }

        return [
            'quote_id'       => $order->get_meta( '_ltms_insurance_quote_id' ),
            'insurance_type' => $order->get_meta( '_ltms_insurance_type' ) ?: 'parcel_protection',
            'order_id'       => (string) $order->get_id(),
            'customer'       => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ],
            'items'          => $items,
            'total'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
        ];
    }

    public static function record_policy( int $order_id, int $vendor_id, array $result, float $premium ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'lt_insurance_policies',
            [
                'order_id'        => $order_id,
                'vendor_id'       => $vendor_id,
                'quote_id'        => $result['quote_id'] ?? '',
                'policy_id'       => $result['policy_id'] ?? $result['id'] ?? '',
                'policy_number'   => $result['policy_number'] ?? '',
                'certificate_url' => $result['certificate_url'] ?? '',
                'insurance_type'  => $result['insurance_type'] ?? 'parcel_protection',
                'premium_amount'  => $premium,
                'currency'        => LTMS_Core_Config::get_currency(),
                'status'          => 'active',
                'metadata'        => wp_json_encode( $result ),
                'created_at'      => LTMS_Utils::now_utc(),
                'updated_at'      => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );
    }
}
