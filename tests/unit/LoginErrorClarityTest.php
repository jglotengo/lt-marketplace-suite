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
	private const FORM_LOST_PASSWORD = __DIR__ . '/../../includes/frontend/views/vendor-parts/form-lost-password.php';
	private const ACTIVATOR_PATH     = __DIR__ . '/../../includes/core/services/class-ltms-activator.php';
	private const ADMIN_PAGES_PATH   = __DIR__ . '/../../includes/admin/views/html-admin-pages.php';
	private const REPAIR_PAGES_PATH  = __DIR__ . '/../../bin/ltms-repair-pages.php';

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

	public function test_password_reset_email_returns_vendor_to_vendor_login(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		// PASSWORD-RESET-RETURN (2026-09-07): el email de recuperacion del vendor
		// debe llevar redirect_to=<página de login LTMS> para que tras el reset el
		// vendor aterrice en /login-vendedor/ y no en wp-login.php (WC descarta el
		// redirect_to del enlace "¿Olvidaste tu contraseña?").
		$this->assertStringContainsString(
			"add_filter( 'retrieve_password_message', [ \$instance, 'vendor_reset_email_redirect' ], 10, 4 );",
			$src,
			'PASSWORD-RESET-RETURN: debe registrarse el filtro retrieve_password_message.'
		);
		$this->assertStringContainsString(
			'public function vendor_reset_email_redirect( $message, $key, $user_login, $user_data )',
			$src,
			'PASSWORD-RESET-RETURN: debe existir el metodo vendor_reset_email_redirect.'
		);
		$this->assertStringContainsString(
			"in_array( 'ltms_vendor', \$roles, true ) && ! in_array( 'ltms_vendor_premium', \$roles, true )",
			$src,
			'PASSWORD-RESET-RETURN: solo debe aplicar a vendors (rol-aware).'
		);
		// El link action=rp del email debe recibir redirect_to del mismo host.
		$this->assertStringContainsString(
			'#(https?://[^\s<]+action=rp[^\s<]*)#',
			$src,
			'PASSWORD-RESET-RETURN: el metodo debe localizar el link action=rp en el email.'
		);
		$this->assertStringContainsString(
			"'redirect_to=' . rawurlencode( \$login_url )",
			$src,
			'PASSWORD-RESET-RETURN: debe anexar redirect_to=<login_url> al link de reset.'
		);
	}

	public function test_lost_password_page_shortcode_and_ajax_registered(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		// LOST-PASSWORD-PAGE (2026-09-07): pagina propia de recuperacion con diseno
		// del login de vendedor (antes iba a /mi-cuenta/lost-password/ de WooCommerce).
		$this->assertStringContainsString(
			"add_shortcode( 'ltms_vendor_lost_password', [ \$instance, 'render_lost_password_form' ] );",
			$src,
			'LOST-PASSWORD-PAGE: debe registrarse el shortcode ltms_vendor_lost_password.'
		);
		$this->assertStringContainsString(
			"'wp_ajax_nopriv_ltms_vendor_lost_password', [ \$instance, 'ajax_vendor_lost_password' ]",
			$src,
			'LOST-PASSWORD-PAGE: el AJAX de recuperacion debe registrarse para nopriv.'
		);
		$this->assertStringContainsString(
			"'wp_ajax_ltms_vendor_lost_password',        [ \$instance, 'ajax_vendor_lost_password' ]",
			$src,
			'LOST-PASSWORD-PAGE: el AJAX de recuperacion debe registrarse para priv.'
		);
		// La pagina debe renderizarse con el bypass de template de Hello Elementor.
		$this->assertStringContainsString(
			"'ltms_vendor_lost_password',",
			$src,
			'LOST-PASSWORD-PAGE: la pagina debe entrar en maybe_serve_sellers_template.'
		);
		// El render no debe cachearse (nonce fresco).
		$this->assertStringContainsString(
			'public function render_lost_password_form( array $atts = [] ): string',
			$src,
			'LOST-PASSWORD-PAGE: debe existir render_lost_password_form.'
		);
		$this->assertStringContainsString(
			'nocache_headers();',
			$src,
			'LOST-PASSWORD-PAGE: el render debe enviar nocache_headers() (nonce fresco).'
		);
	}

	public function test_lost_password_ajax_uses_generic_response_no_enumeration(): void {
		$src = file_get_contents( self::HANDLER_PATH );

		$pos = strpos( $src, 'public function ajax_vendor_lost_password(): void' );
		$this->assertNotFalse( $pos, 'ajax_vendor_lost_password debe existir.' );
		$block = substr( $src, $pos, 1600 );

		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_auth_nonce', 'nonce', false )",
			$block,
			'LOST-PASSWORD-PAGE: el AJAX debe verificar el nonce ltms_auth_nonce.'
		);
		$this->assertStringContainsString(
			'retrieve_password( $identifier );',
			$block,
			'LOST-PASSWORD-PAGE: debe llamar retrieve_password() con el identificador.'
		);
		$this->assertStringContainsString(
			'Si la cuenta existe, te enviamos un enlace',
			$block,
			'LOST-PASSWORD-PAGE: la respuesta debe ser generica (sin enumerar cuentas).'
		);
		// Rate limit por IP (evita email-bombing).
		$this->assertStringContainsString(
			"'ltms_lostpw_' . md5( \$ip )",
			$block,
			'LOST-PASSWORD-PAGE: debe existir rate limit por IP.'
		);
	}

	public function test_lost_password_view_has_form_and_success_screen(): void {
		$src = file_get_contents( self::FORM_LOST_PASSWORD );

		$this->assertStringContainsString(
			'id="ltms-lost-password-form"',
			$src,
			'LOST-PASSWORD-PAGE: la vista debe tener el form de recuperacion.'
		);
		$this->assertStringContainsString(
			'id="ltms-lost-password-email"',
			$src,
			'LOST-PASSWORD-PAGE: la vista debe tener el campo email/usuario.'
		);
		$this->assertStringContainsString(
			'id="ltms-lost-password-success"',
			$src,
			'LOST-PASSWORD-PAGE: la vista debe tener la pantalla de exito.'
		);
		$this->assertStringContainsString(
			'id="ltms-lost-password-resend"',
			$src,
			'LOST-PASSWORD-PAGE: la vista debe tener el boton de reenviar.'
		);
		$this->assertStringContainsString(
			'ltms-auth-card',
			$src,
			'LOST-PASSWORD-PAGE: la vista debe usar el diseno del login de vendedor.'
		);
	}

	public function test_lost_password_js_handler_present(): void {
		$src = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			"document.getElementById('ltms-lost-password-form')",
			$src,
			'LOST-PASSWORD-PAGE: el JS debe enganchar el form de recuperacion.'
		);
		$this->assertStringContainsString(
			"'action', 'ltms_vendor_lost_password'",
			$src,
			'LOST-PASSWORD-PAGE: el JS debe enviar action=ltms_vendor_lost_password.'
		);
		$this->assertStringContainsString(
			'ltmsGetAuthNonce()',
			$src,
			'LOST-PASSWORD-PAGE: el JS debe usar nonce fresco.'
		);
	}

	public function test_login_forgot_link_points_to_ltms_page(): void {
		$src = file_get_contents( self::FORM_LOGIN );

		$this->assertStringContainsString(
			"['ltms-lost-password'] ?? 0",
			$src,
			'LOST-PASSWORD-PAGE: el enlace "¿Olvidaste tu contraseña?" debe apuntar a la pagina LTMS (ltms-lost-password).'
		);
		$this->assertStringContainsString(
			'ltms-forgot-link',
			$src,
			'LOST-PASSWORD-PAGE: el enlace del login debe conservar la clase ltms-forgot-link.'
		);
	}

	public function test_lost_password_page_created_by_activator(): void {
		$src = file_get_contents( self::ACTIVATOR_PATH );

		$this->assertStringContainsString(
			"'ltms-lost-password'   => [",
			$src,
			'LOST-PASSWORD-PAGE: create_required_pages() debe crear la pagina ltms-lost-password.'
		);
		$this->assertStringContainsString(
			"[ltms_vendor_lost_password]",
			$src,
			'LOST-PASSWORD-PAGE: la pagina debe contener el shortcode ltms_vendor_lost_password.'
		);
		$this->assertStringContainsString(
			"'slug'    => 'recuperar-contrasena'",
			$src,
			'LOST-PASSWORD-PAGE: el slug de la pagina debe ser recuperar-contrasena.'
		);
	}

	public function test_lost_password_page_registered_in_admin_pages_panel(): void {
		$src = file_get_contents( self::ADMIN_PAGES_PATH );

		$this->assertStringContainsString(
			"'ltms-lost-password'   => [",
			$src,
			'LOST-PASSWORD-PAGE: el panel de paginas del admin debe listar ltms-lost-password.'
		);
		$this->assertStringContainsString(
			'recuperar-contrasena',
			$src,
			'LOST-PASSWORD-PAGE: el panel de paginas del admin debe mapear el slug recuperar-contrasena.'
		);
	}

	public function test_lost_password_page_mapped_in_repair_script(): void {
		$src = file_get_contents( self::REPAIR_PAGES_PATH );

		$this->assertStringContainsString(
			"'ltms-lost-password'   => 'recuperar-contrasena'",
			$src,
			'LOST-PASSWORD-PAGE: bin/ltms-repair-pages.php debe mapear ltms-lost-password -> recuperar-contrasena.'
		);
	}
}