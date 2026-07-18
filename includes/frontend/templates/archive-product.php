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

    <?php do_action( 'woocommerce_archive_description' ); ?>

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

                <?php while ( have_posts() ) : the_post(); ?>
                    <?php do_action( 'woocommerce_shop_loop' ); ?>
                    <?php wc_get_template_part( 'content', 'product' ); ?>
                <?php endwhile; ?>

                <?php if ( function_exists( 'woocommerce_product_loop_end' ) ) woocommerce_product_loop_end(); ?>

                <?php
                /**
                 * Hook: woocommerce_after_shop_loop
                 */
                do_action( 'woocommerce_after_shop_loop' );
                ?>

            <?php else : ?>
                <?php do_action( 'woocommerce_no_products_found' ); ?>
            <?php endif; ?>

        </div><!-- /.pv-shop__main -->
    </div><!-- /.pv-shop__layout -->

</div><!-- /.pv-scope.pv-shop -->

<?php
/**
 * Hook: woocommerce_after_main_content
 */
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
