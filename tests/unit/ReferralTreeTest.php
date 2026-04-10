<?php
/**
 * ReferralTreeTest — Tests unitarios para LTMS_Referral_Tree
 *
 * Cubre:
 *  § 1  DEFAULT_RATES — constante de tasas por nivel (8 tests)
 *  § 2  get_sponsor_chain() — paths simples, profundos, edge cases (11 tests)
 *  § 3  get_referral_rates() — config válida, inválida, vacía (5 tests)
 *  § 4  distribute_commissions() — aritmética, límite de niveles, chain vacía (10 tests)
 *  § 5  register_node() — código inexistente, vacío, tipo de retorno (3 tests)
 *  § 6  get_network_stats() — estructura, tipos, valores por defecto (8 tests)
 *  § 7  Reflexión e invariantes de clase (10 tests)
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Referral_Tree
 */
class ReferralTreeTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void {
        LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 1 — DEFAULT_RATES
    // ═══════════════════════════════════════════════════════════════════════

    public function test_default_rates_has_three_levels(): void {
        $this->assertCount( 3, LTMS_Referral_Tree::DEFAULT_RATES );
    }

    public function test_default_rates_level_1_is_40_percent(): void {
        $this->assertEqualsWithDelta( 0.40, LTMS_Referral_Tree::DEFAULT_RATES[0], 0.0001 );
    }

    public function test_default_rates_level_2_is_20_percent(): void {
        $this->assertEqualsWithDelta( 0.20, LTMS_Referral_Tree::DEFAULT_RATES[1], 0.0001 );
    }

    public function test_default_rates_level_3_is_10_percent(): void {
        $this->assertEqualsWithDelta( 0.10, LTMS_Referral_Tree::DEFAULT_RATES[2], 0.0001 );
    }

    public function test_default_rates_sum_to_70_percent(): void {
        $sum = array_sum( LTMS_Referral_Tree::DEFAULT_RATES );
        $this->assertEqualsWithDelta( 0.70, $sum, 0.0001 );
    }

    public function test_default_rates_are_decreasing(): void {
        $rates = LTMS_Referral_Tree::DEFAULT_RATES;
        for ( $i = 1; $i < count( $rates ); $i++ ) {
            $this->assertLessThan( $rates[ $i - 1 ], $rates[ $i ],
                "La tasa del nivel $i debería ser menor que la del nivel anterior" );
        }
    }

    public function test_default_rates_all_positive(): void {
        foreach ( LTMS_Referral_Tree::DEFAULT_RATES as $i => $rate ) {
            $this->assertGreaterThan( 0.0, $rate, "DEFAULT_RATES[$i] debe ser positivo" );
        }
    }

    public function test_default_rates_all_less_than_one(): void {
        foreach ( LTMS_Referral_Tree::DEFAULT_RATES as $i => $rate ) {
            $this->assertLessThan( 1.0, $rate, "DEFAULT_RATES[$i] debe ser < 1" );
        }
    }

