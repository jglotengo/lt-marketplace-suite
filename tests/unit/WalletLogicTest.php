<?php
/**
 * WalletLogicTest — Tests unitarios para LTMS_Business_Wallet (métodos de lógica pura)
 *
 * Cubre métodos de instancia sin DB ni WP:
 *   1. get_available_balance() — balance - held
 *   2. validate_debit() — no excede balance disponible
 *   3. validate_amount() — positivo, no cero, no negativo
 *   4. validate_hold() — no supera disponible
 *   5. is_valid_transaction_type() — tipos válidos e inválidos
 *
 * Los métodos estáticos (get_or_create, credit, debit, hold, etc.) requieren
 * $wpdb y se cubren en los integration tests del servidor.
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
            'add_action'  => static fn() => true,
        ]);

        \LTMS_Core_Config::flush_cache();

        // Instanciar vía reflexión (constructor puede ser privado en singleton)
        $r = new ReflectionClass(\LTMS_Business_Wallet::class);
        if ($r->getConstructor()?->isPrivate()) {
            $this->wallet = $r->newInstanceWithoutConstructor();
        } else {
            $this->wallet = new \LTMS_Business_Wallet();
        }
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── get_available_balance ─────────────────────────────────────────────────

    /** @test */
    public function test_get_available_balance_subtracts_held(): void
    {
        $available = $this->wallet->get_available_balance(100000.0, 30000.0);
        $this->assertEqualsWithDelta(70000.0, $available, 0.01);
    }

    /** @test */
    public function test_get_available_balance_zero_held(): void
    {
        $available = $this->wallet->get_available_balance(50000.0, 0.0);
        $this->assertEqualsWithDelta(50000.0, $available, 0.01);
    }

    /** @test */
    public function test_get_available_balance_cannot_be_negative(): void
    {
        // Si held > balance, el disponible debe ser 0 (no negativo)
        $available = $this->wallet->get_available_balance(10000.0, 20000.0);
        $this->assertGreaterThanOrEqual(0.0, $available, 'El balance disponible no puede ser negativo');
    }

    // ── validate_debit ────────────────────────────────────────────────────────

    /** @test */
    public function test_validate_debit_accepts_amount_within_balance(): void
    {
        $this->assertTrue(
            $this->wallet->validate_debit(50000.0, 100000.0),
            'Débito de 50k con 100k disponible debe ser válido'
        );
    }

    /** @test */
    public function test_validate_debit_accepts_exact_balance(): void
    {
        $this->assertTrue(
            $this->wallet->validate_debit(100000.0, 100000.0),
            'Débito exacto al balance disponible debe ser válido'
        );
    }

    /** @test */
    public function test_validate_debit_rejects_amount_exceeding_balance(): void
    {
        $this->assertFalse(
            $this->wallet->validate_debit(150000.0, 100000.0),
            'Débito que supera el balance disponible debe ser rechazado'
        );
    }

    /** @test */
    public function test_validate_debit_rejects_zero_amount(): void
    {
        $this->assertFalse(
            $this->wallet->validate_debit(0.0, 100000.0),
            'Débito de 0 debe ser rechazado'
        );
    }

    // ── validate_amount ───────────────────────────────────────────────────────

    /** @test */
    public function test_validate_amount_accepts_positive(): void
    {
        $this->assertTrue($this->wallet->validate_amount(1000.0));
        $this->assertTrue($this->wallet->validate_amount(0.01));
        $this->assertTrue($this->wallet->validate_amount(999999.99));
    }

    /** @test */
    public function test_validate_amount_rejects_zero(): void
    {
        $this->assertFalse(
            $this->wallet->validate_amount(0.0),
            'Monto de 0 debe ser rechazado'
        );
    }

    /** @test */
    public function test_validate_amount_rejects_negative(): void
    {
        $this->assertFalse(
            $this->wallet->validate_amount(-1000.0),
            'Monto negativo debe ser rechazado'
        );
    }

    // ── validate_hold ─────────────────────────────────────────────────────────

    /** @test */
    public function test_validate_hold_accepts_within_available(): void
    {
        $this->assertTrue(
            $this->wallet->validate_hold(30000.0, 100000.0),
            'Retención de 30k con 100k disponible debe ser válida'
        );
    }

    /** @test */
    public function test_validate_hold_rejects_exceeding_available(): void
    {
        $this->assertFalse(
            $this->wallet->validate_hold(200000.0, 100000.0),
            'Retención que supera el balance disponible debe ser rechazada'
        );
    }

    /** @test */
    public function test_validate_hold_rejects_zero(): void
    {
        $this->assertFalse(
            $this->wallet->validate_hold(0.0, 100000.0),
            'Retención de 0 debe ser rechazada'
        );
    }

    // ── is_valid_transaction_type ─────────────────────────────────────────────

    /** @test */
    public function test_valid_transaction_types(): void
    {
        $valid_types = ['credit', 'debit', 'hold', 'release', 'commission', 'payout'];

        foreach ($valid_types as $type) {
            $this->assertTrue(
                $this->wallet->is_valid_transaction_type($type),
                "'{$type}' debe ser un tipo de transacción válido"
            );
        }
    }

    /** @test */
    public function test_invalid_transaction_type(): void
    {
        $this->assertFalse(
            $this->wallet->is_valid_transaction_type('transferencia_magica'),
            'Tipo de transacción desconocido debe ser rechazado'
        );
    }

    /** @test */
    public function test_empty_transaction_type_is_invalid(): void
    {
        $this->assertFalse(
            $this->wallet->is_valid_transaction_type(''),
            'Tipo vacío debe ser rechazado'
        );
    }

    // ── Class structure ───────────────────────────────────────────────────────

    /** @test */
    public function test_has_all_static_methods(): void
    {
        $expected = [
            'get_or_create', 'credit', 'debit', 'hold', 'release',
            'execute_transaction', 'get_balance', 'get_transactions', 'freeze',
        ];
        $r       = new ReflectionClass(\LTMS_Business_Wallet::class);
        $missing = [];

        foreach ($expected as $m) {
            if (! $r->hasMethod($m)) {
                $missing[] = $m;
            }
        }

        $this->assertEmpty(
            $missing,
            'Métodos estáticos faltantes en LTMS_Business_Wallet: ' . implode(', ', $missing)
        );
    }
}
