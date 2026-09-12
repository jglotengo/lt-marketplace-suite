<?php
/**
 * HeaderAccountMenuTest - HEADER-ACCOUNT-MENU (2026-09-12).
 *
 * El menú que se abre al pulsar el icono de cuenta en el header (cuando el vendor
 * está logueado) estaba desactualizado: apuntaba a páginas de CLIENTE
 * (/mis-pedidos/, /mi-billetera/) o a fallbacks hardcodeados, y NO abría el
 * submenú correcto del panel del vendedor (el SPA siempre cargaba 'home').
 *
 * Fix en 3 capas:
 *  1. PHP (class-ltms-frontend-assets.php): localiza URLs de subvista del vendor
 *     derivadas del dashboard real + ?view=X (v_orders_url, v_wallet_url,
 *     v_products_url, v_settings_url, kyc_url).
 *  2. JS header (ltms-header-nav.js): buildClienteBtn() usa esas URLs (deep-link)
 *     para el menú del vendor.
 *  3. JS dashboard (ltms-dashboard.js): getInitialView() lee ?view= y abre la
 *     subvista correcta (antes siempre 'home').
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HeaderAccountMenuTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-header-account
 *
 * @group audit-header-account
 */
final class HeaderAccountMenuTest extends LTMS_Unit_Test_Case {

	private const PHP_PATH       = __DIR__ . '/../../includes/frontend/class-ltms-frontend-assets.php';
	private const HEADER_JS      = __DIR__ . '/../../assets/js/ltms-header-nav.js';
	private const HEADER_JS_MIN  = __DIR__ . '/../../assets/js/ltms-header-nav.min.js';
	private const DASH_JS        = __DIR__ . '/../../assets/js/ltms-dashboard.js';

	public function test_php_localizes_vendor_subview_urls(): void {
		$php = file_get_contents( self::PHP_PATH );

		$this->assertStringContainsString( "add_query_arg( 'view', 'orders'", $php, 'HEADER-ACCOUNT-MENU: debe derivar v_orders_url del dashboard.' );
		$this->assertStringContainsString( "add_query_arg( 'view', 'wallet'", $php, 'HEADER-ACCOUNT-MENU: debe derivar v_wallet_url del dashboard.' );
		$this->assertStringContainsString( "add_query_arg( 'view', 'products'", $php, 'HEADER-ACCOUNT-MENU: debe derivar v_products_url del dashboard.' );
		$this->assertStringContainsString( "add_query_arg( 'view', 'settings'", $php, 'HEADER-ACCOUNT-MENU: debe derivar v_settings_url del dashboard.' );
		$this->assertStringContainsString( "'v_orders_url'", $php, 'HEADER-ACCOUNT-MENU: debe localizar v_orders_url.' );
		$this->assertStringContainsString( "'kyc_url'", $php, 'HEADER-ACCOUNT-MENU: debe localizar kyc_url.' );
	}

	public function test_header_menu_uses_vendor_deep_links(): void {
		$js = file_get_contents( self::HEADER_JS );

		$this->assertStringContainsString( 'd.v_orders_url', $js, 'HEADER-ACCOUNT-MENU: Mis Pedidos debe usar v_orders_url (no la página de cliente).' );
		$this->assertStringContainsString( 'd.v_wallet_url', $js, 'HEADER-ACCOUNT-MENU: Mi Billetera debe usar v_wallet_url (no la billetera de cliente).' );
		$this->assertStringContainsString( 'd.v_products_url', $js, 'HEADER-ACCOUNT-MENU: Mis Productos debe usar v_products_url.' );
		$this->assertStringContainsString( 'd.v_settings_url', $js, 'HEADER-ACCOUNT-MENU: Configuración debe usar v_settings_url.' );
		$this->assertStringContainsString( "d.kyc_url || '/verificacion-identidad/'", $js, 'HEADER-ACCOUNT-MENU: Verificación KYC debe ir a /verificacion-identidad/' );
	}

	public function test_dashboard_reads_view_deep_link(): void {
		$js = file_get_contents( self::DASH_JS );

		$this->assertStringContainsString( 'getInitialView', $js, 'HEADER-ACCOUNT-MENU: el dashboard debe exponer getInitialView().' );
		$this->assertStringContainsString( 'URLSearchParams', $js, 'HEADER-ACCOUNT-MENU: getInitialView debe leer ?view= de la URL.' );
		$this->assertStringContainsString( 'this.loadView(this.getInitialView())', $js, 'HEADER-ACCOUNT-MENU: init() debe arrancar en la subvista del header (no siempre home).' );
	}

	public function test_header_min_regenerated(): void {
		$min = file_get_contents( self::HEADER_JS_MIN );

		$this->assertStringContainsString( 'v_orders_url', $min, 'HEADER-ACCOUNT-MENU: el .min.js debe regenerar v_orders_url.' );
		$this->assertStringContainsString( 'verificacion-identidad', $min, 'HEADER-ACCOUNT-MENU: el .min.js debe regenerar el enlace KYC.' );
	}
}