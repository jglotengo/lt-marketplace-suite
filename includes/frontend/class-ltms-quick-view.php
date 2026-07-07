<?php
/**
 * LTMS Quick View — Vista rápida de producto en modal AJAX.
 *
 * Permite ver un producto completo (galería, precio, add-to-cart, descripción)
 * en un modal sin salir del grid de productos. AJAX-powered, sin recargar.
 *
 * @package LTMS
 * @version 2.9.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Quick_View {

    public static function init(): void {
        // v2.9.49 PERF: AJAX handler desactivado — estaba duplicado con
        // LTMS_Frontend_Checkout_Handler::ajax_quick_view() que es el que usa
        // el JS (formato estructurado {id,name,price,...}, no {html}).
        // add_action( 'wp_ajax_ltms_quick_view', [ __CLASS__, 'ajax_load_product' ] );
        // add_action( 'wp_ajax_nopriv_ltms_quick_view', [ __CLASS__, 'ajax_load_product' ] );

        // Botón "Quick view" en el loop de productos (woocommerce_after_shop_loop_item).
        add_action( 'woocommerce_after_shop_loop_item', [ __CLASS__, 'render_quick_view_button' ], 15 );
    }

    /**
     * Renderiza el botón de Quick View en el loop de productos.
     */
    public static function render_quick_view_button(): void {
        global $product;
        if ( ! $product ) return;
        ?>
        <div class="ltms-quick-view-btn-wrap" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0;transition:opacity 0.3s;z-index:5;">
            <button type="button"
                    class="ltms-quick-view-btn"
                    data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_quick_view_' . $product->get_id() ) ); ?>"
                    title="<?php esc_attr_e( 'Vista rápida', 'ltms' ); ?>">
                <span class="ltms-quick-view-btn__icon">&#x1F441;</span>
                <span class="ltms-quick-view-btn__text"><?php esc_html_e( 'Vista rápida', 'ltms' ); ?></span>
            </button>
        </div>
        <?php
    }

    /**
     * AJAX: carga el contenido del producto para el modal.
     */
    public static function ajax_load_product(): void {
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );

        if ( ! $product_id || ! wp_verify_nonce( $nonce, 'ltms_quick_view_' . $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido', 'ltms' ) ] );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Producto no encontrado', 'ltms' ) ] );
        }

        // Construir HTML del modal.
        ob_start();
        ?>
        <div class="ltms-quick-view-content" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <!-- Galería -->
            <div class="ltms-qv-gallery">
                <?php echo $product->get_image( 'large' ); // phpcs:ignore ?>
                <?php
                $gallery_ids = $product->get_gallery_image_ids();
                if ( ! empty( $gallery_ids ) ) : ?>
                    <div class="ltms-qv-thumbnails" style="display:flex;gap:6px;margin-top:8px;overflow-x:auto;">
                        <?php foreach ( array_slice( $gallery_ids, 0, 4 ) as $gid ) :
                            echo wp_get_attachment_image( $gid, 'thumbnail', false, [ 'style' => 'width:60px;height:60px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid #e5e7eb;' ] );
                        endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="ltms-qv-info">
                <h2 class="ltms-qv-title" style="margin:0 0 8px;font-size:20px;font-weight:700;">
                    <a href="<?php echo esc_url( $product->get_permalink() ); ?>" style="color:#1f2937;text-decoration:none;">
                        <?php echo esc_html( $product->get_name() ); ?>
                    </a>
                </h2>

                <div class="ltms-qv-rating" style="margin-bottom:8px;">
                    <?php echo wc_get_rating_html( $product->get_average_rating() ); // phpcs:ignore ?>
                    <span style="font-size:12px;color:#6b7280;margin-left:4px;">
                        (<?php echo esc_html( $product->get_rating_count() ); ?>)
                    </span>
                </div>

                <div class="ltms-qv-price" style="font-size:22px;font-weight:700;color:#16a34a;margin-bottom:12px;">
                    <?php echo $product->get_price_html(); // phpcs:ignore ?>
                </div>

                <div class="ltms-qv-short-desc" style="font-size:13px;color:#4b5563;line-height:1.5;margin-bottom:16px;">
                    <?php echo wp_trim_words( $product->get_short_description() ?: $product->get_description(), 30, '...' ); ?>
                </div>

                <!-- Vendor info -->
                <?php
                $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
                $vendor_user = get_userdata( $vendor_id );
                if ( $vendor_user ) :
                    $kyc = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) === 'approved';
                ?>
                    <div class="ltms-qv-vendor" style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#f9fafb;border-radius:8px;margin-bottom:12px;font-size:12px;">
                        <span style="color:#6b7280;"><?php esc_html_e( 'Vendedor:', 'ltms' ); ?></span>
                        <strong><?php echo esc_html( $vendor_user->display_name ); ?></strong>
                        <?php if ( $kyc ) : ?>
                            <span style="color:#16a34a;">&#x2705;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Stock status -->
                <div class="ltms-qv-stock" style="margin-bottom:12px;font-size:13px;">
                    <?php if ( $product->is_in_stock() ) : ?>
                        <span style="color:#16a34a;font-weight:600;">&#x2714; <?php esc_html_e( 'En stock', 'ltms' ); ?></span>
                    <?php else : ?>
                        <span style="color:#dc2626;font-weight:600;">&#x2718; <?php esc_html_e( 'Agotado', 'ltms' ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Add to cart -->
                <?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
                    <div class="ltms-qv-add-to-cart" style="margin-bottom:12px;">
                        <?php
                        if ( $product->is_type( 'simple' ) ) {
                            echo sprintf(
                                '<a href="?add-to-cart=%d" class="button alt ltms-qv-add-cart" style="display:block;text-align:center;padding:12px;border-radius:8px;font-weight:700;">%s</a>',
                                esc_attr( $product->get_id() ),
                                esc_html( $product->single_add_to_cart_text() )
                            );
                        } else {
                            echo sprintf(
                                '<a href="%s" class="button alt" style="display:block;text-align:center;padding:12px;border-radius:8px;font-weight:700;">%s</a>',
                                esc_url( $product->get_permalink() ),
                                esc_html__( 'Ver opciones', 'ltms' )
                            );
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <!-- Trust badges mini -->
                <div class="ltms-qv-trust" style="display:flex;gap:12px;font-size:11px;color:#6b7280;flex-wrap:wrap;">
                    <span>&#x1F6E1; <?php esc_html_e( 'Compra protegida', 'ltms' ); ?></span>
                    <span>&#x1F69A; <?php esc_html_e( 'Envío disponible', 'ltms' ); ?></span>
                    <span>&#x21A9; <?php esc_html_e( 'Devoluciones', 'ltms' ); ?></span>
                </div>

                <!-- View full product link -->
                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="ltms-qv-view-full" style="display:block;text-align:center;margin-top:12px;font-size:12px;color:#2563eb;text-decoration:underline;">
                    <?php esc_html_e( 'Ver página completa del producto →', 'ltms' ); ?>
                </a>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }
}
