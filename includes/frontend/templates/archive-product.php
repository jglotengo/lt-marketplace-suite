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

<?php
/**
 * SHOP-FILTERS FIX (2026-09-11): filtros / herramientas del catálogo
 * (categoría, precio, disponibilidad, orden y vista grid/list) usando las
 * clases del design system (v2.9.191 "SHOP PAGE IMPROVEMENTS"). Las URLs de
 * cada filtro se construyen preservando los demás parámetros activos.
 */
$pv_shop_base = get_permalink( wc_get_page_id( 'shop' ) );
$pv_filter = array();
foreach ( array( 'product_cat', 'min_price', 'max_price', 'instock' ) as $pv_k ) {
    if ( isset( $_GET[ $pv_k ] ) && '' !== $_GET[ $pv_k ] ) {
        $pv_filter[ $pv_k ] = sanitize_text_field( wp_unslash( $_GET[ $pv_k ] ) );
    }
}
$pv_cat_slug = isset( $pv_filter['product_cat'] ) ? (string) $pv_filter['product_cat'] : '';
$pv_min      = isset( $pv_filter['min_price'] ) ? (string) $pv_filter['min_price'] : '';
$pv_max      = isset( $pv_filter['max_price'] ) ? (string) $pv_filter['max_price'] : '';
$pv_instock  = isset( $pv_filter['instock'] );
$pv_view     = ( isset( $_GET['view'] ) && 'list' === $_GET['view'] ) ? 'list' : 'grid';
$pv_cats     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 15, 'orderby' => 'count', 'order' => 'DESC' ) );
?>

