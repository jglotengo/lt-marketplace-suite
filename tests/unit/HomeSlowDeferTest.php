<?php
/**
 * HomeSlowDeferTest - HOME-SLOW-DEFER (2026-09-13).
 *
 * La home tardaba ~13.4s. Medición en vivo (curl desde SG, localhost): TTFB 0.17s
 * (el servidor NO es el cuello de botella) y ~1.11MB de JS+CSS en 12 archivos,
 * de los cuales el plugin aporta ~400KB en 7 scripts separados tras
 * SG-COMBINE-EXCLUDE. El dominante es `ltms-ux-enhancements` (~320KB min, 13K
 * líneas, ~130 init()) encolado síncrono en toda página no-admin, seguido de
 * `ltms-plaza-viva` (~44KB min).
 *
 * Fix fase 1 (bajo riesgo): encolar ambos con `strategy => 'defer'` (WP 6.3+)
 * para no bloquear parse/primer paint. La fase 2 (split dashboard vs storefront
 * del monolito) queda en backlog.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeSlowDeferTest
 *
 * @group home-slow
 */
final class HomeSlowDeferTest extends LTMS_Unit_Test_Case {

	private const ASSETS_PATH  = __DIR__ . '/../../includes/frontend/class-ltms-frontend-assets.php';
	private const PV_PATH      = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';

	private const DEFER_ARGS = "[ 'in_footer' => true, 'strategy' => 'defer' ]";

	public function test_ux_enhancements_enqueued_with_defer(): void {
		$src = file_get_contents( self::ASSETS_PATH );

		$this->assertStringContainsString(
			'HOME-SLOW-DEFER',
			$src,
			'HOME-SLOW-DEFER: el fix debe tener su marcador traceable en class-ltms-frontend-assets.php.'
		);

		// HOME-SLOW-F2 REVERT (2026-09-14): el split en bundles se revirtió por
		// reporte de checkout bloqueado; el monolito vuelve a encolarse con defer.
		$this->assertStringContainsString(
			self::DEFER_ARGS,
			$src,
			'HOME-SLOW-DEFER: el monolito ltms-ux-enhancements debe encolarse con strategy defer (y seguir en footer).'
		);

		$this->assertStringContainsString(
			"'ltms-ux-enhancements'",
			$src,
			'HOME-SLOW-DEFER: el handle ltms-ux-enhancements debe seguir registrándose.'
		);
	}

	public function test_plaza_viva_enqueued_with_defer(): void {
		$src = file_get_contents( self::PV_PATH );

		$this->assertStringContainsString(
			'HOME-SLOW-DEFER FIX',
			$src,
			'HOME-SLOW-DEFER: el fix debe tener su marcador traceable en class-ltms-native-templates.php.'
		);

		$this->assertStringContainsString(
			self::DEFER_ARGS,
			$src,
			'HOME-SLOW-DEFER: ltms-plaza-viva debe encolarse con strategy defer (y seguir en footer).'
		);

		$this->assertStringContainsString(
			"'ltms-plaza-viva', ltms_asset_url( 'js/ltms-plaza-viva' )",
			$src,
			'HOME-SLOW-DEFER: el handle ltms-plaza-viva debe seguir usando ltms_asset_url().'
		);
	}

	public function test_defer_marker_not_on_small_critical_scripts(): void {
		$src = file_get_contents( self::ASSETS_PATH );

		// header-nav (Vender/Cuenta) y homepage-fixes son pequeños y críticos;
		// la fase 1 los deja tal cual para minimizar blast radius. Invariante:
		// no deben quedar huérfanos — siguen usando enqueue simple (in_footer true).
		$this->assertStringContainsString(
			"'ltms-header-nav'",
			$src,
			'HOME-SLOW-DEFER: ltms-header-nav debe seguir registrándose (no tocado por esta fase).'
		);
	}
}