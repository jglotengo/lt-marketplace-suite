<?php
/**
 * AuditorDashboardCsvInjectionTest — Tests estructurales para AUDIT-PANEL-CSV-001 CSV-04
 *
 * La view view-auditor-dashboard.php contiene un bloque de exportacion CSV
 * procedural que se ejecuta SI $_GET['export'] === 'csv'. El bloque usa
 * fputcsv() con datos de user-meta (RFC, CURP, CLABE, domicilio, banco) sin
 * prevencion de formula injection. Como la view es procedural con dependencias
 * WordPress acopladas (no se puede instanciar), aplicamos tests estructurales
 * sobre el source file (mismo patron que VendorFollowersTest, HelpCenterAuditTest,
 * HomeProductScopeAuditTest, etc.).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source):
 * NO cargan la view, NO invocan funciones WP. Verifican invariantes del fix
 * para prevenir regresiones silenciosas.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers view-auditor-dashboard.php
 */
class AuditorDashboardCsvInjectionTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    private string $view_path;
    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->view_path = dirname( __DIR__, 2 ) . '/includes/admin/views/view-auditor-dashboard.php';
        $this->src       = file_get_contents( $this->view_path );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 1 — Bloque de exportación definido y gating correcto
    // -----------------------------------------------------------------------

    public function test_view_file_exists(): void {
        $this->assertFileExists( $this->view_path );
    }

    public function test_export_gate_is_correct(): void {
        // El bloque de exportación se ejecuta solo si $_GET['export'] === 'csv'.
        // Esto previene que se ejecute accidentalmente en la vista normal.
        // CICLO20-P1-AD-066 FIX: el gate ahora requiere ademas wp_verify_nonce
        // con accion 'ltms_auditor_export_csv' (defense-in-depth contra CSRF
        // de descarga). El test original (C14) buscaba el patron textual sin
        // nonce; se actualiza para afirmar ambos componentes del nuevo gate.
        $this->assertStringContainsString( "\$_GET['export'] === 'csv'", $this->src );
        $this->assertStringContainsString( "wp_verify_nonce( sanitize_text_field( \$_GET['_wpnonce'] ), 'ltms_auditor_export_csv' )", $this->src );
        $this->assertStringContainsString( 'if ( $export_csv ) {', $this->src );
        // El patron vulnerable original (sin nonce) ya NO debe estar.
        $this->assertStringNotContainsString(
            "\$export_csv = isset( \$_GET['export'] ) && \$_GET['export'] === 'csv';\n",
            $this->src,
            'AD-066: el patron sin nonce (CSRF-able) no debe existir.'
        );
    }

    public function test_view_opens_with_abspath_guard(): void {
        $this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $this->src, 'View debe abrir con guard ABSPATH.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 2 — Helper $csv_field definido (núcleo del fix)
    // -----------------------------------------------------------------------

    public function test_csv_field_helper_is_defined(): void {
        // AUDIT-PANEL-CSV-001: helper $csv_field declarado en el bloque de export.
        $this->assertStringContainsString( '$csv_field = static function ( $v ): string {', $this->src, 'Fix CSV-04: helper $csv_field debe estar definido.' );
    }

    public function test_csv_field_escapes_equal_sign(): void {
        // El helper debe anteponer una comilla simple a valores que empiezan con =.
        // Para verificar que la vida contiene string-literal "\t" "\r" (no bytes),
        // usamos secuencia literal con backslash + char (chr() con bytes no funcionaria).
        $this->assertStringContainsString( "in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )", $this->src, 'csv_field debe considerar = + - @ \t \r.' );
        $this->assertStringContainsString( "\$v = \"'\" . \$v;", $this->src, 'csv_field debe anteponer comilla simple.' );
    }

    public function test_csv_field_handles_null_via_string_cast(): void {
        // El helper debe manejar null via (string)($v ?? '').
        $this->assertStringContainsString( '(string) ( $v ??', $this->src, 'csv_field debe castear null a string vacio.' );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 3 — $csv_field aplicado a las 3 secciones (Fracción I, II, SAGRILAFT)
    // -----------------------------------------------------------------------

    public function test_csv_field_applied_to_fraccion_i(): void {
        // Fraccion I: $fn permanece para numericos (gross_amount, iva_amount, total_con_iva).
        // Los strings (id, order_id, country_code, created_at, service_type,
        // rfc_cliente, cfdi_folio, metodo_pago_adquiriente, vendor_id) van con $csv_field.
        $this->assertStringContainsString( "\$csv_field( \$r['id'] ),",                       $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['order_id'] ),",                 $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['country_code'] ),",            $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['rfc_cliente'] ),",            $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['cfdi_folio'] ),",              $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['metodo_pago_adquiriente'] ),", $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['vendor_id'] ),",               $this->src );

        // Numericos siguen con $fn (number_format).
        $this->assertStringContainsString( "\$fn( \$r['gross_amount'] ),", $this->src );
        $this->assertStringContainsString( "\$fn( \$r['iva_amount'] ),",   $this->src );
    }

    public function test_csv_field_applied_to_fraccion_ii(): void {
        // Fraccion II: todos los datos de user-meta del vendor (RFC, CURP, CLABE,
        // domicilio, banco) van con $csv_field. Numericos siguen con $fn.
        $this->assertStringContainsString( "\$csv_field( \$r['vendor_id'] ),",          $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['email'] ),",              $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['nombre'] ),",             $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['rfc_nif'] ),",             $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['curp'] ),",               $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['domicilio_fiscal'] ),",   $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['banco_institucion'] ),",  $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['clabe_cuenta'] ),",       $this->src );

        // Numericos siguen con $fn.
        $this->assertStringContainsString( "\$fn( \$r['monto_isr'] ),",  $this->src );
        $this->assertStringContainsString( "\$fn( \$r['monto_iva'] ),",  $this->src );
        $this->assertStringContainsString( "\$fn( \$r['monto_ieps'] ),", $this->src );
    }

    public function test_csv_field_applied_to_sagrilaft_section(): void {
        // SAGRILAFT: strings (id, vendor_id, display_name, user_email, currency,
        // status, created_at) con $csv_field; amount numerico con $fn.
        $this->assertStringContainsString( "\$csv_field( \$r['display_name'] ),", $this->src );
        $this->assertStringContainsString( "\$csv_field( \$r['user_email'] ),",    $this->src );
        $this->assertStringContainsString( "\$fn( \$r['amount'] ),",               $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 4 — BOM UTF-8 + headers + fracción II incluida
    // -----------------------------------------------------------------------

    public function test_csv_includes_bom_utf8_prefix(): void {
        $this->assertStringContainsString( '"\xEF\xBB\xBF"', $this->src, 'CSV debe escribir BOM UTF-8 antes de fputcsv.' );
    }

    public function test_csv_contains_fraccion_i_ii_and_sagrilaft_headers(): void {
        $this->assertStringContainsString( 'FRACCIÓN I',   $this->src );
        $this->assertStringContainsString( 'FRACCIÓN II',  $this->src );
        $this->assertStringContainsString( 'SAGRILAFT',    $this->src );
    }

    public function test_csv_headers_sent_correctly(): void {
        $this->assertStringContainsString( "header( 'Content-Type: text/csv; charset=UTF-8' );", $this->src );
        $this->assertStringContainsString( "header( 'Content-Disposition: attachment; ",          $this->src );
    }

    // -----------------------------------------------------------------------
    // SECCIÓN 5 — regression: NO quedan campos raw sin escape en fputcsv de Fracción I
    // -----------------------------------------------------------------------

    public function test_no_raw_rfc_cliente_in_fraccion_i(): void {
        // Tras el fix, la celda Fraccion I de rfc_cliente debe ir vía $csv_field,
        // no directamente. Buscamos patrón de regresión: ", $r['rfc_cliente'],".
        $this->assertStringNotContainsString( ",        \$r['rfc_cliente'],",     $this->src, 'CSV-04 regression: rfc_cliente sin sanitize vuelve.' );
        $this->assertStringNotContainsString( "            \$r['cfdi_folio'],",    $this->src, 'CSV-04 regression: cfdi_folio sin sanitize vuelve.' );
        $this->assertStringNotContainsString( "            \$r['curp'],",         $this->src, 'CSV-04 regression: curp sin sanitize vuelve.' );
        $this->assertStringNotContainsString( "            \$r['clabe_cuenta'],", $this->src, 'CSV-04 regression: clabe_cuenta sin sanitize vuelve.' );
    }

    public function test_no_raw_vendor_domicilio_in_fraccion_ii(): void {
        $this->assertStringNotContainsString( "            \$r['domicilio_fiscal'],", $this->src, 'CSV-04 regression: domicilio_fiscal sin sanitize vuelve.' );
        $this->assertStringNotContainsString( "            \$r['banco_institucion'],", $this->src, 'CSV-04 regression: banco_institucion sin sanitize vuelve.' );
    }
}
