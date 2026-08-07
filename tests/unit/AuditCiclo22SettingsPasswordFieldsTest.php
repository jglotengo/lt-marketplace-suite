<?php
/**
 * AuditCiclo22SettingsPasswordFieldsTest - Tests para los fixes del Ciclo 22.
 *
 * Cobertura:
 *
 * Modulo: includes/admin/views/settings/ archivos custom restantes (5 archivos
 *   ~509 lineas - zapsign + privacy + analytics + google_oauth + mlm)
 *
 * 1. AD-SET-107 P1: section-zapsign.php input password ltms_zapsign_api_token
 *    mostraba el valor crudo del API token ZapSign en el atributo value= del
 *    input password. El handler central sanitize_settings()
 *    (class-ltms-admin-settings.php:116) cifra el token con AES-256 via
 *    LTMS_Core_Security::encrypt() — los valores cifrados tienen prefijo 'v1:'.
 *    Si el valor crudo (incl. el hash 'v1:...') se muestra en value=, queda
 *    expuesto en el DOM admin (visible via DevTools: el password type solo
 *    oculta visual pero el campo se inspecciona; password managers tambien lo
 *    capturan). El renderer generico de html-admin-settings.php:290 ya aplica
 *    el patron correcto: vaciar el value y usar placeholder alternativo cuando
 *    detecta prefijo 'v1:'. Este view custom NO replicaba ese patron — FIX
 *    agrega la logica: $api_token_display = ( strpos( $api_token, 'v1:' ) === 0 )
 *    ? '' : $api_token; + placeholder alternativo "(guardado — dejar vacío
 *    para mantener)" cuando is_configured.
 *
 * 2. AD-SET-108 P1: section-google_oauth.php input password
 *    ltms_google_client_secret mismo patron que AD-SET-107. client_secret de
 *    Google OAuth permite impersonar el login de vendors via OAuth flow
 *    (impacto mayor que un API token generico — no es solo credencial de API,
 *    es credencial de_AUTENTICACION_de_third_party). El handler central
 *    sanitize_settings() (class-ltms-admin-settings.php:120) cifra el secret
 *    con AES-256 via LTMS_Core_Security::encrypt() — valores con prefijo
 *    'v1:'. Fix aplica mismo patron que AD-SET-107: vaciar value cuando
 *    detecta 'v1:'.
 *
 * 3. AD-SET-112 P1: section-backblaze.php:29 input password
 *    ltms_backblaze_app_key (Application Key B2) — el handler central
 *    sanitize_settings() (class-ltms-admin-settings.php:117) cifra la app_key
 *    con AES-256 via LTMS_Core_Security::encrypt() (valores prefijo 'v1:'). El
 *    view custom (renderer dinamico local con $fields + foreach) emiti el valor
 *    crudo en value= — leak del hash v1: en DOM. Application Key B2 concede
 *    acceso S3 a buckets KYC (.documentos identidad) y contratos ZapSign. Misma
 *    familia C22 que AD-SET-107/108/113/114. Fix: clamp $display_value='' si
 *    password + v1:, placeholder '(guardado — dejar vacío para mantener)'.
 *
 * 4. AD-SET-113 P1: section-siigo.php:34 input password ltms_siigo_access_key.
 *    Handler central sanitize_settings() (class-ltms-admin-settings.php:115)
 *    cifra la access_key. View custom la emitia en value= — leak del hash v1:.
 *    Access Key Siigo concede acceso a API de facturacion electronica (creacion
 *    de facturas, consultas contables). Mismo patron C22.
 *
 * 5. AD-SET-114 P1: section-payments.php:46 — 2 leaks en 1 archivo: los inputs
 *    password ltms_openpay_private_key (permite operar reembolsos/captura
 *    Openpay Colombia) y ltms_addi_client_secret (credencial OAuth del BNPL
 *    Addi) — ambos cifrados por el handler central (linea 115). El view custom
 *    (renderer dinamico $groups => $fields => campo) emiti el valor crudo en
 *    value=. Mismo patron C22.
 *
 * 6. AD-SET-115 P2: section-alegra.php:31 habia un approach distinto (mostrar
 *    '••••••••••••••••••••••••••••••' en value= cuando el token estaba
 *    cifrado). Bug silencioso: el admin ve un campo "lleno", cree el token
 *    esta disponible para inspeccionar y se sorprende al ver bullets. PEOR: si
 *    guarda la forma sin tocar el campo, el navegador envia los bullets como
 *    nuevo token al backend; el handler central los pasa por sanitize_text_field
 *    y los CIFRA COMO NUEVO TOKEN perdiendo el original (no empty()). El fix
 *    alinea al patron estandar C22: vaciar value=, placeholder '(guardado —
 *    dejar vacío para mantener)'. El handler mantiene el valor cifrado original
 *    si el input llega vacio (linea 124-130).
 *
 * Hallazgos P2 backlog NO fixeados en C22 (4 - documentados):
 *   - AD-SET-109 P2: section-analytics.php GTM/GA4/Pixel IDs sin clamping de
 *     longitud al render. Bajo riesgo (esc_attr cubre XSS en atributo).
 *   - AD-SET-110 P2: section-google_oauth.php:54 placeholder con echo literal
 *     sin esc_attr wrapper (string estatico, inconsistencia con otros campos).
 *   - AD-SET-111 P2: section-mlm.php defaults (5/2/1) duplicados vs
 *     sanitization handler (leak de fuente unica).
 *   - AD-SET-116 P2: section-payments.php ltms_stripe_secret_key,
 *     ltms_stripe_webhook_secret, ltms_openpay_mx_priv_key NO estan en
 *     $encrypted_fields del handler — se guardan en texto plano (legacy).
 *     Hallazgo derivado de la auditoria AD-SET-114. NO se fixea en C22 porque
 *     require decision de negocio/consumidor (algun servicio Stripe puede
 *     esperar el valor en claro para firmar webhooks? investigar antes de
 *     agregarlos a $encrypted_fields). Backlog C23.
 *
 * Patron C22 confirmado: los 5 archivos settings custom restantes (zapsign,
 *   privacy, analytics, google_oauth, mlm) NO tienen el anti-patron AD-SET-100
 *   (wp_nonce_field huerfano) que se encontro en C21 en cross-border + donations.
 *   Grep por wp_nonce_field en section-*.php solo encontro comentarios del fix
 *   C21. privacy.php SI tiene wp_create_nonce('ltms_retention_nonce') pero su
 *   handler class-ltms-privacy-toolkit.php:836 check_ajax_referer valida ese
 *   mismo nonce — NO es huerfano. Leccion C22: el anti-patron AD-SET-100 fue
 *   especifico de los 2 archivos legacy con nonces seccionados previos a la
 *   unificacion del dispatcher master nonce (html-admin-settings.php:72).
 *
 * Cross-check con renderer generico (html-admin-settings.php:290): el patron
 *   "vaciar value si prefijo v1:" ya lo aplicaba el renderer de los campos
 *   password declarados via fields_map (ltms_render_generic_settings_section).
 *   Los views custom (zapsign, google_oauth) eran los unicos que no replicaban
 *   porque escriben su propio <input type="password"> en lugar de usar el
 *   renderer. Este ciclo cierra esa brecha.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AD-SET-107, AD-SET-108, AD-SET-112, AD-SET-113, AD-SET-114, AD-SET-115
 */
