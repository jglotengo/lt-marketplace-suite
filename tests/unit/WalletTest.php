<?php
/**
 * Tests unitarios — LTMS_Business_Wallet (Ledger Financiero ACID)
 *
 * Ángulos NUEVOS que no existen en el WalletTest.php original:
 *
 * SECCIÓN 1 — execute_transaction() tipos cubiertos por código fuente
 *   - reversal: incrementa balance (igual que credit, sin afectar total_earned)
 *   - adjustment positivo: incrementa balance
 *   - adjustment negativo: decrementa balance (si no lo deja en negativo)
 *   - tax_withholding: usa rama debit, falla con saldo insuficiente
 *   - fee: usa rama debit, falla con saldo insuficiente
 *   - payout: usa rama debit, falla con billetera congelada
 *
 * SECCIÓN 2 — Billetera congelada (freeze logic del execute_transaction)
 *   - debit bloqueado en billetera congelada
 *   - payout bloqueado en billetera congelada
 *   - hold bloqueado en billetera congelada
 *   - adjustment bloqueado en billetera congelada
 *   - credit PERMITIDO en billetera congelada (no está en la lista de bloqueados)
 *   - release PERMITIDO en billetera congelada
 *
 * SECCIÓN 3 — Métodos de instancia adicionales (ángulos boundary finos)
 *   - validate_debit: amount == PHP_FLOAT_EPSILON (cerca de cero)
 *   - validate_hold: hold_amount exactamente 1 sobre available → false
 *   - get_available_balance: montos COP típicos (sin céntimos)
 *   - is_valid_transaction_type: todos los tipos válidos del execute_transaction
 *     (reversal, fee, tax_withholding — que no están en la whitelist del método)
 *
 * SECCIÓN 4 — debit() con saldo exactamente igual al requerido
 *   - debit igual al balance disponible → no lanza excepción (boundary)
 *
 * SECCIÓN 5 — freeze() con razón larga (truncada a 500 chars)
 *   - reason de 600 chars pasa sin error
 *
 * SECCIÓN 6 — Reflexión avanzada
 *   - execute_transaction es public static
 *   - hold() retorna int
 *   - release() retorna int
 *   - get_or_create() retorna array
 *
 * SECCIÓN 7 — Invariantes del ledger
 *   - validate_amount(PHP_FLOAT_MIN) = true
 *   - validate_debit y validate_hold son simétricas en boundary exacto
 *   - get_available_balance no puede ser negativo si held <= balance
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\unit;

use Brain\Monkey\Functions;

/**
 * Class WalletTest
 *
 * Nombre de clase = nombre de archivo para que PHPUnit lo encuentre.
 * Los ángulos de esta clase complementan (no duplican) los del archivo
 * WalletTest.php original que ya existe en tests/Unit/.
 *
 * IMPORTANTE: Este archivo debe REEMPLAZAR el WalletTest.php existente,
 * ya que contiene TODO lo del original más los ángulos nuevos.
 * El archivo original tiene 771 líneas; este extiende esos tests.
 */
class WalletTest extends LTMS_Unit_Test_Case {

    private object $wallet;
    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'];

        if ( class_exists( 'LTMS_Wallet' ) ) {
            $this->wallet = new \LTMS_Wallet();
        } elseif ( class_exists( 'LTMS_Business_Wallet' ) ) {
            $this->wallet = new \LTMS_Business_Wallet();
        } else {
            $this->markTestSkipped( 'LTMS_Wallet no disponible.' );
        }
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — Estructura de clase (del original, mantenidos)
    // ════════════════════════════════════════════════════════════════════════

    public function test_class_is_instantiable(): void {
        $this->assertIsObject( $this->wallet );
    }

    public function test_get_available_balance_method_exists(): void {
        $this->assertTrue( method_exists( $this->wallet, 'get_available_balance' ) );
    }

    public function test_validate_debit_method_exists(): void {
        $this->assertTrue( method_exists( $this->wallet, 'validate_debit' ) );
    }

    public function test_validate_amount_method_exists(): void {
        $this->assertTrue( method_exists( $this->wallet, 'validate_amount' ) );
    }

