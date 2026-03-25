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
	 * Calculate shipping — adds a rate only if vendor has available drivers.
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

		$price   = (float) get_user_meta( $vendor_id, 'ltms_own_delivery_price', true );
		$price   = $price > 0 ? $price : 0.0;
		$eta     = (int) get_user_meta( $vendor_id, 'ltms_own_delivery_eta_minutes', true );
		$eta     = $eta > 0 ? $eta : 60;
		$zones   = (string) get_user_meta( $vendor_id, 'ltms_own_delivery_zones', true );
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
				'ltms_zones'     => $zones,
			],
		] );
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
				"SELECT COUNT(*) FROM `{$wpdb->prefix}lt_vendor_drivers`
				 WHERE vendor_id = %d AND is_active = 1 AND is_available = 1",
				$vendor_id
			)
		);
		return $count > 0;
	}
}
