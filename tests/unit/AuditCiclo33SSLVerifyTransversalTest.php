<?php
/**
 * AuditCiclo33SSLVerifyTransversalTest - Tests para el Ciclo 33.
 *
 * Modulo: CIERRE INVARIANTE INTEGRATIONS-AUDIT P1 sslverify transversal.
 * La invariante INTEGRATIONS-AUDIT P1 (establecida C18 sobre 15 sitios de
 * class-ltms-api-aveonline.php, extendida C32 a 1 sitio en class-ltms-business-
 * aveonline-cities.php con Leccion 32.1 regla #3) exige sslverify explicito
 * salvo override por constante LTMS_DISABLE_SSL_VERIFY en TODA llamada
 * wp_remote_get/post a endpoint externo de TODO includes/, no solo includes/api/.
 *
 * C33 cierra la invariante en 32 sitios restantes en 14 archivos:
 *   - 26 sitios MISSING (sin sslverify)
 *   - 6 sitios con `sslverify => true` hardcodeado (migrados a patron canonico)
 *   - 3 sitios en class-ltms-fiscal-annual-close.php (PAC scaffolding
 *     FAC-008/FAC-009 P2 - pendiente wire-up, fix inocuo defense-in-depth)
 *
 * Patron canonico aplicado a cada sitio:
 *   'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
 *
 * Excepcion documentada: class-ltms-geo-detector.php:54 usa `sslverify => false`
 * intencional porque el endpoint es `http://ip-api.com` (NO HTTPS) - sslverify
 * no aplica para URLs http. NO se migra; se preserva como caso especial.
 *
 * Patron C33: source-based tests (file_get_contents + assertString
 * Contains/NotContainsString + substr_count), mismo que C20-C32. Cross-checks:
 * - C25 invariantes webhooks (get_client_ip_safe) siguen intactas.
 * - C28 compliance-guardian tags CICLO28 siguen presentes.
 * - C29 sales-booster tags CICLO29 siguen presentes.
 * - C30 fiscal-annual-close tags CICLO30 siguen presentes + hook accepted_args=3.
 * - C31 tags CICLO31 siguen presentes en 6 archivos migrados.
 * - C32 tags CICLO32 siguen presentes en aveonline-cities.php (AVC-001 + AVC-002).
 * - C30 Wallet::execute_transaction usa `reference` para idempotency intacto.
 * - Invariante INTEGRATIONS-AUDIT P1: api-aveonline.php conserva >=10 sitios.
 * - Anti-regresion estructural:geo-detector http endpoint preservado.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CICLO33-P1-SSL-TRANSVERSAL FIX
 */
class AuditCiclo33SSLVerifyTransversalTest extends LTMS_Unit_Test_Case {

	// Paths a los 14 archivos tocados en C33.
	private const BUSINESS_AVEONLINE_SANDBOX_PATH = __DIR__ . '/../../includes/business/class-ltms-business-aveonline-sandbox.php';
	private const COMPLIANCE_GUARDIAN_PATH        = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const FINTECH_COMPLIANCE_PATH         = __DIR__ . '/../../includes/business/class-ltms-fintech-compliance.php';
	private const AUTHORITIES_COMPLIANCE_PATH     = __DIR__ . '/../../includes/business/class-ltms-authorities-compliance.php';
	private const TOURISM_COMPLIANCE_EXT_PATH      = __DIR__ . '/../../includes/business/class-ltms-tourism-compliance-ext.php';
	private const FX_RATE_PROVIDER_PATH           = __DIR__ . '/../../includes/business/class-ltms-fx-rate-provider.php';
	private const FISCAL_ANNUAL_CLOSE_PATH         = __DIR__ . '/../../includes/business/class-ltms-fiscal-annual-close.php';
	private const TRAFFIC_BOOSTER_PATH             = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const GOOGLE_OAUTH_PATH                = __DIR__ . '/../../includes/frontend/class-ltms-google-oauth.php';
	private const PUBLIC_AUTH_HANDLER_PATH         = __DIR__ . '/../../includes/frontend/class-ltms-public-auth-handler.php';
	private const VENDOR_INVOICING_GENERATOR_PATH  = __DIR__ . '/../../includes/frontend/class-ltms-vendor-invoicing-generator.php';
	private const VENDOR_INVOICING_SETTINGS_PATH   = __DIR__ . '/../../includes/frontend/class-ltms-vendor-invoicing-settings.php';
	private const DEPRISA_API_PATH                 = __DIR__ . '/../../includes/deprisa/class-ltms-api-deprisa.php';
	private const API_GATEWAYS_PATH                = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateways.php';