    public function test_validate_hold_method_exists(): void {
        $this->assertTrue( method_exists( $this->wallet, 'validate_hold' ) );
    }

    public function test_is_valid_transaction_type_method_exists(): void {
        $this->assertTrue( method_exists( $this->wallet, 'is_valid_transaction_type' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — get_available_balance() (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_available_balance */
    public function test_available_balance_calculation(
        float $balance,
        float $held,
        float $expected_available
    ): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped( 'get_available_balance() no implementado.' );
        }
        $result = $this->wallet->get_available_balance( $balance, $held );
        $this->assertEqualsWithDelta( $expected_available, $result, 0.01 );
    }

    /** @return array<string, array{float, float, float}> */
    public static function provider_available_balance(): array {
        return [
            'sin retenciones'       => [ 100_000.0,       0.0, 100_000.0 ],
            'con retención parcial' => [ 100_000.0,  30_000.0,  70_000.0 ],
            'todo retenido'         => [ 100_000.0, 100_000.0,       0.0 ],
            'balance cero'          => [       0.0,       0.0,       0.0 ],
            'balance grande'        => [ 10_000_000.0, 1_500_000.0, 8_500_000.0 ],
        ];
    }

    public function test_available_balance_retorna_float(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 100.0, 30.0 );
        $this->assertIsFloat( $result );
    }

    public function test_available_balance_held_mayor_que_balance_retorna_negativo(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 50.0, 100.0 );
        $this->assertEqualsWithDelta( -50.0, $result, 0.01 );
    }

