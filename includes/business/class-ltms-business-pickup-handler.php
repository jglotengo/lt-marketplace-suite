<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Business_Pickup_Handler {
    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_order_status' ] );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_set_ready_for_pickup' ], 20 );
        add_filter( 'wc_order_statuses', [ __CLASS__, 'add_order_status_label' ] );
        add_filter( 'ltms_after_tax_calculate', [ __CLASS__, 'adjust_ica_for_pickup' ], 10, 4 );
    }

    public static function register_order_status(): void {
        register_post_status( 'wc-ready-for-pickup', [
            'label'                     => _x( 'Listo para Recoger', 'Order status', 'ltms' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop( 'Listo para Recoger <span class="count">(%s)</span>', 'Listos para Recoger <span class="count">(%s)</span>', 'ltms' ),
        ] );
    }

    public static function add_order_status_label( array $statuses ): array {
        $statuses['wc-ready-for-pickup'] = _x( 'Listo para Recoger', 'Order status', 'ltms' );
        return $statuses;
    }

    public static function maybe_set_ready_for_pickup( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Check if pickup shipping method was chosen
        $shipping_methods = $order->get_shipping_methods();
        $is_pickup        = false;
        foreach ( $shipping_methods as $method ) {
            if ( strpos( $method->get_method_id(), 'ltms_pickup' ) !== false ) {
                $is_pickup = true;
                break;
            }
        }

        if ( $is_pickup ) {
            $order->update_status( 'wc-ready-for-pickup', __( 'Pedido listo para recogida en tienda.', 'ltms' ) );
            self::send_pickup_notification( $order );
        }
    }

    private static function send_pickup_notification( \WC_Order $order ): void {
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) return;

        $store_info = self::get_vendor_store_info( $vendor_id );

        try {
            global $wpdb;
            $wpdb->insert( $wpdb->prefix . 'lt_notifications', [
                'user_id'    => (int) $order->get_customer_id(),
                'type'       => 'pickup_ready',
                'channel'    => 'inapp',
                'title'      => __( 'Pedido Listo para Recoger', 'ltms' ),
                'message'    => sprintf(
                    /* translators: %1$s: order number, %2$s: store address */
                    __( 'Tu pedido #%1$s está listo. Recógelo en: %2$s', 'ltms' ),
                    $order->get_order_number(),
                    $store_info['address'] ?? __( 'Local del vendedor', 'ltms' )
                ),
                'data'       => wp_json_encode( [ 'order_id' => $order->get_id(), 'store_info' => $store_info ] ),
                'is_read'    => 0,
                'created_at' => LTMS_Utils::now_utc(),
            ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] ); // phpcs:ignore
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'PICKUP_NOTIFICATION_FAILED', $e->getMessage() );
        }
    }

    public static function get_vendor_store_info( int $vendor_id ): array {
        return [
            'address'      => get_user_meta( $vendor_id, 'ltms_store_address', true ) ?: '',
            'hours'        => get_user_meta( $vendor_id, 'ltms_store_hours', true ) ?: '',
            'phone'        => get_user_meta( $vendor_id, 'ltms_store_phone', true ) ?: '',
            'municipality' => get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: '',
        ];
    }

    /**
     * Adjusts ICA calculation for pickup orders (vendor municipality based).
     *
     * @param array     $result      Tax calculation result.
     * @param \WC_Order $order       WC Order.
     * @param array     $vendor_data Vendor data.
     * @param string    $country     Country code.
     * @return array
     */
    public static function adjust_ica_for_pickup( array $result, $order, array $vendor_data, string $country ): array {
        if ( $country !== 'CO' || ! ( $order instanceof \WC_Order ) ) {
            return $result;
        }

        $shipping_methods = $order->get_shipping_methods();
        $is_pickup        = false;
        foreach ( $shipping_methods as $method ) {
            if ( strpos( $method->get_method_id(), 'ltms_pickup' ) !== false ) {
                $is_pickup = true;
                break;
            }
        }

        if ( ! $is_pickup ) {
            return $result;
        }

        // For pickup, ICA uses the vendor's municipality instead of billing city
        $vendor_id           = (int) ( $vendor_data['vendor_id'] ?? 0 );
        $vendor_municipality = $vendor_id
            ? ( get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: '' )
            : '';

        if ( $vendor_municipality ) {
            $result['_ica_municipality'] = $vendor_municipality;
        }

        return $result;
    }
}
