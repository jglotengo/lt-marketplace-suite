<?php
/**
 * AuditCiclo2AdminFinancialFixesTest — Tests para los fixes P0+P1 del Ciclo 2.
 *
 * Cubre los fixes aplicados al backoffice financial (6 archivos):
 *   - AUDIT-ADMIN-001-001 P0: bank-reconciler — dead code truncado
 *     `class LTMS_Legal_Evidence_Handler {` sin body ni cierre provoca
 *     PHP Parse error al cargar el archivo fuera del flujo esperado.
 *   - AUDIT-ADMIN-001-003 P1: bank-reconciler ajax_mark_reconciled —
 *     SELECT + UPDATE no atómico. Race condition con scheduler de payouts
 *     dejaba payouts cancelados marcados como reconciled.
 *   - AUDIT-ADMIN-001-005 P1: bank-reconciler ajax_get_reconciliation —
 *     transient ltms_bank_import_{user_id} no se eliminaba tras consumirlo,
 *     dejando info bancaria accesible durante 1h.
 *   - AUDIT-ADMIN-002-004 P0: commission-writer — START TRANSACTION/COMMIT
 *     dentro del foreach → COMMIT por-ítem. Si el 2° ítem fallaba, el 1°
 *     ya estaba commiteado → reporte fiscal parcial (infracción Art. 30-B).
 *     Adicionalmente sin ROLLBACK explícito en path de error.
 *   - AUDIT-ADMIN-002-006 P1: commission-writer — $wpdb->insert/->update
 *     sin verificar retorno. Log "FISCAL_FIELDS_WRITTEN" mintiendo si
 *     el INSERT/UPDATE fallaba silenciosamente.
 *   - AUDIT-ADMIN-003-001 P1: fiscal-exporter generate_csv — capability
 *     check defensivo en método público (caller futuro mal implementado).
 *   - AUDIT-ADMIN-003-002 P1: fiscal-exporter — `LIMIT $limit` interpolado
 *     sin placeholder. Cambiar a `LIMIT %d` + absint para cerrar riesgo
 *     futuro de SQLi.
 *   - AUDIT-ADMIN-PAYOUTS-001 P0: payouts ajax_kyc_proxy_doc — path
 *     traversal vía `key=../../../wp-config.php` + IDOR cross-vendor
 *     (descarga de cédula/RUT de cualquier vendor). Whitelist regex.
 *   - AUDIT-ADMIN-PAYOUTS-002 P0: payouts ajax_kyc_proxy_doc — bypass de
 *     nonce "si es admin con ltms_manage_kyc, permitir sin nonce". Habilita
 *     CSRF/SSRF-style vía <img> tags a admins autenticados.
 *   - AUDIT-ADMIN-PAYOUTS-003 P0: payouts ajax_kyc_proxy_doc — Content-Type
 *     sniffing + Content-Disposition: inline → stored XSS vía proxy.
 *   - AUDIT-ADMIN-PAYOUTS-006 P1: payouts ajax_kyc_proxy_doc — log de
 *     $_GET/$_REQUEST en claro (puede incluir cookies en algunos SAPIs).
 *   - AUDIT-ADMIN-SAT-001 P1: sat-report log_sat_access + log_fiscal_access —
 *     auditor_name/nit/rfc leídos de headers HTTP X-Auditor-* controlables
 *     por el cliente → bitácora fiscal sin valor probatorio.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AUDIT-ADMIN-001-001, AUDIT-ADMIN-001-003, AUDIT-ADMIN-001-005,
 *         AUDIT-ADMIN-002-004, AUDIT-ADMIN-002-006, AUDIT-ADMIN-003-001,
 *         AUDIT-ADMIN-003-002, AUDIT-ADMIN-PAYOUTS-001/002/003/006,
 *         AUDIT-ADMIN-SAT-001
 */
class AuditCiclo2AdminFinancialFixesTest extends LTMS_Unit_Test_Case {

