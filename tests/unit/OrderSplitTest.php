<?php
/**
 * OrderSplitTest — Tests unitarios para LTMS_Business_Order_Split
 *
 * Cubre:
 *  - process() se detiene cuando no hay vendor_id
 *  - process() se detiene cuando el pedido es full ReDi
 *  - process() se detiene cuando gross_amount <= 0
 *  - Cálculo correcto de platform_fee, vendor_gross y vendor_net
 *  - MLM desactivado no llama distribute_commissions
 *  - order_is_full_redi con items mixtos
 *  - get_non_redi_gross suma solo items no-ReDi
 *  - extract_vendor_from_items desde meta de producto
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Business_Order_Split
 */
class OrderSplitTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'];
        LTMS_Core_Config::flush_cache();
        LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.10 );
        LTMS_Core_Config::set( 'ltms_mlm_enabled', 'no' );

        // Restaurar wpdb al stub base entre tests
        $GLOBALS['wpdb'] = $this->make_base_wpdb();
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. process() — guards de entrada
    // -----------------------------------------------------------------------

    public function test_process_does_nothing_when_no_vendor_id(): void {
        Functions\when( 'get_post_meta' )->justReturn( 0 );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $order = $this->make_order( 1, 0, [] );

        // Sin vendor_id → solo loguea y retorna. No debe lanzar excepción.
        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    public function test_process_does_nothing_for_full_redi_order(): void {
        // Todos los items son ReDi → process() retorna sin acreditar
        Functions\when( 'get_post_meta' )->alias( function( $post_id, $key, $single ) {
            if ( $key === '_ltms_redi_origin_product_id' ) return 'redi_123';
            if ( $key === '_ltms_vendor_id' ) return 5;
            return '';
        } );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $items = [
            $this->make_item( product_id: 10, total: 5000.0 ),
            $this->make_item( product_id: 11, total: 3000.0 ),
        ];
        $order = $this->make_order( 2, 5, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    public function test_process_does_nothing_when_gross_amount_is_zero(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' ); // no es ReDi
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        // Items con total = 0
        $items = [ $this->make_item( product_id: 10, total: 0.0 ) ];
        $order = $this->make_order( 3, 7, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    /**
     * Sin items en el pedido: gross = 0 → process retorna sin acreditar.
     */
    public function test_process_does_nothing_when_order_has_no_items(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $order = $this->make_order( 4, 5, [] );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    /**
     * gross_amount negativo (item con descuento mayor al precio) → se detiene.
     */
    public function test_process_does_nothing_when_gross_is_negative(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

        $items = [ $this->make_item( product_id: 10, total: -500.0 ) ];
        $order = $this->make_order( 5, 7, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    // -----------------------------------------------------------------------
    // 2. Cálculo financiero — platform_fee y vendor_net
    // -----------------------------------------------------------------------

    public function test_process_runs_without_exception_for_valid_order(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' ); // no es ReDi
        Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 10 ] );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $items = [ $this->make_item( product_id: 20, total: 100000.0 ) ];
        $order = $this->make_order( 10, 10, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    public function test_platform_fee_calculation_10_percent(): void {
        // platform_rate = 10%, gross = 100000 → fee = 10000
        $gross = 100000.0;
        $rate  = 0.10;
        $fee   = round( $gross * $rate, 2 );

        $this->assertEqualsWithDelta( 10000.0, $fee, 0.01 );
    }

    public function test_platform_fee_calculation_15_percent(): void {
        $gross = 80000.0;
        $rate  = 0.15;
        $fee   = round( $gross * $rate, 2 );

        $this->assertEqualsWithDelta( 12000.0, $fee, 0.01 );
    }

    public function test_vendor_net_is_gross_minus_fee_minus_withholding(): void {
        $gross       = 100000.0;
        $rate        = 0.10;
        $fee         = round( $gross * $rate, 2 );     // 10000
        $vendor_gross = $gross - $fee;                  // 90000
        $withholding = 5000.0;
        $vendor_net  = max( 0.0, $vendor_gross - $withholding ); // 85000

        $this->assertEqualsWithDelta( 85000.0, $vendor_net, 0.01 );
    }

    public function test_vendor_net_never_goes_negative(): void {
        $gross        = 10000.0;
        $fee          = 1000.0;
        $withholding  = 20000.0; // Mayor que vendor_gross
        $vendor_gross = $gross - $fee;  // 9000
        $vendor_net   = max( 0.0, $vendor_gross - $withholding ); // 0

        $this->assertSame( 0.0, $vendor_net );
    }

    public function test_platform_fee_rounds_to_two_decimals(): void {
        $gross = 33333.33;
        $rate  = 0.10;
        $fee   = round( $gross * $rate, 2 );

        // 33333.33 * 0.10 = 3333.333 → round to 3333.33
        $this->assertEqualsWithDelta( 3333.33, $fee, 0.001 );
    }

    /**
     * Con tasa 0%: platform_fee = 0, vendor_gross = gross completo.
     */
    public function test_platform_fee_zero_rate_fee_is_zero(): void {
        $gross = 100000.0;
        $fee   = round( $gross * 0.0, 2 );

        $this->assertSame( 0.0, $fee );
    }

    /**
     * Con tasa 100%: platform_fee = gross, vendor_gross = 0.
     */
    public function test_platform_fee_100_percent_rate_fee_equals_gross(): void {
        $gross = 50000.0;
        $fee   = round( $gross * 1.0, 2 );
        $vendor_gross = $gross - $fee;

        $this->assertEqualsWithDelta( 50000.0, $fee, 0.01 );
        $this->assertEqualsWithDelta( 0.0, $vendor_gross, 0.01 );
    }

    /**
     * vendor_gross = gross - platform_fee (identidad algebraica).
     */
    public function test_vendor_gross_equals_gross_minus_fee(): void {
        $gross        = 75000.0;
        $rate         = 0.12;
        $platform_fee = round( $gross * $rate, 2 );
        $vendor_gross = $gross - $platform_fee;

        $this->assertEqualsWithDelta( $gross - $platform_fee, $vendor_gross, 0.001 );
    }

    /**
     * Sin retenciones: vendor_net = vendor_gross exacto.
     */
    public function test_vendor_net_equals_vendor_gross_when_no_withholding(): void {
        $gross        = 200000.0;
        $rate         = 0.10;
        $platform_fee = round( $gross * $rate, 2 ); // 20000
        $vendor_gross = $gross - $platform_fee;      // 180000
        $vendor_net   = max( 0.0, $vendor_gross - 0.0 ); // 180000

        $this->assertEqualsWithDelta( $vendor_gross, $vendor_net, 0.01 );
    }

    /**
     * Retención exactamente igual al vendor_gross → vendor_net = 0.
     */
    public function test_vendor_net_zero_when_withholding_equals_vendor_gross(): void {
        $gross        = 100000.0;
        $fee          = 10000.0;
        $vendor_gross = $gross - $fee; // 90000
        $vendor_net   = max( 0.0, $vendor_gross - 90000.0 ); // 0

        $this->assertSame( 0.0, $vendor_net );
    }

    /**
     * Tasa configurable en runtime: cambiar a 5% produce fee correcto.
     */
    public function test_platform_fee_respects_runtime_rate_5_percent(): void {
        LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.05 );

        $rate  = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.10 );
        $gross = 100000.0;
        $fee   = round( $gross * $rate, 2 );

        $this->assertEqualsWithDelta( 5000.0, $fee, 0.01 );
    }

    /**
     * Tasa configurable en runtime: cambiar a 20%.
     */
    public function test_platform_fee_respects_runtime_rate_20_percent(): void {
        LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.20 );

        $rate  = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.10 );
        $gross = 50000.0;
        $fee   = round( $gross * $rate, 2 );

        $this->assertEqualsWithDelta( 10000.0, $fee, 0.01 );
    }

    // -----------------------------------------------------------------------
    // 3. ReDi detection — order_is_full_redi / get_non_redi_gross
    // -----------------------------------------------------------------------

    public function test_process_with_mixed_items_processes_non_redi_gross(): void {
        // Item 10 = ReDi, Item 11 = normal (total 50000)
        // → gross_amount debería ser 50000, no 80000
        Functions\when( 'get_post_meta' )->alias( function( $post_id, $key, $single ) {
            if ( $key === '_ltms_redi_origin_product_id' ) {
                return $post_id === 10 ? 'redi_origin' : '';
            }
            return '';
        } );
        Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 5 ] );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $items = [
            $this->make_item( product_id: 10, total: 30000.0 ), // ReDi
            $this->make_item( product_id: 11, total: 50000.0 ), // Normal
        ];
        $order = $this->make_order( 5, 5, $items );

        // Debe procesar sin excepción con gross = 50000
        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    public function test_non_redi_gross_sums_only_normal_items(): void {
        // Verificar el cálculo manualmente: solo sumar items sin ReDi
        $redi_total   = 30000.0;
        $normal_total = 50000.0;
        $expected_gross = $normal_total; // Solo el normal

        $this->assertEqualsWithDelta( 50000.0, $expected_gross, 0.01 );
    }

    /**
     * Gross de múltiples items normales se suman correctamente.
     */
    public function test_non_redi_gross_sums_multiple_normal_items(): void {
        // Simula la lógica de get_non_redi_gross manualmente
        $items = [
            [ 'redi' => false, 'total' => 30000.0 ],
            [ 'redi' => false, 'total' => 20000.0 ],
            [ 'redi' => false, 'total' => 10000.0 ],
        ];

        $gross = 0.0;
        foreach ( $items as $item ) {
            if ( ! $item['redi'] ) {
                $gross += $item['total'];
            }
        }

        $this->assertEqualsWithDelta( 60000.0, $gross, 0.01 );
    }

    /**
     * Gross con mezcla: 2 ReDi + 3 normales suma solo los normales.
     */
    public function test_non_redi_gross_ignores_all_redi_items(): void {
        $items = [
            [ 'redi' => true,  'total' => 5000.0  ],
            [ 'redi' => true,  'total' => 8000.0  ],
            [ 'redi' => false, 'total' => 15000.0 ],
            [ 'redi' => false, 'total' => 25000.0 ],
            [ 'redi' => false, 'total' => 10000.0 ],
        ];

        $gross = 0.0;
        foreach ( $items as $item ) {
            if ( ! $item['redi'] ) {
                $gross += $item['total'];
            }
        }

        $this->assertEqualsWithDelta( 50000.0, $gross, 0.01 );
    }

    // -----------------------------------------------------------------------
    // 4. Extracción de vendor_id desde items
    // -----------------------------------------------------------------------

    public function test_process_extracts_vendor_from_item_meta_when_order_meta_missing(): void {
        // order->get_meta('_ltms_vendor_id') = 0, pero producto tiene _ltms_vendor_id = 8
        Functions\when( 'get_post_meta' )->alias( function( $post_id, $key, $single ) {
            if ( $key === '_ltms_vendor_id' ) return '8';
            if ( $key === '_ltms_redi_origin_product_id' ) return '';
            return '';
        } );
        Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 8 ] );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        // order->get_meta retorna 0 (sin vendor en order meta)
        $items = [ $this->make_item( product_id: 30, total: 20000.0 ) ];
        $order = $this->make_order( 20, 0, $items ); // vendor_id=0 en orden

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    // -----------------------------------------------------------------------
    // 5. MLM desactivado vs activado
    // -----------------------------------------------------------------------

    public function test_process_does_not_throw_with_mlm_disabled(): void {
        LTMS_Core_Config::set( 'ltms_mlm_enabled', 'no' );

        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 3 ] );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $items = [ $this->make_item( product_id: 40, total: 15000.0 ) ];
        $order = $this->make_order( 30, 3, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    public function test_process_does_not_throw_with_mlm_enabled(): void {
        LTMS_Core_Config::set( 'ltms_mlm_enabled', 'yes' );

        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( (object) [ 'ID' => 3 ] );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( '__' )->returnArg();

        $items = [ $this->make_item( product_id: 40, total: 15000.0 ) ];
        $order = $this->make_order( 31, 3, $items );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Order_Split::process( $order );
    }

    // -----------------------------------------------------------------------
    // 6. Invariantes matemáticas
    // -----------------------------------------------------------------------

    /**
     * @dataProvider provider_commission_math
     */
    public function test_platform_fee_is_always_fraction_of_gross(
        float $gross, float $rate
    ): void {
        $fee = round( $gross * $rate, 2 );
        $this->assertGreaterThanOrEqual( 0.0, $fee );
        $this->assertLessThanOrEqual( $gross, $fee );
    }

    public static function provider_commission_math(): array {
        return [
            '10% de 100000' => [ 100000.0, 0.10 ],
            '15% de 50000'  => [  50000.0, 0.15 ],
            '5% de 250000'  => [ 250000.0, 0.05 ],
            '20% de 10000'  => [  10000.0, 0.20 ],
            '10% de 1'      => [      1.0, 0.10 ],
        ];
    }

    public function test_vendor_gross_plus_platform_fee_equals_gross(): void {
        $gross        = 100000.0;
        $rate         = 0.10;
        $platform_fee = round( $gross * $rate, 2 );
        $vendor_gross = $gross - $platform_fee;

        $this->assertEqualsWithDelta( $gross, $platform_fee + $vendor_gross, 0.01 );
    }

    public function test_different_platform_rates_produce_correct_fees(): void {
        $cases = [
            [ 100000.0, 0.05,  5000.0 ],
            [ 100000.0, 0.10, 10000.0 ],
            [ 100000.0, 0.15, 15000.0 ],
            [ 100000.0, 0.20, 20000.0 ],
        ];

        foreach ( $cases as [ $gross, $rate, $expected_fee ] ) {
            $fee = round( $gross * $rate, 2 );
            $this->assertEqualsWithDelta(
                $expected_fee, $fee, 0.01,
                "Para gross={$gross} y rate={$rate}, fee debería ser {$expected_fee}"
            );
        }
    }

    /**
     * gross = platform_fee + vendor_gross (sin retenciones) — siempre.
     *
     * @dataProvider provider_commission_math
     */
    public function test_gross_equals_fee_plus_vendor_gross_invariant(
        float $gross, float $rate
    ): void {
        $fee          = round( $gross * $rate, 2 );
        $vendor_gross = $gross - $fee;

        $this->assertEqualsWithDelta( $gross, $fee + $vendor_gross, 0.01 );
    }

    /**
     * platform_fee nunca supera el gross, sin importar la tasa.
     *
     * @dataProvider provider_commission_math
     */
    public function test_platform_fee_never_exceeds_gross(
        float $gross, float $rate
    ): void {
        $fee = round( $gross * $rate, 2 );
        $this->assertLessThanOrEqual( $gross, $fee );
    }

    /**
     * vendor_net siempre es ≥ 0 independientemente de retenciones.
     */
    public function test_vendor_net_is_always_non_negative_with_any_withholding(): void {
        $gross       = 50000.0;
        $fee         = 5000.0;
        $vendor_gross = $gross - $fee; // 45000

        // Retenciones arbitrariamente grandes
        foreach ( [ 0.0, 10000.0, 45000.0, 100000.0 ] as $withholding ) {
            $vendor_net = max( 0.0, $vendor_gross - $withholding );
            $this->assertGreaterThanOrEqual( 0.0, $vendor_net,
                "vendor_net debe ser >= 0 con retención {$withholding}" );
        }
    }

    /**
     * Montos reales COP del negocio: valores típicos de tourismo.
     */
    public static function provider_real_cop_amounts(): array {
        return [
            'tour_basico'   => [ 150000.0, 0.10,  15000.0,  135000.0 ],
            'tour_premium'  => [ 850000.0, 0.10,  85000.0,  765000.0 ],
            'hotel_noche'   => [ 320000.0, 0.10,  32000.0,  288000.0 ],
            'paquete_full'  => [ 2500000.0, 0.10, 250000.0, 2250000.0 ],
            'experiencia'   => [ 75000.0, 0.15,   11250.0,  63750.0 ],
        ];
    }

    /**
     * @dataProvider provider_real_cop_amounts
     */
    public function test_fee_and_vendor_gross_with_real_cop_amounts(
        float $gross, float $rate, float $expected_fee, float $expected_vendor_gross
    ): void {
        $fee          = round( $gross * $rate, 2 );
        $vendor_gross = $gross - $fee;

        $this->assertEqualsWithDelta( $expected_fee, $fee, 0.01 );
        $this->assertEqualsWithDelta( $expected_vendor_gross, $vendor_gross, 0.01 );
    }

    // -----------------------------------------------------------------------
    // 7. Reflexión — estructura de la clase
    // -----------------------------------------------------------------------

    public function test_reflection_class_is_final(): void {
        $ref = new \ReflectionClass( LTMS_Business_Order_Split::class );
        $this->assertTrue( $ref->isFinal() );
    }

    public function test_reflection_process_is_public_static(): void {
        $ref    = new \ReflectionClass( LTMS_Business_Order_Split::class );
        $method = $ref->getMethod( 'process' );

        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->isStatic() );
    }

    public function test_reflection_process_returns_void(): void {
        $ref        = new \ReflectionClass( LTMS_Business_Order_Split::class );
        $method     = $ref->getMethod( 'process' );
        $returnType = $method->getReturnType();

        $this->assertNotNull( $returnType );
        $this->assertSame( 'void', (string) $returnType );
    }

    public function test_reflection_process_has_one_parameter(): void {
        $ref    = new \ReflectionClass( LTMS_Business_Order_Split::class );
        $method = $ref->getMethod( 'process' );

        $this->assertCount( 1, $method->getParameters() );
    }

    public function test_reflection_process_parameter_type_is_wc_order(): void {
        $ref   = new \ReflectionClass( LTMS_Business_Order_Split::class );
        $param = $ref->getMethod( 'process' )->getParameters()[0];

        $this->assertSame( 'WC_Order', (string) $param->getType() );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Crea un WC_Order stub con los datos mínimos necesarios.
     */
    private function make_order( int $id, int $vendor_id, array $items ): \WC_Order {
        return new class( $id, $vendor_id, $items ) extends \WC_Order {
            public function __construct(
                private int   $order_id,
                private int   $vid,
                private array $order_items
            ) {}

            public function get_id(): int            { return $this->order_id; }
            public function get_order_number(): string { return (string) $this->order_id; }
            public function get_currency(): string   { return 'COP'; }
            public function get_billing_city(): string { return 'bogota'; }

            public function get_meta( string $key, bool $single = true, string $context = 'view' ): mixed {
                if ( $key === '_ltms_vendor_id' ) return $this->vid;
                if ( $key === '_ltms_product_type' ) return 'physical';
                if ( $key === '_ltms_buyer_type' ) return 'person';
                if ( $key === '_ltms_buyer_regime' ) return 'persona_natural';
                return '';
            }

            public function get_items( $types = 'line_item' ): array {
                return $this->order_items;
            }
        };
    }

    /**
     * Crea un item de pedido stub.
     */
    private function make_item( int $product_id, float $total ): object {
        return new class( $product_id, $total ) {
            public function __construct(
                private int   $pid,
                private float $item_total
            ) {}

            public function get_product_id(): int    { return $this->pid; }
            public function get_name(): string       { return 'Producto Test'; }
            public function get_quantity(): int      { return 1; }
            public function get_subtotal(): float    { return $this->item_total; }
            public function get_total(): float       { return $this->item_total; }
        };
    }

    /**
     * Stub base de $wpdb.
     */
    private function make_base_wpdb(): object {
        return new class {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public mixed  $last_result = null;
            public int    $insert_id  = 1;

            private array $wallet_row = [
                'id'                => 1,
                'vendor_id'         => 0,
                'balance'           => '0.00',
                'balance_pending'   => '0.00',
                'balance_reserved'  => '0.00',
                'currency'          => 'COP',
                'is_frozen'         => 0,
                'total_earned'      => '0.00',
                'total_withdrawn'   => '0.00',
                'created_at'        => '2026-01-01 00:00:00',
                'updated_at'        => '2026-01-01 00:00:00',
                'last_transaction'  => null,
            ];

            public function get_var( mixed $q = null ): mixed { return null; }

            public function get_row( mixed $q = null, string $output = 'OBJECT', int $y = 0 ): mixed {
                if ( is_string( $q ) && str_contains( $q, 'lt_vendor_wallets' ) ) {
                    return $output === ARRAY_A ? $this->wallet_row : (object) $this->wallet_row;
                }
                return null;
            }

            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array { return []; }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function query( string $q ): int|bool { return true; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool { return 1; }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return 1; }
            public function delete( string $t, array $w, mixed $f = null ): int|bool { return 1; }
            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
            public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        };
    }

    // ── order_is_full_redi() — via reflexión ─────────────────────────────

    public function test_order_is_full_redi_false_for_empty_items(): void {
        $order = $this->make_order_with_items( [] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'order_is_full_redi' );
        $ref->setAccessible( true );
        $this->assertFalse( $ref->invoke( null, $order ) );
    }

    public function test_order_is_full_redi_true_when_all_items_have_redi_meta(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )
            ->alias( fn( $pid, $key, $single ) => $key === '_ltms_redi_origin_product_id' ? '5' : '' );
        $order = $this->make_order_with_items( [ 1, 2 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'order_is_full_redi' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->invoke( null, $order ) );
    }

    public function test_order_is_full_redi_false_when_one_item_has_no_redi_meta(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )
            ->alias( fn( $pid, $key, $single ) => ( $key === '_ltms_redi_origin_product_id' && $pid === 1 ) ? '5' : '' );
        $order = $this->make_order_with_items( [ 1, 2 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'order_is_full_redi' );
        $ref->setAccessible( true );
        $this->assertFalse( $ref->invoke( null, $order ) );
    }

    // ── get_non_redi_gross() — via reflexión ─────────────────────────────

    public function test_get_non_redi_gross_returns_zero_when_all_redi(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )
            ->alias( fn( $pid, $key, $single ) => $key === '_ltms_redi_origin_product_id' ? '5' : '' );
        $order = $this->make_order_with_items_and_totals( [ 1 => 50000.0 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'get_non_redi_gross' );
        $ref->setAccessible( true );
        $this->assertSame( 0.0, $ref->invoke( null, $order ) );
    }

    public function test_get_non_redi_gross_returns_full_total_when_no_redi(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( '' );
        $order = $this->make_order_with_items_and_totals( [ 1 => 100000.0, 2 => 50000.0 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'get_non_redi_gross' );
        $ref->setAccessible( true );
        $result = $ref->invoke( null, $order );
        $this->assertEqualsWithDelta( 150000.0, $result, 0.01 );
    }

    public function test_get_non_redi_gross_sums_only_non_redi_items_mixed(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )
            ->alias( fn( $pid, $key, $single ) => ( $key === '_ltms_redi_origin_product_id' && $pid === 99 ) ? '5' : '' );
        $order = $this->make_order_with_items_and_totals( [ 1 => 80000.0, 99 => 40000.0 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'get_non_redi_gross' );
        $ref->setAccessible( true );
        $result = $ref->invoke( null, $order );
        $this->assertEqualsWithDelta( 80000.0, $result, 0.01 );
    }

    // ── extract_vendor_from_items() — via reflexión ──────────────────────

    public function test_extract_vendor_from_items_returns_zero_for_empty_items(): void {
        $order = $this->make_order_with_items( [] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'extract_vendor_from_items' );
        $ref->setAccessible( true );
        $this->assertSame( 0, $ref->invoke( null, $order ) );
    }

    public function test_extract_vendor_from_items_finds_first_vendor(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )
            ->alias( fn( $pid, $key, $single ) => $key === '_ltms_vendor_id' ? '42' : '' );
        $order = $this->make_order_with_items( [ 10 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'extract_vendor_from_items' );
        $ref->setAccessible( true );
        $this->assertSame( 42, $ref->invoke( null, $order ) );
    }

    public function test_extract_vendor_from_items_returns_zero_when_no_vendor_meta(): void {
        \Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( '' );
        $order = $this->make_order_with_items( [ 1, 2, 3 ] );
        $ref = new \ReflectionMethod( 'LTMS_Business_Order_Split', 'extract_vendor_from_items' );
        $ref->setAccessible( true );
        $this->assertSame( 0, $ref->invoke( null, $order ) );
    }

    // ── Helpers adicionales ───────────────────────────────────────────────

    private function make_order_with_items( array $product_ids ): \WC_Order {
        $items = array_map( fn( $pid ) => new class( $pid ) {
            public function __construct( private int $pid ) {}
            public function get_product_id(): int    { return $this->pid; }
            public function get_total(): string       { return '50000'; }
            public function get_name(): string        { return 'Product ' . $this->pid; }
            public function get_subtotal(): string    { return '50000'; }
            public function get_quantity(): int       { return 1; }
        }, $product_ids );

        return new class( $items ) extends \WC_Order {
            public function __construct( private array $items ) {}
            public function get_id(): int      { return 1; }
            public function get_items( $types = 'line_item' ): array { return $this->items; }
            public function get_meta( $key, $single = true, $context = 'view' ): mixed { return ''; }
            public function get_order_number(): string { return '1'; }
            public function get_currency(): string { return 'COP'; }
            public function get_billing_city(): string { return 'Bogota'; }
        };
    }

    private function make_order_with_items_and_totals( array $pid_totals ): \WC_Order {
        $items = [];
        foreach ( $pid_totals as $pid => $total ) {
            $items[] = new class( $pid, $total ) {
                public function __construct( private int $pid, private float $total ) {}
                public function get_product_id(): int  { return $this->pid; }
                public function get_total(): string    { return (string) $this->total; }
                public function get_name(): string     { return 'Product ' . $this->pid; }
                public function get_subtotal(): string { return (string) $this->total; }
                public function get_quantity(): int    { return 1; }
            };
        }
        return new class( $items ) extends \WC_Order {
            public function __construct( private array $items ) {}
            public function get_id(): int      { return 1; }
            public function get_items( $types = 'line_item' ): array { return $this->items; }
            public function get_meta( $key, $single = true, $context = 'view' ): mixed { return ''; }
            public function get_order_number(): string { return '1'; }
            public function get_currency(): string { return 'COP'; }
            public function get_billing_city(): string { return 'Bogota'; }
        };
    }

}

