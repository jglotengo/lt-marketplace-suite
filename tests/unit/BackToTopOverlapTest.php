<?php
/**
 * BackToTopOverlapTest - BACK-TO-TOP-OVERLAP (2026-09-12).
 *
 * El botón flotante "volver arriba" (.ltms-back-to-top, flecha ↑) se renderizaba
 * en la misma esquina inferior-derecha que el botón de soporte (.ltms-live-chat),
 * sobreponiéndose. Fix: la posición (right/bottom) se saca del inline-style del JS
 * a ltms-ux-enhancements.css y se apila POR ENCIMA del chat: desktop bottom:88px
 * (chat en 24px), móvil bottom:152px (chat en 88px por la sticky cart).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class BackToTopOverlapTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-backtotop
 *
 * @group audit-backtotop
 */
final class BackToTopOverlapTest extends LTMS_Unit_Test_Case {

	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-ux-enhancements.js';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-ux-enhancements.css';
	private const CSS_MIN  = __DIR__ . '/../../assets/css/ltms-ux-enhancements.min.css';

	public function test_js_moves_position_to_css(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString( 'BACK-TO-TOP-OVERLAP FIX', $js, 'BACK-TO-TOP: la traza del fix debe estar en el JS.' );
		$this->assertStringContainsString( 'position: fixed;', $js, 'BACK-TO-TOP: el botón debe seguir posicionándose fixed.' );
		// bottom/right ya no viven en el inline-style (pasaron a CSS): entre
		// position:fixed y width:44px no debe haber bottom/right intermedios.
		$this->assertMatchesRegularExpression(
			'/position:\s*fixed;\s*width:\s*44px;/',
			$js,
			'BACK-TO-TOP: el inline-style no debe fijar bottom/right (pasó a CSS responsivo).'
		);
	}

	public function test_css_stacks_above_chat(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString( 'BACK-TO-TOP-OVERLAP FIX', $css, 'BACK-TO-TOP: la traza del fix debe estar en el CSS.' );
		$this->assertStringContainsString(
			".ltms-back-to-top {\n    position: fixed;\n    right: 24px;\n    bottom: 88px;\n}",
			$css,
			'BACK-TO-TOP: desktop debe posicionarse en bottom:88px, por encima del chat (24px).'
		);
		$this->assertStringContainsString(
			'bottom: 152px',
			$css,
			'BACK-TO-TOP: móvil debe subir a bottom:152px (por encima del chat móvil en 88px).'
		);
	}

	public function test_min_css_regenerated(): void {
		$min = file_get_contents( self::CSS_MIN );

		$this->assertStringContainsString( '.ltms-back-to-top', $min, 'BACK-TO-TOP: el .min.css debe contener .ltms-back-to-top.' );
		$this->assertStringContainsString( 'bottom:88px', $min, 'BACK-TO-TOP: el .min.css debe regenerar bottom:88px.' );
		$this->assertStringContainsString( 'bottom:152px', $min, 'BACK-TO-TOP: el .min.css debe regenerar bottom:152px (móvil).' );
	}
}