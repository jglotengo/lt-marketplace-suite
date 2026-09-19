<?php
/**
 * ShopListViewUiTest - SHOP-LIST-UI (2026-09-18).
 *
 * Rediseño UX/UI de la vista lista del shop (/tienda/?view=list), activada por
 * la clase `pv-shop--list` que LTMS_Native_Templates aplica al scope cuando
 * `?view=list` (archive-product.php:66, $pv_view). El CSS vive en
 * ltms-plaza-viva.css (bloque pv-shop--list).
 *
 * Antes: imagen 120px + título + precio + botón ATC apretados en una sola fila
 * sin jerarquía (el link era flex-direction:row con todo inline). Ahora:
 *  - link = grid `160px 1fr`: imagen a la izquierda (grid-row 1/3), título
 *    arriba (fila 1) y precio debajo (fila 2) a la derecha.
 *  - botón ATC = columna derecha de la card (hermano del link), altura 46px,
 *    ancho mínimo 170px, con padding cómodo.
 *  - responsive <=900px: botón pasa debajo del contenido a ancho completo.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class ShopListViewUiTest extends LTMS_Unit_Test_Case {

	private const CSS_PATH     = __DIR__ . '/../../assets/css/ltms-plaza-viva.css';
	private const CSS_MIN_PATH = __DIR__ . '/../../assets/css/ltms-plaza-viva.min.css';

	public function test_list_link_is_grid_160px_plus_1fr(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__link\s*\{[^}]*grid-template-columns:160px 1fr !important;/s',
			$css,
			'SHOP-LIST-UI: el link de la card en vista lista DEBE ser grid 160px|1fr (imagen izquierda + contenido derecha).'
		);
	}

	public function test_list_image_is_160px_left_spanning_two_rows(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__link img\s*\{[^}]*width:160px !important;[^}]*grid-row:1 \/ 3 !important;[^}]*grid-column:1 !important;/s',
			$css,
			'SHOP-LIST-UI: la imagen DEBE ser 160px y ocupar las dos filas de la columna izquierda del grid.'
		);
	}

	public function test_list_title_and_price_stack_in_right_column(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__title\s*\{[^}]*grid-row:1 !important;[^}]*grid-column:2 !important;/s',
			$css,
			'SHOP-LIST-UI: el título DEBE ir en la fila 1 de la columna derecha.'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-shop--list ul\.products li\.product \.price\s*\{[^}]*grid-row:2 !important;[^}]*grid-column:2 !important;/s',
			$css,
			'SHOP-LIST-UI: el precio DEBE ir debajo del título (fila 2, columna derecha).'
		);
	}

	public function test_list_atc_button_is_comfortable_right_column(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-shop--list ul\.products li\.product \.button\.add_to_cart_button,[^}]*\{[^}]*min-width:170px !important;[^}]*height:46px !important;/s',
			$css,
			'SHOP-LIST-UI: el botón ATC DEBE tener ancho mínimo 170px y altura 46px (touch target cómodo).'
		);
	}

	public function test_list_responsive_below_900_moves_button_below(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/@media \(max-width:900px\).*?\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__buttons\s*\{[^}]*flex:1 1 100% !important;/s',
			$css,
			'SHOP-LIST-UI: en <=900px el botón ATC DEBE pasar debajo del contenido a ancho completo.'
		);
	}

	public function test_list_min_css_contains_ui_changes(): void {
		if ( ! file_exists( self::CSS_MIN_PATH ) ) {
			$this->markTestSkipped( 'ltms-plaza-viva.min.css no existe' );
		}
		$min = (string) file_get_contents( self::CSS_MIN_PATH );

		$this->assertStringContainsString(
			'grid-template-columns:160px 1fr',
			$min,
			'el .min desplegado DEBE incluir el grid 160px|1fr de la vista lista.'
		);
		$this->assertStringContainsString(
			'grid-row:1/3',
			$min,
			'el .min desplegado DEBE incluir la imagen ocupando las dos filas.'
		);
	}
}