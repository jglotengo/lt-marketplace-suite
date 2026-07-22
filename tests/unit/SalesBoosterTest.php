<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Unit tests for LTMS_Sales_Booster — social proof AJAX bootstrap.
 *
 * FIX 403-SOCIALPROOF: ajax_get_social_proof() exige check_ajax_referer()
 * contra 'ltms_ux_nonce' desde v2.9.100 (SEC-3), pero el $.post() inline
 * en render_social_proof_container() nunca mandaba el campo `nonce` —
 * cada tick del polling (cada SOCIAL_PROOF_INTERVAL segundos, en TODA
 * página pública) fallaba con 403 "Token inválido". Este test verifica
 * que el HTML/JS renderizado sí incluye el nonce, para evitar que la
 * regresión vuelva a colarse en un refactor futuro del template inline.
 */
final class SalesBoosterTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        $this->require_class( '\LTMS_Sales_Booster' );

        // render_social_proof_container() usa estas dos además de las ya
        // stubbeadas en la clase base (esc_js, is_admin).
        Monkey\Functions\stubs( [
            'esc_html_e'  => static function ( $text ) { echo $text; },
            'is_product'  => false, // fuera de PDP: se omite el bloque de viewer count.
        ] );
    }

    public function test_social_proof_ajax_call_includes_nonce(): void {
        ob_start();
        \LTMS_Sales_Booster::render_social_proof_container();
        $html = ob_get_clean();

        // El payload del $.post debe incluir un campo nonce leído de
        // window.ltmsUX.nonce (localizado globalmente en frontend-assets).
        $this->assertStringContainsString(
            "action: 'ltms_get_social_proof', nonce: spNonce",
            $html
        );
        $this->assertStringContainsString(
            'window.ltmsUX && window.ltmsUX.nonce',
            $html
        );
    }

    /**
     * Regresión directa: la llamada original sin nonce no debe reaparecer.
     */
    public function test_social_proof_ajax_call_is_not_missing_nonce(): void {
        ob_start();
        \LTMS_Sales_Booster::render_social_proof_container();
        $html = ob_get_clean();

        $this->assertStringNotContainsString(
            "{ action: 'ltms_get_social_proof' }",
            $html
        );
    }
}
