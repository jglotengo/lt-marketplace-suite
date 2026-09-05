<?php
/**
 * SellersLandingUxTest - SELLERS-LANDING + WELCOME-POPUP-TEXT (2026-09-04).
 *
 * 1. SELLERS-LANDING-CENTER (P1): la landing /sellers/ quedaba alineada a la
 *    izquierda en desktop (un reset global *{margin:0;padding:0} ganaba sobre
 *    el margin:0 auto). Fix: centrado con !important + padding.
 * 2. WELCOME-POPUP-TEXT (P1): el popup de bienvenida (modal de newsletter en
 *    ltms-ux-enhancements.js) ofrecia "10% de descuento" y mostraba el codigo
 *    BIENVENIDO10 al suscribirse. Fix: texto sin descuento (solo novedades y
 *    ofertas), sin codigo, sin boton "Copiar codigo".
 * 3. SELLERS-LANDING-UX (P2): trust bar del hero + iconos de cards + calculadora.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class SellersLandingUxTest extends LTMS_Unit_Test_Case {

	private const LANDING_PATH = __DIR__ . '/../../includes/frontend/views/view-sellers-landing.php';
	private const FE_CSS_PATH  = __DIR__ . '/../../assets/css/ltms-frontend-extensions.css';
	private const UX_JS_PATH   = __DIR__ . '/../../assets/js/ltms-ux-enhancements.js';

	public function test_landing_container_centered_with_important(): void {
		$css = file_get_contents( self::FE_CSS_PATH );

		$this->assertStringContainsString(
			'margin-left: auto !important;',
			$css,
			'SELLERS-LANDING-CENTER: el contenedor debe forzar margin-left auto con !important.'
		);
		$this->assertStringContainsString(
			'margin-right: auto !important;',
			$css,
			'SELLERS-LANDING-CENTER: el contenedor debe forzar margin-right auto con !important.'
		);
	}

	public function test_hero_trust_row_rendered(): void {
		$src = file_get_contents( self::LANDING_PATH );

		$this->assertStringContainsString(
			'ltms-sl-hero__trust',
			$src,
			'SELLERS-LANDING-UX: el template debe renderizar el trust bar del hero.'
		);
		$this->assertStringContainsString(
			'Sin mensualidades',
			$src,
			'SELLERS-LANDING-UX: el trust bar debe incluir "Sin mensualidades".'
		);
	}

	public function test_welcome_popup_no_discount_offer(): void {
		$src = file_get_contents( self::UX_JS_PATH );

		// Acota al modal de newsletter (initNewsletterSignup): extrae la funcion
		// y verifica que no ofrezca descuento. (El codigo QUEDATE10 de otra
		// feature usa "10% de descuento" — no se toca.)
		$pos = strpos( $src, 'function initNewsletterSignup()' );
		$this->assertNotFalse( $pos, 'initNewsletterSignup debe existir.' );
		$block = substr( $src, $pos, 2500 );

		$this->assertStringNotContainsString( '10% de descuento', $block );
		$this->assertStringNotContainsString( 'Quiero mi descuento', $block );
		$this->assertStringNotContainsString( 'Descuento inmediato', $block );
		// No muestra codigo de descuento en el exito.
		$this->assertStringNotContainsString( 'BIENVENIDO10', $block );
		$this->assertStringNotContainsString( 'Copiar código', $block );
		$this->assertStringNotContainsString( 'ltms-newsletter-code', $block );
	}

	public function test_welcome_popup_updated_copy(): void {
		$src = file_get_contents( self::UX_JS_PATH );

		// El texto nuevo enfatiza novedades y ofertas (no descuento).
		$this->assertStringContainsString(
			'ofertas exclusivas',
			$src,
			'WELCOME-POPUP-TEXT: el popup debe ofrecer ofertas exclusivas (sin descuento).'
		);
		$this->assertStringContainsString(
			'Suscripción confirmada',
			$src,
			'WELCOME-POPUP-TEXT: el estado de exito debe confirmar la suscripcion.'
		);
	}

	public function test_min_files_regenerated(): void {
		$min_js  = file_get_contents( __DIR__ . '/../../assets/js/ltms-ux-enhancements.min.js' );
		$min_css = file_get_contents( __DIR__ . '/../../assets/css/ltms-frontend-extensions.min.css' );

		$this->assertStringNotContainsString( 'BIENVENIDO10', $min_js, 'WELCOME-POPUP-TEXT: el .min.js no debe contener BIENVENIDO10.' );
		$this->assertStringContainsString( 'margin-left:auto!important', $min_css, 'SELLERS-LANDING-CENTER: el .min.css debe tener el centrado !important.' );
	}
}