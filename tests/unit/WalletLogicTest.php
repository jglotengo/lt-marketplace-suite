<?php
/**
 * WalletLogicTest — Tests unitarios para LTMS_Business_Wallet (métodos de lógica pura)
 *
 * No requiere DB ni WP — solo métodos de instancia puros.
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
 * @covers LTMS_Business_Wallet
 */
class WalletLogicTest extends TestCase
{
    private \LTMS_Business_Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs([
            'get_option'  => static fn(string $k, mixed $d = false): mixed => $d,
            'update_option' => static fn(): bool => true,
        ]);
        \LTMS_Core_Config::flush_cache();

        // Instantiate without constructor (wallet has no __construct, trait is harmless)
        $r = new ReflectionClass(\LTMS_Business_Wallet::class);
        $this->wallet = $r->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── get_available_balance ─────────────────────────────────────

    /** @test */
    public function test_get_available_balance_subtracts_held(): void
    {
        $this->assertEqualsWithDelta(70000.0, $this->wallet->get_available_balance(100000.0, 30000.0), 0.01);
    }

    /** @test */
    public function test_get_available_balance_zero_held(): void
    {
        $this->assertEqualsWithDelta(50000.0, $this->wallet->get_available_balance(50000.0, 0.0), 0.01);
    }

    /** @test */
    public function test_get_available_balance_clamps_to_zero_when_held_exceeds_balance(): void
    {
        // Method uses max(0.0, ...) so result must be 0, not negative
        $result = $this->wallet->get_available_balance(10000.0, 20000.0);
        $this->assertEqualsWithDelta(0.0, $result, 0.01, 'Balance disponible debe ser 0 cuando held > balance (no negativo)');
    }

    // ── validate_debit ────────────────────────────────────────────

    /** @test */
    public function test_validate_debit_accepts_within_balance(): void
    {
        $this->assertTrue($this->wallet->validate_debit(50000.0, 100000.0));
    }

    /** @test */
    public function test_validate_debit_accepts_exact_balance(): void
    {
        $this->assertTrue($this->wallet->validate_debit(100000.0, 100000.0));
    }

    /** @test */
    public function test_validate_debit_rejects_exceeding_balance(): void
    {
        $this->assertFalse($this->wallet->validate_debit(150000.0, 100000.0));
    }

    /** @test */
    public function test_validate_debit_rejects_zero(): void
    {
        $this->assertFalse($this->wallet->validate_debit(0.0, 100000.0));
    }

    // ── validate_amount ───────────────────────────────────────────

    /** @test */
    public function test_validate_amount_accepts_positive(): void
    {
        $this->assertTrue($this->wallet->validate_amount(1000.0));
        $this->assertTrue($this->wallet->validate_amount(0.01));
    }

    /** @test */
    public function test_validate_amount_rejects_zero(): void
    {
        $this->assertFalse($this->wallet->validate_amount(0.0));
    }

    /** @test */
    public function test_validate_amount_rejects_negative(): void
    {
        $this->assertFalse($this->wallet->validate_amount(-1000.0));
    }

    // ── validate_hold ─────────────────────────────────────────────

    /** @test */
    public function test_validate_hold_accepts_within_available(): void
    {
        $this->assertTrue($this->wallet->validate_hold(30000.0, 100000.0));
    }

    /** @test */
    public function test_validate_hold_rejects_exceeding_available(): void
    {
        $this->assertFalse($this->wallet->validate_hold(200000.0, 100000.0));
    }

    /** @test */
    public function test_validate_hold_rejects_zero(): void
    {
        $this->assertFalse($this->wallet->validate_hold(0.0, 100000.0));
    }

    // ── is_valid_transaction_type ─────────────────────────────────

    /** @test */
    public function test_valid_transaction_types(): void
    {
        foreach (['credit', 'debit', 'hold', 'release', 'commission', 'payout'] as $type) {
            $this->assertTrue(
                $this->wallet->is_valid_transaction_type($type),
                "'{$type}' debe ser válido"
            );
        }
    }

    /** @test */
    public function test_invalid_transaction_type_rejected(): void
    {
        $this->assertFalse($this->wallet->is_valid_transaction_type('transferencia_magica'));
        $this->assertFalse($this->wallet->is_valid_transaction_type(''));
    }

    // ── Static methods exist ──────────────────────────────────────

    /** @test */
    public function test_has_all_static_methods(): void
    {
        $r       = new ReflectionClass(\LTMS_Business_Wallet::class);
        $missing = array_filter(
            ['get_or_create', 'credit', 'debit', 'hold', 'release', 'execute_transaction', 'get_balance', 'get_transactions'],
            fn($m) => ! $r->hasMethod($m)
        );
        $this->assertEmpty($missing, 'Métodos estáticos faltantes: ' . implode(', ', $missing));
    }
}
