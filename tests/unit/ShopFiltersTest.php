<?php
/**
 * ShopFiltersTest - SHOP-FILTERS (2026-09-11).
 *
 * /tienda/ no tenía filtros (solo productos + orden nativo). Se agregan
 * sidebar de filtros (categoría, precio, disponibilidad) + toolbar con
 * toggle de vista grid/list y chips de filtros activos, y se engancha
 * woocommerce_product_query para aplicar product_cat / min_price / max_price
 * / instock al query del catálogo.
 *
 * Tests source-based (file_get_contents + asserts).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class ShopFiltersTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-shop-filters
 *
 * @group audit-shop-filters
 */
final class ShopFiltersTest extends LTMS_Unit_Test_Case {

	private const TPL_PATH = __DIR__ . '/../../includes/frontend/templates/archive-product.php';
	private const CLS_PATH = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-plaza-viva.css';
	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-homepage-fixes.js';

	public function test_template_renders_filter_sidebar(): void {
		$src = file_get_contents( self::TPL_PATH );

		$this->assertStringContainsString( 'pv-shop__sidebar', $src, 'SHOP-FILTERS: el template debe renderizar el sidebar de filtros.' );
		$this->assertStringContainsString( 'pv-shop__filter-title', $src, 'SHOP-FILTERS: los grupos de filtro deben tener título.' );
		$this->assertStringContainsString( 'name="min_price"', $src, 'SHOP-FILTERS: debe existir el campo de precio min.' );
		$this->assertStringContainsString( 'name="max_price"', $src, 'SHOP-FILTERS: debe existir el campo de precio max.' );
		$this->assertStringContainsString( "name=\"instock\" value=\"1\"", $src, 'SHOP-FILTERS: debe existir el filtro de stock (hidden instock).' );
		$this->assertStringContainsString( 'product_cat', $src, 'SHOP-FILTERS: debe existir el filtro de categoría.' );
	}

	public function test_template_renders_toolbar_view_toggle_and_chips(): void {
		$src = file_get_contents( self::TPL_PATH );

		$this->assertStringContainsString( 'pv-shop__toolbar', $src, 'SHOP-FILTERS: debe existir el toolbar.' );
		$this->assertStringContainsString( 'pv-shop__view-toggle', $src, 'SHOP-FILTERS: debe existir el toggle de vista grid/list.' );
		$this->assertStringContainsString( 'pv-shop__active-filter', $src, 'SHOP-FILTERS: debe existir el chip de filtro activo.' );
		$this->assertStringContainsString( 'data-pv-open-filters', $src, 'SHOP-FILTERS: debe existir el botón móvil de abrir filtros.' );
		$this->assertStringContainsString( 'pv-shop--list', $src, 'SHOP-FILTERS: la vista lista debe marcarse con la clase pv-shop--list.' );
	}

	public function test_query_filter_hooked_and_sanitized(): void {
		$src = file_get_contents( self::CLS_PATH );

		$this->assertStringContainsString(
			"add_action( 'woocommerce_product_query', [ __CLASS__, 'apply_shop_filters' ], 10, 1 )",
			$src,
			'SHOP-FILTERS: el filtro de query debe estar registrado en init().'
		);
		$this->assertStringContainsString(
			'public static function apply_shop_filters( \WP_Query $q ): void',
			$src,
			'SHOP-FILTERS: debe existir el método apply_shop_filters().'
		);
		$this->assertStringContainsString(
			"sanitize_title( wp_unslash( \$_GET['product_cat'] ) )",
			$src,
			'SHOP-FILTERS: product_cat debe sanitizarse.'
		);
		$this->assertStringContainsString(
			"[ 'key' => '_price', 'value' => \$min, 'compare' => '>=', 'type' => 'NUMERIC' ]",
			$src,
			'SHOP-FILTERS: min_price debe filtrar por _price >= (NUMERIC).'
		);
		$this->assertStringContainsString(
			"[ 'key' => '_stock_status', 'value' => 'instock' ]",
			$src,
			'SHOP-FILTERS: instock debe filtrar por _stock_status instock.'
		);
	}

	public function test_css_has_layout_and_list_view(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString( 'SHOP-FILTERS', $css, 'SHOP-FILTERS: el CSS debe tener el marcador traceable.' );
		$this->assertStringContainsString( '.pv-shop__layout', $css, 'SHOP-FILTERS: debe existir el layout grid del shop.' );
		$this->assertStringContainsString( '.pv-shop--list ul.products', $css, 'SHOP-FILTERS: debe existir el estilo de vista lista.' );
		$this->assertStringContainsString( '.pv-shop__view-toggle-btn.is-active', $css, 'SHOP-FILTERS: debe existir el estado activo del toggle de vista.' );
	}

	public function test_js_has_mobile_filters_toggle(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString( 'initShopFilters', $js, 'SHOP-FILTERS: el JS debe contener initShopFilters().' );
		$this->assertStringContainsString( 'data-pv-open-filters', $js, 'SHOP-FILTERS: el JS debe escuchar data-pv-open-filters.' );
		$this->assertStringContainsString( 'data-pv-close-filters', $js, 'SHOP-FILTERS: el JS debe escuchar data-pv-close-filters.' );
	}
}