<?php
/**
 * HeaderWidthAuditTest - HEADER-WIDTH-2026-10-28.
 *
 * Cierre del ciclo de auditoria de ancho del header (checkpoint 2026-10-25):
 *  - Panel vendedor: la topbar de la app debe acaparar TODO el viewport en
 *    desktop. El sidebar arranca debajo de ella y el area de contenido se
 *    compensa con padding-top (decision del operador 2026-10-28).
 *  - Vitrina /vendedor/{slug}/: usa el header PV compartido (negro,
 *    full-width) via wp_body_open; la topbar blanca legacy queda oculta bajo
 *    body.pv-pv-header y solo sobrevive como respaldo si el header PV no
 *    renderiza.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true. Incluye el .min (regenerado con npm run build) para
 * que el fix no viaje solo en el archivo fuente.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HeaderWidthAuditTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group header-width
 *
 * @group header-width
 */
final class HeaderWidthAuditTest extends LTMS_Unit_Test_Case {

	private const DASH_CSS     = __DIR__ . '/../../assets/css/ltms-dashboard.css';
	private const DASH_CSS_MIN = __DIR__ . '/../../assets/css/ltms-dashboard.min.css';
	private const SF_PHP       = __DIR__ . '/../../includes/frontend/class-ltms-vendor-storefront.php';
	private const SF_CSS       = __DIR__ . '/../../assets/css/ltms-storefront.css';
	private const SF_CSS_MIN   = __DIR__ . '/../../assets/css/ltms-storefront.min.css';

	public function test_panel_topbar_es_full_width_en_desktop(): void {
		$css = file_get_contents( self::DASH_CSS );

		$this->assertStringContainsString(
			'HEADER-WIDTH-2026-10-28',
			$css,
			'HEADER-WIDTH: la traza del ciclo debe estar en el CSS.'
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(min-width:\s*769px\)\s*\{/',
			$css,
			'HEADER-WIDTH: el layout full-width aplica solo en desktop (<=768 conserva sidebar off-canvas).'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-dashboard-container\s*>\s*\.ltms-main-content\s*>\s*\.ltms-topbar\s*\{[^}]*position:\s*fixed\s*!important/s',
			$css,
			'HEADER-WIDTH: la topbar del panel sale del flujo y se fija al viewport.'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-dashboard-container\s*>\s*\.ltms-main-content\s*>\s*\.ltms-topbar\s*\{[^}]*left:\s*0\s*!important[^}]*right:\s*0\s*!important/s',
			$css,
			'HEADER-WIDTH: la topbar debe cruzar de borde a borde (left/right 0).'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-dashboard-container\s*>\s*\.ltms-sidebar\s*\{[^}]*top:\s*var\(--ltms-header-h\)\s*!important/s',
			$css,
			'HEADER-WIDTH: el sidebar debe arrancar debajo de la topbar, no detras.'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-dashboard-container\s*>\s*\.ltms-main-content\s*\{\s*padding-top:\s*var\(--ltms-header-h\)/',
			$css,
			'HEADER-WIDTH: el contenido compensa la altura de la topbar fija (sin solaparse).'
		);
	}

	public function test_panel_topbar_full_width_esta_en_el_min(): void {
		$min = file_get_contents( self::DASH_CSS_MIN );

		/* clean-css elimina comentarios: el .min se valida por reglas, no por el
		 * tag de trazabilidad (que solo vive en el .css fuente). */
		$this->assertStringContainsString(
			'@media (min-width:769px)',
			$min,
			'HEADER-WIDTH: el .min.css debe estar regenerado con npm run build.'
		);
		$this->assertStringContainsString(
			'.ltms-dashboard-container>.ltms-main-content>.ltms-topbar{position:fixed!important',
			$min,
			'HEADER-WIDTH: el .min.css debe incluir la topbar fija full-width.'
		);
	}

	public function test_vitrina_dispara_wp_body_open_antes_de_la_topbar_legacy(): void {
		$src = file_get_contents( self::SF_PHP );

		$this->assertMatchesRegularExpression(
			'/<body\s+<\?php\s+body_class\([^)]*\);\s*\?>\s*>\s*<\?php.*?wp_body_open\(\);/s',
			$src,
			'HEADER-WIDTH: la vitrina debe disparar wp_body_open al abrir el body (header PV compartido).'
		);
		$this->assertMatchesRegularExpression(
			'/wp_body_open\(\);.*?<header class="ltms-sf-topbar">/s',
			$src,
			'HEADER-WIDTH: el header PV se inyecta ANTES de la topbar legacy.'
		);
	}

	public function test_vitrina_oculta_topbar_legacy_cuando_hay_header_pv(): void {
		$src = file_get_contents( self::SF_PHP );

		$this->assertStringContainsString(
			'body.pv-pv-header .ltms-sf-topbar{display:none!important}',
			$src,
			'HEADER-WIDTH: el CSS critico inline debe ocultar la topbar legacy bajo el header PV.'
		);
	}

	public function test_css_externo_de_la_vitrina_sincronizado(): void {
		$css     = file_get_contents( self::SF_CSS );
		$css_min = file_get_contents( self::SF_CSS_MIN );

		$this->assertMatchesRegularExpression(
			'/body\.pv-pv-header\s+\.ltms-sf-topbar\s*\{\s*display:\s*none\s*!important;\s*\}/',
			$css,
			'HEADER-WIDTH: el CSS externo debe replicar la regla del bloque critico (AGENTS: sincronizar ambos).'
		);
		$this->assertStringContainsString(
			'body.pv-pv-header .ltms-sf-topbar{display:none!important}',
			$css_min,
			'HEADER-WIDTH: el .min.css de la vitrina debe estar regenerado.'
		);
	}

	public function test_whitelist_del_deploy_incluye_los_archivos_nuevos(): void {
		$whitelist = file_get_contents( __DIR__ . '/../../deploy/ltms-deploy-webhook.php' );

		$this->assertStringContainsString(
			"'assets/css/ltms-dashboard.css'",
			$whitelist,
			'HEADER-WIDTH: sin dashboard.css en la whitelist el fix del panel no llega a SG (leccion #177).'
		);
		$this->assertStringContainsString(
			"'assets/css/ltms-dashboard.min.css'",
			$whitelist,
			'HEADER-WIDTH: el .min.css del panel tambien debe viajar por el webhook.'
		);
		$this->assertStringContainsString(
			"'tests/unit/HeaderWidthAuditTest.php'",
			$whitelist,
			'HEADER-WIDTH: el test del ciclo debe estar en la whitelist.'
		);
	}
}
