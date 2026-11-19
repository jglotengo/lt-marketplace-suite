<?php
/**
 * OpenpayWebhookDocsAlignmentTest — alineación del webhook Openpay con la doc
 * oficial (docs.openpay.co / documents.openpay.co).
 *
 * Cubre:
 *   1. Handshake `type=verification` (200 + persistencia del código) sin firma,
 *      tal como lo documenta Openpay (el payload no trae transaction).
 *   2. `verification_code` ausente → 400, sin persistir nada.
 *   3. Eventos de transacción sin firma siguen rechazados con 401 (fail-closed).
 *   4. Alias documentado `charge.refunded` despacha igual que `refund.succeeded`.
 *   5. El código persistido expira a las 24 h y se muestra a manage_woocommerce.
 *   6. HTTP Basic (mecanismo soportado por Openpay CO) aceptado sin HMAC;
 *      credenciales inválidas/ausentes → 401 cuando está configurado.
 *
 * Runtime real: instancia WP_REST_Request/Response (stubs mínimos en
 * tests/stubs/wp-rest-api-stubs.php) y ejecuta LTMS_Openpay_Webhook_Handler::handle().
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once __DIR__ . '/../stubs/wp-rest-api-stubs.php';

/**
 * @covers LTMS_Openpay_Webhook_Handler
 */
class OpenpayWebhookDocsAlignmentTest extends LTMS_Unit_Test_Case {

	private const HANDLER_PATH = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';

	protected function setUp(): void {
		parent::setUp();

		require_once self::HANDLER_PATH;

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );

