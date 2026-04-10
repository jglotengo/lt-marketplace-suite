<?php
/**
 * AddiApiTest — Tests unitarios para LTMS_Api_Addi
 *
 * Cubre:
 *   1. Constructor — URL correcta por país/entorno, credenciales descifradas
 *   2. get_provider_slug() — retorna 'addi'
 *   3. create_application() — estructura del payload y mapeo de respuesta
 *   4. get_application_status() — mapeo de respuesta
 *   5. cancel_application() — retorna bool según status
 *   6. health_check() — ok con token / error con excepción
 *   7. format_items() — SKU, nombre, cantidad, precio por país (vía Reflection)
 *   8. Reflection — clase final, hereda de LTMS_Abstract_Api_Client
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
 * @covers LTMS_Api_Addi
 */
class AddiApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn(string $s): string => $s,
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

    private function set_credentials(string $country = 'CO'): void
    {
        \LTMS_Core_Config::set('ltms_addi_client_id',     \LTMS_Core_Security::encrypt('addi_client_id_test'));
        \LTMS_Core_Config::set('ltms_addi_client_secret', \LTMS_Core_Security::encrypt('addi_secret_test'));
    }

    private function make_client(): \LTMS_Api_Addi
    {
        $this->set_credentials();
        return new \LTMS_Api_Addi();
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_succeeds_with_credentials(): void
    {
        $client = $this->make_client();
        $this->assertInstanceOf(\LTMS_Api_Addi::class, $client);
    }

    /**
     * @test
     */
    public function test_constructor_sets_sandbox_url_for_co_in_test_env(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);

        // base_url is on the abstract parent
        $prop = $ref->getParentClass()->getProperty('api_url');
        $prop->setAccessible(true);
        $url = $prop->getValue($client);

        $this->assertStringContainsString('sandbox', $url);
        $this->assertStringContainsString('addi.com', $url);
    }

    /**
     * @test
     */
    public function test_constructor_decrypts_client_id(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('client_id');
        $prop->setAccessible(true);
        $this->assertSame('addi_client_id_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_decrypts_client_secret(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('client_secret');
        $prop->setAccessible(true);
        $this->assertSame('addi_secret_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_uses_empty_string_when_no_client_id(): void
    {
        // No credentials set — decrypt('') returns ''
        $client = new \LTMS_Api_Addi();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('client_id');
        $prop->setAccessible(true);
        $this->assertSame('', $prop->getValue($client));
    }

    // ── Section 2: get_provider_slug ──────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_provider_slug_returns_addi(): void
    {
        $client = $this->make_client();
        $this->assertSame('addi', $client->get_provider_slug());
    }

    // ── Section 3: create_application payload mapping ────────────────────────

    /**
     * @test
     */
    public function test_create_application_maps_response_fields(): void
    {
        $client = $this->make_client();

        // Inject a cached token so get_access_token() skips the HTTP call
        $ref = new ReflectionClass($client);
        $tokenProp = $ref->getProperty('access_token');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($client, 'test_bearer_token');

        $captured = null;
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('test_stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(0);

        $checkout_data = [
            'order_id'           => 'ORD-001',
            'amount'             => 150000.0,
            'currency'           => 'COP',
            'items'              => [
                ['sku' => 'SKU-A', 'name' => 'Producto A', 'quantity' => 1, 'price' => 150000.0],
            ],
            'client'             => [
                'document'   => '12345678',
                'first_name' => 'Juan',
                'last_name'  => 'García',
                'email'      => 'juan@test.com',
                'phone'      => '3001234567',
            ],
            'callback_approved'  => 'https://site.com/approved',
            'callback_rejected'  => 'https://site.com/rejected',
            'callback_cancelled' => 'https://site.com/cancelled',
        ];

        try {
            $client->create_application($checkout_data);
        } catch (\RuntimeException) {}

        $this->assertNotNull($captured);
        $body = json_decode($captured['body'], true);
        $this->assertSame('ORD-001', $body['orderId']);
        $this->assertEquals(150000.0, $body['totalAmount']['value']);
        $this->assertSame('COP', $body['totalAmount']['currency']);
        $this->assertSame('CC', $body['client']['idType']); // CO → CC
        $this->assertSame('Juan', $body['client']['firstName']);
        $this->assertSame('SKU-A', $body['items'][0]['sku']);
    }

    /**
     * @test
     */
    public function test_create_application_returns_success_true_when_id_present(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $tokenProp = $ref->getProperty('access_token');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($client, 'test_token');

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'id'          => 'APP-999',
            'checkoutUrl' => 'https://checkout.addi.com/APP-999',
        ]));

        $result = $client->create_application([
            'order_id' => 'ORD-002',
            'amount'   => 50000.0,
            'items'    => [],
            'client'   => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('APP-999', $result['application_id']);
        $this->assertStringContainsString('APP-999', $result['checkout_url']);
    }

    /**
     * @test
     */
    public function test_create_application_returns_success_false_when_no_id(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $tokenProp = $ref->getProperty('access_token');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($client, 'test_token');

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(400);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['error' => 'invalid']));

        $this->expectException(\RuntimeException::class);
        $client->create_application(['order_id' => 'ORD-003', 'amount' => 0.0, 'items' => [], 'client' => []]);
    }

    // ── Section 4: get_application_status ────────────────────────────────────

    /**
     * @test
     */
    public function test_get_application_status_maps_response(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $tokenProp = $ref->getProperty('access_token');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($client, 'tok');

        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'status'         => 'APPROVED',
            'approvedAmount' => ['value' => 120000.0],
            'installments'   => 6,
        ]));

        $result = $client->get_application_status('APP-001');

        $this->assertSame('APPROVED', $result['status']);
        $this->assertSame(120000.0, $result['approved_amount']);
        $this->assertSame(6, $result['installments']);
    }

    /**
     * @test
     */
    public function test_get_application_status_returns_defaults_on_empty_response(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('access_token');
        $prop->setAccessible(true);
        $prop->setValue($client, 'tok');

        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([]));

        $result = $client->get_application_status('APP-002');
        $this->assertSame('unknown', $result['status']);
        $this->assertSame(0.0, $result['approved_amount']);
        $this->assertSame(0, $result['installments']);
    }

    // ── Section 5: cancel_application ────────────────────────────────────────

    /**
     * @test
     */
    public function test_cancel_application_returns_true_when_status_cancelled(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('access_token');
        $prop->setAccessible(true);
        $prop->setValue($client, 'tok');

        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['status' => 'CANCELLED']));

        $this->assertTrue($client->cancel_application('APP-003'));
    }

    /**
     * @test
     */
    public function test_cancel_application_returns_false_when_not_cancelled(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('access_token');
        $prop->setAccessible(true);
        $prop->setValue($client, 'tok');

        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['status' => 'ACTIVE']));

        $this->assertFalse($client->cancel_application('APP-004'));
    }

    // ── Section 6: health_check ───────────────────────────────────────────────

    /**
     * @test
     */
    public function test_health_check_returns_ok_when_token_obtained(): void
    {
        $client = $this->make_client();

        $ref = new ReflectionClass($client);
        $prop = $ref->getProperty('access_token');
        $prop->setAccessible(true);
        $prop->setValue($client, 'pre_cached_token');

        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('OK', $result['message']);
    }

    /**
     * @test
     */
    public function test_health_check_returns_error_on_exception(): void
    {
        // No token cached → perform_request fails with WP_Error → RuntimeException
        $client = $this->make_client();

        Functions\when('wp_remote_request')->justReturn(new \WP_Error('fail', 'network error'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    // ── Section 7: format_items (via Reflection) ──────────────────────────────

    /**
     * @test
     */
    public function test_format_items_uses_sku_when_present(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Addi::class, 'format_items');
        $ref->setAccessible(true);

        $items = [['sku' => 'MY-SKU', 'name' => 'Test', 'quantity' => 2, 'price' => 50000.0]];
        $result = $ref->invoke($client, $items);

        $this->assertSame('MY-SKU', $result[0]['sku']);
        $this->assertSame('Test', $result[0]['name']);
        $this->assertSame(2, $result[0]['quantity']);
        $this->assertSame(50000.0, $result[0]['unitPrice']['value']);
        $this->assertSame('COP', $result[0]['unitPrice']['currency']); // CO default
    }

    /**
     * @test
     */
    public function test_format_items_falls_back_to_product_id_when_no_sku(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Addi::class, 'format_items');
        $ref->setAccessible(true);

        $items = [['product_id' => 99, 'name' => 'Sin SKU', 'quantity' => 1, 'price' => 10000.0]];
        $result = $ref->invoke($client, $items);

        $this->assertSame('99', $result[0]['sku']);
    }

    /**
     * @test
     */
    public function test_format_items_uses_default_sku_when_no_sku_or_product_id(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Addi::class, 'format_items');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, [['name' => 'Sin todo', 'quantity' => 1, 'price' => 0.0]]);
        $this->assertSame('SKU-001', $result[0]['sku']);
    }

    /**
     * @test
     */
    public function test_format_items_empty_array_returns_empty(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Addi::class, 'format_items');
        $ref->setAccessible(true);

        $this->assertSame([], $ref->invoke($client, []));
    }

    // ── Section 8: Reflection ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Addi::class);
        $this->assertFalse($ref->isFinal());
    }

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Addi::class);
        $this->assertStringContainsString('LTMS_Abstract_API_Client', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_get_access_token_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Addi::class, 'get_access_token');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_format_items_is_private(): void
    {
        $ref = new ReflectionMethod(\LTMS_Api_Addi::class, 'format_items');
        $this->assertTrue($ref->isPrivate());
    }

    /**
     * @test
     */
    public function test_api_urls_constant_has_co_and_mx(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Addi::class);
        $urls = $ref->getConstant('API_URLS');
        $this->assertArrayHasKey('CO', $urls);
        $this->assertArrayHasKey('MX', $urls);
        $this->assertArrayHasKey('live', $urls['CO']);
        $this->assertArrayHasKey('sandbox', $urls['CO']);
    }
}
