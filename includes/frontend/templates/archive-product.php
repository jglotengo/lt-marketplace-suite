<?php
/**
 * Template: Archive Product (Shop) — Plaza Viva Design System (MINIMAL SAFE)
 *
 * Versión minimalista y segura del template de shop.
 * Usa exclusivamente funciones WC estándar con guards function_exists.
 * Si algo falla, hace fallback a woocommerce_content().
 *
 * @package LTMS
 * @since   3.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Safety: si WC no está cargado, fallback.
if ( ! function_exists( 'woocommerce_content' ) ) {
    return;
}

get_header( 'shop' );

/**
 * SHOP-BREADCRUMB-DUP FIX (2026-09-06): evitar breadcrumb duplicado en
 * /tienda/. El theme (Hello Elementor / WC) engancha woocommerce_breadcrumb
 * en 'woocommerce_before_main_content' (prioridad 20), y nuestro template
 * imprime su PROPIO breadcrumb con el design system dentro de
 * .pv-shop__breadcrumb. Sin este remove_action el usuario veía "Inicio /
 * Tienda" DOS veces consecutivas. Mismo patrón que cart.php (remueve el
 * hook, renderiza el nuestro, y restaura el hook al final del template).
 */
$pv_shop_breadcrumb_was_hooked = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( $pv_shop_breadcrumb_was_hooked ) {
    remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}

/**
 * Hook: woocommerce_before_main_content
 */
do_action( 'woocommerce_before_main_content' );
?>

<div class="pv-scope pv-shop">

    <?php if ( function_exists( 'woocommerce_breadcrumb' ) ) : ?>
        <div class="pv-shop__breadcrumb">
            <?php woocommerce_breadcrumb(); ?>
        </div>
    <?php endif; ?>

    <?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
        <h1 class="pv-shop__title">
            <?php if ( function_exists( 'woocommerce_page_title' ) ) woocommerce_page_title(); ?>
        </h1>
    <?php endif; ?>

    <?php
    /**
     * SHOP-VENDOR-GATE FIX (2026-09-06): woocommerce_archive_description
     * renderiza el contenido de la shop page (WC lo imprime como descripción
     * del catálogo). Si ese contenido incluye un shortcode del dashboard del
     * vendedor (ej. [ltms_vendor_store]), para compradores/guests el
     * shortcode renderiza el gate "Debes iniciar sesión como vendedor" —
     * exactamente lo que veía un comprador al pulsar "Explorar productos"
     * del carrito vacío. Se captura el output y se omite cualquier bloque
     * .ltms-empty-state-login (login gate) para que el catálogo jamás
     * muestre un gate de vendedor en un contexto público.
     */
    ob_start();
    do_action( 'woocommerce_archive_description' );
    $pv_archive_desc = (string) ob_get_clean();
    if ( false === strpos( $pv_archive_desc, 'ltms-empty-state-login' ) ) {
        echo $pv_archive_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>

    <div class="pv-shop__layout">
        <?php if ( is_active_sidebar( 'shop-sidebar' ) ) : ?>
            <aside class="pv-shop__sidebar">
                <?php dynamic_sidebar( 'shop-sidebar' ); ?>
            </aside>
        <?php endif; ?>

        <div class="pv-shop__main">

            <?php
            /**
             * Hook: woocommerce_before_shop_loop
             */
            do_action( 'woocommerce_before_shop_loop' );
            ?>

            <?php if ( function_exists( 'woocommerce_product_loop' ) && woocommerce_product_loop() ) : ?>

                <?php if ( function_exists( 'woocommerce_product_loop_start' ) ) woocommerce_product_loop_start(); ?>

                <?php
                /*
                 * SHOP-CARD-PARITY FIX (2026-09-06): renderizar las cards del
                 * shop con el MISMO markup compacto del home (widget de
                 * Elementor). Antes el loop usaba wc_get_template_part('content',
                 * 'product') -> content-product.php del theme que NO envuelve
                 * el boton en .woocommerce-loop-product__buttons ni fuerza la
                 * post_class completa -> las cards del shop se veian distintas
                 * al home (boton pegado al precio, sin margin-top:auto).
                 * Mismo patron que CART-EMPTY-CARD-STD en cart.php.
                 */
                while ( have_posts() ) : the_post();
                    do_action( 'woocommerce_shop_loop' );
                    $_pv_p = wc_get_product( get_the_ID() );
                    if ( ! $_pv_p || ! $_pv_p->is_visible() ) {
                        continue;
                    }
                    ?>
                    <li <?php echo wc_product_class( 'product', $_pv_p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <a href="<?php echo esc_url( $_pv_p->get_permalink() ); ?>" class="woocommerce-loop-product__link">
                            <?php echo $_pv_p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <h2 class="woocommerce-loop-product__title"><?php echo esc_html( $_pv_p->get_name() ); ?></h2>
                            <span class="price"><?php echo wp_kses_post( $_pv_p->get_price_html() ); ?></span>
                        </a>
                        <?php if ( $_pv_p->is_purchasable() && $_pv_p->is_in_stock() ) : ?>
                            <div class="woocommerce-loop-product__buttons">
                                <a href="<?php echo esc_url( $_pv_p->add_to_cart_url() ); ?>" data-quantity="1" class="button product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="<?php echo esc_attr( $_pv_p->get_id() ); ?>" data-product_sku="<?php echo esc_attr( $_pv_p->get_sku() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Añadir al carrito: %s', 'ltms' ), $_pv_p->get_name() ) ); ?>" rel="nofollow"><?php esc_html_e( 'Añadir al carrito', 'ltms' ); ?></a>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php
                endwhile;
                ?>

                <?php if ( function_exists( 'woocommerce_product_loop_end' ) ) woocommerce_product_loop_end(); ?>

                <?php
                /**
                 * Hook: woocommerce_after_shop_loop
                 */
                do_action( 'woocommerce_after_shop_loop' );
                ?>

            <?php else : ?>
                <?php
                /*
                 * AUDIT-FE-PV-DS-005 FIX (P1-3): el empty state del shop se
                 * envuelve en .pv-shop__empty para que el output de
                 * woocommerce_no_products_found (típicamente el notice
                 * .woocommerce-info de WC) reciba styling del design system
                 * y no caiga al look crudo del theme. El hook se preserva
                 * como válvula de extensión para plugins que inyectan
                 * resultados alternativos.
                 */
                ?>
                <div class="pv-shop__empty">
                    <?php do_action( 'woocommerce_no_products_found' ); ?>
                </div>
            <?php endif; ?>

        </div><!-- /.pv-shop__main -->
    </div><!-- /.pv-shop__layout -->

</div><!-- /.pv-scope.pv-shop -->

<?php
/**
 * Hook: woocommerce_after_main_content
 */
do_action( 'woocommerce_after_main_content' );

// SHOP-BREADCRUMB-DUP FIX: restaurar el breadcrumb en el hook para no
// afectar al resto del sitio (paridad con cart.php:712-720).
if ( ! empty( $pv_shop_breadcrumb_was_hooked ) ) {
    add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}

get_footer( 'shop' );
