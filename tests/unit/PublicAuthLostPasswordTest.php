<?php
/**
 * PublicAuthLostPasswordTest — manejo del retorno de retrieve_password() en el
 * AJAX de recuperación de contraseña (LOSTPW-MAIL-ERR FIX, 2026-09-28).
 *
 * Hallazgo cubierto:
 *   - P1: ajax_vendor_lost_password() NO verificaba el retorno de
 *     retrieve_password() — si wp_mail() falla (SMTP caido, mail() deshabilitado,
 *     From rechazado), WP 7.x devuelve WP_Error('retrieve_password_email_failure')
 *     y el vendor veía el mensaje genérico "revisa tu email" sin que NADIE (ni
 *     logs) registrara el fallo (causa raíz del reporte "no le llegó nada al
 *     correo electrónico").
 *
 * Comportamiento verificado (QA producción, WP 7.1.2):
 *   - retrieve_password() con la cuenta real del vendor → bool(true) (pipeline OK).
 *   - El fallo de envío retorna WP_Error con code 'retrieve_password_email_failure'
 *     (WP 7.x) / 'mail_failed' (6.x) — ahora se captura: error 500 claro al vendor
 *     + log LOSTPW_MAIL_FAILED.
 *   - Cuenta no encontrada (invalid_email/invalidcombo) → respuesta genérica
 *     (anti-enumeración) + log LOSTPW_NOT_SENT.
 *
 * El test importa y ejerce la clase REAL (LTMS_Public_Auth_Handler), no
 * reimplementa la lógica.
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group auth-lostpw
 *
 * @group auth-lostpw
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Class PublicAuthLostPasswordTest
 *
 * @group auth-lostpw
 */
final class PublicAuthLostPasswordTest extends LTMS_Unit_Test_Case {

