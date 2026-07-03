<?php
/**
 * TptcApiTest — Tests unitarios para LTMS_Api_Tptc
 *
 * Cubre:
 *   1. Constructor — URL sandbox/live, api_key descifrada, program_id
 *   2. get_provider_slug() — 'tptc'
 *   3. register_affiliate() — payload, update_user_meta cuando hay affiliate_id
 *   4. sync_sale() — retorna not_registered cuando no hay affiliate_id
 *   5. get_affiliate_status() — retorna not_registered sin meta, mapeo con meta
 *   6. get_volume_report() — retorna [] sin meta
 *   7. health_check()
 *   8. get_default_headers() — incluye X-Api-Key
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
 * @covers LTMS_Api_Tptc
 */
class TptcApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

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
        \LTMS_Core_Config::set('ltms_tptc_api_key',    \LTMS_Core_Security::encrypt('tptc_key_test'));
        \LTMS_Core_Config::set('ltms_tptc_program_id', 'PROG-001');
    }

    private function make_client(): \LTMS_Api_Tptc
    {
        $this->set_credentials();
        return new \LTMS_Api_Tptc();
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
    public function test_get_provider_slug_returns_tptc(): void
    {
        $this->assertSame('tptc', $this->make_client()->get_provider_slug());
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
        $this->assertSame('tptc_key_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_stores_program_id(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('program_id');
        $prop->setAccessible(true);
        $this->assertSame('PROG-001', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_uses_sandbox_in_test_env(): void
    {
        $client = $this->make_client();
        $ref    = (new ReflectionClass($client))->getParentClass()->getProperty('api_url');
        $ref->setAccessible(true);
        $this->assertStringContainsString('sandbox', $ref->getValue($client));
    }

    /**
     * @test
     */
    public function test_default_headers_include_api_key(): void
    {
        $client  = $this->make_client();
        $ref     = new ReflectionMethod(\LTMS_Api_Tptc::class, 'get_default_headers');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client);

        $this->assertArrayHasKey('X-Api-Key', $headers);
        $this->assertSame('tptc_key_test', $headers['X-Api-Key']);
    }

    /**
     * @test
     */
    public function test_register_affiliate_builds_correct_payload(): void
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
        try {
            $client->register_affiliate([
                'vendor_id'     => 42,
                'first_name'    => 'Ana',
                'last_name'     => 'López',
                'email'         => 'ana@test.com',
                'phone'         => '3009876543',
                'document'      => '987654321',
                'document_type' => 'CC',
                'sponsor_code'  => 'REF-001',
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('PROG-001', $body['program_id']);
        $this->assertSame('ltms_42', $body['external_id']);
        $this->assertSame('Ana', $body['first_name']);
        $this->assertSame('CO', $body['country']);
        $this->assertSame('REF-001', $body['sponsor_code']);
    }

    /**
     * @test
     */
    public function test_register_affiliate_saves_meta_when_affiliate_id_present(): void
    {
        $client = $this->make_client();

        $this->stub_response(['affiliate_id' => 'TPTC-AFF-001', 'referral_code' => 'CODE-XYZ']);

        $update_calls = [];
        Functions\when('update_user_meta')->alias(
            static function(int $uid, string $key, mixed $val) use (&$update_calls): bool {
                $update_calls[$key] = $val;
                return true;
            }
        );
        $result = $client->register_affiliate(['vendor_id' => 42, 'first_name' => 'Ana', 'last_name' => 'L', 'email' => 'a@b.com', 'phone' => '']);

        $this->assertTrue($result['success']);
        $this->assertSame('TPTC-AFF-001', $result['affiliate_id']);
        $this->assertSame('CODE-XYZ', $result['referral_code']);
        $this->assertSame('TPTC-AFF-001', $update_calls['ltms_tptc_affiliate_id']);
    }

    /**
     * @test
     */
    public function test_register_affiliate_returns_success_false_when_no_affiliate_id(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'duplicate']);
        $result = $client->register_affiliate(['vendor_id' => 99, 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.com', 'phone' => '']);
        $this->assertFalse($result['success']);
        $this->assertSame('', $result['affiliate_id']);
    }

    /**
     * @test
     */
    public function test_sync_sale_returns_not_registered_when_no_meta(): void
    {
        $client = $this->make_client();
        Functions\when('get_user_meta')->justReturn(false);

        $result = $client->sync_sale(['vendor_id' => 42, 'order_id' => 1, 'amount' => 50000, 'currency' => 'COP', 'sale_date' => '2025-01-01', 'product_type' => 'physical']);
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['points_credited']);
        $this->assertStringContainsString('no registrado', strtolower($result['message']));
    }

    /**
     * @test
     */
    public function test_sync_sale_maps_points_when_registered(): void
    {
        $client = $this->make_client();
        Functions\when('get_user_meta')->justReturn('TPTC-AFF-001');
        $this->stub_response(['transaction_id' => 'TXN-001', 'points_credited' => 150]);
        $result = $client->sync_sale(['vendor_id' => 42, 'order_id' => 10, 'amount' => 100000, 'currency' => 'COP', 'sale_date' => '2025-01-01', 'product_type' => 'physical']);
        $this->assertTrue($result['success']);
        $this->assertSame(150, $result['points_credited']);
    }

    /**
     * @test
     */
    public function test_get_affiliate_status_returns_not_registered_without_meta(): void
    {
        $client = $this->make_client();
        Functions\when('get_user_meta')->justReturn(false);

        $result = $client->get_affiliate_status(42);
        $this->assertSame('not_registered', $result['status']);
        $this->assertSame(0, $result['points']);
    }

    /**
     * @test
     */
    public function test_get_affiliate_status_maps_response_when_registered(): void
    {
        $client = $this->make_client();
        Functions\when('get_user_meta')->justReturn('TPTC-AFF-001');
        $this->stub_response(['status' => 'active', 'points_balance' => 500, 'current_rank' => 'Silver', 'downline_count' => 3]);

        $result = $client->get_affiliate_status(42);
        $this->assertSame('active', $result['status']);
        $this->assertSame(500, $result['points']);
        $this->assertSame('Silver', $result['rank']);
        $this->assertSame(3, $result['downline_count']);
    }

    /**
     * @test
     */
    public function test_get_volume_report_returns_empty_array_without_meta(): void
    {
        $client = $this->make_client();
        Functions\when('get_user_meta')->justReturn(false);

        $this->assertSame([], $client->get_volume_report(42, '2025-01'));
    }

    /**
     * @test
     */
    public function test_health_check_returns_ok(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'ok']);
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
        $this->assertFalse((new ReflectionClass(\LTMS_Api_Tptc::class))->isFinal());
    }
}

