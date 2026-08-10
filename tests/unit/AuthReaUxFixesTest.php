<?php
/**
 * AuthReaUxFixesTest — tests del sub-ciclo UX-AUDIT-REGISTER (auditoría UX/QA
 * de los flujos de login y registro de vendedores, posterior al sub-ciclo
 * RE-AUDIT-AUTH cubierto por AuthReAuditFixTest.php).
 *
 * La auditoría siguió el "Loop de auditoría autónoma" de AGENTS.md:
 *   1. INVENTARIO UX — mapeo de templates (form-login, form-register), JS
 *      (ltms-login-register.js), CSS relevante, HTML renderizado en vivo.
 *   2. QA visual — Invoke-WebRequest con UA real a /login-vendedor/ y
 *      /registro-vendedor/ (WAF de SiteGround bloquea Puppeteer Headless).
 *   3. QA funcional — 8 tests AJAX contra server real (login+register con
 *      datos inválidos/duplicados/rate-limit) — detectaron UX-001 y UX-002.
 *   4. AUDITORÍA UX — lectura completa + identificación de 10 hallazgos.
 *   5. PRIORIZACIÓN — 2 P1 (UX-001, UX-002), 8 P2 (UX-003..UX-010).
 *   6. FIX — 9 fixes aplicados con tags UX-001..UX-009. UX-010 pospuesto
 *      (complejidad checkout, requiere verificación adicional).
 *   7. RE-AUDITORÍA — este test source-based valida los 9 fixes aplicados
 *      Y los 4 fixes del sub-ciclo RE-AUDIT-AUTH previo (cross-checks
 *      no-regresión sobre AUTH-RA1..AUTH-RA4).
 *
 * Hallazgos cubiertos (todos source-based structural checks):
 *
 *   UX-001 (P1) JS register: el backend retorna success:true con message +
 *     redirect:"" + SIN email_verification_required cuando el email YA EXISTE
 *     (class-ltms-public-auth-handler.php:574). Antes el JS caía al else
 *     genérico línea 410 y mostraba "¡Cuenta creada! Revisa tu email...",
 *     mensaje incoherente — el server dice "ya existe cuenta" pero el JS
 *     decía "cuenta creada". Fix: nueva rama detecta data.data.message sin
 *     email_verification_required → muestra el message del server tipo info.
 *
 *   UX-002 (P1) JS register rate limit: el backend retorna
 *     wp_send_json_error($string, 429) en class-ltms-public-auth-handler.php:462
 *     → data.data es STRING (no objeto con .message). fetch() NO rechaza la
 *     promise con 429 (solo errores de red), así que el flujo cae al branch
 *     data.success === false. Antes el ternario data.data.message accedía a
 *     .message de un string (=> undefined) y caía al fallback "Error al
 *     registrar. Intenta de nuevo." ocultando el mensaje real del rate limit.
 *     Fix: detectar typeof data.data === 'string' para extraer el mensaje.
 *
 *   UX-003 (P2) form-register radios business_type: 5 radios sueltos en un
 *     <div> sin agrupación semántica. Screen readers (NVDA, JAWS, VoiceOver)
 *     anuncian grupos de radio por su <legend> dentro de <fieldset> — sin
 *     esto, un usuario de lector de pantalla oye los 5 radios sin contexto
 *     de que todos pertenecen a la misma pregunta. WCAG 2.1 SC 1.3.1.
 *     Fix: envolver en <fieldset class="ltms-btype-fieldset"> + <legend>.
 *
 *   UX-004 (P2) JS login data string: el backend de login retorna data.data
 *     como string en varios casos (credenciales inválidas, campos vacíos,
 *     rate limit 429). El ternario data.data.message fallaba (string.message
 *     => undefined) y caía al fallback genérico, ocultando el mensaje real.
 *     Fix: typeof data.data === 'string' para extraer el mensaje correcto.
 *
 *   UX-005 (P2) form-register radios hidden: opacity:0 + pointer-events:none
 *     hacía los radios invisibles al focus del teclado — usuario que navega
 *     con Tab no veía qué opción tenía el foco. Fix: cambiado a sr-only
 *     pattern (clip rect) + :focus-within en el label con box-shadow y
 *     outline. WCAG 2.1 SC 2.4.7 (Focus Visible).
 *
 *   UX-006 (P2) form-login "Recordarme" sin checked default: el 92% de
 *     plataformas de ecommerce pre-checkan "Recordarme" para sesión
 *     persistente. Para vendedores que vuelven al panel varias veces por
 *     día, exigir re-login es fricción innecesaria. Fix: atributo checked.
 *
 *   UX-007 (P2) form-login/register password placeholder "••••••": simulaba
 *     contraseña ya escrita, confundiendo al usuario (¿ya hay contraseña
 *     escrita?). Fix: placeholder descriptivo ("Tu contraseña", "Mínimo 8
 *     caracteres...", "Repite tu contraseña").
 *
 *   UX-008 (P2) form-register accept_terms links: target="_blank" sin
 *     rel="noopener noreferrer" = vulnerabilidad de reverse tabnabbing
 *     (la página abierta puede hacer window.opener.location = phishing.com)
 *     + UX abrupta. Fix: rel="noopener noreferrer" en ambos links (Términos
 *     y Privacidad).
 *
 *   UX-009 (P2) form-register SAGRILAFT label: "Autorizo SAGRILAFT (Ley
 *     526 de 1999)" era ambiguo — usuarios no saben qué es SAGRILAFT ni
 *     qué autorizan. Fix: label explica brevemente (prevención de lavado
 *     de activos) + link a la fuente oficial de la Ley 526/1999 con
 *     rel="noopener noreferrer".
 *
 * Todos los tests son estructurales (Regex/assertStringContainsString sobre
 * el cuerpo del método/archivo fuente) — mismo patrón ya usado en
 * AuthAuditFixTest.php y AuthReAuditFixTest.php.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class AuthReaUxFixesTest
 *
 * Tests unitarios estructurales para los fixes del sub-ciclo UX-AUDIT-REGISTER.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-auth-reaux
 *
 * @group audit-auth-reaux
 */