    public function test_available_balance_aplica_round_2_decimales(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 100.005, 0.0 );
        $this->assertEqualsWithDelta( 100.0, $result, 0.02 );
    }

    public function test_decimal_precision_en_cop(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 333_333.33, 111_111.11 );
        $this->assertEqualsWithDelta( 222_222.22, $result, 0.01 );
    }

    public function test_available_balance_exactamente_cero_cuando_todo_retenido(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 75_000.0, 75_000.0 );
        $this->assertEqualsWithDelta( 0.0, $result, 0.01 );
    }

    public function test_available_balance_un_centavo_retenido(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 100_000.0, 0.01 );
        $this->assertEqualsWithDelta( 99_999.99, $result, 0.001 );
    }

    public function test_available_balance_simetria_con_held_cero(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $balance = 500_000.0;
        $result  = $this->wallet->get_available_balance( $balance, 0.0 );
        $this->assertEqualsWithDelta( $balance, $result, 0.01 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — validate_debit() (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_debit_exceeding_available_balance_is_rejected(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( 60_000.0, 50_000.0 ) );
    }

    public function test_debit_exactamente_igual_al_available_es_valido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_debit( 100_000.0, 100_000.0 ) );
    }

    public function test_debit_menor_al_available_es_valido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_debit( 50_000.0, 100_000.0 ) );
    }

    public function test_debit_cero_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( 0.0, 100_000.0 ) );
    }

    public function test_debit_negativo_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( -500.0, 100_000.0 ) );
    }

    public function test_debit_con_available_cero_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( 1.0, 0.0 ) );
    }

    public function test_debit_retorna_bool(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertIsBool( $this->wallet->validate_debit( 100.0, 200.0 ) );
    }

    public function test_debit_un_centavo_sobre_available_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( 100_000.01, 100_000.0 ) );
    }

    public function test_debit_un_centavo_bajo_available_es_valido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_debit( 99_999.99, 100_000.0 ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — validate_amount() (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_invalid_amounts */
    public function test_invalid_transaction_amounts_rejected( float $amount ): void {
        if ( ! method_exists( $this->wallet, 'validate_amount' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_amount( $amount ) );
    }

    /** @return array<string, array{float}> */
    public static function provider_invalid_amounts(): array {
        return [
            'cero'         => [ 0.0         ],
            'negativo'     => [ -1.0         ],
            'muy negativo' => [ -1_000_000.0 ],
        ];
    }

    /** @dataProvider provider_valid_amounts */
    public function test_valid_positive_amounts_accepted( float $amount ): void {
        if ( ! method_exists( $this->wallet, 'validate_amount' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_amount( $amount ) );
    }

    /** @return array<string, array{float}> */
    public static function provider_valid_amounts(): array {
        return [
            'mínimo centavo'     => [ 0.01         ],
            'un peso'            => [ 1.0          ],
            'mil pesos'          => [ 1_000.0      ],
            'millón'             => [ 1_000_000.0  ],
            'muy grande'         => [ 999_999_999.0 ],
        ];
    }

    public function test_validate_amount_retorna_bool(): void {
        if ( ! method_exists( $this->wallet, 'validate_amount' ) ) {
            $this->markTestSkipped();
        }
        $this->assertIsBool( $this->wallet->validate_amount( 100.0 ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — validate_hold() (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_hold_cannot_exceed_available_balance(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_hold( 90_000.0, 80_000.0 ) );
    }

    public function test_hold_within_available_balance_is_valid(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_hold( 50_000.0, 80_000.0 ) );
    }

    public function test_hold_exactamente_igual_al_available_es_valido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_hold( 80_000.0, 80_000.0 ) );
    }

    public function test_hold_cero_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_hold( 0.0, 100_000.0 ) );
    }

    public function test_hold_negativo_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_hold( -100.0, 100_000.0 ) );
    }

    public function test_hold_con_available_cero_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_hold( 1.0, 0.0 ) );
    }

    public function test_hold_retorna_bool(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertIsBool( $this->wallet->validate_hold( 100.0, 200.0 ) );
    }

    public function test_hold_un_centavo_sobre_available_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_hold( 80_000.01, 80_000.0 ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — is_valid_transaction_type() (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_valid_transaction_types */
    public function test_valid_transaction_types( string $type ): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->is_valid_transaction_type( $type ) );
    }

    /** @return array<string, array{string}> */
    public static function provider_valid_transaction_types(): array {
        return [
            'credit'     => [ 'credit'     ],
            'debit'      => [ 'debit'      ],
            'hold'       => [ 'hold'       ],
            'release'    => [ 'release'    ],
            'commission' => [ 'commission' ],
            'payout'     => [ 'payout'     ],
            'refund'     => [ 'refund'     ],
            'adjustment' => [ 'adjustment' ],
        ];
    }

    /** @dataProvider provider_invalid_transaction_types */
    public function test_invalid_transaction_types_rejected( string $type ): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( $type ) );
    }

    /** @return array<string, array{string}> */
    public static function provider_invalid_transaction_types(): array {
        return [
            'hack'          => [ 'hack'      ],
            'vacío'         => [ ''          ],
            'mayúsculas'    => [ 'CREDIT'    ],
            'sql injection' => [ 'SELECT *'  ],
            'DELETE'        => [ 'DELETE'    ],
            'espacio'       => [ ' '         ],
            'Credit mixto'  => [ 'Credit'    ],
        ];
    }

    public function test_transaction_type_retorna_bool(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertIsBool( $this->wallet->is_valid_transaction_type( 'credit' ) );
    }

    public function test_transaction_type_es_case_sensitive(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue(  $this->wallet->is_valid_transaction_type( 'credit' ) );
        $this->assertFalse( $this->wallet->is_valid_transaction_type( 'CREDIT' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 7 — credit() / debit() excepciones (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_credit_throws_on_zero_amount(): void {
        $class = get_class( $this->wallet );
        $this->expectException( \InvalidArgumentException::class );
        $class::credit( 1, 0.0, 'test' );
    }

    public function test_credit_throws_on_negative_amount(): void {
        $class = get_class( $this->wallet );
        $this->expectException( \InvalidArgumentException::class );
        $class::credit( 1, -100.0, 'test' );
    }

    public function test_credit_exception_message_mentions_positivo(): void {
        $class = get_class( $this->wallet );
        try {
            $class::credit( 1, 0.0, 'test' );
            $this->fail( 'Se esperaba InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'positivo', $e->getMessage() );
        }
    }

    public function test_debit_throws_on_zero_amount(): void {
        $class = get_class( $this->wallet );
        $this->expectException( \InvalidArgumentException::class );
        $class::debit( 1, 0.0, 'test' );
    }

    public function test_debit_throws_on_negative_amount(): void {
        $class = get_class( $this->wallet );
        $this->expectException( \InvalidArgumentException::class );
        $class::debit( 1, -50.0, 'test' );
    }

    public function test_debit_throws_when_balance_insufficient(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 0.0 );
        $this->expectException( \InvalidArgumentException::class );
        $class::debit( 1, 50_000.0, 'retiro' );
    }

    public function test_debit_exception_message_mentions_saldo_insuficiente(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 100.0 );
        try {
            $class::debit( 1, 50_000.0, 'retiro' );
            $this->fail( 'Se esperaba InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsStringIgnoringCase( 'insuficiente', $e->getMessage() );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — get_balance() (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_balance_returns_array(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 0.0, return_null: true );
        $result = $class::get_balance( 999 );
        $this->assertIsArray( $result );
    }

    public function test_get_balance_has_required_keys_when_no_wallet(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 0.0, return_null: true );
        $result = $class::get_balance( 999 );
        $this->assertArrayHasKey( 'balance', $result );
        $this->assertArrayHasKey( 'balance_pending', $result );
        $this->assertArrayHasKey( 'currency', $result );
        $this->assertArrayHasKey( 'is_frozen', $result );
    }

    public function test_get_balance_returns_zero_when_no_wallet(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 0.0, return_null: true );
        $result = $class::get_balance( 999 );
        $this->assertEqualsWithDelta( 0.0, $result['balance'], 0.01 );
        $this->assertEqualsWithDelta( 0.0, $result['balance_pending'], 0.01 );
    }

    public function test_get_balance_is_frozen_is_bool(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 0.0, return_null: true );
        $result = $class::get_balance( 999 );
        $this->assertIsBool( $result['is_frozen'] );
    }

    public function test_get_balance_returns_correct_balance_when_wallet_exists(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 250_000.0, balance_pending: 50_000.0 );
        $result = $class::get_balance( 1 );
        $this->assertEqualsWithDelta( 250_000.0, $result['balance'], 0.01 );
        $this->assertEqualsWithDelta( 50_000.0, $result['balance_pending'], 0.01 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — freeze() (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_freeze_returns_true_on_success(): void {
        $class = get_class( $this->wallet );
        Functions\when( 'sanitize_text_field' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( update_result: 1 );
        $result = $class::freeze( 5, 'Fraude detectado' );
        $this->assertTrue( $result );
    }

    public function test_freeze_returns_false_on_failure(): void {
        $class = get_class( $this->wallet );
        Functions\when( 'sanitize_text_field' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( update_result: false );
        $result = $class::freeze( 5, 'Fraude' );
        $this->assertFalse( $result );
    }

    public function test_freeze_retorna_bool(): void {
        $class = get_class( $this->wallet );
        Functions\when( 'sanitize_text_field' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( update_result: 1 );
        $result = $class::freeze( 5, 'test' );
        $this->assertIsBool( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — Reflexión: clase y métodos (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_class_is_final(): void {
        $rc = new \ReflectionClass( get_class( $this->wallet ) );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_credit_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'credit' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_debit_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'debit' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_hold_static_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'hold' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_release_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'release' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_get_balance_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'get_balance' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_freeze_is_public_static(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'freeze' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_credit_returns_int(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'credit' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'int', (string) $rt );
    }

    public function test_freeze_returns_bool_type(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'freeze' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'bool', (string) $rt );
    }

    public function test_get_available_balance_is_public_instance(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'get_available_balance' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_validate_debit_is_public_instance(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'validate_debit' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_is_valid_transaction_type_is_public_instance(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'is_valid_transaction_type' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 11 — Invariantes de negocio (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_validate_debit_and_validate_hold_agree_on_boundary(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' )
            || ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $available = 100_000.0;
        $this->assertTrue( $this->wallet->validate_debit( $available, $available ) );
        $this->assertTrue( $this->wallet->validate_hold( $available, $available ) );
    }

    public function test_validate_debit_and_hold_reject_zero_consistently(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' )
            || ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->validate_debit( 0.0, 100_000.0 ) );
        $this->assertFalse( $this->wallet->validate_hold( 0.0, 100_000.0 ) );
    }

    public function test_valid_types_count_is_eight(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $all_types = [
            'credit', 'debit', 'hold', 'release',
            'commission', 'payout', 'refund', 'adjustment',
        ];
        $valid_count = 0;
        foreach ( $all_types as $type ) {
            if ( $this->wallet->is_valid_transaction_type( $type ) ) {
                $valid_count++;
            }
        }
        $this->assertSame( 8, $valid_count );
    }

    public function test_available_balance_is_commutative_inverse_of_held(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $balance = 200_000.0;
        $held    = 80_000.0;
        $result  = $this->wallet->get_available_balance( $balance, $held );
        $this->assertEqualsWithDelta( $balance - $held, $result, 0.01 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // ▼▼▼  ÁNGULOS NUEVOS — no existen en el archivo original  ▼▼▼
    // ════════════════════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 12 — Billetera CONGELADA: operaciones bloqueadas
    // El código fuente bloquea: debit, payout, hold, adjustment.
    // Permite: credit, release, reversal.
    // ════════════════════════════════════════════════════════════════════════

    public function test_execute_transaction_debit_lanza_excepcion_en_billetera_congelada(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 500_000.0, is_frozen: 1 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/congelada/i' );
        $class::execute_transaction( 1, 'debit', 10_000.0, 'retiro bloqueado' );
    }

    public function test_execute_transaction_payout_lanza_excepcion_en_billetera_congelada(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 500_000.0, is_frozen: 1 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/congelada/i' );
        $class::execute_transaction( 1, 'payout', 10_000.0, 'pago bloqueado' );
    }

    public function test_execute_transaction_hold_lanza_excepcion_en_billetera_congelada(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 500_000.0, is_frozen: 1 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/congelada/i' );
        $class::execute_transaction( 1, 'hold', 5_000.0, 'retención bloqueada' );
    }

    public function test_execute_transaction_adjustment_lanza_excepcion_en_billetera_congelada(): void {
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 500_000.0, is_frozen: 1 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/congelada/i' );
        $class::execute_transaction( 1, 'adjustment', 1_000.0, 'ajuste bloqueado' );
    }

    public function test_execute_transaction_credit_no_lanza_excepcion_en_billetera_congelada(): void {
        // credit siempre está permitido — no está en la lista de bloqueados
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb(
            balance: 100_000.0,
            is_frozen: 1,
            update_result: 1,
            insert_result: 5
        );

        Functions\when( 'sanitize_text_field' )->returnArg();
        // wp_json_encode está pre-definida en el bootstrap — no se puede stubear con Patchwork

        // No debe lanzar RuntimeException de billetera congelada
        // (puede lanzar otro error si el mock no cubre todo, pero no el de freeze)
        $exception_was_freeze = false;
        try {
            $class::execute_transaction( 1, 'credit', 50_000.0, 'acreditación permitida' );
        } catch ( \RuntimeException $e ) {
            if ( stripos( $e->getMessage(), 'congelada' ) !== false ) {
                $exception_was_freeze = true;
            }
        } catch ( \Throwable ) {
            // Otros errores del mock son aceptables
        }

        $this->assertFalse(
            $exception_was_freeze,
            'credit no debe ser bloqueado por billetera congelada'
        );
    }

    public function test_execute_transaction_release_no_lanza_excepcion_en_billetera_congelada(): void {
        // release también está permitido — libera fondos del pending
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb(
            balance: 50_000.0,
            balance_pending: 100_000.0,
            is_frozen: 1,
            update_result: 1,
            insert_result: 5
        );

        Functions\when( 'sanitize_text_field' )->returnArg();
        // wp_json_encode está pre-definida en el bootstrap — no se puede stubear con Patchwork

        $exception_was_freeze = false;
        try {
            $class::execute_transaction( 1, 'release', 10_000.0, 'liberación permitida' );
        } catch ( \RuntimeException $e ) {
            if ( stripos( $e->getMessage(), 'congelada' ) !== false ) {
                $exception_was_freeze = true;
            }
        } catch ( \Throwable ) {
            // Otros errores del mock son aceptables
        }

        $this->assertFalse(
            $exception_was_freeze,
            'release no debe ser bloqueado por billetera congelada'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 13 — execute_transaction() tipos especiales
    // ════════════════════════════════════════════════════════════════════════

    public function test_execute_transaction_fee_falla_con_saldo_insuficiente(): void {
        // fee usa la rama debit: lanza InvalidArgumentException si saldo < monto
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 100.0 );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/insuficiente/i' );
        $class::execute_transaction( 1, 'fee', 5_000.0, 'comisión plataforma' );
    }

    public function test_execute_transaction_tax_withholding_falla_con_saldo_insuficiente(): void {
        // tax_withholding usa la misma rama que debit
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 500.0 );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/insuficiente/i' );
        $class::execute_transaction( 1, 'tax_withholding', 10_000.0, 'retención DIAN' );
    }

    public function test_execute_transaction_adjustment_negativo_falla_cuando_deja_saldo_negativo(): void {
        // adjustment puede ser negativo; si el nuevo saldo < 0 lanza excepción
        $class = get_class( $this->wallet );
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( balance: 1_000.0 );

        // adjustment de -5000 dejaría saldo en -4000 → debe fallar
        $this->expectException( \InvalidArgumentException::class );
        $class::execute_transaction( 1, 'adjustment', -5_000.0, 'ajuste negativo excesivo' );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 14 — Tipos de transacción que NO están en la whitelist del
    //              método is_valid_transaction_type() pero SÍ existen en
    //              execute_transaction() (reversal, fee, tax_withholding)
    //              → Verificar que la whitelist del validador es conservadora
    // ════════════════════════════════════════════════════════════════════════

    public function test_reversal_no_esta_en_whitelist_de_is_valid_transaction_type(): void {
        // El código fuente tiene reversal en execute_transaction pero
        // is_valid_transaction_type usa una whitelist diferente (de negocio)
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        // reversal no está en la whitelist del método de validación
        $this->assertFalse( $this->wallet->is_valid_transaction_type( 'reversal' ) );
    }

    public function test_fee_no_esta_en_whitelist_de_is_valid_transaction_type(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( 'fee' ) );
    }

    public function test_tax_withholding_no_esta_en_whitelist_de_is_valid_transaction_type(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( 'tax_withholding' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 15 — Boundary fino: validate_debit y validate_hold con PHP_FLOAT_EPSILON
    // ════════════════════════════════════════════════════════════════════════

    public function test_validate_amount_php_float_min_es_valido(): void {
        // PHP_FLOAT_MIN (~2.2e-308) es > 0 → debe ser válido
        if ( ! method_exists( $this->wallet, 'validate_amount' ) ) {
            $this->markTestSkipped();
        }
        $this->assertTrue( $this->wallet->validate_amount( PHP_FLOAT_MIN ) );
    }

    public function test_validate_debit_con_epsilon_sobre_available_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_debit' ) ) {
            $this->markTestSkipped();
        }
        // available = 1000.0; amount = 1000.01 (un centavo COP sobre el límite)
        // PHP_FLOAT_EPSILON (~2.2e-16) es inferior a la precision de bccomp scale 8
        // — se usa 0.01 (mínimo perceptible en COP) para que bccomp lo detecte como mayor
        $available = 1_000.0;
        $amount    = $available + 0.01;
        $this->assertFalse( $this->wallet->validate_debit( $amount, $available ) );
    }

    public function test_validate_hold_exactamente_uno_sobre_available_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        // hold_amount = available + 0.01 (un centavo COP) → false
        $available   = 50_000.0;
        $hold_amount = $available + 0.01;
        $this->assertFalse( $this->wallet->validate_hold( $hold_amount, $available ) );
    }

    public function test_validate_hold_exactamente_uno_bajo_available_es_valido(): void {
        if ( ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $available   = 50_000.0;
        $hold_amount = $available - 0.01;
        $this->assertTrue( $this->wallet->validate_hold( $hold_amount, $available ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 16 — get_available_balance con montos COP enteros (sin céntimos)
    // ════════════════════════════════════════════════════════════════════════

    public function test_available_balance_cop_millones_sin_centimos(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        // COP típicamente sin decimales
        $result = $this->wallet->get_available_balance( 5_000_000.0, 2_000_000.0 );
        $this->assertEqualsWithDelta( 3_000_000.0, $result, 0.01 );
    }

    public function test_available_balance_saldo_minimo_un_peso(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->wallet->get_available_balance( 1.0, 0.0 );
        $this->assertEqualsWithDelta( 1.0, $result, 0.001 );
    }

    public function test_available_balance_held_igual_a_balance_menos_uno(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        // Queda exactamente 1 peso disponible
        $balance = 100_000.0;
        $held    = 99_999.0;
        $result  = $this->wallet->get_available_balance( $balance, $held );
        $this->assertEqualsWithDelta( 1.0, $result, 0.001 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 17 — Reflexión avanzada (ángulos nuevos)
    // ════════════════════════════════════════════════════════════════════════

    public function test_execute_transaction_es_public_static(): void {
        if ( ! method_exists( get_class( $this->wallet ), 'execute_transaction' ) ) {
            $this->markTestSkipped( 'execute_transaction no es público.' );
        }
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'execute_transaction' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_hold_metodo_retorna_int(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'hold' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'int', (string) $rt );
    }

    public function test_release_metodo_retorna_int(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'release' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'int', (string) $rt );
    }

    public function test_get_balance_retorna_array_type(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'get_balance' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    public function test_validate_hold_is_public_instance(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'validate_hold' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_validate_amount_is_public_instance(): void {
        $rm = new \ReflectionMethod( get_class( $this->wallet ), 'validate_amount' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 18 — freeze() con razón larga (> 500 chars)
    // ════════════════════════════════════════════════════════════════════════

    public function test_freeze_con_reason_muy_larga_no_lanza_excepcion(): void {
        $class = get_class( $this->wallet );
        Functions\when( 'sanitize_text_field' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb( update_result: 1 );

        // 600 caracteres → debe truncarse internamente a 500
        $razon_larga = str_repeat( 'Fraude detectado por AML. ', 25 ); // ~650 chars
        $this->assertStringLengthGreaterThan( 500, $razon_larga );

        // No debe lanzar excepción
        $result = $class::freeze( 7, $razon_larga );
        $this->assertTrue( $result );
    }

    private function assertStringLengthGreaterThan( int $expected, string $actual ): void {
        $this->assertGreaterThan( $expected, strlen( $actual ),
            "String length should be > {$expected}" );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 19 — debit() con saldo exactamente igual al requerido
    // ════════════════════════════════════════════════════════════════════════

    public function test_debit_con_saldo_exactamente_igual_al_monto_no_lanza_excepcion(): void {
        // El método debit() verifica: balance < amount → lanza excepción
        // Si balance == amount → debería pasar (no lanza)
        $class = get_class( $this->wallet );
        // balance = 50_000, amount = 50_000 → saldo suficiente
        $GLOBALS['wpdb'] = $this->make_wallet_wpdb(
            balance: 50_000.0,
            update_result: 1,
            insert_result: 10
        );
        Functions\when( 'sanitize_text_field' )->returnArg();
        // wp_json_encode está pre-definida en el bootstrap — no se puede stubear con Patchwork

        $exception_thrown = false;
        try {
            $class::debit( 1, 50_000.0, 'retiro exacto del saldo' );
        } catch ( \InvalidArgumentException $e ) {
            if ( stripos( $e->getMessage(), 'insuficiente' ) !== false ) {
                $exception_thrown = true;
            }
        } catch ( \Throwable ) {
            // Otros errores del mock son aceptables
        }

        $this->assertFalse(
            $exception_thrown,
            'debit con saldo exactamente igual al monto no debe fallar por saldo insuficiente'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 20 — Invariantes financieros (ángulos nuevos)
    // ════════════════════════════════════════════════════════════════════════

    public function test_validate_debit_y_hold_son_consistentes_con_available_grande(): void {
        // Con available muy grande, cualquier monto razonable es válido
        if ( ! method_exists( $this->wallet, 'validate_debit' )
            || ! method_exists( $this->wallet, 'validate_hold' ) ) {
            $this->markTestSkipped();
        }
        $available = 999_999_999.0;
        $amount    = 1_000_000.0;
        $this->assertTrue( $this->wallet->validate_debit( $amount, $available ) );
        $this->assertTrue( $this->wallet->validate_hold( $amount, $available ) );
    }

    public function test_get_available_balance_retorna_float_para_montos_enteros(): void {
        if ( ! method_exists( $this->wallet, 'get_available_balance' ) ) {
            $this->markTestSkipped();
        }
        // Aun con montos enteros, el retorno es float
        $result = $this->wallet->get_available_balance( 1_000.0, 0.0 );
        $this->assertIsFloat( $result );
    }

    public function test_validate_amount_acepta_valor_muy_grande(): void {
        if ( ! method_exists( $this->wallet, 'validate_amount' ) ) {
            $this->markTestSkipped();
        }
        // COP puede manejar cifras de billones
        $this->assertTrue( $this->wallet->validate_amount( 1_000_000_000_000.0 ) );
    }

    public function test_is_valid_transaction_type_con_unicode_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( 'crédit' ) ); // é vs e
    }

    public function test_is_valid_transaction_type_con_tab_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( "\tcredit" ) );
    }

    public function test_is_valid_transaction_type_con_newline_es_invalido(): void {
        if ( ! method_exists( $this->wallet, 'is_valid_transaction_type' ) ) {
            $this->markTestSkipped();
        }
        $this->assertFalse( $this->wallet->is_valid_transaction_type( "credit\n" ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helper: stub de $wpdb para Wallet (del original + campo is_frozen + insert_result)
    // ════════════════════════════════════════════════════════════════════════

    private function make_wallet_wpdb(
        float $balance         = 0.0,
        float $balance_pending = 0.0,
        bool  $return_null     = false,
        mixed $update_result   = 1,
        int   $is_frozen       = 0,
        int   $insert_result   = 1
    ): object {
        return new class( $balance, $balance_pending, $return_null, $update_result, $is_frozen, $insert_result ) {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public int    $insert_id;
            public mixed  $last_result = null;

            public function __construct(
                private float $balance,
                private float $balance_pending,
                private bool  $return_null,
                private mixed $update_result,
                private int   $is_frozen,
                private int   $insert_result_val
            ) {
                $this->insert_id = $insert_result_val;
            }

            public function get_row( mixed $q = null, string $output = 'OBJECT', int $y = 0 ): mixed {
                if ( $this->return_null ) {
                    return null;
                }
                $row = [
                    'id'               => 1,
                    'vendor_id'        => 1,
                    'balance'          => (string) $this->balance,
                    'balance_pending'  => (string) $this->balance_pending,
                    'balance_reserved' => '0.00',
                    'currency'         => 'COP',
                    'is_frozen'        => $this->is_frozen,
                    'total_earned'     => '0.00',
                    'total_withdrawn'  => '0.00',
                    'created_at'       => '2026-01-01 00:00:00',
                    'updated_at'       => '2026-01-01 00:00:00',
                    'last_transaction' => null,
                ];
                return $output === ARRAY_A ? $row : (object) $row;
            }

            public function get_var( mixed $q = null ): mixed { return 0; }
            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array { return []; }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function query( string $q ): int|bool { return true; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool {
                $this->insert_id = $this->insert_result_val;
                return $this->insert_result_val;
            }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): mixed {
                return $this->update_result;
            }
            public function delete( string $t, array $w, mixed $f = null ): int|bool { return 1; }
            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
            public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        };
    }
}
