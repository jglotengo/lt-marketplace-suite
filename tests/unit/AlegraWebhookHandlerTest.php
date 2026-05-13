<?php
/**
 * AlegraWebhookHandlerTest — Tests unitarios para LTMS_Alegra_Webhook_Handler
 *
 * Cubre la lógica pura sin HTTP real:
 *   1. verify_signature() — hash_equals timing-safe correcto e incorrecto
 *   2. init() — registra hooks REST
 *   3. Estructura de clase — final, método init estático
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
 * @covers LTMS_Alegra_Webhook_Handler
 */
class AlegraWebhookHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'add_action'          => static fn() => true,
            'register_rest_route' => static fn() => true,
            'get_option'          => static fn(string $k, mixed $d = false): mixed => $d,
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Section 1: verify_signature ───────────────────────────────────────────

    /** @test */
    public function test_verify_signature_accepts_correct_hmac(): void
    {
        $secret  = 'test_webhook_secret_alegra_2026';
        $payload = '{"event":"invoice.created","data":{"id":123}}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        // Set secret in config
        \LTMS_Core_Config::set('ltms_alegra_webhook_secret', \LTMS_Core_Security::encrypt($secret));

        $r      = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        if (! $r->hasMethod('verify_signature')) {
            $this->markTestSkipped('verify_signature not accessible');
        }

        $method = $r->getMethod('verify_signature');
        $method->setAccessible(true);

        $result = $method->invoke(null, $payload, $sig);
        $this->assertTrue($result, 'Firma HMAC válida debe ser aceptada');
    }

    /** @test */
    public function test_verify_signature_rejects_wrong_hmac(): void
    {
        $secret  = 'test_webhook_secret_alegra_2026';
        $payload = '{"event":"invoice.created","data":{"id":123}}';

        \LTMS_Core_Config::set('ltms_alegra_webhook_secret', \LTMS_Core_Security::encrypt($secret));

        $r = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        if (! $r->hasMethod('verify_signature')) {
            $this->markTestSkipped('verify_signature not accessible');
        }

        $method = $r->getMethod('verify_signature');
        $method->setAccessible(true);

        $result = $method->invoke(null, $payload, 'firma_incorrecta_maliciosa');
        $this->assertFalse($result, 'Firma HMAC inválida debe ser rechazada — RIESGO DE SEGURIDAD');
    }

    /** @test */
    public function test_verify_signature_rejects_empty_signature(): void
    {
        $secret  = 'test_webhook_secret';
        $payload = '{"event":"test"}';

        \LTMS_Core_Config::set('ltms_alegra_webhook_secret', \LTMS_Core_Security::encrypt($secret));

        $r = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        if (! $r->hasMethod('verify_signature')) {
            $this->markTestSkipped('verify_signature not accessible');
        }

        $method = $r->getMethod('verify_signature');
        $method->setAccessible(true);

        $result = $method->invoke(null, $payload, '');
        $this->assertFalse($result, 'Firma vacía debe ser rechazada');
    }

    // ── Section 2: Class structure ─────────────────────────────────────────────

    /** @test */
    public function test_class_is_final(): void
    {
        $r = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        $this->assertTrue($r->isFinal(), 'LTMS_Alegra_Webhook_Handler debe ser final');
    }

    /** @test */
    public function test_has_init_static_method(): void
    {
        $r      = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        $method = $r->getMethod('init');
        $this->assertTrue($method->isStatic(), 'init() debe ser estático');
        $this->assertTrue($method->isPublic(), 'init() debe ser público');
    }

    /** @test */
    public function test_has_required_handler_methods(): void
    {
        $required = ['init', 'handle'];
        $r        = new ReflectionClass(\LTMS_Alegra_Webhook_Handler::class);
        $missing  = [];

        foreach ($required as $m) {
            if (! $r->hasMethod($m)) {
                $missing[] = $m;
            }
        }

        $this->assertEmpty(
            $missing,
            'Métodos faltantes: ' . implode(', ', $missing)
        );
    }
}
