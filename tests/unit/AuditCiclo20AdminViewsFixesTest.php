<?php
/**
 * AuditCiclo20AdminViewsFixesTest - Tests para los fixes del Ciclo 20.
 *
 * Cobertura:
 *
 * Modulo A: includes/admin/views/html-admin-kyc.php (lista de KYC admin)
 *   - AD-058 P1: falta capability check defense-in-depth en el view. El menu
 *     WP (class-ltms-admin.php:124) exige capability 'ltms_manage_kyc' pero
 *     el view no redundaba con current_user_can() como SI lo hace
 *     view-auditor-dashboard.php:22. Si otro dev registra el slug sin
 *     capability o un hook reescribe el callback, KYC data leak. Fix:
 *     current_user_can('ltms_manage_kyc') + wp_die(..., 403) al inicio del
 *     view. Patron WordPress VIP / WPCS.
 *   - AD-060 P1: $status se sanitizaba (sanitize_key) pero no se validaba
 *     contra allowlist {pending, approved, rejected}. Un valor no listado
 *     pasaba al WHERE k.status = %s y devolvia 0 filas silenciosamente
 *     (UX rota). Fix: in_array($raw_status, ['pending','approved','rejected'],
 *     true). Mismo patron defensive que AD-033 country/level allowlist
 *     (CICLO14).
 *   - AD-061 P1: $b2_client = new LTMS_Api_Backblaze() se construia en
 *     CADA render del view (incl. paginacion, tabs) aunque el modal docs
 *     lo pide via AJAX aparte (action=ltms_kyc_proxy_doc). El constructor
 *     inicializa token + bucket lookup (1-2 HTTP a B2). Como el proxy
 *     admin-ajax autentica y firma ad-hoc, el view NO necesita el cliente
 *     B2 en runtime. Fix: $b2_available = false; $b2_client = null;
 *     sin try/catch Throwable. Comentario explica como reinicializar si
 *     se requiere firma directa legacy.
 *
 * Modulo B: includes/admin/views/view-auditor-dashboard.php (panel
 *   auditor externo - Art. 30-B CFF / E.T. Art. 437-2 / SAGRILAFT)
 *   - AD-083 P1: wp_die sin status code explicito devolvia HTTP 200 con
 *     el mensaje "No tienes permiso" -> incorrecto para auditoria forense
 *     que espera 403 en access logs. Fix: wp_die($msg, '', 403).
 *   - AD-066 P1: el export CSV se disparaba con ?export=csv sin nonce.
 *     Aunque la descarga no muta state del servidor, un atacante podria
 *     forzar al auditor a descargar un CSV via link malicioso (CSRF de
 *     descarga). Defense-in-depth: requerir _wpnonce con accion
 *     'ltms_auditor_export_csv'. Fix: wp_verify_nonce +
 *     wp_nonce_url en export_url.
 *   - AD-067 P1: SHOW COLUMNS FROM lt_commissions se ejecutaba 2 veces
 *     por page load (1 export path + 1 panel web) sin cacheo. Es una query
 *     de schema (rapida pero still 1 round-trip + parse de
 *     information_schema cada vez). Fix: get_transient +
 *     set_transient HOUR_IN_SECONDS. Mismo cache key en ambos paths.
 *   - AD-068/AD-077 P1: printf( __( 'Umbral: $%s ... UIAF' ) ) sin
 *     esc_html + middot (U+00B7) no-ASCII en source. Fix: esc_html__ +
 *     reemplazo ' · ' por ' / ' para mantener ASCII puro.
 *
 * Hallazgos descartados (P2 backlog, no fixeados en C20):
 *   - AD-059 P1 (query COUNT(*) sin prepare): no inyectable (tabla fija
 *     sin user input). phpcs:disable WordPress.DB.DirectDatabaseQuery es
 *     explicito. Aceptado como documental.
 *   - AD-069 P1 (escape de todos los string en esc_html_e): ya hecho en
 *     el 99% del view - los uncos sin esc_html eran el printf del AD-068
 *     (arreglado) y otros ya validados previamente en CICLO14.
 *   - AD-079 P1 (filename traversal en CSV): $fn_suffix viene de $country
 *     (allowlist) + $date_from/$date_to (DateTime validated). defense-in-
 *     depth quitaria pero no rompe nada. Backlog.
 *   - AD-081 P1 (SHOW COLUMNS duplicado): mergeado en AD-067 — el
 *     transient cubre ambos paths.
 *   - AD-062 P2 (U+2026 ellipsis en placeholder): cosmetico, no rompe
 *     scripts.
 *   - AD-071 P2 (paginacion hardcode 12): UX, no seguridad.
 *   - AD-073 P2 ($f alias colision con $fn): refactor estetico.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AD-058, AD-060, AD-061, AD-066, AD-067, AD-068, AD-077, AD-083
 */
