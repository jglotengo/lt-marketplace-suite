<?php
/**
 * Stub minimalista de WC_Product_Simple para tests e2e (modo UNIT_ONLY).
 *
 * Solo implementa los métodos que LTMS_Vtex_Sync usa en el path de creación
 * de productos. Guarda el último SKU asignado para que el test pueda
 * verificar que el RefId VTEX llegó al producto WooCommerce.
 *
 * @package LTMS\Tests
 */

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	class WC_Product_Attribute {
		private $data = [];
		public function set_name( string $n ) { $this->data['name'] = $n; return $this; }
		public function set_options( array $o ) { $this->data['options'] = $o; return $this; }
		public function set_position( int $p ) { $this->data['position'] = $p; return $this; }
		public function set_visible( bool $v ) { $this->data['visible'] = $v; return $this; }
		public function set_variation( bool $v ) { $this->data['variation'] = $v; return $this; }
	}
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	class WC_Product_Simple extends \WC_Product {
		public static $last_sku = '';
		private $data = [];

		public function __call( $name, $args ) {
			if ( strpos( $name, 'set_' ) === 0 ) {
				$this->data[ substr( $name, 4 ) ] = $args[0] ?? null;
				if ( 'sku' === substr( $name, 4 ) ) {
					self::$last_sku = (string) $args[0];
				}
				return $this;
			}
			return $this;
		}
		public function set_attributes( array $a ) { $this->data['attributes'] = $a; return $this; }
		public function set_category_ids( array $a ) { $this->data['category_ids'] = $a; return $this; }
		public function update_meta_data( $k, $v ) { $this->data[ 'meta_' . $k ] = $v; return $this; }
		public function save(): int { return 1234; }
		public function get_id(): int { return 1234; }
		public function get_meta( $k = '' ) { return $this->data[ 'meta_' . $k ] ?? ''; }
	}
}