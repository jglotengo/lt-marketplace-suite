<?php
/**
 * AveonlineApiTest — QA completo para LTMS_Api_Aveonline
 *
 * Cubre (42 tests):
 *
 * SECCIÓN 1 — Constructor
 *   1.1  URL sandbox en entorno test
 *   1.2  API key descifrada correctamente
 *   1.3  Account ID almacenado en plano
 *
 * SECCIÓN 2 — get_provider_slug
 *   2.1  Retorna 'aveonline'
 *
 * SECCIÓN 3 — create_shipment
 *   3.1  Éxito con tracking_number presente
 *   3.2  Fallo cuando API no retorna tracking_number
 *   3.3  account_id incluido en payload
 *   3.4  Lanza InvalidArgumentException sin origin
 *   3.5  Lanza InvalidArgumentException sin destination
 *   3.6  Lanza InvalidArgumentException con origin no-array
 *   3.7  Paquetes vacíos → default package (1 kg 30×20×15)
 *   3.8  service default es 'express'
 *   3.9  declared_value default es 0.0
 *   3.10 label_url mapeado correctamente
 *   3.11 shipment_id mapeado desde response['id']
 *
 * SECCIÓN 4 — track_shipment
 *   4.1  Todos los campos mapeados
 *   4.2  Defaults en respuesta vacía
 *   4.3  events como array vacío por defecto
 *   4.4  URL incluye tracking_number codificado
 *
 * SECCIÓN 5 — get_rates
 *   5.1  Retorna rates[]
 *   5.2  Retorna [] cuando no hay clave 'rates'
 *   5.3  Acepta 'weight' (alias de weight_kg)
 *   5.4  Acepta 'weight_kg' (campo canónico)
 *   5.5  weight default es 1.0 si ninguno presente
 *   5.6  Dimensiones default cuando faltan
 *
 * SECCIÓN 6 — get_label
 *   6.1  Retorna base64
 *   6.2  Retorna '' si clave ausente
 *
 * SECCIÓN 7 — cancel_shipment
 *   7.1  Retorna true cuando cancelled=true
 *   7.2  Retorna false cuando cancelled=false
 *   7.3  Retorna false cuando clave ausente
 *   7.4  Lanza InvalidArgumentException con ID vacío
 *   7.5  Lanza InvalidArgumentException con solo espacios
 *
 * SECCIÓN 8 — health_check
 *   8.1  OK cuando status='ok'
 *   8.2  Error cuando status≠'ok'
 *   8.3  Error en excepción de red
 *
 * SECCIÓN 9 — create_return (nuevo método)
 *   9.1  Éxito con tracking_number en respuesta
 *   9.2  Fallo si API no retorna tracking
 *   9.3  Lanza InvalidArgumentException con ID vacío
 *   9.4  Payload incluye original_shipment_id
 *   9.5  reason default 'customer_request'
 *
 * SECCIÓN 10 — get_default_headers
 *   10.1 X-Api-Key presente y descifrado
 *   10.2 X-Account-Id presente
 *
 * SECCIÓN 11 — format_address (Reflection)
 *   11.1 country forzado a 'CO'
 *   11.2 email inválido → vacío
 *   11.3 email válido → preservado
 *
 * SECCIÓN 12 — format_packages (Reflection)
 *   12.1 Vacío → paquete default
 *   12.2 Dimensiones mapeadas correctamente
 *   12.3 Múltiples paquetes
 *
 * SECCIÓN 13 — Estructura de clase
 *   13.1 Extiende LTMS_Abstract_API_Client
 *   13.2 Constantes de URL definidas
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
use InvalidArgumentException;

/**
 * @covers LTMS_Api_Aveonline
 */
class AveonlineApiTest extends TestCase
{
    // ── setUp / tearDown ──────────────────────────────────────────────────────

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
            // is_email is defined in bootstrap before Patchwork — do not stub it here
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

