<?php
/**
 * LoginErrorClarityTest - LOGIN-ERR-CLARITY (2026-09-04).
 *
 * El formulario /login-vendedor/ mostraba "Usuario o contraseña incorrectos."
 * aunque las credenciales fueran correctas cuando el nonce fallaba:
 *   - check_ajax_referer con die=true devolvia "-1" (texto plano) y el JS
 *     (ltms-login-register.js) caia al mensaje generico de credenciales.
 *   - Causa tipica: pagina cacheada por SG con un ltmsAuth.nonce stale, o el
 *     usuario logueado en otra pestana cargando una pagina cacheada con nonce
 *     de guest.
 * Fix:
 *   - ajax_vendor_login usa check_ajax_referer(..., false) + wp_send_json_error
 *     con mensaje claro "La sesión expiró..." (403) en vez de "-1".
 *   - render_login_form llama nocache_headers() para que SG no cachee el nonce.
 *
 * Tests source-based (patrón C20-C29): file_get_contents + asserts.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class LoginErrorClarityTest extends LTMS_Unit_Test_Case {

	private const HANDLER_PATH = __DIR__ . '/../../includes/frontend/class-ltms-public-auth-handler.php';

	public function test_login_nonce_failure_returns_clear_json_error(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		$pos = strpos( $src, 'public function ajax_vendor_login(): void' );
		$this->assertNotFalse( $pos, 'ajax_vendor_login debe existir.' );
		$block = substr( $src, $pos, 1500 );

		// check_ajax_referer con die=false (no "-1").
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_auth_nonce', 'nonce', false )",
			$block,
			'LOGIN-ERR-CLARITY: ajax_vendor_login debe usar check_ajax_referer con die=false.'
		);
		// Mensaje claro en JSON en vez de "-1".
		$this->assertStringContainsString(
			'La sesión expiró',
			$block,
			'LOGIN-ERR-CLARITY: el fallo de nonce debe devolver el mensaje de sesión expirada.'
		);
		$this->assertStringContainsString(
			'wp_send_json_error',
			$block,
			'LOGIN-ERR-CLARITY: el fallo de nonce debe responder con wp_send_json_error.'
		);
	}

	public function test_login_form_sends_nocache_headers(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		$pos = strpos( $src, 'public function render_login_form( array $atts = [] ): string' );
		$this->assertNotFalse( $pos, 'render_login_form debe existir.' );
		$block = substr( $src, $pos, 500 );

		$this->assertStringContainsString(
			'nocache_headers();',
			$block,
			'LOGIN-ERR-CLARITY: render_login_form debe enviar nocache_headers() para evitar nonce stale.'
		);
	}
}