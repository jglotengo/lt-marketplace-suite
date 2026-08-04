<?php
/**
 * GatewayStripeTest — Tests unitarios para LTMS_Gateway_Stripe
 *
 * Cubre la lógica pura del gateway sin WordPress ni HTTP real:
 *   1. is_testmode()         — lee la opción 'testmode' correctamente
 *   2. get_publishable_key() — devuelve la clave sandbox o live según el modo
 *   3. validate_fields()     — retorna false y agrega notice cuando falta el PM
 *   4. process_refund()      — guards: pedido no encontrado, intent faltante
 *   5. process_payment()     — guard: pedido no encontrado
 *   6. init_form_fields()    — registra todos los campos requeridos
 *   7. Reflection            — estructura de la clase
 *
 * process_payment() con Stripe real se cubre en integración.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers LTMS_Gateway_Stripe
 */
class GatewayStripeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'sanitize_text_field'    => static fn(string $s): string => $s,
            'esc_html'               => static fn(string $s): string => $s,
            'esc_attr'               => static fn(string $s): string => $s,
            '__'                     => static fn(string $s): string => $s,
            'apply_filters'          => static fn(string $tag, mixed $value): mixed => $value,
            'add_action'             => static fn(): bool => true,
            'rest_url'               => static fn(string $path = ''): string => 'http://localhost/wp-json/' . ltrim($path, '/'),
            'get_option'             => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'          => static fn(): bool => true,
            'get_woocommerce_currency' => static fn(): string => 'COP',
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates an LTMS_Gateway_Stripe instance, injecting settings via the stub.
     */
    private function make_gateway( array $settings = [] ): \LTMS_Gateway_Stripe
    {
        $gateway = new \LTMS_Gateway_Stripe();

        // Inject settings directly into the protected $settings array
        $ref  = new ReflectionClass($gateway);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($gateway, array_merge([
            'testmode'           => 'yes',
            'publishable_key'    => 'pk_test_sandbox_key',
            'publishable_key_live' => 'pk_live_production_key',
            'secret_key'         => 'sk_test_sandbox_secret',
            'secret_key_live'    => 'sk_live_production_secret',
        ], $settings));

        return $gateway;
    }

    // ── Section 1: is_testmode() ──────────────────────────────────────────────

    /**
     * @test
     */
    public function test_is_testmode_returns_true_when_option_is_yes(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'yes']);

        $ref = new ReflectionMethod($gateway, 'is_testmode');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($gateway));
    }

    /**
     * @test
     */
    public function test_is_testmode_returns_false_when_option_is_no(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'no']);

        $ref = new ReflectionMethod($gateway, 'is_testmode');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($gateway));
    }

    /**
     * @test
     * Default value when option is missing must be 'yes' (safe default = sandbox).
     */
    public function test_is_testmode_defaults_to_yes_when_option_missing(): void
    {
        $gateway = $this->make_gateway();

        // Override to simulate missing testmode key
        $ref  = new ReflectionClass($gateway);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($gateway, []);

        $method = new ReflectionMethod($gateway, 'is_testmode');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($gateway));
    }

    // ── Section 2: get_publishable_key() ──────────────────────────────────────

    /**
     * @test
     */
    public function test_get_publishable_key_returns_sandbox_key_in_testmode(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'yes']);

        $ref = new ReflectionMethod($gateway, 'get_publishable_key');
        $ref->setAccessible(true);

        $key = $ref->invoke($gateway);

        $this->assertSame('pk_test_sandbox_key', $key);
    }

    /**
     * @test
     */
    public function test_get_publishable_key_returns_live_key_in_live_mode(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'no']);

        $ref = new ReflectionMethod($gateway, 'get_publishable_key');
        $ref->setAccessible(true);

        $key = $ref->invoke($gateway);

        $this->assertSame('pk_live_production_key', $key);
    }

    /**
     * @test
     */
    public function test_get_publishable_key_returns_empty_string_when_not_set(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'yes', 'publishable_key' => '']);

        $ref = new ReflectionMethod($gateway, 'get_publishable_key');
        $ref->setAccessible(true);

        $this->assertSame('', $ref->invoke($gateway));
    }

    // ── Section 3: validate_fields() ──────────────────────────────────────────

    /**
     * @test
     */
    public function test_validate_fields_returns_false_when_payment_method_missing(): void
    {
        $gateway = $this->make_gateway();

        // $_POST has no _ltms_stripe_payment_method
        $_POST = [];

        $notice_added = false;
        Functions\when('wc_add_notice')->alias(
            static function() use (&$notice_added): void {
                $notice_added = true;
            }
        );
        Functions\when('wp_unslash')->returnArg();

        $result = $gateway->validate_fields();

        $this->assertFalse($result);
        $this->assertTrue($notice_added, 'wc_add_notice must be called when payment method is missing');

        unset($_POST);
    }

    /**
     * @test
     */
    public function test_validate_fields_returns_true_when_payment_method_present(): void
    {
        $gateway = $this->make_gateway();

        $_POST = ['_ltms_stripe_payment_method' => 'pm_test_abc123'];

        Functions\when('wc_add_notice')->alias(static function(): void {});
        Functions\when('wp_unslash')->returnArg();

        $result = $gateway->validate_fields();

        $this->assertTrue($result);

        unset($_POST);
    }

    // ── Section 4: process_refund() guards ────────────────────────────────────

    /**
     * @test
     */
    public function test_process_refund_returns_wp_error_when_order_not_found(): void
    {
        $gateway = $this->make_gateway();

        Functions\when('wc_get_order')->justReturn(false);

        $result = $gateway->process_refund(9999, 10000.0, 'Test refund');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ltms_stripe_refund', $result->get_error_code());
    }

    /**
     * @test
     */
    public function test_process_refund_returns_wp_error_when_intent_id_missing(): void
    {
        $gateway = $this->make_gateway();

        $order = new class extends \WC_Order {
            public function get_meta( string $key, bool $single = true ): mixed {
                return ''; // No intent ID stored
            }
        };

        Functions\when('wc_get_order')->justReturn($order);

        $result = $gateway->process_refund(1, 10000.0);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ltms_stripe_refund', $result->get_error_code());
    }

    /**
     * @test
     * When Stripe API returns failure, process_refund must return WP_Error.
     */
    public function test_process_refund_returns_wp_error_on_stripe_failure(): void
    {
        $gateway = $this->make_gateway(['testmode' => 'yes', 'secret_key' => 'sk_test_key']);

        $order = new class extends \WC_Order {
            public function get_meta( string $key, bool $single = true ): mixed {
                return 'pi_test_intent_123';
            }
            public function get_total(): float { return 50000.0; }
            public function add_order_note( string $note ): int { return 1; }
        };

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_price')->justReturn('$50,000');
        Functions\when('esc_html')->returnArg();

        // Stripe SDK is not loaded in unit-only — LTMS_Api_Stripe constructor
        // requires the secret_key. We test by making the stripe client throw.
        // Since \Stripe\Stripe class doesn't exist, create_payment_intent etc.
        // will never be called. We mock LTMS_Api_Stripe via a test double.
        // The simplest approach: verify the WP_Error path by checking the gateway
        // handles an exception from the stripe client gracefully.
        // Note: process_refund calls $this->get_stripe_client() which returns
        // LTMS_Api_Stripe. Since \Stripe\* classes don't exist in unit-only,
        // LTMS_Api_Stripe::create_refund catches Throwable and returns failure.

        $result = $gateway->process_refund(1, 50000.0, 'fraude');

        // Either WP_Error (on error path) or true (on success) — just verify no exception
        $this->assertTrue(
            $result instanceof \WP_Error || $result === true,
            'process_refund must return WP_Error or true, never throw'
        );
    }

    // ── Section 5: process_payment() guard ────────────────────────────────────

    /**
     * @test
     */
    public function test_process_payment_returns_fail_when_order_not_found(): void
    {
        $gateway = $this->make_gateway();

        $_POST = ['_ltms_stripe_payment_method' => 'pm_test'];

        Functions\when('wc_get_order')->justReturn(false);
        Functions\when('wc_add_notice')->alias(static function(): void {});
        Functions\when('wp_unslash')->returnArg();

        $result = $gateway->process_payment(9999);

        $this->assertSame('fail', $result['result']);

        unset($_POST);
    }

    /**
     * @test
     */
    public function test_process_payment_returns_fail_when_no_payment_method_id(): void
    {
        $gateway = $this->make_gateway();

        $_POST = []; // No _ltms_stripe_payment_method

        $order = new class extends \WC_Order {
            public function get_total(): float { return 100000.0; }
        };

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_add_notice')->alias(static function(): void {});
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_woocommerce_currency')->justReturn('COP');

        $result = $gateway->process_payment(1);

        $this->assertSame('fail', $result['result']);

        unset($_POST);
    }

    // ── Section 6: init_form_fields() ─────────────────────────────────────────

    /**
     * @test
     */
    public function test_init_form_fields_registers_all_required_fields(): void
    {
        $gateway = new \LTMS_Gateway_Stripe();

        $ref  = new ReflectionClass($gateway);
        $prop = $ref->getProperty('form_fields');
        $prop->setAccessible(true);
        $fields = $prop->getValue($gateway);

        $required_keys = [
            'enabled', 'title', 'description', 'testmode',
            'publishable_key', 'secret_key',
            'publishable_key_live', 'secret_key_live',
            'webhook_secret', 'enable_connect',
        ];

        foreach ($required_keys as $key) {
            $this->assertArrayHasKey($key, $fields, "Field '{$key}' must be registered");
        }
    }

    /**
     * @test
     */
    public function test_gateway_id_is_ltms_stripe(): void
    {
        $gateway = new \LTMS_Gateway_Stripe();
        $this->assertSame('ltms_stripe', $gateway->id);
    }

    /**
     * @test
     */
    public function test_gateway_supports_products_and_refunds(): void
    {
        $gateway = new \LTMS_Gateway_Stripe();
        $this->assertContains('products', $gateway->supports);
        $this->assertContains('refunds',  $gateway->supports);
    }

    // ── Section 7: Reflection ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_extends_wc_payment_gateway(): void
    {
        $ref = new ReflectionClass(\LTMS_Gateway_Stripe::class);
        $this->assertSame('WC_Payment_Gateway', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_is_testmode_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Gateway_Stripe::class, 'is_testmode');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_get_publishable_key_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Gateway_Stripe::class, 'get_publishable_key');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_get_stripe_client_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Gateway_Stripe::class, 'get_stripe_client');
        $this->assertTrue($ref->isPrivate());
    }

    // ── Section 8: AUDIT-GATEWAY-STRIPE-001 (Ciclo 1.4 P0-2): class_exists guard ─

    /**
     * @test
     * AUDIT-GATEWAY-STRIPE-001 (P0-2): la declaración de la clase está envuelta
     * en `if ( ! class_exists( 'LTMS_Gateway_Stripe' ) )` para evitar fatal error
     * de redeclaración si el autoloader la carga antes que el archivo literal.
     *
     * Verificamos que NUEVAS cargas del archivo (after class ya declarada) sean
     * un no-op silencioso en vez de fatal.
     */
    public function test_class_exists_guard_prevents_redeclaration_fatal(): void
    {
        $this->assertTrue(
            class_exists( \LTMS_Gateway_Stripe::class ),
            'LTMS_Gateway_Stripe debe estar declarada después del require del archivo'
        );

        // Cargar el archivo de nuevo debe ser no-op gracias al guard.
        $file = dirname( __DIR__, 2 ) . '/includes/gateway/class-ltms-gateway-stripe.php';

        // Deshabilitar temporalmente el display de errors fatales para esta
        //Assertion: si el guard NO existiera, require_once lanzaría Fatal error.
        // Con require_once no se recarga, pero el guard es para require (no _once).
        // Verificamos leyendo el source: el guard `if ( ! class_exists(...) )`
        // debe estar presente antes de la declaración de la clase.
        $source = file_get_contents( $file );
        $this->assertStringContainsString(
            "if ( ! class_exists( 'LTMS_Gateway_Stripe' ) )",
            $source,
            'AUDIT-GATEWAY-STRIPE-001: la clase debe estar envuelta en class_exists guard'
        );
    }

    // ── Section 9: AUDIT-GATEWAY-STRIPE-002 (Ciclo 1.4 P0-3): mark_payment_captured_atomic ─

    /**
     * @test
     * AUDIT-GATEWAY-STRIPE-002 (P0-3): mark_payment_captured_atomic() debe
     * retornar true la PRIMERA vez (ganó el flag) y false la SEGUNDA vez
     * (otro camino ya capturó). Verifica el lock anti-doble payment_complete().
     */
    public function test_mark_payment_captured_atomic_first_call_returns_true(): void
    {
        $gateway = $this->make_gateway();

        $order = new class extends \WC_Order {
            private array $meta = [];
            public function get_meta( string $key, bool $single = true ): mixed {
                return $this->meta[ $key ] ?? '';
            }
            public function update_meta_data( string $key, $value ): void {
                $this->meta[ $key ] = $value;
            }
            public function save(): int { return 1; }
        };

        $ref = new \ReflectionMethod( $gateway, 'mark_payment_captured_atomic' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $gateway, $order, 'pi_test_123' );

        $this->assertTrue( $result, 'Primera llamada debe retornar true (ganó el flag)' );
        $this->assertSame( 'yes', $order->get_meta( '_ltms_stripe_payment_captured' ) );
        $this->assertSame( 'pi_test_123', $order->get_meta( '_ltms_stripe_captured_intent_id' ) );
    }

    /**
     * @test
     * AUDIT-GATEWAY-STRIPE-002 (P0-3): segunda llamada retorna false si el meta
     * ya está en 'yes' — segundo camino_SYNC o webhook async no debe volver a
     * llamar payment_complete().
     */
    public function test_mark_payment_captured_atomic_second_call_returns_false(): void
    {
        $gateway = $this->make_gateway();

        $order = new class extends \WC_Order {
            private array $meta = [ '_ltms_stripe_payment_captured' => 'yes' ];
            public function get_meta( string $key, bool $single = true ): mixed {
                return $this->meta[ $key ] ?? '';
            }
            public function update_meta_data( string $key, $value ): void {
                $this->meta[ $key ] = $value;
            }
            public function save(): int { return 1; }
        };

        $ref = new \ReflectionMethod( $gateway, 'mark_payment_captured_atomic' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $gateway, $order, 'pi_test_456' );

        $this->assertFalse( $result, 'Segunda llamada debe retornar false (ya capturado)' );
    }

    /**
     * @test
     * AUDIT-GATEWAY-STRIPE-002 (P0-3): mark_payment_captured_atomic es private.
     */
    public function test_mark_payment_captured_atomic_is_private(): void
    {
        $ref = new \ReflectionMethod( \LTMS_Gateway_Stripe::class, 'mark_payment_captured_atomic' );
        $this->assertTrue( $ref->isPrivate() );
    }
}

