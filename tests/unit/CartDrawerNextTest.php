<?php
/**
 * CartDrawerNextTest - CART-UX-NEXT (2026-09-10, v2.9.357).
 *
 * Reimplementación ROBUSTA del mini-cart lateral que v2.9.208 había desactivado
 * (fallos repetidos en SiteGround: inline-script stripped, fetch() soltado por
 * mod_security, jQuery legacy). El enfoque nuevo:
 *  - HTML estático en wp_footer (render_drawer_html, SIN <script> inline).
 *  - JS external versionado (ltms-cart-drawer.js + .min) con config por
 *    wp_localize_script.
 *  - AJAX vía XHR sobre ltms_get_cart / ltms_drawer_update_qty /
 *    ltms_drawer_remove_item (nonce ltms_ux_nonce).
 *  - Namespace .ltms-minicart-* (NO .ltms-cart-drawer-*) para no colisionar con
 *    el drawer legacy de ltms-ux-enhancements.js.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CartDrawerNextTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-minicart
 *
 * @group audit-minicart
 */
final class CartDrawerNextTest extends LTMS_Unit_Test_Case {

	private const PHP_PATH = __DIR__ . '/../../includes/frontend/class-ltms-cart-drawer.php';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-cart-drawer.css';
	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-cart-drawer.js';
	private const JS_MIN   = __DIR__ . '/../../assets/js/ltms-cart-drawer.min.js';

	public function test_init_hooks_render_and_enqueue(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString(
			"add_action( 'wp_footer', [ __CLASS__, 'render_drawer_html' ], 30 )",
			$src,
			'CART-UX-NEXT: init() debe renderizar el skeleton del drawer en wp_footer.'
		);
		$this->assertStringContainsString(
			"add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] )",
			$src,
			'CART-UX-NEXT: init() debe encolar los assets del drawer.'
		);
		$this->assertStringContainsString(
			"wp_localize_script( 'ltms-cart-drawer', 'ltmsCartDrawer'",
			$src,
			'CART-UX-NEXT: la config debe viajar por wp_localize_script (no script inline).'
		);
	}

	public function test_render_uses_minicart_namespace_no_legacy_class(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString(
			'class="ltms-minicart',
			$src,
			'CART-UX-NEXT: el markup del drawer debe usar el namespace .ltms-minicart-*.'
		);
		$this->assertStringNotContainsString(
			'class="ltms-cart-drawer',
			$src,
			'CART-UX-NEXT: el markup del drawer NO debe reusar el namespace legacy .ltms-cart-drawer-.'
		);
		$this->assertStringContainsString(
			'CART-UX-NEXT',
			$src,
			'CART-UX-NEXT: la traza del fix debe estar presente en class-ltms-cart-drawer.php.'
		);
	}

	public function test_css_self_contained(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString( '.ltms-minicart-overlay', $css, 'CART-UX-NEXT: overlay CSS presente.' );
		$this->assertStringContainsString( '.ltms-minicart__checkout', $css, 'CART-UX-NEXT: CTA checkout CSS presente.' );
		$this->assertStringContainsString( '#E80001', $css, 'CART-UX-NEXT: el CTA checkout debe ser brand red.' );
		$this->assertStringContainsString( 'body.ltms-minicart-locked{overflow:hidden;}', $css, 'CART-UX-NEXT: lock de scroll al abrir.' );
		$this->assertStringNotContainsString( '.ltms-cart-drawer{', $css, 'CART-UX-NEXT: el CSS no debe definir el selector .ltms-cart-drawer (collision legacy).' );
	}

	public function test_js_triggers_and_endpoints(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString( 'added_to_cart', $js, 'CART-UX-NEXT: el JS debe abrir en el evento added_to_cart de WC.' );
		$this->assertStringContainsString( 'data-pv-add-to-cart', $js, 'CART-UX-NEXT: el JS debe abrir en el click de data-pv-add-to-cart (design system).' );
		$this->assertStringContainsString( 'ltms_get_cart', $js, 'CART-UX-NEXT: el JS debe hidratar el drawer con ltms_get_cart.' );
		$this->assertStringContainsString( 'ltms_drawer_update_qty', $js, 'CART-UX-NEXT: el JS debe usar ltms_drawer_update_qty para cantidad.' );
		$this->assertStringContainsString( 'ltms_drawer_remove_item', $js, 'CART-UX-NEXT: el JS debe usar ltms_drawer_remove_item para eliminar.' );
		$this->assertStringContainsString( 'XMLHttpRequest', $js, 'CART-UX-NEXT: el JS debe usar XHR (no fetch) para resistir el WAF/mod_security.' );
	}

	public function test_js_min_regenerated(): void {
		$min = file_get_contents( self::JS_MIN );

		$this->assertStringContainsString( 'ltms_get_cart', $min, 'CART-UX-NEXT: el .min.js debe estar regenerado (contiene ltms_get_cart).' );
		$this->assertStringContainsString( 'added_to_cart', $min, 'CART-UX-NEXT: el .min.js debe contener el trigger added_to_cart.' );
	}
}