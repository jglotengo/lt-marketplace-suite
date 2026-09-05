<?php
/**
 * CartDrawerUxFixTest - CART-DRAWER-OVERLAP + CART-UX-001 (2026-09-04).
 *
 * 1. CART-DRAWER-OVERLAP (P1): el drawer del carrito de Elementor
 *    (.elementor-menu-cart__container, z-index 9998) quedaba DEBAJO del
 *    #ltms-floating-access (z-index 100002) del header: al abrir el carrito,
 *    los botones "Vender gratis" / "Mi Cuenta" se sobreponian al drawer.
 *    Fix: z-index del contenedor y del drawer por encima de 100002.
 *
 * 2. CART-UX-001 (P2): barra de progreso de envío gratis dentro del
 *    mini-cart / side-cart de Elementor (hook woocommerce_before_mini_cart,
 *    reusa LTMS_Cart_Drawer::get_shipping_bar_data()) + CSS del side-cart.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class CartDrawerUxFixTest extends LTMS_Unit_Test_Case {

	private const DRAWER_PATH = __DIR__ . '/../../includes/frontend/class-ltms-cart-drawer.php';
	private const HDR_CSS_PATH = __DIR__ . '/../../assets/css/ltms-header-nav.css';

	// ====================================================================
	//  CART-DRAWER-OVERLAP (P1): el carrito modal debe quedar sobre el header
	// ====================================================================

	public function test_cart_container_zindex_above_floating_access(): void {
		$css = file_get_contents( self::HDR_CSS_PATH );

		$this->assertStringContainsString(
			'.elementor-menu-cart__container',
			$css,
			'CART-DRAWER-OVERLAP: debe existir el override del contenedor del menu-cart.'
		);
		$this->assertStringContainsString(
			'z-index: 100003 !important;',
			$css,
			'CART-DRAWER-OVERLAP: el contenedor del carrito debe quedar por encima del #ltms-floating-access (100002).'
		);
	}

	// ====================================================================
	//  CART-UX-001 (P2): barra de envío gratis en el mini-cart
	// ====================================================================

	public function test_mini_cart_shipping_bar_hook_registered(): void {
		$src = file_get_contents( self::DRAWER_PATH );

		$this->assertStringContainsString(
			"add_action( 'woocommerce_before_mini_cart', [ __CLASS__, 'render_mini_cart_shipping_bar' ], 5 )",
			$src,
			'CART-UX-001: LTMS_Cart_Drawer::init() debe registrar render_mini_cart_shipping_bar en woocommerce_before_mini_cart.'
		);
	}

	public function test_mini_cart_shipping_bar_method_exists(): void {
		$src = file_get_contents( self::DRAWER_PATH );

		$this->assertStringContainsString(
			'public static function render_mini_cart_shipping_bar(): void {',
			$src,
			'CART-UX-001: el método render_mini_cart_shipping_bar() debe existir.'
		);
		// Reusa el umbral del módulo de shipping.
		$this->assertStringContainsString(
			'get_shipping_bar_data(',
			$src,
			'CART-UX-001: render_mini_cart_shipping_bar() debe reutilizar get_shipping_bar_data().'
		);
	}

	public function test_side_cart_css_ux_polish(): void {
		$css = file_get_contents( self::HDR_CSS_PATH );

		$this->assertStringContainsString(
			'.ltms-mini-cart-shipping',
			$css,
			'CART-UX-001: el CSS de la barra de envío gratis debe existir.'
		);
		$this->assertStringContainsString(
			'.elementor-menu-cart__main .elementor-menu-cart__footer-buttons a.checkout',
			$css,
			'CART-UX-001: el botón checkout del side-cart debe tener estilos propios.'
		);
	}
}