    private function set_credentials(
        string $api_key    = 'ave_api_key_test',
        string $account_id = 'ACCT-001'
    ): void {
        \LTMS_Core_Config::set('ltms_aveonline_api_key',    \LTMS_Core_Security::encrypt($api_key));
        \LTMS_Core_Config::set('ltms_aveonline_account_id', $account_id);
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

    private function capture_payload(): array
    {
        $captured = null;
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$captured): object {
                $captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        return [&$captured];
    }

    private function prop(object $obj, string $name): mixed
    {
        $r = new ReflectionClass($obj);
        while ($r !== false) {
            if ($r->hasProperty($name)) {
                $p = $r->getProperty($name);
                $p->setAccessible(true);
                return $p->getValue($obj);
            }
            $r = $r->getParentClass();
        }
        $this->fail("Property {$name} not found");
    }

    private function call(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    private function full_shipment_data(): array
    {
        return [
            'origin'      => ['name' => 'Vendedor', 'phone' => '3001234567', 'email' => 'v@test.co', 'address' => 'Cra 1', 'city' => 'Bogotá', 'state' => 'Cund.', 'zip_code' => '110111'],
            'destination' => ['name' => 'Comprador', 'phone' => '3107654321', 'email' => 'c@test.co', 'address' => 'Cll 10', 'city' => 'Medellín', 'state' => 'Ant.', 'zip_code' => '050001'],
            'packages'    => [['weight_kg' => 2.0, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15, 'quantity' => 1]],
        ];
    }

    // ── SECCIÓN 1 — Constructor ───────────────────────────────────────────────

    /** @test 1.1 */
    public function test_constructor_uses_sandbox_in_test_env(): void
    {
        $client = $this->make_client();
        $this->assertStringContainsString('sandbox', $this->prop($client, 'api_url'));
    }

    /** @test 1.2 */
    public function test_constructor_decrypts_api_key(): void
    {
        $client = $this->make_client();
        $this->assertSame('ave_api_key_test', $this->prop($client, 'api_key'));
    }

    /** @test 1.3 */
    public function test_constructor_stores_account_id_plain(): void
    {
        $client = $this->make_client();
        $this->assertSame('ACCT-001', $this->prop($client, 'account_id'));
    }

    // ── SECCIÓN 2 — get_provider_slug ────────────────────────────────────────

    /** @test 2.1 */
    public function test_get_provider_slug_returns_aveonline(): void
    {
        $this->assertSame('aveonline', $this->make_client()->get_provider_slug());
    }

    // ── SECCIÓN 3 — create_shipment ──────────────────────────────────────────

    /** @test 3.1 */
    public function test_create_shipment_success_with_tracking_number(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'tracking_number' => 'AVE-TRK-001',
            'label_url'       => 'https://labels.aveonline.co/001.pdf',
            'cost'            => 12500.0,
            'id'              => 'SHIP-001',
        ]);

        $result = $client->create_shipment($this->full_shipment_data());

        $this->assertTrue($result['success']);
        $this->assertSame('AVE-TRK-001', $result['tracking_number']);
        $this->assertSame(12500.0, $result['cost']);
    }

