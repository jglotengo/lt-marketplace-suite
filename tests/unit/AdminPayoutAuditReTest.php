<?php
/**
 * AdminPayoutAuditReTest — Re-audit cycle ADMIN-PAYOUT-AUDIT-RE (v2.9.188).
 *
 * Extension del ciclo ADMIN-KYC-APPROVE-AUDIT hacia el modulo de retiros
 * (payouts) del panel admin. Cubre 2 hallazgos P1 del mismo patron que el
 * bug "desconectado" arreglado en KYC approve:
 *
 *   FN-01 (P1, mudo-exito): ajax_approve_payout / ajax_reject_payout usaban
 *     `wp_send_json( $result )` que empaquetaba el array {success:false,...}
 *     del scheduler dentro del canal success. El JS .done() interpretaba
 *     success=false y mostraba "Error al aprobar." generico, perdiendo el
 *     motivo real (KYC revocado, limites operativos, ya-procesada). Refactor:
 *     normalize_payout_result() enruta al channel correcto
 *     (wp_send_json_success si success=true, wp_send_json_error {message,
 *     block_code} 422 si success=false) — paridad con el fix ADMIN-KYC-APPROVE-
 *     AUDIT del KYC approve handler.
 *
 *   FN-02 (P1, mudo-fail): los 3 .fail() de html-admin-payouts.php decian
 *     "Error de conexion." sin distinguir compliance-block (422 + JSON) /
 *     sesion expirada (401/403 HTML) / server 500 / red real — mismo bug
 *     que ADMIN-KYC-APPROVE-AUDIT ya arreglo en html-admin-kyc.php. Ahora
 *     usan window.ltmsHttpFailReason(jqXHR) que se define inline en la vista
 *     payouts (con guard contra doble definicion si ambas vistas cargan).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * Class AdminPayoutAuditReApprovePathTest
 *
 * Tests unitarios del handler AJAX approve/reject payout — refactor
 * normalize_payout_result().
 *
 * Ejecutar con: ./vendor/bin/phpunit --group admin-payout-audit-re
 *
 * @group admin-payout-audit-re
 * @group admin-kyc-approve-audit
 * @group kyc
 * @covers LTMS_Admin_Payouts::normalize_payout_result
 */
class AdminPayoutAuditReApprovePathTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	/**
	 * Stub del scheduler via alias Patchwork no es practico en UNIT_ONLY.
	 * En su lugar, invocamos el metodo privado normalize_payout_result()
	 * directamente via Reflection con un array sintetico que imita la
	 * respuesta del scheduler cuando el KYC esta revocado.
	 *
	 * Regresión FN-01: antes el handler usaba `wp_send_json( $result )` que
	 * empaquetaba este array {success:false,...} dentro del canal success,
	 * así el admin veía "✓ Aprobado" sobre un retiro bloqueado.
	 */
	public function test_normalize_payout_result_routes_scheduler_failure_to_json_error(): void {
		// NOTA: get_current_user_id() ya esta definido en tests/bootstrap.php
		// como stub global, no podemos mockearlo via Brain\Monkey (DefinedTooEarly).

		$captured_payload = null;
		$captured_status  = null;
		$success_called   = false;

		Functions\when( 'wp_send_json_error' )->alias(
			static function( mixed $data = null, int $status = null ) use ( &$captured_payload, &$captured_status ): void {
				$captured_payload = $data;
				$captured_status  = $status;
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function() use ( &$success_called ): void {
				$success_called = true;
				throw new \RuntimeException( 'json_success' );
			}
		);

		if ( ! class_exists( 'LTMS_Core_Logger', false ) ) {
			eval( 'final class LTMS_Core_Logger {
				public static function info( string $c, string $m, array $ctx = [] ): void {}
				public static function warning( string $c, string $m, array $ctx = [] ): void {}
				public static function error( string $c, string $m, array $ctx = [] ): void {}
				public static function security( string $c, string $m, array $ctx = [] ): void {}
			}' );
		}
		if ( ! trait_exists( 'LTMS_Logger_Aware', false ) ) {
			eval( 'trait LTMS_Logger_Aware {}' );
		}

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		$rc = new ReflectionClass( $payouts );
		$m  = $rc->getMethod( 'normalize_payout_result' );
		$m->setAccessible( true );

		try {
			$m->invoke( $payouts, [
				'success' => false,
				'message' => 'No se puede aprobar el retiro: KYC ya no esta aprobado.',
			], 'PAYOUT_APPROVE' );
		} catch ( \RuntimeException $e ) {
			// esperado: wp_send_json_error lanza para cortar el control flow
		}

		$this->assertFalse( $success_called, 'No debe invocarse wp_send_json_success cuando el scheduler falla' );
		$this->assertNotNull( $captured_payload, 'wp_send_json_error debe ser invocado' );
		$this->assertSame( 422, $captured_status, 'Status HTTP debe ser 422 (Unprocessable Entity) distinto de "server down"' );

		$this->assertIsArray( $captured_payload );
		$this->assertArrayHasKey( 'message', $captured_payload );
		$this->assertStringContainsStringIgnoringCase(
			'KYC',
			$captured_payload['message'],
			'El mensaje real del scheduler debe llegar al admin, no un generico "Error al aprobar."'
		);
		$this->assertArrayHasKey( 'block_code', $captured_payload );
	}

	/**
	 * Happy path: scheduler devuelve success=true -> el handler debe llamar
	 * wp_send_json_success (no wp_send_json_error).
	 *
	 * Verifica que el refactor no rompio el happy path: el antiguo
	 * `wp_send_json( $result )` tambien servia success=true por el canal
	 * success, asi que el JS .done() ya lo trataba bien. El refactor debe
	 * preservar ese comportamiento (solo corrige el path success=false).
	 */
	public function test_normalize_payout_result_routes_scheduler_success_to_json_success(): void {
		// NOTA: get_current_user_id() ya esta definido en tests/bootstrap.php.

		$success_payload = null;
		$error_called    = false;

		Functions\when( 'wp_send_json_success' )->alias(
			static function( mixed $payload = null ) use ( &$success_payload ): void {
				$success_payload = $payload;
				throw new \RuntimeException( 'json_success' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function() use ( &$error_called ): void {
				$error_called = true;
				throw new \RuntimeException( 'json_error' );
			}
		);

		if ( ! class_exists( 'LTMS_Core_Logger', false ) ) {
			eval( 'final class LTMS_Core_Logger {
				public static function info( string $c, string $m, array $ctx = [] ): void {}
				public static function warning( string $c, string $m, array $ctx = [] ): void {}
				public static function error( string $c, string $m, array $ctx = [] ): void {}
				public static function security( string $c, string $m, array $ctx = [] ): void {}
			}' );
		}
		if ( ! trait_exists( 'LTMS_Logger_Aware', false ) ) {
			eval( 'trait LTMS_Logger_Aware {}' );
		}

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		$rc = new ReflectionClass( $payouts );
		$m  = $rc->getMethod( 'normalize_payout_result' );
		$m->setAccessible( true );

		try {
			$m->invoke( $payouts, [
				'success' => true,
				'message' => 'Retiro aprobado correctamente.',
				'payout_id' => 42,
			], 'PAYOUT_APPROVE' );
		} catch ( \RuntimeException $e ) {
			// esperado
		}

		$this->assertFalse( $error_called, 'wp_send_json_error no debe invocarse en el happy path' );
		$this->assertNotNull( $success_payload, 'wp_send_json_success debe invocarse' );
		$this->assertIsArray( $success_payload );
		$this->assertArrayNotHasKey( 'success', $success_payload, 'El flag success no debe duplicarse en el payload (lo provee el channel)' );
		$this->assertSame( 42, $success_payload['payout_id'] ?? null, 'El payload extra del scheduler debe preservarse' );
	}

	/**
	 * Estructural: ajax_approve_payout NO debe seguir llamando a wp_send_json()
	 * (el viejo atajo que mezclaba ambos canales). Debe delegar en
	 * normalize_payout_result().
	 */
	public function test_handler_approve_payout_delegates_to_normalize_payout_result(): void {
		$this->require_class( 'LTMS_Admin_Payouts' );
		$rc   = new ReflectionClass( 'LTMS_Admin_Payouts' );
		$body = $this->get_method_body( $rc, 'ajax_approve_payout' );

		$this->assertStringContainsString( 'normalize_payout_result', $body, 'Handler debe delegar en normalize_payout_result()' );
		$this->assertStringNotContainsString( 'wp_send_json( $result', $body, 'Handler no debe usar wp_send_json( $result ) que mezclaba los canales' );
	}

	/**
	 * Estructural: ajax_reject_payout tambien delega.
	 */
	public function test_handler_reject_payout_delegates_to_normalize_payout_result(): void {
		$this->require_class( 'LTMS_Admin_Payouts' );
		$rc   = new ReflectionClass( 'LTMS_Admin_Payouts' );
		$body = $this->get_method_body( $rc, 'ajax_reject_payout' );

		$this->assertStringContainsString( 'normalize_payout_result', $body );
		$this->assertStringNotContainsString( 'wp_send_json( $result', $body );
	}

	/**
	 * Estructural: existe el metodo normalize_payout_result() en la clase.
	 */
	public function test_normalize_payout_result_method_exists(): void {
		$this->require_class( 'LTMS_Admin_Payouts' );
		$this->assertTrue( method_exists( 'LTMS_Admin_Payouts', 'normalize_payout_result' ) );
	}

	// ── Helper ──────────────────────────────────────────────────────────────

	/**
	 * Extrae el cuerpo (source) de un metodo via Reflection.
	 */
	private function get_method_body( ReflectionClass $rc, string $method_name ): string {
		$method     = $rc->getMethod( $method_name );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		return implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );
	}
}

/**
 * Class AdminPayoutAuditReJsFailCallbacksTest
 *
 * Tests FN-02: el JS de html-admin-payouts.php define y usa
 * ltmsHttpFailReason() en los 3 .fail() callbacks, igual que el JS del KYC.
 *
 * @group admin-payout-audit-re
 * @group admin-kyc-approve-audit
 * @group kyc
 */
class AdminPayoutAuditReJsFailCallbacksTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	/**
	 * El JS de la vista payouts debe definir window.ltmsHttpFailReason (con
	 * guard de doble definicion) o al menos invocarla desde los .fail().
	 */
	public function test_payouts_view_js_defines_and_uses_ltmsHttpFailReason(): void {
		$view = dirname( __DIR__, 2 ) . '/includes/admin/views/html-admin-payouts.php';
		$this->assertFileExists( $view );
		$src = file_get_contents( $view );

		$this->assertStringContainsString( 'window.ltmsHttpFailReason', $src, 'JS debe definir window.ltmsHttpFailReason' );
		$this->assertStringContainsString( "typeof window.ltmsHttpFailReason !== 'function'", $src, 'Debe haber guard de doble definicion (ambas vistas comparten scope global)' );

		$this->assertStringContainsString( 'Tu sesi', $src, 'JS debe distinguir sesion expirada (401/403 HTML) de error de red' );
		$this->assertStringContainsString( 'jqXHR.status === 0', $src, 'JS debe distinguir status 0 (red) de otros HTTP errors' );

		// 422 path: compliance-block en la propia respuesta JSON.
		$this->assertStringContainsString( 'jqXHR.status === 422', $src, 'JS debe distinguir 422 (compliance block) de 500 (server)' );

		// Los 3 callbacks .fail() deben invocar window.ltmsHttpFailReason.
		$count = substr_count( $src, 'window.ltmsHttpFailReason(' );
		$this->assertGreaterThanOrEqual(
			3,
			$count,
			'Los 3 callbacks .fail() de payouts (approve/reject/export) deben invocar window.ltmsHttpFailReason'
		);
	}

	/**
	 * El JS NO debe seguir usando strings inline "Error de conexion." en los
	 * .fail() callbacks (estaba en los 3 antes del fix).
	 *
	 * Verificacion "ausence-of-pattern" per LECCIONES_APRENDIDAS #133: hay
	 * que distinguir los matches verdaderos (strings literales en el callback)
	 * vs. comentarios que documentan el bug viejo. Buscamos los patrones
	 * exactos que estaban en los callbacks:
	 *   - ltmsNotify('error', 'Error de conexion.')
	 *   - '#ltms-reject-error').text('Error de conexion.').show()
	 *   - ltmsNotify('error', 'Error de conexion al exportar.')
	 */
	public function test_payouts_view_js_does_not_use_inline_error_strings_in_fail(): void {
		$view = dirname( __DIR__, 2 ) . '/includes/admin/views/html-admin-payouts.php';
		$src  = file_get_contents( $view );

		// Quitar comentarios para no generar falsos positivos (LECCIONES #133).
		$stripped = preg_replace( '/\/\/[^\n]*/', '', $src );
		$stripped = preg_replace( '/\/\*.*?\*\//s', '', $stripped );

		$this->assertStringNotContainsString(
			"ltmsNotify('error', 'Error de conexión.')",
			$stripped,
			'El callback .fail() del approve no debe usar inline "Error de conexión." — debe usar ltmsHttpFailReason()'
		);
		$this->assertStringNotContainsString(
			"'#ltms-reject-error').text('Error de conexión.').show()",
			$stripped,
			'El callback .fail() del reject no debe usar inline "Error de conexión."'
		);
		$this->assertStringNotContainsString(
			"ltmsNotify('error', 'Error de conexión al exportar.')",
			$stripped,
			'El callback .fail() del export no debe usar inline "Error de conexión al exportar."'
		);
	}
}
