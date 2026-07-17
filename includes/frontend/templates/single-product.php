<?php
/**
 * Template: Single Product — Plaza Viva Design System
 *
 * Plantilla nativa de página de producto para WooCommerce multi-vendor.
 * Reemplaza al template de Elementor vía `template_include` (ver
 * LTMS_Native_Templates::maybe_override()).
 *
 * Características:
 *  - Layout 2 columnas (galería + info) → 1 columna en móvil.
 *  - Vendor mini-card con avatar, nombre, Star Seller badge y rating.
 *  - Stock progress bar dinámico desde _stock / _low_stock_amount.
 *  - Sticky nav por anclas (sin tabs) con resaltado de sección activa.
 *  - Bundle deals con checkboxes y total dinámico (JS).
 *  - Countdown timer desde meta _ltms_sale_ends_at.
 *  - Reviews (comments_template) + Related products.
 *  - Sticky ATC bar (mobile) con price + add-to-cart AJAX.
 *
 * Usa WC hooks estándar (compatible WC 7.0 → 8.9) y el design system
 * "Plaza Viva" (assets/css/ltms-plaza-viva.css + assets/js/ltms-plaza-viva.js).
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar que WooCommerce está cargado y tenemos objeto producto.
if ( ! function_exists( 'wc_get_product' ) ) {
    return;
}

global $product;

if ( ! $product instanceof WC_Product ) {
    $product = wc_get_product( get_the_ID() );
}
if ( ! $product ) {
    // Fallback al contenido por defecto si el producto no resuelve.
    get_header( 'shop' );
    woocommerce_content();
    get_footer( 'shop' );
    return;
}

$product_id = $product->get_id();
$post_id    = $product_id;

/* ---------------------------------------------------------------------------
 * 1. Datos del vendor (multi-vendor)
 * ------------------------------------------------------------------------- */
$vendor_id = (int) get_post_field( 'post_author', $post_id );
$vendor_user = $vendor_id > 0 ? get_userdata( $vendor_id ) : null;

$store_name = '';
if ( $vendor_id > 0 ) {
    $store_name = (string) get_user_meta( $vendor_id, 'ltms_store_name', true );
}
if ( '' === $store_name && $vendor_user ) {
    $store_name = $vendor_user->display_name ?: $vendor_user->user_login;
}

$store_slug = $vendor_id > 0 ? (string) get_user_meta( $vendor_id, 'ltms_store_slug', true ) : '';
if ( $store_slug ) {
    $store_url = home_url( '/vendedor/' . rawurlencode( $store_slug ) );
} elseif ( $vendor_id > 0 ) {
    $store_url = get_author_posts_url( $vendor_id );
} else {
    $store_url = '#';
}

$kyc_status   = $vendor_id > 0 ? (string) get_user_meta( $vendor_id, 'ltms_kyc_status', true ) : '';
$kyc_approved = ( 'approved' === $kyc_status );

// Ventas del vendor (con cache transient interno).
$vendor_sales = 0;
if ( class_exists( 'LTMS_Trust_Badges' ) && method_exists( 'LTMS_Trust_Badges', 'get_vendor_sales_count' ) ) {
    $vendor_sales = (int) LTMS_Trust_Badges::get_vendor_sales_count( $vendor_id );
}

// Star Seller: KYC aprobado + al menos 50 ventas completadas.
$star_seller = ( $kyc_approved && $vendor_sales >= 50 );

// Rating del vendor (meta opcional, fallback al rating del producto).
$vendor_rating = 0.0;
if ( $vendor_id > 0 ) {
    $vr = get_user_meta( $vendor_id, 'ltms_vendor_rating', true );
    if ( '' !== $vr ) {
        $vendor_rating = (float) $vr;
    }
}
if ( $vendor_rating <= 0 ) {
    $vendor_rating = (float) $product->get_average_rating();
}

/* ---------------------------------------------------------------------------
 * 2. Stock progress bar
 * ------------------------------------------------------------------------- */
$manage_stock = $product->get_manage_stock();
$stock_qty    = $product->get_stock_quantity(); // int|null.
$low_amount   = (int) get_post_meta( $post_id, '_low_stock_amount', true );
if ( $low_amount <= 0 ) {
    $low_amount = 5; // Umbral por defecto razonable.
}
$in_stock = $product->is_in_stock();
$backordered = ( method_exists( $product, 'get_backorders' ) && in_array( $product->get_backorders(), array( 'yes', 'notify' ), true ) );

$stock_bar_class = '';
$stock_pct       = 0;
$stock_label     = '';
if ( $manage_stock && null !== $stock_qty ) {
    if ( $stock_qty <= 0 ) {
        $stock_label = $backordered ? __( 'Disponible bajo pedido', 'ltms' ) : __( 'Agotado', 'ltms' );
    } else {
        $cap     = max( $low_amount * 4, 20, $stock_qty );
        $stock_pct = (int) min( 100, max( 4, round( ( $stock_qty / $cap ) * 100 ) ) );
        if ( $stock_qty <= $low_amount ) {
            $stock_bar_class = 'pv-stock-bar--danger';
            /* translators: %d: unidades restantes. */
            $stock_label = sprintf( __( '¡Solo quedan %d unidades!', 'ltms' ), $stock_qty );
        } elseif ( $stock_qty <= $low_amount * 2 ) {
            $stock_bar_class = 'pv-stock-bar--warn';
            $stock_label = sprintf( __( 'Stock limitado (%d unidades)', 'ltms' ), $stock_qty );
        } else {
            $stock_label = sprintf( __( '%d unidades disponibles', 'ltms' ), $stock_qty );
        }
    }
} elseif ( $in_stock ) {
    $stock_label = __( 'En stock', 'ltms' );
} else {
    $stock_label = __( 'Agotado', 'ltms' );
}

