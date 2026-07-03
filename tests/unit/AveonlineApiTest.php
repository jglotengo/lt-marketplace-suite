<?php
/**
 * AveonlineApiTest — QA completo para LTMS_Api_Aveonline v2
 *
 * Cubre (42 tests):
 *
 * SECCIÓN 1 — Constructor
 *   1.1  URL sandbox en entorno test
 *   1.2  usuario almacenado en plano
 *   1.3  idempresa almacenado como entero
 *
 * SECCIÓN 2 — get_provider_slug
 *   2.1  Retorna 'aveonline'
 *
 * SECCIÓN 3 — create_shipment
 *   3.1  Éxito cuando resultado.guia.numguia presente y codigo='0'
 *   3.2  Fallo cuando API no retorna numguia
 *   3.3  tipo='generarGuia2' incluido en payload
 *   3.4  Lanza InvalidArgumentException sin origin
 *   3.5  Lanza InvalidArgumentException sin destination
 *   3.6  Lanza InvalidArgumentException con origin no-array
 *   3.7  Paquetes vacíos → default package (peso='1')
 *   3.8  dscontenido default es 'Mercancía general'
 *   3.9  valorrecaudo default es 0
 *   3.10 label_url mapeado desde resultado.guia.rutaguia
 *   3.11 shipment_id mapeado desde resultado.guia.numguia
 *
 * SECCIÓN 4 — track_shipment
 *   4.1  Todos los campos mapeados desde guias[0]
 *   4.2  Defaults en respuesta vacía
 *   4.3  events mapeados desde historicos[]
 *   4.4  tipo='obtenerEstadoAuth' en payload
 *
 * SECCIÓN 5 — get_rates
 *   5.1  Retorna cotizaciones[] sin error
 *   5.2  Retorna [] cuando no hay cotizaciones válidas
 *   5.3  Acepta 'weight' (alias de weight_kg)
 *   5.4  Acepta 'weight_kg' (campo canónico)
 *   5.5  weight default es 1.0 si ninguno presente
 *   5.6  Dimensiones default cuando faltan
 *
 * SECCIÓN 6 — get_label
 *   6.1  Retorna ruta_rotulo desde track_shipment
 *   6.2  Retorna '' si no hay label_url
 *
 * SECCIÓN 7 — cancel_shipment
 *   7.1  Lanza RuntimeException (endpoint no disponible en API v2)
 *   7.2  Lanza RuntimeException también con ID válido
 *   7.3  Lanza InvalidArgumentException con ID vacío
 *   7.4  Lanza InvalidArgumentException con solo espacios
 *
 * SECCIÓN 8 — health_check
 *   8.1  OK cuando auth retorna token
 *   8.2  Error cuando auth falla
 *   8.3  Error en excepción de red
 *
 * SECCIÓN 9 — create_return
 *   9.1  Éxito con guia en resultado
 *   9.2  Fallo si API no retorna guia
 *   9.3  Lanza InvalidArgumentException con ID vacío
 *   9.4  Payload incluye cartaporte='1'
 *   9.5  reason default 'customer_request' en dscom
 *
 * SECCIÓN 10 — get_default_headers
 *   10.1 Content-Type presente
 *   10.2 No contiene X-Api-Key (auth por body en v2)
 *
 * SECCIÓN 11 — sanitize_email_field
 *   11.1 limpia email inválido
 *   11.2 preserva email válido
 *   11.3 acepta vacío sin error
 *
 * SECCIÓN 12 — format_packages
 *   12.1 Vacío → paquete default (peso='1', largo='30', etc.)
 *   12.2 Dimensiones mapeadas correctamente (strings)
 *   12.3 Múltiples paquetes preservan unidades
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

    private function set_credentials(
        string $usuario   = 'ave_user_test',
        string $clave     = 'ave_pass_test',
        int    $idempresa = 15289,
        string $idagente  = '2600'
    ): void {
        \LTMS_Core_Config::set('ltms_aveonline_usuario',   $usuario);
        \LTMS_Core_Config::set('ltms_aveonline_clave',     \LTMS_Core_Security::encrypt($clave));
        \LTMS_Core_Config::set('ltms_aveonline_idempresa', (string) $idempresa);
        \LTMS_Core_Config::set('ltms_aveonline_idagente',  $idagente);
    }

    private function make_client(): \LTMS_Api_Aveonline
    {
        $this->set_credentials();
        Functions\when('get_transient')->justReturn('jwt_test_token_cached');
        return new \LTMS_Api_Aveonline();
    }

    private function stub_response(mixed $body, int $code = 200): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($body));
    }

    private function capture_post_payload(): array
    {
        $ref = new \stdClass();
        $ref->captured = null;
        Functions\when('wp_remote_post')->alias(
            static function(string $url, array $args) use ($ref): \WP_Error {
                $ref->captured = $args;
                return new \WP_Error('stop', 'stopped');
            }
        );
        Functions\when('is_wp_error')->justReturn(true);
        return [$ref];
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
            'origin'      => [
                'name'    => 'Vendedor Test',
                'phone'   => '3001234567',
                'email'   => 'v@test.co',
                'address' => 'Cra 1 # 2-3',
                'city'    => 'BOGOTA(CUNDINAMARCA)',
                'nit'     => '900123456',
            ],
            'destination' => [
                'name'    => 'Comprador Test',
                'phone'   => '3107654321',
                'email'   => 'c@test.co',
                'address' => 'Cll 10 # 20-30',
                'city'    => 'MEDELLIN(ANTIOQUIA)',
                'nit'     => '12345678',
            ],
            'packages'    => [['weight_kg' => 2.0, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15, 'quantity' => 1]],
        ];
    }

    private function successful_guia_response(): array
    {
        return [
            'status'    => 'ok',
            'message'   => 'proceso correcto',
            'resultado' => [
                'guia' => [
                    'codigo'        => '0',
                    'mensaje'       => 'Guia 21027846356 Generada',
                    'numguia'       => 21027846356,
                    'rutaguia'      => 'https://app.aveonline.co/imprimir.php?guia=21027846356',
                    'rotulo'        => 'https://app.aveonline.co/rotulo.php?pkid=123',
                    'rutasticker'   => 'https://app.aveonline.co/sticker.php?guia=21027846356',
                    'archivorotulo' => 'JVBERi0xLjQ=',
                    'transportadora'=> 'ENVIA',
                ],
            ],
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
    public function test_constructor_stores_usuario_plain(): void
    {
        $client = $this->make_client();
        $this->assertSame('ave_user_test', $this->prop($client, 'usuario'));
    }

    /** @test 1.3 */
    public function test_constructor_stores_idempresa_as_int(): void
    {
        $client = $this->make_client();
        $this->assertSame(15289, $this->prop($client, 'idempresa'));
    }

    // ── SECCIÓN 2 — get_provider_slug ────────────────────────────────────────

    /** @test 2.1 */
    public function test_get_provider_slug_returns_aveonline(): void
    {
        $this->assertSame('aveonline', $this->make_client()->get_provider_slug());
    }

    // ── SECCIÓN 3 — create_shipment ──────────────────────────────────────────

    /** @test 3.1 */
    public function test_create_shipment_success_when_numguia_present_and_codigo_zero(): void
    {
        $client = $this->make_client();
        $this->stub_response($this->successful_guia_response());

        $result = $client->create_shipment($this->full_shipment_data());

        $this->assertTrue($result['success']);
        $this->assertSame('21027846356', $result['tracking_number']);
    }

    /** @test 3.2 */
    public function test_create_shipment_returns_success_false_when_no_numguia(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'error', 'message' => 'El origen no existe']);

        $result = $client->create_shipment($this->full_shipment_data());

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['tracking_number']);
    }

    /** @test 3.3 */
    public function test_create_shipment_payload_contains_tipo_generarGuia2(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try { $client->create_shipment($this->full_shipment_data()); } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('generarGuia2', $body['tipo']);
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
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O', 'city' => 'BOGOTA(CUNDINAMARCA)'],
                'destination' => ['name' => 'D', 'city' => 'MEDELLIN(ANTIOQUIA)'],
                'packages'    => [],
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertCount(1, $body['productos']);
        $this->assertSame('1', $body['productos'][0]['peso']);
    }

    /** @test 3.8 */
    public function test_create_shipment_default_dscontenido(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O'],
                'destination' => ['name' => 'D'],
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('Mercancía general', $body['dscontenido']);
    }

    /** @test 3.9 */
    public function test_create_shipment_default_valorrecaudo_is_zero(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->create_shipment([
                'origin'      => ['name' => 'O'],
                'destination' => ['name' => 'D'],
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame(0, $body['valorrecaudo']);
    }

    /** @test 3.10 */
    public function test_create_shipment_maps_label_url_from_rutaguia(): void
    {
        $client = $this->make_client();
        $this->stub_response($this->successful_guia_response());

        $result = $client->create_shipment($this->full_shipment_data());
        $this->assertStringContainsString('aveonline.co', $result['label_url']);
    }

    /** @test 3.11 */
    public function test_create_shipment_maps_shipment_id_from_numguia(): void
    {
        $client = $this->make_client();
        $this->stub_response($this->successful_guia_response());

        $result = $client->create_shipment($this->full_shipment_data());
        $this->assertSame('21027846356', $result['shipment_id']);
    }

    // ── SECCIÓN 4 — track_shipment ────────────────────────────────────────────

    /** @test 4.1 */
    public function test_track_shipment_maps_all_fields_from_guias_array(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status'  => 'ok',
            'message' => 'registros encontrados',
            'guias'   => [[
                'estado'         => 'EN TRANSITO',
                'dsfechaentrega' => '2026-06-15',
                'destino'        => 'MEDELLIN(ANTIOQUIA)',
                'destinatario'   => 'Comprador Test',
                'transportadora' => 'ENVIA',
                'historicos'     => [
                    ['estado' => 'RECOGIDO', 'fechamostrar' => '2026-06-10 08:00:00', 'descripcion' => 'Recogida realizada', 'novedad' => 0],
                ],
                'ruta_rotulo'    => 'https://app.aveonline.co/rotulo.php?pkid=1',
                'ruta_sticker'   => 'https://app.aveonline.co/sticker.php?pkid=1',
            ]],
        ]);

        $result = $client->track_shipment('21027846356');

        $this->assertSame('EN TRANSITO',        $result['status']);
        $this->assertSame('2026-06-15',          $result['estimated_delivery']);
        $this->assertSame('MEDELLIN(ANTIOQUIA)', $result['current_location']);
        $this->assertSame('Comprador Test',      $result['destinatario']);
        $this->assertSame('ENVIA',               $result['transportadora']);
        $this->assertCount(1,                    $result['events']);
        $this->assertSame('RECOGIDO',            $result['events'][0]['status']);
        $this->assertStringContainsString('aveonline', $result['label_url']);
    }

    /** @test 4.2 */
    public function test_track_shipment_returns_defaults_on_empty_response(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'ok', 'guias' => []]);

        $result = $client->track_shipment('UNKNOWN');

        $this->assertSame('unknown', $result['status']);
        $this->assertSame([],        $result['events']);
        $this->assertSame('',        $result['estimated_delivery']);
        $this->assertSame('',        $result['current_location']);
    }

    /** @test 4.3 */
    public function test_track_shipment_maps_historicos_to_events(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status' => 'ok',
            'guias'  => [[
                'estado'     => 'ENTREGADA',
                'historicos' => [
                    ['estado' => 'EN RUTA',   'fechamostrar' => '2026-06-14', 'descripcion' => 'Salida a reparto', 'novedad' => 0],
                    ['estado' => 'ENTREGADA', 'fechamostrar' => '2026-06-15', 'descripcion' => 'Entregado',        'novedad' => 0],
                ],
            ]],
        ]);

        $result = $client->track_shipment('TRK-X');
        $this->assertIsArray($result['events']);
        $this->assertCount(2, $result['events']);
        $this->assertArrayHasKey('date',        $result['events'][0]);
        $this->assertArrayHasKey('description', $result['events'][0]);
        $this->assertArrayHasKey('status',      $result['events'][0]);
    }

    /** @test 4.4 */
    public function test_track_shipment_payload_contains_tipo_obtenerEstadoAuth(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try { $client->track_shipment('21027846356'); } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('obtenerEstadoAuth', $body['tipo']);
        $this->assertSame('21027846356',       $body['guia']);
    }

    // ── SECCIÓN 5 — get_rates ────────────────────────────────────────────────

    /** @test 5.1 */
    public function test_get_rates_returns_cotizaciones_without_error(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status'       => 'ok',
            'cotizaciones' => [
                ['numbererror' => '-0-', 'codTransportadora' => '29',   'nombreTransportadora' => 'ENVIA', 'total' => 15000, 'diasentrega' => 2],
                ['numbererror' => '-0-', 'codTransportadora' => '1010', 'nombreTransportadora' => 'TCC',   'total' => 18000, 'diasentrega' => 3],
            ],
        ]);

        $rates = $client->get_rates([
            'origin_city'      => 'BOGOTA(CUNDINAMARCA)',
            'destination_city' => 'MEDELLIN(ANTIOQUIA)',
            'weight_kg'        => 1.5,
        ]);

        $this->assertCount(2, $rates);
        $this->assertSame('-0-', $rates[0]['numbererror']);
    }

    /** @test 5.2 */
    public function test_get_rates_returns_empty_when_all_have_errors(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status'       => 'ok',
            'cotizaciones' => [
                ['numbererror' => '-1', 'dataerror' => 'El origen no existe'],
            ],
        ]);

        $this->assertSame([], $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y']));
    }

    /** @test 5.3 */
    public function test_get_rates_accepts_weight_alias(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->get_rates([
                'origin_city'      => 'BOGOTA(CUNDINAMARCA)',
                'destination_city' => 'CALI(VALLE DEL CAUCA)',
                'weight'           => 3.0,
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('3', $body['productos'][0]['peso']);
    }

    /** @test 5.4 */
    public function test_get_rates_canonical_weight_kg_takes_priority(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->get_rates([
                'origin_city'      => 'BOGOTA(CUNDINAMARCA)',
                'destination_city' => 'CALI(VALLE DEL CAUCA)',
                'weight_kg'        => 2.5,
                'weight'           => 99.0,
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('2.5', $body['productos'][0]['peso']);
    }

    /** @test 5.5 */
    public function test_get_rates_defaults_weight_to_one_when_absent(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y']);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('1', $body['productos'][0]['peso']);
    }

    /** @test 5.6 */
    public function test_get_rates_uses_default_dimensions_when_absent(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->get_rates(['origin_city' => 'X', 'destination_city' => 'Y']);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('30', $body['productos'][0]['largo']);
        $this->assertSame('20', $body['productos'][0]['ancho']);
        $this->assertSame('15', $body['productos'][0]['alto']);
    }

    // ── SECCIÓN 6 — get_label ────────────────────────────────────────────────

    /** @test 6.1 */
    public function test_get_label_returns_ruta_rotulo(): void
    {
        $client = $this->make_client();
        $this->stub_response([
            'status' => 'ok',
            'guias'  => [[
                'estado'      => 'ENTREGADA',
                'historicos'  => [],
                'ruta_rotulo' => 'https://app.aveonline.co/rotulo.php?pkid=999',
            ]],
        ]);

        $label = $client->get_label('21027846356');
        $this->assertStringContainsString('aveonline.co', $label);
    }

    /** @test 6.2 */
    public function test_get_label_returns_empty_string_when_no_label(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'ok', 'guias' => [[
            'estado'     => 'ENTREGADA',
            'historicos' => [],
        ]]]);

        $this->assertSame('', $client->get_label('SHIP-002'));
    }

    // ── SECCIÓN 7 — cancel_shipment ──────────────────────────────────────────

    /** @test 7.1 */
    public function test_cancel_shipment_throws_runtime_exception_always(): void
    {
        $client = $this->make_client();
        $this->expectException(\RuntimeException::class);
        $client->cancel_shipment('SHIP-001');
    }

    /** @test 7.2 */
    public function test_cancel_shipment_throws_even_with_valid_id(): void
    {
        $client = $this->make_client();
        $this->stub_response(['cancelled' => true]);
        $this->expectException(\RuntimeException::class);
        $client->cancel_shipment('21027846356');
    }

    /** @test 7.3 */
    public function test_cancel_shipment_throws_invalid_arg_on_empty_id(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->cancel_shipment('');
    }

    /** @test 7.4 */
    public function test_cancel_shipment_throws_invalid_arg_on_whitespace_only(): void
    {
        $client = $this->make_client();
        $this->expectException(InvalidArgumentException::class);
        $client->cancel_shipment('   ');
    }

    // ── SECCIÓN 8 — health_check ─────────────────────────────────────────────

    /** @test 8.1 */
    public function test_health_check_returns_ok_when_auth_succeeds(): void
    {
        $this->set_credentials();
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'status'  => 'ok',
            'message' => 'usuario encontrado',
            'token'   => 'jwt_fresh_token_xyz',
        ]));
        Functions\when('get_transient')->justReturn(false);

        $client = new \LTMS_Api_Aveonline();
        $result = $client->health_check();
        $this->assertSame('ok', $result['status']);
    }

    /** @test 8.2 */
    public function test_health_check_returns_error_when_auth_fails(): void
    {
        $this->set_credentials();
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'status'  => 'error',
            'message' => 'Usuario no encontrado',
        ]));
        Functions\when('get_transient')->justReturn(false);

        $client = new \LTMS_Api_Aveonline();
        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
    }

    /** @test 8.3 */
    public function test_health_check_returns_error_on_network_exception(): void
    {
        $this->set_credentials();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('fail', 'network down'));
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);

        $client = new \LTMS_Api_Aveonline();
        $result = $client->health_check();
        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    // ── SECCIÓN 9 — create_return ────────────────────────────────────────────

    /** @test 9.1 */
    public function test_create_return_success_with_guia_in_response(): void
    {
        $client = $this->make_client();
        $this->stub_response($this->successful_guia_response());

        $result = $client->create_return('SHIP-001', 'customer_request', [
            'origin'      => ['name' => 'D', 'city' => 'MEDELLIN(ANTIOQUIA)'],
            'destination' => ['name' => 'O', 'city' => 'BOGOTA(CUNDINAMARCA)'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('21027846356', $result['tracking_number']);
    }

    /** @test 9.2 */
    public function test_create_return_success_false_when_no_tracking(): void
    {
        $client = $this->make_client();
        $this->stub_response(['status' => 'error', 'message' => 'cannot return']);

        $result = $client->create_return('SHIP-002', 'customer_request', [
            'origin'      => ['name' => 'D'],
            'destination' => ['name' => 'O'],
        ]);

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
    public function test_create_return_payload_has_cartaporte_one(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->create_return('SHIP-XYZ', 'customer_request', [
                'origin'      => ['name' => 'D', 'city' => 'X'],
                'destination' => ['name' => 'O', 'city' => 'Y'],
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertSame('1', $body['cartaporte']);
    }

    /** @test 9.5 */
    public function test_create_return_default_reason_customer_request_in_dscom(): void
    {
        $client = $this->make_client();
        [$ref]  = $this->capture_post_payload();

        try {
            $client->create_return('SHIP-ABC', 'customer_request', [
                'origin'      => ['name' => 'D'],
                'destination' => ['name' => 'O'],
            ]);
        } catch (\RuntimeException $e) {}

        $body = json_decode($ref->captured['body'] ?? '{}', true);
        $this->assertStringContainsString('customer_request', $body['dscom']);
    }

    // ── SECCIÓN 10 — get_default_headers ─────────────────────────────────────

    /** @test 10.1 */
    public function test_default_headers_include_content_type(): void
    {
        $client  = $this->make_client();
        $headers = $this->call($client, 'get_default_headers');
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    /** @test 10.2 */
    public function test_default_headers_do_not_include_x_api_key(): void
    {
        $client  = $this->make_client();
        $headers = $this->call($client, 'get_default_headers');
        $this->assertArrayNotHasKey('X-Api-Key', $headers);
    }

    // ── SECCIÓN 11 — sanitize_email_field ────────────────────────────────────

    /** @test 11.1 */
    public function test_sanitize_email_field_clears_invalid_email(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'sanitize_email_field', 'not-an-email');
        $this->assertSame('', $result, 'Email inválido debe limpiarse');
    }

    /** @test 11.2 */
    public function test_sanitize_email_field_preserves_valid_email(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'sanitize_email_field', 'valido@ejemplo.co');
        $this->assertSame('valido@ejemplo.co', $result);
    }

    /** @test 11.3 */
    public function test_sanitize_email_field_accepts_empty_string(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'sanitize_email_field', '');
        $this->assertSame('', $result);
    }

    // ── SECCIÓN 12 — format_packages ─────────────────────────────────────────

    /** @test 12.1 */
    public function test_format_packages_returns_default_when_empty(): void
    {
        $client = $this->make_client();
        $result = $this->call($client, 'format_packages', []);

        $this->assertCount(1, $result);
        $this->assertSame('1',  $result[0]['peso']);
        $this->assertSame('30', $result[0]['largo']);
        $this->assertSame('20', $result[0]['ancho']);
        $this->assertSame('15', $result[0]['alto']);
        $this->assertSame(1,    $result[0]['unidades']);
    }

    /** @test 12.2 */
    public function test_format_packages_maps_dimensions_as_strings(): void
    {
        $client   = $this->make_client();
        $packages = [['weight_kg' => 3.5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'quantity' => 2]];
        $result   = $this->call($client, 'format_packages', $packages);

        $this->assertSame('3.5', $result[0]['peso']);
        $this->assertSame('40',  $result[0]['largo']);
        $this->assertSame('30',  $result[0]['ancho']);
        $this->assertSame('20',  $result[0]['alto']);
        $this->assertSame(2,     $result[0]['unidades']);
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
        $this->assertSame(1, $result[0]['unidades']);
        $this->assertSame(3, $result[1]['unidades']);
    }

    // ── SECCIÓN 13 — Estructura de clase ─────────────────────────────────────

    /** @test 13.1 */
    public function test_class_extends_abstract_api_client(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Aveonline::class);
        $this->assertSame(\LTMS_Abstract_API_Client::class, $ref->getParentClass()->getName());
    }

    /** @test 13.2 */
    public function test_api_base_constants_point_to_app_aveonline(): void
    {
        $this->assertStringContainsString('app.aveonline.co',  \LTMS_Api_Aveonline::API_BASE_LIVE);
        $this->assertStringContainsString('sandbox',           \LTMS_Api_Aveonline::API_BASE_SANDBOX);
    }
}
