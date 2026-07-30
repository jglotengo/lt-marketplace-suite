<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Regression test for AUDIT-FE-HOME-001 (Fase 1.2).
 *
 * El botón de quick-view en home.php emitia SOLO el atributo
 * `data-pv-quick-view` (CON guion), pero ltms-plaza-viva.js:577 escucha
 * `[data-pv-quickview]` (SIN guion). El resultado: el botón era visible
 * pero el listener nunca disparaba → quick view muerto en home.
 *
 * Este test verifica que home.php ahora emite AMBOS atributos, igual que
 * wc-parts/content-product.php:143 y vendor-store.php:255-256. No
 * ejecutamos el template (necesitaría WP) — solo validamos el contenido
 * del archivo fuente.
 */
final class HomeQuickViewAttrTest extends LTMS_Unit_Test_Case {

    private const HOME_TEMPLATE = __DIR__ . '/../../includes/frontend/templates/home.php';

    /**
     * La card de quick-view en home debe tener AMBOS atributos.
     */
    public function test_home_template_quick_view_button_emits_both_attrs(): void {
        $this->assertFileExists( self::HOME_TEMPLATE );
        $source = file_get_contents( self::HOME_TEMPLATE );

        // El bloque quick-view button debe contener ambos attrs.
        $this->assertStringContainsString(
            'data-pv-quick-view=',
            $source,
            'home.php must retain data-pv-quick-view attribute (legacy compat)'
        );
        $this->assertStringContainsString(
            'data-pv-quickview=',
            $source,
            'home.php must ALSO emit data-pv-quickview (sin guion) — auditoria AUDIT-FE-HOME-001 fix'
        );
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
