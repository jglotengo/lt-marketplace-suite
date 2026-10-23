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
 *  - Header 3 zonas (logo · buscador · acciones de cuenta). Carrito abre el
 *    mini-cart lateral (HOME-UX2-004). La barra global de categorías
 *    (LTMS_Global_Catbar) se renderiza DENTRO del header sticky como última
 *    fila (server-side, HOME-CATBAR-2026-006/009): chips blancos sobre el
 *    rojo de marca, deslizable con rueda/arrastre.
 *  - Grid de categorías «Explora por categorías» con TODAS las activas
 *    (HOME-UX2-002).
 *  - Hero banner (slide 1 del Home Slider + tarjetas 2-3, texto en HTML).
 *  - Trust bar (4 items: Compra Protegida, Pago Seguro,
 *    Vendedores Verificados, Envío a todo el país). SIN devoluciones.
 *  - Trending productos (WC query best_sellers, 8 productos).
 *  - Vendedores destacados (Star Sellers: KYC approved + star_seller=1).
 *  - Vende con nosotros (CTA registro de vendedor).
 *  - Ayuda y políticas (HOME-UX2-005: Vende · Ayuda · Legal · Contacto —
 *    el texto que antes vivía en las columnas del footer).
 *  - Footer puramente visual (logo · redes reales · métodos de pago · ©);
 *    el footer del tema se oculta en la home.
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
 * REG-CHOICE-001 (auditoría de confusión comprador/vendedor, 2026-10-22):
 * URLs canónicas del funnel de vendedor. Los CTAs apuntaban a slugs fantasmas
 * (/vendedor/registro vía redirect manual ajeno al plugin; /vendedores en 404
 * real — verificado en producción). Se resuelven por ID desde el mapa de páginas
 * del activator (ltms_installed_pages) con fallback al slug canónico, y se
 * conservan los filtros originales para customizaciones.
 */
$pv_installed_pages         = get_option( 'ltms_installed_pages', array() );
$pv_seller_register_page_id = (int) ( $pv_installed_pages['ltms-vendor-register'] ?? 0 );
$pv_sellers_page_id         = (int) ( $pv_installed_pages['ltms-sellers'] ?? 0 );
$pv_seller_register_url = apply_filters(
	'ltms_become_seller_url',
	$pv_seller_register_page_id ? get_permalink( $pv_seller_register_page_id ) : home_url( '/registro-vendedor/' )
);
$pv_sellers_landing_url = apply_filters(
	'ltms_sellers_page_url',
	$pv_sellers_page_id ? get_permalink( $pv_sellers_page_id ) : home_url( '/sellers/' )
);

/**
 * HOME-CATBAR-2026-005 (2026-10-15, operador — "reemplace la barra que
 * existe en las páginas del proyecto por esta"): la fila de chips de
 * categorías del header fue REEMPLAZADA por la barra global
 * LTMS_Global_Catbar — una sola barra de categorías en todo el proyecto
 * (el JS de ltms-catbar.js la adopta dentro de este header sticky como
 * última fila). El filtro ltms_home_popular_chips y su markup murieron
 * con el consumidor.
 */

/* ---------------------------------------------------------------------------
 * 2. Categorías para la barra de accesos (HOME-REDESIGN-003)
 *    Fuente: LTMS_Utils::get_normalized_product_categories() — dedup por
 *    fingerprint (case/acento/singular-plural) + nombre en MAYUSCULAS +
 *    orden por # de productos (proxy de conversión).
 *    HOME-UX2-002 (2026-10-02): TODAS las categorías activas — antes solo el
 *    top 8 y el usuario no percibía que había más (20 reales hoy). El hint
 *    visual de "hay más" lo dan el fade del borde + scroll horizontal en
 *    todos los tamaños (ver CSS). Fallback: get_terms crudo si el helper
 *    no está cargado.
 * ------------------------------------------------------------------------- */
$pv_cat_terms = array();
if ( class_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'get_normalized_product_categories' ) ) {
    // HOME-MATRIX-FIX (2026-10-01): excluir "NO APLICA" — artefacto de los
    // syncs (no es una categoría navegable; el top por count la incluía).
    $pv_all_cats = array_filter( LTMS_Utils::get_normalized_product_categories( true ), static function ( $c ) {
        return isset( $c->slug ) && 'no-aplica' !== $c->slug;
    } );
    $pv_cat_terms = array_values( $pv_all_cats );
} else {
    $pv_cat_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ) );
}

/**
 * Mapa slug → emoji para los iconos de categoría.
 * Fallback genérico 🛍️ si el slug no coincide.
 */
$pv_cat_icons = apply_filters( 'ltms_home_category_icons', array(
    /* HOME-MATRIX-FIX (2026-10-01): los slugs reales llevan sufijos numéricos
     * del dedup WP (belleza-y-salud-342) y el lookup matchea por PREFIJO —
     * entradas añadidas para el catálogo real (belleza/capilar/corporal). */
    'tecnologia'   => '💻',
    'electronica'  => '🔌',
    'hogar'        => '🏠',
    'moda'         => '👕',
    'ropa'         => '👗',
    'belleza'      => '💄',
    'cuidado'      => '🧴',
    'shampoo'      => '🧴',
    'coloracion'   => '🎨',
    'mascarillas'  => '🧴',
    'ceras'        => '🧴',
    'cremas'       => '🧴',
    'hidratantes'  => '🧴',
    'tratamientos' => '🧴',
    'proteccion-solar' => '☀️',
    'exfoliantes'  => '🧼',
    'jabones'      => '🧼',
    'ampolletas'   => '🧴',
    'accesorios'   => '👜',
    'deportes'     => '⚽',
    'juegos'       => '🎮',
    'juegos-de-mesa' => '🎲',
    'juego'        => '🎲',
    /* QA-TAIWAN-SYNC (2026-10-04): DIDACTICO entró al catálogo vía la sync de
     * PosGold del vendor 168 (52+49) — sin entrada en el mapa renderizaba el
     * fallback genérico 🛍️ (verificado en el HTML servido). */
    'didactico'    => '🧩',
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
 * 3. HOME-UX5 (2026-10-04): imagen representativa por categoría + carruseles
 *    por categoría (top 8). Reemplaza el query de "Trending productos"
 *    (decisión del operador: cada categoría exhibe su propio catálogo en
 *    carruseles de 2 filas; ver sección CARRUSELES en el body).
 *
 *    3a. Imagen por categoría (HOME-UX5-002): para cada categoría activa se
 *        toma la imagen del producto más vendido con thumbnail visible
 *        (orderby popularity + _thumbnail_id EXISTS). Fallback al emoji del
 *        mapa de iconos si la categoría no tiene imágenes. Queries livianas
 *        (fields=ids, no_found_rows) amortizadas por la cache dinámica de SG.
 * ------------------------------------------------------------------------- */
$pv_popularity_args = array();
$pv_vis_meta_query  = array();
if ( class_exists( 'WooCommerce' ) && isset( WC()->query ) && method_exists( WC()->query, 'get_catalog_ordering_args' ) ) {
    $pv_popularity_args = WC()->query->get_catalog_ordering_args( 'popularity' );
    if ( method_exists( WC()->query, 'get_meta_query' ) ) {
        // Meta query de visibilidad WC (excluir hidden/exclude-from-search).
        $pv_vis_meta_query = (array) WC()->query->get_meta_query();
    }
}

$pv_cat_images = array();
if ( ! empty( $pv_cat_terms ) && ! is_wp_error( $pv_cat_terms ) ) {
    foreach ( $pv_cat_terms as $pv_ct ) {
        $pv_ct_id = (int) ( $pv_ct->term_id ?? 0 );
        if ( $pv_ct_id < 1 ) {
            continue;
        }
        $pv_img_args = wp_parse_args( $pv_popularity_args, array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'fields'              => 'ids',
            'tax_query'           => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $pv_ct_id,
                ),
            ),
            'meta_query'          => array_merge( $pv_vis_meta_query, array(
                array(
                    'key'     => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ),
            ) ),
        ) );
        $pv_img_ids = get_posts( $pv_img_args );
        if ( empty( $pv_img_ids ) ) {
            continue;
        }
        $pv_img_url = get_the_post_thumbnail_url( (int) $pv_img_ids[0], 'woocommerce_thumbnail' );
        if ( is_string( $pv_img_url ) && '' !== $pv_img_url ) {
            $pv_cat_images[ $pv_ct_id ] = $pv_img_url;
        }
    }
}

