<?php
/**
 * HeaderNavFixTest - HEADER-NAV-FIX (2026-09-04).
 *
 * 1. El boton "Vender" (y "Mi Cuenta") se ocultaba para vendors logueados:
 *    buildSellerBtn() reemplazaba el boton VENDER por el chip de cuenta.
 *    Fix: el boton VENDER ahora es SIEMPRE visible; el chip de cuenta va aparte
 *    via buildClienteBtn() cuando hay sesion (vendor o cliente).
 *
 * 2. El dropdown del usuario logueado podia quedar recortado por overflow:hidden
 *    de menus de Elementor (nav-menu/icon-list) -> opciones "no llevan a nada".
 *    Fix: CSS fuerza overflow:visible en los contenedores LTMS y sus ancestros.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class HeaderNavFixTest extends LTMS_Unit_Test_Case {

	private const JS_PATH  = __DIR__ . '/../../assets/js/ltms-header-nav.js';
	private const CSS_PATH = __DIR__ . '/../../assets/css/ltms-header-nav.css';

	public function test_seller_button_always_renders_vender(): void {
		$src = file_get_contents( self::JS_PATH );

		// buildSellerBtn ya no tiene la rama is_vendor que devolvia el chip (ocultaba Vender).
		$this->assertStringContainsString(
			'class="ltms-nav-btn ltms-btn-seller"',
			$src,
			'HEADER-NAV-FIX: buildSellerBtn debe devolver el boton Vender (ltms-btn-seller).'
		);
		$this->assertStringNotContainsString(
			'var d = ltmsHeaderNav;
        if (d.is_vendor) {
            return \'<div class="ltms-user-dropdown-wrap" id="ltms-vendor-chip-wrap">\'',
			$src,
			'HEADER-NAV-FIX: la rama is_vendor que reemplazaba Vender por el chip debe haber sido eliminada.'
		);
	}

	public function test_logged_in_chip_moved_to_cliente_btn(): void {
		$src = file_get_contents( self::JS_PATH );

		// El chip de cuenta (con menu de rol) se construye en buildClienteBtn cuando hay sesion.
		$this->assertStringContainsString(
			'if (d.is_logged_in) {',
			$src,
			'HEADER-NAV-FIX: buildClienteBtn debe manejar el caso de sesion (chip de cuenta).'
		);
		// El menu del vendor incluye los enlaces del panel.
		$this->assertStringContainsString(
			'Mi Panel',
			$src,
			'HEADER-NAV-FIX: el menu del vendor debe incluir "Mi Panel".'
		);
		$this->assertStringContainsString(
			'Verificación KYC',
			$src,
			'HEADER-NAV-FIX: el menu del vendor debe incluir "Verificación KYC".'
		);
	}

	public function test_dropdown_anti_clipping_css(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'overflow: visible !important;',
			$css,
			'HEADER-NAV-FIX: el CSS debe forzar overflow:visible para evitar el clipping del dropdown.'
		);
		$this->assertStringContainsString(
			'li.ltms-menu-item',
			$css,
			'HEADER-NAV-FIX: el selector li.ltms-menu-item debe existir en el CSS.'
		);
	}

	public function test_min_files_regenerated(): void {
		$min_js  = file_get_contents( __DIR__ . '/../../assets/js/ltms-header-nav.min.js' );
		$min_css = file_get_contents( __DIR__ . '/../../assets/css/ltms-header-nav.min.css' );

		// El min JS debe contener el boton seller y el min CSS el anti-clipping.
		$this->assertStringContainsString( 'ltms-btn-seller', $min_js, 'HEADER-NAV-FIX: el .min.js debe contener ltms-btn-seller.' );
		$this->assertStringContainsString( 'ltms-user-dropdown', $min_css, 'HEADER-NAV-FIX: el .min.css debe contener ltms-user-dropdown.' );
	}
}