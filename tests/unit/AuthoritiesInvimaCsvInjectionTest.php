<?php
/**
 * AuthoritiesInvimaCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-02
 *
 * LTMS_Authorities_Compliance::generate_invima_annual_report() (verificar
 * nombre exacto en source) construye el CSV anual INVIMA con fputcsv() a
 * partir de product_name (get_the_title), category (taxonomia del producto) e
 * invima_cert (post_meta _ltms_cert_invima_registro), todos editables por el
 * vendor. fputcsv NO previene formula injection. El reporte se envia por email
 * al oficial de cumplimiento (INVIMA CO / COFEPRIS MX).
 *
 * Tests puramente estructurales sobre el source. Patron identico a
 * AuthoritiesRaeeCsvInjectionTest.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Authorities_Compliance::generate_invima_annual_report
 */
class AuthoritiesInvimaCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $file_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-authorities-compliance.php';
        $this->src       = file_get_contents( $this->file_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Estructura básica del método
    // -----------------------------------------------------------------------

    public function test_file_exists(): void {
        $this->assertFileExists( $this->file_path );
    }

    public function test_method_generates_invima_csv(): void {
        $this->assertStringContainsString( "fputcsv( \$fp, [ 'PRODUCT_ID', 'PRODUCT_NAME', 'CATEGORY', 'INVIMA_CERT', 'UNITS_SOLD', 'YEAR' ] );", $this->src );
        $this->assertStringContainsString( "invima_report_' . \$year . '_' . wp_generate_password( 6, false ) . '.csv'", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-02: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-02: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-02: helper debe castear null a string vacio.' );
    }

    public function test_helper_marker_comment_present(): void {
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-02: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables
    // -----------------------------------------------------------------------

    public function test_product_name_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$r['product_name'] )", $this->src, 'Fix CSV2-02: product_name debe pasar por $csv_field.' );
    }

    public function test_category_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$r['category'] )", $this->src, 'Fix CSV2-02: category debe pasar por $csv_field.' );
    }

    public function test_invima_cert_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$r['invima_cert'] )", $this->src, 'Fix CSV2-02: invima_cert debe pasar por $csv_field.' );
    }

    public function test_units_sold_preserved_as_raw_int(): void {
        $this->assertStringContainsString( "\$r['units_sold'], \$year ] );", $this->src, 'Fix CSV2-02: numericos se preservan sin $csv_field.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_fputcsv_for_invima_remains(): void {
        $this->assertStringNotContainsString(
            "fputcsv( \$fp, [ \$r['product_id'], \$r['product_name'], \$r['category'], \$r['invima_cert'], \$r['units_sold'], \$year ] );",
            $this->src,
            'CSV2-02 regression: fputcsv raw (sin $csv_field) no debe permanecer.'
        );
    }
}
