<?php
/**
 * PanelAuditE2ETest — tests del ciclo PANEL-E2E (auditoría del panel del vendedor:
 * submenús sin datos + "Failed to load resource: net::" en producción).
 *
 * Diagnóstico end-to-end realizado con sesión real de vendor en producción:
 *   - Endpoints del panel (dashboard/orders/wallet/analytics/notifications)
 *     responden 200 success:true en ~1s. Servidor exonerado.
 *   - WAF sin bloqueos (0 eventos/7d).
 *   - JS del panel actual + resiliencia NET-01 presente.
 *   - Causa raíz encontrada: producción corría con LTMS_ENVIRONMENT='staging'
 *     (wp-config) + opción DB 'sandbox'. Consecuencias:
 *       (a) TODAS las integraciones (Aveonline/TPTC/XCover/Addi) apuntaban a
 *           SANDBOX (LTMS_ENVIRONMENT === 'production' ? LIVE : SANDBOX).
 *       (b) Todo el JS del panel se servía NO-minificado: ltms-ux-enhancements.js
 *           a 613KB en cada página (?v= excluía la minificación de SG Optimizer)
 *           + ~800KB de scripts de vistas hardcodeados no-min en los views.
 *       El payload de ~1.4MB ampliaba la ventana de fallos de red
 *       "net::ERR_NETWORK_IO_SUSPENDED" que deja los submenús sin datos en
 *       conexiones móviles/flaky.
 *
 * Fixes (todos source-based structural checks):
 *   PANEL-E2E-005 (P1): enqueue_ux_enhancements() usa SCRIPT_DEBUG (no el flag
 *     LTMS_ENVIRONMENT) para decidir el sufijo .min.
 *   PANEL-E2E-006 (P1): enqueue_frontend_assets() deriva $suffix de SCRIPT_DEBUG.
 *   PANEL-E2E-007 (P1): helper global ltms_asset_url() (SCRIPT_DEBUG + file_exists)
 *     y todos los views/clases enquean via el helper (fin del hardcode .js).
 *   PANEL-E2E-008 (P0, config server-side): LTMS_ENVIRONMENT → 'production' en
 *     wp-config + opción DB (verificado vía SSH, no en este test).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PanelAuditE2ETest
 *
 * Tests unitarios estructurales para los fixes del ciclo PANEL-E2E.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-panel-e2e
 *
 * @group audit-panel-e2e
 */
