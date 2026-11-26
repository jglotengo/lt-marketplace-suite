<?php
/**
 * E2eBuyerCheckoutPolishTest — Tests del ciclo E2E-BUYER-CHECKOUT-POLISH
 * (2026-11-24, continúa el backlog P3 del ciclo E2E-BUYER-2026-11-20).
 *
 * Cubre:
 *   E2E-BUYER-HPOS-DETECT (P3): find_order_by_txn solo consulta wc_orders_meta
 *     cuando HPOS está habilitado (OrderUtil) — en legacy la tabla no existe y
 *     cada lookup generaba error MySQL por cada meta key del loop.
 *   E2E-BUYER-PAY-RADIO-CHECKED (P3): el checkout ya no pre-chequea el primer
 *     gateway ADEMÁS del gateway elegido en sesión (2 radios checked).
 *   E2E-BUYER-MAPS-REAL-IDS (P3): payment_meta/carrier_meta re-key a los IDs
 *     reales de gateways ($this->id: ltms_openpay/…) y methods
 *     (ltms_deprisa/…) — las keys fantasmas nunca matcheaban el lookup.
 *   E2E-BUYER-THRESHOLD-SYNC (P3): la barra del cart lee la MISMA fuente que
 *     el mini-cart y el single-product (ltms_shipping_hybrid_threshold) y no
 *     woocommerce_free_shipping_settings (setting que la tienda no usa).
 *   E2E-BUYER-LEGACY-DRAWER-DEAD (P3): el objeto JS LTMS_Drawer (~337 líneas)
 *     eliminado — operaba sobre markup #ltms-drawer-* que ningún template
 *     renderiza desde v2.9.208.
 *
 * Source-based (patrón E2eBuyerQuickViewHardeningTest): los handlers y
 * templates dependen de WP/WC runtime no disponible en modo unit.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Openpay_Webhook_Handler, LTMS_Geo_Detector
 */
class E2eBuyerCheckoutPolishTest extends LTMS_Unit_Test_Case {

	private const WEBHOOK_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const CHECKOUT_TPL     = __DIR__ . '/../../includes/frontend/templates/checkout.php';
	private const CART_TPL         = __DIR__ . '/../../includes/frontend/templates/cart.php';
	private const PE_JS_PATH       = __DIR__ . '/../../assets/js/ltms-product-enhancements.js';
	private const PE_JS_MIN_PATH   = __DIR__ . '/../../assets/js/ltms-product-enhancements.min.js';

	/**
	 * Extrae el cuerpo de un método de una clase PHP (aproximación strpos,
	 * patrón WishlistPvToggleTest::extract_method).
	 */
	private function extract_method( string $src, string $method_name ): string {
		$needle = "private static function {$method_name}( ";
		$pos    = strpos( $src, $needle );
		if ( false === $pos ) {
			return '';
		}
		return substr( $src, $pos, 3000 );
	}

	// =====================================================================
	// E2E-BUYER-HPOS-DETECT
	// =====================================================================

