<?php
/**
 * StorefrontTopbarTest - HEADER-UX (2026-09-11).
 *
 * Mejora del topbar de la vitrina del vendedor (class-ltms-vendor-storefront.php
 * + assets/css/ltms-storefront.css):
 *  - El carrito pasa de emoji 🛒 a SVG (consistente con el mini-cart .ltms-minicart).
 *  - Topbar reorganizado en 3 zonas: start (volver + logo) / actions (wishlist + carrito).
 *  - Botón "Volver a la tienda" con chevron SVG + label; colapsa a chevron solo en
 *    móvil/compact (antes desaparecía por completo en móvil).
 *  - Touch targets 40×40, hover + focus-visible, badge de carrito absoluto.
 *  - Altura controlada por .ltms-sf-topbar-inner (el .is-compact encoge el inner).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class StorefrontTopbarTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-topbar
 *
 * @group audit-topbar
 */
final class StorefrontTopbarTest extends LTMS_Unit_Test_Case {

	private const PHP_PATH = __DIR__ . '/../../includes/frontend/class-ltms-vendor-storefront.php';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-storefront.css';
	private const CSS_MIN  = __DIR__ . '/../../assets/css/ltms-storefront.min.css';

	public function test_cart_uses_svg_not_emoji(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringNotContainsString( '🛒', $src, 'HEADER-UX: el topbar NO debe usar el emoji de carrito.' );
		$this->assertStringContainsString( 'class="ltms-sf-topbar-cart"', $src, 'HEADER-UX: debe existir el botón de carrito.' );
		$this->assertStringContainsString( 'data-ltms-open-cart', $src, 'HEADER-UX: el carrito debe mantener data-ltms-open-cart (mini-cart).' );
		$this->assertStringContainsString( '<svg', $src, 'HEADER-UX: el carrito debe usar un SVG.' );
	}

	public function test_topbar_restructured_start_actions_back(): void {
		$src = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString( 'class="ltms-sf-topbar-start"', $src, 'HEADER-UX: debe existir la zona start (volver + logo).' );
		$this->assertStringContainsString( 'class="ltms-sf-topbar-actions"', $src, 'HEADER-UX: debe existir la zona actions (wishlist + carrito).' );
		$this->assertStringContainsString( 'ltms-sf-topbar-back-icon', $src, 'HEADER-UX: el botón volver debe tener chevron SVG.' );
		$this->assertStringContainsString( 'ltms-sf-topbar-back-label', $src, 'HEADER-UX: el botón volver debe tener label (se oculta en móvil).' );
		$this->assertStringContainsString( 'HEADER-UX', $src, 'HEADER-UX: la traza del fix debe estar en el markup.' );
	}

	public function test_css_has_zones_touch_targets_and_focus(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString( '.ltms-sf-topbar-start', $css, 'HEADER-UX: el CSS debe estilar la zona start.' );
		$this->assertStringContainsString( '.ltms-sf-topbar-actions', $css, 'HEADER-UX: el CSS debe estilar la zona actions.' );
		$this->assertStringContainsString( 'width: 40px', $css, 'HEADER-UX: wishlist/carrito deben tener touch target de 40px.' );
		$this->assertStringContainsString( 'focus-visible', $css, 'HEADER-UX: debe existir estilo focus-visible en los controles del topbar.' );
		$this->assertStringContainsString( 'backdrop-filter', $css, 'HEADER-UX: el topbar debe tener backdrop-filter (glass).' );
	}

	public function test_min_css_regenerated_with_zones(): void {
		$min = file_get_contents( self::CSS_MIN );

		$this->assertStringContainsString( '.ltms-sf-topbar-start', $min, 'HEADER-UX: el .min.css debe estar regenerado con la zona start.' );
		$this->assertStringContainsString( '.ltms-sf-topbar-actions', $min, 'HEADER-UX: el .min.css debe estar regenerado con la zona actions.' );
	}
}