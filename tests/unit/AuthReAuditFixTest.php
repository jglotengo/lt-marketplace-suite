<?php
/**
 * AuthReAuditFixTest — tests del sub-ciclo RE-AUDIT-AUTH (re-auditoría profunda
 * sobre el módulo de autenticación/login/registro de vendedores + Google OAuth,
 * posterior al cierre del ciclo AUTH-AUDIT original cubierto por
 * AuthAuditFixTest.php).
 *
 * La re-auditoría siguió el "Loop de auditoría autónoma" de AGENTS.md:
 *   1. INVENTARIO — mapeo de archivos auth (handler, google-oauth, form-login,
 *      form-register, JS, test AuthAuditFixTest previo).
 *   2. AUDITORÍA — lectura completa + identificación de hallazgos nuevos no
 *      cubiertos por AUTH-01..AUTH-10 del ciclo previo.
 *   3. PRIORIZACIÓN — clasificación P1/P2 (no se detectaron P0 nuevos; los
 *      P0 ya cerrados por el ciclo previo son no-regresión).
 *   4. FIX — 4 fixes aplicados con tags AUTH-RA1..AUTH-RA4.
 *   5. RE-AUDITORÍA — este test, que valida los 4 fixes Y los 10 fixes
 *      previos del ciclo AUTH-AUDIT original (cross-checks no-regresión).
 *
 * Hallazgos cubiertos (todos source-based structural checks):
 *
 *   AUTH-RA1 (P1) H-N1: form-register.php abría <form id="ltms-register-form">
 *     en la línea ~53 pero NUNCA lo cerraba con </form>. El footer-auth
 *     ("¿Ya tienes cuenta? Iniciar sesión") y el cierre .ltms-register-card
 *     quedaban DENTRO del form. Bug HTML: (a) semántica inválida, (b) el
 *     JS del wizard llamaba form.reset() tras registro exitoso, lo que
 *     habría reseteado cualquier input futuro que appeared en el footer,
 *     (c) el step nav (.ltms-wizard-back) quedaba semánticamente dentro del
 *     form. Fix: añadir </form><!-- #ltms-register-form --> antes del
 *     auth-footer y el cierre del card.
 *
 *   AUTH-RA2 (P2) H-N2: form-login.php:124 tenía
 *     wp_nonce_field('ltms_vendor_login', 'ltms_login_nonce') — código
 *     muerto. El AJAX handler (ajax_vendor_login) verifica
 *     check_ajax_referer('ltms_auth_nonce', 'nonce') donde el nonce viaja
 *     via wp_localize_script('ltmsAuth', nonce: wp_create_nonce('ltms_auth_nonce'))
 *     desde class-ltms-frontend-assets.php:812 o template-sellers-page.php:40.
 *     El JS (ltms-login-register.js:123) envía ltmsAuth.nonce como campo
 *     'nonce', NO usa 'ltms_login_nonce' ni el action 'ltms_vendor_login'.
 *     M-2 eliminó el wp_nonce_field en form-register pero no aquí, dejando
 *     el input hidden muerto. Fix: eliminado con comentario.
 *
 *   AUTH-RA3 (P2) H-N5: auth-handler en get_users() para validar referral
 *     code (líneas 582-588 originales) tenía la clave 'number' => 1
 *     DUPLICADA (líneas 585 y 587). PHP silenciosamente usa el último
 *     valor (1 en ambos, sin efecto funcional), pero el duplicate key es
 *     código confuso y analyzer estatico lo flagga como bug. Fix: eliminado
 *     el duplicado.
 *
 *   AUTH-RA4 (P1) H-N6: login JS (ltms-login-register.js) en el branch
 *     `else` (login error) solo mostraba data.data.message e IGNORABA
 *     data.data.redirect. El backend ajax_vendor_login (fix AUTH-01 del
 *     ciclo previo) retorna HTTP 403 con message + redirect cuando el
 *     vendor tiene email no verificado — el redirect apunta a la página
 *     de login con ?resend_verification=1 que muestra el mini-form de
 *     reenvío de email. Antes el JS solo mostraba el message y NO seguía
 *     el redirect, rompiendo la UX del fix AUTH-01: el vendor veia
 *     "verifica tu email" pero no era llevado al form de reenvío. Fix:
 *     añadir setTimeout(redirect, 1200) en el branch else con guard
 *     data.data.redirect.
 *
 * Santos los tests son estructurales (Regex/assertStringContainsString sobre
 * el cuerpo del método/archivo fuente) — mismo patrón ya usado en
 * AuthAuditFixTest.php y los ciclos de auditoría previos.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class AuthReAuditFixTest
 *
 * Tests unitarios estructurales para los fixes del sub-ciclo RE-AUDIT-AUTH.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-auth-reaudit
 *
 * @group audit-auth-reaudit
 */
