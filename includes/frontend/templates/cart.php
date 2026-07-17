<?php
/**
 * Template: Cart — Plaza Viva Design System
 *
 * Carrito nativo de WooCommerce multi-vendor.
 * Reemplaza al template de WC vía `template_include` (ver
 * LTMS_Native_Templates::maybe_override() — integra cart.php cuando
 * is_cart() && ! is_cart_empty_block()).
 *
 * Características:
 *  - Items agrupados por vendor (post_author del producto).
 *  - Layout 2 columnas (items izquierda + resumen sticky derecha) → 1 col móvil.
 *  - Vendor mini-card con avatar, nombre y enlace a la tienda.
 *  - Cupón inline (no colapsado) vía woocommerce_checkout_coupon_form().
 *  - Shipping progress bar hacia el envío gratis (umbral configurable).
 *  - Cross-sells bajo los items (woocommerce_cross_sell_display()).
 *  - Totales + CTA "Finalizar compra" (woocommerce_proceed_to_checkout()).
 *  - Touch targets ≥44px, responsive, sin jQuery.
 *
 * Hooks WC estándar:
 *  - woocommerce_before_cart / woocommerce_before_cart_table
 *  - woocommerce_cart_collaterals (cross-sells, totals)
 *  - woocommerce_after_cart
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar que WooCommerce está cargado y el carrito existe.
if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
    get_header( 'shop' );
    echo '<div class="pv-scope pv-cart"><main class="pv-section" style="padding:60px 22px;text-align:center"><p>' . esc_html__( 'WooCommerce no está activo o el carrito no está disponible.', 'ltms' ) . '</p></main></div>';
    get_footer( 'shop' );
    return;
}

// Si el carrito está vacío, dejamos que WC maneje la vista (wc_empty_cart).
if ( WC()->cart->is_empty() ) {
    get_header( 'shop' );
    ?>
    <div class="pv-scope pv-cart">
        <main class="pv-section pv-cart__empty" role="main">
            <div class="pv-card pv-cart__empty-card">
                <div class="pv-cart__empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M3 4h2l2.4 12.5a1 1 0 0 0 1 .8h8.5a1 1 0 0 0 1-.78L21 8H6" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="20" r="1.4" fill="currentColor" stroke="none"/>
                        <circle cx="18" cy="20" r="1.4" fill="currentColor" stroke="none"/>
                    </svg>
                </div>
                <h1 class="pv-cart__empty-title"><?php esc_html_e( 'Tu carrito está vacío', 'ltms' ); ?></h1>
                <p class="pv-cart__empty-sub"><?php esc_html_e( 'Explora productos de miles de vendedores verificados en el marketplace.', 'ltms' ); ?></p>
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="pv-btn pv-btn--lg">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h14M11 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e( 'Explorar productos', 'ltms' ); ?>
                </a>
            </div>
        </main>
    </div>
    <?php
    get_footer( 'shop' );
    return;
}

/* ---------------------------------------------------------------------------
 * 1. Agrupar items del carrito por vendor (post_author del producto).
 * ------------------------------------------------------------------------- */
$cart_items      = WC()->cart->get_cart();
$vendors_groups  = array(); // [vendor_id => ['name'=>, 'url'=>, 'items'=>[...]]]
$unknown_vendor  = array( 'name' => '', 'url' => '#', 'items' => array() );

foreach ( $cart_items as $cart_item_key => $cart_item ) {
    $product_id   = (int) ( $cart_item['product_id'] ?? 0 );
    $variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
    $post_id      = $variation_id ? $variation_id : $product_id;

    $vendor_id = 0;
    if ( $post_id > 0 ) {
        $vendor_id = (int) get_post_field( 'post_author', $post_id );
    }

    if ( $vendor_id <= 0 ) {
        $unknown_vendor['items'][ $cart_item_key ] = $cart_item;
        continue;
    }

    if ( ! isset( $vendors_groups[ $vendor_id ] ) ) {
        $vendor_user = get_userdata( $vendor_id );
        $store_name  = (string) get_user_meta( $vendor_id, 'ltms_store_name', true );
        if ( '' === $store_name && $vendor_user ) {
            $store_name = $vendor_user->display_name ?: $vendor_user->user_login;
        }
        $store_slug = (string) get_user_meta( $vendor_id, 'ltms_store_slug', true );
        if ( $store_slug ) {
            $store_url = home_url( '/vendedor/' . rawurlencode( $store_slug ) );
        } else {
            $store_url = get_author_posts_url( $vendor_id );
        }

        $vendors_groups[ $vendor_id ] = array(
            'name'  => $store_name,
            'url'   => $store_url,
            'items' => array(),
        );
    }
    $vendors_groups[ $vendor_id ]['items'][ $cart_item_key ] = $cart_item;
}

// Si hay items sin vendor identificado, los añadimos como grupo "Marketplace".
if ( ! empty( $unknown_vendor['items'] ) ) {
    $unknown_vendor['name'] = __( 'Marketplace', 'ltms' );
    $vendors_groups[0]      = $unknown_vendor;
}

/* ---------------------------------------------------------------------------
 * 2. Envío gratis — umbral (woocommerce_free_shipping_settings o 100000 COP).
 * ------------------------------------------------------------------------- */
$free_shipping_threshold = 100000; // Fallback razonable en COP.
$fs_settings = get_option( 'woocommerce_free_shipping_settings', array() );
if ( is_array( $fs_settings ) && isset( $fs_settings['min_amount'] ) ) {
    $min = (float) $fs_settings['min_amount'];
    if ( $min > 0 ) {
        $free_shipping_threshold = $min;
    }
}
/**
 * Filter: ltms_cart_free_shipping_threshold
 * Permite ajustar el umbral de envío gratis mostrado en el progress bar.
 */
$free_shipping_threshold = (float) apply_filters( 'ltms_cart_free_shipping_threshold', $free_shipping_threshold );

