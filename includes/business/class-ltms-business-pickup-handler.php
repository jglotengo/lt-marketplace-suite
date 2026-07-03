<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Business_Pickup_Handler {
    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_order_status' ] );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_set_ready_for_pickup' ], 20 );
        add_filter( 'wc_order_statuses', [ __CLASS__, 'add_order_status_label' ] );
        add_filter( 'ltms_after_tax_calculate', [ __CLASS__, 'adjust_ica_for_pickup' ], 10, 4 );
        add_action( 'wp_ajax_ltms_mark_pickup_completed', [ __CLASS__, 'ajax_mark_pickup_completed' ] );

        // AUDIT-SHIPPING-ENGINE #8 FIX: cuando un pedido pickup se marca como
        // completed (el cliente recogió el producto), disparar ltms_shipping_delivered
        // para que el consumer protection hold se libere.
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_pickup_completed' ], 5 );

        // AJAX handler para que el vendor marque el pickup como completado.
        add_action( 'wp_ajax_ltms_pickup_mark_picked_up', [ __CLASS__, 'ajax_mark_picked_up' ] );
    }

    /**
     * AJAX (admin): marca un pedido de recogida como completado/entregado.
     *
     * Reubicado desde LTMS_Admin_Redi (chore/pickup): este handler pertenece
     * al módulo de pickup, no a ReDi. Ver includes/admin/views/html-admin-pickup-orders.php
     * para el botón "Marcar Entregado" que lo invoca.
     *
     * @return void
     */
    public static function ajax_mark_pickup_completed(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_view_all_orders' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Pedido no encontrado.', 'ltms' ) );
        }

        $order->update_status( 'completed', __( 'Recogido por el cliente.', 'ltms' ) );

        LTMS_Core_Logger::info(
            'PICKUP_MARKED_COMPLETED',
            sprintf( 'Order #%d marked completed (pickup) by admin #%d', $order_id, get_current_user_id() )
        );

        wp_send_json_success( [ 'message' => __( 'Pedido marcado como completado.', 'ltms' ) ] );
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

    /**
     * AUDIT-SHIPPING-ENGINE #8 FIX: cuando un pedido pickup se marca como
     * completed, disparar ltms_shipping_delivered para liberar el hold
     * de consumer protection. Antes esto nunca se hacía → los holds de
     * pedidos pickup duraban 5-10 días sin liberarse.
     *
     * @param int $order_id
     * @return void
     */
    public static function on_pickup_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Solo si es pickup.
        $is_pickup = false;
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( strpos( $method->get_method_id(), 'ltms_pickup' ) !== false ) {
                $is_pickup = true;
                break;
            }
        }
        if ( ! $is_pickup ) return;

        // Idempotency guard.
        if ( $order->get_meta( '_ltms_shipping_delivered_fired' ) ) return;

        $order->update_meta_data( '_ltms_shipping_delivered_fired', 1 );
        $order->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->update_meta_data( '_ltms_pickup_completed_at', current_time( 'mysql', true ) );
        $order->save();

        do_action( 'ltms_shipping_delivered', $order_id );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'PICKUP_DELIVERED',
                sprintf( 'Pickup order #%d marked as completed — ltms_shipping_delivered fired.', $order_id ),
                [ 'order_id' => $order_id ]
            );
        }
    }

    /**
     * AUDIT-SHIPPING-ENGINE #8 FIX: AJAX handler para que el vendor marque
     * un pedido pickup como completado (el cliente recogió el producto).
     *
     * @return void
     */
    public static function ajax_mark_picked_up(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id || ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Order ID requerido.', 'ltms' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'ltms' ) ] );
        }

        // Verificar ownership.
        $order_vendor = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( $order_vendor !== $user_id && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado.', 'ltms' ) ], 403 );
        }

        // Verificar que es pickup.
        $is_pickup = false;
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( strpos( $method->get_method_id(), 'ltms_pickup' ) !== false ) {
                $is_pickup = true;
                break;
            }
        }
        if ( ! $is_pickup ) {
            wp_send_json_error( [ 'message' => __( 'Este pedido no es de recogida en tienda.', 'ltms' ) ] );
        }

        // Marcar como completado — esto dispara on_pickup_completed() que
        // a su vez dispara ltms_shipping_delivered.
        $order->update_status( 'completed', __( 'Producto recogido por el cliente.', 'ltms' ) );

        wp_send_json_success( [ 'message' => __( 'Pedido marcado como recogido.', 'ltms' ) ] );
    }
}
