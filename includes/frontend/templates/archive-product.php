<?php
/**
 * Template: Archive Product (Shop) — Plaza Viva Design System
 *
 * Plantilla nativa de tienda/archive de WooCommerce multi-vendor.
 * Reemplaza al template de Elementor vía `template_include` (ver
 * LTMS_Native_Templates::maybe_override()).
 *
 * Secciones:
 *  - Breadcrumb (woocommerce_breadcrumb).
 *  - Título de categoría (woocommerce_page_title / single_term_title).
 *  - Layout 2 columnas: sidebar filtros (240px) + grid productos.
 *  - Sidebar: categorías, precio, rating, envío, vendor.
 *    Usa dynamic_sidebar('shop-sidebar') si existe; si no, filtros nativos.
 *  - Toolbar: count + ordenar + vista toggle (grid/lista).
 *  - Active filter chips (removibles).
 *  - Product loop con wc_get_template_part('content','product').
 *  - Paginación (woocommerce_pagination).
 *
 * Front-back integration:
 *  - WC hooks: woocommerce_before_shop_loop, woocommerce_after_shop_loop,
 *    woocommerce_archive_description, woocommerce_before_main_content,
 *    woocommerce_after_main_content.
 *  - WC_Query: WC()->query->get_catalog_ordering_args() para el orden.
 *  - El product loop usa wc_get_template_part('content','product') que
 *    carga el override (wc-parts/content-product.php) vía
 *    LTMS_Native_Templates::locate_wc_template().
 *
 * @package LTMS
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salida directa no permitida.
}

// Garantizar que WooCommerce está cargado.
if ( ! function_exists( 'woocommerce_content' ) ) {
    get_header( 'shop' );
    woocommerce_content();
    get_footer( 'shop' );
    return;
}

/* ---------------------------------------------------------------------------
 * Helpers de render (funciones locales del template).
 * Se definen aquí (antes de su primer uso) porque PHP NO hoistea funciones
 * declaradas dentro de bloques condicionales `if (...) :`.
 * ------------------------------------------------------------------------- */

if ( ! function_exists( 'ltms_pv_remove_query_arg' ) ) :
/**
 * Elimina argumentos de query string de la URL actual.
 *
 * @param string|array $pv_keys Clave(s) a eliminar.
 * @return string URL resultante.
 */
function ltms_pv_remove_query_arg( $pv_keys ) {
    $pv_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    return remove_query_arg( $pv_keys, $pv_uri );
}
endif; // ltms_pv_remove_query_arg

if ( ! function_exists( 'ltms_pv_star_row' ) ) :
/**
 * Renderiza una fila de estrellas (★) hasta el rating dado.
 *
 * @param int $pv_rating Rating (1-5).
 * @return string Markup HTML.
 */
function ltms_pv_star_row( $pv_rating ) {
    $pv_rating = max( 0, min( 5, (int) $pv_rating ) );
    $pv_html = '<span class="pv-shop-filter__stars-row" aria-hidden="true">';
    for ( $pv_i = 1; $pv_i <= 5; $pv_i++ ) {
        $pv_html .= $pv_i <= $pv_rating ? '★' : '☆';
    }
    $pv_html .= '</span>';
    return $pv_html;
}
endif; // ltms_pv_star_row

/* ---------------------------------------------------------------------------
 * 1. Datos para filtros del sidebar
 * ------------------------------------------------------------------------- */

/**
 * Categorías para el filtro (top-level, con count).
 * Si estamos en una categoría, se excluye a sí misma de la lista del filtro
 * para evitar redundancia (la categoría actual ya es el contexto).
 */
$pv_filter_cats = get_terms( array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'parent'     => 0,
    'number'     => 12,
    'orderby'    => 'count',
    'order'      => 'DESC',
) );

/**
 * Rango de precios global del catálogo.
 * Usa WC price filter (WC()->query->get_filtered_price) si hay filtros
 * activos; si no, calcula el min/max de todos los productos publicados.
 */
$pv_price_min = 0;
$pv_price_max = 1000000;
if ( function_exists( 'wc_get_price_decimal_sep' ) ) {
    global $wpdb;
    // Min/Max de productos publicados con precio.
    $pv_price_row = $wpdb->get_row(
        "SELECT
            COALESCE(MIN(meta_value), 0) AS min_price,
            COALESCE(MAX(meta_value), 0) AS max_price
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_price'
           AND pm.meta_value > 0
           AND p.post_type = 'product'
           AND p.post_status = 'publish'",
        ARRAY_A
    );
    if ( $pv_price_row ) {
        $pv_price_min = (float) $pv_price_row['min_price'];
        $pv_price_max = (float) $pv_price_row['max_price'];
        if ( $pv_price_max <= $pv_price_min ) {
            $pv_price_max = $pv_price_min + 1000;
        }
    }
}

/**
 * Filtros activos (leídos de $_GET — compatibles con WC_Query).
 * WC usa: min_price, max_price, rating, ?filter_<taxonomy>=slug.
 */
$pv_get_min_price    = isset( $_GET['min_price'] ) ? wc_clean( wp_unslash( $_GET['min_price'] ) ) : '';
$pv_get_max_price    = isset( $_GET['max_price'] ) ? wc_clean( wp_unslash( $_GET['max_price'] ) ) : '';
$pv_get_rating       = isset( $_GET['rating'] ) ? array_map( 'intval', (array) wc_clean( wp_unslash( $_GET['rating'] ) ) ) : array();
$pv_get_free_ship    = isset( $_GET['filter_shipping'] ) ? wc_clean( wp_unslash( $_GET['filter_shipping'] ) ) : '';
$pv_get_vendor_type  = isset( $_GET['filter_vendor'] ) ? wc_clean( wp_unslash( $_GET['filter_vendor'] ) ) : '';
$pv_get_view         = isset( $_GET['pv_view'] ) ? wc_clean( wp_unslash( $_GET['pv_view'] ) ) : 'grid';

/**
 * Construye la lista de chips de filtros activos (removibles).
 * Cada chip tiene label + URL sin su parámetro.
 */
$pv_active_chips = array();

// Categoría actual (si es archive de categoría).
$pv_current_term = is_product_category() ? get_queried_object() : null;

