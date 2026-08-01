<?php
/**
 * XcoverPoliciesCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV-05
 *
 * La view html-admin-xcover-policies.php tiene un bloque de exportacion CSV
 * procedural que se ejecuta si $_GET['export_csv'] esta set. Construye el CSV
 * manualmente con implode(',', array_map(function($v) {...}, $row)) - escapa
 * comillas dobles (RFC 4180) pero NO previene formula injection. Este fix añade
 * el prefijo comilla-simple a valores que empiezan con = + - @ \t \r.
 *
 * Como la view es procedural con dependencias WP acopladas, aplicamos tests
 * estructurales sobre el source file (mismo patron que AuditorDashboardCsvInjectionTest,
 * VendorFollowersTest, HelpCenterAuditTest, etc.) para garantizar los invariantes
 * del fix y prevenir regresiones silenciosas.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers html-admin-xcover-policies.php
 */
class XcoverPoliciesCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $view_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->view_path = dirname( __DIR__, 2 ) . '/includes/admin/views/html-admin-xcover-policies.php';
        $this->src       = file_get_contents( $this->view_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Estructura básica del bloque de exportación
    // -----------------------------------------------------------------------

    public function test_view_file_exists(): void {
        $this->assertFileExists( $this->view_path );
    }

    public function test_view_does_not_require_abspath_guard_because_includes_admin_namespace(): void {
        // La view es incluida por lt-marketplace-suite.php en contexto admin,
        // por lo que define('ABSPATH') ya ocurrio. Verificamos mediante la
        // ausencia de exit directo al inicio (signo de view pura, no plugin file).
        $this->assertStringNotContainsString( "<?php\nif ( ! defined( 'ABSPATH' ) ) exit;", $this->src, 'View debe ser inclusion-only, no entrypoint.' );
    }

    public function test_export_gate_is_correct(): void {
        // El bloque de exportacion se ejecuta solo si $_GET['export_csv'] && table_exists.
        $this->assertStringContainsString( "if ( isset( \$_GET['export_csv'] ) && \$table_exists ) {", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper inline con formula injection protection (núcleo del fix)
    // -----------------------------------------------------------------------

    public function test_export_uses_inline_helper_with_formula_injection_protection(): void {
        // AUDIT-PANEL-CSV-001 CSV-05: el closure dentro de array_map ahora aplica
        // el prefijo ' a valores peligrosos, ademas del str_replace '""' (RFC 4180).
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'Fix CSV-05: helper debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'Fix CSV-05: helper debe anteponer comilla simple.' );
    }

    public function test_export_helper_handles_null_via_string_cast(): void {
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'Fix CSV-05: helper debe castear null a string vacio.' );
    }

    public function test_export_preserves_double_quote_escape_rfc4180(): void {
        // El fix mantiene el escape RFC 4180 existente (str_replace '"', '""').
        $this->assertStringContainsString( "str_replace( '\"', '\"\"', \$v )", $this->src, 'Fix CSV-05: escape RFC 4180 de comillas dobles debe preservarse.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — BOM UTF-8 + headers + estructura del CSV
    // -----------------------------------------------------------------------

    public function test_export_includes_utf8_bom(): void {
        $this->assertStringContainsString( '"\xEF\xBB\xBF"', $this->src, 'CSV debe escribir BOM UTF-8.' );
    }

    public function test_export_sets_csv_headers(): void {
        $this->assertStringContainsString( "header( 'Content-Type: text/csv; charset=UTF-8' );", $this->src );
        $this->assertStringContainsString( "header( 'Content-Disposition: attachment; filename=\"polizas-xcover-", $this->src );
    }

    public function test_export_writes_header_row_then_data_rows(): void {
        // La estructura header + rows se preserva.
        $this->assertStringContainsString( "echo implode( ',', array_keys( \$all[0] ) )", $this->src );
        $this->assertStringContainsString( "foreach ( \$all as \$row ) {", $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — regression: NO queda patron raw original sin formula protection
    // -----------------------------------------------------------------------

    public function test_no_raw_unprotected_closure_remains(): void {
        // regression: el closure ANTERIOR (solo str_replace '"', '""') no debe
        // permanecer en el source. Patron a evitar:
        //   function( $v ) { return '"' . str_replace( '"', '""', $v ) . '"'; }
        $this->assertStringNotContainsString(
            "function( \$v ) { return '\"' . str_replace( '\"', '\"\"', \$v ) . '\"'; }",
            $this->src,
            'CSV-05 regression: closure raw (sin formula protection) no debe permanecer.'
        );
    }
}