/* 3b. Carruseles por categoría (HOME-UX5-001): top 8 categorías activas
 *     (por # de productos), 12 productos visibles c/u — best sellers
 *     primero (popularity), visible-check en el render. tax_query con
 *     include_children por defecto (TRUE) = mismo comportamiento del
 *     archivo de la categoría al que enlaza "Ver más". */
$pv_cat_carousels = array();
if ( ! empty( $pv_cat_terms ) && ! is_wp_error( $pv_cat_terms ) ) {
    foreach ( array_slice( $pv_cat_terms, 0, 8 ) as $pv_cc_term ) {
        $pv_cc_id = (int) ( $pv_cc_term->term_id ?? 0 );
        if ( $pv_cc_id < 1 ) {
            continue;
        }
        $pv_cc_args = wp_parse_args( $pv_popularity_args, array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => 12,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'fields'              => 'ids',
            'tax_query'           => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $pv_cc_id,
                ),
            ),
            'meta_query'          => $pv_vis_meta_query,
        ) );
        $pv_cc_ids = get_posts( $pv_cc_args );
        if ( empty( $pv_cc_ids ) ) {
            continue;
        }
        $pv_cc_url = get_term_link( $pv_cc_id );
        if ( is_wp_error( $pv_cc_url ) ) {
            $pv_cc_url = $pv_shop_url;
        }
        $pv_cat_carousels[] = array(
            'term_id' => $pv_cc_id,
            'name'    => (string) $pv_cc_term->name,
            'count'   => (int) ( $pv_cc_term->count ?? 0 ),
            'url'     => $pv_cc_url,
            'ids'     => $pv_cc_ids,
        );
    }
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
 * 5. Hero — banners del Home Slider (HOME-REDESIGN-004)
 *    Banner principal = slide 1; tarjetas secundarias = slides 2 y 3 (título
 *    corto + un único botón cada uno). Sin autoplay y sin carrusel en el hero
 *    (brief). El contenido lo gestiona el admin (LT Marketplace → Home
 *    Slider). Si no hay banners activos, fallback: hero gradiente con CTA.
 * ------------------------------------------------------------------------- */
$pv_hero_slides = array();
if ( class_exists( 'LTMS_Frontend_Home_Slider' ) && method_exists( 'LTMS_Frontend_Home_Slider', 'get_slides' ) ) {
    $pv_hero_slides = ( new LTMS_Frontend_Home_Slider() )->get_slides( true );
}
$pv_hero_banner = array_shift( $pv_hero_slides ); // slide 1 → banner principal
$pv_hero_cards  = array_slice( $pv_hero_slides, 0, 2 ); // slides 2-3 → tarjetas

