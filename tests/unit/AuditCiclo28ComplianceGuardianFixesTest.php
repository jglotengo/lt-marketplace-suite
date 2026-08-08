<?php
/**
 * AuditCiclo28ComplianceGuardianFixesTest - Tests para los fixes del Ciclo 28.
 *
 * Modulo: includes/business/class-ltms-compliance-guardian.php (784L auditados)
 *
 * COMPLIANCE GUARDIAN cubre cumplimiento Meta (CAAPI, Consent Mode v2, LDU),
 * Colombia (Ley 1581/2012 ARCO), Mexico (LFPDPPP / INAI / PLD). NO es modulo
 * CRITICO estricto en AGENTS.md "Revision como ultimo filtro" (no toca
 * wallet/comisiones/payouts/KYC/SAGRILAFT/ZapSign/Backblaze/gateways de pago)
 * PERO SI toca compliance regulatorio (privacidad + AML) — segunda revision
 * del diff by subagente general se hizo igual (Leccion 27.1 regla #6).
 *
 * 1. CG-001 P0 (ajax_cookie_consent en compliance-guardian.php):
 *    El endpoint `wp_ajax_nopriv_ltms_cookie_consent` (sin login) usaba
 *    `check_ajax_referer( 'ltms_ux_nonce', 'nonce', false )` — el tercer
 *    parametro `$die=false` significa que si el nonce falla el endpoint
 *    SIGUE procesando. Cualquier site podia embed un form POST y forzar
 *    `level=full` silenciosamente -> bypass del consent gating M3/M10 y
 *    bypass del opt-out M14 (si cookie=full, gate_pixel_on_consent devuelve
 *    true sin revisar ltms_meta_data_opt_out). Ademas el comentario decia
 *    "for backward compat with cached pages" — pero la pagina cacheada
 *    NO gana bypass de compliance; el cache es problema del front, no del
 *    endpoint. Este patron es identico al TB-007 (C26, backlog P2) pero
 *    aqui es P0 porque toca compliance regulatorio (Ley 1581 art. 9
 *    consentimiento libre/previo/expreso/informado + RGPD art. 7 + Meta
 *    policy M3 que obliga a NO disparar Pixel sin consent).
 *    Fix: nonce fail-closed. Si check_ajax_referer(..., false) devuelve
 *    false, el endpoint retorna 403 y NO loguea consent. Adicionalmente se
 *    anade wp_unslash() sobre $_POST['level'] que antes faltaba.
 *
 * 2. CG-002 P1 (build_capi_user_data L339 + build_capi_user_data_from_session
 *    L359): ambos usaban `LTMS_Utils::get_ip()` en lugar de
 *    `LTMS_Core_Security::get_client_ip_safe()`. La Leccion 25.1 establece
 *    get_client_ip_safe() como fuente unica de IP (sanitiza spoofing-
 *    resistente — solo confia en X-Forwarded-For si REMOTE_ADDR esta en
 *    `ltms_trusted_proxies` configurado por admin). `LTMS_Utils::get_ip()`
 *    confia en cualquier header XFF sin validacion de proxy — spoofable.
 *    Aunque aqui el IP se envia a Meta (no se persiste), inconsistencia
 *    con el invariante transversal endurece riesgo y diverge entre gateways.
 *    Fix: get_client_ip_safe() en ambas llamadas CAPI. NO toca
 *    class-ltms-deposit.php:164 ni class-ltms-wallet.php:606 — backlog
 *    futuro.
 *
 * 3. CG-003 P1 (arco_cancel L535-560): el set `$pii_keys` eliminaba 26
 *    metas PII pero NO incluia `ltms_opposition_marketing`,
 *    `ltms_opposition_profiling`, `ltms_opposition_data_sharing`,
 *    `ltms_opposition_automated_decisions` (escritas por arco_oppose L572)
 *    ni `ltms_meta_data_opt_out` (escrita por save_meta_opt_out L752). El
 *    usuario que pide "cancel" asume que NADA de su voluntad permanece.
 *    La Ley 1581 art. 8 lit. e y la LFPDPPP art. 25 exigen Cancelacion
 *    efectiva. Las entries en `lt_consent_log` (evidencia historica) SI
 *    se retienen por obligacion fiscal (ET art. 632 / LISR art. 30), pero
 *    el user_meta `ltms_opposition_*` son oposiciones ACTIVAS — en cuenta
 *    cerrada no recibira marketing, retener la oposicion es basura sin
 *    proposito valido y viola el principio de minimizacion (Ley 1581 art.
 *    4 lit. c). Lo mismo aplica a ltms_meta_data_opt_out (M14): cuenta
 *    cerrada no enviara eventos CAPI — retener el flag no aporta nada.
 *    Fix: anadir las 5 metas al set $pii_keys.
 *
 * Patron C28: source-based tests (file_get_contents + assertStringContains/
 * NotContainsString), mismo que C20-C27. Ademas cross-checks transversales:
 * - Traffic-booster sigue usando get_client_ip_safe (no regression C26).
 * - Xcover checkout handler mantiene get_client_ip_safe (C4).
 * - 5 webhook handlers mantienen get_client_ip_safe (C25).
 * - PLD MX cron sigue registrado (`add_action( 'ltms_daily_cron', ...`).
 * - Endpoints ARCO siguen con permission_callback is_user_logged_in.
 * - arco_cancel sigue invocando LTMS_Privacy_Toolkit::erase_extended_data
 *   (PR-4 v2.9.13 fix intacto).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CG-001 (P0), CG-002 (P1), CG-003 (P1)
 */
