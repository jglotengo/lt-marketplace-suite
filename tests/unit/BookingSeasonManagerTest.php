<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Booking_Season_Manager — F-12
 */
class BookingSeasonManagerTest extends TestCase {

    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\stubs( [ 'error_log' => null, 'current_time' => static fn( $t ) => gmdate( 'Y-m-d H:i:s' ) ] );
        $this->original_wpdb = $GLOBALS['wpdb'] ?? new \stdClass();

        $ref = new \ReflectionProperty( 'LTMS_Booking_Season_Manager', 'initialized' );
        $ref->setAccessible( true );
        $ref->setValue( null, false );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        if ( class_exists( 'LTMS_Booking_Season_Manager', false ) ) {
            $ref = new \ReflectionProperty( 'LTMS_Booking_Season_Manager', 'initialized' );
            $ref->setAccessible( true );
            $ref->setValue( null, false );
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_wpdb( mixed $get_row = null, array $get_results = [], int $insert_id = 0 ): object {
        return new class( $get_row, $get_results, $insert_id ) {
            public string $prefix    = 'wp_';
            public int    $insert_id;
            private mixed $row;
            private array $results;

            public function __construct( mixed $r, array $res, int $id ) {
                $this->row       = $r;
                $this->results   = $res;
                $this->insert_id = $id;
            }
            public function get_row( mixed $q = null, string $out = 'OBJECT', int $y = 0 ): mixed { return $this->row; }
            public function get_results( mixed $q = null, string $out = 'OBJECT' ): array { return $this->results; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool { return $this->insert_id > 0; }
            public function update( string $t, array $d, array $w, mixed $df = null, mixed $wf = null ): int|false { return 1; }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function query( string $q ): int|bool { return true; }
        };
    }

    // ── Estructura ────────────────────────────────────────────────────────

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Booking_Season_Manager' ) );
    }

    public function test_has_init(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'init' ) );
    }

    public function test_has_apply_season_modifier(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'apply_season_modifier' ) );
    }

    public function test_has_get_modifier_for_date(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'get_modifier_for_date' ) );
    }

    public function test_has_calculate_total(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'calculate_total' ) );
    }

    public function test_has_get_rules(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'get_rules' ) );
    }

    public function test_has_save_rule(): void {
        $this->assertTrue( method_exists( 'LTMS_Booking_Season_Manager', 'save_rule' ) );
    }

    // ── get_modifier_for_date: sin reglas → 1.0 ───────────────────────────

    public function test_get_modifier_returns_1_when_no_rules(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $result = \LTMS_Booking_Season_Manager::get_modifier_for_date( 1, '2025-08-01' );
        $this->assertSame( 1.0, $result );
    }

    public function test_get_modifier_returns_float(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $this->assertIsFloat( \LTMS_Booking_Season_Manager::get_modifier_for_date( 1, '2025-01-01' ) );
    }

    public function test_get_modifier_uses_product_rule_when_found(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( [ 'price_modifier' => '1.5' ] );
        $result = \LTMS_Booking_Season_Manager::get_modifier_for_date( 5, '2025-12-25' );
        $this->assertSame( 1.5, $result );
    }

    public function test_get_modifier_clamps_to_minimum_0_1(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( [ 'price_modifier' => '0.0' ] );
        $result = \LTMS_Booking_Season_Manager::get_modifier_for_date( 1, '2025-06-01' );
        $this->assertSame( 0.1, $result );
    }

    public function test_get_modifier_high_season_2x(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( [ 'price_modifier' => '2.0' ] );
        $result = \LTMS_Booking_Season_Manager::get_modifier_for_date( 1, '2025-12-31' );
        $this->assertSame( 2.0, $result );
    }

    // ── apply_season_modifier ─────────────────────────────────────────────

    public function test_apply_modifier_1x_returns_same_price(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null ); // modifier = 1.0
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier( 100.0, 1, '2025-06-01' );
        $this->assertEqualsWithDelta( 100.0, $result, 0.01 );
    }

    public function test_apply_modifier_2x_doubles_price(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( [ 'price_modifier' => '2.0' ] );
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier( 100.0, 1, '2025-12-25' );
        $this->assertEqualsWithDelta( 200.0, $result, 0.01 );
    }

    public function test_apply_modifier_returns_float(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $this->assertIsFloat( \LTMS_Booking_Season_Manager::apply_season_modifier( 50.0, 1, '2025-01-01' ) );
    }

    // ── calculate_total ───────────────────────────────────────────────────

    public function test_calculate_total_one_night_no_modifier(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $result = \LTMS_Booking_Season_Manager::calculate_total( 100.0, 1, '2025-06-01', '2025-06-02' );
        $this->assertEqualsWithDelta( 100.0, $result, 0.01 );
    }

    public function test_calculate_total_three_nights_no_modifier(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $result = \LTMS_Booking_Season_Manager::calculate_total( 100.0, 1, '2025-06-01', '2025-06-04' );
        $this->assertEqualsWithDelta( 300.0, $result, 0.01 );
    }

    public function test_calculate_total_returns_float(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $this->assertIsFloat( \LTMS_Booking_Season_Manager::calculate_total( 80.0, 1, '2025-07-01', '2025-07-03' ) );
    }

    public function test_calculate_total_zero_nights_returns_zero(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $result = \LTMS_Booking_Season_Manager::calculate_total( 100.0, 1, '2025-06-01', '2025-06-01' );
        $this->assertSame( 0.0, $result );
    }

    public function test_calculate_total_checkout_before_checkin_returns_zero(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null );
        $result = \LTMS_Booking_Season_Manager::calculate_total( 100.0, 1, '2025-06-05', '2025-06-01' );
        $this->assertSame( 0.0, $result );
    }

    public function test_calculate_total_with_2x_modifier(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( [ 'price_modifier' => '2.0' ] );
        $result = \LTMS_Booking_Season_Manager::calculate_total( 100.0, 1, '2025-12-24', '2025-12-26' );
        $this->assertEqualsWithDelta( 400.0, $result, 0.01 );
    }

    // ── get_rules ─────────────────────────────────────────────────────────

    public function test_get_rules_returns_array(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null, [] );
        $this->assertIsArray( \LTMS_Booking_Season_Manager::get_rules() );
    }

    public function test_get_rules_returns_empty_when_none(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null, [] );
        $this->assertEmpty( \LTMS_Booking_Season_Manager::get_rules( 1 ) );
    }

    public function test_get_rules_returns_rows_when_present(): void {
        $rows = [ [ 'id' => 1, 'season_name' => 'Alta', 'price_modifier' => '1.5' ] ];
        $GLOBALS['wpdb'] = $this->make_wpdb( null, $rows );
        $result = \LTMS_Booking_Season_Manager::get_rules( 1 );
        $this->assertCount( 1, $result );
    }

    // ── save_rule ─────────────────────────────────────────────────────────

    public function test_save_rule_new_returns_int_on_success(): void {
        $wpdb = $this->make_wpdb( null, [], 99 );
        $wpdb->insert_id = 99;
        $GLOBALS['wpdb'] = $wpdb;

        Monkey\Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );

        $result = \LTMS_Booking_Season_Manager::save_rule( [
            'season_name'    => 'Alta',
            'price_modifier' => 1.5,
            'date_from'      => '2025-12-01',
            'date_to'        => '2025-12-31',
            'product_id'     => 1,
        ] );
        $this->assertIsInt( $result );
    }

    public function test_save_rule_update_returns_existing_id(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( null, [] );
        Monkey\Functions\stubs( [ 'sanitize_text_field' => static fn( $v ) => $v ] );

        $result = \LTMS_Booking_Season_Manager::save_rule( [
            'id'             => 5,
            'season_name'    => 'Alta',
            'price_modifier' => 1.5,
            'date_from'      => '2025-12-01',
            'date_to'        => '2025-12-31',
        ] );
        $this->assertSame( 5, $result );
    }

    // ── Reflexión ─────────────────────────────────────────────────────────

    public function test_init_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Booking_Season_Manager', 'init' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_calculate_total_return_type_is_float(): void {
        $ref = new \ReflectionMethod( 'LTMS_Booking_Season_Manager', 'calculate_total' );
        $this->assertSame( 'float', (string) $ref->getReturnType() );
    }

    public function test_get_modifier_return_type_is_float(): void {
        $ref = new \ReflectionMethod( 'LTMS_Booking_Season_Manager', 'get_modifier_for_date' );
        $this->assertSame( 'float', (string) $ref->getReturnType() );
    }

    public function test_class_is_not_final(): void {
        $this->assertFalse( ( new \ReflectionClass( 'LTMS_Booking_Season_Manager' ) )->isFinal() );
    }
}
