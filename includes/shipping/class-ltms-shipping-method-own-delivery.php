<?php
/**
 * LTMS Shipping Method: Domiciliario Propio del Vendedor
 *
 * Solo visible en checkout si el vendedor tiene al menos 1 domiciliario
 * activo y disponible. Precio y detalles configurados por el vendedor desde
 * su panel.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/shipping
 * @version    1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class LTMS_Shipping_Method_Own_Delivery
 */
class LTMS_Shipping_Method_Own_Delivery extends WC_Shipping_Method {

        /**
         * Constructor.
         *
         * @param int $instance_id Instance ID.
         */
        public function __construct( int $instance_id = 0 ) {
                $this->id                 = 'ltms_own_delivery';
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Domiciliario propio', 'ltms' );
                $this->method_description = __( 'Entrega con domiciliario propio del vendedor. Solo disponible si el vendedor tiene repartidores activos.', 'ltms' );
                $this->supports           = [ 'shipping-zones', 'instance-settings' ];
                $this->title              = $this->get_option( 'title', __( 'Domiciliario propio', 'ltms' ) );

                $this->init();

                // AUDIT-SHIPPING-ENGINE #10 FIX: AJAX handler para marcar
                // pedidos own-delivery como entregados.
                add_action( 'wp_ajax_ltms_own_delivery_mark_delivered', [ __CLASS__, 'ajax_mark_delivered' ] );
                // AUDIT-SHIPPING-ENGINE #10: disparar ltms_shipping_delivered
                // cuando un own-delivery order se completa.
                add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_own_delivery_completed' ], 5 );
        }

        /**
         * Initialize the shipping method.
         */
        public function init(): void {
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
        }

        /**
         * Define admin form fields.
         */
        public function init_form_fields(): void {
                $this->instance_form_fields = [
                        'title' => [
                                'title'   => __( 'Título', 'ltms' ),
                                'type'    => 'text',
                                'default' => __( 'Domiciliario propio', 'ltms' ),
                        ],
                ];
        }

        /**
         * Calculate shipping — adds a rate only if vendor has available drivers
         * AND the customer's address is within the vendor's delivery zone.
         *
         * AUDIT-SHIPPING-ENGINE #9 FIX: antes NO se validaba la zona de
         * entrega del vendor — un cliente a 200km podía seleccionar
         * domiciliario propio. Ahora se valida el código postal o ciudad
         * del cliente contra las zonas configuradas del vendor.
         *
         * @param array $package WooCommerce package.
         */
        public function calculate_shipping( $package = [] ): void {
                $vendor_id = self::get_vendor_id_from_package( $package );
                if ( ! $vendor_id ) {
                        return;
                }

                if ( ! self::vendor_has_available_driver( $vendor_id ) ) {
                        return;
                }

                // AUDIT-SHIPPING-ENGINE #9 FIX: validar zona de entrega.
                $delivery_zones = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_zones', true );
                if ( ! self::is_address_in_delivery_zone( $package, $delivery_zones ) ) {
                        return; // Customer outside delivery zone — don't show this method.
                }

                $price   = (float) get_user_meta( $vendor_id, 'ltms_own_delivery_price', true );
                $price   = $price > 0 ? $price : 0.0;
                $eta     = (int) get_user_meta( $vendor_id, 'ltms_own_delivery_eta_minutes', true );
                $eta     = $eta > 0 ? $eta : 60;
                $message = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_message', true );

                $label = sprintf(
                        /* translators: %d: estimated time in minutes */
                        __( 'Domiciliario propio (~%d min)', 'ltms' ),
                        $eta
                );
                if ( $message ) {
                        $label .= ' — ' . $message;
                }

                $this->add_rate( [
                        'id'        => $this->get_rate_id(),
                        'label'     => $label,
                        'cost'      => $price,
                        'meta_data' => [
                                'ltms_vendor_id' => $vendor_id,
                                'ltms_eta_min'   => $eta,
                                'ltms_zones'     => $delivery_zones,
                        ],
                ] );
        }

