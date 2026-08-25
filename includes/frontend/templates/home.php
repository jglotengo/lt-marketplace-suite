<?php
/**
 * Template: Home — Plaza Viva Design System
 *
 * Homepage nativa del marketplace para WooCommerce multi-vendor.
 * Se sirve vía `template_include` cuando la página frontal del sitio
 * corresponde al marketplace (ver LTMS_Native_Templates::maybe_override()
 * — integra home.php cuando is_front_page() y la opción
 * ltms_home_template_enabled = 'yes').
 *
 * Secciones:
 *  - Header 3 zonas (logo · buscador con chips · acciones de cuenta).
 *  - Hero banner (gradiente azul #2563EB) con CTA "Explorar productos".
 *  - Trust bar (4 items: Compra Protegida, Pago Seguro,
 *    Vendedores Verificados, Envío a todo el país). SIN devoluciones.
 *  - Bento grid de categorías (6 tiles asimétricos) con get_terms().
 *  - Trending productos (WC query best_sellers, 8 productos).
 *  - Vendedores destacados (Star Sellers: KYC approved + star_seller=1).
 *  - Footer (legales · métodos de pago · redes sociales).
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

// Garantizar que WooCommerce está cargado.
if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_page_id' ) ) {
    // Sin WC no tiene sentido renderizar la home del marketplace.
    get_header();
    echo '<div class="pv-scope pv-home"><main class="pv-section pv-fallback__section"><p class="pv-fallback__msg">' . esc_html__( 'WooCommerce no está activo. La homepage del marketplace requiere WooCommerce.', 'ltms' ) . '</p></main></div>';
    get_footer();
    return;
}

/* ---------------------------------------------------------------------------
 * 1. Datos del header (búsqueda, carrito, cuenta, favoritos)
 * ------------------------------------------------------------------------- */
$pv_shop_url    = get_permalink( wc_get_page_id( 'shop' ) );
$pv_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
$pv_cart_url    = wc_get_cart_url();
$pv_cart_count  = ( WC()->cart && ! WC()->cart->is_empty() ) ? (int) WC()->cart->get_cart_contents_count() : 0;

/**
 * URL de favoritos / wishlist.
 * Filterable para que el módulo LTMS_Wishlist pueda inyectar la URL real.
 */
$pv_wishlist_url = apply_filters( 'ltms_wishlist_url', home_url( '/favoritos' ) );
$pv_wishlist_count = apply_filters( 'ltms_wishlist_count', 0 );

/**
 * Popular Requests — chips de búsqueda rápidos.
 * Hardcodeados por diseño (4 chips). Filterable para personalización.
 */
$pv_popular_chips = apply_filters( 'ltms_home_popular_chips', array(
    __( 'Juegos de mesa', 'ltms' ),
    __( 'Regalos', 'ltms' ),
    __( 'Hogar', 'ltms' ),
    __( 'Tecnología', 'ltms' ),
) );

/* ---------------------------------------------------------------------------
 * 2. Categorías para el Bento Grid (get_terms → product_cat)
 *    Top 6 por número de productos, orden descendente.
 * ------------------------------------------------------------------------- */
$pv_cat_terms = get_terms( array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'number'     => 6,
    'orderby'    => 'count',
    'order'      => 'DESC',
) );

/**
 * Mapa slug → emoji para los iconos de categoría.
 * Fallback genérico 🛍️ si el slug no coincide.
 */
$pv_cat_icons = apply_filters( 'ltms_home_category_icons', array(
    'tecnologia'   => '💻',
    'electronica'  => '🔌',
    'hogar'        => '🏠',
    'moda'         => '👕',
    'ropa'         => '👗',
    'belleza'      => '💄',
    'deportes'     => '⚽',
    'juegos'       => '🎮',
    'juegos-de-mesa' => '🎲',
    'libros'       => '📚',
    'juguetes'     => '🧸',
    'muebles'      => '🛋️',
    'cocina'       => '🍳',
    'jardin'       => '🌿',
    'automotriz'   => '🚗',
    'salud'        => '💊',
    'mascotas'     => '🐾',
    'alimentos'    => '🛒',
    'bebidas'      => '🍷',
    'musica'       => '🎵',
    'arte'         => '🎨',
    'regalos'      => '🎁',
) );

/* ---------------------------------------------------------------------------
 * 3. Trending productos — WC()->query->get_catalog_ordering_args('popularity')
 *    8 productos más vendidos. Usamos get_posts() con los ordering args.
 * ------------------------------------------------------------------------- */
$pv_trending_ids = array();
if ( class_exists( 'WooCommerce' ) && isset( WC()->query ) && method_exists( WC()->query, 'get_catalog_ordering_args' ) ) {
    $pv_order_args   = WC()->query->get_catalog_ordering_args( 'popularity' );
    $pv_trending_qargs = wp_parse_args( $pv_order_args, array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => 8,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
        'fields'              => 'ids',
        'tax_query'           => array(), // WC->query->get_tax_query() filtraría por la página actual; aquí queremos global.
    ) );

    // Meta query de visibilidad WC (excluir hidden/exclude-from-search).
    if ( method_exists( WC()->query, 'get_meta_query' ) ) {
        $pv_trending_qargs['meta_query'] = WC()->query->get_meta_query();
    }

    $pv_trending_ids = get_posts( $pv_trending_qargs );
}

// Fallback: si no hay best-sellers, tomar los 8 productos más recientes.
if ( empty( $pv_trending_ids ) ) {
    $pv_trending_ids = get_posts( array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => 8,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'fields'              => 'ids',
        'tax_query'           => array(
            array(
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
                'operator' => 'NOT IN',
            ),
        ),
    ) );
}

/* ---------------------------------------------------------------------------
 * 4. Vendedores destacados — Star Sellers
 *    Query users con ltms_kyc_status=approved AND ltms_star_seller=1.
 *    4 vendedores, ordenados por fecha de registro (más recientes primero).
 *
 *    UX-AUDIT-FE-P0-05 FIX: este query coincide 1:1 con el criterio canónico
 *    LTMS_Trust_Badges::is_star_seller(). Antes single-product.php divergía
 *    usando umbral sales>=50 — ya corregido. Si en algún momento el criterio
 *    canónico cambia en el helper, debe cambiarse también este query para
 *    mantener paridad (mejor: hacer que single-product + vendor-store llamen
 *    al helper en runtime, como ahora; este query queda como filtrado
 *    server-side para evitar N llamadas individuales en una vitrina con 4
 *    vendors). Ver docblock de is_star_seller().
 * ------------------------------------------------------------------------- */
