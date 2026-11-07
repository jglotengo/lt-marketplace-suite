<?php
/**
 * PluginHeaderVersionSyncTest - HEADER-SYNC-2026-11-07.
 *
 * El header del plugin tenia `Version: 2.9.339` mientras el define real era
 * `LTMS_VERSION 2.9.433` (drift preexistente detectado en el ciclo
 * DEPLOY-RELOAD-2026-10-29 como P2). WP lee el `Version:` del header del
 * archivo principal (get_plugin_data), no la constante: la UI de plugins,
 * los checks de compatibilidad y cualquier diagnostico external que compare
 * versiones leen el header.
 *
 * Este test fija la invariante: el Version del header debe ser SIEMPRE
 * igual a LTMS_VERSION, para que no vuelva a haber dos fuentes de verdad
 * divergentes en version del plugin.
 *
 * @package LTMS\Tests\Unit
 * @group plugin-version
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PluginHeaderVersionSyncTest
 *
 * @group plugin-version
 */
final class PluginHeaderVersionSyncTest extends LTMS_Unit_Test_Case {

	private const ROOT   = __DIR__ . '/../..';
	private const PLUGIN = self::ROOT . '/lt-marketplace-suite.php';

	/**
	 * Extrae el valor del header `Version:` tal como lo lee get_file_data de WP.
	 */
	private function get_header_version(): string {
		$src = file_get_contents( self::PLUGIN );

		$this->assertSame(
			1,
			preg_match_all( '/^\s*\*\s+Version:\s+([\d.]+)\s*$/m', $src, $m ),
			'HEADER-SYNC: el plugin debe declarar exactamente un header `* Version:`.'
		);

		return trim( $m[1][0] );
	}

	/**
	 * Extrae el literal de LTMS_VERSION desde el define real del plugin.
	 */
	private function get_ltms_version(): string {
		$src = file_get_contents( self::PLUGIN );

		$this->assertSame(
			1,
			preg_match_all( "/define\(\s*'LTMS_VERSION'\s*,\s*'([\d.]+)'\s*\);/", $src, $m ),
			'HEADER-SYNC: el plugin debe declarar exactamente un define de LTMS_VERSION.'
		);

		return trim( $m[1][0] );
	}

	public function test_001_header_version_igual_a_ltms_version(): void {
		$header = $this->get_header_version();
		$define = $this->get_ltms_version();

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$header,
			'HEADER-SYNC: el Version del header debe ser un semver AAA.B.C.'
		);
		$this->assertSame(
			$define,
			$header,
			"HEADER-SYNC: el header `Version: {$header}` no puede divergir de LTMS_VERSION `{$define}`; WP lee el header, el cache-busting usa LTMS_VERSION."
		);
	}
}
