<?php
/**
 * FintechCrsFatcaCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-05
 *
 * LTMS_Fintech_Compliance::generate_crs_fatca_report() construye el CSV CRS
 * (MCAA OECD + IGA CO-US Decreto 2219/2016 + IGA MX-US 2014) con fputcsv() a
 * partir de name (display_name), address (ltms_address user_meta),
 * tin_reporting (ltms_tax_id), tin_foreign (ltms_tin_foreign) y birth_date
 * (ltms_birth_date), todos seteables por el vendor durante el KYC. El reporte
 * se entrega a la DIAN (CO) o SAT (MX).
 *
 * Tests puramente estructurales sobre el source. Patron identico a
 * AuthoritiesRaeeCsvInjectionTest.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Fintech_Compliance::generate_crs_fatca_report
 */
class FintechCrsFatcaCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

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

    public function test_method_generate_crs_fatca_report_exists(): void {
        $this->assertStringContainsString( 'public static function generate_crs_fatca_report(): void {', $this->src );
    }

    public function test_uses_fputcsv_for_crs_header(): void {
        $this->assertStringContainsString( "'TIN_REPORTING', 'NAME', 'ADDRESS', 'RESIDENCE_COUNTRY'", $this->src );
        $this->assertStringContainsString( "'TIN_FOREIGN', 'BIRTH_DATE', 'ACCOUNT_NUMBER'", $this->src );
        $this->assertStringContainsString( "'ACCOUNT_BALANCE', 'ANNUAL_INCOME', 'CURRENCY'", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-05: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-05: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-05: helper debe castear null a string vacio.' );
    }

    public function test_helper_marker_comment_present(): void {
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-05: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables
    // -----------------------------------------------------------------------

    public function test_name_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$v['name'] )", $this->src );
    }

    public function test_address_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( get_user_meta( \$v['vendor_id'], 'ltms_address', true ) )", $this->src );
    }

    public function test_tin_reporting_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( get_user_meta( \$v['vendor_id'], 'ltms_tax_id', true ) )", $this->src );
    }

    public function test_tin_foreign_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$v['tin'] )", $this->src );
    }

    public function test_birth_date_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( get_user_meta( \$v['vendor_id'], 'ltms_birth_date', true ) )", $this->src );
    }

    public function test_account_number_wrapped_in_csv_field(): void {
        // LTMS-WALLET-{vendor_id} es string compuesto; wrap aplica.
        $this->assertStringContainsString( "\$csv_field( 'LTMS-WALLET-' . \$v['vendor_id'] )", $this->src );
    }

    public function test_numeric_amounts_preserved_as_raw(): void {
        // balance_total y annual_income son numericos -> se preservan.
        $this->assertStringContainsString( "\$v['balance_total'],", $this->src, 'Fix CSV2-05: balance_total numerico se preserva.' );
        $this->assertStringContainsString( "\$v['annual_income'],", $this->src, 'Fix CSV2-05: annual_income numerico se preserva.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_name_remains(): void {
        $this->assertStringNotContainsString(
            "\$v['name'],",
            $this->src,
            'CSV2-05 regression: name raw (sin $csv_field) no debe permanecer en CRS.'
        );
    }

    public function test_no_raw_unprotected_address_remains(): void {
        $this->assertStringNotContainsString(
            "get_user_meta( \$v['vendor_id'], 'ltms_address', true ),",
            $this->src,
            'CSV2-05 regression: address raw (sin $csv_field) no debe permanecer en CRS.'
        );
    }
}
