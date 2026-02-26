<?php
/**
 * LTMS WalletTest - Pruebas Unitarias de la Billetera ACID
 *
 * @package    LTMS\Tests\Unit
 * @version    1.5.0
 */

namespace LTMS\Tests\Unit;

use WP_UnitTestCase;

/**
 * Class WalletTest
 */
class WalletTest extends WP_UnitTestCase {

    /**
     * @var int ID del vendedor de prueba.
     */
    private int $vendor_id;

    /**
     * Configuración de cada test.
     */
    public function setUp(): void {
        parent::setUp();

        // Crear usuario de prueba con rol de vendedor
        $this->vendor_id = self::factory()->user->create([
            'role' => 'ltms_vendor',
        ]);
    }

    /**
     * Test: get_or_create crea una billetera nueva si no existe.
     */
    public function test_get_or_create_creates_wallet(): void {
        $wallet = \LTMS_Wallet::get_or_create( $this->vendor_id );

        $this->assertIsArray( $wallet );
        $this->assertEquals( $this->vendor_id, (int) $wallet['user_id'] );
        $this->assertEquals( '0.00', $wallet['balance'] );
        $this->assertFalse( (bool) $wallet['is_frozen'] );
    }

    /**
     * Test: credit aumenta el balance correctamente.
     */
    public function test_credit_increases_balance(): void {
        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::credit( $this->vendor_id, 100000.00, 'commission', 'Test credit' );

        $wallet = \LTMS_Wallet::get_or_create( $this->vendor_id );
        $this->assertEquals( 100000.00, (float) $wallet['balance'] );
    }

    /**
     * Test: debit reduce el balance correctamente.
     */
    public function test_debit_reduces_balance(): void {
        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::credit( $this->vendor_id, 200000.00, 'commission', 'Initial credit' );
        \LTMS_Wallet::debit( $this->vendor_id, 50000.00, 'payout', 'Test debit' );

        $wallet = \LTMS_Wallet::get_or_create( $this->vendor_id );
        $this->assertEquals( 150000.00, (float) $wallet['balance'] );
    }

    /**
     * Test: debit falla si el balance es insuficiente.
     */
    public function test_debit_throws_on_insufficient_balance(): void {
        $this->expectException( \RuntimeException::class );

        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::debit( $this->vendor_id, 999999.00, 'payout', 'Should fail' );
    }

    /**
     * Test: no permite transacciones en billetera congelada.
     */
    public function test_frozen_wallet_blocks_payout(): void {
        $this->expectException( \RuntimeException::class );

        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::credit( $this->vendor_id, 100000.00, 'commission', 'Crédit' );
        \LTMS_Wallet::freeze( $this->vendor_id, 'SAGRILAFT investigation' );

        \LTMS_Wallet::debit( $this->vendor_id, 10000.00, 'payout', 'Should fail - frozen' );
    }

    /**
     * Test: hold y release funcionan correctamente.
     */
    public function test_hold_and_release(): void {
        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::credit( $this->vendor_id, 100000.00, 'commission', 'Credit' );
        \LTMS_Wallet::hold( $this->vendor_id, 30000.00, 'Payout in progress' );

        $wallet = \LTMS_Wallet::get_or_create( $this->vendor_id );
        $this->assertEquals( 30000.00, (float) $wallet['held_balance'] );

        \LTMS_Wallet::release( $this->vendor_id, 30000.00, 'Payout released' );
        $wallet_after = \LTMS_Wallet::get_or_create( $this->vendor_id );
        $this->assertEquals( 0.00, (float) $wallet_after['held_balance'] );
    }

    /**
     * Test: el balance nunca queda negativo.
     */
    public function test_balance_never_goes_negative(): void {
        \LTMS_Wallet::get_or_create( $this->vendor_id );
        \LTMS_Wallet::credit( $this->vendor_id, 1000.00, 'commission', 'Small credit' );

        try {
            \LTMS_Wallet::debit( $this->vendor_id, 5000.00, 'payout', 'Excessive debit' );
            $this->fail( 'Should have thrown exception' );
        } catch ( \RuntimeException $e ) {
            $wallet = \LTMS_Wallet::get_or_create( $this->vendor_id );
            $this->assertGreaterThanOrEqual( 0.0, (float) $wallet['balance'] );
        }
    }

    /**
     * Limpieza después de cada test.
     */
    public function tearDown(): void {
        wp_delete_user( $this->vendor_id );
        parent::tearDown();
    }
}
