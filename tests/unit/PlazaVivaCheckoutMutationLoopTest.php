<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Regresión: bucle infinito del MutationObserver en el scope CHECKOUT de
 * ltms-plaza-viva.js que bloqueaba el hilo principal del navegador.
 *
 * CHECKOUT-HANG-MUTATION FIX (2026-09-17).
 *
 * Causa: `fixFieldLabels()` reescribía los labels del checkout (innerHTML='' +
 * appendChild) en CADA llamada SIN guard idempotente. El MutationObserver
 * (childList+subtree+characterData, plaza-viva.js:1570) llamaba a
 * fixFieldLabels() en cada mutación → el rewrite disparaba una nueva mutación
 * → loop infinito → hilo principal saturado → Chrome muestra "la página no
 * responde, esperar o salir" y el formulario queda sin poder diligenciar.
 *
 * Fix: guard `data-ltms-label-fixed` (mismo patrón que ya usaba
 * ltms-checkout-fixes.js) para que solo se reescriba el label la primera vez.
 *
 * Este es un test source-based: lee el archivo JS real y verifica que el guard
 * esté presente, que el observer siga desconectándose tras un timeout, y que el
 * min desplegado también lo incluya.
 */
final class PlazaVivaCheckoutMutationLoopTest extends LTMS_Unit_Test_Case {

    private const JS_PATH = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';
    private const MIN_PATH = __DIR__ . '/../../assets/js/ltms-plaza-viva.min.js';

    /**
     * El guard idempotente del label existe en el source (corta el loop).
     */
    public function test_label_fix_guard_is_idempotent(): void {
        $src = (string) file_get_contents( self::JS_PATH );
        $this->assertStringContainsString(
            "labelEl.getAttribute('data-ltms-label-fixed') === '1'",
            $src,
            'fixFieldLabels() DEBE re-checkear data-ltms-label-fixed antes de reescribir el label'
        );
        $this->assertStringContainsString(
            "labelEl.setAttribute('data-ltms-label-fixed', '1')",
            $src,
            'fixFieldLabels() DEBE marcar data-ltms-label-fixed tras el primer rewrite'
        );
    }

    /**
     * El guard aparece ANTES del rewrite del label (innerHTML='' + appendChild),
     * de modo que las llamadas subsecuentes del observer no mutan el DOM.
     */
    public function test_guard_precedes_dom_rewrite(): void {
        $src = (string) file_get_contents( self::JS_PATH );

        $guardIdx = strpos( $src, "getAttribute('data-ltms-label-fixed')" );
        $rewriteIdx = strpos( $src, "labelEl.innerHTML = ''" );

        $this->assertNotFalse( $guardIdx, 'el guard debe existir' );
        $this->assertNotFalse( $rewriteIdx, 'el rewrite del label debe existir' );
        $this->assertLessThan(
            $rewriteIdx,
            $guardIdx,
            'el guard data-ltms-label-fixed DEBE preceder al innerHTML="" (early-return antes de mutar)'
        );
    }

    /**
     * El MutationObserver sigue teniendo timeout de desconexión (5s) como
     * cortafuego de seguridad adicional — no se debe eliminar.
     */
    public function test_mutation_observer_keeps_disconnect_timeout(): void {
        $src = (string) file_get_contents( self::JS_PATH );
        $this->assertStringContainsString(
            'observer.disconnect()',
            $src,
            'el observer DEBE desconectarse tras un timeout (cortafuego)'
        );
    }

    /**
     * El min desplegado incluye el guard (producción servía el .min, no el src).
     */
    public function test_min_contains_guard(): void {
        if ( ! file_exists( self::MIN_PATH ) ) {
            $this->markTestSkipped( 'ltms-plaza-viva.min.js no existe' );
        }
        $min = (string) file_get_contents( self::MIN_PATH );
        $this->assertStringContainsString(
            'data-ltms-label-fixed',
            $min,
            'el .min desplegado DEBE incluir data-ltms-label-fixed'
        );
    }

    /**
     * El observer observa childList+subtree (necesario para WOOCCM) pero NUNCA
     * observa 'attributes' — el guard del label es el freno real del loop.
     */
    public function test_observer_config_matches_fix_contract(): void {
        $src = (string) file_get_contents( self::JS_PATH );
        $this->assertStringContainsString(
            '{ childList: true, subtree: true, characterData: true }',
            $src,
            'la config del observer no debe cambiar sin revisar el contrato del fix'
        );
    }
}