<?php
/**
 * AlegraWebhookHandlerTest — Tests unitarios para LTMS_Alegra_Webhook_Handler
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
 * @covers LTMS_Alegra_Webhook_Handler
 */
class AlegraWebhookHandlerTest extends TestCase
{
    private string $secret = 'test_webhook_secret_alegra_2026';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Must stub ALL functions that may be called — including update_option
        Functions\stubs([
            'add_action'          => static fn() => true,
            'register_rest_route' => static fn() => true,
            'get_option'          => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'       => static fn(): bool => true,
        ]);

        \LTMS_Core_Config::flush_cache();
        $this->inject_secret($this->secret);
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function inject_secret(string $secret): void
    {
        $r = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $r->getProperty('cache');
        $cache->setAccessible(true);
        $cur = $cache->getValue(null);
        // Store plaintext — verify_signature will decrypt or use as-is depending on implementation
        $cur['ltms_alegra_webhook_secret'] = $secret;
        $cache->setValue(null, $cur);
        $settings = $r->getProperty('settings');
        $settings->setAccessible(true);
        $cur2 = $settings->getValue(null) ?? [];
        $cur2['ltms_alegra_webhook_secret'] = $secret;
        $settings->setValue(null, $cur2);
    }

    private function call_verify(string $payload, string $sig): bool|null
    {
        $r = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        if (! $r->hasMethod('verify_signature')) {
            $this->markTestSkipped('verify_signature not accessible');
        }
        $method = $r->getMethod('verify_signature');
        $method->setAccessible(true);
        return $method->invoke(null, $payload, $sig);
    }

    /** @test */
    public function test_verify_signature_accepts_correct_hmac(): void
    {
        $payload = '{"event":"invoice.created","data":{"id":123}}';
        $sig     = hash_hmac('sha256', $payload, $this->secret);
        $this->assertTrue($this->call_verify($payload, $sig), 'Firma válida debe ser aceptada');
    }

    /** @test */
    public function test_verify_signature_rejects_wrong_hmac(): void
    {
        $payload = '{"event":"invoice.created","data":{"id":123}}';
        $this->assertFalse($this->call_verify($payload, 'firma_incorrecta'), 'Firma inválida debe ser rechazada');
    }

    /** @test */
    public function test_verify_signature_rejects_empty_signature(): void
    {
        $this->assertFalse($this->call_verify('{"event":"test"}', ''), 'Firma vacía debe ser rechazada');
    }

    /** @test */
    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class))->isFinal());
    }

    /** @test */
    public function test_init_is_public_static(): void
    {
        $r = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        $m = $r->getMethod('init');
        $this->assertTrue($m->isStatic());
        $this->assertTrue($m->isPublic());
    }

    /** @test */
    public function test_has_handle_method(): void
    {
        $this->assertTrue(
            (new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class))->hasMethod('handle')
        );
    }
}
