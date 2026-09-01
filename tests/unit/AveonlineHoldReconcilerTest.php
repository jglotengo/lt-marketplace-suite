<?php
/**
 * AveonlineHoldReconcilerTest — Tests del reconciliador de holds atascados
 *
 * Cubre `LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds()`:
 *  - Hold vencido + pedido entregado en Aveonline → dispara ltms_shipping_delivered
 *    (vía meta del pedido o fallback a tabla lt_aveonline_guias).
 *  - Pedido ya entregado → skip sin consultar la API.
 *  - Estado fallido (DEVUELTA) → dispara ltms_shipping_failed (congela hold).
 *  - Sin guía → deja el hold intacto.
 *  - Guard de idempotencia _ltms_shipping_delivered_fired → no re-dispara.
 *  - Estado en tránsito → no toca el hold.
 *  - Gate ltms_payout_require_delivery = 'no' → early return.
 *
 * RECONCILIATION FIX: P0 — un webhook perdido (order_id sin resolver) dejaba
 * el hold congelado indefinidamente; este cron lo resuelve consultando la API.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Orden WooCommerce falsa para el test (metas configurables).
 */
class FakeReconcilerOrder {
    public int $id;
    public array $metas;
    public array $saves = [];

    public function __construct( int $id, array $metas = [] ) {
        $this->id    = $id;
        $this->metas = $metas;
    }

    public function get_id(): int { return $this->id; }

    public function get_meta( string $key ) { return $this->metas[ $key ] ?? ''; }

    public function update_meta_data( string $key, $value ): void { $this->metas[ $key ] = $value; }

    public function save(): void { $this->saves[] = true; }

    public function needs_shipping_address(): bool { return true; }
}

/**
 * Cliente de API Aveonline falso: constructor no-op + track_shipment programable.
 *
 * Usa estado estático compartido porque LTMS_Api_Factory cachea la instancia
 * en una propiedad private static (no accesible desde el test) e instancia la
 * clase registrada internamente.
 */
class FakeReconcilerAveonlineApi extends \LTMS_Api_Aveonline {
    public static array $shared_results = [];
    public static array $shared_calls   = [];

    public function __construct() {}

    public function track_shipment( string $tracking_number ): array {
        self::$shared_calls[] = $tracking_number;
        return self::$shared_results[ $tracking_number ] ?? [ 'status' => 'unknown', 'events' => [] ];
    }
}

/**
 * @covers LTMS_Business_Consumer_Protection
 */
class AveonlineHoldReconcilerTest extends LTMS_Unit_Test_Case {

    private object $mock_wpdb;
    public array $updates = [];
    public array $queries = [];
    public array $actions = [];
    public array $orders  = [];

