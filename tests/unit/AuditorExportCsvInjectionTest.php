<?php
/**
 * AuditorExportCsvInjectionTest — Tests unitarios para LTMS_Auditor_Export
 *
 * AUDIT-PANEL-CSV-001 CSV-03: el exportador del auditor (mismo formato fiscal
 * Art. 30-B CFF / E.T. 437-2 CO que fiscal-exporter pero usado por la vista del
 * dashboard del auditor) usa fputcsv() con datos de user-meta sin prevención
 * de formula injection. Idéntico anti-patrón a CSV-02.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Auditor_Export
 */
class AuditorExportCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private object $original_wpdb;
    private string $temp_dir;

    protected function setUp(): void {
        parent::setUp();

        $this->original_wpdb = $GLOBALS['wpdb'];
        $this->temp_dir      = sys_get_temp_dir();

        Functions\stubs( [
            'get_bloginfo'         => static fn( string $k ) => 'LT Marketplace',
            'wp_upload_dir'        => static fn() => [ 'basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.com' ],
            'wp_get_current_user'  => static fn() => (object) [ 'display_name' => 'auditor-test' ],
            'sanitize_file_name'   => static fn( string $name ): string => preg_replace( '/[^a-zA-Z0-9_\-\.]/', '_', $name ),
        ] );

        $this->require_class( 'LTMS_Auditor_Export' );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        foreach ( glob( $this->temp_dir . '/ltms-fiscal-30b-*.csv' ) as $f ) {
            @unlink( $f );
        }
        parent::tearDown();
    }

    private function make_wpdb_with_rows( array $rows ): object {
        return new class( $rows ) {
            public string $prefix = 'wp_';
            public string $users   = 'wp_users';
            public string $usermeta = 'wp_usermeta';
            public function __construct( private array $rows ) {}
            public function get_results( mixed $q = null, string $output = OBJECT ): array {
                return $this->rows;
            }
            public function prepare( string $q, mixed ...$args ): string { return $q; }
        };
    }

    private function make_commission_row( array $values ): array {
        return array_merge( [
            'id'                       => 1,
            'order_id'                 => 100,
            'country_code'             => 'MX',
            'created_at'               => '2026-01-15 10:00:00',
            'service_type'            => 'producto',
            'rfc_cliente'              => 'XAXX010101000',
            'gross_amount'             => 1000.0,
            'iva_amount'               => 160.0,
            'ieps_amount'              => 0.0,
            'isr_amount'               => 0.0,
            'iva_retenido'             => 0.0,
            'ieps_retenido'            => 0.0,
            'reteiva_amount'            => 0.0,
            'cfdi_folio'               => 'UUID-EXAMPLE-1234',
            'payment_method_buyer'     => 'card',
            'payment_method_vendor'    => 'bank_transfer',
            'payment_method_platform'  => 'stripe',
            'vendor_id'                => 5,
            'vendor_email'             => 'vendor@example.com',
            'vendor_name'              => 'Vendor Name',
            'vendor_rfc'               => 'VEND010101AAA',
            'vendor_curp'              => 'VEND010101HDFRNN01',
            'vendor_domicilio'         => 'Calle Falsa 123',
            'vendor_pais'              => 'MX',
            'vendor_banco'             => 'BBVA',
            'vendor_clabe'             => '012345678901234567',
            'metadata'                 => '',
            'is_hospedaje'             => 0,
            'hospedaje_direccion'      => '',
            'is_import'                => 0,
            'aranceles_amount'         => 0.0,
            'status'                   => 'completed',
        ], $values );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — generate_csv básico
    // -----------------------------------------------------------------------

    public function test_generate_csv_returns_error_for_empty(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from'  => '2026-01-01',
            'date_to'    => '2026-01-31',
            'country'    => '',
        ] );

        $this->assertSame( [ 'error' => 'Sin datos en el período' ], $result );
    }

    public function test_generate_csv_returns_file_and_count_for_non_empty(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'id' => 42 ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from'  => '2026-01-01',
            'date_to'    => '2026-01-31',
            'country'    => 'MX',
        ] );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['rows'] );
        $this->assertFileExists( $result['file'] );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — BOM UTF-8 + estructura
    // -----------------------------------------------------------------------

    public function test_csv_file_starts_with_utf8_bom(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $bom = chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF );
        $this->assertStringStartsWith( $bom, $content, 'CSV debe empezar con BOM UTF-8.' );
    }

    public function test_csv_includes_fraccion_i_ii_and_sagrilaft_headers(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( 'FRACCIÓN I', $content );
        $this->assertStringContainsString( 'FRACCIÓN II', $content );
        $this->assertStringContainsString( 'SAGRILAFT', $content );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Formula injection sanitization (núcleo del fix)
    // -----------------------------------------------------------------------

    public function test_csv_sanitizes_equal_sign_formula_in_rfc_cliente(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'rfc_cliente' => '=cmd|/c calc!A1' ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'=cmd|/c calc!A1", $content, 'RFC con formula = debe ir precedido de comilla simple.' );
        $this->assertStringNotContainsString( ',"=cmd|/c calc!A1",', $content, 'CSV no debe contener la celda =cmd sin escape.' );
    }

    public function test_csv_sanitizes_plus_minus_at_formulas_in_vendor_data(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [
                'vendor_name'    => '+3+1*2',
                'vendor_banco'   => '-1+cmd',
                'vendor_domicilio' => '@SUM(A1:A2)',
            ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'+3+1*2",     $content, '+ formula debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'-1+cmd",     $content, '- formula debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'@SUM(A1:A2)", $content, '@ formula debe ir precedido de comilla simple.' );
    }

    public function test_csv_sanitizes_tab_and_cr_prefixes_in_curp(): void {
        // vendor_id distintos para evitar agregación (CURP solo se setea la primera vez).
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'vendor_id' => 51, 'vendor_curp' => "\tCURP-TAB-MAL" ] ),
            $this->make_commission_row( [ 'vendor_id' => 52, 'vendor_curp' => "\rCURP-CR-MAL", 'id' => 2 ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'" . "\t" . "CURP-TAB-MAL", $content, 'CURP con tab-prefix debe ir precedido de comilla simple.' );
        $this->assertStringContainsString( "'" . "\r" . "CURP-CR-MAL",  $content, 'CURP con CR-prefix debe ir precedido de comilla simple.' );
    }

    public function test_csv_sanitizes_clabe_with_equal_sign(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'vendor_clabe' => '=HYPERLINK("http://evil.com","click")' ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'=HYPERLINK", $content, 'CLABE con = formula debe ir precedido de comilla simple.' );
    }

    public function test_csv_sanitizes_cfdi_folio_with_equal_sign(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'cfdi_folio' => '=2+2+cmd|/c calc!A1' ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'=2+2+cmd|/c calc!A1", $content, 'CFDI folio con = formula debe ir precedido de comilla simple.' );
    }

    public function test_csv_sanitizes_payment_method_concatenated(): void {
        $GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
            $this->make_commission_row( [ 'payment_method_buyer' => '=cmd' ] ),
        ] );

        $result = \LTMS_Auditor_Export::generate_csv( [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'country' => '',
        ] );

        $content = file_get_contents( $result['file'] );
        $this->assertStringContainsString( "'=cmd", $content, 'pm_a concatenado con = debe ir precedido de comilla simple.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — Reflexión
    // -----------------------------------------------------------------------

    public function test_generate_csv_is_public_static(): void {
        $rm = new \ReflectionMethod( \LTMS_Auditor_Export::class, 'generate_csv' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }
}