	private function load_handler_class(): bool {
		if ( class_exists( 'LTMS_Public_Auth_Handler' ) ) {
			return true;
		}
		$file = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-public-auth-handler.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			return class_exists( 'LTMS_Public_Auth_Handler' );
		}
		return false;
	}

	/**
	 * Stubs base del flujo lost-password: nonce válido, rate limit vacío, IP.
	 */
	private function stub_lost_password_flow( callable $retrieve_password_result ): void {
		Functions\when( 'check_ajax_referer' )->alias( static fn( $action, $arg = false, $die = true ) => true );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'retrieve_password' )->alias( $retrieve_password_result );
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		$_POST['email']         = 'vendor@example.com';
	}

	/**
	 * Captura wp_send_json_error (AdminPayoutsTest pattern: alias que lanza para
	 * cortar la ejecución del handler).
	 *
	 * @return array{data: mixed, status: int}
	 */
	private function capture_json_error( callable $callable ): array {
		$captured = [ 'data' => null, 'status' => 0 ];
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( mixed $data = null, int $status = 0 ) use ( &$captured ): void {
				$captured = [ 'data' => $data, 'status' => $status ];
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			$callable();
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() === 'json_error' ) {
				return $captured;
			}
			throw $e;
		}

		return $captured;
	}

	/**
	 * Captura wp_send_json_success.
	 */
	private function capture_json_success( callable $callable ): mixed {
		$captured = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( mixed $data = null ) use ( &$captured ): void {
				$captured = $data;
				throw new \RuntimeException( 'json_success' );
			}
		);

		try {
			$callable();
		} catch ( \RuntimeException $e ) {
			if ( $e->getMessage() === 'json_success' ) {
				return $captured;
			}
			throw $e;
		}

		return null;
	}

	// ─── SECCIÓN 1: fallo de envío (LOSTPW_MAIL_FAILED) ──────────────────────

	public function test_mail_failed_returns_clear_error_500(): void {
		$this->assertTrue( $this->load_handler_class() );
		$this->stub_lost_password_flow(
			static fn( $identifier ) => new \WP_Error( 'retrieve_password_email_failure', 'The email could not be sent.' )
		);
		$handler = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_error( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertSame( 500, $captured['status'], 'Fallo de envío debe responder 500 (no success silencioso).' );
		$this->assertIsArray( $captured['data'] );
		$this->assertArrayHasKey( 'message', $captured['data'] );
		$this->assertStringContainsString( 'No pudimos enviar el correo', $captured['data']['message'] );
	}

	public function test_mail_failed_legacy_code_6x_also_returns_error(): void {
		$this->assertTrue( $this->load_handler_class() );
		// Paridad WP 6.x: code 'mail_failed'.
		$this->stub_lost_password_flow(
			static fn( $identifier ) => new \WP_Error( 'mail_failed', 'The email could not be sent.' )
		);
		$handler = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_error( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertSame( 500, $captured['status'] );
		$this->assertStringContainsString( 'No pudimos enviar el correo', (string) ( $captured['data']['message'] ?? '' ) );
	}

	// ─── SECCIÓN 2: éxito y anti-enumeración ─────────────────────────────────

	public function test_success_returns_generic_message(): void {
		$this->assertTrue( $this->load_handler_class() );
		// Pipeline OK (QA producción: retrieve_password con cuenta real → true).
		$this->stub_lost_password_flow( static fn( $identifier ) => true );
		$handler = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_success( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertIsArray( $captured );
		$this->assertStringContainsString( 'Si la cuenta existe', $captured['message'] );
	}

	public function test_invalid_email_keeps_generic_response_anti_enumeration(): void {
		$this->assertTrue( $this->load_handler_class() );
		// Cuenta no encontrada → MISMA respuesta genérica (no revela existencia)
		// — solo log de diagnóstico (LOSTPW_NOT_SENT).
		$this->stub_lost_password_flow(
			static fn( $identifier ) => new \WP_Error( 'invalid_email', 'There is no account with that username or email address.' )
		);
		$handler = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_success( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertIsArray( $captured );
		$this->assertStringContainsString( 'Si la cuenta existe', $captured['message'] );
	}

	// ─── SECCIÓN 3: guards previos intactos ──────────────────────────────────

	public function test_empty_identifier_still_rejected(): void {
		$this->assertTrue( $this->load_handler_class() );
		$this->stub_lost_password_flow( static fn( $identifier ) => true );
		$_POST['email'] = '';
		$handler        = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_error( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertSame( 0, $captured['status'] );
		$this->assertStringContainsString( 'Ingresa tu email', (string) ( $captured['data']['message'] ?? '' ) );
	}

	public function test_rate_limit_still_enforced(): void {
		$this->assertTrue( $this->load_handler_class() );
		Functions\when( 'check_ajax_referer' )->alias( static fn( $action, $arg = false, $die = true ) => true );
		Functions\when( 'get_transient' )->justReturn( 3 );
		Functions\when( 'retrieve_password' )->alias( static fn( $identifier ) => true );
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		$_POST['email']         = 'vendor@example.com';
		$handler                = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_error( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertSame( 429, $captured['status'], 'Rate limit 3/15min por IP debe seguir activo.' );
	}

	public function test_invalid_nonce_still_rejected(): void {
		$this->assertTrue( $this->load_handler_class() );
		Functions\when( 'check_ajax_referer' )->alias( static fn( $action, $arg = false, $die = true ) => false );
		Functions\when( 'retrieve_password' )->alias( static fn( $identifier ) => true );
		$_POST['email'] = 'vendor@example.com';
		$handler        = new \LTMS_Public_Auth_Handler();

		$captured = $this->capture_json_error( static fn() => $handler->ajax_vendor_lost_password() );

		$this->assertSame( 403, $captured['status'], 'Nonce inválido debe seguir rechazándose con 403.' );
	}

	// ─── SECCIÓN 4: paridad a nivel de fuente ────────────────────────────────

	public function test_source_checks_retrieve_password_return(): void {
		$path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-public-auth-handler.php';
		$this->assertFileExists( $path );
		$source = (string) file_get_contents( $path );

		// El retorno se captura y se maneja (LOSTPW-MAIL-ERR FIX).
		$this->assertStringContainsString( '$result = retrieve_password( $identifier );', $source, 'El retorno de retrieve_password() debe capturarse.' );
		$this->assertStringContainsString( 'retrieve_password_email_failure', $source, 'El code de fallo de envío de WP 7.x debe manejarse.' );
		$this->assertStringContainsString( 'LOSTPW_MAIL_FAILED', $source, 'El fallo de envío debe loggearse (diagnóstico ops).' );
	}
}