$pv_star_vendors = get_users( array(
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key'     => 'ltms_kyc_status',
            'value'   => 'approved',
            'compare' => '=',
        ),
        array(
            'key'     => 'ltms_star_seller',
            'value'   => '1',
            'compare' => '=',
        ),
    ),
    'number'  => 4,
    'orderby' => 'registered',
    'order'   => 'DESC',
    'fields'  => 'ID',
) );

/* ---------------------------------------------------------------------------
 * 5. Helpers de render
 *
 * AUDIT-FE-PV-DS-003 FIX (P1-1, DRY): el helper ltms_pv_render_trending_card()
 * fue eliminado — reimplementaba .pv-product-card duplicando
 * wc-parts/content-product.php con un subconjunto de features (sin KYC badge,
 * sin SF-04 free shipping, sin swatches, sin stock urgency, sin badges
 * --soft/--muted). La sección trending ahora delega vía
 * wc_get_template_part( 'content', 'product' ) — una sola fuente de verdad
 * para el UI de card de producto (ver loop TRENDING más abajo).
 * ------------------------------------------------------------------------- */

/**
 * Renderiza un icono SVG de red social.
 *
 * @param string $pv_key Clave del icono (instagram, facebook, tiktok, youtube, x).
 * @return string Markup SVG.
 */
if ( ! function_exists( 'ltms_pv_social_icon' ) ) :
function ltms_pv_social_icon( $pv_key ) {
    $pv_icons = array(
        'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
        'facebook'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
        'tiktok'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
        'youtube'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>',
        'x'         => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
    );
    return isset( $pv_icons[ $pv_key ] ) ? $pv_icons[ $pv_key ] : '';
}
endif; // ltms_pv_social_icon

get_header();

/**
 * Hook: ltms_before_home_plazaviva
 * Permite inyectar contenido antes del contenedor principal.
 */
do_action( 'ltms_before_home_plazaviva' );
?>

