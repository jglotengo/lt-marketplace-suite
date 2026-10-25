<?php
/**
 * HtmlNoCacheHeaderTest — UIUX-BATCH-2026-10-25c.
 *
 * Auditoría completa del 3er reporte ("sigue viéndose el lema"): el HTML
 * crudo servido, todos los assets y TODA la base de datos (postmeta con
 * datos de Elementor incluidos) NO contienen el lema. El texto que el
 * operador ve es una COPIA LOCAL de su navegador: el HTML de SiteGround
 * viaja SIN Cache-Control/ETag/Last-Modified → caché heurística que
 * retiene páginas viejas días sin revalidar (los in-app browsers son
 * todavía más pegajosos).
 *
 * Fix: el kernel envía `Cache-Control: no-cache, must-revalidate` en todo
 * HTML público — el navegador revalida SIEMPRE y la caché de borde de SG
 * sigue sirviendo su copia al validarse.
 *
 * @package LTMS\Tests\Unit
 * @group html-no-cache
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HtmlNoCacheHeaderTest
 *
 * @group html-no-cache
 */
final class HtmlNoCacheHeaderTest extends LTMS_Unit_Test_Case {

	private string $kernel_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->kernel_path = dirname( __DIR__, 2 ) . '/includes/core/class-ltms-kernel.php';
		$this->webhook_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * NOCACHE-A: el kernel envía el header que fuerza la revalidación del
	 * navegador (fin de la caché heurística que mostraba contenido viejo).
	 */
	public function test_001_kernel_envia_no_cache(): void {
		$this->assertFileExists( $this->kernel_path );
		$src = (string) file_get_contents( $this->kernel_path );

		$this->assertStringContainsString(
			"header( 'Cache-Control: no-cache, must-revalidate' );",
			$src,
			'NOCACHE-A: el kernel debe enviar Cache-Control: no-cache, must-revalidate en el HTML público.'
		);
		$this->assertStringContainsString(
			"add_action( 'send_headers'",
			$src,
			'NOCACHE-A: el hook debe ser send_headers (el punto correcto del ciclo de WP).'
		);
		$this->assertStringContainsString(
			'if ( is_admin() )',
			$src,
			'NOCACHE-A: el header NO se envía en wp-admin (el admin gestiona sus propias cabeceras).'
		);
		$this->assertStringContainsString(
			'UIUX-BATCH-2026-10-25c',
			$src,
			'NOCACHE-A: trazabilidad del fix en el kernel.'
		);
	}

	/**
	 * NOCACHE-B: el script de desactivación del slide queda documentado como
	 * REVERTIDO (el operador aclaró que NO era el banner) — el estado de los
	 * slides se restauró por completo (los 3 activos).
	 */
	public function test_002_script_banner_documentado_como_revertido(): void {
		$script = dirname( __DIR__, 2 ) . '/deploy/ltms-deactivate-hero-lema-slide-2026-10-25.php';
		$this->assertFileExists( $script );
		$src = (string) file_get_contents( $script );

		$this->assertStringContainsString(
			'REVERTIDO',
			$src,
			'NOCACHE-B: el script de desactivación del banner debe documentar que fue REVERTIDO (el operador aclaró que el lema no era el banner).'
		);
	}

	/**
	 * NOCACHE-C: whitelist del deploy webhook (lección #177).
	 */
	public function test_003_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString(
			"'tests/unit/HtmlNoCacheHeaderTest.php',",
			$webhook,
			'NOCACHE-C: este test debe estar en la whitelist del webhook (lección #177).'
		);
	}
}
