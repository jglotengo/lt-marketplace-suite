<?php
/**
 * AveonlineShipmentRelationsTest — Tests estructurales para AUDIT-BIZ-AVE-001 AVE-001
 *
 * LTMS_Business_Aveonline_ShipmentRelations::ajax_search_recipients() expone el
 * autocomplete de destinatarios de la API Aveonline. Antes del fix, el handler
 * solo verificaba `is_user_logged_in()`, lo que permitía a cualquier cliente
 * (incluido el rol `subscriber`) con sesión acceder al endpoint y buscar
 * destinatarios — expone PII (nombre, telefono, direccion) de destinatarios
 * previos. El patrón esperado, replicado de los demás handlers vendor_*
 * (ajax_vendor_create/list/delete), exige `LTMS_Utils::is_ltms_vendor()` ||
 * `current_user_can('manage_options')`.
 *
 * Tests estructurales sobre el source (mismo patrón que AuthoritiesRaeeCsvInjectionTest,
 * AuditorExportCsvInjectionTest, etc. del ciclo AUDIT-PANEL-CSV-001/002) — la clase
 * tiene dependencias WP/WC acopladas que no cargan en `LTMS_UNIT_ONLY=true`; los
 * asserts estructurales validan el código real sin instanciarlo.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Business_Aveonline_ShipmentRelations::ajax_search_recipients
 */
class AveonlineShipmentRelationsTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private string $file_path;
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-business-aveonline-shipment-relations.php';
		$this->src       = file_get_contents( $this->file_path );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Estructura básica del handler
	// -----------------------------------------------------------------------

	public function test_file_exists(): void {
		$this->assertFileExists( $this->file_path );
	}

	public function test_class_definition_present(): void {
		$this->assertStringContainsString( 'class LTMS_Business_Aveonline_ShipmentRelations', $this->src );
	}

	public function test_ajax_search_recipients_method_exists(): void {
		$this->assertStringContainsString( 'public static function ajax_search_recipients(): void {', $this->src, 'El método ajax_search_recipients debe existir y ser público/estático.' );
	}

	public function test_ajax_search_recipients_action_registered(): void {
		$this->assertStringContainsString( "wp_ajax_ltms_aveonline_search_recipients',        [ __CLASS__, 'ajax_search_recipients']", $this->src, 'La action wp_ajax_ltms_aveonline_search_recipients debe estar registrada en init().' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — Validación de nonce (debe mantenerse)
	// -----------------------------------------------------------------------

	public function test_nonce_check_present(): void {
		$this->assertStringContainsString( "check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );", $this->src, 'El handler debe verificar nonce ltms_vendor_nonce.' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — FIX AVE-001: capability check robusto (núcleo del fix)
	// -----------------------------------------------------------------------

	public function test_fix_ave_001_marker_comment_present(): void {
		$this->assertStringContainsString( 'AVE-001 FIX (AUDIT-BIZ-AVE-001, P1)', $this->src, 'El comentario-marcatório del fix AVE-001 debe estar presente para trazabilidad.' );
	}

	public function test_fix_ave_001_is_ltms_vendor_check_present(): void {
		$this->assertStringContainsString( "is_vendor = class_exists( 'LTMS_Utils' ) && LTMS_Utils::is_ltms_vendor();", $this->src, 'Fix AVE-001: debe verificar LTMS_Utils::is_ltms_vendor() como los demás handlers vendor_*.' );
	}

	public function test_fix_ave_001_manage_options_fallback_present(): void {
		$this->assertStringContainsString( "! \$is_vendor && ! current_user_can( 'manage_options' )", $this->src, 'Fix AVE-001: admin puede acceder vía manage_options fallback (igual que check_vendor_nonce de guias.php).' );
	}

	public function test_fix_ave_001_returns_403_on_insufficient_role(): void {
		$this->assertStringContainsString( "wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );", $this->src, 'Fix AVE-001: denegación de rol debe devolver 403, no 200.' );
	}

	public function test_fix_ave_001_returns_401_on_not_logged_in(): void {
		$this->assertStringContainsString( "wp_send_json_error( [ 'message' => 'Sin permisos.' ], 401 );", $this->src, 'Fix AVE-001: usuario no autenticado recibe 401 (antes era 200 sin status code explícito).' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Paridad con handlers vendor_* del mismo archivo
	// -----------------------------------------------------------------------

	public function test_vendor_create_handler_uses_same_capability_pattern(): void {
		$this->assertStringContainsString( "! is_user_logged_in() || ! LTMS_Utils::is_ltms_vendor()", $this->src, 'El patrón de capability check debe ser consistente con ajax_vendor_create/list/delete.' );
	}

	public function test_vendor_handlers_count_check_ltms_vendor_at_least_4_times(): void {
		// 3 vendor handlers (create/list/delete) + 1 nuevo en search_recipients (fix AVE-001)
		// Cada uno usa `LTMS_Utils::is_ltms_vendor()` (3 con || + 1 con class_exists && ...)
		$count = substr_count( $this->src, 'is_ltms_vendor()' );
		$this->assertGreaterThanOrEqual( 4, $count, 'Después del fix AVE-001, is_ltms_vendor() debe aparecer al menos 4 veces (3 vendor handlers + search_recipients).' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — Datos de entrada del handler
	// -----------------------------------------------------------------------

	public function test_search_recipients_uses_get_param(): void {
		// El handler usa $_GET['param'] (no $_POST),独特 en el archivo.
		$this->assertStringContainsString( "\$_GET['param'] ?? ''", $this->src, 'El handler lee el parámetro de búsqueda desde $_GET.' );
	}

	public function test_search_recipients_minimum_length_check(): void {
		$this->assertStringContainsString( "strlen( \$param ) < 3", $this->src, 'El handler debe exigir mínimo 3 caracteres para destruir el autocomplete spammable.' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 6 — Prevención de regresión: el fix NO puede remove el check
	// -----------------------------------------------------------------------

	public function test_fix_ave_001_no_revert_to_is_user_logged_in_only(): void {
		// El patrón antiguo era: `if ( ! is_user_logged_in() ) { wp_send_json_error( ... ); }`
		// seguido inmediatamente por la lógica del handler. Después del fix, debe
		// haber un segundo check (${is_vendor}|{manage_options}).
		$pattern = '/if \( ! is_user_logged_in\(\) \) \{[^}]+wp_send_json_error[^}]+\}\s*\$\w+\s*=\s*class_exists.*is_ltms_vendor/s';
		$this->assertMatchesRegularExpression( $pattern, $this->src, 'Fix AVE-001: el segundo check de capability (is_ltms_vendor || manage_options) debe seguir al is_user_logged_in.' );
	}
}
