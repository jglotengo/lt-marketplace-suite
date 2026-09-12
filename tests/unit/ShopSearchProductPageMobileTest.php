<?php
/**
 * ShopSearchProductPageMobileTest - SHOP-SEARCH-MOBILE + PDP-MOBILE-SPACING (2026-09-12).
 *
 * Dos fixes de móvil del frontend cliente:
 *
 *  1. SHOP-SEARCH-MOBILE — el widget "Buscar" de WooCommerce
 *     (.woocommerce-product-search) en la columna de filtros de /tienda/ solapaba
 *     el botón (lupa) con el input en móvil. Se fuerza layout flex con gap y el
 *     input crece. También cubre el bloque .wp-block-search de WordPress.
 *
 *  2. PDP-MOBILE-SPACING — en la página de producto los controles de compra del
 *     form.cart (cantidad, botón "Añadir al carrito" y wishlist) se amontonaban
 *     uno sobre otro sin espacio en móvil. Se separan con margen inferior, la
 *     wishlist pasa a fila propia a ancho completo, manteniendo jerarquía.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class ShopSearchProductPageMobileTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-mobile-frontend
 *
 * @group audit-mobile-frontend
 */
final class ShopSearchProductPageMobileTest extends LTMS_Unit_Test_Case {

	private const PV_CSS_PATH    = __DIR__ . '/../../assets/css/ltms-plaza-viva.css';
	private const PV_MIN_PATH    = __DIR__ . '/../../assets/css/ltms-plaza-viva.min.css';
	private const SP_PHP_PATH    = __DIR__ . '/../../includes/frontend/templates/single-product.php';

	/* ── SHOP-SEARCH-MOBILE ───────────────────────────────────────── */

	public function test_shop_search_widget_has_flex_layout(): void {
		$css = file_get_contents( self::PV_CSS_PATH );

		$this->assertStringContainsString(
			'SHOP-SEARCH-MOBILE FIX',
			$css,
			'SHOP-SEARCH-MOBILE: el fix debe tener su marcador traceable en ltms-plaza-viva.css.'
		);
		$this->assertStringContainsString(
			'.pv-scope .woocommerce-product-search',
			$css,
			'SHOP-SEARCH-MOBILE: el widget de búsqueda WC debe estilarse para evitar solapamiento.'
		);
		$this->assertStringContainsString(
			'display:flex',
			$css,
			'SHOP-SEARCH-MOBILE: el buscador debe usar flex para que input y lupa no se monten.'
		);
		$this->assertStringContainsString(
			'flex:1 1 auto;min-width:0',
			$css,
			'SHOP-SEARCH-MOBILE: el input de búsqueda debe crecer y no fijar ancho que cause desborde.'
		);
	}

	public function test_min_css_regenerated_with_search_widget(): void {
		$min = file_get_contents( self::PV_MIN_PATH );

		$this->assertStringContainsString(
			'.woocommerce-product-search',
			$min,
			'SHOP-SEARCH-MOBILE: el .min.css debe regenerarse con el selector del buscador.'
		);
	}

	/* ── PDP-MOBILE-SPACING ───────────────────────────────────────── */

	public function test_pdp_mobile_spacing_fix_present(): void {
		$src = file_get_contents( self::SP_PHP_PATH );

		$this->assertStringContainsString(
			'PDP-MOBILE-SPACING FIX',
			$src,
			'PDP-MOBILE-SPACING: el fix debe tener su marcador traceable en single-product.php.'
		);
		$this->assertStringContainsString(
			'.ltms-wishlist-btn-single',
			$src,
			'PDP-MOBILE-SPACING: el botón de wishlist debe estilarse en móvil.'
		);
		$this->assertStringContainsString(
			'margin-bottom:12px',
			$src,
			'PDP-MOBILE-SPACING: los controles de compra deben separarse con margen en móvil.'
		);
		$this->assertStringContainsString(
			'width:100%',
			$src,
			'PDP-MOBILE-SPACING: la wishlist debe pasar a fila propia a ancho completo.'
		);
	}
}