$cart_contents_total = (float) WC()->cart->get_cart_contents_total(); // Sin shipping/tax.
$shipping_remaining  = max( 0, $free_shipping_threshold - $cart_contents_total );
$shipping_progress   = $free_shipping_threshold > 0
    ? min( 100, round( ( $cart_contents_total / $free_shipping_threshold ) * 100 ) )
    : 100;
$has_free_shipping   = ( $shipping_remaining <= 0 );

/* ---------------------------------------------------------------------------
 * 3. Totales / cupones / cantidades.
 * ------------------------------------------------------------------------- */
$cart_subtotal     = WC()->cart->get_cart_subtotal(); // HTML formateado.
$cart_total        = WC()->cart->get_cart_total(); // HTML formateado con moneda.
$shipping_total    = (float) WC()->cart->get_shipping_total();
$shipping_total_fm = wc_price( $shipping_total );
$discount_total    = (float) WC()->cart->get_discount_total();
$discount_total_fm = wc_price( $discount_total );
$tax_total         = (float) WC()->cart->get_cart_contents_tax();
$tax_total_fm      = wc_price( $tax_total );
$coupons           = WC()->cart->get_coupons();
$cart_count        = (int) WC()->cart->get_cart_contents_count();

/**
 * Hook: ltms_before_cart_plazaviva
 * Permite inyectar contenido antes del contenedor principal del carrito.
 */
do_action( 'ltms_before_cart_plazaviva' );

/**
 * Wrapper del tema — woocommerce_before_main_content.
 * Desenganchamos temporalmente el breadcrumb (prioridad 20) porque lo
 * renderizamos explícitamente dentro del scope para evitar duplicados.
 */
