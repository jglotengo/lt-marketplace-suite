<?php
/**
 * Tests estructurales de CSP-compliance en plantillas públicas de LTMS.
 *
 * Foco actual: AUDIT-FE-VS-JT-001 (Fase 1.4 backlog closure).
 * - vendor-store.php debe tener cero <script> inline.
 * - ltms-plaza-viva.js debe contener el handler data-pv-jump-tab migrado.
 *
 * Estos tests son PURAMENTE estructurales (lectura del filesystem + asserts
 * sobre el contenido). NO cargan clases del plugin ni invocan funciones WP,
 * por lo que corren en modo LTMS_UNIT_ONLY=true sin skipping. Esto los hace
 * deterministas en CI Ubuntu/local independientemente del classmap estático
 * del autoloader de Composer.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class VendorStoreCspTest
 *
 * Valida que la migración AUDIT-FE-VS-JT-001 (handler data-pv-jump-tab de
 * vendor-store.php al design system ltms-plaza-viva.js) quedó aplicada y
 * que la plantilla ya NO contiene NINGÚN bloque <script> inline. La她在tes
 * de CSP require-style on Firefox/Chrome reject cualquier script-inline
 * no-listado explícitamente en la directive `script-src 'unsafe-inline'`,
 * y la migración cierra esta excepción.
 */
final class VendorStoreCspTest extends LTMS_Unit_Test_Case {

    /**
     * Ruta absoluta a la plantilla vendor-store.php.
     */
    private string $template_path;

    /**
     * Ruta absoluta al design system ltms-plaza-viva.js.
     */
    private string $js_path;

    protected function setUp(): void {
        parent::setUp();
        // NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
        // tests son puramente de filesystem (file_get_contents + asserts),
        // por lo que NO dependen del classmap estático de Composer. Esto
        // los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
        // Ubuntu — el order de tests no los afecta porque no cargan clases.
        $this->template_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php';
        $this->js_path      = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
    }

    /**
     * AUDIT-FE-VS-JT-001: vendor-store.php ya NO contiene NINGÚN bloque
     * <script> inline. Antes de este fix, vendor-store.php tenía dos bloques
     * inline (uno para follow-vendor migrado en 43a2da5b, otro para jump-tab
     * que se migra ahora). Tras el fix, la plantilla queda 100% CSP-compliant.
     *
     * Adicionalmente este test detecta el bug de cierre que existía previamente:
     * un `</script>` duplicado (vendor-store.php:626+627 antes del fix) — residuo
     * de la migración previa del handler del follow que dejó un tag de cierre
     * sin su apertura. Ver `LECCIONES_APRENDIDAS.md` #137.
     */
    public function test_vendor_store_template_no_inline_scripts_at_all(): void {
        $this->assertFileExists( $this->template_path );
        $src = file_get_contents( $this->template_path );

        // (1) Cero <script> inline (CSP-compliance estricto en la plantilla).
        $this->assertStringNotContainsString(
            '<script',
            $src,
            'AUDIT-FE-VS-JT-001 fix: vendor-store.php must NOT contain any inline <script> tag (CSP-compliance)'
        );

        // (2) Cero </script> — adicionalmente detecta el bug del tag de cierre
        // duplicado que residía de la migración del handler del follow.
        $this->assertStringNotContainsString(
            '</script>',
            $src,
            'AUDIT-FE-VS-JT-001 fix: vendor-store.php must NOT contain any </script> closing tag — also detects the duplicate </script> bug that existed before the fix (LECCIONES #137)'
        );

        // (3) El botón HTML sigue existiendo (la migración fue del JS, no del HTML).
        $this->assertStringContainsString(
            'data-pv-jump-tab=',
            $src,
            'AUDIT-FE-VS-JT-001: vendor-store.php must keep the data-pv-jump-tab button HTML (only the JS handler was migrated)'
        );

        // (4) El handler JS inline previo (closure IIFE con var jump) fue eliminado.
        $this->assertStringNotContainsString(
            "var jump = e.target.closest('[data-pv-jump-tab]')",
            $src,
            'AUDIT-FE-VS-JT-001 fix: vendor-store.php must NOT contain the inline jump-tab JS block (the old IIFE closure)'
        );
    }

