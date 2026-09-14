<?php
/**
 * HomeSlowSplitTest - HOME-SLOW fase 2 (2026-09-14): split del monolito UX.
 *
 * El monolito `assets/js/ltms-ux-enhancements.js` (~13K líneas, ~320KB min) se
 * descargaba en TODA página no-admin. La fase 1 (HOME-SLOW-DEFER) ya lo cargaba
 * con `defer`. La fase 2 lo parte en 3 bundles generados por
 * `bin/build-ux-bundles.js` (el monolito sigue siendo la fuente de verdad):
 *   - ltms-ux-shared      → secciones compartidas (todas las páginas frontend)
 *   - ltms-ux-dashboard   → panel del vendedor + mi-cuenta (is_account_page /
 *                           shortcode ltms_vendor_*)
 *   - ltms-ux-storefront  → tienda pública
 * El storefront ahorra ~74KB min y el panel ~138KB min por página.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeSlowSplitTest
 *
 * @group home-slow
 */
final class HomeSlowSplitTest extends LTMS_Unit_Test_Case {

	private const ASSETS_PHP = __DIR__ . '/../../includes/frontend/class-ltms-frontend-assets.php';
	private const SHARED_JS  = __DIR__ . '/../../assets/js/ltms-ux-shared.js';
	private const DASH_JS    = __DIR__ . '/../../assets/js/ltms-ux-dashboard.js';
	private const SF_JS      = __DIR__ . '/../../assets/js/ltms-ux-storefront.js';

	public function test_monolith_is_not_enqueued_as_script(): void {
		$src = file_get_contents( self::ASSETS_PHP );

		$this->assertStringNotContainsString(
			"js/ltms-ux-enhancements",
			$src,
			'HOME-SLOW-F2: el monolito ltms-ux-enhancements.js ya no debe encolarse como script JS.'
		);
	}

	public function test_three_bundles_are_enqueued(): void {
		$src = file_get_contents( self::ASSETS_PHP );

		$this->assertStringContainsString(
			"'ltms-ux-shared'",
			$src,
			'HOME-SLOW-F2: ltms-ux-shared debe encolarse en todas las páginas frontend.'
		);
		$this->assertStringContainsString(
			"'ltms-ux-dashboard'",
			$src,
			'HOME-SLOW-F2: ltms-ux-dashboard debe encolarse en mi-cuenta y panel del vendedor.'
		);
		$this->assertStringContainsString(
			"'ltms-ux-storefront'",
			$src,
			'HOME-SLOW-F2: ltms-ux-storefront debe encolarse en la tienda pública.'
		);
		$this->assertStringContainsString(
			"is_account_page()",
			$src,
			'HOME-SLOW-F2: la condición de dashboard debe usar is_account_page().'
		);
	}

	public function test_shared_bundle_contains_cross_functions(): void {
		$shared = file_get_contents( self::SHARED_JS );

		$this->assertStringContainsString(
			'function celebrateConfetti()',
			$shared,
			'HOME-SLOW-F2: celebrateConfetti (cruce dashboard→storefront) debe vivir en el bundle shared.'
		);
		$this->assertStringContainsString(
			'function showOrderSuccess(orderData = {})',
			$shared,
			'HOME-SLOW-F2: showOrderSuccess (cruce storefront→dashboard) debe vivir en el bundle shared.'
		);
		$this->assertStringContainsString(
			'LTMS.UX.reinit',
			$shared,
			'HOME-SLOW-F2: el shared debe exponer LTMS.UX.reinit para el re-init del SPA del dashboard.'
		);
	}

	public function test_shared_bundle_does_not_init_dashboard_or_storefront_modules(): void {
		$shared = file_get_contents( self::SHARED_JS );

		$this->assertStringNotContainsString(
			'initKeyboardShortcuts();',
			$shared,
			'HOME-SLOW-F2: el initAll del shared no debe llamar init del bundle dashboard.'
		);
		$this->assertStringNotContainsString(
			'initCartDrawer();',
			$shared,
			'HOME-SLOW-F2: el initAll del shared no debe llamar init del bundle storefront.'
		);
	}

	public function test_bundles_keep_their_init_scope(): void {
		$dash = file_get_contents( self::DASH_JS );
		$sf   = file_get_contents( self::SF_JS );

		$this->assertStringContainsString(
			'loadNotifSettings();',
			$dash,
			'HOME-SLOW-F2: el initAll del dashboard debe llamar loadNotifSettings().'
		);
		$this->assertStringContainsString(
			"jQuery(document).on('ltms:view:loaded ltms:modal:open'",
			$dash,
			'HOME-SLOW-F2: el re-init jQuery del SPA del dashboard debe vivir solo en el bundle dashboard.'
		);
		$this->assertStringContainsString(
			'initCartDrawer();',
			$sf,
			'HOME-SLOW-F2: el initAll del storefront debe llamar initCartDrawer().'
		);
		$this->assertStringNotContainsString(
			'initKeyboardShortcuts();',
			$sf,
			'HOME-SLOW-F2: el initAll del storefront no debe llamar init del bundle dashboard.'
		);
	}

	public function test_generated_bundles_and_min_exist(): void {
		$this->assertFileExists( self::SHARED_JS, 'HOME-SLOW-F2: ltms-ux-shared.js debe existir.' );
		$this->assertFileExists( self::DASH_JS, 'HOME-SLOW-F2: ltms-ux-dashboard.js debe existir.' );
		$this->assertFileExists( self::SF_JS, 'HOME-SLOW-F2: ltms-ux-storefront.js debe existir.' );
		$this->assertFileExists( __DIR__ . '/../../assets/js/ltms-ux-shared.min.js', 'HOME-SLOW-F2: ltms-ux-shared.min.js debe existir (generado por build:js).' );
		$this->assertFileExists( __DIR__ . '/../../assets/js/ltms-ux-dashboard.min.js', 'HOME-SLOW-F2: ltms-ux-dashboard.min.js debe existir.' );
		$this->assertFileExists( __DIR__ . '/../../assets/js/ltms-ux-storefront.min.js', 'HOME-SLOW-F2: ltms-ux-storefront.min.js debe existir.' );
	}
}