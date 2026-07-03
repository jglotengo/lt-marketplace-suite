<?php
/**
 * LTMS SEO Enhanced — Fundamentos SEO + AEO + RSS feeds + Schema.org ampliado.
 *
 * v2.9.22 — Implementa Sprint 1 de la estrategia de visibilidad:
 *   - 7 feeds RSS segmentados (productos por ciudad, categoría, vendor, etc.)
 *   - Schema.org comprehensivo (BreadcrumbList, FAQPage, LocalBusiness,
 *     AggregateRating, ItemList, WebSite+SearchAction, speakable)
 *   - llms.txt para AEO (optimización para LLMs)
 *   - Sitemap index con sub-sitemaps segmentados
 *   - robots.txt optimizado para marketplace
 *   - Core Web Vitals hints (preload, dns-prefetch)
 *
 * Estrategia: marketplace SSR sólido + 7 capas RSS + AEO con llms.txt +
 * Schema.org comprehensivo. RSS como complemento de distribución, no como
 * reemplazo del marketplace.
 *
 * @package LTMS
 * @version 2.9.22
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_SEO_Enhanced {

    /**
     * Ciudades con cobertura (para city feeds + city pages).
     * 15 CO + 8 MX.
     */
    public const CITIES_CO = [
        'bogota', 'medellin', 'cali', 'barranquilla', 'cartagena',
        'cucuta', 'bucaramanga', 'pereira', 'santa_marta', 'ibague',
        'villavicencio', 'manizales', 'armenia', 'neiva', 'monteria',
    ];

    public const CITIES_MX = [
        'cdmx', 'guadalajara', 'monterrey', 'puebla', 'tijuana',
        'merida', 'leon', 'queretaro',
    ];

    /**
     * FAQ global del marketplace (FAQPage schema + AEO).
     * Respuestas concisas (40-60 palabras) para featured snippets.
     */
    public const GLOBAL_FAQ = [
        [
            'q' => '¿Cómo comprar en Lo Tengo Colombia?',
            'a' => 'Para comprar: 1) Busca el producto o navega por categorías. 2) Selecciona el producto y añádelo al carrito. 3) Completa el checkout con tarjeta, PSE, Nequi o efectivo. 4) Recibe tu pedido en 24-72 horas con seguimiento GPS. Vendedores verificados con KYC.',
        ],
        [
            'q' => '¿Cuánto cuesta el envío en Colombia?',
            'a' => 'El envío varía según ciudad y peso: Bogotá desde $5.000 COP, otras ciudades desde $8.000 COP. Envío gratis en compras superiores a $150.000 COP. Cobertura nacional con Deprisa, Heka y Aveonline. Pago contraentrega disponible en ciudades principales.',
        ],
        [
            'q' => '¿Qué métodos de pago aceptan?',
            'a' => 'Aceptamos: tarjetas crédito/débito (Visa, Mastercard, Amex) vía Openpay y Stripe; PSE (Colombia); Nequi y Daviplata; OXXO (México); pago contraentrega en ciudades seleccionadas. Todos los pagos son cifrados con AES-256 y tokenización PCI DSS.',
        ],
        [
            'q' => '¿Puedo devolver un producto?',
            'a' => 'Sí. Tienes 5 días hábiles en Colombia (Ley 1480/2011 art. 47) y 10 días naturales en México (PROFECO) desde la entrega para retracto. Inicia el reclamo desde "Mi Cuenta → Mis Pedidos". Reembolso garantizado en 7 días hábiles.',
        ],
        [
            'q' => '¿Cómo me convierto en vendedor?',
            'a' => 'Regístrate gratis en /vender. Completa el KYC (cédula, RUT, cuenta bancaria). Verificación en 1-2 días hábiles. Sin costos de alta. Comisión del 10-15% por venta. Recibes pagos a tu billetera semanalmente. Soporte SAGRILAFT y facturación electrónica automática.',
        ],
        [
            'q' => '¿Los vendedores están verificados?',
            'a' => 'Sí. Todos los vendedores pasan por KYC: verificación de identidad (cédula/RFC), cuenta bancaria, RUT/DIAN. Cumplimos SAGRILAFT (Ley 526/1999), screening OFAC/ONU/UE, y validamos registro sanitario INVIMA para restaurantes. Comisión transparente del 10-15%.',
        ],
        [
            'q' => '¿En qué ciudades tienen cobertura?',
            'a' => 'Cobrimos 15 ciudades en Colombia (Bogotá, Medellín, Cali, Barranquilla, Cartagena, etc.) y 8 en México (CDMX, Guadalajara, Monterrey, Puebla, etc.). Envíos cross-border CO-MX vía aduana automatizada con TLC ACE 65.',
        ],
        [
            'q' => '¿Cómo protegen mis datos personales?',
            'a' => 'Cumplimos Ley 1581/2012 (Habeas Data Colombia), LFPDPPP (México) y GDPR (UE). Datos cifrados AES-256-GCM. Registro SIC como Responsable de Tratamiento (Decreto 1727/2024). DPO designado. Derechos ARCO vía endpoint REST. Bitácora de acceso a datos personales.',
        ],
        [
            'q' => '¿Qué hago si tengo un problema con mi pedido?',
            'a' => 'Inicia una PQR (Petición, Queja, Reclamo) desde "Mi Cuenta → Mis Pedidos" o vía /wp-json/ltms/v1/pqr. SLA de respuesta: 15 días hábiles en Colombia (Ley 1480 art. 53), 10 días en México. Conciliación SIC/PROFECO disponible para disputas.',
        ],
        [
            'q' => '¿Venden productos falsificados?',
            'a' => 'No. Detectamos automáticamente keywords sospechosas (replica, imitación, fake) y bloqueamos productos. Validamos marcas contra DNDA (Colombia) e IMPI (México). Reportamos a SIC. Vendedores con revendedor autorizado de marca tienen badge verificado.',
        ],
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // RSS feeds.
        add_action( 'init', [ __CLASS__, 'register_rss_feeds' ] );

        // Schema.org ampliado.
        add_action( 'wp_head', [ __CLASS__, 'inject_breadcrumb_schema' ], 5 );
        add_action( 'wp_head', [ __CLASS__, 'inject_faq_schema' ], 6 );
        add_action( 'wp_head', [ __CLASS__, 'inject_local_business_schema' ], 7 );
        add_action( 'wp_head', [ __CLASS__, 'inject_website_search_schema' ], 8 );
        add_action( 'wp_head', [ __CLASS__, 'inject_speakable_markup' ], 9 );
        add_action( 'woocommerce_after_single_product', [ __CLASS__, 'inject_aggregate_rating_schema' ], 20 );
        add_action( 'woocommerce_shop_loop', [ __CLASS__, 'inject_item_list_schema' ], 10 );

        // llms.txt.
        add_action( 'init', [ __CLASS__, 'register_llms_txt_rewrite' ] );
        add_action( 'template_redirect', [ __CLASS__, 'serve_llms_txt' ] );

        // Sitemap index.
        add_filter( 'init', [ __CLASS__, 'register_sitemap_index_rewrite' ] );
        add_action( 'template_redirect', [ __CLASS__, 'serve_sitemap_index' ] );

        // robots.txt.
        add_filter( 'robots_txt', [ __CLASS__, 'enhance_robots_txt' ], 10, 2 );

        // Core Web Vitals hints.
        add_action( 'wp_head', [ __CLASS__, 'inject_preconnect_hints' ], 1 );

        // Hook para que otros módulos disparen.
        do_action( 'ltms_seo_enhanced_loaded' );
    }

    // ================================================================
    // 1. RSS FEEDS (7 segmentados).
    // ================================================================

    /**
     * Registra 7 tipos de feeds RSS segmentados.
     */
    public static function register_rss_feeds(): void {
        // 1. Productos por ciudad: /feed/productos/{ciudad}.xml
        add_feed( 'ltms-products-city', [ __CLASS__, 'render_products_city_feed' ] );

        // 2. Productos por vendor: /feed/vendedor/{slug}.xml
        add_feed( 'ltms-vendor-products', [ __CLASS__, 'render_vendor_products_feed' ] );

        // 3. Productos por categoría: /feed/categoria/{slug}.xml
        add_feed( 'ltms-category-products', [ __CLASS__, 'render_category_products_feed' ] );

        // 4. Nuevos productos: /feed/nuevos-productos.xml
        add_feed( 'ltms-new-products', [ __CLASS__, 'render_new_products_feed' ] );

        // 5. Ofertas y descuentos: /feed/ofertas.xml
        add_feed( 'ltms-offers', [ __CLASS__, 'render_offers_feed' ] );

        // 6. Vendors nuevos: /feed/vendedores-nuevos.xml
        add_feed( 'ltms-new-vendors', [ __CLASS__, 'render_new_vendors_feed' ] );

        // 7. Productos por ciudad+categoría: /feed/{ciudad}/{categoria}.xml
        add_feed( 'ltms-city-category', [ __CLASS__, 'render_city_category_feed' ] );

        // Flush rewrite rules solo una vez tras activación.
        if ( ! get_option( 'ltms_seo_feeds_flushed' ) ) {
            flush_rewrite_rules( false );
            update_option( 'ltms_seo_feeds_flushed', '1' );
        }
    }

    /**
     * Feed 1: Productos por ciudad.
     */
    public static function render_products_city_feed(): void {
        $city = sanitize_title( get_query_var( 'ltms_city' ) ?: ( $_GET['city'] ?? '' ) );
        if ( empty( $city ) ) {
            $city = 'bogota'; // Default.
        }

        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html( sprintf( __( 'Productos en %s — Lo Tengo', 'ltms' ), ucfirst( $city ) ) ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( "/productos/{$city}/" ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html( sprintf( __( 'Productos disponibles en %s con envío local y pago seguro', 'ltms' ), ucfirst( $city ) ) ) . '</description>' . "\n";
        echo '  <language>' . esc_html( get_locale() ) . '</language>' . "\n";
        echo '  <atom:link href="' . esc_url( home_url( "/feed/productos/{$city}.xml" ) ) . '" rel="self" type="application/rss+xml" />' . "\n";

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'meta_key' => '_ltms_city',
            'meta_value' => $city,
        ] );

        foreach ( $products as $product ) {
            self::render_product_rss_item( $product );
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 2: Productos por vendor.
     */
    public static function render_vendor_products_feed(): void {
        $vendor_slug = sanitize_title( get_query_var( 'ltms_vendor_slug' ) ?: ( $_GET['vendor'] ?? '' ) );
        $vendor = get_user_by( 'slug', $vendor_slug );
        if ( ! $vendor ) {
            status_header( 404 );
            return;
        }

        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html( sprintf( __( 'Productos de %s — Lo Tengo', 'ltms' ), $vendor->display_name ) ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( "/vendedor/{$vendor_slug}/" ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html( sprintf( __( 'Catálogo de productos de %s', 'ltms' ), $vendor->display_name ) ) . '</description>' . "\n";

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'author'   => $vendor->ID,
        ] );

        foreach ( $products as $product ) {
            self::render_product_rss_item( $product );
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 3: Productos por categoría.
     */
    public static function render_category_products_feed(): void {
        $category = sanitize_title( get_query_var( 'ltms_category' ) ?: ( $_GET['cat'] ?? '' ) );
        if ( empty( $category ) ) {
            $category = 'restaurantes';
        }

        $term = get_term_by( 'slug', $category, 'product_cat' );

        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html( sprintf( __( '%s — Lo Tengo Colombia', 'ltms' ), $term ? $term->name : ucfirst( $category ) ) ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( "/categoria/{$category}/" ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html( sprintf( __( 'Productos en categoría %s', 'ltms' ), $term ? $term->name : $category ) ) . '</description>' . "\n";

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'category' => [ $category ],
        ] );

        foreach ( $products as $product ) {
            self::render_product_rss_item( $product );
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 4: Nuevos productos (últimos 7 días).
     */
    public static function render_new_products_feed(): void {
        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html__( 'Nuevos productos — Lo Tengo Colombia', 'ltms' ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( '/nuevos-productos/' ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html__( 'Productos recién publicados en el marketplace', 'ltms' ) . '</description>' . "\n";

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'date_after' => gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) ),
        ] );

        foreach ( $products as $product ) {
            self::render_product_rss_item( $product );
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 5: Ofertas y descuentos.
     */
    public static function render_offers_feed(): void {
        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html__( 'Ofertas — Lo Tengo Colombia', 'ltms' ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( '/ofertas/' ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html__( 'Productos en oferta y con descuento en Lo Tengo', 'ltms' ) . '</description>' . "\n";

        // Productos en sale (WC sale_price).
        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'meta_key' => '_sale_price',
            'meta_compare' => 'EXISTS',
        ] );

        foreach ( $products as $product ) {
            if ( $product->is_on_sale() ) {
                self::render_product_rss_item( $product, true );
            }
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 6: Vendors nuevos.
     */
    public static function render_new_vendors_feed(): void {
        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html__( 'Nuevos vendedores — Lo Tengo Colombia', 'ltms' ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( '/vendedores-nuevos/' ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html__( 'Vendedores recién verificados en el marketplace', 'ltms' ) . '</description>' . "\n";

        $vendors = get_users( [
            'role'       => 'vendor',
            'number'     => 50,
            'orderby'    => 'registered',
            'order'      => 'DESC',
            'meta_key'   => 'ltms_kyc_status',
            'meta_value' => 'approved',
        ] );

        foreach ( $vendors as $vendor ) {
            echo '<item>' . "\n";
            echo '  <title>' . esc_html( $vendor->display_name ) . '</title>' . "\n";
            echo '  <link>' . esc_url( home_url( "/vendedor/{$vendor->user_nicename}/" ) ) . '</link>' . "\n";
            echo '  <guid isPermaLink="true">' . esc_url( home_url( "/vendedor/{$vendor->user_nicename}/" ) ) . '</guid>' . "\n";
            echo '  <pubDate>' . esc_html( gmdate( 'D, d M Y H:i:s +0000', strtotime( $vendor->user_registered ) ) ) . '</pubDate>' . "\n";
            $city = get_user_meta( $vendor->ID, 'ltms_city', true );
            echo '  <description>' . esc_html( sprintf( __( 'Vendedor en %s, verificado KYC', 'ltms' ), ucfirst( $city ) ) ) . '</description>' . "\n";
            echo '</item>' . "\n";
        }

        echo '</channel></rss>';
    }

    /**
     * Feed 7: Productos por ciudad+categoría (hiper-segmentado).
     */
    public static function render_city_category_feed(): void {
        $city     = sanitize_title( get_query_var( 'ltms_city' ) ?: ( $_GET['city'] ?? 'bogota' ) );
        $category = sanitize_title( get_query_var( 'ltms_category' ) ?: ( $_GET['cat'] ?? 'restaurantes' ) );

        self::send_feed_headers();
        echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '<channel>' . "\n";
        echo '  <title>' . esc_html( sprintf( __( '%s en %s — Lo Tengo', 'ltms' ), ucfirst( $category ), ucfirst( $city ) ) ) . '</title>' . "\n";
        echo '  <link>' . esc_url( home_url( "/productos/{$city}/{$category}/" ) ) . '</link>' . "\n";
        echo '  <description>' . esc_html( sprintf( __( '%s disponibles en %s con envío local', 'ltms' ), ucfirst( $category ), ucfirst( $city ) ) ) . '</description>' . "\n";

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'category' => [ $category ],
            'meta_key'   => '_ltms_city',
            'meta_value' => $city,
        ] );

        foreach ( $products as $product ) {
            self::render_product_rss_item( $product );
        }

        echo '</channel></rss>';
    }

    /**
     * Renderiza un item de producto en formato RSS (compatible Google Shopping).
     */
    private static function render_product_rss_item( \WC_Product $product, bool $is_offer = false ): void {
        $vendor_id   = (int) get_post_meta( $product->get_id(), '_ltms_vendor_id', true );
        $vendor_name = $vendor_id ? ( get_userdata( $vendor_id )->display_name ?? '' ) : '';
        $image_url   = wp_get_attachment_url( $product->get_image_id() ) ?: '';
        $categories  = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        $category_str = is_array( $categories ) ? implode( ' > ', $categories ) : '';

        echo '<item>' . "\n";
        echo '  <title>' . esc_html( $product->get_name() ) . '</title>' . "\n";
        echo '  <link>' . esc_url( $product->get_permalink() ) . '</link>' . "\n";
        echo '  <guid isPermaLink="true">' . esc_url( $product->get_permalink() ) . '</guid>' . "\n";
        echo '  <pubDate>' . esc_html( gmdate( 'D, d M Y H:i:s +0000', strtotime( $product->get_date_created() ) ) ) . '</pubDate>' . "\n";
        echo '  <description><![CDATA[' . wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ) . ']]></description>' . "\n";
        if ( $image_url ) {
            echo '  <enclosure url="' . esc_url( $image_url ) . '" type="' . esc_attr( self::get_image_mime( $image_url ) ) . '" />' . "\n";
        }

        // Google Shopping namespace.
        echo '  <g:id>' . esc_html( $product->get_id() ) . '</g:id>' . "\n";
        echo '  <g:title>' . esc_html( $product->get_name() ) . '</g:title>' . "\n";
        echo '  <g:description><![CDATA[' . wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ) . ']]></g:description>' . "\n";
        echo '  <g:link>' . esc_url( $product->get_permalink() ) . '</g:link>' . "\n";
        if ( $image_url ) {
            echo '  <g:image_link>' . esc_url( $image_url ) . '</g:image_link>' . "\n";
        }
        echo '  <g:price>' . esc_html( $product->get_price() ) . ' ' . esc_html( get_woocommerce_currency() ) . '</g:price>' . "\n";
        if ( $is_offer && $product->get_regular_price() > $product->get_price() ) {
            echo '  <g:sale_price>' . esc_html( $product->get_price() ) . ' ' . esc_html( get_woocommerce_currency() ) . '</g:sale_price>' . "\n";
            echo '  <g:regular_price>' . esc_html( $product->get_regular_price() ) . ' ' . esc_html( get_woocommerce_currency() ) . '</g:regular_price>' . "\n";
        }
        echo '  <g:availability>' . esc_html( $product->is_in_stock() ? 'in stock' : 'out of stock' ) . '</g:availability>' . "\n";
        echo '  <g:condition>new</g:condition>' . "\n";
        if ( $category_str ) {
            echo '  <g:product_type>' . esc_html( $category_str ) . '</g:product_type>' . "\n";
        }
        if ( $vendor_name ) {
            echo '  <g:brand>' . esc_html( $vendor_name ) . '</g:brand>' . "\n";
        }
        echo '</item>' . "\n";
    }

    private static function send_feed_headers(): void {
        header( 'Content-Type: application/rss+xml; charset=' . get_bloginfo( 'charset' ), true );
        header( 'Cache-Control: public, max-age=3600' );
    }

    private static function get_image_mime( string $url ): string {
        $ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ];
        return $map[ $ext ] ?? 'image/jpeg';
    }

    // ================================================================
    // 2. SCHEMA.ORG AMPLIADO.
    // ================================================================

    /**
     * BreadcrumbList schema para todas las páginas.
     */
    public static function inject_breadcrumb_schema(): void {
        if ( is_admin() ) return;

        $breadcrumbs = [];

        if ( is_single() ) {
            $breadcrumbs[] = [ 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ];
            $cats = get_the_terms( get_the_ID(), 'product_cat' );
            if ( $cats ) {
                foreach ( $cats as $cat ) {
                    $breadcrumbs[] = [ 'name' => $cat->name, 'url' => get_term_link( $cat ) ];
                }
            }
            $breadcrumbs[] = [ 'name' => get_the_title(), 'url' => get_permalink() ];
        } elseif ( is_product_category() ) {
            $breadcrumbs[] = [ 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ];
            $breadcrumbs[] = [ 'name' => single_term_title( '', false ), 'url' => get_term_link( get_queried_object_id() ) ];
        } elseif ( is_page() ) {
            $breadcrumbs[] = [ 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ];
            $breadcrumbs[] = [ 'name' => get_the_title(), 'url' => get_permalink() ];
        } else {
            return;
        }

        $items = [];
        foreach ( $breadcrumbs as $i => $b ) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $b['name'],
                'item'     => $b['url'],
            ];
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * FAQPage schema (global FAQ para AEO).
     */
    public static function inject_faq_schema(): void {
        if ( is_admin() ) return;
        // Solo en páginas no-producto (home, blog, páginas informativas).
        if ( is_product() ) return;

        $entities = [];
        foreach ( self::GLOBAL_FAQ as $faq ) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $faq['a'],
                ],
            ];
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'FAQPage',
            'mainEntity'  => $entities,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * LocalBusiness schema por vendor.
     */
    public static function inject_local_business_schema(): void {
        if ( ! is_singular() ) return;

        // En /vendedor/{slug}/ (vendor storefront).
        $vendor_slug = get_query_var( 'ltms_vendor_slug' );
        if ( empty( $vendor_slug ) ) return;

        $vendor = get_user_by( 'slug', $vendor_slug );
        if ( ! $vendor ) return;

        $city    = get_user_meta( $vendor->ID, 'ltms_city', true );
        $address = get_user_meta( $vendor->ID, 'ltms_address', true );
        $phone   = get_user_meta( $vendor->ID, 'ltms_phone', true );
        $lat     = get_user_meta( $vendor->ID, 'ltms_lat', true );
        $lng     = get_user_meta( $vendor->ID, 'ltms_lng', true );

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => $vendor->display_name,
            'url'         => home_url( "/vendedor/{$vendor_slug}/" ),
            'image'       => get_user_meta( $vendor->ID, 'ltms_store_banner_url', true ) ?: '',
            'telephone'   => $phone ?: '',
            'priceRange'  => '$$',
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address ?: '',
                'addressLocality' => ucfirst( $city ) ?: '',
                'addressCountry'  => LTMS_Core_Config::get_country(),
            ],
        ];

        if ( $lat && $lng ) {
            $schema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * WebSite + SearchAction schema (sitelinks search box).
     */
    public static function inject_website_search_schema(): void {
        if ( ! is_front_page() ) return;

        $schema = [
            '@context'       => 'https://schema.org',
            '@type'          => 'WebSite',
            'url'            => home_url( '/' ),
            'name'           => get_bloginfo( 'name' ),
            'description'    => get_bloginfo( 'description' ),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url( '/?s={search_term_string}&post_type=product' ),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * Speakable markup para AEO (asistentes de voz + LLMs).
     */
    public static function inject_speakable_markup(): void {
        if ( is_admin() ) return;
        if ( ! is_singular() && ! is_front_page() ) return;

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => [ 'WebPage', 'Article' ],
            '@id'         => get_permalink() ?: home_url( '/' ),
            'speakable'   => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => [ 'h1', '.entry-title', '.summary', '.faq-answer', '.ltms-product-summary' ],
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * AggregateRating schema en PDP.
     */
    public static function inject_aggregate_rating_schema(): void {
        global $product;
        if ( ! $product ) return;

        $rating_count = (int) $product->get_rating_count();
        $avg_rating   = (float) $product->get_average_rating();

        if ( $rating_count < 1 ) return;

        $schema = [
            '@context'       => 'https://schema.org',
            '@type'          => 'AggregateRating',
            'ratingValue'    => $avg_rating,
            'reviewCount'    => $rating_count,
            'bestRating'     => 5,
            'worstRating'    => 1,
            'itemReviewed'   => [
                '@type' => 'Product',
                'name'  => $product->get_name(),
            ],
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    /**
     * ItemList schema en listados de productos (categorías, búsquedas).
     */
    public static function inject_item_list_schema(): void {
        global $products_loop;
        // Se renderiza al final del loop en categorías/tienda.
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

        $items = [];
        $i = 1;
        while ( have_posts() ) {
            the_post();
            $product = wc_get_product( get_the_ID() );
            if ( ! $product ) continue;
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i++,
                'item'     => [
                    '@type' => 'Product',
                    'name'  => $product->get_name(),
                    'url'   => $product->get_permalink(),
                    'image' => wp_get_attachment_url( $product->get_image_id() ) ?: '',
                    'offers' => [
                        '@type'         => 'Offer',
                        'price'         => $product->get_price(),
                        'priceCurrency' => get_woocommerce_currency(),
                        'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    ],
                ],
            ];
            if ( $i > 20 ) break; // Limitar a 20 para no saturar.
        }
        wp_reset_query();

        if ( empty( $items ) ) return;

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'ItemList',
            'itemListElement' => $items,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    // ================================================================
    // 3. llms.txt PARA AEO.
    // ================================================================

    /**
     * Registra rewrite para /llms.txt.
     */
    public static function register_llms_txt_rewrite(): void {
        add_rewrite_rule( '^llms\.txt$', 'index.php?ltms_llms_txt=1', 'top' );
        if ( ! get_option( 'ltms_llms_txt_flushed' ) ) {
            flush_rewrite_rules( false );
            update_option( 'ltms_llms_txt_flushed', '1' );
        }
    }

    /**
     * Sirve llms.txt cuando se accede a /llms.txt.
     */
    public static function serve_llms_txt(): void {
        if ( ! get_query_var( 'ltms_llms_txt' ) ) return;

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: public, max-age=86400' );

        echo self::generate_llms_txt_content();
        exit;
    }

    /**
     * Genera el contenido del llms.txt (estándar https://llmstxt.org).
     */
    public static function generate_llms_txt_content(): string {
        $stats = self::get_marketplace_stats();

        $content  = "# " . get_bloginfo( 'name' ) . "\n\n";
        $content  .= get_bloginfo( 'description' ) . "\n\n";
        $content  .= "Marketplace multi-vendor que conecta vendedores verificados con compradores en Colombia y México. Categorías: restaurantes, productos físicos, turismo, servicios.\n\n";
        $content  .= "## Estadísticas públicas\n";
        $content  .= "- " . number_format( $stats['vendors'] ) . " vendedores verificados\n";
        $content  .= "- " . number_format( $stats['products'] ) . " productos activos\n";
        $content  .= "- Cobertura: 15 ciudades CO + 8 ciudades MX\n";
        $content  .= "- Pagos: tarjeta, PSE, Nequi, OXXO, contraentrega\n";
        $content  .= "- Envíos: Deprisa, Heka, Aveonline, Uber Direct\n\n";
        $content  .= "## Páginas principales\n";
        $content  .= "- [Inicio](" . home_url( '/' ) . ")\n";
        $content  .= "- [Cómo comprar](" . home_url( '/como-comprar/' ) . ")\n";
        $content  .= "- [Cómo vender](" . home_url( '/vender/' ) . ")\n";
        $content  .= "- [Términos y Condiciones](" . get_permalink( get_option( 'ltms_terms_page_id' ) ) ?: home_url( '/terminos/' ) . ")\n";
        $content  .= "- [Política de Privacidad](" . get_privacy_policy_url() . ")\n";
        $content  .= "- [Preguntas Frecuentes](" . home_url( '/faq/' ) . ")\n\n";
        $content  .= "## Categorías principales\n";
        $cats = get_terms( [ 'taxonomy' => 'product_cat', 'number' => 20, 'hide_empty' => true ] );
        if ( ! is_wp_error( $cats ) ) {
            foreach ( $cats as $cat ) {
                $content .= "- [" . $cat->name . "](" . get_term_link( $cat ) . ")\n";
            }
        }
        $content .= "\n## Feeds RSS\n";
        $content .= "- [Nuevos productos](" . home_url( '/feed/nuevos-productos.xml' ) . ")\n";
        $content .= "- [Ofertas](" . home_url( '/feed/ofertas.xml' ) . ")\n";
        $content .= "- [Nuevos vendedores](" . home_url( '/feed/vendedores-nuevos.xml' ) . ")\n\n";
        $content .= "## Cumplimiento normativo\n";
        $content .= "- Ley 1581/2012 (Habeas Data Colombia)\n";
        $content .= "- Ley 1480/2011 (Estatuto del Consumidor)\n";
        $content .= "- LFPDPPP (México)\n";
        $content .= "- GDPR (UE)\n";
        $content .= "- PCI DSS v4.0 SAQ-A\n";
        $content .= "- ISO 27001 (en implementación)\n";
        $content .= "- SAGRILAFT / SARLAFT\n";
        $content .= "- Registro SIC (Decreto 1727/2024)\n";

        return $content;
    }

    private static function get_marketplace_stats(): array {
        $vendor_count = (int) get_transient( 'ltms_seo_vendor_count' );
        if ( ! $vendor_count ) {
            $vendor_count = count( get_users( [ 'role' => 'vendor', 'fields' => 'ID', 'meta_key' => 'ltms_kyc_status', 'meta_value' => 'approved' ] ) );
            set_transient( 'ltms_seo_vendor_count', $vendor_count, HOUR_IN_SECONDS );
        }
        $product_count = (int) get_transient( 'ltms_seo_product_count' );
        if ( ! $product_count ) {
            $product_count = wp_count_posts( 'product' )->publish ?? 0;
            set_transient( 'ltms_seo_product_count', $product_count, HOUR_IN_SECONDS );
        }
        return [
            'vendors'  => $vendor_count,
            'products' => $product_count,
        ];
    }

    // ================================================================
    // 4. SITEMAP INDEX.
    // ================================================================

    public static function register_sitemap_index_rewrite(): void {
        add_rewrite_rule( '^ltms-sitemap-index\.xml$', 'index.php?ltms_sitemap_index=1', 'top' );
    }

    /**
     * Sirve el sitemap index que referencia sub-sitemaps.
     */
    public static function serve_sitemap_index(): void {
        if ( ! get_query_var( 'ltms_sitemap_index' ) ) return;

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );

        $sub_sitemaps = [
            [ 'loc' => home_url( '/ltms-sitemap.xml' ),                  'type' => 'products' ],
            [ 'loc' => home_url( '/ltms-sitemap-vendors.xml' ),          'type' => 'vendors' ],
            [ 'loc' => home_url( '/ltms-sitemap-categories.xml' ),       'type' => 'categories' ],
            [ 'loc' => home_url( '/ltms-sitemap-cities.xml' ),           'type' => 'cities' ],
            [ 'loc' => home_url( '/ltms-sitemap-blog.xml' ),             'type' => 'blog' ],
        ];

        // Disparar hook para que otros módulos añadan sub-sitemaps.
        $extra = apply_filters( 'ltms_sitemap_index_entries', [] );
        if ( is_array( $extra ) ) {
            $sub_sitemaps = array_merge( $sub_sitemaps, $extra );
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ( $sub_sitemaps as $sm ) {
            echo '  <sitemap>' . "\n";
            echo '    <loc>' . esc_url( $sm['loc'] ) . '</loc>' . "\n";
            echo '    <lastmod>' . esc_html( gmdate( 'Y-m-d' ) ) . '</lastmod>' . "\n";
            echo '  </sitemap>' . "\n";
        }
        echo '</sitemapindex>';
        exit;
    }

    // ================================================================
    // 5. ROBOTS.TXT OPTIMIZADO.
    // ================================================================

    /**
     * Mejora robots.txt para marketplace.
     */
    public static function enhance_robots_txt( string $output, bool $public ): string {
        if ( ! $public ) return $output;

        $output  = "User-agent: *\n";
        $output .= "Allow: /\n";
        $output .= "Disallow: /carrito/\n";
        $output .= "Disallow: /checkout/\n";
        $output .= "Disallow: /mi-cuenta/\n";
        $output .= "Disallow: /wp-admin/\n";
        $output .= "Disallow: /*?add-to-cart=\n";
        $output .= "Disallow: /*?orderby=\n";
        $output .= "Disallow: /*?filter_*\n";
        $output .= "Disallow: /*?utm_*\n";
        $output .= "Disallow: /*?replytocom=\n";
        $output .= "\n";
        $output .= "User-agent: Googlebot\n";
        $output .= "Allow: /\n";
        $output .= "Disallow: /carrito/\n";
        $output .= "Disallow: /checkout/\n";
        $output .= "Disallow: /mi-cuenta/\n";
        $output .= "\n";
        $output .= "User-agent: AdsBot-Google\n";
        $output .= "Allow: /\n";
        $output .= "\n";
        $output .= "Sitemap: " . home_url( '/ltms-sitemap-index.xml' ) . "\n";
        $output .= "Sitemap: " . home_url( '/ltms-sitemap.xml' ) . "\n";
        $output .= "Sitemap: " . home_url( '/feed/nuevos-productos.xml' ) . "\n";
        $output .= "Sitemap: " . home_url( '/feed/ofertas.xml' ) . "\n";

        return $output;
    }

    // ================================================================
    // 6. CORE WEB VITALS HINTS.
    // ================================================================

    /**
     * Inyecta preconnect/dns-prefetch para recursos críticos.
     */
    public static function inject_preconnect_hints(): void {
        if ( is_admin() ) return;

        // Preconnect a CDNs y APIs externas.
        $preconnects = [
            'https://js.openpay.mx',
            'https://resources.openpay.co',
            'https://cdn.jsdelivr.net',
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
        ];

        foreach ( $preconnects as $url ) {
            echo '<link rel="preconnect" href="' . esc_url( $url ) . '" crossorigin />' . "\n";
            echo '<link rel="dns-prefetch" href="' . esc_url( $url ) . '" />' . "\n";
        }

        // Preload del hero image (si está en homepage).
        if ( is_front_page() ) {
            $hero = get_option( 'ltms_hero_image_url', '' );
            if ( $hero ) {
                echo '<link rel="preload" as="image" href="' . esc_url( $hero ) . '" />' . "\n";
            }
        }
    }
}
