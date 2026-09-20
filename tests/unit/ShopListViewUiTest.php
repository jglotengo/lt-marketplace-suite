<?php
/**
 * ShopListViewUiTest - SHOP-LIST-UI (2026-09-18) + SHOP-LIST-UI-SPEC (2026-09-19).
 *
 * Rediseño UX/UI de la vista lista del shop (/tienda/?view=list), activada por
 * la clase `pv-shop--list` que LTMS_Native_Templates aplica al scope cuando
 * `?view=list` (archive-product.php:66, $pv_view). El CSS vive en
 * ltms-plaza-viva.css (bloque pv-shop--list).
 *
 * Layout: card en columna (link con grid imagen|contenido + botón debajo),
 * 2 columnas en desktop (<=1100px → 1 columna), imagen 110px izquierda
 * ocupando 2 filas, título arriba + precio debajo a la derecha.
 *
 * SPECIFICITY (2026-09-19): TODOS los selectores usan el scope completo
 * `.pv-scope.pv-shop.pv-shop--list` (0,4,1) para ganar la cascada contra
 * ltms-homepage-fixes.css que usa `.pv-scope.pv-shop ul.products` (0,3,1)
 * — sin el scope completo el grid quedaba en 4 columnas angostas y el botón
 * tapaba la imagen.
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

	public function test_list_ul_uses_full_scope_and_two_columns(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// El scope completo (0,4,1) gana sobre .pv-scope.pv-shop ul.products
		// (0,3,1) de homepage-fixes — sin esto el grid queda en 4 columnas.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products\s*\{\s*grid-template-columns:repeat\(2, 1fr\) !important;/',
			$css,
			'SHOP-LIST-UI: el ul.products DEBE usar el scope completo pv-scope.pv-shop.pv-shop--list y 2 columnas desktop.'
		);
	}

	public function test_list_card_is_column_with_button_below(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// Card en columna (no fila): link arriba, botón debajo — el botón NO
		// puede quedar a la derecha tapando la imagen.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product\s*\{[^}]*flex-direction:column !important;/s',
			$css,
			'SHOP-LIST-UI: la card DEBE ser flex-direction:column (botón debajo, no a la derecha).'
		);
	}

	public function test_list_link_is_grid_110px_plus_1fr(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// Selector con `a.` + scope completo (0,4,2) — gana contra homepage-fixes.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product a\.woocommerce-loop-product__link\s*\{[^}]*display:grid !important;[^}]*grid-template-columns:110px 1fr !important;/s',
			$css,
			'SHOP-LIST-UI: el link DEBE ser grid 110px|1fr con selector a. y scope completo.'
		);
	}

	public function test_list_image_is_110px_left_spanning_two_rows(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product a\.woocommerce-loop-product__link img\s*\{[^}]*width:110px !important;[^}]*grid-row:1 \/ 3 !important;[^}]*grid-column:1 !important;/s',
			$css,
			'SHOP-LIST-UI: la imagen DEBE ser 110px y ocupar las dos filas de la columna izquierda.'
		);
	}

	public function test_list_title_and_price_stack_in_right_column(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__title\s*\{[^}]*grid-row:1 !important;[^}]*grid-column:2 !important;/s',
			$css,
			'SHOP-LIST-UI: el título DEBE ir en la fila 1 de la columna derecha.'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product \.price\s*\{[^}]*grid-row:2 !important;[^}]*grid-column:2 !important;/s',
			$css,
			'SHOP-LIST-UI: el precio DEBE ir debajo del título (fila 2, columna derecha).'
		);
	}

	public function test_list_atc_button_is_below_content(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		// El botón DEBE estar en una fila propia debajo del contenido (padding
		// inferior), no en la columna derecha que tapaba la imagen.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product \.woocommerce-loop-product__buttons\s*\{[^}]*padding:0 16px 16px !important;/s',
			$css,
			'SHOP-LIST-UI: el contenedor del botón DEBE tener padding inferior (botón debajo del contenido).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop\.pv-shop--list ul\.products li\.product \.button\.add_to_cart_button,.*?a\.button\s*\{[^}]*min-width:0 !important;[^}]*height:44px !important;/s',
			$css,
			'SHOP-LIST-UI: el botón ATC DEBE ser de altura 44px y sin ancho fijo (no tapa la imagen).'
		);
	}

	public function test_list_responsive_one_column_below_1100(): void {
		$css = (string) file_get_contents( self::CSS_PATH );

		$this->assertMatchesRegularExpression(
			'/@media \(max-width:1100px\).*?\.pv-scope\.pv-shop\.pv-shop--list ul\.products\s*\{\s*grid-template-columns:1fr !important;/s',
			$css,
			'SHOP-LIST-UI: en <=1100px la vista lista DEBE pasar a una sola columna.'
		);
	}

	public function test_list_min_css_contains_ui_changes(): void {
		if ( ! file_exists( self::CSS_MIN_PATH ) ) {
			$this->markTestSkipped( 'ltms-plaza-viva.min.css no existe' );
		}
		$min = (string) file_get_contents( self::CSS_MIN_PATH );

		$this->assertStringContainsString(
			'grid-template-columns:repeat(2,1fr)',
			$min,
			'el .min desplegado DEBE incluir el grid de 2 columnas desktop.'
		);
		$this->assertStringContainsString(
			'grid-template-columns:110px 1fr',
			$min,
			'el .min desplegado DEBE incluir el grid 110px|1fr del link.'
		);
	}
}