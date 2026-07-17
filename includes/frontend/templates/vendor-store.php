<?php
/**
 * Template: Vendor Store — Plaza Viva Design System
 *
 * Página pública de tienda del vendedor. Se sirve vía `template_include`
 * cuando la página actual corresponde a una vendor store
 * (ver LTMS_Native_Templates::is_vendor_store_page()).
 *
 * Secciones:
 *  - Banner gradiente (azul #2563EB + gold #D4A857) con avatar, nombre,
 *    Star Seller badge y CTA de seguir/contactar.
 *  - Stats row: productos publicados, ventas completadas, rating promedio,
 *    vendedor desde (fecha de registro).
 *  - Botones de acción: Seguir vendedor, Contactar, Ver políticas.
 *  - Tabs (vanilla JS): Productos | Reseñas | Sobre nosotros | Políticas.
 *  - Grid 4→3→2→1 de productos del vendor (wc_get_products paginate).
 *
 * Usa WC hooks estándar y el design system "Plaza Viva"
 * (assets/css/ltms-plaza-viva.css + assets/js/ltms-plaza-viva.js).
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

/* ---------------------------------------------------------------------------
 * 0. Resolver vendor_id y validar
 * ------------------------------------------------------------------------- */
$pv_vendor_id = 0;
if ( function_exists( 'get_query_var' ) ) {
    $pv_vendor_id = (int) get_query_var( 'vendor_id' );
}
if ( $pv_vendor_id <= 0 && function_exists( 'get_queried_object_id' ) ) {
    $pv_vendor_id = (int) get_queried_object_id();
}