        /**
         * AUDIT-SHIPPING-ENGINE #9 FIX: valida si la dirección del cliente
         * está dentro de la zona de entrega del vendor.
         *
         * Las zonas se configuran como una lista comma-separated de
         * ciudades/municipios o códigos postales. Si está vacía, se
         * permite todo (backward compatible).
         *
         * @param array  $package        WC checkout package.
         * @param string $delivery_zones Comma-separated zones.
         * @return bool
         */
        private static function is_address_in_delivery_zone( array $package, string $delivery_zones ): bool {
                if ( empty( $delivery_zones ) ) {
                        return true; // No zone restriction configured.
                }

                $zones = array_map( 'trim', array_filter( explode( ',', strtolower( $delivery_zones ) ) ) );
                if ( empty( $zones ) ) {
                        return true;
                }

                $customer_city    = strtolower( trim( $package['destination']['city'] ?? '' ) );
                $customer_postcode = strtolower( trim( $package['destination']['postcode'] ?? '' ) );
                $customer_state    = strtolower( trim( $package['destination']['state'] ?? '' ) );

                foreach ( $zones as $zone ) {
                        $zone = strtolower( trim( $zone ) );
                        if ( $zone === $customer_city || $zone === $customer_postcode || $zone === $customer_state ) {
                                return true;
                        }
                        // Partial match (ej: "bogot" matches "Bogotá", "Bogota D.C.").
                        if ( ! empty( $customer_city ) && stripos( $customer_city, $zone ) !== false ) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Extracts the vendor ID from a WooCommerce package.
         *
         * @param array $package WooCommerce package.
         * @return int Vendor ID or 0.
         */
        private static function get_vendor_id_from_package( array $package ): int {
                foreach ( ( $package['contents'] ?? [] ) as $item ) {
                        $product_id = (int) ( $item['product_id'] ?? 0 );
                        if ( $product_id ) {
                                $vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
                                if ( $vendor_id ) {
                                        return $vendor_id;
                                }
                        }
                }
                return 0;
        }

        /**
         * Checks if the vendor has at least one active & available driver.
         *
         * @param int $vendor_id Vendor user ID.
         * @return bool
         */
        private static function vendor_has_available_driver( int $vendor_id ): bool {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                                // M-51: lt_vendor_drivers has no is_active/is_available columns — use status ENUM.
                                // Availability (transient) is ephemeral and not queryable via SQL — count active drivers as proxy.
                                "SELECT COUNT(*) FROM `{$wpdb->prefix}lt_vendor_drivers`
                                 WHERE vendor_id = %d AND status = 'active'",
                                $vendor_id
                        )
                );
                return $count > 0;
        }

        /**
         * AUDIT-SHIPPING-ENGINE #10 FIX: cuando un pedido own-delivery se
         * completa, disparar ltms_shipping_delivered para liberar el hold.
         */
        public static function on_own_delivery_completed( int $order_id ): void {
                $order = wc_get_order( $order_id );
                if ( ! $order ) return;

                $is_own_delivery = false;
                foreach ( $order->get_shipping_methods() as $method ) {
                        if ( strpos( $method->get_method_id(), 'own_delivery' ) !== false
                             || strpos( $method->get_method_id(), 'own-delivery' ) !== false ) {
                                $is_own_delivery = true;
                                break;
                        }
                }
                if ( ! $is_own_delivery ) return;

                if ( $order->get_meta( '_ltms_shipping_delivered_fired' ) ) return;

                $order->update_meta_data( '_ltms_shipping_delivered_fired', 1 );
                $order->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
                $order->save();

                do_action( 'ltms_shipping_delivered', $order_id );
        }

        /**
         * AUDIT-SHIPPING-ENGINE #10 FIX: AJAX handler para que el vendor
         * marque un pedido own-delivery como entregado.
         */
        public static function ajax_mark_delivered(): void {
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

                $order_vendor = (int) $order->get_meta( '_ltms_vendor_id' );
                if ( $order_vendor !== $user_id && ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( [ 'message' => __( 'No autorizado.', 'ltms' ) ], 403 );
                }

                $is_own = false;
                foreach ( $order->get_shipping_methods() as $method ) {
                        if ( strpos( $method->get_method_id(), 'own_delivery' ) !== false
                             || strpos( $method->get_method_id(), 'own-delivery' ) !== false ) {
                                $is_own = true;
                                break;
                        }
                }
                if ( ! $is_own ) {
                        wp_send_json_error( [ 'message' => __( 'Este pedido no usa domiciliario propio.', 'ltms' ) ] );
                }

                $order->update_status( 'completed', __( 'Pedido entregado por domiciliario propio.', 'ltms' ) );

                wp_send_json_success( [ 'message' => __( 'Pedido marcado como entregado.', 'ltms' ) ] );
        }
}