<div class="pv-scope pv-home">

    <?php
    /* =====================================================================
     * HEADER — 3 zonas: logo · buscador + chips · acciones de cuenta
     * =====================================================================
     */
    ?>
    <header class="pv-home-header" role="banner">
        <div class="pv-section pv-home-header__inner">

            <?php /* --- Zona 1: Logo "Lo Tengo" --- */ ?>
            <a class="pv-home-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                <span class="pv-home-header__logo-mark" aria-hidden="true">📍</span>
                <span class="pv-home-header__logo-text">
                    <span class="pv-home-header__logo-name"><?php esc_html_e( 'Lo Tengo', 'ltms' ); ?></span>
                    <span class="pv-home-header__logo-tag"><?php esc_html_e( 'Marketplace', 'ltms' ); ?></span>
                </span>
            </a>

            <?php /* --- Zona 2: Buscador con chips --- */ ?>
            <div class="pv-home-header__search" role="search">
                <form class="pv-home-header__search-form" method="get" action="<?php echo esc_url( $pv_shop_url ); ?>">
                    <label class="pv-visually-hidden" for="pv-home-search"><?php esc_html_e( 'Buscar productos', 'ltms' ); ?></label>
                    <span class="pv-home-header__search-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="search"
                           id="pv-home-search"
                           class="pv-home-header__search-input"
                           name="s"
                           placeholder="<?php esc_attr_e( 'Buscar productos, marcas, vendedores...', 'ltms' ); ?>"
                           value="<?php echo esc_attr( get_search_query() ); ?>"
                           autocomplete="off" />
                    <input type="hidden" name="post_type" value="product" />
                    <button type="submit" class="pv-btn pv-btn--sm pv-home-header__search-btn">
                        <?php esc_html_e( 'Buscar', 'ltms' ); ?>
                    </button>
                </form>
                <?php if ( ! empty( $pv_popular_chips ) ) : ?>
                    <ul class="pv-home-header__chips" aria-label="<?php esc_attr_e( 'Búsquedas populares', 'ltms' ); ?>">
                        <?php foreach ( $pv_popular_chips as $pv_chip ) : ?>
                            <li>
                                <button type="button" class="pv-home-header__chip" data-pv-search-chip="<?php echo esc_attr( $pv_chip ); ?>" data-pv-search-chip-value="<?php echo esc_attr( $pv_chip ); ?>">
                                    <?php echo esc_html( $pv_chip ); ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php /* --- Zona 3: Acciones de cuenta --- */ ?>
            <nav class="pv-home-header__actions" aria-label="<?php esc_attr_e( 'Acciones de cuenta', 'ltms' ); ?>">
                <a class="pv-home-header__action" href="<?php echo esc_url( $pv_account_url ); ?>" aria-label="<?php esc_attr_e( 'Mi cuenta', 'ltms' ); ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="pv-home-header__action-label"><?php esc_html_e( 'Cuenta', 'ltms' ); ?></span>
                </a>
                <a class="pv-home-header__action" href="<?php echo esc_url( $pv_wishlist_url ); ?>" aria-label="<?php esc_attr_e( 'Favoritos', 'ltms' ); ?>">
                    <span class="pv-home-header__action-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <?php if ( $pv_wishlist_count > 0 ) : ?>
                            <span class="pv-home-header__badge"><?php echo esc_html( number_format_i18n( $pv_wishlist_count ) ); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="pv-home-header__action-label"><?php esc_html_e( 'Favoritos', 'ltms' ); ?></span>
                </a>
                <a class="pv-home-header__action" href="<?php echo esc_url( $pv_cart_url ); ?>" aria-label="<?php esc_attr_e( 'Carrito', 'ltms' ); ?>">
                    <span class="pv-home-header__action-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        <?php if ( $pv_cart_count > 0 ) : ?>
                            <span class="pv-home-header__badge pv-home-header__badge--accent"><?php echo esc_html( number_format_i18n( $pv_cart_count ) ); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="pv-home-header__action-label"><?php esc_html_e( 'Carrito', 'ltms' ); ?></span>
                </a>
            </nav>

        </div>
    </header><!-- /.pv-home-header -->

    <?php
    /* =====================================================================
     * HERO BANNER — gradiente azul #2563EB
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-home__hero-wrap" aria-labelledby="pv-home-hero-title">
        <div class="pv-hero pv-home-hero">
            <span class="pv-hero__eyebrow">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                <?php esc_html_e( 'Marketplace protegido con Escrow', 'ltms' ); ?>
            </span>
            <h1 id="pv-home-hero-title" class="pv-hero__title">
                <?php esc_html_e( 'Compra con confianza, vende con libertad', 'ltms' ); ?>
            </h1>
            <p class="pv-hero__sub">
                <?php esc_html_e( 'Miles de productos de vendedores verificados. Pago seguro con Escrow, wallets integradas y envío a todo el país.', 'ltms' ); ?>
            </p>
            <div class="pv-hero__actions">
                <a class="pv-btn pv-btn--lg" href="<?php echo esc_url( $pv_shop_url ); ?>">
                    <?php esc_html_e( 'Explorar productos', 'ltms' ); ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a class="pv-btn pv-btn--ghost pv-btn--lg" href="<?php echo esc_url( apply_filters( 'ltms_become_seller_url', home_url( '/vendedor/registro' ) ) ); ?>">
                    <?php esc_html_e( 'Vender en Lo Tengo', 'ltms' ); ?>
                </a>
            </div>
        </div>
    </section>

    <?php
    /* =====================================================================
     * TRUST BAR — 4 items (SIN devoluciones)
     *   1. Compra Protegida (Escrow hasta confirmar)
     *   2. Pago Seguro (PSE · Nequi · Tarjeta)
     *   3. Vendedores Verificados (KYC + Star Seller)
     *   4. Envío a todo el país (Deprisa · Aveonline · Heka)
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-home__trust" aria-label="<?php esc_attr_e( 'Garantías del marketplace', 'ltms' ); ?>">
        <div class="pv-trust-bar">
            <div class="pv-trust-item">
                <span class="pv-trust-item__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                </span>
                <span class="pv-trust-item__body">
                    <span class="pv-trust-item__title"><?php esc_html_e( 'Compra Protegida', 'ltms' ); ?></span>
                    <span class="pv-trust-item__desc"><?php esc_html_e( 'Escrow hasta que confirmes recibir', 'ltms' ); ?></span>
                </span>
            </div>
            <div class="pv-trust-item">
                <span class="pv-trust-item__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </span>
                <span class="pv-trust-item__body">
                    <span class="pv-trust-item__title"><?php esc_html_e( 'Pago Seguro', 'ltms' ); ?></span>
                    <span class="pv-trust-item__desc"><?php esc_html_e( 'PSE · Nequi · Tarjeta', 'ltms' ); ?></span>
                </span>
            </div>
            <div class="pv-trust-item">
                <span class="pv-trust-item__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </span>
                <span class="pv-trust-item__body">
                    <span class="pv-trust-item__title"><?php esc_html_e( 'Vendedores Verificados', 'ltms' ); ?></span>
                    <span class="pv-trust-item__desc"><?php esc_html_e( 'KYC + Star Seller', 'ltms' ); ?></span>
                </span>
            </div>
            <div class="pv-trust-item">
                <span class="pv-trust-item__icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </span>
                <span class="pv-trust-item__body">
                    <span class="pv-trust-item__title"><?php esc_html_e( 'Envío a todo el país', 'ltms' ); ?></span>
                    <span class="pv-trust-item__desc"><?php esc_html_e( 'Deprisa · Aveonline · Heka', 'ltms' ); ?></span>
                </span>
            </div>
        </div>
    </section>

    <?php
    /* =====================================================================
     * BENTO GRID — 6 categorías asimétricas
     * =====================================================================
     */
    if ( ! empty( $pv_cat_terms ) && ! is_wp_error( $pv_cat_terms ) ) :
        $pv_bento_classes = array( 'a', 'b', 'c', 'd', 'e', 'f' ); // placement
    ?>
        <section class="pv-section pv-home__cats" aria-labelledby="pv-home-cats-title">
            <header class="pv-section__head">
                <div>
                    <h2 id="pv-home-cats-title" class="pv-section__title"><?php esc_html_e( 'Explora por categorías', 'ltms' ); ?></h2>
                    <p class="pv-section__sub"><?php esc_html_e( 'Encuentra lo que buscas en nuestros principales departamentos', 'ltms' ); ?></p>
                </div>
                <a class="pv-section__more" href="<?php echo esc_url( $pv_shop_url ); ?>">
                    <?php esc_html_e( 'Ver todas', 'ltms' ); ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </header>

            <div class="pv-bento-grid" role="list">
                <?php foreach ( $pv_cat_terms as $pv_idx => $pv_term ) :
                    $pv_placement = isset( $pv_bento_classes[ $pv_idx ] ) ? $pv_bento_classes[ $pv_idx ] : 'f';
                    $pv_icon = isset( $pv_cat_icons[ $pv_term->slug ] ) ? $pv_cat_icons[ $pv_term->slug ] : '🛍️';
                    $pv_cat_url = get_term_link( $pv_term );
                    if ( is_wp_error( $pv_cat_url ) ) {
                        $pv_cat_url = $pv_shop_url;
                    }
                    $pv_count = (int) $pv_term->count;
                ?>
                    <a class="pv-bento-tile pv-bento-tile--<?php echo esc_attr( $pv_placement ); ?> <?php echo $pv_placement === 'a' ? 'pv-bento-tile--feature' : ''; ?>"
                       href="<?php echo esc_url( $pv_cat_url ); ?>"
                       role="listitem"
                       aria-label="<?php echo esc_attr( sprintf( __( '%s — %d productos', 'ltms' ), $pv_term->name, $pv_count ) ); ?>">
                        <span class="pv-bento-tile__icon" aria-hidden="true"><?php echo esc_html( $pv_icon ); ?></span>
                        <span class="pv-bento-tile__body">
                            <span class="pv-bento-tile__name"><?php echo esc_html( $pv_term->name ); ?></span>
                            <span class="pv-bento-tile__count">
                                <?php
                                /* translators: %d: número de productos. */
                                echo esc_html( sprintf( _n( '%d producto', '%d productos', $pv_count, 'ltms' ), $pv_count ) );
                                ?>
                            </span>
                        </span>
                        <span class="pv-bento-tile__arrow" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else : ?>
        <!-- AUDIT-FE-PV-DS-008 FIX (P1-6): empty state visible en vez de sección silenciosa -->
        <section class="pv-section pv-home__cats">
            <div class="pv-card pv-card--flat pv-home__empty-note">
                <h3><?php esc_html_e( 'Próximamente más categorías', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Estamos organizando el catálogo. Mientras tanto, explora todos los productos disponibles.', 'ltms' ); ?></p>
                <a class="pv-btn pv-btn--sm" href="<?php echo esc_url( $pv_shop_url ); ?>"><?php esc_html_e( 'Ver todos los productos', 'ltms' ); ?></a>
            </div>
        </section>
    <?php endif; ?>

    <?php
    /* =====================================================================
     * TRENDING PRODUCTOS — 8 best sellers
     * =====================================================================
     */
    if ( ! empty( $pv_trending_ids ) ) :
    ?>
        <section class="pv-section pv-home__trending" aria-labelledby="pv-home-trending-title">
            <header class="pv-section__head">
                <div>
                    <h2 id="pv-home-trending-title" class="pv-section__title"><?php esc_html_e( '🔥 Productos en tendencia', 'ltms' ); ?></h2>
                    <p class="pv-section__sub"><?php esc_html_e( 'Los más vendidos del marketplace esta semana', 'ltms' ); ?></p>
                </div>
                <a class="pv-section__more" href="<?php echo esc_url( add_query_arg( 'orderby', 'popularity', $pv_shop_url ) ); ?>">
                    <?php esc_html_e( 'Ver más', 'ltms' ); ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </header>

            <div class="pv-home__product-grid" role="list">
                <?php
                /*
                 * AUDIT-FE-PV-DS-003 FIX (P1-1, DRY): la card trending delega al
                 * template part canónico wc-parts/content-product.php — el mismo
                 * markup que shop/related/cross-sells. El helper duplicado
                 * ltms_pv_render_trending_card() fue eliminado físicamente.
                 */
                foreach ( $pv_trending_ids as $pv_tid ) :
                    $pv_trending_product = wc_get_product( $pv_tid );
                    if ( ! $pv_trending_product instanceof WC_Product || ! $pv_trending_product->is_visible() ) {
                        continue;
                    }
                    // content-product.php consume los globals $product/$post.
                    global $product, $post;
                    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup intencional para el template part
                    $product = $pv_trending_product;
                    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup intencional para el template part
                    $post    = get_post( $pv_tid );
                    wc_get_template_part( 'content', 'product' );
                endforeach;
                wp_reset_postdata();
                ?>
            </div>
        </section>
    <?php else : ?>
        <!-- AUDIT-FE-PV-DS-008 FIX (P1-6): empty state visible en vez de sección silenciosa -->
        <section class="pv-section pv-home__trending">
            <div class="pv-card pv-card--flat pv-home__empty-note">
                <h3><?php esc_html_e( 'Aún no hay productos en tendencia', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Cuando los vendedores publiquen sus productos, los más vendidos aparecerán aquí.', 'ltms' ); ?></p>
                <a class="pv-btn pv-btn--sm" href="<?php echo esc_url( $pv_shop_url ); ?>"><?php esc_html_e( 'Explorar productos', 'ltms' ); ?></a>
            </div>
        </section>
    <?php endif; ?>

    <?php
    /* =====================================================================
     * VENDEDORES DESTACADOS — Star Sellers (KYC approved + star_seller=1)
     * =====================================================================
     */
    if ( ! empty( $pv_star_vendors ) ) :
    ?>
        <section class="pv-section pv-home__vendors" aria-labelledby="pv-home-vendors-title">
            <header class="pv-section__head">
                <div>
                    <h2 id="pv-home-vendors-title" class="pv-section__title"><?php esc_html_e( '⭐ Vendedores destacados', 'ltms' ); ?></h2>
                    <p class="pv-section__sub"><?php esc_html_e( 'Star Sellers verificados con KYC y excelente reputación', 'ltms' ); ?></p>
                </div>
                <a class="pv-section__more" href="<?php echo esc_url( apply_filters( 'ltms_sellers_page_url', home_url( '/vendedores' ) ) ); ?>">
                    <?php esc_html_e( 'Ver todos', 'ltms' ); ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </header>

            <div class="pv-home__vendor-grid" role="list">
                <?php foreach ( $pv_star_vendors as $pv_vid ) :
                    $pv_v = get_userdata( $pv_vid );
                    if ( ! $pv_v ) {
                        continue;
                    }
                    $pv_vname = (string) get_user_meta( $pv_vid, 'ltms_store_name', true );
                    if ( '' === $pv_vname ) {
                        $pv_vname = $pv_v->display_name ?: $pv_v->user_login;
                    }
                    $pv_vslug = (string) get_user_meta( $pv_vid, 'ltms_store_slug', true );
                    if ( $pv_vslug ) {
                        $pv_vurl = home_url( '/vendedor/' . rawurlencode( $pv_vslug ) );
                    } else {
                        $pv_vurl = get_author_posts_url( $pv_vid );
                    }
                    $pv_vrating = (float) get_user_meta( $pv_vid, 'ltms_vendor_rating', true );
                    $pv_vsales  = 0;
                    if ( class_exists( 'LTMS_Trust_Badges' ) && method_exists( 'LTMS_Trust_Badges', 'get_vendor_sales_count' ) ) {
                        $pv_vsales = (int) LTMS_Trust_Badges::get_vendor_sales_count( $pv_vid );
                    }
                    $pv_vproducts = (int) count_user_posts( $pv_vid, 'product', true );
                    $pv_vavatar = get_avatar( $pv_vid, 72, '', $pv_vname, array( 'class' => 'pv-vendor-card__img' ) );
                ?>
                    <article class="pv-vendor-card pv-card pv-fade-up" role="listitem">
                        <div class="pv-vendor-card__head">
                            <!-- AUDIT-FE-PV-DS-012 FIX (P1-11): el badge vive dentro
                                 del wrapper del avatar y se centra con translateX(-50%)
                                 — antes usaba right:50%+translateX(30px) fijo, que se
                                 desalineaba si el ancho del badge cambiaba. -->
                            <span class="pv-vendor-card__avatar-wrap">
                                <span class="pv-vendor-card__avatar"><?php echo $pv_vavatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                <span class="pv-badge pv-badge--gold pv-vendor-card__star">
                                    <?php esc_html_e( '★ Star Seller', 'ltms' ); ?>
                                </span>
                            </span>
                        </div>
                        <div class="pv-vendor-card__body">
                            <h3 class="pv-vendor-card__name"><?php echo esc_html( $pv_vname ); ?></h3>
                            <div class="pv-vendor-card__meta">
                                <?php if ( $pv_vrating > 0 ) : ?>
                                    <span class="pv-vendor-card__rating">
                                        <?php echo wc_get_rating_html( $pv_vrating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span class="pv-vendor-card__rating-num"><?php echo esc_html( number_format_i18n( $pv_vrating, 1 ) ); ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $pv_vsales > 0 ) : ?>
                                    <span class="pv-vendor-card__sales">
                                        <?php
                                        /* translators: %d: número de ventas. */
                                        echo esc_html( sprintf( _n( '%d venta', '%d ventas', $pv_vsales, 'ltms' ), $pv_vsales ) );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="pv-vendor-card__products">
                                <?php
                                /* translators: %d: número de productos. */
                                echo esc_html( sprintf( _n( '%d producto activo', '%d productos activos', $pv_vproducts, 'ltms' ), $pv_vproducts ) );
                                ?>
                            </span>
                        </div>
                        <a class="pv-btn pv-btn--ghost pv-btn--sm pv-btn--block pv-vendor-card__visit"
                           href="<?php echo esc_url( $pv_vurl ); ?>"
                           aria-label="<?php echo esc_attr( sprintf( __( 'Visitar la tienda de %s', 'ltms' ), $pv_vname ) ); ?>">
                            <?php esc_html_e( 'Ver tienda', 'ltms' ); ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else : ?>
        <!-- AUDIT-FE-PV-DS-008 FIX (P1-6): empty state visible en vez de sección silenciosa -->
        <section class="pv-section pv-home__vendors">
            <div class="pv-card pv-card--flat pv-home__empty-note">
                <h3><?php esc_html_e( 'Aún no hay vendedores destacados', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Los Star Sellers verificados con KYC y excelente reputación aparecerán aquí.', 'ltms' ); ?></p>
            </div>
        </section>
    <?php endif; ?>

    <?php
    /* =====================================================================
     * FOOTER — enlaces legales · métodos de pago · redes sociales
     * =====================================================================
     */
    ?>
    <footer class="pv-home-footer" role="contentinfo">
        <div class="pv-section pv-home-footer__inner">

            <div class="pv-home-footer__col pv-home-footer__col--brand">
                <a class="pv-home-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <span aria-hidden="true">📍</span>
                    <span><?php esc_html_e( 'Lo Tengo', 'ltms' ); ?></span>
                </a>
                <p class="pv-home-footer__tagline">
                    <?php esc_html_e( 'El marketplace donde compras con confianza y vendes con libertad. Protegido con Escrow, vendedores verificados y envío a todo el país.', 'ltms' ); ?>
                </p>
            </div>

            <nav class="pv-home-footer__col" aria-label="<?php esc_attr_e( 'Enlaces legales', 'ltms' ); ?>">
                <h4 class="pv-home-footer__col-title"><?php esc_html_e( 'Legal', 'ltms' ); ?></h4>
                <ul class="pv-home-footer__links">
                    <?php
                    $pv_legal_links = apply_filters( 'ltms_home_footer_legal_links', array(
                        array( 'label' => __( 'Términos y condiciones', 'ltms' ), 'url' => home_url( '/terminos' ) ),
                        array( 'label' => __( 'Política de privacidad', 'ltms' ), 'url' => home_url( '/privacidad' ) ),
                        array( 'label' => __( 'Política de cookies', 'ltms' ), 'url' => home_url( '/cookies' ) ),
                        array( 'label' => __( 'Tratamiento de datos', 'ltms' ), 'url' => home_url( '/habeas-data' ) ),
                    ) );
                    foreach ( $pv_legal_links as $pv_link ) :
                    ?>
                        <li><a href="<?php echo esc_url( $pv_link['url'] ); ?>"><?php echo esc_html( $pv_link['label'] ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <nav class="pv-home-footer__col" aria-label="<?php esc_attr_e( 'Métodos de pago', 'ltms' ); ?>">
                <h4 class="pv-home-footer__col-title"><?php esc_html_e( 'Pagos', 'ltms' ); ?></h4>
                <ul class="pv-home-footer__payments">
                    <?php
                    $pv_payments = apply_filters( 'ltms_home_footer_payments', array(
                        'PSE', 'Nequi', 'Daviplata', 'Visa', 'Mastercard', 'Amex',
                    ) );
                    foreach ( $pv_payments as $pv_pay ) :
                    ?>
                        <li class="pv-home-footer__pay-badge"><?php echo esc_html( $pv_pay ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="pv-home-footer__pay-note">
                    <?php esc_html_e( 'Pago seguro con Escrow · Billetera Lo Tengo', 'ltms' ); ?>
                </p>
            </nav>

            <nav class="pv-home-footer__col" aria-label="<?php esc_attr_e( 'Redes sociales', 'ltms' ); ?>">
                <h4 class="pv-home-footer__col-title"><?php esc_html_e( 'Síguenos', 'ltms' ); ?></h4>
                <ul class="pv-home-footer__social">
                    <?php
                    $pv_socials = apply_filters( 'ltms_home_footer_socials', array(
                        array( 'label' => 'Instagram', 'url' => 'https://instagram.com', 'icon' => 'instagram' ),
                        array( 'label' => 'Facebook',  'url' => 'https://facebook.com',  'icon' => 'facebook' ),
                        array( 'label' => 'TikTok',    'url' => 'https://tiktok.com',     'icon' => 'tiktok' ),
                        array( 'label' => 'YouTube',   'url' => 'https://youtube.com',    'icon' => 'youtube' ),
                        array( 'label' => 'X',         'url' => 'https://x.com',          'icon' => 'x' ),
                    ) );
                    foreach ( $pv_socials as $pv_soc ) :
                    ?>
                        <li>
                            <a class="pv-home-footer__social-link" href="<?php echo esc_url( $pv_soc['url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $pv_soc['label'] ); ?>">
                                <?php echo ltms_pv_social_icon( $pv_soc['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

        </div>
        <div class="pv-home-footer__bottom">
            <div class="pv-section pv-home-footer__bottom-inner">
                <span>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. <?php esc_html_e( 'Todos los derechos reservados.', 'ltms' ); ?></span>
                <span class="pv-home-footer__built"><?php esc_html_e( 'Marketplace powered by LT Marketplace Suite', 'ltms' ); ?></span>
            </div>
        </div>
    </footer><!-- /.pv-home-footer -->

</div><!-- /.pv-scope.pv-home -->

<?php
/**
 * Hook: ltms_after_home_plazaviva
 * Punto de extensión final antes del footer del tema.
 */
do_action( 'ltms_after_home_plazaviva' );
?>

<?php
/* ============================================================================
 * Estilos estructurales específicos de la homepage.
 * El design system (ltms-plaza-viva.css) cubre componentes compartidos
 * (.pv-btn, .pv-badge, .pv-card, .pv-product-card, .pv-hero, .pv-trust-bar,
 * .pv-section, grid-*, spacing, micro-interactions). Estas reglas cubren
 * SOLO el layout de la home y están scopeadas bajo .pv-scope.pv-home.
 * ========================================================================== */
?>
<style>
.pv-scope.pv-home{display:block;background:var(--bg);}

/* ── HEADER ──────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home-header{
    position:sticky;top:0;z-index:50;
    background:rgba(255,255,255,.94);backdrop-filter:blur(10px);
    border-bottom:1px solid var(--border);
}
.pv-scope.pv-home .pv-home-header__inner{
    display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:24px;
    padding-top:14px;padding-bottom:14px;
}
.pv-scope.pv-home .pv-home-header__logo{
    display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);
}
.pv-scope.pv-home .pv-home-header__logo-mark{
    width:42px;height:42px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:20px;
    background:var(--primary-50);border-radius:var(--r-md);
}
.pv-scope.pv-home .pv-home-header__logo-text{display:flex;flex-direction:column;line-height:1.1;}
.pv-scope.pv-home .pv-home-header__logo-name{font-family:var(--display);font-weight:800;font-size:19px;color:var(--text);}
.pv-scope.pv-home .pv-home-header__logo-tag{font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;}

.pv-scope.pv-home .pv-home-header__search{display:flex;flex-direction:column;gap:8px;min-width:0;}
.pv-scope.pv-home .pv-home-header__search-form{
    display:flex;align-items:center;gap:0;
    background:var(--surface);border:2px solid var(--border);
    border-radius:var(--r-pill);padding:4px 4px 4px 16px;
    transition:border-color var(--t),box-shadow var(--t);
}
.pv-scope.pv-home .pv-home-header__search-form:focus-within{
    border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,.14);
}
.pv-scope.pv-home .pv-home-header__search-icon{color:var(--text-3);flex-shrink:0;display:flex;}
.pv-scope.pv-home .pv-home-header__search-input{
    flex:1;height:40px;border:0;background:transparent;font-size:14.5px;
    padding:0 12px;min-width:0;
}
.pv-scope.pv-home .pv-home-header__search-input:focus{outline:none;}
.pv-scope.pv-home .pv-home-header__search-btn{border-radius:var(--r-pill);height:40px;}
.pv-scope.pv-home .pv-home-header__chips{display:flex;gap:6px;flex-wrap:wrap;}
.pv-scope.pv-home .pv-home-header__chip{
    padding:3px 11px;border-radius:var(--r-pill);
    background:var(--bg-2);color:var(--text-2);
    font-size:12px;font-weight:600;border:1px solid transparent;
    cursor:pointer;transition:background var(--t),color var(--t),border-color var(--t);
}
.pv-scope.pv-home .pv-home-header__chip:hover{background:var(--primary-50);color:var(--primary-700);border-color:var(--primary-100);}

.pv-scope.pv-home .pv-home-header__actions{display:flex;align-items:center;gap:6px;}
.pv-scope.pv-home .pv-home-header__action{
    display:flex;flex-direction:column;align-items:center;gap:3px;
    padding:6px 12px;border-radius:var(--r-md);color:var(--text-2);
    text-decoration:none;transition:background var(--t),color var(--t);
    position:relative;
}
.pv-scope.pv-home .pv-home-header__action:hover{background:var(--bg-2);color:var(--primary);}
.pv-scope.pv-home .pv-home-header__action-label{font-size:11px;font-weight:600;}
.pv-scope.pv-home .pv-home-header__action-icon{position:relative;display:flex;}
.pv-scope.pv-home .pv-home-header__badge{
    position:absolute;top:-4px;right:-6px;
    min-width:18px;height:18px;padding:0 5px;
    display:flex;align-items:center;justify-content:center;
    background:var(--primary);color:#fff;
    border-radius:var(--r-pill);font-size:10.5px;font-weight:700;
    border:2px solid var(--surface);
}
.pv-scope.pv-home .pv-home-header__badge--accent{background:var(--accent);}

/* ── HERO ────────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__hero-wrap{padding-top:28px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home-hero{padding:52px 48px;min-height:360px;}

/* ── TRUST ───────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__trust{padding-top:24px;padding-bottom:8px;}

/* ── BENTO GRID ──────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__cats{padding-top:40px;padding-bottom:8px;}
.pv-scope.pv-home .pv-bento-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    grid-auto-rows:minmax(130px,auto);
    grid-template-areas:
        "a a b b"
        "a a c d"
        "e e f f";
    gap:16px;
}
.pv-scope.pv-home .pv-bento-tile--a{grid-area:a;}
.pv-scope.pv-home .pv-bento-tile--b{grid-area:b;}
.pv-scope.pv-home .pv-bento-tile--c{grid-area:c;}
.pv-scope.pv-home .pv-bento-tile--d{grid-area:d;}
.pv-scope.pv-home .pv-bento-tile--e{grid-area:e;}
.pv-scope.pv-home .pv-bento-tile--f{grid-area:f;}

.pv-scope.pv-home .pv-bento-tile{
    position:relative;display:flex;flex-direction:column;justify-content:flex-end;
    padding:22px;border-radius:var(--r-card);overflow:hidden;
    background:var(--surface);border:1px solid var(--border);box-shadow:var(--sh-1);
    text-decoration:none;color:var(--text);
    transition:transform var(--t-slow),box-shadow var(--t-slow),border-color var(--t-slow);
}
.pv-scope.pv-home .pv-bento-tile:hover{
    transform:translateY(-4px);box-shadow:var(--sh-hover);border-color:var(--primary-100);
}
.pv-scope.pv-home .pv-bento-tile__icon{
    position:absolute;top:18px;left:22px;font-size:34px;line-height:1;
    filter:drop-shadow(0 2px 4px rgba(15,17,17,.08));
}
.pv-scope.pv-home .pv-bento-tile__body{display:flex;flex-direction:column;gap:2px;position:relative;z-index:1;}
.pv-scope.pv-home .pv-bento-tile__name{font-family:var(--display);font-weight:700;font-size:16px;color:var(--text);}
.pv-scope.pv-home .pv-bento-tile__count{font-size:12.5px;color:var(--text-3);font-weight:500;}
.pv-scope.pv-home .pv-bento-tile__arrow{
    position:absolute;bottom:22px;right:22px;
    width:36px;height:36px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:var(--bg);color:var(--text-2);
    transition:background var(--t),color var(--t),transform var(--t);
}
.pv-scope.pv-home .pv-bento-tile:hover .pv-bento-tile__arrow{background:var(--primary);color:#fff;transform:translateX(2px);}

/* Tile destacado (a) — fondo con gradiente sutil */
.pv-scope.pv-home .pv-bento-tile--feature{
    background:linear-gradient(135deg,var(--primary-50) 0%,var(--surface) 60%);
    border-color:var(--primary-100);
}
.pv-scope.pv-home .pv-bento-tile--feature .pv-bento-tile__icon{font-size:54px;}
.pv-scope.pv-home .pv-bento-tile--feature .pv-bento-tile__name{font-size:22px;font-weight:800;}

/* ── TRENDING ────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__trending{padding-top:40px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home__product-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:18px;
}
.pv-scope.pv-home .pv-home__product-grid .pv-product-card{margin:0;}

/* ── VENDORS ─────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__vendors{padding-top:40px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home__vendor-grid{
    display:grid;grid-template-columns:repeat(4,1fr);gap:18px;
}
.pv-scope.pv-home .pv-vendor-card{
    display:flex;flex-direction:column;align-items:center;text-align:center;
    padding:24px 20px;gap:14px;
}
.pv-scope.pv-home .pv-vendor-card__head{position:relative;display:flex;justify-content:center;}
.pv-scope.pv-home .pv-vendor-card__avatar{
    width:72px;height:72px;border-radius:50%;overflow:hidden;
    border:3px solid var(--gold-100);box-shadow:var(--sh-1);
}
.pv-scope.pv-home .pv-vendor-card__avatar img{width:100%;height:100%;object-fit:cover;}
/* AUDIT-FE-PV-DS-012 FIX (P1-11): wrapper relativo del avatar — el badge
   Star Seller se centra respecto al AVATAR (translateX(-50%)) en vez del
   hack right:50%+translateX(30px) dependiente del ancho del badge. */
.pv-scope.pv-home .pv-vendor-card__avatar-wrap{
    position:relative;display:inline-block;
}
.pv-scope.pv-home .pv-vendor-card__star{
    position:absolute;bottom:-8px;left:50%;transform:translateX(-50%);
    box-shadow:var(--sh-1);white-space:nowrap;z-index:1;
}
.pv-scope.pv-home .pv-vendor-card__body{display:flex;flex-direction:column;gap:6px;align-items:center;width:100%;}
.pv-scope.pv-home .pv-vendor-card__name{
    font-family:var(--display);font-weight:700;font-size:16px;color:var(--text);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;
}
.pv-scope.pv-home .pv-vendor-card__meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center;}
.pv-scope.pv-home .pv-vendor-card__rating{display:inline-flex;align-items:center;gap:4px;font-size:13px;}
.pv-scope.pv-home .pv-vendor-card__rating-num{font-weight:700;color:var(--text);}
.pv-scope.pv-home .pv-vendor-card__sales{font-size:12.5px;color:var(--text-3);}
.pv-scope.pv-home .pv-vendor-card__products{font-size:12.5px;color:var(--text-3);font-weight:600;}

/* ── EMPTY STATES (secciones dinámicas) ───────────────────────────────────
   AUDIT-FE-PV-DS-008 FIX (P1-6): bento cats / trending / star vendors eran
   secciones silenciosas (if !empty sin else). Ahora muestran una nota vacía
   con CTA en vez de desaparecer sin explicación. */
.pv-scope.pv-home .pv-home__empty-note{
    padding:32px 24px;text-align:center;
}
.pv-scope.pv-home .pv-home__empty-note h3{
    font-family:var(--display);font-weight:700;font-size:16px;color:var(--text);
    margin-bottom:6px;
}
.pv-scope.pv-home .pv-home__empty-note p{
    font-size:14px;color:var(--text-2);line-height:1.55;
    margin-bottom:14px;
}

/* ── FOOTER ──────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home-footer{
    margin-top:56px;background:var(--surface);border-top:1px solid var(--border);
}
.pv-scope.pv-home .pv-home-footer__inner{
    display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:40px;
    padding-top:48px;padding-bottom:40px;
}
.pv-scope.pv-home .pv-home-footer__logo{
    display:inline-flex;align-items:center;gap:8px;
    font-family:var(--display);font-weight:800;font-size:20px;color:var(--text);
    text-decoration:none;margin-bottom:12px;
}
.pv-scope.pv-home .pv-home-footer__tagline{font-size:13.5px;color:var(--text-3);line-height:1.6;max-width:340px;}
.pv-scope.pv-home .pv-home-footer__col-title{
    font-size:13px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.05em;
    margin-bottom:14px;
}
.pv-scope.pv-home .pv-home-footer__links{display:flex;flex-direction:column;gap:9px;}
.pv-scope.pv-home .pv-home-footer__links a{font-size:13.5px;color:var(--text-2);text-decoration:none;transition:color var(--t);}
.pv-scope.pv-home .pv-home-footer__links a:hover{color:var(--primary);}
.pv-scope.pv-home .pv-home-footer__payments{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
.pv-scope.pv-home .pv-home-footer__pay-badge{
    padding:5px 10px;border-radius:var(--r-sm);
    background:var(--bg);border:1px solid var(--border);
    font-size:11.5px;font-weight:700;color:var(--text-2);letter-spacing:.02em;
}
.pv-scope.pv-home .pv-home-footer__pay-note{font-size:12px;color:var(--text-3);line-height:1.5;}
.pv-scope.pv-home .pv-home-footer__social{display:flex;gap:8px;}
.pv-scope.pv-home .pv-home-footer__social-link{
    width:40px;height:40px;border-radius:var(--r-md);
    display:flex;align-items:center;justify-content:center;
    background:var(--bg);border:1px solid var(--border);color:var(--text-2);
    transition:background var(--t),color var(--t),border-color var(--t),transform var(--t);
}
.pv-scope.pv-home .pv-home-footer__social-link:hover{
    background:var(--primary);color:#fff;border-color:var(--primary);transform:translateY(-2px);
}
.pv-scope.pv-home .pv-home-footer__bottom{border-top:1px solid var(--border);}
.pv-scope.pv-home .pv-home-footer__bottom-inner{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding-top:18px;padding-bottom:18px;font-size:12.5px;color:var(--text-3);
}
.pv-scope.pv-home .pv-home-footer__built{font-weight:600;}

/* ── RESPONSIVE ──────────────────────────────────────────────────────────── */
@media (max-width:1100px){
    .pv-scope.pv-home .pv-home__product-grid{grid-template-columns:repeat(3,1fr);}
    .pv-scope.pv-home .pv-home__vendor-grid{grid-template-columns:repeat(2,1fr);}
    .pv-scope.pv-home .pv-home-footer__inner{grid-template-columns:1fr 1fr;gap:32px;}
    .pv-scope.pv-home .pv-home-footer__col--brand{grid-column:1 / -1;}
}
@media (max-width:980px){
    .pv-scope.pv-home .pv-home-header__inner{grid-template-columns:auto 1fr;gap:16px;}
    .pv-scope.pv-home .pv-home-header__search{grid-column:1 / -1;order:3;}
    .pv-scope.pv-home .pv-home-header__actions{justify-self:end;}
    .pv-scope.pv-home .pv-home-header__action-label{display:none;}
    .pv-scope.pv-home .pv-home-hero{padding:38px 30px;min-height:280px;}
    .pv-scope.pv-home .pv-bento-grid{
        grid-template-columns:repeat(2,1fr);
        grid-template-areas:"a a" "b b" "c d" "e f";
    }
}
@media (max-width:760px){
    .pv-scope.pv-home .pv-home__product-grid{grid-template-columns:repeat(2,1fr);}
    .pv-scope.pv-home .pv-home__vendor-grid{grid-template-columns:1fr;}
    .pv-scope.pv-home .pv-home-footer__inner{grid-template-columns:1fr;gap:28px;}
}
@media (max-width:560px){
    .pv-scope.pv-home .pv-home-header__logo-tag{display:none;}
    .pv-scope.pv-home .pv-home-hero{padding:30px 20px;border-radius:var(--r-md);}
    .pv-scope.pv-home .pv-bento-grid{
        grid-template-columns:1fr;
        grid-template-areas:"a" "b" "c" "d" "e" "f";
    }
    .pv-scope.pv-home .pv-bento-tile--feature .pv-bento-tile__icon{font-size:40px;}
    .pv-scope.pv-home .pv-bento-tile--feature .pv-bento-tile__name{font-size:18px;}
    .pv-scope.pv-home .pv-home__product-grid{grid-template-columns:1fr;gap:14px;}
    .pv-scope.pv-home .pv-home-footer__bottom-inner{flex-direction:column;align-items:flex-start;}
}
</style>

<?php
/* ============================================================================
 * AUDIT-FE-HOME-001 FIX (Fase 1.10): el bloque <script> inline de chips de
 * búsqueda + header shadow fue ELIMINADO. Su lógica era código muerto:
 *   1. Chips de búsqueda: el handler global del design system 
 *      ltms-plaza-viva.js (líneas 588-614, AUDIT-FE-HOME-003 FIX, commit
 *      9882789b) ya rellena el input + hace form.submit() al click en
 *      [data-pv-search-chip]. Como ese handler global se registra primero
 *      y dispara navegación síncrona (form.submit()), el listener inline
 *      registrado después al cargar el footer NUNCA tenía oportunidad de
 *      correr visible — Bulldozer code duplicado que rompía CSP sin aportar.
 *   2. Header shadow: toggle de clase `.is-scrolled` en `.pv-home-header`
 *      según window.scrollY > 8. PERO la clase `.is-scrolled` NO está 
 *      definida en ningún CSS (verificado: grep en ltms-plaza-viva.css,
 *      ltms-homepage-fixes.css, ltms-frontend.css = 0 matches). Behaviour 
 *      cosmético sin efecto — mismo patrón que LECCIONES #139 y OT-002
 *      (UI que lee/escribe datos que nadie usa). NO se migra: si en el 
 *      futuro se quiere sombra de header on-scroll, se añadirá clase CSS
 *      nueva + este scope, pero sólo cuando exista CSS que la consuma.
 * El scope HOME en ltms-plaza-viva.js (ver bloque `homeScope()` líneas 
 * ~1753+) se mantiene como válvula de extensión IIFE para futuros 
 * behaviours específicos de la home. home.php queda 100% CSP-compliant
 * (cero <script> inline).
 * ========================================================================== */
?>
<?php
get_footer();
