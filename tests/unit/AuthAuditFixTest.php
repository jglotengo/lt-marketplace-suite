<?php
/**
 * AuthAuditFixTest — tests del ciclo AUTH-AUDIT (auditoría full-stack del
 * flujo de autenticación, registro, onboarding y 2FA de vendedores).
 *
 * Cubre los 10 fixes aplicados en el ciclo AUTH-AUDIT siguiendo el
 * "Loop de auditoría autónoma" de AGENTS.md. Todos los tests son
 * estructurales (Regex/assertStringContainsString sobre el cuerpo del
 * método fuente del archivo .php) — mismo patrón ya usado en
 * KycAudit2FixTest.php, ProductsAuditFixTest.php y PanelAuditFixTest.php
 * para ciclos de auditoría previos.
 *
 * Hallazgos cubiertos:
 *
 *   AUTH-01 (P0): ajax_vendor_login() aceptaba a vendors con email NO
 *     verificado. La validación ltms_email_verified solo se efecutaba en la
 *     UX del dashboard (lógica de presentación, no de seguridad). Un
 *     atacante con email y password correctos pero email no verificado
 *     podía loguearse. Fix: bloquear login en origen, logout inmediato,
 *     redirect a página de verificación.
 *   AUTH-02 (P0): handle_email_verification() eliminaba el token DESPUÉS
 *     de marcar ltms_email_verified=1 — race window donde 2 requests
 *     concurrentes con el mismo token válido ambos pasaban
 *     hash_equals(). Sin rate limit → vector de brute-force del token.
 *     Fix: rate-limit por IP (10/15min) + invalidar token ANTES de marcar
 *     verificado + eliminar token expirado.
 *   AUTH-03 (P1): log_consent() y log_vault_access() estaban dentro del
 *     try{} principal que hace rollback del vendor si algo falla. Un
 *     TypeError en LTMS_Legal_Compliance disparaba rollback de todo el
 *     registro tras crear wallet + metas. Fix: try-catch drain —
 *     loggeo el error de legal logging pero el vendor ya está creado.
 *   AUTH-04 (P1): Google OAuth callback() ponía wp_set_auth_cookie()
 *     INCONDICIONALMENTE, incluso para vendors con perfil incompleto. El
 *     hook wp_login prio 30 de TOTP_2FA disparaba redirect a 2FA challenge
 *     medio, dejando sesión inconsistente. Fix: si perfil incompleto,
 *     redirect a ?complete_profile=1 SIN auth cookie.
 *   AUTH-05 (P1): $_COOKIE['ltms_ref'] se pasaba crudo a
 *     LTMS_Referral_Tree::register_node() sin sanitizar ni validar.
 *     Cookie maliciosa podía inyectar IDs arbitrarios como referrer. Fix:
 *     sanitize + uppercase + length cap 8 + validar que el referrer exists.
 *   AUTH-06 (P1): ajax_complete_profile() forzaba ltms_email_verified=1
 *     sin distinguir el origen del vendor. Vendors registrados via email
 *     normal que no habían clickeado el link de verificación podían marcar
 *     su email verificado llamando complete_profile. Fix: NO marcar
 *     email_verified aquí — debe provenir exclusivamente de
 *     handle_email_verification() (link en email) o del Google OAuth path
 *     (donde Google YA verificó).
 *   AUTH-07 (P2): is_2fa_required() en TOTP_2FA chequeaba rol 'vendor'
 *     (rol WP default que NO existe en LTMS — usamos 'ltms_vendor' y
 *     'ltms_vendor_premium'). Vendors con payouts recientes NUNCA eran
 *     forzados a 2FA. Fix: usar array_intersect con ['ltms_vendor',
 *     'ltms_vendor_premium'].
 *   AUTH-08 (P2): ajax_resend_verification() rate limit via
 *     get_transient+set_transient (NO atómico, TOCTOU). 50 requests
 *     concurrentes podían leer todos $attempts=0, bypass del límite de
 *     3/hora. Fix: migrar a INSERT...ON DUPLICATE KEY atómico (mismo
 *     patrón que login/register throttle).
 *   AUTH-09 (P2): ajax_complete_profile() rate limit mismo bug TOCTOU.
 *     Fix: migrar a INSERT...ON DUPLICATE KEY atómico.
 *   AUTH-10 (P2): login throttle tenía race subtle en el reset-then-
 *     increment: el UPDATE de reset y el INSERT... ON DUPLICATE puede
 *     no ver estado consistente si el transient expira en medio del
 *     request. Fix: si expired, INSERT forzado a '1' (no increment).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class AuthAuditFixTest
 *
 * Tests unitarios estructurales para los fixes del ciclo AUTH-AUDIT.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-auth
 *
 * @group audit-auth
 */
