<?php
/**
 * CrossBorderFxCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-03
 *
 * LTMS_Cross_Border_Compliance::generate_fx_forma4_csv() construye el CSV
 * Forma 4 (DIAN CO) con fputcsv() a partir de display_name (get_userdata) y
 * ltms_tax_id / ltms_document_number (user_meta), todos seteables por el
 * vendor durante el onboarding. El reporte se envia a la DIAN.
 *
 * Tests puramente estructurales sobre el source. Patron identico a
 * AuthoritiesRaeeCsvInjectionTest.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Cross_Border_Compliance::generate_fx_forma4_csv
 */
class CrossBorderFxCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $file_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-cross-border-compliance.php';
        $this->src       = file_get_contents( $this->file_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Estructura básica del método
    // -----------------------------------------------------------------------

    public function test_file_exists(): void {
        $this->assertFileExists( $this->file_path );
    }

    public function test_method_generate_fx_forma4_csv_exists(): void {
        $this->assertStringContainsString( 'private static function generate_fx_forma4_csv( array $declarations ): string {', $this->src );
    }

    public function test_uses_fputcsv_for_fx_header(): void {
        $this->assertStringContainsString( "fputcsv( \$fp, [ 'TIPO_DECLARACION', 'PERIODO', 'IDENTIFICACION', 'NOMBRE', 'MONEDA', 'MONTO_TOTAL', 'MONTO_USD', 'NUM_TRANSACCIONES' ] );", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-03: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-03: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-03: helper debe castear null a string vacio.' );
    }

    public function test_helper_marker_comment_present(): void {
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-03: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables
    // -----------------------------------------------------------------------

    public function test_vendor_display_name_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$vendor ? \$vendor->display_name : '' )", $this->src, 'Fix CSV2-03: display_name debe pasar por $csv_field.' );
    }

    public function test_vendor_tax_id_or_document_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( get_user_meta( \$d['vendor_id'], 'ltms_tax_id', true ) ?: get_user_meta( \$d['vendor_id'], 'ltms_document_number', true ) )", $this->src, 'Fix CSV2-03: tax_id/document_number debe pasar por $csv_field.' );
    }

    public function test_currency_and_period_wrapped(): void {
        $this->assertStringContainsString( "\$csv_field( \$d['currency'] )", $this->src );
        $this->assertStringContainsString( "\$csv_field( \$d['month'] )", $this->src );
    }

    public function test_numeric_amounts_preserved_as_raw(): void {
        // $d['total'], $d['total_usd'], $d['tx_count'] son numericos y se preservan.
        $this->assertStringContainsString( "\$d['total'],", $this->src );
        $this->assertStringContainsString( "\$d['total_usd'],", $this->src );
        $this->assertStringContainsString( "\$d['tx_count'],", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_display_name_remains(): void {
        $this->assertStringNotContainsString(
            "\$vendor ? \$vendor->display_name : '',",
            $this->src,
            'CSV2-03 regression: display_name raw (sin $csv_field) no debe permanecer.'
        );
    }

    public function test_no_raw_unprotected_tax_id_remains(): void {
        $this->assertStringNotContainsString(
            "get_user_meta( \$d['vendor_id'], 'ltms_tax_id', true ) ?: get_user_meta( \$d['vendor_id'], 'ltms_document_number', true ),",
            $this->src,
            'CSV2-03 regression: tax_id/document_number raw (sin $csv_field) no debe permanecer.'
        );
    }
}
