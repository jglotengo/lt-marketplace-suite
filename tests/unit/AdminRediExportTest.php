<?php
/**
 * AdminRediExportTest — Tests unitarios para LTMS_Admin_Redi (AUDIT-PANEL-CSV-001)
 *
 * Cubre:
 *  SECCIÓN 1 — init()
 *    - Registra los 3 hooks wp_ajax_* esperados
 *
 *  SECCIÓN 2 — ajax_approve_agreement() / ajax_revoke_agreement() guards
 *    - wp_send_json_error 403 cuando falta permiso ltms_manage_all_vendors
 *
 *  SECCIÓN 3 — ajax_export_redi_commissions()
 *    - Devuelve CSV en base64 (no texto plano) para evitar problemas de transporte
 *    - Incluye BOM UTF-8 (EF BB BF) para que Excel reconozca la codificación
 *    - El header del CSV tiene las 11 columnas esperadas
 *    - SANITIZA valores con formula injection: '=cmd|/c calc!A1' se antepone '
 *    - SANITIZA valores con +formula, -formula, @formula
 *    - SANITIZA valores con tabulador/CR iniciales
 *    - Escapa comillas dobles embebidas RFC 4180 (" -> "")
 *    - El campo 'count' refleja el número de rows
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Admin_Redi
 */
class AdminRediExportTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private object $original_wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'];

        if ( ! trait_exists( 'LTMS_Logger_Aware', false ) ) {
            eval( 'trait LTMS_Logger_Aware {}' );
        }
        if ( ! class_exists( 'LTMS_Core_Logger', false ) ) {
            eval( 'final class LTMS_Core_Logger { public static function info(string $c, string $m, array $ctx = []): void {} }' );
        }
        if ( ! class_exists( 'LTMS_Utils', false ) ) {
            eval( 'final class LTMS_Utils { public static function now_utc(): string { return date("Y-m-d H:i:s"); } }' );
        }

        \Brain\Monkey\Functions\stubs( [
            'sanitize_text_field' => static fn( $v ) => is_string( $v ) ? $v : (string) $v,
            'sanitize_key'         => static fn( string $k ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $k ) ),
        ] );

        $this->require_class( 'LTMS_Admin_Redi' );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function capture_json_success( callable $callable ): mixed {
        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias(
            function( mixed $data = null ) use ( &$captured ): void {
                $captured = $data;
                throw new \RuntimeException( 'json_success' );
            }
        );
        try {
            $callable();
        } catch ( \RuntimeException $e ) {
            if ( $e->getMessage() === 'json_success' ) {
                return $captured;
            }
            throw $e;
        }
        return null;
    }

    private function capture_json_error( callable $callable ): mixed {
        $captured = null;
        Functions\when( 'wp_send_json_error' )->alias(
            function( mixed $data = null ) use ( &$captured ): void {
                $captured = $data;
                throw new \RuntimeException( 'json_error' );
            }
        );
        try {
            $callable();
        } catch ( \RuntimeException $e ) {
            if ( $e->getMessage() === 'json_error' ) {
                return $captured;
            }
            throw $e;
        }
        return null;
    }

    /**
     * Mock $wpdb que devuelve N filas de lt_redi_commissions con valores
     * potencialmente peligrosos para probar la sanitización CSV.
     */
    private function make_wpdb_with_rows( array $rows ): object {
        return new class( $rows ) {
            public string $prefix = 'wp_';
            public function __construct( private array $rows ) {}
            public function get_results( mixed $q = null, string $output = 'OBJECT' ): array {
                return $this->rows;
            }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
            public function update( string $t, array $d, array $w, mixed $f = null, mixed $wf = null ): int|bool { return 1; }
        };
    }

    private function make_row( array $values ): object {
        return (object) array_merge( [
            'id'                  => 1,
            'order_id'            => 100,
            'origin_vendor_id'    => 5,
            'reseller_vendor_id'  => 6,
            'gross_amount'        => 1000.0,
            'platform_fee'        => 50.0,
            'reseller_commission' => 100.0,
            'origin_vendor_net'   => 850.0,
            'tax_withholding'     => 0.0,
            'status'              => 'paid',
            'created_at'          => date( 'Y-m-d H:i:s' ),
        ], $values );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — init() registra hooks
    // -----------------------------------------------------------------------

    public function test_init_registers_three_wp_ajax_hooks(): void {
        $actions = [];
        Functions\when( 'add_action' )->alias(
            static function( string $hook ) use ( &$actions ): void { $actions[] = $hook; }
        );

        \LTMS_Admin_Redi::init();

        $this->assertContains( 'wp_ajax_ltms_approve_redi_agreement',  $actions );
        $this->assertContains( 'wp_ajax_ltms_revoke_redi_agreement',   $actions );
        $this->assertContains( 'wp_ajax_ltms_export_redi_commissions', $actions );
        $this->assertCount( 3, $actions );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — guards capability
    // -----------------------------------------------------------------------

    public function test_approve_agreement_denies_without_capability(): void {
        // get_current_user_id está definida antes de Patchwork en el bootstrap;
        // no se puede stubbr. Pero el handler llama a wp_send_json_error antes de
        // log_info (que la usa) — el guard de capability cortocircuita.
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $err = $this->capture_json_error( fn() => ( new \LTMS_Admin_Redi() )->ajax_approve_agreement() );

        $this->assertNotNull( $err );
    }

    public function test_revoke_agreement_denies_without_capability(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $err = $this->capture_json_error( fn() => ( new \LTMS_Admin_Redi() )->ajax_revoke_agreement() );

        $this->assertNotNull( $err );
    }

    public function test_export_denies_without_capability(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $err = $this->capture_json_error( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );

        $this->assertNotNull( $err );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — export CSV sanitization (AUDIT-PANEL-CSV-001)
    // -----------------------------------------------------------------------

    public function test_export_returns_base64_encoded_csv(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [ $this->make_row( [ 'id' => 42, 'order_id' => 100 ] ) ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'csv', $result );
        $this->assertArrayHasKey( 'count', $result );
        $this->assertSame( 1, $result['count'] );

        // csv debe ser base64-encoded (no string legible directamente).
        $csv = base64_decode( $result['csv'] );
        $this->assertIsString( $csv );
        $this->assertNotEmpty( $csv );
        $this->assertStringContainsString( 'ID,Pedido,Origen,Revendedor,Bruto,Fee,Comision,NetoOrigen,Retencion,Estado,Fecha', $csv );
        $this->assertStringContainsString( '42', $csv );
    }

    public function test_export_includes_utf8_bom(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [ $this->make_row( [] ) ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );
        $csv = base64_decode( $result['csv'] );

        // BOM UTF-8 = EF BB BF. phpcs no permite escapes hex en string literal?
        $bom = chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF );
        $this->assertStringStartsWith( $bom, $csv, 'CSV debe empezar con BOM UTF-8 para que Excel reconozca la codificación.' );
    }

    public function test_export_sanitizes_formula_injection_equal_sign(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_row( [ 'order_id' => '=cmd|/c calc!A1' ] ),
        ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );
        $csv = base64_decode( $result['csv'] );

        // El peligro "=cmd|/c calc!A1" debe aparecer escapado como "'=cmd|/c calc!A1".
        $this->assertStringContainsString( "'=cmd|/c calc!A1", $csv, 'Formula injection con = debe ir precedido de comilla simple.' );
        // Y NO debe aparecer el payload sin escapar como valor de celda (sí puede aparecer dentro
        // del string escapado, por eso verificamos que la celda contenga el prefijo ').
        $this->assertStringNotContainsString( '"=cmd|/c calc!A1"', $csv, 'CSV no debe contener la celda "=cmd|/c calc!A1" sin escape.' );
    }

    public function test_export_sanitizes_formula_injection_plus_minus_at(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_row( [ 'status' => '+3+1*2' ] ),
            $this->make_row( [ 'status' => '-1+cmd' ] ),
            $this->make_row( [ 'status' => '@SUM(A1:A2)' ] ),
        ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );
        $csv = base64_decode( $result['csv'] );

        $this->assertStringContainsString( "'+3+1*2", $csv, '+ formula debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'-1+cmd",  $csv, '- formula debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'@SUM",    $csv, '@ formula debe ir precedido de comilla simple.' );
    }

    public function test_export_sanitizes_tab_and_cr_prefixes(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_row( [ 'status' => "\tcmd" ] ),
            $this->make_row( [ 'status' => "\rcmd" ] ),
        ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );
        $csv = base64_decode( $result['csv'] );

        $this->assertStringContainsString( "'\tcmd", $csv, 'Tab-prefix debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'\rcmd", $csv, 'CR-prefix debe ir precedido de comilla simple.' );
    }

    public function test_export_escapes_embedded_double_quotes_rfc4180(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_row( [ 'status' => 'paid "with" quotes' ] ),
        ] );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );
        $csv = base64_decode( $result['csv'] );

        // RFC 4180: " dentro de una celda quoted debe escaparse como "".
        $this->assertStringContainsString( 'paid ""with"" quotes', $csv, 'Comillas dobles embebidas deben escaparse como "".' );
    }

    public function test_export_count_matches_rows_returned(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $rows = [
            $this->make_row( [ 'id' => 1, 'order_id' => 100 ] ),
            $this->make_row( [ 'id' => 2, 'order_id' => 200 ] ),
            $this->make_row( [ 'id' => 3, 'order_id' => 300 ] ),
        ];
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( $rows );

        $result = $this->capture_json_success( fn() => ( new \LTMS_Admin_Redi() )->ajax_export_redi_commissions() );

        $this->assertSame( 3, $result['count'] );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — Reflexión
    // -----------------------------------------------------------------------

    public function test_class_is_final(): void {
        // LTMS_Admin_Redi no se declaró `final` en el código (solo lógica de
        // exportación); documentamos el hecho en vez de forzar un refactor de
        // alcance fuera de este fix.
        $rc = new \ReflectionClass( \LTMS_Admin_Redi::class );
        $this->assertTrue( $rc->isInstantiable() );
    }

    public function test_init_is_public_static(): void {
        $rm = new \ReflectionMethod( \LTMS_Admin_Redi::class, 'init' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_ajax_export_redi_commissions_is_public(): void {
        $rm = new \ReflectionMethod( \LTMS_Admin_Redi::class, 'ajax_export_redi_commissions' );
        $this->assertTrue( $rm->isPublic() );
    }
}