class AuthAuditFixTest extends LTMS_Unit_Test_Case {

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
	// AUTH-01 (P0) — ajax_vendor_login bloquea vendors con email no verificado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_01a_ajax_vendor_login_blocks_unverified_email(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Auth handler file no disponible.' );
		}
		$src = file_get_contents( $file );

		// El comentario del fix AUTH-01 debe existir.
		$this->assertStringContainsString( 'AUTH-01 (P0) AUDIT-AUTH FIX', $src,
			'ajax_vendor_login debe tener el comentario AUTH-01 (P0) AUDIT-AUTH FIX.' );

		// La condición ltms_require_email_verification debe chequearse.
		$this->assertStringContainsString( "get_option( 'ltms_require_email_verification', 'yes' )", $src,
			'ajax_vendor_login debe chequear el policy ltms_require_email_verification antes de aceptar el login.' );

		// El flag ltms_email_verified del user debe leerse.
		$this->assertStringContainsString( "get_user_meta( \$user->ID, 'ltms_email_verified', true )", $src,
			'ajax_vendor_login debe leer el meta ltms_email_verified del user autenticado.' );

		// Si no verificado, hacer wp_logout + wp_clear_auth_cookie.
		$this->assertStringContainsString( 'wp_logout();', $src,
			'ajax_vendor_login debe llamar wp_logout() si el email no está verificado.' );
		$this->assertStringContainsString( 'wp_clear_auth_cookie();', $src,
			'ajax_vendor_login debe llamar wp_clear_auth_cookie() si el email no está verificado.' );

		// El response debe ser 403.
		$this->assertStringContainsString( ', 403', $src,
			'ajax_vendor_login debe retornar HTTP 403 cuando el email no está verificado.' );
	}

	public function test_01b_ajax_vendor_login_redirect_to_resend_verification(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// El redirect debe incluir el parámetro resend_verification=1.
		$this->assertStringContainsString( "'resend_verification' => '1'", $src,
			'ajax_vendor_login debe agregar resend_verification=1 al redirect para que el vendor pueda reenviar el link.' );
	}

	/**
	 * RA-AUTH-01 (P1) AUDIT-AUTH RE-FIX — el reset de throttle del login se
	 * hacía ANTES del check AUTH-01, permitiendo que un atacante con credenciales
	 * correctas pero email no verificado pateara el endpoint infinitamente. Tras
	 * el re-fix, el reset se hace justo antes de wp_send_json_success (solo si
	 * todos los checks pasaron).
	 */
	public function test_01c_login_throttle_reset_after_auth01_check(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_vendor_login' );
		$this->assertNotFalse( $start, 'Método ajax_vendor_login debe existir.' );
		// Buffer amplio (12000) — el método ajax_vendor_login completo mide
		// ~8600 chars (throttle+signon+AUTH-01+redirect+reset+success), y el
		// reset delete_transient($throttle_key) está en pos ~8272 dentro del body.
		$body = substr( $src, $start, 12000 );

		// Comentario del re-fix RA-AUTH-01 debe estar presente.
		$this->assertStringContainsString( 'RA-AUTH-01 (P1) AUDIT-AUTH RE-FIX', $body,
			'ajax_vendor_login debe documentar el re-fix RA-AUTH-01 (reset de throttle fuera del bypass AUTH-01).' );

		// La guarda AUTH-01 (rechazo si email no verificado) debe ir ANTES que el
		// reset de throttle. Localizamos ambos y comparamos posiciones.
		$auth01_pos = strpos( $body, 'AUTH-01 (P0) AUDIT-AUTH FIX' );
		$reset_pos  = strpos( $body, 'delete_transient( $throttle_key )' );
		$this->assertNotFalse( $auth01_pos, 'Debe existir el bloque AUTH-01 (P0) AUDIT-AUTH FIX.' );
		$this->assertNotFalse( $reset_pos,  'Debe existir el reset de throttle delete_transient($throttle_key).' );
		$this->assertLessThan( $reset_pos, $auth01_pos,
			'RA-AUTH-01: el check AUTH-01 debe ir ANTES que el reset de throttle. Si reset primero, un atacante con email no verificado puede patear el login infinitamente.' );

		// El reset debe estar CERCA del wp_send_json_success final (no más de
		// 200 chars antes), confirmando que solo se ejecuta si el login pasa.
		$success_pos = strpos( $body, 'wp_send_json_success(' );
		$this->assertNotFalse( $success_pos );
		$this->assertGreaterThan( $reset_pos, $success_pos,
			'RA-AUTH-01: el reset de throttle debe ir ANTES de wp_send_json_success (solo si el login pasa).' );
		$this->assertLessThan( 200, $success_pos - $reset_pos,
			'RA-AUTH-01: el reset debe estar justo antes del success, no suelto en el medio del método.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-02 (P0) — handle_email_verification invalida token + rate limit.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_02a_handle_email_verification_invalidates_token_before_marking(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar el método handle_email_verification.
		$start = strpos( $src, 'function handle_email_verification' );
		$this->assertNotFalse( $start, 'Método handle_email_verification debe existir.' );
		// Buffer 6000 — tras RA-AUTH-02 el método creció (~55 líneas de SQL
		// INSERT...ON DUPLICATE KEY); las llamadas delete_user_meta + update_user_meta
		// de la sección final quedan fuera del window de 4000 original.
		$body = substr( $src, $start, 6000 );

		// Comentario del fix AUTH-02.
		$this->assertStringContainsString( 'AUTH-02 (P0) AUDIT-AUTH FIX', $body,
			'handle_email_verification debe tener el comentario AUTH-02 (P0) AUDIT-AUTH FIX.' );

		// El delete_user_meta del token y expires debe aparecer ANTES del
		// update_user_meta( ...'ltms_email_verified', 1 ).
		$delete_token_pos   = strpos( $body, "delete_user_meta( \$user_id, 'ltms_email_verify_token' )" );
		$update_verified_pos = strpos( $body, "update_user_meta( \$user_id, 'ltms_email_verified', 1 )" );
		$this->assertNotFalse( $delete_token_pos, 'handle_email_verification debe eliminar el token de verificación.' );
		$this->assertNotFalse( $update_verified_pos, 'handle_email_verification debe marcar ltms_email_verified=1.' );
		$this->assertLessThan( $update_verified_pos, $delete_token_pos,
			'AUTH-02: delete_user_meta del token debe ir ANTES que update ltms_email_verified=1 (invalidar antes de marcar).' );
	}

	public function test_02b_handle_email_verification_has_rate_limit(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Cuerpo del método amplio — tras RA-AUTH-02 el método creció con
		// las queries INSERT...ON DUPLICATE KEY (mismo patrón que AUTH-08/09).
		$start = strpos( $src, 'function handle_email_verification' );
		$body  = substr( $src, $start, 6000 );

		// Rate-limit key ltms_email_verify_attempts_.
		$this->assertStringContainsString( 'ltms_email_verify_attempts_', $body,
			'handle_email_verification debe usar rate-limit key ltms_email_verify_attempts_.' );

		// Límite 10 intentos (post-RA-AUTH-02: el check es > 10 porque el
		// increment atómico ya contó este request antes del chequeo, mientras
		// que la versión transient chequeaba >= 10 ANTES de incrementar).
		$this->assertStringContainsString( '> 10', $body,
			'handle_email_verification debe limitar a 10 intentos por IP (check > 10 tras increment atómico RA-AUTH-02).' );

		// Ventana 15 minutos.
		$this->assertStringContainsString( '15 * MINUTE_IN_SECONDS', $body,
			'handle_email_verification debe usar ventana de 15 minutos para el rate limit.' );

		// Response 429.
		$this->assertStringContainsString( "'response' => 429", $body,
			'handle_email_verification debe retornar 429 en rate limit.' );
	}

	/**
	 * RA-AUTH-02 (P2) AUDIT-AUTH RE-FIX — verify email throttle migró a INSERT
	 * atómico (mismo patrón que AUTH-08/AUTH-09). Antes get_transient+set_transient
	 * (TOCTOU) permitía bypass del límite bajo concurrencia.
	 */
	public function test_02d_verify_email_throttle_uses_atomic_insert(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function handle_email_verification' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 6000 );

		// El comentario del re-fix debe estar presente.
		$this->assertStringContainsString( 'RA-AUTH-02 (P2) AUDIT-AUTH RE-FIX', $body,
			'handle_email_verification debe documentar el re-fix RA-AUTH-02 (migración a INSERT atómico).' );

		// Query INSERT...ON DUPLICATE KEY atómica debe existir dentro del método.
		$this->assertStringContainsString(
			'INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, \'1\', \'no\')',
			$body,
			'RA-AUTH-02: debe usar INSERT...ON DUPLICATE KEY atómico para el increment (race-safe).'
		);
		$this->assertStringContainsString(
			'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1',
			$body,
			'RA-AUTH-02: el increment debe hacer CAST(option_value AS UNSIGNED) + 1 on conflict.'
		);

		// El patrón legacy get_transient NO debe usarse para el rate-limit key.
		$get_transient_pos = strpos( $body, "get_transient( \$verify_throttle" );
		$this->assertFalse( $get_transient_pos,
			'RA-AUTH-02: get_transient($verify_throttle) NO debe usarse (migrado a INSERT atómico).' );
	}

	public function test_02c_handle_email_verification_clears_token_on_expiry(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Buffer 6000 — tras RA-AUTH-02 el método creció con las queries
		// INSERT...ON DUPLICATE KEY; el branch de expiración quedó más lejos.
		$start = strpos( $src, 'function handle_email_verification' );
		$body  = substr( $src, $start, 6000 );

		// En el branch de expiración (time() > $expires), el token debe eliminarse.
		$expiry_branch_pos = strpos( $body, 'time() > $expires' );
		$this->assertNotFalse( $expiry_branch_pos, 'handle_email_verification debe chequear time() > $expires.' );

		// Después del chequeo de expiración, delete_user_meta debe aparecer.
		$window_after_expiry = substr( $body, $expiry_branch_pos, 600 );
		$this->assertStringContainsString( "delete_user_meta( \$user_id, 'ltms_email_verify_token' )", $window_after_expiry,
			'Token expirado debe eliminarse para prevenir futuros intentos.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-03 (P1) — log_consent/log_vault_access desacoplados del rollback.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_03a_log_consent_terms_wrapped_in_try_catch(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Buscar el bloque del consent terms_and_conditions con AUTH-03.
		$start = strpos( $src, 'AUTH-03 (P1) AUDIT-AUTH FIX' );
		$this->assertNotFalse( $start, 'El fix AUTH-03 debe estar documentado en el código.' );

		// Tomar 2000 chars a partir del comentario para cubrir los 3 bloques (vault, terms, DT).
		$body = substr( $src, $start, 5000 );

		// El log_consent de terms_and_conditions debe envolverse en try-catch.
		$this->assertStringContainsString( "LTMS_Legal_Compliance::log_consent( \$user_id, 'terms_and_conditions'", $body,
			'El log_consent de terms_and_conditions debe seguir invocado.' );

		// Y debe haber un catch (assí como un try antes del call).
		$this->assertStringContainsString( "catch ( \\Throwable \$e )", $body,
			'log_consent debe estar envuelto en try-catch \\Throwable.' );

		// El log de fallback debe escribirse.
		$this->assertStringContainsString( "'REGISTER_CONSENT_LOG_FAILED'", $body,
			'En caso de fallar log_consent de terms, debe loggear REGISTER_CONSENT_LOG_FAILED.' );
	}

	public function test_03b_log_vault_access_wrapped_in_try_catch(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Buscar el bloque del vault_access.
		$start = strpos( $src, 'AUTH-03 (P1) AUDIT-AUTH FIX: envolver log_vault_access' );
		$this->assertNotFalse( $start, 'El fix AUTH-03 para log_vault_access debe estar documentado.' );

		$body = substr( $src, $start, 2200 );

		$this->assertStringContainsString( 'LTMS_Legal_Compliance::log_vault_access( $user_id, $user_id', $body,
			'log_vault_access debe seguir siendo invocado con accessor_id = user_id.' );

		$this->assertStringContainsString( "'REGISTER_VAULT_LOG_FAILED'", $body,
			'En caso de fallar log_vault_access, debe loggear REGISTER_VAULT_LOG_FAILED.' );
	}

	public function test_03c_log_consent_data_treatment_wrapped(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Buscar el segundo fix AUTH-03 para data_treatment.
		$start = strpos( $src, "AUTH-03 (P1) AUDIT-AUTH FIX: mismo drenaje del log_consent" );
		$this->assertNotFalse( $start, 'El fix AUTH-03 para data_treatment debe estar documentado.' );

		$body = substr( $src, $start, 1500 );

		$this->assertStringContainsString( "log_consent( \$user_id, 'data_treatment'", $body,
			'log_consent de data_treatment debe seguir siendo invocado.' );

		$this->assertStringContainsString( "'REGISTER_CONSENT_LOG_FAILED_DT'", $body,
			'En caso de fallar log_consent de data_treatment, debe loggear REGISTER_CONSENT_LOG_FAILED_DT.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-04 (P1) — Google OAuth callback no auth cookie si perfil incompleto.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_04a_google_oauth_callback_checks_profile_incomplete(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Google OAuth file no disponible.' );
		}
		$src = file_get_contents( $file );

		// Comentario del fix AUTH-04.
		$this->assertStringContainsString( 'AUTH-04 (P1) AUDIT-AUTH FIX', $src,
			'Google OAuth callback debe tener el comentario AUTH-04 (P1) AUDIT-AUTH FIX.' );

		// La lectura del meta ltms_profile_incomplete debe aparecer.
		$this->assertStringContainsString( "get_user_meta( \$user_id, 'ltms_profile_incomplete', true )", $src,
			'Google OAuth callback debe leer el meta ltms_profile_incomplete del user.' );

		// Si profile_incomplete, redirigir a ?complete_profile=1.
		$this->assertStringContainsString( "'complete_profile', '1'", $src,
			'Google OAuth callback debe redirigir con ?complete_profile=1 cuando el perfil está incompleto.' );
	}

	public function test_04b_google_oauth_no_auth_cookie_for_incomplete_profile(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque de AUTH-04 y verificar que wp_set_auth_cookie
		// solo se ejecuta si $profile_incomplete es false.
		$start = strpos( $src, 'AUTH-04 (P1) AUDIT-AUTH FIX' );
		$this->assertNotFalse( $start );

		// Tomar 2000 chars después del fix.
		$body = substr( $src, $start, 2000 );

		// La condición if ( $profile_incomplete ) debe estar ANTES de
		// wp_set_auth_cookie, y dentro de esa condición debe haber wp_safe_redirect + exit.
		$if_pos          = strpos( $body, 'if ( $profile_incomplete )' );
		$auth_cookie_pos = strpos( $body, 'wp_set_auth_cookie( $user_id, true )' );
		$this->assertNotFalse( $if_pos, 'Debe haber un if ($profile_incomplete) como guard.' );
		$this->assertNotFalse( $auth_cookie_pos, 'wp_set_auth_cookie debe seguir existiendo (cuando perfil completo).' );
		$this->assertLessThan( $auth_cookie_pos, $if_pos,
			'AUTH-04: la guarda $profile_incomplete debe ir ANTES que wp_set_auth_cookie (no autenticar si perfil incompleto).' );

		// Dentro del if debe haber un wp_safe_redirect + exit.
		// Buffer amplio (800) porque el bloque if incluye la construcción de
		// $reg_url con fallback ternario ANTES de llegar al redirect + exit.
		$if_block = substr( $body, $if_pos, 800 );
		$this->assertStringContainsString( 'wp_safe_redirect( $reg_url )', $if_block,
			'Cuando perfil incompleto, debe redirigir a $reg_url (URL con complete_profile=1).' );
		$this->assertStringContainsString( 'exit;', $if_block,
			'Cuando perfil incompleto, debe exit; tras el redirect.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-05 (P1) — $_COOKIE['ltms_ref'] sanitizado y validado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_05a_google_oauth_referral_cookie_is_sanitized(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		// Comentario del fix AUTH-05.
		$this->assertStringContainsString( 'AUTH-05 (P1) AUDIT-AUTH FIX', $src,
			'Google OAuth debe tener el comentario AUTH-05 (P1) AUDIT-AUTH FIX.' );

		// El sanitize_text_field + wp_unslash deben aplicarse a $_COOKIE['ltms_ref'].
		$this->assertStringContainsString( "sanitize_text_field( wp_unslash( \$_COOKIE['ltms_ref'] ) )", $src,
			'$_COOKIE[\'ltms_ref\'] debe sanitizarse con sanitize_text_field + wp_unslash.' );

		// Length cap 8 con strtoupper + substr.
		$this->assertStringContainsString( 'strtoupper( substr( $raw_ref, 0, 8 ) )', $src,
			'El código de referido debe uppercasearse y truncarse a 8 chars.' );
	}

	public function test_05b_google_oauth_referral_cookie_validates_referrer_exists(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );

		// Validación de que el referrer existe en la DB.
		$this->assertStringContainsString( "'meta_key'   => 'ltms_referral_code'", $src,
			'AUTH-05: debe validarse el referrer consultando user_meta ltms_referral_code.' );

		// Si no existe, $ref_code debe resetearse a ''.
		$this->assertStringContainsString( "\$ref_code = '';", $src,
			'AUTH-05: si el referrer no existe, $ref_code debe resetearse a string vacío.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-06 (P1) — ajax_complete_profile NO marca email_verified=1.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_06a_complete_profile_does_not_force_email_verified(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar el método ajax_complete_profile.
		$start = strpos( $src, 'function ajax_complete_profile' );
		$this->assertNotFalse( $start, 'Método ajax_complete_profile debe existir.' );
		// Buffer amplio (16000) — el método es largo: nonce check, auth check,
		// rol check, validación ltms_profile_incomplete, rate limit AUTH-09 (~120
		// líneas de SQL atómico), sanitización, validaciones, encripción del
		// document, escritura de metas, y AL FINAL el fix AUTH-06 (línea ~1464
		// del archivo). Con 6000 no se llega al comentario AUTH-06 y el test
		// reportaba falso negativo.
		$body = substr( $src, $start, 16000 );

		// El comentario AUTH-06 debe estar presente.
		$this->assertStringContainsString( 'AUTH-06 (P1) AUDIT-AUTH FIX', $body,
			'ajax_complete_profile debe tener el comentario AUTH-06 (P1) AUDIT-AUTH FIX.' );

		// La línea que forzaba ltms_email_verified=1 debe estar ausente
		// en el body del método.
		$this->assertStringNotContainsString(
			"update_user_meta( \$user_id, 'ltms_email_verified', 1 )",
			$body,
			'AUTH-06: ajax_complete_profile NO debe forzar ltms_email_verified=1. El flag debe provenir del link del email o del login_via_google.'
		);

		// La línea de ltms_email_verified_at tampoco debe forzarse aquí.
		$this->assertStringNotContainsString(
			"update_user_meta( \$user_id, 'ltms_email_verified_at'",
			$body,
			'AUTH-06: ajax_complete_profile NO debe setear ltms_email_verified_at (delegado al endpoint handle_email_verification).'
		);
	}

	public function test_06b_complete_profile_still_clears_profile_incomplete(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_complete_profile' );
		// Buffer 16000 — ver justificación en test_06a. Con 8000 no se llega
		// a delete_user_meta (línea ~1475 del archivo).
		$body  = substr( $src, $start, 16000 );

		// IMPORTANTE: el delete_user_meta de ltms_profile_incomplete debe seguir
		// (es lo que realmente marca el wizard como completado).
		$this->assertStringContainsString(
			"delete_user_meta( \$user_id, 'ltms_profile_incomplete' )",
			$body,
			'AUTH-06: ajax_complete_profile SI debe hacer delete_user_meta de ltms_profile_incomplete para marcar wizard completo.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-07 (P2) — is_2fa_required usa rol ltms_vendor (no 'vendor').
	// ─────────────────────────────────────────────────────────────────────────

	public function test_07a_is_2fa_required_uses_ltms_vendor_role(): void {
		$file = $this->plugin_path( 'includes/core/class-ltms-totp-2fa.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'TOTP 2FA file no disponible.' );
		}
		$src = file_get_contents( $file );

		// Comentario del fix AUTH-07.
		$this->assertStringContainsString( 'AUTH-07 (P2) AUDIT-AUTH FIX', $src,
			'TOTP 2FA debe tener el comentario AUTH-07 (P2) AUDIT-AUTH FIX.' );

		// La variable $vendor_roles debe estar definida con los roles correctos.
		$this->assertStringContainsString( "\$vendor_roles = [ 'ltms_vendor', 'ltms_vendor_premium' ]", $src,
			'AUTH-07: is_2fa_required debe definir $vendor_roles con [\'ltms_vendor\', \'ltms_vendor_premium\'].' );

		// El array_intersect debe usarse.
		$this->assertStringContainsString( 'array_intersect( $vendor_roles, (array) $user->roles )', $src,
			'AUTH-07: is_2fa_required debe usar array_intersect para comparar roles.' );
	}

	public function test_07b_is_2fa_required_no_longer_uses_legacy_vendor_role(): void {
		$file = $this->plugin_path( 'includes/core/class-ltms-totp-2fa.php' );
		$src  = file_get_contents( $file );

		// Localizar el método is_2fa_required.
		$start = strpos( $src, 'function is_2fa_required' );
		$this->assertNotFalse( $start, 'Método is_2fa_required debe existir.' );
		$body = substr( $src, $start, 1200 );

		// La condición anterior `in_array( 'vendor', $user->roles, true )`
		// NO debe seguir presente en is_2fa_required (rol legacy de WP default).
		$this->assertStringNotContainsString(
			"in_array( 'vendor', \$user->roles, true )",
			$body,
			'AUTH-07: is_2fa_required NO debe usar in_array con \'vendor\' (rol default de WP, no existe en LTMS).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-08 (P2) — ajax_resend_verification rate limit atómico.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_08a_resend_verification_uses_atomic_increment(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar el método ajax_resend_verification.
		$start = strpos( $src, 'function ajax_resend_verification' );
		$this->assertNotFalse( $start, 'Método ajax_resend_verification debe existir.' );
		$body = substr( $src, $start, 3000 );

		// El comentario AUTH-08 debe estar presente.
		$this->assertStringContainsString( 'AUTH-08 (P2) AUDIT-AUTH FIX', $body,
			'ajax_resend_verification debe tener el comentario AUTH-08 (P2) AUDIT-AUTH FIX.' );

		// INSERT...ON DUPLICATE KEY debe usarse (atómico).
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1', $body,
			'AUTH-08: el rate limit debe usar INSERT...ON DUPLICATE KEY UPDATE para incremento atómico.' );

		// La sentencia preparada con $wpdb->prepare debe usarse.
		$this->assertStringContainsString( '$wpdb->prepare(', $body,
			'AUTH-08: las queries deben prépararse con $wpdb->prepare().' );

		// Límite 3 ( > 3 ).
		$this->assertStringContainsString( '$attempts > 3', $body,
			'AUTH-08: el límite de resend debe seguir siendo 3 intentos por hora.' );
	}

	public function test_08b_resend_verification_no_longer_uses_transient_race(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_resend_verification' );
		$body  = substr( $src, $start, 3000 );

		// La implementación anterior get_transient( $throttle_key ) + set_transient ...
		// NO debe seguir presente en el body del método.
		$this->assertStringNotContainsString(
			"get_transient( \$throttle_key )",
			$body,
			'AUTH-08: ajax_resend_verification NO debe usar get_transient (NO atómico, TOCTOU).'
		);
		$this->assertStringNotContainsString(
			"set_transient( \$throttle_key, \$attempts + 1, HOUR_IN_SECONDS )",
			$body,
			'AUTH-08: ajax_resend_verification NO debe usar set_transient+1 (race condition).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-09 (P2) — ajax_complete_profile rate limit atómico.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_09a_complete_profile_uses_atomic_increment(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_complete_profile' );
		$this->assertNotFalse( $start, 'Método ajax_complete_profile debe existir.' );
		$body = substr( $src, $start, 3500 );

		// El comentario AUTH-09 debe estar.
		$this->assertStringContainsString( 'AUTH-09 (P2) AUDIT-AUTH FIX', $body,
			'ajax_complete_profile debe tener el comentario AUTH-09 (P2) AUDIT-AUTH FIX.' );

		// INSERT...ON DUPLICATE KEY en el body.
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1', $body,
			'AUTH-09: el rate limit debe usar INSERT...ON DUPLICATE KEY UPDATE para incremento atómico.' );

		// Límite 5.
		$this->assertStringContainsString( '$tries > 5', $body,
			'AUTH-09: el límite de complete_profile debe seguir siendo 5 intentos por IP cada 15 min.' );
	}

	public function test_09b_complete_profile_no_longer_uses_transient_race(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function ajax_complete_profile' );
		$body  = substr( $src, $start, 3500 );

		$this->assertStringNotContainsString(
			"get_transient( \$throttle_key )",
			$body,
			'AUTH-09: ajax_complete_profile NO debe usar get_transient (NO atómico, TOCTOU).'
		);
		$this->assertStringNotContainsString(
			"set_transient( \$throttle_key, \$tries + 1, 15 * MINUTE_IN_SECONDS )",
			$body,
			'AUTH-09: ajax_complete_profile NO debe usar set_transient+1 (race condition).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-10 (P2) — login throttle reset atómico en expired.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_10a_login_throttle_expired_branch_forces_value_1(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar ajax_vendor_login.
		$start = strpos( $src, 'function ajax_vendor_login' );
		$this->assertNotFalse( $start, 'Método ajax_vendor_login debe existir.' );
		$body = substr( $src, $start, 3500 );

		// El comentario AUTH-10 debe estar presente.
		$this->assertStringContainsString( 'AUTH-10 (P2) AUDIT-AUTH FIX', $body,
			'ajax_vendor_login throttle debe tener el comentario AUTH-10 (P2) AUDIT-AUTH FIX.' );

		// La variable $expired debe definirse.
		$this->assertStringContainsString( '$expired = $timeout_val && $timeout_val < $now;', $body,
			'AUTH-10: el throttle del login debe exponer $expired como boolean.' );

		// El branch if ( $expired ) debe forzar option_value a 1 (INSERT...ON DUPLICATE UPDATE = 1).
		$this->assertStringContainsString(
			"ON DUPLICATE KEY UPDATE option_value = '1'",
			$body,
			'AUTH-10: en el branch expirado, el INSERT debe forzar option_value a 1 (no increment).'
		);

		// Y $tries debe setearse a 1 directamente (no select).
		$this->assertStringContainsString( '$tries = 1;', $body,
			'AUTH-10: en el branch expirado, $tries debe setearse a 1 directamente (no select).'
		);
	}

	public function test_10b_login_throttle_expired_branch_no_longer_uses_increment(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar la sección de AUTH-10.
		$marker = strpos( $src, 'AUTH-10 (P2) AUDIT-AUTH FIX' );
		$this->assertNotFalse( $marker, 'Comentario AUTH-10 debe existir.' );

		// Tomar el body desde el marker hasta ~2000 chars (cubriendo el if ( $expired )).
		$body = substr( $src, $marker, 2200 );

		// El branch de expirado debe estar separado del else (increment).
		$if_expired = strpos( $body, 'if ( $expired ) {' );
		$else_block = strpos( $body, '} else {' );
		$this->assertNotFalse( $if_expired, 'AUTH-10: Debe haber un if ( $expired ) { ... } else { ... }.' );
		$this->assertNotFalse( $else_block, 'AUTH-10: Debe haber un else { ... } con el branch de atomic increment.' );
		$this->assertLessThan( $else_block, $if_expired,
			'AUTH-10: if ( $expired ) debe ir ANTES del else { atomic increment }.' );

		// En el if-branch (entre $if_expired y $else_block), NO debe haber
		// CAST(option_value AS UNSIGNED) + 1 (eso es del increment branch).
		$expired_block = substr( $body, $if_expired, $else_block - $if_expired );
		$this->assertStringNotContainsString(
			'CAST(option_value AS UNSIGNED) + 1',
			$expired_block,
			'AUTH-10: en el branch expirado, NO debe usarse +1 (debe forzar a 1).'
		);
	}
}
