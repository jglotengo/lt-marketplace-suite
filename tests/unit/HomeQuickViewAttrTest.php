<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Regression test for AUDIT-FE-HOME-001 (Fase 1.2) + AUDIT-FE-AP-002 (Fase 1.5).
 *
 * El botón de quick-view en home.php emitia SOLO el atributo
 * `data-pv-quick-view` (CON guion), pero ltms-plaza-viva.js:577 escucha
 * `[data-pv-quickview]` (SIN guion). El resultado: el botón era visible
 * pero el listener nunca disparaba → quick view muerto en home.
 *
 * ORIGINAL FIX (AUDIT-FE-HOME-001, Fase 1.2): se añadieron AMBOS atributos
 * (data-pv-quick-view= + data-pv-quickview=) como puente legacy.
 *
 * RE-AUDIT FIX (AUDIT-FE-AP-002, Fase 1.5): el atributo `data-pv-quick-view`
 * (con guion) NO es legible vía DOM API estándar (`dataset.pvQuickView` no
 * mapea a este nombre) ni via getAttribute en ningún código del plugin.
 * Era atributo muerto/confuso. Se eliminó de home.php, vendor-store.php y
 * wc-parts/content-product.php. Estándar canonical: solo `data-pv-quickview`
 * (sin guion interno). Esta suite se actualiza en el MISMO commit (lección
 * #119 del AGENTS.md: test huérfano que rompe suite futura no relacionada).
 *
 * No ejecutamos el template (necesitaría WP) — validamos fuente del archivo.
 */
final class HomeQuickViewAttrTest extends LTMS_Unit_Test_Case {

    private const HOME_TEMPLATE       = __DIR__ . '/../../includes/frontend/templates/home.php';
    private const VENDOR_STORE_TPL    = __DIR__ . '/../../includes/frontend/templates/vendor-store.php';
    private const CONTENT_PRODUCT_TPL = __DIR__ . '/../../includes/frontend/templates/wc-parts/content-product.php';

    /**
     * La card de quick-view en home debe tener SOLO `data-pv-quickview` (sin guion).
     * AUDIT-FE-AP-002: el atributo CON guion (`data-pv-quick-view=`) fue removido.
     */
    public function test_home_template_quick_view_button_emits_canonical_attr(): void {
        $this->assertFileExists( self::HOME_TEMPLATE );
        $source = file_get_contents( self::HOME_TEMPLATE );

        // Atributo canonical (sin guion) — DEBE estar presente.
        $this->assertStringContainsString(
            'data-pv-quickview=',
            $source,
            'home.php must emit data-pv-quickview (sin guion) — JS lo lee en ltms-plaza-viva.js:603'
        );

        // AUDIT-FE-AP-002: el atributo legacy CON guion DEBE estar ausente.
        $this->assertStringNotContainsString(
            'data-pv-quick-view=',
            $source,
            'AUDIT-FE-AP-002 (Fase 1.5): el atributo data-pv-quick-view (con guion) fue eliminado porque era muerto (ningun JS lo lee)'
        );
    }

    /**
     * AUDIT-FE-AP-002 (Fase 1.5): el mismo estándar se aplica a vendor-store.php
     * y wc-parts/content-product.php para consistencia del design system.
     */
    public function test_vendor_store_and_content_product_quick_view_attr_canonical(): void {
        foreach ( [ 'vendor-store.php' => self::VENDOR_STORE_TPL, 'content-product.php' => self::CONTENT_PRODUCT_TPL ] as $label => $path ) {
            $this->assertFileExists( $path, "$label debe existir" );
            $src = file_get_contents( $path );

            $this->assertStringContainsString(
                'data-pv-quickview=',
                $src,
                "$label debe emitir data-pv-quickview (sin guion) para que el JS del design system lo lea"
            );

            $this->assertStringNotContainsString(
                'data-pv-quick-view=',
                $src,
                "AUDIT-FE-AP-002: $label no debe emitir data-pv-quick-view (con guion) — atributo muerto"
            );
        }
    }

    /**
     * Regresión del search-chip: garantiza que el atributo data-pv-search-chip
     * sigue presente (AUDIT-FE-HOME-003 añade listener en ltms-plaza-viva.js).
     */
    public function test_home_template_has_search_chip_attr(): void {
        $source = file_get_contents( self::HOME_TEMPLATE );
        $this->assertStringContainsString(
            'data-pv-search-chip',
            $source,
            'home.php must keep data-pv-search-chip attr (chips populares)'
        );
    }
}
