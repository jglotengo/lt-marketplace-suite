<?php
/**
 * SiigoApiTest — Tests unitarios para LTMS_Api_Siigo
 *
 * Cubre la lógica pura sin HTTP real:
 *   1. Constructor — excepciones si faltan credenciales
 *   2. build_invoice_payload() — estructura del payload de factura desde WC_Order
 *   3. authenticate() — manejo de errores de red y respuestas sin token
 *   4. ensure_authenticated() — llama authenticate() cuando no hay token
 *   5. Reflection — clase final, métodos privados
 *
 * Los métodos que llaman a wp_remote_post/perform_request se cubren en integración.
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
 * @covers LTMS_Api_Siigo
 */
class SiigoApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn(string $s): string => $s,
            'sanitize_email'      => static fn(string $s): string => $s,
            'get_option'          => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'       => static fn(): bool => true,
            'get_transient'       => static fn(): mixed => false,
            'set_transient'       => static fn(): bool => true,
            'delete_transient'    => static fn(): bool => true,
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

    private function set_credentials(): void
    {
        \LTMS_Core_Config::set('ltms_siigo_username',   \LTMS_Core_Security::encrypt('testuser@empresa.com'));
        \LTMS_Core_Config::set('ltms_siigo_access_key', \LTMS_Core_Security::encrypt('TestAccesKey123'));
    }

    private function make_client(): \LTMS_Api_Siigo
    {
        $this->set_credentials();
        return new \LTMS_Api_Siigo();
    }

    /**
     * Builds a minimal WC_Order stub with items.
     */
    private function make_order( float $total = 119000.0, array $items = [] ): object
    {
        $default_items = [
            (object)[
                'name'       => 'Producto Test',
                'product_id' => 42,
                'quantity'   => 2,
                'total'      => 100000.0,
                'product'    => new class { public function get_sku(): string { return 'SKU-001'; } },
            ],
        ];

        $items = $items ?: $default_items;

        return new class($total, $items) extends \WC_Order {
            private float  $total;
            private array  $items_list;
            private string $order_number = '1001';

            public function __construct(float $total, array $items) {
                $this->total      = $total;
                $this->items_list = $items;
            }

            public function get_total(): float            { return $this->total; }
            public function get_order_number(): string    { return (string) $this->order_number; }
            public function get_shipping_total(): float   { return 0.0; }

            public function get_items(): array {
                return array_map(fn($raw) => new class($raw) {
                    private object $raw;
                    public function __construct(object $r) { $this->raw = $r; }
                    public function get_name(): string     { return $this->raw->name; }
                    public function get_product_id(): int  { return $this->raw->product_id; }
                    public function get_quantity(): int    { return $this->raw->quantity; }
                    public function get_total(): float     { return $this->raw->total; }
                    public function get_product(): ?object {
                        return $this->raw->product ?? null;
                    }
                }, $this->items_list);
            }
        };
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_throws_when_no_username(): void
    {
        \LTMS_Core_Config::set('ltms_siigo_access_key', 'key');
        // No username

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/credenciales/i');

        new \LTMS_Api_Siigo();
    }

    /**
     * @test
     */
    public function test_constructor_throws_when_no_access_key(): void
    {
        \LTMS_Core_Config::set('ltms_siigo_username', 'user@test.com');
        // No access_key

        $this->expectException(\RuntimeException::class);

        new \LTMS_Api_Siigo();
    }

    /**
     * @test
     */
    public function test_constructor_succeeds_with_credentials(): void
    {
        $client = $this->make_client();
        $this->assertInstanceOf(\LTMS_Api_Siigo::class, $client);
    }

    /**
     * @test
     */
    public function test_provider_slug_is_siigo(): void
    {
        $client = $this->make_client();
        $this->assertSame('siigo', $client->get_provider_slug());
    }

    // ── Section 2: build_invoice_payload() ────────────────────────────────────

    /**
     * @test
     */
    public function test_build_invoice_payload_returns_required_keys(): void
    {
        $client   = $this->make_client();
        $order    = $this->make_order();
        $customer = ['id' => 789];

        $payload = $client->build_invoice_payload($order, $customer, []);

        $this->assertArrayHasKey('document',     $payload);
        $this->assertArrayHasKey('date',         $payload);
        $this->assertArrayHasKey('customer',     $payload);
        $this->assertArrayHasKey('items',        $payload);
        $this->assertArrayHasKey('payments',     $payload);
        $this->assertArrayHasKey('observations', $payload);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_customer_id_is_set(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order();
        $payload = $client->build_invoice_payload($order, ['id' => 42], []);

        $this->assertSame(42, $payload['customer']['id']);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_date_is_today(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order();
        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertSame(gmdate('Y-m-d'), $payload['date']);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_items_built_from_order_items(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order(119000.0, [
            (object)[
                'name'       => 'Camisa',
                'product_id' => 10,
                'quantity'   => 3,
                'total'      => 90000.0,
                'product'    => new class { public function get_sku(): string { return 'CAM-001'; } },
            ],
        ]);

        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertCount(1, $payload['items']);
        $item = $payload['items'][0];
        $this->assertSame('CAM-001', $item['code']);
        $this->assertSame('Camisa',  $item['description']);
        $this->assertSame(3,         $item['quantity']);
        $this->assertEqualsWithDelta(30000.0, $item['price'], 0.01);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_uses_product_id_as_code_when_no_sku(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order(50000.0, [
            (object)[
                'name'       => 'Sin SKU',
                'product_id' => 99,
                'quantity'   => 1,
                'total'      => 50000.0,
                'product'    => null, // null product → uses 'LTMS-PROD-{product_id}'
            ],
        ]);

        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertStringContainsString('99', $payload['items'][0]['code']);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_null_product_uses_fallback_code(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order(50000.0, [
            (object)[
                'name'       => 'Sin Producto',
                'product_id' => 55,
                'quantity'   => 1,
                'total'      => 50000.0,
                'product'    => null,
            ],
        ]);

        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        // Falls back to "LTMS-PROD-{product_id}" or "SRV-001"
        $code = $payload['items'][0]['code'];
        $this->assertNotEmpty($code);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_adds_shipping_item_when_nonzero(): void
    {
        $client = $this->make_client();

        $order = new class extends \WC_Order {
            public function get_total(): float          { return 130000.0; }
            public function get_order_number(): string  { return '2001'; }
            public function get_shipping_total(): float { return 10000.0; }
            public function get_items(): array {
                return [new class {
                    public function get_name(): string     { return 'Prod'; }
                    public function get_product_id(): int  { return 1; }
                    public function get_quantity(): int    { return 1; }
                    public function get_total(): float     { return 120000.0; }
                    public function get_product(): ?object { return null; }
                }];
            }
        };

        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        // Should have 2 items: product + shipping
        $this->assertCount(2, $payload['items']);
        $shipping_item = $payload['items'][1];
        $this->assertSame('FLETE-001', $shipping_item['code']);
        $this->assertEqualsWithDelta(10000.0, $shipping_item['price'], 0.01);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_no_shipping_item_when_zero(): void
    {
        $client = $this->make_client();
        $order  = $this->make_order(); // shipping = 0.0

        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        // Only product items, no shipping
        foreach ($payload['items'] as $item) {
            $this->assertNotSame('FLETE-001', $item['code']);
        }
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_payment_total_matches_order_total(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order(119000.0);
        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertNotEmpty($payload['payments']);
        $this->assertEqualsWithDelta(119000.0, $payload['payments'][0]['value'], 0.01);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_observations_contains_order_number(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order();
        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertStringContainsString('1001', $payload['observations']);
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_stamp_send_is_true(): void
    {
        $client  = $this->make_client();
        $order   = $this->make_order();
        $payload = $client->build_invoice_payload($order, ['id' => 1], []);

        $this->assertTrue($payload['stamp']['send']);
    }

    // ── Section 3: authenticate() error handling ───────────────────────────────

    /**
     * @test
     */
    public function test_authenticate_throws_on_network_error(): void
    {
        $client = $this->make_client();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_error', 'Connection refused'));
        Functions\when('is_wp_error')->justReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $client->authenticate();
    }

    /**
     * @test
     */
    public function test_authenticate_throws_when_no_access_token_in_response(): void
    {
        $client = $this->make_client();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}', 'response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/token/i');

        $client->authenticate();
    }

    /**
     * @test
     */
    public function test_authenticate_returns_cached_token_from_transient(): void
    {
        $client = $this->make_client();

        Functions\when('get_transient')->justReturn('cached_jwt_token_xyz');

        $token = $client->authenticate();

        $this->assertSame('cached_jwt_token_xyz', $token);
    }

    /**
     * @test
     */
    public function test_authenticate_stores_token_in_transient_on_success(): void
    {
        $client = $this->make_client();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            '{"access_token":"new_jwt_abc","expires_in":3600}'
        );

        $stored_key   = null;
        $stored_value = null;
        Functions\when('set_transient')->alias(
            static function(string $key, mixed $value, int $ttl) use (&$stored_key, &$stored_value): bool {
                $stored_key   = $key;
                $stored_value = $value;
                return true;
            }
        );

        $token = $client->authenticate();

        $this->assertSame('new_jwt_abc', $token);
        $this->assertStringStartsWith('ltms_siigo_token_', $stored_key);
        $this->assertSame('new_jwt_abc', $stored_value);
    }

    // ── Section 4: Reflection ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Siigo::class);
        $this->assertTrue($ref->isFinal());
    }

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Siigo::class);
        $this->assertSame('LTMS_Abstract_API_Client', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_ensure_authenticated_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Siigo::class, 'ensure_authenticated');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_build_invoice_payload_is_public(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Siigo::class, 'build_invoice_payload');
        $this->assertTrue($ref->isPublic());
    }
}