		// Aislar credenciales entre tests: las claves/secretos se cachean en
		// LTMS_Core_Config y podrían filtrarse de un test a otro.
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', '' );
		\LTMS_Core_Config::set( 'ltms_openpay_private_key', '' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', '' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', '' );
		\LTMS_Core_Config::set( 'ltms_openpay_webhook_basic_user', '' );
		\LTMS_Core_Config::set( 'ltms_openpay_webhook_basic_password', '' );
	}

	private function make_request( string $json, string $signature = '', array $headers = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/ltms/v1/webhooks/openpay' );
		$request->set_body( $json );
		if ( '' !== $signature ) {
			$request->set_header( 'x-openpay-signature', $signature );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( (string) $name, (string) $value );
		}
		return $request;
	}

	// ── 1. Verificación documentada ──────────────────────────────────────────

	public function test_verification_event_is_acknowledged_and_persisted_without_signature(): void {
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value, $autoload = null ) use ( &$captured ): bool {
				$captured = [ $key, $value, $autoload ];
				return true;
			}
		);

		$payload  = wp_json_encode(
			[
				'type'              => 'verification',
				'verification_code' => 'abc123def456',
			]
		);
		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ok' => true ], $response->get_data() );

		$this->assertIsArray( $captured );
		$this->assertSame( 'ltms_openpay_webhook_verification', $captured[0] );
		$this->assertSame( 'abc123def456', $captured[1]['code'] );
		$this->assertArrayHasKey( 'received_at', $captured[1] );
		$this->assertSame( false, $captured[2] );
	}

	public function test_verification_event_without_code_is_rejected(): void {
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value, $autoload = null ) use ( &$captured ): bool {
				$captured = [ $key, $value ];
				return true;
			}
		);

		$payload  = wp_json_encode( [ 'type' => 'verification' ] );
		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $captured, 'Sin verification_code no debe persistirse nada.' );
	}

	// ── 2. Fail-closed sigue intacto para transacciones ──────────────────────

	public function test_empty_validation_ping_is_acknowledged_without_side_effects(): void {
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value, $autoload = null ) use ( &$captured ): bool {
				$captured = [ $key, $value ];
				return true;
			}
		);

		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( '{}' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ok' => true ], $response->get_data() );
		$this->assertNull( $captured, 'El ping de validación no debe persistir nada.' );
	}

	public function test_non_empty_unknown_event_without_signature_is_rejected(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', 'sk_test_abc' );

		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( '{"foo":"bar"}' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_unsigned_transaction_event_is_still_rejected(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', 'sk_test_abc' );

		$payload  = wp_json_encode(
			[
				'type'        => 'charge.succeeded',
				'transaction' => [
					'id'       => 'tr_test',
					'order_id' => '123',
				],
			]
		);
		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload ) );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── 3. Evento documentado charge.refunded ────────────────────────────────

	public function test_charge_refunded_event_dispatches_refund_logic(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', 'sk_test_abc' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_total' )->once()->andReturn( 100.0 );
		$order->shouldReceive( 'update_status' )
			->once()
			->with( 'refunded', Mockery::type( 'string' ) )
			->andReturn( true );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$payload   = wp_json_encode(
			[
				'type'        => 'charge.refunded',
				'transaction' => [
					'id'       => 'tr_refund_1',
					'order_id' => '123',
					'amount'   => 100,
				],
			]
		);
		$signature = 'sha256=' . hash_hmac( 'sha256', $payload, 'sk_test_abc' );

		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload, $signature ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'received' => true ], $response->get_data() );
	}

	// ── 3b. HTTP Basic (mecanismo documentado por Openpay) ───────────────────

	public function test_basic_auth_event_is_accepted_without_hmac(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'needs_payment' )->once()->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$payload = wp_json_encode(
			[
				'type'        => 'charge.succeeded',
				'transaction' => [
					'id'       => 'tr_basic_auth',
					'order_id' => '123',
				],
			]
		);
		$auth    = 'Basic ' . base64_encode( 'ltms_wh:super-secret' );

		$response = \LTMS_Openpay_Webhook_Handler::handle(
			$this->make_request( $payload, '', [ 'authorization' => $auth ] )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'received' => true ], $response->get_data() );
	}

	public function test_basic_auth_invalid_credentials_are_rejected(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );

		$payload = wp_json_encode(
			[
				'type'        => 'charge.succeeded',
				'transaction' => [
					'id'       => 'tr_basic_bad',
					'order_id' => '123',
				],
			]
		);
		$auth    = 'Basic ' . base64_encode( 'ltms_wh:wrong' );

		$response = \LTMS_Openpay_Webhook_Handler::handle(
			$this->make_request( $payload, '', [ 'authorization' => $auth ] )
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_basic_auth_missing_header_is_rejected_when_configured(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );

		$payload = wp_json_encode(
			[
				'type'        => 'charge.succeeded',
				'transaction' => [
					'id'       => 'tr_basic_missing',
					'order_id' => '123',
				],
			]
		);

		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_hmac_fallback_still_works_when_basic_is_configured(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', 'sk_test_abc' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'needs_payment' )->once()->andReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$payload   = wp_json_encode(
			[
				'type'        => 'charge.succeeded',
				'transaction' => [
					'id'       => 'tr_hmac_fallback',
					'order_id' => '123',
				],
			]
		);
		$signature = 'sha256=' . hash_hmac( 'sha256', $payload, 'sk_test_abc' );

		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload, $signature ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'received' => true ], $response->get_data() );
	}

	public function test_verification_event_still_needs_no_basic_auth(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );
		Functions\when( 'update_option' )->justReturn( true );

		$payload  = wp_json_encode(
			[
				'type'              => 'verification',
				'verification_code' => 'basic-order-check',
			]
		);
		$response = \LTMS_Openpay_Webhook_Handler::handle( $this->make_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ok' => true ], $response->get_data() );
	}

	// ── 3c. Bypass del error de Basic de WP core (Application Passwords) ─────

	public function test_core_basic_auth_error_is_bypassed_for_openpay_webhook_routes(): void {
		$error = new \WP_Error( 'incorrect_password', 'Invalid credentials' );

		$_SERVER['REQUEST_URI'] = '/wp-json/ltms/v1/webhooks/openpay';
		$this->assertTrue( \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/ltms/v1/webhook/openpay';
		$this->assertTrue( \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		// Otras rutas REST: el error de core pasa sin cambios.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
		$this->assertSame( $error, \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_core_basic_auth_error_is_bypassed_via_rest_route_query_param(): void {
		$error = new \WP_Error( 'incorrect_password', 'Invalid credentials' );

		// En producción PHP ya decodifica la query; $_GET trae el valor decoded.
		$_GET['rest_route'] = '/ltms/v1/webhooks/openpay';
		$this->assertTrue( \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		// Sin error de core, el filtro no interviene.
		$_SERVER['REQUEST_URI'] = '/wp-json/ltms/v1/webhooks/openpay';
		$this->assertTrue( \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( true ) );

		unset( $_GET['rest_route'], $_SERVER['REQUEST_URI'] );
	}

	public function test_bypass_does_not_fire_for_query_string_injection(): void {
		$error = new \WP_Error( 'incorrect_password', 'Invalid credentials' );

		// Substring del webhook dentro de la query de OTRA ruta: sin bypass.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders/123?_wpnonce=x&x=/ltms/v1/webhooks/openpay';
		$this->assertSame( $error, \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		// La ruta propia con query string sigue siendo bypassable.
		$_SERVER['REQUEST_URI'] = '/wp-json/ltms/v1/webhooks/openpay?_wpnonce=bad';
		$this->assertTrue( \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $error ) );

		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_bypass_only_clears_application_password_errors(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/ltms/v1/webhooks/openpay';

		// Error ajeno a Basic (nonce de cookie inválido): el bypass NO interviene.
		$nonce_error = new \WP_Error( 'rest_cookie_invalid_nonce', 'Cookie nonce is invalid' );
		$this->assertSame( $nonce_error, \LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( $nonce_error ) );

		// Códigos de Application Passwords (Basic de un no-usuario WP): se limpian.
		foreach ( [ 'invalid_username', 'incorrect_password', 'application_passwords_disabled', 'application_passwords_disabled_for_user' ] as $code ) {
			$this->assertTrue(
				\LTMS_Openpay_Webhook_Handler::bypass_core_basic_auth_for_webhook( new \WP_Error( $code, 'x' ) ),
				"Código $code debe pasar el bypass"
			);
		}

		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_scalar_transaction_is_rejected_with_400_not_500(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_user', 'ltms_wh' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_webhook_basic_password', 'super-secret' );

		$payload = wp_json_encode( [ 'type' => 'payout.created', 'transaction' => 'abc' ] );
		$auth    = 'Basic ' . base64_encode( 'ltms_wh:super-secret' );

		$response = \LTMS_Openpay_Webhook_Handler::handle(
			$this->make_request( $payload, '', [ 'authorization' => $auth ] )
		);

		$this->assertSame( 400, $response->get_status() );
	}

	// ── 4. Vigencia y exposición del código ──────────────────────────────────

	public function test_pending_verification_code_expires_after_24_hours(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'code'        => 'abc123',
				'received_at' => time() - 90000,
			]
		);

		$this->assertSame( [], \LTMS_Openpay_Webhook_Handler::get_pending_verification_code() );
	}

	public function test_pending_verification_code_is_returned_while_valid(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'code'        => 'abc123',
				'received_at' => time(),
			]
		);

		$pending = \LTMS_Openpay_Webhook_Handler::get_pending_verification_code();
		$this->assertSame( 'abc123', $pending['code'] );
	}

	public function test_verification_notice_renders_code_for_woocommerce_admins(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn(
			[
				'code'        => 'abc123',
				'received_at' => time(),
			]
		);

		ob_start();
		\LTMS_Openpay_Webhook_Handler::maybe_render_verification_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'abc123', $output );
	}

	// ── 5. Alias legacy de la URL guardada en el dashboard ───────────────────

	public function test_register_route_exposes_canonical_and_legacy_alias(): void {
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ): bool {
				$registered[] = $namespace . $route;
				return true;
			}
		);

		\LTMS_Openpay_Webhook_Handler::register_route();

		$this->assertContains( 'ltms/v1/webhooks/openpay', $registered );
		$this->assertContains( 'ltms/v1/webhook/openpay', $registered );
	}
}