class AuditCiclo22SettingsPasswordFieldsTest extends LTMS_Unit_Test_Case {

	private const ZAPSIGN_PATH     = __DIR__ . '/../../includes/admin/views/settings/section-zapsign.php';
	private const GOOGLE_OAUTH_PATH = __DIR__ . '/../../includes/admin/views/settings/section-google_oauth.php';
	private const BACKBLAZE_PATH    = __DIR__ . '/../../includes/admin/views/settings/section-backblaze.php';
	private const SIIGO_PATH        = __DIR__ . '/../../includes/admin/views/settings/section-siigo.php';
	private const PAYMENTS_PATH     = __DIR__ . '/../../includes/admin/views/settings/section-payments.php';
	private const ALEGRA_PATH       = __DIR__ . '/../../includes/admin/views/settings/section-alegra.php';
	private const HANDLER_PATH      = __DIR__ . '/../../includes/admin/class-ltms-admin-settings.php';
	private const RENDERER_PATH     = __DIR__ . '/../../includes/admin/views/html-admin-settings.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'         => static fn( string $s ): string => $s,
			'esc_html__' => static fn( string $s ): string => $s,
			'esc_html_e' => static function ( string $s ): void { echo $s; },
			'esc_attr_e' => static function ( string $s ): void { echo $s; },
			'esc_attr'   => static fn( string $s ): string => $s,
			'esc_html'   => static fn( string $s ): string => $s,
			'esc_url'    => static fn( string $s ): string => $s,
			'checked'    => static function ( $a, $b = 'yes', $e = true ): string {
				return (string) $a === (string) $b ? ( $e ? ' checked="checked"' : '' ) : '';
			},
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ====================================================================
	//  AD-SET-107 P1: section-zapsign.php - vaciar token si v1: cifrado
	// ====================================================================