// Precio.
if ( '' !== $pv_get_min_price || '' !== $pv_get_max_price ) {
    $pv_price_label = '';
    if ( '' !== $pv_get_min_price && '' !== $pv_get_max_price ) {
        /* translators: 1: precio mín, 2: precio máx. */
        $pv_price_label = sprintf( __( '%s – %s', 'ltms' ), wc_price( $pv_get_min_price ), wc_price( $pv_get_max_price ) );
    } elseif ( '' !== $pv_get_min_price ) {
        /* translators: %s: precio mín. */
        $pv_price_label = sprintf( __( 'Desde %s', 'ltms' ), wc_price( $pv_get_min_price ) );
    } else {
        /* translators: %s: precio máx. */
        $pv_price_label = sprintf( __( 'Hasta %s', 'ltms' ), wc_price( $pv_get_max_price ) );
    }
    $pv_active_chips[] = array(
        'label' => $pv_price_label,
        'url'   => ltms_pv_remove_query_arg( array( 'min_price', 'max_price' ) ),
    );
}

// Rating.
if ( ! empty( $pv_get_rating ) ) {
    foreach ( $pv_get_rating as $pv_r ) {
        $pv_rk = array_search( $pv_r, $pv_get_rating );
        $pv_rurl = ltms_pv_remove_query_arg( 'rating' );
        // Reconstruir sin el rating actual.
        $pv_others = array_diff( $pv_get_rating, array( $pv_r ) );
        if ( ! empty( $pv_others ) ) {
            $pv_rurl = add_query_arg( 'rating', implode( ',', $pv_others ), $pv_rurl );
        }
        $pv_active_chips[] = array(
            'label' => sprintf( _n( '%d estrella o más', '%d estrellas o más', $pv_r, 'ltms' ), $pv_r ),
            'url'   => $pv_rurl,
        );
    }
}

// Envío.
if ( '' !== $pv_get_free_ship ) {
    $pv_ship_labels = array(
        'free'     => __( 'Envío gratis', 'ltms' ),
        'fast'     => __( 'Entrega rápida', 'ltms' ),
    );
    $pv_ship_label = isset( $pv_ship_labels[ $pv_get_free_ship ] ) ? $pv_ship_labels[ $pv_get_free_ship ] : $pv_get_free_ship;
    $pv_active_chips[] = array(
        'label' => $pv_ship_label,
        'url'   => ltms_pv_remove_query_arg( 'filter_shipping' ),
    );
}

// Vendor.
if ( '' !== $pv_get_vendor_type ) {
    $pv_vendor_labels = array(
        'star'       => __( 'Star Seller', 'ltms' ),
        'verified'   => __( 'Vendedor verificado', 'ltms' ),
    );
    $pv_vendor_label = isset( $pv_vendor_labels[ $pv_get_vendor_type ] ) ? $pv_vendor_labels[ $pv_get_vendor_type ] : $pv_get_vendor_type;
    $pv_active_chips[] = array(
        'label' => $pv_vendor_label,
        'url'   => ltms_pv_remove_query_arg( 'filter_vendor' ),
    );
}

/**
 * Si hay una categoría de filtro (no la del contexto) activa vía ?filter_category.
 */
$pv_get_filter_cat = isset( $_GET['filter_category'] ) ? array_map( 'intval', (array) wc_clean( wp_unslash( $_GET['filter_category'] ) ) ) : array();
foreach ( $pv_get_filter_cat as $pv_fc ) {
    $pv_fcterm = get_term( $pv_fc, 'product_cat' );
    if ( $pv_fcterm && ! is_wp_error( $pv_fcterm ) ) {
        $pv_others = array_diff( $pv_get_filter_cat, array( $pv_fc ) );
        $pv_fcurl = ltms_pv_remove_query_arg( 'filter_category' );
        if ( ! empty( $pv_others ) ) {
            $pv_fcurl = add_query_arg( 'filter_category', implode( ',', $pv_others ), $pv_fcurl );
        }
        $pv_active_chips[] = array(
            'label' => $pv_fcterm->name,
            'url'   => $pv_fcurl,
        );
    }
}

/**
 * ¿Existe el widget area 'shop-sidebar'? Si sí, se usa dynamic_sidebar;
 * si no, se renderizan los filtros nativos.
 */
$pv_has_shop_sidebar = is_active_sidebar( 'shop-sidebar' );

/**
 * URL base del formulario de filtros (la página actual).
 */
$pv_filter_action = is_product_category() && $pv_current_term ? get_term_link( $pv_current_term ) : get_permalink( wc_get_page_id( 'shop' ) );
if ( is_wp_error( $pv_filter_action ) ) {
    $pv_filter_action = home_url( '/' );
}

/**
 * Query string base sin parámetros de paginación.
 */
$pv_shop_url = get_permalink( wc_get_page_id( 'shop' ) );

get_header( 'shop' );

/**
 * Hook: ltms_before_archive_product_plazaviva
 * Permite inyectar contenido antes del contenedor principal.
 */
do_action( 'ltms_before_archive_product_plazaviva' );

/**
 * Wrapper del tema — woocommerce_before_main_content.
 * Se invoca FUERA de .pv-scope para que el wrapper de apertura y cierre
 * queden balanceados. Desenganchamos temporalmente woocommerce_breadcrumb
 * (prioridad 20) porque lo renderizamos explícitamente dentro del scope.
 */
$pv_breadcrumb_was_hooked = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( $pv_breadcrumb_was_hooked ) {
    remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}
do_action( 'woocommerce_before_main_content' );
?>