$pv_breadcrumb_was_hooked = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( $pv_breadcrumb_was_hooked ) {
    remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
do_action( 'woocommerce_before_main_content' );

get_header( 'shop' );
?>

<div class="pv-scope pv-cart">

    <?php
    /**
     * Hook: woocommerce_before_cart
     * Imprime mensajes / errores / info notices (wc_print_notices está
     * delegado a WC 7+ mediante este hook en prioridad 10).
     */
    do_action( 'woocommerce_before_cart' );
    ?>

    <!-- ===================================================================
         BREADCRUMB
         =================================================================== -->
    <nav class="pv-cart__breadcrumb pv-section pv-section--tight" aria-label="<?php esc_attr_e( 'Migas de navegación', 'ltms' ); ?>">
        <?php woocommerce_breadcrumb(); ?>
    </nav>

    <!-- ===================================================================
         HEADER: Título + count +Vaciar carrito
         =================================================================== -->
    <header class="pv-cart__header pv-section">
        <div class="pv-cart__header-inner">
            <div class="pv-cart__title-wrap">
                <h1 class="pv-cart__title"><?php esc_html_e( 'Tu carrito', 'ltms' ); ?></h1>
                <span class="pv-badge pv-badge--trust pv-badge--lg">
                    <?php
                    /* translators: %d: número de items en el carrito. */
                    echo esc_html( sprintf( _n( '%d producto', '%d productos', $cart_count, 'ltms' ), $cart_count ) );
                    ?>
                </span>
            </div>
            <div class="pv-cart__header-actions">
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="pv-btn pv-btn--ghost pv-btn--sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e( 'Seguir comprando', 'ltms' ); ?>
                </a>
            </div>
        </div>
    </header>

    <!-- ===================================================================
         LAYOUT PRINCIPAL: 2 columnas (items + summary sticky)
         =================================================================== -->
    <main class="pv-cart__main pv-section" role="main">

        <!-- ===============================================================
             SHIPPING PROGRESS BAR
             =============================================================== -->
        <section class="pv-cart__shipping-bar" aria-label="<?php esc_attr_e( 'Progreso hacia envío gratis', 'ltms' ); ?>">
            <?php if ( $has_free_shipping ) : ?>
                <div class="pv-cart__shipping-msg pv-cart__shipping-msg--ok">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><strong><?php esc_html_e( '¡Envío gratis desbloqueado!', 'ltms' ); ?></strong> <?php esc_html_e( 'Tu pedido califica para envío sin costo.', 'ltms' ); ?></span>
                </div>
            <?php else : ?>
                <div class="pv-cart__shipping-msg">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>
                    <span>
                        <?php
                        /* translators: %s: monto restante para envío gratis. */
                        echo wp_kses_post( sprintf( __( 'Te faltan <strong>%s</strong> para obtener <strong>envío gratis</strong>.', 'ltms' ), wc_price( $shipping_remaining ) ) );
                        ?>
                    </span>
                </div>
            <?php endif; ?>
            <div class="pv-stock-bar pv-stock-bar--<?php echo $has_free_shipping ? '' : 'warn'; ?> pv-cart__shipping-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( $shipping_progress ); ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="pv-stock-bar__fill" style="width:<?php echo esc_attr( max( 4, $shipping_progress ) ); ?>%"></div>
            </div>
        </section>

        <div class="pv-cart__layout">

            <!-- ===========================================================
                 COLUMNA IZQUIERDA: Items agrupados por vendor
                 =========================================================== -->
            <div class="pv-cart__items-col">

                <?php
                /**
                 * Form principal del carrito — method=post action=cart_url.
                 * WC usa el atributo name="update_cart" para detectar el submit.
                 */
                ?>
                <form name="checkout_cart" class="pv-cart__form woocommerce-cart-form" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
                    <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>

                    <?php
                    /**
                     * Hook: woocommerce_before_cart_table
                     */
                    do_action( 'woocommerce_before_cart_table' );
                    ?>

                    <?php foreach ( $vendors_groups as $vendor_id => $group ) :
                        $vendor_name = $group['name'];
                        $vendor_url  = $group['url'];
                        $vendor_items = $group['items'];
                        $vendor_subtotal = 0.0;

                        // Calcular subtotal del vendor.
                        foreach ( $vendor_items as $item ) {
                            $vendor_subtotal += (float) ( $item['line_total'] ?? 0 );
                        }
                        ?>

                        <section class="pv-cart__vendor-group" data-vendor-id="<?php echo esc_attr( $vendor_id ); ?>">

                            <!-- Encabezado del vendor -->
                            <header class="pv-cart__vendor-head">
                                <div class="pv-cart__vendor-info">
                                    <span class="pv-cart__vendor-avatar" aria-hidden="true">
                                        <?php echo esc_html( strtoupper( mb_substr( $vendor_name, 0, 1 ) ) ); ?>
                                    </span>
                                    <div class="pv-cart__vendor-meta">
                                        <?php if ( $vendor_id > 0 && $vendor_url && '#' !== $vendor_url ) : ?>
                                            <a href="<?php echo esc_url( $vendor_url ); ?>" class="pv-cart__vendor-name">
                                                <?php echo esc_html( $vendor_name ); ?>
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M17 7H8M17 7v9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </a>
                                        <?php else : ?>
                                            <span class="pv-cart__vendor-name"><?php echo esc_html( $vendor_name ); ?></span>
                                        <?php endif; ?>
                                        <span class="pv-cart__vendor-count">
                                            <?php
                                            /* translators: %d: cantidad de items del vendor. */
                                            echo esc_html( sprintf( _n( '%d artículo', '%d artículos', count( $vendor_items ), 'ltms' ), count( $vendor_items ) ) );
                                            ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="pv-cart__vendor-subtotal">
                                    <?php echo wp_kses_post( wc_price( $vendor_subtotal ) ); ?>
                                </div>
                            </header>

                            <!-- Lista de items del vendor -->
                            <ul class="pv-cart__items" role="list">
                                <?php foreach ( $vendor_items as $cart_item_key => $cart_item ) :
                                    $_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                                    $product_id = (int) apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

                                    if ( ! $_product || ! $_product->exists() || ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
                                        continue;
                                    }

                                    $permalink       = apply_filters( 'woocommerce_cart_item_permalink', $_product->get_permalink(), $cart_item, $cart_item_key );
                                    $thumbnail       = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_thumbnail', array( 'class' => 'pv-cart__item-img' ), true ), $cart_item, $cart_item_key );
                                    $product_name    = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
                                    $product_price   = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
                                    $line_subtotal   = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
                                    $qty             = (int) $cart_item['quantity'];
                                    $min_purchase    = (int) $_product->get_min_purchase_quantity();
                                    $max_purchase    = (int) $_product->get_max_purchase_quantity();
                                    if ( $min_purchase < 1 ) { $min_purchase = 1; }
                                    if ( $max_purchase < 1 ) { $max_purchase = 99; }
                                    $is_visible      = apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key );
                                    ?>

                                    <li class="pv-cart__item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">

                                        <!-- Thumbnail -->
                                        <div class="pv-cart__item-media">
                                            <?php if ( $permalink ) : ?>
                                                <a href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $product_name ); ?>"><?php echo wp_kses_post( $thumbnail ); ?></a>
                                            <?php else : ?>
                                                <?php echo wp_kses_post( $thumbnail ); ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Info -->
                                        <div class="pv-cart__item-info">
                                            <div class="pv-cart__item-top">
                                                <?php if ( $permalink ) : ?>
                                                    <a href="<?php echo esc_url( $permalink ); ?>" class="pv-cart__item-name"><?php echo wp_kses_post( $product_name ); ?></a>
                                                <?php else : ?>
                                                    <span class="pv-cart__item-name"><?php echo wp_kses_post( $product_name ); ?></span>
                                                <?php endif; ?>

                                                <?php
                                                // Variations / attributes del item.
                                                $item_data = WC()->cart->get_item_data( $cart_item );
                                                if ( $item_data ) :
                                                    ?>
                                                    <div class="pv-cart__item-attr"><?php echo wp_kses_post( $item_data ); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="pv-cart__item-bottom">
                                                <span class="pv-cart__item-unit-price">
                                                    <?php echo wp_kses_post( $product_price ); ?>
                                                    <span class="pv-cart__item-unit-label"><?php esc_html_e( '/ unidad', 'ltms' ); ?></span>
                                                </span>

                                                <!-- Quantity stepper -->
                                                <div class="pv-qty pv-qty--sm pv-cart__item-qty">
                                                    <button type="button" class="pv-qty__btn pv-qty__btn--minus" data-pv-qty-step="-1" data-pv-qty-min="<?php echo esc_attr( $min_purchase ); ?>" data-pv-qty-max="<?php echo esc_attr( $max_purchase ); ?>" aria-label="<?php esc_attr_e( 'Disminuir cantidad', 'ltms' ); ?>">−</button>
                                                    <?php
                                                    woocommerce_quantity_input(
                                                        array(
                                                            'input_name'   => "cart[{$cart_item_key}][qty]",
                                                            'input_value'  => $qty,
                                                            'min_value'    => $min_purchase,
                                                            'max_value'    => $max_purchase,
                                                            'product_name' => $product_name,
                                                        ),
                                                        $_product
                                                    );
                                                    ?>
                                                    <button type="button" class="pv-qty__btn pv-qty__btn--plus" data-pv-qty-step="1" data-pv-qty-min="<?php echo esc_attr( $min_purchase ); ?>" data-pv-qty-max="<?php echo esc_attr( $max_purchase ); ?>" aria-label="<?php esc_attr_e( 'Aumentar cantidad', 'ltms' ); ?>">+</button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Subtotal + remove -->
                                        <div class="pv-cart__item-side">
                                            <div class="pv-cart__item-subtotal"><?php echo wp_kses_post( $line_subtotal ); ?></div>
                                            <?php
                                            $remove_url = wc_get_cart_remove_url( $cart_item_key );
                                            $remove_label = __( 'Eliminar este artículo', 'ltms' );
                                            ?>
                                            <a href="<?php echo esc_url( $remove_url ); ?>" class="pv-cart__item-remove" data-pv-cart-remove="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php echo esc_attr( $remove_label ); ?>" title="<?php echo esc_attr( $remove_label ); ?>">
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 7h12M9 7V5h6v2M7 7l1 13h8l1-13" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </a>
                                        </div>
                                    </li>
                                <?php endforeach; // endforeach vendor_items ?>
                            </ul>
                        </section>
                    <?php endforeach; // endforeach vendors_groups ?>

                    <?php
                    /**
                     * Hook: woocommerce_after_cart_table
                     */
                    do_action( 'woocommerce_after_cart_table' );
                    ?>

                    <!-- Update cart button (acción inferior) -->
                    <div class="pv-cart__form-actions">
                        <button type="submit" class="pv-btn pv-btn--ghost pv-btn--sm pv-cart__update-btn" name="update_cart" value="<?php esc_attr_e( 'Actualizar carrito', 'ltms' ); ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Actualizar carrito', 'ltms' ); ?>
                        </button>
                        <?php
                        // Hook estándar de WC para el botón update (output silencioso si no existe).
                        if ( function_exists( 'woocommerce_cart_update_button' ) ) {
                            woocommerce_cart_update_button();
                        }
                        ?>
                    </div>

                    <?php do_action( 'woocommerce_cart_actions' ); ?>
                </form>

                <!-- =======================================================
                     CROSS-SELLS
                     ======================================================= -->
                <section class="pv-cart__crosssells" aria-label="<?php esc_attr_e( 'Productos relacionados', 'ltms' ); ?>">
                    <?php
                    /**
                     * woocommerce_cross_sell_display() — renderiza los cross-sells
                     * del carrito usando el template wc-parts/cart/cross-sells.php.
                     * Hook estándar de WC dentro de woocommerce_cart_collaterals.
                     */
                    if ( function_exists( 'woocommerce_cross_sell_display' ) ) {
                        woocommerce_cross_sell_display();
                    }
                    ?>
                </section>

                <?php
                /**
                 * Hook: woocommerce_after_cart
                 */
                do_action( 'woocommerce_after_cart' );
                ?>
            </div><!-- /.pv-cart__items-col -->

            <!-- ===========================================================
                 COLUMNA DERECHA: Summary sticky + cupón + CTA
                 =========================================================== -->
            <aside class="pv-cart__summary-col" aria-label="<?php esc_attr_e( 'Resumen del carrito', 'ltms' ); ?>">
                <div class="pv-cart__summary pv-card pv-card--pad-lg" data-pv-sticky>

                    <header class="pv-cart__summary-head">
                        <h2 class="pv-cart__summary-title"><?php esc_html_e( 'Resumen del pedido', 'ltms' ); ?></h2>
                        <span class="pv-badge pv-badge--verified pv-badge--dot"><?php esc_html_e( 'Compra protegida', 'ltms' ); ?></span>
                    </header>

                    <!-- =======================================================
                         CUPÓN INLINE
                         ======================================================= -->
                    <div class="pv-cart__coupon">
                        <label for="pv-cart-coupon-code" class="pv-cart__coupon-label">
                            <?php esc_html_e( '¿Tienes un cupón de descuento?', 'ltms' ); ?>
                        </label>
                        <div class="pv-input-group pv-cart__coupon-input">
                            <input type="text" id="pv-cart-coupon-code" name="coupon_code" class="pv-input pv-input--sm input-text" placeholder="<?php esc_attr_e( 'Ej. BIENVENIDA10', 'ltms' ); ?>" autocomplete="off" />
                            <button type="submit" class="pv-btn pv-btn--ghost pv-btn--sm" name="apply_coupon" value="<?php esc_attr_e( 'Aplicar', 'ltms' ); ?>" form="pv-cart-coupon-form"><?php esc_html_e( 'Aplicar', 'ltms' ); ?></button>
                        </div>
                        <?php
                        /**
                         * Form alternativo dedicado al cupón (estándar WC).
                         * El hook woocommerce_checkout_coupon_form() también se usa
                         * en carrito para el cupón colapsado; aquí lo exponemos
                         * dentro del summary como form oculto para mantener
                         * compatibilidad con WC < 7.0 que dependen del form propio.
                         */
                        ?>
                        <form id="pv-cart-coupon-form" class="pv-cart__coupon-form woocommerce-form-coupon" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>" style="display:none;">
                            <?php wp_nonce_field( 'apply-coupon', 'security' ); ?>
                            <input type="hidden" name="coupon_code" value="" />
                        </form>
                    </div>

                    <!-- Cupones aplicados -->
                    <?php if ( ! empty( $coupons ) ) : ?>
                        <ul class="pv-cart__coupons-applied" role="list">
                            <?php foreach ( $coupons as $code => $coupon_obj ) :
                                $coupon_remove_url = esc_url( add_query_arg( array( 'remove_coupon' => rawurlencode( $code ) ), wc_get_cart_url() ) );
                                ?>
                                <li class="pv-cart__coupon-chip">
                                    <span class="pv-cart__coupon-chip-code"><?php echo esc_html( strtoupper( $code ) ); ?></span>
                                    <a href="<?php echo $coupon_remove_url; ?>" class="pv-cart__coupon-chip-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Quitar cupón %s', 'ltms' ), $code ) ); ?>">×</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <!-- =======================================================
                         TOTALES
                         ======================================================= -->
                    <div class="pv-cart__totals">
                        <div class="pv-cart__totals-row">
                            <span class="pv-cart__totals-label"><?php esc_html_e( 'Subtotal', 'ltms' ); ?></span>
                            <span class="pv-cart__totals-value"><?php echo wp_kses_post( $cart_subtotal ); ?></span>
                        </div>

                        <?php if ( $discount_total > 0 ) : ?>
                            <div class="pv-cart__totals-row pv-cart__totals-row--discount">
                                <span class="pv-cart__totals-label"><?php esc_html_e( 'Descuentos', 'ltms' ); ?></span>
                                <span class="pv-cart__totals-value">−<?php echo wp_kses_post( $discount_total_fm ); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( $shipping_total > 0 ) : ?>
                            <div class="pv-cart__totals-row">
                                <span class="pv-cart__totals-label"><?php esc_html_e( 'Envío', 'ltms' ); ?></span>
                                <span class="pv-cart__totals-value"><?php echo wp_kses_post( $shipping_total_fm ); ?></span>
                            </div>
                        <?php elseif ( $has_free_shipping ) : ?>
                            <div class="pv-cart__totals-row pv-cart__totals-row--ok">
                                <span class="pv-cart__totals-label"><?php esc_html_e( 'Envío', 'ltms' ); ?></span>
                                <span class="pv-cart__totals-value"><?php esc_html_e( 'Gratis', 'ltms' ); ?></span>
                            </div>
                        <?php else : ?>
                            <div class="pv-cart__totals-row pv-cart__totals-row--muted">
                                <span class="pv-cart__totals-label"><?php esc_html_e( 'Envío', 'ltms' ); ?></span>
                                <span class="pv-cart__totals-value"><?php esc_html_e( 'Calculado al finalizar', 'ltms' ); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ( $tax_total > 0 && wc_tax_enabled() ) : ?>
                            <div class="pv-cart__totals-row">
                                <span class="pv-cart__totals-label"><?php esc_html_e( 'Impuestos', 'ltms' ); ?></span>
                                <span class="pv-cart__totals-value"><?php echo wp_kses_post( $tax_total_fm ); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="pv-cart__totals-divider"></div>

                        <div class="pv-cart__totals-row pv-cart__totals-row--total">
                            <span class="pv-cart__totals-label"><?php esc_html_e( 'Total', 'ltms' ); ?></span>
                            <span class="pv-cart__totals-value pv-cart__totals-total"><?php echo wp_kses_post( $cart_total ); ?></span>
                        </div>
                    </div>

                    <!-- =======================================================
                         ESCROW DISCLOSURE
                         ======================================================= -->
                    <div class="pv-escrow-notice pv-cart__escrow" role="note">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <div class="pv-escrow-notice__body">
                            <strong><?php esc_html_e( 'Pago en custodia (escrow)', 'ltms' ); ?></strong>
                            <p><?php esc_html_e( 'Tus fondos están protegidos: solo se liberan al vendedor cuando confirmes la recepción del pedido.', 'ltms' ); ?></p>
                        </div>
                    </div>

                    <!-- =======================================================
                         CTA: PROCEDER A CHECKOUT
                         ======================================================= -->
                    <div class="pv-cart__cta">
                        <?php
                        /**
                         * woocommerce_proceed_to_checkout() — imprime el botón
                         * estándar de WC para avanzar al checkout. Hook estándar
                         * invocado dentro de woocommerce_cart_collaterals.
                         */
                        if ( function_exists( 'woocommerce_proceed_to_checkout' ) ) {
                            woocommerce_proceed_to_checkout();
                        }
                        ?>
                        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="pv-btn pv-btn--accent pv-btn--lg pv-btn--block pv-cart__checkout-cta">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Finalizar compra', 'ltms' ); ?>
                        </a>
                    </div>

                    <!-- Métodos de pago aceptados -->
                    <div class="pv-cart__payment-methods" aria-label="<?php esc_attr_e( 'Métodos de pago aceptados', 'ltms' ); ?>">
                        <span class="pv-cart__payment-label"><?php esc_html_e( 'Pagas con:', 'ltms' ); ?></span>
                        <ul class="pv-cart__payment-list" role="list">
                            <li class="pv-cart__payment-badge">PSE</li>
                            <li class="pv-cart__payment-badge">Nequi</li>
                            <li class="pv-cart__payment-badge">Daviplata</li>
                            <li class="pv-cart__payment-badge">Tarjeta</li>
                            <li class="pv-cart__payment-badge"><?php esc_html_e( 'Contra entrega', 'ltms' ); ?></li>
                        </ul>
                    </div>
                </div>
            </aside>

        </div><!-- /.pv-cart__layout -->

        <?php
        /**
         * Hook: woocommerce_cart_collaterals
         * Cross-sells y cart totals nativos de WC. Como ya los renderizamos
         * manualmente, este hook queda para extensiones de terceros.
         */
        do_action( 'woocommerce_cart_collaterals' );
        ?>
    </main>
