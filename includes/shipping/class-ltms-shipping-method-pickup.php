<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Method_Pickup extends WC_Shipping_Method {
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'ltms_pickup';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Recogida en Tienda', 'ltms' );
        $this->method_description = __( 'Recoge tu pedido directamente en el local del vendedor. Sin costo de envío.', 'ltms' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        $this->init();
    }

    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();
        $this->title   = $this->get_option( 'title', $this->method_title );
        $this->enabled = $this->get_option( 'enabled', 'yes' );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [ 'title' => __( 'Habilitar', 'ltms' ), 'type' => 'checkbox', 'default' => 'yes' ],
            'title'   => [ 'title' => __( 'Título', 'ltms' ), 'type' => 'text', 'default' => __( 'Recogida en Tienda', 'ltms' ) ],
        ];
    }

    public function calculate_shipping( $package = [] ): void {
        // Extract vendor_id from cart items to show vendor store info
        $vendor_id  = 0;
        $store_info = '';
        foreach ( $package['contents'] ?? [] as $item ) {
            $pid = $item['product_id'] ?? 0;
            $vid = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( $vid ) {
                $vendor_id = $vid;
                break;
            }
        }

        if ( $vendor_id ) {
            $store_address = get_user_meta( $vendor_id, 'ltms_store_address', true );
            $store_hours   = get_user_meta( $vendor_id, 'ltms_store_hours', true );
            if ( $store_address ) {
                $store_info = sprintf(
                    /* translators: %1$s: dirección, %2$s: horario */
                    __( 'Dirección: %1$s | Horario: %2$s', 'ltms' ),
                    esc_html( $store_address ),
                    esc_html( $store_hours ?: __( 'Consultar con el vendedor', 'ltms' ) )
                );
            }
        }

        $this->add_rate( [
            'id'    => $this->get_rate_id(),
            'label' => $this->title . ( $store_info ? ' — ' . $store_info : '' ),
            'cost'  => 0,
            'meta_data' => [
                '_ltms_pickup_vendor_id'  => $vendor_id,
                '_ltms_pickup_store_info' => $store_info,
            ],
        ] );
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Pickup';
        return $methods;
    }
}
