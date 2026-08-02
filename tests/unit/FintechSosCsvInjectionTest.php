<?php
/**
 * FintechSosCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-04
 *
 * LTMS_Fintech_Compliance::generate_sos_csv_uiaf() construye el CSV SOS (UIAF
 * Anexo 1 CO / SHCP Anexo 1 MX) con fputcsv() a partir de display_name
 * (get_userdata), ltms_document_number (user_meta) y $a['reason'] (cadena
 * construida en detect_sos con datos de la wallet del vendor). El reporte se
 * envia al oficial de cumplimiento (UIAF CO / SHCP MX).
 *
 * Tests puramente estructurales sobre el source. Patron identico a
 * AuthoritiesRaeeCsvInjectionTest.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Fintech_Compliance::generate_sos_csv_uiaf
 */
class FintechSosCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $file_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-fintech-compliance.php';
        $this->src       = file_get_contents( $this->file_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Estructura básica del método
    // -----------------------------------------------------------------------

    public function test_file_exists(): void {
        $this->assertFileExists( $this->file_path );
    }

    public function test_method_generate_sos_csv_uiaf_exists(): void {
        $this->assertStringContainsString( 'private static function generate_sos_csv_uiaf( array $alerts ): string {', $this->src );
    }

    public function test_uses_fputcsv_for_sos_header(): void {
        $this->assertStringContainsString( "'TIPO_REPORTE', 'PERIODO', 'IDENTIFICACION', 'NOMBRE'", $this->src );
        $this->assertStringContainsString( "'TIPO_OPERACION', 'MONTO_TOTAL', 'MONEDA', 'FECHA_OPERACION'", $this->src );
        $this->assertStringContainsString( "'DESCRIPCION_SOSPECHA', 'PRODUCTO'", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-04: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-04: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-04: helper debe castear null a string vacio.' );
    }

    public function test_helper_marker_comment_present(): void {
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-04: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables
    // -----------------------------------------------------------------------

    public function test_display_name_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$vendor ? \$vendor->display_name : \"Vendor #{\$a['vendor_id']}\" )", $this->src );
    }

    public function test_document_number_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$doc ?: 'DESCONOCIDO' )", $this->src );
    }

    public function test_reason_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$a['reason'] )", $this->src );
    }

    public function test_currency_and_dates_wrapped(): void {
        $this->assertStringContainsString( "\$csv_field( \$a['currency'] )", $this->src );
        $this->assertStringContainsString( "\$csv_field( gmdate( 'Y-m-d' ) )", $this->src );
        $this->assertStringContainsString( "\$csv_field( gmdate( 'Ym' ) )", $this->src );
    }

    public function test_total_amount_preserved_as_raw(): void {
        $this->assertStringContainsString( "\$a['total'],", $this->src, 'Fix CSV2-04: total numerico se preserva.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_display_name_in_sos_remains(): void {
        $this->assertStringNotContainsString(
            "\$vendor ? \$vendor->display_name : \"Vendor #{\$a['vendor_id']}\",",
            $this->src,
            'CSV2-04 regression: display_name raw (sin $csv_field) no debe permanecer.'
        );
    }

    public function test_no_raw_unprotected_reason_remains(): void {
        $this->assertStringNotContainsString(
            "\$a['reason'],",
            $this->src,
            'CSV2-04 regression: reason raw (sin $csv_field) no debe permanecer en SOS.'
        );
    }

    public function test_two_csv_field_closures_present_for_sos_and_crs(): void {
        // El archivo tiene 2 metodos CSV (SOS UIAF + CRS FATCA); ambos deben
        // definir su propio $csv_field.
        $count = substr_count( $this->src, '$csv_field = static function ( $v ): string {' );
        $this->assertGreaterThanOrEqual( 2, $count, 'CSV2-04+CSV2-05: ambos metodos (SOS + CRS) deben definir $csv_field.' );
    }
}
