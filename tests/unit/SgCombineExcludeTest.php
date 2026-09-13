<?php
/**
 * SgCombineExcludeTest - SG-COMBINE-EXCLUDE (2026-09-12).
 *
 * SiteGround Optimizer combinaba TODOS los JS del frontend en un único
 * `siteground-optimizer-combined-js-*.js` (cargado `defer`), rompiendo la
 * dependencia de jQuery y el orden de ejecución de ltms-header-nav (botones
 * Vender/Cuenta) y ltms-cart-drawer (mini-cart) — que quedaban "muertos" sin
 * error de consola. El fix excluye todos los handles `ltms-*` de la combinación
 * y minificación de SG en todas las páginas (paridad con la vitrina, que ya se
 * auto-excluía).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class SgCombineExcludeTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-sg-combine
 *
 * @group audit-sg-combine
 */
final class SgCombineExcludeTest extends LTMS_Unit_Test_Case {

	private const ASSETS_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-assets.php';

	public function test_sg_exclusion_filters_registered(): void {
		$src = file_get_contents( self::ASSETS_PATH );

		$this->assertStringContainsString(
			'SG-COMBINE-EXCLUDE FIX',
			$src,
			'SG-COMBINE-EXCLUDE: el fix debe tener su marcador traceable.'
		);
		$this->assertStringContainsString(
			'sgo_javascript_combine_exclude',
			$src,
			'SG-COMBINE-EXCLUDE: debe registrarse la exclusión de combinación de JS de SG.'
		);
		$this->assertStringContainsString(
			'sgo_css_combine_exclude',
			$src,
			'SG-COMBINE-EXCLUDE: debe registrarse la exclusión de combinación de CSS de SG.'
		);
		$this->assertStringContainsString(
			'sgo_js_minify_exclude',
			$src,
			'SG-COMBINE-EXCLUDE: debe registrarse la exclusión de minificación de JS de SG.'
		);
	}

	public function test_exclude_all_ltms_handles(): void {
		$src = file_get_contents( self::ASSETS_PATH );

		$this->assertStringContainsString(
			'function sg_exclude_ltms_assets',
			$src,
			'SG-COMBINE-EXCLUDE: debe existir el helper sg_exclude_ltms_assets().'
		);
		$this->assertStringContainsString(
			"strpos( \$handle, 'ltms-' ) === 0",
			$src,
			'SG-COMBINE-EXCLUDE: debe filtrar todos los handles con prefijo ltms-.'
		);
		$this->assertStringContainsString(
			"strpos( \$handle, 'ltms_' ) === 0",
			$src,
			'SG-COMBINE-EXCLUDE: debe filtrar también handles con prefijo ltms_ (underscore).'
		);
	}
}