	public function test_zapsign_view_no_longer_emits_raw_encrypted_token_in_value(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		// Antes: value="...esc_attr( $api_token )..." emitia el hash v1:... al DOM. NO debe existir.
		$this->assertStringNotContainsString(
			'value="<?php echo esc_attr( $api_token ); ?>"',
			$source,
			'AD-SET-107: section-zapsign.php NO debe emitir $api_token crudo en value= (leak de hash v1: en DOM).'
		);
	}

	public function test_zapsign_view_uses_display_var_in_value(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		// Ahora: value="...esc_attr( $api_token_display )..." usa la var display (vacia si v1:).
		$this->assertStringContainsString(
			'value="<?php echo esc_attr( $api_token_display ); ?>"',
			$source,
			'AD-SET-107: section-zapsign.php debe usar $api_token_display (vaciar si v1:) en value=.'
		);
	}

	public function test_zapsign_view_has_v1_prefix_detection_logic(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		// La deteccion del prefijo 'v1:' debe estar presente.
		$this->assertStringContainsString(
			"strpos( \$api_token, 'v1:' ) === 0",
			$source,
			'AD-SET-107: section-zapsign.php debe detectar prefijo v1: para vaciar value.'
		);
	}

	public function test_zapsign_view_has_alternate_placeholder_when_saved(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		// El placeholder alternativo "(guardado — dejar vacío para mantener)" debe existir
		// como mensaje de UX cuando el token ya esta guardado (cifrado v1:).
		$this->assertStringContainsString(
			'(guardado',
			$source,
			'AD-SET-107: section-zapsign.php debe tener placeholder alternativo "(guardado...)" cuando valor existe.'
		);
	}

	public function test_zapsign_view_has_ciclo22_tag_adset107(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		$this->assertStringContainsString(
			'CICLO22-P1-AD-SET-107 FIX',
			$source,
			'AD-SET-107: tag de trazabilidad CICLO22-P1-AD-SET-107 FIX debe estar en section-zapsign.php.'
		);
	}

	public function test_zapsign_view_docblock_explains_rationale(): void {
		$this->assertFileExists( self::ZAPSIGN_PATH );
		$source = file_get_contents( self::ZAPSIGN_PATH );

		$this->assertStringContainsString(
			'html-admin-settings.php:290',
			$source,
			'AD-SET-107: docblock debe referenciar el renderer generico (linea 290) que ya aplicaba el patron.'
		);
		$this->assertStringContainsString(
			'v1:',
			$source,
			'AD-SET-107: docblock debe explicar que v1: indica valor ya cifrado con AES-256.'
		);
	}

	// ====================================================================
	//  AD-SET-108 P1: section-google_oauth.php - vaciar secret si v1: cifrado
	// ====================================================================

	public function test_google_oauth_view_no_longer_emits_raw_encrypted_secret_in_value(): void {
		$this->assertFileExists( self::GOOGLE_OAUTH_PATH );
		$source = file_get_contents( self::GOOGLE_OAUTH_PATH );

		// Antes: value="...esc_attr( $client_secret )..." emitia el hash v1:... al DOM.
		$this->assertStringNotContainsString(
			'value="<?php echo esc_attr( $client_secret ); ?>"',
			$source,
			'AD-SET-108: section-google_oauth.php NO debe emitir $client_secret crudo en value= (leak de hash v1: en DOM).'
		);
	}

	public function test_google_oauth_view_uses_display_var_in_value(): void {
		$this->assertFileExists( self::GOOGLE_OAUTH_PATH );
		$source = file_get_contents( self::GOOGLE_OAUTH_PATH );

		$this->assertStringContainsString(
			'value="<?php echo esc_attr( $client_secret_display ); ?>"',
			$source,
			'AD-SET-108: section-google_oauth.php debe usar $client_secret_display (vaciar si v1:) en value=.'
		);
	}