<div class="pv-scope pv-shop<?php echo 'list' === $pv_view ? ' pv-shop--list' : ''; ?>">

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
     * SHOP-VENDOR-GATE FIX (2026-09-06): ver docblock original del template.
     */
    ob_start();
    do_action( 'woocommerce_archive_description' );
    $pv_archive_desc = (string) ob_get_clean();
    if ( false === strpos( $pv_archive_desc, 'ltms-empty-state-login' ) ) {
        echo $pv_archive_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>

    <div class="pv-shop__layout">
        <div class="pv-shop__filters-overlay" data-pv-close-filters aria-hidden="true"></div>
        <aside class="pv-shop__sidebar" id="pv-shop-filters" aria-label="<?php esc_attr_e( 'Filtros', 'ltms' ); ?>">
            <div class="pv-shop__sidebar-head">
                <span class="pv-shop__sidebar-title"><?php esc_html_e( 'Filtros', 'ltms' ); ?></span>
                <button type="button" class="pv-shop__sidebar-close" data-pv-close-filters aria-label="<?php esc_attr_e( 'Cerrar filtros', 'ltms' ); ?>">&times;</button>
            </div>

            <div class="pv-shop__filter">
                <h4 class="pv-shop__filter-title"><?php esc_html_e( 'Precio', 'ltms' ); ?></h4>
                <form method="get" action="<?php echo esc_url( $pv_shop_base ); ?>" class="pv-shop__filter-price">
                    <?php if ( $pv_instock ) : ?><input type="hidden" name="instock" value="1"><?php endif; ?>
                    <?php if ( $pv_cat_slug ) : ?><input type="hidden" name="product_cat" value="<?php echo esc_attr( $pv_cat_slug ); ?>"><?php endif; ?>
                    <div class="pv-shop__price-field">
                        <input type="number" name="min_price" min="0" step="1" placeholder="<?php esc_attr_e( 'Min', 'ltms' ); ?>" value="<?php echo esc_attr( $pv_min ); ?>">
                        <span>&ndash;</span>
                        <input type="number" name="max_price" min="0" step="1" placeholder="<?php esc_attr_e( 'Max', 'ltms' ); ?>" value="<?php echo esc_attr( $pv_max ); ?>">
                    </div>
                    <button type="submit" class="pv-btn pv-btn--sm pv-btn--block pv-shop__price-apply"><?php esc_html_e( 'Aplicar', 'ltms' ); ?></button>
                </form>
            </div>

            <div class="pv-shop__filter">
                <h4 class="pv-shop__filter-title"><?php esc_html_e( 'Categoría', 'ltms' ); ?></h4>
                <?php
                $pv_qs_all = $pv_filter;
                unset( $pv_qs_all['product_cat'] );
                ?>
                <a class="pv-shop__filter-link<?php echo '' === $pv_cat_slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( $pv_qs_all, $pv_shop_base ) ); ?>"><?php esc_html_e( 'Todas', 'ltms' ); ?></a>
                <?php if ( ! is_wp_error( $pv_cats ) ) : foreach ( $pv_cats as $pv_cat ) :
                    $pv_url_cat = add_query_arg( array_merge( $pv_filter, array( 'product_cat' => $pv_cat->slug ) ), $pv_shop_base );
                ?>
                    <a class="pv-shop__filter-link<?php echo $pv_cat_slug === $pv_cat->slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( $pv_url_cat ); ?>">
                        <?php echo esc_html( $pv_cat->name ); ?> <span class="pv-shop__filter-count">(<?php echo esc_html( $pv_cat->count ); ?>)</span>
                    </a>
                <?php endforeach; endif; ?>
            </div>

            <div class="pv-shop__filter">
                <h4 class="pv-shop__filter-title"><?php esc_html_e( 'Disponibilidad', 'ltms' ); ?></h4>
                <?php
                $pv_qs_stock = $pv_filter;
                if ( $pv_instock ) { unset( $pv_qs_stock['instock'] ); } else { $pv_qs_stock['instock'] = '1'; }
                ?>
                <a class="pv-shop__filter-link<?php echo $pv_instock ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( $pv_qs_stock, $pv_shop_base ) ); ?>"><?php esc_html_e( 'Solo en stock', 'ltms' ); ?></a>
            </div>
        </aside>

        <div class="pv-shop__main">

            <?php
            /**
             * Toolbar: resultado + ordenar + toggle de vista + botón filtros (móvil).
             * El hook woocommerce_before_shop_loop renderiza woocommerce_result_count
             * y woocommerce_catalog_ordering (que ya preserva los params vía
             * wc_query_string_form_fields).
             */
            ?>
            <div class="pv-shop__toolbar">
                <button type="button" class="pv-shop__filters-toggle" data-pv-open-filters aria-controls="pv-shop-filters">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="8" y1="18" x2="16" y2="18"/></svg>
                    <?php esc_html_e( 'Filtros', 'ltms' ); ?>
                </button>
                <?php do_action( 'woocommerce_before_shop_loop' ); ?>
                <div class="pv-shop__view-toggle" role="group" aria-label="<?php esc_attr_e( 'Vista', 'ltms' ); ?>">
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $pv_filter, array( 'view' => 'grid' ) ), $pv_shop_base ) ); ?>" class="pv-shop__view-toggle-btn<?php echo 'grid' === $pv_view ? ' is-active' : ''; ?>" aria-label="<?php esc_attr_e( 'Vista cuadrícula', 'ltms' ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $pv_filter, array( 'view' => 'list' ) ), $pv_shop_base ) ); ?>" class="pv-shop__view-toggle-btn<?php echo 'list' === $pv_view ? ' is-active' : ''; ?>" aria-label="<?php esc_attr_e( 'Vista lista', 'ltms' ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="18" height="3" rx="1"/><rect x="3" y="10" width="18" height="3" rx="1"/><rect x="3" y="16" width="18" height="3" rx="1"/></svg>
                    </a>
                </div>
            </div>

            <?php if ( $pv_cat_slug || $pv_instock || '' !== $pv_min || '' !== $pv_max ) : ?>
                <div class="pv-shop__chips" aria-label="<?php esc_attr_e( 'Filtros activos', 'ltms' ); ?>">
                    <?php if ( $pv_cat_slug ) :
                        $pv_cat_obj = get_term_by( 'slug', $pv_cat_slug, 'product_cat' );
                        $pv_qs_rm_cat = $pv_filter; unset( $pv_qs_rm_cat['product_cat'] );
                    ?>
                        <a class="pv-shop__active-filter" href="<?php echo esc_url( add_query_arg( $pv_qs_rm_cat, $pv_shop_base ) ); ?>"><?php echo esc_html( $pv_cat_obj ? $pv_cat_obj->name : $pv_cat_slug ); ?> &times;</a>
                    <?php endif; ?>
                    <?php if ( '' !== $pv_min || '' !== $pv_max ) :
                        $pv_qs_rm_price = $pv_filter; unset( $pv_qs_rm_price['min_price'], $pv_qs_rm_price['max_price'] );
                        $pv_price_label = ( $pv_min !== '' && $pv_max !== '' ) ? $pv_min . ' – ' . $pv_max : ( $pv_min !== '' ? '≥ ' . $pv_min : '≤ ' . $pv_max );
                    ?>
                        <a class="pv-shop__active-filter" href="<?php echo esc_url( add_query_arg( $pv_qs_rm_price, $pv_shop_base ) ); ?>"><?php echo esc_html( $pv_price_label ); ?> &times;</a>
                    <?php endif; ?>
                    <?php if ( $pv_instock ) :
                        $pv_qs_rm_stock = $pv_filter; unset( $pv_qs_rm_stock['instock'] );
                    ?>
                        <a class="pv-shop__active-filter" href="<?php echo esc_url( add_query_arg( $pv_qs_rm_stock, $pv_shop_base ) ); ?>"><?php esc_html_e( 'En stock', 'ltms' ); ?> &times;</a>
                    <?php endif; ?>
                    <a class="pv-shop__active-filter pv-shop__active-filter--clear" href="<?php echo esc_url( $pv_shop_base ); ?>"><?php esc_html_e( 'Limpiar todo', 'ltms' ); ?></a>
                </div>
            <?php endif; ?>

            <?php if ( function_exists( 'woocommerce_product_loop' ) && woocommerce_product_loop() ) : ?>

                <?php if ( function_exists( 'woocommerce_product_loop_start' ) ) woocommerce_product_loop_start(); ?>

                <?php
                /*
                 * SHOP-CARD-PARITY FIX (2026-09-06): renderizar las cards del shop
                 * con el MISMO markup compacto del home (widget de Elementor).
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
