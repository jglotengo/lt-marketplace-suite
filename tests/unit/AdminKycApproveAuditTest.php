<?php
/**
 * AdminKycApproveAuditTest — tests del ciclo ADMIN-KYC-APPROVE-AUDIT (v2.9.188).
 *
 * Cubre los fixes del bug "desconectado" al aprobar KYC:
 *
 *   RAÍZ (A) PHP: los filtros `ltms_kyc_pre_approve` (FT-2 sanctions, AC-7 RUT,
 *     RT-2 sanitary, HD-12 minor) bloqueaban silenciosamente con `return false`,
 *     dejando al handler sin contexto del motivo. Ahora devuelven `WP_Error`
 *     con un mensaje específico que el handler reenvía al admin.
 *
 *   RAÍZ (B) JS: jQuery `.fail()` convertía CUALQUIER HTTP no-2xx (incluido
 *     el 403 con JSON `wp_send_json_error`) en "Error de conexión", dando
 *     falsa alarma de server caído. Ahora `ltmsHttpFailReason()` distingue
 *     bloqueo de compliance (mensaje del server), sesión expirada (401/403
 *     HTML), error server 500 y error de red real.
 *
 * Hallazgos P0/P1 cubiertos por este test (ver QA_REPORT.md):
 *   - P0-1: AC-7 devolvía `false` mudo al faltar Cámara de Comercio.
 *   - P0-2: FT-2 fail-closed devolvía `false` mudo al no descargar las listas
 *           OFAC/UN/EU.
 *   - P1-1: RT-2 devolvía `false` mudo si faltaba registro sanitario.
 *   - P1-2: HD-12 devolvía `false` mudo si menor sin autorización.
 *   - P1-3: JS `.fail()` siempre decía "Error de conexión" sin distinguir causa.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * Class AdminKycApproveAuditTest
 *
 * Tests unitarios para los fixes del ciclo ADMIN-KYC-APPROVE-AUDIT.
 * Ejecutar con: ./vendor/bin/phpunit --group admin-kyc-approve-audit
 *
 * @group admin-kyc-approve-audit
 * @group kyc
 * @covers LTMS_Admin_Payouts
 * @covers LTMS_Authorities_Compliance
 * @covers LTMS_Fintech_Compliance
 * @covers LTMS_Restaurant_Compliance
 * @covers LTMS_Data_Protection_Compliance
 */
class AdminKycApproveAuditTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

	/**
	 * E2E: vendor sin Cámara de Comercio → handler approve_kyc devuelve 403 con el
	 * mensaje específico "Falta el número de matrícula de Cámara de Comercio..."
	 * (no un genérico "Aprobación bloqueada por política de cumplimiento" que el
	 * admin interprete como "desconectado").
	 *
	 * Reproducción e2e del bug reportado: mockeamos apply_filters para simular
	 * que el filter AC-7 levantó el WP_Error real que ahora produce el PHP, y
	 * verificamos que el handler lo extrae y lo envía completo al admin.
	 */
	public function test_approve_kyc_returns_specific_message_when_cc_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$_POST = [ 'kyc_id' => '42' ];

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_row( mixed $q = null ): mixed {
				return [ 'id' => 42, 'status' => 'pending', 'bank_name' => '', 'bank_account_number' => '' ];
			}
			public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int { return 0; }
		};

		// Simular que AC-7 (validate_rut_and_camara_comercio) bloquea con WP_Error
		// específico — el comportamiento nuevo tras el fix.
		Functions\when( 'apply_filters' )->alias(
			static function( string $tag, $value ) {
				if ( $tag === 'ltms_kyc_pre_approve' ) {
					return new \WP_Error(
						'ac_cc_missing',
						'Falta el número de matrícula de Cámara de Comercio (Decreto 2150/1995). El vendedor #5 debe completar este campo en su panel y reenviar el KYC.'
					);
				}
				return $value;
			}
		);

		// get_vendor_id_by_kyc interno: necesita get_var una vez.
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_var( mixed $q = null ): mixed { return 5; }
			public function get_row( mixed $q = null ): mixed {
				return [ 'id' => 42, 'status' => 'pending', 'bank_name' => '', 'bank_account_number' => '' ];
			}
			public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int { return 0; }
		};

		// Capturar también el array {message, block_code} que el handler envía.
		$captured_payload = null;
		$captured_status = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function( mixed $data = null, int $status = null ) use ( &$captured_payload, &$captured_status ): void {
				$captured_payload = $data;
				$captured_status = $status;
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
		if ( ! class_exists( 'LTMS_Utils', false ) ) {
			eval( 'final class LTMS_Utils {
				public static function now_utc(): string { return date( "Y-m-d H:i:s" ); }
			}' );
		}

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		try {
			$payouts->ajax_approve_kyc();
		} catch ( \RuntimeException $e ) {
			// esperado
		}

		$this->assertNotNull( $captured_payload, 'wp_send_json_error debió ser invocado' );
		$this->assertSame( 403, $captured_status, 'Status HTTP debe ser 403' );

		// El payload ES un array (no un string) — para que el JS pueda
		// extraer `res.data.message`.
		$this->assertIsArray( $captured_payload, 'El payload debe ser array (no string) para que el JS extraiga el mensaje' );
		$this->assertArrayHasKey( 'message', $captured_payload );
		$this->assertArrayHasKey( 'block_code', $captured_payload );

		// El mensaje debe mencionar específicamente la causa — NO el genérico
		// "Aprobación bloqueada por política de cumplimiento" que el admin
		// interpreta como "desconectado".
		$msg = $captured_payload['message'];
		$this->assertStringContainsStringIgnoringCase( 'Cámara de Comercio', $msg, 'Mensaje debe mencionar la causa específica' );
		$this->assertStringNotContainsStringIgnoringCase(
			'Aprobación bloqueada por política de cumplimiento',
			$msg,
			'No debe ser el mensaje genérico viejo que el admin confundía con desconexión'
		);
		$this->assertSame( 'ac_cc_missing', $captured_payload['block_code'] );
	}

	/**
	 * E2E: vendor con coincidencia en lista restrictiva OFAC → handler devuelve 403
	 * con mensaje específico nombrando la lista.
	 */
	public function test_approve_kyc_returns_specific_message_on_sanctions_match(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$_POST = [ 'kyc_id' => '99' ];

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_var( mixed $q = null ): mixed { return 7; }
			public function get_row( mixed $q = null ): mixed {
				return [ 'id' => 99, 'status' => 'pending', 'bank_name' => '', 'bank_account_number' => '' ];
			}
			public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int { return 0; }
		};

		Functions\when( 'apply_filters' )->alias(
			static function( string $tag, $value ) {
				if ( $tag === 'ltms_kyc_pre_approve' ) {
					return new \WP_Error(
						'ft_sanctions_match',
						'Coincidencia en lista restrictiva ofac_sdn detectada para "Juan Pérez" — KYC bloqueado. Oficial de cumplimiento notificado. Revisar manualmente antes de cualquier aprobación.'
					);
				}
				return $value;
			}
		);

		$captured_payload = null;
		$captured_status  = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function( mixed $data = null, int $status = null ) use ( &$captured_payload, &$captured_status ): void {
				$captured_payload = $data;
				$captured_status  = $status;
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
		if ( ! class_exists( 'LTMS_Utils', false ) ) {
			eval( 'final class LTMS_Utils { public static function now_utc(): string { return date( "Y-m-d H:i:s" ); } }' );
		}

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		try {
			$payouts->ajax_approve_kyc();
		} catch ( \RuntimeException $e ) {
			// esperado
		}

		$this->assertNotNull( $captured_payload );
		$this->assertSame( 403, $captured_status );
		$this->assertIsArray( $captured_payload );
		$msg = $captured_payload['message'];
		$this->assertStringContainsStringIgnoringCase( 'ofac_sdn', $msg );
		$this->assertStringContainsStringIgnoringCase( 'Coincidencia', $msg );
		$this->assertSame( 'ft_sanctions_match', $captured_payload['block_code'] );
	}

	/**
	 * E2E: cuando ningún filter bloquea, apply_filters devuelve `true` y el handler
	 * continúa el flujo normal — esto verifica que el fix no rompió el happy path.
	 *
	 * No llega a wp_send_json_success porque el happy path hace más queries y emails
	 * que requieren más mocks — solo verificamos que NO se invoque wp_send_json_error.
	 */
	public function test_approve_kyc_happy_path_does_not_error_when_filters_pass(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$_POST = [ 'kyc_id' => '1' ];

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, mixed ...$args ): string { return $sql; }
			public function get_var( mixed $q = null ): mixed { return 1; }
			public function get_row( mixed $q = null ): mixed {
				return [ 'id' => 1, 'status' => 'pending', 'bank_name' => '', 'bank_account_number' => '' ];
			}
			public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int { return 0; }
		};

		// apply_filters devuelve `true` (sin bloqueo).
		Functions\when( 'apply_filters' )->alias(
			static function( string $tag, $value ) { return $value; }
		);

		$error_called = false;
		Functions\when( 'wp_send_json_error' )->alias(
			static function() use ( &$error_called ): void {
				$error_called = true;
				throw new \RuntimeException( 'json_error' );
			}
		);

		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( (object) [ 'user_email' => 'v@x.co', 'display_name' => 'V' ] );

		// Desactivar el envío de email para que el handler no incluya el template
		// HTML (que usa otras funciones WP no relevantes a este test).
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = null ) => $key === 'ltms_email_kyc_approved' ? 'no' : $default
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
		if ( ! class_exists( 'LTMS_Utils', false ) ) {
			eval( 'final class LTMS_Utils { public static function now_utc(): string { return date( "Y-m-d H:i:s" ); } }' );
		}
		if ( ! class_exists( 'LTMS_Legal_Compliance', false ) ) {
			eval( 'final class LTMS_Legal_Compliance { public static function log_vault_access( int $vid, int $aid, string $t, string $a, string $s ): void {} }' );
		}

		// Capture success to short-circuit before the handler emails.
		Functions\when( 'wp_send_json_success' )->alias(
			static function(): void {
				throw new \RuntimeException( 'json_success' );
			}
		);

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		$reached_success = false;
		try {
			$payouts->ajax_approve_kyc();
		} catch ( \RuntimeException $e ) {
			$reached_success = ( $e->getMessage() === 'json_success' );
		}

		$this->assertTrue( $reached_success, 'El happy path debe llegar a wp_send_json_success, no invocar wp_send_json_error' );
		$this->assertFalse( $error_called, 'wp_send_json_error no debe ser invocado en el happy path' );
	}

	/**
	 * Estructural: el filter AC-7 debe contener return WP_Error con code 'ac_cc_missing'.
	 */
	public function test_ac7_filter_returns_wp_error_on_cc_missing(): void {
		$this->require_class( 'LTMS_Authorities_Compliance' );
		$rc   = new ReflectionClass( 'LTMS_Authorities_Compliance' );
		$body = $this->get_method_body( $rc, 'validate_rut_and_camara_comercio' );

		$this->assertStringContainsString( "new \\WP_Error( 'ac_cc_missing'", $body );
		$this->assertStringContainsString( "new \\WP_Error( 'ac_cc_expired'", $body );
		$this->assertStringContainsString( "new \\WP_Error( 'ac_rut_dian_invalid'", $body );
	}

	/**
	 * Estructural: el filter FT-2 debe contener return WP_Error con code 'ft_sanctions_list_unavailable'.
	 */
	public function test_ft2_filter_returns_wp_error_on_list_unavailable(): void {
		$this->require_class( 'LTMS_Fintech_Compliance' );
		$rc   = new ReflectionClass( 'LTMS_Fintech_Compliance' );
		$body = $this->get_method_body( $rc, 'screen_against_sanctions_lists' );

		$this->assertStringContainsString( "new \\WP_Error( 'ft_sanctions_list_unavailable'", $body );
		$this->assertStringContainsString( "new \\WP_Error( 'ft_sanctions_match'", $body );
	}

	/**
	 * Estructural: el filter RT-2 debe contener return WP_Error con code 'kyc_sanitary_reg_missing'.
	 */
	public function test_rt2_filter_returns_wp_error_on_reg_missing(): void {
		$this->require_class( 'LTMS_Restaurant_Compliance' );
		$rc   = new ReflectionClass( 'LTMS_Restaurant_Compliance' );
		$body = $this->get_method_body( $rc, 'validate_sanitary_registration' );

		$this->assertStringContainsString( "new \\WP_Error( 'kyc_sanitary_reg_missing'", $body );
		$this->assertStringContainsString( "new \\WP_Error( 'kyc_sanitary_reg_expired'", $body );
	}

	/**
	 * Estructural: el filter HD-12 debe contener return WP_Error con code 'hd_minor_auth_missing'.
	 */
	public function test_hd12_filter_returns_wp_error_on_minor_auth_missing(): void {
		$this->require_class( 'LTMS_Data_Protection_Compliance' );
		$rc   = new ReflectionClass( 'LTMS_Data_Protection_Compliance' );
		$body = $this->get_method_body( $rc, 'verify_minor_authorization' );

		$this->assertStringContainsString( "new \\WP_Error( 'hd_minor_auth_missing'", $body );
		$this->assertStringContainsString( "new \\WP_Error( 'hd_minor_blocked'", $body );
	}

	/**
	 * Estructural: el handler ajax_approve_kyc debe manejar WP_Error en применяя filters.
	 */
	public function test_handler_approve_kyc_handles_wp_error(): void {
		$this->require_class( 'LTMS_Admin_Payouts' );
		$rc   = new ReflectionClass( 'LTMS_Admin_Payouts' );
		$body = $this->get_method_body( $rc, 'ajax_approve_kyc' );

		$this->assertStringContainsString( 'instanceof \\WP_Error', $body );
		$this->assertStringContainsString( 'get_error_message', $body );
		$this->assertStringContainsString( "'block_code'", $body );
	}

	/**
	 * E2E: el handler ajax_quick_approve_kyc también extrae el mensaje del WP_Error.
	 */
	public function test_quick_approve_kyc_returns_specific_message_on_block(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 3 ] );
		$_POST = [ 'vendor_id' => '3' ];

		Functions\when( 'apply_filters' )->alias(
			static function( string $tag, $value ) {
				if ( $tag === 'ltms_kyc_pre_approve' ) {
					return new \WP_Error(
						'ac_cc_missing',
						'Falta el número de matrícula de Cámara de Comercio.'
					);
				}
				return $value;
			}
		);

		$captured_payload = null;
		$captured_status  = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function( mixed $data = null, int $status = null ) use ( &$captured_payload, &$captured_status ): void {
				$captured_payload = $data;
				$captured_status  = $status;
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
		if ( ! class_exists( 'LTMS_Utils', false ) ) {
			eval( 'final class LTMS_Utils { public static function now_utc(): string { return date( "Y-m-d H:i:s" ); } }' );
		}

		$this->require_class( 'LTMS_Admin_Payouts' );
		$payouts = new \LTMS_Admin_Payouts();

		try {
			$payouts->ajax_quick_approve_kyc();
		} catch ( \RuntimeException $e ) {
			// esperado
		}

		$this->assertNotNull( $captured_payload );
		$this->assertSame( 403, $captured_status );
		$this->assertIsArray( $captured_payload );
		$this->assertSame( 'ac_cc_missing', $captured_payload['block_code'] );
		$this->assertStringContainsStringIgnoringCase( 'Cámara de Comercio', $captured_payload['message'] );
	}

	/**
	 * JS: el callback .fail() del approve debe usar ltmsHttpFailReason (no inline "Error de conexión.").
	 */
	public function test_js_approve_fail_callback_uses_specific_reason(): void {
		$view = dirname( __DIR__, 2 ) . '/includes/admin/views/html-admin-kyc.php';
		$this->assertFileExists( $view );
		$src = file_get_contents( $view );

		$this->assertStringContainsString( 'ltmsHttpFailReason', $src, 'La vista debe definir ltmsHttpFailReason()' );
		$this->assertStringContainsString( 'Tu sesión expiró', $src, 'JS debe distinguir sesión expirada (401/403 HTML) de error de red' );
		$this->assertStringContainsString( 'jqXHR.status === 0', $src, 'JS debe distinguir status 0 (red) de otros HTTP errors' );
		// El callback .fail() del approve debe delegar en ltmsHttpFailReason (no inline "Error de conexión.").
		$this->assertStringContainsString( 'ltmsShowKycError( ltmsHttpFailReason( jqXHR ) );', $src );
		$this->assertStringNotContainsString( "ltmsShowKycError( 'Error de conexión.' );", $src, 'No debe quedar el callback .fail() inline con string fijo' );
	}

	// ── Helper ──────────────────────────────────────────────────────────────

	/**
	 * Extrae el cuerpo (source) de un método via Reflection.
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
