<?php
/**
 * OpenpayApiTest — Tests unitarios para LTMS_Api_Openpay
 *
 * Cubre la lógica pura sin HTTP real:
 *   1. Constructor — excepciones si faltan credenciales, URL según país/entorno
 *   2. format_amount() — COP sin decimales, MXN con 2 decimales
 *   3. create_pse_charge() guard — solo disponible en CO
 *   4. create_oxxo_charge() guard — solo disponible en MX
 *   5. perform_request() override — inyecta header Authorization: Basic
 *   6. Reflection — estructura de la clase
 *
 * Los métodos que llaman a wp_remote_request se cubren en integración.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers LTMS_Api_Openpay
 */
class OpenpayApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn(string $s): string => $s,
            'sanitize_email'      => static fn(string $s): string => $s,
            'esc_url_raw'         => static fn(string $s): string => $s,
            'get_option'          => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'       => static fn(): bool => true,
            'get_transient'       => static fn(): mixed => false,
            'set_transient'       => static fn(): bool => true,
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
     * Configures LTMS_Core_Config credentials for a given country.
     */
    private function set_credentials( string $country = 'CO' ): void
    {
        \LTMS_Core_Config::set( "ltms_openpay_{$country}_merchant_id", \LTMS_Core_Security::encrypt( 'merchant_123' ) );
        \LTMS_Core_Config::set( "ltms_openpay_{$country}_private_key", \LTMS_Core_Security::encrypt( 'sk_test_abc' ) );
    }

    /**
     * Builds an Openpay client for CO (default bootstrap country).
     */
    private function make_client(): \LTMS_Api_Openpay
    {
        $this->set_credentials('CO');
        return new \LTMS_Api_Openpay();
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_throws_when_no_merchant_id(): void
    {
        // Only set private key, not merchant_id
        \LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', 'sk_test' );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/credenciales/i');

        new \LTMS_Api_Openpay();
    }

    /**
     * @test
     */
    public function test_constructor_throws_when_no_private_key(): void
    {
        \LTMS_Core_Config::set( 'ltms_openpay_CO_merchant_id', 'mid' );
        // No private key

        $this->expectException(\RuntimeException::class);

        new \LTMS_Api_Openpay();
    }

    /**
     * @test
     */
    public function test_constructor_succeeds_with_credentials(): void
    {
        $client = $this->make_client();
        $this->assertInstanceOf(\LTMS_Api_Openpay::class, $client);
    }

    /**
     * @test
     */
    public function test_provider_slug_is_openpay(): void
    {
        $client = $this->make_client();
        $this->assertSame('openpay', $client->get_provider_slug());
    }

    /**
     * @test
     * In test environment (is_production=false), sandbox URL must be used for CO.
     */
    public function test_api_url_is_sandbox_for_co_in_test_env(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('api_url');
        $prop->setAccessible(true);

        $url = $prop->getValue($client);

        $this->assertStringContainsString('sandbox', $url);
        $this->assertStringContainsString('openpay.co', $url);
    }

    // ── Section 2: format_amount() ────────────────────────────────────────────

    /**
     * @test
     * COP amounts must be integers (no decimal places).
     */
    public function test_format_amount_cop_returns_integer(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'format_amount');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, 150000.0);

        $this->assertSame(150000, $result);
        $this->assertIsInt($result);
    }

    /**
     * @test
     * COP amounts are rounded to nearest integer.
     */
    public function test_format_amount_cop_rounds_to_nearest_int(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'format_amount');
        $ref->setAccessible(true);

        $this->assertSame(100, $ref->invoke($client, 99.6));
        $this->assertSame(99,  $ref->invoke($client, 99.4));
    }

    /**
     * @test
     * COP amount of zero returns 0 integer.
     */
    public function test_format_amount_cop_zero(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod($client, 'format_amount');
        $ref->setAccessible(true);

        $this->assertSame(0, $ref->invoke($client, 0.0));
    }

    /**
     * @test
     * MX client: MXN amounts use 2-decimal rounding.
     * Since LTMS_Api_Openpay is final, we override the private $country
     * property via Reflection so format_amount uses the MXN branch.
     */
    public function test_format_amount_mxn_returns_float_with_two_decimals(): void
    {
        $client = $this->make_client();

        // Override private $country to MX so format_amount uses MXN logic
        $ref  = new ReflectionClass($client);
        $prop = $ref->getProperty('country');
        $prop->setAccessible(true);
        $prop->setValue($client, 'MX');

        $method = new ReflectionMethod($client, 'format_amount');
        $method->setAccessible(true);

        $this->assertSame(199.99, $method->invoke($client, 199.994));
        $this->assertSame(200.0,  $method->invoke($client, 199.995));
        $this->assertSame(0.5,    $method->invoke($client, 0.499));
    }

    // ── Section 3: create_pse_charge() guard ──────────────────────────────────

    /**
     * @test
     * PSE is only available in Colombia — calling it in MX must throw.
     * We test by temporarily overriding the country property via Reflection.
     */
    public function test_create_pse_charge_throws_outside_colombia(): void
    {
        $client = $this->make_client();

        // Override the private $country property to MX
        $ref  = new ReflectionClass($client);
        $prop = $ref->getProperty('country');
        $prop->setAccessible(true);
        $prop->setValue($client, 'MX');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PSE solo disponible en Colombia/i');

        $client->create_pse_charge(
            100000.0,
            'Test PSE charge',
            ['name' => 'Test', 'email' => 'a@b.co', 'city' => 'Bogotá'],
            '1022',
            'https://example.com/return',
            'order_001'
        );
    }

    /**
     * @test
     * PSE with CO country does NOT throw on guard (may fail later on HTTP — that's OK).
     */
    public function test_create_pse_charge_does_not_throw_guard_for_colombia(): void
    {
        $client = $this->make_client();

        // Mock wp_remote_request to avoid actual HTTP
        Functions\when('wp_remote_request')->justReturn([
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{"id":"chg_123","status":"in_progress"}',
        ]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"id":"chg_123","status":"in_progress"}');
        Functions\when('is_wp_error')->justReturn(false);

        // Verify: no RuntimeException from the PSE country guard
        $result = $client->create_pse_charge(
            100000.0,
            'Test PSE',
            ['name' => 'Test', 'email' => 'a@b.co', 'city' => 'Bogotá'],
            '1022',
            'https://example.com/return',
            'order_001'
        );

        $this->assertIsArray($result);
    }

    // ── Section 4: create_oxxo_charge() guard ─────────────────────────────────

    /**
     * @test
     * OXXO is only available in Mexico — throws in CO.
     */
    public function test_create_oxxo_charge_throws_outside_mexico(): void
    {
        $client = $this->make_client(); // country = CO

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OXXO solo disponible en México/i');

        $client->create_oxxo_charge(
            500.0,
            'Test OXXO',
            ['name' => 'Test', 'email' => 'a@b.mx'],
            'order_002'
        );
    }

    /**
     * @test
     * OXXO with MX country skips guard without throwing.
     */
    public function test_create_oxxo_charge_does_not_throw_guard_for_mexico(): void
    {
        $client = $this->make_client();

        // Override country to MX
        $ref  = new ReflectionClass($client);
        $prop = $ref->getProperty('country');
        $prop->setAccessible(true);
        $prop->setValue($client, 'MX');

        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"id":"oxxo_ref_001","method":"store"}');
        Functions\when('is_wp_error')->justReturn(false);

        $result = $client->create_oxxo_charge(
            350.0,
            'Test OXXO MX',
            ['name' => 'Juan', 'email' => 'j@e.mx'],
            'order_003'
        );

        $this->assertIsArray($result);
    }

    // ── Section 5: perform_request() Basic Auth header ────────────────────────

    /**
     * @test
     * perform_request() override must inject Authorization: Basic header derived
     * from the private_key before delegating to the parent.
     */
    public function test_perform_request_injects_basic_auth_header(): void
    {
        $client = $this->make_client();

        $captured_args = null;
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_args): mixed {
                $captured_args = $args;
                // Simulate WP_Error to abort the actual request flow cleanly
                return new \WP_Error('test_stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            // Use get_charge to trigger perform_request
            $client->get_charge('chg_test_001');
        } catch (\RuntimeException $e) {
            // Expected — we just need to capture the args
        }

        $this->assertNotNull($captured_args, 'wp_remote_request must have been called');
        $this->assertArrayHasKey('Authorization', $captured_args['headers'] ?? []);
        $auth = $captured_args['headers']['Authorization'];
        $this->assertStringStartsWith('Basic ', $auth);

        // Verify the payload decodes to "sk_test_abc:"
        $decoded = base64_decode(substr($auth, 6));
        $this->assertStringEndsWith(':', $decoded);
        $this->assertStringContainsString('sk_test_abc', $decoded);
    }

    // ── Section 6: Reflection ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Openpay::class);
        $this->assertTrue($ref->isFinal());
    }

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Openpay::class);
        $this->assertSame('LTMS_Abstract_API_Client', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_format_amount_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Openpay::class, 'format_amount');
        $this->assertTrue($ref->isPrivate());
    }
}
