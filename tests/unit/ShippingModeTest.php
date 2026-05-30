<?php
/**
 * ShippingModeTest — Tests unitarios para LTMS_Shipping_Mode
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Shipping_Mode
 */
class ShippingModeTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        LTMS_Core_Config::flush_cache();

        $ref = new \ReflectionProperty( \LTMS_Shipping_Mode::class, 'initialized' );
        $ref->setAccessible( true );
        $ref->setValue( null, false );
    }

    protected function tearDown(): void {
        LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    protected function require_class( string $class_name = 'LTMS_Shipping_Mode' ): void {
        static $loaded = false;
        if ( ! $loaded ) {
            require_once dirname( __DIR__, 2 ) . '/includes/shipping/class-ltms-shipping-mode.php';
            $loaded = true;
        }
    }

    // ── SECCIÓN 1: Constantes ─────────────────────────────────────────────

    public function test_mode_flat_constant(): void {
        $this->require_class();
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::MODE_FLAT );
    }

    public function test_mode_free_absorbed_constant(): void {
        $this->require_class();
        $this->assertSame( 'free_absorbed', \LTMS_Shipping_Mode::MODE_FREE_ABSORBED );
    }

    public function test_mode_hybrid_constant(): void {
        $this->require_class();
        $this->assertSame( 'hybrid', \LTMS_Shipping_Mode::MODE_HYBRID );
    }

    public function test_valid_modes_contains_all_three(): void {
        $this->require_class();
        $this->assertCount( 3, \LTMS_Shipping_Mode::VALID_MODES );
        $this->assertContains( 'flat',          \LTMS_Shipping_Mode::VALID_MODES );
        $this->assertContains( 'free_absorbed', \LTMS_Shipping_Mode::VALID_MODES );
        $this->assertContains( 'hybrid',        \LTMS_Shipping_Mode::VALID_MODES );
    }

    // ── SECCIÓN 2: get_mode_for_vendor ────────────────────────────────────

    public function test_get_mode_returns_flat_by_default(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::get_mode_for_vendor( 1 ) );
    }

    public function test_get_mode_returns_vendor_meta_when_set(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $this->assertSame( 'free_absorbed', \LTMS_Shipping_Mode::get_mode_for_vendor( 5 ) );
    }

    public function test_get_mode_ignores_invalid_meta(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'invalid_mode' );
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'hybrid' );
        $this->assertSame( 'hybrid', \LTMS_Shipping_Mode::get_mode_for_vendor( 1 ) );
    }

    public function test_get_mode_falls_back_to_global_option(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( '' );
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'free_absorbed' );
        $this->assertSame( 'free_absorbed', \LTMS_Shipping_Mode::get_mode_for_vendor( 1 ) );
    }

    public function test_get_mode_vendor_id_zero_uses_global(): void {
        $this->require_class();
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'hybrid' );
        $this->assertSame( 'hybrid', \LTMS_Shipping_Mode::get_mode_for_vendor( 0 ) );
    }

    public function test_get_mode_invalid_global_returns_flat(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( '' );
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'bogus' );
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::get_mode_for_vendor( 1 ) );
    }

    // ── SECCIÓN 3: set_mode_for_vendor ────────────────────────────────────

    public function test_set_mode_returns_false_for_vendor_zero(): void {
        $this->require_class();
        $this->assertFalse( \LTMS_Shipping_Mode::set_mode_for_vendor( 0, 'flat' ) );
    }

    public function test_set_mode_returns_false_for_invalid_mode(): void {
        $this->require_class();
        $this->assertFalse( \LTMS_Shipping_Mode::set_mode_for_vendor( 1, 'invalid' ) );
    }

    public function test_set_mode_returns_true_for_valid(): void {
        $this->require_class();
        Functions\when( 'update_user_meta' )->justReturn( true );
        $this->assertTrue( \LTMS_Shipping_Mode::set_mode_for_vendor( 1, 'flat' ) );
    }

    public function test_set_mode_free_absorbed_valid(): void {
        $this->require_class();
        Functions\when( 'update_user_meta' )->justReturn( 1 );
        $this->assertTrue( \LTMS_Shipping_Mode::set_mode_for_vendor( 3, 'free_absorbed' ) );
    }

    public function test_set_mode_hybrid_valid(): void {
        $this->require_class();
        Functions\when( 'update_user_meta' )->justReturn( 1 );
        $this->assertTrue( \LTMS_Shipping_Mode::set_mode_for_vendor( 3, 'hybrid' ) );
    }

    // ── SECCIÓN 4: calculate_shipping — modo flat ─────────────────────────

    public function test_calculate_flat_returns_global_flat_rate(): void {
        $this->require_class();
        LTMS_Core_Config::set( 'ltms_flat_shipping_rate', 15000 );
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'flat';
            if ( $key === '_ltms_flat_shipping_rate' ) return '';
            return '';
        } );
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 50000.0, [] );
        $this->assertSame( 15000.0, $cost );
    }

    public function test_calculate_flat_uses_vendor_meta_rate(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'flat';
            if ( $key === '_ltms_flat_shipping_rate' ) return '8000';
            return '';
        } );
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 2, 0.0, [] );
        $this->assertSame( 8000.0, $cost );
    }

    // ── SECCIÓN 5: calculate_shipping — modo free_absorbed ────────────────

    public function test_calculate_free_absorbed_returns_zero(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 100000.0, [] );
        $this->assertSame( 0.0, $cost );
    }

    public function test_calculate_free_absorbed_always_zero_regardless_total(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( 1, 0.0, [] ) );
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( 1, 999999.0, [] ) );
    }

    // ── SECCIÓN 6: calculate_shipping — modo hybrid ───────────────────────

    public function test_calculate_hybrid_empty_package_returns_zero(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'hybrid' );
        LTMS_Core_Config::set( 'ltms_shipping_free_categories', '1,2' );
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 0.0, [ 'contents' => [] ] );
        $this->assertSame( 0.0, $cost );
    }

    public function test_calculate_hybrid_no_free_cats_returns_flat(): void {
        $this->require_class();
        LTMS_Core_Config::set( 'ltms_shipping_free_categories', '' );
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'hybrid';
            if ( $key === '_ltms_flat_shipping_rate' ) return '5000';
            return '';
        } );
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 0.0, [ 'contents' => [ ['product_id' => 10] ] ] );
        $this->assertSame( 5000.0, $cost );
    }

    public function test_calculate_hybrid_all_products_in_free_cats_returns_zero(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'hybrid' );
        LTMS_Core_Config::set( 'ltms_shipping_free_categories', '5,6' );
        Functions\when( 'wc_get_product_term_ids' )->justReturn( [ 5, 7 ] );
        $package = [ 'contents' => [ ['product_id' => 10], ['product_id' => 11] ] ];
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 0.0, $package );
        $this->assertSame( 0.0, $cost );
    }

    public function test_calculate_hybrid_product_not_in_free_cats_returns_flat(): void {
        $this->require_class();
        LTMS_Core_Config::set( 'ltms_shipping_free_categories', '5,6' );
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'hybrid';
            if ( $key === '_ltms_flat_shipping_rate' ) return '12000';
            return '';
        } );
        Functions\when( 'wc_get_product_term_ids' )->justReturn( [ 99 ] );
        $package = [ 'contents' => [ ['product_id' => 10] ] ];
        $cost = \LTMS_Shipping_Mode::calculate_shipping( 1, 0.0, $package );
        $this->assertSame( 12000.0, $cost );
    }

    // ── SECCIÓN 7: is_free_for_customer ──────────────────────────────────

    public function test_is_free_for_customer_free_absorbed_true(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $this->assertTrue( \LTMS_Shipping_Mode::is_free_for_customer( 1 ) );
    }

    public function test_is_free_for_customer_flat_false(): void {
        $this->require_class();
        Functions\when( 'get_user_meta' )->justReturn( 'flat' );
        $this->assertFalse( \LTMS_Shipping_Mode::is_free_for_customer( 1 ) );
    }

    // ── SECCIÓN 8: save_vendor_mode_from_post ─────────────────────────────

    public function test_save_vendor_mode_from_post_valid(): void {
        $this->require_class();
        $called_with = null;
        Functions\when( 'update_user_meta' )->alias(
            function( $uid, $key, $val ) use ( &$called_with ) {
                $called_with = [ $uid, $key, $val ];
                return true;
            }
        );
        Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
        \LTMS_Shipping_Mode::save_vendor_mode_from_post( 7, [ 'ltms_shipping_mode' => 'hybrid' ] );
        $this->assertSame( [ 7, '_ltms_shipping_mode', 'hybrid' ], $called_with );
    }

    public function test_save_vendor_mode_from_post_empty_does_nothing(): void {
        $this->require_class();
        $called = false;
        Functions\when( 'update_user_meta' )->alias( function() use ( &$called ) { $called = true; return true; } );
        Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
        \LTMS_Shipping_Mode::save_vendor_mode_from_post( 7, [] );
        $this->assertFalse( $called );
    }

    // ── SECCIÓN 9: Reflexión ─────────────────────────────────────────────

    public function test_class_is_not_final(): void {
        $this->require_class();
        $this->assertFalse( ( new \ReflectionClass( \LTMS_Shipping_Mode::class ) )->isFinal() );
    }

    public function test_init_is_public_static(): void {
        $this->require_class();
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'init' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_get_mode_is_public_static(): void {
        $this->require_class();
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'get_mode_for_vendor' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_set_mode_is_public_static(): void {
        $this->require_class();
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'set_mode_for_vendor' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_calculate_shipping_is_public_static(): void {
        $this->require_class();
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'calculate_shipping' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_calculate_shipping_returns_float(): void {
        $this->require_class();
        $rt = ( new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'calculate_shipping' ) )->getReturnType();
        $this->assertSame( 'float', (string) $rt );
    }
}
