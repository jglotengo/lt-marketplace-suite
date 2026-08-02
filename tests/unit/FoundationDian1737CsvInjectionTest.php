<?php
/**
 * FoundationDian1737CsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV2-06
 *
 * LTMS_Foundation_Compliance::generate_dian_1737_report() (verificar nombre
 * exacto en source) construye el CSV Formato 1737 v.9 (DIAN CO, donaciones
 * deducibles) con fputcsv() a partir de donor_nit (ltms_tax_id user_meta) y
 * display_name (nombre del donante en WP), ambos seteables por el usuario al
 * registrarse / editar su perfil.
 *
 * Tests puramente estructurales sobre el source. Patron identico a
 * AuthoritiesRaeeCsvInjectionTest.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Foundation_Compliance::generate_dian_1737_report
 */
class FoundationDian1737CsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $file_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->file_path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-foundation-compliance.php';
        $this->src       = file_get_contents( $this->file_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Estructura básica del método
    // -----------------------------------------------------------------------

    public function test_file_exists(): void {
        $this->assertFileExists( $this->file_path );
    }

    public function test_uses_fputcsv_for_dian_1737_header(): void {
        $this->assertStringContainsString( "'TIPO_DOC', 'NIT_CC_DONANTE', 'NOMBRE_DONANTE', 'CONCEPTO'", $this->src );
        $this->assertStringContainsString( "'MONTO_DONACION', 'MONEDA', 'FECHA_DONACION', 'FORMA_PAGO'", $this->src );
        $this->assertStringContainsString( "'TIPO_DONACION', 'DETERMINACION_CUANTIA'", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection
    // -----------------------------------------------------------------------

    public function test_uses_inline_helper_with_formula_injection_protection(): void {
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV2-06: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV2-06: helper debe anteponer comilla simple.' );
    }

    public function test_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV2-06: helper debe castear null a string vacio.' );
    }

    public function test_helper_marker_comment_present(): void {
        $this->assertStringContainsString( '// AUDIT-PANEL-CSV-001 CSV2-06: proteccion CSV formula injection', $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — Fix aplicado a las celdas atacables
    // -----------------------------------------------------------------------

    public function test_donor_nit_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$d['donor_nit'] ?: '' )", $this->src );
    }

    public function test_display_name_wrapped_in_csv_field(): void {
        $this->assertStringContainsString( "\$csv_field( \$d['display_name'] )", $this->src );
    }

    public function test_currency_and_created_at_wrapped(): void {
        $this->assertStringContainsString( "\$csv_field( \$d['currency'] )", $this->src );
        $this->assertStringContainsString( "\$csv_field( \$d['created_at'] )", $this->src );
    }

    public function test_total_donation_preserved_as_raw(): void {
        $this->assertStringContainsString( "\$d['total_donation'],", $this->src, 'Fix CSV2-06: total_donation numerico se preserva.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_display_name_remains(): void {
        $this->assertStringNotContainsString(
            "\$d['display_name'],",
            $this->src,
            'CSV2-06 regression: display_name raw (sin $csv_field) no debe permanecer en DIAN 1737.'
        );
    }

    public function test_no_raw_unprotected_donor_nit_remains(): void {
        $this->assertStringNotContainsString(
            "\$d['donor_nit'] ?: '',",
            $this->src,
            'CSV2-06 regression: donor_nit raw (sin $csv_field) no debe permanecer en DIAN 1737.'
        );
    }
}