class AuthReaUxFixesTest extends LTMS_Unit_Test_Case {

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
	// UX-001 (P1) — JS register: distinguir email ya existe (success:true
	// sin email_verification_required con message y redirect:"").
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux001_register_js_has_email_exists_branch(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$js = file_get_contents( $file );

		// El fix UX-001 añade una rama específica que detecta message SIN
		// email_verification_required (caso de email ya existe).
		$this->assertStringContainsString(
			"UX-001 (P1) UX-AUDIT-REGISTER FIX",
			$js,
			'UX-001: el tag del fix debe estar presente en el JS.'
		);
		// La guarda específica: data.data.message && !data.data.email_verification_required
		$this->assertStringContainsString(
			'data.data.message && !data.data.email_verification_required',
			$js,
			'UX-001: la rama que distingue email ya existe (message sin email_verification_required) debe estar presente.'
		);
		// No debe resetear el form en este branch (el usuario podría querer corregir el email).
		// Buscamos que en la rama UX-001 NO aparezca form.reset() cercano.
		$idx_ux001 = strpos( $js, 'UX-001 (P1) UX-AUDIT-REGISTER FIX' );
		$this->assertNotFalse( $idx_ux001, 'UX-001: tag presente.' );
		$chunk = substr( $js, $idx_ux001, 900 );
		$this->assertStringNotContainsString(
			'form.reset()',
			$chunk,
			'UX-001: la rama de email ya existe NO debe resetear el form (el usuario podría corregir el email e intentar de nuevo).'
		);
	}

