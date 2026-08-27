<?php
/**
 * RegisterAuditE2ETest — tests del ciclo REGISTRO-E2E (auditoría del e2e de
 * registro de vendedores en 3 pasos + login normal/Google).
 *
 * Contexto: se auditó el flujo completo de registro/login:
 *   1. Registro normal por email (wizard 3 pasos → ltms_vendor_register).
 *   2. Registro/login con Google OAuth (LTMS_Google_OAuth).
 *   3. Login por credenciales (ltms_vendor_login).
 *
 * El ejercicio e2e reprodujo un bug P0: al registrarse con Google, el callback
 * creaba la cuenta y redirigía a ?complete_profile=1 (wizard de 3 pasos) PERO
 * sin establecer sesión. Al dar "Crear Cuenta", ajax_complete_profile() exigía
 * is_user_logged_in() → 401 "Debes iniciar sesión" — el vendor no podía avanzar.
 * El fix AUTH-04 (ciclo previo) había quitado la cookie de auth sin crear una
 * sesión alternativa, rompiendo el e2e completo de registro con Google.
 *
 * Hallazgos cubiertos (source-based structural checks, mismo patrón que
 * AuthAuditFixTest / AuthReAuditFixTest):
 *
 *   REG-E2E-001 (P0): handle_callback() en class-ltms-google-oauth.php
 *     redirigía a ?complete_profile=1 sin sesión. Fix: en el branch de perfil
 *     incompleto se establece la sesión real (wp_set_current_user +
 *     wp_set_auth_cookie) SIN disparar do_action('wp_login') — preservando la
 *     intención de AUTH-04 de no gatillar TOTP_2FA::intercept_login_for_2fa.
 *   REG-E2E-002 (P1): login_or_register() para un user EXISTENTE no marcaba
 *     ltms_email_verified=1 aunque Google ya verificó el email. Fix: marcar
 *     verificado + ltms_email_verified_at en el branch de usuario existente.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class RegisterAuditE2ETest
 *
 * Tests unitarios estructurales para los fixes del ciclo REGISTRO-E2E.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-register-e2e
 *
 * @group audit-register-e2e
 */
