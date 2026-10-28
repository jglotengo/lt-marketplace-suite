<?php
/**
 * AuditFunnelBuyerPaymentsFixesTest — Tests de regresión del ciclo de auditoría
 * del funnel del comprador con foco en pagos E2E sandbox (AUDIT-FUNNEL-*).
 *
 * Cubre los fixes:
 *   PAY-01 (P0): orquestador consumía un contrato inventado de
 *     LTMS_Api_Openpay::charge() (['success','data']) — el contrato real es
 *     ['id','status','amount','authorization','raw'] → todo cobro Openpay
 *     exitoso se reportaba como fallido DESPUÉS de cobrar. Fix + stub del test
 *     corregido al contrato real (el stub anterior mentía y mantenía el bug
 *     invisible a la suite).
 *   PAY-02 (P0): LTMS_Api_Factory::get('stripe') instanciaba sin args →
 *     ArgumentCountError → fallback Stripe del orquestador jamás funcionó.
 *     Fix: constructor con auto-configuración desde settings WC.
 *   PAY-03/PAY-17 (P0): webhook Openpay llamaba decrypt() incondicional sobre
 *     la private key (que el gateway guarda en texto plano) → 500 en cada
 *     evento → PSE/Nequi/Daviplata/OXXO/SPEI nunca confirmados. Fix:
 *     LTMS_Core_Security::maybe_decrypt() + cifrado al persistir + fallback a
 *     la key genérica.
 *   PAY-04 (P0): gateway Openpay CO llamaba payment_complete() sin validar
 *     status → 3DS/in_progress = orden pagada sin dinero. Fix: solo
 *     'completed' confirma; in_progress+URL → on-hold + redirect 3DS.
 *   PAY-05 (P0): webhook Addi comparaba case 'APPROVED' contra
 *     sanitize_key('APPROVED')='approved' → nunca matcheaba. Fix: minúsculas.
 *   PAY-06 (P0): gateway Addi con contrato desalineado en ambas direcciones
 *     con LTMS_Api_Addi::create_application() → payload con orderId=null,
 *     value=0 y redirect al order-received en vez de Addi. Fix: keys reales
 *     + sync de settings WC → opciones LTMS + decrypt guard del constructor.
 *   PAY-07 (P0 MX): gateway Openpay MX instanciaba LTMS_Api_Openpay con 5 args
 *     (firma real: 1 arg opcional) → 'País no soportado: mXXXX' en todo pago
 *     MX. Fix: factory + firmas reales (create_charge posicional, oxxo,
 *     spei_reference).
 *   PAY-08 (P1): idempotency key de Openpay regenerada por intento → doble
 *     cobro en retry. Fix: order_ref estable por orden.
 *   PAY-09 (P1): lock de checkout TOCTOU (get_transient + set_transient
 *     separados). Fix: primitiva atómica add_option con stale-takeover.
 *   PAY-10 (P1): return_url 3DS de Stripe apuntaba al checkout. Fix:
 *     order-received URL.
 *   PAY-11 (P1): webhook Stripe respondía 200 tras error interno → evento
 *     perdido para siempre. Fix: 500 + borrar transient de idempotencia.
 *   PAY-12 (P1): lista de bancos PSE pedida SIEMPRE a producción con endpoint
 *     equivocado → PSE no operable en sandbox. Fix: delegar al cliente API.
 *   PAY-16 (P2): meta del gateway PSE nativo ausente en el fallback del
 *     webhook. PAY-21 (P2): eventos sin orden se tragaban con 200 silencioso.
 *   POST-05 (P0): listener de comisiones disparado por status_completed sin
 *     verificar pago → vendor acredita comisiones de pedidos impagos desde su
 *     panel. Fix: is_paid() en el path status_completed (excepción offline
 *     cod/bacs/cheque) + guard en ajax_update_order_status del vendor.
 *
 * Patrón: mezcla de tests conductuales (contratos reales, Brain Monkey) y
 * source-based (patrón C24/C25) para handlers que dependen de WP_REST_Request.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers LTMS_Core_Security, LTMS_Api_Stripe, LTMS_Payment_Orchestrator,
 *          LTMS_Order_Paid_Listener, gateways y webhooks del ciclo AUDIT-FUNNEL
 */
