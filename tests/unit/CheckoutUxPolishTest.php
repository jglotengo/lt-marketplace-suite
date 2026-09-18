<?php
/**
 * CheckoutUxPolishTest - CHECKOUT-UX-FIXES (2026-09-17).
 *
 * Ronda de pulido UX del checkout reportada por el usuario tras el fix
 * CHECKOUT-HANG-HEADINGS (2.9.382):
 *
 * 1. CHECKOUT-SUBMIT-VISIBLE: el botón "Confirmar pedido" solo se veía al
 *    hacer hover sobre el texto. Causa: el CSS `!important` del botón
 *    (background #E80001) vivía en el script inline inyectado por el output
 *    buffer (LTMS_Frontend_Checkout_Script_Injector::inject_script_into_html),
 *    que es código muerto (no está enganchado a ningún hook). Sin `!important`,
 *    la regla del combined CSS de SG/Elementor (especificidad 0,2,0) ganaba
 *    sobre `.pv-btn--brand` (0,1,0) fuera de hover. El fix traslada esas reglas
 *    al JS externo `ltms-checkout-fixes.js` que sí se ejecuta en el checkout.
 *
 * 2. SHIPPING-HIDE-UNAVAILABLE: el bloque "Comparar opciones de envío"
 *    (ltms-shipping-selector.js) renderizaba tarjetas fijas (Uber, Aveonline,
 *    Heka, Recogida) mostrando "No disponible" con opacidad 0.5 para carriers
 *    que no cotizaron al destino. El fix las oculta por completo (y oculta el
 *    bloque si ninguna quedó visible).
 *
 * 3. REVIEW-TABLE-UI: jerarquía visual mejorada de la tabla "Tu pedido"
 *    (ltms-checkout.css): thead uppercase, quantity como badge, total en card.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true. No dependen de WooCommerce cargado.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CheckoutUxPolishTest
 *
 * @group checkout
 */
final class CheckoutUxPolishTest extends LTMS_Unit_Test_Case {

	private const CHECKOUT_FIXES_JS = __DIR__ . '/../../assets/js/ltms-checkout-fixes.js';
	private const CHECKOUT_FIXES_MIN = __DIR__ . '/../../assets/js/ltms-checkout-fixes.min.js';
	private const SHIPPING_SELECTOR_JS = __DIR__ . '/../../assets/js/ltms-shipping-selector.js';
	private const SHIPPING_SELECTOR_MIN = __DIR__ . '/../../assets/js/ltms-shipping-selector.min.js';
	private const CHECKOUT_CSS = __DIR__ . '/../../assets/css/ltms-checkout.css';

	/* ---------------------------------------------------------------------
	 * 1. Botón "Confirmar pedido" visible (CHECKOUT-SUBMIT-VISIBLE)
	 * ------------------------------------------------------------------- */

	public function test_checkout_fixes_js_injects_submit_brand_css(): void {
		$src = (string) file_get_contents( self::CHECKOUT_FIXES_JS );
		$this->assertStringContainsString(
			'.pv-btn--brand{background:#E80001 !important;color:#fff !important',
			$src,
			'ltms-checkout-fixes.js DEBE inyectar el fondo rojo !important del botón Confirmar pedido (antes vivía en un script inline muerto).'
		);
	}

	public function test_checkout_fixes_js_injects_submit_height_css(): void {
		$src = (string) file_get_contents( self::CHECKOUT_FIXES_JS );
		$this->assertStringContainsString(
			'.pv-checkout__submit{height:60px !important;font-size:17px !important;',
			$src,
			'el JS externo DEBE incluir el height del botón que antes vivía en el inline muerto.'
		);
	}

	public function test_checkout_fixes_min_contains_submit_css(): void {
		if ( ! file_exists( self::CHECKOUT_FIXES_MIN ) ) {
			$this->markTestSkipped( 'ltms-checkout-fixes.min.js no existe' );
		}
		$min = (string) file_get_contents( self::CHECKOUT_FIXES_MIN );
		$this->assertStringContainsString(
			'#E80001',
			$min,
			'el .min desplegado DEBE incluir el color del botón Confirmar pedido.'
		);
	}

	/* ---------------------------------------------------------------------
	 * 2. Ocultar carriers sin cobertura (SHIPPING-HIDE-UNAVAILABLE)
	 * ------------------------------------------------------------------- */

	public function test_shipping_selector_hides_unavailable_cards(): void {
		$src = (string) file_get_contents( self::SHIPPING_SELECTOR_JS );
		$this->assertStringNotContainsString(
			'No disponible',
			$src,
			'shipping-selector NO debe mostrar "No disponible" — los carriers sin cotización se ocultan.'
		);
		$this->assertStringContainsString(
			'card.hide()',
			$src,
			'shipping-selector DEBE ocultar (hide) la tarjeta cuando el carrier no cotizó.'
		);
	}

	public function test_shipping_selector_hides_whole_block_when_none_visible(): void {
		$src = (string) file_get_contents( self::SHIPPING_SELECTOR_JS );
		$this->assertStringContainsString(
			'container.parent().hide()',
			$src,
			'si ningún carrier cotizó, el bloque "Comparar opciones de envío" DEBE ocultarse entero.'
		);
		$this->assertStringContainsString(
			'container.parent().show()',
			$src,
			'si al menos un carrier cotizó, el bloque DEBE volver a mostrarse.'
		);
	}

	public function test_shipping_selector_min_contains_hide_logic(): void {
		if ( ! file_exists( self::SHIPPING_SELECTOR_MIN ) ) {
			$this->markTestSkipped( 'ltms-shipping-selector.min.js no existe' );
		}
		$min = (string) file_get_contents( self::SHIPPING_SELECTOR_MIN );
		$this->assertStringContainsString(
			'.hide()',
			$min,
			'el .min desplegado DEBE incluir la lógica de ocultar tarjetas sin cotización.'
		);
		$this->assertStringNotContainsString(
			'No disponible',
			$min,
			'el .min desplegado NO debe contener "No disponible".'
		);
	}

	/* ---------------------------------------------------------------------
	 * 3. Tabla "Tu pedido" mejorada (REVIEW-TABLE-UI)
	 * ------------------------------------------------------------------- */

	public function test_review_table_css_improves_quantity_badge(): void {
		$css = (string) file_get_contents( self::CHECKOUT_CSS );
		$this->assertStringContainsString(
			'.product-quantity{',
			$css,
			'el CSS del review DEBE estilizar .product-quantity como badge.'
		);
		$this->assertStringContainsString(
			'border-radius:999px',
			$css,
			'el badge de cantidad DEBE ser pill-shaped (border-radius:999px).'
		);
	}

	public function test_review_table_css_styles_thead(): void {
		$css = (string) file_get_contents( self::CHECKOUT_CSS );
		$this->assertStringContainsString(
			'table.woocommerce-checkout-review-order-table thead th',
			$css,
			'el CSS del review DEBE estilizar el thead (jerarquía visual de la tabla).'
		);
		$this->assertStringContainsString(
			'text-transform:uppercase',
			$css,
			'el thead DEBE ser uppercase.'
		);
	}
}