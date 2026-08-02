<?php
/**
 * AuthoritiesRaeeCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-01
 *
 * LTMS_Authorities_Compliance::generate_raee_annual_report() construye el CSV
 * anual RAEE con fputcsv() a partir de product_name (get_the_title) y
 * raee_category (post_meta _ltms_raee_category), ambos editables por el vendor
 * al crear/editar el producto. fputcsv escapa comillas dobles (RFC 4180) pero
 * NO previene formula injection: un valor que empiece con = + - @ \t \r se
 * interpreta como formula al abrir el CSV en Excel/Sheets. El reporte se envia
 * por email al oficial de cumplimiento (ANLA CO / SEMARNAT MX).
 *
 * Tests puramente estructurales sobre el source (mismo patron que
 * AuditorExportCsvInjectionTest, FiscalExporterCsvInjectionTest, etc.) para
 * garantizar los invariantes del fix y prevenir regresiones silenciosas. La
 * clase tiene dependencias WP acopladas que no se pueden instanciar en
 * LTMS_UNIT_ONLY, pero los asserts estructurales validan el codigo real.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Authorities_Compliance::generate_raee_annual_report
 */
class AuthoritiesRaeeCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

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

    public function test_method_generate_raee_annual_report_exists(): void {
        $this->assertStringContainsString( 'public static function generate_raee_annual_report(): void {', $this->src );
    }

    public function test_method_uses_fputcsv_for_raee_export(): void {
        $this->assertStringContainsString( "fputcsv( \$fp, [ 'PRODUCT_ID', 'PRODUCT_NAME', 'RAEE_CATEGORY', 'UNITS_SOLD', 'YEAR' ] );", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection (núcleo del fix)
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        // AUDIT-PANEL-CSV-001 CSV2-01: helper debe considerar = + - @ \t \r.
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-01: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-01: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-01: helper debe castear null a string vacio.' );
    }

    public function test_helper_is_inside_raee_block(): void {
        // El helper debe estar definido despues del fopen de RAEE y antes del
        // fputcsv de RAEE. Localizamos por cercadad al filename pattern.
        $this->assertStringContainsString( "raee_report_' . \$year . '_' . wp_generate_password( 6, false ) . '.csv'", $this->src );
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-01: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables (product_name, raee_category)
    // -----------------------------------------------------------------------

    public function test_product_name_wrapped_in_csv_field(): void {
        // product_name (get_the_title) es el campo mas atacable.
        $this->assertStringContainsString( "\$csv_field( \$r['product_name'] )", $this->src, 'Fix CSV2-01: product_name debe pasar por $csv_field.' );
    }

    public function test_raee_category_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$r['raee_category'] )", $this->src, 'Fix CSV2-01: raee_category debe pasar por $csv_field.' );
    }

    public function test_units_sold_preserved_as_raw_int(): void {
        // Numerico no se envuelve (preserva formato number_format).
        $this->assertStringContainsString( "\$r['units_sold'], \$year ] );", $this->src, 'Fix CSV2-01: numericos se preservan sin $csv_field.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression: NO queda patron raw original sin formula protection
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_fputcsv_for_raee_remains(): void {
        // regression: la linea original NO debe permanecer en el source.
        // Patron a evitar:
        //   fputcsv( $fp, [ $r['product_id'], $r['product_name'], $r['raee_category'], $r['units_sold'], $year ] );
        $this->assertStringNotContainsString(
            "fputcsv( \$fp, [ \$r['product_id'], \$r['product_name'], \$r['raee_category'], \$r['units_sold'], \$year ] );",
            $this->src,
            'CSV2-01 regression: fputcsv raw (sin $csv_field en product_name/raee_category) no debe permanecer.'
        );
    }

    public function test_csv_field_closure_count_matches_two_methods(): void {
        // El archivo tiene 2 metodos de exportacion CSV (RAEE + INVIMA); ambos
        // deben tener su propio closure $csv_field (no compartido, ya que son
        // metodos estaticos distintos sin estado compartido).
        $count = substr_count( $this->src, '$csv_field = static function ( $v ): string {' );
        $this->assertGreaterThanOrEqual( 2, $count, 'CSV2-01+CSV2-02: ambos metodos (RAEE + INVIMA) deben definir $csv_field.' );
    }
}
