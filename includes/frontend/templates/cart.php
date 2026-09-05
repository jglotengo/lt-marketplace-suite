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
    echo '<div class="pv-scope pv-cart"><main class="pv-section pv-fallback__section"><p class="pv-fallback__msg">' . esc_html__( 'WooCommerce no está activo o el carrito no está disponible.', 'ltms' ) . '</p></main></div>';
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
            </div><!-- /.pv-cart__empty-card -->

            <?php
            // CART-EMPTY-PRODUCTS (2026-09-05): mostrar productos directamente
            // en el carrito vacio (4 recientes) para que el usuario no quede
            // con una pantalla muerta.
            // CART-EMPTY-CARD-STD FIX (2026-09-05): usar el MISMO markup compacto
            // del home (woocommerce-loop-product__link + __title + price) en vez
            // de content-product.php (card PV completa, mas alta 287x732 vs
            // 264x472 del home). Asi enhanceElementorCards() las mejora igual
            // que las del home (agrega .pv-enhanced + rating + vendor + fav).
            $pv_empty_q = new WP_Query( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 8,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ] );
            if ( $pv_empty_q->have_posts() ) :
                ?>
                <section class="pv-cart__empty-products" aria-label="<?php esc_attr_e( 'Productos para ti', 'ltms' ); ?>">
                    <h2 class="pv-cart__empty-products-title"><?php esc_html_e( 'Productos para ti', 'ltms' ); ?></h2>
                    <ul class="products pv-cart-empty-grid">
                        <?php
                        while ( $pv_empty_q->have_posts() ) :
                            $pv_empty_q->the_post();
                            $_p = wc_get_product( get_the_ID() );
                            if ( ! $_p ) {
                                continue;
                            }
                            ?>
                            <li class="<?php echo esc_attr( implode( ' ', get_post_class( 'product' ) ) ); ?>">
                                <a href="<?php echo esc_url( $_p->get_permalink() ); ?>" class="woocommerce-loop-product__link">
                                    <?php echo $_p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <h2 class="woocommerce-loop-product__title"><?php echo esc_html( $_p->get_name() ); ?></h2>
                                    <span class="price"><?php echo wp_kses_post( $_p->get_price_html() ); ?></span>
                                </a>
                            </li>
                            <?php
                        endwhile;
                        ?>
                    </ul>
                </section>
                <?php
                wp_reset_postdata();
            endif;
            ?>
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
 *
 * AUDIT-FE-PV-DS-CICLO1 — P1-9 CERRADO COMO OBSOLETO (decisión de producto
 * 2026-08-25): el hallazgo original ("umbral hardcodeado sin válvula") estaba
 * desactualizado — este bloque ya lee la config WC y expone el filtro
 * ltms_cart_free_shipping_threshold. Limitación conocida ACEPTADA: si la
 * moneda activa ≠ moneda base, el umbral NO se convierte con el currency
 * switcher (la tienda opera single-currency; reabrir solo si se activa
 * multi-moneda real). Ver CHANGELOG PLAZA-VIVA-DS-AUDIT-CICLO1.
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
         HEADER: Título + count + Vaciar carrito + Seguir comprando
         AUDIT-FE-CART-009 FIX (Fase 1.6): botón "Vaciar carrito" añadido.
         Antes el comment HTML mencionaba esta acción pero NO existía el
         botón. Ahora se invoca via AJAX (ltms_pv_empty_cart handler) con
         confirmación accesible (data-pv-empty-cart + JS delegado en
         ltms-plaza-viva.js). El botón requiere confirmación antes de
         vaciar para evitar click accidental (UX-hostil sin undo).
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
                <button type="button" class="pv-btn pv-btn--ghost pv-btn--sm pv-btn--danger pv-cart__empty-btn" data-pv-empty-cart aria-label="<?php esc_attr_e( 'Vaciar todo el carrito', 'ltms' ); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 7h12M9 7V5h6v2M7 7l1 13h8l1-13" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e( 'Vaciar carrito', 'ltms' ); ?>
                </button>
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
                                    // AUDIT-FE-CART-005 FIX: removido $is_visible (variable dead code
                                    // — asignada con apply_filters pero nunca leída. La visibilidad ya
                                    // se chequeó arriba con apply_filters('woocommerce_cart_item_visible').
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
                        <!-- AUDIT-FE-PV-DS-006 FIX (P1-4): ocultamiento via atributo
                             style inline reemplazado por la clase utilitaria .d-none
                             del design system (CSP-friendly, paridad con
                             .pv-scope .d-none en ltms-plaza-viva.css). -->
                        <form id="pv-cart-coupon-form" class="pv-cart__coupon-form woocommerce-form-coupon d-none" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
                            <?php wp_nonce_field( 'apply-coupon', 'security' ); ?>
                            <input type="hidden" name="coupon_code" value="" />
                        </form>
                    </div>

                    <!-- Cupones aplicados -->
                    <?php if ( ! empty( $coupons ) ) : ?>
                        <ul class="pv-cart__coupons-applied" role="list">
                            <?php foreach ( $coupons as $code => $coupon_obj ) :
                                $coupon_remove_url = add_query_arg( array( 'remove_coupon' => rawurlencode( $code ) ), wc_get_cart_url() );
                                ?>
                                <li class="pv-cart__coupon-chip">
                                    <span class="pv-cart__coupon-chip-code"><?php echo esc_html( strtoupper( $code ) ); ?></span>
                                    <a href="<?php echo esc_url( $coupon_remove_url ); ?>" class="pv-cart__coupon-chip-remove" aria-label="<?php echo esc_attr( sprintf( __( 'Quitar cupón %s', 'ltms' ), $code ) ); ?>">×</a>
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
                         Jerarquía v2.9.212:
                           1° Finalizar compra (primary, brand red)
                           2° Seguir comprando (ghost, header)
                         Botón WC .checkout-button oculto (lo reemplazamos).
                         ======================================================= -->
                    <div class="pv-cart__cta">
                        <?php
                        /**
                         * woocommerce_proceed_to_checkout() — imprime el botón
                         * estándar de WC para avanzar al checkout. Lo ocultamos
                         * via CSS (.checkout-button { display:none }) y usamos
                         * nuestro botón con el design system PV + brand color.
                         */
                        if ( function_exists( 'woocommerce_proceed_to_checkout' ) ) {
                            woocommerce_proceed_to_checkout();
                        }
                        ?>
                        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="pv-btn pv-btn--brand pv-btn--lg pv-btn--block pv-cart__checkout-cta">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Finalizar compra', 'ltms' ); ?>
                        </a>
                        <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="pv-btn pv-btn--ghost pv-btn--block pv-cart__continue-cta">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Seguir comprando', 'ltms' ); ?>
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
         *
         * v2.9.213: Removidas las acciones default de WC (woocommerce_cross_sell_display
         * y woocommerce_cart_totals) porque YA las renderizamos manualmente arriba:
         *   - Cross-sells: línea 443 (woocommerce_cross_sell_display() en .pv-cart__crosssells)
         *   - Totales: en .pv-cart__summary (nuestro diseño con tarjeta + total destacado)
         *   - CTA Finalizar compra: en .pv-cart__cta (nuestro botón brand red)
         *
         * Sin este remove_action, WC imprimía DUPLICADO:
         *   - "Totales del carrito" nativo de WC debajo de nuestro summary
         *   - "Finalizar compra" nativo de WC debajo del nuestro
         *
         * Mantenemos el do_action() para que extensiones de terceros puedan
         * engancharse si lo necesitan.
         */
        remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
        remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cart_totals' );
        do_action( 'woocommerce_cart_collaterals' );
        ?>
    </main>
