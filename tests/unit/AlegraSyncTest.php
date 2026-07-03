<?php
/**
 * AlegraSyncTest — Tests unitarios para LTMS_Alegra_Sync
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
        ]);

        \LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Inject config values directly into cache (no update_option round-trip) */
    private function inject_config(array $values): void
    {
        $r = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $r->getProperty('cache');
        $cache->setAccessible(true);
        $cur = array_merge($cache->getValue(null), $values);
        $cache->setValue(null, $cur);
        $settings = $r->getProperty('settings');
        $settings->setAccessible(true);
        $cur2 = array_merge($settings->getValue(null) ?? [], $values);
        $settings->setValue(null, $cur2);
    }

    /** Enable Alegra so init() doesn't early-return */
    private function enable_alegra(): void
    {
        $this->inject_config(['ltms_alegra_enabled' => 'yes']);
    }

    // ── init() hooks ──────────────────────────────────────────────

    /** @test */
    public function test_init_early_returns_when_disabled(): void
    {
        // Alegra disabled (default) — init should register 0 hooks
        $count = 0;
        Functions\when('add_action')->alias(static function() use (&$count) {
            $count++;
        });
        \LTMS_Alegra_Sync::init();
        $this->assertSame(0, $count, 'init() debe salir temprano cuando Alegra está deshabilitado');
    }

    /** @test */
    public function test_init_registers_hooks_when_enabled(): void
    {
        $this->enable_alegra();
        $count = 0;
        Functions\when('add_action')->alias(static function() use (&$count) {
            $count++;
        });
        \LTMS_Alegra_Sync::init();
        $this->assertGreaterThanOrEqual(2, $count, 'init() debe registrar ≥2 hooks cuando Alegra está habilitado');
    }

    /** @test */
    public function test_init_registers_vendor_registered_hook_when_enabled(): void
    {
        $this->enable_alegra();
        $hooks = [];
        Functions\when('add_action')->alias(static function(string $hook) use (&$hooks) {
            $hooks[] = $hook;
        });
        \LTMS_Alegra_Sync::init();
        $this->assertContains('ltms_vendor_registered', $hooks);
    }

    /** @test */
    public function test_init_registers_payout_completed_hook_when_enabled(): void
    {
        $this->enable_alegra();
        $hooks = [];
        Functions\when('add_action')->alias(static function(string $hook) use (&$hooks) {
            $hooks[] = $hook;
        });
        \LTMS_Alegra_Sync::init();
        $this->assertContains('ltms_payout_completed', $hooks);
    }

    /** @test */
    public function test_init_registers_order_completed_hook_when_enabled(): void
    {
        $this->enable_alegra();
        $hooks = [];
        Functions\when('add_action')->alias(static function(string $hook) use (&$hooks) {
            $hooks[] = $hook;
        });
        \LTMS_Alegra_Sync::init();
        $this->assertContains('woocommerce_order_status_completed', $hooks);
    }

    // ── Class structure ───────────────────────────────────────────

    /** @test */
    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(\LTMS_Alegra_Sync::class))->isFinal());
    }

    /** @test */
    public function test_has_required_methods(): void
    {
        $r       = new ReflectionClass(\LTMS_Alegra_Sync::class);
        $missing = array_filter(
            ['init', 'on_vendor_registered', 'on_payout_completed', 'on_order_completed'],
            fn($m) => ! $r->hasMethod($m)
        );
        $this->assertEmpty($missing, 'Métodos faltantes: ' . implode(', ', $missing));
    }
}