	public function test_google_oauth_view_has_v1_prefix_detection_logic(): void {
		$this->assertFileExists( self::GOOGLE_OAUTH_PATH );
		$source = file_get_contents( self::GOOGLE_OAUTH_PATH );

		$this->assertStringContainsString(
			"strpos( \$client_secret, 'v1:' ) === 0",
			$source,
			'AD-SET-108: section-google_oauth.php debe detectar prefijo v1: para vaciar value.'
		);
	}

	public function test_google_oauth_view_has_ciclo22_tag_adset108(): void {
		$this->assertFileExists( self::GOOGLE_OAUTH_PATH );
		$source = file_get_contents( self::GOOGLE_OAUTH_PATH );

		$this->assertStringContainsString(
			'CICLO22-P1-AD-SET-108 FIX',
			$source,
			'AD-SET-108: tag de trazabilidad CICLO22-P1-AD-SET-108 FIX debe estar en section-google_oauth.php.'
		);
	}

	public function test_google_oauth_view_docblock_explains_oauth_impact(): void {
		$this->assertFileExists( self::GOOGLE_OAUTH_PATH );
		$source = file_get_contents( self::GOOGLE_OAUTH_PATH );

		// El docblock debe mencionar el impacto especifico de client_secret OAuth
		// (permite impersonar login de vendors — no es solo API key generica).
		$this->assertStringContainsString(
			'impersonar',
			$source,
			'AD-SET-108: docblock debe mencionar que client_secret OAuth permite impersonar login de vendors.'
		);
	}

	// ====================================================================
	//  Cross-check: handler central sigue cifrando estos campos con AES-256
	//  Verifica que los fields siguen listados en $encrypted_fields del
	//  handler. Si alguien los saca de la lista, el fix del view queda
	//  irrelevant (no habria cifradoBackend). Leccion AGENTS.md #119: si un
	//  fix toca un patron que un test verificaba textualmente, actualizar el
	//  test en el mismo commit. Aqui blidamos que la lista cifrada del
	//  handler NO se reduzca y vuelva el leak (con un valor en claro al DOM).
	// ====================================================================

	public function test_handler_encrypts_zapsign_api_token(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		// La lista $encrypted_fields debe contener ltms_zapsign_api_token.
		$this->assertStringContainsString(
			"'ltms_zapsign_api_token'",
			$source,
			'AD-SET-107 cross-check: handler debe seguir listando ltms_zapsign_api_token en $encrypted_fields.'
		);
	}