class AuditFunnelBuyerPaymentsFixesTest extends LTMS_Unit_Test_Case {

	private const SECURITY_PATH    = __DIR__ . '/../../includes/core/class-ltms-security.php';
	private const ORCHESTRATOR_PATH = __DIR__ . '/../../includes/business/class-ltms-payment-orchestrator.php';
	private const OPENPAY_API_PATH  = __DIR__ . '/../../includes/api/class-ltms-api-openpay.php';
	private const ADDI_API_PATH     = __DIR__ . '/../../includes/api/class-ltms-api-addi.php';
	private const STRIPE_API_PATH   = __DIR__ . '/../../includes/api/class-ltms-api-stripe.php';
	private const GATEWAYS_PATH     = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateways.php';
	private const GATEWAY_MX_PATH   = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateway-openpay-mx.php';
	private const OPENPAY_WH_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const ADDI_WH_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const STRIPE_WH_PATH   = __DIR__ . '/../../includes/api/webhooks/class-ltms-stripe-webhook-handler.php';
	private const STRIPE_GW_PATH   = __DIR__ . '/../../includes/gateway/class-ltms-gateway-stripe.php';
	private const CHECKOUT_H_PATH  = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const DASHBOARD_PATH   = __DIR__ . '/../../includes/frontend/class-ltms-dashboard-logic.php';
	private const LISTENER_PATH    = __DIR__ . '/../../includes/business/listeners/class-ltms-order-paid-listener.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'          => static fn( string $s ): string => $s,
			'esc_html__'  => static fn( string $s ): string => $s,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ════════════════════════════════════════════════════════════════════
	// A. Helpers de cifrado (PAY-03) — conductual con crypto real del stub
	// ════════════════════════════════════════════════════════════════════

	public function test_maybe_decrypt_plaintext_passthrough_no_throw(): void {
		// PAY-03 (regresión exacta): una private key en texto plano ('sk_live_...')
		// NO debe lanzar InvalidArgumentException — era el bug que mataba el
		// webhook Openpay con 500 en cada evento.
		$result = \LTMS_Core_Security::maybe_decrypt( 'sk_live_priv plaintext' );
		$this->assertSame( 'sk_live_priv plaintext', $result );
	}

	public function test_maybe_decrypt_roundtrip_with_encrypt_if_plain(): void {
		$secret = 'sk_test_4xCDF23vTxsZ';
		$stored = \LTMS_Core_Security::encrypt_if_plain( $secret );

		$this->assertNotSame( $secret, $stored, 'encrypt_if_plain debe transformar el texto plano.' );
		$this->assertSame( $secret, \LTMS_Core_Security::maybe_decrypt( $stored ) );
	}

	public function test_encrypt_if_plain_no_double_encrypt(): void {
		$secret = 'sk_test_abc123';
		$stored = \LTMS_Core_Security::encrypt_if_plain( $secret );

		// Re-persistir un valor ya cifrado no debe volver a cifrarlo.
		$this->assertSame( $stored, \LTMS_Core_Security::encrypt_if_plain( $stored ) );
	}

	public function test_maybe_decrypt_empty_string(): void {
		$this->assertSame( '', \LTMS_Core_Security::maybe_decrypt( '' ) );
	}

	public function test_security_real_class_has_helpers(): void {
		$this->assertFileExists( self::SECURITY_PATH );
		$source = file_get_contents( self::SECURITY_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-03', $source );
		$this->assertStringContainsString( 'public static function maybe_decrypt(', $source );
		$this->assertStringContainsString( 'public static function encrypt_if_plain(', $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// B. Stripe self-config (PAY-02) — conductual
	// ════════════════════════════════════════════════════════════════════

	public function test_stripe_constructor_no_args_self_configures(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'testmode' => 'yes', 'secret_key' => 'sk_test_selfconfig_123' ]
		);

		// Antes del fix: ArgumentCountError (secret_key era obligatorio).
		$stripe = new \LTMS_Api_Stripe();
		$this->assertSame( 'stripe', $stripe->get_provider_slug() );
	}

	public function test_api_factory_get_stripe_instantiates_without_error(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'testmode' => 'yes', 'secret_key' => 'sk_test_factory_456' ]
		);

