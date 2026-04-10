<?php
/**
 * AbstractApiClientTest — Tests unitarios para LTMS_Abstract_API_Client
 *
 * Cubre los métodos con lógica pura testeable sin HTTP real:
 * - redact_sensitive_data(): redacción de campos sensibles en requests
 * - extract_error_message(): extracción de mensajes de error de respuestas API
 * - get_provider_slug(): getter básico
 *
 * perform_request() y health_check() dependen de wp_remote_request
 * y se testean en integración.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers LTMS_Abstract_API_Client
 */
class AbstractApiClientTest extends TestCase
{
    /** @var \LTMS_Abstract_API_Client */
    private object $client;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'sanitize_text_field' => static fn(string $s): string => $s,
        ]);

        // Subclase concreta mínima — implementa health_check() y get_provider_slug()
        $this->client = new class extends \LTMS_Abstract_API_Client {
            public string $api_url       = 'https://api.example.com';
            public string $provider_slug = 'test_provider';

            public function health_check(): array
            {
                return ['status' => 'ok', 'message' => 'test'];
            }

            // Expone métodos protected para testing
            public function publicRedact(array $data): array
            {
                return $this->redact_sensitive_data($data);
            }

            public function publicExtractError(array $response, int $code): string
            {
                return $this->extract_error_message($response, $code);
            }
        };
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_provider_slug()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_provider_slug_returns_configured_slug(): void
    {
        $this->assertSame('test_provider', $this->client->get_provider_slug());
    }

    // ════════════════════════════════════════════════════════════════════════
    // redact_sensitive_data() — campos individuales
    // ════════════════════════════════════════════════════════════════════════

    public function test_redact_card_number(): void
    {
        $result = $this->client->publicRedact(['card_number' => '4111111111111111']);
        $this->assertSame('[REDACTED]', $result['card_number']);
    }

    public function test_redact_cvv(): void
    {
        $result = $this->client->publicRedact(['cvv' => '123']);
        $this->assertSame('[REDACTED]', $result['cvv']);
    }

    public function test_redact_cvv2(): void
    {
        $result = $this->client->publicRedact(['cvv2' => '456']);
        $this->assertSame('[REDACTED]', $result['cvv2']);
    }

    public function test_redact_expiry(): void
    {
        $result = $this->client->publicRedact(['expiry' => '12/26']);
        $this->assertSame('[REDACTED]', $result['expiry']);
    }

    public function test_redact_pin(): void
    {
        $result = $this->client->publicRedact(['pin' => '1234']);
        $this->assertSame('[REDACTED]', $result['pin']);
    }

    public function test_redact_password(): void
    {
        $result = $this->client->publicRedact(['password' => 'super_secret']);
        $this->assertSame('[REDACTED]', $result['password']);
    }

    public function test_redact_secret(): void
    {
        $result = $this->client->publicRedact(['secret' => 'sk_live_abc123']);
        $this->assertSame('[REDACTED]', $result['secret']);
    }

    public function test_redact_private_key(): void
    {
        $result = $this->client->publicRedact(['private_key' => '-----BEGIN RSA-----']);
        $this->assertSame('[REDACTED]', $result['private_key']);
    }

    public function test_redact_api_key(): void
    {
        $result = $this->client->publicRedact(['api_key' => 'pk_test_xyz']);
        $this->assertSame('[REDACTED]', $result['api_key']);
    }

    public function test_redact_document(): void
    {
        $result = $this->client->publicRedact(['document' => '12345678']);
        $this->assertSame('[REDACTED]', $result['document']);
    }

    public function test_redact_document_number(): void
    {
        $result = $this->client->publicRedact(['document_number' => '900123456-1']);
        $this->assertSame('[REDACTED]', $result['document_number']);
    }

    public function test_redact_nit(): void
    {
        $result = $this->client->publicRedact(['nit' => '900123456-1']);
        $this->assertSame('[REDACTED]', $result['nit']);
    }

    public function test_redact_rfc(): void
    {
        $result = $this->client->publicRedact(['rfc' => 'XAXX010101000']);
        $this->assertSame('[REDACTED]', $result['rfc']);
    }

    public function test_redact_curp(): void
    {
        $result = $this->client->publicRedact(['curp' => 'XEXX010101HNEXXXA4']);
        $this->assertSame('[REDACTED]', $result['curp']);
    }

    public function test_redact_cedula(): void
    {
        $result = $this->client->publicRedact(['cedula' => '12345678']);
        $this->assertSame('[REDACTED]', $result['cedula']);
    }

    public function test_redact_nuip(): void
    {
        $result = $this->client->publicRedact(['nuip' => '12345678']);
        $this->assertSame('[REDACTED]', $result['nuip']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // redact_sensitive_data() — campos seguros se preservan
    // ════════════════════════════════════════════════════════════════════════

    public function test_preserves_amount(): void
    {
        $result = $this->client->publicRedact(['amount' => 15000]);
        $this->assertSame(15000, $result['amount']);
    }

    public function test_preserves_currency(): void
    {
        $result = $this->client->publicRedact(['currency' => 'COP']);
        $this->assertSame('COP', $result['currency']);
    }

    public function test_preserves_order_id(): void
    {
        $result = $this->client->publicRedact(['order_id' => 42]);
        $this->assertSame(42, $result['order_id']);
    }

    public function test_preserves_customer_name(): void
    {
        $result = $this->client->publicRedact(['customer_name' => 'Juan Pérez']);
        $this->assertSame('Juan Pérez', $result['customer_name']);
    }

    public function test_preserves_email(): void
    {
        $result = $this->client->publicRedact(['email' => 'juan@example.com']);
        $this->assertSame('juan@example.com', $result['email']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // redact_sensitive_data() — detección case-insensitive
    // ════════════════════════════════════════════════════════════════════════

    public function test_redact_is_case_insensitive_for_api_key(): void
    {
        $result = $this->client->publicRedact(['API_KEY' => 'pk_test']);
        $this->assertSame('[REDACTED]', $result['API_KEY']);
    }

    public function test_redact_is_case_insensitive_for_password(): void
    {
        $result = $this->client->publicRedact(['USER_PASSWORD' => 'hunter2']);
        $this->assertSame('[REDACTED]', $result['USER_PASSWORD']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // redact_sensitive_data() — redacción recursiva en arrays anidados
    // ════════════════════════════════════════════════════════════════════════

    public function test_redact_nested_array(): void
    {
        $data = [
            'customer' => [
                'name'       => 'Juan',
                'card_number' => '4111111111111111',
            ],
            'amount' => 5000,
        ];

        $result = $this->client->publicRedact($data);

        $this->assertSame('Juan',        $result['customer']['name']);
        $this->assertSame('[REDACTED]',  $result['customer']['card_number']);
        $this->assertSame(5000,          $result['amount']);
    }

    public function test_redact_deeply_nested_secret(): void
    {
        $data = [
            'payment' => [
                'card' => [
                    'cvv' => '321',
                    'last4' => '1234',
                ],
            ],
        ];

        $result = $this->client->publicRedact($data);
        $this->assertSame('[REDACTED]', $result['payment']['card']['cvv']);
        $this->assertSame('1234',       $result['payment']['card']['last4']);
    }

    public function test_redact_mix_of_safe_and_sensitive_fields(): void
    {
        $data = [
            'order_id'    => 99,
            'api_key'     => 'sk_live_xxx',
            'total'       => 50000,
            'nit'         => '900123456',
            'description' => 'Compra de prueba',
        ];

        $result = $this->client->publicRedact($data);

        $this->assertSame(99,            $result['order_id']);
        $this->assertSame('[REDACTED]',  $result['api_key']);
        $this->assertSame(50000,         $result['total']);
        $this->assertSame('[REDACTED]',  $result['nit']);
        $this->assertSame('Compra de prueba', $result['description']);
    }

    public function test_redact_empty_array_returns_empty(): void
    {
        $result = $this->client->publicRedact([]);
        $this->assertSame([], $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // extract_error_message() — claves conocidas
    // ════════════════════════════════════════════════════════════════════════

    public function test_extract_message_key(): void
    {
        $result = $this->client->publicExtractError(['message' => 'Card declined'], 400);
        $this->assertSame('Card declined', $result);
    }

    public function test_extract_error_message_key(): void
    {
        $result = $this->client->publicExtractError(['error_message' => 'Invalid token'], 401);
        $this->assertSame('Invalid token', $result);
    }

    public function test_extract_error_key(): void
    {
        $result = $this->client->publicExtractError(['error' => 'Unauthorized'], 401);
        $this->assertSame('Unauthorized', $result);
    }

    public function test_extract_description_key(): void
    {
        $result = $this->client->publicExtractError(['description' => 'Insufficient funds'], 402);
        $this->assertSame('Insufficient funds', $result);
    }

    public function test_extract_detail_key(): void
    {
        $result = $this->client->publicExtractError(['detail' => 'Not found'], 404);
        $this->assertSame('Not found', $result);
    }

    public function test_extract_msg_key(): void
    {
        $result = $this->client->publicExtractError(['msg' => 'Rate limit exceeded'], 429);
        $this->assertSame('Rate limit exceeded', $result);
    }

    public function test_extract_error_message_camel_case(): void
    {
        $result = $this->client->publicExtractError(['errorMessage' => 'Service unavailable'], 503);
        $this->assertSame('Service unavailable', $result);
    }

    public function test_extract_fallback_to_http_error_when_no_known_key(): void
    {
        $result = $this->client->publicExtractError(['code' => 'ERR_001', 'data' => []], 400);
        $this->assertSame('HTTP Error 400', $result);
    }

    public function test_extract_fallback_with_empty_response(): void
    {
        $result = $this->client->publicExtractError([], 500);
        $this->assertSame('HTTP Error 500', $result);
    }

    public function test_extract_ignores_non_string_values(): void
    {
        // 'message' existe pero no es string — no debe usarse
        $result = $this->client->publicExtractError(['message' => ['nested' => 'error']], 400);
        $this->assertSame('HTTP Error 400', $result);
    }

    public function test_extract_prioritizes_message_over_error(): void
    {
        // Tanto 'message' como 'error' existen — 'message' tiene prioridad
        $result = $this->client->publicExtractError([
            'message' => 'Primary error message',
            'error'   => 'Secondary error',
        ], 400);
        $this->assertSame('Primary error message', $result);
    }
}