class AuditCiclo20AdminViewsFixesTest extends LTMS_Unit_Test_Case {

	private const KYC_PATH        = __DIR__ . '/../../includes/admin/views/html-admin-kyc.php';
	private const AUDITOR_PATH    = __DIR__ . '/../../includes/admin/views/view-auditor-dashboard.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'sanitize_text_field' => static fn( string $s ): string => $s,
			'sanitize_key'        => static fn( string $s ): string => strtolower( preg_replace( '/[^a-z0-9_-]/i', '-', $s ) ?? $s ),
			'__'                  => static fn( string $s ): string => $s,
			'esc_html__'          => static fn( string $s ): string => $s,
			'esc_html_e'          => static function ( string $s ): void { echo $s; },
			'esc_js'              => static fn( string $s ): string => $s,
			'wp_unslash'          => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
			'wp_create_nonce'     => static fn( string $a = '' ): string => 'nonce_' . $a,
			'wp_verify_nonce'     => static fn( ?string $n, string $a = '' ): bool => $n === 'nonce_' . $a,
			'wp_nonce_url'        => static fn( string $url, string $a = '', string $n = '' ): string => $url . '&_wpnonce=nonce_' . $a,
			'current_user_can'    => static fn( string $c ): bool => true,
			'wp_die'              => static function ( $msg = '', $title = '', $args = [] ): void {
				// Brain\Monkey no soporta wp_die real; stubear para que no mate el proceso.
				throw new \RuntimeException( 'wp_die_called: ' . (string) $msg . ' / ' . (string) $title . ' / ' . ( is_array( $args ) ? json_encode( $args ) : $args ) );
			},
			'get_transient'        => static fn( string $k ) => false,
			'set_transient'        => static fn( string $k, $v, int $t = 3600 ): bool => true,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ====================================================================
	//  Modulo A — html-admin-kyc.php
	// ====================================================================

	// ---- AD-058 P1: capability check defense-in-depth -------------------