class RegisterAuditE2ETest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta real al archivo dentro de includes/ o assets/ del plugin.
	 * En modo UNIT_ONLY, ABSPATH apunta al root del plugin mismo
	 * (ver tests/bootstrap.php:28 `ABSPATH = dirname(__DIR__) . '/'`),
	 * así que el path canónico es dirname(__DIR__, 2) . '/includes/...'.
	 */
	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REG-E2E-001 (P0) — Google OAuth establece sesión en el wizard de perfil.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reg001_traceability_tag(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Google OAuth file no disponible.' );
		}
		$src = file_get_contents( $file );

		$this->assertStringContainsString( 'REG-E2E-001 (P0) REGISTRO-E2E FIX', $src,
			'El fix REG-E2E-001 debe estar documentado en class-ltms-google-oauth.php.' );
	}

	public function test_reg001_incomplete_branch_establishes_real_session(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque del fix REG-E2E-001 y verificar que establece sesión.
		$start = strpos( $src, 'REG-E2E-001 (P0) REGISTRO-E2E FIX' );
		$this->assertNotFalse( $start, 'El tag REG-E2E-001 debe existir en el callback.' );

		$body = substr( $src, $start, 2200 );

		// La sesión se establece antes del redirect al wizard.
		$if_pos = strpos( $body, 'if ( $profile_incomplete )' );
		$this->assertNotFalse( $if_pos, 'Debe haber un guard $profile_incomplete.' );
		$this->assertLessThan( strpos( $body, 'wp_set_current_user( $user_id )' ), $if_pos,
			'El guard $profile_incomplete debe ir antes de setear current_user.' );

		$if_block = substr( $body, $if_pos, 900 );
		$this->assertStringContainsString( 'wp_set_current_user( $user_id )', $if_block,
			'El branch incompleto debe setear current_user.' );
		$this->assertStringContainsString( 'wp_set_auth_cookie( $user_id, true )', $if_block,
			'El branch incompleto debe setear la auth cookie.' );
		$this->assertStringNotContainsString( "do_action( 'wp_login'", $if_block,
			'El branch incompleto NO debe disparar wp_login (evita intercept de TOTP_2FA).' );
	}

	public function test_reg001_complete_profile_endpoint_requires_session(): void {
		// No-regresión/contrato: ajax_complete_profile() sigue exigiendo sesión —
		// el fix del callback la establece ANTES, así que el contrato del endpoint
		// se mantiene intacto (nadie puede completar perfil sin estar autenticado).
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Auth handler file no disponible.' );
		}
		$src = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_complete_profile' );
		$this->assertNotFalse( $start, 'Método ajax_complete_profile debe existir.' );

		$body = substr( $src, $start, 600 );
		$this->assertStringContainsString( 'if ( ! is_user_logged_in() )', $body,
			'ajax_complete_profile debe seguir exigiendo sesión (contrato del endpoint).' );
		$this->assertStringContainsString( "__( 'Debes iniciar sesión.', 'ltms' )", $body,
			'ajax_complete_profile debe seguir retornando "Debes iniciar sesión" sin sesión.' );
	}

	public function test_reg001_register_page_renders_wizard_for_incomplete_profile(): void {
		// El guard de render_register_form() debe permitir mostrar el wizard cuando
		// el vendor logueado llega con ?complete_profile=1 o ltms_profile_incomplete
		// (si bloqueara, el vendor recién autenticado por Google vería "ya tienes
		// sesión" en vez del wizard de 3 pasos).
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, '$needs_profile_completion = is_user_logged_in()' );
		$this->assertNotFalse( $start, 'render_register_form debe calcular $needs_profile_completion.' );

		$body = substr( $src, $start, 700 );
		$this->assertStringContainsString( "\$_GET['complete_profile'] === '1'", $body,
			'El guard debe contemplar ?complete_profile=1 para mostrar el wizard.' );
		$this->assertStringContainsString( "get_user_meta( get_current_user_id(), 'ltms_profile_incomplete', true )", $body,
			'El guard debe contemplar el meta ltms_profile_incomplete.' );
	}

	public function test_reg001_js_sends_complete_profile_action(): void {
		// E2E JS: el wizard del registro debe detectar ?complete_profile=1 y enviar
		// la action ltms_complete_profile (no ltms_vendor_register) para el Google path.
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$src = file_get_contents( $file );

		$this->assertStringContainsString( "window.location.search.indexOf('complete_profile=1') > -1", $src,
			'El JS debe detectar el flag complete_profile=1 en la URL.' );
		$this->assertStringContainsString( "'ltms_complete_profile'", $src,
			'El JS debe usar la action ltms_complete_profile para el wizard de perfil.' );
		$this->assertStringContainsString( "'ltms_vendor_register'", $src,
			'El JS debe seguir usando ltms_vendor_register para el registro normal.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REG-E2E-002 (P1) — Google login de user existente marca email verificado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reg002_traceability_tag(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		$this->assertStringContainsString( 'REG-E2E-002 (P1) REGISTRO-E2E FIX', $src,
			'El fix REG-E2E-002 debe estar documentado en class-ltms-google-oauth.php.' );
	}

	public function test_reg002_existing_user_sets_email_verified(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque del fix dentro de login_or_register.
		$start = strpos( $src, 'REG-E2E-002 (P1) REGISTRO-E2E FIX' );
		$this->assertNotFalse( $start, 'El tag REG-E2E-002 debe existir.' );

		$body = substr( $src, $start, 1200 );

		$this->assertStringContainsString( "update_user_meta( \$existing->ID, 'ltms_email_verified', 1 )", $body,
			'El login con Google de un user existente debe marcar ltms_email_verified=1 (Google ya verificó).' );
		$this->assertStringContainsString( "get_user_meta( \$existing->ID, 'ltms_email_verified_at', true )", $body,
			'El fix debe respetar un ltms_email_verified_at ya existente (no sobrescribirlo).' );
		$this->assertStringContainsString( "LTMS_Utils::now_utc()", $body,
			'El timestamp ltms_email_verified_at debe setearse con LTMS_Utils::now_utc().' );
	}

	public function test_reg002_existing_user_email_verified_before_vendor_check(): void {
		// El update debe ocurrir en el branch de user existente, ANTES del check
		// "si ya es vendor, directo" — para que aplique también al caso de promoción.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'REG-E2E-002 (P1) REGISTRO-E2E FIX' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 1200 );

		$update_pos = strpos( $body, "update_user_meta( \$existing->ID, 'ltms_email_verified', 1 )" );
		$vendor_pos = strpos( $body, 'if ( LTMS_Utils::is_ltms_vendor( $existing->ID ) )' );
		$this->assertNotFalse( $update_pos, 'El update de email_verified debe existir.' );
		$this->assertNotFalse( $vendor_pos, 'El check is_ltms_vendor debe existir.' );
		$this->assertLessThan( $vendor_pos, $update_pos,
			'ltms_email_verified debe setearse ANTES del check de vendor para cubrir también la promoción.' );
	}

	public function test_reg002_new_user_branch_still_sets_email_verified(): void {
		// No-regresión: el branch de registro NUEVO (línea ~381) debe seguir
		// marcando ltms_email_verified=1 — el fix solo añade el caso existente.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		$this->assertStringContainsString( "update_user_meta( \$user_id, 'ltms_email_verified',  1 )", $src,
			'El branch de registro nuevo debe seguir marcando ltms_email_verified=1.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REG-E2E-003 (P2) — wizard de perfil (Google) no muestra campos de password.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reg003_profile_wizard_hides_password_fields(): void {
		// En el flujo de completar perfil (Google OAuth), los campos de password NO
		// se guardan (ajax_complete_profile no los lee) y el vendor autentica con
		// Google. Mostrarlos inducía a error: el usuario creía haber creado una
		// contraseña válida para login por credenciales. El branch else (password)
		// debe ir DESPUÉS del branch de complete_profile + aviso.
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$src = file_get_contents( $file );

		$this->assertStringContainsString( 'REG-E2E-003 (P2) REGISTRO-E2E FIX', $src,
			'El fix REG-E2E-003 debe estar documentado en form-register.php.' );

		$start = strpos( $src, 'REG-E2E-003 (P2) REGISTRO-E2E FIX' );
		$block = substr( $src, $start, 1400 );

		$else_pos = strpos( $block, '<?php else : ?>' );
		$pwd_pos  = strpos( $block, 'id="ltms-reg-password"' );
		$this->assertNotFalse( $else_pos, 'Debe existir un branch else para el registro normal.' );
		$this->assertNotFalse( $pwd_pos, 'Los campos de password deben seguir existiendo para el registro normal.' );
		$this->assertLessThan( $pwd_pos, $else_pos,
			'El branch else (password) debe ir después del branch de complete_profile (Google).' );

		$this->assertStringContainsString( 'Tu cuenta usa Google para iniciar sesión', $block,
			'El wizard de perfil debe informar que el login es con Google, no con contraseña.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Cross-checks no-regresión del flujo normal (email + password).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reg_native_login_still_verifies_email_gate(): void {
		// AUTH-01 (ciclo previo): el login por credenciales sigue bloqueando a
		// vendors con email no verificado (no-regresión del flujo normal).
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$this->assertStringContainsString( "get_user_meta( \$user->ID, 'ltms_email_verified', true )", $src,
			'El login nativo debe seguir leyendo ltms_email_verified.' );
		$this->assertStringContainsString( 'wp_logout();', $src,
			'El login nativo debe seguir haciendo logout ante email no verificado.' );
	}
}