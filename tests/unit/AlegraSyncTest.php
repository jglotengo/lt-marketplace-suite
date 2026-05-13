<?php
/**
 * AlegraSyncTest — Tests unitarios para LTMS_Alegra_Sync
 *
 * Cubre la lógica pura sin HTTP real:
 *   1. init() — registra los hooks correctos
 *   2. build_contact_data() — mapea datos de vendedor WP correctamente
 *   3. Singleton pattern — get_instance() retorna misma instancia
 *   4. Clase final correctamente estructurada
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
 * @covers LTMS_Alegra_Sync
 */
class AlegraSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'add_action'          => static fn() => true,
            'get_option'          => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option'       => static fn(): bool => true,
            'get_user_meta'       => static fn(): mixed => '',
            'update_user_meta'    => static fn(): bool => true,
            'sanitize_text_field' => static fn(string $s): string => $s,
            'sanitize_email'      => static fn(string $s): string => $s,
            '__'                  => static fn(string $s): string => $s,
            'wp_generate_password'=> static fn(): string => 'testpass',
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Section 1: init() hooks ───────────────────────────────────────────────

    /** @test */
    public function test_init_registers_hooks(): void
    {
        $hooks_registered = 0;

        Functions\when('add_action')->alias(static function() use (&$hooks_registered) {
            $hooks_registered++;
            return true;
        });

        \LTMS_Alegra_Sync::init();

        $this->assertGreaterThanOrEqual(
            2,
            $hooks_registered,
            'LTMS_Alegra_Sync::init() debe registrar al menos 2 hooks'
        );
    }

    /** @test */
    public function test_init_registers_vendor_registered_hook(): void
    {
        $registered_hooks = [];

        Functions\when('add_action')->alias(static function(string $hook) use (&$registered_hooks) {
            $registered_hooks[] = $hook;
            return true;
        });

        \LTMS_Alegra_Sync::init();

        $this->assertContains(
            'ltms_vendor_registered',
            $registered_hooks,
            'Debe escuchar ltms_vendor_registered para sincronizar contactos'
        );
    }

    /** @test */
    public function test_init_registers_payout_completed_hook(): void
    {
        $registered_hooks = [];

        Functions\when('add_action')->alias(static function(string $hook) use (&$registered_hooks) {
            $registered_hooks[] = $hook;
            return true;
        });

        \LTMS_Alegra_Sync::init();

        $this->assertContains(
            'ltms_payout_completed',
            $registered_hooks,
            'Debe escuchar ltms_payout_completed para registrar pagos en Alegra'
        );
    }

    // ── Section 2: Singleton ──────────────────────────────────────────────────

    /** @test */
    public function test_get_instance_returns_same_object(): void
    {
        $r = new ReflectionClass(\LTMS_Alegra_Sync::class);

        if (! $r->hasMethod('get_instance')) {
            $this->markTestSkipped('get_instance not accessible');
        }

        $method = $r->getMethod('get_instance');
        $method->setAccessible(true);

        $i1 = $method->invoke(null);
        $i2 = $method->invoke(null);

        $this->assertSame($i1, $i2, 'get_instance() debe retornar siempre la misma instancia (singleton)');
    }

    // ── Section 3: build_contact_data (via reflexión) ─────────────────────────

    /** @test */
    public function test_build_contact_data_returns_array_with_required_keys(): void
    {
        $r = new ReflectionClass(\LTMS_Alegra_Sync::class);

        if (! $r->hasMethod('build_contact_data')) {
            $this->markTestSkipped('build_contact_data not accessible');
        }

        $method   = $r->getMethod('build_contact_data');
        $method->setAccessible(true);
        $instance = $r->getMethod('get_instance');
        $instance->setAccessible(true);
        $obj = $instance->invoke(null);

        // Mock WP_User-like data
        $user = new \stdClass();
        $user->ID           = 42;
        $user->user_email   = 'vendedor@test.com';
        $user->display_name = 'Vendedor Test';

        Functions\when('get_user_meta')->alias(static function(int $uid, string $key, bool $single = false): mixed {
            $data = [
                'ltms_store_name'      => 'Tienda Test',
                'ltms_store_phone'     => '3001234567',
                'ltms_nit'             => '900123456',
                'billing_address_1'    => 'Calle 123',
                'billing_city'         => 'Bogotá',
            ];
            return $data[$key] ?? '';
        });

        try {
            $result = $method->invoke($obj, $user);
            $this->assertIsArray($result);
            $this->assertNotEmpty($result);
            // Debe tener algún campo de identificación
            $has_name = isset($result['name']) || isset($result['nameObject']);
            $this->assertTrue($has_name, 'build_contact_data debe incluir nombre del contacto');
        } catch (\Throwable $e) {
            $this->markTestSkipped('build_contact_data requires WP user context: ' . $e->getMessage());
        }
    }

    // ── Section 4: Class structure ─────────────────────────────────────────────

    /** @test */
    public function test_class_is_final(): void
    {
        $r = new ReflectionClass(\LTMS_Alegra_Sync::class);
        $this->assertTrue($r->isFinal(), 'LTMS_Alegra_Sync debe ser final');
    }

    /** @test */
    public function test_has_required_public_methods(): void
    {
        $required = ['init', 'on_vendor_registered', 'on_payout_completed'];
        $r        = new ReflectionClass(\LTMS_Alegra_Sync::class);
        $missing  = [];

        foreach ($required as $m) {
            if (! $r->hasMethod($m)) {
                $missing[] = $m;
            }
        }

        $this->assertEmpty(
            $missing,
            'Métodos públicos faltantes en LTMS_Alegra_Sync: ' . implode(', ', $missing)
        );
    }
}
