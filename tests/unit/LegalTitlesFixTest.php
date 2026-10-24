<?php
/**
 * LegalTitlesFixTest — LEGAL-TITLES-FIX-2026-10-24.
 *
 * Bug reportado por el operador: en las páginas de Política de Privacidad
 * y Términos y Condiciones los títulos se repetían (H1 ×2 en el render).
 *
 * Root cause: el ACTIVATOR creaba esas páginas con contenido que empieza
 * con su PROPIO <h1> ("Términos y Condiciones" / "Política de Privacidad")
 * mientras el tema/Elementor ya renderiza el título de la página
 * (post_title) → el visitante veía el título dos veces.
 *
 * Fix (ambos frentes):
 *  1. Fuente: class-ltms-activator.php ya NO emite <h1> en el contenido de
 *     las páginas legales (fresh installs no regrecen).
 *  2. Datos: deploy/ltms-fix-legal-titles-2026-10-24.php quita el <h1>
 *     inicial del contenido YA publicado (targets por ltms_installed_pages
 *     + twins por slug; idempotente — solo toca el primer <h1>).
 *
 * @package LTMS\Tests\Unit
 * @group legal-titles
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class LegalTitlesFixTest
 *
 * @group legal-titles
 */
final class LegalTitlesFixTest extends LTMS_Unit_Test_Case {

	private string $activator_path;
	private string $fixer_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->activator_path = dirname( __DIR__, 2 ) . '/includes/core/services/class-ltms-activator.php';
		$this->fixer_path     = dirname( __DIR__, 2 ) . '/deploy/ltms-fix-legal-titles-2026-10-24.php';
		$this->webhook_path   = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * LEGAL-TITLES-A: el activator ya NO emite <h1> en el contenido de las
	 * páginas legales — el título de la página (post_title) es el único H1.
	 */
	public function test_001_activator_sin_h1_en_contenido(): void {
		$this->assertFileExists( $this->activator_path );
		$src = (string) file_get_contents( $this->activator_path );

		// El bloque de páginas legales ya no lleva <h1> propio.
		$this->assertStringNotContainsString(
			'<h1>Términos y Condiciones</h1>',
			$src,
			'LEGAL-TITLES-A: el contenido de la página de Términos no debe llevar <h1> propio (el tema ya renderiza post_title → título duplicado).'
		);
		$this->assertStringNotContainsString(
			'<h1>Política de Privacidad</h1>',
			$src,
			'LEGAL-TITLES-A: el contenido de la página de Privacidad no debe llevar <h1> propio.'
		);
		// El contenido empieza directo con el <p> introductorio.
		$this->assertStringContainsString(
			"'content' => \"<p>Al registrarte como vendedor",
			$src,
			'LEGAL-TITLES-A: el contenido de Términos arranca con el <p> introductorio (sin h1).'
		);
		$this->assertStringContainsString(
			"'content' => \"<p>Lo Tengo cumple con la Ley 1581",
			$src,
			'LEGAL-TITLES-A: el contenido de Privacidad arranca con el <p> introductorio (sin h1).'
		);
		// Trazabilidad del fix.
		$this->assertStringContainsString( 'LEGAL-TITLES-FIX-2026-10-24', $src, 'LEGAL-TITLES-A: el fix lleva su tag de trazabilidad en el activator.' );
	}

	/**
	 * LEGAL-TITLES-B: el fixer de datos es idempotente y de alcance acotado —
	 * solo quita el PRIMER <h1> de páginas legales ya publicadas.
	 */
	public function test_002_fixer_idempotente_y_acotado(): void {
		$this->assertFileExists( $this->fixer_path );
		$src = (string) file_get_contents( $this->fixer_path );

		// Solo el primer <h1> (limit=1) — el resto del contenido intacto.
		$this->assertStringContainsString(
			"preg_replace( '/^\\s*<h1[^>]*>.*?<\\/h1>\\s*/is', '', \$content, 1 )",
			$src,
			'LEGAL-TITLES-B: el fixer solo elimina el primer <h1> inicial (idempotente, alcance acotado).'
		);
		// Targets: las páginas enlazadas (installed) + twins por slug.
		$this->assertStringContainsString( "'ltms-terms'", $src, 'LEGAL-TITLES-B: el fixer corrige la página de Términos registrada en ltms_installed_pages (la que la home enlaza).' );
		$this->assertStringContainsString( "'ltms-privacy'", $src, 'LEGAL-TITLES-B: el fixer corrige la página de Privacidad registrada.' );
		$this->assertStringContainsString( 'get_page_by_path', $src, 'LEGAL-TITLES-B: el fixer también cubre las twins por slug.' );
		// Guard: solo páginas de tipo page.
		$this->assertStringContainsString( "'page' !== \$post->post_type", $src, 'LEGAL-TITLES-B: el fixer solo toca páginas de tipo page.' );
	}

	/**
	 * LEGAL-TITLES-C: whitelist del deploy webhook (lección #177) — el
	 * activator (modificado) y el fixer + este test viajan con el fix.
	 */
	public function test_003_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString(
			"'includes/core/services/class-ltms-activator.php',",
			$webhook,
			'LEGAL-TITLES-C: el activator (modificado) debe estar en la whitelist del webhook.'
		);
		$this->assertStringContainsString(
			"'deploy/ltms-fix-legal-titles-2026-10-24.php',",
			$webhook,
			'LEGAL-TITLES-C: el fixer de datos debe estar en la whitelist del webhook.'
		);
		$this->assertStringContainsString(
			"'tests/unit/LegalTitlesFixTest.php',",
			$webhook,
			'LEGAL-TITLES-C: este test debe estar en la whitelist del webhook (lección #177).'
		);
	}
}