final class PanelAuditE2ETest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta real al archivo dentro de includes/ o assets/ del plugin.
	 */
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
	// PANEL-E2E-005 (P1) — ux-enhancements min por SCRIPT_DEBUG (613KB → 313KB).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_005_ux_enhancements_min_por_script_debug(): void {
		$src = $this->src( 'includes/frontend/class-ltms-frontend-assets.php' );

		$this->assertStringContainsString( 'PANEL-E2E-005 (P1) FIX', $src,
			'El fix PANEL-E2E-005 debe estar documentado en frontend-assets.' );

		// El sufijo .min se decide por SCRIPT_DEBUG (estándar WP), no por el flag
		// de entorno de pago.
		$this->assertStringContainsString( "|| ! SCRIPT_DEBUG ) ? '.min' : ''", $src,
			'enqueue_ux_enhancements debe usar SCRIPT_DEBUG para el sufijo .min.' );

		// No debe quedar el gate por LTMS_ENVIRONMENT en este método.
		$this->assertStringNotContainsString( "LTMS_ENVIRONMENT === 'production' ) ? '.min' : ''", $src,
			'El sufijo .min no debe depender de LTMS_ENVIRONMENT (producción corría como staging).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PANEL-E2E-006 (P1) — pipeline de assets min por SCRIPT_DEBUG.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_006_frontend_assets_suffix_por_script_debug(): void {
		$src = $this->src( 'includes/frontend/class-ltms-frontend-assets.php' );

		$this->assertStringContainsString( 'PANEL-E2E-006 (P1) FIX', $src,
			'El fix PANEL-E2E-006 debe estar documentado en frontend-assets.' );

		$this->assertStringContainsString( '$is_prod = ! ( defined( \'SCRIPT_DEBUG\' ) && SCRIPT_DEBUG );', $src,
			'enqueue_frontend_assets debe derivar $is_prod de SCRIPT_DEBUG.' );
		$this->assertStringContainsString( '$suffix  = $is_prod ? \'.min\' : \'\';', $src,
			'El sufijo .min debe seguir aplicándose por $is_prod.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PANEL-E2E-007 (P1) — helper ltms_asset_url() + views sin hardcode no-min.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_007_helper_ltms_asset_url_defined(): void {
		$src = $this->src( 'lt-marketplace-suite.php' );

		$this->assertStringContainsString( 'PANEL-E2E-007 (P1) FIX', $src,
			'El fix PANEL-E2E-007 debe estar documentado en el main plugin.' );
		$this->assertStringContainsString( "function ltms_asset_url( string \$relative_base )", $src,
			'Debe existir el helper global ltms_asset_url().' );
		$this->assertStringContainsString( 'file_exists( $min_path )', $src,
			'El helper debe verificar que el .min existe antes de usarlo (evita 404).' );
		$this->assertStringContainsString( 'SCRIPT_DEBUG', $src,
			'El helper debe respetar SCRIPT_DEBUG.' );
	}

	/**
	 * @dataProvider hardcoded_views_provider
	 */
	public function test_007_views_usan_ltms_asset_url( string $view, string $base ): void {
		$src = $this->src( $view );

		$this->assertStringContainsString( "ltms_asset_url( '$base' )", $src,
			"$view debe enqueuear via ltms_asset_url('$base') (min en producción)." );
		$this->assertStringNotContainsString( "LTMS_ASSETS_URL . '$base.js'", $src,
			"$view no debe hardcodear el .js no-minificado." );
	}

	/**
	 * @return array<int, array{0:string,1:string}>
	 */
	public function hardcoded_views_provider(): array {
		return [
			[ 'includes/frontend/views/view-wallet.php', 'js/ltms-wallet' ],
			[ 'includes/frontend/views/view-settings.php', 'js/ltms-settings' ],
			[ 'includes/frontend/views/view-bookings.php', 'js/ltms-bookings' ],
			[ 'includes/frontend/views/view-security.php', 'js/ltms-security' ],
			[ 'includes/frontend/views/view-incidents.php', 'js/ltms-incidents' ],
			[ 'includes/frontend/views/view-posgold.php', 'js/ltms-posgold' ],
			[ 'includes/frontend/views/view-redi.php', 'js/ltms-redi' ],
			[ 'includes/frontend/views/view-drivers.php', 'js/ltms-drivers' ],
			[ 'includes/frontend/views/view-envios.php', 'js/ltms-envios' ],
			[ 'includes/frontend/views/view-marketing.php', 'js/ltms-marketing' ],
			[ 'includes/frontend/views/view-ordenes-compra.php', 'js/ltms-ordenes-compra' ],
			[ 'includes/frontend/views/view-insurance.php', 'js/ltms-insurance-view' ],
			[ 'includes/frontend/views/view-donations.php', 'js/ltms-donations' ],
			[ 'includes/frontend/views/view-shipping-statement.php', 'js/ltms-shipping-statement' ],
			[ 'includes/frontend/views/view-kyc.php', 'js/ltms-kyc' ],
			[ 'includes/frontend/views/view-products.php', 'js/ltms-products' ],
			[ 'includes/frontend/views/view-sellers-landing.php', 'js/ltms-sellers-landing' ],
			[ 'includes/frontend/views/view-aveonline-onboarding.php', 'js/ltms-aveonline-onboarding' ],
			[ 'includes/frontend/views/vendor-parts/form-register.php', 'js/ltms-login-register' ],
			[ 'includes/admin/class-ltms-admin.php', 'js/ltms-admin' ],
			[ 'includes/core/class-ltms-kernel.php', 'js/ltms-product-enhancements' ],
			[ 'includes/frontend/class-ltms-native-templates.php', 'js/ltms-plaza-viva' ],
			[ 'includes/frontend/class-ltms-product-video.php', 'js/ltms-product-video' ],
			[ 'includes/frontend/class-ltms-frontend-assets.php', 'js/ltms-homepage-fixes' ],
			[ 'includes/frontend/class-ltms-frontend-assets.php', 'js/ltms-header-nav' ],
			[ 'includes/booking/class-ltms-booking-calendar.php', 'js/ltms-booking-calendar' ],
			[ 'includes/frontend/class-ltms-frontend-checkout-script-injector.php', 'js/ltms-checkout-fixes' ],
			[ 'includes/frontend/class-ltms-product-tabs.php', 'js/ltms-product-tabs' ],
			[ 'includes/frontend/class-ltms-vendor-storefront.php', 'js/ltms-storefront' ],
			[ 'includes/gateway/class-ltms-gateway-stripe.php', 'js/ltms-stripe' ],
			[ 'includes/api/gateways/class-ltms-api-gateways.php', 'js/ltms-openpay-gateway' ],
			[ 'includes/frontend/views/template-sellers-page.php', 'js/ltms-login-register' ],
		];
	}

	public function test_007_unico_hardcode_no_min_restante_es_openpay_mx(): void {
		// Regresión: el único enqueue no-min hardcodeado restante debe ser
		// ltms-openpay-mx (sin .min y gateway MX inactivo en CO).
		$found = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_path( 'includes' ), \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}
			$content = (string) file_get_contents( $file->getPathname() );
			if ( preg_match( '/wp_enqueue_script\([^)]*js\/ltms-[a-z-]+\.js\'/', $content ) ) {
				$found[] = str_replace( '\\', '/', str_replace( $this->plugin_path( '' ), '', $file->getPathname() ) );
			}
		}
		$this->assertEquals( [ 'includes/api/gateways/class-ltms-api-gateway-openpay-mx.php' ], $found,
			'El único hardcode no-min restante debe ser ltms-openpay-mx (sin .min, gateway MX inactivo en CO).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Cross-check: las integraciones siguen dependiendo del entorno (no-regresión
	// del patrón LIVE/SANDBOX), solo cambió el minificado de assets.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_apis_mantienen_patron_live_sandbox(): void {
		$src = $this->src( 'includes/api/class-ltms-api-aveonline.php' );
		$this->assertStringContainsString( "LTMS_ENVIRONMENT === 'production' ? self::API_BASE_LIVE : self::API_BASE_SANDBOX", $src,
			'Las integraciones siguen resolviendo LIVE/SANDBOX por LTMS_ENVIRONMENT (el fix de assets no debe tocarlas).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PANEL-E2E-009 (P0) — delta de migración que alinea lt_vendor_drivers.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_009_migration_drivers_schema_delta(): void {
		$src = $this->src( 'includes/core/migrations/class-ltms-db-migrations.php' );

		$this->assertStringContainsString( 'PANEL-E2E-009 (P0) FIX', $src,
			'El fix PANEL-E2E-009 debe estar documentado en las migraciones.' );
		$this->assertStringContainsString( "private const CURRENT_VERSION = '2.9.18';", $src,
			'La versión de migración debe bumpear a 2.9.18.' );
		$this->assertStringContainsString( "migrate_2_9_18_drivers_schema", $src,
			'Debe existir el delta migrate_2_9_18_drivers_schema.' );

		$start = strpos( $src, 'private static function migrate_2_9_18_drivers_schema' );
		$this->assertNotFalse( $start, 'Debe existir la definición del método migrate_2_9_18_drivers_schema.' );
		$block = substr( $src, $start, 2600 );

		$this->assertStringContainsString( "CHANGE COLUMN `name` `full_name` VARCHAR(200) NOT NULL", $block,
			'El delta debe renombrar la columna legacy name → full_name.' );
		$this->assertStringContainsString( "ENUM('active','inactive','suspended')", $block,
			'El delta debe añadir la columna status ENUM canónica.' );
		$this->assertStringContainsString( '`wp_user_id`', $block,
			'El delta debe añadir la columna wp_user_id.' );
		$this->assertStringContainsString( "UPDATE `{\$t}` SET `status` = 'active' WHERE `is_active` = 1", $block,
			'El delta debe hacer backfill de status desde is_active legacy.' );
	}
}