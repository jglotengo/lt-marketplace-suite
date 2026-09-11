<?php
/**
 * HomeProductsTwoColTest - HOME-PRODUCTS-2COL (2026-09-11).
 *
 * El loop de productos del home (widget WooCommerce de Elementor,
 * `.elementor-wc-products ul.products`) se mostraba en móvil como un CAROUSEL
 * horizontal scroll-snap (cards de 42vw, una sola fila deslizable). El usuario
 * pidió que en móvil se vean productos en 2 columnas. Fix: se reemplaza el
 * carrusel por un grid `repeat(2, 1fr)` en `ltms-homepage-fixes.css` y se
 * elimina el hint "Desliza para ver más" (injectCarouselHint) del JS.
 *
 * Tests source-based (file_get_contents + asserts).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeProductsTwoColTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-home-2col
 *
 * @group audit-home-2col
 */
final class HomeProductsTwoColTest extends LTMS_Unit_Test_Case {

	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-homepage-fixes.css';
	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-homepage-fixes.js';
	private const JS_MIN   = __DIR__ . '/../../assets/js/ltms-homepage-fixes.min.js';

	public function test_home_mobile_is_two_column_grid(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'HOME-PRODUCTS-2COL FIX',
			$css,
			'HOME-PRODUCTS-2COL: el fix debe tener su marcador traceable.'
		);
		$this->assertStringContainsString(
			'.elementor-wc-products ul.products',
			$css,
			'HOME-PRODUCTS-2COL: debe existir la regla móvil del loop de productos del home.'
		);
		$this->assertStringContainsString(
			'grid-template-columns: repeat(2, 1fr) !important',
			$css,
			'HOME-PRODUCTS-2COL: el home debe ser grid de 2 columnas en móvil.'
		);
		$this->assertStringNotContainsString(
			'scroll-snap-type: x mandatory',
			$css,
			'HOME-PRODUCTS-2COL: el carrusel scroll-snap del home debe estar eliminado.'
		);
		$this->assertStringNotContainsString(
			'flex: 0 0 42vw',
			$css,
			'HOME-PRODUCTS-2COL: el ancho fijo 42vw de las cards de carrusel debe estar eliminado.'
		);
	}

	public function test_carousel_hint_removed_from_js(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringNotContainsString(
			'injectCarouselHint',
			$js,
			'HOME-PRODUCTS-2COL: la función injectCarouselHint (hint "Desliza") debe estar eliminada.'
		);
		$this->assertStringNotContainsString(
			'ltms-carousel-hint',
			$js,
			'HOME-PRODUCTS-2COL: el hint ltms-carousel-hint debe estar eliminado del JS.'
		);
	}

	public function test_js_min_regenerated(): void {
		$min = file_get_contents( self::JS_MIN );

		$this->assertStringNotContainsString(
			'injectCarouselHint',
			$min,
			'HOME-PRODUCTS-2COL: el .min.js debe estar regenerado sin injectCarouselHint.'
		);
	}
}