    /**
     * AUDIT-FE-VS-JT-001: ltms-plaza-viva.js contiene el handler del jump-tab
     * dentro del listener global de click (patrón delegado con closest),
     * alineado con los handlers data-pv-add-to-cart (línea A) y
     * data-pv-follow-vendor (línea B) ya existentes.
     *
     * Verifica los invariantes de comportamiento del handler migrado:
     * 1. Delega click sobre [data-pv-jump-tab] (patrón closest()).
     * 2. Hace query del tab destino por id `#pv-vendor-tab-X`.
     * 3. Dispara click programático en el tab (tabEl.click()).
     * 4. Hace scroll suave al panel destino `#pv-vendor-panel-X`.
     * 5. Contiene la traza de fix 'AUDIT-FE-VS-JT-001 FIX' para auditoría futura.
     */
    public function test_plaza_viva_js_has_jump_tab_handler(): void {
        $this->assertFileExists( $this->js_path );
        $src = file_get_contents( $this->js_path );

        // (1) Handler delegado en el listener global de click.
        $this->assertStringContainsString(
            "e.target.closest('[data-pv-jump-tab]')",
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must delegate click on data-pv-jump-tab (same pattern as data-pv-add-to-cart and data-pv-follow-vendor)'
        );

        // (2) Hace query del tab destino (#pv-vendor-tab-X).
        $this->assertStringContainsString(
            "querySelector('#pv-vendor-tab-'",
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must query the target tab element by id #pv-vendor-tab-X'
        );

        // (3) Dispara click programático en el tab destino.
        $this->assertStringContainsString(
            'tabEl.click()',
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must trigger a programmatic click on the target tab to switcher tabs'
        );

        // (4) Hace scroll suave al panel destino (#pv-vendor-panel-X).
        $this->assertStringContainsString(
            "getElementById('pv-vendor-panel-'",
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must locate the target panel element by id #pv-vendor-panel-X'
        );
        $this->assertStringContainsString(
            "scrollIntoView({ behavior: 'smooth', block: 'start' })",
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must smoothly scroll the target panel into view (reduced-motion safe skipped here — see handler defensive check)'
        );

        // (5) Marca de traza del fix para auditoría futura.
        $this->assertStringContainsString(
            'AUDIT-FE-VS-JT-001 FIX',
            $src,
            'AUDIT-FE-VS-JT-001: ltms-plaza-viva.js must contain the traceable fix marker comment for future audits'
        );
    }

    /**
     * AUDIT-FE-VS-JT-001 (re-audit, invariante de paridad): ltms-plaza-viva.js
     * mantiene el handler data-pv-follow-vendor YA MIGRADO en 43a2da5b. Este
     * test confirma que la migración nueva del jump-tab NO rompió el handler
     * previo del follow (re-audit de la propia Fase 1.4 como exige el loop
     * AUDIT→FIX→RE-AUDIT de AGENTS.md).
     */
    public function test_plaza_viva_js_still_has_follow_vendor_handler_unchanged(): void {
        $this->assertFileExists( $this->js_path );
        $src = file_get_contents( $this->js_path );

        // Handler del follow sigue intacto (migrado en 43a2da5b).
        $this->assertStringContainsString(
            "e.target.closest('[data-pv-follow-vendor]')",
            $src,
            'AUDIT-FE-VS-JT-001 re-audit: the follow-vendor handler (migrated in 43a2da5b) must still be present — JT migration must not touch it'
        );

        // La invocación PV.ajax('ltms_follow_vendor', ...) sigue intacta.
        $this->assertStringContainsString(
            "PV.ajax('ltms_follow_vendor'",
            $src,
            'AUDIT-FE-VS-JT-001 re-audit: PV.ajax call to ltms_follow_vendor must remain unchanged after JT migration'
        );
    }
}
