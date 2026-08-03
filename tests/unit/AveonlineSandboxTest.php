<?php
/**
 * AveonlineSandboxTest — Tests estructurales para AUDIT-BIZ-AVE-001 AVE-017
 *
 * LTMS_Business_Aveonline_Sandbox expone 2 handlers AJAX admin:
 *   - ajax_obtener_estados   (obtenerEstadoAuth)
 *   - ajax_avanzar_estado    (avanzarEstado)
 *
 * Sólo funciona con las empresas de prueba 6077 y 25505 (constante ALLOWED_IDS).
 * `ajax_avanzar_estado` valida el ID contra ALLOWED_IDS en línea 93; antes del
 * fix, `ajax_obtener_estados` NO validaba el ID contra la lista blanca, lo que
 * permitía a un admin enviar id=1, id=2,... y enumerar otros IDs de sandbox
 * accessible vía obtenerEstadoAuth (information disclosure lateral en sandbox
 * de otras empresas). Aunque sandbox no procesa producción, la validación
 * previene IDOR lateral entre empresas sandbox y mantiene consistencia entre
 * los 2 handlers del módulo.
 *
 * Tests estructurales sobre el source (mismo patrón que AuthoritiesRaeeCsvInjectionTest
 * del ciclo AUDIT-PANEL-CSV-001/002) — la clase tiene dependencias WP/WC
 * acopladas que no cargan en `LTMS_UNIT_ONLY=true`; los asserts estructurales
 * validan el código real sin instanciarlo.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Business_Aveonline_Sandbox::ajax_obtener_estados
 * @covers LTMS_Business_Aveonline_Sandbox::ajax_avanzar_estado
 */
class AveonlineSandboxTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	private string $file_path;
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-business-aveonline-sandbox.php';
		$this->src       = file_get_contents( $this->file_path );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 1 — Estructura básica de la clase y los 2 handlers
	// -----------------------------------------------------------------------

	public function test_file_exists(): void {
		$this->assertFileExists( $this->file_path );
	}

	public function test_class_definition_present(): void {
		$this->assertStringContainsString( 'class LTMS_Business_Aveonline_Sandbox', $this->src );
	}

	public function test_allowed_ids_constant_defined(): void {
		$this->assertStringContainsString( "const ALLOWED_IDS = [ 6077, 25505 ];", $this->src, 'La lista blanca de IDs sandbox debe estar definida.' );
	}

	public function test_ajax_obtener_estados_method_exists(): void {
		$this->assertStringContainsString( 'public static function ajax_obtener_estados(): void {', $this->src );
	}

	public function test_ajax_avanzar_estado_method_exists(): void {
		$this->assertStringContainsString( 'public static function ajax_avanzar_estado(): void {', $this->src );
	}

	public function test_both_actions_registered(): void {
		$this->assertStringContainsString( "wp_ajax_ltms_aveonline_sandbox_estados", $this->src );
		$this->assertStringContainsString( "wp_ajax_ltms_aveonline_sandbox_avanzar", $this->src );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 2 — Validación de nonce y admin capability (debe mantenerse)
	// -----------------------------------------------------------------------

	public function test_admin_nonce_check_in_both_handlers(): void {
		$this->assertSame( 2, substr_count( $this->src, "check_ajax_referer( 'ltms_admin_nonce', 'nonce' );" ), 'Ambos handlers deben verificar el nonce admin.' );
	}

	public function test_manage_options_check_in_both_handlers(): void {
		$this->assertSame( 2, substr_count( $this->src, "current_user_can( 'manage_options' )" ), 'Ambos handlers deben exigir manage_options (solo admin).' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 3 — FIX AVE-017: validación ALLOWED_IDS en ajax_obtener_estados
	// -----------------------------------------------------------------------

	public function test_fix_ave_017_marker_comment_present(): void {
		$this->assertStringContainsString( 'AVE-017 FIX (AUDIT-BIZ-AVE-001, P2)', $this->src, 'El comentario-marcatório del fix AVE-017 debe estar presente para trazabilidad.' );
	}

	public function test_fix_ave_017_in_array_check_added_after_token_id_validation(): void {
		// El check del fix viene después de `if ( empty( $token ) || ! $id )`
		$this->assertStringContainsString( "if ( ! in_array( \$id, self::ALLOWED_IDS, true ) ) {", $this->src, 'Fix AVE-017: ajax_obtener_estados debe validar id contra ALLOWED_IDS.' );
	}

	public function test_fix_ave_017_in_array_check_count_is_two(): void {
		// Antes del fix: 1 (solo en ajax_avanzar_estado).
		// Después del fix: 2 (uno en cada handler).
		$this->assertSame( 2, substr_count( $this->src, 'in_array( $id, self::ALLOWED_IDS, true )' ), 'Fix AVE-017: el check ALLOWED_IDS debe estar presente en los 2 handlers.' );
	}

	public function test_fix_ave_017_error_message_matches_avanzar_estado_format(): void {
		// Mensaje consistente con el de ajax_avanzar_estado (línea 94-98 pre-fix).
		$this->assertStringContainsString( "'ID de empresa no autorizado. Sólo se permiten: %s', 'ltms'", $this->src, 'Fix AVE-017: el mensaje de error debe ser consistente con ajax_avanzar_estado.' );
	}

	public function test_fix_ave_017_uses_implode_for_allowed_ids(): void {
		$this->assertStringContainsString( "implode( ', ', self::ALLOWED_IDS )", $this->src, 'Fix AVE-017: usa implode para mostrar IDs permitidos en el mensaje.' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 4 — Prevención de regresión
	// -----------------------------------------------------------------------

	public function test_fix_ave_017_obtener_estados_block_precedes_request_call(): void {
		// El check ALLOWED_IDS debe estar antes de self::request() para no hacer
		// la llamada HTTP si el ID no está permitido.
		$pos_check = strpos( $this->src, 'if ( ! in_array( $id, self::ALLOWED_IDS, true ) )' );
		$obtener_block_end = strpos( $this->src, 'public static function ajax_avanzar_estado' );
		$this->assertNotFalse( $pos_check );
		$this->assertNotFalse( $obtener_block_end );
		$this->assertLessThan( $obtener_block_end, $pos_check, 'Fix AVE-017: el check ALLOWED_IDS en ajax_obtener_estados debe estar dentro del método obtener_estados (antes del método avanzar_estado).' );
	}

	public function test_avanzar_estado_allowed_ids_check_preserved(): void {
		// Confirmar que el fix pre-existente de ajax_avanzar_estado siga intacto.
		$avant_pos = strpos( $this->src, 'public static function ajax_avanzar_estado' );
		$avant_check = strpos( $this->src, 'if ( ! in_array( $id, self::ALLOWED_IDS, true ) )', $avant_pos ?: 0 );
		$this->assertNotFalse( $avant_check, 'El check ALLOWED_IDS pre-existente en ajax_avanzar_estado debe seguir presente (no regression).' );
	}

	// -----------------------------------------------------------------------
	// SECCIÓN 5 — Estructura general de seguridad
	// -----------------------------------------------------------------------

	public function test_request_method_is_private(): void {
		$this->assertStringContainsString( 'private static function request( array $payload )', $this->src, 'El helper HTTP request() debe ser private (no accesible como AJAX o API pública).' );
	}

	public function test_sandbox_url_is_https(): void {
		$this->assertStringContainsString( "'https://aveonline.co/api/nal/v1.0/sandbox/guia.php'", $this->src, 'La URL del sandbox debe ser HTTPS.' );
	}

	public function test_no_direct_die_or_echo_in_handlers(): void {
		$this->assertStringNotContainsString( ' die(', $this->src, 'Los handlers no deben usar die() directo (deben usar wp_send_json_*).' );
		$this->assertStringNotContainsString( 'echo ', $this->src, 'Los handlers no deben hacer echo directo (deben usar wp_send_json_*).' );
	}
}