		\LTMS_Api_Factory::reset( 'stripe' );

		// Antes del fix: TypeError fatal — la factory instanciaba `new $class_name()`.
		$client = \LTMS_Api_Factory::get( 'stripe' );
		$this->assertInstanceOf( \LTMS_Api_Stripe::class, $client );

		\LTMS_Api_Factory::reset( 'stripe' );
	}

	public function test_stripe_return_url_resolves_order_received(): void {
		// PAY-10: el return_url del 3DS debe derivar de la orden (order-received),
		// no de wc_get_checkout_url().
		$this->assertFileExists( self::STRIPE_API_PATH );
		$source = file_get_contents( self::STRIPE_API_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-10', $source );
		$this->assertStringContainsString( 'resolve_return_url', $source );
		// El patrón viejo (return_url directo al checkout) ya no debe existir:
		$this->assertStringNotContainsString(
			"\"return_url\"           = wc_get_checkout_url();",
			$source
		);
	}

	// ════════════════════════════════════════════════════════════════════
	// C. Orquestador (PAY-01) — conductual con contrato REAL de charge()
	// ════════════════════════════════════════════════════════════════════

	public function test_charge_via_openpay_completed_returns_transaction_id(): void {
		$stub = new class() extends \LTMS_Abstract_API_Client {
			public function charge( array $data ): array {
				return [
					'id'            => 'op_real_charge_1',
					'status'        => 'completed',
					'amount'        => 50000.0,
					'authorization' => 'AUTH',
					'raw'           => [],
				];
			}
			public function health_check(): array { return [ 'status' => 'ok' ]; }
			public function get_provider_slug(): string { return 'openpay'; }
		};

		$this->inject_factory_instance( 'openpay', $stub );

		$order = $this->make_order();
		$method = new \ReflectionMethod( \LTMS_Payment_Orchestrator::class, 'charge_via_openpay' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 50000.0, 'COP', [ 'token_id' => 'tok_1' ], $order );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'op_real_charge_1', $result['transaction_id'] );
	}

	public function test_charge_via_openpay_in_progress_throws_not_success(): void {
		// PAY-01/PAY-04: un cargo in_progress (3DS) NO debe reportarse como
		// éxito — el caller haría payment_complete() de un cargo sin confirmar.
		$stub = new class() extends \LTMS_Abstract_API_Client {
			public function charge( array $data ): array {
				return [
					'id'            => 'op_3ds_charge',
					'status'        => 'in_progress',
					'amount'        => 50000.0,
					'authorization' => '',
					'raw'           => [ 'payment_method' => [ 'url' => 'https://3ds.example/auth' ] ],
				];
			}
			public function health_check(): array { return [ 'status' => 'ok' ]; }
			public function get_provider_slug(): string { return 'openpay'; }
		};

		$this->inject_factory_instance( 'openpay', $stub );

		$order  = $this->make_order();
		$method = new \ReflectionMethod( \LTMS_Payment_Orchestrator::class, 'charge_via_openpay' );
		$method->setAccessible( true );

		$this->expectException( \RuntimeException::class );
		$method->invoke( null, 50000.0, 'COP', [ 'token_id' => 'tok_1' ], $order );
	}

	public function test_orchestrator_no_longer_reads_invented_success_key(): void {
		$this->assertFileExists( self::ORCHESTRATOR_PATH );
		$source = file_get_contents( self::ORCHESTRATOR_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-01', $source );

		// Aislar el método charge_via_openpay (la rama stripe SÍ usa data.id —
		// ese contrato es correcto para create_payment_intent).
		$start = strpos( $source, 'function charge_via_openpay' );
		$this->assertNotFalse( $start, 'charge_via_openpay debe existir.' );
		$end   = strpos( $source, 'Utilidades internas', $start );
		$method_body = substr( $source, $start, ( $end ? $end : strlen( $source ) ) - $start );

		$this->assertStringContainsString( "'completed' !== \$charge_status", $method_body );
		$this->assertStringNotContainsString( "empty( \$result['success'] )", $method_body );
		$this->assertStringNotContainsString( "\$result['data']['id']", $method_body );
	}

	// ════════════════════════════════════════════════════════════════════
	// D. Webhooks — source-based (patrón C24/C25, WP_REST_Request fuera de scope unit)
	// ════════════════════════════════════════════════════════════════════

	public function test_addi_webhook_matches_lowercase_status(): void {
		$this->assertFileExists( self::ADDI_WH_PATH );
		$source = file_get_contents( self::ADDI_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-05', $source );
		$this->assertStringContainsString( "strtolower( trim( \$status ) )", $source );
		$this->assertStringContainsString( "case 'approved':", $source );
		$this->assertStringContainsString( "case 'rejected':", $source );
		$this->assertStringContainsString( "case 'cancelled':", $source );
		// El case en mayúsculas ya no debe existir (nunca matcheaba contra
		// sanitize_key() que lowercase):
		$this->assertStringNotContainsString( "case 'APPROVED':", $source );
	}

	public function test_openpay_webhook_uses_maybe_decrypt_with_generic_fallback(): void {
		$this->assertFileExists( self::OPENPAY_WH_PATH );
		$source = file_get_contents( self::OPENPAY_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-03/PAY-17', $source );
		$this->assertStringContainsString( 'maybe_decrypt', $source );
		// Fallback a la key genérica (PAY-17):
		$this->assertStringContainsString( "ltms_openpay_private_key", $source );
	}

	public function test_openpay_webhook_meta_fallback_includes_native_pse_gateway(): void {
		$this->assertFileExists( self::OPENPAY_WH_PATH );
		$source = file_get_contents( self::OPENPAY_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-16', $source );
		$this->assertStringContainsString( "'_ltms_openpay_pse_transaction_id'", $source );
		$this->assertStringContainsString( "'_ltms_openpay_mx_charge_id'", $source );
	}

	public function test_openpay_webhook_logs_orphan_charge_events(): void {
		$this->assertFileExists( self::OPENPAY_WH_PATH );
		$source = file_get_contents( self::OPENPAY_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-21', $source );
		$this->assertStringContainsString( 'OPENPAY_WEBHOOK_ORPHAN_EVENT', $source );
	}

	public function test_stripe_webhook_returns_500_and_clears_seen_on_error(): void {
		$this->assertFileExists( self::STRIPE_WH_PATH );
		$source = file_get_contents( self::STRIPE_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-11', $source );
		$this->assertStringContainsString( 'delete_transient( $seen_key )', $source );
		// El catch debe responder 500 (antes 200 → evento perdido para siempre):
		$this->assertStringContainsString( "'Internal processing error'", $source );
		$this->assertStringContainsString( '500', $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// E. Gateways WC — source-based
	// ════════════════════════════════════════════════════════════════════

	public function test_openpay_gateway_validates_status_before_payment_complete(): void {
		$this->assertFileExists( self::GATEWAYS_PATH );
		$source = file_get_contents( self::GATEWAYS_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-04', $source );
		$this->assertStringContainsString( "'completed' !== \$charge_status", $source );
	}

	public function test_openpay_gateway_persists_private_key_encrypted(): void {
		$this->assertFileExists( self::GATEWAYS_PATH );
		$source = file_get_contents( self::GATEWAYS_PATH );

		// Lado escritor del PAY-03: nunca guardar la private key en texto plano.
		$this->assertStringContainsString( 'encrypt_if_plain', $source );
		$this->assertStringNotContainsString(
			"update_option( \"ltms_openpay_{\$country}_private_key\",  \$this->get_option( 'private_key', '' ) );",
			$source
		);
	}

	public function test_addi_gateway_uses_real_contract_keys(): void {
		$this->assertFileExists( self::GATEWAYS_PATH );
		$source = file_get_contents( self::GATEWAYS_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-06', $source );
		// Keys que la API realmente lee:
		$this->assertStringContainsString( "'callback_approved'", $source );
		$this->assertStringContainsString( "'callback_rejected'", $source );
		$this->assertStringContainsString( "'callback_cancelled'", $source );
		$this->assertStringContainsString( "'order_id'           => (string) \$order_id", $source );
		// Keys que la API retorna:
		$this->assertStringContainsString( "\$session['checkout_url']", $source );
		// El contrato inventado de ida ya no debe enviarse:
		$this->assertStringNotContainsString( "'totalAmount' => [", $source );
	}

	public function test_addi_gateway_syncs_settings_to_ltms_options(): void {
		$this->assertFileExists( self::GATEWAYS_PATH );
		$source = file_get_contents( self::GATEWAYS_PATH );

		$this->assertStringContainsString( "update_option( 'ltms_addi_client_id'", $source );
		$this->assertStringContainsString( "update_option( 'ltms_addi_webhook_token'", $source );
	}

	public function test_addi_api_constructor_uses_maybe_decrypt(): void {
		$this->assertFileExists( self::ADDI_API_PATH );
		$source = file_get_contents( self::ADDI_API_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-06', $source );
		$this->assertStringContainsString( 'maybe_decrypt', $source );
	}

	public function test_openpay_mx_gateway_no_broken_constructor_call(): void {
		$this->assertFileExists( self::GATEWAY_MX_PATH );
		$source = file_get_contents( self::GATEWAY_MX_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-07', $source );
		// La instanciación rota (merchant_id como country_override) ya no debe
		// existir como código ejecutable — el patrón de asignación directa:
		$this->assertStringNotContainsString( '= new LTMS_Api_Openpay(', $source );
		// Debe usar el factory y las firmas reales:
		$this->assertStringContainsString( "LTMS_Api_Factory::get( 'openpay' )", $source );
		$this->assertStringContainsString( 'create_spei_reference', $source );
	}

	public function test_pse_gateway_banks_delegate_to_api_client(): void {
		$this->assertFileExists( self::GATEWAYS_PATH );
		$source = file_get_contents( self::GATEWAYS_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-12', $source );
		$this->assertStringContainsString( 'get_pse_banks', $source );
		// La llamada remota con URL de producción hardcodeada ya no debe existir
		// como código (el comentario del fix usa el placeholder {merchant}):
		$this->assertStringNotContainsString( '{$merchant_id}/pseBanks', $source );
		$this->assertStringContainsString( "LTMS_Api_Factory::get( 'openpay' )", $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// F. Checkout handler (PAY-08, PAY-09) — conductual + source
	// ════════════════════════════════════════════════════════════════════

	public function test_checkout_lock_acquire_is_atomic_and_releases(): void {
		// get_current_user_id está definido en el bootstrap ANTES de Patchwork —
		// usar el global $GLOBALS['__ltms_current_uid'] en vez de re-stubear.
		$GLOBALS['__ltms_current_uid'] = 7;

		$handler = new \LTMS_Frontend_Checkout_Handler();

		$ref  = new \ReflectionMethod( $handler, 'acquire_checkout_lock' );
		$ref->setAccessible( true );

		$prop = new \ReflectionProperty( $handler, 'checkout_lock_key' );
		$prop->setAccessible( true );
		$prop->setValue( $handler, 'ltms_checkout_lock_7' );

		// Primera adquisición: INSERT atómico exitoso.
		Functions\when( 'add_option' )->justReturn( true );
		$this->assertTrue( $ref->invoke( $handler ) );

		// Lock fresco existente (crash no): add_option false, get_option reciente → 409.
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( (string) time() );
		$this->assertFalse( $ref->invoke( $handler ) );

		// Lock vencido (crash hace >60s): takeover exitoso.
		Functions\when( 'get_option' )->justReturn( (string) ( time() - 61 ) );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		$this->assertTrue( $ref->invoke( $handler ) );

		// Release: limpia la option del lock (+transient legado).
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$handler->release_checkout_lock();
		$this->assertTrue( true );

		unset( $GLOBALS['__ltms_current_uid'] );
	}

	public function test_checkout_lock_uses_atomic_primitive(): void {
		$this->assertFileExists( self::CHECKOUT_H_PATH );
		$source = file_get_contents( self::CHECKOUT_H_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-09', $source );
		$this->assertStringContainsString( 'function acquire_checkout_lock', $source );
		// El TOCTOU viejo ya no debe existir:
		$this->assertStringNotContainsString(
			'if ( get_transient( $this->checkout_lock_key ) )',
			$source
		);
	}

	public function test_checkout_order_ref_is_stable_per_order(): void {
		$this->assertFileExists( self::CHECKOUT_H_PATH );
		$source = file_get_contents( self::CHECKOUT_H_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-08', $source );
		$this->assertStringContainsString( "\$order_ref   = 'LTMS-' . \$order->get_id();", $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// G. POST-05 — listener + vendor dashboard
	// ════════════════════════════════════════════════════════════════════

	public function test_listener_blocks_split_on_unpaid_completed_order(): void {
		// POST-05: orden IMPAGA con pasarela online marcada 'completed' → el
		// listener NO debe procesar (ni siquiera reclamar el flag atómico).
		Functions\when( 'current_filter' )->justReturn( 'woocommerce_order_status_completed' );
		Functions\when( 'wc_get_order' )->justReturn(
			new class() extends \WC_Order {
				public function is_paid(): bool { return false; }
				public function get_payment_method(): string { return 'ltms_openpay'; }
				public function get_order_number(): string { return '1001'; }
			}
		);

		// add_post_meta es lo PRIMERO que hace el listener tras el guard — si
		// se llama, el pedido pasó el guard y eso sería el bug POST-05 vivo.
		Functions\expect( 'add_post_meta' )->never();

		\LTMS_Order_Paid_Listener::on_order_paid( 1001 );

		// La expectation `never()` es verificada por MockeryPHPUnitIntegration
		// en tearDown; addToAssertionCount evita el warning "risky no assertions".
		$this->addToAssertionCount( 1 );
	}

	public function test_listener_allows_unpaid_offline_completed_order(): void {
		// POST-05 (excepción): método offline (cod/bacs/cheque) marcado
		// 'completed' manualmente por el admin SÍ debe pasar el guard y
		// llegar al claim atómico (add_post_meta).
		Functions\when( 'current_filter' )->justReturn( 'woocommerce_order_status_completed' );
		Functions\when( 'wc_get_order' )->justReturn(
			new class() extends \WC_Order {
				public function is_paid(): bool { return false; }
				public function get_payment_method(): string { return 'bacs'; }
				public function get_order_number(): string { return '1002'; }
			}
		);

		Functions\expect( 'add_post_meta' )->once()->andReturn( true );

		$claimed = null;
		try {
			\LTMS_Order_Paid_Listener::on_order_paid( 1002 );
		} catch ( \Throwable $e ) {
			// process_commissions puede fallar por dependencias fuera de scope
			// unit — lo relevante es que PASÓ el guard: add_post_meta se ejecutó
			// una vez (verificado por la expectation de arriba).
			$claimed = $e;
		}

		// Sin assert del resultado: la expectation once() es la verificación.
		$this->addToAssertionCount( 1 );
	}

	public function test_listener_guard_tag_present(): void {
		$this->assertFileExists( self::LISTENER_PATH );
		$source = file_get_contents( self::LISTENER_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-POST-05', $source );
		$this->assertStringContainsString( 'ORDER_PAID_UNPAID_ORDER_BLOCKED', $source );
		$this->assertStringContainsString( "in_array( \$payment_method, [ 'cod', 'bacs', 'cheque' ], true )", $source );
	}

	public function test_vendor_dashboard_blocks_unpaid_status_transitions(): void {
		$this->assertFileExists( self::DASHBOARD_PATH );
		$source = file_get_contents( self::DASHBOARD_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-POST-05', $source );
		$this->assertStringContainsString( '! $order->is_paid()', $source );
		$this->assertStringContainsString( "in_array( (string) \$order->get_payment_method(), [ 'cod', 'bacs', 'cheque' ], true )", $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// H. Stripe gateway cart (PAY-20) — source-based
	// ════════════════════════════════════════════════════════════════════

	public function test_stripe_gateway_empty_cart_after_status_not_before(): void {
		$this->assertFileExists( self::STRIPE_GW_PATH );
		$source = file_get_contents( self::STRIPE_GW_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-20', $source );

		// Aislar process_payment: el empty_cart no debe ocurrir ANTES de evaluar
		// el status del PaymentIntent (el comentario viejo "Vaciar el carrito
		// ahora" indicaba la posición prematura).
		$start  = strpos( $source, 'function process_payment' );
		$this->assertNotFalse( $start, 'process_payment debe existir.' );
		$end    = strpos( $source, 'function process_refund', $start );
		$method = substr( $source, $start, ( $end ? $end : strlen( $source ) ) - $start );

		$this->assertStringNotContainsString( 'Vaciar el carrito ahora', $method );
		$cart_pos    = strpos( $method, 'WC()->cart->empty_cart();' );
		$status_pos  = strpos( $method, "'requires_action' || \$pi_status === 'requires_source_action'" );
		$this->assertNotFalse( $cart_pos, 'empty_cart debe seguir ocurriendo (en rutas de éxito).' );
		$this->assertNotFalse( $status_pos );
		$this->assertGreaterThan( $status_pos, $cart_pos, 'PAY-20: empty_cart debe ocurrir después de evaluar el status del pago.' );
	}

	public function test_openpay_webhook_event_type_preserves_dots(): void {
		// PAY-23 (re-auditoría): sanitize_key() elimina los PUNTOS de los tipos
		// de evento de Openpay ('charge.succeeded' → 'chargesucceeded') → todo
		// el dispatch estaba muerto. El handler debe sanitizar preservando el
		// formato real del evento.
		$this->assertFileExists( self::OPENPAY_WH_PATH );
		$source = file_get_contents( self::OPENPAY_WH_PATH );

		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-23', $source );
		$this->assertStringNotContainsString(
			'sanitize_key( $body[\'type\']',
			$source,
			'PAY-23: sanitize_key destruye los puntos de los event types Openpay.'
		);

		// Los cases con punto siguen presentes para el dispatch corregido:
		$this->assertStringContainsString( "case 'charge.succeeded':", $source );
		$this->assertStringContainsString( "case 'refund.succeeded':", $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// J. PAY-13: fuente única de verdad del entorno sandbox/live
	// ════════════════════════════════════════════════════════════════════

	public function test_openpay_environment_single_truth_gateway_testmode(): void {
		// PAY-13 (regresión exacta): flag global en 'production' + gateway
		// testmode=yes (default de fábrica del gateway) → antes el servidor
		// cobraba contra producción con un token creado en sandbox. Ahora el
		// testmode del gateway WC es la fuente única.
		\LTMS_Core_Config::set( 'LTMS_ENVIRONMENT', 'production' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', \LTMS_Core_Security::encrypt( 'merchant_123' ) );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', \LTMS_Core_Security::encrypt( 'sk_test_abc' ) );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = [] ) {
				if ( 'woocommerce_ltms_openpay_settings' === $key ) {
					return [ 'testmode' => 'yes' ];
				}
				return $default;
			}
		);

		$client = new \LTMS_Api_Openpay( 'CO' );
		$this->assertSame(
			'sandbox',
			$client->get_environment(),
			'PAY-13: testmode=yes del gateway debe forzar sandbox aunque el flag global diga production.'
		);
	}

	public function test_openpay_environment_live_when_gateway_testmode_no(): void {
		\LTMS_Core_Config::set( 'LTMS_ENVIRONMENT', 'staging' );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', \LTMS_Core_Security::encrypt( 'merchant_123' ) );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', \LTMS_Core_Security::encrypt( 'sk_live_abc' ) );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = [] ) {
				if ( 'woocommerce_ltms_openpay_settings' === $key ) {
					return [ 'testmode' => 'no' ];
				}
				return $default;
			}
		);

		$client = new \LTMS_Api_Openpay( 'CO' );
		$this->assertSame( 'live', $client->get_environment() );
	}

	public function test_openpay_environment_explicit_override_wins(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', \LTMS_Core_Security::encrypt( 'merchant_123' ) );
		\LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', \LTMS_Core_Security::encrypt( 'sk_test_abc' ) );

		Functions\when( 'get_option' )->justReturn( [ 'testmode' => 'no' ] );

		$client = new \LTMS_Api_Openpay( 'CO', 'sandbox' );
		$this->assertSame( 'sandbox', $client->get_environment() );
	}

	public function test_mx_client_reads_mx_gateway_settings(): void {
		\LTMS_Core_Config::set( 'ltms_openpay_MX_merchant_id', \LTMS_Core_Security::encrypt( 'm_mx' ) );
		\LTMS_Core_Config::set( 'ltms_openpay_MX_private_key', \LTMS_Core_Security::encrypt( 'sk_mx' ) );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = [] ) {
				if ( 'woocommerce_ltms_openpay_mx_settings' === $key ) {
					return [ 'testmode' => 'yes' ];
				}
				return $default;
			}
		);

		$client = new \LTMS_Api_Openpay( 'MX' );
		$this->assertSame( 'sandbox', $client->get_environment() );
	}

	public function test_pse_banks_cache_key_includes_environment(): void {
		$source = file_get_contents( self::OPENPAY_API_PATH );
		$this->assertStringContainsString(
			"'ltms_pse_banks_' . strtolower( \$this->country ) . '_' . \$this->environment",
			$source,
			'PAY-13: el cache de bancos PSE debe variar por entorno (antes servía 6h la lista del entorno anterior).'
		);
	}

	public function test_oxxo_charge_uses_mx_country(): void {
		// PAY-13 (hallazgo de re-auditoría): process_oxxo_payment instanciaba el
		// cliente sin país → usaba el país global del sitio (CO) para un método
		// que solo existe en MX.
		$source = file_get_contents( self::CHECKOUT_H_PATH );
		$this->assertStringContainsString( "new LTMS_Api_Openpay( 'MX' )", $source );
	}

	public function test_pse_charge_uses_pse_gateway_environment(): void {
		$source = file_get_contents( self::CHECKOUT_H_PATH );
		$this->assertStringContainsString( 'woocommerce_ltms_pse_settings', $source );
		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-13', $source );
	}

	public function test_addi_client_environment_single_truth(): void {
		\LTMS_Core_Config::set( 'ltms_addi_client_id', \LTMS_Core_Security::encrypt( 'cli_123' ) );
		\LTMS_Core_Config::set( 'ltms_addi_client_secret', \LTMS_Core_Security::encrypt( 'secret_123' ) );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = [] ) {
				if ( 'woocommerce_ltms_addi_settings' === $key ) {
					return [ 'testmode' => 'yes' ];
				}
				return $default;
			}
		);

		$client = new \LTMS_Api_Addi();
		$ref    = new \ReflectionProperty( \LTMS_Api_Addi::class, 'api_url' );
		$ref->setAccessible( true );
		$this->assertStringContainsString( 'sandbox', (string) $ref->getValue( $client ) );
	}

	// ════════════════════════════════════════════════════════════════════
	// K. PAY-15: device_session ausente ya no es silencioso
	// ════════════════════════════════════════════════════════════════════

	public function test_missing_device_session_is_logged(): void {
		$source = file_get_contents( self::GATEWAYS_PATH );
		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-15', $source );
		$this->assertStringContainsString( 'OPENPAY_DEVICE_SESSION_MISSING', $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// Helpers
	// ════════════════════════════════════════════════════════════════════

	private function make_order( int $id = 1 ): \WC_Order {
		return new class( $id ) extends \WC_Order {
			public function __construct( private int $order_id ) {}
			public function get_id(): int { return $this->order_id; }
			public function get_billing_email(): string { return 'test@example.com'; }
			public function get_billing_first_name(): string { return 'Juan'; }
			public function get_billing_last_name(): string { return 'Pérez'; }
			public function get_billing_phone(): string { return '3001234567'; }
		};
	}

	private function inject_factory_instance( string $provider, \LTMS_Abstract_API_Client $stub ): void {
		$ref  = new \ReflectionClass( \LTMS_Api_Factory::class );
		$prop = $ref->getProperty( 'instances' );
		$prop->setAccessible( true );
		$instances = $prop->getValue( null ) ?? [];
		$instances[ $provider ] = $stub;
		$prop->setValue( null, $instances );
	}
}
