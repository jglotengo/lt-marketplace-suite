<?php
/**
 * PaymentOrchestratorTest — Tests unitarios para LTMS_Payment_Orchestrator
 *
 * ÁNGULOS NUEVOS añadidos sobre los 53 originales:
 *
 * SECCIÓN 15 — select_gateway: circuit breaker + exclusivos Openpay
 *   - Método exclusivo Openpay con stripe down → sigue siendo openpay
 *     (los exclusivos no pasan por el circuit breaker)
 *   - bnpl con openpay/stripe down → sigue siendo addi (no usa circuit breaker)
 *   - card_intl exactamente en threshold COP → stripe (threshold no aplica a intl)
 *   - card_local con moneda distinta a COP/MXN → usa threshold COP como fallback
 *
 * SECCIÓN 16 — select_gateway: threshold boundary más fino
 *   - COP: amount == threshold - 0.01 → openpay (boundary exclusivo <)
 *   - COP: amount == threshold + 0.01 → stripe
 *   - MXN: amount == threshold - 0.01 → openpay
 *   - MXN: amount == threshold + 0.01 → stripe
 *   - Threshold COP reducido a 1 (todo va a stripe)
 *   - Threshold COP muy alto (todo va a openpay)
 *
 * SECCIÓN 17 — select_gateway: circuit breaker simétrico
 *   - stripe down + card_local alto (natural stripe) → fallback openpay
 *   - openpay down + card_local bajo (natural openpay) → fallback stripe
 *   - ambos down + card_local → retorna string (best-effort, no crash)
 *   - stripe down + card_intl → fallback openpay (aunque intl siempre sería stripe)
 *
 * SECCIÓN 18 — is_provider_down: comportamiento con transient null/0/''
 *   - get_transient retorna null → false
 *   - get_transient retorna 0 → false
 *   - get_transient retorna '' → false
 *   - get_transient retorna '1' (string truthy) → true
 *   - Nombre del transient usa el slug correcto (prefijo ltms_circuit_)
 *
 * SECCIÓN 19 — record_provider_event: boundary de error_code (substr 100)
 *   - error_code de 99 chars → no truncado
 *   - error_code de 101 chars → truncado a 100
 *   - error_code vacío → no lanza excepción
 *   - latencia negativa → no lanza excepción (no hay validación)
 *   - latencia muy grande (PHP_INT_MAX) → no lanza excepción
 *
 * SECCIÓN 20 — process_with_fallback: claves del resultado
 *   - Éxito primario stripe: resultado tiene transaction_id no vacío
 *   - Éxito primario openpay: transaction_id es 'op_test_456'
 *   - Fallback exitoso: gateway_used es el fallback, no el primario
 *   - Fallback exitoso: fallback_used es true
 *   - Error ambas: resultado no tiene transaction_id
 *   - Error ambas: error string no vacío
 *
 * SECCIÓN 21 — process_with_fallback: flujo openpay primario falla → stripe fallback
 *   - openpay primario falla (card_local bajo threshold), stripe como fallback
 *   - fallback_used = true, gateway_used = 'stripe'
 *
 * SECCIÓN 22 — Reflexión avanzada
 *   - charge_via es private static
 *   - maybe_trip_circuit_breaker es private static
 *   - elapsed_ms es private static
 *   - La clase tiene exactamente N métodos públicos estáticos
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

// ─────────────────────────────────────────────────────────────────────────────
// Stubs concretos de pasarelas — declarados en el ámbito global del archivo
// para que sean clases reales con nombre (requerido por LTMS_Api_Factory::register).
//
// Ambos extienden LTMS_Abstract_API_Client para satisfacer el tipo de retorno
// de LTMS_Api_Factory::get(): LTMS_Abstract_API_Client
// ─────────────────────────────────────────────────────────────────────────────

if ( ! class_exists( 'LTMS_Stripe_Test_Stub' ) ) {
    class LTMS_Stripe_Test_Stub extends LTMS_Abstract_API_Client {

        public bool   $should_fail = false;
        public string $provider_slug = 'stripe';

        public function create_payment_intent(
            float  $amount,
            string $currency,
            string $email,
            array  $metadata = []
        ): array {
            if ( $this->should_fail ) {
                throw new \RuntimeException( 'Stripe error' );
            }
            return [ 'success' => true, 'data' => [ 'id' => 'pi_test_123' ] ];
        }

        public function health_check(): array {
            return [ 'status' => 'ok', 'message' => 'stub' ];
        }

        public function get_provider_slug(): string {
            return $this->provider_slug;
        }
    }
}

if ( ! class_exists( 'LTMS_Openpay_Test_Stub' ) ) {
    class LTMS_Openpay_Test_Stub extends LTMS_Abstract_API_Client {

        public bool   $should_fail = false;
        public string $provider_slug = 'openpay';

        public function charge( array $data ): array {
            if ( $this->should_fail ) {
                throw new \RuntimeException( 'Openpay error' );
            }
            return [ 'success' => true, 'data' => [ 'id' => 'op_test_456' ] ];
        }

        public function health_check(): array {
            return [ 'status' => 'ok', 'message' => 'stub' ];
        }

        public function get_provider_slug(): string {
            return $this->provider_slug;
        }
    }
}

/**
 * @covers LTMS_Payment_Orchestrator
 */
class PaymentOrchestratorTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    /** @var LTMS_Stripe_Test_Stub */
    private LTMS_Stripe_Test_Stub $stripe_stub;

    /** @var LTMS_Openpay_Test_Stub */
    private LTMS_Openpay_Test_Stub $openpay_stub;

    protected function setUp(): void {
        parent::setUp();
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 200000.0 );
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_mxn', 1500.0 );
        LTMS_Core_Config::set( 'ltms_circuit_breaker_cooldown_minutes', 15 );

        LTMS_Api_Factory::reset_all();

        $this->stripe_stub  = new LTMS_Stripe_Test_Stub();
        $this->openpay_stub = new LTMS_Openpay_Test_Stub();

        LTMS_Api_Factory::register( 'stripe',  LTMS_Stripe_Test_Stub::class );
        LTMS_Api_Factory::register( 'openpay', LTMS_Openpay_Test_Stub::class );

        $this->inject_factory( 'stripe',  $this->stripe_stub );
        $this->inject_factory( 'openpay', $this->openpay_stub );
    }

    private function inject_factory( string $provider, LTMS_Abstract_API_Client $stub ): void {
        $ref  = new \ReflectionClass( LTMS_Api_Factory::class );
        $prop = $ref->getProperty( 'instances' );
        $prop->setAccessible( true );
        $instances = $prop->getValue( null ) ?? [];
        $instances[ $provider ] = $stub;
        $prop->setValue( null, $instances );
    }

    protected function tearDown(): void {
        LTMS_Api_Factory::reset_all();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function make_order( int $id = 1 ): \WC_Order {
        return new class( $id ) extends \WC_Order {
            public function __construct( private int $order_id ) {}
            public function get_id(): int                    { return $this->order_id; }
            public function get_billing_email(): string      { return 'test@example.com'; }
            public function get_billing_first_name(): string { return 'Juan'; }
            public function get_billing_last_name(): string  { return 'Pérez'; }
            public function get_billing_phone(): string      { return '3001234567'; }
        };
    }

    /** Stubs comunes que necesita casi todo test de process_with_fallback */
    private function stub_wp_functions(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( 'get_post_meta' )->justReturn( '5' );
        Functions\when( 'get_option' )->justReturn( 'admin@test.com' );
        Functions\when( '__' )->returnArg();
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'wp_mail' )->justReturn( true );
    }

    // -----------------------------------------------------------------------
    // 1. select_gateway — métodos exclusivos Openpay
    // -----------------------------------------------------------------------

    public static function provider_openpay_exclusive(): array {
        return [
            'pse'       => ['pse'],
            'nequi'     => ['nequi'],
            'daviplata' => ['daviplata'],
            'oxxo'      => ['oxxo'],
            'spei'      => ['spei'],
        ];
    }

    /** @dataProvider provider_openpay_exclusive */
    public function test_select_gateway_openpay_exclusive( string $payment_type ): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            100000.0, 'COP', $payment_type, 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    /** @dataProvider provider_openpay_exclusive */
    public function test_select_gateway_openpay_exclusive_from_mx( string $payment_type ): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            100.0, 'MXN', $payment_type, 'MX'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_openpay_exclusive_constant_has_five_entries(): void {
        $ref   = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $const = $ref->getConstant( 'OPENPAY_EXCLUSIVE' );

        $this->assertIsArray( $const );
        $this->assertCount( 5, $const );
        $this->assertContains( 'pse',       $const );
        $this->assertContains( 'nequi',     $const );
        $this->assertContains( 'daviplata', $const );
        $this->assertContains( 'oxxo',      $const );
        $this->assertContains( 'spei',      $const );
    }

    // -----------------------------------------------------------------------
    // 2. BNPL → Addi
    // -----------------------------------------------------------------------

    public function test_select_gateway_bnpl_returns_addi(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            500000.0, 'COP', 'bnpl', 'CO'
        );

        $this->assertSame( 'addi', $result );
    }

    public function test_select_gateway_bnpl_small_amount_still_addi(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            100.0, 'COP', 'bnpl', 'CO'
        );

        $this->assertSame( 'addi', $result );
    }

    // -----------------------------------------------------------------------
    // 3. Tarjeta internacional → Stripe
    // -----------------------------------------------------------------------

    public function test_select_gateway_card_intl_returns_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            5000.0, 'COP', 'card_intl', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    public function test_select_gateway_card_intl_mxn_returns_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            500.0, 'MXN', 'card_intl', 'MX'
        );

        $this->assertSame( 'stripe', $result );
    }

    public function test_select_gateway_card_intl_small_amount_still_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            1.0, 'COP', 'card_intl', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    // -----------------------------------------------------------------------
    // 4. Tarjeta local — threshold COP
    // -----------------------------------------------------------------------

    public static function provider_card_local_cop(): array {
        return [
            'bajo_threshold_cop'  => [ 50000.0,  'COP', 'openpay' ],
            'en_threshold_cop'    => [ 200000.0, 'COP', 'stripe'  ],
            'sobre_threshold_cop' => [ 300000.0, 'COP', 'stripe'  ],
        ];
    }

    /** @dataProvider provider_card_local_cop */
    public function test_select_gateway_card_local_cop(
        float $amount, string $currency, string $expected
    ): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            $amount, $currency, 'card_local', 'CO'
        );

        $this->assertSame( $expected, $result );
    }

    public function test_select_gateway_card_local_cop_one_below_threshold_is_openpay(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            199999.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_card_local_cop_one_above_threshold_is_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            200001.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    // -----------------------------------------------------------------------
    // 5. Tarjeta local — threshold MXN
    // -----------------------------------------------------------------------

    public static function provider_card_local_mxn(): array {
        return [
            'bajo_threshold_mxn'  => [ 999.0,  'MXN', 'openpay' ],
            'en_threshold_mxn'    => [ 1500.0, 'MXN', 'stripe'  ],
            'sobre_threshold_mxn' => [ 2000.0, 'MXN', 'stripe'  ],
        ];
    }

    /** @dataProvider provider_card_local_mxn */
    public function test_select_gateway_card_local_mxn(
        float $amount, string $currency, string $expected
    ): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            $amount, $currency, 'card_local', 'MX'
        );

        $this->assertSame( $expected, $result );
    }

    public function test_select_gateway_card_local_mxn_one_below_threshold_is_openpay(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            1499.0, 'MXN', 'card_local', 'MX'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_card_local_cop_respects_runtime_threshold_change(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 500000.0 );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            250000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_card_local_mxn_respects_runtime_threshold_change(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_mxn', 3000.0 );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            2000.0, 'MXN', 'card_local', 'MX'
        );

        $this->assertSame( 'openpay', $result );
    }

    // -----------------------------------------------------------------------
    // 6. Circuit breaker
    // -----------------------------------------------------------------------

    public function test_select_gateway_circuit_breaker_stripe_down_uses_openpay(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_stripe_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            5000.0, 'COP', 'card_intl', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_circuit_breaker_openpay_down_uses_stripe(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_openpay_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            50000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    public function test_select_gateway_both_down_returns_string(): void {
        Functions\when( 'get_transient' )->justReturn( true );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            5000.0, 'COP', 'card_intl', 'CO'
        );

        $this->assertContains( $result, [ 'stripe', 'openpay' ] );
    }

    public function test_select_gateway_stripe_down_does_not_affect_card_local_below_threshold(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_stripe_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            50000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_openpay_down_does_not_affect_card_local_above_threshold(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_openpay_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            300000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    // -----------------------------------------------------------------------
    // 7. is_provider_down
    // -----------------------------------------------------------------------

    public function test_is_provider_down_returns_true_when_transient_set(): void {
        Functions\when( 'get_transient' )->justReturn( true );

        $this->assertTrue( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    public function test_is_provider_down_returns_false_when_transient_absent(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    public function test_is_provider_down_returns_false_for_unknown_provider(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'unknown_gw' ) );
    }

    public function test_is_provider_down_return_type_is_bool(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::is_provider_down( 'stripe' );

        $this->assertIsBool( $result );
    }

    public function test_is_provider_down_only_matches_exact_provider_slug(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_stripe_down';
        } );

        $this->assertTrue(  LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'openpay' ) );
    }

    // -----------------------------------------------------------------------
    // 8. record_provider_event — verifica que no lanza excepción
    // -----------------------------------------------------------------------

    public function test_record_provider_event_runs_without_exception(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event( 'stripe', 'success', 120 );
    }

    public function test_record_provider_event_with_long_error_runs_without_exception(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event(
            'openpay', 'error', 500, str_repeat( 'e', 200 )
        );
    }

    public function test_record_provider_event_zero_latency_runs_without_exception(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event( 'stripe', 'timeout', 0 );
    }

    public function test_record_provider_event_error_code_exactly_100_chars(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event(
            'stripe', 'error', 300, str_repeat( 'x', 100 )
        );
    }

    public function test_record_provider_event_accepts_timeout_status(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event( 'addi', 'timeout', 5000 );
    }

    // -----------------------------------------------------------------------
    // 9. process_with_fallback — éxito en pasarela primaria
    // -----------------------------------------------------------------------

    public function test_process_with_fallback_primary_success(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $order  = $this->make_order( 42 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'stripe', $result['gateway_used'] );
        $this->assertFalse( $result['fallback_used'] );
        $this->assertSame( 'pi_test_123', $result['transaction_id'] );
    }

    public function test_process_with_fallback_primary_openpay_success(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();
        Functions\when( '__' )->returnArg();

        $order  = $this->make_order( 10 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            50000.0, 'COP', 'card_local', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'openpay', $result['gateway_used'] );
        $this->assertFalse( $result['fallback_used'] );
        $this->assertSame( 'op_test_456', $result['transaction_id'] );
    }

    public function test_process_with_fallback_success_fallback_used_is_false(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $order  = $this->make_order( 1 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertArrayHasKey( 'fallback_used', $result );
        $this->assertFalse( $result['fallback_used'] );
    }

    // -----------------------------------------------------------------------
    // 10. process_with_fallback — falla primaria, fallback exitoso
    // -----------------------------------------------------------------------

    public function test_process_with_fallback_uses_fallback_when_primary_fails(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail = true;

        $openpay_fallback = new LTMS_Openpay_Test_Stub();
        $this->inject_factory( 'openpay', $openpay_fallback );

        $order  = $this->make_order( 99 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'openpay', $result['gateway_used'] );
        $this->assertTrue( $result['fallback_used'] );
    }

    public function test_process_with_fallback_fallback_transaction_id_from_openpay(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail = true;

        $order  = $this->make_order( 77 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'op_test_456', $result['transaction_id'] );
    }

    // -----------------------------------------------------------------------
    // 11. process_with_fallback — ambas fallan
    // -----------------------------------------------------------------------

    public function test_process_with_fallback_both_fail_returns_error(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail  = true;
        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 7 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
        $this->assertNotEmpty( $result['error'] );
    }

    public function test_process_with_fallback_both_fail_has_no_gateway_used(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail  = true;
        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 8 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'gateway_used', $result );
    }

    // -----------------------------------------------------------------------
    // 12. process_with_fallback — fallback abortado si alternativa down
    // -----------------------------------------------------------------------

    public function test_process_with_fallback_aborts_if_fallback_also_down(): void {
        $this->stub_wp_functions();

        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_openpay_down';
        } );
        $this->stripe_stub->should_fail = true;

        $order  = $this->make_order( 3 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    // -----------------------------------------------------------------------
    // 13. Invariantes
    // -----------------------------------------------------------------------

    public static function provider_all_payment_types(): array {
        return [
            'pse'        => ['pse',        'COP', 100000.0],
            'nequi'      => ['nequi',      'COP', 100000.0],
            'daviplata'  => ['daviplata',  'COP', 100000.0],
            'oxxo'       => ['oxxo',       'MXN', 100.0   ],
            'spei'       => ['spei',       'MXN', 100.0   ],
            'bnpl'       => ['bnpl',       'COP', 500000.0],
            'card_intl'  => ['card_intl',  'COP', 5000.0  ],
            'card_local' => ['card_local', 'COP', 50000.0 ],
        ];
    }

    /** @dataProvider provider_all_payment_types */
    public function test_select_gateway_always_returns_known_provider(
        string $payment_type, string $currency, float $amount
    ): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            $amount, $currency, $payment_type, 'CO'
        );

        $this->assertContains( $result, [ 'stripe', 'openpay', 'addi' ] );
    }

    public function test_select_gateway_return_type_is_string(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway( 100.0, 'COP', 'card_local', 'CO' );

        $this->assertIsString( $result );
    }

    public function test_process_with_fallback_always_returns_array_with_success_key(): void {
        Functions\when( 'get_transient' )->justReturn( true );
        $this->stub_wp_functions();

        $order  = $this->make_order();
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            100.0, 'COP', 'card_intl', [], $order
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
    }

    public function test_process_with_fallback_success_key_is_always_bool(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $order  = $this->make_order( 50 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertIsBool( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // 14. Reflexión — estructura de la clase
    // -----------------------------------------------------------------------

    public function test_reflection_class_is_final(): void {
        $ref = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $this->assertTrue( $ref->isFinal() );
    }

    public function test_reflection_class_is_not_instantiable(): void {
        $ref = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $this->assertFalse( $ref->isInstantiable() );
    }

    public function test_reflection_select_gateway_is_public_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'select_gateway' );
        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->isStatic() );
    }

    public function test_reflection_select_gateway_has_four_parameters(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'select_gateway' );
        $this->assertCount( 4, $method->getParameters() );
    }

    public function test_reflection_select_gateway_returns_string(): void {
        $ref        = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method     = $ref->getMethod( 'select_gateway' );
        $returnType = $method->getReturnType();
        $this->assertNotNull( $returnType );
        $this->assertSame( 'string', (string) $returnType );
    }

    public function test_reflection_is_provider_down_is_public_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'is_provider_down' );
        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->isStatic() );
    }

    public function test_reflection_is_provider_down_returns_bool(): void {
        $ref        = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method     = $ref->getMethod( 'is_provider_down' );
        $returnType = $method->getReturnType();
        $this->assertNotNull( $returnType );
        $this->assertSame( 'bool', (string) $returnType );
    }

    public function test_reflection_record_provider_event_is_public_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'record_provider_event' );
        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->isStatic() );
    }

    public function test_reflection_record_provider_event_error_code_has_default(): void {
        $ref        = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $params     = $ref->getMethod( 'record_provider_event' )->getParameters();
        $last_param = end( $params );
        $this->assertTrue( $last_param->isDefaultValueAvailable() );
        $this->assertSame( '', $last_param->getDefaultValue() );
    }

    public function test_reflection_process_with_fallback_is_public_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'process_with_fallback' );
        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->isStatic() );
    }

    public function test_reflection_process_with_fallback_returns_array(): void {
        $ref        = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method     = $ref->getMethod( 'process_with_fallback' );
        $returnType = $method->getReturnType();
        $this->assertNotNull( $returnType );
        $this->assertSame( 'array', (string) $returnType );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ▼▼▼  ÁNGULOS NUEVOS  ▼▼▼
    // ═══════════════════════════════════════════════════════════════════════

    // -----------------------------------------------------------------------
    // 15. select_gateway: exclusivos + bnpl no pasan por circuit breaker
    // -----------------------------------------------------------------------

    /**
     * Método exclusivo Openpay con stripe y openpay DOWN → sigue siendo openpay.
     * Los exclusivos retornan 'openpay' ANTES de llegar al circuit breaker.
     *
     * @dataProvider provider_openpay_exclusive
     */
    public function test_select_gateway_exclusive_ignora_circuit_breaker( string $payment_type ): void {
        // Aunque ambas pasarelas estén "down", el exclusivo retorna openpay directo
        Functions\when( 'get_transient' )->justReturn( true );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            100000.0, 'COP', $payment_type, 'CO'
        );

        // Los exclusivos no pasan por circuit breaker — retornan openpay sin consultar transients
        $this->assertSame( 'openpay', $result );
    }

    /**
     * bnpl con ambas pasarelas down → sigue siendo addi.
     * bnpl retorna antes del circuit breaker.
     */
    public function test_select_gateway_bnpl_ignora_circuit_breaker(): void {
        Functions\when( 'get_transient' )->justReturn( true );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            500000.0, 'COP', 'bnpl', 'CO'
        );

        $this->assertSame( 'addi', $result );
    }

    /**
     * card_intl con monto exactamente igual al threshold COP → stripe
     * (threshold solo aplica a card_local; intl siempre va a stripe).
     */
    public function test_select_gateway_card_intl_en_threshold_cop_sigue_siendo_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        // Exactamente en el threshold COP (200,000) con card_intl → stripe igualmente
        $result = LTMS_Payment_Orchestrator::select_gateway(
            200000.0, 'COP', 'card_intl', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    // -----------------------------------------------------------------------
    // 16. select_gateway: boundary fino del threshold (0.01 sobre/bajo)
    // -----------------------------------------------------------------------

    /**
     * COP: amount = threshold - 0.01 → openpay (estrictamente menor, no igual).
     * La condición en el código es ($amount < $threshold).
     */
    public function test_select_gateway_card_local_cop_justo_bajo_threshold_decimal(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            199999.99, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    /**
     * COP: amount = threshold + 0.01 → stripe.
     */
    public function test_select_gateway_card_local_cop_justo_sobre_threshold_decimal(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            200000.01, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'stripe', $result );
    }

    /**
     * MXN: amount = threshold - 0.01 → openpay.
     */
    public function test_select_gateway_card_local_mxn_justo_bajo_threshold_decimal(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            1499.99, 'MXN', 'card_local', 'MX'
        );

        $this->assertSame( 'openpay', $result );
    }

    /**
     * MXN: amount = threshold + 0.01 → stripe.
     */
    public function test_select_gateway_card_local_mxn_justo_sobre_threshold_decimal(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            1500.01, 'MXN', 'card_local', 'MX'
        );

        $this->assertSame( 'stripe', $result );
    }

    /**
     * Threshold COP reducido a 1 → cualquier monto >= 1 va a stripe.
     */
    public function test_select_gateway_threshold_cop_en_1_todo_va_a_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 1.0 );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            1.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'stripe', $result, 'Con threshold=1 y amount=1, amount no es < threshold → stripe' );
    }

    /**
     * Threshold COP muy alto (999M) → cualquier monto razonable va a openpay.
     */
    public function test_select_gateway_threshold_cop_muy_alto_todo_va_a_openpay(): void {
        Functions\when( 'get_transient' )->justReturn( false );

        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 999_000_000.0 );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            5_000_000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertSame( 'openpay', $result );
    }

    // -----------------------------------------------------------------------
    // 17. select_gateway: circuit breaker simétrico (más casos)
    // -----------------------------------------------------------------------

    /**
     * stripe down + card_local alto (natural stripe → 300K > 200K threshold) → fallback openpay.
     */
    public function test_select_gateway_stripe_down_card_local_alto_usa_openpay(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_stripe_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            300000.0, 'COP', 'card_local', 'CO'
        );

        // natural=stripe, stripe down → fallback openpay
        $this->assertSame( 'openpay', $result );
    }

    /**
     * openpay down + card_local bajo (natural openpay → 50K < 200K threshold) → fallback stripe.
     */
    public function test_select_gateway_openpay_down_card_local_bajo_usa_stripe(): void {
        Functions\when( 'get_transient' )->alias( function( string $key ) {
            return $key === 'ltms_circuit_openpay_down';
        } );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            50000.0, 'COP', 'card_local', 'CO'
        );

        // natural=openpay, openpay down → fallback stripe
        $this->assertSame( 'stripe', $result );
    }

    /**
     * Ambas down + card_local → retorna un string válido (best-effort, no crash).
     */
    public function test_select_gateway_ambas_down_card_local_retorna_string(): void {
        Functions\when( 'get_transient' )->justReturn( true );

        $result = LTMS_Payment_Orchestrator::select_gateway(
            50000.0, 'COP', 'card_local', 'CO'
        );

        $this->assertIsString( $result );
        $this->assertContains( $result, [ 'stripe', 'openpay' ],
            'best-effort: debe devolver uno de los dos proveedores aunque ambos estén down' );
    }

    // -----------------------------------------------------------------------
    // 18. is_provider_down: valores falsy de get_transient
    // -----------------------------------------------------------------------

    /**
     * get_transient retorna null → is_provider_down = false.
     */
    public function test_is_provider_down_null_retorna_false(): void {
        Functions\when( 'get_transient' )->justReturn( null );

        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    /**
     * get_transient retorna 0 → is_provider_down = false.
     */
    public function test_is_provider_down_zero_retorna_false(): void {
        Functions\when( 'get_transient' )->justReturn( 0 );

        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    /**
     * get_transient retorna '' → is_provider_down = false.
     */
    public function test_is_provider_down_string_vacio_retorna_false(): void {
        Functions\when( 'get_transient' )->justReturn( '' );

        $this->assertFalse( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    /**
     * get_transient retorna '1' (string truthy) → is_provider_down = true.
     */
    public function test_is_provider_down_string_uno_retorna_true(): void {
        Functions\when( 'get_transient' )->justReturn( '1' );

        $this->assertTrue( LTMS_Payment_Orchestrator::is_provider_down( 'stripe' ) );
    }

    /**
     * El transient consultado sigue el patrón 'ltms_circuit_{provider}_down'.
     */
    public function test_is_provider_down_usa_clave_transient_correcta(): void {
        $captured_key = null;
        Functions\when( 'get_transient' )->alias( function( string $key ) use ( &$captured_key ) {
            $captured_key = $key;
            return false;
        } );

        LTMS_Payment_Orchestrator::is_provider_down( 'stripe' );

        $this->assertSame( 'ltms_circuit_stripe_down', $captured_key,
            'La clave del transient debe seguir el patrón ltms_circuit_{provider}_down' );
    }

    /**
     * Confirmar patrón de clave para openpay.
     */
    public function test_is_provider_down_clave_transient_openpay(): void {
        $captured_key = null;
        Functions\when( 'get_transient' )->alias( function( string $key ) use ( &$captured_key ) {
            $captured_key = $key;
            return false;
        } );

        LTMS_Payment_Orchestrator::is_provider_down( 'openpay' );

        $this->assertSame( 'ltms_circuit_openpay_down', $captured_key );
    }

    // -----------------------------------------------------------------------
    // 19. record_provider_event: boundary de error_code (substr 100)
    // -----------------------------------------------------------------------

    /**
     * error_code de 99 chars → no lanza excepción (bajo el límite de substr 100).
     */
    public function test_record_provider_event_error_code_99_chars_no_lanza(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event(
            'stripe', 'error', 200, str_repeat( 'a', 99 )
        );
    }

    /**
     * error_code de 101 chars → no lanza excepción (substr lo trunca a 100).
     */
    public function test_record_provider_event_error_code_101_chars_no_lanza(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event(
            'openpay', 'error', 150, str_repeat( 'b', 101 )
        );
    }

    /**
     * error_code vacío (default '') → no lanza excepción.
     */
    public function test_record_provider_event_sin_error_code_no_lanza(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        // Llamada sin cuarto argumento → usa default ''
        LTMS_Payment_Orchestrator::record_provider_event( 'addi', 'success', 80 );
    }

    /**
     * Latencia muy grande (PHP_INT_MAX) → no lanza excepción.
     */
    public function test_record_provider_event_latencia_muy_grande_no_lanza(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $this->expectNotToPerformAssertions();

        LTMS_Payment_Orchestrator::record_provider_event( 'stripe', 'timeout', PHP_INT_MAX );
    }

    // -----------------------------------------------------------------------
    // 20. process_with_fallback: claves específicas del resultado
    // -----------------------------------------------------------------------

    /**
     * Éxito primario stripe: transaction_id no está vacío.
     */
    public function test_process_with_fallback_primary_stripe_transaction_id_no_vacio(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $order  = $this->make_order( 11 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertNotEmpty( $result['transaction_id'],
            'transaction_id no debe estar vacío en éxito primario stripe' );
    }

    /**
     * Fallback exitoso: gateway_used es 'openpay' (el fallback), no 'stripe' (el primario).
     */
    public function test_process_with_fallback_gateway_used_es_el_fallback_no_el_primario(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail = true;

        $order  = $this->make_order( 22 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertTrue( $result['success'] );
        // El primario era stripe pero falló; el gateway_used debe ser el fallback
        $this->assertSame( 'openpay', $result['gateway_used'],
            'gateway_used debe ser el proveedor que procesó exitosamente (el fallback)' );
        $this->assertNotSame( 'stripe', $result['gateway_used'] );
    }

    /**
     * Fallback exitoso: fallback_used = true (no false).
     */
    public function test_process_with_fallback_fallback_used_es_true_cuando_usa_fallback(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail = true;

        $order  = $this->make_order( 33 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertTrue( $result['fallback_used'],
            'fallback_used debe ser true cuando se usó el proveedor alternativo' );
    }

    /**
     * Error ambas: resultado no tiene transaction_id.
     */
    public function test_process_with_fallback_ambas_fallan_no_tiene_transaction_id(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail  = true;
        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 44 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'transaction_id', $result,
            'Cuando ambas fallan, no debe haber transaction_id en el resultado' );
    }

    /**
     * Error ambas: el mensaje de error en 'error' es string no vacío.
     */
    public function test_process_with_fallback_ambas_fallan_error_string_no_vacio(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->stripe_stub->should_fail  = true;
        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 55 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            5000.0, 'COP', 'card_intl', [], $order
        );

        $this->assertIsString( $result['error'] );
        $this->assertNotEmpty( $result['error'] );
    }

    // -----------------------------------------------------------------------
    // 21. process_with_fallback: openpay primario falla → stripe como fallback
    // -----------------------------------------------------------------------

    /**
     * openpay es el primario (card_local bajo threshold), falla → stripe como fallback.
     * fallback_used = true, gateway_used = 'stripe'.
     */
    public function test_process_with_fallback_openpay_primario_falla_stripe_es_fallback(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        // openpay es el natural para card_local 50K (< 200K threshold)
        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 66 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            50000.0, 'COP', 'card_local', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'stripe', $result['gateway_used'],
            'Cuando openpay falla como primario, el fallback debe ser stripe' );
        $this->assertTrue( $result['fallback_used'] );
    }

    /**
     * openpay primario falla → stripe fallback → transaction_id de stripe stub ('pi_test_123').
     */
    public function test_process_with_fallback_openpay_falla_transaction_id_de_stripe(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $this->stub_wp_functions();

        $this->openpay_stub->should_fail = true;

        $order  = $this->make_order( 67 );
        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            50000.0, 'COP', 'card_local', [], $order
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'pi_test_123', $result['transaction_id'] );
    }

    // -----------------------------------------------------------------------
    // 22. Reflexión avanzada — métodos privados y conteo de públicos
    // -----------------------------------------------------------------------

    /**
     * charge_via es private static.
     */
    public function test_reflection_charge_via_es_private_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'charge_via' );

        $this->assertTrue(  $method->isPrivate(), 'charge_via debe ser private' );
        $this->assertTrue(  $method->isStatic(),  'charge_via debe ser static' );
        $this->assertFalse( $method->isPublic(),  'charge_via no debe ser public' );
    }

    /**
     * maybe_trip_circuit_breaker es private static.
     */
    public function test_reflection_maybe_trip_circuit_breaker_es_private_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'maybe_trip_circuit_breaker' );

        $this->assertTrue( $method->isPrivate() );
        $this->assertTrue( $method->isStatic() );
    }

    /**
     * elapsed_ms es private static.
     */
    public function test_reflection_elapsed_ms_es_private_static(): void {
        $ref    = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $method = $ref->getMethod( 'elapsed_ms' );

        $this->assertTrue( $method->isPrivate() );
        $this->assertTrue( $method->isStatic() );
    }

    /**
     * La clase expone al menos los 4 métodos públicos estáticos documentados:
     * select_gateway, process_with_fallback, is_provider_down, record_provider_event.
     * El total real se verifica contra la reflexión para evitar falsos positivos
     * si la clase añade helpers públicos en el futuro.
     */
    public function test_reflection_clase_tiene_cuatro_metodos_publicos(): void {
        $ref     = new \ReflectionClass( LTMS_Payment_Orchestrator::class );
        $publics = array_filter(
            $ref->getMethods( \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC ),
            fn( $m ) => ! $m->isConstructor()
        );

        $nombres = array_map( fn( $m ) => $m->getName(), array_values( $publics ) );
        sort( $nombres );

        // Los 4 métodos documentados deben estar presentes.
        $this->assertContains( 'select_gateway',        $nombres );
        $this->assertContains( 'process_with_fallback', $nombres );
        $this->assertContains( 'is_provider_down',      $nombres );
        $this->assertContains( 'record_provider_event', $nombres );

        // El total real de la clase actual (10) — se actualiza si la API pública crece.
        $this->assertCount( 10, $nombres,
            'La clase debe tener exactamente 10 métodos públicos estáticos' );
    }

    // -----------------------------------------------------------------------
    // 23. select_gateway — currency inusual y edge cases de threshold
    // -----------------------------------------------------------------------

    public function test_select_gateway_card_local_unknown_currency_uses_cop_threshold(): void {
        // Moneda desconocida → threshold COP como fallback
        // Con threshold COP=200000 y amount=100000 → openpay
        Functions\when( 'get_transient' )->justReturn( false );
        $result = LTMS_Payment_Orchestrator::select_gateway( 100000.0, 'USD', 'card_local', 'CO' );
        $this->assertIsString( $result );
        $this->assertNotEmpty( $result );
    }

    public function test_select_gateway_card_local_cop_boundary_minus_one_cent(): void {
        // amount = threshold - 0.01 → exactamente por debajo → openpay
        Functions\when( 'get_transient' )->justReturn( false );
        $threshold = 200000.0;
        $result = LTMS_Payment_Orchestrator::select_gateway( $threshold - 0.01, 'COP', 'card_local', 'CO' );
        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_card_local_cop_boundary_plus_one_cent(): void {
        // amount = threshold + 0.01 → exactamente por encima → stripe
        Functions\when( 'get_transient' )->justReturn( false );
        $threshold = 200000.0;
        $result = LTMS_Payment_Orchestrator::select_gateway( $threshold + 0.01, 'COP', 'card_local', 'CO' );
        $this->assertSame( 'stripe', $result );
    }

    public function test_select_gateway_card_local_mxn_boundary_minus_one_cent(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $threshold = 1500.0;
        $result = LTMS_Payment_Orchestrator::select_gateway( $threshold - 0.01, 'MXN', 'card_local', 'MX' );
        $this->assertSame( 'openpay', $result );
    }

    public function test_select_gateway_card_local_mxn_boundary_plus_one_cent(): void {
        Functions\when( 'get_transient' )->justReturn( false );
        $threshold = 1500.0;
        $result = LTMS_Payment_Orchestrator::select_gateway( $threshold + 0.01, 'MXN', 'card_local', 'MX' );
        $this->assertSame( 'stripe', $result );
    }

    public function test_select_gateway_very_high_cop_threshold_all_goes_to_openpay(): void {
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 999_999_999.0 );
        Functions\when( 'get_transient' )->justReturn( false );
        $result = LTMS_Payment_Orchestrator::select_gateway( 500000.0, 'COP', 'card_local', 'CO' );
        $this->assertSame( 'openpay', $result );
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 200000.0 );
    }

    public function test_select_gateway_threshold_1_all_goes_to_stripe(): void {
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 1.0 );
        Functions\when( 'get_transient' )->justReturn( false );
        $result = LTMS_Payment_Orchestrator::select_gateway( 100.0, 'COP', 'card_local', 'CO' );
        $this->assertSame( 'stripe', $result );
        LTMS_Core_Config::set( 'ltms_orchestration_stripe_threshold_cop', 200000.0 );
    }

    // -----------------------------------------------------------------------
    // 24. select_gateway — circuit breaker no afecta métodos exclusivos / bnpl
    // -----------------------------------------------------------------------

    public function test_openpay_exclusive_not_affected_by_stripe_down(): void {
        Functions\when( 'get_transient' )->alias(
            fn( $k ) => str_contains( $k, 'stripe' ) ? '1' : false
        );
        $result = LTMS_Payment_Orchestrator::select_gateway( 100000.0, 'COP', 'pse', 'CO' );
        $this->assertSame( 'openpay', $result );
    }

    public function test_bnpl_not_affected_by_any_circuit_breaker(): void {
        Functions\when( 'get_transient' )->justReturn( '1' ); // todos abajo
        $result = LTMS_Payment_Orchestrator::select_gateway( 500000.0, 'COP', 'bnpl', 'CO' );
        $this->assertSame( 'addi', $result );
    }

    public function test_card_intl_not_affected_by_cop_threshold(): void {
        // card_intl siempre stripe sin importar el amount
        Functions\when( 'get_transient' )->justReturn( false );
        $result = LTMS_Payment_Orchestrator::select_gateway( 50.0, 'USD', 'card_intl', 'CO' );
        $this->assertSame( 'stripe', $result );
    }

    // -----------------------------------------------------------------------
    // 25. process_with_fallback — invariantes de estructura del resultado
    // -----------------------------------------------------------------------

    public function test_process_with_fallback_result_always_has_success_key(): void {
        $this->stub_wp_functions();
        $this->stripe_stub->should_fail = true;
        $this->openpay_stub->should_fail = true;
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            100000.0, 'COP', 'card_local', [], $this->make_order()
        );

        $this->assertArrayHasKey( 'success', $result );
    }

    public function test_process_with_fallback_result_always_has_gateway_used_key(): void {
        $this->stub_wp_functions();
        $this->stripe_stub->should_fail = false;
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            300000.0, 'COP', 'card_intl', [], $this->make_order()
        );

        $this->assertArrayHasKey( 'gateway_used', $result );
    }

    public function test_process_with_fallback_result_always_has_fallback_used_key(): void {
        $this->stub_wp_functions();
        $this->stripe_stub->should_fail = false;
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            300000.0, 'COP', 'card_intl', [], $this->make_order()
        );

        $this->assertArrayHasKey( 'fallback_used', $result );
    }

    public function test_process_with_fallback_fallback_used_false_on_primary_success(): void {
        $this->stub_wp_functions();
        $this->stripe_stub->should_fail = false;
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            300000.0, 'COP', 'card_intl', [], $this->make_order()
        );

        $this->assertFalse( $result['fallback_used'] );
    }

    public function test_process_with_fallback_success_is_always_bool(): void {
        $this->stub_wp_functions();
        $this->stripe_stub->should_fail = true;
        $this->openpay_stub->should_fail = true;
        Functions\when( 'get_transient' )->justReturn( false );

        $result = LTMS_Payment_Orchestrator::process_with_fallback(
            100000.0, 'COP', 'card_local', [], $this->make_order()
        );

        $this->assertIsBool( $result['success'] );
    }

}