class AuditCiclo28ComplianceGuardianFixesTest extends LTMS_Unit_Test_Case {

	private const COMPLIANCE_GUARDIAN_PATH = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const TRAFFIC_BOOSTER_PATH     = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const XCOVER_HANDLER_PATH      = __DIR__ . '/../../includes/business/class-ltms-xcover-checkout-handler.php';
	private const ZAPSIGN_WEBHOOK_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
	private const OPENPAY_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const SIIGO_HANDLER_PATH       = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'          => static fn( string $s ): string => $s,
			'esc_html__'  => static fn( string $s ): string => $s,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ====================================================================
	//  CG-001 P0: ajax_cookie_consent nonce fail-closed
	// ====================================================================

	public function test_compliance_guardian_file_exists(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
	}

	public function test_ajax_cookie_consent_has_ciclo28_tag(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			'CICLO28-P0-CG-001 FIX',
			$source,
			'CG-001: tag de trazabilidad CICLO28-P0-CG-001 FIX debe estar en compliance-guardian.'
		);
	}

	public function test_ajax_cookie_consent_returns_403_on_invalid_nonce(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-001 P0: el fix debe retornar wp_send_json_error con 403 dentro
		// del bloque `if ( ! check_ajax_referer(...) )`. Antes el bloque
		// estaba vacio (solo comentario) — procesaba igual sin nonce.
		$this->assertStringContainsString(
			"wp_send_json_error(",
			$source,
			'CG-001: ajax_cookie_consent debe retornar wp_send_json_error cuando el nonce falla (fail-closed).'
		);
		$this->assertStringContainsString(
			'403',
			$source,
			'CG-001: el wp_send_json_error del nonce failure debe retornar status 403 Forbidden.'
		);
	}

	public function test_ajax_cookie_consent_no_more_empty_nonce_bypass(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-001 P0: el anti-patron viejo tenia el bloque
		//   if ( ! check_ajax_referer(...) ) {
		//       // comment
		//   }
		// sin return/wp_send_json_error dentro. Tras el fix el bloque debe
		// contener wp_send_json_error. Buscamos el patron "vacio" y
		// negamos que siga presente.
		$this->assertStringNotContainsString(
			"// still process but don't log consent",
			$source,
			'CG-001: el bypass docstring "still process but don\'t log consent" fue eliminado por el fix.'
		);
		$this->assertStringNotContainsString(
			"// For backward compat with cached pages that may not have nonce,",
			$source,
			'CG-001: el comentario "backward compat with cached pages" fue eliminado (compat con cache no justifica bypass nonce).'
		);
	}

	public function test_ajax_cookie_consent_uses_die_false_param(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-001: el fix mantiene `check_ajax_referer( 'ltms_ux_nonce', 'nonce', false )`
		// — el tercer param false evita que WP haga die() automatico y permite
		// retornar wp_send_json_error(403) controlado en su lugar.
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_ux_nonce', 'nonce', false )",
			$source,
			'CG-001: se mantiene check_ajax_referer con die=false para controlar el retorno 403.'
		);
	}

	public function test_ajax_cookie_consent_unslash_level_input(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-001 P0 adyacente: el fix anade wp_unslash() sobre $_POST['level'].
		// Sin wp_unslash, WP agrega backslashes a las comillas — el
		// in_array() contra ['full','essential'] fallaria si level venia
		// con escapes. Peor: el log_consent persistiria 'cookie_\\full'.
		$this->assertStringContainsString(
			"wp_unslash( \$_POST['level'] ?? '' )",
			$source,
			'CG-001: $_POST[\'level\'] debe pasar por wp_unslash() antes de sanitize_text_field.'
		);
	}

	// ====================================================================
	//  CG-002 P1: get_client_ip_safe() reemplaza LTMS_Utils::get_ip()
	// ====================================================================

	public function test_cg002_has_tag_in_compliance_guardian(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			'CICLO28-P1-CG-002 FIX',
			$source,
			'CG-002: tag de trazabilidad CICLO28-P1-CG-002 FIX debe estar en compliance-guardian.'
		);
	}

	public function test_build_capi_user_data_uses_get_client_ip_safe(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-002 P1: build_capi_user_data (order flow) debe usar
		// LTMS_Core_Security::get_client_ip_safe() en client_ip_address.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'CG-002: build_capi_user_data debe llamar LTMS_Core_Security::get_client_ip_safe() (no LTMS_Utils::get_ip).'
		);
	}

	public function test_capi_functions_no_longer_use_ltms_utils_get_ip(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// CG-002 P1: ninguna de las funciones CAPI debe usar LTMS_Utils::get_ip().
		// Buscamos el patron completo convetido a comentario explicativo —
		// el patron ACTIVO `$user_data['client_ip_address'] = LTMS_Utils::get_ip();`
		// debe haber desaparecido.
		$this->assertStringNotContainsString(
			"\$user_data['client_ip_address'] = LTMS_Utils::get_ip();",
			$source,
			'CG-002: $user_data[client_ip_address] = LTMS_Utils::get_ip() debe haber sido reemplazado por get_client_ip_safe().'
		);
	}

	public function test_client_ip_address_assignment_present_in_both_capi_builders(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Debe haber exactamente 2 asignaciones de client_ip_address, ambas
		// llamando a get_client_ip_safe(). Una en build_capi_user_data (order),
		// otra en build_capi_user_data_from_session (cart).
		$count = substr_count( $source, "'client_ip_address'] = LTMS_Core_Security::get_client_ip_safe()" );
		$this->assertSame( 2, $count,
			'CG-002: debe haber 2 asignaciones de client_ip_address llamando a get_client_ip_safe() (build_capi_user_data + build_capi_user_data_from_session).'
		);
	}

	// ====================================================================
	//  CG-003 P1: arco_cancel elimina opposition + opt_out metas
	// ====================================================================

	public function test_cg003_has_tag_in_compliance_guardian(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			'CICLO28-P1-CG-003 FIX',
			$source,
			'CG-003: tag de trazabilidad CICLO28-P1-CG-003 FIX debe estar en compliance-guardian.'
		);
	}

	public function test_arco_cancel_pii_keys_includes_opposition_marketing(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			"'ltms_opposition_marketing'",
			$source,
			'CG-003: arco_cancel debe eliminar ltms_opposition_marketing.'
		);
	}

	public function test_arco_cancel_pii_keys_includes_opposition_profiling(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			"'ltms_opposition_profiling'",
			$source,
			'CG-003: arco_cancel debe eliminar ltms_opposition_profiling.'
		);
	}

	public function test_arco_cancel_pii_keys_includes_opposition_data_sharing(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			"'ltms_opposition_data_sharing'",
			$source,
			'CG-003: arco_cancel debe eliminar ltms_opposition_data_sharing.'
		);
	}

	public function test_arco_cancel_pii_keys_includes_opposition_automated_decisions(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			"'ltms_opposition_automated_decisions'",
			$source,
			'CG-003: arco_cancel debe eliminar ltms_opposition_automated_decisions.'
		);
	}

	public function test_arco_cancel_pii_keys_includes_meta_data_opt_out(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString(
			"'ltms_meta_data_opt_out'",
			$source,
			'CG-003: arco_cancel debe eliminar ltms_meta_data_opt_out (opt-out Meta M14).'
		);
	}

	public function test_arco_oppose_defines_all_four_opposition_keys(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Verificamos que arco_oppose define EXACTAMENTE las 4 metas que
		// arco_cancel ahora elimina. Si arco_oppose anade una nueva pero
		// arco_cancel no la elimina, hay inconsistencia (principio de
		// cancelacion efectiva podria romperse). El valid_types de
		// arco_oppose es: marketing, profiling, data_sharing,
		// automated_decisions — cobertura 1:1 con pii_keys del fix CG-003.
		$this->assertStringContainsString(
			"'marketing', 'profiling', 'data_sharing', 'automated_decisions'",
			$source,
			'Las 4 oposiciones validas (marketing/profiling/data_sharing/automated_decisions) deben coincidir con las 4 metas eliminadas por arco_cancel.'
		);
	}

	public function test_arco_cancel_count_opposition_metas_in_pii_keys(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Garantia anti-regresion: si alguien remueve una de las 4 metas
		// ltms_opposition_* del set $pii_keys, este test falla. Contamos
		// cuantas veces aparece "'ltms_opposition_" en el archivo — debe
		// ser >= 4 (las 4 del set) + 1 (arco_oppose update_user_meta).
		$count = substr_count( $source, "'ltms_opposition_" );
		$this->assertGreaterThanOrEqual( 5, $count,
			'CG-003: arco_cancel debe listar las 4 ltms_opposition_* + arco_oppose define las 4 — al menos 5 ocurrencias esperadas.'
		);
	}

	// ====================================================================
	//  No-regresion: estructura del modulo compliance-guardian
	// ====================================================================

	public function test_class_ltms_compliance_guardian_exists(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		$this->assertStringContainsString( 'class LTMS_Compliance_Guardian', $source );
	}

	public function test_compliance_guardian_init_registers_all_hooks(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: init() debe seguir registrando todos los hooks
		// del modulo. Si un fix accidentalmente remueve un add_action o
		// add_filter, compliance completo se rompe.
		$this->assertStringContainsString( "add_action( 'wp_head', [ __CLASS__, 'inject_consent_mode_v2' ], 2 )", $source );
		$this->assertStringContainsString( "add_filter( 'ltms_should_inject_pixel'", $source );
		$this->assertStringContainsString( "add_filter( 'ltms_should_inject_ga4'", $source );
		$this->assertStringContainsString( "add_filter( 'ltms_should_inject_vendor_pixel'", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_payment_complete'", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_add_to_cart'", $source );
		$this->assertStringContainsString( "add_action( 'rest_api_init', [ __CLASS__, 'register_arco_endpoints' ] )", $source );
		$this->assertStringContainsString( "add_action( 'ltms_daily_cron', [ __CLASS__, 'run_pld_monitoring_mx' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_footer', [ __CLASS__, 'render_privacy_notice_link' ], 5 )", $source );
		$this->assertStringContainsString( "add_action( 'rest_api_init', [ __CLASS__, 'register_data_export_endpoint' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_checkout_before_terms_and_conditions'", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_edit_account_form'", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_save_account_details'", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_cookie_consent'", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_nopriv_ltms_cookie_consent'", $source );
	}

	public function test_arco_endpoints_have_permission_callback(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: los 5 endpoints ARCO/Data Export siguen
		// permission_callback is_user_logged_in. Si se quita, cualquier
		// anon podria llamar /ltms/v1/privacy/access y leer todos los
		// datos del user_id stale — bypass total de privacidad.
		$this->assertStringContainsString( "'permission_callback' => function() { return is_user_logged_in(); }", $source );
	}

	public function test_arco_cancel_still_invokes_extended_eraser(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion PR-4 v2.9.13: arco_cancel debe seguir llamando
		// LTMS_Privacy_Toolkit::erase_extended_data. Era un fix previo
		// que evitaba que las tablas lt_wallet_transactions,
		// lt_commissions, lt_payout_requests quedaran intactas tras el
		// erase — violacion del art. 8 lit. e Ley 1581. El fix CG-003
		// POO debe preservar.
		$this->assertStringContainsString(
			'LTMS_Privacy_Toolkit::erase_extended_data',
			$source,
			'PR-4 v2.9.13 intacto: arco_cancel sigue invocando LTMS_Privacy_Toolkit::erase_extended_data.'
		);
	}

	public function test_arco_cancel_still_anonymizes_wp_users_row(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: arco_cancel anonimiza user_email + display_name
		// + user_nicename en wp_users. Esto se mantiene tras CG-003.
		$this->assertStringContainsString( "'user_email' => \$anon_email", $source );
		$this->assertStringContainsString( "'display_name' => __( 'Usuario eliminado', 'ltms' )", $source );
		$this->assertStringContainsString( "'user_nicename' => 'deleted_' . \$user_id", $source );
	}

	public function test_pld_mx_cron_registered_in_init(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion AML: cron PLD MX sigue registrado. Si se rompe,
		// no se detectan vendors con >$10k USD sin KYC -> violacion
		// LFPIDRPI / PLD Mexico.
		$this->assertStringContainsString(
			"add_action( 'ltms_daily_cron', [ __CLASS__, 'run_pld_monitoring_mx' ] )",
			$source,
			'run_pld_monitoring_mx debe seguir registrado como handler de ltms_daily_cron (no cron huerfano).'
		);
	}

	public function test_run_pld_monitoring_mx_gated_to_mx_country(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion PLD: el cron PLD retorna temprano si country != MX.
		// Esto evita que dispare en instalaciones CO (donde LFPIDRPI no
		// aplica). El fix C28 NO toco esto — garantizamos que sigue.
		// Buscamos el early return dentro de run_pld_monitoring_mx.
		$this->assertStringContainsString(
			"if ( \$country !== 'MX' ) return;",
			$source,
			'run_pld_monitoring_mx debe retornar temprano si country != MX (no PLD en Colombia).'
		);
	}

	public function test_gate_vendor_pixel_on_consent_checks_opt_out(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion M14: gate_vendor_pixel_on_consent sigue revisando
		// ltms_meta_data_opt_out para vetar el pixel del vendor si el
		// usuario opto out. Esto es vital — el fix CG-003 elimina este
		// flag en arco_cancel, pero gateVendor sigue protegiendo mientras
		// la cuenta este viva.
		$this->assertStringContainsString(
			"get_user_meta( \$current_user, 'ltms_meta_data_opt_out', true )",
			$source,
			'M14 intacto: gate_vendor_pixel_on_consent sigue revisando ltms_meta_data_opt_out.'
		);
	}

	public function test_send_capi_purchase_applies_ldu_when_opt_out_yes(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion M4/M14: send_capi_purchase aplica LDU
		// (Limited Data Use) y remueve PII del user_data cuando
		// ltms_meta_data_opt_out = 'yes'. Esto protege usuarios
		// California/EE.UU. con derechos LDU + usuarios que optaron out.
		// Nota: en el codigo real la asignacion es indexada
		// `$event_data['data'][0]['data_processing_options'] = [ 'LDU' ]`
		// (no $k => $v). El assert matchea el formato exacto del fuente.
		$this->assertStringContainsString(
			"['data_processing_options'] = [ 'LDU' ]",
			$source,
			'M4 LDU intacto: send_capi_purchase sigue aplicando LDU cuando opt_out=yes.'
		);
		$this->assertStringContainsString(
			"['data'][0]['user_data'] = [];",
			$source,
			'M4 LDU intacto: send_capi_purchase remueve user_data (PII) cuando opt_out=yes.'
		);
	}

	public function test_save_meta_opt_out_logs_consent(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: save_meta_opt_out sigue logueando el cambio de
		// opt-out a lt_consent_log via LTMS_Legal_Compliance::log_consent.
		// Sin este log, el opt-out no genera evidencia regulatoria.
		$this->assertStringContainsString(
			'LTMS_Legal_Compliance::log_consent( $user_id, \'meta_opt_\' . $opt_out',
			$source,
			'save_meta_opt_out sigue logueando el consent con meta_opt_yes|no.'
		);
	}

	public function test_ajax_cookie_consent_invalidates_invalid_level(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: ajax_cookie_consent sigue validando level contra
		// ['full', 'essential'] y rechazando cualquier otro valor. Si se
		// rompe, un atacante podria setear level='malicioso' que PHP
		// persistiria en cookie consent y application logic no esperaria.
		$this->assertStringContainsString(
			"in_array( \$level, [ 'full', 'essential' ], true )",
			$source,
			'ajax_cookie_consent sigue validando level contra [full, essential] (strict in_array).'
		);
	}

	public function test_build_capi_user_data_hashes_email_sha256(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion M2 Advanced Matching: build_capi_user_data sigue
		// hasheando email con SHA-256 (no envia PII en claro a Meta).
		$this->assertStringContainsString(
			"hash( 'sha256', \$email )",
			$source,
			'M2 intacto: build_capi_user_data sigue hasheando email SHA-256 antes de enviar a Meta.'
		);
	}

	public function test_build_capi_user_data_hashes_phone_sha256(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion M2: phone tambien hashed SHA-256.
		$this->assertStringContainsString(
			"hash( 'sha256', \$phone )",
			$source,
			'M2 intacto: build_capi_user_data sigue hasheando phone SHA-256.'
		);
	}

	public function test_arco_cancel_logs_data_cancellation_consent(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: arco_cancel loguea 'data_cancellation' a
		// lt_consent_log. Esto genera evidencia regulatoria del ejercicio
		// del derecho de supresion (Ley 1581 art. 8 lit. e).
		$this->assertStringContainsString(
			'log_consent( $user_id, \'data_cancellation\'',
			$source,
			'arco_cancel sigue logueando data_cancellation a lt_consent_log (evidencia Ley 1581 art. 8 lit. e).'
		);
	}

	public function test_send_capi_add_to_cart_uses_async_request(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion v2.9.49: send_capi_add_to_cart sigue usando la
		// version async non-blocking del CAPI request (timeout 0.01,
		// blocking false). Esto evita que el add-to-cart espere a Meta.
		$this->assertStringContainsString(
			'self::send_capi_request_async',
			$source,
			'v2.9.49 intacto: send_capi_add_to_cart sigue usando send_capi_request_async (non-blocking).'
		);
		$this->assertStringContainsString(
			"'timeout'   => 0.01,",
			$source,
			'Async CAPI mantiene timeout 0.01 (non-blocking).'
		);
		$this->assertStringContainsString(
			"'blocking'  => false,",
			$source,
			'Async CAPI mantiene blocking=false.'
		);
	}

	public function test_send_capi_request_logs_failures_with_logger(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: send_capi_request (sync, usado por purchase) sigue
		// logueando errores WP_Error con LTMS_Core_Logger::warning. Si se
		// rompe, fallos silenciosos del CAPI quedan invisibles.
		$this->assertStringContainsString(
			'is_wp_error( $response )',
			$source,
			'send_capi_request sigue validando is_wp_error response.'
		);
		$this->assertStringContainsString(
			'LTMS_Core_Logger::warning(',
			$source,
			'send_capi_request sigue logueando warnings en WP_Error.'
		);
	}

	public function test_build_capi_user_data_normalizes_phone_for_co_and_mx(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion M2: build_capi_user_data sigue añadiendo prefijo
		// 57 (CO) o 52 (MX) a telefonos de 10 digitos. Esto normaliza el
		// E.164 para Meta Advanced Matching.
		$this->assertStringContainsString(
			"if ( \$country === 'CO' && strlen( \$phone ) === 10 ) \$phone = '57' . \$phone;",
			$source,
			'M2 intacto: normalizacion telefono CO conserva prefijo 57.'
		);
		$this->assertStringContainsString(
			"if ( \$country === 'MX' && strlen( \$phone ) === 10 ) \$phone = '52' . \$phone;",
			$source,
			'M2 intacto: normalizacion telefono MX conserva prefijo 52.'
		);
	}

	public function test_arco_cancel_preserves_kyc_pii_destruction(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: arco_cancel sigue eliminando metas KYC criticas
		// (ltms_kyc_status, ltms_kyc_document_number). Estaban en el set
		// antes del fix y deben permanecer.
		$this->assertStringContainsString( "'ltms_kyc_status'", $source );
		$this->assertStringContainsString( "'ltms_kyc_document_number'", $source );
	}

	public function test_arco_cancel_preserves_zapsign_meta_cleanup(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion C27 (ZS-MGR-008): arco_cancel sigue eliminando
		// las metas publicas + privadas de ZapSign que el webhook firmaba.
		// Si se quita, una cuenta cancelada conservaria ltms_contract_status
		// = 'signed' eternamente — info de contrato firmado sin proposito.
		$this->assertStringContainsString(
			"'ltms_contract_status', 'ltms_contract_token', 'ltms_contract_signed_at',",
			$source,
			'ZS-MGR-008 C27 intacto: arco_cancel sigue eliminando metas publicas ZapSign.'
		);
		$this->assertStringContainsString(
			"'_ltms_zapsign_doc_token', '_ltms_zapsign_signed_at',",
			$source,
			'ZS-MGR-008 C27 intacto: arco_cancel sigue eliminando metas privadas ZapSign.'
		);
	}

	public function test_arco_rectify_validates_email_sanitization(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: arco_rectify sigue sanitizando todos los fields
		// con sanitize_text_field. Sin esto, XSS en user_meta active.
		$this->assertStringContainsString(
			'sanitize_text_field( $request->get_param( $field ) )',
			$source,
			'arco_rectify sigue sanitizando inputs con sanitize_text_field.'
		);
	}

	public function test_arco_oppose_validates_opposition_type(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// No-regresion: arco_oppose sigue validando opposition_type contra
		// whitelist ['marketing','profiling','data_sharing','automated_decisions'].
		// Sin esto, inyeccion meta key arbitraria (ltms_opposition_admin_bypass).
		$this->assertStringContainsString(
			"\$valid_types = [ 'marketing', 'profiling', 'data_sharing', 'automated_decisions' ];",
			$source,
			'arco_oppose sigue validando opposition_type contra whitelist.'
		);
		$this->assertStringContainsString(
			"in_array( \$opposition_type, \$valid_types, true )",
			$source,
			'arco_oppose sigue usando in_array strict para validar opposition_type.'
		);
	}

	// ====================================================================
	//  Cross-checks transversales (no regresion C4 / C25 / C26 / C27)
	// ====================================================================

	public function test_traffic_booster_still_uses_get_client_ip_safe_c28(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// Cross-check C26 (no regression): traffic-booster sigue delegando
		// IP resolution a LTMS_Core_Security::get_client_ip_safe() —
		// compliance-guardian C28 ahora usa la misma fuente.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Traffic-booster C26 sigue usando get_client_ip_safe (consistencia con compliance-guardian C28).'
		);
	}

	public function test_xcover_checkout_handler_still_uses_get_client_ip_safe(): void {
		$this->assertFileExists( self::XCOVER_HANDLER_PATH );
		$source = file_get_contents( self::XCOVER_HANDLER_PATH );

		// Cross-check C4 (no regression): xcover checkout handler sigue
		// usando get_client_ip_safe() para el IP del comprador del seguro.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Xcover checkout handler C4 sigue usando get_client_ip_safe.'
		);
	}

	public function test_zapsign_webhook_handler_still_delegates_client_ip_safe(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// Cross-check C27 (no regression): el webhook handler de ZapSign
		// sigue delegando a get_client_ip_safe() para el log del firmante.
		$this->assertStringContainsString(
			'get_client_ip_safe()',
			$source,
			'ZapSign webhook handler C27 sigue usando get_client_ip_safe.'
		);
	}

	public function test_all_5_webhook_handlers_use_get_client_ip_safe(): void {
		$paths = [
			self::OPENPAY_HANDLER_PATH,
			self::UBER_HANDLER_PATH,
			self::ADDI_HANDLER_PATH,
			self::SIIGO_HANDLER_PATH,
			self::ZAPSIGN_WEBHOOK_PATH,
		];
		foreach ( $paths as $path ) {
			$this->assertFileExists( $path, "Webhook handler {$path} debe existir." );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'get_client_ip_safe()',
				$source,
				"Webhook handler {$path} sigue usando get_client_ip_safe (invariante C25)."
			);
		}
	}

	public function test_ltms_utils_get_ip_method_still_exists(): void {
		// No regression: Aunque compliance-guardian C28 dejo de usar
		// LTMS_Utils::get_ip(), el metodo sigue existiendo para
		// class-ltms-deposit.php:164 y class-ltms-wallet.php:606 (backlog
		// futuro — fuera de scope C28).
		$utils_path = __DIR__ . '/../../includes/core/utils/class-ltms-utils.php';
		$this->assertFileExists( $utils_path );
		$source = file_get_contents( $utils_path );
		$this->assertStringContainsString(
			'public static function get_ip(): string',
			$source,
			'LTMS_Utils::get_ip() sigue definido (deposit/wallet dependen de el — backlog C29+).'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque los metodos de Compliance Guardian
	// (wp_remote_post, wp_send_json_*, WC_Order, get_user_meta, $wpdb) y
	// los webhooks/checkout handlers requieren stubeo extensivo de WP
	// internals. Los tests documentan el contrato del fix (tag presente,
	// cambios estructurales correctos) sin reimplementar logica.
}