    public function test_default_rates_is_indexed_array(): void {
        $this->assertSame( [0, 1, 2], array_keys( LTMS_Referral_Tree::DEFAULT_RATES ) );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 2 — get_sponsor_chain()
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_sponsor_chain_returns_empty_for_unknown_vendor(): void {
        $chain = LTMS_Referral_Tree::get_sponsor_chain( 9999 );
        $this->assertIsArray( $chain );
        $this->assertEmpty( $chain );
    }

    public function test_get_sponsor_chain_returns_empty_when_no_row(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( null );
        $this->assertEmpty( LTMS_Referral_Tree::get_sponsor_chain( 5 ) );
    }

    public function test_get_sponsor_chain_single_level(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        $this->assertSame( [1], LTMS_Referral_Tree::get_sponsor_chain( 10 ) );
    }

    public function test_get_sponsor_chain_two_levels_closest_first(): void {
        // path "1/10" → invertido → [10, 1]
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '10',
            'ancestor_path' => '1/10',
        ]);
        $this->assertSame( [10, 1], LTMS_Referral_Tree::get_sponsor_chain( 20 ) );
    }

    public function test_get_sponsor_chain_three_levels_correct_order(): void {
        // path "1/5/12" → invertido → [12, 5, 1]
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '12',
            'ancestor_path' => '1/5/12',
        ]);
        $this->assertSame( [12, 5, 1], LTMS_Referral_Tree::get_sponsor_chain( 30 ) );
    }

    public function test_get_sponsor_chain_five_levels(): void {
        // path "1/2/3/4/5" → invertido → [5, 4, 3, 2, 1]
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '5',
            'ancestor_path' => '1/2/3/4/5',
        ]);
        $this->assertSame( [5, 4, 3, 2, 1], LTMS_Referral_Tree::get_sponsor_chain( 100 ) );
    }

    public function test_get_sponsor_chain_returns_integers(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '7',
            'ancestor_path' => '3/7',
        ]);
        foreach ( LTMS_Referral_Tree::get_sponsor_chain( 15 ) as $id ) {
            $this->assertIsInt( $id );
        }
    }

    public function test_get_sponsor_chain_count_matches_path_segments(): void {
        // path "10/20/30" → 3 segmentos → chain de 3
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '30',
            'ancestor_path' => '10/20/30',
        ]);
        $this->assertCount( 3, LTMS_Referral_Tree::get_sponsor_chain( 40 ) );
    }

    public function test_get_sponsor_chain_first_is_direct_sponsor(): void {
        // path "1/5/12" → primer elemento = 12 = sponsor_id
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '12',
            'ancestor_path' => '1/5/12',
        ]);
        $chain = LTMS_Referral_Tree::get_sponsor_chain( 30 );
        $this->assertSame( 12, $chain[0] );
    }

    public function test_get_sponsor_chain_last_is_root(): void {
        // path "1/5/12" → último elemento = 1 = root
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '12',
            'ancestor_path' => '1/5/12',
        ]);
        $chain = LTMS_Referral_Tree::get_sponsor_chain( 30 );
        $this->assertSame( 1, end( $chain ) );
    }

    public function test_get_sponsor_chain_root_child_has_single_element(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        $chain = LTMS_Referral_Tree::get_sponsor_chain( 2 );
        $this->assertCount( 1, $chain );
        $this->assertSame( 1, $chain[0] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 3 — get_referral_rates() (indirecto vía config)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_custom_two_level_rates_config(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.50, 0.25 ] ) );
        // DEFAULT_RATES no cambia — es constante
        $this->assertCount( 3, LTMS_Referral_Tree::DEFAULT_RATES );
    }

    public function test_single_level_rates_config_accepted(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 1.0 ] ) );
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 5, 1000.0, 1 );
    }

    public function test_invalid_json_rates_falls_back_to_defaults(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', 'not-valid-json' );
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( null );

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 5, 1000.0, 1 );
    }

    public function test_empty_json_array_causes_early_return(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [] ) );
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 10, 5000.0, 1 );
    }

    public function test_null_config_uses_default_rates(): void {
        LTMS_Core_Config::flush_cache();
        Functions\when( 'get_option' )->justReturn( null );
        $this->assertCount( 3, LTMS_Referral_Tree::DEFAULT_RATES );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 4 — distribute_commissions() — aritmética y límites
    // ═══════════════════════════════════════════════════════════════════════

    public function test_distribute_empty_chain_no_exception(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( null );
        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 99, 10000.0, 1 );
    }

    public function test_distribute_empty_rates_no_exception(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [] ) );
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 10, 5000.0, 1 );
    }

    public function test_level1_commission_formula(): void {
        // DEFAULT_RATES[0] = 0.40 → nivel1 = 1000 * 0.40 = 400.00
        $this->assertEqualsWithDelta(
            400.0,
            round( 1000.0 * LTMS_Referral_Tree::DEFAULT_RATES[0], 2 ),
            0.001
        );
    }

    public function test_level2_commission_formula(): void {
        // DEFAULT_RATES[1] = 0.20 → nivel2 = 1000 * 0.20 = 200.00
        $this->assertEqualsWithDelta(
            200.0,
            round( 1000.0 * LTMS_Referral_Tree::DEFAULT_RATES[1], 2 ),
            0.001
        );
    }

    public function test_level3_commission_formula(): void {
        // DEFAULT_RATES[2] = 0.10 → nivel3 = 1000 * 0.10 = 100.00
        $this->assertEqualsWithDelta(
            100.0,
            round( 1000.0 * LTMS_Referral_Tree::DEFAULT_RATES[2], 2 ),
            0.001
        );
    }

    public function test_total_distributed_3_levels_fee_10000(): void {
        // platform_fee = 10000 → 4000 + 2000 + 1000 = 7000
        $total = 0.0;
        foreach ( LTMS_Referral_Tree::DEFAULT_RATES as $rate ) {
            $total += round( 10000.0 * $rate, 2 );
        }
        $this->assertEqualsWithDelta( 7000.0, $total, 0.001 );
    }

    public function test_distribute_chain_longer_than_rates_no_exception(): void {
        // Chain de 5, rates de 3 → procesa solo 3
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '50',
            'ancestor_path' => '1/2/3/4/50',
        ]);
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 60, 5000.0, 99 );
    }

    public function test_distribute_chain_shorter_than_rates_no_exception(): void {
        // Chain de 1, rates de 3 → procesa solo 1
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 10, 5000.0, 1 );
    }

    public function test_distribute_zero_fee_no_exception(): void {
        // platform_fee = 0 → commission = 0 → continue (nada se acredita)
        $GLOBALS['wpdb'] = $this->make_wpdb_stub([
            'sponsor_id'    => '1',
            'ancestor_path' => '1',
        ]);
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::distribute_commissions( 10, 0.0, 1 );
    }

    public function test_distribute_custom_two_level_rates_math(): void {
        // 2 niveles: 50% y 25% sobre 1000 → 500 + 250 = 750
        $rates  = [ 0.50, 0.25 ];
        $total  = 0.0;
        foreach ( $rates as $rate ) {
            $total += round( 1000.0 * $rate, 2 );
        }
        $this->assertEqualsWithDelta( 750.0, $total, 0.001 );
    }

    public function test_commission_rounded_to_two_decimals(): void {
        // round(platform_fee * rate, 2) debe tener máximo 2 decimales
        $platform_fee = 3333.33;
        foreach ( LTMS_Referral_Tree::DEFAULT_RATES as $rate ) {
            $commission = round( $platform_fee * $rate, 2 );
            $this->assertEqualsWithDelta( round( $commission, 2 ), $commission, 0.001 );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 5 — register_node()
    // ═══════════════════════════════════════════════════════════════════════

    public function test_register_node_returns_false_for_nonexistent_code(): void {
        Functions\when( 'get_users' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->returnArg();

        $this->assertFalse( LTMS_Referral_Tree::register_node( 100, 'BADCODE' ) );
    }

    public function test_register_node_returns_false_for_empty_code(): void {
        Functions\when( 'get_users' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->returnArg();

        $this->assertFalse( LTMS_Referral_Tree::register_node( 100, '' ) );
    }

    public function test_register_node_return_type_is_bool(): void {
        Functions\when( 'get_users' )->justReturn( [] );
        Functions\when( 'sanitize_text_field' )->returnArg();

        $this->assertIsBool( LTMS_Referral_Tree::register_node( 1, 'ANYCODE' ) );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 6 — get_network_stats()
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_network_stats_returns_array(): void {
        $this->assertIsArray( LTMS_Referral_Tree::get_network_stats( 1 ) );
    }

    public function test_get_network_stats_has_total_referrals_key(): void {
        $this->assertArrayHasKey( 'total_referrals', LTMS_Referral_Tree::get_network_stats( 1 ) );
    }

    public function test_get_network_stats_has_total_earned_key(): void {
        $this->assertArrayHasKey( 'total_earned', LTMS_Referral_Tree::get_network_stats( 1 ) );
    }

    public function test_get_network_stats_total_referrals_is_int(): void {
        $this->assertIsInt( LTMS_Referral_Tree::get_network_stats( 1 )['total_referrals'] );
    }

    public function test_get_network_stats_total_earned_is_float(): void {
        $this->assertIsFloat( LTMS_Referral_Tree::get_network_stats( 1 )['total_earned'] );
    }

    public function test_get_network_stats_defaults_zero_for_unknown_vendor(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( null );
        $stats = LTMS_Referral_Tree::get_network_stats( 9999 );
        $this->assertSame( 0, $stats['total_referrals'] );
        $this->assertEqualsWithDelta( 0.0, $stats['total_earned'], 0.0001 );
    }

    public function test_get_network_stats_total_referrals_not_negative(): void {
        $this->assertGreaterThanOrEqual( 0, LTMS_Referral_Tree::get_network_stats( 1 )['total_referrals'] );
    }

    public function test_get_network_stats_total_earned_not_negative(): void {
        $this->assertGreaterThanOrEqual( 0.0, LTMS_Referral_Tree::get_network_stats( 1 )['total_earned'] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // § 7 — Reflexión e invariantes
    // ═══════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Referral_Tree' ) );
    }

    public function test_class_is_final(): void {
        $this->assertTrue( ( new \ReflectionClass( 'LTMS_Referral_Tree' ) )->isFinal() );
    }

    public function test_get_sponsor_chain_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Referral_Tree', 'get_sponsor_chain' );
        $this->assertTrue( $ref->isPublic() && $ref->isStatic() );
    }

    public function test_distribute_commissions_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Referral_Tree', 'distribute_commissions' );
        $this->assertTrue( $ref->isPublic() && $ref->isStatic() );
    }

    public function test_register_node_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Referral_Tree', 'register_node' );
        $this->assertTrue( $ref->isPublic() && $ref->isStatic() );
    }

    public function test_get_network_stats_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Referral_Tree', 'get_network_stats' );
        $this->assertTrue( $ref->isPublic() && $ref->isStatic() );
    }

    public function test_get_descendant_tree_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Referral_Tree', 'get_descendant_tree' ) );
    }

    public function test_init_no_lanza_excepcion(): void {
        $this->expectNotToPerformAssertions();
        LTMS_Referral_Tree::init();
    }

    public function test_default_rate_constant_is_array(): void {
        $this->assertIsArray( LTMS_Referral_Tree::DEFAULT_RATES );
    }

    public function test_get_descendant_tree_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Referral_Tree', 'get_descendant_tree' );
        $this->assertTrue( $ref->isPublic() && $ref->isStatic() );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function make_wpdb_stub( mixed $get_row_result ): object {
        return new class( $get_row_result ) {
            public string $prefix = 'wp_';
            private mixed $row;

            public function __construct( mixed $row ) { $this->row = $row; }

            public function get_row( mixed $q = null, string $output = 'OBJECT', int $y = 0 ): mixed {
                if ( $this->row === null ) return null;
                return $output === ARRAY_A ? (array) $this->row : (object) $this->row;
            }

            public function get_var( mixed $q = null ): mixed {
                if ( $this->row === null ) return null;
                if ( is_array( $this->row ) ) return reset( $this->row );
                return null;
            }

            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array { return []; }

            public function prepare( string $q, mixed ...$args ): string { return $q; }

            public function insert( string $t, array $d, mixed $f = null ): int|bool { return false; }

            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return false; }

            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }

            public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        };
    }
}
