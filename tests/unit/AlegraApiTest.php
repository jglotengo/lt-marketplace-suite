<?php
/**
 * AlegraApiTest — Tests unitarios para LTMS_Api_Alegra
 *
 * Cubre la lógica pura sin HTTP real:
 *   1. Constructor — excepción si faltan credenciales
 *   2. get_provider_slug() — retorna 'alegra'
 *   3. format_invoice_items() — estructura correcta de líneas de factura
 *   4. Clase final y método privado correctamente definidos
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
 * @covers LTMS_Api_Alegra
 */
class AlegraApiTest extends TestCase
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
        \LTMS_Core_Config::set('ltms_alegra_token', \LTMS_Core_Security::encrypt('test_user:test_token'));
        \LTMS_Core_Config::set('ltms_alegra_user',  \LTMS_Core_Security::encrypt('test@empresa.com'));
    }

    private function make_client(): \LTMS_Api_Alegra
    {
        $this->set_credentials();
        return new \LTMS_Api_Alegra();
    }

    // ── Section 1: Constructor ────────────────────────────────────────────────

    /** @test */
    public function test_constructor_throws_when_no_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        // Sin credenciales → excepción
        new \LTMS_Api_Alegra();
    }

    /** @test */
    public function test_constructor_succeeds_with_credentials(): void
    {
        $client = $this->make_client();
        $this->assertInstanceOf(\LTMS_Api_Alegra::class, $client);
    }

    // ── Section 2: get_provider_slug ─────────────────────────────────────────

    /** @test */
    public function test_get_provider_slug_returns_alegra(): void
    {
        $client = $this->make_client();
        $this->assertSame('alegra', $client->get_provider_slug());
    }

    // ── Section 3: format_invoice_items (via reflexión) ───────────────────────

    /** @test */
    public function test_format_invoice_items_returns_correct_structure(): void
    {
        $client = $this->make_client();
        $r      = new ReflectionClass($client);

        if (! $r->hasMethod('format_invoice_items')) {
            $this->markTestSkipped('format_invoice_items not accessible');
        }

        $method = $r->getMethod('format_invoice_items');
        $method->setAccessible(true);

        $items = [
            [
                'description' => 'Producto de prueba',
                'quantity'    => 2,
                'price'       => 50000.0,
                'tax'         => [],
                'allegra_id'  => 42,
            ],
        ];

        $result = $method->invoke($client, $items);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('quantity', $first);
        $this->assertSame(2, (int) $first['quantity']);
    }

    /** @test */
    public function test_format_invoice_items_empty_returns_empty_array(): void
    {
        $client = $this->make_client();
        $r      = new ReflectionClass($client);

        if (! $r->hasMethod('format_invoice_items')) {
            $this->markTestSkipped('format_invoice_items not accessible');
        }

        $method = $r->getMethod('format_invoice_items');
        $method->setAccessible(true);

        $result = $method->invoke($client, []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── Section 4: Class structure ────────────────────────────────────────────

    /** @test */
    public function test_class_is_final(): void
    {
        $r = new ReflectionClass(\LTMS_Api_Alegra::class);
        $this->assertTrue($r->isFinal(), 'LTMS_Api_Alegra debe ser final');
    }

    /** @test */
    public function test_extends_abstract_api_client(): void
    {
        $r = new ReflectionClass(\LTMS_Api_Alegra::class);
        $this->assertTrue(
            $r->isSubclassOf(\LTMS_Abstract_API_Client::class),
            'LTMS_Api_Alegra debe extender LTMS_Abstract_API_Client'
        );
    }

    /** @test */
    public function test_has_all_required_public_methods(): void
    {
        $required = [
            'get_provider_slug', 'health_check',
            'create_contact', 'find_contact_by_identification', 'get_or_create_contact',
            'create_item', 'update_item',
            'create_invoice', 'get_invoice', 'send_invoice_email', 'list_invoices',
            'create_payment', 'get_number_templates', 'get_company', 'subscribe_webhook',
        ];

        $r       = new ReflectionClass(\LTMS_Api_Alegra::class);
        $missing = [];

        foreach ($required as $method) {
            if (! $r->hasMethod($method)) {
                $missing[] = $method;
            }
        }

        $this->assertEmpty(
            $missing,
            'Métodos faltantes en LTMS_Api_Alegra: ' . implode(', ', $missing)
        );
    }
}