/* ---------------------------------------------------------------------------
 * 6. Helpers de render
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
     * SALTAR AL CONTENIDO — accesibilidad: primer elemento enfocable,
     * visible al recibir foco (HOME-REDESIGN-002).
     * =====================================================================
     */
    ?>
    <a class="pv-home-skip" href="#pv-main"><?php esc_html_e( 'Saltar al contenido', 'ltms' ); ?></a>

    <?php
    /* =====================================================================
     * HEADER — REG-CHOICE-008 (2026-10-23, operador: "el header del home
     * aplícalo para todas las demás páginas públicas del proyecto y oculta
     * el viejo"): el header Plaza Viva (logo · buscador con sugerencias
     * live + filtro categoría · Vender GRATIS + Cuenta/Favoritos/Carrito ·
     * catbar global) ya NO se imprime aquí — vive en el componente
     * compartido LTMS_PV_Header (class-ltms-pv-header.php + CSS
     * assets/css/ltms-pv-header.css) que el hook wp_body_open inyecta en
     * TODAS las páginas públicas, esta incluida. Una sola fuente de verdad.
     * =====================================================================
     */
    ?>

    <main id="pv-main">

    <?php
    /* =====================================================================
     * CATEGORÍAS — grid multi-fila con imágenes (HOME-UX5-002, 2026-10-04)
     * Un único elemento de navegación de categorías, justo debajo del header
     * (antes del hero — orden de compra: 1 buscar, 2 elegir categoría,
     * 3 oferta principal, 4 productos).
     * Historia: barra deslizable con emojis (HOME-REDESIGN-003 → UX2-002 →
     * UX4-001). Decisión del operador (HOME-UX5): TODAS las categorías
     * activas organizadas en un grid ENVOLVENTE de varias filas (móvil 4
     * col / tablet 6 / escritorio 7 — 21 activas ≈ 3 filas), y cada card
     * exhibe la IMAGEN del producto más vendido de la categoría en vez del
     * emoji (el emoji queda SOLO como fallback para categorías sin
     * thumbnails — $pv_cat_images del bloque 3a). El head con título +
     * "Ver todas" y el contenedor --pv-maxw se conservan (UX4-001).
     * =====================================================================
     */
    if ( ! empty( $pv_cat_terms ) && ! is_wp_error( $pv_cat_terms ) ) :
    ?>
        <nav class="pv-cat-bar" aria-labelledby="pv-home-cats-title">
            <div class="pv-cat-bar__head">
                <h2 class="pv-cat-bar__title" id="pv-home-cats-title"><?php esc_html_e( 'Explora por categorías', 'ltms' ); ?></h2>
                <a class="pv-cat-bar__more" href="<?php echo esc_url( $pv_shop_url ); ?>">
                    <?php esc_html_e( 'Ver todas', 'ltms' ); ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
            <ul class="pv-cat-bar__grid" role="list">
                <?php foreach ( $pv_cat_terms as $pv_term ) :
                    /* HOME-MATRIX-FIX (2026-10-01): los slugs reales llevan
                     * sufijos numéricos del dedup de WP (belleza-y-salud-342)
                     * — matchear el mapa de íconos por PREFIJO; el fallback
                     * 🛍️ solo si ningún prefijo coincide. Con imágenes reales
                     * (HOME-UX5-002) el emoji es el FALLBACK cuando la
                     * categoría no tiene thumbnail. */
                    $pv_icon = '🛍️';
                    if ( isset( $pv_cat_icons[ $pv_term->slug ] ) ) {
                        $pv_icon = $pv_cat_icons[ $pv_term->slug ];
                    } else {
                        foreach ( $pv_cat_icons as $pv_icon_slug => $pv_icon_emoji ) {
                            if ( '' !== $pv_icon_slug && strpos( (string) $pv_term->slug, (string) $pv_icon_slug ) === 0 ) {
                                $pv_icon = $pv_icon_emoji;
                                break;
                            }
                        }
                    }
                    $pv_term_id = (int) ( $pv_term->term_id ?? 0 );
                    $pv_cat_url = $pv_term_id ? get_term_link( $pv_term_id ) : get_term_link( $pv_term );
                    if ( is_wp_error( $pv_cat_url ) ) {
                        $pv_cat_url = $pv_shop_url;
                    }
                    $pv_count = (int) $pv_term->count;
                    $pv_img   = (string) ( $pv_cat_images[ $pv_term_id ] ?? '' );
                ?>
                    <li role="listitem">
                        <a class="pv-cat-bar__item"
                           href="<?php echo esc_url( $pv_cat_url ); ?>"
                           aria-label="<?php echo esc_attr( sprintf( __( '%1$s — %2$s', 'ltms' ), $pv_term->name, sprintf( _n( '%d producto', '%d productos', $pv_count, 'ltms' ), $pv_count ) ) ); ?>">
                            <?php if ( '' !== $pv_img ) : ?>
                                <?php /* HOME-UX5-002: imagen del best-seller de la categoría;
                                     * alt vacío (decorativa — el nombre visible + aria-label
                                     * portan el significado), lazy (hay ~21 en el viewport). */ ?>
                                <span class="pv-cat-bar__img-wrap" aria-hidden="true">
                                    <img class="pv-cat-bar__img" src="<?php echo esc_url( $pv_img ); ?>" alt="" width="96" height="96" loading="lazy" decoding="async" />
                                </span>
                            <?php else : ?>
                                <span class="pv-cat-bar__icon" aria-hidden="true"><?php echo esc_html( $pv_icon ); ?></span>
                            <?php endif; ?>
                            <span class="pv-cat-bar__name"><?php echo esc_html( $pv_term->name ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
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
     * HERO (HOME-REDESIGN-004, patrón Amazon): 1 banner principal (slide 1
     * del Home Slider del admin) + 2 tarjetas secundarias (slides 2-3), cada
     * una con título corto y un único botón. Sin autoplay, sin carrusel.
     * Desktop: banner a la izquierda (≈66%) + tarjetas apiladas a la derecha
     * (≈33%), una sola altura. Móvil/tablet: banner a ancho completo + las
     * tarjetas en una fila de 2 columnas debajo. El texto va en HTML (nunca
     * dentro de la imagen). La imagen del banner con prioridad de carga.
     * =====================================================================
     */
    if ( ! empty( $pv_hero_banner ) && ! empty( $pv_hero_banner['image_desktop'] ) ) :
    ?>
    <section class="pv-section pv-home__hero-wrap" aria-labelledby="pv-home-hero-title">
        <div class="pv-home-hero__head">
            <span class="pv-hero__eyebrow">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                <?php esc_html_e( 'Marketplace protegido con Escrow', 'ltms' ); ?>
            </span>
            <h1 id="pv-home-hero-title" class="pv-hero__title">
                <?php esc_html_e( 'Compra con confianza, vende con libertad', 'ltms' ); ?>
            </h1>
            <p class="pv-hero__sub">
                <?php esc_html_e( 'Miles de productos de vendedores verificados. Pago seguro y envío a todo el país.', 'ltms' ); ?>
            </p>
        </div>
        <div class="pv-home-hero__grid">
            <a class="pv-home-hero__banner"
               href="<?php echo esc_url( (string) ( $pv_hero_banner['cta_url'] ?? '' ) !== '' ? (string) $pv_hero_banner['cta_url'] : $pv_shop_url ); ?>">
                <?php /* HOME-REDESIGN-004: <picture> con recorte distinto para
                     móvil (imagen 1:1 del admin) y escritorio (panorámica). */ ?>
                <picture>
                    <?php if ( ! empty( $pv_hero_banner['image_mobile'] ) ) : ?>
                        <source media="(max-width: 767px)" srcset="<?php echo esc_url( (string) $pv_hero_banner['image_mobile'] ); ?>" />
                    <?php endif; ?>
                    <img src="<?php echo esc_url( (string) $pv_hero_banner['image_desktop'] ); ?>"
                         alt="<?php echo esc_attr( (string) ( $pv_hero_banner['title'] ?? '' ) ); ?>"
                         fetchpriority="high" loading="eager" decoding="async" />
                </picture>
                <span class="pv-home-hero__overlay">
                    <?php if ( ! empty( $pv_hero_banner['title'] ) ) : ?>
                        <span class="pv-home-hero__banner-title"><?php echo esc_html( (string) $pv_hero_banner['title'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $pv_hero_banner['cta_text'] ) ) : ?>
                        <span class="pv-home-hero__banner-cta"><?php echo esc_html( (string) $pv_hero_banner['cta_text'] ); ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php if ( ! empty( $pv_hero_cards ) ) : ?>
                <div class="pv-home-hero__side">
                    <?php foreach ( $pv_hero_cards as $pv_hero_card ) :
                        $pv_card_img = (string) ( $pv_hero_card['image_mobile'] ?? '' ) !== '' ? (string) $pv_hero_card['image_mobile'] : (string) ( $pv_hero_card['image_desktop'] ?? '' );
                        if ( '' === $pv_card_img ) {
                            continue;
                        }
                        $pv_card_url = (string) ( $pv_hero_card['cta_url'] ?? '' ) !== '' ? (string) $pv_hero_card['cta_url'] : $pv_shop_url;
                    ?>
                        <a class="pv-home-hero__card" href="<?php echo esc_url( $pv_card_url ); ?>">
                            <img src="<?php echo esc_url( $pv_card_img ); ?>"
                                 alt="<?php echo esc_attr( (string) ( $pv_hero_card['title'] ?? '' ) ); ?>"
                                 loading="lazy" decoding="async" />
                            <span class="pv-home-hero__overlay pv-home-hero__overlay--card">
                                <?php if ( ! empty( $pv_hero_card['title'] ) ) : ?>
                                    <span class="pv-home-hero__card-title"><?php echo esc_html( (string) $pv_hero_card['title'] ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $pv_hero_card['cta_text'] ) ) : ?>
                                    <span class="pv-home-hero__card-cta"><?php echo esc_html( (string) $pv_hero_card['cta_text'] ); ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php else : ?>
        <!-- Fallback: sin banners en el Home Slider — hero gradiente con CTA
             (HOME-REDESIGN-004: una sola acción principal). -->
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
            </div>
        </div>
    </section>
    <?php endif; ?>

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
     * CARRUSELES POR CATEGORÍA (HOME-UX5-001, 2026-10-04)
     * Reemplaza a "Productos en tendencia" (decisión del operador): las
     * top 8 categorías activas exhiben su propio catálogo en un carrusel
     * de 2 filas por categoría — track grid-auto-flow:column + 2 rows +
     * scroll-snap (desliza con el dedo en móvil; en escritorio además
     * flechas prev/next, JS del scope HOME). "Ver más" enlaza al archivo
     * de la categoría. Cards delegadas al template part canónico
     * content-product.php (AUDIT-FE-PV-DS-003: una sola fuente de verdad
     * del UI de card — quick-view, wishlist, badges, ATC).
     * =====================================================================
     */
    if ( ! empty( $pv_cat_carousels ) ) :
    ?>
        <section class="pv-section pv-home__cat-carousels" aria-label="<?php esc_attr_e( 'Productos por categoría', 'ltms' ); ?>">
            <?php foreach ( $pv_cat_carousels as $pv_cc ) : ?>
                <article class="pv-cat-carousel" aria-labelledby="pv-cat-carousel-<?php echo esc_attr( (string) $pv_cc['term_id'] ); ?>">
                    <header class="pv-section__head">
                        <div>
                            <h2 id="pv-cat-carousel-<?php echo esc_attr( (string) $pv_cc['term_id'] ); ?>" class="pv-section__title"><?php echo esc_html( $pv_cc['name'] ); ?></h2>
                            <p class="pv-section__sub">
                                <?php
                                /* translators: %d: número de productos de la categoría. */
                                echo esc_html( sprintf(
                                    _n( 'Los más vendidos — %d producto en la categoría', 'Los más vendidos — %d productos en la categoría', $pv_cc['count'], 'ltms' ),
                                    $pv_cc['count']
                                ) );
                                ?>
                            </p>
                        </div>
                        <a class="pv-section__more" href="<?php echo esc_url( $pv_cc['url'] ); ?>">
                            <?php esc_html_e( 'Ver más', 'ltms' ); ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </a>
                    </header>
                    <div class="pv-cat-carousel__wrap">
                        <div class="pv-cat-carousel__track" role="list">
                            <?php
                            foreach ( $pv_cc['ids'] as $pv_cc_pid ) :
                                $pv_cc_product = wc_get_product( $pv_cc_pid );
                                if ( ! $pv_cc_product instanceof WC_Product || ! $pv_cc_product->is_visible() ) {
                                    continue;
                                }
                                // content-product.php consume los globals $product/$post.
                                global $product, $post;
                                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup intencional para el template part
                                $product = $pv_cc_product;
                                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup intencional para el template part
                                $post    = get_post( $pv_cc_pid );
                                wc_get_template_part( 'content', 'product' );
                            endforeach;
                            wp_reset_postdata();
                            ?>
                        </div>
                        <button type="button" class="pv-cat-carousel__nav pv-cat-carousel__nav--prev" data-pv-carousel-prev aria-label="<?php esc_attr_e( 'Anterior', 'ltms' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button type="button" class="pv-cat-carousel__nav pv-cat-carousel__nav--next" data-pv-carousel-next aria-label="<?php esc_attr_e( 'Siguiente', 'ltms' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php else : ?>
        <!-- AUDIT-FE-PV-DS-008 FIX (P1-6): empty state visible en vez de sección silenciosa -->
        <section class="pv-section pv-home__cat-carousels">
            <div class="pv-card pv-card--flat pv-home__empty-note">
                <h3><?php esc_html_e( 'Aún no hay productos por categoría', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Cuando los vendedores publiquen sus productos, cada categoría tendrá su propia vitrina.', 'ltms' ); ?></p>
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
                    <h2 id="pv-home-vendors-title" class="pv-section__title"><?php esc_html_e( 'Vendedores destacados', 'ltms' ); ?></h2>
                    <p class="pv-section__sub"><?php esc_html_e( 'Star Sellers verificados con KYC y excelente reputación', 'ltms' ); ?></p>
                </div>
                <a class="pv-section__more" href="<?php echo esc_url( $pv_sellers_landing_url ); ?>">
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
     * VENDE CON NOSOTROS (HOME-REDESIGN-005, patrón AliExpress → BRAND-2026-001):
     * franja con un único botón de registro de vendedor. BRAND-2026-001 (brief):
     * gradiente ROJO de marca (--brand → --brand-600) con detalle dorado
     * primario --ltms-gold #F7B731. Sin cifras inventadas (brief). Desktop:
     * texto a la izquierda + botón a la derecha en una sola fila; móvil:
     * apilado, botón a todo el ancho.
     *
     * REG-CHOICE-003 (2026-10-22, auditoría de confusión comprador/vendedor):
     * la franja pasa a ser DUAL — "Quiero vender" (registro de vendedor) y
     * "Quiero comprar" (ingreso/registro de comprador en /mi-cuenta/). Antes
     * el único botón de registro rotulado de la home era para vendedores y
     * los compradores no encontraban dónde crear su cuenta (reporte del
     * operador: confusión en el home). El botón comprador es secundario
     * (ghost) para no diluir la vocación de captación de la franja.
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-home__sell" aria-labelledby="pv-home-sell-title">
        <div class="pv-home-sell">
            <div class="pv-home-sell__body">
                <h2 id="pv-home-sell-title" class="pv-home-sell__title"><?php esc_html_e( 'Vende con nosotros', 'ltms' ); ?></h2>
                <p class="pv-home-sell__sub"><?php esc_html_e( 'Publica tus productos en el marketplace y llega a compradores de toda Colombia y México.', 'ltms' ); ?></p>
            </div>
            <div class="pv-home-sell__ctas">
                <a class="pv-home-sell__cta" href="<?php echo esc_url( $pv_seller_register_url ); ?>">
                    <?php esc_html_e( 'Quiero vender', 'ltms' ); ?>
                </a>
                <a class="pv-home-sell__cta pv-home-sell__cta--ghost" href="<?php echo esc_url( $pv_account_url ); ?>">
                    <?php esc_html_e( 'Quiero comprar', 'ltms' ); ?>
                </a>
            </div>
        </div>
    </section>

    <?php
    /* =====================================================================
     * AYUDA Y POLÍTICAS (HOME-UX2-005, 2026-10-02): el footer pasa a ser
     * puramente visual (logo, redes, pagos, ©) y TODO el texto de enlaces
     * que vivía en las 4 columnas del footer + el contacto del footer del
     * tema se organiza aquí como sección propia, compacta y navegable.
     * =====================================================================
     */
    ?>
    <section class="pv-section pv-home__policies" aria-labelledby="pv-home-policies-title">
        <header class="pv-section__head">
            <h2 id="pv-home-policies-title" class="pv-section__title"><?php esc_html_e( 'Ayuda y políticas', 'ltms' ); ?></h2>
        </header>
        <div class="pv-home-policies">
            <div class="pv-home-policies__group">
                <h3 class="pv-home-policies__title"><?php esc_html_e( 'Vende con nosotros', 'ltms' ); ?></h3>
                <ul class="pv-home-policies__links">
                    <li><a href="<?php echo esc_url( $pv_seller_register_url ); ?>"><?php esc_html_e( 'Regístrate como vendedor', 'ltms' ); ?></a></li>
                    <li><a href="<?php echo esc_url( $pv_sellers_landing_url ); ?>"><?php esc_html_e( 'Ver vendedores', 'ltms' ); ?></a></li>
                </ul>
            </div>
            <div class="pv-home-policies__group">
                <h3 class="pv-home-policies__title"><?php esc_html_e( 'Ayuda', 'ltms' ); ?></h3>
                <ul class="pv-home-policies__links">
                    <li><a href="<?php echo esc_url( home_url( '/ayuda' ) ); ?>"><?php esc_html_e( 'Centro de ayuda', 'ltms' ); ?></a></li>
                    <li><a href="<?php echo esc_url( home_url( '/seguimiento' ) ); ?>"><?php esc_html_e( 'Rastrear pedido', 'ltms' ); ?></a></li>
                    <li><a href="<?php echo esc_url( $pv_account_url ); ?>"><?php esc_html_e( 'Mi cuenta', 'ltms' ); ?></a></li>
                </ul>
            </div>
            <div class="pv-home-policies__group">
                <h3 class="pv-home-policies__title"><?php esc_html_e( 'Legal', 'ltms' ); ?></h3>
                <ul class="pv-home-policies__links">
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
            </div>
            <div class="pv-home-policies__group">
                <h3 class="pv-home-policies__title"><?php esc_html_e( 'Contacto', 'ltms' ); ?></h3>
                <ul class="pv-home-policies__links">
                    <li><a href="mailto:dircomercialcol@lo-tengo.com.co">dircomercialcol@lo-tengo.com.co</a></li>
                    <li><a href="mailto:sellerscolombia@lo-tengo.com.co">sellerscolombia@lo-tengo.com.co</a></li>
                    <li><a href="tel:+5753014106251"><?php esc_html_e( '057 301 410 6251', 'ltms' ); ?></a></li>
                    <li><span><?php esc_html_e( 'Cra 48 # 12B - 55 - Of 102, Cali - Colombia', 'ltms' ); ?></span></li>
                </ul>
            </div>
        </div>
    </section>

    </main><!-- /#pv-main -->

    <?php
    /* =====================================================================
     * FOOTER (HOME-UX2-005, 2026-10-02): puramente VISUAL — logo, redes
     * reales, badges de pago y ©. TODO el texto de enlaces (Vende, Ayuda,
     * Legal, Contacto) se organizó en la sección "Ayuda y políticas" del
     * body. El footer del tema (Elementor) se oculta en la home vía CSS
     * (bloque defensivo de abajo) para eliminar la redundancia de dos
     * footers apilados con las mismas políticas/redes/pagos.
     * =====================================================================
     */
    ?>
    <footer class="pv-home-footer" role="contentinfo">
        <div class="pv-section pv-home-footer__inner">

            <?php /* --- Marca --- */
            /* BRAND-2026-001 (brief): logo oficial en variante BLANCA — el footer
             * es NEGRO (#000000, color de marca del brief) y el logo a color
             * llevaría "TENGO" en rojo (invisible sobre negro). PNG 769x324 a
             * 36px de altura = densidad 9x. */ ?>
            <a class="pv-home-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
                <img class="pv-home-footer__logo-img"
                     src="<?php echo esc_url( ( defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '' ) . 'img/logo-lo-tengo-white.png' ); ?>"
                     alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                     width="85" height="36" />
            </a>

            <?php /* --- Redes reales del operador (HOME-UX2-005: antes eran
                 * placeholders genéricos a instagram.com/facebook.com etc.) --- */ ?>
            <ul class="pv-home-footer__social" aria-label="<?php esc_attr_e( 'Redes sociales', 'ltms' ); ?>">
                <?php
                $pv_socials = apply_filters( 'ltms_home_footer_socials', array(
                    array( 'label' => 'Instagram', 'url' => 'https://www.instagram.com/lotengooficial/', 'icon' => 'instagram' ),
                    array( 'label' => 'TikTok',    'url' => 'https://www.tiktok.com/@lotengocolombia', 'icon' => 'tiktok' ),
                    array( 'label' => 'YouTube',   'url' => 'https://www.youtube.com/@lotengocolombia', 'icon' => 'youtube' ),
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
        </div>

        <?php /* --- Pagos, donaciones y afiliaciones (HOME-UX3-002: las IMÁGENES
             * reales que el footer del tema mostraba y se perdieron al ocultarlo
             * — logos de métodos de pago incl. OpenPay, Fundación Cardioinfantil
             * LaCardio, Cámara de Comercio de Cali y Cámara Colombiana de
             * Comercio Electrónico. Mismos assets del footer Elementor) +
             * selector de moneda (solo si multi-moneda activa). --- */ ?>
        <div class="pv-section pv-home-footer__payrow">
            <ul class="pv-home-footer__brands" aria-label="<?php esc_attr_e( 'Pagos seguros, donaciones y afiliaciones', 'ltms' ); ?>">
                <?php
                $pv_footer_brands = apply_filters( 'ltms_home_footer_brands', array(
                    array( 'src' => '/wp-content/uploads/2025/10/logos-footer-4-300x169.png', 'alt' => __( 'Pagos seguros: PSE, Nequi, Daviplata, Visa, Mastercard, Amex, OpenPay', 'ltms' ) ),
                    array( 'src' => '/wp-content/uploads/2026/09/logo-lacardio-300x111.png',  'alt' => __( 'Fundación Cardioinfantil LaCardio', 'ltms' ) ),
                    array( 'src' => '/wp-content/uploads/2025/10/logo-foot-1-300x169.png',    'alt' => __( 'Cámara de Comercio de Cali — La CCC', 'ltms' ) ),
                    array( 'src' => '/wp-content/uploads/2025/10/logo-foot-2-300x169.png',    'alt' => __( 'Cámara Colombiana de Comercio Electrónico', 'ltms' ) ),
                ) );
                foreach ( $pv_footer_brands as $pv_brand ) :
                ?>
                    <li class="pv-home-footer__brand-item">
                        <img src="<?php echo esc_url( home_url( $pv_brand['src'] ) ); ?>" alt="<?php echo esc_attr( $pv_brand['alt'] ); ?>" loading="lazy" decoding="async" />
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php
            /* HOME-REDESIGN-006: selector de moneda solo si ya existe soporte
               multi-moneda (brief). El widget canónico del checkout reutilizado
               — hace bail defensivo si el motor cross-border no está cargado o
               no hay monedas habilitadas (renderiza nada). */
            if ( class_exists( 'LTMS_Currency_Manager' ) && method_exists( 'LTMS_Currency_Manager', 'render_currency_selector' ) ) {
                LTMS_Currency_Manager::render_currency_selector();
            }
            ?>
        </div>

        <div class="pv-home-footer__bottom">
            <div class="pv-section pv-home-footer__bottom-inner">
                <span>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. <?php esc_html_e( 'Todos los derechos reservados.', 'ltms' ); ?></span>
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

/* HOME-MATRIX-FIX: cuerpo 16px mínimo en móvil (brief; la base 15px de
   plaza-viva se mantiene en las demás páginas — este override es solo la
   home nativa). body.pv-home-native gana por orden de carga (inline <style>
   en el body, después del CSS externo de wp_head). */
body.pv-home-native{font-size:16px;}

/* ── RADIOS UNIFORMES (HOME-REDESIGN-005) ──────────────────────────────────
   Brief: radio uniforme (8px tarjetas, 8-12px botones). Scoped a la home —
   NO toca los tokens globales compartidos con shop/cart/checkout. Los radios
   de las TARJETAS van al final del <style> (deben ganar sobre las reglas
   específicas de cada sección que usan --r-md). El botón del buscador
   conserva el pill (matchea la forma del campo, patrón Amazon; su regla
   específica viene después en la cascada y gana por orden). */
.pv-scope.pv-home .pv-btn{border-radius:12px;}

/* ── HEADER (REG-CHOICE-008, 2026-10-23): el markup y el CSS del header
   Plaza Viva viven en el componente compartido LTMS_PV_Header
   (class-ltms-pv-header.php + assets/css/ltms-pv-header.css) — se inyecta
   vía wp_body_open en TODAS las páginas públicas, esta incluida. Aquí solo
   quedan las reglas exclusivas de la home: skip link, defensiva del tema,
   y el resto del design system de la página. */

/* Enlace "Saltar al contenido" — oculto hasta recibir foco. */
.pv-scope.pv-home .pv-home-skip{
    position:absolute;left:16px;top:-60px;z-index:100;
    background:var(--surface);color:var(--primary);font-weight:700;font-size:14px;
    padding:12px 20px;border-radius:0 0 var(--r-sm) var(--r-sm);
    text-decoration:none;box-shadow:var(--sh-2);
    transition:top var(--t);
}
.pv-scope.pv-home .pv-home-skip:focus{top:0;color:var(--primary);outline:3px solid var(--primary);}

/* ── DEFENSIVA (HOME-REDESIGN-002): home nativa — ocultar el header del tema
   (Hello Elementor / WoodMart / Theme Builder) y el floating access que
   ltms-header-nav.js appenda cuando no hay .site-header. La home nativa
   tiene su propio header con acciones de cuenta. Selectores precisos: NUNCA
   un bare `header` (matchearía .pv-home-header). Mismo patrón probado de la
   vitrina (class-ltms-vendor-storefront.php HEADER OVERLAP FIX).
   HOME-UX2-005 (2026-10-02): también el FOOTER del tema (Elementor
   location-footer) — la home ya tiene su propio footer visual; el del tema
   apilaba políticas/redes/pagos duplicados. NUNCA un bare `footer`
   (matchearía .pv-home-footer). */
body.pv-home-native #site-header,
body.pv-home-native .site-header,
body.pv-home-native #masthead,
body.pv-home-native .elementor-location-header,
body.pv-home-native .elementor-location-footer,
body.pv-home-native footer[data-elementor-type="footer"],
body.pv-home-native #colophon,
body.pv-home-native .site-footer,
body.pv-home-native .whb-header,
body.pv-home-native .whb-sticky-header,
body.pv-home-native .woodmart-header,
body.pv-home-native #ltms-floating-access,
body.pv-home-native #ltms-hello-access,
body.pv-home-native #ltms-header-access,
body.pv-home-native .ltms-header-access{display:none!important}

/* ── HERO (HOME-REDESIGN-004, patrón Amazon) ──────────────────────────────
   Mobile-first: banner a ancho completo + 2 tarjetas en fila 2 columnas
   debajo. ≥768 (tablet): banner con aspect-ratio panorámico. ≥1024
   (escritorio): banner a la izquierda (≈66%) + tarjetas apiladas a la
   derecha (≈33%), una sola altura (grid stretch). El texto del banner va
   en HTML (overlay con scrim — nunca dentro de la imagen). La imagen del
   banner con fetchpriority=high (sin carga diferida). */
.pv-scope.pv-home .pv-home__hero-wrap{padding-top:28px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home-hero__head{
    display:flex;flex-direction:column;gap:6px;
    padding-bottom:16px;
}
/* HOME-MATRIX-FIX (2026-10-01): el sub y el eyebrow del design system
   estaban diseñados para el fondo AZUL del hero gradiente (texto blanco
   rgba(255,255,255,.86) — invisible sobre el fondo claro de la home: P0 de
   contraste). Sobre fondo claro: sub gris oscuro (--text-2, ~6.9:1) y
   eyebrow con pill azul visible (~8.6:1). El FALLBACK (hero gradiente)
   conserva el texto blanco — solo la rama con banners usa este override. */
.pv-scope.pv-home .pv-home-hero__head .pv-hero__sub{color:var(--text-2);}
.pv-scope.pv-home .pv-home-hero__head .pv-hero__eyebrow{
    background:var(--primary-50);color:var(--primary-700);
}
.pv-scope.pv-home .pv-home-hero__grid{
    display:grid;grid-template-columns:1fr;gap:12px;
}
.pv-scope.pv-home .pv-home-hero__side{
    display:grid;grid-template-columns:repeat(2,1fr);gap:12px;
}
.pv-scope.pv-home .pv-home-hero__banner{
    position:relative;display:block;overflow:hidden;
    border-radius:var(--r-md);text-decoration:none;
    background:var(--primary-700);
    min-height:120px;
}
.pv-scope.pv-home .pv-home-hero__banner img{display:block;width:100%;height:auto;}
.pv-scope.pv-home .pv-home-hero__card{
    position:relative;display:block;overflow:hidden;
    border-radius:var(--r-md);text-decoration:none;
    background:var(--primary-700);
    min-height:120px;
}
.pv-scope.pv-home .pv-home-hero__card img{
    position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;
}
.pv-scope.pv-home .pv-home-hero__overlay{
    position:absolute;inset:0;
    display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-end;
    gap:6px;padding:16px 18px;
    background:linear-gradient(to top, rgba(13,13,31,.72) 0%, rgba(13,13,31,.14) 55%, rgba(13,13,31,0) 100%);
}
.pv-scope.pv-home .pv-home-hero__banner-title{
    font-family:var(--display);font-weight:800;
    font-size:clamp(18px,2.4vw,28px);
    color:#fff;line-height:1.15;
    text-shadow:0 1px 8px rgba(0,0,0,.35);
}
.pv-scope.pv-home .pv-home-hero__banner-cta,
.pv-scope.pv-home .pv-home-hero__card-cta{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:40px;padding:0 16px;
    background:var(--surface);color:var(--primary-700);
    font-size:13px;font-weight:700;
    border-radius:var(--r-pill);text-decoration:none;
    white-space:nowrap;
}
.pv-scope.pv-home .pv-home-hero__card-title{
    font-family:var(--display);font-weight:700;
    font-size:clamp(14px,1.6vw,18px);
    color:#fff;line-height:1.2;
    text-shadow:0 1px 8px rgba(0,0,0,.35);
}
/* Fallback sin banners: el hero gradiente del design system. */
.pv-scope.pv-home .pv-home-hero{padding:52px 48px;min-height:360px;}
/* HOME-CATBAR-2026-002 (2026-10-15, operador: "las imágenes de la sección
   de banner no se ven completas en vista escritorio"): el bloque ≥768 que
   forzaba un recorte panorámico fijo (aspect-ratio 16:6) + img absolute +
   object-fit:cover fue ELIMINADO — recortaba la imagen del admin
   verticalmente. El banner fluye con su ALTURA NATURAL en todos los
   breakpoints (img width:100%;height:auto, igual que en móvil): la imagen
   completa siempre visible. Las tarjetas secundarias conservan el cover
   (son cards pequeñas, el recorte es su patrón); el grid ≥1024 sigue
   estirándolas a la altura del banner. */
@media (min-width:768px){
    .pv-scope.pv-home .pv-home-hero__banner{min-height:0;}
}
@media (min-width:1024px){
    .pv-scope.pv-home .pv-home-hero__grid{grid-template-columns:2fr 1fr;gap:16px;}
    .pv-scope.pv-home .pv-home-hero__side{
        grid-template-columns:1fr;
        grid-template-rows:repeat(2,1fr);
    }
    .pv-scope.pv-home .pv-home-hero__card{min-height:0;}
}

/* ── TRUST ───────────────────────────────────────────────────────────────── */
.pv-scope.pv-home .pv-home__trust{padding-top:24px;padding-bottom:8px;}
/* HOME-REDESIGN-005 (Temu/AliExpress): franja compacta, sin animación.
   Móvil 2×2; tablet/escritorio una sola línea con los 4 elementos. Íconos
   unificados a azul (la variante danger del 4º item leía como error — el
   brief reserva rojo para estados de error/éxito; el dorado queda escaso
   para el badge del header). */
.pv-scope.pv-home .pv-trust-bar{
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    padding:16px 20px;
    box-shadow:none;
}
.pv-scope.pv-home .pv-trust-item:nth-child(n) .pv-trust-item__icon{
    background:var(--primary-50);color:var(--primary);
}
@media (max-width:767px){
    .pv-scope.pv-home .pv-trust-bar{
        grid-template-columns:repeat(2,1fr);
        gap:10px;
        padding:14px;
    }
}

/* ── CATEGORÍAS — grid multi-fila con imágenes (HOME-UX5-002, 2026-10-04) ───
    Antes: fila deslizable con emojis (HOME-REDESIGN-003 → UX2-002 → UX4-001).
    Ahora: TODAS las activas (21) en un grid ENVOLVENTE de varias filas —
    móvil 3 col / ≥480 5 / ≥768 6 / ≥1024 7 (≈3 filas). Cada card exhibe la
    IMAGEN del best-seller de la categoría (48px, object-fit cover) con el
    emoji SOLO como fallback (categorías sin thumbnails). El contenedor
    conserva --pv-maxw + head con título y "Ver todas" (UX4-001). Cards
    blancas + borde --border + hover azul (patrón UX4-001 de presencia).
    Nombres 2 líneas con clamp (los reales son largos:
    "MASCARILLAS CAPILARES Y TRATAMIENTOS"). Touch targets ≥86px.
    HOME-CATBAR-2026-001 (2026-10-15, operador: "en móvil se desborda una
    tarjeta de las categorías — sobresale por el lateral derecho, resta una
    tarjeta"): causa raíz = 1fr equivale a minmax(auto,1fr) — una palabra
    larga ("TRATAMIENTOS" a 11.5px/700 ≈ 90px) supera la celda de ~78px a
    360px y el mínimo auto EXPANDE la pista → el grid total excede el
    contenedor y la última columna sobresale por la derecha. Fix de raíz:
    minmax(0,1fr) en TODOS los breakpoints (mínimo 0 = las pistas pueden
    encoger bajo el min-content; el nombre ya está contenido por su clamp +
    overflow hidden) + una columna MENOS en móvil (petición explícita del
    operador: 3 columnas ≈ 106px/celda a 360px — nombres legibles). */
.pv-scope.pv-home .pv-cat-bar{
    display:flex;flex-direction:column;gap:12px;
    width:100%;max-width:var(--pv-maxw);margin:0 auto;
    padding:14px 22px 6px;
}
.pv-scope.pv-home .pv-cat-bar__head{
    display:flex;align-items:baseline;justify-content:space-between;gap:12px;
}
.pv-scope.pv-home .pv-cat-bar__title{
    margin:0;
    font-family:var(--display);font-weight:800;font-size:15px;
    letter-spacing:-.01em;color:var(--text);
}
.pv-scope.pv-home .pv-cat-bar__grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}
.pv-scope.pv-home .pv-cat-bar__item{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:8px;min-height:96px;
    padding:12px 8px;
    background:var(--surface);border:1px solid var(--border);border-radius:var(--r-md);
    text-decoration:none;color:var(--text);
    transition:transform var(--t),box-shadow var(--t),border-color var(--t);
}
.pv-scope.pv-home .pv-cat-bar__item:hover{
    transform:translateY(-2px);box-shadow:var(--sh-hover);
    border-color:var(--primary);
}
.pv-scope.pv-home .pv-cat-bar__img-wrap{
    width:48px;height:48px;flex-shrink:0;
    border-radius:var(--r-sm);overflow:hidden;
    background:var(--bg-2);
    display:flex;align-items:center;justify-content:center;
}
.pv-scope.pv-home .pv-cat-bar__img{width:100%;height:100%;object-fit:cover;display:block;}
/* HOME-UX5-002: el emoji queda SOLO como fallback sin imagen real. */
.pv-scope.pv-home .pv-cat-bar__icon{font-size:30px;line-height:1;}
.pv-scope.pv-home .pv-cat-bar__name{
    font-size:11.5px;font-weight:700;color:var(--text);
    text-align:center;line-height:1.25;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.pv-scope.pv-home .pv-cat-bar__more{
    display:inline-flex;align-items:center;gap:4px;flex-shrink:0;
    font-size:13.5px;font-weight:700;color:var(--primary);text-decoration:none;
    min-height:44px;padding:0 8px;
}
.pv-scope.pv-home .pv-cat-bar__more:hover{color:var(--primary-600);}
/* HOME-UX4-001: padding de contenedor móvil igual al de .pv-section (14px).
   HOME-UX5-002 → HOME-CATBAR-2026-001: cards compactas en móvil (3 col en
   ~360px ≈ 106px/celda — antes 4 col ≈ 78px con desborde de palabras largas). */
@media (max-width:760px){
    .pv-scope.pv-home .pv-cat-bar{padding-left:14px;padding-right:14px;}
    .pv-scope.pv-home .pv-cat-bar__grid{gap:6px;}
    .pv-scope.pv-home .pv-cat-bar__item{gap:6px;min-height:86px;padding:10px 4px;}
    .pv-scope.pv-home .pv-cat-bar__img-wrap{width:40px;height:40px;}
}
@media (min-width:480px){
    .pv-scope.pv-home .pv-cat-bar__grid{grid-template-columns:repeat(5,minmax(0,1fr));}
}
@media (min-width:768px){
    .pv-scope.pv-home .pv-cat-bar__grid{grid-template-columns:repeat(6,minmax(0,1fr));}
}
@media (min-width:1024px){
    .pv-scope.pv-home .pv-cat-bar__grid{grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;}
    .pv-scope.pv-home .pv-cat-bar__img-wrap{width:52px;height:52px;}
}

/* ── CARRUSELES POR CATEGORÍA (HOME-UX5-001) ───────────────────────────────
   8 secciones (top categorías activas), cada una con un carrusel de 2
   filas: track grid-auto-flow:column + grid-template-rows:repeat(2,auto)
   + scroll-snap proximity (desliza con el dedo en móvil; en escritorio
   además flechas prev/next — initCatCarousels del scope HOME). Cards =
   template canónico content-product.php (DRY, AUDIT-FE-PV-DS-003) con
   snap-align start. Auto-columns: 160px móvil / 200px ≥768 / 248px ≥1024
   (a 248px×5.6 visibles en 1400px las 12 cards requieren scroll → las
   flechas tienen trabajo). Radio 8px (regla de radios uniformes arriba). */
.pv-scope.pv-home .pv-home__cat-carousels{padding-top:8px;padding-bottom:8px;}
.pv-scope.pv-home .pv-cat-carousel{padding-top:28px;}
.pv-scope.pv-home .pv-cat-carousel__wrap{position:relative;}
.pv-scope.pv-home .pv-cat-carousel__track{
    display:grid;grid-auto-flow:column;
    grid-template-rows:repeat(2,auto);
    grid-auto-columns:160px;
    gap:12px;
    overflow-x:auto;
    scroll-snap-type:x proximity;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:none;
    padding-bottom:4px;
}
.pv-scope.pv-home .pv-cat-carousel__track::-webkit-scrollbar{display:none;}
.pv-scope.pv-home .pv-cat-carousel__track .pv-product-card{
    margin:0;border-radius:8px;scroll-snap-align:start;width:100%;
}
/* HOME-UX5-001: flechas (solo escritorio — en móvil el track se desliza con
   el dedo). Flotan sobre el track, centradas verticalmente. */
.pv-scope.pv-home .pv-cat-carousel__nav{
    position:absolute;top:50%;transform:translateY(-50%);z-index:5;
    width:38px;height:38px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:var(--surface);color:var(--primary-700);
    border:1px solid var(--border-2);box-shadow:var(--sh-2);
    cursor:pointer;
    transition:background var(--t),color var(--t);
}
.pv-scope.pv-home .pv-cat-carousel__nav:hover{background:var(--primary);color:#fff;}
.pv-scope.pv-home .pv-cat-carousel__nav--prev{left:-12px;}
.pv-scope.pv-home .pv-cat-carousel__nav--next{right:-12px;}
/* Fade de bordes del track (mismo patrón data-pv-scrollable del ciclo UX2,
   ahora por carrusel — lo togglea initCatCarousels en el JS). */
.pv-scope.pv-home .pv-cat-carousel__track[data-pv-scrollable="1"]:not([data-pv-at-end="1"]){
    -webkit-mask-image:linear-gradient(to right,#000 0,#000 calc(100% - 28px),transparent 100%);
    mask-image:linear-gradient(to right,#000 0,#000 calc(100% - 28px),transparent 100%);
}
@media (max-width:767px){
    .pv-scope.pv-home .pv-cat-carousel__nav{display:none;}
    .pv-scope.pv-home .pv-cat-carousel__track{gap:10px;}
}
@media (min-width:768px){
    .pv-scope.pv-home .pv-cat-carousel__track{grid-auto-columns:200px;gap:14px;}
}
@media (min-width:1024px){
    .pv-scope.pv-home .pv-cat-carousel__track{grid-auto-columns:248px;gap:16px;}
}

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

/* ── VENDE CON NOSOTROS (HOME-UX2-001 → BRAND-2026-001, 2026-10-15) ────────
    BRAND-2026-001 (brief): el slab pasa del gradiente azul del design system
    al ROJO DE MARCA (--brand #E80001 → --brand-600 #C70001) con detalle
    dorado primario del brief (--ltms-gold #F7B731) — la paleta oficial es
    rojo/negro/dorado (azul queda reservado a enlaces e interactivo, soporte
    del brief). El CTA replica el botón "VENDER GRATIS" del brief: dorado
    #F7B731 con texto NEGRO (contraste 11.8:1 — el dorado NUNCA como texto).
    Contraste del texto: #fff sobre #E80001 = 4.74:1 (AA); sobre #C70001 el
    cierre del gradiente = 6.2:1. Desktop: texto izq + botón der. Móvil:
    apilado, botón full-width. */
.pv-scope.pv-home .pv-home__sell{padding-top:40px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home-sell{
    display:flex;flex-direction:column;align-items:flex-start;gap:18px;
    background:radial-gradient(120% 100% at 0% 0%,var(--brand) 0%,var(--brand-600) 100%);
    border-top:3px solid var(--ltms-gold);
    border-radius:var(--r-md);
    padding:32px 24px;
}
.pv-scope.pv-home .pv-home-sell__body{display:flex;flex-direction:column;gap:6px;min-width:0;}
.pv-scope.pv-home .pv-home-sell__title{
    font-family:var(--display);font-weight:800;font-size:clamp(20px,2.4vw,28px);
    color:#fff;line-height:1.15;margin:0;
}
.pv-scope.pv-home .pv-home-sell__sub{
    font-size:14.5px;color:#fff;line-height:1.5;margin:0;
    max-width:560px;
}
.pv-scope.pv-home .pv-home-sell__cta{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:48px;padding:0 28px;flex-shrink:0;
    background:var(--ltms-gold);color:#000;
    font-family:var(--display);font-size:14.5px;font-weight:700;
    border-radius:12px;text-decoration:none;
    transition:filter var(--t),transform var(--t);
    white-space:nowrap;
}
.pv-scope.pv-home .pv-home-sell__cta:hover{filter:brightness(.92);transform:translateY(-1px);}
.pv-scope.pv-home .pv-home-sell__cta:focus-visible{outline:3px solid #fff;outline-offset:2px;}
/* REG-CHOICE-003 (2026-10-22): contenedor dual. Móvil/tablet: columna con
   botones a todo el ancho (align-items:stretch estira los inline-flex).
   Escritorio: fila junto al texto (media de abajo). */
.pv-scope.pv-home .pv-home-sell__ctas{
    display:flex;flex-direction:column;align-items:stretch;gap:10px;width:100%;
}
/* Botón comprador secundario: sobre el rojo de marca, fondo oscuro
   translúcido + borde blanco. #fff sobre #E80001+22% negro ≈ 6.3:1 (AA). */
.pv-scope.pv-home .pv-home-sell__cta--ghost{
    background:rgba(0,0,0,.22);color:#fff;
    border:1.5px solid rgba(255,255,255,.75);
}
.pv-scope.pv-home .pv-home-sell__cta--ghost:hover{filter:brightness(1.15);}
@media (min-width:1024px){
    .pv-scope.pv-home .pv-home-sell{
        flex-direction:row;align-items:center;justify-content:space-between;
        padding:36px 40px;
    }
    .pv-scope.pv-home .pv-home-sell__ctas{flex-direction:row;width:auto;align-items:center;}
}
@media (max-width:560px){
    .pv-scope.pv-home .pv-home-sell__cta{width:100%;padding:0 16px;}
}

/* ── EMPTY STATES (secciones dinámicas) ───────────────────────────────────
   AUDIT-FE-PV-DS-008 FIX (P1-6): trending / star vendors eran secciones
   silenciosas (if !empty sin else). Ahora muestran una nota vacía con CTA
   en vez de desaparecer sin explicación. */
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

/* ── AYUDA Y POLÍTICAS (HOME-UX2-005) ────────────────────────────────────
   Sección compacta que organiza TODO el texto de enlaces que antes vivía
   en las columnas del footer (+ el contacto del footer del tema, hoy
   oculto en la home). El footer queda puramente visual. */
.pv-scope.pv-home .pv-home__policies{padding-top:40px;padding-bottom:8px;}
.pv-scope.pv-home .pv-home-policies{
    display:grid;grid-template-columns:1fr 1fr;gap:24px 16px;
    padding:24px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--r-md);
}
.pv-scope.pv-home .pv-home-policies__title{
    font-size:12.5px;font-weight:700;color:var(--text);
    text-transform:uppercase;letter-spacing:.05em;
    margin-bottom:10px;
}
.pv-scope.pv-home .pv-home-policies__links{
    display:flex;flex-direction:column;gap:8px;list-style:none;margin:0;padding:0;
}
.pv-scope.pv-home .pv-home-policies__links a,
.pv-scope.pv-home .pv-home-policies__links span{
    font-size:13px;color:var(--text-2);text-decoration:none;line-height:1.4;
}
.pv-scope.pv-home .pv-home-policies__links a:hover{color:var(--primary);}
@media (min-width:1024px){
    .pv-scope.pv-home .pv-home-policies{grid-template-columns:repeat(4,1fr);}
}

/* ── FOOTER (HOME-UX2-005: puramente visual → BRAND-2026-001, 2026-10-15) ──
    BRAND-2026-001 (brief): fondo NEGRO #000000 (color de marca del brief —
    "footer negro"), texto blanco, hover dorado --ltms-gold. Las imágenes de
    pagos/afiliaciones viajan sobre chips blancos redondeados (los assets del
    operador tienen fondo claro — el chip los hace intencionales sobre negro). */
.pv-scope.pv-home .pv-home-footer{
    margin-top:56px;background:#000000;border-top:1px solid rgba(255,255,255,.14);
}
.pv-scope.pv-home .pv-home-footer__inner{
    display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;
    padding-top:32px;padding-bottom:28px;
}
.pv-scope.pv-home .pv-home-footer__logo{
    display:inline-flex;align-items:center;
    text-decoration:none;
}
/* BRAND-2026-001: logo oficial (variante blanca, transparente) — 36px. */
.pv-scope.pv-home .pv-home-footer__logo-img{height:36px;width:auto;display:block;}
.pv-scope.pv-home .pv-home-footer__social{display:flex;gap:8px;}
.pv-scope.pv-home .pv-home-footer__social-link{
    width:40px;height:40px;border-radius:var(--r-md);
    display:flex;align-items:center;justify-content:center;
    background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.22);color:#fff;
    transition:background var(--t),color var(--t),border-color var(--t),transform var(--t);
}
.pv-scope.pv-home .pv-home-footer__social-link:hover{
    background:var(--ltms-gold);color:#000;border-color:var(--ltms-gold);transform:translateY(-2px);
}
/* Fila de marcas (pagos/donaciones/afiliaciones — las imágenes reales del
   operador) + selector de moneda (bajo la marca). HOME-UX3-002.
   BRAND-2026-001: chips blancos sobre el footer negro. */
.pv-scope.pv-home .pv-home-footer__payrow{
    display:flex;flex-direction:column;gap:10px;
    padding-top:24px;padding-bottom:32px;
    border-top:1px solid rgba(255,255,255,.14);
}
.pv-scope.pv-home .pv-home-footer__brands{
    display:flex;align-items:center;justify-content:center;
    gap:18px;flex-wrap:wrap;list-style:none;margin:0;padding:0;
}
.pv-scope.pv-home .pv-home-footer__brand-item img{
    height:56px;width:auto;max-width:190px;object-fit:contain;display:block;
    background:#fff;border-radius:8px;padding:6px 10px;
}
@media (max-width:560px){
    .pv-scope.pv-home .pv-home-footer__brands{gap:12px;}
    .pv-scope.pv-home .pv-home-footer__brand-item img{height:44px;max-width:150px;}
}
/* Selector de moneda sobre fondo negro (label legible; el select es blanco). */
.pv-scope.pv-home .pv-home-footer__payrow .ltms-currency-switcher__label{color:rgba(255,255,255,.7);}
.pv-scope.pv-home .pv-home-footer__bottom{border-top:1px solid rgba(255,255,255,.14);}
.pv-scope.pv-home .pv-home-footer__bottom-inner{
    display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;
    padding-top:18px;padding-bottom:18px;font-size:12.5px;color:rgba(255,255,255,.66);
}

/* ── RADIOS DE TARJETAS (HOME-REDESIGN-005) — al final del <style> para
   ganar sobre las reglas específicas de cada sección (cat-bar items, hero
   banners/cards y cards de producto usaban --r-md 14px o 20px; el brief
   exige radio uniforme de 8px en tarjetas). Especificidad igual o mayor
   + orden posterior = ganan. */
.pv-scope.pv-home .pv-product-card,
.pv-scope.pv-home .pv-vendor-card,
.pv-scope.pv-home .pv-cat-bar__item,
.pv-scope.pv-home .pv-home-hero__banner,
.pv-scope.pv-home .pv-home-hero__card{border-radius:8px;}

/* ── RESPONSIVE ────────────────────────────────────────────────────────────
   HOME-REDESIGN-002/003/005: header, barra de categorías, hero y grid de
   productos usan el sistema móvil-primero (min-width 480/768/1024/1280/1440)
   definido arriba. Los bloques max-width de abajo cubren solo las secciones
   pendientes de su propio commit (vendors, footer). Las reglas viejas del
   header (max-width:980/560), del bento grid y del grid de productos fueron
   ELIMINADAS — pisaban al sistema nuevo en la cascada.
   HOME-UX2-005 (2026-10-02): las reglas de las columnas del footer viejo
   (grid 4-col / col--brand) fueron eliminadas junto con el footer de texto. */
@media (max-width:1100px){
    .pv-scope.pv-home .pv-home__vendor-grid{grid-template-columns:repeat(2,1fr);}
}
@media (max-width:760px){
    .pv-scope.pv-home .pv-home__vendor-grid{grid-template-columns:1fr;}
}

/* ── MÓVIL (HOME-UX2-008, 2026-10-02) ──────────────────────────────────────
   Decisión del operador: en móvil (<768px) el hero de banners y la sección
   de vendedores destacados se OCULTAN, y el orden de compra es el natural
   del funnel: header → categorías → tarjetas de productos → garantías →
   vende → políticas → footer. La barra de categorías ya está justo debajo
   del header (fuera de #pv-main), así que solo se reordena main con flex
   order. Escritorio/tablet (≥768px) conserva el orden original completo. */
@media (max-width:767px){
    .pv-scope.pv-home .pv-home__hero-wrap{display:none;}
    .pv-scope.pv-home .pv-home__vendors{display:none;}
    .pv-scope.pv-home #pv-main{display:flex;flex-direction:column;}
    .pv-scope.pv-home .pv-home__cat-carousels{order:1;}
    .pv-scope.pv-home .pv-home__trust{order:2;}
    .pv-scope.pv-home .pv-home__sell{order:3;}
    .pv-scope.pv-home .pv-home__policies{order:4;}
    /* Hero y vendors ocultos arriba, pero mantienen su orden para que un
       futuro cambio de visibilidad no altere la intención. */
    .pv-scope.pv-home .pv-home__hero-wrap{order:0;}
    .pv-scope.pv-home .pv-home__vendors{order:5;}
}
@media (max-width:560px){
    .pv-scope.pv-home .pv-home-footer__bottom-inner{flex-direction:column;align-items:center;}
}
</style>

<?php
/* ============================================================================
 * AUDIT-FE-HOME-001 FIX (Fase 1.10): el bloque <script> inline de chips de
 * búsqueda + header shadow fue ELIMINADO. Su lógica era código muerto:
 *   1. Chips de búsqueda: el handler global del design system 
 *      ltms-plaza-viva.js (líneas 588-614, AUDIT-FE-HOME-003 FIX, commit
 *      9882789b) ya rellena el input + hace form.submit() al click en
 *      los chips de búsqueda (attr que la home dejó de emitir en
 *      HOME-CATBAR-2026-005, cuando la fila fue reemplazada por la barra
 *      global). Como ese handler global se registra primero
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