/* ---------------------------------------------------------------------------
 * 3. Countdown timer (meta _ltms_sale_ends_at)
 * ------------------------------------------------------------------------- */
$sale_ends_raw = get_post_meta( $post_id, '_ltms_sale_ends_at', true );
$sale_ends_ts  = 0;
if ( $sale_ends_raw ) {
    if ( is_numeric( $sale_ends_raw ) ) {
        $sale_ends_ts = (int) $sale_ends_raw;
    } else {
        $sale_ends_ts = (int) strtotime( (string) $sale_ends_raw );
    }
}
$now_ts          = time();
$sale_remaining  = ( $sale_ends_ts && $sale_ends_ts > $now_ts ) ? ( $sale_ends_ts - $now_ts ) : 0;
$sale_ends_iso   = $sale_ends_ts ? gmdate( 'c', $sale_ends_ts ) : '';
$has_countdown   = ( $sale_remaining > 0 && $product->is_on_sale() );

/* ---------------------------------------------------------------------------
 * 4. Bundle deals (cross-sells del producto)
 * ------------------------------------------------------------------------- */
$bundle_ids   = $product->get_cross_sell_ids();
$bundle_ids   = array_slice( array_map( 'intval', $bundle_ids ), 0, 3 );
$bundle_items = array();
foreach ( $bundle_ids as $bid ) {
    $bp = wc_get_product( $bid );
    if ( $bp && $bp->is_purchasable() && $bp->get_price() !== '' ) {
        $bundle_items[] = $bp;
    }
}
$has_bundle = ! empty( $bundle_items );
$base_price = (float) $product->get_price();
$bundle_discount = 10; // % de descuento al comprar 2+.

/* ---------------------------------------------------------------------------
 * 5. Rating del producto (conteo de reseñas para badges y secciones)
 * ------------------------------------------------------------------------- */
$review_count = (int) $product->get_review_count();

get_header( 'shop' );

/**
 * Hook: ltms_before_single_product_plazaviva
 * Permite inyectar contenido antes del contenedor principal.
 */
do_action( 'ltms_before_single_product_plazaviva', $product );

/**
 * Wrapper del tema — woocommerce_before_main_content.
 * Se invoca FUERA de .pv-scope para que el wrapper de apertura y cierre
 * queden balanceados. Evitamos el breadcrumb duplicado desenganchando
 * temporalmente woocommerce_breadcrumb (prioridad 20) porque lo
 * renderizamos explícitamente dentro del scope.
 */
