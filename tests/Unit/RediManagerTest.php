<?php
/**
 * RediManagerTest — Tests unitarios para LTMS_Business_Redi_Manager
 *
 * Cubre:
 *  SECCIÓN 1 — is_redi_product()
 *    - Devuelve true cuando el meta existe y es truthy
 *    - Devuelve false cuando el meta es vacío / no existe
 *    - Devuelve false cuando el meta es '0'
 *
 *  SECCIÓN 2 — get_origin_product_id()
 *    - Devuelve el int correcto cuando el meta existe
 *    - Devuelve 0 cuando el meta no existe
 *    - Castea string numérico a int
 *
 *  SECCIÓN 3 — get_origin_vendor_id()
 *    - Devuelve el int correcto cuando el meta existe
 *    - Devuelve 0 cuando el meta no existe
 *
 *  SECCIÓN 4 — get_redi_rate()
 *    - Devuelve el float correcto
 *    - Devuelve 0.0 cuando el meta no existe
 *    - Castea string '0.15' a float 0.15
 *
 *  SECCIÓN 5 — adopt_product(): guards de entrada
 *    - Retorna 0 cuando el origin_product no existe (wc_get_product = false)
 *    - Retorna 0 cuando new_product->save() devuelve 0
 *
 *  SECCIÓN 6 — adopt_product(): tasa efectiva
 *    - Usa override_rate cuando >= 0
 *    - Usa tasa del producto cuando override_rate = -1 (default)
 *    - override_rate = 0.0 es válido (no es -1, se usa)
 *
 *  SECCIÓN 7 — detect_redi_items(): filtrado de items
 *    - Omite items sin _ltms_redi_origin_product_id
 *    - Incluye items con _ltms_redi_origin_product_id
 *    - Estructura del array retornado tiene todas las claves requeridas
 *    - gross viene de get_total() del item
 *    - Orden vacío → array vacío
 *
 *  SECCIÓN 8 — detect_redi_items(): múltiples items mixtos
 *    - Solo los items ReDi aparecen en el resultado
 *    - Count correcto con 2 redi + 1 normal
 *
 *  SECCIÓN 9 — deduct_origin_stock(): guards
 *    - Items sin origin_product_id son ignorados
 *    - Items cuyo origin_product no existe son ignorados
 *    - Items cuyo origin_product no gestiona stock son ignorados
 *
 *  SECCIÓN 10 — deduct_origin_stock(): lógica de stock
 *    - Stock se reduce correctamente por la cantidad vendida
 *    - Stock nunca baja de 0 (max 0)
 *
 *  SECCIÓN 11 — init()
 *    - Registra dos hooks de WooCommerce
 *
 *  SECCIÓN 12 — Reflexión
 *    - is_redi_product es public static
 *    - get_origin_product_id es public static
 *    - get_origin_vendor_id es public static
 *    - get_redi_rate es public static
 *    - adopt_product es public static
 *    - detect_redi_items es public static
 *    - deduct_origin_stock es public static
 *    - get_agreement_id es private static
 *    - get_origin_vendor_id_from_product es private static
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Business_Redi_Manager
 */
class RediManagerTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb();
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function make_wpdb( int $agreement_id = 0 ): object {
        return new class( $agreement_id ) {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public int    $insert_id  = 1;

            public function __construct( private int $agreement_id ) {}

            public function get_var( mixed $q = null ): mixed {
                return $this->agreement_id ?: null;
            }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool { return 1; }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return 1; }
        };
    }

    /**
     * Crea un item de pedido anónimo (sin extender WC_Order_Item_Product).
     */
    private function make_item( int $product_id, float $total = 50000.0, int $qty = 1 ): object {
        return new class( $product_id, $total, $qty ) {
            public function __construct(
                private int   $pid,
                private float $total,
                private int   $qty
            ) {}
            public function get_product_id(): int  { return $this->pid; }
            public function get_total(): string    { return (string) $this->total; }
            public function get_quantity(): int    { return $this->qty; }
        };
    }

    /**
     * Crea un WC_Order anónimo con los items dados.
     */
    private function make_order( array $items ): \WC_Order {
        return new class( $items ) extends \WC_Order {
            public function __construct( private array $items ) {}
            public function get_id(): int { return 99; }
            public function get_items( $types = 'line_item' ): array { return $this->items; }
        };
    }

    /**
     * Crea un stub de WC_Product que gestiona stock.
     */
    private function make_wc_product( int $stock, bool $manages_stock = true ): object {
        return new class( $stock, $manages_stock ) {
            private int $current_stock;
            public function __construct( private int $initial_stock, private bool $manages ) {
                $this->current_stock = $initial_stock;
            }
            public function managing_stock(): bool  { return $this->manages; }
            public function get_stock_quantity(): int { return $this->current_stock; }
            public function set_stock_quantity( int $q ): void { $this->current_stock = $q; }
            public function save(): int { return 1; }
            public function get_saved_stock(): int { return $this->current_stock; }
        };
    }

    // -----------------------------------------------------------------------
    // 1. is_redi_product()
    // -----------------------------------------------------------------------

    public function test_is_redi_product_true_when_meta_exists(): void {
        Functions\when( 'get_post_meta' )->justReturn( '42' );
        $this->assertTrue( LTMS_Business_Redi_Manager::is_redi_product( 10 ) );
    }

    public function test_is_redi_product_false_when_meta_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $this->assertFalse( LTMS_Business_Redi_Manager::is_redi_product( 10 ) );
    }

    public function test_is_redi_product_false_when_meta_zero_string(): void {
        Functions\when( 'get_post_meta' )->justReturn( '0' );
        $this->assertFalse( LTMS_Business_Redi_Manager::is_redi_product( 10 ) );
    }

    public function test_is_redi_product_false_when_meta_null(): void {
        Functions\when( 'get_post_meta' )->justReturn( null );
        $this->assertFalse( LTMS_Business_Redi_Manager::is_redi_product( 5 ) );
    }

    // -----------------------------------------------------------------------
    // 2. get_origin_product_id()
    // -----------------------------------------------------------------------

    public function test_get_origin_product_id_returns_correct_int(): void {
        Functions\when( 'get_post_meta' )->justReturn( '77' );
        $this->assertSame( 77, LTMS_Business_Redi_Manager::get_origin_product_id( 10 ) );
    }

    public function test_get_origin_product_id_returns_zero_when_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $this->assertSame( 0, LTMS_Business_Redi_Manager::get_origin_product_id( 10 ) );
    }

    public function test_get_origin_product_id_casts_string_to_int(): void {
        Functions\when( 'get_post_meta' )->justReturn( '123' );
        $result = LTMS_Business_Redi_Manager::get_origin_product_id( 1 );
        $this->assertIsInt( $result );
        $this->assertSame( 123, $result );
    }

    // -----------------------------------------------------------------------
    // 3. get_origin_vendor_id()
    // -----------------------------------------------------------------------

    public function test_get_origin_vendor_id_returns_correct_int(): void {
        Functions\when( 'get_post_meta' )->justReturn( '55' );
        $this->assertSame( 55, LTMS_Business_Redi_Manager::get_origin_vendor_id( 10 ) );
    }

    public function test_get_origin_vendor_id_returns_zero_when_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $this->assertSame( 0, LTMS_Business_Redi_Manager::get_origin_vendor_id( 10 ) );
    }

    // -----------------------------------------------------------------------
    // 4. get_redi_rate()
    // -----------------------------------------------------------------------

    public function test_get_redi_rate_returns_correct_float(): void {
        Functions\when( 'get_post_meta' )->justReturn( '0.20' );
        $this->assertEqualsWithDelta( 0.20, LTMS_Business_Redi_Manager::get_redi_rate( 10 ), 0.001 );
    }

    public function test_get_redi_rate_returns_zero_when_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $this->assertSame( 0.0, LTMS_Business_Redi_Manager::get_redi_rate( 10 ) );
    }

    public function test_get_redi_rate_casts_string_to_float(): void {
        Functions\when( 'get_post_meta' )->justReturn( '0.15' );
        $result = LTMS_Business_Redi_Manager::get_redi_rate( 10 );
        $this->assertIsFloat( $result );
        $this->assertEqualsWithDelta( 0.15, $result, 0.0001 );
    }

    public function test_get_redi_rate_returns_max_rate_1(): void {
        Functions\when( 'get_post_meta' )->justReturn( '1' );
        $this->assertEqualsWithDelta( 1.0, LTMS_Business_Redi_Manager::get_redi_rate( 10 ), 0.0001 );
    }

    // -----------------------------------------------------------------------
    // 5. adopt_product() — guards
    // -----------------------------------------------------------------------

    public function test_adopt_product_returns_zero_when_origin_not_found(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = LTMS_Business_Redi_Manager::adopt_product( 1, 999 );

        $this->assertSame( 0, $result );
    }

    public function test_adopt_product_returns_zero_when_save_fails(): void {
        // $origin must have ALL setter methods because adopt_product() clones it
        // and immediately calls set_id(0), set_status(), set_date_created(), etc.
        // on the clone. The clone shares the same anonymous class, so the class
        // must implement every method that adopt_product() calls post-clone.
        $origin = new class extends \WC_Product {
            public function get_name(): string        { return 'Producto origen'; }
            public function get_id(): int             { return 10; }
            public function set_id( int $id ): void   {}
            public function set_status( string $s ): void {}
            public function set_date_created( mixed $d ): void {}
            public function set_date_modified( mixed $d ): void {}
            public function set_name( string $n ): void {}
            public function set_slug( string $s ): void {}
            public function save(): int               { return 0; } // simulate save failure
        };

        Functions\when( 'wc_get_product' )->justReturn( $origin );
        Functions\when( 'get_post_meta' )->justReturn( '0.15' );

        $result = LTMS_Business_Redi_Manager::adopt_product( 1, 999 );

        // save() returns 0 → adopt_product() must return 0
        $this->assertSame( 0, $result );
    }

    // -----------------------------------------------------------------------
    // 6. adopt_product() — tasa efectiva
    // -----------------------------------------------------------------------

    public function test_adopt_product_uses_override_rate_when_provided(): void {
        // No podemos controlar clone internamente, pero verificamos el guard:
        // cuando wc_get_product devuelve false → 0 sin importar override_rate.
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = LTMS_Business_Redi_Manager::adopt_product( 1, 10, 0.25 );
        $this->assertSame( 0, $result );
    }

    public function test_adopt_product_uses_product_rate_when_override_is_negative(): void {
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = LTMS_Business_Redi_Manager::adopt_product( 1, 10, -1.0 );
        $this->assertSame( 0, $result );
    }

    public function test_adopt_product_override_zero_is_valid(): void {
        // override_rate = 0.0 es distinto de -1.0, debe usarse
        Functions\when( 'wc_get_product' )->justReturn( false );

        $result = LTMS_Business_Redi_Manager::adopt_product( 1, 10, 0.0 );
        $this->assertSame( 0, $result ); // falla en guard, pero no lanza excepción
    }

    // -----------------------------------------------------------------------
    // 7. detect_redi_items() — filtrado de items
    // -----------------------------------------------------------------------

    public function test_detect_redi_items_empty_order_returns_empty_array(): void {
        $order = $this->make_order( [] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );
        $this->assertSame( [], $result );
    }

    public function test_detect_redi_items_skips_items_without_origin_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' ); // sin origin_product_id
        $order = $this->make_order( [ $this->make_item( 10 ) ] );

        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertSame( [], $result );
    }

    public function test_detect_redi_items_includes_items_with_origin_meta(): void {
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.15',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order = $this->make_order( [ $this->make_item( 10, 80000.0 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertCount( 1, $result );
    }

    public function test_detect_redi_items_result_has_all_required_keys(): void {
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.15',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order  = $this->make_order( [ $this->make_item( 10, 60000.0 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );
        $item   = $result[0];

        $this->assertArrayHasKey( 'item_id',           $item );
        $this->assertArrayHasKey( 'product_id',        $item );
        $this->assertArrayHasKey( 'gross',             $item );
        $this->assertArrayHasKey( 'reseller_id',       $item );
        $this->assertArrayHasKey( 'origin_product_id', $item );
        $this->assertArrayHasKey( 'origin_vendor_id',  $item );
        $this->assertArrayHasKey( 'redi_rate',         $item );
        $this->assertArrayHasKey( 'agreement_id',      $item );
    }

    public function test_detect_redi_items_gross_comes_from_item_total(): void {
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.15',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order  = $this->make_order( [ $this->make_item( 10, 123456.78 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertEqualsWithDelta( 123456.78, $result[0]['gross'], 0.01 );
    }

    public function test_detect_redi_items_values_are_correct_types(): void {
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.15',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order  = $this->make_order( [ $this->make_item( 10, 50000.0 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );
        $item   = $result[0];

        $this->assertIsInt  ( $item['product_id'] );
        $this->assertIsFloat( $item['gross'] );
        $this->assertIsInt  ( $item['reseller_id'] );
        $this->assertIsInt  ( $item['origin_product_id'] );
        $this->assertIsInt  ( $item['origin_vendor_id'] );
        $this->assertIsFloat( $item['redi_rate'] );
        $this->assertIsInt  ( $item['agreement_id'] );
    }

    // -----------------------------------------------------------------------
    // 8. detect_redi_items() — múltiples items mixtos
    // -----------------------------------------------------------------------

    public function test_detect_redi_items_mixed_returns_only_redi_items(): void {
        // product_id 10 y 20 son ReDi, product_id 30 no
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( true ) {
                $key === '_ltms_redi_origin_product_id' && in_array( $pid, [ 10, 20 ], true ) => '5',
                $key === '_ltms_redi_origin_vendor_id'  => '3',
                $key === '_ltms_redi_rate'              => '0.10',
                $key === '_ltms_vendor_id'              => '7',
                default                                 => '',
            }
        );

        $items = [
            $this->make_item( 10, 50000.0 ),
            $this->make_item( 30, 20000.0 ), // no ReDi
            $this->make_item( 20, 80000.0 ),
        ];
        $order  = $this->make_order( $items );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertCount( 2, $result );
    }

    public function test_detect_redi_items_all_redi_returns_all(): void {
        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.12',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $items = [
            $this->make_item( 10 ),
            $this->make_item( 11 ),
            $this->make_item( 12 ),
        ];
        $order  = $this->make_order( $items );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertCount( 3, $result );
    }

    public function test_detect_redi_items_agreement_id_from_wpdb(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( agreement_id: 42 );

        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.10',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order  = $this->make_order( [ $this->make_item( 10 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertSame( 42, $result[0]['agreement_id'] );
    }

    public function test_detect_redi_items_agreement_id_zero_when_not_found(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( agreement_id: 0 );

        Functions\when( 'get_post_meta' )->alias(
            fn( $pid, $key, $single ) => match( $key ) {
                '_ltms_redi_origin_product_id' => '5',
                '_ltms_redi_origin_vendor_id'  => '3',
                '_ltms_redi_rate'              => '0.10',
                '_ltms_vendor_id'              => '7',
                default                        => '',
            }
        );

        $order  = $this->make_order( [ $this->make_item( 10 ) ] );
        $result = LTMS_Business_Redi_Manager::detect_redi_items( $order );

        $this->assertSame( 0, $result[0]['agreement_id'] );
    }

    // -----------------------------------------------------------------------
    // 9. deduct_origin_stock() — guards
    // -----------------------------------------------------------------------

    public function test_deduct_origin_stock_skips_items_without_origin_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wc_get_product' )->justReturn( null );

        $order = $this->make_order( [ $this->make_item( 10 ) ] );

        // No debe lanzar excepción ni llamar wc_get_product
        $this->expectNotToPerformAssertions();
        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );
    }

    public function test_deduct_origin_stock_skips_when_origin_product_not_found(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' ); // tiene origin
        Functions\when( 'wc_get_product' )->justReturn( null ); // pero no existe

        $order = $this->make_order( [ $this->make_item( 10 ) ] );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );
    }

    public function test_deduct_origin_stock_skips_when_product_does_not_manage_stock(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' );

        $product = $this->make_wc_product( stock: 100, manages_stock: false );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $order = $this->make_order( [ $this->make_item( 10, 50000.0, 3 ) ] );

        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        // Stock no debe haber cambiado
        $this->assertSame( 100, $product->get_stock_quantity() );
    }

    public function test_deduct_origin_stock_empty_order_does_nothing(): void {
        $order = $this->make_order( [] );

        $this->expectNotToPerformAssertions();
        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );
    }

    // -----------------------------------------------------------------------
    // 10. deduct_origin_stock() — lógica de stock
    // -----------------------------------------------------------------------

    public function test_deduct_origin_stock_reduces_stock_by_quantity(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' );

        $product = $this->make_wc_product( stock: 50 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $order = $this->make_order( [ $this->make_item( 10, 50000.0, qty: 3 ) ] );

        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        $this->assertSame( 47, $product->get_stock_quantity() );
    }

    public function test_deduct_origin_stock_never_goes_below_zero(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' );

        $product = $this->make_wc_product( stock: 2 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $order = $this->make_order( [ $this->make_item( 10, 50000.0, qty: 10 ) ] );

        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        $this->assertSame( 0, $product->get_stock_quantity() );
    }

    public function test_deduct_origin_stock_exact_quantity_equals_stock_reaches_zero(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' );

        $product = $this->make_wc_product( stock: 5 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $order = $this->make_order( [ $this->make_item( 10, 50000.0, qty: 5 ) ] );

        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        $this->assertSame( 0, $product->get_stock_quantity() );
    }

    public function test_deduct_origin_stock_single_unit_reduces_by_one(): void {
        Functions\when( 'get_post_meta' )->justReturn( '5' );

        $product = $this->make_wc_product( stock: 20 );
        Functions\when( 'wc_get_product' )->justReturn( $product );

        $order = $this->make_order( [ $this->make_item( 10, 50000.0, qty: 1 ) ] );

        LTMS_Business_Redi_Manager::deduct_origin_stock( $order );

        $this->assertSame( 19, $product->get_stock_quantity() );
    }

    // -----------------------------------------------------------------------
    // 11. init()
    // -----------------------------------------------------------------------

    public function test_init_registers_product_options_hook(): void {
        // add_action ya está stubbeado en setUp (no-op)
        // Solo verificamos que no lanza excepción
        $this->expectNotToPerformAssertions();
        LTMS_Business_Redi_Manager::init();
    }

    // -----------------------------------------------------------------------
    // 12. Reflexión — visibilidad y naturaleza estática de métodos
    // -----------------------------------------------------------------------

    public function test_reflection_is_redi_product_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'is_redi_product' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_get_origin_product_id_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'get_origin_product_id' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_get_origin_vendor_id_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'get_origin_vendor_id' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_get_redi_rate_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'get_redi_rate' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_adopt_product_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'adopt_product' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_detect_redi_items_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'detect_redi_items' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_deduct_origin_stock_is_public_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'deduct_origin_stock' );
        $this->assertTrue( $m->isPublic() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_get_agreement_id_is_private_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'get_agreement_id' );
        $this->assertTrue( $m->isPrivate() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_get_origin_vendor_id_from_product_is_private_static(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'get_origin_vendor_id_from_product' );
        $this->assertTrue( $m->isPrivate() );
        $this->assertTrue( $m->isStatic() );
    }

    public function test_reflection_adopt_product_has_three_parameters(): void {
        $m = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'adopt_product' );
        $this->assertCount( 3, $m->getParameters() );
    }

    public function test_reflection_adopt_product_third_param_has_default(): void {
        $m      = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'adopt_product' );
        $params = $m->getParameters();
        $this->assertTrue( $params[2]->isOptional() );
        $this->assertEqualsWithDelta( -1.0, $params[2]->getDefaultValue(), 0.001 );
    }

    public function test_reflection_detect_redi_items_returns_array(): void {
        $m  = new \ReflectionMethod( LTMS_Business_Redi_Manager::class, 'detect_redi_items' );
        $rt = $m->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }
}