</div><!-- /.pv-scope.pv-cart -->

<?php
/**
 * Wrapper del tema — woocommerce_after_main_content.
 */
do_action( 'woocommerce_after_main_content' );
?>

<style>
/* ============================================================================
   CART · Plaza Viva scoped styles
   ========================================================================== */
.pv-scope.pv-cart{display:flex;flex-direction:column;gap:18px;padding-bottom:48px;}

/* Breadcrumb */
.pv-scope.pv-cart .pv-cart__breadcrumb{margin-top:8px;}

/* Header */
.pv-scope.pv-cart .pv-cart__header-inner{
    display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
}
.pv-scope.pv-cart .pv-cart__title-wrap{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.pv-scope.pv-cart .pv-cart__title{font-family:var(--display);font-weight:800;font-size:clamp(26px,3vw,36px);line-height:1.1;}
.pv-scope.pv-cart .pv-cart__header-actions{display:flex;gap:8px;align-items:center;}

/* Main */
.pv-scope.pv-cart .pv-cart__main{display:flex;flex-direction:column;gap:22px;}

/* Shipping progress bar */
.pv-scope.pv-cart .pv-cart__shipping-bar{
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-card);padding:16px 18px;
    box-shadow:var(--sh-1);
}
.pv-scope.pv-cart .pv-cart__shipping-msg{
    display:flex;align-items:center;gap:10px;margin-bottom:10px;
    font-size:14px;color:var(--text-2);line-height:1.4;
}
.pv-scope.pv-cart .pv-cart__shipping-msg svg{color:var(--primary);flex-shrink:0;}
.pv-scope.pv-cart .pv-cart__shipping-msg strong{color:var(--text);}
.pv-scope.pv-cart .pv-cart__shipping-msg--ok{color:var(--accent);}
.pv-scope.pv-cart .pv-cart__shipping-msg--ok svg{color:var(--accent);}
.pv-scope.pv-cart .pv-cart__shipping-progress{height:8px;}

