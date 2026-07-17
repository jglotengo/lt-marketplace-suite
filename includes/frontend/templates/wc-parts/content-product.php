<?php
/**
 * Template Part: content-product.php — Plaza Viva Design System
 *
 * Product card para loops de WooCommerce (shop archive, related, trending,
 * cross-sells, up-sells, shortcodes de categoría, etc.).
 *
 * WC lo carga vía `wc_get_template_part('content', 'product')` dentro del
 * loop global. Override interceptado por
 * LTMS_Native_Templates::locate_wc_template().
 *
 * Estructura:
 *  - Imagen con hover zoom (.pv-product-card__image / __media).
 *  - Discount badge si `is_on_sale()` (.pv-product-card__discount).
 *  - Fav button (wishlist) con enlace `?add_to_wishlist=ID`
 *    y `data-pv-wishlist-toggle` para AJAX (.pv-product-card__fav).
 *  - Hover actions: Quick view + Add to cart (.pv-product-card__actions).
 *  - Vendor badge con nombre + KYC badge (.pv-product-card__vendor).
 *  - Title (2 líneas máx.) (.pv-product-card__title).
 *  - Rating stars + count (.pv-product-card__rating).
 *  - Price + old price (.pv-product-card__price).
 *  - Color swatches si es variable (.pv-product-card__swatches).
 *
 * Responsive: grid 4→3→2→1 (definido por el parent wrapper en CSS).
 * Touch targets 44px+: acciones reveladas en hover desktop / tap mobile.
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

global $product, $post;

// Asegurar objeto WC_Product válido.
if ( ! $product instanceof WC_Product ) {
    $product = ( function_exists( 'wc_get_product' ) ) ? wc_get_product( get_the_ID() ) : null;
}

if ( ! $product || ! $product->is_visible() ) {
    return;
}

/* ---------------------------------------------------------------------------
 * 1. Datos del producto
 * ------------------------------------------------------------------------- */
$pv_pid          = $product->get_id();
$pv_permalink    = $product->get_permalink();
$pv_title        = wp_strip_all_tags( $product->get_name() );
$pv_image_html   = $product->get_image( 'woocommerce_thumbnail', [ 'loading' => 'lazy', 'class' => 'pv-product-card__img' ], true );
$pv_rating       = (float) $product->get_average_rating();
$pv_review_count = (int) $product->get_review_count();

$pv_on_sale      = $product->is_on_sale();
$pv_regular      = $product->get_regular_price();
$pv_sale         = $product->get_sale_price();
$pv_discount_pct = '';
if ( $pv_on_sale ) {
    $pv_reg_f = (float) $pv_regular;
    $pv_sal_f = (float) $pv_sale;
    if ( $pv_reg_f > 0 && $pv_sal_f > 0 && $pv_sal_f < $pv_reg_f ) {
        $pv_discount_pct = round( 100 - ( ( $pv_sal_f / $pv_reg_f ) * 100 ) );
    }
}

$pv_purchasable = $product->is_purchasable();
$pv_in_stock    = $product->is_in_stock();
$pv_stock_qty   = $product->get_stock_quantity();
$pv_manage_stock = $product->managing_stock();

$pv_type = $product->get_type();

/* ---------------------------------------------------------------------------
 * 2. Vendor (autor del producto)
 * ------------------------------------------------------------------------- */
$pv_vendor_id   = (int) get_post_field( 'post_author', $pv_pid );
$pv_vendor_name = '';
$pv_vendor_kyc  = '';
$pv_vendor_url  = '';
if ( $pv_vendor_id > 0 ) {
    $pv_vendor_store = (string) get_user_meta( $pv_vendor_id, 'ltms_store_name', true );
    if ( '' !== $pv_vendor_store ) {
        $pv_vendor_name = $pv_vendor_store;
    } else {
        $pv_vendor_user = get_userdata( $pv_vendor_id );
        if ( $pv_vendor_user ) {
            $pv_vendor_name = $pv_vendor_user->display_name ?: $pv_vendor_user->user_login;
        }
    }
    $pv_kyc_status = (string) get_user_meta( $pv_vendor_id, 'ltms_kyc_status', true );
    if ( $pv_kyc_status === 'approved' ) {
        $pv_vendor_kyc = 'approved';
    }
    // URL de la tienda del vendor (filtrable por módulo LTMS_Vendor_Storefront).
    $pv_vendor_url = apply_filters( 'ltms_vendor_store_url', home_url( '/vendor/' . $pv_vendor_id . '/' ), $pv_vendor_id );
}

/* ---------------------------------------------------------------------------
 * 3. Swatches (variaciones de color) si es producto variable
 * ------------------------------------------------------------------------- */