$pv_breadcrumb_was_hooked = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( $pv_breadcrumb_was_hooked ) {
    remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
do_action( 'woocommerce_before_main_content' );
?>

<div class="pv-scope pv-product-page"<?php echo $has_countdown ? ' data-pv-on-sale' : ''; ?>>

    <?php
    /**
     * Breadcrumb — woocommerce_breadcrumb().
     * Hook estándar de WC para la migaja de navegación.
     */
    ?>
    <div class="pv-product-page__breadcrumb pv-section pv-section--tight">
        <?php woocommerce_breadcrumb(); ?>
    </div>

    <main class="pv-product-page__main pv-section" id="pv-product-main" role="main">

        <?php
        /**
         * Hook: woocommerce_before_single_product
         * Notas de venta flash, mensajes de stock, etc.
         */
        do_action( 'woocommerce_before_single_product' );
        ?>

        <div class="pv-product-page__layout">

            <?php
            /* =====================================================================
             * COLUMNA IZQUIERDA — Galería
             * ===================================================================== */
            ?>
            <div class="pv-product-gallery" aria-label="<?php esc_attr_e( 'Galería de imágenes del producto', 'ltms' ); ?>">
                <?php
                /**
                 * Hook: woocommerce_before_single_product_summary (prioridad 5).
                 * Flash de venta + imágenes principales.
                 * woocommerce_show_product_images() ya incluye los thumbnails
                 * (woocommerce_product_thumbnails()) dentro de su template,
                 * por eso no se invocan por separado para evitar duplicados.
                 */
                do_action( 'woocommerce_before_single_product_summary' );
                ?>
            </div><!-- /.pv-product-gallery -->

            <?php
            /* =====================================================================
             * COLUMNA DERECHA — Info del producto
             * ===================================================================== */
            ?>
            <div class="pv-product-info">

                <?php
                /**
                 * Título — woocommerce_template_single_title().
                 * (Callback estándar del hook woocommerce_single_product_summary,
                 *  invocado directamente para controlar el orden del layout.)
                 */
                woocommerce_template_single_title();

                /* -----------------------------------------------------------------
                 * Vendor mini-card (multi-vendor)
                 * --------------------------------------------------------------- */
                if ( $vendor_id > 0 ) :
                ?>
                    <div class="pv-vendor-minicard" <?php echo $store_url !== '#' ? '' : 'aria-hidden="true"'; ?>>
                        <a class="pv-vendor-minicard__link" href="<?php echo esc_url( $store_url ); ?>">
                            <span class="pv-vendor-minicard__avatar">
                                <?php echo get_avatar( $vendor_id, 44, '', $store_name, array( 'class' => 'pv-vendor-minicard__img' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </span>
                            <span class="pv-vendor-minicard__body">
                                <span class="pv-vendor-minicard__name">
                                    <?php echo esc_html( $store_name ); ?>
                                    <?php if ( $star_seller ) : ?>
                                        <span class="pv-badge pv-badge--gold" title="<?php esc_attr_e( 'Star Seller: vendedor verificado con alto volumen de ventas', 'ltms' ); ?>">
                                            <?php esc_html_e( '★ Star Seller', 'ltms' ); ?>
                                        </span>
                                    <?php elseif ( $kyc_approved ) : ?>
                                        <span class="pv-badge pv-badge--verified" title="<?php esc_attr_e( 'Vendedor verificado (KYC aprobado)', 'ltms' ); ?>">
                                            <?php esc_html_e( 'Verificado', 'ltms' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="pv-vendor-minicard__meta">
                                    <?php if ( $vendor_rating > 0 ) : ?>
                                        <span class="pv-stars" data-rating="<?php echo esc_attr( $vendor_rating ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Valoración del vendedor: %s de 5', 'ltms' ), number_format_i18n( $vendor_rating, 1 ) ) ); ?>">
                                            <?php echo wc_get_rating_html( $vendor_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            <span class="pv-stars__num"><?php echo esc_html( number_format_i18n( $vendor_rating, 1 ) ); ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $vendor_sales > 0 ) : ?>
                                        <span class="pv-vendor-minicard__sales">
                                            <?php
                                            /* translators: %d: número de ventas. */
                                            echo esc_html( sprintf( _n( '%d venta', '%d ventas', $vendor_sales, 'ltms' ), $vendor_sales ) );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </a>
                        <a class="pv-btn pv-btn--ghost pv-btn--sm pv-vendor-minicard__visit"
                           href="<?php echo esc_url( $store_url ); ?>"
                           aria-label="<?php echo esc_attr( sprintf( __( 'Visitar la tienda de %s', 'ltms' ), $store_name ) ); ?>">
                            <?php esc_html_e( 'Ver tienda', 'ltms' ); ?>
                        </a>
                    </div><!-- /.pv-vendor-minicard -->
                <?php endif; ?>

                <?php
                /* -----------------------------------------------------------------
                 * Rating — woocommerce_template_single_rating().
                 * (Callback estándar del hook woocommerce_single_product_summary,
                 *  prioridad 10. Muestra estrellas + enlace a #reviews.)
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-info__rating">
                    <?php woocommerce_template_single_rating(); ?>
                </div>

                <?php
                /* -----------------------------------------------------------------
                 * Precio — woocommerce_template_single_price().
                 * (Callback estándar del hook woocommerce_single_product_summary,
                 *  prioridad 10.)
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-info__price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                    <?php do_action( 'ltms_before_single_product_price', $product ); ?>
                    <?php woocommerce_template_single_price(); ?>
                    <?php do_action( 'ltms_after_single_product_price', $product ); ?>
                </div>

                <?php
                /* -----------------------------------------------------------------
                 * Countdown timer (solo si hay _ltms_sale_ends_at futuro).
                 * --------------------------------------------------------------- */
                if ( $has_countdown ) :
                ?>
                    <div class="pv-product-info__countdown" role="timer" aria-live="off" aria-label="<?php esc_attr_e( 'Tiempo restante de la oferta', 'ltms' ); ?>">
                        <span class="pv-product-info__countdown-label"><?php esc_html_e( 'La oferta termina en:', 'ltms' ); ?></span>
                        <div class="pv-countdown pv-countdown--lg"
                             data-ends="<?php echo esc_attr( $sale_ends_iso ); ?>"
                             data-pv-countdown="<?php echo esc_attr( $sale_remaining ); ?>"
                             data-pv-countdown-label>
                            <span class="pv-countdown__item">
                                <span class="pv-countdown__num">--</span>
                                <span class="pv-countdown__lbl"><?php esc_html_e( 'días', 'ltms' ); ?></span>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                /* -----------------------------------------------------------------
                 * Stock progress bar
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-info__stock">
                    <?php if ( $manage_stock && null !== $stock_qty && $stock_qty > 0 ) : ?>
                        <div class="pv-stock-bar <?php echo esc_attr( $stock_bar_class ); ?>">
                            <div class="pv-stock-bar__label">
                                <span><?php echo esc_html( $stock_label ); ?></span>
                                <span class="pv-stock-bar__pct"><?php echo esc_html( $stock_pct . '%' ); ?></span>
                            </div>
                            <div class="pv-stock-bar__track">
                                <div class="pv-stock-bar__fill" style="width:<?php echo esc_attr( $stock_pct ); ?>%"></div>
                            </div>
                        </div>
                    <?php else : ?>
                        <span class="pv-badge <?php echo $in_stock ? 'pv-badge--verified' : 'pv-badge--danger'; ?>">
                            <?php echo esc_html( $stock_label ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php
                /* -----------------------------------------------------------------
                 * Excerpt / short description (hook estándar).
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-info__excerpt">
                    <?php woocommerce_template_single_excerpt(); ?>
                </div>

                <?php
                /* -----------------------------------------------------------------
                 * Add to cart — envuelto en .pv-product-actions.
                 * woocommerce_template_single_add_to_cart() renderiza el form
                 * con cantidad, variaciones y botón (compatible WC AJAX).
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-actions" data-pv-sticky-sentinel>
                    <?php do_action( 'ltms_before_single_product_actions', $product ); ?>
                    <?php woocommerce_template_single_add_to_cart(); ?>
                    <?php do_action( 'ltms_after_single_product_actions', $product ); ?>
                </div><!-- /.pv-product-actions -->

                <?php
                /* -----------------------------------------------------------------
                 * Meta (SKU, categorías, etiquetas) — hook estándar.
                 * --------------------------------------------------------------- */
                ?>
                <div class="pv-product-info__meta">
                    <?php woocommerce_template_single_meta(); ?>
                </div>

            </div><!-- /.pv-product-info -->
        </div><!-- /.pv-product-page__layout -->

        <?php
        /* =====================================================================
         * STICKY NAV por anclas (sin tabs)
         * =====================================================================
         */
        ?>
        <nav class="pv-sticky-nav" aria-label="<?php esc_attr_e( 'Secciones del producto', 'ltms' ); ?>" data-pv-sticky-nav>
            <ul class="pv-sticky-nav__list">
                <li><a class="pv-sticky-nav__link is-active" href="#pv-descripcion"><?php esc_html_e( 'Descripción', 'ltms' ); ?></a></li>
                <li><a class="pv-sticky-nav__link" href="#pv-specs"><?php esc_html_e( 'Especificaciones', 'ltms' ); ?></a></li>
                <li><a class="pv-sticky-nav__link" href="#pv-reseñas"><?php esc_html_e( 'Reseñas', 'ltms' ); ?>
                        <?php if ( $review_count > 0 ) : ?>
                            <span class="pv-sticky-nav__count"><?php echo esc_html( number_format_i18n( $review_count ) ); ?></span>
                        <?php endif; ?>
                    </a></li>
                <li><a class="pv-sticky-nav__link" href="#pv-envio"><?php esc_html_e( 'Envío', 'ltms' ); ?></a></li>
            </ul>
        </nav>

        <?php
        /* =====================================================================
         * BUNDLE DEALS section
         * =====================================================================
         */
        if ( $has_bundle ) :
        ?>
            <section class="pv-bundle" id="pv-bundle" aria-labelledby="pv-bundle-title">
                <header class="pv-bundle__head">
                    <h2 id="pv-bundle-title" class="pv-bundle__title"><?php esc_html_e( 'Cómpralo junto y ahorra', 'ltms' ); ?></h2>
                    <p class="pv-bundle__sub">
                        <?php
                        /* translators: %d: porcentaje de descuento. */
                        echo esc_html( sprintf( __( 'Compra 2 o más y ahorra %d%%', 'ltms' ), $bundle_discount ) );
                        ?>
                    </p>
                </header>

                <ul class="pv-bundle__list">
                    <?php
                    // Producto principal (siempre incluido, marcado).
                    $main_thumb = $product->get_image( 'thumbnail' );
                    ?>
                    <li class="pv-bundle__item is-main is-selected" data-pv-bundle-item data-pv-bundle-price="<?php echo esc_attr( $base_price ); ?>" data-pv-bundle-id="<?php echo esc_attr( $product_id ); ?>">
                        <label class="pv-bundle__item-label">
                            <input type="checkbox" class="pv-bundle__check" checked disabled aria-label="<?php echo esc_attr( sprintf( __( 'Incluir %s en el paquete', 'ltms' ), $product->get_name() ) ); ?>">
                            <span class="pv-bundle__thumb"><?php echo $main_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <span class="pv-bundle__item-body">
                                <span class="pv-bundle__item-name"><?php echo esc_html( $product->get_name() ); ?></span>
                                <span class="pv-bundle__item-price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            </span>
                        </label>
                    </li>

                    <?php foreach ( $bundle_items as $bp ) :
                        $bp_price = (float) $bp->get_price();
                        $bp_thumb = $bp->get_image( 'thumbnail' );
                    ?>
                        <li class="pv-bundle__item is-selected" data-pv-bundle-item data-pv-bundle-price="<?php echo esc_attr( $bp_price ); ?>" data-pv-bundle-id="<?php echo esc_attr( $bp->get_id() ); ?>">
                            <label class="pv-bundle__item-label">
                                <input type="checkbox" class="pv-bundle__check" checked aria-label="<?php echo esc_attr( sprintf( __( 'Incluir %s en el paquete', 'ltms' ), $bp->get_name() ) ); ?>">
                                <span class="pv-bundle__thumb"><?php echo $bp_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="pv-bundle__item-body">
                                    <span class="pv-bundle__item-name"><?php echo esc_html( $bp->get_name() ); ?></span>
                                    <span class="pv-bundle__item-price"><?php echo $bp->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <footer class="pv-bundle__footer">
                    <div class="pv-bundle__total">
                        <span class="pv-bundle__total-label"><?php esc_html_e( 'Total del paquete:', 'ltms' ); ?></span>
                        <span class="pv-bundle__total-now" data-pv-bundle-total><?php echo wc_price( $base_price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <span class="pv-bundle__total-save pv-badge pv-badge--gold" data-pv-bundle-save hidden></span>
                    </div>
                    <button type="button"
                            class="pv-btn pv-btn--gold pv-btn--lg"
                            data-pv-bundle-add
                            aria-label="<?php esc_attr_e( 'Añadir los productos seleccionados al carrito', 'ltms' ); ?>">
                        <?php esc_html_e( 'Añadir paquete al carrito', 'ltms' ); ?>
                    </button>
                </footer>
            </section><!-- /.pv-bundle -->
        <?php endif; ?>

        <?php
        /* =====================================================================
         * SECCIONES ANCLA — tabs reformateados como secciones.
         * (woocommerce_output_product_data_tabs() entrega tabs; aquí
         *  renderizamos el mismo contenido como bloques anchor.)
         * =====================================================================
         */
        ?>

        <section class="pv-anchor-section" id="pv-descripcion" aria-labelledby="pv-descripcion-title">
            <h2 id="pv-descripcion-title" class="pv-anchor-section__title"><?php esc_html_e( 'Descripción', 'ltms' ); ?></h2>
            <div class="pv-anchor-section__body pv-anchor-section__body--prose">
                <?php
                /**
                 * Contenido equivalente al tab 'description' de WC.
                 * El callback de WC es `the_content()`.
                 */
                if ( method_exists( $product, 'get_description' ) && $product->get_description() ) {
                    the_content();
                } else {
                    woocommerce_template_single_excerpt();
                }
                ?>
            </div>
        </section><!-- /#pv-descripcion -->

        <section class="pv-anchor-section" id="pv-specs" aria-labelledby="pv-specs-title">
            <h2 id="pv-specs-title" class="pv-anchor-section__title"><?php esc_html_e( 'Especificaciones', 'ltms' ); ?></h2>
            <div class="pv-anchor-section__body">
                <?php
                /**
                 * Contenido equivalente al tab 'additional_information'.
                 * Renderiza atributos, dimensiones y peso vía wc_get_template.
                 */
                if ( $product->has_attributes() || $product->has_dimensions() || $product->has_weight() ) {
                    wc_get_template( 'single-product/tabs/additional-information.php', array( 'product' => $product ) );
                } else {
                    echo '<p class="pv-empty">' . esc_html__( 'Este producto no tiene especificaciones técnicas adicionales.', 'ltms' ) . '</p>';
                }
                ?>
            </div>
        </section><!-- /#pv-specs -->

        <section class="pv-anchor-section" id="pv-reseñas" aria-labelledby="pv-reseñas-title">
            <h2 id="pv-reseñas-title" class="pv-anchor-section__title">
                <?php esc_html_e( 'Reseñas', 'ltms' ); ?>
                <?php if ( $review_count > 0 ) : ?>
                    <span class="pv-anchor-section__count">(<?php echo esc_html( number_format_i18n( $review_count ) ); ?>)</span>
                <?php endif; ?>
            </h2>
            <div class="pv-anchor-section__body">
                <?php
                /**
                 * Reviews — comments_template().
                 * WC usa comments_template con plantilla de reviews propia.
                 */
                comments_template();
                ?>
            </div>
        </section><!-- /#pv-reseñas -->

        <section class="pv-anchor-section" id="pv-envio" aria-labelledby="pv-envio-title">
            <h2 id="pv-envio-title" class="pv-anchor-section__title"><?php esc_html_e( 'Envío y devoluciones', 'ltms' ); ?></h2>
            <div class="pv-anchor-section__body pv-anchor-section__body--prose">
                <?php
                /**
                 * Hook: ltms_single_product_shipping_section
                 * Permite que los módulos de shipping (Deprisa, Aveonline, Heka,
                 * Uber Direct, etc.) inyecten políticas reales del vendor.
                 */
                do_action( 'ltms_single_product_shipping_section', $product, $vendor_id );
                ?>
                <?php if ( ! has_action( 'ltms_single_product_shipping_section' ) ) : ?>
                    <p>
                        <?php esc_html_e( 'El tiempo de entrega estimado se calcula al finalizar la compra según tu ubicación y el método de envío seleccionado.', 'ltms' ); ?>
                    </p>
                    <?php if ( $vendor_id > 0 ) : ?>
                        <p>
                            <?php
                            /* translators: %s: nombre de la tienda. */
                            echo esc_html( sprintf( __( 'Vendido y enviado por %s.', 'ltms' ), $store_name ) );
                            ?>
                        </p>
                    <?php endif; ?>
                    <p>
                        <?php esc_html_e( 'Devoluciones aceptadas dentro del período de protección al consumidor (Ley 1480 / PROFECO).', 'ltms' ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </section><!-- /#pv-envio -->

        <?php
        /* =====================================================================
         * RELATED PRODUCTS
         * =====================================================================
         */
        ?>
        <section class="pv-related pv-section" id="pv-related" aria-labelledby="pv-related-title">
            <header class="pv-section__head">
                <h2 id="pv-related-title" class="pv-section__title"><?php esc_html_e( 'Productos relacionados', 'ltms' ); ?></h2>
            </header>
            <?php
            /**
             * Related products — woocommerce_related_products().
             * 4 productos, 4 columnas (responsive via design system grid).
             */
            woocommerce_related_products( array(
                'posts_per_page' => 4,
                'columns'        => 4,
                'orderby'        => 'rand',
            ) );
            ?>
        </section><!-- /#pv-related -->

        <?php
        /**
         * Hook: woocommerce_after_single_product
         * Cierre de WC para fragments y social sharing.
         */
        do_action( 'woocommerce_after_single_product' );
        ?>

    </main><!-- /.pv-product-page__main -->

    <?php
    /* =====================================================================
     * STICKY ATC BAR (mobile) — aparece al salir del viewport del ATC.
     * =====================================================================
     */
    ?>
    <div class="pv-sticky-atc" id="pv-sticky-atc" role="region" aria-label="<?php esc_attr_e( 'Acción rápida de compra', 'ltms' ); ?>" data-pv-sticky-atc>
        <?php
        $atc_thumb = $product->get_image( 'gallery_thumbnail', array( 'class' => 'pv-sticky-atc__thumb' ), false );
        echo $atc_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div class="pv-sticky-atc__info">
            <span class="pv-sticky-atc__title"><?php echo esc_html( wp_strip_all_tags( $product->get_name() ) ); ?></span>
            <span class="pv-sticky-atc__price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        </div>
        <button type="button"
                class="pv-btn pv-btn--primary"
                data-pv-add-to-cart="<?php echo esc_attr( $product_id ); ?>"
                aria-label="<?php echo esc_attr( sprintf( __( 'Añadir %s al carrito', 'ltms' ), wp_strip_all_tags( $product->get_name() ) ) ); ?>">
            <?php esc_html_e( 'Añadir al carrito', 'ltms' ); ?>
        </button>
    </div><!-- /.pv-sticky-atc -->

</div><!-- /.pv-scope.pv-product-page -->

<?php
/**
 * Hook: woocommerce_after_main_content
 * Wrapper de cierre del tema (balancea woocommerce_before_main_content).
 */
do_action( 'woocommerce_after_main_content' );

// Restaurar el breadcrumb en el hook para no afectar al resto del sitio.
if ( ! empty( $pv_breadcrumb_was_hooked ) ) {
    add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}

/**
 * Hook: ltms_after_single_product_plazaviva
 */
do_action( 'ltms_after_single_product_plazaviva', $product );
?>

<?php
/* ============================================================================
 * Estilos estructurales específicos de la página de producto.
 * El design system (ltms-plaza-viva.css) cubre componentes compartidos
 * (.pv-btn, .pv-badge, .pv-stars, .pv-stock-bar, .pv-countdown, .pv-sticky-atc,
 * .pv-section, .pv-card, grid-*). Estas reglas cubren SOLO el layout de
 * single-product y están scopeadas bajo .pv-scope.pv-product-page para no
 * filtrar al resto del sitio.
 * ========================================================================== */
?>
<style>
.pv-scope.pv-product-page{--pv-pg-gap:32px;display:block;}
.pv-scope.pv-product-page .pv-product-page__breadcrumb{padding-top:10px;padding-bottom:0;}
.pv-scope.pv-product-page .pv-product-page__main{display:block;}
.pv-scope.pv-product-page .pv-product-page__layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:var(--pv-pg-gap);
    align-items:start;
}
.pv-scope.pv-product-page .pv-product-gallery{
    position:sticky;top:16px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-card);padding:14px;box-shadow:var(--sh-1);
}
.pv-scope.pv-product-page .pv-product-gallery .woocommerce-product-gallery{margin:0;}
.pv-scope.pv-product-page .pv-product-info{
    display:flex;flex-direction:column;gap:16px;min-width:0;
}
.pv-scope.pv-product-page .pv-product-info .product_title{font-family:var(--display);font-weight:800;font-size:26px;line-height:1.2;margin:0;}
.pv-scope.pv-product-page .pv-product-info .price{font-family:var(--display);font-weight:800;font-size:28px;color:var(--text);}
.pv-scope.pv-product-page .pv-product-info__excerpt{color:var(--text-2);font-size:14.5px;line-height:1.6;}

/* Vendor mini-card */
.pv-scope.pv-product-page .pv-vendor-minicard{
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    padding:12px 14px;border:1px solid var(--border);border-radius:var(--r-md);
    background:var(--bg);
}
.pv-scope.pv-product-page .pv-vendor-minicard__link{
    display:flex;align-items:center;gap:12px;min-width:0;flex:1;text-decoration:none;color:inherit;
}
.pv-scope.pv-product-page .pv-vendor-minicard__avatar{flex-shrink:0;}
.pv-scope.pv-product-page .pv-vendor-minicard__avatar img,.pv-scope.pv-product-page .pv-vendor-minicard__img{width:44px;height:44px;border-radius:50%;display:block;}
.pv-scope.pv-product-page .pv-vendor-minicard__body{display:flex;flex-direction:column;gap:4px;min-width:0;}
.pv-scope.pv-product-page .pv-vendor-minicard__name{display:flex;align-items:center;gap:8px;font-weight:700;font-size:14.5px;flex-wrap:wrap;}
.pv-scope.pv-product-page .pv-vendor-minicard__meta{display:flex;align-items:center;gap:10px;font-size:12.5px;color:var(--text-3);flex-wrap:wrap;}
.pv-scope.pv-product-page .pv-vendor-minicard__visit{flex-shrink:0;}

/* Stock track */
.pv-scope.pv-product-page .pv-stock-bar__track{height:8px;background:var(--bg-2);border-radius:var(--r-pill);overflow:hidden;}
.pv-scope.pv-product-page .pv-stock-bar__fill{height:100%;background:linear-gradient(90deg,var(--accent),#0fc4a0);border-radius:var(--r-pill);transition:width .4s ease;}

/* Countdown wrapper */
.pv-scope.pv-product-page .pv-product-info__countdown{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 14px;background:var(--gold-50);border:1px solid var(--gold-100);border-radius:var(--r-md);}
.pv-scope.pv-product-page .pv-product-info__countdown-label{font-weight:700;color:#8a6d20;font-size:13.5px;}

/* Sticky nav */
.pv-scope.pv-product-page .pv-sticky-nav{
    position:sticky;top:0;z-index:40;
    background:rgba(255,255,255,.92);backdrop-filter:blur(8px);
    border-bottom:1px solid var(--border);margin:24px 0 0;
}
.pv-scope.pv-product-page .pv-sticky-nav__list{
    list-style:none;display:flex;gap:6px;overflow-x:auto;max-width:var(--pv-maxw);margin:0 auto;padding:8px 0;scrollbar-width:none;
}
.pv-scope.pv-product-page .pv-sticky-nav__list::-webkit-scrollbar{display:none;}
.pv-scope.pv-product-page .pv-sticky-nav__link{
    display:inline-flex;align-items:center;gap:6px;white-space:nowrap;
    padding:10px 16px;border-radius:var(--r-pill);font-size:14px;font-weight:600;
    color:var(--text-2);text-decoration:none;transition:background var(--t),color var(--t);
}
.pv-scope.pv-product-page .pv-sticky-nav__link:hover{background:var(--bg-2);color:var(--text);}
.pv-scope.pv-product-page .pv-sticky-nav__link.is-active{background:var(--primary);color:#fff;}
.pv-scope.pv-product-page .pv-sticky-nav__count{font-size:11px;background:rgba(255,255,255,.25);padding:1px 7px;border-radius:var(--r-pill);}
.pv-scope.pv-product-page .pv-sticky-nav__link:not(.is-active) .pv-sticky-nav__count{background:var(--bg-2);color:var(--text-3);}

/* Bundle */
.pv-scope.pv-product-page .pv-bundle{
    margin-top:28px;padding:22px;border:1px solid var(--border);border-radius:var(--r-card);background:var(--surface);box-shadow:var(--sh-1);
}
.pv-scope.pv-product-page .pv-bundle__head{margin-bottom:14px;}
.pv-scope.pv-product-page .pv-bundle__title{font-family:var(--display);font-weight:800;font-size:20px;}
.pv-scope.pv-product-page .pv-bundle__sub{font-size:13.5px;color:var(--text-3);margin-top:2px;}
.pv-scope.pv-product-page .pv-bundle__list{list-style:none;display:flex;flex-direction:column;gap:10px;}
.pv-scope.pv-product-page .pv-bundle__item{border:1px solid var(--border);border-radius:var(--r-md);background:var(--surface);transition:border-color var(--t),background var(--t);}
.pv-scope.pv-product-page .pv-bundle__item.is-selected{border-color:var(--primary-100);background:var(--primary-50);}
.pv-scope.pv-product-page .pv-bundle__item-label{display:flex;align-items:center;gap:12px;padding:10px 12px;cursor:pointer;}
.pv-scope.pv-product-page .pv-bundle__check{width:18px;height:18px;accent-color:var(--primary);flex-shrink:0;}
.pv-scope.pv-product-page .pv-bundle__thumb{width:54px;height:54px;flex-shrink:0;border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;display:block;}
.pv-scope.pv-product-page .pv-bundle__thumb img{width:100%;height:100%;object-fit:cover;}
.pv-scope.pv-product-page .pv-bundle__item-body{display:flex;flex-direction:column;min-width:0;}
.pv-scope.pv-product-page .pv-bundle__item-name{font-weight:600;font-size:14px;}
.pv-scope.pv-product-page .pv-bundle__item-price{font-size:13.5px;color:var(--primary);font-weight:700;}
.pv-scope.pv-product-page .pv-bundle__footer{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.pv-scope.pv-product-page .pv-bundle__total{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.pv-scope.pv-product-page .pv-bundle__total-label{font-size:13.5px;color:var(--text-3);}
.pv-scope.pv-product-page .pv-bundle__total-now{font-family:var(--display);font-weight:800;font-size:22px;color:var(--text);}

/* Anchor sections */
.pv-scope.pv-product-page .pv-anchor-section{scroll-margin-top:64px;padding:28px 0;border-top:1px solid var(--border);}
.pv-scope.pv-product-page .pv-anchor-section:first-of-type{border-top:none;}
.pv-scope.pv-product-page .pv-anchor-section__title{font-family:var(--display);font-weight:800;font-size:22px;margin-bottom:14px;display:flex;align-items:center;gap:10px;}
.pv-scope.pv-product-page .pv-anchor-section__count{font-size:14px;font-weight:600;color:var(--text-3);}
.pv-scope.pv-product-page .pv-anchor-section__body{font-size:15px;line-height:1.7;color:var(--text-2);}
.pv-scope.pv-product-page .pv-anchor-section__body--prose p{margin:0 0 12px;}
.pv-scope.pv-product-page .pv-anchor-section__body table{width:100%;border-collapse:collapse;margin-top:8px;}
.pv-scope.pv-product-page .pv-anchor-section__body th,.pv-scope.pv-product-page .pv-anchor-section__body td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left;font-size:14px;}
.pv-scope.pv-product-page .pv-anchor-section__body th{font-weight:600;color:var(--text);background:var(--bg);}
.pv-scope.pv-product-page .pv-empty{color:var(--text-3);font-style:italic;}

/* Related */
.pv-scope.pv-product-page .pv-related{margin-top:36px;}
.pv-scope.pv-product-page .pv-related ul.products{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;list-style:none;margin:0;padding:0;}
.pv-scope.pv-product-page .pv-related ul.products li.product{margin:0;}

/* Sticky ATC trigger sentinel alineado con .pv-product-actions */
.pv-scope.pv-product-page .pv-product-actions{scroll-margin-top:80px;}

/* Sticky ATC sólo en mobile/tablet (≤ 980px); en desktop el ATC principal está siempre visible */
@media (min-width:981px){
    .pv-scope.pv-product-page .pv-sticky-atc{display:none !important;}
}

/* Responsive: 2 → 1 columna */
@media (max-width:980px){
    .pv-scope.pv-product-page .pv-product-page__layout{grid-template-columns:1fr;gap:20px;}
    .pv-scope.pv-product-page .pv-product-gallery{position:static;}
    .pv-scope.pv-product-page .pv-related ul.products{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:560px){
    .pv-scope.pv-product-page .pv-product-info .product_title{font-size:21px;}
    .pv-scope.pv-product-page .pv-product-info .price{font-size:23px;}
    .pv-scope.pv-product-page .pv-bundle{padding:16px;}
    .pv-scope.pv-product-page .pv-bundle__footer{flex-direction:column;align-items:stretch;}
    .pv-scope.pv-product-page .pv-bundle__footer .pv-btn{width:100%;}
    .pv-scope.pv-product-page .pv-related ul.products{grid-template-columns:1fr;}
}
</style>

<?php
/* ============================================================================
 * JS de página: sticky-nav active link, bundle recálculo y bundle add-to-cart.
 * Se emite al final del body (tras footer) para no bloquear el render.
 * Usa el objeto global `PV` del design system si está disponible.
 * ========================================================================== */

// Config de moneda desde WooCommerce para formatear el total del bundle en JS.
$pv_currency = array(
    'symbol'         => html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
    'decimal'        => (string) get_option( 'woocommerce_price_decimal_sep', '.' ),
    'thousand'       => (string) get_option( 'woocommerce_price_thousand_sep', ',' ),
    'decimals'       => (int) get_option( 'woocommerce_price_num_decimals', 2 ),
    'position'       => (string) get_option( 'woocommerce_currency_pos', 'left' ),
    'price_format'   => get_woocommerce_price_format(),
);
?>
<script>
window.ltms_pv_currency = <?php echo wp_json_encode( $pv_currency ); ?>;
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-product-page');
    if (!scope) return;

    /* --- 1. Sticky nav: resaltar enlace de sección activa --------------- */
    var navLinks = Array.prototype.slice.call(scope.querySelectorAll('.pv-sticky-nav__link'));
    var sections = [];
    navLinks.forEach(function(link){
        var hash = link.getAttribute('href') || '';
        if (hash.charAt(0) === '#') {
            var sec = document.getElementById(hash.slice(1));
            if (sec) sections.push({ link: link, el: sec });
        }
    });

    function setActive(id){
        navLinks.forEach(function(l){ l.classList.remove('is-active'); l.removeAttribute('aria-current'); });
        var match = sections.filter(function(s){ return s.el.id === id; })[0];
        if (match){ match.link.classList.add('is-active'); match.link.setAttribute('aria-current','true'); }
    }

    if ('IntersectionObserver' in window && sections.length){
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(e){
                if (e.isIntersecting){ setActive(e.target.id); }
            });
        }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
        sections.forEach(function(s){ io.observe(s.el); });
    }

    // Smooth scroll al hacer click en los enlaces de ancla.
    navLinks.forEach(function(link){
        link.addEventListener('click', function(e){
            var hash = link.getAttribute('href') || '';
            if (hash.charAt(0) !== '#') return;
            var target = document.getElementById(hash.slice(1));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior:'smooth', block:'start' });
            if (history.replaceState){ history.replaceState(null,'',hash); }
            setActive(target.id);
        });
    });

    /* --- 2. Bundle: total dinámico ------------------------------------- */
    var bundle = scope.querySelector('.pv-bundle');
    if (bundle){
        var items = Array.prototype.slice.call(bundle.querySelectorAll('[data-pv-bundle-item]'));
        var totalEl = bundle.querySelector('[data-pv-bundle-total]');
        var saveEl  = bundle.querySelector('[data-pv-bundle-save]');
        var addBtn  = bundle.querySelector('[data-pv-bundle-add]');
        var discountPct = <?php echo (int) $bundle_discount; ?>;

        // Formatea un número según la configuración de moneda de WooCommerce.
        function formatMoney(n){
            var cfg = window.ltms_pv_currency || {};
            var dec = (typeof cfg.decimals === 'number') ? cfg.decimals : 2;
            var dsep = cfg.decimal || '.';
            var tsep = cfg.thousand || ',';
            var sym  = cfg.symbol || '$';
            var pos  = cfg.position || 'left';
            var neg = n < 0;
            n = Math.abs(n);
            var fixed = n.toFixed(dec);
            var parts = fixed.split('.');
            var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, tsep);
            var numStr = (parts[1] && dec > 0) ? (intPart + dsep + parts[1]) : intPart;
            var out;
            switch (pos){
                case 'right':        out = numStr + sym; break;
                case 'left_space':   out = sym + ' ' + numStr; break;
                case 'right_space':  out = numStr + ' ' + sym; break;
                case 'left':
                default:             out = sym + numStr; break;
            }
            return neg ? ('-' + out) : out;
        }

        function selectedItems(){
            return items.filter(function(it){
                var cb = it.querySelector('.pv-bundle__check');
                return cb ? cb.checked : false;
            });
        }

        function recompute(){
            var sel = selectedItems();
            var sum = sel.reduce(function(acc,it){ return acc + (parseFloat(it.getAttribute('data-pv-bundle-price'))||0); },0);
            var applyDiscount = sel.length >= 2;
            var save = applyDiscount ? sum * (discountPct/100) : 0;
            var total = sum - save;
            if (totalEl){
                totalEl.textContent = formatMoney(total);
                totalEl.style.color = applyDiscount ? 'var(--accent)' : '';
            }
            if (saveEl){
                if (applyDiscount && save > 0){
                    saveEl.hidden = false;
                    saveEl.textContent = '- ' + formatMoney(save);
                } else {
                    saveEl.hidden = true;
                }
            }
            if (addBtn){
                addBtn.disabled = sel.length === 0;
            }
        }

        items.forEach(function(it){
            var cb = it.querySelector('.pv-bundle__check');
            if (!cb) return;
            it.classList.toggle('is-selected', cb.checked);
            cb.addEventListener('change', function(){
                it.classList.toggle('is-selected', cb.checked);
                recompute();
            });
        });
        recompute();

        /* --- 3. Bundle add-to-cart (AJAX secuencial) ------------------- */
        if (addBtn){
            addBtn.addEventListener('click', function(){
                var sel = selectedItems();
                if (!sel.length) return;
                var ajaxUrl = (window.ltms_data && ltms_data.ajax_url) || '/wp-admin/admin-ajax.php';
                var nonce = (window.ltms_data && ltms_data.nonce) || '';
                addBtn.classList.add('pv-btn--loading');
                addBtn.disabled = true;

                var queue = sel.slice();
                function next(){
                    if (!queue.length){
                        addBtn.classList.remove('pv-btn--loading');
                        addBtn.disabled = false;
                        if (window.PV && PV.toast){ PV.toast((ltms_data.i18n && ltms_data.i18n.addedToCart) || 'Añadido al carrito', { type:'success' }); }
                        if (window.PV && PV.Shopping){ try { PV.Shopping.refresh(); } catch(_){} }
                        return;
                    }
                    var it = queue.shift();
                    var pid = it.getAttribute('data-pv-bundle-id');
                    var body = new URLSearchParams();
                    body.append('action','ltms_plaza_viva_add_to_cart');
                    body.append('nonce', nonce);
                    body.append('product_id', pid);
                    body.append('quantity','1');
                    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: body })
                        .then(function(r){ return r.json(); })
                        .then(function(){ next(); })
                        .catch(function(){ next(); });
                }
                next();
            });
        }
    }
})();
</script>

<?php
/**
 * Hook: ltms_single_product_plazaviva_footer
 * Punto de extensión final antes del footer de WC.
 */
do_action( 'ltms_single_product_plazaviva_footer', $product );

get_footer( 'shop' );
