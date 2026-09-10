<?php
/**
 * DeadCodeVendorStoreTest — DEAD-CODE (2026-09-10).
 *
 * La plantilla vendor-store.php (design system "Plaza Viva", @since 3.0.0) era
 * código muerto no conectado: su wiring en LTMS_Native_Templates estaba
 * deshabilitado desde 2026-07-18 (register_rewrites/register_query_vars
 * comentados por "causing shop page crash") y el CPT 'ltms_vendor_store' +
 * query var 'ltms_page' nunca se registraron, por lo que is_vendor_store_page()
 * retornaba siempre false.
 *
 * Fix (decisión de producto: "eliminar wiring muerto + documentar"):
 * - Se eliminaron register_rewrites(), register_query_vars(), is_vendor_store_page()
 *   y las ramas inalcanzables de vendor-store.php en maybe_override().
 * - Se eliminaron las condiciones muertas get_query_var('ltms_page') de
 *   is_order_tracking_page()/is_help_page() (se sirven vía is_page()).
 * - vendor-store.php se conserva como "declared, awaiting wiring" (referencia de
 *   paridad CSP/design system + ~6 suites de test la leen como gold standard).
 *
 * Tests puramente estructurales (file_get_contents + asserts), sin carga de
 * clases del plugin ni invocación WP — deterministas en LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class DeadCodeVendorStoreTest
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --group audit-deadcode
 *
 * @group audit-deadcode
 */
final class DeadCodeVendorStoreTest extends LTMS_Unit_Test_Case {

    /**
     * Ruta al router nativo del design system Plaza Viva.
     */
    private const NATIVE_PATH = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';

    /**
     * Ruta a la plantilla vendor-store.php (conservada como awaiting wiring).
     */
    private const VENDOR_STORE_PATH = __DIR__ . '/../../includes/frontend/templates/vendor-store.php';

    /**
     * DEAD-CODE: el wiring muerto del vendor-store (métodos register_rewrites /
     * register_query_vars / is_vendor_store_page) fue eliminado del router.
     */
    public function test_native_templates_removed_dead_vendor_store_wiring(): void {
        $src = file_get_contents( self::NATIVE_PATH );

        // Se verifica la DECLARACIÓN del método (no el comentario DEAD-CODE que
        // documenta su eliminación y sí menciona los nombres).
        $this->assertStringNotContainsString(
            'public static function register_rewrites',
            $src,
            'DEAD-CODE: class-ltms-native-templates.php must NOT declare register_rewrites() (dead wiring removed)'
        );
        $this->assertStringNotContainsString(
            'public static function register_query_vars',
            $src,
            'DEAD-CODE: class-ltms-native-templates.php must NOT declare register_query_vars() (dead wiring removed)'
        );
        $this->assertStringNotContainsString(
            'function is_vendor_store_page',
            $src,
            'DEAD-CODE: class-ltms-native-templates.php must NOT declare is_vendor_store_page() (always-false predicate removed)'
        );
        $this->assertStringNotContainsString(
            'is_singular( \'ltms_vendor_store\' )',
            $src,
            'DEAD-CODE: the never-registered CPT ltms_vendor_store check must be removed'
        );
    }

    /**
     * DEAD-CODE: las condiciones get_query_var('ltms_page') (inalcanzables, query
     * var nunca registrado) fueron eliminadas de is_order_tracking_page() y
     * is_help_page(). order-tracking / help-center se sirven vía is_page().
     */
    public function test_native_templates_removed_dead_ltms_page_query_var(): void {
        $src = file_get_contents( self::NATIVE_PATH );

        $this->assertStringNotContainsString(
            "get_query_var( 'ltms_page' )",
            $src,
            'DEAD-CODE: class-ltms-native-templates.php must NOT reference the never-registered ltms_page query var'
        );
    }

    /**
     * DEAD-CODE: la traza del fix debe quedar presente en el router para que una
     * auditoría futura entienda que la ausencia de vendor-store en maybe_override()
     * es intencional (no una regresión), y que vendor-store.php es "awaiting wiring".
     */
    public function test_native_templates_carries_dead_code_fix_marker(): void {
        $src = file_get_contents( self::NATIVE_PATH );

        $this->assertStringContainsString(
            'DEAD-CODE FIX',
            $src,
            'DEAD-CODE: class-ltms-native-templates.php must contain the traceable DEAD-CODE FIX marker'
        );
    }

    /**
     * DEAD-CODE (conservación): la plantilla vendor-store.php sigue existiendo y
     * está marcada explícitamente como "declared, awaiting wiring" (no se borró;
     * se mantiene por decisión de producto como referencia de paridad + gold
     * standard de otras suites de test).
     */
    public function test_vendor_store_template_kept_and_marked_awaiting_wiring(): void {
        $this->assertFileExists( self::VENDOR_STORE_PATH );
        $src = file_get_contents( self::VENDOR_STORE_PATH );

        $this->assertStringContainsString(
            'declared, awaiting wiring',
            $src,
            'DEAD-CODE: vendor-store.php must be marked "declared, awaiting wiring" (kept by product decision)'
        );
        $this->assertStringContainsString(
            'DEAD-CODE (2026-09-10)',
            $src,
            'DEAD-CODE: vendor-store.php header must contain the traceable DEAD-CODE marker'
        );
    }
}