    protected function setUp(): void {
        parent::setUp();

        $this->updates = [];
        $this->queries = [];
        $this->actions = [];
        $this->orders  = [];
        FakeReconcilerAveonlineApi::$shared_results = [];
        FakeReconcilerAveonlineApi::$shared_calls   = [];

        $self = $this;
        $this->mock_wpdb = new class( $self ) {
            public $prefix = 'wp_';
            public array $holds           = [];
            public ?string $guia_from_table = null;
            private $test;
            public function __construct( $test ) { $this->test = $test; }
            public function prepare( $sql, ...$args ) { $this->test->queries[] = $sql; return $sql; }
            public function query( $sql ) { $this->test->queries[] = $sql; return true; }
            public function get_var( $sql ) {
                $this->test->queries[] = $sql;
                return $this->guia_from_table;
            }
            public function get_row( $sql, $o = OBJECT ) { $this->test->queries[] = $sql; return null; }
            public function get_results( $sql, $o = OBJECT ) { $this->test->queries[] = $sql; return $this->holds; }
            public function get_col( $sql ) { return []; }
            public function insert( $t, $d, $f = null ) { return 1; }
            public function update( $t, $d, $w, $f = null, $wf = null ) {
                $this->test->updates[] = [ 'table' => $t, 'data' => $d, 'where' => $w ];
                return 1;
            }
            public function get_charset_collate() { return 'utf8mb4 utf8mb4_unicode_ci'; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        // Capturar do_action en vez de no-op.
        Functions\when( 'do_action' )->alias(
            function ( string $tag, ...$args ): void {
                $this->actions[] = [ $tag, $args ];
            }
        );
        Functions\when( 'sanitize_key' )->alias(
            static fn( string $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) )
        );
        Functions\when( 'wc_get_order' )->alias(
            function ( $id ) {
                return $this->orders[ (int) $id ] ?? false;
            }
        );

        // Registrar el cliente falso en el factory y aislarlo de otros tests.
        \LTMS_Api_Factory::reset( 'aveonline' );
        \LTMS_Api_Factory::register( 'aveonline', FakeReconcilerAveonlineApi::class );
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        // Restaurar el mapeo real del factory para no contaminar otros tests.
        \LTMS_Api_Factory::reset_all();
        \LTMS_Api_Factory::register( 'aveonline', \LTMS_Api_Aveonline::class );
        FakeReconcilerAveonlineApi::$shared_results = [];
        FakeReconcilerAveonlineApi::$shared_calls   = [];
        parent::tearDown();
    }

    private function make_hold( int $id, int $vendor, int $order ): object {
        return (object) [
            'id'         => $id,
            'vendor_id'  => $vendor,
            'order_id'   => $order,
            'status'     => 'held',
            'release_at' => '2026-01-01 00:00:00',
            'amount'     => 50.0,
        ];
    }

    private function actions_for( string $tag ): array {
        return array_values( array_filter(
            $this->actions,
            static fn( array $a ) => $a[0] === $tag
        ) );
    }

    // ── 1. Entregada resuelta vía meta del pedido ─────────────────────────

    public function test_delivered_hold_resolved_via_order_meta(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [ '_ltms_aveonline_tracking' => 'G123' ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        FakeReconcilerAveonlineApi::$shared_results['G123'] = [ 'status' => 'ENTREGADA', 'events' => [] ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [ 'G123' ], FakeReconcilerAveonlineApi::$shared_calls, 'Debe consultar la guía a la API.' );
        $delivered = $this->actions_for( 'ltms_shipping_delivered' );
        $this->assertCount( 1, $delivered, 'Debe disparar ltms_shipping_delivered.' );
        $this->assertSame( 100, $delivered[0][1][0] );
        $this->assertSame( 'aveonline', $delivered[0][1][1] );
        $this->assertSame( 'delivered', $this->orders[100]->get_meta( '_ltms_aveonline_status' ) );
        $this->assertNotEmpty( $this->orders[100]->get_meta( '_ltms_shipping_delivered_fired' ), 'Debe setear el guard de idempotencia.' );
        // Sync de trazabilidad local.
        $last_update = end( $this->updates );
        $this->assertSame( 'wp_lt_aveonline_guias', $last_update['table'] );
        $this->assertSame( 'ENTREGADA', $last_update['data']['estado'] );
        $this->assertSame( 'G123', $last_update['where']['numguia'] );
    }

    // ── 2. Pedido ya entregado → skip, sin API ────────────────────────────

    public function test_already_delivered_order_skipped_without_api_call(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [ '_ltms_aveonline_status' => 'delivered' ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [], FakeReconcilerAveonlineApi::$shared_calls, 'No debe consultar la API si ya está entregado.' );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_delivered' ) );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_failed' ) );
    }

    // ── 3. Estado fallido → ltms_shipping_failed ──────────────────────────

