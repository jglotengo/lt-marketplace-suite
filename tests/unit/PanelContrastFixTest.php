<?php
/**
 * PanelContrastFixTest - PANEL-CONTRAST FIX (2026-09-11).
 *
 * El panel del vendedor (dashboard SPA) tiene un sidebar y un topbar azules
 * (#1a5276 vía --ltms-primary). El texto de los items de navegación usaba
 * `.ltms-nav-label { color: #9ca3af }` (gris) y las etiquetas de sección
 * `.ltms-nav-section-label { color: rgba(255,255,255,0.4) }`, ambas ilegibles
 * sobre fondo azul. PANEL-CONTRAST subió a blanco / rgba(.72) pero los títulos
 * de submenú (sección) y el color base del item todavía se leían grises;
 * PANEL-CONTRAST-2 los fuerza a blanco pleno (#fff) tanto en el source CSS
 * como en el bloque inline crítico `ltms-dash-critical` (que tiene prioridad
 * sobre el CSS externo cuando carga con caché).
 *
 * Tests source-based (file_get_contents + asserts).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PanelContrastFixTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-panel-contrast
 *
 * @group audit-panel-contrast
 */
final class PanelContrastFixTest extends LTMS_Unit_Test_Case {

	private const CSS_PATH     = __DIR__ . '/../../assets/css/ltms-dashboard.css';
	private const MIN_PATH     = __DIR__ . '/../../assets/css/ltms-dashboard.min.css';
	private const WRAPPER_PATH = __DIR__ . '/../../includes/frontend/views/dashboard-wrapper.php';

	public function test_nav_label_not_gray_on_blue(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'PANEL-CONTRAST FIX',
			$css,
			'PANEL-CONTRAST: el fix debe tener su marcador traceable en ltms-dashboard.css.'
		);
		$this->assertStringContainsString(
			".ltms-nav-label {\n    font-size: 0.75rem;\n    color: #fff;",
			$css,
			'PANEL-CONTRAST: la etiqueta del item de nav debe ser blanca (antes gris #9ca3af) sobre el sidebar azul.'
		);
	}

	public function test_section_label_legible(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			"color: #fff;\n    text-transform: uppercase;\n    letter-spacing: 0.08em;\n    padding: 12px 20px 4px;",
			$css,
			'PANEL-CONTRAST-2: la etiqueta de sección (título de submenú) debe ser blanca plena, no gris.'
		);
	}

	public function test_nav_item_base_color_white(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			".ltms-sidebar-nav .ltms-nav-item {\n    display: flex;\n    align-items: center;\n    gap: 10px;\n    padding: 11px 20px;\n    color: #fff;",
			$css,
			'PANEL-CONTRAST-2: el color base del item de nav (y su icono por herencia) debe ser blanco, no rgba(.8).'
		);
	}

	public function test_inline_critical_forces_white(): void {
		$src = file_get_contents( self::WRAPPER_PATH );

		$this->assertStringContainsString(
			'PANEL-CONTRAST-2 FIX',
			$src,
			'PANEL-CONTRAST-2: el fix inline debe estar marcado en dashboard-wrapper.php.'
		);
		$this->assertStringContainsString(
			'.ltms-nav-item .ltms-nav-label',
			$src,
			'PANEL-CONTRAST: el inline crítico debe forzar color en .ltms-nav-item .ltms-nav-label.'
		);
		$this->assertStringContainsString(
			'color:#fff!important',
			$src,
			'PANEL-CONTRAST: el texto del item de nav debe forzarse blanco con !important.'
		);
		$this->assertStringContainsString(
			'.ltms-nav-section-label{color:#fff!important;}',
			preg_replace( '/\s+/', '', $src ),
			'PANEL-CONTRAST-2: la etiqueta de sección debe forzarse blanca plena en el inline crítico.'
		);
	}

	public function test_min_css_regenerated(): void {
		$min = file_get_contents( self::MIN_PATH );

		$this->assertStringContainsString(
			'.ltms-nav-label',
			$min,
			'PANEL-CONTRAST: el .min.css debe contener el selector .ltms-nav-label.'
		);
		$this->assertStringContainsString(
			'.ltms-nav-section-label',
			$min,
			'PANEL-CONTRAST-2: el .min.css debe contener el selector .ltms-nav-section-label.'
		);
		$this->assertStringContainsString(
			'.ltms-nav-section-label{font-size:.65rem;font-weight:700;color:#fff',
			$min,
			'PANEL-CONTRAST-2: el .min.css debe tener la etiqueta de sección regenerada en blanco (#fff).'
		);
	}
}