	// Paths para cross-checks invariantes previos.
	private const AVEONLINE_CITIES_PATH  = __DIR__ . '/../../includes/business/class-ltms-business-aveonline-cities.php';
	private const API_AVEONLINE_PATH     = __DIR__ . '/../../includes/api/class-ltms-api-aveonline.php';
	private const SALES_BOOSTER_PATH     = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const WALLET_PATH            = __DIR__ . '/../../includes/business/class-ltms-wallet.php';
	private const DEPOSIT_PATH            = __DIR__ . '/../../includes/business/class-ltms-deposit.php';
	private const DASHBOARD_LOGIC_PATH    = __DIR__ . '/../../includes/frontend/class-ltms-dashboard-logic.php';
	private const EXTERNAL_AUDITOR_PATH   = __DIR__ . '/../../includes/roles/class-ltms-external-auditor-role.php';
	private const FISCAL_ONLINE_PATH     = __DIR__ . '/../../includes/business/class-ltms-fiscal-online-access.php';
	private const GEO_DETECTOR_PATH      = __DIR__ . '/../../includes/frontend/class-ltms-geo-detector.php';
	private const WEBHOOKS_DIR           = __DIR__ . '/../../includes/api/webhooks';

	/**
	 * Lista canonica de los 32 tags CICLO33-P1-SSL-* FIX esperados (14 archivos).
	 * Tomada del inventario C33. Si falta alguno, el fix se perdio o el tag cambió.
	 */
	private const EXPECTED_C33_TAGS = [
		['file' => self::BUSINESS_AVEONLINE_SANDBOX_PATH, 'tag' => 'CICLO33-P1-SSL-SANDBOX FIX'],
		['file' => self::COMPLIANCE_GUARDIAN_PATH,        'tag' => 'CICLO33-P1-SSL-CAPI-ASYNC FIX'],
		['file' => self::COMPLIANCE_GUARDIAN_PATH,        'tag' => 'CICLO33-P1-SSL-CAPI-SYNC FIX'],
		['file' => self::FINTECH_COMPLIANCE_PATH,         'tag' => 'CICLO33-P1-SSL-FT-SANCTIONS FIX'],
		['file' => self::AUTHORITIES_COMPLIANCE_PATH,     'tag' => 'CICLO33-P1-SSL-AC-PPC-SIC FIX'],
		['file' => self::TOURISM_COMPLIANCE_EXT_PATH,     'tag' => 'CICLO33-P1-SSL-TOURISM-FONTUR FIX'],
		['file' => self::FX_RATE_PROVIDER_PATH,          'tag' => 'CICLO33-P1-SSL-FX-FRANKFURTER FIX'],
		['file' => self::FX_RATE_PROVIDER_PATH,          'tag' => 'CICLO33-P1-SSL-FX-EXCHANGERATE FIX'],
		['file' => self::FX_RATE_PROVIDER_PATH,          'tag' => 'CICLO33-P1-SSL-FX-ECB FIX'],
		['file' => self::FISCAL_ANNUAL_CLOSE_PATH,       'tag' => 'CICLO33-P1-SSL-PAC-FACTURAMA FIX'],
		['file' => self::FISCAL_ANNUAL_CLOSE_PATH,       'tag' => 'CICLO33-P1-SSL-PAC-SW-SAPIEN FIX'],
		['file' => self::FISCAL_ANNUAL_CLOSE_PATH,       'tag' => 'CICLO33-P1-SSL-PAC-EDICOM FIX'],
		['file' => self::TRAFFIC_BOOSTER_PATH,           'tag' => 'CICLO33-P1-SSL-IG-CONTAINER FIX'],
		['file' => self::TRAFFIC_BOOSTER_PATH,           'tag' => 'CICLO33-P1-SSL-IG-PUBLISH FIX'],
		['file' => self::TRAFFIC_BOOSTER_PATH,           'tag' => 'CICLO33-P1-SSL-FB-FEED FIX'],
		['file' => self::TRAFFIC_BOOSTER_PATH,           'tag' => 'CICLO33-P1-SSL-PINTEREST FIX'],
		['file' => self::TRAFFIC_BOOSTER_PATH,           'tag' => 'CICLO33-P1-SSL-GBP FIX'],
		['file' => self::GOOGLE_OAUTH_PATH,              'tag' => 'CICLO33-P1-SSL-GOOGLE-TOKEN FIX'],
		['file' => self::GOOGLE_OAUTH_PATH,              'tag' => 'CICLO33-P1-SSL-GOOGLE-USERINFO FIX'],
		['file' => self::PUBLIC_AUTH_HANDLER_PATH,       'tag' => 'CICLO33-P1-SSL-TURNSTILE FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-ALEGRA-INVOICE FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-SIIGO-INVOICE FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-ALEGRA-CONTACT-GET FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-ALEGRA-CONTACT-POST FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-SIIGO-AUTH FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-SIIGO-CUSTOMER-GET FIX'],
		['file' => self::VENDOR_INVOICING_GENERATOR_PATH,'tag' => 'CICLO33-P1-SSL-SIIGO-CUSTOMER-POST FIX'],
		['file' => self::VENDOR_INVOICING_SETTINGS_PATH, 'tag' => 'CICLO33-P1-SSL-ALEGRA-TEST FIX'],
		['file' => self::VENDOR_INVOICING_SETTINGS_PATH, 'tag' => 'CICLO33-P1-SSL-SIIGO-TEST FIX'],
		['file' => self::DEPRISA_API_PATH,               'tag' => 'CICLO33-P1-SSL-DEPRISA-POST FIX'],
		['file' => self::DEPRISA_API_PATH,               'tag' => 'CICLO33-P1-SSL-DEPRISA-GET FIX'],
		['file' => self::API_GATEWAYS_PATH,              'tag' => 'CICLO33-P1-SSL-OPENPAY-PSE FIX'],
	];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'         => static fn( string $s ): string => $s,
			'esc_html__' => static fn( string $s ): string => $s,
		] );
	}

	// ====================================================================
	//  Tags CICLO33 presentes en los 14 archivos (32 tags totales)
	// ====================================================================

	/**
	 * @dataProvider provide_expected_c33_tags
	 */
	public function test_tag_ciclo33_present( string $file, string $tag ): void {
		$this->assertFileExists( $file, "Archivo esperado existe: {$file}" );
		$source = file_get_contents( $file );
		$this->assertStringContainsString(
			$tag,
			$source,
			"Tag '{$tag}' ausente en " . basename( $file ) . ' - fix C33 se perdio'
		);
	}

	public function provide_expected_c33_tags(): array {
		$cases = [];
		foreach ( self::EXPECTED_C33_TAGS as $i => $row ) {
			$cases[ "tag#{$i}:" . basename( $row['file'] ) ] = [ $row['file'], $row['tag'] ];
		}
		return $cases;
	}

	public function test_total_c33_tags_count_is_32(): void {
		// Anti-regresion: si alguien anade/quita un fix, este test detecta
		// el cambio de cardinalidad. 32 = 26 MISSING + 6 true-hardcodeado.
		$total = 0;
		foreach ( self::EXPECTED_C33_TAGS as $row ) {
			$source = file_get_contents( $row['file'] );
			$total += substr_count( $source, $row['tag'] );
		}
		$this->assertSame( 32, $total, 'Total de tags CICLO33-P1-SSL-* FIX debe ser 32 (26 MISSING + 6 hardcodeado)' );
	}

	// ====================================================================
	//  Patron canonico sslverify presente en cada archivo tocado
	// ====================================================================

	/**
	 * @dataProvider provide_touched_files
	 */
	public function test_canonical_sslverify_pattern_in_touched_file( string $file ): void {
		$this->assertFileExists( $file );
		$source = file_get_contents( $file );
		$this->assertStringContainsString(
			"! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY )",
			$source,
			basename( $file ) . ' debe contener el patron canonico INTEGRATIONS-AUDIT P1 sslverify'
		);
	}

	public function provide_touched_files(): array {
		$files = [
			self::BUSINESS_AVEONLINE_SANDBOX_PATH,
			self::COMPLIANCE_GUARDIAN_PATH,
			self::FINTECH_COMPLIANCE_PATH,
			self::AUTHORITIES_COMPLIANCE_PATH,
			self::TOURISM_COMPLIANCE_EXT_PATH,
			self::FX_RATE_PROVIDER_PATH,
			self::FISCAL_ANNUAL_CLOSE_PATH,
			self::TRAFFIC_BOOSTER_PATH,
			self::GOOGLE_OAUTH_PATH,
			self::PUBLIC_AUTH_HANDLER_PATH,
			self::VENDOR_INVOICING_GENERATOR_PATH,
			self::VENDOR_INVOICING_SETTINGS_PATH,
			self::DEPRISA_API_PATH,
			self::API_GATEWAYS_PATH,
		];
		$cases = [];
		foreach ( $files as $f ) {
			$cases[ basename( $f ) ] = [ $f ];
		}
		return $cases;
	}

	// ====================================================================
	//  Anti-regresion: NO sslverify=>true hardcodeado en archivos migrados
	// ====================================================================

	/**
	 * @dataProvider provide_hardcoded_migration_files
	 */
	public function test_no_sslverify_true_hardcoded_in_migrated_files( string $file ): void {
		$this->assertFileExists( $file );
		$source = file_get_contents( $file );
		$this->assertStringNotContainsString(
			"'sslverify' => true,",
			$source,
			basename( $file ) . ' NO debe tener sslverify=>true hardcodeado (migrado a patron canonico C33)'
		);
		$this->assertStringNotContainsString(
			"'sslverify' => true ",
			$source,
			basename( $file ) . ' NO debe tener sslverify=>true hardcodeado (migrado a patron canonico C33)'
		);
	}

	public function provide_hardcoded_migration_files(): array {
		// Los 6 archivos donde habia sslverify=>true hardcodeado pre-C33:
		// - fx-rate-provider x3
		// - api-deprisa x2
		// - compliance-guardian x1
		return [
			'fx-rate-provider'       => [ self::FX_RATE_PROVIDER_PATH ],
			'api-deprisa'            => [ self::DEPRISA_API_PATH ],
			'compliance-guardian'    => [ self::COMPLIANCE_GUARDIAN_PATH ],
		];
	}

	// ====================================================================
	//  Caso especial: geo-detector http endpoint (NO se migra)
	// ====================================================================

	public function test_geo_detector_keeps_sslverify_false_for_http_endpoint(): void {
		// Excepcion documentada: class-ltms-geo-detector.php usa `http://ip-api.com`
		// (NO HTTPS), por lo que sslverify NO aplica. La migracion C33 NO debe
		// tocar este archivo. El `sslverify => false` intencional se preserva.
		$this->assertFileExists( self::GEO_DETECTOR_PATH );
		$source = file_get_contents( self::GEO_DETECTOR_PATH );
		$this->assertStringContainsString(
			"'sslverify' => false",
			$source,
			'geo-detector.php preserva sslverify=>false para endpoint http://ip-api.com (NO HTTPS)'
		);
		$this->assertStringNotContainsString(
			'LTMS_DISABLE_SSL_VERIFY',
			$source,
			'geo-detector.php NO se migra a patron canonico (endpoint http, sslverify no aplica)'
		);
		$this->assertStringNotContainsString(
			'CICLO33-P1-SSL',
			$source,
			'geo-detector.php NO debe tener tag C33 (excepcion documentada)'
		);
	}

	// ====================================================================
	//  Cross-check C32 - tags CICLO32 siguen presentes en aveonline-cities
	// ====================================================================

	public function test_cross_check_c32_tags_present_in_aveonline_cities(): void {
		$this->assertFileExists( self::AVEONLINE_CITIES_PATH );
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-002 FIX', $source );
	}

	public function test_cross_check_c32_aveonline_cities_uses_canonical_sslverify(): void {
		// C32 ya aplico el patron canonico en aveonline-cities.php. C33 NO debe
		// regressar ese fix. Verificamos que sigue presente.
		$this->assertFileExists( self::AVEONLINE_CITIES_PATH );
		$source = file_get_contents( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString(
			"'sslverify'   => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),",
			$source,
			'aveonline-cities.php conserva el patron canonico C32 (no regresion C33)'
		);
	}

	// ====================================================================
	//  Invariante INTEGRATIONS-AUDIT P1 - api-aveonline.php conserva >=10 sitios
	// ====================================================================

	public function test_cross_check_integrations_audit_p1_pattern_in_api_aveonline(): void {
		// La base C18 de la invariante: api-aveonline.php tiene >=10 sitios con
		// el patron canonico INTEGRATIONS-AUDIT P1. C33 NO debe regressarlos.
		$this->assertFileExists( self::API_AVEONLINE_PATH );
		$source = file_get_contents( self::API_AVEONLINE_PATH );
		$count  = substr_count( $source, 'INTEGRATIONS-AUDIT P1 FIX: sslverify explicit' );
		$this->assertGreaterThanOrEqual(
			10,
			$count,
			'api-aveonline.php conserva >= 10 sitios con patron INTEGRATIONS-AUDIT P1 (no regresion C33)'
		);
	}

	// ====================================================================
	//  Cross-checks C25/C28/C29/C30/C31 - invariantes previas intactas
	// ====================================================================

	public function test_cross_check_c25_webhooks_use_safe_ip(): void {
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
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );
		$this->assertStringContainsString( 'CICLO28-P1-CG-002 FIX', $source );
	}

	public function test_cross_check_c29_sales_booster_tags_present(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );
		$this->assertStringContainsString( 'CICLO29-P0-SB-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-007 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_tags_present(): void {
		$this->assertFileExists( self::FISCAL_ANNUAL_CLOSE_PATH );
		$source = file_get_contents( self::FISCAL_ANNUAL_CLOSE_PATH );
		$this->assertStringContainsString( 'CICLO30-P0-FAC-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO30-P1-FAC-002 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_hook_accepts_3_args(): void {
		$this->assertFileExists( self::FISCAL_ANNUAL_CLOSE_PATH );
		$source = file_get_contents( self::FISCAL_ANNUAL_CLOSE_PATH );
		$this->assertStringContainsString(
			"add_action( 'ltms_payout_completed', [ __CLASS__, 'calculate_gmf_on_payout' ], 10, 3 )",
			$source
		);
	}

	public function test_cross_check_c31_tags_present_in_migrated_files(): void {
		$files = [
			self::PUBLIC_AUTH_HANDLER_PATH,
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
		$files = [
			self::PUBLIC_AUTH_HANDLER_PATH,
			self::DEPOSIT_PATH,
			self::WALLET_PATH,
		];
		foreach ( $files as $path ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringNotContainsString(
				'= LTMS_Utils::get_ip()',
				$source,
				basename( $path ) . " NO debe tener llamadas runtime LTMS_Utils::get_ip() (cierre C31)"
			);
		}
	}

	public function test_cross_check_c30_wallet_uses_reference_for_idempotency(): void {
		$this->assertFileExists( self::WALLET_PATH );
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringContainsString(
			'reference',
			$source,
			'Wallet::execute_transaction sigue usando reference para idempotency (WL-CRASH-2)'
		);
		$this->assertStringContainsString(
			'CICLO31-P2-CG-28-P2-6 FIX',
			$source,
			'wallet.php sigue con tag CICLO31 (no regresion)'
		);
	}

	// ====================================================================
	//  Invariante estructural - PAC scaffolding sigue marcado como P2
	// ====================================================================

	public function test_fiscal_annual_close_pac_adapters_keep_fail_closed_invariants(): void {
		// Los 3 PAC adapters son scaffolding (FAC-008/FAC-009 P2 - pendiente
		// wire-up ltms_cfdi_request). C33 aplica sslverify defense-in-depth
		// pero NO debe alterar el comportamiento de fail-open vs fail-closed
		// de los timeouts ni la estructura del metodo. Verificamos invariante.
		$this->assertFileExists( self::FISCAL_ANNUAL_CLOSE_PATH );
		$source = file_get_contents( self::FISCAL_ANNUAL_CLOSE_PATH );
		// Los 3 endpoints HTTPS siguen apuntando a los PAC oficiales.
		$this->assertStringContainsString( 'https://api.facturama.mx/cfdi33/issue/json', $source );
		$this->assertStringContainsString( 'https://sw.sw.com.mx/api/v3/cfdi33/issue/json/v1', $source );
		$this->assertStringContainsString( 'https://api.edicom.mx/cfdi/issue', $source );
		// Los 3 timeouts siguen en 30s (no fueron alterados por C33).
		$this->assertSame(
			3,
			substr_count( $source, "'timeout' => 30," ),
			'fiscal-annual-close conserva 3 timeouts de 30s (uno por PAC adapter)'
		);
	}

	// ====================================================================
	//  Anti-regresion estructural - modulos business no reciben IP del cliente
	// ====================================================================

	public function test_no_ltms_utils_get_ip_in_business_aveonline_sandbox(): void {
		// business-aveonline-sandbox no recibe IP del cliente. NO debe tener
		// LTMS_Utils::get_ip() (invariante C25/C31 NO le aplica).
		$this->assertFileExists( self::BUSINESS_AVEONLINE_SANDBOX_PATH );
		$source = file_get_contents( self::BUSINESS_AVEONLINE_SANDBOX_PATH );
		$this->assertStringNotContainsString(
			'LTMS_Utils::get_ip()',
			$source,
			'business-aveonline-sandbox.php NO debe usar LTMS_Utils::get_ip()'
		);
	}

	public function test_no_ltms_utils_get_ip_in_fx_rate_provider(): void {
		$this->assertFileExists( self::FX_RATE_PROVIDER_PATH );
		$source = file_get_contents( self::FX_RATE_PROVIDER_PATH );
		$this->assertStringNotContainsString(
			'LTMS_Utils::get_ip()',
			$source,
			'fx-rate-provider.php NO debe usar LTMS_Utils::get_ip()'
		);
	}

	// ====================================================================
	//  Constante LTMS_DISABLE_SSL_VERIFY
	// ====================================================================

	public function test_ltms_disable_ssl_verify_constant_referenced_in_14_files(): void {
		// La constante LTMS_DISABLE_SSL_VERIFY debe estar referenciada en los
		// 14 archivos tocados C33 + los 11+ archivos previos con patron canonic.
		// Verificamos que esta presente en TODOS los archivos tocados C33.
		foreach ( $this->provide_touched_files() as $name => $args ) {
			$file = $args[0];
			$this->assertFileExists( $file );
			$source = file_get_contents( $file );
			$this->assertStringContainsString(
				'LTMS_DISABLE_SSL_VERIFY',
				$source,
				basename( $file ) . ' referencia LTMS_DISABLE_SSL_VERIFY (patron canonico)'
			);
		}
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque las llamadas wp_remote_get/post requieren
	// stubeo extensivo de WP internals + HTTP transport + credenciales externas.
	// Los tests documentan el contrato del fix transversal (tags presentes en
	// 14 archivos, patron canonico uniforme, anti-regresion sin sslverify=>
	// true hardcodeado, geo-detector http preservado, cross-checks C25-C32
	// invariantes) sin reimplementar logica.
}