    public function test_failed_state_fires_shipping_failed(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [ '_ltms_aveonline_tracking' => 'G123' ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        FakeReconcilerAveonlineApi::$shared_results['G123'] = [ 'status' => 'DEVUELTA', 'events' => [] ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $failed = $this->actions_for( 'ltms_shipping_failed' );
        $this->assertCount( 1, $failed );
        $this->assertSame( 100, $failed[0][1][0] );
        $this->assertStringContainsString( 'aveonline:reconciler', $failed[0][1][1] );
        $this->assertSame( 'failed', $this->orders[100]->get_meta( '_ltms_aveonline_status' ) );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_delivered' ) );
    }

    // ── 4. Sin guía → hold intacto, sin API ───────────────────────────────

    public function test_no_guia_leaves_hold_intact(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100 ); // sin tracking meta
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        $this->mock_wpdb->guia_from_table = null;

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [], FakeReconcilerAveonlineApi::$shared_calls );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_delivered' ) );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_failed' ) );
        $this->assertSame( [], $this->updates, 'No debe escribir estado sin guía.' );
    }

    // ── 5. Fallback a tabla lt_aveonline_guias ────────────────────────────

    public function test_guia_resolved_from_local_table_fallback(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100 ); // sin tracking meta
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        $this->mock_wpdb->guia_from_table = 'G456';
        FakeReconcilerAveonlineApi::$shared_results['G456'] = [ 'status' => 'ENTREGADO', 'events' => [] ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [ 'G456' ], FakeReconcilerAveonlineApi::$shared_calls, 'Debe resolver la guía desde la tabla local.' );
        $this->assertCount( 1, $this->actions_for( 'ltms_shipping_delivered' ) );
    }

    // ── 6. Idempotencia: guard fired ya seteado → no re-dispara ───────────

    public function test_delivered_not_duplicated_when_guard_already_set(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [
            '_ltms_aveonline_tracking' => 'G123',
            '_ltms_shipping_delivered_fired' => '2026-01-01 00:00:00',
        ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        FakeReconcilerAveonlineApi::$shared_results['G123'] = [ 'status' => 'ENTREGADA', 'events' => [] ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertCount( 0, $this->actions_for( 'ltms_shipping_delivered' ), 'El guard impide doble crédito.' );
        $this->assertSame( 'delivered', $this->orders[100]->get_meta( '_ltms_aveonline_status' ) );
    }

    // ── 7. En tránsito → no toca el hold ──────────────────────────────────

    public function test_in_transit_leaves_hold_untouched(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [ '_ltms_aveonline_tracking' => 'G123' ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        FakeReconcilerAveonlineApi::$shared_results['G123'] = [ 'status' => 'EN REPARTO', 'events' => [] ];

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [], $this->actions_for( 'ltms_shipping_delivered' ) );
        $this->assertSame( [], $this->actions_for( 'ltms_shipping_failed' ) );
        $this->assertSame( 'in_transit', $this->orders[100]->get_meta( '_ltms_aveonline_status' ) );
    }

    // ── 8. Gate de entrega desactivado → early return ─────────────────────

    public function test_gate_disabled_returns_early(): void {
        $this->orders[100] = new FakeReconcilerOrder( 100, [ '_ltms_aveonline_tracking' => 'G123' ] );
        $this->mock_wpdb->holds = [ $this->make_hold( 1, 10, 100 ) ];
        FakeReconcilerAveonlineApi::$shared_results['G123'] = [ 'status' => 'ENTREGADA', 'events' => [] ];

        $this->mock_options( [ 'ltms_payout_require_delivery' => 'no' ] );
        \LTMS_Core_Config::flush_cache();

        \LTMS_Business_Consumer_Protection::reconcile_stuck_aveonline_holds();

        $this->assertSame( [], FakeReconcilerAveonlineApi::$shared_calls, 'Con el gate off no debe consultar la API.' );
        $this->assertSame( [], $this->queries, 'No debe siquiera consultar holds.' );
    }

    // ── 9. Clasificación por nombre (fuente única en webhook handler) ─────

    public function test_classify_by_nombre_maps_all_semantic_groups(): void {
        $this->require_class( 'LTMS_Aveonline_Webhook_Handler' );
        $this->assertSame( 'delivered', \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'Entregada' ) );
        $this->assertSame( 'delivered', \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'ENTREGADO' ) );
        $this->assertSame( 'failed',    \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'DEVUELTA' ) );
        $this->assertSame( 'failed',    \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'NO ENTREGADA' ) );
        $this->assertSame( 'in_transit',\LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'EN REPARTO' ) );
        $this->assertSame( 'in_transit',\LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'En bodega destino' ) );
        $this->assertSame( 'unknown',   \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( 'ESTADO RARO' ) );
        $this->assertSame( 'unknown',   \LTMS_Aveonline_Webhook_Handler::classify_by_nombre( '' ) );
    }
}