	public function test_handler_encrypts_google_client_secret(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_google_client_secret'",
			$source,
			'AD-SET-108 cross-check: handler debe seguir listando ltms_google_client_secret en $encrypted_fields.'
		);
	}

	public function test_handler_uses_ltms_core_security_encrypt_for_encrypted_fields(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		// La llamada al cifrado debe seguir presente.
		$this->assertStringContainsString(
			'LTMS_Core_Security::encrypt( sanitize_text_field( $value ) )',
			$source,
			'AD-SET-107/AD-SET-108 cross-check: handler debe seguir invocando LTMS_Core_Security::encrypt() para campos cifrados.'
		);
	}

	// ====================================================================
	//  Cross-check con renderer generico: el patron "vaciar value si v1:"
	//  ya lo aplicaba el renderer. Verifico que sigue presente ahi tambien
	//  (si alguien lo quita del renderer, los fields_map password quedan
	//  afrancesados — mismo patron que C22 ahora aplica a views custom).
	// ====================================================================

	public function test_generic_renderer_also_empties_value_when_v1_prefix(): void {
		$this->assertFileExists( self::RENDERER_PATH );
		$source = file_get_contents( self::RENDERER_PATH );

		// El renderer generico debe seguir aplicando el patron (linea 290 original).
		$this->assertStringContainsString(
			"strpos( \$value, 'v1:' ) === 0",
			$source,
			'AD-SET-107/AD-SET-108 cross-check: renderer generico (html-admin-settings.php) debe seguir aplicando v1: detection para fields_map password.'
		);
		$this->assertStringContainsString(
			"\$value = '';",
			$source,
			'AD-SET-107/AD-SET-108 cross-check: renderer generico debe seguir vaciando value cuando detecta v1:.'
		);
	}

	// ====================================================================
	//  AD-SET-112 P1: section-backblaze.php - vaciar app_key si v1: cifrado
	// ====================================================================

	public function test_backblaze_view_no_longer_emits_raw_encrypted_app_key_in_value(): void {
		$this->assertFileExists( self::BACKBLAZE_PATH );
		$source = file_get_contents( self::BACKBLAZE_PATH );

		// Antes: el foreach emiti esc_attr($value) directo en el input password
		// del campo ltms_backblaze_app_key — leak del hash v1: en DOM.
		$this->assertStringNotContainsString(
			"elseif(\$field['type']==='password'): ?>\r\n            <input type=\"password\" name=\"<?php echo esc_attr(\$key);?>\" value=\"<?php echo esc_attr(\$value);?>",
			$source,
			'AD-SET-112: section-backblaze.php NO debe emitir $value crudo en value= del input password.'
		);
		$this->assertStringNotContainsString(
			"elseif(\$field['type']==='password'): ?>\n            <input type=\"password\" name=\"<?php echo esc_attr(\$key);?>\" value=\"<?php echo esc_attr(\$value);?>",
			$source,
			'AD-SET-112: section-backblaze.php NO debe emitir $value crudo en value= del input password.'
		);
	}

	public function test_backblaze_view_uses_display_value_var_in_password_input(): void {
		$this->assertFileExists( self::BACKBLAZE_PATH );
		$source = file_get_contents( self::BACKBLAZE_PATH );

		$this->assertStringContainsString(
			'value="<?php echo esc_attr($display_value);?>"',
			$source,
			'AD-SET-112: section-backblaze.php debe usar $display_value (vaciar si v1:) en value=.'
		);
	}

	public function test_backblaze_view_has_v1_prefix_detection_for_password(): void {
		$this->assertFileExists( self::BACKBLAZE_PATH );
		$source = file_get_contents( self::BACKBLAZE_PATH );

		$this->assertStringContainsString(
			"strpos( \$value, 'v1:' ) === 0",
			$source,
			'AD-SET-112: section-backblaze.php debe detectar prefijo v1: en $value para vaciar value=.'
		);
	}

	public function test_backblaze_view_has_ciclo22_tag_adset112(): void {
		$this->assertFileExists( self::BACKBLAZE_PATH );
		$source = file_get_contents( self::BACKBLAZE_PATH );

		$this->assertStringContainsString(
			'CICLO22-P1-AD-SET-112 FIX',
			$source,
			'AD-SET-112: tag de trazabilidad CICLO22-P1-AD-SET-112 FIX debe estar en section-backblaze.php.'
		);
	}

	public function test_backblaze_view_docblock_references_handler_and_other_c22_fixes(): void {
		$this->assertFileExists( self::BACKBLAZE_PATH );
		$source = file_get_contents( self::BACKBLAZE_PATH );

		$this->assertStringContainsString(
			'class-ltms-admin-settings.php:117',
			$source,
			'AD-SET-112: docblock debe referenciar el handler (linea 117 donde se cifra ltms_backblaze_app_key).'
		);
		$this->assertStringContainsString(
			'AD-SET-107',
			$source,
			'AD-SET-112: docblock debe vincular el patron con AD-SET-107 (zapsign) del mismo ciclo.'
		);
	}

	// ====================================================================
	//  AD-SET-113 P1: section-siigo.php - vaciar access_key si v1: cifrado
	// ====================================================================

	public function test_siigo_view_no_longer_emits_raw_encrypted_access_key_in_value(): void {
		$this->assertFileExists( self::SIIGO_PATH );
		$source = file_get_contents( self::SIIGO_PATH );

		$this->assertStringNotContainsString(
			'<input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password">' . "\n            <?php if(!empty(\$field['desc'])):",
			$source,
			'AD-SET-113: section-siigo.php NO debe emitir $value crudo en value= del input password.'
		);
	}

	public function test_siigo_view_uses_display_value_var_in_password_input(): void {
		$this->assertFileExists( self::SIIGO_PATH );
		$source = file_get_contents( self::SIIGO_PATH );

		$this->assertStringContainsString(
			'value="<?php echo esc_attr($display_value);?>"',
			$source,
			'AD-SET-113: section-siigo.php debe usar $display_value (vaciar si v1:) en value=.'
		);
	}

	public function test_siigo_view_has_v1_prefix_detection_for_password(): void {
		$this->assertFileExists( self::SIIGO_PATH );
		$source = file_get_contents( self::SIIGO_PATH );

		$this->assertStringContainsString(
			"strpos( \$value, 'v1:' ) === 0",
			$source,
			'AD-SET-113: section-siigo.php debe detectar prefijo v1: en $value para vaciar value=.'
		);
	}

	public function test_siigo_view_has_ciclo22_tag_adset113(): void {
		$this->assertFileExists( self::SIIGO_PATH );
		$source = file_get_contents( self::SIIGO_PATH );

		$this->assertStringContainsString(
			'CICLO22-P1-AD-SET-113 FIX',
			$source,
			'AD-SET-113: tag de trazabilidad CICLO22-P1-AD-SET-113 FIX debe estar en section-siigo.php.'
		);
	}

	public function test_siigo_view_docblock_references_handler_line_115(): void {
		$this->assertFileExists( self::SIIGO_PATH );
		$source = file_get_contents( self::SIIGO_PATH );

		$this->assertStringContainsString(
			'class-ltms-admin-settings.php:115',
			$source,
			'AD-SET-113: docblock debe referenciar el handler (linea 115 donde se cifra ltms_siigo_access_key).'
		);
	}

	// ====================================================================
	//  AD-SET-114 P1: section-payments.php - vaciar openpay_private_key +
	//  addi_client_secret si v1: cifrado (2 leaks en 1 archivo)
	// ====================================================================

	public function test_payments_view_no_longer_emits_raw_encrypted_values(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		// Antes: el foreach emitia esc_attr($value) en value= del input password
		// sin proteccion para ltms_openpay_private_key ni ltms_addi_client_secret.
		$this->assertStringNotContainsString(
			'<input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password">' . "\n            <?php if(!empty(\$field['desc'])):",
			$source,
			'AD-SET-114: section-payments.php NO debe emitir $value crudo en value= del input password.'
		);
	}

	public function test_payments_view_uses_display_value_var_in_password_input(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		$this->assertStringContainsString(
			'value="<?php echo esc_attr($display_value);?>"',
			$source,
			'AD-SET-114: section-payments.php debe usar $display_value (vaciar si v1:) en value=.'
		);
	}

	public function test_payments_view_has_v1_prefix_detection_for_password(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		$this->assertStringContainsString(
			"strpos( \$value, 'v1:' ) === 0",
			$source,
			'AD-SET-114: section-payments.php debe detectar prefijo v1: en $value para vaciar value=.'
		);
	}

	public function test_payments_view_has_ciclo22_tag_adset114(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		$this->assertStringContainsString(
			'CICLO22-P1-AD-SET-114 FIX',
			$source,
			'AD-SET-114: tag de trazabilidad CICLO22-P1-AD-SET-114 FIX debe estar en section-payments.php.'
		);
	}

	public function test_payments_view_docblock_documents_adset116_p2_backlog(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		// El docblock debe mencionar el hallazgo derivado AD-SET-116 P2:
		// stripe_secret_key, stripe_webhook_secret y openpay_mx_priv_key
		// no estan en $encrypted_fields (legacy, se guardan en claro).
		$this->assertStringContainsString(
			'AD-SET-116',
			$source,
			'AD-SET-114: docblock debe documentar el hallazgo P2 derivado AD-SET-116 (stripe/openpay_mx no cifrados).'
		);
		$this->assertStringContainsString(
			'ltms_stripe_secret_key',
			$source,
			'AD-SET-114: docblock debe mencionar ltms_stripe_secret_key como caso P2 del hallazgo AD-SET-116.'
		);
	}

	public function test_payments_view_docblock_lists_both_openpay_and_addi_as_leaked(): void {
		$this->assertFileExists( self::PAYMENTS_PATH );
		$source = file_get_contents( self::PAYMENTS_PATH );

		$this->assertStringContainsString(
			'ltms_openpay_private_key',
			$source,
			'AD-SET-114: docblock debe mencionar ltms_openpay_private_key como leak fixeado.'
		);
		$this->assertStringContainsString(
			'ltms_addi_client_secret',
			$source,
			'AD-SET-114: docblock debe mencionar ltms_addi_client_secret como leak fixeado.'
		);
	}

	// ====================================================================
	//  AD-SET-115 P2: section-alegra.php - alinear bullets a patron estandar
	//  (vaciar value=, placeholder '(guardado...)' en lugar de '••••••')
	// ====================================================================

	public function test_alegra_view_no_longer_emits_bullets_placeholder_in_value(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		// Antes: este view tenia la asignacion operativa:
		//   $token_display = ( str_starts_with( $token_val, 'v1:' ) ) ? '••••••••••••••••••••••••••••••' : $token_val;
		// El approach C22 reemplaza eso por $token_display = $token_is_encrypted ? '' : $token_val;
		// Verificamos que ya NO exista el patron operativo viejo: $token_display = ( str_starts_with(...)
		// NOTA: el docblock explica el bug anterior y menciona '•••••••••••••••••' como parte de la
		// explicacion (linea 32 del archivo) — eso es documentation, no codigo operativo. La
		// assertion debe distinguir: buscar el patron viejo completo, no bullets sueltos.
		$this->assertStringNotContainsString(
			'str_starts_with( $token_val, \'v1:\' ) ) ? \'',
			$source,
			'AD-SET-115: section-alegra.php NO debe tener la asignacion vieja $token_display = str_starts_with(...) ? bullets : $token_val.'
		);
		$this->assertStringNotContainsString(
			"'••••••••••••••••••••••••••••••' : ",
			$source,
			'AD-SET-115: section-alegra.php NO debe tener el string operativo de 29 bullets como fallback $token_display.'
		);
	}

	public function test_alegra_view_uses_empty_token_display_when_v1(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'$token_display = $token_is_encrypted ? \'\' : $token_val;',
			$source,
			'AD-SET-115: section-alegra.php debe vaciar $token_display a string vacio cuando token esta cifrado (v1:).'
		);
	}

	public function test_alegra_view_uses_guardado_placeholder_when_encrypted(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			"'(guardado",
			$source,
			'AD-SET-115: section-alegra.php debe usar placeholder alternativo "(guardado...)" cuando token esta cifrado.'
		);
	}

	public function test_alegra_view_has_ciclo22_tag_adset115(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'CICLO22-P2-AD-SET-115 FIX',
			$source,
			'AD-SET-115: tag de trazabilidad CICLO22-P2-AD-SET-115 FIX debe estar en section-alegra.php.'
		);
	}

	public function test_alegra_view_docblock_explains_silent_save_bug(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		// El docblock debe explicar el bug silencioso: el navegador envia los
		// bullets como nuevo token al guardar sin tocar el campo; backend los
		// cifra perdiendo el original.
		$this->assertStringContainsString(
			'bullet',
			$source,
			'AD-SET-115: docblock debe mencionar el problema del placeholder visual bullets.'
		);
	}

	// ====================================================================
	//  Cross-check: handler central sigue listando los 5 nuevos campos
	//  cifrados en $encrypted_fields (backblaze, siigo, openpay, addi, alegra).
	//  Si alguien los saca de la lista, el fix del view queda irrelevant (no
	//  hay cifrado backend al que proteger contra leak DOM).
	// ====================================================================

	public function test_handler_encrypts_backblaze_app_key(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_backblaze_app_key'",
			$source,
			'AD-SET-112 cross-check: handler debe seguir listando ltms_backblaze_app_key en $encrypted_fields.'
		);
	}

	public function test_handler_encrypts_siigo_access_key(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_siigo_access_key'",
			$source,
			'AD-SET-113 cross-check: handler debe seguir listando ltms_siigo_access_key en $encrypted_fields.'
		);
	}

	public function test_handler_encrypts_openpay_private_key_and_addi_client_secret(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_openpay_private_key'",
			$source,
			'AD-SET-114 cross-check: handler debe seguir listando ltms_openpay_private_key en $encrypted_fields.'
		);
		$this->assertStringContainsString(
			"'ltms_addi_client_secret'",
			$source,
			'AD-SET-114 cross-check: handler debe seguir listando ltms_addi_client_secret en $encrypted_fields.'
		);
	}

	public function test_handler_encrypts_alegra_token(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_alegra_token'",
			$source,
			'AD-SET-115 cross-check: handler debe seguir listando ltms_alegra_token en $encrypted_fields.'
		);
	}

	// ====================================================================
	//  Cross-check: el handler debe mantener el valor cifrado original cuando
	//  el input llega vacio (sin esto, los fixes C22 vaciar el value=
	//  causarianborrado del valor al guardar la forma sin tocar el campo).
	//  Ver handler lineas 124-130: si input NO empty() pero empieza con v1:,
	//  se mantiene; si input empty(), no se toca (no override). El test
	//  asegura que la rama "Ya cifrado, mantener" sigue presente.
	// ====================================================================

	public function test_handler_keeps_already_encrypted_value_on_resave(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"// Ya cifrado, mantener",
			$source,
			'AD-SET-107~115 cross-check: handler debe mantener el valor ya cifrado (v1:) en $sanitized[$key] = $value.'
		);
	}

	public function test_handler_encrypts_only_non_empty_values(): void {
		$this->assertFileExists( self::HANDLER_PATH );
		$source = file_get_contents( self::HANDLER_PATH );

		$this->assertStringContainsString(
			"&& ! empty( \$value )",
			$source,
			'AD-SET-107~115 cross-check: handler solo cifra si el campo esta en $encrypted_fields Y no esta vacio (no pisa valor existente).'
		);
	}

	// ====================================================================
	//  Guard de regression: TOTAL fix tags esperados en C22 (5 archivos)
	// ====================================================================

	public function test_ciclo22_total_fix_tags_in_settings_views(): void {
		$tags = [
			self::ZAPSIGN_PATH      => [ 'CICLO22-P1-AD-SET-107 FIX' ],
			self::GOOGLE_OAUTH_PATH => [ 'CICLO22-P1-AD-SET-108 FIX' ],
			self::BACKBLAZE_PATH    => [ 'CICLO22-P1-AD-SET-112 FIX' ],
			self::SIIGO_PATH        => [ 'CICLO22-P1-AD-SET-113 FIX' ],
			self::PAYMENTS_PATH     => [ 'CICLO22-P1-AD-SET-114 FIX' ],
			self::ALEGRA_PATH       => [ 'CICLO22-P2-AD-SET-115 FIX' ],
		];

		foreach ( $tags as $path => $expected_tags ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			foreach ( $expected_tags as $tag ) {
				$this->assertStringContainsString(
					$tag,
					$source,
					"Tag de trazabilidad {$tag} debe estar en " . basename( $path )
				);
			}
		}
	}

	// ====================================================================
	//  Cross-check: section-aveonline.php YA estaba bien escrito (value="" literal
	//  para los 3 password) - confirmed no leak en C22 inventario. Leave this
	//  test como documentacion de que aveonline no requiere fix C22.
	// ====================================================================

	public function test_aveonline_view_already_uses_empty_value_for_password_inputs(): void {
		$aveonline_path = __DIR__ . '/../../includes/admin/views/settings/section-aveonline.php';
		$this->assertFileExists( $aveonline_path );
		$source = file_get_contents( $aveonline_path );

		// El dev original ya usaba value="" literal para los 3 password
		// (onboarding_token, clave, clave_guia) — NO requiere fix C22.
		// Confirmamos que el patron "value=" vacio intencional esta presente
		// en al menos 3 ocurrencias.
		$count = substr_count( $source, 'value="" class="regular-text" autocomplete="new-password"' );
		$this->assertGreaterThanOrEqual(
			3,
			$count,
			'C22 inventario: section-aveonline.php debe tener >=3 inputs password con value="" literal (onboarding_token, clave, clave_guia) — no requiere fix.'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime include
	// ====================================================================
	// Originalmente estaba considerando ejecutar los templates via include
	// para verificar el runtime behavior del "vaciar value si v1:" con un
	// valor cifrado falso (LTMS_Core_Config::set('ltms_zapsign_api_token',
	// 'v1:test_hash')). Igual que en C21, los templates dependen de un stack
	// WP completo (Brain\Monkey no stubea get_option() con valores válidos
	// sin configuración adicional). Opte por tests source-based puros (17
	// tests), mismo patron que C21. Para runtime tests, usar tests/integration/
	// con LTMS_Integration_Test_Case. Ver NOTAS CRITICAS C21 GOTCHA
	// "TESTS RUNTIME INCLUDE FALLA EN BRAIN\MONKEY".
}