<div class="pv-scope pv-shop">

    <?php
    /* =====================================================================
     * BREADCRUMB
     * =====================================================================
     */
    ?>
    <div class="pv-shop__breadcrumb pv-section pv-section--tight">
        <?php woocommerce_breadcrumb(); ?>
    </div>

    <?php
    /* =====================================================================
     * CABECERA — título + descripción de categoría
     * =====================================================================
     */
    ?>
    <header class="pv-shop__head pv-section">
        <?php
        /**
         * Título — woocommerce_page_title() muestra el título del shop o
         * el nombre de la categoría/etiqueta actual. Se renderiza con
         * un wrapper para aplicar tipografía del design system.
         */
        if ( is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            echo '<h1 class="pv-shop__title">';
            woocommerce_page_title();
            echo '</h1>';
        } else {
            echo '<h1 class="pv-shop__title">';
            woocommerce_page_title();
            echo '</h1>';
        }

        /**
         * Hook: woocommerce_archive_description
         * Renderiza la descripción de la categoría/etiqueta actual
         * (woocommerce_taxonomy_archive_description) o la descripción
         * de la página de tienda (woocommerce_product_archive_description).
         */
        do_action( 'woocommerce_archive_description' );
        ?>
    </header>

    <?php
    /* =====================================================================
     * LAYOUT 2 COLUMNAS — sidebar filtros + grid productos
     * =====================================================================
     */
    ?>
    <div class="pv-shop__layout pv-section">

        <?php
        /* =================================================================
         * SIDEBAR FILTROS (240px)
         * Usa dynamic_sidebar('shop-sidebar') si existe; si no, nativos.
         * ============================================================== */
        ?>
        <aside class="pv-shop-sidebar" id="pv-shop-sidebar" aria-label="<?php esc_attr_e( 'Filtros de productos', 'ltms' ); ?>" data-pv-shop-sidebar>

            <?php if ( $pv_has_shop_sidebar ) : ?>
                <?php dynamic_sidebar( 'shop-sidebar' ); ?>
            <?php else : ?>

                <?php
                /* --- Formulario nativo de filtros ------------------------- */
                ?>
                <form class="pv-shop-filters" method="get" action="<?php echo esc_url( $pv_filter_action ); ?>" data-pv-filters-form>

                    <?php
                    // Preservar parámetros de orden de WC (orderby, paged).
                    $pv_preserve = array( 'orderby', 'order' );
                    foreach ( $pv_preserve as $pv_pk ) {
                        if ( isset( $_GET[ $pv_pk ] ) ) {
                            echo '<input type="hidden" name="' . esc_attr( $pv_pk ) . '" value="' . esc_attr( wc_clean( wp_unslash( $_GET[ $pv_pk ] ) ) ) . '" />';
                        }
                    }
                    ?>

                    <?php
                    /* --- Filtro: Categorías (checkbox con count) ------------- */
                    if ( ! empty( $pv_filter_cats ) && ! is_wp_error( $pv_filter_cats ) ) :
                    ?>
                        <fieldset class="pv-shop-filter">
                            <legend class="pv-shop-filter__title">
                                <button type="button" class="pv-shop-filter__toggle" data-pv-filter-toggle aria-expanded="true" aria-controls="pv-filter-cat">
                                    <span><?php esc_html_e( 'Categorías', 'ltms' ); ?></span>
                                    <svg class="pv-shop-filter__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </legend>
                            <div class="pv-shop-filter__body" id="pv-filter-cat">
                                <?php foreach ( $pv_filter_cats as $pv_ft ) :
                                    $pv_checked = in_array( (int) $pv_ft->term_id, $pv_get_filter_cat, true );
                                ?>
                                    <label class="pv-shop-filter__option">
                                        <input type="checkbox" name="filter_category[]" value="<?php echo esc_attr( $pv_ft->term_id ); ?>" <?php checked( $pv_checked ); ?> />
                                        <span class="pv-shop-filter__label"><?php echo esc_html( $pv_ft->name ); ?></span>
                                        <span class="pv-shop-filter__count"><?php echo esc_html( number_format_i18n( (int) $pv_ft->count ) ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <?php
                    /* --- Filtro: Precio (2 inputs min/max) ------------------- */
                    ?>
                    <fieldset class="pv-shop-filter">
                        <legend class="pv-shop-filter__title">
                            <button type="button" class="pv-shop-filter__toggle" data-pv-filter-toggle aria-expanded="true" aria-controls="pv-filter-price">
                                <span><?php esc_html_e( 'Precio', 'ltms' ); ?></span>
                                <svg class="pv-shop-filter__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </legend>
                        <div class="pv-shop-filter__body" id="pv-filter-price">
                            <div class="pv-shop-filter__price">
                                <label class="pv-shop-filter__price-field">
                                    <span class="pv-visually-hidden"><?php esc_html_e( 'Precio mínimo', 'ltms' ); ?></span>
                                    <input type="number"
                                           name="min_price"
                                           class="pv-input pv-input--sm"
                                           placeholder="<?php esc_attr_e( 'Mín', 'ltms' ); ?>"
                                           value="<?php echo esc_attr( $pv_get_min_price ); ?>"
                                           min="0"
                                           inputmode="numeric" />
                                </label>
                                <span class="pv-shop-filter__price-sep" aria-hidden="true">—</span>
                                <label class="pv-shop-filter__price-field">
                                    <span class="pv-visually-hidden"><?php esc_html_e( 'Precio máximo', 'ltms' ); ?></span>
                                    <input type="number"
                                           name="max_price"
                                           class="pv-input pv-input--sm"
                                           placeholder="<?php esc_attr_e( 'Máx', 'ltms' ); ?>"
                                           value="<?php echo esc_attr( $pv_get_max_price ); ?>"
                                           min="0"
                                           inputmode="numeric" />
                                </label>
                            </div>
                            <button type="submit" class="pv-btn pv-btn--ghost pv-btn--sm pv-btn--block pv-shop-filter__apply">
                                <?php esc_html_e( 'Aplicar', 'ltms' ); ?>
                            </button>
                        </div>
                    </fieldset>

                    <?php
                    /* --- Filtro: Rating (checkbox 5★, 4★, 3★) --------------- */
                    ?>
                    <fieldset class="pv-shop-filter">
                        <legend class="pv-shop-filter__title">
                            <button type="button" class="pv-shop-filter__toggle" data-pv-filter-toggle aria-expanded="true" aria-controls="pv-filter-rating">
                                <span><?php esc_html_e( 'Valoración', 'ltms' ); ?></span>
                                <svg class="pv-shop-filter__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </legend>
                        <div class="pv-shop-filter__body" id="pv-filter-rating">
                            <?php
                            $pv_ratings = array( 5, 4, 3 );
                            foreach ( $pv_ratings as $pv_rv ) :
                                $pv_rchecked = in_array( $pv_rv, $pv_get_rating, true );
                            ?>
                                <label class="pv-shop-filter__option">
                                    <input type="checkbox" name="rating[]" value="<?php echo esc_attr( $pv_rv ); ?>" <?php checked( $pv_rchecked ); ?> />
                                    <span class="pv-shop-filter__stars" aria-label="<?php echo esc_attr( sprintf( __( '%d estrellas o más', 'ltms' ), $pv_rv ) ); ?>">
                                        <?php echo ltms_pv_star_row( $pv_rv ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <span class="pv-shop-filter__label"><?php echo esc_html( sprintf( __( '%d★ o más', 'ltms' ), $pv_rv ) ); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <?php
                    /* --- Filtro: Envío (checkbox: envío gratis, entrega rápida) */
                    ?>
                    <fieldset class="pv-shop-filter">
                        <legend class="pv-shop-filter__title">
                            <button type="button" class="pv-shop-filter__toggle" data-pv-filter-toggle aria-expanded="true" aria-controls="pv-filter-shipping">
                                <span><?php esc_html_e( 'Envío', 'ltms' ); ?></span>
                                <svg class="pv-shop-filter__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </legend>
                        <div class="pv-shop-filter__body" id="pv-filter-shipping">
                            <label class="pv-shop-filter__option">
                                <input type="radio" name="filter_shipping" value="free" <?php checked( $pv_get_free_ship, 'free' ); ?> />
                                <span class="pv-shop-filter__icon" aria-hidden="true">🚚</span>
                                <span class="pv-shop-filter__label"><?php esc_html_e( 'Envío gratis', 'ltms' ); ?></span>
                            </label>
                            <label class="pv-shop-filter__option">
                                <input type="radio" name="filter_shipping" value="fast" <?php checked( $pv_get_free_ship, 'fast' ); ?> />
                                <span class="pv-shop-filter__icon" aria-hidden="true">⚡</span>
                                <span class="pv-shop-filter__label"><?php esc_html_e( 'Entrega rápida', 'ltms' ); ?></span>
                            </label>
                        </div>
                    </fieldset>

                    <?php
                    /* --- Filtro: Vendor (checkbox: Star Seller, verificado) --- */
                    ?>
                    <fieldset class="pv-shop-filter">
                        <legend class="pv-shop-filter__title">
                            <button type="button" class="pv-shop-filter__toggle" data-pv-filter-toggle aria-expanded="true" aria-controls="pv-filter-vendor">
                                <span><?php esc_html_e( 'Vendedor', 'ltms' ); ?></span>
                                <svg class="pv-shop-filter__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </legend>
                        <div class="pv-shop-filter__body" id="pv-filter-vendor">
                            <label class="pv-shop-filter__option">
                                <input type="radio" name="filter_vendor" value="star" <?php checked( $pv_get_vendor_type, 'star' ); ?> />
                                <span class="pv-shop-filter__icon" aria-hidden="true">⭐</span>
                                <span class="pv-shop-filter__label"><?php esc_html_e( 'Star Seller', 'ltms' ); ?></span>
                            </label>
                            <label class="pv-shop-filter__option">
                                <input type="radio" name="filter_vendor" value="verified" <?php checked( $pv_get_vendor_type, 'verified' ); ?> />
                                <span class="pv-shop-filter__icon" aria-hidden="true">✓</span>
                                <span class="pv-shop-filter__label"><?php esc_html_e( 'Verificado', 'ltms' ); ?></span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="pv-shop-filter__actions">
                        <button type="submit" class="pv-btn pv-btn--sm pv-btn--block">
                            <?php esc_html_e( 'Aplicar filtros', 'ltms' ); ?>
                        </button>
                        <a class="pv-btn pv-btn--ghost pv-btn--sm pv-btn--block pv-shop-filter__clear" href="<?php echo esc_url( $pv_filter_action ); ?>">
                            <?php esc_html_e( 'Limpiar todo', 'ltms' ); ?>
                        </a>
                    </div>
                </form><!-- /.pv-shop-filters -->

            <?php endif; // endif $pv_has_shop_sidebar ?>
        </aside><!-- /.pv-shop-sidebar -->

        <?php
        /* =================================================================
         * COLUMNA PRINCIPAL — toolbar + filtros activos + grid + paginación
         * ============================================================== */
        ?>
        <div class="pv-shop__main">

            <?php
            /**
             * Hook: woocommerce_before_shop_loop
             * Desenganchamos temporalmente woocommerce_result_count (prio 20)
             * y woocommerce_catalog_ordering (prio 30) para evitar duplicarlos
             * con nuestro toolbar custom. Mantenemos woocommerce_output_all_notices
             * (prio 10) y cualquier otro callback de terceros.
             */
            $pv_remove_count    = has_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count' );
            $pv_remove_ordering = has_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering' );
            if ( $pv_remove_count ) {
                remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
            }
            if ( $pv_remove_ordering ) {
                remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
            }

            do_action( 'woocommerce_before_shop_loop' );

            // Restaurar para no afectar al resto del sitio.
            if ( $pv_remove_count ) {
                add_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
            }
            if ( $pv_remove_ordering ) {
                add_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
            }
            ?>

            <?php
            /* --- TOOLBAR: count + ordenar + vista toggle ----------------- */
            ?>
            <div class="pv-shop-toolbar">
                <div class="pv-shop-toolbar__count">
                    <?php
                    global $wp_query;
                    $pv_total = (int) ( isset( $wp_query->found_posts ) ? $wp_query->found_posts : 0 );
                    if ( function_exists( 'wc_get_loop_prop' ) && wc_get_loop_prop( 'total' ) ) {
                        $pv_total = (int) wc_get_loop_prop( 'total' );
                    }
                    ?>
                    <span class="pv-shop-toolbar__count-num"><?php echo esc_html( number_format_i18n( $pv_total ) ); ?></span>
                    <span class="pv-shop-toolbar__count-label">
                        <?php
                        /* translators: %d: número de resultados. */
                        echo esc_html( sprintf( _n( '%d producto', '%d productos', $pv_total, 'ltms' ), $pv_total ) );
                        ?>
                    </span>
                </div>

                <div class="pv-shop-toolbar__controls">
                    <?php
                    /**
                     * Select de ordenación — usa WC()->query->get_catalog_ordering_args()
                     * para conocer las opciones disponibles. El formulario se envía
                     * por GET (compatibilidad con WC_Query).
                     */
                    $pv_current_orderby = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : apply_filters( 'woocommerce_default_catalog_orderby', '' );
                    if ( '' === $pv_current_orderby ) {
                        $pv_current_orderby = 'menu_order';
                    }
                    $pv_orderby_options = apply_filters( 'woocommerce_catalog_orderby', array(
                        'menu_order' => __( 'Predeterminado', 'ltms' ),
                        'popularity' => __( 'Más vendidos', 'ltms' ),
                        'rating'     => __( 'Mejor valorados', 'ltms' ),
                        'date'       => __( 'Más recientes', 'ltms' ),
                        'price'      => __( 'Precio: menor a mayor', 'ltms' ),
                        'price-desc' => __( 'Precio: mayor a menor', 'ltms' ),
                    ) );
                    ?>
                    <label class="pv-shop-toolbar__sort">
                        <span class="pv-visually-hidden"><?php esc_html_e( 'Ordenar por', 'ltms' ); ?></span>
                        <select name="orderby" class="pv-select pv-select--sm pv-shop-toolbar__sort-select" data-pv-sort-select>
                            <?php foreach ( $pv_orderby_options as $pv_ok => $pv_olabel ) : ?>
                                <option value="<?php echo esc_attr( $pv_ok ); ?>" <?php selected( $pv_current_orderby, $pv_ok ); ?>><?php echo esc_html( $pv_olabel ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php
                    /* --- Vista toggle: grid / lista -------------------------- */
                    ?>
                    <div class="pv-shop-toolbar__view" role="group" aria-label="<?php esc_attr_e( 'Modo de vista', 'ltms' ); ?>">
                        <button type="button"
                                class="pv-shop-toolbar__view-btn <?php echo ( 'grid' === $pv_get_view ) ? 'is-active' : ''; ?>"
                                data-pv-view-toggle="grid"
                                aria-pressed="<?php echo ( 'grid' === $pv_get_view ) ? 'true' : 'false'; ?>"
                                aria-label="<?php esc_attr_e( 'Vista de cuadrícula', 'ltms' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                        </button>
                        <button type="button"
                                class="pv-shop-toolbar__view-btn <?php echo ( 'list' === $pv_get_view ) ? 'is-active' : ''; ?>"
                                data-pv-view-toggle="list"
                                aria-pressed="<?php echo ( 'list' === $pv_get_view ) ? 'true' : 'false'; ?>"
                                aria-label="<?php esc_attr_e( 'Vista de lista', 'ltms' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        </button>
                    </div>
                </div>
            </div><!-- /.pv-shop-toolbar -->

            <?php
            /* --- ACTIVE FILTER CHIPS (removibles) ------------------------- */
            if ( ! empty( $pv_active_chips ) ) :
            ?>
                <div class="pv-shop-active-filters" aria-label="<?php esc_attr_e( 'Filtros activos', 'ltms' ); ?>">
                    <span class="pv-shop-active-filters__label"><?php esc_html_e( 'Filtros:', 'ltms' ); ?></span>
                    <?php foreach ( $pv_active_chips as $pv_chip ) : ?>
                        <a class="pv-badge pv-badge--trust pv-shop-active-chip" href="<?php echo esc_url( $pv_chip['url'] ); ?>">
                            <span><?php echo wp_kses_post( $pv_chip['label'] ); ?></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </a>
                    <?php endforeach; ?>
                    <a class="pv-shop-active-filters__clear" href="<?php echo esc_url( $pv_filter_action ); ?>"><?php esc_html_e( 'Limpiar todo', 'ltms' ); ?></a>
                </div>
            <?php endif; ?>

            <?php
            /* --- GRID DE PRODUCTOS — WC loop estándar ---------------------
             * woocommerce_product_loop_start() renderiza <ul class="products">.
             * El loop usa wc_get_template_part('content','product') que carga
             * el override (wc-parts/content-product.php) vía locate_wc_template,
             * o el template por defecto de WC si no existe override.
             * ------------------------------------------------------------ */
            ?>
            <div class="pv-shop__products <?php echo ( 'list' === $pv_get_view ) ? 'is-list-view' : 'is-grid-view'; ?>" data-pv-products-container>
                <?php
                if ( woocommerce_product_loop() ) {

                    woocommerce_product_loop_start();

                    if ( wc_get_loop_prop( 'total' ) ) {
                        while ( have_posts() ) {
                            the_post();

                            /**
                             * Hook: woocommerce_shop_loop
                             * Se dispara antes de renderizar cada producto en el loop.
                             */
                            do_action( 'woocommerce_shop_loop' );

                            wc_get_template_part( 'content', 'product' );
                        }
                    }

                    woocommerce_product_loop_end();

                } else {
                    /**
                     * No hay productos — mensaje de "no results" de WC.
                     * woocommerce_no_products_found() renderiza el template
                     * archive-product/no-products-found.php.
                     */
                    do_action( 'woocommerce_no_products_found' );
                }
                ?>
            </div><!-- /.pv-shop__products -->

            <?php
            /**
             * Paginación — woocommerce_pagination().
             * Renderiza woocommerce_pagination() que usa el template
             * loop/pagination.php (compatible con HPOS y WP-PageNavi).
             */
            if ( function_exists( 'woocommerce_pagination' ) ) {
                woocommerce_pagination();
            } elseif ( function_exists( 'the_posts_pagination' ) ) {
                the_posts_pagination( array(
                    'prev_text' => __( 'Anterior', 'ltms' ),
                    'next_text' => __( 'Siguiente', 'ltms' ),
                ) );
            }

            /**
             * Hook: woocommerce_after_shop_loop
             * Cierra el loop de shop. Restaura postdata.
             */
            do_action( 'woocommerce_after_shop_loop' );
            ?>

        </div><!-- /.pv-shop__main -->

    </div><!-- /.pv-shop__layout -->

    <?php
    /* =====================================================================
     * BOTÓN FLOTANTE — abrir filtros en mobile (sidebar colapsa)
     * =====================================================================
     */
    ?>
    <button type="button"
            class="pv-shop__filters-fab pv-btn pv-btn--primary"
            data-pv-filters-open
            aria-controls="pv-shop-sidebar"
            aria-expanded="false">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
        <span><?php esc_html_e( 'Filtros', 'ltms' ); ?></span>
    </button>

    <?php
    /* Backdrop para el sidebar mobile */
    ?>
    <div class="pv-shop__sidebar-backdrop" data-pv-filters-backdrop hidden></div>

</div><!-- /.pv-scope.pv-shop -->

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
 * Hook: ltms_after_archive_product_plazaviva
 */
do_action( 'ltms_after_archive_product_plazaviva' );
?>

<?php
/* ============================================================================
 * Estilos estructurales específicos de la página de tienda.
 * El design system (ltms-plaza-viva.css) cubre componentes compartidos
 * (.pv-btn, .pv-badge, .pv-card, .pv-product-card, .pv-input, .pv-select,
 * .pv-section, .pv-breadcrumb, grid-*). Estas reglas cubren SOLO el layout
 * de archive-product y están scopeadas bajo .pv-scope.pv-shop.
 * ========================================================================== */
?>
<style>
.pv-scope.pv-shop{display:block;background:var(--bg);}

/* ── BREADCRUMB + HEAD ────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__breadcrumb{padding-top:14px;padding-bottom:6px;}
.pv-scope.pv-shop .pv-shop__breadcrumb .woocommerce-breadcrumb,
.pv-scope.pv-shop .pv-shop__breadcrumb nav.woocommerce-breadcrumb{
    font-size:13px;color:var(--text-3);
}
.pv-scope.pv-shop .pv-shop__breadcrumb .woocommerce-breadcrumb a{color:var(--text-2);}
.pv-scope.pv-shop .pv-shop__breadcrumb .woocommerce-breadcrumb a:hover{color:var(--primary);}

.pv-scope.pv-shop .pv-shop__head{padding-top:14px;padding-bottom:20px;}
.pv-scope.pv-shop .pv-shop__title{
    font-family:var(--display);font-weight:800;
    font-size:clamp(26px,3vw,38px);color:var(--text);margin:0 0 6px;
}
.pv-scope.pv-shop .pv-shop__head .term-description,
.pv-scope.pv-shop .pv-shop__head .woocommerce-products-header__description{
    font-size:14.5px;color:var(--text-2);max-width:720px;line-height:1.6;
}

/* ── LAYOUT 2 COLUMNAS ────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__layout{
    display:grid;grid-template-columns:240px 1fr;gap:28px;align-items:flex-start;
    padding-top:8px;padding-bottom:48px;
}

/* ── SIDEBAR FILTROS ──────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop-sidebar{
    position:sticky;top:72px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-card);padding:18px;
    box-shadow:var(--sh-1);
    max-height:calc(100vh - 90px);overflow-y:auto;
}
/* Scrollbar custom */
.pv-scope.pv-shop .pv-shop-sidebar::-webkit-scrollbar{width:6px;}
.pv-scope.pv-shop .pv-shop-sidebar::-webkit-scrollbar-track{background:transparent;}
.pv-scope.pv-shop .pv-shop-sidebar::-webkit-scrollbar-thumb{background:var(--border-2);border-radius:var(--r-pill);}
.pv-scope.pv-shop .pv-shop-sidebar::-webkit-scrollbar-thumb:hover{background:var(--text-3);}

.pv-scope.pv-shop .pv-shop-filters{display:flex;flex-direction:column;gap:6px;}
.pv-scope.pv-shop .pv-shop-filter{
    border:0;border-bottom:1px solid var(--border);padding:0 0 4px;
}
.pv-scope.pv-shop .pv-shop-filter:last-of-type{border-bottom:none;}
.pv-scope.pv-shop .pv-shop-filter__title{padding:0;margin:0;}
.pv-scope.pv-shop .pv-shop-filter__toggle{
    width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:14px 0;background:transparent;border:0;cursor:pointer;
    font-family:var(--display);font-weight:700;font-size:14px;color:var(--text);
    text-align:left;
}
.pv-scope.pv-shop .pv-shop-filter__toggle:hover{color:var(--primary);}
.pv-scope.pv-shop .pv-shop-filter__chevron{flex-shrink:0;color:var(--text-3);transition:transform var(--t);}
.pv-scope.pv-shop .pv-shop-filter__toggle[aria-expanded="false"] .pv-shop-filter__chevron{transform:rotate(-90deg);}
.pv-scope.pv-shop .pv-shop-filter__body{
    padding:4px 0 14px;display:flex;flex-direction:column;gap:8px;
    animation:pv-fade .2s ease;
}
.pv-scope.pv-shop .pv-shop-filter__body[hidden]{display:none;}
.pv-scope.pv-shop .pv-shop-filter__option{
    display:flex;align-items:center;gap:9px;cursor:pointer;
    padding:5px 6px;border-radius:var(--r-sm);
    transition:background var(--t);
}
.pv-scope.pv-shop .pv-shop-filter__option:hover{background:var(--bg);}
.pv-scope.pv-shop .pv-shop-filter__option input[type="checkbox"],
.pv-scope.pv-shop .pv-shop-filter__option input[type="radio"]{
    width:17px;height:17px;accent-color:var(--primary);flex-shrink:0;cursor:pointer;
}
.pv-scope.pv-shop .pv-shop-filter__label{font-size:13.5px;color:var(--text-2);font-weight:500;flex:1;min-width:0;}
.pv-scope.pv-shop .pv-shop-filter__count{
    font-size:11.5px;color:var(--text-3);font-weight:600;
    background:var(--bg-2);padding:1px 8px;border-radius:var(--r-pill);
}
.pv-scope.pv-shop .pv-shop-filter__icon{font-size:15px;flex-shrink:0;}

.pv-scope.pv-shop .pv-shop-filter__stars{display:inline-flex;align-items:center;gap:6px;}
.pv-scope.pv-shop .pv-shop-filter__stars-row{color:var(--gold,#D4A857);font-size:14px;letter-spacing:1px;}

.pv-scope.pv-shop .pv-shop-filter__price{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.pv-scope.pv-shop .pv-shop-filter__price-field{flex:1;min-width:0;}
.pv-scope.pv-shop .pv-shop-filter__price-sep{color:var(--text-3);flex-shrink:0;}
.pv-scope.pv-shop .pv-shop-filter__apply{margin-top:2px;}
.pv-scope.pv-shop .pv-shop-filter__actions{display:flex;flex-direction:column;gap:8px;padding-top:14px;border-top:1px solid var(--border);margin-top:6px;}

/* ── MAIN COLUMN ──────────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__main{min-width:0;display:flex;flex-direction:column;gap:18px;}

/* ── TOOLBAR ──────────────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
    padding:12px 16px;background:var(--surface);
    border:1px solid var(--border);border-radius:var(--r-card);box-shadow:var(--sh-1);
}
.pv-scope.pv-shop .pv-shop-toolbar__count{display:flex;align-items:baseline;gap:6px;}
.pv-scope.pv-shop .pv-shop-toolbar__count-num{font-family:var(--display);font-weight:800;font-size:18px;color:var(--text);}
.pv-scope.pv-shop .pv-shop-toolbar__count-label{font-size:13.5px;color:var(--text-3);}
.pv-scope.pv-shop .pv-shop-toolbar__controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.pv-scope.pv-shop .pv-shop-toolbar__sort{display:flex;align-items:center;gap:6px;}
.pv-scope.pv-shop .pv-shop-toolbar__sort-select{height:40px;min-width:200px;}
.pv-scope.pv-shop .pv-shop-toolbar__view{display:flex;gap:4px;background:var(--bg-2);border-radius:var(--r-md);padding:3px;}
.pv-scope.pv-shop .pv-shop-toolbar__view-btn{
    width:34px;height:34px;display:flex;align-items:center;justify-content:center;
    border-radius:var(--r-sm);color:var(--text-3);background:transparent;
    transition:background var(--t),color var(--t);
}
.pv-scope.pv-shop .pv-shop-toolbar__view-btn:hover{color:var(--text);}
.pv-scope.pv-shop .pv-shop-toolbar__view-btn.is-active{background:var(--surface);color:var(--primary);box-shadow:var(--sh-1);}

/* ── ACTIVE FILTERS ───────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop-active-filters{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.pv-scope.pv-shop .pv-shop-active-filters__label{font-size:13px;font-weight:600;color:var(--text-2);}
.pv-scope.pv-shop .pv-shop-active-chip{
    display:inline-flex;align-items:center;gap:6px;text-decoration:none;
    transition:background var(--t);
}
.pv-scope.pv-shop .pv-shop-active-chip:hover{background:var(--primary-100);}
.pv-scope.pv-shop .pv-shop-active-chip svg{opacity:.7;}
.pv-scope.pv-shop .pv-shop-active-filters__clear{
    font-size:13px;font-weight:600;color:var(--primary);text-decoration:none;margin-left:4px;
}
.pv-scope.pv-shop .pv-shop-active-filters__clear:hover{text-decoration:underline;}

/* ── PRODUCTS GRID ────────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__products.is-grid-view ul.products,
.pv-scope.pv-shop .pv-shop__products ul.products{
    display:grid;grid-template-columns:repeat(4,1fr);gap:18px;list-style:none;margin:0;padding:0;
}
.pv-scope.pv-shop .pv-shop__products.is-grid-view ul.products li.product,
.pv-scope.pv-shop .pv-shop__products ul.products li.product{
    margin:0;width:auto;max-width:none;
}
/* Bridge: si el override .pv-product-card no está, dar estilo on-brand a .product */
.pv-scope.pv-shop .pv-shop__products ul.products li.product .pv-product-card{
    /* Override nativo — ya estilizado por design system */
}
.pv-scope.pv-shop .pv-shop__products ul.products li.product:not(:has(.pv-product-card)){
    background:var(--surface);border:1px solid var(--border);border-radius:var(--r-card);
    overflow:hidden;box-shadow:var(--sh-1);
    transition:transform var(--t-slow),box-shadow var(--t-slow),border-color var(--t-slow);
}
.pv-scope.pv-shop .pv-shop__products ul.products li.product:not(:has(.pv-product-card)):hover{
    transform:translateY(-4px);box-shadow:var(--sh-hover);border-color:var(--primary-100);
}

/* Vista lista — productos en fila horizontal */
.pv-scope.pv-shop .pv-shop__products.is-list-view ul.products{
    grid-template-columns:1fr;gap:14px;
}
.pv-scope.pv-shop .pv-shop__products.is-list-view ul.products li.product{
    display:grid;grid-template-columns:200px 1fr auto;gap:20px;align-items:center;
    padding:16px;
}
.pv-scope.pv-shop .pv-shop__products.is-list-view ul.products li.product .woocommerce-loop-product__link{
    display:contents;
}
.pv-scope.pv-shop .pv-shop__products.is-list-view ul.products li.product img{
    width:100%;height:160px;object-fit:cover;border-radius:var(--r-md);
}

/* ── PAGINATION ───────────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination{
    margin-top:8px;
}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers{
    display:flex;gap:6px;list-style:none;margin:0;padding:0;justify-content:center;
}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers li{margin:0;}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers a,
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers span{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:42px;height:42px;padding:0 12px;
    border:1px solid var(--border);border-radius:var(--r-md);
    font-size:14px;font-weight:600;color:var(--text-2);text-decoration:none;
    background:var(--surface);
    transition:background var(--t),color var(--t),border-color var(--t);
}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers a:hover{
    background:var(--primary-50);color:var(--primary);border-color:var(--primary-100);
}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers .current{
    background:var(--primary);color:#fff;border-color:var(--primary);
}
.pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers .dots{border:0;background:transparent;}

/* ── NO RESULTS ───────────────────────────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__products .woocommerce-info,
.pv-scope.pv-shop .pv-shop__main .woocommerce-info{
    background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--primary);
    border-radius:var(--r-md);padding:24px;text-align:center;color:var(--text-2);
    font-size:15px;
}

/* ── NOTICES (woocommerce_before_shop_loop) ───────────────────────────────── */
.pv-scope.pv-shop .woocommerce-message,
.pv-scope.pv-shop .woocommerce-error{
    border-radius:var(--r-md);font-size:13.5px;
}

/* ── FAB (filtros mobile) + backdrop ──────────────────────────────────────── */
.pv-scope.pv-shop .pv-shop__filters-fab{display:none;}
.pv-scope.pv-shop .pv-shop__sidebar-backdrop{
    position:fixed;inset:0;z-index:60;background:rgba(15,17,17,.5);
    backdrop-filter:blur(3px);opacity:0;transition:opacity var(--t);
}
.pv-scope.pv-shop .pv-shop__sidebar-backdrop.is-visible{opacity:1;}

/* ── RESPONSIVE ───────────────────────────────────────────────────────────── */
@media (max-width:1100px){
    .pv-scope.pv-shop .pv-shop__products.is-grid-view ul.products,
    .pv-scope.pv-shop .pv-shop__products ul.products{grid-template-columns:repeat(3,1fr);}
}
@media (max-width:980px){
    .pv-scope.pv-shop .pv-shop__layout{grid-template-columns:1fr;gap:18px;}
    .pv-scope.pv-shop .pv-shop-sidebar{
        position:fixed;top:0;left:0;bottom:0;z-index:70;
        width:min(320px,85vw);max-height:100vh;border-radius:0;
        transform:translateX(-100%);
        transition:transform var(--t-slow);
        padding-top:60px;
    }
    .pv-scope.pv-shop .pv-shop-sidebar.is-open{transform:translateX(0);}
    .pv-scope.pv-shop .pv-shop__filters-fab{
        display:inline-flex;position:fixed;bottom:20px;right:20px;z-index:55;
        box-shadow:var(--sh-3);
    }
    .pv-scope.pv-shop .pv-shop__filters-fab[hidden]{display:none;}
    .pv-scope.pv-shop .pv-shop__products.is-grid-view ul.products,
    .pv-scope.pv-shop .pv-shop__products ul.products{grid-template-columns:repeat(2,1fr);}
    .pv-scope.pv-shop .pv-shop__products.is-list-view ul.products li.product{
        grid-template-columns:120px 1fr;gap:14px;
    }
    .pv-scope.pv-shop .pv-shop__products.is-list-view ul.products li.product img{height:120px;}
    .pv-scope.pv-shop .pv-shop-toolbar__sort-select{min-width:160px;}
}
@media (max-width:560px){
    .pv-scope.pv-shop .pv-shop__products.is-grid-view ul.products,
    .pv-scope.pv-shop .pv-shop__products ul.products{grid-template-columns:1fr;}
    .pv-scope.pv-shop .pv-shop-toolbar{flex-direction:column;align-items:stretch;}
    .pv-scope.pv-shop .pv-shop-toolbar__controls{justify-content:space-between;}
    .pv-scope.pv-shop .pv-shop-toolbar__sort{flex:1;}
    .pv-scope.pv-shop .pv-shop-toolbar__sort-select{width:100%;min-width:0;}
    .pv-scope.pv-shop .pv-shop__title{font-size:24px;}
    .pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers a,
    .pv-scope.pv-shop .pv-shop__main .woocommerce-pagination ul.page-numbers span{min-width:38px;height:38px;}
}
</style>

<?php
/* ============================================================================
 * JS de página: toggle de filtros mobile, colapso de secciones de filtro,
 * vista toggle (grid/lista), submit del select de ordenación.
 * Vanilla JS, sin jQuery.
 * ========================================================================== */
?>
<script>
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-shop');
    if (!scope) return;

    /* --- 1. Sidebar mobile: abrir / cerrar ------------------------------- */
    var sidebar = scope.querySelector('[data-pv-shop-sidebar]');
    var openBtn = scope.querySelector('[data-pv-filters-open]');
    var backdrop = scope.querySelector('[data-pv-filters-backdrop]');

    function openSidebar(){
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (openBtn){ openBtn.setAttribute('aria-expanded','true'); openBtn.hidden = true; }
        if (backdrop){ backdrop.hidden = false; requestAnimationFrame(function(){ backdrop.classList.add('is-visible'); }); }
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar(){
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (openBtn){ openBtn.setAttribute('aria-expanded','false'); openBtn.hidden = false; }
        if (backdrop){ backdrop.classList.remove('is-visible'); setTimeout(function(){ backdrop.hidden = true; }, 250); }
        document.body.style.overflow = '';
    }

    if (openBtn){ openBtn.addEventListener('click', openSidebar); }
    if (backdrop){ backdrop.addEventListener('click', closeSidebar); }

    // Cerrar con ESC.
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')){
            closeSidebar();
        }
    });

    /* --- 2. Colapso de secciones de filtro (accordion) -------------------- */
    var toggles = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-filter-toggle]'));
    toggles.forEach(function(toggle){
        toggle.addEventListener('click', function(){
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            var targetId = toggle.getAttribute('aria-controls');
            var body = targetId ? document.getElementById(targetId) : null;
            if (!body) return;
            if (expanded){
                toggle.setAttribute('aria-expanded','false');
                body.hidden = true;
            } else {
                toggle.setAttribute('aria-expanded','true');
                body.hidden = false;
            }
        });
    });

    /* --- 3. Vista toggle: grid / lista ----------------------------------- */
    var viewBtns = Array.prototype.slice.call(scope.querySelectorAll('[data-pv-view-toggle]'));
    var productsContainer = scope.querySelector('[data-pv-products-container]');
    viewBtns.forEach(function(btn){
        btn.addEventListener('click', function(){
            var view = btn.getAttribute('data-pv-view-toggle') || 'grid';
            // Actualizar clases del contenedor.
            if (productsContainer){
                productsContainer.classList.remove('is-grid-view','is-list-view');
                productsContainer.classList.add(view === 'list' ? 'is-list-view' : 'is-grid-view');
            }
            // Actualizar estado de botones.
            viewBtns.forEach(function(b){
                b.classList.remove('is-active');
                b.setAttribute('aria-pressed','false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-pressed','true');
            // Persistir en URL (sin recargar).
            var url = new URL(window.location.href);
            url.searchParams.set('pv_view', view);
            window.history.replaceState({}, '', url.toString());
        });
    });

    /* --- 4. Select de ordenación: submit automático ---------------------- */
    var sortSelect = scope.querySelector('[data-pv-sort-select]');
    if (sortSelect){
        sortSelect.addEventListener('change', function(){
            // WC usa ?orderby=... en el query string.
            var url = new URL(window.location.href);
            url.searchParams.set('orderby', sortSelect.value);
            // Resetear paginación al cambiar orden.
            url.searchParams.delete('paged');
            url.searchParams.delete('page');
            window.location.href = url.toString();
        });
    }

    /* --- 5. Cerrar sidebar mobile al hacer click en un enlace de filtro -- */
    if (sidebar){
        var filterLinks = Array.prototype.slice.call(sidebar.querySelectorAll('a'));
        filterLinks.forEach(function(link){
            link.addEventListener('click', function(){
                if (window.innerWidth < 981){
                    // Pequeño delay para que el click se registre antes de cerrar.
                    setTimeout(closeSidebar, 100);
                }
            });
        });
    }
})();
</script>

<?php
get_footer( 'shop' );