/* Layout 2 col → 1 col */
.pv-scope.pv-cart .pv-cart__layout{
    display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:28px;align-items:start;
}

/* Items column */
.pv-scope.pv-cart .pv-cart__items-col{display:flex;flex-direction:column;gap:18px;min-width:0;}

/* Vendor group */
.pv-scope.pv-cart .pv-cart__vendor-group{
    background:var(--surface);border:1px solid var(--border);border-radius:var(--r-card);
    overflow:hidden;box-shadow:var(--sh-1);
}
.pv-scope.pv-cart .pv-cart__vendor-head{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:16px 20px;background:var(--bg);border-bottom:1px solid var(--border);
}
.pv-scope.pv-cart .pv-cart__vendor-info{display:flex;align-items:center;gap:12px;min-width:0;}
.pv-scope.pv-cart .pv-cart__vendor-avatar{
    width:44px;height:44px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:var(--primary-50);color:var(--primary-700);
    font-family:var(--display);font-weight:700;font-size:18px;
    border:2px solid var(--surface);box-shadow:var(--sh-1);
}
.pv-scope.pv-cart .pv-cart__vendor-meta{display:flex;flex-direction:column;gap:2px;min-width:0;}
.pv-scope.pv-cart .pv-cart__vendor-name{
    display:inline-flex;align-items:center;gap:6px;
    font-family:var(--display);font-weight:700;font-size:15px;color:var(--text);
    text-decoration:none;transition:color var(--t);
}
.pv-scope.pv-cart .pv-cart__vendor-name:hover{color:var(--primary);}
.pv-scope.pv-cart .pv-cart__vendor-name svg{color:var(--text-3);}
.pv-scope.pv-cart .pv-cart__vendor-count{font-size:12.5px;color:var(--text-3);}
.pv-scope.pv-cart .pv-cart__vendor-subtotal{
    font-family:var(--display);font-weight:700;font-size:16px;color:var(--text);
    flex-shrink:0;
}

