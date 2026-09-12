<?php
/**
 * PanelContrastTableResponsiveTest - PANEL-CONTRAST-3 + PANEL-TABLES-RESPONSIVE (2026-09-12).
 *
 * Dos fixes del panel del vendedor (dashboard SPA):
 *
 *  1. PANEL-CONTRAST-3 — el blanket `color:inherit!important` del bloque crítico
 *     inline (dashboard-wrapper.php) forzaba a heredar #2c3e50 incluso en banners
 *     y contenedores de COLOR (ej. "¡Programa ReDi disponible!" #1A1A4E, wallet,
 *     balance), grisando el texto blanco y dejándolo ilegible sobre fondo azul.
 *     El fix elimina el sledgehammer y oscurece solo el texto estructural de las
 *     zonas claras.
 *
 *  2. PANEL-TABLES-RESPONSIVE — `.ltms-table-responsive` (usado por view-drivers,
 *     view-insurance y view-redi) no tenía overflow-x, así que sus tablas
 *     desbordaban la card sin scroll en móvil. Se unifica con los wrappers
 *     scrolleables. Además se envuelve la tabla "Mis Depósitos" (view-wallet)
 *     en un contenedor scrolleable.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PanelContrastTableResponsiveTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-panel-mobile
 *
 * @group audit-panel-mobile
 */
final class PanelContrastTableResponsiveTest extends LTMS_Unit_Test_Case {

	private const WRAPPER_PATH = __DIR__ . '/../../includes/frontend/views/dashboard-wrapper.php';
	private const WALLET_PATH  = __DIR__ . '/../../includes/frontend/views/view-wallet.php';
	private const CSS_PATH     = __DIR__ . '/../../assets/css/ltms-dashboard.css';
	private const MIN_PATH     = __DIR__ . '/../../assets/css/ltms-dashboard.min.css';

	/* ── PANEL-CONTRAST-3 ─────────────────────────────────────────── */

	public function test_blanket_color_inherit_rule_removed(): void {
		$src = file_get_contents( self::WRAPPER_PATH );

		// La declaración `color:inherit!important;` (con ;) era el sledgehammer que
		// grisaba el texto blanco. El comment lo menciona en backticks sin el ';'.
		$this->assertStringNotContainsString(
			'color:inherit!important;',
			$src,
			'PANEL-CONTRAST-3: el blanket color:inherit!important debe eliminarse (grisaba texto blanco sobre azul).'
		);
		$this->assertStringContainsString(
			'PANEL-CONTRAST-3 FIX',
			$src,
			'PANEL-CONTRAST-3: el fix debe tener su marcador traceable en dashboard-wrapper.php.'
		);
	}

	public function test_scoped_structural_heading_rule_added(): void {
		$src = file_get_contents( self::WRAPPER_PATH );

		$this->assertStringContainsString(
			'.ltms-main-content h1',
			$src,
			'PANEL-CONTRAST-3: debe oscurecerse el texto estructural de las zonas claras, sin pisar color:#fff.'
		);
		$this->assertStringContainsString(
			'.ltms-main-content h6',
			$src,
			'PANEL-CONTRAST-3: la regla scoped debe cubrir h1-h6 dentro del contenido principal.'
		);
	}

	/* ── PANEL-TABLES-RESPONSIVE ──────────────────────────────────── */

	public function test_table_responsive_class_has_horizontal_scroll(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'PANEL-TABLES-RESPONSIVE FIX',
			$css,
			'PANEL-TABLES-RESPONSIVE: el fix debe tener su marcador traceable en ltms-dashboard.css.'
		);
		$this->assertStringContainsString(
			'.ltms-table-responsive',
			$css,
			'PANEL-TABLES-RESPONSIVE: el selector .ltms-table-responsive debe estilarse.'
		);
		$this->assertStringContainsString(
			'.ltms-table-scroll,',
			$css,
			'PANEL-TABLES-RESPONSIVE: el wrapper scrollable debe seguir listado con overflow-x:auto.'
		);
		$this->assertStringContainsString(
			'overflow-x: auto;',
			$css,
			'PANEL-TABLES-RESPONSIVE: los wrappers de tabla deben scrollear horizontalmente.'
		);
	}

	public function test_min_css_regenerated_with_table_responsive(): void {
		$min = file_get_contents( self::MIN_PATH );

		$this->assertStringContainsString(
			'.ltms-table-responsive',
			$min,
			'PANEL-TABLES-RESPONSIVE: el .min.css debe regenerarse con el selector .ltms-table-responsive.'
		);
	}

	public function test_wallet_deposit_table_wrapped_in_scroll_container(): void {
		$src = file_get_contents( self::WALLET_PATH );

		// "Últimos Movimientos" y "Mis Depósitos" deben estar ambos en un wrapper
		// scrolleable; antes "Mis Depósitos" usaba .ltms-card-body a secas.
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $src, 'ltms-card-body ltms-table-scroll' ),
			'PANEL-TABLES-RESPONSIVE: la tabla "Mis Depósitos" debe quedar en un contenedor scrolleable.'
		);
	}
}