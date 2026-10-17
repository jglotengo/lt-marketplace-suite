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

	/**
	 * CART-UX-013 (2026-10-17, operador): desde la ficha de producto,
	 * "Añadir al carrito" redirige directo al carrito y el aviso
	 * "producto añadido — Ver carrito" se suprime. Scope: SOLO el submit
	 * clásico del form (POST add-to-cart) — los adds de cards conservan
	 * el flujo del drawer.
	 */
	public function test_cart_ux_013_single_product_redirect_and_message(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString(
			"add_filter( 'woocommerce_add_to_cart_redirect', [ __CLASS__, 'single_product_add_to_cart_redirect' ] )",
			$src,
			'CART-UX-013: init() engancha el redirect al carrito tras el add de la ficha ("no te lleva a la página donde se selecciona el pedido" — operador).'
		);
		$this->assertStringContainsString(
			"add_filter( 'wc_add_to_cart_message_html', [ __CLASS__, 'single_product_add_to_cart_message' ], 10, 2 )",
			$src,
			'CART-UX-013: init() engancha la supresión del aviso "producto añadido — Ver carrito" ("oculto eso" — operador).'
		);
		$this->assertStringContainsString(
			"isset( \$_POST['add-to-cart'] )",
			$src,
			'CART-UX-013: el scope es el submit clásico de la ficha (POST add-to-cart) — los adds de cards (GET/AJAX) conservan el drawer.'
		);
		$this->assertStringContainsString(
			'wc_get_cart_url()',
			$src,
			'CART-UX-013: el redirect usa la URL canónica del carrito (wc_get_cart_url, respeta la página del plugin).'
		);
		$this->assertStringContainsString(
			'CART-UX-013',
			$src,
			'CART-UX-013: la traza del fix debe estar presente en class-ltms-cart-drawer.php.'
		);
	}

	/**
	 * CART-UX-013b: la barra sticky ATC de la ficha de producto redirige
	 * directo al carrito (misma conducta que el formulario clásico, CART-UX-013)
	 * — un solo comportamiento mental en la misma página. El resto de adds
	 * (cards de home/vitrina) conserva el flujo fly + toast.
	 */
	public function test_cart_ux_013b_sticky_atc_redirects_to_cart(): void {
		$js = file_get_contents( __DIR__ . '/../../assets/js/ltms-plaza-viva.js' );

		$this->assertStringContainsString(
			"atc.closest('[data-pv-sticky-atc]')",
			$js,
			'CART-UX-013b: el handler delegado detecta la barra sticky ATC de la ficha ([data-pv-sticky-atc]).'
		);
		$this->assertStringContainsString(
			'window.ltms_data.cart_url',
			$js,
			'CART-UX-013b: el redirect usa ltms_data.cart_url (la URL canónica del carrito expuesta por wp_localize_script).'
		);
		// El fly + toast siguen vivos para el resto de adds (cards).
		$this->assertStringContainsString(
			'PV.flyToCart(atc',
			$js,
			'CART-UX-013b: el flujo fly + toast se conserva para los adds que NO son de la sticky bar.'
		);
		// El .min.js sincronizado (SG sirve el min).
		$min = file_get_contents( __DIR__ . '/../../assets/js/ltms-plaza-viva.min.js' );
		$this->assertStringContainsString(
			'data-pv-sticky-atc',
			$min,
			'CART-UX-013b: el .min.js debe contener el selector de la sticky bar (regenerado, no desincronizado).'
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
		$this->assertStringContainsString( 'min-height:0', $css, 'CART-UX-NEXT: el body del drawer debe tener min-height:0 para poder scrollear (fix flexbox).' );
		$this->assertStringNotContainsString( '.ltms-cart-drawer{', $css, 'CART-UX-NEXT: el CSS no debe definir el selector .ltms-cart-drawer (collision legacy).' );
	}

	public function test_js_triggers_and_endpoints(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString( 'added_to_cart', $js, 'CART-UX-NEXT: el JS debe abrir en el evento added_to_cart de WC.' );
		$this->assertStringContainsString( 'data-pv-add-to-cart', $js, 'CART-UX-NEXT: el JS debe abrir en el click de data-pv-add-to-cart (design system).' );
		$this->assertStringContainsString( '.elementor-menu-cart__toggle', $js, 'CART-UX-NEXT: el JS debe abrir el drawer desde el icono del carrito (Elementor menu-cart).' );
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

	public function test_js_syncs_header_cart_count_on_remove(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			'wc_fragment_refresh',
			$js,
			'CART-COUNT-SYNC: el JS debe disparar wc_fragment_refresh tras qty/remove para actualizar el contador del carrito del header.'
		);

		$min = file_get_contents( self::JS_MIN );
		$this->assertStringContainsString(
			'wc_fragment_refresh',
			$min,
			'CART-COUNT-SYNC: el .min.js debe estar regenerado con el fragment refresh.'
		);
	}
}