<?php
/**
 * LTMS Shipping Method: Free Absorbed
 *
 * Modo "precio todo incluido": el cliente ve $0, el vendedor asume el flete.
 * El sistema cotiza internamente y guarda el proveedor más económico en sesión WC
 * para debitarlo de la billetera del vendedor al completar el pago.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/shipping
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Shipping_Method_Free_Absorbed
 */
class LTMS_Shipping_Method_Free_Absorbed extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'ltms_free_absorbed';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Envío incluido (LTMS)', 'ltms' );
        $this->method_description = __( 'El vendedor asume el costo del envío. El cliente ve $0.', 'ltms' );
        $this->supports           = [ 'shipping-zones', 'instance-settings' ];
        parent::__construct( $instance_id );
    }

    public function calculate_shipping( $package = [] ): void {
        $mode = LTMS_Core_Config::get( 'ltms_shipping_mode', 'quoted' );
        if ( 'quoted' === $mode ) {
            return;
        }

        // Modo hybrid: verificar si algún producto está en categoría de cotización visible
        if ( 'hybrid' === $mode ) {
            $free_cats = LTMS_Core_Config::get( 'ltms_shipping_free_categories', '' );
            if ( $free_cats ) {
                $cat_ids = array_filter( array_map( 'intval', explode( ',', $free_cats ) ) );
                foreach ( $package['contents'] ?? [] as $item ) {
                    $product_cats = wc_get_product_term_ids( (int) ( $item['product_id'] ?? 0 ), 'product_cat' );
                    if ( ! empty( array_intersect( $product_cats, $cat_ids ) ) ) {
                        return; // Algún producto requiere cotización visible
                    }
                }
            }
        }

        $min_amount = (float) LTMS_Core_Config::get( 'ltms_shipping_free_min_amount', 0 );
        if ( $min_amount > 0 ) {
            $subtotal = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
            if ( $subtotal < $min_amount ) {
                return;
            }
        }

        // Cotizar en background y guardar en sesión para metadata de orden
        try {
            if ( class_exists( 'LTMS_Shipping_Parallel_Quoter' ) ) {
                $quote = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote( $package );
                if ( $quote && WC()->session ) {
                    WC()->session->set( 'ltms_absorbed_shipping_quote', $quote );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Free Absorbed: quote failed — ' . $e->getMessage() );
        }

        $this->add_rate( [
            'id'      => $this->get_rate_id(),
            'label'   => __( 'Envío gratis', 'ltms' ),
            'cost'    => 0,
            'package' => $package,
        ] );
    }
}
