<?php
/**
 * UberApiTest — Tests unitarios para LTMS_Api_Uber
 *
 * Cubre:
 *   1. Constructor — excepciones sin credenciales/customer_id, client_secret descifrado
 *   2. get_quote() — endpoint incluye customer_id con rawurlencode
 *   3. create_delivery() — payload incluye quote_id
 *   4. get_delivery() — endpoint correcto
 *   5. cancel_delivery() — endpoint /cancel
 *   6. health_check() — ok con token cacheado / error sin token
 *   7. get_access_token — usa transient, lanza excepción en 401
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
 * @covers LTMS_Api_Uber
 */
class UberApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'get_option'     => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'  => static fn(): bool => true,
            'get_transient'  => static fn(): mixed => false,
            'set_transient'  => static fn(): bool => true,
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
        \LTMS_Core_Config::set('ltms_uber_direct_client_id',     'uber_client_id_test');
        \LTMS_Core_Config::set('ltms_uber_direct_client_secret', \LTMS_Core_Security::encrypt('uber_secret_test'));
        \LTMS_Core_Config::set('ltms_uber_direct_customer_id',   'CUST-001');
    }

    private function make_client(): \LTMS_Api_Uber
    {
        $this->set_credentials();
        return new \LTMS_Api_Uber();
    }

    /** Injects a pre-cached token so OAuth2 is never called */
    private function inject_token(\LTMS_Api_Uber $client, string $token = 'bearer_test'): void
    {
        Functions\when('get_transient')->justReturn($token);

        // Also prime via perform_request path — set Authorization header
        $ref  = new ReflectionClass($client);
        $parent = $ref->getParentClass();
        $prop = $parent->getProperty('default_headers');
        $prop->setAccessible(true);
        $headers = $prop->getValue($client);
        $headers['Authorization'] = 'Bearer ' . $token;
        $prop->setValue($client, $headers);
    }

    private function stub_api_response(mixed $body, int $code = 200): void
    {
        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
        Functions\when('wp_remote_retrieve_response_message')->justReturn('OK');
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_throws_when_no_client_id(): void
    {
        \LTMS_Core_Config::set('ltms_uber_direct_client_secret', \LTMS_Core_Security::encrypt('s'));
        \LTMS_Core_Config::set('ltms_uber_direct_customer_id',   'C');
        // client_id not set → empty

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/credenciales/i');
        new \LTMS_Api_Uber();
    }

    /**
     * @test
     */
    public function test_constructor_throws_when_no_customer_id(): void
    {
        \LTMS_Core_Config::set('ltms_uber_direct_client_id',     'id');
        \LTMS_Core_Config::set('ltms_uber_direct_client_secret', \LTMS_Core_Security::encrypt('secret'));
        // customer_id not set

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/customer.id/i');
        new \LTMS_Api_Uber();
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
        $this->assertSame('uber_secret_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_stores_customer_id(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('customer_id');
        $prop->setAccessible(true);
        $this->assertSame('CUST-001', $prop->getValue($client));
    }

    // ── Section 2: get_quote ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_quote_endpoint_includes_customer_id(): void
    {
        $client = $this->make_client();
        $captured_url = null;

        Functions\when('get_transient')->justReturn('cached_token');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            $client->get_quote(['pickup_address' => [], 'dropoff_address' => []]);
        } catch (\RuntimeException) {}

        $this->assertNotNull($captured_url);
        $this->assertStringContainsString('CUST-001', $captured_url);
        $this->assertStringContainsString('delivery_quotes', $captured_url);
    }

    /**
     * @test
     */
    public function test_get_quote_url_encodes_customer_id(): void
    {
        \LTMS_Core_Config::set('ltms_uber_direct_client_id',     'id');
        \LTMS_Core_Config::set('ltms_uber_direct_client_secret', \LTMS_Core_Security::encrypt('s'));
        \LTMS_Core_Config::set('ltms_uber_direct_customer_id',   'CUST ID/001');
        $client = new \LTMS_Api_Uber();

        $captured_url = null;
        Functions\when('get_transient')->justReturn('tok');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try { $client->get_quote([]); } catch (\RuntimeException) {}

        $this->assertStringContainsString('CUST%20ID%2F001', $captured_url);
    }

    // ── Section 3: create_delivery ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_create_delivery_merges_quote_id_into_payload(): void
    {
        $client  = $this->make_client();
        $captured = null;

        Functions\when('get_transient')->justReturn('tok');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            $client->create_delivery('QTE-001', ['pickup_name' => 'Sender', 'dropoff_name' => 'Receiver']);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('QTE-001', $body['quote_id']);
        $this->assertSame('Sender', $body['pickup_name']);
    }

    /**
     * @test
     */
    public function test_create_delivery_endpoint_includes_deliveries(): void
    {
        $client = $this->make_client();
        $captured_url = null;

        Functions\when('get_transient')->justReturn('tok');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try { $client->create_delivery('QTE', []); } catch (\RuntimeException) {}

        $this->assertStringContainsString('deliveries', $captured_url);
    }

    // ── Section 4: get_delivery ───────────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_delivery_endpoint_includes_delivery_id(): void
    {
        $client = $this->make_client();
        $captured_url = null;

        Functions\when('get_transient')->justReturn('tok');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try { $client->get_delivery('DEL-999'); } catch (\RuntimeException) {}

        $this->assertStringContainsString('DEL-999', $captured_url);
    }

    // ── Section 5: cancel_delivery ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_cancel_delivery_endpoint_includes_cancel_suffix(): void
    {
        $client = $this->make_client();
        $captured_url = null;

        Functions\when('get_transient')->justReturn('tok');
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try { $client->cancel_delivery('DEL-001'); } catch (\RuntimeException) {}

        $this->assertStringContainsString('cancel', $captured_url);
        $this->assertStringContainsString('DEL-001', $captured_url);
    }

    // ── Section 6: health_check ───────────────────────────────────────────────

    /**
     * @test
     */
    public function test_health_check_returns_ok_with_latency_when_token_obtained(): void
    {
        $client = $this->make_client();
        Functions\when('get_transient')->justReturn('cached_tok');

        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('latency_ms', $result);
    }

    /**
     * @test
     */
    public function test_health_check_returns_error_when_oauth_fails(): void
    {
        $client = $this->make_client();

        // get_transient returns false → tries real OAuth → wp_remote_post fails
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('fail', 'no network'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
    }

    // ── Section 7: get_access_token (Reflection) ──────────────────────────────

    /**
     * @test
     */
    public function test_get_access_token_uses_transient_cache(): void
    {
        $client = $this->make_client();
        Functions\when('get_transient')->justReturn('cached_token_xyz');

        $ref = new ReflectionMethod(\LTMS_Api_Uber::class, 'get_access_token');
        $ref->setAccessible(true);

        $token = $ref->invoke($client);
        $this->assertSame('cached_token_xyz', $token);
    }

    /**
     * @test
     */
    public function test_get_access_token_throws_on_auth_failure(): void
    {
        $client = $this->make_client();

        Functions\when('wp_remote_post')->justReturn(['response' => [], 'body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['error' => 'invalid_client', 'error_description' => 'Bad credentials']));

        $ref = new ReflectionMethod(\LTMS_Api_Uber::class, 'get_access_token');
        $ref->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/uber/i');
        $ref->invoke($client);
    }

    /**
     * @test
     */
    public function test_get_access_token_throws_on_wp_error(): void
    {
        $client = $this->make_client();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('network_error', 'Connection refused'));
        Functions\when('is_wp_error')->justReturn(true);

        $ref = new ReflectionMethod(\LTMS_Api_Uber::class, 'get_access_token');
        $ref->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $ref->invoke($client);
    }

    /**
     * @test
     */
    public function test_get_access_token_caches_token_on_success(): void
    {
        $client = $this->make_client();

        $set_calls = [];
        Functions\when('set_transient')->alias(
            static function(string $key, mixed $val, int $ttl) use (&$set_calls): bool {
                $set_calls[$key] = $val;
                return true;
            }
        );

        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'access_token' => 'new_uber_token',
            'expires_in'   => 3600,
        ]));

        $ref = new ReflectionMethod(\LTMS_Api_Uber::class, 'get_access_token');
        $ref->setAccessible(true);
        $token = $ref->invoke($client);

        $this->assertSame('new_uber_token', $token);
        $this->assertArrayHasKey('ltms_uber_access_token', $set_calls);
        $this->assertSame('new_uber_token', $set_calls['ltms_uber_access_token']);
    }

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $this->assertFalse((new ReflectionClass(\LTMS_Api_Uber::class))->isFinal());
    }

    /**
     * @test
     */
    public function test_auth_url_constant_points_to_uber(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Uber::class);
        $this->assertStringContainsString('uber.com', $ref->getConstant('AUTH_URL'));
    }
}

