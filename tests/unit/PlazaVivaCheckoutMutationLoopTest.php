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

    /**
     * CHECKOUT-HANG-HEADINGS FIX (2026-09-17): los headings de billing/shipping
     * se reescribían con .textContent en CADA llamada a fixFieldLabels() SIN
     * guard. Asignar .textContent SIEMPRE dispara una mutación childList en el
     * observer (aunque el string sea idéntico) → fixFieldLabels → textContent →
     * bucle infinito de 5s (hasta disconnect) que Chrome reporta como "la página
     * no responde". El guard comparativo solo escribe si el texto cambió.
     */
    public function test_heading_textcontent_has_comparative_guard(): void {
        $src = (string) file_get_contents( self::JS_PATH );
        $this->assertStringContainsString(
            'billingHeading.textContent !== PV.i18n.billingHeading',
            $src,
            'billingHeading DEBE comparar el texto antes de asignar .textContent (evita la mutación en cada llamada)'
        );
        $this->assertStringContainsString(
            'shippingHeading.textContent !== PV.i18n.shippingHeadingAlt',
            $src,
            'shippingHeading DEBE comparar el texto antes de asignar .textContent'
        );
    }

    /**
     * El guard comparativo de los headings DEBE estar evaluado dentro del mismo
     * if que asigna textContent (mismo bloque), no en un if separado después.
     */
    public function test_heading_guard_is_inline_with_assign(): void {
        $src = (string) file_get_contents( self::JS_PATH );

        $this->assertMatchesRegularExpression(
            '/billingHeading\.textContent !== PV\.i18n\.billingHeading\s*\)\s*\{\s*billingHeading\.textContent = PV\.i18n\.billingHeading;/',
            $src,
            'el guard comparativo de billingHeading DEBE estar en la condición del if que asigna textContent'
        );
        $this->assertMatchesRegularExpression(
            '/shippingHeading\.textContent !== PV\.i18n\.shippingHeadingAlt\s*\)\s*\{\s*shippingHeading\.textContent = PV\.i18n\.shippingHeadingAlt;/',
            $src,
            'el guard comparativo de shippingHeading DEBE estar en la condición del if que asigna textContent'
        );
    }

    /**
     * El min desplegado incluye el guard comparativo de los headings (producción
     * sirve el .min, no el src). NOTA: terser manglea los nombres de variables
     * locales (billingHeading→e, shippingHeading→a), así que el assert valida el
     * patrón que sobrevive al mangle: `.textContent!==PV.i18n.<clave>`.
     */
    public function test_min_contains_heading_guard(): void {
        if ( ! file_exists( self::MIN_PATH ) ) {
            $this->markTestSkipped( 'ltms-plaza-viva.min.js no existe' );
        }
        $min = (string) file_get_contents( self::MIN_PATH );
        $this->assertMatchesRegularExpression(
            '/\.textContent!==PV\.i18n\.billingHeading/',
            $min,
            'el .min desplegado DEBE incluir la comparación de billingHeading antes de asignar'
        );
        $this->assertMatchesRegularExpression(
            '/\.textContent!==PV\.i18n\.shippingHeadingAlt/',
            $min,
            'el .min desplegado DEBE incluir la comparación de shippingHeading antes de asignar'
        );
    }
}