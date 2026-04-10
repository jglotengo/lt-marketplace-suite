<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Shipping_Mode::calculate_shipping() — versión extendida.
 */
class ShippingModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\stubs( [ 'error_log' => null ] );
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stubMode( string $mode ): void
    {
        Monkey\Functions\when( 'get_option' )
            ->alias( static fn( $key, $default = null ) =>
                $key === 'ltms_settings'
                    ? [ 'ltms_shipping_mode' => $mode ]
                    : $default
            );
    }

    // ------------------------------------------------------------------ //
    //  Default
    // ------------------------------------------------------------------ //

    public function test_default_config_returns_null(): void
    {
        Monkey\Functions\when( 'get_option' )->justReturn( null );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_returns_null_or_float_never_other_type(): void
    {
        Monkey\Functions\when( 'get_option' )->justReturn( null );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertTrue( $result === null || is_float( $result ) );
    }

    public function test_does_not_throw_with_empty_package(): void
    {
        Monkey\Functions\when( 'get_option' )->justReturn( null );
        \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertTrue( true );
    }

    public function test_does_not_throw_with_full_package(): void
    {
        Monkey\Functions\when( 'get_option' )->justReturn( null );
        $package = [
            'contents'      => [ [ 'product_id' => 1, 'quantity' => 2 ] ],
            'contents_cost' => 150000,
            'destination'   => [ 'country' => 'CO', 'state' => 'VAC', 'city' => 'Cali' ],
        ];
        $result = \LTMS_Shipping_Mode::calculate_shipping( $package );
        $this->assertTrue( $result === null || is_float( $result ) );
    }

    // ------------------------------------------------------------------ //
    //  mode = 'free' → 0.0
    // ------------------------------------------------------------------ //

    public function test_free_mode_returns_zero_float(): void
    {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertSame( 0.0, $result );
    }

    public function test_free_mode_returns_float_not_int(): void
    {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertIsFloat( $result );
    }

    public function test_free_mode_ignores_package_contents(): void
    {
        $this->stubMode( 'free' );
        $package = [
            'contents'      => [ 'item1', 'item2' ],
            'contents_cost' => 999999,
            'destination'   => [ 'country' => 'CO' ],
        ];
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( $package ) );
    }

    // ------------------------------------------------------------------ //
    //  mode = 'quoted' → null
    // ------------------------------------------------------------------ //

    public function test_quoted_mode_returns_null(): void
    {
        $this->stubMode( 'quoted' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_quoted_mode_ignores_package_destination(): void
    {
        $this->stubMode( 'quoted' );
        $package = [ 'destination' => [ 'country' => 'MX', 'city' => 'CDMX' ] ];
        $this->assertNull( \LTMS_Shipping_Mode::calculate_shipping( $package ) );
    }

    // ------------------------------------------------------------------ //
    //  mode = 'flat' → null
    // ------------------------------------------------------------------ //

    public function test_flat_mode_returns_null(): void
    {
        $this->stubMode( 'flat' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------ //
    //  modos desconocidos / edge cases → null
    // ------------------------------------------------------------------ //

    public function test_unknown_mode_returns_null(): void
    {
        $this->stubMode( 'carrier_api' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_empty_mode_string_returns_null(): void
    {
        $this->stubMode( '' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_uppercase_FREE_mode_returns_null(): void
    {
        // Comparación estricta '=== "free"' → 'FREE' no coincide → retorna null
        $this->stubMode( 'FREE' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_uppercase_QUOTED_mode_returns_null(): void
    {
        // '=== "quoted"' → 'QUOTED' no coincide → retorna null
        $this->stubMode( 'QUOTED' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_mixed_case_Free_mode_returns_null(): void
    {
        $this->stubMode( 'Free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_mode_with_whitespace_returns_null(): void
    {
        $this->stubMode( ' free ' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    public function test_numeric_mode_returns_null(): void
    {
        $this->stubMode( '0' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------ //
    //  Reflexión — estructura y visibilidad de la clase
    // ------------------------------------------------------------------ //

    public function test_class_exists(): void
    {
        $this->assertTrue( class_exists( 'LTMS_Shipping_Mode' ) );
    }

    public function test_calculate_shipping_is_public_static(): void
    {
        $ref = new \ReflectionMethod( 'LTMS_Shipping_Mode', 'calculate_shipping' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_calculate_shipping_return_type_is_nullable_float(): void
    {
        $ref = new \ReflectionMethod( 'LTMS_Shipping_Mode', 'calculate_shipping' );
        $rt  = $ref->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertTrue( $rt->allowsNull() );
        $this->assertSame( 'float', $rt->getName() );
    }

    public function test_calculate_shipping_accepts_array_param(): void
    {
        $ref    = new \ReflectionMethod( 'LTMS_Shipping_Mode', 'calculate_shipping' );
        $params = $ref->getParameters();
        $this->assertCount( 1, $params );
        $type = $params[0]->getType();
        $this->assertNotNull( $type );
        $this->assertSame( 'array', $type->getName() );
    }

    public function test_class_is_not_final(): void
    {
        $ref = new \ReflectionClass( 'LTMS_Shipping_Mode' );
        $this->assertFalse( $ref->isFinal() );
    }

    public function test_free_mode_result_is_not_null(): void
    {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertNotNull( $result );
    }

    public function test_free_mode_result_equals_zero(): void
    {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertEqualsWithDelta( 0.0, $result, 0.0001 );
    }

    // ------------------------------------------------------------------ //
    //  Package con campos nulos / malformados
    // ------------------------------------------------------------------ //

    public function test_null_destination_does_not_throw(): void {
        Monkey\Functions\when( 'get_option' )->justReturn( null );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [ 'destination' => null ] );
        $this->assertTrue( $result === null || is_float( $result ) );
    }

    public function test_contents_cost_zero_does_not_change_result(): void {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [ 'contents_cost' => 0 ] );
        $this->assertSame( 0.0, $result );
    }

    public function test_negative_contents_cost_does_not_throw(): void {
        $this->stubMode( 'free' );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [ 'contents_cost' => -100 ] );
        $this->assertSame( 0.0, $result );
    }

    public function test_free_mode_large_package_still_zero(): void {
        $this->stubMode( 'free' );
        $package = [
            'contents'      => array_fill( 0, 50, [ 'product_id' => 1, 'quantity' => 10 ] ),
            'contents_cost' => 99_999_999,
            'destination'   => [ 'country' => 'CO', 'state' => 'VAC', 'city' => 'Cali' ],
        ];
        $this->assertSame( 0.0, \LTMS_Shipping_Mode::calculate_shipping( $package ) );
    }

    public function test_quoted_large_package_still_null(): void {
        $this->stubMode( 'quoted' );
        $package = [
            'contents'      => array_fill( 0, 50, [ 'product_id' => 1, 'quantity' => 10 ] ),
            'contents_cost' => 99_999_999,
            'destination'   => [ 'country' => 'CO', 'state' => 'VAC', 'city' => 'Cali' ],
        ];
        $this->assertNull( \LTMS_Shipping_Mode::calculate_shipping( $package ) );
    }

    // ------------------------------------------------------------------ //
    //  Invariante de tipo en todos los modos
    // ------------------------------------------------------------------ //

    /**
     * @dataProvider provider_all_modes
     */
    public function test_return_is_always_null_or_float( string $mode ): void {
        $this->stubMode( $mode );
        $result = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertTrue( $result === null || is_float( $result ),
            "Mode '{$mode}' returned unexpected type: " . gettype( $result ) );
    }

    public static function provider_all_modes(): array {
        return [
            'free'       => [ 'free' ],
            'quoted'     => [ 'quoted' ],
            'flat'       => [ 'flat' ],
            'unknown'    => [ 'carrier_api' ],
            'empty'      => [ '' ],
            'numeric'    => [ '1' ],
            'FREE upper' => [ 'FREE' ],
        ];
    }

    // ------------------------------------------------------------------ //
    //  Idempotencia — misma llamada, mismo resultado
    // ------------------------------------------------------------------ //

    public function test_free_mode_idempotent(): void {
        $this->stubMode( 'free' );
        $package = [ 'destination' => [ 'country' => 'CO' ] ];
        $r1 = \LTMS_Shipping_Mode::calculate_shipping( $package );
        $r2 = \LTMS_Shipping_Mode::calculate_shipping( $package );
        $this->assertSame( $r1, $r2 );
    }

    public function test_flat_mode_idempotent(): void {
        $this->stubMode( 'flat' );
        $r1 = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $r2 = \LTMS_Shipping_Mode::calculate_shipping( [] );
        $this->assertSame( $r1, $r2 );
    }

}