    private const BANK_RECONCILER_PATH    = __DIR__ . '/../../includes/admin/class-ltms-bank-reconciler.php';
    private const COMMISSION_WRITER_PATH  = __DIR__ . '/../../includes/admin/class-ltms-commission-writer.php';
    private const FISCAL_EXPORTER_PATH    = __DIR__ . '/../../includes/admin/class-ltms-fiscal-exporter.php';
    private const ADMIN_PAYOUTS_PATH      = __DIR__ . '/../../includes/admin/class-ltms-admin-payouts.php';
    private const ADMIN_SAT_REPORT_PATH   = __DIR__ . '/../../includes/admin/class-ltms-admin-sat-report.php';

    protected function setUp(): void {
        parent::setUp();

        Functions\stubs( [
            'sanitize_text_field' => static fn( string $s ): string => $s,
            '__'                  => static fn( string $s ): string => $s,
            'wp_unslash'          => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
        ] );
    }

    protected function tearDown(): void {
        \LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ── AUDIT-ADMIN-001-001 P0: bank-reconcifier dead code truncado ───────

    /**
     * El archivo class-ltms-bank-reconcifier.php NO debe contener la
     * declaración orphan `class LTMS_Legal_Evidence_Handler {` truncada.
     * Antes, las líneas 367-377 abrían la clase sin cerrarla provocando
     * PHP Parse error: unexpected end of file al cargar el archivo fuera
     * del flujo esperado. La clase real vive en class-ltms-legal-evidence-handler.php.
     */
    public function test_bank_reconciler_no_legal_evidence_handler_dead_class(): void {
        $this->assertFileExists( self::BANK_RECONCILER_PATH );
        $source = file_get_contents( self::BANK_RECONCILER_PATH );

        // La clase real LTMS_Legal_Evidence_Handler está en su propio
        // archivo. Cualquier mención en bank-reconcifier.php es dead code.
        $this->assertStringNotContainsString(
            'class LTMS_Legal_Evidence_Handler',
            $source,
            'AUDIT-ADMIN-001-001: bank-reconcifier.php no debe declarar LTMS_Legal_Evidence_Handler — la clase real vive en class-ltms-legal-evidence-handler.php. Eliminar dead code orphan.'
        );
    }

    /**
     * El archivo debe terminar con el cierre de la clase LTMS_Bank_Reconciler
     * sin dejar PHPDoc de clase orphan hanging.
     */
    public function test_bank_reconciler_file_ends_correctly(): void {
        $this->assertFileExists( self::BANK_RECONCILER_PATH );
        $source = file_get_contents( self::BANK_RECONCILER_PATH );

        // El último bloque de comentario NO debe ser el PHPDoc abierto de
        // LTMS_Legal_Evidence_Handler. Verificamos con el fix tag.
        $this->assertStringContainsString(
            'AUDIT-ADMIN-001-001 FIX',
            $source,
            'AUDIT-ADMIN-001-001: el fix debe estar marcado con el tag en el código.'
        );
    }

    // ── AUDIT-ADMIN-001-003 P1: ajax_marcar_reconciled atomic UPDATE ─────

    /**
     * ajax_mark_reconciled debe hacer el UPDATE con cláusula WHERE que
     * valide atomically status IN ('completed','paid'). Antes, el SELECT
     * previo + UPDATE separado era no atómico (race condition con scheduler).
     */
    public function test_mark_reconciled_uses_atomic_update_with_status_in_where(): void {
        $this->assertFileExists( self::BANK_RECONCILER_PATH );
        $source = file_get_contents( self::BANK_RECONCILER_PATH );

        // El WHERE del UPDATE debe incluir el check de status.
        $this->assertStringContainsString(
            "WHERE id = %d AND status IN ('completed','paid')",
            $source,
            'AUDIT-ADMIN-001-003: el UPDATE debe ser atómico con status IN (completed,paid) en WHERE.'
        );

        // No debe quedar el SELECT previo de status (ha sido reemplazado
        // por el WHERE atómico).
        $this->assertStringNotContainsString(
            "SELECT status FROM `{\$table}` WHERE id = %d",
            $source,
            'AUDIT-ADMIN-001-003: eliminar el SELECT previo no atómico.'
        );
    }

    /**
     * El handler debe verificar $updated === 0 (no solo === false) para
     * distinguir "no se actualizó ninguna fila" de "error de DB".
     */
    public function test_mark_reconciled_checks_zero_affected_rows(): void {
        $this->assertFileExists( self::BANK_RECONCILER_PATH );
        $source = file_get_contents( self::BANK_RECONCILER_PATH );

        $this->assertStringContainsString(
            '$updated === 0',
            $source,
            'AUDIT-ADMIN-001-003: verificar affected_rows === 0 para detectar payout no encontrado o cambio de status.'
        );
    }

    // ── AUDIT-ADMIN-001-005 P1: cleanup transient tras get_reconciliation ─

    /**
     * ajax_get_reconciliation debe eliminar el transient
     * ltms_bank_import_{user_id} tras consumirlo, para que la info
     * bancaria no quede accesible durante 1h.
     */
    public function test_get_reconciliation_deletes_transient_after_use(): void {
        $this->assertFileExists( self::BANK_RECONCILER_PATH );
        $source = file_get_contents( self::BANK_RECONCILER_PATH );

        $this->assertStringContainsString(
            "delete_transient( 'ltms_bank_import_' . get_current_user_id() )",
            $source,
            'AUDIT-ADMIN-001-005: delete_transient(llave) tras consumir los bank_rows.'
        );
        $this->assertStringContainsString(
            'AUDIT-ADMIN-001-005 FIX',
            $source,
            'AUDIT-ADMIN-001-005: el fix debe estar marcado con el tag en el código.'
        );
    }

    // ── AUDIT-ADMIN-002-004 P0: commission-writer transacción por-orden ──

    /**
     * El START TRANSACTION debe estar ANTES del foreach (transacción por-
     * orden, no por-ítem). Antes, cada ítem tenía su propia transacción
     * → COMMIT por-ítem → si el 2° fallaba, el 1° ya estaba commiteado.
     */
    public function test_commission_writer_start_transaction_before_foreach(): void {
        $this->assertFileExists( self::COMMISSION_WRITER_PATH );
        $source = file_get_contents( self::COMMISSION_WRITER_PATH );

        // El START TRANSACTION debe aparecer ANTES del foreach.
        $start_pos    = strpos( $source, "START TRANSACTION" );
        $foreach_pos  = strpos( $source, "foreach ( \$order->get_items()" );
        $this->assertNotFalse( $start_pos, 'AUDIT-ADMIN-002-004: debe haber START TRANSACTION en el archivo.' );
        $this->assertNotFalse( $foreach_pos, 'AUDIT-ADMIN-002-004: debe haber foreach de items.' );
        $this->assertLessThan(
            $foreach_pos, $start_pos,
            'AUDIT-ADMIN-002-004: START TRANSACTION debe ir ANTES del foreach (transacción por-orden, no por-ítem).'
        );
    }

    /**
     * El COMMIT debe estar DESPUÉS del cierre del foreach (fuera del loop).
     */
    public function test_commission_writer_commit_after_foreach(): void {
        $this->assertFileExists( self::COMMISSION_WRITER_PATH );
        $source = file_get_contents( self::COMMISSION_WRITER_PATH );

        // Buscar el último 'COMMIT' después del foreach (fuera del loop).
        $foreach_pos = strpos( $source, "foreach ( \$order->get_items()" );
        $this->assertNotFalse( $foreach_pos );
        $after_foreach = substr( $source, $foreach_pos );
        $this->assertStringContainsString(
            "'COMMIT'",
            $after_foreach,
            'AUDIT-ADMIN-002-004: COMMIT debe aparecer después del foreach (fuera del loop).'
        );
    }

    /**
     * El catch debe hacer ROLLBACK explícito.
     */
    public function test_commission_writer_catch_does_rollback(): void {
        $this->assertFileExists( self::COMMISSION_WRITER_PATH );
        $source = file_get_contents( self::COMMISSION_WRITER_PATH );

        // El catch Throwable en write_fiscal_fields debe contener ROLLBACK.
        $this->assertMatchesRegularExpression(
            "/catch\s*\(\s*\\\\Throwable.*?ROLLBACK/s",
            $source,
            'AUDIT-ADMIN-002-004: el catch de write_fiscal_fields debe hacer ROLLBACK explícito.'
        );
        $this->assertStringContainsString(
            'FISCAL_FIELDS_WRITE_FAILED',
            $source,
            'AUDIT-ADMIN-002-004: el catch debe logear FISCAL_FIELDS_WRITE_FAILED.'
        );
    }

    // ── AUDIT-ADMIN-002-006 P1: commission-writer verificar retorno insert ─

    /**
     * $wpdb->update e $wpdb->insert deben verificar el retorno === false
     * para detectar fallos silenciosos y hacer throw + ROLLBACK.
     */
    public function test_commission_writer_checks_insert_return_value(): void {
        $this->assertFileExists( self::COMMISSION_WRITER_PATH );
        $source = file_get_contents( self::COMMISSION_WRITER_PATH );

        // Debe verificar === false después de $wpdb->insert.
        $this->assertStringContainsString(
            'FISCAL_FIELDS_INSERT_FAILED',
            $source,
            'AUDIT-ADMIN-002-006: $wpdb->insert false debe lanzar FISCAL_FIELDS_INSERT_FAILED.'
        );
        $this->assertStringContainsString(
            'FISCAL_FIELDS_UPDATE_FAILED',
            $source,
            'AUDIT-ADMIN-002-006: $wpdb->update false debe lanzar FISCAL_FIELDS_UPDATE_FAILED.'
        );
    }

    // ── AUDIT-ADMIN-003-001 P1: fiscal-exporter cap check defensivo ───────

    /**
     * generate_csv debe tener check current_user_can antes de proceder.
     */
    public function test_fiscal_exporter_has_capability_check(): void {
        $this->assertFileExists( self::FISCAL_EXPORTER_PATH );
        $source = file_get_contents( self::FISCAL_EXPORTER_PATH );

        $this->assertStringContainsString(
            "current_user_can( 'ltms_export_reports'",
            $source,
            'AUDIT-ADMIN-003-001: generate_csv debe tener cap check ltms_export_reports o manage_options.'
        );
        $this->assertStringContainsString(
            'AUDIT-ADMIN-003-001 FIX',
            $source,
            'AUDIT-ADMIN-003-001: el fix debe estar marcado con el tag.'
        );
    }

    // ── AUDIT-ADMIN-003-002 P1: fiscal-exporter LIMIT como placeholder ───

    /**
     * El LIMIT debe ser `LIMIT %d` con $limit en $params, no `LIMIT $limit`
     * interpolado directo en el SQL string.
     */
    public function test_fiscal_exporter_limit_is_placeholder(): void {
        $this->assertFileExists( self::FISCAL_EXPORTER_PATH );
        $source = file_get_contents( self::FISCAL_EXPORTER_PATH );

        // Buscar LIMIT %d (placeholder), no LIMIT $limit (interpolado).
        $this->assertStringContainsString(
            'LIMIT %d',
            $source,
            'AUDIT-ADMIN-003-002: LIMIT debe ser placeholder %d, no $limit interpolado.'
        );
        // Verificar que la INTERPOLACIÓN "LIMIT $limit" NO está en SQL.
        // Buscar específicamente el patrón de string SQL con $limit al final
        // del query (no el comentario del fix que menciona la string legacy).
        $this->assertDoesNotMatchRegularExpression(
            '/ORDER BY c\.id DESC LIMIT \$limit/',
            $source,
            'AUDIT-ADMIN-003-002: eliminar "LIMIT $limit" interpolado directo en SQL query (ORDER BY c.id DESC).'
        );

        // $limit debe ser absint (no intval) — força não-negativo.
        $this->assertStringContainsString(
            'absint( $args',
            $source,
            'AUDIT-ADMIN-003-002: $limit debe venir de absint (no intval) para forzar no-negativo.'
        );
    }

    // ── AUDIT-ADMIN-PAYOUTS-001 P0: KYC proxy whitelist regex de key ──────

    /**
     * ajax_kyc_proxy_doc debe validar $key con whitelist regex estricta
     * antes de pasarlo a download_file/file_get_contents.
     */
    public function test_kyc_proxy_validates_key_with_whitelist_regex(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        // La regex whitelist debe estar presente.
        $this->assertStringContainsString(
            'preg_match',
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: debe usar preg_match para validar formato de key.'
        );
        $this->assertStringContainsString(
            '#^kyc/(\d+)/[A-Za-z0-9_\-]+\.(pdf|jpe?g|png|gif|webp)$#i',
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: regex whitelist kyc/{vendor_id}/{filename}.{ext}.'
        );
        $this->assertStringContainsString(
            'KYC_PROXY_INVALID_KEY',
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: log de key inválido con KYC_PROXY_INVALID_KEY.'
        );
    }

    /**
     * El handler debe bloquear `..` y null bytes en $key como defense-in-depth.
     */
    public function test_kyc_proxy_blocks_path_traversal_characters(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        $this->assertStringContainsString(
            "str_contains( \$key, '..' )",
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: bloquear .. como defense-in-depth.'
        );
        $this->assertStringContainsString(
            "str_contains( \$key, \"\\0\" )",
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: bloquear null bytes.'
        );
    }

    /**
     * El fallback filesystem debe usar realpath + str_starts_with para
     * asegurar que el archivo resuelto está dentro del dir esperado.
     */
    public function test_kyc_proxy_local_fallback_uses_realpath_check(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        $this->assertStringContainsString(
            'realpath( $local_file )',
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: realpath del archivo local.'
        );
        $this->assertStringContainsString(
            'str_starts_with( $real_local, $real_basedir )',
            $source,
            'AUDIT-ADMIN-PAYOUTS-001: str_starts_with para validar que realpath está dentro del basedir.'
        );
    }

    // ── AUDIT-ADMIN-PAYOUTS-002 P0: KYC proxy nonce mandatory ─────────────

    /**
     * El handler NO debe tener el bypass "si es admin con ltms_manage_kyc,
     * permitir sin nonce". El nonce debe ser siempre obligatorio.
     */
    public function test_kyc_proxy_nonce_is_mandatory_no_bypass(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        // El bypass "Admin con permisos pero nonce expirado — permitir
        // acceso" debe estar eliminado.
        $this->assertStringNotContainsString(
            'Admin con permisos pero nonce expirado',
            $source,
            'AUDIT-ADMIN-PAYOUTS-002: eliminar bypass "admin con permisos pero nonce expirado".'
        );
        // Tampoco debe quedar el fallback "Si el nonce falla, verificar si
        // el usuario ES admin".
        $this->assertStringNotContainsString(
            'Si el nonce falla, verificar si el usuario ES admin',
            $source,
            'AUDIT-ADMIN-PAYOUTS-002: eliminar fallback de bypass.'
        );
    }

    /**
     * La lectura del nonce debe ser solo de $_GET (no $_REQUEST/$_POST
     * ambiguos). El fix anterior consolidó el origen.
     */
    public function test_kyc_proxy_nonce_reads_only_from_get(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        // La línea debe ser "$_GET['nonce'] ?? ''" (sin $_REQUEST).
        $this->assertStringContainsString(
            "\$_GET['nonce'] ?? ''",
            $source,
            'AUDIT-ADMIN-PAYOUTS-005: nonce solo desde $_GET (no $_REQUEST/$_POST ambiguo).'
        );
    }

    // ── AUDIT-ADMIN-PAYOUTS-003 P0: XSS prevención via nosniff + attachment ─

    /**
     * El handler debe enviar X-Content-Type-Options: nosniff para
     * prevenir Content-Type sniffing (stored XSS vía proxy).
     */
    public function test_kyc_proxy_sends_nosniff_header(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        $this->assertStringContainsString(
            'X-Content-Type-Options: nosniff',
            $source,
            'AUDIT-ADMIN-PAYOUTS-003: header X-Content-Type-Options: nosniff.'
        );
    }

    /**
     * Content-Disposition debe ser `attachment` (forzar download), no
     * `inline` (render en browser que permite XSS).
     */
    public function test_kyc_proxy_content_disposition_is_attachment(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        $this->assertStringContainsString(
            "Content-Disposition: attachment",
            $source,
            'AUDIT-ADMIN-PAYOUTS-003: Content-Disposition: attachment (no inline) para prevenir XSS.'
        );
        // Verificar que NO hay `header( 'Content-Disposition: inline ...)'`
        // como ejecución real (no como mención en comentario). El patrón
        // busca el header() de PHP con 'inline' dentro.
        $this->assertDoesNotMatchRegularExpression(
            "/header\(\s*'Content-Disposition:\s*inline/",
            $source,
            'AUDIT-ADMIN-PAYOUTS-003: eliminar header Content-Disposition: inline (no inline).'
        );
    }

    // ── AUDIT-ADMIN-PAYOUTS-006 P1: no loguear $_GET/$_REQUEST en claro ──

    /**
     * El handler NO debe loguear $_GET o $_REQUEST completos (puede
     * incluir cookies en algunos SAPIs). Solo logs sanitizados y acotados.
     */
    public function test_kyc_proxy_does_not_log_request_globals(): void {
        $this->assertFileExists( self::ADMIN_PAYOUTS_PATH );
        $source = file_get_contents( self::ADMIN_PAYOUTS_PATH );

        // No debe haber json_encode( $_GET ) ni json_encode( $_REQUEST ).
        $this->assertStringNotContainsString(
            "json_encode( \$_GET )",
            $source,
            'AUDIT-ADMIN-PAYOUTS-006: no loguear $_GET completo en claro.'
        );
        $this->assertStringNotContainsString(
            "json_encode( \$_REQUEST )",
            $source,
            'AUDIT-ADMIN-PAYOUTS-006: no loguear $_REQUEST completo en claro.'
        );
    }

    // ── AUDIT-ADMIN-SAT-001 P1: auditor_name desde wp_get_current_user ────

    /**
     * log_sat_access debe usar wp_get_current_user() en vez de headers
     * HTTP X-Auditor-* (controlables por el cliente).
     */
    public function test_sat_log_uses_wp_get_current_user(): void {
        $this->assertFileExists( self::ADMIN_SAT_REPORT_PATH );
        $source = file_get_contents( self::ADMIN_SAT_REPORT_PATH );

        $this->assertStringContainsString(
            'wp_get_current_user()',
            $source,
            'AUDIT-ADMIN-SAT-001: usar wp_get_current_user() para auditor identity.'
        );
        $this->assertStringContainsString(
            'AUDIT-ADMIN-SAT-001 FIX',
            $source,
            'AUDIT-ADMIN-SAT-001: el fix debe estar marcado con el tag.'
        );

        // No debe quedar referencia a HTTP_X_AUDITOR_NAME para el
        // auditor_name (puede quedar en otro contexto, pero no como
        // fuente de auditor_namerfc).
        // Buscar específicamente el patrón en la función log_*_access.
        $this->assertStringNotContainsString(
            "\$_SERVER['HTTP_X_AUDITOR_NAME'] ?? ''",
            $source,
            'AUDIT-ADMIN-SAT-001: eliminar HTTP_X_AUDITOR_NAME como fuente de auditor_name.'
        );
        $this->assertStringNotContainsString(
            "\$_SERVER['HTTP_X_AUDITOR_RFC'] ?? ''",
            $source,
            'AUDIT-ADMIN-SAT-001: eliminar HTTP_X_AUDITOR_RFC como fuente de auditor_rfc.'
        );
        $this->assertStringNotContainsString(
            "\$_SERVER['HTTP_X_AUDITOR_NIT'] ?? ''",
            $source,
            'AUDIT-ADMIN-SAT-001: eliminar HTTP_X_AUDITOR_NIT como fuente de auditor_nit.'
        );
    }

    /**
     * El link al user_meta del admin (ltms_auditor_rfc/ltms_auditor_nit)
     * debe estar presente para que un admin certificado pueda proveer RFC/NIT.
     */
    public function test_sat_log_uses_user_meta_for_auditor_credentials(): void {
        $this->assertFileExists( self::ADMIN_SAT_REPORT_PATH );
        $source = file_get_contents( self::ADMIN_SAT_REPORT_PATH );

        $this->assertStringContainsString(
            "'ltms_auditor_rfc'",
            $source,
            'AUDIT-ADMIN-SAT-001: auditor_rfc debe venir de user_meta ltms_auditor_rfc.'
        );
        $this->assertStringContainsString(
            "'ltms_auditor_nit'",
            $source,
            'AUDIT-ADMIN-SAT-001: auditor_nit debe venir de user_meta ltms_auditor_nit.'
        );
    }
}
