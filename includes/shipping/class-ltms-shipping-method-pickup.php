<?php
/**
 * Método de envío: Recogida en Tienda (Pickup)
 *
 * P-03: label limpio en el radio button del checkout; tarjeta estructurada con
 * dirección, horario y teléfono inyectada vía woocommerce_after_shipping_rate.
 *
 * @package LTMS\Shipping
 * @since   2.5.0
 */
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
        // P-03: inyectar la tarjeta estructurada debajo del radio button de pickup
        add_action( 'woocommerce_after_shipping_rate', [ __CLASS__, 'render_store_info_card' ], 10, 2 );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [ 'title' => __( 'Habilitar', 'ltms' ), 'type' => 'checkbox', 'default' => 'yes' ],
            'title'   => [ 'title' => __( 'Título', 'ltms' ),    'type' => 'text',     'default' => __( 'Recogida en Tienda', 'ltms' ) ],
        ];
    }

    public function calculate_shipping( $package = [] ): void {
        // Extraer vendor_id del primer ítem del paquete (P-02 garantiza que
        // solo hay un vendedor en el carrito cuando pickup está disponible).
        $vendor_id = 0;
        foreach ( $package['contents'] ?? [] as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            $vid = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( $vid ) {
                $vendor_id = $vid;
                break;
            }
        }

        // P-03: guardar la info de tienda en meta_data del rate (no en el label).
        // La tarjeta se renderiza por render_store_info_card() vía hook.
        $store_info = [];
        if ( $vendor_id ) {
            $store_info = LTMS_Business_Pickup_Handler::get_vendor_store_info( $vendor_id );
        }

        $this->add_rate( [
            'id'        => $this->get_rate_id(),
            'label'     => $this->title, // P-03: label limpio, sin concatenación de dirección
            'cost'      => 0,
            'meta_data' => [
                '_ltms_pickup_vendor_id'  => $vendor_id,
                '_ltms_pickup_store_info' => wp_json_encode( $store_info ),
            ],
        ] );
    }

    /**
     * P-03: Renderiza la tarjeta de información de tienda debajo del radio button
     * de recogida en tienda, solo cuando ese rate está seleccionado.
     *
     * @param \WC_Shipping_Rate $rate     Rate que acaba de renderizarse.
     * @param int               $index    Índice del rate en la lista.
     */
    public static function render_store_info_card( \WC_Shipping_Rate $rate, int $index ): void {
        if ( strpos( $rate->get_method_id(), 'ltms_pickup' ) === false ) {
            return;
        }

        $raw        = $rate->get_meta_data()['_ltms_pickup_store_info'] ?? '';
        $store_info = $raw ? json_decode( $raw, true ) : [];

        $address = esc_html( $store_info['address'] ?? '' );
        $hours   = esc_html( $store_info['hours']   ?? '' );
        $phone   = esc_html( $store_info['phone']   ?? '' );

        if ( ! $address && ! $hours && ! $phone ) {
            return; // Sin datos configurados, no mostrar tarjeta vacía
        }

        echo '<div class="ltms-pickup-card" style="'
            . 'display:none;' // JS de checkout lo muestra al seleccionar este método
            . 'margin:8px 0 4px 24px;padding:12px 14px;'
            . 'background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;'
            . 'font-size:.85rem;line-height:1.6;color:#1e3a5f;"'
            . ' data-pickup-rate-id="' . esc_attr( $rate->get_id() ) . '">';

        if ( $address ) {
            echo '<div>📍 <strong>' . esc_html__( 'Dirección:', 'ltms' ) . '</strong> ' . $address . '</div>';
        }
        if ( $hours ) {
            echo '<div>🕐 <strong>' . esc_html__( 'Horario:', 'ltms' ) . '</strong> ' . $hours . '</div>';
        }
        if ( $phone ) {
            echo '<div>📞 <strong>' . esc_html__( 'Teléfono:', 'ltms' ) . '</strong> ' . $phone . '</div>';
        }

        echo '</div>';
    }

    public static function register( array $methods ): array {
        $methods[] = 'LTMS_Shipping_Method_Pickup';
        return $methods;
    }
}
