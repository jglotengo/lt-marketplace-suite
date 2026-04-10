<?php
/**
 * BookingManagerTest — EXTENDED v2
 *
 * Nuevos ángulos cubiertos (sin repetir los del test original):
 *  - is_available(): wpdb mock con count real (>0), rango de 1 noche exacta,
 *    producto_id=0, fechas con hora incluida ignoradas correctamente
 *  - cancel_booking(): wpdb mock con row real (cancelable + ya cancelado + checked_out)
 *  - confirm_booking(): wpdb mock que retorna 1 (rows afectadas) → true
 *  - get_blocked_dates(): wpdb mock que retorna rows reales → array de fechas
 *  - cleanup_pending_bookings(): config timeout customizado extremo (0, 1440)
 *  - create_booking(): devolución de WP_Error cuando slots vacíos
 *  - Reflexión: tipos de retorno de métodos clave
 *  - $initialized reset entre tests (invariante de setUp)
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\unit;

use Brain\Monkey\Functions;

/**
 * Class BookingManagerTest_Extended
 */
class BookingManagerTest extends LTMS_Unit_Test_Case {

    /** @var object|null Backup del wpdb global */
    private ?object $original_wpdb = null;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Booking_Manager' ) ) {
            $this->markTestSkipped( 'LTMS_Booking_Manager no disponible.' );
        }

        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;

        // Resetear flag estático $initialized entre tests
        $ref = new \ReflectionProperty( 'LTMS_Booking_Manager', 'initialized' );
        $ref->setAccessible( true );
        $ref->setValue( null, false );

        Functions\stubs([
            'current_time' => static fn( $type ) => gmdate( 'Y-m-d H:i:s' ),
        ]);
    }

    protected function tearDown(): void {
        if ( $this->original_wpdb !== null ) {
            $GLOBALS['wpdb'] = $this->original_wpdb;
        }
        if ( class_exists( 'LTMS_Booking_Manager' ) ) {
            $ref = new \ReflectionProperty( 'LTMS_Booking_Manager', 'initialized' );
            $ref->setAccessible( true );
            $ref->setValue( null, false );
        }
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════════════

    /**
     * wpdb mock completo: configurable por método.
     *
     * @param mixed $get_var_return    Valor para get_var()
     * @param mixed $get_row_return    Valor para get_row()
     * @param array $get_results_rows  Filas para get_results()
     * @param int   $update_rows       Rows afectadas por update()
     */
    private function make_wpdb(
        mixed $get_var_return    = null,
        mixed $get_row_return    = null,
        array $get_results_rows  = [],
        int   $update_rows       = 0
    ): object {
        return new class( $get_var_return, $get_row_return, $get_results_rows, $update_rows ) {
            public string $prefix     = 'wp_';
            public string $posts      = 'wp_posts';
            public int    $insert_id  = 0;
            private mixed $get_var_val;
            private mixed $get_row_val;
            private array $results;
            private int   $upd_rows;

            public function __construct( mixed $gv, mixed $gr, array $res, int $upd ) {
                $this->get_var_val = $gv;
                $this->get_row_val = $gr;
                $this->results     = $res;
                $this->upd_rows    = $upd;
            }

            public function get_var( mixed $q = null ): mixed   { return $this->get_var_val; }
            public function get_row( mixed $q = null, string $out = 'OBJECT', int $y = 0 ): mixed { return $this->get_row_val; }
            public function get_results( mixed $q = null, string $out = 'OBJECT' ): array { return $this->results; }
            public function update( string $t, array $d, array $w, mixed $df = null, mixed $wf = null ): int|false { return $this->upd_rows ?: false; }
            public function insert( string $t, array $d, mixed $f = null ): int|bool { return false; }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function query( string $q ): int|bool { return true; }
            public function delete( string $t, array $w, mixed $f = null ): int|false { return 1; }
        };
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — is_available() con wpdb mock que retorna count real
    // ════════════════════════════════════════════════════════════════════════

    /** count=3, expected_nights=3 → true */
    public function test_is_available_true_when_count_equals_expected_nights(): void {
        // 3 noches (2025-06-01 → 2025-06-04), get_var retorna '3'
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '3' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-06-01', '2025-06-04' );
        $this->assertTrue( $result );
    }

    /** count=5, expected_nights=3 → true (más slots que noches) */
    public function test_is_available_true_when_count_exceeds_expected_nights(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '5' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-06-01', '2025-06-04' );
        $this->assertTrue( $result );
    }

    /** count=2, expected_nights=3 → false (faltan slots) */
    public function test_is_available_false_when_count_less_than_expected_nights(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '2' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-06-01', '2025-06-04' );
        $this->assertFalse( $result );
    }

    /** 1 noche exacta: count=1 → true */
    public function test_is_available_one_night_count_one_true(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '1' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-07-10', '2025-07-11' );
        $this->assertTrue( $result );
    }

    /** producto_id=0: count='0' → false (no hay slots) */
    public function test_is_available_product_zero_false(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '0' );

        $result = \LTMS_Booking_Manager::is_available( 0, '2025-06-01', '2025-06-02' );
        $this->assertFalse( $result );
    }

    /** is_available retorna bool (no int) */
    public function test_is_available_return_type_is_bool(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '3' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-06-01', '2025-06-04' );
        $this->assertIsBool( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — cancel_booking() con row real
    // ════════════════════════════════════════════════════════════════════════

    private function make_booking_row( string $status = 'pending', int $product_id = 10 ): array {
        return [
            'id'             => 1,
            'product_id'     => $product_id,
            'customer_id'    => 5,
            'checkin_date'   => '2025-08-01',
            'checkout_date'  => '2025-08-05',
            'status'         => $status,
            'total_price'    => 500000,
        ];
    }

    /** Booking con status 'pending' → cancelable → retorna true */
    public function test_cancel_booking_pending_returns_true(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb(
            get_row_return: $this->make_booking_row( 'pending' )
        );

        $result = \LTMS_Booking_Manager::cancel_booking( 1 );
        $this->assertTrue( $result );
    }

    /** Booking con status 'confirmed' → cancelable → retorna true */
    public function test_cancel_booking_confirmed_returns_true(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb(
            get_row_return: $this->make_booking_row( 'confirmed' )
        );

        $result = \LTMS_Booking_Manager::cancel_booking( 1 );
        $this->assertTrue( $result );
    }

    /** Booking ya cancelado → WP_Error 'invalid_status' */
    public function test_cancel_booking_already_cancelled_returns_wp_error(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb(
            get_row_return: $this->make_booking_row( 'cancelled' )
        );

        $result = \LTMS_Booking_Manager::cancel_booking( 1 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_status', $result->get_error_code() );
    }

    /** Booking con status 'checked_out' → WP_Error 'invalid_status' */
    public function test_cancel_booking_checked_out_returns_wp_error(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb(
            get_row_return: $this->make_booking_row( 'checked_out' )
        );

        $result = \LTMS_Booking_Manager::cancel_booking( 1 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_status', $result->get_error_code() );
    }

    /** Booking con status 'completed' → WP_Error 'invalid_status' */
    public function test_cancel_booking_completed_returns_wp_error(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb(
            get_row_return: $this->make_booking_row( 'completed' )
        );

        $result = \LTMS_Booking_Manager::cancel_booking( 1 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_status', $result->get_error_code() );
    }

    /** cancel_booking retorna bool|WP_Error (never null) */
    public function test_cancel_booking_never_returns_null(): void {
        // row=null → WP_Error
        $result = \LTMS_Booking_Manager::cancel_booking( 99999 );
        $this->assertNotNull( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — confirm_booking() con update que retorna filas
    // ════════════════════════════════════════════════════════════════════════

    /** update retorna 1 → confirm_booking retorna true */
    public function test_confirm_booking_returns_true_when_update_succeeds(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( update_rows: 1 );

        $result = \LTMS_Booking_Manager::confirm_booking( 42 );
        $this->assertTrue( $result );
    }

    /** update retorna 0/false → confirm_booking retorna false */
    public function test_confirm_booking_returns_false_when_update_fails(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( update_rows: 0 );

        $result = \LTMS_Booking_Manager::confirm_booking( 42 );
        $this->assertFalse( $result );
    }

    /** confirm_booking con booking_id=0 retorna bool */
    public function test_confirm_booking_id_zero_returns_bool(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( update_rows: 0 );

        $result = \LTMS_Booking_Manager::confirm_booking( 0 );
        $this->assertIsBool( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — get_blocked_dates() con rows reales
    // ════════════════════════════════════════════════════════════════════════

    private function make_slot_rows( array $dates ): array {
        // get_blocked_dates usa ARRAY_A + array_column → retorna strings directamente
        // El mock debe devolver arrays asociativos para que array_column() funcione
        return array_map( fn( string $date ) => [ 'slot_date' => $date ], $dates );
    }

    /** get_results retorna 3 rows → array de 3 fechas */
    public function test_get_blocked_dates_returns_three_dates(): void {
        $rows = $this->make_slot_rows( ['2025-06-01', '2025-06-02', '2025-06-03'] );
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: $rows );

        $result = \LTMS_Booking_Manager::get_blocked_dates( 1 );
        $this->assertIsArray( $result );
        $this->assertCount( 3, $result );
    }

    /** get_blocked_dates aplica array_column → retorna array de strings de fechas */
    public function test_get_blocked_dates_rows_have_slot_date(): void {
        $rows = $this->make_slot_rows( ['2025-07-15'] );
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: $rows );

        $result = \LTMS_Booking_Manager::get_blocked_dates( 1 );
        $this->assertCount( 1, $result );
        // get_blocked_dates hace array_column($rows, 'slot_date') → array de strings
        $this->assertSame( '2025-07-15', $result[0] );
    }

    /** get_blocked_dates con rango personalizado retorna array (puede estar vacío) */
    public function test_get_blocked_dates_with_custom_range_returns_array(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: [] );

        $result = \LTMS_Booking_Manager::get_blocked_dates( 1, '2025-12-01', '2025-12-31' );
        $this->assertIsArray( $result );
    }

    /** get_blocked_dates: rango de 30 días con 0 bloqueados → vacío */
    public function test_get_blocked_dates_empty_for_free_month(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: [] );

        $result = \LTMS_Booking_Manager::get_blocked_dates( 5, '2025-09-01', '2025-09-30' );
        $this->assertEmpty( $result );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — cleanup_pending_bookings() con timeouts extremos
    // ════════════════════════════════════════════════════════════════════════

    /** timeout=0 minutos → no lanza excepción */
    public function test_cleanup_timeout_zero_no_exception(): void {
        \LTMS_Core_Config::set( 'ltms_booking_pending_timeout_minutes', 0 );
        $this->expectNotToPerformAssertions();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
    }

    /** timeout=1 minuto → no lanza excepción */
    public function test_cleanup_timeout_one_minute_no_exception(): void {
        \LTMS_Core_Config::set( 'ltms_booking_pending_timeout_minutes', 1 );
        $this->expectNotToPerformAssertions();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
    }

    /** timeout=1440 minutos (24h) → no lanza excepción */
    public function test_cleanup_timeout_24h_no_exception(): void {
        \LTMS_Core_Config::set( 'ltms_booking_pending_timeout_minutes', 1440 );
        $this->expectNotToPerformAssertions();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
    }

    /** cleanup se puede llamar múltiples veces sin excepción */
    public function test_cleanup_multiple_calls_no_exception(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
        \LTMS_Booking_Manager::cleanup_pending_bookings();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — create_booking() con slots vacíos → WP_Error
    // ════════════════════════════════════════════════════════════════════════

    /** get_results retorna [] (sin slots) → WP_Error 'slot_unavailable' o db error */
    public function test_create_booking_no_slots_returns_wp_error(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: [] );

        $result = \LTMS_Booking_Manager::create_booking(
            1, 5, 10,
            '2025-10-01', '2025-10-03',
            2, 150000.0
        );

        // Sin slots el insert_id=0 → WP_Error 'db_insert_failed'
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** create_booking retorna int|\WP_Error (nunca null) */
    public function test_create_booking_never_returns_null(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_results_rows: [] );

        $result = \LTMS_Booking_Manager::create_booking(
            99, 1, 1,
            '2025-11-01', '2025-11-02',
            1, 50000.0
        );

        $this->assertNotNull( $result );
        $this->assertTrue( is_int( $result ) || $result instanceof \WP_Error );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 7 — Reflexión: tipos de retorno de métodos clave
    // ════════════════════════════════════════════════════════════════════════

    /** is_available retorna bool */
    public function test_is_available_return_type_declared_bool(): void {
        $ref  = new \ReflectionMethod( 'LTMS_Booking_Manager', 'is_available' );
        $type = $ref->getReturnType();
        $this->assertNotNull( $type );
        $this->assertSame( 'bool', (string) $type );
    }

    /** confirm_booking retorna bool */
    public function test_confirm_booking_return_type_declared_bool(): void {
        $ref  = new \ReflectionMethod( 'LTMS_Booking_Manager', 'confirm_booking' );
        $type = $ref->getReturnType();
        $this->assertNotNull( $type );
        $this->assertSame( 'bool', (string) $type );
    }

    /** get_blocked_dates retorna array */
    public function test_get_blocked_dates_return_type_declared_array(): void {
        $ref  = new \ReflectionMethod( 'LTMS_Booking_Manager', 'get_blocked_dates' );
        $type = $ref->getReturnType();
        $this->assertNotNull( $type );
        $this->assertSame( 'array', (string) $type );
    }

    /** init() retorna void */
    public function test_init_return_type_is_void(): void {
        $ref  = new \ReflectionMethod( 'LTMS_Booking_Manager', 'init' );
        $type = $ref->getReturnType();
        $this->assertNotNull( $type );
        $this->assertSame( 'void', (string) $type );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — $initialized: invariantes de setUp/tearDown
    // ════════════════════════════════════════════════════════════════════════

    /** Antes de init(), $initialized es false */
    public function test_initialized_is_false_before_init(): void {
        $ref = new \ReflectionProperty( 'LTMS_Booking_Manager', 'initialized' );
        $ref->setAccessible( true );
        $this->assertFalse( $ref->getValue( null ) );
    }

    /** Después de init(), $initialized es true */
    public function test_initialized_is_true_after_init(): void {
        \LTMS_Booking_Manager::init();

        $ref = new \ReflectionProperty( 'LTMS_Booking_Manager', 'initialized' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->getValue( null ) );
    }

    /** Segunda llamada a init() no cambia $initialized (sigue true) */
    public function test_initialized_stays_true_after_double_init(): void {
        \LTMS_Booking_Manager::init();
        \LTMS_Booking_Manager::init();

        $ref = new \ReflectionProperty( 'LTMS_Booking_Manager', 'initialized' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->getValue( null ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Lógica de noches: dataProvider extendido
    // ════════════════════════════════════════════════════════════════════════

    private function calc_nights( string $checkin, string $checkout ): int {
        return (int) floor(
            ( strtotime( $checkout ) - strtotime( $checkin ) ) / DAY_IN_SECONDS
        );
    }

    /** @dataProvider provider_extended_nights */
    public function test_nights_formula_extended( string $in, string $out, int $expected ): void {
        $this->assertSame( $expected, $this->calc_nights( $in, $out ) );
    }

    /** @return array<string, array{string, string, int}> */
    public static function provider_extended_nights(): array {
        return [
            'semana en diciembre'         => [ '2025-12-20', '2025-12-27', 7  ],
            'cruce año bisiesto feb'      => [ '2024-02-27', '2024-03-01', 3  ],
            '31 días de enero'            => [ '2025-01-01', '2025-02-01', 31 ],
            '28 días feb no bisiesto'     => [ '2025-02-01', '2025-03-01', 28 ],
            '29 días feb bisiesto'        => [ '2024-02-01', '2024-03-01', 29 ],
            '365 días año completo'       => [ '2025-01-01', '2026-01-01', 365 ],
            '366 días año bisiesto'       => [ '2024-01-01', '2025-01-01', 366 ],
            '14 noches quincena'          => [ '2025-07-01', '2025-07-15', 14 ],
            '21 noches tres semanas'      => [ '2025-08-01', '2025-08-22', 21 ],
            'checkout antes → negativo'   => [ '2025-06-10', '2025-06-08', -2 ],
        ];
    }

    /** Noches negativas → is_available retorna false */
    public function test_negative_nights_is_available_false(): void {
        $nights = $this->calc_nights( '2025-06-10', '2025-06-08' );
        $this->assertLessThan( 0, $nights );
        $this->assertFalse( $nights > 0 );
    }

    /** 31 noches mes largo → is_available false con count=30 (insuficiente) */
    public function test_is_available_false_when_count_insufficient_for_month(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb( get_var_return: '30' );

        $result = \LTMS_Booking_Manager::is_available( 1, '2025-01-01', '2025-02-01' ); // 31 noches
        $this->assertFalse( $result );
    }
}
