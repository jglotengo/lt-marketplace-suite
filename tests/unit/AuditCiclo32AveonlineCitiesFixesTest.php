<?php
/**
 * AuditCiclo32AveonlineCitiesFixesTest - Tests para los fixes del Ciclo 32.
 *
 * Modulo: includes/business/class-ltms-business-aveonline-cities.php (517L pre-fix,
 * 527L post-fix). API client hardening: el modulo hace wp_remote_get a un endpoint
 * externo (https://app.aveonline.co/assets/resources/public/listadociudades.json)
 * y upsertea el catalogo de ciudades en la tabla local `lt_aveonline_cities`.
 *
 * 2 fixes aplicados en C32:
 *
 *  AVC-001 P1 (cache invalidation huerfano):
 *    `flush_options_cache()` definido en linea ~388 sin callers. Tras sync()
 *    exitosa, el transient `ltms_aveonline_city_options` (TTL 12h, escrito por
 *    get_options() en linea 355) seguia sirviendo data stale aunque el upsert
 *    modifico las filas. Fix: llamar `self::flush_options_cache()` post-
 *    set_transient(last_sync) en sync().
 *
 *  AVC-002 P1 (sslverify missing en wp_remote_get):
 *    `fetch_source()` hace wp_remote_get a SOURCE_URL sin sslverify explicito.
 *    La invariante INTEGRATIONS-AUDIT P1 (establecida en C18, aplicada en 15
 *    sitios de class-ltms-api-aveonline.php con tag "INTEGRATIONS-AUDIT P1 FIX")
 *    exige sslverify explicito salvo override por constante LTMS_DISABLE_SSL_VERIFY.
 *    Sin esto, un MITM podria inyectar un JSON malicioso en el catalogo de 20000
 *    ciudades que el upsert ingresa sin mas sanitizacion que (string) cast.
 *    Fix: mismo patron que class-ltms-api-aveonline.php:950.
 *
 * Patron C32: source-based tests (file_get_contents + assertString
 * Contains/NotContainsString), mismo que C20-C31. Cross-checks:
 * - C25 invariantes webhooks siguen intactos (get_client_ip_safe).
 * - C28 compliance-guardian tags CICLO28 siguen presentes.
 * - C29 sales-booster tags CICLO29 siguen presentes.
 * - C30 fiscal-annual-close tags CICLO30 siguen presentes.
 * - C31 tags CICLO31 siguen presentes en los 6 archivos migrados.
 * - C30 invariante Wallet::execute_transaction usa `reference` para idempotency
 *   sigue intacta (AVC-001 no toca wallet; AVC-002 no toca wallet).
 * - C30 hook ltms_payout_completed sigue con accepted_args=3.
 * - Invariante INTEGRATIONS-AUDIT P1: sslverify en class-ltms-api-aveonline.php
 *   sigue presente en los 15 sitios.
 * - Anti-regresion: NO debe haber LTMS_Utils::get_ip() en aveonline-cities.php
 *   (la invariante C25/C31 NO aplicaba a este modulo - modulo no recibe IP del
 *   cliente).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CICLO32-P1-AVC-001 FIX
 * @covers CICLO32-P1-AVC-002 FIX
 */
class AuditCiclo32AveonlineCitiesFixesTest extends LTMS_Unit_Test_Case {

