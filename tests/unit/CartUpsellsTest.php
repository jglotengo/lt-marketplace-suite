<?php
/**
 * CartUpsellsTest - CART-UX-NEXT-UPSells (2026-09-11).
 *
 * El mini-cart nuevo (ltms-cart-drawer.js, namespace .ltms-minicart-*) abría y
 * scrolleaba, pero NO mostraba upsells: get_upsell_products() existía en
 * LTMS_Cart_Drawer pero solo lo servía el endpoint legacy ltms_refresh_drawer;
 * el drawer nuevo hidrata vía ltms_get_cart (ajax_get_cart) que no devolvía
 * `upsells`. Este fix:
 *  - Añade LTMS_Cart_Drawer::get_upsells_for_cart() (wrapper público que reusa
 *    get_upsell_products() privado, sin duplicar la WP_Query).
 *  - ajax_get_cart sirve `upsells` cuando le llega full=1 (solo al abrir; los
 *    refrescos de qty/remove no pagan la query).
 *  - El JS renderiza la tira de upsells en el <section> nuevo del drawer.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CartUpsellsTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-cart-upsells
 *
 * @group audit-cart-upsells
 */
final class CartUpsellsTest extends LTMS_Unit_Test_Case {

	private const DRAWER_PHP = __DIR__ . '/../../includes/frontend/class-ltms-cart-drawer.php';
	private const CHKO_PHP   = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const CSS_PATH   = __DIR__ . '/../../assets/css/ltms-cart-drawer.css';
	private const JS_PATH    = __DIR__ . '/../../assets/js/ltms-cart-drawer.js';
	private const JS_MIN     = __DIR__ . '/../../assets/js/ltms-cart-drawer.min.js';

	public function test_drawer_exposes_upsells_wrapper(): void {
		$src = file_get_contents( self::DRAWER_PHP );

		$this->assertStringContainsString(
			'public static function get_upsells_for_cart( \WC_Cart $cart ): array',
			$src,
			'CART-UX-NEXT-UPSells: debe existir el wrapper público get_upsells_for_cart().'
		);
		$this->assertStringContainsString(
			'get_upsell_products( array_keys( $vendor_ids ), $cart )',
			$src,
			'CART-UX-NEXT-UPSells: el wrapper debe reusar get_upsell_products() (sin duplicar la query).'
		);
		$this->assertStringContainsString(
			'wp_strip_all_tags',
			$src,
			'CART-UX-NEXT-UPSells: el wrapper debe normalizar el precio HTML a texto plano para el drawer nuevo.'
		);
		$this->assertStringContainsString(
			'CART-UX-NEXT-UPSells',
			$src,
			'CART-UX-NEXT-UPSells: la traza del fix debe estar en class-ltms-cart-drawer.php.'
		);
	}

	public function test_drawer_renders_upsells_section(): void {
		$src = file_get_contents( self::DRAWER_PHP );

		$this->assertStringContainsString(
			'class="ltms-minicart__upsells"',
			$src,
			'CART-UX-NEXT-UPSells: el drawer debe renderizar el <section> de upsells.'
		);
		$this->assertStringContainsString(
			'ltms-minicart__upsells-list',
			$src,
			'CART-UX-NEXT-UPSells: debe existir el contenedor de la lista de upsells.'
		);
	}

	public function test_ajax_get_cart_serves_upsells_on_full(): void {
		$src = file_get_contents( self::CHKO_PHP );

		$this->assertStringContainsString(
			"\$full = isset( \$_POST['full'] )",
			$src,
			'CART-UX-NEXT-UPSells: ajax_get_cart debe leer el flag full.'
		);
		$this->assertStringContainsString(
			'LTMS_Cart_Drawer::get_upsells_for_cart( $cart )',
			$src,
			'CART-UX-NEXT-UPSells: ajax_get_cart debe delegar en LTMS_Cart_Drawer.'
		);
		$this->assertStringContainsString(
			"'upsells'         => \$upsells,",
			$src,
			'CART-UX-NEXT-UPSells: la respuesta de ltms_get_cart debe incluir upsells.'
		);
	}

	public function test_js_renders_upsells_and_requests_full_on_open(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			'function renderUpsells(upsells)',
			$js,
			'CART-UX-NEXT-UPSells: el JS debe contener renderUpsells().'
		);
		$this->assertStringContainsString(
			".ltms-minicart__upsells",
			$js,
			'CART-UX-NEXT-UPSells: el JS debe apuntar al contenedor .ltms-minicart__upsells.'
		);
		$this->assertStringContainsString(
			"params.full = '1'",
			$js,
			'CART-UX-NEXT-UPSells: el JS debe pedir full=1 al abrir para recibir upsells.'
		);
	}

	public function test_css_has_upsells_styles(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString( 'CART-UX-NEXT-UPSells', $css, 'CART-UX-NEXT-UPSells: el CSS debe tener el marcador traceable.' );
		$this->assertStringContainsString( '.ltms-minicart__upsells', $css, 'CART-UX-NEXT-UPSells: debe existir el estilo del contenedor de upsells.' );
		$this->assertStringContainsString( '.ltms-minicart__upsells-list', $css, 'CART-UX-NEXT-UPSells: debe existir el estilo de la lista de upsells.' );
		$this->assertStringContainsString( '.ltms-minicart__upsell-img', $css, 'CART-UX-NEXT-UPSells: debe existir el estilo de la imagen del upsell.' );
	}

	public function test_js_min_regenerated_with_upsells(): void {
		$min = file_get_contents( self::JS_MIN );

		$this->assertStringContainsString( '.ltms-minicart__upsells', $min, 'CART-UX-NEXT-UPSells: el .min.js debe estar regenerado con el render de upsells.' );
		$this->assertStringContainsString( 'ltms_get_cart', $min, 'CART-UX-NEXT-UPSells: el .min.js debe seguir hidratando vía ltms_get_cart.' );
	}
}