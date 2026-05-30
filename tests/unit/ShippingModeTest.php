<?php
/**
 * ShippingModeTest — Tests para LTMS_Shipping_Mode (includes/business)
 * @package LTMS\Tests\Unit
 */
declare( strict_types=1 );
use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/** @covers LTMS_Shipping_Mode */
class ShippingModeTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        LTMS_Core_Config::flush_cache();
    }

    protected function tearDown(): void {
        LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    protected function require_class( string $class_name = 'LTMS_Shipping_Mode' ): void {
        // autoloader carga la clase
    }

    // ── Constantes ────────────────────────────────────────────────────────

    public function test_mode_flat_constant(): void {
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::MODE_FLAT );
    }

    public function test_mode_free_absorbed_constant(): void {
        $this->assertSame( 'free_absorbed', \LTMS_Shipping_Mode::MODE_FREE_ABSORBED );
    }

    public function test_mode_hybrid_constant(): void {
        $this->assertSame( 'hybrid', \LTMS_Shipping_Mode::MODE_HYBRID );
    }

    public function test_mode_quoted_constant(): void {
        $this->assertSame( 'quoted', \LTMS_Shipping_Mode::MODE_QUOTED );
    }

    public function test_valid_modes_contains_required(): void {
        $modes = \LTMS_Shipping_Mode::valid_modes();
        $this->assertContains( 'flat',          $modes );
        $this->assertContains( 'free_absorbed', $modes );
        $this->assertContains( 'hybrid',        $modes );
        $this->assertContains( 'quoted',        $modes );
    }

    // ── get_global_mode ───────────────────────────────────────────────────

    public function test_get_global_mode_returns_flat_by_default(): void {
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::get_global_mode() );
    }

    public function test_get_global_mode_returns_configured_value(): void {
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'quoted' );
        $this->assertSame( 'quoted', \LTMS_Shipping_Mode::get_global_mode() );
    }

    // ── get_vendor_mode ───────────────────────────────────────────────────

    public function test_get_vendor_mode_returns_global_when_no_meta(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'flat' );
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::get_vendor_mode( 1 ) );
    }

    public function test_get_vendor_mode_returns_vendor_override(): void {
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $this->assertSame( 'free_absorbed', \LTMS_Shipping_Mode::get_vendor_mode( 5 ) );
    }

    public function test_get_vendor_mode_ignores_invalid_meta(): void {
        Functions\when( 'get_user_meta' )->justReturn( 'bogus' );
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'flat' );
        $this->assertSame( 'flat', \LTMS_Shipping_Mode::get_vendor_mode( 1 ) );
    }

    public function test_get_vendor_mode_vendor_zero_uses_global(): void {
        LTMS_Core_Config::set( 'ltms_shipping_mode', 'hybrid' );
        $this->assertSame( 'hybrid', \LTMS_Shipping_Mode::get_vendor_mode( 0 ) );
    }

    // ── calculate_shipping ────────────────────────────────────────────────

    public function test_calculate_free_absorbed_returns_zero(): void {
        Functions\when( 'get_user_meta' )->justReturn( 'free_absorbed' );
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( [], 1 ) );
    }

    public function test_calculate_free_returns_zero(): void {
        Functions\when( 'get_user_meta' )->justReturn( 'free' );
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( [], 1 ) );
    }

    public function test_calculate_quoted_returns_null(): void {
        Functions\when( 'get_user_meta' )->justReturn( 'quoted' );
        $this->assertNull( \LTMS_Shipping_Mode::calculate_shipping( [], 1 ) );
    }

    public function test_calculate_flat_uses_vendor_meta_rate(): void {
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'flat';
            if ( $key === '_ltms_flat_shipping_rate' ) return '8000';
            return '';
        } );
        $this->assertSame( 8000.0, \LTMS_Shipping_Mode::calculate_shipping( [], 2 ) );
    }

    public function test_calculate_flat_uses_global_rate(): void {
        Functions\when( 'get_user_meta' )->alias( function( $uid, $key ) {
            if ( $key === '_ltms_shipping_mode'      ) return 'flat';
            if ( $key === '_ltms_flat_shipping_rate' ) return '';
            return '';
        } );
        LTMS_Core_Config::set( 'ltms_flat_shipping_rate', 15000 );
        $this->assertSame( 15000.0, \LTMS_Shipping_Mode::calculate_shipping( [], 1 ) );
    }

    public function test_calculate_shipping_returns_nullable_float(): void {
        $rt = ( new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'calculate_shipping' ) )->getReturnType();
        $this->assertStringContainsString( 'float', (string) $rt );
    }

    // ── Reflexión ─────────────────────────────────────────────────────────

    public function test_get_global_mode_is_public_static(): void {
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'get_global_mode' );
        $this->assertTrue( $m->isPublic() && $m->isStatic() );
    }

    public function test_get_vendor_mode_is_public_static(): void {
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'get_vendor_mode' );
        $this->assertTrue( $m->isPublic() && $m->isStatic() );
    }

    public function test_calculate_shipping_is_public_static(): void {
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'calculate_shipping' );
        $this->assertTrue( $m->isPublic() && $m->isStatic() );
    }

    public function test_valid_modes_is_public_static(): void {
        $m = new \ReflectionMethod( \LTMS_Shipping_Mode::class, 'valid_modes' );
        $this->assertTrue( $m->isPublic() && $m->isStatic() );
    }
}