/* Items list */
.pv-scope.pv-cart .pv-cart__items{display:flex;flex-direction:column;}
.pv-scope.pv-cart .pv-cart__item{
    display:grid;grid-template-columns:80px minmax(0,1fr) auto;gap:14px;
    padding:16px 20px;border-bottom:1px solid var(--border);
    transition:background var(--t);
}
.pv-scope.pv-cart .pv-cart__item:last-child{border-bottom:0;}
.pv-scope.pv-cart .pv-cart__item:hover{background:var(--bg);}

.pv-scope.pv-cart .pv-cart__item-media{
    width:80px;height:80px;border-radius:var(--r-md);overflow:hidden;
    background:var(--bg);border:1px solid var(--border);flex-shrink:0;
}
.pv-scope.pv-cart .pv-cart__item-media img{width:100%;height:100%;object-fit:cover;}
.pv-scope.pv-cart .pv-cart__item-media a{display:block;width:100%;height:100%;}

.pv-scope.pv-cart .pv-cart__item-info{display:flex;flex-direction:column;justify-content:space-between;min-width:0;gap:8px;}
.pv-scope.pv-cart .pv-cart__item-top{display:flex;flex-direction:column;gap:4px;}
.pv-scope.pv-cart .pv-cart__item-name{
    font-family:var(--display);font-weight:600;font-size:14.5px;line-height:1.35;color:var(--text);
    text-decoration:none;transition:color var(--t);
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.pv-scope.pv-cart .pv-cart__item-name:hover{color:var(--primary);}
.pv-scope.pv-cart .pv-cart__item-attr{font-size:12.5px;color:var(--text-3);line-height:1.4;}
.pv-scope.pv-cart .pv-cart__item-attr .pv-scope.pv-cart .pv-cart__item-attr dt,
.pv-scope.pv-cart .pv-cart__item-attr p{margin:0;}
.pv-scope.pv-cart .pv-cart__item-bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.pv-scope.pv-cart .pv-cart__item-unit-price{
    font-size:13px;color:var(--text-2);font-weight:600;
}
.pv-scope.pv-cart .pv-cart__item-unit-price .woocommerce-Price-amount{color:var(--text);font-weight:700;}
.pv-scope.pv-cart .pv-cart__item-unit-label{color:var(--text-3);font-weight:400;}

.pv-scope.pv-cart .pv-cart__item-qty{flex-shrink:0;}

.pv-scope.pv-cart .pv-cart__item-side{
    display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;gap:8px;
    min-width:90px;
}
.pv-scope.pv-cart .pv-cart__item-subtotal{
    font-family:var(--display);font-weight:700;font-size:15px;color:var(--text);text-align:right;
}
.pv-scope.pv-cart .pv-cart__item-subtotal .woocommerce-Price-amount{color:var(--primary);}
.pv-scope.pv-cart .pv-cart__item-remove{
    width:36px;height:36px;border-radius:var(--r-sm);
    display:flex;align-items:center;justify-content:center;
    color:var(--text-3);transition:background var(--t),color var(--t);
}
.pv-scope.pv-cart .pv-cart__item-remove:hover{background:var(--danger-50);color:var(--danger);}

/* Form actions */
.pv-scope.pv-cart .pv-cart__form-actions{
    display:flex;justify-content:flex-end;padding:14px 20px;
    background:var(--bg);border-top:1px solid var(--border);
}

/* Quantity input override — WC usa .qty */
.pv-scope.pv-cart .pv-qty .qty.pv-qty__input,
.pv-scope.pv-cart .pv-qty .qty{
    width:48px;height:44px;text-align:center;border:0;
    border-left:1px solid var(--border);border-right:1px solid var(--border);
    background:transparent;font-weight:600;font-size:14px;color:var(--text);
    -moz-appearance:textfield;
}
.pv-scope.pv-cart .pv-qty .qty::-webkit-outer-spin-button,
.pv-scope.pv-cart .pv-qty .qty::-webkit-inner-spin-button{
    -webkit-appearance:none;margin:0;
}

/* Cross-sells */
.pv-scope.pv-cart .pv-cart__crosssells{display:flex;flex-direction:column;gap:14px;}
.pv-scope.pv-cart .pv-cart__crosssells > .cross-sells > h2,
.pv-scope.pv-cart .pv-cart__crosssells h2{
    font-family:var(--display);font-weight:800;font-size:20px;margin-bottom:8px;
}
.pv-scope.pv-cart .pv-cart__crosssells ul.products{
    display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;
    list-style:none;margin:0;padding:0;
}

/* Summary column */
.pv-scope.pv-cart .pv-cart__summary-col{position:relative;}
.pv-scope.pv-cart .pv-cart__summary[data-pv-sticky]{
    position:sticky;top:20px;
    display:flex;flex-direction:column;gap:16px;
}
.pv-scope.pv-cart .pv-cart__summary-head{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.pv-scope.pv-cart .pv-cart__summary-title{font-family:var(--display);font-weight:800;font-size:18px;}

/* Coupon */
.pv-scope.pv-cart .pv-cart__coupon{display:flex;flex-direction:column;gap:8px;padding-bottom:14px;border-bottom:1px dashed var(--border);}
.pv-scope.pv-cart .pv-cart__coupon-label{font-size:13px;font-weight:600;color:var(--text-2);}
.pv-scope.pv-cart .pv-cart__coupon-input .pv-input{height:42px;border-radius:var(--r-sm) 0 0 var(--r-sm);}
.pv-scope.pv-cart .pv-cart__coupon-input .pv-btn{height:42px;border-radius:0 var(--r-sm) var(--r-sm) 0;}

.pv-scope.pv-cart .pv-cart__coupons-applied{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.pv-scope.pv-cart .pv-cart__coupon-chip{
    display:inline-flex;align-items:center;gap:6px;
    padding:4px 8px 4px 12px;border-radius:var(--r-pill);
    background:var(--accent-50);color:#0a8a68;
    font-size:12px;font-weight:700;letter-spacing:.02em;
}
.pv-scope.pv-cart .pv-cart__coupon-chip-remove{
    color:inherit;text-decoration:none;font-size:16px;line-height:1;
    width:20px;height:20px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;background:rgba(11,163,127,.18);
    transition:background var(--t);
}
.pv-scope.pv-cart .pv-cart__coupon-chip-remove:hover{background:rgba(11,163,127,.32);}

/* Totals */
.pv-scope.pv-cart .pv-cart__totals{display:flex;flex-direction:column;gap:8px;}
.pv-scope.pv-cart .pv-cart__totals-row{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    font-size:14px;color:var(--text-2);
}
.pv-scope.pv-cart .pv-cart__totals-label{font-weight:500;}
.pv-scope.pv-cart .pv-cart__totals-value{font-weight:600;color:var(--text);text-align:right;}
.pv-scope.pv-cart .pv-cart__totals-value .woocommerce-Price-amount{font-weight:700;}
.pv-scope.pv-cart .pv-cart__totals-row--discount .pv-cart__totals-value,
.pv-scope.pv-cart .pv-cart__totals-row--discount .pv-cart__totals-value .woocommerce-Price-amount{color:var(--accent);}
.pv-scope.pv-cart .pv-cart__totals-row--ok .pv-cart__totals-value{color:var(--accent);font-weight:700;}
.pv-scope.pv-cart .pv-cart__totals-row--muted .pv-cart__totals-value{color:var(--text-3);font-size:12.5px;font-weight:500;}
.pv-scope.pv-cart .pv-cart__totals-divider{height:1px;background:var(--border);margin:6px 0;}
.pv-scope.pv-cart .pv-cart__totals-row--total{align-items:baseline;padding-top:6px;}
.pv-scope.pv-cart .pv-cart__totals-row--total .pv-cart__totals-label{font-family:var(--display);font-weight:700;font-size:16px;color:var(--text);}
.pv-scope.pv-cart .pv-cart__totals-total{font-family:var(--display);font-weight:800;font-size:24px;color:var(--primary);}

/* Escrow notice */
.pv-scope.pv-cart .pv-escrow-notice{
    display:flex;gap:12px;padding:14px 16px;
    background:var(--accent-50);border:1px solid var(--accent-100);
    border-radius:var(--r-md);
}
.pv-scope.pv-cart .pv-escrow-notice svg{color:var(--accent);flex-shrink:0;}
.pv-scope.pv-cart .pv-escrow-notice__body{display:flex;flex-direction:column;gap:2px;font-size:13px;line-height:1.45;color:var(--text-2);}
.pv-scope.pv-cart .pv-escrow-notice__body strong{color:var(--text);font-size:13.5px;}
.pv-scope.pv-cart .pv-escrow-notice__body p{margin:0;}

/* CTA */
.pv-scope.pv-cart .pv-cart__cta{display:flex;flex-direction:column;gap:8px;padding-top:6px;}
.pv-scope.pv-cart .pv-cart__cta .checkout-button{display:none;} /* oculto el botón WC por defecto */
.pv-scope.pv-cart .pv-cart__checkout-cta{height:56px;}

/* Payment methods */
.pv-scope.pv-cart .pv-cart__payment-methods{display:flex;flex-direction:column;gap:8px;padding-top:10px;border-top:1px solid var(--border);}
.pv-scope.pv-cart .pv-cart__payment-label{font-size:12px;color:var(--text-3);font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.pv-scope.pv-cart .pv-cart__payment-list{display:flex;flex-wrap:wrap;gap:6px;list-style:none;margin:0;padding:0;}
.pv-scope.pv-cart .pv-cart__payment-badge{
    padding:5px 10px;border-radius:var(--r-sm);
    background:var(--bg);border:1px solid var(--border);
    font-size:11.5px;font-weight:700;color:var(--text-2);letter-spacing:.02em;
}

/* Empty cart */
.pv-scope.pv-cart .pv-cart__empty{padding:80px 22px;}
.pv-scope.pv-cart .pv-cart__empty-card{
    max-width:520px;margin:0 auto;text-align:center;padding:48px 32px;
    display:flex;flex-direction:column;align-items:center;gap:14px;
}
.pv-scope.pv-cart .pv-cart__empty-icon{color:var(--text-3);margin-bottom:6px;}
.pv-scope.pv-cart .pv-cart__empty-title{font-family:var(--display);font-weight:800;font-size:26px;}
.pv-scope.pv-cart .pv-cart__empty-sub{color:var(--text-2);font-size:15px;line-height:1.5;margin:0;}

/* ============================================================================
   RESPONSIVE
   ========================================================================== */
@media (max-width:1100px){
    .pv-scope.pv-cart .pv-cart__layout{grid-template-columns:1fr;gap:20px;}
    .pv-scope.pv-cart .pv-cart__summary[data-pv-sticky]{position:static;top:auto;}
}
@media (max-width:760px){
    .pv-scope.pv-cart .pv-cart__item{grid-template-columns:64px minmax(0,1fr);gap:12px;padding:14px 14px;}
    .pv-scope.pv-cart .pv-cart__item-media{width:64px;height:64px;}
    .pv-scope.pv-cart .pv-cart__item-side{
        grid-column:1 / -1;flex-direction:row;align-items:center;justify-content:space-between;
        min-width:0;padding-top:8px;border-top:1px dashed var(--border);
    }
    .pv-scope.pv-cart .pv-cart__vendor-head{padding:14px;}
    .pv-scope.pv-cart .pv-cart__vendor-subtotal{font-size:14px;}
    .pv-scope.pv-cart .pv-cart__form-actions{padding:12px 14px;}
    .pv-scope.pv-cart .pv-cart__summary{padding:18px;}
    .pv-scope.pv-cart .pv-cart__summary-title{font-size:16px;}
    .pv-scope.pv-cart .pv-cart__totals-total{font-size:20px;}
    .pv-scope.pv-cart .pv-cart__checkout-cta{height:52px;}
}
@media (max-width:480px){
    .pv-scope.pv-cart .pv-cart__title{font-size:22px;}
    .pv-scope.pv-cart .pv-cart__vendor-name{font-size:14px;}
    .pv-scope.pv-cart .pv-cart__item-bottom{flex-direction:column;align-items:flex-start;gap:8px;}
    .pv-scope.pv-cart .pv-cart__coupon-input{flex-direction:column;}
    .pv-scope.pv-cart .pv-cart__coupon-input .pv-input{border-radius:var(--r-sm);}
    .pv-scope.pv-cart .pv-cart__coupon-input .pv-btn{border-radius:var(--r-sm);width:100%;}
}
</style>

<script>
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-cart');
    if (!scope) return;

    /* --- 1. Quantity stepper (botones +/- actualizan el input) -------- */
    var qtyWraps = Array.prototype.slice.call(scope.querySelectorAll('.pv-cart__item-qty'));
    qtyWraps.forEach(function(wrap){
        var input = wrap.querySelector('.qty');
        var minus = wrap.querySelector('.pv-qty__btn--minus');
        var plus  = wrap.querySelector('.pv-qty__btn--plus');
        var min   = parseInt(wrap.getAttribute('data-pv-qty-min') || (input && input.min) || 1, 10);
        var max   = parseInt(wrap.getAttribute('data-pv-qty-max') || (input && input.max) || 99, 10);
        if (!input) return;
        if (minus){
            minus.addEventListener('click', function(){
                var v = parseInt(input.value || 0, 10);
                if (isNaN(v)) v = min;
                v = Math.max(min, v - 1);
                input.value = v;
                input.dispatchEvent(new Event('change', { bubbles:true }));
            });
        }
        if (plus){
            plus.addEventListener('click', function(){
                var v = parseInt(input.value || 0, 10);
                if (isNaN(v)) v = min;
                v = Math.min(max, v + 1);
                input.value = v;
                input.dispatchEvent(new Event('change', { bubbles:true }));
            });
        }
    });

    /* --- 2. Coupon inline — sincroniza input visible con form WC ------ */
    var couponInput = scope.querySelector('#pv-cart-coupon-code');
    var couponForm  = scope.querySelector('#pv-cart-coupon-form');
    var couponBtn   = scope.querySelector('[name="apply_coupon"]');
    if (couponInput && couponForm && couponBtn){
        couponBtn.addEventListener('click', function(e){
            var hidden = couponForm.querySelector('input[name="coupon_code"]');
            if (hidden){ hidden.value = couponInput.value; }
            // Re-dirigimos el submit al form nativo de WC para que aplique.
            e.preventDefault();
            couponForm.submit();
        });
        // Permitir Enter para aplicar.
        couponInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter'){
                e.preventDefault();
                couponBtn.click();
            }
        });
    }

    /* --- 3. Update cart highlight (cuando cambian cantidades) ---------- */
    var qtyInputs = Array.prototype.slice.call(scope.querySelectorAll('.pv-cart__item-qty .qty'));
    var updateBtn = scope.querySelector('.pv-cart__update-btn');
    if (qtyInputs.length && updateBtn){
        qtyInputs.forEach(function(input){
            input.addEventListener('change', function(){
                updateBtn.classList.add('is-pending');
            });
        });
    }
})();
</script>

<?php
get_footer( 'shop' );
