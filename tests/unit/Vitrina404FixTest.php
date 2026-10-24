<?php
/**
 * Vitrina404FixTest — VITRINA-404-FIX-2026-10-24.
 *
 * Bug P0 hallado en producción tras FOOTER-GLOBAL-2026-10-24 (verificación
 * multi-página): /vendedor/{slug-desconocido}/ devolvía HTTP 500 con 0 bytes
 * y sin traza en error_log.
 *
 * Root cause (verificado en vivo con repro en-proceso + try/catch):
 *   LTMS_Vendor_Storefront::maybe_render(), rama `! $vendor`, hacía
 *   `include get_404_template();` — pero el tema activo (hello-elementor)
 *   NO trae 404.php → get_404_template() devuelve '' → `include ''` lanza
 *   "ValueError: Path cannot be empty" en PHP 8 → fatal silencioso
 *   (500, 0 bytes, nada en error_log). Afectaba a CUALQUIER slug
 *   desconocido y a los huérfanos (usuarios sin rol ltms_vendor que
 *   conservan la meta ltms_store_slug — ej. user #15 'juan-guillermo',
 *   cuenta admin de pruebas con roles vacíos).
 *
 * Fix: la rama not-found hace `return;` y deja que el template-loader de
 * WordPress resuelva la jerarquía NATIVA del 404 (404.php → index.php →
 * Elementor Theme Builder 404). WP::main()->handle_404() ya marcó is_404
 * y send_headers() ya envió el status 404 ANTES de template_redirect
 * (mismo hecho que el código documenta en la rama del vendor ENCONTRADO,
 * donde se revierte explícitamente).
 *
 * @package LTMS\Tests\Unit
 * @group storefront-404
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class Vitrina404FixTest
 *
 * @group storefront-404
 */
final class Vitrina404FixTest extends LTMS_Unit_Test_Case {

	private string $storefront_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->storefront_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-vendor-storefront.php';
		$this->webhook_path    = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * VITRINA-404-A: la rama not-found de maybe_render hace return — NUNCA
	 * include directo de get_404_template() (ValueError en PHP 8 si el tema
	 * no trae 404.php, como hello-elementor).
	 */
	public function test_001_rama_404_hace_return_sin_include(): void {
		$this->assertFileExists( $this->storefront_path );
		$src = $this->strip_php_comments( (string) file_get_contents( $this->storefront_path ) );

		// La rama not-found termina en return, sin exit/include.
		$this->assertMatchesRegularExpression(
			'/\$vendor = self::get_vendor_by_slug\( \$slug \);\s*\n\s*if \( ! \$vendor \) \{\s*\n\s*return;\s*\n\s*\}/',
			$src,
			'VITRINA-404-A: la rama `! $vendor` de maybe_render debe hacer return (el flujo 404 nativo de WP ya está seteado).'
		);
		// Prohibido reincroducir el include directo del template de 404.
		$this->assertStringNotContainsString(
			'include get_404_template(',
			$src,
			'VITRINA-404-A: prohibido include get_404_template() — con temas sin 404.php devuelve string vacío y PHP 8 lanza ValueError (fatal 500 silencioso en producción).'
		);
		// El branch documenta el fix con su tag de trazabilidad.
		$raw = (string) file_get_contents( $this->storefront_path );
		$this->assertStringContainsString( 'VITRINA-404-FIX-2026-10-24', $raw, 'VITRINA-404-A: el fix lleva su tag de trazabilidad.' );
		$this->assertStringContainsString( 'ValueError: Path cannot be empty', $raw, 'VITRINA-404-A: el comentario documenta el root cause (ValueError de PHP 8 con path vacío).' );
	}

	/**
	 * VITRINA-404-B: la rama del vendor ENCONTRADO sigue revirtiendo el 404
	 * que WP fijó (is_404=false + status 200) — el fix no la tocó.
	 */
	public function test_002_rama_vendor_encontrado_sigue_reviertiendo_404(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->storefront_path ) );

		$this->assertMatchesRegularExpression(
			'/\$wp_query->is_404 = false;/',
			$src,
			'VITRINA-404-B: cuando el vendor SÍ existe, maybe_render sigue revirtiendo el estado 404 de WP.'
		);
		$this->assertMatchesRegularExpression(
			'/status_header\( 200 \);/',
			$src,
			'VITRINA-404-B: el status 200 se fija para las vitrinas encontradas.'
		);
		$this->assertMatchesRegularExpression(
			'/self::render\( \$vendor \);\s*\n\s*exit;/',
			$src,
			'VITRINA-404-B: el render de la vitrina encontrada se mantiene (render + exit).'
		);
	}

	/**
	 * VITRINA-404-C: whitelist del deploy webhook (lección #177) — la clase
	 * del storefront ya estaba; este test (nuevo) debe viajar con el fix.
	 */
	public function test_003_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString(
			"'includes/frontend/class-ltms-vendor-storefront.php',",
			$webhook,
			'VITRINA-404-C: la clase del storefront debe estar en la whitelist del webhook (ya estaba — se vigila).'
		);
		$this->assertStringContainsString(
			"'tests/unit/Vitrina404FixTest.php',",
			$webhook,
			'VITRINA-404-C: este test debe estar en la whitelist del webhook (lección #177: la whitelist es inventario vivo).'
		);
	}
}
