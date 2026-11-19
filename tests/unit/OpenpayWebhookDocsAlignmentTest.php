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
	}

	private function make_request( string $json, string $signature = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/ltms/v1/webhooks/openpay' );
		$request->set_body( $json );
		if ( '' !== $signature ) {
			$request->set_header( 'x-openpay-signature', $signature );
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
