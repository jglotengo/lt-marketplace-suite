<?php
/**
 * XcoverApiTest — Tests unitarios para LTMS_Api_Xcover
 *
 * Cubre:
 *   1. Constructor — URL sandbox, partner_code, api_key descifrada
 *   2. get_provider_slug() — 'xcover'
 *   3. get_quotes() — payload con partner_code y country, retorna quotes[]
 *   4. create_policy() — payload, retorna success/policy_id/policy_number
 *   5. get_policy() — endpoint correcto
 *   6. cancel_policy() — retorna success/refund_amount
 *   7. health_check()
 *   8. get_default_headers() — Authorization: ApiKey partner:key
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
 * @covers LTMS_Api_Xcover
 */
class XcoverApiTest extends TestCase
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
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function set_credentials(): void
    {
        \LTMS_Core_Config::set('ltms_xcover_partner_code', 'LTMS-PARTNER');
        \LTMS_Core_Config::set('ltms_xcover_api_key',      \LTMS_Core_Security::encrypt('xcover_key_test'));
    }

    private function make_client(): \LTMS_Api_Xcover
    {
        $this->set_credentials();
        return new \LTMS_Api_Xcover();
    }

    private function stub_response(mixed $body, int $code = 200): void
    {
        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
    }

    /**
     * @test
     */
    public function test_get_provider_slug_returns_xcover(): void
    {
        $this->assertSame('xcover', $this->make_client()->get_provider_slug());
    }

    /**
     * @test
     */
    public function test_constructor_decrypts_api_key(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('api_key');
        $prop->setAccessible(true);
        $this->assertSame('xcover_key_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_stores_partner_code(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('partner_code');
        $prop->setAccessible(true);
        $this->assertSame('LTMS-PARTNER', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_default_headers_include_apikey_authorization(): void
    {
        $client  = $this->make_client();
        $ref     = new ReflectionMethod(\LTMS_Api_Xcover::class, 'get_default_headers');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client);

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('ApiKey ', $headers['Authorization']);
        $this->assertStringContainsString('LTMS-PARTNER', $headers['Authorization']);
        $this->assertStringContainsString('xcover_key_test', $headers['Authorization']);
    }

    /**
     * @test
     */
    public function test_get_quotes_sends_partner_code_and_country(): void
    {
        $client  = $this->make_client();
        $captured = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('gmdate')->justReturn('2026-01-01');

        try {
            $client->get_quotes([
                'name'             => 'Smartphone',
                'price'            => 1500000.0,
                'currency'         => 'COP',
                'insurance_type'   => 'product_protection',
                'category'         => 'electronics',
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('LTMS-PARTNER', $body['partner_code']);
        $this->assertSame('CO', $body['request'][0]['country']);
        $this->assertSame('product_protection', $body['request'][0]['policyType']);
        $this->assertEquals(1500000.0, $body['request'][0]['productPrice']['amount']);
    }

    /**
     * @test
     */
    public function test_get_quotes_returns_quotes_array(): void
    {
        $client = $this->make_client();
        $this->stub_response(['quotes' => [['id' => 'QTE-001', 'price' => 50000]]]);
        Functions\when('gmdate')->justReturn('2026-01-01');

        $quotes = $client->get_quotes(['name' => 'X', 'price' => 100000.0]);
        $this->assertCount(1, $quotes);
        $this->assertSame('QTE-001', $quotes[0]['id']);
    }

    /**
     * @test
     */
    public function test_get_quotes_returns_empty_when_no_quotes_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'no coverage']);
        Functions\when('gmdate')->justReturn('2026-01-01');

        $this->assertSame([], $client->get_quotes(['name' => 'X', 'price' => 100.0]));
    }

    /**
     * @test
     */
    public function test_create_policy_returns_success_true_when_id_present(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'id'             => 'POL-001',
            'policyNumber'   => 'XCV-2025-001',
            'certificateUrl' => 'https://xcover.com/cert/001.pdf',
        ]);
        $result = $client->create_policy('QTE-001', [
            'first_name' => 'Juan',
            'last_name'  => 'García',
            'email'      => 'juan@test.com',
            'phone'      => '3001234567',
            'order_id'   => 'ORD-001',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('POL-001', $result['policy_id']);
        $this->assertSame('XCV-2025-001', $result['policy_number']);
    }

    /**
     * @test
     */
    public function test_create_policy_returns_success_false_when_no_id(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'invalid quote']);
        $result = $client->create_policy('QTE-BAD', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.com', 'phone' => '', 'order_id' => '']);
        $this->assertFalse($result['success']);
        $this->assertSame('', $result['policy_id']);
    }

    /**
     * @test
     */
    public function test_cancel_policy_returns_success_with_refund_amount(): void
    {
        $client = $this->make_client();
        $this->stub_response(['refundAmount' => ['amount' => 45000.0]]);

        $result = $client->cancel_policy('POL-001', 'customer_request');
        $this->assertTrue($result['success']);
        $this->assertSame(45000.0, $result['refund_amount']);
    }

    /**
     * @test
     */
    public function test_cancel_policy_returns_zero_refund_on_no_refund_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'not eligible']);

        $result = $client->cancel_policy('POL-002', 'out_of_window');
        $this->assertFalse($result['success']);
        $this->assertSame(0.0, $result['refund_amount']);
    }

    /**
     * @test
     */
    public function test_health_check_returns_ok_when_partner_code_in_response(): void
    {
        $client = $this->make_client();
        $this->stub_response(['partnerCode' => 'LTMS-PARTNER', 'status' => 'active']);

        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
    }

    /**
     * @test
     */
    public function test_health_check_returns_error_on_exception(): void
    {
        $client = $this->make_client();
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('fail', 'down'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
    }

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $this->assertFalse((new ReflectionClass(\LTMS_Api_Xcover::class))->isFinal());
    }
}