    /** @test 3.2 */
    public function test_create_shipment_returns_success_false_on_api_error(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'invalid address']);

        $result = $client->create_shipment($this->full_shipment_data());

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['tracking_number']);
        $this->assertSame(0.0, $result['cost']);
    }

    /** @test 3.3 */
    public function test_create_shipment_includes_account_id_in_payload(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try { $client->create_shipment($this->full_shipment_data()); } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('ACCT-001', $body['account_id']);
    }

    /** @test 3.4 */
    public function test_create_shipment_throws_on_missing_origin(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/origin/');
        $client->create_shipment(['destination' => ['name' => 'X']]);
    }

    /** @test 3.5 */
    public function test_create_shipment_throws_on_missing_destination(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/destination/');
        $client->create_shipment(['origin' => ['name' => 'X']]);
    }

    /** @test 3.6 */
    public function test_create_shipment_throws_when_origin_not_array(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->create_shipment(['origin' => 'string_not_array', 'destination' => []]);
    }

    /** @test 3.7 */
    public function test_create_shipment_uses_default_package_when_packages_empty(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O'],
                'destination' => ['name' => 'D'],
                'packages'    => [],
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertCount(1, $body['packages']);
        $this->assertSame(1.0, $body['packages'][0]['weight']);
    }

    /** @test 3.8 */
    public function test_create_shipment_default_service_is_express(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O'],
                'destination' => ['name' => 'D'],
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('express', $body['service']);
    }

    /** @test 3.9 */
    public function test_create_shipment_default_declared_value_is_zero(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O'],
                'destination' => ['name' => 'D'],
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame(0.0, $body['declared_value']);
    }

    /** @test 3.10 */
    public function test_create_shipment_maps_label_url(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'tracking_number' => 'AVE-002',
            'label_url'       => 'https://labels.aveonline.co/002.pdf',
            'cost'            => 5000.0,
            'id'              => 'SHIP-002',
        ]);

        $result = $client->create_shipment($this->full_shipment_data());
        $this->assertSame('https://labels.aveonline.co/002.pdf', $result['label_url']);
    }

    /** @test 3.11 */
    public function test_create_shipment_maps_shipment_id_from_id_key(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'tracking_number' => 'AVE-003',
            'id'              => 'SHIP-XYZ',
        ]);

        $result = $client->create_shipment($this->full_shipment_data());
        $this->assertSame('SHIP-XYZ', $result['shipment_id']);
    }

    // ── SECCIÓN 4 — track_shipment ────────────────────────────────────────────

    /** @test 4.1 */
    public function test_track_shipment_maps_all_fields(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status'             => 'in_transit',
            'events'             => [['date' => '2026-01-01', 'description' => 'Recogido']],
            'estimated_delivery' => '2026-01-03',
            'current_location'   => 'Bogotá',
        ]);

        $result = $client->track_shipment('AVE-TRK-001');

        $this->assertSame('in_transit', $result['status']);
        $this->assertCount(1, $result['events']);
        $this->assertSame('2026-01-03', $result['estimated_delivery']);
        $this->assertSame('Bogotá', $result['current_location']);
    }

    /** @test 4.2 */
    public function test_track_shipment_returns_defaults_on_empty_response(): void
    {
        $client = $this->make_client();
        $this->stub_response([]);

        $result = $client->track_shipment('UNKNOWN');

        $this->assertSame('unknown', $result['status']);
        $this->assertSame([], $result['events']);
        $this->assertSame('', $result['estimated_delivery']);
        $this->assertSame('', $result['current_location']);
    }

    /** @test 4.3 */
    public function test_track_shipment_events_default_to_empty_array(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'pending']);

        $result = $client->track_shipment('TRK-X');
        $this->assertIsArray($result['events']);
        $this->assertEmpty($result['events']);
    }

    /** @test 4.4 */
    public function test_track_shipment_encodes_tracking_number_in_url(): void
    {
        $client       = $this->make_client();
        $url_captured = null;
        Functions\when('wp_remote_request')->alias(
            static function(string $url, array $args) use (&$url_captured) {
                $url_captured = $url;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);

        try { $client->track_shipment('AVE TRK/001'); } catch (\RuntimeException) {}

        $this->assertNotNull($url_captured);
        $this->assertStringContainsString('AVE%20TRK%2F001', $url_captured);
    }

    // ── SECCIÓN 5 — get_rates ────────────────────────────────────────────────

    /** @test 5.1 */
    public function test_get_rates_returns_rates_array(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'rates' => [
                ['service' => 'express',  'price' => 15000, 'eta_days' => 1],
                ['service' => 'standard', 'price' => 8000,  'eta_days' => 3],
            ],
        ]);

        $rates = $client->get_rates([
            'origin_city'      => 'Bogotá',
            'destination_city' => 'Medellín',
            'weight_kg'        => 1.5,
        ]);

        $this->assertCount(2, $rates);
        $this->assertSame('express', $rates[0]['service']);
    }

    /** @test 5.2 */
    public function test_get_rates_returns_empty_on_missing_rates_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'no coverage']);

        $this->assertSame([], $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y']));
    }

    /** @test 5.3 */
    public function test_get_rates_accepts_weight_alias(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->get_rates([
                'origin_city'      => 'Bogotá',
                'destination_city' => 'Cali',
                'weight'           => 3.0,
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame(3.0, $body['weight'], 'Campo weight (alias) debe usarse si weight_kg ausente');
    }

    /** @test 5.4 */
    public function test_get_rates_canonical_weight_kg_takes_priority(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->get_rates([
                'origin_city'      => 'Bogotá',
                'destination_city' => 'Cali',
                'weight_kg'        => 2.5,
                'weight'           => 99.0,
            ]);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame(2.5, $body['weight']);
    }

    /** @test 5.5 */
    public function test_get_rates_defaults_weight_to_one_when_absent(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->get_rates(['origin_city' => 'Bogotá', 'destination_city' => 'Cali']);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame(1.0, $body['weight']);
    }

    /** @test 5.6 */
    public function test_get_rates_uses_default_dimensions_when_absent(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try {
            $client->get_rates(['origin_city' => 'Bogotá', 'destination_city' => 'Cali']);
        } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame(30.0, $body['dimensions']['length']);
        $this->assertSame(20.0, $body['dimensions']['width']);
        $this->assertSame(15.0, $body['dimensions']['height']);
    }

    // ── SECCIÓN 6 — get_label ────────────────────────────────────────────────

    /** @test 6.1 */
    public function test_get_label_returns_base64_string(): void
    {
        $client = $this->make_client();
        $this->stub_response(['label_base64' => 'JVBERi0xLjQ...']);

        $this->assertSame('JVBERi0xLjQ...', $client->get_label('SHIP-001'));
    }

    /** @test 6.2 */
    public function test_get_label_returns_empty_string_when_key_absent(): void
    {
        $client = $this->make_client();
        $this->stub_response([]);

        $this->assertSame('', $client->get_label('SHIP-002'));
    }

    // ── SECCIÓN 7 — cancel_shipment ──────────────────────────────────────────

    /** @test 7.1 */
    public function test_cancel_shipment_returns_true_when_cancelled_true(): void
    {
        $client = $this->make_client();
        $this->stub_response(['cancelled' => true]);

        $this->assertTrue($client->cancel_shipment('SHIP-001'));
    }

    /** @test 7.2 */
    public function test_cancel_shipment_returns_false_when_cancelled_false(): void
    {
        $client = $this->make_client();
        $this->stub_response(['cancelled' => false]);

        $this->assertFalse($client->cancel_shipment('SHIP-002'));
    }

    /** @test 7.3 */
    public function test_cancel_shipment_returns_false_on_missing_key(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'already dispatched']);

        $this->assertFalse($client->cancel_shipment('SHIP-003'));
    }

    /** @test 7.4 */
    public function test_cancel_shipment_throws_on_empty_id(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->cancel_shipment('');
    }

    /** @test 7.5 */
    public function test_cancel_shipment_throws_on_whitespace_only_id(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->cancel_shipment('   ');
    }

    // ── SECCIÓN 8 — health_check ─────────────────────────────────────────────

    /** @test 8.1 */
    public function test_health_check_returns_ok_when_status_is_ok(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'ok']);

        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
    }

    /** @test 8.2 */
    public function test_health_check_returns_error_when_status_is_not_ok(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'degraded']);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
    }

    /** @test 8.3 */
    public function test_health_check_returns_error_on_network_exception(): void
    {
        $client = $this->make_client();
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('fail', 'network down'));
        Functions\when('is_wp_error')->justReturn(true);

        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    // ── SECCIÓN 9 — create_return ────────────────────────────────────────────

    /** @test 9.1 */
    public function test_create_return_success_with_tracking(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'tracking_number' => 'AVE-RET-001',
            'label_url'       => 'https://labels.aveonline.co/ret-001.pdf',
            'id'              => 'RET-001',
        ]);

        $result = $client->create_return('SHIP-001');

        $this->assertTrue($result['success']);
        $this->assertSame('AVE-RET-001', $result['tracking_number']);
        $this->assertSame('RET-001', $result['return_id']);
    }

    /** @test 9.2 */
    public function test_create_return_success_false_when_no_tracking(): void
    {
        $client = $this->make_client();
        $this->stub_response(['error' => 'cannot return']);

        $result = $client->create_return('SHIP-002');

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['tracking_number']);
    }

    /** @test 9.3 */
    public function test_create_return_throws_on_empty_shipment_id(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->create_return('');
    }

    /** @test 9.4 */
    public function test_create_return_includes_original_shipment_id_in_payload(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try { $client->create_return('SHIP-XYZ'); } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('SHIP-XYZ', $body['original_shipment_id']);
    }

    /** @test 9.5 */
    public function test_create_return_default_reason_is_customer_request(): void
    {
        $client    = $this->make_client();
        [$captured] = $this->capture_payload();

        try { $client->create_return('SHIP-ABC'); } catch (\RuntimeException) {}

        $body = json_decode($captured['body'] ?? '{}', true);
        $this->assertSame('customer_request', $body['reason']);
    }

    // ── SECCIÓN 10 — get_default_headers ─────────────────────────────────────

    /** @test 10.1 */
    public function test_default_headers_include_decrypted_api_key(): void
    {
        $client  = $this->make_client();
        $headers = $this->call($client, 'get_default_headers');

        $this->assertArrayHasKey('X-Api-Key', $headers);
        $this->assertSame('ave_api_key_test', $headers['X-Api-Key']);
    }

    /** @test 10.2 */
    public function test_default_headers_include_account_id(): void
    {
        $client  = $this->make_client();
        $headers = $this->call($client, 'get_default_headers');

        $this->assertArrayHasKey('X-Account-Id', $headers);
        $this->assertSame('ACCT-001', $headers['X-Account-Id']);
    }

    // ── SECCIÓN 11 — format_address ──────────────────────────────────────────

    /** @test 11.1 */
    public function test_format_address_forces_country_co(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'format_address', ['name' => 'Test', 'city' => 'Bogotá']);

        $this->assertSame('CO', $result['country']);
    }

    /** @test 11.2 */
    public function test_format_address_clears_invalid_email(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'format_address', [
            'name'  => 'Test',
            'email' => 'not-an-email',
        ]);

        $this->assertSame('', $result['email'], 'Email inválido debe limpiarse');
    }

    /** @test 11.3 */
    public function test_format_address_preserves_valid_email(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'format_address', [
            'name'  => 'Test',
            'email' => 'valido@ejemplo.co',
        ]);

        $this->assertSame('valido@ejemplo.co', $result['email']);
    }

    // ── SECCIÓN 12 — format_packages ─────────────────────────────────────────

    /** @test 12.1 */
    public function test_format_packages_returns_default_when_empty(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'format_packages', []);

        $this->assertCount(1, $result);
        $this->assertSame(1.0, $result[0]['weight']);
        $this->assertSame(30,  $result[0]['length']);
        $this->assertSame(20,  $result[0]['width']);
        $this->assertSame(15,  $result[0]['height']);
        $this->assertSame(1,   $result[0]['quantity']);
    }

    /** @test 12.2 */
    public function test_format_packages_maps_dimensions_correctly(): void
    {
        $client   = $this->make_client();
        $packages = [['weight_kg' => 3.5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'quantity' => 2]];
        $result   = $this->call($client, 'format_packages', $packages);

        $this->assertSame(3.5, $result[0]['weight']);
        $this->assertSame(40,  $result[0]['length']);
        $this->assertSame(30,  $result[0]['width']);
        $this->assertSame(20,  $result[0]['height']);
        $this->assertSame(2,   $result[0]['quantity']);
    }

    /** @test 12.3 */
    public function test_format_packages_handles_multiple_packages(): void
    {
        $client   = $this->make_client();
        $packages = [
            ['weight_kg' => 1.0, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'quantity' => 1],
            ['weight_kg' => 2.0, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15, 'quantity' => 3],
        ];
        $result = $this->call($client, 'format_packages', $packages);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['quantity']);
        $this->assertSame(3, $result[1]['quantity']);
    }

    // ── SECCIÓN 13 — Estructura de clase ─────────────────────────────────────

    /** @test 13.1 */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Aveonline::class);
        $this->assertSame(\LTMS_Abstract_API_Client::class, $ref->getParentClass()->getName());
    }

    /** @test 13.2 */
    public function test_api_base_constants_defined(): void
    {
        $this->assertStringContainsString('aveonline.co', \LTMS_Api_Aveonline::API_BASE_LIVE);
        $this->assertStringContainsString('sandbox',      \LTMS_Api_Aveonline::API_BASE_SANDBOX);
    }
}
