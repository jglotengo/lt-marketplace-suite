<?php
/**
 * HeroLemaSlideTest — UIUX-BATCH-2026-10-25c.
 *
 * 3er reporte del operador: "sigue viéndose el lema". Causa raíz REAL:
 * el lema viaja EN LA IMAGEN del slide #1 del Home Slider
 * (WhatsApp-Image-2026-10-15-at-1.31.59-AM.jpeg — banner principal con el
 * texto horneado en el JPEG, subido el día del brief de marca). El script
 * deploy/ltms-deactivate-hero-lema-slide-2026-10-25.php lo desactiva
 * (active=0, reversible) y el hero reorganiza los banners restantes.
 *
 * @package LTMS\Tests\Unit
 * @group hero-lema-slide
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HeroLemaSlideTest
 *
 * @group hero-lema-slide
 */
final class HeroLemaSlideTest extends LTMS_Unit_Test_Case {

	private string $script_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->script_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deactivate-hero-lema-slide-2026-10-25.php';
		$this->webhook_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * LEMA-A: el script desactiva el slide del banner del lema por patrón
	 * de archivo, de forma idempotente y reversible (NO borra el slide).
	 */
	public function test_001_script_desactiva_banner_lema(): void {
		$this->assertFileExists( $this->script_path );
		$src = (string) file_get_contents( $this->script_path );

		$this->assertStringContainsString(
			"get_option( 'ltms_home_slides', array() )",
			$src,
			'LEMA-A: el script opera sobre la opción real del Home Slider (ltms_home_slides — la fuente de verdad del hero).'
		);
		$this->assertStringContainsString(
			'strpos( $img, \'WhatsApp-Image-2026-10-15\' )',
			$src,
			'LEMA-A: identifica el banner del lema por el nombre de archivo (upload del día del brief de marca).'
		);
		$this->assertStringContainsString(
			"\$s['active'] = '0';",
			$src,
			'LEMA-A: lo DESACTIVA (active=0) — reversible, no destruye el slide.'
		);
		$this->assertStringContainsString(
			'Idempotente',
			$src,
			'LEMA-A: declara idempotencia (re-run solo reporta).'
		);
		$this->assertStringNotContainsString(
			'unset( $slides',
			$src,
			'LEMA-A: NO elimina slides (desactivación reversible, no destrucción de contenido del operador).'
		);
	}

	/**
	 * LEMA-B: whitelist del deploy webhook (lección #177).
	 */
	public function test_002_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString(
			"'deploy/ltms-deactivate-hero-lema-slide-2026-10-25.php',",
			$webhook,
			'LEMA-B: el script debe estar en la whitelist del webhook.'
		);
		$this->assertStringContainsString(
			"'tests/unit/HeroLemaSlideTest.php',",
			$webhook,
			'LEMA-B: este test debe estar en la whitelist del webhook (lección #177).'
		);
	}
}