	public function test_kyc_view_has_capability_check_defense_in_depth(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			"if ( ! current_user_can( 'ltms_manage_kyc' ) )",
			$source,
			'AD-058: html-admin-kyc.php debe tener current_user_can(\'ltms_manage_kyc\') defense-in-depth.'
		);
	}

	public function test_kyc_view_wp_die_has_403_status_code(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// Buscar wp_die con 403 explicito.
		$this->assertMatchesRegularExpression(
			'/wp_die\(\s*[^,]+,\s*[^,]+,\s*403\s*\)/',
			$source,
			'AD-058: wp_die debe llevar 403 como 3er arg para que access log registre forbidden.'
		);
	}

	public function test_kyc_view_has_ciclo20_tag_ad058(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-058 FIX',
			$source,
			'AD-058: tag de trazabilidad CICLO20-P1-AD-058 FIX debe estar en el source.'
		);
	}

	// ---- AD-060 P1: $status allowlist ----------------------------------

	public function test_kyc_status_validated_against_allowlist(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			"in_array( \$raw_status, [ 'pending', 'approved', 'rejected' ], true )",
			$source,
			'AD-060: $status debe validarse contra allowlist [pending, approved, rejected] strict comparison.'
		);
	}

	public function test_kyc_status_falls_back_to_pending_when_invalid(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// El ternario debe caer a 'pending' cuando allowlist falla.
		$this->assertStringContainsString(
			"? \$raw_status : 'pending'",
			$source,
			'AD-060: fallback a pending cuando $raw_status no esta en allowlist.'
		);
	}

	public function test_kyc_status_no_longer_uses_raw_sanitize_key_only(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// Antes: $status = sanitize_key( $_GET['status'] ?? 'pending' );
		// Ese patron (sin in_array) ya NO debe existir en su forma directa.
		$this->assertStringNotContainsString(
			"\$status = sanitize_key( \$_GET['status'] ?? 'pending' );",
			$source,
			'AD-060: el patron vulnerable original (sanitize_key sin allowlist) NO debe existir.'
		);
	}

	public function test_kyc_view_has_ciclo20_tag_ad060(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-060 FIX',
			$source,
			'AD-060: tag de trazabilidad CICLO20-P1-AD-060 FIX debe estar en el source.'
		);
	}

	// ---- AD-061 P1: lazy init B2 client --------------------------------

	public function test_kyc_view_does_not_construct_b2_client_in_render(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// Antes: try { $b2_client = new LTMS_Api_Backblaze(); } catch ...
		// Ese try-catch de instanciacion NO debe existir.
		$this->assertStringNotContainsString(
			'new LTMS_Api_Backblaze()',
			$source,
			'AD-061: el view NO debe instanciar LTMS_Api_Backblaze en cada render (coste HTTP B2).'
		);
	}

	public function test_kyc_view_b2_available_is_hardcoded_false(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// Despues del fix: $b2_available = false; (literal, no lectura de Config).
		$this->assertStringContainsString(
			"\$b2_available = false;",
			$source,
			'AD-061: $b2_available debe ser false literal (no se consulta LTMS_Core_Config en cada render).'
		);
	}

	public function test_kyc_view_b2_client_is_hardcoded_null(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			"\$b2_client    = null;",
			$source,
			'AD-061: $b2_client debe ser null literal.'
		);
	}

	public function test_kyc_view_does_not_query_backblaze_enabled_config(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		// Antes: $b2_available = LTMS_Core_Config::get( 'ltms_backblaze_enabled', 'no' ) === 'yes';
		// Esa lectura en runtime NO debe existir (el proxy AJAX lo hace).
		$this->assertStringNotContainsString(
			"ltms_backblaze_enabled",
			$source,
			'AD-061: el view NO debe consultar ltms_backblaze_enabled en runtime (lo hace el proxy AJAX).'
		);
	}

	public function test_kyc_view_has_ciclo20_tag_ad061(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-061 FIX',
			$source,
			'AD-061: tag de trazabilidad CICLO20-P1-AD-061 FIX debe estar en el source.'
		);
	}

	// ====================================================================
	//  Modulo B — view-auditor-dashboard.php
	// ====================================================================

	// ---- AD-083 P1: wp_die con 403 status code -------------------------

	public function test_auditor_view_wp_die_has_403_status_code(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// wp_die( $msg, '', 403 ) - ultimos 2 args: string '', entero 403.
		// Regex permisivo: wp_die( ... , '', 403 ).
		$this->assertMatchesRegularExpression(
			'/wp_die\(.+?,\s*\'\'\s*,\s*403\s*\)/s',
			$source,
			'AD-083: wp_die debe llevar 403 como 3er arg, no string vacio.'
		);
	}

	public function test_auditor_view_wp_die_no_longer_uses_1_arg_form(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Antes: wp_die( esc_html__( '...' ) ); (1 arg).
		// Ahora debe haber 3 args. Buscar el patron viejo con tilde 'pagina'.
		// Pattern UTF-8: 'pagina' con U+00ED (0xC3 0xAD) - 2 bytes.
		$this->assertStringNotContainsString(
			"permiso para acceder a esta p\xC3\xA1gina.",
			$source,
			'AD-083: el mensaje con tilde del 1-arg original debe estar ausente.'
		);
	}

	public function test_auditor_view_has_ciclo20_tag_ad083(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-083 FIX',
			$source,
			'AD-083: tag de trazabilidad CICLO20-P1-AD-083 FIX debe estar en el source.'
		);
	}

	// ---- AD-066 P1: nonce en CSV export URL + validacion ---------------

	public function test_auditor_view_export_csv_requires_nonce(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// El boolean de $export_csv debe incluir wp_verify_nonce.
		$this->assertStringContainsString(
			"wp_verify_nonce( sanitize_text_field( \$_GET['_wpnonce'] ), 'ltms_auditor_export_csv' )",
			$source,
			'AD-066: $export_csv debe requerir wp_verify_nonce con accion ltms_auditor_export_csv.'
		);
	}

	public function test_auditor_view_export_url_uses_wp_nonce_url(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"wp_nonce_url( add_query_arg( 'export', 'csv', \$base_url ), 'ltms_auditor_export_csv' )",
			$source,
			'AD-066: export_url debe construirse con wp_nonce_url para que el link incluya _wpnonce.'
		);
	}

	public function test_auditor_view_export_csv_no_longer_bare_export_check(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Antes: $export_csv = isset( $_GET['export'] ) && $_GET['export'] === 'csv';
		// Esa linea sola (sin nonce) NO debe existir.
		$this->assertStringNotContainsString(
			"\$export_csv = isset( \$_GET['export'] ) && \$_GET['export'] === 'csv';\n",
			$source,
			'AD-066: el boolean sin nonce NO debe existir (CSRF abierto).'
		);
	}

	public function test_auditor_view_has_ciclo20_tag_ad066(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-066 FIX',
			$source,
			'AD-066: tag de trazabilidad CICLO20-P1-AD-066 FIX debe estar.'
		);
	}

	// ---- AD-067 P1: SHOW COLUMNS cacheado en transient ----------------

	public function test_auditor_view_show_columns_uses_get_transient_export_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"get_transient( 'ltms_schema_lt_commissions' )",
			$source,
			'AD-067: export path debe usar get_transient para SHOW COLUMNS.'
		);
	}

	public function test_auditor_view_show_columns_uses_set_transient_export_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"set_transient( 'ltms_schema_lt_commissions', \$exp_check_cached, HOUR_IN_SECONDS )",
			$source,
			'AD-067: export path debe usar set_transient HOUR_IN_SECONDS.'
		);
	}

	public function test_auditor_view_show_columns_uses_get_transient_panel_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"get_transient( 'ltms_schema_lt_commissions' )",
			$source,
			'AD-067: panel path debe usar el mismo transient key.'
		);
	}

	public function test_auditor_view_show_columns_uses_set_transient_panel_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"set_transient( 'ltms_schema_lt_commissions', \$col_check_cached, HOUR_IN_SECONDS )",
			$source,
			'AD-067: panel path debe usar set_transient HOUR_IN_SECONDS con $col_check_cached.'
		);
	}

	public function test_auditor_view_show_columns_not_called_directly_export_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Antes: $exp_check = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}lt_commissions", ARRAY_A );
		// Esa asignacion directa NO debe existir.
		$this->assertStringNotContainsString(
			"\$exp_check  = \$wpdb->get_results( \"SHOW COLUMNS FROM {\$wpdb->prefix}lt_commissions\", ARRAY_A );",
			$source,
			'AD-067: el patron sin cache NO debe existir en export path.'
		);
	}

	public function test_auditor_view_show_columns_not_called_directly_panel_path(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Antes: $col_check = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}lt_commissions", ARRAY_A );
		$this->assertStringNotContainsString(
			"\$col_check = \$wpdb->get_results( \"SHOW COLUMNS FROM {\$wpdb->prefix}lt_commissions\", ARRAY_A );\n",
			$source,
			'AD-067: el patron sin cache NO debe existir en panel path.'
		);
	}

	public function test_auditor_view_has_ciclo20_tag_ad067(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-067 FIX',
			$source,
			'AD-067: tag CICLO20-P1-AD-067 FIX debe estar en source.'
		);
	}

	// ---- AD-068/AD-077 P1: esc_html__ en printf de Umbral --------------

	public function test_auditor_view_printf_uses_esc_html__not_bare_underscore(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Buscar que la cadena "Umbral: $%s COP" este en un esc_html__(...).
		// Pattern: printf( esc_html__( 'Umbral: $%s COP ... ) ).
		$this->assertStringContainsString(
			"printf(\n            esc_html__( 'Umbral: \$%s COP (%s UVT) / Res. 314/2021 UIAF', 'ltms' )",
			$source,
			'AD-068/AD-077: printf debe usar esc_html__ para el template string.'
		);
	}

	public function test_auditor_view_printf_no_longer_uses_bare_underscore_double_dash(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Antes: printf( __( 'Umbral: $%s COP (%s UVT) · Res. 314/2021 UIAF', 'ltms' ), ... )
		// Con middot. Esa forma NO debe existir.
		$this->assertStringNotContainsString(
			"printf( __( 'Umbral: \$%s COP (%s UVT) \xc2\xb7 Res. 314/2021 UIAF', 'ltms' )",
			$source,
			'AD-077: el printf con __ bare + middot no debe existir.'
		);
	}

	public function test_auditor_view_umbral_string_no_middot(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		// Reemplazamos ' · ' por ' / '. Verifico que el source nuevo no usa middot en ese string.
		$segment = substr( $source, strpos( $source, 'Umbral:' ) ?: 0, 200 );
		$this->assertStringNotContainsString(
			"\xc2\xb7",
			$segment,
			'AD-077: el segmento "Umbral: ..." NO debe contener middot (U+00B7).'
		);
	}

	public function test_auditor_view_has_ciclo20_tag_ad068_ad077(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			'CICLO20-P1-AD-068/AD-077 FIX',
			$source,
			'AD-068/AD-077: tag CICLO20-P1-AD-068/AD-077 FIX debe estar.'
		);
	}

	// ---- Cross-checks: anti-regresion ----------------------------------

	public function test_auditor_view_country_allowlist_still_present(): void {
		// Regresion: CICLO14-P1-AD-033 FIX (country allowlist) no debe romperse.
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"in_array( \$country, [ 'MX', 'CO' ], true )",
			$source,
			'CICLO14-P1-AD-033 (regresion): allowlist de country sigue presente.'
		);
	}

	public function test_auditor_view_level_allowlist_still_present(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"in_array( \$raw_level, [ 'critical', 'high', 'medium', 'low', 'info' ], true )",
			$source,
			'CICLO14-P1-AD-033 (regresion): allowlist de level sigue presente.'
		);
	}

	public function test_auditor_view_datetime_validation_still_present(): void {
		// CICLO14-P1-AD-034 FIX: DateTime::createFromFormat('Y-m-d', $raw_from) ...
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"DateTime::createFromFormat( 'Y-m-d', \$raw_from )",
			$source,
			'CICLO14-P1-AD-034 (regresion): DateTime::createFromFormat sigue presente.'
		);
	}

	public function test_auditor_view_csv_formula_injection_protection_still_present(): void {
		// AUDIT-PANEL-CSV-001: $csv_field prepends "'" a celdas de formula injection.
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"AUDIT-PANEL-CSV-001",
			$source,
			'AUDIT-PANEL-CSV-001 (regresion): proteccion CSV formula injection sigue presente.'
		);
	}

	public function test_auditor_view_ieps_retenido_optional_column_logic_still_present(): void {
		// CICLO14-P1-AD-035 FIX: alineado con CSV export.
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			'CICLO14-P1-AD-035 FIX',
			$source,
			'CICLO14-P1-AD-035 (regresion): alineacion IEPS retenido CSV/panel sigue presente.'
		);
	}

	public function test_auditor_view_capability_check_still_present(): void {
		$this->assertFileExists( self::AUDITOR_PATH );
		$source = file_get_contents( self::AUDITOR_PATH );

		$this->assertStringContainsString(
			"current_user_can( 'ltms_access_auditor_dashboard' )",
			$source,
			'Regresion: capability check del auditor sigue presente.'
		);
	}

	public function test_kyc_view_kses_e_esc_html_e_still_present(): void {
		// No romper el escape de outputs que ya estaba correcto.
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString( 'esc_html(', $source, 'Regresion: esc_html sigue usado en outputs.' );
		$this->assertStringContainsString( 'esc_attr(', $source, 'Regresion: esc_attr sigue usado en attributes.' );
		$this->assertStringContainsString( 'esc_url(', $source, 'Regresion: esc_url sigue usado en URLs.' );
	}

	public function test_kyc_view_doc_uses_proxy_ajax_url(): void {
		// AD-061 no rompe el contrato del view: sigue generando URLs via proxy AJAX.
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			"action=ltms_kyc_proxy_doc",
			$source,
			'AD-061 (no regresion): el view sigue usando el proxy admin-ajax para docs B2.'
		);
	}

	public function test_kyc_view_make_signed_url_closure_still_present(): void {
		$this->assertFileExists( self::KYC_PATH );
		$source = file_get_contents( self::KYC_PATH );

		$this->assertStringContainsString(
			"\$make_signed_url = static function( string \$key )",
			$source,
			'AD-061 (no regresion): el closure $make_signed_url sigue definido.'
		);
	}
}
