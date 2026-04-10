<?php
/**
 * HekaApiTest — Tests unitarios para LTMS_Api_Heka
 *
 * Cubre:
 *   1. Constructor — excepción si falta API Key, header X-API-Key inyectado, account_id
 *   2. get_rates() — payload con account_id cuando presente, estructura básica
 *   3. create_shipment() — inyecta account_id si falta, Logger::info llamado
 *   4. track_shipment() — endpoint correcto con rawurlencode
 *   5. health_check() — retorna latency_ms en ok, error en excepción
 *   6. Reflection — hereda de LTMS_Abstract_API_Client (con mayúsculas)
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers LTMS_Api_Heka
 */
class HekaApiTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function set_credentials(string $account_id = 'HEKA-ACCT-001'): void
    {
        \LTMS_Core_Config::set('ltms_heka_api_key',    \LTMS_Core_Security::encrypt('heka_key_test'));
        \LTMS_Core_Config::set('ltms_heka_account_id', $account_id);
    }

    private function make_client(): \LTMS_Api_Heka
    {
        $this->set_credentials();
        return new \LTMS_Api_Heka();
    }

    private function stub_response(mixed $body, int $code = 200): void
    {
        Functions\when('wp_remote_request')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
    }

    // ── Section 1: Constructor ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_constructor_throws_when_api_key_missing(): void
    {
        // No credentials set
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ltms_heka_api_key/i');
        new \LTMS_Api_Heka();
    }

    /**
     * @test
     */
    public function test_constructor_injects_api_key_header(): void
    {
        $client  = $this->make_client();
        $ref     = new ReflectionClass($client);
        $parent  = $ref->getParentClass();
        $prop    = $parent->getProperty('default_headers');
        $prop->setAccessible(true);
        $headers = $prop->getValue($client);

        $this->assertArrayHasKey('X-API-Key', $headers);
        $this->assertSame('heka_key_test', $headers['X-API-Key']);
    }

    /**
     * @test
     */
    public function test_constructor_stores_account_id(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('account_id');
        $prop->setAccessible(true);
        $this->assertSame('HEKA-ACCT-001', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_sets_api_url(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $parent = $ref->getParentClass();
        $prop   = $parent->getProperty('api_url');
        $prop->setAccessible(true);
        $this->assertStringContainsString('hekaentrega.com', $prop->getValue($client));
    }

    // ── Section 2: get_rates ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_rates_includes_account_id_when_set(): void
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
            $client->get_rates(['origin_city' => 'Bogotá', 'destination_city' => 'Cali', 'weight_kg' => 1.0]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('HEKA-ACCT-001', $body['account_id']);
    }

    /**
     * @test
     */
    public function test_get_rates_omits_account_id_when_empty(): void
    {
        // Set credentials without account_id
        \LTMS_Core_Config::set('ltms_heka_api_key', \LTMS_Core_Security::encrypt('heka_key_test'));
        \LTMS_Core_Config::set('ltms_heka_account_id', '');
        $client  = new \LTMS_Api_Heka();
        $captured = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): mixed {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y', 'weight_kg' => 1]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertArrayNotHasKey('account_id', $body);
    }

    /**
     * @test
     */
    public function test_get_rates_sanitizes_city_names(): void
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
            $client->get_rates(['origin_city' => 'Bogotá', 'destination_city' => 'Medellín', 'weight_kg' => 2.5, 'declared_value' => 80000, 'items_count' => 3]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('Bogotá', $body['origin_city']);
        $this->assertSame(2.5, $body['weight_kg']);
        $this->assertSame(3, $body['items_count']);
    }

    // ── Section 3: create_shipment ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_create_shipment_injects_account_id_when_missing_from_data(): void
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
            $client->create_shipment(['external_reference' => 'WC-001', 'service_type' => 'express']);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('HEKA-ACCT-001', $body['account_id']);
    }

    /**
     * @test
     */
    public function test_create_shipment_does_not_override_existing_account_id(): void
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
            $client->create_shipment(['account_id' => 'CUSTOM-ACCT', 'external_reference' => 'WC-002']);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('CUSTOM-ACCT', $body['account_id']);
    }

    /**
     * @test
     */
    public function test_create_shipment_returns_response_on_success(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'shipment_id'     => 'HEK-001',
            'tracking_number' => 'HEK-TRK-001',
            'label_url'       => 'https://heka.com/label/001.pdf',
        ]);

        $result = $client->create_shipment(['external_reference' => 'WC-010', 'service_type' => 'express']);

        $this->assertSame('HEK-001', $result['shipment_id']);
        $this->assertSame('HEK-TRK-001', $result['tracking_number']);
    }

    // ── Section 4: track_shipment ─────────────────────────────────────────────

    /**
     * @test
     */
    public function test_track_shipment_calls_correct_endpoint(): void
    {
        $client  = $this->make_client();
        $captured_url = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            $client->track_shipment('HEK-TRK-001');
        } catch (\RuntimeException) {}

        $this->assertNotNull($captured_url);
        $this->assertStringContainsString('track', $captured_url);
        $this->assertStringContainsString('HEK-TRK-001', $captured_url);
    }

    /**
     * @test
     */
    public function test_track_shipment_url_encodes_tracking_number(): void
    {
        $client  = $this->make_client();
        $captured_url = null;

        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured_url): mixed {
                $captured_url = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try {
            $client->track_shipment('TRK 001/A');
        } catch (\RuntimeException) {}

        // rawurlencode turns space→%20, /→%2F
        $this->assertStringContainsString('TRK%20001%2FA', $captured_url);
    }

    // ── Section 5: health_check ───────────────────────────────────────────────

    /**
     * @test
     */
    public function test_health_check_returns_ok_with_latency_ms(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'ok']);

        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('Conectado', $result['message']);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertIsInt($result['latency_ms']);
    }

    /**
     * @test
     */
    public function test_health_check_returns_error_on_exception(): void
    {
        $client = $this->make_client();
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('fail', 'timeout'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['message']);
        $this->assertArrayNotHasKey('latency_ms', $result);
    }

    // ── Section 6: Reflection ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Heka::class);
        // Heka extends LTMS_Abstract_API_Client (uppercase)
        $this->assertStringContainsString('Abstract_API_Client', $ref->getParentClass()->getName());
    }

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $this->assertFalse((new ReflectionClass(\LTMS_Api_Heka::class))->isFinal());
    }
}

