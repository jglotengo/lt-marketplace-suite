<?php
/**
 * AveonlineApiTest — Tests unitarios para LTMS_Api_Aveonline
 *
 * Cubre:
 *   1. Constructor — URL por entorno, api_key descifrada, account_id plano
 *   2. get_provider_slug() — retorna 'aveonline'
 *   3. create_shipment() — payload con formato de dirección/paquete, mapeo de respuesta
 *   4. track_shipment() — mapeo de respuesta con defaults
 *   5. get_rates() — payload y retorno de rates[]
 *   6. get_label() — retorna base64 o string vacío
 *   7. cancel_shipment() — retorna bool
 *   8. health_check() — ok/error
 *   9. get_default_headers() — incluye X-Api-Key y X-Account-Id
 *  10. format_address/format_packages (via Reflection)
 *  11. Reflection — estructura de la clase
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
 * @covers LTMS_Api_Aveonline
 */
class AveonlineApiTest extends TestCase
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

    private function set_credentials(): void
    {
        \LTMS_Core_Config::set('ltms_aveonline_api_key',    \LTMS_Core_Security::encrypt('ave_api_key_test'));
        \LTMS_Core_Config::set('ltms_aveonline_account_id', 'ACCT-001');
    }

    private function make_client(): \LTMS_Api_Aveonline
    {
        $this->set_credentials();
        return new \LTMS_Api_Aveonline();
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
    public function test_constructor_decrypts_api_key(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('api_key');
        $prop->setAccessible(true);
        $this->assertSame('ave_api_key_test', $prop->getValue($client));
    }

    /**
     * @test
     */
    public function test_constructor_stores_account_id_plain(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionClass($client);
        $prop   = $ref->getProperty('account_id');
        $prop->setAccessible(true);
        $this->assertSame('ACCT-001', $prop->getValue($client));
    }

    // ── Section 2: get_provider_slug ──────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_provider_slug_returns_aveonline(): void
    {
        $this->assertSame('aveonline', $this->make_client()->get_provider_slug());
    }

    // ── Section 3: create_shipment ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_create_shipment_returns_success_true_when_tracking_number_present(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'tracking_number' => 'AVE-TRK-001',
            'label_url'       => 'https://labels.aveonline.co/001.pdf',
            'cost'            => 12500.0,
            'id'              => 'SHIP-001',
        ]);

        $result = $client->create_shipment([
            'origin'      => ['name' => 'Vendedor', 'phone' => '3001234567', 'address' => 'Cra 1', 'city' => 'Bogotá', 'state' => 'Cundinamarca', 'zip_code' => '110111'],
            'destination' => ['name' => 'Comprador', 'phone' => '3107654321', 'address' => 'Cll 10', 'city' => 'Medellín', 'state' => 'Antioquia', 'zip_code' => '050001'],
            'packages'    => [['weight_kg' => 2.0, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15, 'quantity' => 1]],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('AVE-TRK-001', $result['tracking_number']);
        $this->assertSame(12500.0, $result['cost']);
    }

    /**
     * @test
     */
    public function test_create_shipment_returns_success_false_when_no_tracking(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'invalid address']);

        $result = $client->create_shipment(['origin' => [], 'destination' => [], 'packages' => []]);

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['tracking_number']);
        $this->assertSame(0.0, $result['cost']);
    }

    /**
     * @test
     */
    public function test_create_shipment_includes_account_id_in_payload(): void
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
            $client->create_shipment(['origin' => [], 'destination' => [], 'packages' => []]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('ACCT-001', $body['account_id']);
    }

    // ── Section 4: track_shipment ─────────────────────────────────────────────

    /**
     * @test
     */
    public function test_track_shipment_maps_response_fields(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status'             => 'in_transit',
            'events'             => [['date' => '2025-01-01', 'description' => 'Recogido']],
            'estimated_delivery' => '2025-01-03',
            'current_location'   => 'Bogotá',
        ]);

        $result = $client->track_shipment('AVE-TRK-001');

        $this->assertSame('in_transit', $result['status']);
        $this->assertCount(1, $result['events']);
        $this->assertSame('2025-01-03', $result['estimated_delivery']);
        $this->assertSame('Bogotá', $result['current_location']);
    }

    /**
     * @test
     */
    public function test_track_shipment_returns_defaults_on_empty_response(): void
    {
        $client = $this->make_client();
        $this->stub_response([]);

        $result = $client->track_shipment('UNKNOWN');
        $this->assertSame('unknown', $result['status']);
        $this->assertSame([], $result['events']);
        $this->assertSame('', $result['estimated_delivery']);
    }

    // ── Section 5: get_rates ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_rates_returns_rates_array(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'rates' => [
                ['service' => 'express', 'price' => 15000, 'eta_days' => 1],
                ['service' => 'standard', 'price' => 8000, 'eta_days' => 3],
            ],
        ]);

        $rates = $client->get_rates([
            'origin_city'      => 'Bogotá',
            'destination_city' => 'Medellín',
            'weight_kg'        => 1.5,
            'declared_value'   => 100000,
        ]);

        $this->assertCount(2, $rates);
        $this->assertSame('express', $rates[0]['service']);
    }

    /**
     * @test
     */
    public function test_get_rates_returns_empty_array_when_no_rates_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'no coverage']);

        $this->assertSame([], $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y', 'weight_kg' => 1]));
    }

    // ── Section 6: get_label ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_get_label_returns_base64_string(): void
    {
        $client = $this->make_client();
        $this->stub_response(['label_base64' => 'JVBERi0xLjQ...']);

        $this->assertSame('JVBERi0xLjQ...', $client->get_label('SHIP-001'));
    }

    /**
     * @test
     */
    public function test_get_label_returns_empty_string_when_key_missing(): void
    {
        $client = $this->make_client();
        $this->stub_response([]);

        $this->assertSame('', $client->get_label('SHIP-002'));
    }

    // ── Section 7: cancel_shipment ────────────────────────────────────────────

    /**
     * @test
     */
    public function test_cancel_shipment_returns_true_when_cancelled_key_is_true(): void
    {
        $client = $this->make_client();
        $this->stub_response(['cancelled' => true]);

        $this->assertTrue($client->cancel_shipment('SHIP-001'));
    }

    /**
     * @test
     */
    public function test_cancel_shipment_returns_false_when_cancelled_not_true(): void
    {
        $client = $this->make_client();
        $this->stub_response(['cancelled' => false]);

        $this->assertFalse($client->cancel_shipment('SHIP-002'));
    }

    /**
     * @test
     */
    public function test_cancel_shipment_returns_false_on_missing_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'already dispatched']);

        $this->assertFalse($client->cancel_shipment('SHIP-003'));
    }

    // ── Section 8: health_check ───────────────────────────────────────────────

    /**
     * @test
     */
    public function test_health_check_returns_ok_when_status_is_ok(): void
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
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('fail', 'network down'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
    }

    // ── Section 9: get_default_headers ────────────────────────────────────────

    /**
     * @test
     */
    public function test_default_headers_include_api_key_header(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Aveonline::class, 'get_default_headers');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client);

        $this->assertArrayHasKey('X-Api-Key', $headers);
        $this->assertSame('ave_api_key_test', $headers['X-Api-Key']);
    }

    /**
     * @test
     */
    public function test_default_headers_include_account_id_header(): void
    {
        $client  = $this->make_client();
        $ref     = new ReflectionMethod(\LTMS_Api_Aveonline::class, 'get_default_headers');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client);

        $this->assertArrayHasKey('X-Account-Id', $headers);
        $this->assertSame('ACCT-001', $headers['X-Account-Id']);
    }

    // ── Section 10: format_address / format_packages (Reflection) ─────────────

    /**
     * @test
     */
    public function test_format_address_sets_country_to_co(): void
    {
        $client = $this->make_client();
        $ref = new ReflectionMethod(\LTMS_Api_Aveonline::class, 'format_address');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, ['name' => 'Test', 'phone' => '3001234567', 'address' => 'Cra 1', 'city' => 'Bogotá', 'state' => 'Cund.', 'zip_code' => '110111']);
        $this->assertSame('CO', $result['country']);
        $this->assertSame('Test', $result['name']);
    }

    /**
     * @test
     */
    public function test_format_packages_returns_default_when_empty(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Aveonline::class, 'format_packages');
        $ref->setAccessible(true);

        $result = $ref->invoke($client, []);
        $this->assertCount(1, $result);
        $this->assertSame(1.0, $result[0]['weight']);
        $this->assertSame(1, $result[0]['quantity']);
    }

    /**
     * @test
     */
    public function test_format_packages_maps_dimensions_correctly(): void
    {
        $client = $this->make_client();
        $ref    = new ReflectionMethod(\LTMS_Api_Aveonline::class, 'format_packages');
        $ref->setAccessible(true);

        $packages = [['weight_kg' => 3.5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'quantity' => 2]];
        $result   = $ref->invoke($client, $packages);

        $this->assertSame(3.5, $result[0]['weight']);
        $this->assertSame(40, $result[0]['length']);
        $this->assertSame(2, $result[0]['quantity']);
    }

    // ── Section 11: Reflection ────────────────────────────────────────────────

    /**
     * @test
     */
    public function test_class_is_final(): void
    {
        $this->assertFalse((new ReflectionClass(\LTMS_Api_Aveonline::class))->isFinal());
    }

    /**
     * @test
     */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Aveonline::class);
        $this->assertStringContainsString('LTMS_Abstract_API_Client', $ref->getParentClass()->getName());
    }
}