	public function test_find_order_by_txn_detecta_modo_hpos(): void {
		$src  = (string) file_get_contents( self::WEBHOOK_PATH );
		$txn  = $this->extract_method( $src, 'find_order_by_txn' );

		$this->assertStringContainsString( 'E2E-BUYER-HPOS-DETECT', $txn, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString(
			'\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()',
			$txn,
			'Debe detectar el modo de almacenamiento antes de consultar wc_orders_meta.'
		);
		$this->assertStringContainsString( 'if ( $hpos_enabled )', $txn, 'La query HPOS debe estar gated por la detección.' );
		// El fallback postmeta permanece SIEMPRE (legacy activo y HPOS-compat).
		$this->assertStringContainsString( '{$wpdb->postmeta}', $txn, 'El fallback postmeta debe permanecer incondicional.' );
	}

	// =====================================================================
	// E2E-BUYER-PAY-RADIO-CHECKED
	// =====================================================================

	public function test_checkout_no_doble_radio_pago(): void {
		$src = (string) file_get_contents( self::CHECKOUT_TPL );

		$this->assertStringContainsString( 'E2E-BUYER-PAY-RADIO-CHECKED', $src, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString( '$pv_any_gateway_chosen', $src, 'Debe existir la detección de gateway elegido en sesión.' );
		$this->assertStringNotContainsString(
			'( $gateway->chosen ) || ( $pay_idx === 0 )',
			$src,
			'El patrón viejo pre-chequeaba el primer gateway ADEMÁS del elegido (2 radios checked).'
		);
		$this->assertStringContainsString(
			'! empty( $gateway->chosen ) || ( ! $pv_any_gateway_chosen && $pay_idx === 0 )',
			$src,
			'El elegido en sesión gana; solo si ninguno está elegido se pre-chequea el primero.'
		);
	}

	// =====================================================================
	// E2E-BUYER-MAPS-REAL-IDS
	// =====================================================================

	public function test_payment_meta_keys_reales(): void {
		$src = (string) file_get_contents( self::CHECKOUT_TPL );

		$this->assertStringContainsString( 'E2E-BUYER-MAPS-REAL-IDS', $src, 'El fix debe estar marcado con su ID de tarea.' );
		// Gateways reales (class-ltms-api-gateways.php $this->id).
		$this->assertStringContainsString( "'ltms_openpay'    => array(", $src, 'payment_meta debe incluir el gateway real ltms_openpay.' );
		$this->assertStringContainsString( "'ltms_openpay_mx' => array(", $src, 'payment_meta debe incluir el gateway real ltms_openpay_mx.' );
		$this->assertStringContainsString( "'ltms_pse'        => array(", $src, 'payment_meta debe incluir el gateway real ltms_pse.' );
		// Keys fantasma eliminadas (nunca matcheaban $gateway_id).
		$this->assertStringNotContainsString( "'woo_nequi'", $src, 'La key fantasma woo_nequi no debe persistir.' );
		$this->assertStringNotContainsString( "'woo_daviplata'", $src, 'La key fantasma woo_daviplata no debe persistir.' );
	}

	public function test_carrier_meta_keys_reales(): void {
		$src = (string) file_get_contents( self::CHECKOUT_TPL );

		$this->assertStringContainsString( "'ltms_deprisa'", $src, 'carrier_meta debe incluir el method_id real ltms_deprisa.' );
		$this->assertStringContainsString( "'ltms_aveonline'", $src, 'carrier_meta debe incluir el method_id real ltms_aveonline.' );
		$this->assertStringContainsString( "'ltms_heka'", $src, 'carrier_meta debe incluir el method_id real ltms_heka.' );
		$this->assertStringContainsString( "'ltms_pickup'", $src, 'carrier_meta debe incluir el method_id real ltms_pickup.' );
		$this->assertStringContainsString( "'ltms_free_absorbed'", $src, 'carrier_meta debe incluir el method_id real ltms_free_absorbed.' );
		// Keys fantasma eliminadas (no coincidían con $rate->get_method_id()).
		$this->assertStringNotContainsString( "'deprisa'    => array(", $src, 'La key fantasma deprisa no debe persistir.' );
		$this->assertStringNotContainsString( "'aveonline'  => array(", $src, 'La key fantasma aveonline no debe persistir.' );
	}

	// =====================================================================
	// E2E-BUYER-THRESHOLD-SYNC
	// =====================================================================

	public function test_cart_umbral_misma_fuente_que_mini_cart(): void {
		$src = (string) file_get_contents( self::CART_TPL );

		$this->assertStringContainsString( 'E2E-BUYER-THRESHOLD-SYNC', $src, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString(
			"LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 )",
			$src,
			'El cart debe leer la fuente real del umbral (misma que mini-cart y product-tabs).'
		);
		$this->assertStringNotContainsString(
			'woocommerce_free_shipping_settings',
			$src,
			'Setting que la tienda no usa (el envío real lo calcula LTMS_Shipping_Mode) — no debe ser la fuente del display.'
		);
		// La válvula de escape (filtro) debe permanecer.
		$this->assertStringContainsString( 'ltms_cart_free_shipping_threshold', $src, 'El filtro ltms_cart_free_shipping_threshold debe permanecer.' );
	}

	// =====================================================================
	// E2E-BUYER-LEGACY-DRAWER-DEAD
	// =====================================================================

	public function test_drawer_legacy_js_eliminado(): void {
		$src = (string) file_get_contents( self::PE_JS_PATH );
		$min = (string) file_get_contents( self::PE_JS_MIN_PATH );

		$this->assertStringContainsString( 'E2E-BUYER-LEGACY-DRAWER-DEAD', $src, 'El JS debe documentar la eliminación.' );
		$this->assertStringNotContainsString( 'LTMS_Drawer', $src, 'El objeto JS del drawer legacy debe estar eliminado.' );
		$this->assertStringNotContainsString( 'ltms_drawer_timer', $src, 'El countdown del drawer legacy no debe persistir.' );
		$this->assertStringNotContainsString( 'initTncCheckbox', $src, 'El T&C del drawer legacy (markup inexistente) no debe persistir.' );
		// El .min (el que sirve SG) debe reflejar la eliminación.
		$this->assertStringNotContainsString( 'initTncCheckbox', $min, 'El .min debe estar sincronizado (ci-lint Min files sync).' );
		// El sticky cart y la wishlist SÍ siguen vivos en ese archivo.
		$this->assertStringContainsString( 'LTMS_StickyCart', $src, 'El sticky cart (vivo) debe permanecer en el archivo.' );
		$this->assertStringContainsString( 'ltms_toggle_wishlist', $src, 'La wishlist (viva) debe permanecer en el archivo.' );
	}
}