</div><!-- /.pv-scope.pv-cart -->

<?php
/**
 * Wrapper del tema — woocommerce_after_main_content.
 */
do_action( 'woocommerce_after_main_content' );

// AUDIT-FE-CART-001 FIX (Fase 1.6): restaurar el breadcrumb en el hook para
// no afectar al resto del sitio. Paridad con single-product.php:722-725. El
// remove_action anterior (línea ~170) sin este add_action dejaba
// desenganchado el breadcrumb para cualquier caller posterior del hook en el
// mismo request (SEO plugins, schema.org breadcrumbs en footer, themes que
// esperan woocommerce_breadcrumb registrado en woocommerce_before_main_content).
if ( ! empty( $pv_breadcrumb_was_hooked ) ) {
    add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
?>

<!-- AUDIT-FE-UIUX-BACKLOG-D32 FIX: los estilos scoped del carrito viven ahora en
     assets/css/ltms-cart.css, encolada condicionalmente en paginas de carrito
     (class-ltms-native-templates.php). -->

<?php
/* AUDIT-FE-CART-001 FIX (Fase 1.6): el bloque script-tag inline original
 * (líneas 962-1029 del source pre-fix) fue migrado al design system global
 * assets/js/ltms-plaza-viva.js (scope CART al final del archivo). Esta
 * plantilla ya NO contiene lógica JS inline — paridad con vendor-store.php
 * (100% CSP-compliant tras AUDIT-FE-VS-JT-001). Los 4 behaviours migrados:
 *   1. Quantity stepper (botones +/- actualizan el input + dispatch 'change')
 *   2. Coupon inline (sincroniza input visible con form WC oculto + Enter)
 *   3. Update cart highlight (marca el botón 'Actualizar carrito' is-pending)
 *   4. AUDIT-FE-CART-009: empty cart con confirmación + AJAX handler
 *      (wp_ajax_ltms_pv_empty_cart registrado en class-ltms-frontend-checkout-handler.php)
 */

get_footer( 'shop' );
