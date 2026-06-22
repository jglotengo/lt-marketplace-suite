<?php
/**
 * LTMS Vendor Storefront — Vitrina pública del vendedor
 *
 * Sirve /vendedor/{slug} con perfil + grid de productos.
 *
 * v2.8.1: la ruta cambió de /tienda/{slug}/ a /vendedor/{slug}/ porque
 * "tienda" ya es el slug base de la tienda de WooCommerce (configuración
 * en español) — esa regla de WooCommerce capturaba la URL antes que la
 * nuestra, sin importar la prioridad 'top' del add_rewrite_rule().
 *
 * También se hizo robusto el lookup de vendedor: muchos vendedores legacy
 * tienen user_login con caracteres no aptos para URL (ej. "marco@dominio.com",
 * "Nombre Con Espacios"), así que ahora:
 *   1. Se busca primero por meta ltms_store_slug (la fuente de verdad).
 *   2. Si no existe, se intenta login exacto (compatibilidad histórica).
 *   3. Si tampoco, se compara el slug sanitizado del nombre de tienda/login
 *      contra vendedores sin slug guardado, y se persiste el match — así
 *      cada vendedor termina con un slug estable después de su primera visita.
 *
 * deploy/ltms-backfill-store-slugs.php hace este mismo trabajo de una sola
 * vez para todos los vendedores existentes, sin depender de que alguien
 * visite su URL primero.
 *
 * @package LTMS
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Vendor_Storefront {

    const QUERY_VAR = 'ltms_vendor_slug';

    public static function init(): void {
        // IMPORTANTE: add_rewrite_rule() usa $wp_rewrite internamente, que
        // WordPress crea recién en el hook 'init' — no antes. El kernel
        // ejecuta este init() en un punto previo (boot_frontend()), así que
        // llamarlo aquí directo causa "Call to a member function add_rule()
        // on null". Por eso se difiere a register_rewrite_rule(), igual
        // patrón que ya usa LTMS_Geo_Detector::register_city_rewrite_rules().
        add_action( 'init', [ __CLASS__, 'register_rewrite_rule' ] );
        add_filter( 'query_vars', static function( array $vars ): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        } );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
        add_filter( 'document_title_parts', [ __CLASS__, 'filter_title' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function register_rewrite_rule(): void {
        // /vendedor/{slug}/ — singular, distinto de:
        //   - /vendedores/{ciudad}/ (geo-detección, LTMS_Geo_Detector)
        //   - /tienda/ (slug base del shop de WooCommerce en este sitio)
        add_rewrite_rule( '^vendedor/([\w-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
    }

    public static function maybe_render(): void {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug ) return;

        $vendor = self::get_vendor_by_slug( $slug );
        if ( ! $vendor ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            include get_404_template();
            exit;
        }

        self::render( $vendor );
        exit;
    }

    /**
     * Resuelve un vendedor a partir del slug de la URL.
     *
     * Tres niveles de búsqueda, en orden de costo creciente — ver docblock
     * de la clase para el razonamiento completo.
     */
    private static function get_vendor_by_slug( string $raw_slug ): ?object {
        $slug = sanitize_title( $raw_slug );
        if ( '' === $slug ) return null;

        // Nivel 1 — fuente de verdad: meta ltms_store_slug.
        $users = get_users( [
            'meta_key'   => 'ltms_store_slug',
            'meta_value' => $slug,
            'number'     => 1,
            'role'       => 'ltms_vendor',
        ] );

        // Nivel 2 — compatibilidad histórica: login exacto (solo funciona
        // si el login ya era un slug válido, ej. "tiendaejemplo").
        if ( ! $users ) {
            $user = get_user_by( 'login', $slug );
            if ( $user && in_array( 'ltms_vendor', (array) $user->roles, true ) ) {
                $users = [ $user ];
            }
        }

        // Nivel 3 — vendedores legacy sin slug guardado: comparar el slug
        // sanitizado de su nombre de tienda o login. Acotado a 300 filas
        // para no degradar rendimiento; el backfill cubre el resto de una vez.
        if ( ! $users ) {
            $legacy = get_users( [ 'role' => 'ltms_vendor', 'number' => 300 ] );
            foreach ( $legacy as $v ) {
                if ( get_user_meta( $v->ID, 'ltms_store_slug', true ) ) continue;

                $store_name = get_user_meta( $v->ID, 'ltms_store_name', true ) ?: $v->display_name ?: $v->user_login;
                $candidates = [ sanitize_title( $store_name ), sanitize_title( $v->user_login ) ];

                if ( in_array( $slug, $candidates, true ) ) {
                    update_user_meta( $v->ID, 'ltms_store_slug', $slug ); // se estabiliza para la próxima visita
                    $users = [ $v ];
                    break;
                }
            }
        }

        if ( ! $users ) return null;

        $u  = $users[0];
        $id = $u->ID;

        return (object) [
            'id'          => $id,
            'name'        => get_user_meta( $id, 'ltms_store_name', true ) ?: $u->display_name,
            'description' => get_user_meta( $id, 'ltms_store_description', true ),
            'city'        => get_user_meta( $id, 'ltms_store_city', true ),
            'logo'        => get_user_meta( $id, 'ltms_store_logo', true ),
            'banner'      => get_user_meta( $id, 'ltms_store_banner', true ),
            'rnt'         => get_user_meta( $id, 'ltms_rnt_number', true ),
            'kyc_status'  => get_user_meta( $id, 'ltms_kyc_status', true ),
            'slug'        => $slug,
        ];
    }

    /**
     * Genera un slug único para un vendedor a partir del nombre de su tienda.
     * Usado tanto en el registro de vendedores nuevos como en el backfill
     * de vendedores existentes (deploy/ltms-backfill-store-slugs.php).
     */
    public static function generate_unique_slug( string $store_name, int $exclude_user_id = 0 ): string {
        $base = sanitize_title( $store_name );
        if ( '' === $base ) {
            $base = 'tienda-' . ( $exclude_user_id ?: wp_rand( 1000, 9999 ) );
        }

        $slug = $base;
        $i    = 2;
        while ( self::slug_taken( $slug, $exclude_user_id ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function slug_taken( string $slug, int $exclude_user_id ): bool {
        $args = [
            'meta_key'   => 'ltms_store_slug',
            'meta_value' => $slug,
            'number'     => 1,
            'fields'     => 'ID',
        ];
        if ( $exclude_user_id ) {
            $args['exclude'] = [ $exclude_user_id ];
        }
        return ! empty( get_users( $args ) );
    }

    public static function filter_title( array $parts ): array {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug ) return $parts;
        $vendor = self::get_vendor_by_slug( $slug );
        if ( $vendor ) {
            $parts['title'] = esc_html( $vendor->name ) . ' — Lo Tengo';
        }
        return $parts;
    }

    public static function enqueue_assets(): void {
        if ( ! get_query_var( self::QUERY_VAR ) ) return;
        wp_enqueue_style(
            'ltms-storefront',
            LTMS_ASSETS_URL . 'css/ltms-storefront.css',
            [],
            LTMS_VERSION
        );
    }

    private static function render( object $vendor ): void {
        // Productos del vendedor
        $paged    = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
        $per_page = 12;
        $cat_slug = sanitize_title( $_GET['cat'] ?? '' );
        $orderby  = in_array( $_GET['order'] ?? '', [ 'price', 'price-desc', 'date' ], true )
                    ? sanitize_text_field( $_GET['order'] )
                    : 'date';

        $tax_query = [];
        if ( $cat_slug ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $cat_slug,
            ];
        }

        $wc_order = match ( $orderby ) {
            'price'      => [ 'orderby' => 'meta_value_num', 'order' => 'ASC',  'meta_key' => '_price' ],
            'price-desc' => [ 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_key' => '_price' ],
            default      => [ 'orderby' => 'date',           'order' => 'DESC' ],
        };

        $args = array_merge( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'author'         => $vendor->id,
            'tax_query'      => $tax_query,
        ], $wc_order );

        $query = new WP_Query( $args );
        $total = $query->found_posts;
        $pages = $query->max_num_pages;

        // Categorías del vendedor (para tabs)
        $vendor_cats = self::get_vendor_categories( $vendor->id );

        // Ruta base para paginación/filtros
        $base_url = home_url( '/vendedor/' . $vendor->slug . '/' );

        // Renderizar HTML
        get_header();
        ?>
        <div class="ltms-storefront" itemscope itemtype="https://schema.org/Store">
            <meta itemprop="name" content="<?php echo esc_attr( $vendor->name ); ?>">

            <!-- BANNER -->
            <div class="ltms-sf-banner" style="<?php
                if ( $vendor->banner ) {
                    echo 'background-image:url(' . esc_url( $vendor->banner ) . ');';
                }
            ?>">
                <div class="ltms-sf-banner-overlay">
                    <div class="ltms-sf-header">
                        <?php if ( $vendor->logo ) : ?>
                            <img class="ltms-sf-logo" src="<?php echo esc_url( $vendor->logo ); ?>"
                                 alt="<?php echo esc_attr( $vendor->name ); ?>" loading="lazy">
                        <?php else : ?>
                            <div class="ltms-sf-logo ltms-sf-logo-placeholder">
                                <?php echo esc_html( mb_strtoupper( mb_substr( $vendor->name, 0, 1 ) ) ); ?>
                            </div>
                        <?php endif; ?>

                        <div class="ltms-sf-meta">
                            <h1 class="ltms-sf-name" itemprop="name">
                                <?php echo esc_html( $vendor->name ); ?>
                                <?php if ( 'approved' === $vendor->kyc_status ) : ?>
                                    <span class="ltms-sf-verified" title="Vendedor verificado">✓</span>
                                <?php endif; ?>
                            </h1>

                            <div class="ltms-sf-meta-row">
                                <?php if ( $vendor->city ) : ?>
                                    <span class="ltms-sf-city">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?php echo esc_html( $vendor->city ); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ( $vendor->rnt ) : ?>
                                    <span class="ltms-sf-rnt">RNT <?php echo esc_html( $vendor->rnt ); ?></span>
                                <?php endif; ?>

                                <span class="ltms-sf-count">
                                    <?php echo esc_html( number_format_i18n( $total ) ); ?>
                                    <?php echo $total === 1 ? 'producto' : 'productos'; ?>
                                </span>
                            </div>

                            <?php if ( $vendor->description ) : ?>
                                <p class="ltms-sf-description" itemprop="description">
                                    <?php echo esc_html( wp_trim_words( $vendor->description, 25 ) ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div><!-- .ltms-sf-banner -->

            <!-- FILTROS -->
            <div class="ltms-sf-toolbar">
                <div class="ltms-sf-cats">
                    <a href="<?php echo esc_url( $base_url ); ?>"
                       class="ltms-sf-cat-tab <?php echo ! $cat_slug ? 'active' : ''; ?>">
                        Todos
                    </a>
                    <?php foreach ( $vendor_cats as $cat ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'cat', $cat->slug, $base_url ) ); ?>"
                           class="ltms-sf-cat-tab <?php echo $cat_slug === $cat->slug ? 'active' : ''; ?>">
                            <?php echo esc_html( $cat->name ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="ltms-sf-order">
                    <select onchange="location.href=this.value" aria-label="Ordenar por">
                        <?php
                        $order_options = [
                            'date'       => 'Más recientes',
                            'price'      => 'Precio: menor a mayor',
                            'price-desc' => 'Precio: mayor a menor',
                        ];
                        foreach ( $order_options as $val => $label ) :
                            $url = add_query_arg( [ 'order' => $val, 'cat' => $cat_slug ?: null ], $base_url );
                        ?>
                            <option value="<?php echo esc_url( $url ); ?>"
                                <?php selected( $orderby, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div><!-- .ltms-sf-toolbar -->

            <!-- GRID DE PRODUCTOS -->
            <div class="ltms-sf-products">
                <?php if ( $query->have_posts() ) : ?>
                    <div class="ltms-sf-grid">
                        <?php while ( $query->have_posts() ) : $query->the_post();
                            global $product;
                            $product = wc_get_product( get_the_ID() );
                            if ( ! $product ) continue;
                        ?>
                            <article class="ltms-sf-card" itemscope itemtype="https://schema.org/Product">
                                <a href="<?php echo esc_url( get_permalink() ); ?>" class="ltms-sf-card-link">
                                    <div class="ltms-sf-card-img">
                                        <?php if ( has_post_thumbnail() ) : ?>
                                            <?php the_post_thumbnail( 'woocommerce_thumbnail', [
                                                'itemprop' => 'image',
                                                'loading'  => 'lazy',
                                                'alt'      => esc_attr( get_the_title() ),
                                            ] ); ?>
                                        <?php else : ?>
                                            <div class="ltms-sf-card-no-img">Sin imagen</div>
                                        <?php endif; ?>

                                        <?php if ( $product->is_on_sale() ) : ?>
                                            <span class="ltms-sf-badge-sale">OFERTA</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ltms-sf-card-body">
                                        <p class="ltms-sf-card-cat">
                                            <?php
                                            $cats = wp_get_post_terms( get_the_ID(), 'product_cat', [ 'number' => 1 ] );
                                            echo $cats ? esc_html( $cats[0]->name ) : '';
                                            ?>
                                        </p>
                                        <h2 class="ltms-sf-card-name" itemprop="name">
                                            <?php echo esc_html( get_the_title() ); ?>
                                        </h2>
                                        <div class="ltms-sf-card-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                                            <meta itemprop="priceCurrency" content="COP">
                                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                                        </div>
                                    </div>
                                </a>
                            </article>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </div><!-- .ltms-sf-grid -->

                    <?php if ( $pages > 1 ) : ?>
                        <nav class="ltms-sf-pagination" aria-label="Paginación">
                            <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [ 'pg' => $p, 'cat' => $cat_slug ?: null, 'order' => $orderby !== 'date' ? $orderby : null ], $base_url ) ); ?>"
                                   class="ltms-sf-page-btn <?php echo $p === $paged ? 'active' : ''; ?>"
                                   aria-label="Página <?php echo esc_attr( $p ); ?>"
                                   <?php echo $p === $paged ? 'aria-current="page"' : ''; ?>>
                                    <?php echo esc_html( $p ); ?>
                                </a>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>

                <?php else : ?>
                    <div class="ltms-sf-empty">
                        <p>Esta tienda aún no tiene productos publicados.</p>
                    </div>
                <?php endif; ?>
            </div><!-- .ltms-sf-products -->
        </div><!-- .ltms-storefront -->
        <?php
        get_footer();
    }

    private static function get_vendor_categories( int $vendor_id ): array {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.term_id, t.name, t.slug
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
               AND p.post_author = %d
             ORDER BY t.name ASC",
            $vendor_id
        ) );
        return is_array( $results ) ? $results : [];
    }
}
