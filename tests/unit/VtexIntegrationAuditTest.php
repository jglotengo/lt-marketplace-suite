<?php
/**
 * VtexIntegrationAuditTest — tests estructurales de la integración VTEX.
 *
 * Integración tipo POSGold: cada vendor con su cuenta VTEX (accountName +
 * appKey + appToken) sincroniza su catálogo hacia WooCommerce usando las APIs
 * de Catalog (Search + PVT), Pricing e Inventory/Logistics, con las MISMAS
 * reglas de negocio configurables que la integración PosGold (transporte,
 * publicidad, devoluciones, margen, comisión Lo Tengo, IVA, ReDi, redondeo,
 * plantilla SEO, filtro por categoría).
 *
 * Alcance acordado con el usuario: Catalog + Pricing + Inventory.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class VtexIntegrationAuditTest
 *
 * Ejecutar con: ./vendor/bin/phpunit --group audit-vtex
 *
 * @group audit-vtex
 */
final class VtexIntegrationAuditTest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function src( string $relative ): string {
		$file = $this->plugin_path( $relative );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( "$relative no disponible." );
		}
		return (string) file_get_contents( $file );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// API client — autenticación, SSRF guard, endpoints, normalización.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_api_client_exists_and_auth_headers(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( 'final class LTMS_Api_Vtex', $src, 'Debe existir LTMS_Api_Vtex.' );
		$this->assertStringContainsString( "'X-VTEX-API-AppKey'", $src, 'Auth header AppKey debe estar presente.' );
		$this->assertStringContainsString( "'X-VTEX-API-AppToken'", $src, 'Auth header AppToken debe estar presente.' );
	}

	public function test_api_client_ssrf_guard_on_account_name(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( 'build_base_url', $src, 'Debe existir build_base_url.' );
		$this->assertStringContainsString( "preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', \$account_name )", $src,
			'accountName debe validarse con patrón estricto (SSRF guard, mismo patrón que PosGold).' );
		$this->assertStringContainsString( "preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', \$environment )", $src,
			'environment debe validarse con patrón estricto.' );
	}

	public function test_api_client_endpoints_catalog_pricing_inventory(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		// Catalog (Search + PVT).
		$this->assertStringContainsString( '/api/catalog_system/pub/products/search', $src, 'Search API endpoint presente.' );
		$this->assertStringContainsString( '/api/catalog_system/pub/category/tree/', $src, 'Category tree endpoint presente.' );
		$this->assertStringContainsString( '/api/catalog_system/pvt/products/productget/', $src, 'Product PVT endpoint presente.' );
		$this->assertStringContainsString( '/api/catalog_system/pvt/sku/stockkeepingunitbyid/', $src, 'SKU endpoint presente.' );
		// Pricing.
		$this->assertStringContainsString( '/api/pricing/prices/', $src, 'Pricing API endpoint presente.' );
		// Inventory (Logistics).
		$this->assertStringContainsString( '/api/logistics/pvt/inventory/skus/', $src, 'Inventory API endpoint presente.' );
		$this->assertStringContainsString( '/api/logistics/pvt/configuration/warehouses', $src, 'Warehouses endpoint presente.' );
	}

	public function test_api_client_normalize_search_item(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( 'normalize_search_item', $src, 'Debe existir normalize_search_item.' );
		$this->assertStringContainsString( "'commertialOffer'", $src, 'Debe leer el commertialOffer (precio + stock).' );
		$this->assertStringContainsString( "'AvailableQuantity'", $src, 'Debe leer AvailableQuantity (inventario).' );
		$this->assertStringContainsString( "'regular_price'", $src, 'Debe mapear regular_price (pricing).' );
		$this->assertStringContainsString( "'refId'", $src, 'Debe usar RefId como SKU canónico.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Price calculator — mismas reglas de negocio que PosGold.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_price_calculator_reuses_posgold_formula(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-price-calculator.php' );

		$this->assertStringContainsString( 'final class LTMS_Vtex_Price_Calculator', $src, 'Debe existir LTMS_Vtex_Price_Calculator.' );
		$this->assertStringContainsString( 'const META_PREFIX = \'ltms_vtex_price_\'', $src,
			'Las reglas VTEX deben usar meta prefix propio (independiente de PosGold).' );
		$this->assertStringContainsString( 'LTMS_PosGold_Price_Calculator::get_defaults()', $src,
			'Los defaults deben ser los mismos que PosGold.' );
		$this->assertStringContainsString( 'LTMS_PosGold_Price_Calculator::calculate( $cost, $rules )', $src,
			'El cálculo debe delegar a la misma fórmula de PosGold (reglas idénticas).' );
	}

	public function test_price_calculator_filter_by_category_includes_ancestors(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-price-calculator.php' );

		$this->assertStringContainsString( 'filter_by_category', $src );
		$this->assertStringContainsString( "'categoria_ids'", $src,
			'El filtro debe considerar categoriesIds (ancestros) — seleccionar "Moda" incluye subcategorías.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Sync engine — credenciales, cron, sync paginado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_sync_engine_credentials_decrypt(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertStringContainsString( 'final class LTMS_Vtex_Sync', $src, 'Debe existir LTMS_Vtex_Sync.' );
		$this->assertStringContainsString( "'ltms_vtex_account_name'", $src, 'Meta de accountName presente.' );
		$this->assertStringContainsString( "'ltms_vtex_app_token'", $src, 'Meta de appToken presente.' );
		$this->assertStringContainsString( 'LTMS_Core_Security::decrypt', $src,
			'appKey/appToken deben desencriptarse antes de usarse.' );
	}

	public function test_sync_engine_cron_and_pagination(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertStringContainsString( 'const CRON_HOOK = \'ltms_vtex_sync_cron\'', $src, 'Cron hook presente.' );
		$this->assertStringContainsString( 'get_products_search', $src, 'El sync debe usar el Search API.' );
		$this->assertStringContainsString( 'PAGE_SIZE', $src, 'Debe existir paginación (PAGE_SIZE).' );
		$this->assertStringContainsString( 'const SYNC_META_KEY = \'_ltms_vtex_synced\'', $src, 'Meta de sync presente.' );
	}

	public function test_sync_engine_rate_limit_and_in_progress(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertStringContainsString( 'ltms_vtex_last_sync', $src, 'Rate limit por vendor presente.' );
		$this->assertStringContainsString( '_ltms_vtex_sync_in_progress', $src, 'Flag de sync en curso presente.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Registro — autoloader, AJAX, nav del dashboard, vista.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_autoloader_registers_vtex_classes(): void {
		$src = $this->src( 'lt-marketplace-suite.php' );

		$this->assertStringContainsString( "'ltms-api-vtex'", $src, 'Autoloader: ltms-api-vtex.' );
		$this->assertStringContainsString( "'ltms-vtex-sync'", $src, 'Autoloader: ltms-vtex-sync.' );
		$this->assertStringContainsString( "'ltms-vtex-price-calculator'", $src, 'Autoloader: ltms-vtex-price-calculator.' );
	}

	public function test_ajax_actions_registered(): void {
		$src = $this->src( 'includes/frontend/class-ltms-dashboard-logic.php' );

		foreach ( [ 'ltms_save_vtex_credentials', 'ltms_test_vtex_connection', 'ltms_sync_vtex_products', 'ltms_get_vtex_sync_status', 'ltms_save_vtex_categories', 'ltms_save_vtex_rules', 'ltms_save_vtex_seo', 'ltms_get_vtex_categories' ] as $action ) {
			$this->assertStringContainsString( 'wp_ajax_' . $action, $src, "AJAX action $action debe registrarse." );
		}
		$this->assertStringContainsString( 'LTMS_Vtex_Sync::init()', $src, 'El cron hook de VTEX debe inicializarse.' );
	}

	public function test_ajax_sync_schedules_in_background(): void {
		$src = $this->src( 'includes/frontend/class-ltms-dashboard-logic.php' );

		$this->assertStringContainsString( 'LTMS_Vtex_Sync::schedule_sync', $src,
			'El handler de sync debe PROGRAMAR la sync en background (no ejecutarla en el request AJAX).' );
		$this->assertStringContainsString( 'parse_category_ids', $src,
			'Debe persistir el filtro de categorías actual antes de programar (evita "0 productos" por filtro viejo).' );
		$this->assertStringContainsString( 'ajax_get_vtex_sync_status', $src,
			'Debe existir el endpoint de polling de estado.' );
	}

	public function test_sync_engine_exposes_status(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertStringContainsString( 'public static function get_sync_status', $src,
			'LTMS_Vtex_Sync debe exponer get_sync_status() para el polling del frontend.' );
		$this->assertStringContainsString( '_ltms_vtex_sync_last_result', $src,
			'El resultado de la última sync debe persistirse en user_meta.' );
	}

	public function test_api_full_catalog_methods(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( 'public static function get_catalog_slugs', $src,
			'Debe existir get_catalog_slugs() (sitemaps del catálogo completo).' );
		$this->assertStringContainsString( 'public static function get_products_search_by_slug', $src,
			'Debe existir get_products_search_by_slug() (/products/search/{slug}/p).' );
		$this->assertStringContainsString( 'public static function fetch_raw', $src,
			'Debe existir fetch_raw() (GET crudo para el sitemap XML).' );
		$this->assertStringContainsString( 'product-', $src, 'Debe consultar los sitemaps product-{n}.xml.' );
	}

	public function test_sync_uses_full_catalog_phase_b(): void {
		$src = $this->src( 'includes/business/class-ltms-vtex-sync.php' );

		$this->assertStringContainsString( 'get_catalog_slugs', $src,
			'El sync debe enumerar el catálogo completo vía sitemaps.' );
		$this->assertStringContainsString( 'get_products_search_by_slug', $src,
			'El sync debe fetchear cada producto faltante por slug.' );
		$this->assertStringContainsString( 'product-example', $src,
			'Debe saltarse el producto de ejemplo de VTEX.' );
		$this->assertStringContainsString( 'processed_product_ids', $src,
			'Debe deduplicar entre la Fase A (search) y la Fase B (sitemap).' );
	}

	public function test_normalize_prefers_product_name(): void {
		$src = $this->src( 'includes/api/class-ltms-api-vtex.php' );

		$this->assertStringContainsString( "pick_product_name", $src,
			'Debe existir pick_product_name() para el nombre real del producto.' );
		$this->assertStringContainsString( "'productName'", $src,
			'El nombre debe priorizar productName sobre el código corto del SKU.' );
	}

	public function test_dashboard_nav_and_view_include(): void {
		$src = $this->src( 'includes/frontend/views/dashboard-wrapper.php' );

		$this->assertStringContainsString( "'vtex'", $src, 'Nav item vtex presente.' );
		$this->assertStringContainsString( "'vtex'     =>", $src, 'Ícono vtex presente en $svg_icons.' );
		$this->assertStringContainsString( 'id="ltms-view-vtex"', $src, 'Sección de vista vtex presente.' );
		$this->assertStringContainsString( 'view-vtex.php', $src, 'La vista view-vtex.php debe incluirse.' );
	}

	public function test_vtex_view_uses_posgold_style_business_rules(): void {
		$src = $this->src( 'includes/frontend/views/view-vtex.php' );

		// Mismas reglas de negocio que PosGold.
		foreach ( [ 'transport_pct', 'advertising_pct', 'returns_pct', 'margin_pct', 'lotengo_commission_pct', 'iva_pct', 'redi_cost_pct', 'round_multiple' ] as $rule ) {
			$this->assertStringContainsString( $rule, $src, "Regla de negocio $rule presente en la vista VTEX." );
		}
		$this->assertStringContainsString( 'ltms-vtex-account-name', $src, 'Campo accountName presente.' );
		$this->assertStringContainsString( 'ltms-vtex-app-key', $src, 'Campo appKey presente.' );
		$this->assertStringContainsString( 'ltms-vtex-app-token', $src, 'Campo appToken presente.' );
		$this->assertStringContainsString( "ltms_asset_url( 'js/ltms-vtex' )", $src, 'La vista debe enqueuear el JS min en producción.' );
	}
}