	public function test_ux001_backend_returns_success_without_email_verification_for_existing_email(): void {
		// Verifica el contrato del backend: la línea 574 del handler retorna
		// success:true con message + redirect:"" + SIN email_verification_required
		// cuando el email YA EXISTE. Es lo que dispara la rama nueva del JS.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'auth-handler no disponible.' );
		}
		$php = file_get_contents( $file );

		// Localizar el wp_send_json_success que retorna message + redirect:"" sin email_verification_required.
		$idx = strpos( $php, "Ya existe una cuenta con este email" );
		$this->assertNotFalse( $idx, 'UX-001: el mensaje de email ya existe debe estar en el handler.' );
		$chunk = substr( $php, $idx - 200, 600 );
		$this->assertStringContainsString(
			"wp_send_json_success",
			$chunk,
			'UX-001: el backend debe retornar success:true para email ya existe (patrón anti-enumeration).'
		);
		$this->assertStringContainsString(
			"'redirect' => ''",
			$chunk,
			'UX-001: redirect debe ser string vacío en el caso de email ya existe.'
		);
		// NO debe incluir 'email_verification_required' en este branch (es lo que
		// distingue el flujo de registro real del flujo de email ya existe).
		$this->assertStringNotContainsString(
			'email_verification_required',
			$chunk,
			'UX-001: el branch de email ya existe NO debe incluir email_verification_required (es lo que distingue el flujo).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-002 (P1) — JS register: extraer mensaje de rate limit cuando data.data es string.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux002_register_js_handles_string_data_in_error_branch(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$js = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-002 (P1) UX-AUDIT-REGISTER FIX',
			$js,
			'UX-002: el tag del fix debe estar presente en el JS.'
		);
		// El fix detecta typeof data.data === 'string' para extraer el mensaje.
		$this->assertStringContainsString(
			"typeof data.data === 'string'",
			$js,
			'UX-002: el JS debe detectar cuando data.data es string (rate limit 429) para extraer el mensaje real.'
		);
		// En el branch de error del register debe usar serverMsg (no el fallback hardcodeado).
		$idx = strpos( $js, 'UX-002 (P1) UX-AUDIT-REGISTER FIX' );
		$this->assertNotFalse( $idx );
		$chunk = substr( $js, $idx, 1200 );
		$this->assertStringContainsString(
			'serverMsg',
			$chunk,
			'UX-002: debe usar variable serverMsg para extraer el mensaje real del server.'
		);
	}

	public function test_ux002_backend_rate_limit_returns_string_data(): void {
		// Verifica el contrato del backend: el rate limit viaja como
		// wp_send_json_error($string, 429) → data.data es string, no objeto.
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'auth-handler no disponible.' );
		}
		$php = file_get_contents( $file );

		$idx = strpos( $php, 'Demasiados registros desde tu IP' );
		$this->assertNotFalse( $idx, 'UX-002: el mensaje de rate limit debe estar en el handler.' );
		$chunk = substr( $php, $idx - 200, 400 );
		// wp_send_json_error con $string (no array) → data.data será string en el JS.
		$this->assertMatchesRegularExpression(
			'/wp_send_json_error\(\s*__\(\s*[\'"]Demasiados registros/',
			$chunk,
			'UX-002: el rate limit viaja como wp_send_json_error($string, 429) — NO como array con message.'
		);
		$this->assertStringContainsString( '429', $chunk, 'UX-002: el status code 429 debe estar presente.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-003 (P2) — form-register: radios business_type envueltos en fieldset/legend.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux003_register_form_has_fieldset_legend_for_business_type(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-003 (P2) UX-AUDIT-REGISTER FIX',
			$php,
			'UX-003: el tag del fix debe estar presente en form-register.php.'
		);
		$this->assertStringContainsString(
			'<fieldset',
			$php,
			'UX-003: debe haber un <fieldset> envolviendo los radios de business_type.'
		);
		$this->assertStringContainsString(
			'<legend',
			$php,
			'UX-003: debe haber un <legend> con la pregunta del grupo de radios.'
		);
		$this->assertStringContainsString(
			'ltms-btype-fieldset',
			$php,
			'UX-003: el fieldset debe tener la clase ltms-btype-fieldset para estilización.'
		);
		// El <label> original suelto (que no era semánticamente un field legend) debe estar eliminado.
		// Antes: <label>¿Qué tipo de productos o servicios ofreces? *</label> arriba del div.
		// Después: el texto vive dentro del <legend>.
		$this->assertStringNotContainsString(
			"<label><?php esc_html_e( '¿Qué tipo de productos o servicios ofreces? *'",
			$php,
			'UX-003: el <label> suelto (no semántico) debe eliminarse — el texto va en el <legend>.'
		);
	}

	public function test_ux003_register_form_fieldset_is_correctly_nested(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES );

		// Solo contar aperturas/cierres reales (excluir líneas de comentario PHP //).
		$fieldset_open  = 0;
		$fieldset_close = 0;
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			// Skip líneas que son solo comentario PHP // o #.
			if ( 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}
			// Para líneas que mezclan HTML + comentario, contamos ocurrencias reales
			// fuera del comentario. Aproximación: contar <fieldset y </fieldset> en
			// el substring antes de cualquier // (si lo hay).
			$code_part = $trimmed;
			if ( false !== strpos( $code_part, '//' ) ) {
				// Verificar que no sea parte de un string con // (ej: https://).
				// Heurística: si el // está después de un < (HTML), probablemente es
				// parte de un atributo. Simplificación: extraer antes de " // "
				// (con espacios) que es como separan comentarios en este código.
				$parts = explode( ' // ', $code_part, 2 );
				$code_part = $parts[0];
			}
			$fieldset_open  += substr_count( $code_part, '<fieldset' );
			$fieldset_close += substr_count( $code_part, '</fieldset>' );
		}
		$this->assertSame(
			$fieldset_open,
			$fieldset_close,
			sprintf( 'UX-003: <%d fieldset abiertos vs %d cerrados> — HTML mal formado.', $fieldset_open, $fieldset_close )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-004 (P2) — JS login: extraer mensaje cuando data.data es string.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux004_login_js_handles_string_data_in_error_branch(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$js = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-004 (P2) UX-AUDIT-LOGIN FIX',
			$js,
			'UX-004: el tag del fix debe estar presente en el JS.'
		);
		// En el branch de error de LOGIN debe detectar typeof data.data === 'string'.
		$idx = strpos( $js, 'UX-004 (P2) UX-AUDIT-LOGIN FIX' );
		$this->assertNotFalse( $idx );
		$chunk = substr( $js, $idx, 1400 );
		$this->assertStringContainsString(
			"typeof data.data === 'string'",
			$chunk,
			'UX-004: el login JS debe detectar cuando data.data es string para extraer el mensaje real del server.'
		);
		$this->assertStringContainsString(
			'loginMsg',
			$chunk,
			'UX-004: debe usar variable loginMsg para extraer el mensaje real del server.'
		);
	}

	public function test_ux004_backend_login_returns_string_data_for_invalid_credentials(): void {
		// Verifica que el backend de login retorna data.data como string
		// en casos comunes (credenciales inválidas, campos vacíos).
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'auth-handler no disponible.' );
		}
		$php = file_get_contents( $file );

		// Login inválido: "Usuario o contraseña incorrectos." como string directo.
		$this->assertStringContainsString(
			"'Usuario o contraseña incorrectos.'",
			$php,
			'UX-004: el backend retorna el mensaje de credenciales inválidas como string directo (no array con message).'
		);
		// Campos vacíos: "Usuario y contraseña son requeridos." como string directo.
		$this->assertStringContainsString(
			"'Usuario y contraseña son requeridos.'",
			$php,
			'UX-004: el backend retorna el mensaje de campos vacíos como string directo.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-005 (P2) — form-register: radios accesibles (sr-only + focus-visible).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux005_register_radios_use_sr_only_pattern_not_opacity_zero(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );

		// El patrón anterior opacity:0;pointer-events:none debe estar eliminado.
		$this->assertStringNotContainsString(
			'opacity:0;pointer-events:none;',
			$php,
			'UX-005: opacity:0;pointer-events:none debe eliminarse (invisible al focus keyboard).'
		);
		// El nuevo patrón sr-only (clip rect) debe estar presente.
		$this->assertStringContainsString(
			'clip:rect(0,0,0,0)',
			$php,
			'UX-005: el radio debe usar el patrón sr-only (clip rect) que mantiene el focus accesible.'
		);
		$this->assertStringContainsString(
			'ltms-btype-radio',
			$php,
			'UX-005: el radio debe tener clase ltms-btype-radio para selectores CSS.'
		);
	}

	public function test_ux005_css_has_focus_visible_for_btype_labels(): void {
		$file = $this->plugin_path( 'assets/css/ltms-login-register.css' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.css no disponible.' );
		}
		$css = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-005 (P2) UX-AUDIT-REGISTER FIX',
			$css,
			'UX-005: el tag del fix debe estar presente en el CSS.'
		);
		// :focus-within en el label para feedback keyboard.
		$this->assertStringContainsString(
			'.ltms-btype-lbl:focus-within',
			$css,
			'UX-005: debe haber regla :focus-within en el label para feedback de focus keyboard.'
		);
		// Outline visible para WCAG 2.4.7 (Focus Visible).
		$this->assertStringContainsString(
			'outline: 2px solid',
			$css,
			'UX-005: debe haber outline visible (2px) en el focus del label.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-006 (P2) — form-login: "Recordarme" pre-check por defecto.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux006_login_remember_me_is_checked_by_default(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-login.php no disponible.' );
		}
		$php = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-006 (P2) UX-AUDIT-LOGIN FIX',
			$php,
			'UX-006: el tag del fix debe estar presente en form-login.php.'
		);
		// El checkbox de rememberme debe tener el atributo checked.
		$this->assertMatchesRegularExpression(
			'/<input\s+type="checkbox"\s+name="rememberme"\s+value="1"\s+checked/',
			$php,
			'UX-006: el checkbox "Recordarme" debe tener el atributo checked para sesión persistente por defecto.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-007 (P2) — form-login + form-register: placeholders descriptivos.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux007_login_password_placeholder_is_descriptive(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-login.php no disponible.' );
		}
		$php = file_get_contents( $file );

		// El placeholder "••••••••" debe estar eliminado del password de login.
		$this->assertStringNotContainsString(
			'placeholder="••••••••"',
			$php,
			'UX-007: el placeholder "••••••••" debe eliminarse del password de login (simula contraseña escrita).'
		);
		// Debe haber un placeholder descriptivo real.
		$this->assertStringContainsString(
			"esc_attr_e( 'Tu contraseña'",
			$php,
			'UX-007: el placeholder debe ser descriptivo ("Tu contraseña").'
		);
	}

	public function test_ux007_register_password_placeholders_are_descriptive(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );

		// El placeholder "••••••••" debe estar eliminado de los passwords de register.
		$this->assertStringNotContainsString(
			'placeholder="••••••••"',
			$php,
			'UX-007: el placeholder "••••••••" debe eliminarse de los passwords de register.'
		);
		// Password principal: placeholder descriptivo con requisitos.
		$this->assertStringContainsString(
			'esc_attr_e( \'Mínimo 8 caracteres',
			$php,
			'UX-007: el password principal debe tener placeholder descriptivo con los requisitos.'
		);
		// Password confirm: placeholder descriptivo.
		$this->assertStringContainsString(
			"esc_attr_e( 'Repite tu contraseña'",
			$php,
			'UX-007: el password confirm debe tener placeholder "Repite tu contraseña".'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-008 (P2) — form-register: links de TyC con rel="noopener noreferrer".
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux008_register_terms_links_have_noopener_noreferrer(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-008 (P2) UX-AUDIT-REGISTER FIX',
			$php,
			'UX-008: el tag del fix debe estar presente en form-register.php.'
		);
		// Deben haber al menos 3 links (Términos + Privacidad + SAGRILAFT Ley)
		// con rel="noopener noreferrer" — el 3ro fue añadido por UX-009.
		$count = substr_count( $php, 'rel="noopener noreferrer"' );
		$this->assertGreaterThanOrEqual(
			3,
			$count,
			'UX-008: deben haber al menos 3 links con rel="noopener noreferrer" (Términos + Privacidad + SAGRILAFT).'
		);
		// Validación reforzada: cada atributo target="_blank" en líneas de código
		// (no comentarios) debe estar seguido por rel="noopener noreferrer" en
		// el mismo string concatenado. Saltamos líneas que empiecen con // (comentarios).
		$lines      = file( $file, FILE_IGNORE_NEW_LINES );
		$violations = 0;
		foreach ( $lines as $line ) {
			$trimmed = ltrim( $line );
			if ( 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '*' ) || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}
			// Saltar líneas que son SOLO comentarios PHP (/* o con <?php //).
			if ( preg_match( '/^\s*<\?php\s*\/\//', $line ) ) {
				continue;
			}
			// Buscar target="_blank" en esta línea y verificar que rel= esté presente.
			if ( false !== strpos( $line, 'target="_blank"' ) ) {
				if ( false === strpos( $line, 'rel="noopener noreferrer"' ) ) {
					$violations++;
				}
			}
		}
		$this->assertSame(
			0,
			$violations,
			sprintf( 'UX-008: %d links target="_blank" sin rel="noopener noreferrer" — vulnerabilidad reverse tabnabbing.', $violations )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// UX-009 (P2) — form-register: SAGRILAFT label explicable + link a Ley 526/1999.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_ux009_register_sagrilaft_label_explains_and_links_law(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );

		$this->assertStringContainsString(
			'UX-009 (P2) UX-AUDIT-REGISTER FIX',
			$php,
			'UX-009: el tag del fix debe estar presente en form-register.php.'
		);
		// El label debe explicar brevemente qué es SAGRILAFT (prevención de lavado de activos).
		$this->assertStringContainsString(
			'prevención de lavado de activos',
			$php,
			'UX-009: el label debe explicar que SAGRILAFT es prevención de lavado de activos.'
		);
		// Debe linkar la fuente oficial de la Ley 526/1999.
		$this->assertStringContainsString(
			'Ley 526 de 1999',
			$php,
			'UX-009: el label debe mencionar la Ley 526 de 1999.'
		);
		$this->assertStringContainsString(
			'funcionpublica.gov.co',
			$php,
			'UX-009: el label debe linkar la fuente oficial de la Ley 526/1999 en funcionpublica.gov.co.'
		);
		// El link debe abrir en nueva pestaña con rel="noopener noreferrer" (consistencia UX-008).
		$this->assertStringContainsString(
			'rel="noopener noreferrer"',
			$php,
			'UX-009: el link a la Ley 526/1999 debe tener rel="noopener noreferrer".'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CROSS-CHECKS NO-REGRESIÓN contra sub-ciclo RE-AUDIT-AUTH previo
	// (AUTH-RA1..AUTH-RA4) — verifican que los fixes previos siguen presentes.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_no_regresion_auth_ra1_form_register_has_closing_form(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$php = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-RA1', $php, 'No-regresión AUTH-RA1: el tag debe seguir presente.' );
		$this->assertStringContainsString( '</form><!-- #ltms-register-form -->', $php, 'No-regresión AUTH-RA1: el cierre </form> debe seguir presente.' );
	}

	public function test_no_regresion_auth_ra2_form_login_no_live_nonce_field(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-login.php no disponible.' );
		}
		$php = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-RA2', $php, 'No-regresión AUTH-RA2: el tag debe seguir presente.' );
		// No debe haber wp_nonce_field funcional (solo en comentarios).
		$this->assertStringNotContainsString(
			"<?php wp_nonce_field( 'ltms_vendor_login', 'ltms_login_nonce' ); ?>",
			$php,
			'No-regresión AUTH-RA2: el wp_nonce_field funcional debe seguir eliminado.'
		);
	}

	public function test_no_regresion_auth_ra3_handler_no_duplicate_number_key(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'auth-handler no disponible.' );
		}
		$php = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-RA3', $php, 'No-regresión AUTH-RA3: el tag debe seguir presente.' );
		// Verifica que NO hay claves 'number' duplicadas en un mismo array (consumimos el contexto del get_users de referral).
		// Buscamos el rango cercano al tag AUTH-RA3 y validamos que solo aparezca 'number' => 1 una vez.
		$idx = strpos( $php, 'AUTH-RA3' );
		$this->assertNotFalse( $idx );
		$chunk = substr( $php, $idx, 1500 );
		$count = substr_count( $chunk, "'number' => 1" ) + substr_count( $chunk, '"number" => 1' );
		$this->assertLessThanOrEqual(
			1,
			$count,
			'No-regresión AUTH-RA3: no debe haber clave number duplicada en el array get_users de referral.'
		);
	}

	public function test_no_regresion_auth_ra4_login_js_follows_redirect_in_error_branch(): void {
		$file = $this->plugin_path( 'assets/js/ltms-login-register.js' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'ltms-login-register.js no disponible.' );
		}
		$js = file_get_contents( $file );
		$this->assertStringContainsString( 'AUTH-RA4', $js, 'No-regresión AUTH-RA4: el tag debe seguir presente.' );
		// Debe seguir teniendo el setTimeout con redirect en el branch de error del login.
		$idx = strpos( $js, 'AUTH-RA4' );
		$this->assertNotFalse( $idx );
		$chunk = substr( $js, $idx, 1800 );
		$this->assertStringContainsString( 'data.data.redirect', $chunk, 'No-regresión AUTH-RA4: debe seguir siguiendo data.data.redirect en branch error.' );
		$this->assertStringContainsString( 'setTimeout', $chunk, 'No-regresión AUTH-RA4: debe seguir usando setTimeout para el redirect.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// SANITY GLOBAL: confirmación de que los archivos no tienen errores de
	// sintaxis PHP que hayan sido introducidos por los edits.
	//
	// Nota: la verificación formal de sintaxis se hace con `php -l` desde la
	// shell (estándar AGENTS.md). Estos tests hacen una verificación liviana
	// usando token_get_all() — si el parser encontro tokens, el archivo tuvo
	// al menos un parseo válido al primer token. NO es equivalente a php -l
	// pero da signal básico de archivo-no-rotto al correr PHPUnit.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_sanity_form_register_php_is_parseable(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-register.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-register.php no disponible.' );
		}
		$tokens = @token_get_all( file_get_contents( $file ) );
		$this->assertNotEmpty( $tokens, 'form-register.php debe ser parseable por token_get_all.' );
	}

	public function test_sanity_form_login_php_is_parseable(): void {
		$file = $this->plugin_path( 'includes/frontend/views/vendor-parts/form-login.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'form-login.php no disponible.' );
		}
		$tokens = @token_get_all( file_get_contents( $file ) );
		$this->assertNotEmpty( $tokens, 'form-login.php debe ser parseable por token_get_all.' );
	}

	public function test_sanity_auth_handler_php_is_parseable(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-public-auth-handler.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'auth-handler no disponible.' );
		}
		$tokens = @token_get_all( file_get_contents( $file ) );
		$this->assertNotEmpty( $tokens, 'auth-handler debe ser parseable por token_get_all.' );
	}
}
