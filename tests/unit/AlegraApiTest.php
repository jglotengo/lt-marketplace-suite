<?php
/**
 * AlegraApiTest — Tests unitarios para LTMS_Api_Alegra
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

    /** Inject credentials directly into Config cache (no update_option needed) */
    private function inject_credentials(): void
    {
        $r = new ReflectionClass(\LTMS_Core_Config::class);

        $cache = $r->getProperty('cache');
        $cache->setAccessible(true);
        $cur = $cache->getValue(null);
        $cur['ltms_alegra_email'] = 'test@empresa.com';
        $cur['ltms_alegra_token'] = 'plain_token_qa';
        $cache->setValue(null, $cur);

        $settings = $r->getProperty('settings');
        $settings->setAccessible(true);
        $cur = $settings->getValue(null) ?? [];
        $cur['ltms_alegra_email'] = 'test@empresa.com';
        $cur['ltms_alegra_token'] = 'plain_token_qa';
        $settings->setValue(null, $cur);
    }

    private function make_client(): \LTMS_Api_Alegra
    {
        $this->inject_credentials();
        return new \LTMS_Api_Alegra();
    }

    /** @test */
    public function test_constructor_throws_when_no_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        new \LTMS_Api_Alegra();
    }

    /** @test */
    public function test_constructor_succeeds_with_credentials(): void
    {
        $this->assertInstanceOf(\LTMS_Api_Alegra::class, $this->make_client());
    }

    /** @test */
    public function test_get_provider_slug_returns_alegra(): void
    {
        $this->assertSame('alegra', $this->make_client()->get_provider_slug());
    }

    /** @test */
    public function test_format_invoice_items_returns_correct_structure(): void
    {
        $client = $this->make_client();
        $r      = new ReflectionClass($client);
        if (! $r->hasMethod('format_invoice_items')) {
            $this->markTestSkipped('format_invoice_items not present');
        }
        $method = $r->getMethod('format_invoice_items');
        $method->setAccessible(true);
        $result = $method->invoke($client, [
            ['description' => 'Test', 'quantity' => 2, 'price' => 50000.0, 'tax' => [], 'alegra_id' => 1],
        ]);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame(2, (int) $result[0]['quantity']);
    }

    /** @test */
    public function test_format_invoice_items_empty_returns_empty_array(): void
    {
        $client = $this->make_client();
        $r      = new ReflectionClass($client);
        if (! $r->hasMethod('format_invoice_items')) {
            $this->markTestSkipped('format_invoice_items not present');
        }
        $method = $r->getMethod('format_invoice_items');
        $method->setAccessible(true);
        $this->assertSame([], $method->invoke($client, []));
    }

    /** @test */
    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(\LTMS_Api_Alegra::class))->isFinal());
    }

    /** @test */
    public function test_extends_abstract_api_client(): void
    {
        $this->assertTrue((new ReflectionClass(\LTMS_Api_Alegra::class))->isSubclassOf(\LTMS_Abstract_API_Client::class));
    }

    /** @test */
    public function test_has_required_public_methods(): void
    {
        $r       = new ReflectionClass(\LTMS_Api_Alegra::class);
        $missing = array_filter(
            ['get_provider_slug', 'health_check', 'create_contact', 'get_or_create_contact',
             'create_invoice', 'get_invoice', 'send_invoice_email', 'create_payment', 'get_company'],
            fn($m) => ! $r->hasMethod($m)
        );
        $this->assertEmpty($missing, 'Métodos faltantes: ' . implode(', ', $missing));
    }
}