$pv_swatches = [];
if ( $pv_type === 'variable' && method_exists( $product, 'get_available_variations' ) ) {
    $pv_variations = $product->get_available_variations();
    if ( is_array( $pv_variations ) ) {
        foreach ( $pv_variations as $pv_var ) {
            if ( empty( $pv_var['attributes'] ) ) continue;
            foreach ( $pv_var['attributes'] as $pv_attr_key => $pv_attr_val ) {
                if ( ! $pv_attr_val ) continue;
                // Detectar atributo de color por nombre.
                $pv_is_color_attr = ( stripos( $pv_attr_key, 'color' ) !== false || stripos( $pv_attr_key, 'colour' ) !== false || stripos( $pv_attr_key, 'pa_color' ) !== false );
                $pv_hex = sanitize_hex_color( $pv_attr_val );
                if ( $pv_is_color_attr || $pv_hex ) {
                    $pv_swatches[ $pv_attr_val ] = $pv_hex ?: null;
                }
                if ( count( $pv_swatches ) >= 5 ) break 2;
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 * 4. URLs de acción
 * ------------------------------------------------------------------------- */
$pv_wishlist_url = add_query_arg( 'add_to_wishlist', $pv_pid, $pv_permalink );
$pv_quick_view_attrs = sprintf( 'data-pv-quick-view="%1$d" data-pv-quickview="%1$d" data-product_id="%1$d"', esc_attr( $pv_pid ) );
$pv_atc_attrs = $pv_purchasable && $pv_in_stock
    ? sprintf( 'data-pv-add-to-cart="%d"', esc_attr( $pv_pid ) )
    : '';

/* ---------------------------------------------------------------------------
 * 5. Hooks WC antes del card (woocommerce_before_shop_loop_item)
 *    Permite a extensiones inyectar contenido (badges extra, etc.).
 * ------------------------------------------------------------------------- */
?>
<article <?php wc_product_class( 'pv-product-card pv-fade-up', $product ); ?> data-product-id="<?php echo esc_attr( $pv_pid ); ?>">

    <?php
    /**
     * Hook: woocommerce_before_shop_loop_item
     * (Sin output por defecto — placeholder para extensiones.)
     */
    do_action( 'woocommerce_before_shop_loop_item' );
    ?>

    <div class="pv-product-card__media pv-product-card__image">
        <?php
        /**
         * Hook: woocommerce_before_shop_loop_item_title
         * WC lo usa para imprimir badges de stock/sale por defecto. Nosotros
         * lo suprimimos visualmente y usamos nuestro propio discount badge.
         */
        remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
        do_action( 'woocommerce_before_shop_loop_item_title' );
        ?>

        <a href="<?php echo esc_url( $pv_permalink ); ?>" class="pv-product-card__media-link" aria-label="<?php echo esc_attr( $pv_title ); ?>" tabindex="-1">
            <?php echo $pv_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </a>

        <?php /* Discount badge */ ?>
        <?php if ( $pv_on_sale && $pv_discount_pct ) : ?>
            <span class="pv-product-card__discount" aria-label="<?php echo esc_attr( sprintf( __( '%d%% de descuento', 'ltms' ), $pv_discount_pct ) ); ?>">
                <?php echo esc_html( sprintf( __( '-%d%%', 'ltms' ), $pv_discount_pct ) ); ?>
            </span>
        <?php elseif ( $pv_on_sale ) : ?>
            <span class="pv-product-card__discount pv-product-card__discount--soft" aria-label="<?php esc_attr_e( 'En oferta', 'ltms' ); ?>">
                <?php esc_html_e( 'Oferta', 'ltms' ); ?>
            </span>
        <?php endif; ?>

        <?php /* Out of stock badge */ ?>
        <?php if ( ! $pv_in_stock ) : ?>
            <span class="pv-product-card__discount pv-product-card__discount--muted"><?php esc_html_e( 'Agotado', 'ltms' ); ?></span>
        <?php endif; ?>

        <?php /* Fav button (wishlist) — enlace a ?add_to_wishlist=ID + data attribute para AJAX */ ?>
        <a class="pv-product-card__fav"
           href="<?php echo esc_url( $pv_wishlist_url ); ?>"
           data-pv-wishlist-toggle="<?php echo esc_attr( $pv_pid ); ?>"
           data-product-id="<?php echo esc_attr( $pv_pid ); ?>"
           aria-label="<?php echo esc_attr( sprintf( __( 'Añadir %s a favoritos', 'ltms' ), $pv_title ) ); ?>"
           aria-pressed="false"
           rel="nofollow">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        </a>

        <?php /* Hover actions (desktop) / static actions (mobile) */ ?>
        <div class="pv-product-card__actions">
            <?php if ( $pv_atc_attrs ) : ?>
                <button type="button"
                        class="pv-btn pv-btn--sm pv-product-card__atc"
                        <?php echo $pv_atc_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        aria-label="<?php echo esc_attr( sprintf( __( 'Añadir %s al carrito', 'ltms' ), $pv_title ) ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    <?php esc_html_e( 'Añadir', 'ltms' ); ?>
                </button>
            <?php else : ?>
                <a class="pv-btn pv-btn--sm pv-btn--ghost pv-product-card__atc"
                   href="<?php echo esc_url( $pv_permalink ); ?>"
                   aria-label="<?php echo esc_attr( sprintf( __( 'Ver %s', 'ltms' ), $pv_title ) ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <?php esc_html_e( 'Ver', 'ltms' ); ?>
                </a>
            <?php endif; ?>

            <button type="button"
                    class="pv-product-card__quickview"
                    <?php echo $pv_quick_view_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    aria-label="<?php echo esc_attr( sprintf( __( 'Vista rápida de %s', 'ltms' ), $pv_title ) ); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        </div>
    </div>

    <div class="pv-product-card__body">
        <?php
        /* Vendor badge (con KYC check si está verificado) */
        if ( $pv_vendor_name ) :
            ?>
            <a class="pv-product-card__brand pv-product-card__vendor" href="<?php echo esc_url( $pv_vendor_url ); ?>" data-pv-vendor-id="<?php echo esc_attr( $pv_vendor_id ); ?>">
                <span class="pv-product-card__vendor-name"><?php echo esc_html( $pv_vendor_name ); ?></span>
                <?php if ( $pv_vendor_kyc === 'approved' ) : ?>
                    <svg class="pv-product-card__vendor-check" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-label="<?php esc_attr_e( 'Vendedor verificado', 'ltms' ); ?>" role="img"><path d="M12 2L4 5v6c0 5.5 3.8 10.6 8 12 4.2-1.4 8-6.5 8-12V5l-8-3zm-1.2 14.5l-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3z"/></svg>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php
        /**
         * Hook: woocommerce_shop_loop_item_title
         * (Sin output por defecto.)
         */
        do_action( 'woocommerce_shop_loop_item_title' );
        ?>

        <h3 class="pv-product-card__title">
            <a href="<?php echo esc_url( $pv_permalink ); ?>" title="<?php echo esc_attr( $pv_title ); ?>"><?php echo esc_html( $pv_title ); ?></a>
        </h3>

        <div class="pv-product-card__rating" aria-label="<?php echo esc_attr( sprintf( __( 'Valoración media %s de 5', 'ltms' ), number_format_i18n( $pv_rating, 1 ) ) ); ?>">
            <?php
            /**
             * Hook: woocommerce_after_shop_loop_item_title
             * WC lo usa para imprimir rating y price por defecto. Lo suprimimos
             * y usamos nuestro propio markup para control total del design.
             */
            remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
            remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
            do_action( 'woocommerce_after_shop_loop_item_title' );

            echo wc_get_rating_html( $pv_rating, $pv_review_count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
            <span class="pv-product-card__rating-count">(<?php echo esc_html( number_format_i18n( $pv_review_count ) ); ?>)</span>
        </div>

        <div class="pv-product-card__price">
            <?php if ( $pv_on_sale && $pv_sale !== '' && $pv_regular !== '' ) : ?>
                <span class="pv-product-card__price-now"><?php echo wc_price( $pv_sale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="pv-product-card__price-old"><?php echo wc_price( $pv_regular ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <?php else : ?>
                <span class="pv-product-card__price-now"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <?php endif; ?>
        </div>

        <?php
        /* Color swatches (sólo si el producto es variable y tiene colores) */
        if ( ! empty( $pv_swatches ) ) :
            ?>
            <div class="pv-product-card__swatches" aria-label="<?php esc_attr_e( 'Colores disponibles', 'ltms' ); ?>">
                <?php foreach ( $pv_swatches as $pv_label => $pv_hex ) :
                    if ( $pv_hex ) { ?>
                        <span class="pv-swatch"
                              style="background:<?php echo esc_attr( $pv_hex ); ?>"
                              title="<?php echo esc_attr( ucfirst( $pv_label ) ); ?>"
                              aria-label="<?php echo esc_attr( ucfirst( $pv_label ) ); ?>"></span>
                    <?php } else { ?>
                        <span class="pv-swatch pv-swatch--text"
                              title="<?php echo esc_attr( ucfirst( $pv_label ) ); ?>"
                              aria-label="<?php echo esc_attr( ucfirst( $pv_label ) ); ?>"><?php echo esc_html( mb_substr( ucfirst( $pv_label ), 0, 2 ) ); ?></span>
                    <?php }
                endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        /* Low stock hint para urgencia */
        if ( $pv_in_stock && $pv_manage_stock && $pv_stock_qty !== null && $pv_stock_qty > 0 && $pv_stock_qty <= 5 ) :
            ?>
            <div class="pv-product-card__meta">
                <span class="pv-product-card__stock-low" data-pv-stock-low="<?php echo esc_attr( (int) $pv_stock_qty ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php echo esc_html( sprintf( _n( '¡Solo queda %d unidad!', '¡Solo quedan %d unidades!', (int) $pv_stock_qty, 'ltms' ), (int) $pv_stock_qty ) ); ?>
                </span>
            </div>
        <?php elseif ( $pv_in_stock && ! $product->is_virtual() ) : ?>
            <div class="pv-product-card__meta">
                <span class="pv-product-card__shipping">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <?php esc_html_e( 'Envío a todo el país', 'ltms' ); ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php
    /**
     * Hook: woocommerce_after_shop_loop_item
     * WC lo usa para imprimir el botón "Add to cart" por defecto. Lo suprimimos
     * porque ya tenemos nuestro propio ATC en .pv-product-card__actions.
     */
    remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
    do_action( 'woocommerce_after_shop_loop_item' );
    ?>
</article>