// Fallback: ?vendor_id=ID en query string.
if ( $pv_vendor_id <= 0 && isset( $_GET['vendor_id'] ) ) {
    $pv_vendor_id = (int) $_GET['vendor_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

$pv_vendor = $pv_vendor_id > 0 ? get_userdata( $pv_vendor_id ) : false;

if ( ! $pv_vendor ) {
    get_header();
    echo '<div class="pv-scope pv-vendor-store"><main class="pv-section" style="padding:60px 22px;text-align:center">';
    echo '<h1 style="margin-bottom:10px">' . esc_html__( 'Vendedor no encontrado', 'ltms' ) . '</h1>';
    echo '<p style="color:var(--text-2)">' . esc_html__( 'La tienda que buscas no existe o fue desactivada.', 'ltms' ) . '</p>';
    echo '<p style="margin-top:18px"><a class="pv-btn" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Volver al inicio', 'ltms' ) . '</a></p>';
    echo '</main></div>';
    get_footer();
    return;
}

/* ---------------------------------------------------------------------------
 * 1. Garantizar WooCommerce activo
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
    get_header();
    echo '<div class="pv-scope pv-vendor-store"><main class="pv-section" style="padding:60px 22px;text-align:center">';
    echo '<p>' . esc_html__( 'WooCommerce no está activo. La tienda del vendedor requiere WooCommerce.', 'ltms' ) . '</p>';
    echo '</main></div>';
    get_footer();
    return;
}

/* ---------------------------------------------------------------------------
 * 2. Datos del vendor
 * ------------------------------------------------------------------------- */
$pv_store_name        = (string) get_user_meta( $pv_vendor_id, 'ltms_store_name', true );
if ( '' === $pv_store_name ) {
    $pv_store_name = $pv_vendor->display_name ?: $pv_vendor->user_login;
}

$pv_store_logo        = get_user_meta( $pv_vendor_id, 'ltms_store_logo', true ); // attachment ID o URL.
$pv_store_description = (string) get_user_meta( $pv_vendor_id, 'description', true );
if ( '' === $pv_store_description ) {
    $pv_store_description = (string) get_user_meta( $pv_vendor_id, 'ltms_store_description', true );
}
$pv_kyc_status        = (string) get_user_meta( $pv_vendor_id, 'ltms_kyc_status', true );
$pv_is_star_seller    = ( $pv_kyc_status === 'approved' ) && ( get_user_meta( $pv_vendor_id, 'ltms_star_seller', true ) === '1' );
$pv_policies          = (string) get_user_meta( $pv_vendor_id, 'ltms_store_policies', true );

// Avatar: si hay logo, usarlo; si no, generar avatar con iniciales.
$pv_logo_html = '';
if ( $pv_store_logo ) {
    if ( is_numeric( $pv_store_logo ) ) {
        $pv_logo_html = wp_get_attachment_image( (int) $pv_store_logo, 'thumbnail', false, [ 'class' => 'pv-vendor-store__avatar-img', 'alt' => esc_attr( $pv_store_name ) ] );
    } else {
        $pv_logo_html = '<img class="pv-vendor-store__avatar-img" src="' . esc_url( $pv_store_logo ) . '" alt="' . esc_attr( $pv_store_name ) . '" />';
    }
}
if ( '' === $pv_logo_html ) {
    // Iniciales del store name.
    $pv_words  = preg_split( '/\s+/', trim( $pv_store_name ) );
    $pv_initials = '';
    foreach ( (array) $pv_words as $pv_w ) {
        if ( $pv_w === '' ) continue;
        $pv_initials .= mb_strtoupper( mb_substr( $pv_w, 0, 1 ) );
        if ( mb_strlen( $pv_initials ) >= 2 ) break;
    }
    if ( '' === $pv_initials ) {
        $pv_initials = mb_strtoupper( mb_substr( $pv_vendor->user_login, 0, 2 ) );
    }
    $pv_logo_html = '<span class="pv-vendor-store__avatar-initials" aria-hidden="true">' . esc_html( $pv_initials ) . '</span>';
}

/* ---------------------------------------------------------------------------
 * 3. Estadísticas del vendor
 * ------------------------------------------------------------------------- */
$pv_products_count = (int) count_user_posts( $pv_vendor_id, 'product', true );

$pv_sales_count = 0;
if ( class_exists( 'LTMS_Trust_Badges' ) && method_exists( 'LTMS_Trust_Badges', 'get_vendor_sales_count' ) ) {
    $pv_sales_count = (int) LTMS_Trust_Badges::get_vendor_sales_count( $pv_vendor_id );
}

$pv_rating = 0.0;
if ( class_exists( 'LTMS_Product_Tabs' ) && method_exists( 'LTMS_Product_Tabs', 'calculate_vendor_rating' ) ) {
    $pv_rating = (float) LTMS_Product_Tabs::calculate_vendor_rating( $pv_vendor_id );
}

$pv_registered_ts = strtotime( $pv_vendor->user_registered );
$pv_vendor_since  = $pv_registered_ts > 0
    ? date_i18n( __( 'M Y', 'ltms' ), $pv_registered_ts )
    : '—';

/* ---------------------------------------------------------------------------
 * 4. Productos del vendor (paginado, 12 por página)
 * ------------------------------------------------------------------------- */
$pv_paged        = max( 1, (int) get_query_var( 'paged' ) );
$pv_per_page     = 12;
$pv_products_q   = wc_get_products( [
    'status'   => 'publish',
    'author'   => $pv_vendor_id,
    'limit'    => $pv_per_page,
    'page'     => $pv_paged,
    'paginate' => true,
    'orderby'  => 'date',
    'order'    => 'DESC',
] );

$pv_products     = is_object( $pv_products_q ) && property_exists( $pv_products_q, 'products' ) ? $pv_products_q->products : [];
$pv_total_prods  = is_object( $pv_products_q ) && property_exists( $pv_products_q, 'total' ) ? (int) $pv_products_q->total : 0;
$pv_max_pages    = is_object( $pv_products_q ) && property_exists( $pv_products_q, 'max_num_pages' ) ? (int) $pv_products_q->max_num_pages : 1;

/* ---------------------------------------------------------------------------
 * 5. Reseñas del vendor (últimas 6 comentarios tipo "review" en sus productos)
 * ------------------------------------------------------------------------- */
$pv_reviews_args = [
    'status'     => 'approve',
    'type'       => 'review',
    'number'     => 6,
    'post_status'=> 'publish',
    'post_type'  => 'product',
    'author__in' => [ $pv_vendor_id ], // comments on posts authored by vendor.
    'meta_key'   => 'rating',
];
// WP_Comment_Query no soporta author__in directo sobre post_author; lo filtramos vía query.
$pv_reviews_q = new WP_Comment_Query();
$pv_reviews   = $pv_reviews_q->query( [
    'status'  => 'approve',
    'type'    => 'review',
    'number'  => 8,
    'orderby' => 'comment_date',
    'order'   => 'DESC',
    'meta_query' => [
        [ 'key' => 'rating', 'compare' => 'EXISTS' ],
    ],
] );
// Filtrar por author del producto.
$pv_reviews = array_filter( (array) $pv_reviews, function ( $c ) use ( $pv_vendor_id ) {
    $pid = $c->comment_post_ID ?? 0;
    return $pid > 0 && (int) get_post_field( 'post_author', $pid ) === $pv_vendor_id;
} );
$pv_reviews = array_slice( $pv_reviews, 0, 6 );

/* ---------------------------------------------------------------------------
 * 6. URLs de acción
 * ------------------------------------------------------------------------- */
$pv_shop_url       = ( function_exists( 'wc_get_page_id' ) ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/tienda' );
$pv_contact_url    = esc_url( add_query_arg( [ 'vendor_id' => $pv_vendor_id ], home_url( '/contacto-vendedor' ) ) );
$pv_follow_url     = esc_url( add_query_arg( [ 'follow_vendor' => $pv_vendor_id, '_wpnonce' => wp_create_nonce( 'ltms_follow_vendor' ) ], home_url( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
$pv_policies_anchor = '#pv-vendor-tab-policies';

/* ---------------------------------------------------------------------------
 * 7. Helpers de render para productos
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'ltms_pv_vendor_render_product' ) ) :
function ltms_pv_vendor_render_product( $pv_p ) {
    if ( ! $pv_p instanceof WC_Product ) return;
    $pv_pid       = $pv_p->get_id();
    $pv_permalink = $pv_p->get_permalink();
    $pv_rating    = (float) $pv_p->get_average_rating();
    $pv_reviews   = (int) $pv_p->get_review_count();
    $pv_on_sale   = $pv_p->is_on_sale();
    $pv_disc      = '';
    if ( $pv_on_sale ) {
        $pv_reg  = (float) $pv_p->get_regular_price();
        $pv_sale = (float) $pv_p->get_sale_price();
        if ( $pv_reg > 0 && $pv_sale > 0 && $pv_sale < $pv_reg ) {
            $pv_disc = round( 100 - ( ( $pv_sale / $pv_reg ) * 100 ) );
        }
    }
    $pv_is_variable = ( $pv_p->get_type() === 'variable' );
    $pv_swatches    = [];
    if ( $pv_is_variable && method_exists( $pv_p, 'get_available_variations' ) ) {
        $pv_vars = $pv_p->get_available_variations();
        if ( is_array( $pv_vars ) ) {
            foreach ( $pv_vars as $pv_var ) {
                if ( ! empty( $pv_var['attributes'] ) ) {
                    foreach ( $pv_var['attributes'] as $pv_attr_val ) {
                        if ( $pv_attr_val && ! isset( $pv_swatches[ $pv_attr_val ] ) && count( $pv_swatches ) < 5 ) {
                            $pv_swatches[ $pv_attr_val ] = sanitize_hex_color( $pv_attr_val ) ?: null;
                        }
                    }
                }
            }
        }
    }
    ?>
    <article class="pv-product-card pv-fade-up" data-product-id="<?php echo esc_attr( $pv_pid ); ?>">
        <div class="pv-product-card__media pv-product-card__image">
            <a href="<?php echo esc_url( $pv_permalink ); ?>" aria-label="<?php echo esc_attr( wp_strip_all_tags( $pv_p->get_name() ) ); ?>" tabindex="-1">
                <?php echo $pv_p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <?php if ( $pv_disc ) : ?>
                <span class="pv-product-card__discount"><?php echo esc_html( sprintf( __( '-%d%%', 'ltms' ), $pv_disc ) ); ?></span>
            <?php endif; ?>
            <a class="pv-product-card__fav"
               href="<?php echo esc_url( add_query_arg( 'add_to_wishlist', $pv_pid, $pv_permalink ) ); ?>"
               data-pv-wishlist-toggle="<?php echo esc_attr( $pv_pid ); ?>"
               data-product-id="<?php echo esc_attr( $pv_pid ); ?>"
               aria-label="<?php esc_attr_e( 'Añadir a favoritos', 'ltms' ); ?>"
               aria-pressed="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </a>
            <div class="pv-product-card__actions">
                <?php if ( $pv_p->is_purchasable() && $pv_p->is_in_stock() ) : ?>
                    <button type="button"
                            class="pv-btn pv-btn--sm"
                            data-pv-add-to-cart="<?php echo esc_attr( $pv_pid ); ?>"
                            aria-label="<?php echo esc_attr( sprintf( __( 'Añadir %s al carrito', 'ltms' ), wp_strip_all_tags( $pv_p->get_name() ) ) ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        <?php esc_html_e( 'Añadir', 'ltms' ); ?>
                    </button>
                <?php else : ?>
                    <a class="pv-btn pv-btn--sm pv-btn--ghost" href="<?php echo esc_url( $pv_permalink ); ?>"><?php esc_html_e( 'Ver', 'ltms' ); ?></a>
                <?php endif; ?>
                <button type="button"
                        class="pv-product-card__quickview"
                        data-pv-quick-view="<?php echo esc_attr( $pv_pid ); ?>"
                        data-pv-quickview="<?php echo esc_attr( $pv_pid ); ?>"
                        aria-label="<?php esc_attr_e( 'Vista rápida', 'ltms' ); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>
        <div class="pv-product-card__body">
            <h3 class="pv-product-card__title">
                <a href="<?php echo esc_url( $pv_permalink ); ?>"><?php echo esc_html( wp_strip_all_tags( $pv_p->get_name() ) ); ?></a>
            </h3>
            <div class="pv-product-card__rating">
                <?php echo wc_get_rating_html( $pv_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span>(<?php echo esc_html( number_format_i18n( $pv_reviews ) ); ?>)</span>
            </div>
            <div class="pv-product-card__price">
                <?php if ( $pv_on_sale ) : ?>
                    <span class="pv-product-card__price-now"><?php echo wc_price( $pv_p->get_sale_price() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="pv-product-card__price-old"><?php echo wc_price( $pv_p->get_regular_price() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php else : ?>
                    <span class="pv-product-card__price-now"><?php echo $pv_p->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $pv_swatches ) ) : ?>
                <div class="pv-product-card__swatches" aria-label="<?php esc_attr_e( 'Variantes disponibles', 'ltms' ); ?>">
                    <?php foreach ( $pv_swatches as $pv_label => $pv_color ) : ?>
                        <?php if ( $pv_color ) : ?>
                            <span class="pv-swatch" style="background:<?php echo esc_attr( $pv_color ); ?>" title="<?php echo esc_attr( $pv_label ); ?>" aria-label="<?php echo esc_attr( $pv_label ); ?>"></span>
                        <?php else : ?>
                            <span class="pv-swatch" title="<?php echo esc_attr( $pv_label ); ?>" aria-label="<?php echo esc_attr( $pv_label ); ?>"><?php echo esc_html( mb_substr( $pv_label, 0, 2 ) ); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
endif;

get_header();

/**
 * Hook: ltms_before_vendor_store_plazaviva
 * Permite inyectar contenido antes del contenedor principal.
 */
do_action( 'ltms_before_vendor_store_plazaviva', $pv_vendor_id );
?>

<div class="pv-scope pv-vendor-store">

    <?php
    /* =====================================================================
     * BANNER — gradiente azul + gold, avatar, nombre, badge, CTA
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-vendor-store__hero-wrap" aria-labelledby="pv-vendor-store-title">
        <div class="pv-vendor-store__hero" role="banner">
            <span class="pv-vendor-store__hero-glow" aria-hidden="true"></span>

            <div class="pv-vendor-store__hero-inner">

                <?php /* Avatar o logo */ ?>
                <div class="pv-vendor-store__avatar <?php echo $pv_is_star_seller ? 'is-star' : ''; ?>">
                    <?php echo $pv_logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php if ( $pv_is_star_seller ) : ?>
                        <span class="pv-vendor-store__star" title="<?php esc_attr_e( 'Star Seller verificado', 'ltms' ); ?>" aria-label="<?php esc_attr_e( 'Star Seller verificado', 'ltms' ); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                        </span>
                    <?php endif; ?>
                </div>

                <?php /* Nombre + badges */ ?>
                <div class="pv-vendor-store__hero-meta">
                    <div class="pv-vendor-store__badges">
                        <?php if ( $pv_is_star_seller ) : ?>
                            <span class="pv-badge pv-badge--gold pv-badge--lg">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                                <?php esc_html_e( 'Star Seller', 'ltms' ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $pv_kyc_status === 'approved' ) : ?>
                            <span class="pv-badge pv-badge--verified pv-badge--lg">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <?php esc_html_e( 'Identidad verificada', 'ltms' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 id="pv-vendor-store-title" class="pv-vendor-store__title"><?php echo esc_html( $pv_store_name ); ?></h1>
                    <p class="pv-vendor-store__handle">@<?php echo esc_html( sanitize_title( $pv_vendor->user_login ) ); ?></p>
                </div>

                <?php /* Botones de acción */ ?>
                <div class="pv-vendor-store__hero-actions">
                    <button type="button"
                            class="pv-btn pv-btn--gold"
                            data-pv-follow-vendor="<?php echo esc_attr( $pv_vendor_id ); ?>"
                            data-pv-follow-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_follow_vendor' ) ); ?>"
                            aria-pressed="false">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        <?php esc_html_e( 'Seguir', 'ltms' ); ?>
                    </button>
                    <a class="pv-btn pv-btn--ghost pv-btn--invert" href="<?php echo esc_url( $pv_contact_url ); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <?php esc_html_e( 'Contactar', 'ltms' ); ?>
                    </a>
                    <a class="pv-btn pv-btn--ghost pv-btn--invert pv-vendor-store__policies-cta" href="<?php echo esc_url( $pv_policies_anchor ); ?>" data-pv-jump-tab="policies">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                        <?php esc_html_e( 'Ver políticas', 'ltms' ); ?>
                    </a>
                </div>
            </div>

            <?php /* Stats row */ ?>
            <dl class="pv-vendor-store__stats" aria-label="<?php esc_attr_e( 'Estadísticas del vendedor', 'ltms' ); ?>">
                <div class="pv-vendor-store__stat">
                    <dt><?php esc_html_e( 'Productos', 'ltms' ); ?></dt>
                    <dd><?php echo esc_html( number_format_i18n( $pv_products_count ) ); ?></dd>
                </div>
                <div class="pv-vendor-store__stat">
                    <dt><?php esc_html_e( 'Ventas', 'ltms' ); ?></dt>
                    <dd><?php echo esc_html( number_format_i18n( $pv_sales_count ) ); ?></dd>
                </div>
                <div class="pv-vendor-store__stat">
                    <dt><?php esc_html_e( 'Rating', 'ltms' ); ?></dt>
                    <dd>
                        <span class="pv-vendor-store__rating-num"><?php echo esc_html( number_format_i18n( $pv_rating, 1 ) ); ?></span>
                        <span class="pv-stars" aria-hidden="true">
                            <span class="pv-stars__stars">
                                <span class="pv-stars__fill" style="width:<?php echo esc_attr( ( $pv_rating / 5 ) * 100 ); ?>%"></span>
                            </span>
                        </span>
                    </dd>
                </div>
                <div class="pv-vendor-store__stat">
                    <dt><?php esc_html_e( 'Vendedor desde', 'ltms' ); ?></dt>
                    <dd><?php echo esc_html( $pv_vendor_since ); ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <?php
    /* =====================================================================
     * TABS — Productos | Reseñas | Sobre nosotros | Políticas
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-vendor-store__tabs-wrap" aria-label="<?php esc_attr_e( 'Contenido de la tienda', 'ltms' ); ?>">
        <div class="pv-tabs pv-vendor-store__tabs" data-pv-tabs role="tablist" aria-orientation="horizontal">
            <button type="button" class="pv-tab" role="tab" id="pv-vendor-tab-products" aria-controls="pv-vendor-panel-products" aria-selected="true" tabindex="0">
                <?php esc_html_e( 'Productos', 'ltms' ); ?>
                <span class="pv-tab__count"><?php echo esc_html( number_format_i18n( $pv_products_count ) ); ?></span>
            </button>
            <button type="button" class="pv-tab" role="tab" id="pv-vendor-tab-reviews" aria-controls="pv-vendor-panel-reviews" aria-selected="false" tabindex="-1">
                <?php esc_html_e( 'Reseñas', 'ltms' ); ?>
            </button>
            <button type="button" class="pv-tab" role="tab" id="pv-vendor-tab-about" aria-controls="pv-vendor-panel-about" aria-selected="false" tabindex="-1">
                <?php esc_html_e( 'Sobre nosotros', 'ltms' ); ?>
            </button>
            <button type="button" class="pv-tab" role="tab" id="pv-vendor-tab-policies" aria-controls="pv-vendor-panel-policies" aria-selected="false" tabindex="-1">
                <?php esc_html_e( 'Políticas', 'ltms' ); ?>
            </button>
        </div>

        <?php
        /* ----- Panel 1: Productos ----- */
        ?>
        <div class="pv-tabpanel pv-vendor-store__panel" role="tabpanel" id="pv-vendor-panel-products" aria-labelledby="pv-vendor-tab-products">
            <?php if ( ! empty( $pv_products ) ) : ?>
                <div class="pv-vendor-store__grid pv-scope grid-4 grid-auto">
                    <?php foreach ( $pv_products as $pv_vp ) : ?>
                        <?php ltms_pv_vendor_render_product( $pv_vp ); ?>
                    <?php endforeach; ?>
                </div>

                <?php if ( $pv_max_pages > 1 ) : ?>
                    <nav class="pv-vendor-store__pagination" aria-label="<?php esc_attr_e( 'Paginación de productos', 'ltms' ); ?>">
                        <?php
                        echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '?paged=%#%',
                            'current'   => $pv_paged,
                            'total'     => $pv_max_pages,
                            'prev_text' => '&larr; ' . __( 'Anterior', 'ltms' ),
                            'next_text' => __( 'Siguiente', 'ltms' ) . ' &rarr;',
                            'mid_size'  => 1,
                        ] );
                        ?>
                    </nav>
                <?php endif; ?>
            <?php else : ?>
                <div class="pv-card pv-card--flat pv-vendor-store__empty">
                    <div class="pv-vendor-store__empty-icon" aria-hidden="true">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
                    </div>
                    <h3><?php esc_html_e( 'Aún no hay productos publicados', 'ltms' ); ?></h3>
                    <p><?php esc_html_e( 'Este vendedor está preparando su catálogo. Vuelve pronto para ver novedades.', 'ltms' ); ?></p>
                    <a class="pv-btn pv-btn--ghost" href="<?php echo esc_url( $pv_shop_url ); ?>"><?php esc_html_e( 'Explorar otros vendedores', 'ltms' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        /* ----- Panel 2: Reseñas ----- */
        ?>
        <div class="pv-tabpanel pv-vendor-store__panel" role="tabpanel" id="pv-vendor-panel-reviews" aria-labelledby="pv-vendor-tab-reviews" hidden>
            <div class="pv-vendor-store__reviews-head">
                <div class="pv-vendor-store__reviews-summary">
                    <span class="pv-vendor-store__reviews-big"><?php echo esc_html( number_format_i18n( $pv_rating, 1 ) ); ?></span>
                    <span class="pv-stars" aria-hidden="true">
                        <span class="pv-stars__stars">
                            <span class="pv-stars__fill" style="width:<?php echo esc_attr( ( $pv_rating / 5 ) * 100 ); ?>%"></span>
                        </span>
                    </span>
                    <span class="pv-vendor-store__reviews-count"><?php echo esc_html( sprintf( _n( '%d reseña', '%d reseñas', count( $pv_reviews ), 'ltms' ), count( $pv_reviews ) ) ); ?></span>
                </div>
            </div>

            <?php if ( ! empty( $pv_reviews ) ) : ?>
                <ul class="pv-vendor-store__reviews">
                    <?php foreach ( $pv_reviews as $pv_r ) :
                        $pv_r_rating = (int) get_comment_meta( $pv_r->comment_ID, 'rating', true );
                        $pv_r_pid    = (int) $pv_r->comment_post_ID;
                        $pv_r_product = $pv_r_pid > 0 ? wc_get_product( $pv_r_pid ) : null;
                        ?>
                        <li class="pv-card pv-vendor-store__review">
                            <div class="pv-vendor-store__review-head">
                                <span class="pv-vendor-store__review-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $pv_r->comment_author ?: 'A', 0, 1 ) ) ); ?></span>
                                <div>
                                    <span class="pv-vendor-store__review-author"><?php echo esc_html( $pv_r->comment_author ?: __( 'Comprador verificado', 'ltms' ) ); ?></span>
                                    <?php if ( $pv_r_rating ) : ?>
                                        <span class="pv-stars pv-stars--sm" aria-label="<?php echo esc_attr( sprintf( __( '%d de 5 estrellas', 'ltms' ), $pv_r_rating ) ); ?>">
                                            <span class="pv-stars__stars">
                                                <span class="pv-stars__fill" style="width:<?php echo esc_attr( ( $pv_r_rating / 5 ) * 100 ); ?>%"></span>
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <time class="pv-vendor-store__review-date" datetime="<?php echo esc_attr( $pv_r->comment_date ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pv_r->comment_date ) ) ); ?></time>
                            </div>
                            <?php if ( $pv_r->comment_content ) : ?>
                                <p class="pv-vendor-store__review-text"><?php echo esc_html( wp_strip_all_tags( $pv_r->comment_content ) ); ?></p>
                            <?php endif; ?>
                            <?php if ( $pv_r_product ) : ?>
                                <a class="pv-vendor-store__review-product" href="<?php echo esc_url( $pv_r_product->get_permalink() ); ?>">
                                    <?php echo $pv_r_product->get_image( 'thumbnail', [ 'class' => 'pv-vendor-store__review-thumb' ], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span><?php echo esc_html( wp_strip_all_tags( $pv_r_product->get_name() ) ); ?></span>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="pv-card pv-card--flat pv-vendor-store__empty">
                    <h3><?php esc_html_e( 'Aún no hay reseñas', 'ltms' ); ?></h3>
                    <p><?php esc_html_e( 'Cuando los compradores compren a este vendedor y dejen su calificación, aparecerán aquí.', 'ltms' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php
        /* ----- Panel 3: Sobre nosotros ----- */
        ?>
        <div class="pv-tabpanel pv-vendor-store__panel" role="tabpanel" id="pv-vendor-panel-about" aria-labelledby="pv-vendor-tab-about" hidden>
            <div class="pv-card pv-vendor-store__about">
                <h2 class="pv-vendor-store__about-title"><?php echo esc_html( sprintf( __( 'Sobre %s', 'ltms' ), $pv_store_name ) ); ?></h2>
                <?php if ( $pv_store_description ) : ?>
                    <div class="pv-vendor-store__about-text">
                        <?php echo wpautop( wp_kses_post( $pv_store_description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php else : ?>
                    <p class="pv-vendor-store__about-text pv-text-muted"><?php esc_html_e( 'Este vendedor aún no ha añadido una descripción a su tienda.', 'ltms' ); ?></p>
                <?php endif; ?>

                <ul class="pv-vendor-store__about-meta">
                    <li>
                        <span class="pv-vendor-store__about-meta-label"><?php esc_html_e( 'Vendedor desde', 'ltms' ); ?></span>
                        <span class="pv-vendor-store__about-meta-value"><?php echo esc_html( $pv_vendor_since ); ?></span>
                    </li>
                    <li>
                        <span class="pv-vendor-store__about-meta-label"><?php esc_html_e( 'Productos publicados', 'ltms' ); ?></span>
                        <span class="pv-vendor-store__about-meta-value"><?php echo esc_html( number_format_i18n( $pv_products_count ) ); ?></span>
                    </li>
                    <li>
                        <span class="pv-vendor-store__about-meta-label"><?php esc_html_e( 'Ventas completadas', 'ltms' ); ?></span>
                        <span class="pv-vendor-store__about-meta-value"><?php echo esc_html( number_format_i18n( $pv_sales_count ) ); ?></span>
                    </li>
                    <li>
                        <span class="pv-vendor-store__about-meta-label"><?php esc_html_e( 'Verificación', 'ltms' ); ?></span>
                        <span class="pv-vendor-store__about-meta-value">
                            <?php if ( $pv_kyc_status === 'approved' ) : ?>
                                <span class="pv-badge pv-badge--verified"><?php esc_html_e( 'KYC aprobado', 'ltms' ); ?></span>
                            <?php elseif ( $pv_kyc_status === 'pending' ) : ?>
                                <span class="pv-badge pv-badge--warning"><?php esc_html_e( 'Verificación pendiente', 'ltms' ); ?></span>
                            <?php else : ?>
                                <span class="pv-badge"><?php esc_html_e( 'No verificado', 'ltms' ); ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <?php
        /* ----- Panel 4: Políticas ----- */
        ?>
        <div class="pv-tabpanel pv-vendor-store__panel" role="tabpanel" id="pv-vendor-panel-policies" aria-labelledby="pv-vendor-tab-policies" hidden>
            <div class="pv-card pv-vendor-store__policies">
                <h2 class="pv-vendor-store__policies-title"><?php esc_html_e( 'Políticas de la tienda', 'ltms' ); ?></h2>
                <?php if ( $pv_policies ) : ?>
                    <div class="pv-vendor-store__policies-text">
                        <?php echo wp_kses_post( wpautop( wptexturize( $pv_policies ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php else : ?>
                    <p class="pv-text-muted"><?php esc_html_e( 'Este vendedor aún no ha publicado políticas específicas. Aplican las políticas generales del marketplace.', 'ltms' ); ?></p>
                <?php endif; ?>

                <div class="pv-vendor-store__policies-default">
                    <h3><?php esc_html_e( 'Políticas del marketplace', 'ltms' ); ?></h3>
                    <ul class="pv-vendor-store__policies-list">
                        <li>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                            <span><strong><?php esc_html_e( 'Compra protegida:', 'ltms' ); ?></strong> <?php esc_html_e( 'El pago se retiene en Escrow hasta que confirmes recibir el producto.', 'ltms' ); ?></span>
                        </li>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            <span><strong><?php esc_html_e( 'Envío:', 'ltms' ); ?></strong> <?php esc_html_e( 'Despacho a todo el país en 2-5 días hábiles. Seguimiento disponible desde tu cuenta.', 'ltms' ); ?></span>
                        </li>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span><strong><?php esc_html_e( 'Soporte:', 'ltms' ); ?></strong> <?php esc_html_e( 'Si tienes inconvenientes, abre un caso desde tu cuenta dentro de los 7 días posteriores a la entrega.', 'ltms' ); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

</div><!-- /.pv-scope.pv-vendor-store -->

<?php
/**
 * Inline JS: jump-to-tab desde el botón "Ver políticas" del hero.
 * Vanilla JS — sin dependencias.
 */
?>
<script>
(function(){
    document.addEventListener('click', function(e){
        var jump = e.target.closest('[data-pv-jump-tab]');
        if (!jump) return;
        var target = jump.getAttribute('data-pv-jump-tab');
        var tab = document.querySelector('#pv-vendor-tab-' + target);
        if (!tab) return;
        e.preventDefault();
        tab.click();
        var panel = document.getElementById('pv-vendor-panel-' + target);
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    /* Follow vendor — toggle visual state, AJAX best-effort. */
    document.addEventListener('click', function(e){
        var btn = e.target.closest('[data-pv-follow-vendor]');
        if (!btn) return;
        e.preventDefault();
        var active = btn.getAttribute('aria-pressed') === 'true';
        btn.setAttribute('aria-pressed', String(!active));
        if (!active) {
            btn.classList.add('is-following');
            btn.innerHTML = btn.innerHTML.replace(/(<?php echo esc_js( __( 'Seguir', 'ltms' ) ); ?>)/, '<?php echo esc_js( __( 'Siguiendo', 'ltms' ) ); ?>');
        } else {
            btn.classList.remove('is-following');
            btn.innerHTML = btn.innerHTML.replace(/(<?php echo esc_js( __( 'Siguiendo', 'ltms' ) ); ?>)/, '<?php echo esc_js( __( 'Seguir', 'ltms' ) ); ?>');
        }
    });
})();
</script>

<?php
/**
 * Hook: ltms_after_vendor_store_plazaviva
 * Permite inyectar contenido después del contenedor principal.
 */
do_action( 'ltms_after_vendor_store_plazaviva', $pv_vendor_id );

get_footer();
