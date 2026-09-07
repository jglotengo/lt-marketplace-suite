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
	private const JS_PATH      = __DIR__ . '/../../assets/js/ltms-login-register.js';
	private const FORM_LOGIN   = __DIR__ . '/../../includes/frontend/views/vendor-parts/form-login.php';

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

	public function test_fresh_nonce_endpoint_registered(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		// LOGIN-NONCE-FRESH (2026-09-07): endpoint que devuelve un nonce ltms_auth_nonce
		// RECIEN generado para que el JS no dependa del nonce del HTML (que puede estar
		// stale por cache / otra pestana logueada -> "Sesion expirada" con credenciales
		// correctas).
		$this->assertStringContainsString(
			"add_action( 'wp_ajax_nopriv_ltms_auth_nonce',    [ \$instance, 'ajax_fresh_auth_nonce' ] );",
			$src,
			'LOGIN-NONCE-FRESH: el endpoint de nonce fresco debe registrarse para nopriv.'
		);
		$this->assertStringContainsString(
			"add_action( 'wp_ajax_ltms_auth_nonce',           [ \$instance, 'ajax_fresh_auth_nonce' ] );",
			$src,
			'LOGIN-NONCE-FRESH: el endpoint de nonce fresco debe registrarse para priv.'
		);
		$this->assertStringContainsString(
			'public function ajax_fresh_auth_nonce(): void',
			$src,
			'LOGIN-NONCE-FRESH: debe existir el metodo ajax_fresh_auth_nonce.'
		);
		$this->assertStringContainsString(
			"wp_send_json_success( [ 'nonce' => wp_create_nonce( 'ltms_auth_nonce' ) ] );",
			$src,
			'LOGIN-NONCE-FRESH: el metodo debe responder con un nonce ltms_auth_nonce fresco.'
		);
	}

	public function test_login_js_uses_fresh_nonce_and_fallback_url(): void {
		$src = file_get_contents( self::JS_PATH );

		// LOGIN-NONCE-FRESH: el JS debe obtener un nonce fresco antes del submit.
		$this->assertStringContainsString(
			'function ltmsGetAuthNonce()',
			$src,
			'LOGIN-NONCE-FRESH: el JS debe tener el helper ltmsGetAuthNonce.'
		);
		$this->assertStringContainsString(
			"body: 'action=ltms_auth_nonce'",
			$src,
			'LOGIN-NONCE-FRESH: ltmsGetAuthNonce debe llamar al endpoint ltms_auth_nonce.'
		);
		$this->assertStringContainsString(
			'loginData.append(\'nonce\', await ltmsGetAuthNonce());',
			$src,
			'LOGIN-NONCE-FRESH: el submit del login debe usar el nonce fresco.'
		);
		// AJAX-FALLBACK: si el endpoint primario devuelve HTML (WAF) se reintenta admin-ajax.
		$this->assertStringContainsString(
			'function ltmsPostJson(urls, body)',
			$src,
			'LOGIN-NONCE-FRESH: debe existir el helper ltmsPostJson con reintento.'
		);
		$this->assertStringContainsString(
			"urls.push('/wp-admin/admin-ajax.php');",
			$src,
			'LOGIN-NONCE-FRESH: el submit debe incluir admin-ajax.php como URL de fallback.'
		);
		$this->assertStringContainsString(
			"if (d && typeof d.success !== 'undefined') return d;",
			$src,
			'LOGIN-NONCE-FRESH: solo se reintenta si el primario NO devolvio JSON valido del backend.'
		);
	}

	public function test_register_js_uses_fresh_nonce(): void {
		$src = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			'formData.append(\'nonce\', await ltmsGetAuthNonce());',
			$src,
			'LOGIN-NONCE-FRESH: el submit del registro tambien debe usar el nonce fresco.'
		);
	}

	public function test_resend_verification_script_uses_fresh_nonce(): void {
		$src = file_get_contents( self::FORM_LOGIN );

		$this->assertStringContainsString(
			"body:'action=ltms_auth_nonce'",
			$src,
			'LOGIN-NONCE-FRESH: el script inline de reenvio de verificacion debe pedir nonce fresco antes de enviar.'
		);
		$this->assertStringContainsString(
			'ltms_auth_nonce',
			$src,
			'LOGIN-NONCE-FRESH: el reenvio usa el endpoint ltms_auth_nonce.'
		);
	}
}