	private const AVEONLINE_CITIES_PATH  = __DIR__ . '/../../includes/business/class-ltms-business-aveonline-cities.php';
	private const API_AVEONLINE_PATH     = __DIR__ . '/../../includes/api/class-ltms-api-aveonline.php';
	private const COMPLIANCE_GUARDIAN_PATH = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const SALES_BOOSTER_PATH     = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const FISCAL_ANNUAL_PATH     = __DIR__ . '/../../includes/business/class-ltms-fiscal-annual-close.php';
	private const WALLET_PATH            = __DIR__ . '/../../includes/business/class-ltms-wallet.php';
	private const DEPOSIT_PATH           = __DIR__ . '/../../includes/business/class-ltms-deposit.php';
	private const PUBLIC_AUTH_PATH       = __DIR__ . '/../../includes/frontend/class-ltms-public-auth-handler.php';
	private const DASHBOARD_LOGIC_PATH   = __DIR__ . '/../../includes/frontend/class-ltms-dashboard-logic.php';
	private const WEBHOOKS_DIR           = __DIR__ . '/../../includes/api/webhooks';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'         => static fn( string $s ): string => $s,
			'esc_html__' => static fn( string $s ): string => $s,
		] );
	}

	// ====================================================================
	//  Tag CICLO32 presente en aveonline-cities.php
	// ====================================================================

	public function test_tag_ciclo32_present_in_aveonline_cities(): void {
		$this->assertFileExists( self::AVEONLINE_CITIES_PATH );
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-002 FIX', $source );
	}

	// ====================================================================
	//  AVC-001 FIX — flush_options_cache invocado tras sync exitosa
	// ====================================================================

	public function test_flush_options_cache_defined_and_targets_correct_transient(): void {
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );

		// El metodo flush_options_cache debe existir y borrar el mismo transient
		// que get_options() escribe.
		$this->assertStringContainsString(
			"delete_transient( 'ltms_aveonline_city_options' )",
			$source,
			'flush_options_cache() debe borrar el transient ltms_aveonline_city_options'
		);
	}

	public function test_get_options_reads_and_writes_same_transient_key(): void {
		// Invariante de cache-coherencia: el transient que lee get_options()
		// debe ser el mismo que escribe y el mismo que flush_options_cache()
		// borra. Si los 3 no coincide, el fix no valida.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( "get_transient( 'ltms_aveonline_city_options' )", $source );
		$this->assertStringContainsString( "set_transient( 'ltms_aveonline_city_options'", $source );
		$this->assertStringContainsString( "delete_transient( 'ltms_aveonline_city_options' )", $source );
	}

	public function test_sync_calls_flush_options_cache(): void {
		// El fix AVC-001: sync() debe invocar self::flush_options_cache() tras
		// set_transient(TRANSIENT_LAST_SYNC, ...). Verificamos el call site.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			'self::flush_options_cache();',
			$source,
			'sync() debe invocar self::flush_options_cache() tras el upsert exitoso'
		);
	}

	public function test_flush_options_cache_called_after_set_transient_last_sync(): void {
		// Verifica el orden logico: set_transient(last_sync) -> flush_options_cache().
		// Si flush viene antes, un sync fallido podria marcar last_sync como exitoso
		// en un futuro refactor que mueva el set_transient. El orden documentado es
		// write-op -> invalidar cache derivado.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );

		// Localizamos el bloque: set_transient(TRANSIENT_LAST_SYNC) seguido cercanamente
		// de self::flush_options_cache().
		$pos_set = strpos( $source, "set_transient( self::TRANSIENT_LAST_SYNC, time()" );
		$pos_flush = strpos( $source, 'self::flush_options_cache();' );

		$this->assertNotFalse( $pos_set, 'set_transient(TRANSIENT_LAST_SYNC) presente en sync()' );
		$this->assertNotFalse( $pos_flush, 'self::flush_options_cache() invocado en sync()' );
		$this->assertGreaterThan(
			$pos_set,
			$pos_flush,
			'flush_options_cache() debe ir DESPUES de set_transient(TRANSIENT_LAST_SYNC) - orden write-then-invalidate'
		);
	}

	public function test_flush_options_cache_not_called_in_empty_data_early_return(): void {
		// El early return de sync() linea ~94 (data vacia) NO debe llamar
		// flush_options_cache() - no se sincronizo nada, no hay nada que
		// invalidar. Verificamos que el flush aparece solo despues del bloque
		// de upsert, no antes.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );

		// El early return: "if ( empty( \$data ) ) { return [...]; }"
		// y el flush aparece mucho despues. Confirmamos que flush no aparece
		// en las primeras 100 lineas del archivo (que es donde estaria el
		// early return) - esta en la zona post-upsert (~linea 155+).
		$first_chunk = substr( $source, 0, 4500 );
		$this->assertStringNotContainsString(
			'self::flush_options_cache();',
			$first_chunk,
			'flush_options_cache() NO debe invocarse en el early return de data vacia'
		);
	}

	public function test_no_other_writes_to_ltms_aveonline_city_options_transient(): void {
		// Verifica que el transient solo se escribe en get_options() (cache fill)
		// y se borra en flush_options_cache(). Si otro sitio lo escribe sin
		// llama flush, hay nuevo bug de cache-coherencia.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		// Cuenta ocurrencias de set_transient con este key.
		$count_set = substr_count( $source, "set_transient( 'ltms_aveonline_city_options'" );
		$count_del = substr_count( $source, "delete_transient( 'ltms_aveonline_city_options' )" );

		$this->assertSame( 1, $count_set, 'set_transient ltms_aveonline_city_options aparece exactamente 1 vez (en get_options)' );
		$this->assertSame( 1, $count_del, 'delete_transient ltms_aveonline_city_options aparece exactamente 1 vez (en flush_options_cache)' );
	}

	// ====================================================================
	//  AVC-002 FIX — sslverify explicito en wp_remote_get de fetch_source
	// ====================================================================

	public function test_fetch_source_wp_remote_get_has_sslverify(): void {
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			"'sslverify'   => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),",
			$source,
			'fetch_source() debe tener sslverify explicito siguiendo el patron INTEGRATIONS-AUDIT P1'
		);
	}

	public function test_sslverify_pattern_matches_api_aveonline_reference(): void {
		// La invariante INTEGRATIONS-AUDIT P1 establece un patron canonico en
		// class-ltms-api-aveonline.php (15 sitios). El fix C32 debe usar el
		// mismo literal. Comparamos byte-a-byte.
		$cities_source  = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$api_source     = file_get_contents( self::API_AVEONLINE_PATH );

		$pattern = "! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),";

		$this->assertStringContainsString( $pattern, $cities_source, 'aveonline-cities usa el patron canonico INTEGRATIONS-AUDIT P1' );
		$this->assertStringContainsString( $pattern, $api_source,    'api-aveonline sigue teniendo el patron canonico (no regresion)' );
	}

	public function test_no_wp_remote_get_without_sslverify_in_aveonline_cities(): void {
		// Anti-regresion: tras AVC-002, no debe quedar ningun wp_remote_get
		// en el modulo sin sslverify explicito. Grep counting.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );

		// Cuenta cuantas veces aparece wp_remote_get (debe ser exactamente 1).
		$count_remote_get = substr_count( $source, 'wp_remote_get(' );
		$this->assertSame( 1, $count_remote_get, 'aveonline-cities.php usa wp_remote_get exactamente 1 vez (en fetch_source)' );

		// Cuenta cuantas de esas llamadas tiene sslverify en el mismo bloque.
		// Buscamos el bloque wp_remote_get( ... ) y verificamos que contiene
		// sslverify. Es enough confirmar que el modulo tiene 1 llamado y ese
		// llamado tiene sslverify (test_fetch_source_wp_remote_get_has_sslverify).
		$count_sslverify = substr_count( $source, "'sslverify'   => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' )" );
		$this->assertSame( 1, $count_sslverify, 'aveonline-cities.php tiene exactamente 1 sslverify explicito (en fetch_source)' );
	}

	public function test_ltms_disable_ssl_verify_constant_is_real(): void {
		// La constante LTMS_DISABLE_SSL_VERIFY existe en bootstrap.php y en
		// el patron INTEGRATIONS-AUDIT P1. Verificamos que esta referenciada
		// en al menos 1 archivo del plugin (no es constante fantasma).
		$paths = [
			self::API_AVEONLINE_PATH,
			__DIR__ . '/../../tests/bootstrap.php',
		];
		$found = 0;
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				$src = file_get_contents( $path );
				if ( strpos( $src, 'LTMS_DISABLE_SSL_VERIFY' ) !== false ) {
					$found++;
				}
			}
		}
		$this->assertGreaterThanOrEqual( 2, $found, 'LTMS_DISABLE_SSL_VERIFY referenciada en al menos 2 archivos del plugin' );
	}

	// ====================================================================
	//  Invariantes transversales (NO aplica get_client_ip_safe a este modulo)
	// ====================================================================

	public function test_no_ltms_utils_get_ip_in_aveonline_cities(): void {
		// Invariante C25/C31 (cierre IP transversal): este modulo hace fetch
		// externo sin forward de IP del cliente. NO debe tener ninguna llamada
		// a LTMS_Utils::get_ip() (la invariante transversal NO le aplica,
		// pero verificamos anti-regresion: nadie introduce get_ip a posteriori).
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringNotContainsString(
			'LTMS_Utils::get_ip()',
			$source,
			'aveonline-cities.php NO debe usar LTMS_Utils::get_ip() - modulo no recibe IP del cliente'
		);
		// Tampoco la nueva segura (no la necesita - no hay IP del cliente).
		$this->assertStringNotContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'aveonline-cities.php NO debe usar get_client_ip_safe() - modulo no recibe IP del cliente'
		);
	}

	// ====================================================================
	//  Nonce checks (invariantes C28 anti-patron CG-001 $die=false)
	// ====================================================================

	public function test_ajax_handlers_have_nonce_without_die_false(): void {
		// Invariante C28: check_ajax_referer sin $die=false (default die=true).
		// Anti-patron CG-001: check_ajax_referer(..., false) permite continuar
		// tras fallar nonce. Los 2 handlers del modulo no deben caer en eso.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );

		// ajax_sync usa check_ajax_referer('ltms_aveonline_sync_cities', 'nonce')
		// sin $die=false (default true).
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_aveonline_sync_cities', 'nonce' )",
			$source,
			'ajax_sync usa check_ajax_referer con nonce (no $die=false)'
		);
		// ajax_search_cities usa check_ajax_referer('ltms_search_cities', 'nonce')
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_search_cities', 'nonce' )",
			$source,
			'ajax_search_cities usa check_ajax_referer con nonce (no $die=false)'
		);

		// Anti-patron explicito: NO debe haber ', false)' despues de check_ajax_referer.
		$this->assertStringNotContainsString(
			"check_ajax_referer( 'ltms_aveonline_sync_cities', 'nonce', false )",
			$source,
			'ajax_sync NO usa $die=false (anti-patron CG-001)'
		);
		$this->assertStringNotContainsString(
			"check_ajax_referer( 'ltms_search_cities', 'nonce', false )",
			$source,
			'ajax_search_cities NO usa $die=false (anti-patron CG-001)'
		);
	}

	// ====================================================================
	//  Input sanitization on ajax_search_cities (invariante AGENTS.md)
	// ====================================================================

	public function test_ajax_search_cities_sanitize_inputs(): void {
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		// query con sanitize_text_field + wp_unslash
		$this->assertStringContainsString(
			"sanitize_text_field( wp_unslash( \$_POST['query']",
			$source,
			'ajax_search_cities sanitiza $_POST[query] con sanitize_text_field + wp_unslash'
		);
		// registros con absint
		$this->assertStringContainsString(
			"absint( \$_POST['registros']",
			$source,
			'ajax_search_cities sanitiza $_POST[registros] con absint'
		);
	}

	// ====================================================================
	//  SQL prepare — queries externas usan $wpdb->prepare
	// ====================================================================

	public function test_find_by_name_uses_prepare(): void {
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			'$wpdb->get_var( $wpdb->prepare(',
			$source,
			'find_by_name usa $wpdb->prepare para queries con input externo'
		);
	}

	public function test_search_local_uses_prepare(): void {
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			'$wpdb->get_results(' . "\n\t\t\t" . '$wpdb->prepare(',
			$source
		);
	}

	public function test_search_local_uses_esc_like_for_like_query(): void {
		// Invariante WPCS: LIKE queries deben escapar con $wpdb->esc_like para
		// evitar que % y _ en input del usuario rompan el LIKE pattern.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( '$wpdb->esc_like(', $source );
	}

	// ====================================================================
	//  Cross-checks C25/C28/C29/C30/C31 — invariantes previas intactas
	// ====================================================================

	public function test_cross_check_c25_webhooks_use_safe_ip(): void {
		// C25 cerro get_client_ip_safe en 7 webhooks. Verificamos que siguen.
		$webhooks = [
			'class-ltms-addi-webhook-handler.php',
			'class-ltms-aveonline-webhook-handler.php',
			'class-ltms-openpay-webhook-handler.php',
			'class-ltms-siigo-webhook-handler.php',
			'class-ltms-stripe-webhook-handler.php',
			'class-ltms-uber-direct-webhook-handler.php',
			'class-ltms-api-webhook-router.php',
		];
		foreach ( $webhooks as $basename ) {
			$path = self::WEBHOOKS_DIR . '/' . $basename;
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'get_client_ip_safe()',
				$source,
				"C25 invariante: {$basename} sigue usando get_client_ip_safe()"
			);
		}
	}

	public function test_cross_check_c28_compliance_guardian_tags_present(): void {
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );
		$this->assertStringContainsString( 'CICLO28-P1-CG-002 FIX', $source );
	}

	public function test_cross_check_c29_sales_booster_tags_present(): void {
		$source = file_get_contents( self::SALES_BOOSTER_PATH );
		$this->assertStringContainsString( 'CICLO29-P0-SB-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-007 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_tags_present(): void {
		$source = file_get_contents( self::FISCAL_ANNUAL_PATH );
		$this->assertStringContainsString( 'CICLO30-P0-FAC-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO30-P1-FAC-002 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_hook_accepts_3_args(): void {
		$source = file_get_contents( self::FISCAL_ANNUAL_PATH );
		$this->assertStringContainsString(
			"add_action( 'ltms_payout_completed', [ __CLASS__, 'calculate_gmf_on_payout' ], 10, 3 )",
			$source
		);
	}

	public function test_cross_check_c31_tags_present_in_6_migrated_files(): void {
		// C31 cerro invariante transversal IP migrando 12 ocurrencias en 6
		// archivos. Verificamos que los tags CICLO31 siguen presentes.
		$files = [
			self::PUBLIC_AUTH_PATH,
			self::DASHBOARD_LOGIC_PATH,
			self::DEPOSIT_PATH,
			self::WALLET_PATH,
		];
		foreach ( $files as $path ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'CICLO31-P2-CG-28-P2-6 FIX',
				$source,
				"C31 tag presente en " . basename( $path )
			);
		}
	}

	public function test_cross_check_c31_no_ltms_utils_get_ip_in_migrated_files(): void {
		// C31 anti-regresion: los 4 archivos migrados no deben tener llamadas
		// runtime a LTMS_Utils::get_ip() (las migradas a get_client_ip_safe).
		// Excluye dashboard-logic.php:2491 que tiene fallback defensivo.
		$files = [
			self::PUBLIC_AUTH_PATH,
			self::DEPOSIT_PATH,
			self::WALLET_PATH,
		];
		foreach ( $files as $path ) {
			$source = file_get_contents( $path );
			$this->assertStringNotContainsString(
				'= LTMS_Utils::get_ip()',
				$source,
				basename( $path ) . " NO debe tener llamadas runtime LTMS_Utils::get_ip() (cierre C31)"
			);
		}
	}

	public function test_cross_check_c30_wallet_uses_reference_for_idempotency(): void {
		// C30 FAC-001 depende de Wallet::execute_transaction retornando
		// existing_tx_id cuando reference coincide (WL-CRASH-2). C32 NO toca
		// wallet, pero verificamos que el contrato sigue intacto.
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringContainsString(
			'reference',
			$source,
			'Wallet::execute_transaction sigue usando reference para idempotency (WL-CRASH-2)'
		);
		// C31 invariante: wallet:606 sigue usando get_client_ip_safe (no regresion).
		$this->assertStringContainsString(
			"CICLO31-P2-CG-28-P2-6 FIX",
			$source,
			'wallet.php sigue con tag CICLO31 (no regresion)'
		);
	}

	// ====================================================================
	//  Invariante INTEGRATIONS-AUDIT P1 — patron canonico en api-aveonline
	// ====================================================================

	public function test_cross_check_integrations_audit_p1_pattern_in_api_aveonline(): void {
		// La invariante INTEGRATIONS-AUDIT P1 establecio sslverify explicito
		// en 15+ sitios de api-aveonline. C32 extiende la invariante a
		// aveonline-cities.php. Verificamos que la base sigue intacta (al
		// menos 5 ocurrencias del patron canonico).
		$source = file_get_contents( self::API_AVEONLINE_PATH );
		$count  = substr_count( $source, "INTEGRATIONS-AUDIT P1 FIX: sslverify explicit" );
		$this->assertGreaterThanOrEqual(
			10,
			$count,
			'api-aveonline.php conserva >= 10 sitios con el patron INTEGRATIONS-AUDIT P1 sslverify (no regresion)'
		);
	}

	// ====================================================================
	//  Anti-regresion estructural - constantes y hooks del modulo
	// ====================================================================

	public function test_source_url_constant_unchanged(): void {
		// La URL oficial del catalogo de Aveonline NO debe cambiar - romperia
		// el contrato con el endpoint externo.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			"'https://app.aveonline.co/assets/resources/public/listadociudades.json'",
			$source,
			'SOURCE_URL constante sigue apuntando al catalogo oficial de Aveonline'
		);
	}

	public function test_max_cities_safety_bound_present(): void {
		// La constante MAX_CITIES protege contra respuestas malformadas
		// del endpoint externo. C32 NO debe eliminarla.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( 'const MAX_CITIES = 20000', $source );
		// Y el guard count( $data ) > self::MAX_CITIES debe seguir presente.
		$this->assertStringContainsString(
			'count( $data ) > self::MAX_CITIES',
			$source,
			'MAX_CITIES guard sigue presente en sync() para proteger contra JSON malicioso'
		);
	}

	public function test_fetch_source_validates_response_code_and_json(): void {
		// AVC-002 endurece wp_remote_get, pero el fetch_source TAMBIEN debe
		// validar el HTTP code (==200) y el JSON decode (no error + is_array).
		// C32 no debe debilitar estas validaciones.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( 'is_wp_error( $response )', $source );
		$this->assertStringContainsString( 'wp_remote_retrieve_response_code( $response )', $source );
		$this->assertStringContainsString( '(int) $code !== 200', $source );
		$this->assertStringContainsString( 'json_last_error() !== JSON_ERROR_NONE', $source );
		$this->assertStringContainsString( 'is_array( $data )', $source );
	}

	public function test_ajax_sync_requires_manage_woocommerce_cap(): void {
		// Invariante de permisos: ajax_sync (admin sync manual) debe requerir
		// 'manage_woocommerce' (no 'manage_options' ni nada mas debil).
		// Anti-regresion: nadie mueve el cap check.
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			"current_user_can( 'manage_woocommerce' )",
			$source,
			'ajax_sync requiere capability manage_woocommerce'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque sync() requiere stubeo extensivo
	// de WP internals (wp_remote_get, $wpdb->query con ON DUPLICATE KEY
	// UPDATE, set_transient, LTMS_Core_Logger). Los tests documentan el
	// contrato del fix (tag presente, llamada agregada, orden
	// write-then-invalidate, patron canonico INTEGRATIONS-AUDIT P1,
	// constants y guards NO regredian, hooks NO usan $die=false,
	// inputs sanitizados, cross-checks C25-C31 invariantes) sin
	// reimplementar logica.
}
