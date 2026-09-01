<?php
/**
 * AveonlineGuiasEstadoSyncTest — Tests del sync de trazabilidad lt_aveonline_guias.estado
 *
 * RECONCILIATION FIX (P2): la columna `lt_aveonline_guias.estado` solo la
 * actualizaba el AJAX manual del vendor y quedaba desactualizada (display
 * engañoso) aunque el pedido ya estuviera entregado. Ahora la sincronizan:
 *  - el webhook de estados (LTMS_Aveonline_Webhook_Handler::update_order)
 *  - el cron de tracking (LTMS_Core_Cron_Manager::process_tracking_for_order)
 *  - el reconciliador de holds (Consumer_Protection)
 * via el helper compartido LTMS_Business_Aveonline_Guias::update_estado_by_numguia().
 *
 * Cubre:
 *  - update_estado_by_numguia(): UPDATE con estado en MAYÚSCULAS, validación de
 *    entradas vacías, comportamiento al afectar 0 filas.
 *  - Wiring estructural: webhook handler, cron manager y consumer-protection
 *    invocan el helper / registran el reconciliador (invariantes de source).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers LTMS_Business_Aveonline_Guias
 * @covers LTMS_Aveonline_Webhook_Handler
 * @covers LTMS_Core_Cron_Manager
 */
class AveonlineGuiasEstadoSyncTest extends LTMS_Unit_Test_Case {

    private object $mock_wpdb;
    public array $updates = [];

    protected function setUp(): void {
        parent::setUp();

        $this->updates = [];
        $self = $this;
        $this->mock_wpdb = new class( $self ) {
            public $prefix = 'wp_';
            private $test;
            public $rows_updated = 1;
            public function __construct( $test ) { $this->test = $test; }
            public function prepare( $sql, ...$args ) { return $sql; }
            public function query( $sql ) { return true; }
            public function get_var( $sql ) { return null; }
            public function get_row( $sql, $o = OBJECT ) { return null; }
            public function get_results( $sql, $o = OBJECT ) { return []; }
            public function get_col( $sql ) { return []; }
            public function insert( $t, $d, $f = null ) { return 1; }
            public function update( $t, $d, $w, $f = null, $wf = null ) {
                $this->test->updates[] = [ 'table' => $t, 'data' => $d, 'where' => $w ];
                return $this->rows_updated;
            }
            public function get_charset_collate() { return 'utf8mb4 utf8mb4_unicode_ci'; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    // ── 1. update_estado_by_numguia ────────────────────────────────────────

    public function test_update_estado_by_numguia_uppercases_and_updates(): void {
        $result = \LTMS_Business_Aveonline_Guias::update_estado_by_numguia( 'G123', 'Entregada' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $this->updates );
        $this->assertSame( 'wp_lt_aveonline_guias', $this->updates[0]['table'] );
        $this->assertSame( 'ENTREGADA', $this->updates[0]['data']['estado'], 'Estado debe normalizarse a MAYÚSCULAS.' );
        $this->assertSame( 'G123', $this->updates[0]['where']['numguia'] );
    }

    public function test_update_estado_by_numguia_rejects_empty_numguia(): void {
        $this->assertFalse( \LTMS_Business_Aveonline_Guias::update_estado_by_numguia( '', 'ENTREGADA' ) );
        $this->assertSame( [], $this->updates, 'No debe tocar la BD con numguia vacío.' );
    }

    public function test_update_estado_by_numguia_rejects_empty_estado(): void {
        $this->assertFalse( \LTMS_Business_Aveonline_Guias::update_estado_by_numguia( 'G123', '' ) );
        $this->assertSame( [], $this->updates, 'No debe tocar la BD con estado vacío.' );
    }

    public function test_update_estado_by_numguia_returns_false_when_no_rows(): void {
        $this->mock_wpdb->rows_updated = 0;
        $this->assertFalse( \LTMS_Business_Aveonline_Guias::update_estado_by_numguia( 'G999', 'ENTREGADA' ) );
    }

    // ── 2. Wiring estructural: webhook handler ─────────────────────────────

    public function test_webhook_handler_syncs_guias_estado(): void {
        $src = $this->webhook_source();
        $this->assertStringContainsString(
            'update_estado_by_numguia',
            $src,
            'El webhook debe sincronizar lt_aveonline_guias.estado.'
        );
        $this->assertStringContainsString(
            'LTMS_Business_Aveonline_Guias::update_estado_by_numguia( $guia, $nombre_estado )',
            $src,
            'Debe usar el helper compartido con la guía y el nombre de estado.'
        );
    }

    // ── 3. Wiring estructural: cron de tracking ────────────────────────────

    public function test_cron_manager_syncs_guias_estado(): void {
        $src = $this->cron_source();
        $this->assertStringContainsString(
            'update_estado_by_numguia',
            $src,
            'El cron de tracking debe sincronizar lt_aveonline_guias.estado.'
        );
        $this->assertStringContainsString(
            'if ( \'aveonline\' === $carrier && $tracking_n',
            $src,
            'El sync debe aplicarse solo para guías de Aveonline.'
        );
    }

    // ── 4. Wiring estructural: reconciliador en consumer protection ────────

    public function test_consumer_protection_registers_reconciler_on_daily_cron(): void {
        $src = $this->consumer_protection_source();
        $this->assertStringContainsString(
            'reconcile_stuck_aveonline_holds',
            $src,
            'Consumer Protection debe contener el reconciliador.'
        );
        $this->assertStringContainsString(
            "add_action( 'ltms_daily_cron', [ __CLASS__, 'reconcile_stuck_aveonline_holds' ], 5 )",
            $src,
            'El reconciliador debe registrarse en ltms_daily_cron con prioridad 5 (antes de liberar).'
        );
        $this->assertStringContainsString(
            'resolve_aveonline_guia',
            $src,
            'Debe existir el resolver de guía (meta o tabla local).'
        );
    }

    // ── Helpers de source ───────────────────────────────────────────────────

    private function webhook_source(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/includes/api/webhooks/class-ltms-aveonline-webhook-handler.php'
        );
    }

    private function cron_source(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/includes/core/class-ltms-core-cron-manager.php'
        );
    }

    private function consumer_protection_source(): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/includes/business/class-ltms-business-consumer-protection.php'
        );
    }
}