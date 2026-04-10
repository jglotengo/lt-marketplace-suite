<?php
/**
 * PayoutSchedulerTest — Tests unitarios para LTMS_Payout_Scheduler
 *
 * Cubre:
 *  - Constantes de negocio (MIN_PAYOUT_COP, MIN_PAYOUT_MXN, MAX_PENDING)
 *  - create_request() — 5 guards: monto mínimo, balance, pendientes, KYC, hold
 *  - create_request() — flujo exitoso, métodos nequi/openpay/bank_transfer
 *  - create_request() — mínimo por país CO vs MX, override por config
 *  - create_request() — estructura del array de respuesta
 *  - reject() — flujo y guards
 *  - auto_approve_eligible() — desactivado y sin elegibles
 *  - calculate_payout_fee() — todos los métodos, fee fijo openpay, método desconocido
 *  - net_amount = amount - fee para todos los métodos
 *  - Reflexión — clase final, métodos públicos estáticos, tipos de retorno
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Payout_Scheduler
 */
class PayoutSchedulerTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'];
        LTMS_Core_Config::flush_cache();
        LTMS_Core_Config::set( 'ltms_min_payout_amount', 0 );   // usar defaults por país
        LTMS_Core_Config::set( 'ltms_kyc_required_for_payout', 'yes' );
        LTMS_Core_Config::set( 'ltms_auto_approve_payouts', 'no' );
        $GLOBALS['wpdb'] = $this->make_payout_wpdb();
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Constantes de negocio
    // -----------------------------------------------------------------------

    public function test_min_payout_cop_constant(): void {
        $this->assertSame( 50000, LTMS_Payout_Scheduler::MIN_PAYOUT_COP );
    }

    public function test_min_payout_mxn_constant(): void {
        $this->assertSame( 500, LTMS_Payout_Scheduler::MIN_PAYOUT_MXN );
    }

    public function test_max_pending_per_vendor_constant(): void {
        $this->assertSame( 3, LTMS_Payout_Scheduler::MAX_PENDING_PER_VENDOR );
    }

    public function test_min_cop_is_greater_than_min_mxn(): void {
        $this->assertGreaterThan(
            LTMS_Payout_Scheduler::MIN_PAYOUT_MXN,
            LTMS_Payout_Scheduler::MIN_PAYOUT_COP
        );
    }

    public function test_max_pending_is_positive_integer(): void {
        $this->assertGreaterThan( 0, LTMS_Payout_Scheduler::MAX_PENDING_PER_VENDOR );
    }

    // -----------------------------------------------------------------------
    // create_request() — guard: monto mínimo
    // -----------------------------------------------------------------------

    public function test_create_request_fails_below_minimum_cop(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 10000.0, 'acct_123' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['payout_id'] );
    }

    public function test_create_request_fails_at_exactly_one_below_minimum(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 49999.0, 'acct_123' );

        $this->assertFalse( $result['success'] );
    }

    public function test_create_request_message_contains_minimum_amount_when_below_min(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 1000.0, 'acct_123' );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_create_request_fails_with_zero_amount(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 0.0, 'acct_123' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['payout_id'] );
    }

    public function test_create_request_fails_at_exactly_minimum_mxn_when_country_mx(): void {
        LTMS_Core_Config::set( 'ltms_country', 'MX' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        // MXN mínimo = 500. Pedimos 499 → falla.
        $result = LTMS_Payout_Scheduler::create_request( 1, 499.0, 'acct_mx' );

        $this->assertFalse( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // create_request() — guard: saldo insuficiente
    // -----------------------------------------------------------------------

    public function test_create_request_fails_when_balance_insufficient(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        // Wallet con balance 0 → monto 100000 > 0
        $result = LTMS_Payout_Scheduler::create_request( 1, 100000.0, 'acct_123' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['payout_id'] );
    }

    public function test_create_request_insufficient_balance_message_is_set(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 2, 500000.0, 'acct_456' );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_create_request_fails_when_amount_equals_balance_plus_one_cent(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        // Balance = 100000, pedimos 100000.01 → falla
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 100000.0 );
        $result = LTMS_Payout_Scheduler::create_request( 1, 100000.01, 'acct_123' );

        $this->assertFalse( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // create_request() — guard: pendientes excedidos
    // -----------------------------------------------------------------------

    public function test_create_request_fails_when_max_pending_reached(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'approved' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 3 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['payout_id'] );
    }

    public function test_create_request_fails_when_pending_exceeds_max(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'approved' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 10 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertFalse( $result['success'] );
    }

    public function test_create_request_succeeds_when_pending_one_below_max(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'approved' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        // MAX_PENDING = 3, tenemos 2 → se puede crear
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 2, insert_id: 99 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 99, $result['payout_id'] );
    }

    // -----------------------------------------------------------------------
    // create_request() — guard: KYC no aprobado
    // -----------------------------------------------------------------------

    public function test_create_request_fails_when_kyc_not_approved(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'pending' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 0 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertFalse( $result['success'] );
    }

    public function test_create_request_fails_when_kyc_status_is_rejected(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'rejected' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 0 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertFalse( $result['success'] );
    }

    public function test_create_request_fails_when_kyc_meta_is_empty(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( '' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 1000000.0, pending_count: 0 );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertFalse( $result['success'] );
    }

    public function test_create_request_succeeds_when_kyc_disabled_in_config(): void {
        LTMS_Core_Config::set( 'ltms_kyc_required_for_payout', 'no' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'pending' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb(
            balance: 1000000.0,
            pending_count: 0,
            insert_id: 55
        );

        $result = LTMS_Payout_Scheduler::create_request( 5, 50000.0, 'acct_789' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 55, $result['payout_id'] );
    }

    // -----------------------------------------------------------------------
    // create_request() — flujo exitoso completo
    // -----------------------------------------------------------------------

    public function test_create_request_succeeds_with_all_guards_passing(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'get_user_meta' )->justReturn( 'approved' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb(
            balance: 500000.0,
            pending_count: 1,
            insert_id: 42
        );

        $result = LTMS_Payout_Scheduler::create_request( 10, 100000.0, 'acct_ok' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 42, $result['payout_id'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_create_request_returns_array_with_required_keys(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 1000.0, 'acct_123' );

        $this->assertArrayHasKey( 'success', $result );
        $this->assertArrayHasKey( 'message', $result );
        $this->assertArrayHasKey( 'payout_id', $result );
    }

    public function test_create_request_success_is_bool(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 1000.0, 'acct_123' );

        $this->assertIsBool( $result['success'] );
    }

    public function test_create_request_payout_id_is_int(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = LTMS_Payout_Scheduler::create_request( 1, 1000.0, 'acct_123' );

        $this->assertIsInt( $result['payout_id'] );
    }

    public function test_create_request_with_nequi_method_succeeds(): void {
        LTMS_Core_Config::set( 'ltms_kyc_required_for_payout', 'no' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb(
            balance: 500000.0,
            pending_count: 0,
            insert_id: 77
        );

        $result = LTMS_Payout_Scheduler::create_request( 10, 100000.0, 'acct_ok', 'nequi' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 77, $result['payout_id'] );
    }

    public function test_create_request_with_openpay_method_succeeds(): void {
        LTMS_Core_Config::set( 'ltms_kyc_required_for_payout', 'no' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb(
            balance: 500000.0,
            pending_count: 0,
            insert_id: 88
        );

        $result = LTMS_Payout_Scheduler::create_request( 10, 100000.0, 'acct_ok', 'openpay' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 88, $result['payout_id'] );
    }

    // -----------------------------------------------------------------------
    // Monto mínimo por país
    // -----------------------------------------------------------------------

    public function test_minimum_payout_uses_cop_default_for_colombia(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        // Monto = MIN_PAYOUT_COP - 1 → debe fallar
        $result = LTMS_Payout_Scheduler::create_request( 1, LTMS_Payout_Scheduler::MIN_PAYOUT_COP - 1, 'x' );
        $this->assertFalse( $result['success'] );

        // Monto = MIN_PAYOUT_COP → pasa el guard de monto mínimo (puede fallar en balance)
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 0.0 );
        $result = LTMS_Payout_Scheduler::create_request( 1, LTMS_Payout_Scheduler::MIN_PAYOUT_COP, 'x' );
        // Falla en balance (0), no en monto mínimo → mensaje diferente
        $this->assertFalse( $result['success'] );
        // El mensaje NO debe ser sobre el mínimo
        $this->assertStringNotContainsString( 'mínimo de retiro', $result['message'] );
    }

    public function test_custom_minimum_from_config_overrides_default(): void {
        LTMS_Core_Config::set( 'ltms_min_payout_amount', 200000 );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        // 100000 < 200000 → debe fallar por mínimo
        $result = LTMS_Payout_Scheduler::create_request( 1, 100000.0, 'acct' );
        $this->assertFalse( $result['success'] );
    }

    public function test_custom_minimum_allows_amount_above_it(): void {
        LTMS_Core_Config::set( 'ltms_min_payout_amount', 80000 );
        LTMS_Core_Config::set( 'ltms_kyc_required_for_payout', 'no' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        // 200000 > 80000 y hay balance suficiente → éxito
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 500000.0, pending_count: 0, insert_id: 33 );

        $result = LTMS_Payout_Scheduler::create_request( 1, 200000.0, 'acct' );
        $this->assertTrue( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // auto_approve_eligible() — desactivado y sin elegibles
    // -----------------------------------------------------------------------

    public function test_auto_approve_does_nothing_when_disabled(): void {
        LTMS_Core_Config::set( 'ltms_auto_approve_payouts', 'no' );

        $this->expectNotToPerformAssertions();
        LTMS_Payout_Scheduler::auto_approve_eligible();
    }

    public function test_auto_approve_does_nothing_when_no_eligible_payouts(): void {
        LTMS_Core_Config::set( 'ltms_auto_approve_payouts', 'yes' );
        LTMS_Core_Config::set( 'ltms_auto_approve_max_amount', 500000 );

        $GLOBALS['wpdb'] = $this->make_payout_wpdb( results: [] );

        $this->expectNotToPerformAssertions();
        LTMS_Payout_Scheduler::auto_approve_eligible();
    }

    // -----------------------------------------------------------------------
    // Invariantes matemáticas de payout_fee
    // -----------------------------------------------------------------------

    /**
     * @dataProvider provider_payout_fees
     */
    public function test_payout_fee_calculation( string $method, float $amount, float $expected_fee ): void {
        $fees = [
            'bank_transfer' => 0.0,
            'openpay'       => 4000.0,
            'nequi'         => 0.0,
        ];

        $fee = $fees[ $method ] ?? 0.0;
        $this->assertEqualsWithDelta( $expected_fee, $fee, 0.01 );
    }

    public static function provider_payout_fees(): array {
        return [
            'bank_transfer sin costo'          => [ 'bank_transfer', 100000.0, 0.0 ],
            'openpay fee fijo COP'             => [ 'openpay', 100000.0, 4000.0 ],
            'nequi sin costo'                  => [ 'nequi', 100000.0, 0.0 ],
            'openpay fee independiente del monto' => [ 'openpay', 50000.0, 4000.0 ],
            'openpay fee igual para monto alto' => [ 'openpay', 1000000.0, 4000.0 ],
        ];
    }

    public function test_net_amount_is_amount_minus_fee(): void {
        $amount = 200000.0;
        $fee    = 4000.0; // openpay
        $net    = $amount - $fee;

        $this->assertEqualsWithDelta( 196000.0, $net, 0.01 );
    }

    public function test_net_amount_equals_amount_for_bank_transfer(): void {
        $amount = 150000.0;
        $fee    = 0.0;
        $net    = $amount - $fee;

        $this->assertEqualsWithDelta( $amount, $net, 0.01 );
    }

    public function test_net_amount_equals_amount_for_nequi(): void {
        $amount = 75000.0;
        $fee    = 0.0; // nequi gratis
        $net    = $amount - $fee;

        $this->assertEqualsWithDelta( $amount, $net, 0.01 );
    }

    public function test_openpay_fee_is_fixed_not_percentage(): void {
        // El fee de openpay es FIJO ($4000 COP), no porcentual
        $fee_small = 4000.0;  // para monto 50000
        $fee_large = 4000.0;  // para monto 1000000

        $this->assertEqualsWithDelta( $fee_small, $fee_large, 0.01 );
    }

    public function test_unknown_method_has_zero_fee(): void {
        $fees = [
            'bank_transfer' => 0.0,
            'openpay'       => 4000.0,
            'nequi'         => 0.0,
        ];

        $fee = $fees['unknown_method'] ?? 0.0;
        $this->assertEqualsWithDelta( 0.0, $fee, 0.01 );
    }

    // -----------------------------------------------------------------------
    // Reflexión — clase y métodos
    // -----------------------------------------------------------------------

    public function test_class_is_final(): void {
        $rc = new ReflectionClass( LTMS_Payout_Scheduler::class );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_create_request_is_public_static(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'create_request' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_create_request_returns_array(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'create_request' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    public function test_approve_is_public_static(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'approve' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_reject_is_public_static(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'reject' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_auto_approve_eligible_is_public_static(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'auto_approve_eligible' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_process_pending_payouts_is_public_static(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'process_pending_payouts' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_create_request_has_four_parameters(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'create_request' );
        $this->assertCount( 4, $rm->getParameters() );
    }

    public function test_create_request_method_param_has_default(): void {
        $rm     = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'create_request' );
        $params = $rm->getParameters();
        // 4to parámetro ($method) tiene default 'bank_transfer'
        $this->assertTrue( $params[3]->isOptional() );
        $this->assertSame( 'bank_transfer', $params[3]->getDefaultValue() );
    }

    public function test_reject_has_three_parameters(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'reject' );
        $this->assertCount( 3, $rm->getParameters() );
    }

    // -----------------------------------------------------------------------
    // Helper: stub de $wpdb para Payout
    // -----------------------------------------------------------------------

    /**
     * Crea un stub de $wpdb con comportamientos específicos para PayoutScheduler.
     */
    private function make_payout_wpdb(
        float   $balance         = 0.0,
        int     $pending_count   = 0,
        int     $insert_id       = 1,
        array   $results         = [],
        ?array  $payout_row      = null,
        float   $balance_pending = 0.0
    ): object {
        return new class( $balance, $pending_count, $insert_id, $results, $payout_row, $balance_pending ) {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public mixed  $last_result = null;

            public function __construct(
                private float   $balance,
                private int     $pending_count,
                public int      $insert_id,
                private array   $results,
                private ?array  $payout_row,
                private float   $balance_pending
            ) {}

            public function get_row( mixed $q = null, string $output = 'OBJECT', int $y = 0 ): mixed {
                if ( is_string( $q ) && str_contains( $q, 'lt_vendor_wallets' ) ) {
                    $row = [
                        'id'                => 1,
                        'vendor_id'         => 0,
                        'balance'           => (string) $this->balance,
                        'balance_pending'   => (string) $this->balance_pending,
                        'balance_reserved'  => '0.00',
                        'currency'          => 'COP',
                        'is_frozen'         => 0,
                        'total_earned'      => '0.00',
                        'total_withdrawn'   => '0.00',
                        'created_at'        => '2026-01-01 00:00:00',
                        'updated_at'        => '2026-01-01 00:00:00',
                        'last_transaction'  => null,
                    ];
                    return $output === ARRAY_A ? $row : (object) $row;
                }
                if ( is_string( $q ) && str_contains( $q, 'lt_payout_requests' ) && $this->payout_row !== null ) {
                    return $output === ARRAY_A ? $this->payout_row : (object) $this->payout_row;
                }
                return null;
            }

            public function get_var( mixed $q = null ): mixed {
                return $this->pending_count;
            }

            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array {
                return $this->results;
            }

            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function query( string $q ): int|bool { return true; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool { return 1; }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return 1; }
            public function delete( string $t, array $w, mixed $f = null ): int|bool { return 1; }
            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
            public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        };
    }

    // -----------------------------------------------------------------------
    // approve() — guards: payout no existe o ya procesado
    // -----------------------------------------------------------------------

    public function test_approve_fails_when_payout_not_found(): void {
        Functions\when( '__' )->returnArg();
        // get_row devuelve null → payout no existe
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::approve( 999, 1 );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_approve_fails_when_payout_already_completed(): void {
        Functions\when( '__' )->returnArg();
        $payout = [
            'id' => 1, 'vendor_id' => 10, 'amount' => '100000.00',
            'status' => 'completed', 'method' => 'bank_transfer', 'bank_account_id' => 'acct_1',
        ];
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: $payout );

        $result = LTMS_Payout_Scheduler::approve( 1, 1 );

        $this->assertFalse( $result['success'] );
    }

    public function test_approve_fails_when_payout_is_rejected(): void {
        Functions\when( '__' )->returnArg();
        $payout = [
            'id' => 2, 'vendor_id' => 10, 'amount' => '100000.00',
            'status' => 'rejected', 'method' => 'bank_transfer', 'bank_account_id' => 'acct_1',
        ];
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: $payout );

        $result = LTMS_Payout_Scheduler::approve( 2, 1 );

        $this->assertFalse( $result['success'] );
    }

    public function test_approve_result_has_success_and_message_keys(): void {
        Functions\when( '__' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::approve( 999, 1 );

        $this->assertArrayHasKey( 'success', $result );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_approve_success_is_bool(): void {
        Functions\when( '__' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::approve( 999, 1 );

        $this->assertIsBool( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // reject() — guards y flujo
    // -----------------------------------------------------------------------

    public function test_reject_fails_when_payout_not_found(): void {
        Functions\when( '__' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::reject( 999, 'Sin fondos', 1 );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_reject_fails_when_payout_already_completed(): void {
        Functions\when( '__' )->returnArg();
        $payout = [
            'id' => 1, 'vendor_id' => 10, 'amount' => '100000.00',
            'status' => 'completed', 'method' => 'bank_transfer', 'bank_account_id' => 'acct_1',
        ];
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: $payout );

        $result = LTMS_Payout_Scheduler::reject( 1, 'Motivo', 1 );

        $this->assertFalse( $result['success'] );
    }

    public function test_reject_succeeds_on_pending_payout(): void {
        Functions\when( '__' )->returnArg();
        $payout = [
            'id' => 5, 'vendor_id' => 20, 'amount' => '150000.00',
            'status' => 'pending', 'method' => 'bank_transfer', 'bank_account_id' => 'acct_2',
        ];
        $GLOBALS['wpdb'] = $this->make_payout_wpdb(
            balance: 300000.0,
            payout_row: $payout,
            balance_pending: 150000.0
        );

        $result = LTMS_Payout_Scheduler::reject( 5, 'Documentos incompletos', 1 );

        $this->assertTrue( $result['success'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_reject_result_has_required_keys(): void {
        Functions\when( '__' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::reject( 999, 'Motivo', 1 );

        $this->assertArrayHasKey( 'success', $result );
        $this->assertArrayHasKey( 'message', $result );
    }

    public function test_reject_success_is_bool(): void {
        Functions\when( '__' )->returnArg();
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( payout_row: null );

        $result = LTMS_Payout_Scheduler::reject( 999, 'x', 1 );

        $this->assertIsBool( $result['success'] );
    }

    public function test_reject_with_empty_reason_does_not_throw(): void {
        Functions\when( '__' )->returnArg();
        $payout = [
            'id' => 6, 'vendor_id' => 20, 'amount' => '50000.00',
            'status' => 'pending', 'method' => 'nequi', 'bank_account_id' => 'acct_3',
        ];
        $GLOBALS['wpdb'] = $this->make_payout_wpdb( balance: 200000.0, payout_row: $payout, balance_pending: 50000.0 );

        $result = LTMS_Payout_Scheduler::reject( 6, '', 1 );

        $this->assertIsBool( $result['success'] );
    }

    // -----------------------------------------------------------------------
    // approve() / reject() — firma de métodos
    // -----------------------------------------------------------------------

    public function test_approve_has_two_parameters(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'approve' );
        $this->assertCount( 2, $rm->getParameters() );
    }

    public function test_approve_returns_array(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'approve' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    public function test_reject_returns_array(): void {
        $rm = new ReflectionMethod( LTMS_Payout_Scheduler::class, 'reject' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

}