class AuthReAuditFixTest extends LTMS_Unit_Test_Case {

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
	// AUTH-RA1 (P1) H-N1 — form-register.php debe cerrar <form>.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ra1_register_form_has_closing_form_tag(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$src = file_get_contents( $file );

		// El tag <form id="ltms-register-form"> debe existir (apertura).
		$this->assertStringContainsString( '<form id="ltms-register-form"', $src,
			'form-register.php debe abrir <form id="ltms-register-form">.' );

		// El tag de cierre </form> debe existir (este es el fix AUTH-RA1).
		$this->assertStringContainsString( '</form>', $src,
			'AUTH-RA1: form-register.php debe cerrar el <form> con </form>. Antes del fix, el form se abría pero nunca se cerraba, dejando el footer-auth y el cierre del card dentro del form.' );

		// El comentario del fix debe estar presente.
		$this->assertStringContainsString( 'AUTH-RA1 (P1) RE-AUDIT-AUTH FIX', $src,
			'form-register.php debe tener el comentario AUTH-RA1 (P1) RE-AUDIT-AUTH FIX que documenta el cierre </form> añadido.' );
	}

	public function test_ra1_register_form_closes_before_auth_footer(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		$src  = file_get_contents( $file );

		// El cierre </form> debe aparecer ANTES que el div.ltms-auth-footer.
		// Esto garantiza que el footer ("¿Ya tienes cuenta? Iniciar sesión")
		// quede FUERA del form, no reseteado por form.reset() del JS.
		$form_close_pos = strpos( $src, '</form>' );
		$footer_pos      = strpos( $src, 'class="ltms-auth-footer"' );
		$this->assertNotFalse( $form_close_pos, 'AUTH-RA1: </form> debe existir.' );
		$this->assertNotFalse( $footer_pos, 'div.ltms-auth-footer debe existir.' );
		$this->assertLessThan( $footer_pos, $form_close_pos,
			'AUTH-RA1: </form> debe ir ANTES que div.ltms-auth-footer. Si va después, el footer queda dentro del form y form.reset() del JS lo resetea.' );
	}

	public function test_ra1_register_form_closes_before_card_closing_div(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		$src  = file_get_contents( $file );

		// El cierre </form> debe aparecer ANTES que el cierre
		// </div><!-- .ltms-register-card --> (último div del archivo).
		$form_close_pos = strpos( $src, '</form>' );
		$card_close_pos = strpos( $src, '.ltms-register-card -->' );
		$this->assertNotFalse( $form_close_pos, 'AUTH-RA1: </form> debe existir.' );
		$this->assertNotFalse( $card_close_pos, 'comentario .ltms-register-card --> debe existir (cierre del card).' );
		$this->assertLessThan( $card_close_pos, $form_close_pos,
			'AUTH-RA1: </form> debe ir ANTES que el cierre del card. Antes del fix, el card se cerraba con el form aún abierto, generando HTML inválido.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-RA2 (P2) H-N2 — form-login.php sin wp_nonce_field código muerto.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ra2_login_form_no_dead_nonce_field(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-login.php no disponible.' );
		}
		$src = file_get_contents( $file );

		// El comentario del fix debe estar presente.
		$this->assertStringContainsString( 'AUTH-RA2 (P2) RE-AUDIT-AUTH FIX', $src,
			'form-login.php debe tener el comentario AUTH-RA2 (P2) RE-AUDIT-AUTH FIX que documenta la eliminación del wp_nonce_field muerto.' );

		// El wp_nonce_field('ltms_vendor_login', 'ltms_login_nonce') NO debe
		// estar presente en el archivo (era código muerto — el JS no usaba
		// ltms_login_nonce ni el action ltms_vendor_login).
		// NOTA: el substring puede aparecer en el comentario del fix, asi que
		// verificamos que no haya una invocación PHP funcional (sin el prefijo
		// de comentario // o #).
		// Buscamos líneas que contengan wp_nonce_field( sin ser comentario.
		$has_live_nonce_call = false;
		foreach ( explode( "\n", $src ) as $line ) {
			$trimmed = ltrim( $line );
			// Saltar líneas que son comentarios PHP o HTML.
			if ( strncmp( $trimmed, '//', 2 ) === 0 ) continue;
			if ( strncmp( $trimmed, '#', 1 ) === 0 ) continue;
			if ( strpos( $trimmed, '<!--' ) === 0 ) continue;
			if ( strpos( $trimmed, '*' ) === 0 ) continue;
			// Buscar invocación funcional wp_nonce_field( no comentada.
			if ( strpos( $line, 'wp_nonce_field(' ) !== false
			     && strpos( $line, '//' ) === false
			     && strpos( $line, '#' ) === false ) {
				$has_live_nonce_call = true;
				break;
			}
		}
		$this->assertFalse( $has_live_nonce_call,
			'AUTH-RA2: form-login.php NO debe tener invocación funcional wp_nonce_field(...). El nonce real viaja via wp_localize_script(ltmsAuth, nonce: wp_create_nonce(ltms_auth_nonce)).' );
	}

	public function test_ra2_login_form_still_has_form_tag(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		$src  = file_get_contents( $file );

		// Sanity: el form debe seguir existiendo (no rompimos la estructura).
		$this->assertStringContainsString( '<form id="ltms-login-form"', $src,
			'form-login.php debe preservar <form id="ltms-login-form">.' );

		// Sanity: el cierre </form> debe existir tambien en login.
		$this->assertStringContainsString( '</form>', $src,
			'form-login.php debe cerrar </form> (no fue tocado, sanity check).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-RA3 (P2) H-N5 — auth-handler get_users sin 'number' duplicado.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ra3_referral_validation_no_duplicate_number_key(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Auth handler file no disponible.' );
		}
		$src = file_get_contents( $file );

		// Localizar el bloque de validación de referral_code (produce del fix).
		$marker = strpos( $src, 'AUTH-RA3 (P2) RE-AUDIT-AUTH FIX' );
		$this->assertNotFalse( $marker, 'AUTH-RA3: el comentario del fix debe existir en el auth handler.' );

		// Tomar 800 chars a partir del marker para cubrir el cuerpo del get_users.
		$body = substr( $src, $marker, 800 );

		// La clave 'meta_key' => 'ltms_referral_code' debe seguir (no se rompió la validación).
		$this->assertStringContainsString( "'meta_key'   => 'ltms_referral_code'", $body,
			'AUTH-RA3: la validación de referral_code debe seguir consultando meta_key ltms_referral_code.' );

		// La clave 'number' => 1 debe aparecer EXACTAMENTE una vez en el bloque
		// get_users de referral (no duplicada).
		// Contar ocurrencias de "'number'     => 1," en el body.
		$count = substr_count( $body, "'number'     => 1," );
		$this->assertSame( 1, $count,
			'AUTH-RA3: la clave \'number\' => 1 debe aparecer EXACTAMENTE una vez en el get_users de validación de referral code. Antes del fix aparecia duplicada (líneas 585 y 587 originales).' );
	}

	public function test_ra3_referral_validation_still_clears_invalid_code(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque de validación.
		$marker = strpos( $src, 'AUTH-RA3 (P2) RE-AUDIT-AUTH FIX' );
		$body   = substr( $src, $marker, 1200 );

		// El comportamiento de "invalid code → clear it" debe seguir (no se rompió).
		$this->assertStringContainsString( "\$data['referral_code'] = '';", $body,
			'AUTH-RA3: si el referrer no se encuentra, $data[\'referral_code\'] debe resetearse a string vacío (comportamiento pre-existing preservado).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AUTH-RA4 (P1) H-N6 — login JS sigue data.data.redirect en branch error.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ra4_login_js_follows_redirect_in_error_branch(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$src = file_get_contents( $file );

		// Localizar el bloque del login form submit handler.
		$start = strpos( $src, "loginForm.addEventListener('submit'" );
		$this->assertNotFalse( $start, 'El handler de submit del login form debe existir.' );
		// Buffer 7000 — el handler creció tras UX-004 (sub-ciclo UX-AUDIT-REGISTER)
		// que añadió ~1200 chars de comentarios + typeof guard + loginMsg var dentro
		// del branch else ANTES del setTimeout/redirect AUTH-RA4. Con 5000 chars el
		// substr del else_body (que empieza en offset ~2838 dentro de $body) se
		// truncaba a 2162 chars reales (5000-2838) y el redirect AUTH-RA4 que
		// ahora vive 2221 chars después del else quedaba fuera del buffer.
		// 7000 cubre con margen amplio (handler completo ~5500 chars).
		$body = substr( $src, $start, 7000 );

		// El comentario del fix debe estar presente en el branch else.
		$this->assertStringContainsString( 'AUTH-RA4 (P1) RE-AUDIT-AUTH FIX', $body,
			'login JS debe tener el comentario AUTH-RA4 (P1) RE-AUDIT-AUTH FIX en el branch else del fetch.' );

		// La guarda if (data.data && data.data.redirect) debe existir en el
		// branch else (error), no solo en el success.
		// Localizamos el branch else y el success para comparar.
		$success_pos = strpos( $body, 'if (data.success)' );
		$else_pos    = strpos( $body, '} else {' );
		$this->assertNotFalse( $success_pos, 'Debe existir branch if (data.success).' );
		$this->assertNotFalse( $else_pos, 'Debe existir branch else para error.' );
		$this->assertLessThan( $else_pos, $success_pos,
			'El branch success debe ir antes del branch else.' );

		// Tomar el cuerpo del branch else (después de } else { hasta el cierre}).
		// Buffer 2500 — el branch else con los fixes AUTH-RA4 + UX-004 mide
		// ~2300 chars. Si vuelve a quedarse corto, refactorizar a strpos global
		// en lugar de substr (ver Leccion 34.2: "coverage gaps del ciclo previo
		// aparecen en JS/templates").
		$else_body = substr( $body, $else_pos, 3000 );

		// En el branch else debe haber una guarda data.data.redirect.
		$this->assertStringContainsString( 'data.data.redirect', $else_body,
			'AUTH-RA4: el branch else del login JS debe chequear data.data.redirect. Antes del fix, solo se usaba data.data.message y se ignoraba el redirect retornado por AUTH-01.' );

		// Y debe haber un setTimeout con window.location.href para seguir el redirect.
		$this->assertStringContainsString( 'window.location.href = redirectUrl', $else_body,
			'AUTH-RA4: el branch else debe asignar window.location.href = redirectUrl para seguir el redirect.' );
		$this->assertStringContainsString( 'setTimeout', $else_body,
			'AUTH-RA4: el redirect del branch else debe envolverse en setTimeout (delay para que el usuario lea el message antes del redirect automatico).' );
	}

	public function test_ra4_login_js_success_branch_still_has_redirect(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		$src  = file_get_contents( $file );

		// Sanity: el branch success debe seguir teniendo su redirect (no rompimos).
		$start = strpos( $src, "loginForm.addEventListener('submit'" );
		$this->assertNotFalse( $start, 'Login submit handler debe existir.' );
		// Buffer 3500 — el branch success está en pos ~2521 dentro del handler,
		// su redirect está en pos ~2595, cubierto cómodamente por 3500.
		$body = substr( $src, $start, 3500 );

		$success_pos = strpos( $body, 'if (data.success)' );
		$this->assertNotFalse( $success_pos, 'Branch success debe existir.' );
		$success_body = substr( $body, $success_pos, 400 );
		$this->assertStringContainsString( 'data.data.redirect', $success_body,
			'Sanity: el branch success del login JS debe seguir teniendo su redirect (no tocado por AUTH-RA4).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CROSS-CHECKS NO-REGRESIÓN — ciclo AUTH-AUDIT previo (AUTH-01..AUTH-10).
	// Verifica que los fixes de la re-auditoría no rompen los fixes previos.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_cross_auth01_unverified_email_block_preserved(): void {
		// AUTH-01 (P0): ajax_vendor_login debe seguir bloqueando vendors con
		// email no verificado. La presencia del comentario + check de
		// ltms_email_verified es la señal no-regresión.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-01 (P0) AUDIT-AUTH FIX', $src,
			'No-regresión: AUTH-01 (P0) AUDIT-AUTH FIX debe seguir presente (no tocado por AUTH-RA3).' );
		$this->assertStringContainsString( 'wp_logout();', $src,
			'No-regresión: AUTH-01 wp_logout() debe seguir presente.' );
		$this->assertStringContainsString( 'wp_clear_auth_cookie();', $src,
			'No-regresión: AUTH-01 wp_clear_auth_cookie() debe seguir presente.' );
	}

	public function test_cross_auth02_email_verify_invalidates_token_preserved(): void {
		// AUTH-02 (P0): handle_email_verification debe seguir invalidando el token.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-02 (P0) AUDIT-AUTH FIX', $src,
			'No-regresión: AUTH-02 (P0) AUDIT-AUTH FIX debe seguir presente.' );
		$this->assertStringContainsString( "delete_user_meta( \$user_id, 'ltms_email_verify_token' )", $src,
			'No-regresión: AUTH-02 debe seguir eliminando el token de verificación.' );
	}

	public function test_cross_auth04_google_oauth_profile_incomplete_check_preserved(): void {
		// AUTH-04 (P1): Google OAuth callback debe seguir sin autenticar
		// vendors con perfil incompleto.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Google OAuth file no disponible.' );
		}
		$src = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-04 (P1) AUDIT-AUTH FIX', $src,
			'No-regresión: AUTH-04 (P1) AUDIT-AUTH FIX debe seguir presente en Google OAuth.' );
		$this->assertStringContainsString( "get_user_meta( \$user_id, 'ltms_profile_incomplete', true )", $src,
			'No-regresión: AUTH-04 debe seguir leyendo el meta ltms_profile_incomplete.' );
	}

	public function test_cross_auth06_complete_profile_no_force_email_verified_preserved(): void {
		// AUTH-06 (P1): ajax_complete_profile NO debe forzar ltms_email_verified=1.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$start = strpos( $src, 'function ajax_complete_profile' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 16000 );
		$this->assertStringContainsString( 'AUTH-06 (P1) AUDIT-AUTH FIX', $body,
			'No-regresión: AUTH-06 debe seguir presente en ajax_complete_profile.' );
		$this->assertStringNotContainsString(
			"update_user_meta( \$user_id, 'ltms_email_verified', 1 )",
			$body,
			'No-regresión: AUTH-06 ajax_complete_profile NO debe forzar ltms_email_verified=1 (sigue delegado a handle_email_verification o Google OAuth).'
		);
	}

	public function test_cross_auth05_google_oauth_cookie_sanitization_preserved(): void {
		// AUTH-05 (P1): $_COOKIE['ltms_ref'] debe seguir sanitizado.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-05 (P1) AUDIT-AUTH FIX', $src,
			'No-regresión: AUTH-05 debe seguir presente.' );
		$this->assertStringContainsString( "sanitize_text_field( wp_unslash( \$_COOKIE['ltms_ref'] ) )", $src,
			'No-regresión: AUTH-05 $_COOKIE[ltms_ref] debe seguir sanitizado.' );
		$this->assertStringContainsString( 'strtoupper( substr( $raw_ref, 0, 8 ) )', $src,
			'No-regresión: AUTH-05 strtoupper + substr(0,8) debe seguir presente.' );
	}

	public function test_cross_auth10_login_throttle_expired_branch_preserved(): void {
		// AUTH-10 (P2): login throttle expired branch debe seguir forzando 1.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$start = strpos( $src, 'function ajax_vendor_login' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 3500 );
		$this->assertStringContainsString( 'AUTH-10 (P2) AUDIT-AUTH FIX', $body,
			'No-regresión: AUTH-10 debe seguir presente en login throttle.' );
		$this->assertStringContainsString(
			"ON DUPLICATE KEY UPDATE option_value = '1'",
			$body,
			'No-regresión: AUTH-10 en branch expirado debe seguir forzando option_value = 1.'
		);
	}

	public function test_cross_sslverify_transversal_c33_preserved_on_google_oauth(): void {
		// CICLO33-P1 invariante INTEGRATIONS-AUDIT: sslverify canonico en los
		// 2 wp_remote_* de Google OAuth (TOKEN_URL y USERINFO_URL). La re-auditoría
		// AUTH no debe destruir el cierre transversal del C33.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-google-oauth.php' );
		$src  = file_get_contents( $file );
		$this->assertStringContainsString( 'CICLO33-P1-SSL-GOOGLE-TOKEN FIX', $src,
			'No-regresión C33: el tag CICLO33-P1-SSL-GOOGLE-TOKEN debe seguir presente (sslverify en TOKEN_URL).' );
		$this->assertStringContainsString( 'CICLO33-P1-SSL-GOOGLE-USERINFO FIX', $src,
			'No-regresión C33: el tag CICLO33-P1-SSL-GOOGLE-USERINFO debe seguir presente (sslverify en USERINFO_URL).' );
		// Cardinalidad: debe haber exactamente 2 sslverify overrides en Google OAuth.
		$count = substr_count( $src, "'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY )" );
		$this->assertGreaterThanOrEqual( 2, $count,
			'No-regresión C33: Google OAuth debe tener al menos 2 calls con sslverify canonico (TOKEN_URL + USERINFO_URL).' );
	}

	public function test_cross_client_ip_safe_c31_preserved_on_auth_handler(): void {
		// CICLO31-P2 invariante transversal IP: LTMS_Core_Security::get_client_ip_safe()
		// debe seguir usandose en TODOS los throttle del auth handler (login,
		// register, verify_email, resend_verification, resend_verification_public,
		// complete_profile). Esto fue cerrado en C31 y verificado en ciclos
		// posteriores. La re-auditoría AUTH no debe migrar a otra resolución IP.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		// Cada call debe tener el comentario CICLO31-P2-CG-28-P2-6 FIX o usar
		// la función directamente. Contar ocurrencias de la llamada.
		$count = substr_count( $src, 'LTMS_Core_Security::get_client_ip_safe()' );
		$this->assertGreaterThanOrEqual( 5, $count,
			'No-regresión C31: auth handler debe usar LTMS_Core_Security::get_client_ip_safe() en al menos 5 sitios (login, register, verify_email, resend_public, complete_profile).' );
	}

	public function test_cross_ltms_auth_nonce_localization_preserved(): void {
		// La re-auditoría AUTH-RA2 elimina el wp_nonce_field muerto de
		// form-login.php, pero el nonce real viaja via wp_localize_script.
		// Verificamos que la localización siga presente en los 2 sitios
		// canonicos (frontend-assets.php y template-sellers-page.php).
		$file1 = $this->plugin_path( 'includes/frontend/class-ltms-frontend-assets.php' );
		$file2 = $this->plugin_path( 'includes/frontend/views/template-sellers-page.php' );
		$src1 = file_get_contents( $file1 );
		$src2 = file_get_contents( $file2 );
		$this->assertStringContainsString( "wp_create_nonce( 'ltms_auth_nonce' )", $src1,
			'No-regresión: class-ltms-frontend-assets.php debe seguir localizando wp_create_nonce(ltms_auth_nonce) como ltmsAuth.nonce.' );
		$this->assertStringContainsString( "wp_create_nonce( 'ltms_auth_nonce' )", $src2,
			'No-regresión: template-sellers-page.php debe seguir localizando wp_create_nonce(ltms_auth_nonce) como ltmsAuth.nonce.' );
	}

	public function test_cross_auth08_resend_verification_atomic_preserved(): void {
		// AUTH-08 (P2): ajax_resend_verification debe seguir con INSERT atomic.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$start = strpos( $src, 'function ajax_resend_verification' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 3000 );
		$this->assertStringContainsString( 'AUTH-08 (P2) AUDIT-AUTH FIX', $body,
			'No-regresión: AUTH-08 debe seguir presente en ajax_resend_verification.' );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1', $body,
			'No-regresión: AUTH-08 debe seguir usando INSERT...ON DUPLICATE KEY atomic.' );
	}

	public function test_cross_auth09_complete_profile_atomic_preserved(): void {
		// AUTH-09 (P2): ajax_complete_profile rate limit atomic.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$start = strpos( $src, 'function ajax_complete_profile' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 3500 );
		$this->assertStringContainsString( 'AUTH-09 (P2) AUDIT-AUTH FIX', $body,
			'No-regresión: AUTH-09 debe seguir presente en ajax_complete_profile.' );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1', $body,
			'No-regresión: AUTH-09 debe seguir usando INSERT...ON DUPLICATE KEY atomic en complete_profile.' );
	}

	public function test_cross_login_throttle_uses_atomic_increment_preserved(): void {
		// Login throttle (líneas 254-290) debe seguir usando INSERT...ON DUPLICATE.
		// Esto fue cerrado por INTEGRATIONS-AUDIT P0 + AUTH-10. Verificamos que
		// la re-auditoría no haya migrado a get_transient (regresión).
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		$src  = file_get_contents( $file );
		$start = strpos( $src, 'function ajax_vendor_login' );
		$this->assertNotFalse( $start );
		// LOGIN-ERR-CLARITY (2026-09-04): se anadio un comentario de trazabilidad
		// al inicio de ajax_vendor_login, desplazando el throttle; aumentar slice.
		$body = substr( $src, $start, 5000 );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1', $body,
			'No-regresión: login throttle debe seguir usando INSERT...ON DUPLICATE KEY atomic (no regresar a get_transient).' );
	}
}
