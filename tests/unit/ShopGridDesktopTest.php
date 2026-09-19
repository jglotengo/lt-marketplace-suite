<?php
/**
 * ShopGridDesktopTest - SHOP-GRID-DESKTOP (2026-09-18).
 *
 * El shop (/tienda/) forzaba grid de 5 columnas con `!important` (paridad con
 * el home). En desktop con sidebar (`.pv-shop__sidebar` + `.pv-shop__main`)
 * cada card quedaba ~180px y el contenido (título 2 líneas + precio + botón
 * "Añadir al carrito") se desbordaba — reportado por el usuario en
 * /tienda/?view_list como "imágenes y texto de las tarjetas desbordados".
 *
 * Fix (assets/css/ltms-homepage-fixes.css):
 *  - `repeat(4, 1fr)` en `<=1600px` (cards más anchas en desktop típico).
 *  - `repeat(5, 1fr)` solo en pantallas muy anchas (>1600px).
 *  - `min-width:0` + `max-width:100%` en `li.product` y en el link de la card,
 *    más `overflow-wrap:break-word` — el grid define el ancho, nunca el texto.
 *
 * El home (carrusel / `.elementor-wc-products`) NO se toca: el override es
 * exclusivo del scope `.pv-scope.pv-shop`.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class ShopGridDesktopTest extends LTMS_Unit_Test_Case {

	private const CSS_PATH     = __DIR__ . '/../../assets/css/ltms-homepage-fixes.css';
	private const CSS_MIN_PATH = __DIR__ . '/../../assets/css/ltms-homepage-fixes.min.css';

	public function test_desktop_uses_four_columns_below_1600(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// El media query <=1600px debe forzar 4 columnas (cards más anchas).
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 1600px\)\s*\{\s*\.pv-scope\.pv-shop ul\.products\s*\{\s*grid-template-columns: repeat\(4, 1fr\) !important;/',
			$css,
			'SHOP-GRID-DESKTOP: en desktop <=1600px el shop DEBE usar 4 columnas (las cards de 5 se desbordaban).'
		);
	}

	public function test_very_wide_screens_keep_five_columns(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// El grid base (sin media query) debe seguir siendo 5 columnas para
		// pantallas muy anchas (>1600px) — paridad con el home se conserva.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop ul\.products\s*\{\s*display: grid !important;\s*grid-template-columns: repeat\(5, 1fr\) !important;/',
			$css,
			'SHOP-GRID-DESKTOP: el grid base del shop debe seguir siendo 5 columnas (>1600px).'
		);
	}

	public function test_card_link_cannot_force_grid_width(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop ul\.products li\.product a\.woocommerce-loop-product__link\s*\{\s*min-width: 0 !important;\s*max-width: 100% !important;/',
			$css,
			'SHOP-GRID-DESKTOP: el link de la card debe tener min-width:0 + max-width:100% para no empujar el grid.'
		);
		$this->assertStringContainsString(
			'overflow-wrap: break-word !important;',
			$css,
			'SHOP-GRID-DESKTOP: el link debe romper palabras largas para no desbordar.'
		);
	}

	public function test_min_css_contains_four_columns_and_guard(): void {
		if ( ! file_exists( self::CSS_MIN_PATH ) ) {
			$this->markTestSkipped( 'ltms-homepage-fixes.min.css no existe' );
		}
		$min = (string) file_get_contents( self::CSS_MIN_PATH );

		// clean-css compacta espacios: repeat(4,1fr) y media query 1600px.
		$this->assertStringContainsString(
			'repeat(4,1fr)',
			$min,
			'el .min desplegado DEBE incluir el grid de 4 columnas desktop.'
		);
		$this->assertStringContainsString(
			'max-width:1600px',
			$min,
			'el .min desplegado DEBE incluir el breakpoint 1600px.'
		);
	}
}