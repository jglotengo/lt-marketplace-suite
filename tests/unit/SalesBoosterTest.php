<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for LTMS_Sales_Booster — viewer count (PDP) rendering.
 *
 * REMOVE-PROMO-POPUP-001 FIX (2026-09-04): los toasts de social proof
 * ("X compró Y", container #ltms-social-proof-container + AJAX
 * ltms_get_social_proof) se eliminaron a petición del negocio. El CSS de
 * v2.9.278 intentó ocultarlos con selectores de clase que no matcheaban el
 * markup real (el container usa un ID y los toasts la clase .ltms-toast
 * excluida explícitamente). Este test verifica que el render de la feature
 * conservada (viewer count) NO reintroduce el toast ni su AJAX.
 */
final class SalesBoosterTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Sales_Booster' );

        // render_viewer_count() usa estas además de las ya stubbeadas en la
        // clase base (esc_js, is_admin).
        Monkey\Functions\stubs( [
            'esc_html_e'  => static function ( $text ) { echo $text; },
            'is_product'  => false, // fuera de PDP: se omite el bloque JS de viewer count.
        ] );
    }

    public function test_viewer_count_renders_container(): void {
        ob_start();
        \LTMS_Sales_Booster::render_viewer_count();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'id="ltms-viewer-count"', $html );
        $this->assertStringContainsString( 'id="ltms-viewer-count-num"', $html );
        // El JS de tracking solo se emite en PDP (test_viewer_count_js_sends_nonce_on_pdp).
    }

    public function test_social_proof_toast_removed_from_render(): void {
        ob_start();
        \LTMS_Sales_Booster::render_viewer_count();
        $html = ob_get_clean();

        // REMOVE-PROMO-POPUP-001 FIX: ni el container de toasts, ni el AJAX
        // de social proof, ni los estilos de toast pueden existir en el render.
        $this->assertStringNotContainsString( 'ltms-social-proof-container', $html );
        $this->assertStringNotContainsString( 'ltms_get_social_proof', $html );
        $this->assertStringNotContainsString( 'ltms-toast-styles', $html );
        $this->assertStringNotContainsString( 'Compra verificada', $html );
    }

    public function test_viewer_count_js_sends_nonce_on_pdp(): void {
        // En PDP (is_product=true) el JS del viewer count debe seguir enviando
        // el nonce (CICLO29-P1-SB-002 FIX): handler ajax_track_product_view
        // es fail-closed contra ltms_ux_nonce.
        Monkey\Functions\stubs( [
            'is_product' => true,
            'get_the_ID' => static fn(): int => 42,
        ] );

        ob_start();
        \LTMS_Sales_Booster::render_viewer_count();
        $html = ob_get_clean();

        $this->assertStringContainsString(
            "{ action: 'ltms_track_product_view', nonce: spNonce, product_id: productId }",
            $html
        );
        // No debe reaparecer el patrón sin nonce.
        $this->assertStringNotContainsString(
            "{ action: 'ltms_track_product_view', product_id: productId }",
            $html
        );
    }
}
