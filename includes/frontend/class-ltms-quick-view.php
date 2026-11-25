<?php
/**
 * LTMS Quick View — Botón "Vista rápida" del loop de productos.
 *
 * E2E-BUYER-QV-BROKEN-BTN (2026-11-24): la clase ya no tiene handler AJAX
 * propio. Desde v2.9.49 el handler ajax_load_product estaba sin registrar
 * (duplicado de LTMS_Frontend_Checkout_Handler::ajax_quick_view) y fue
 * eliminado; el modal vivo es ltms_pv_quick_view (LTMS_Native_Templates),
 * invocado vía el trigger canonical data-pv-quickview que este botón emite.
 *
 * @package LTMS
 * @version 2.9.436
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Quick_View {

    public static function init(): void {
        // Botón "Quick view" en el loop de productos (woocommerce_after_shop_loop_item).
        add_action( 'woocommerce_after_shop_loop_item', [ __CLASS__, 'render_quick_view_button' ], 15 );
    }

    /**
     * Renderiza el botón de Quick View en el loop de productos.
     *
     * E2E-BUYER-QV-BROKEN-BTN (2026-11-24): el botón legacy posteba con un nonce
     * por producto (ltms_quick_view_{id}) contra el handler vivo ltms_quick_view,
     * que exige ltms_ux_nonce — 100% de clicks recibían 403 y el modal JS legacy
     * esperaba response.data.html (contrato que el handler estructurado nunca
     * devolvió). Ahora emite el trigger canonical del design system
     * (data-pv-quickview → PV.quickView → ltms_pv_quick_view, nonce global
     * ltms_plaza_viva), mismo path de las cards content-product.php.
     */
    public static function render_quick_view_button(): void {
        global $product;
        if ( ! $product ) return;
        ?>
        <div class="ltms-quick-view-btn-wrap" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0;transition:opacity 0.3s;z-index:5;">
            <button type="button"
                    class="ltms-quick-view-btn"
                    data-pv-quickview="<?php echo esc_attr( $product->get_id() ); ?>"
                    title="<?php esc_attr_e( 'Vista rápida', 'ltms' ); ?>">
                <span class="ltms-quick-view-btn__icon">&#x1F441;</span>
                <span class="ltms-quick-view-btn__text"><?php esc_html_e( 'Vista rápida', 'ltms' ); ?></span>
            </button>
        </div>
        <?php
    }
}
