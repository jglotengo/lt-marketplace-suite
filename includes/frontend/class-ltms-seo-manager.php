<?php
/**
 * LTMS SEO Manager
 *
 * SEO técnico: Schema.org, Open Graph, Twitter Cards, Google Search Console.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_SEO_Manager
 */
class LTMS_SEO_Manager {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'wp_head', [ self::class, 'inject_schema_org' ],     1 );
        add_action( 'wp_head', [ self::class, 'inject_open_graph' ],     2 );
        add_action( 'wp_head', [ self::class, 'inject_search_console' ], 3 );
        add_filter( 'document_title_parts', [ self::class, 'optimize_title_parts' ], 20 );
    }

    public static function inject_schema_org(): void {
        try {
            if ( is_singular( 'product' ) ) {
                $product = wc_get_product( get_the_ID() );
                if ( ! $product ) return;
                $schema = self::build_product_schema( $product );
            } elseif ( is_front_page() || is_home() ) {
                $schema = self::build_organization_schema();
            } else {
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
        } catch ( \Throwable $e ) {
            error_log( 'LTMS SEO: inject_schema_org failed — ' . $e->getMessage() );
        }
    }

    private static function build_product_schema( \WC_Product $product ): array {
        $vendor_id  = (int) get_post_field( 'post_author', $product->get_id() );
        $store_name = get_user_meta( $vendor_id, 'ltms_store_name', true ) ?: get_bloginfo( 'name' );
        $currency   = get_woocommerce_currency();
        $image_url  = wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src();
        return [
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'image'       => [ $image_url ],
            'sku'         => $product->get_sku() ?: (string) $product->get_id(),
            'brand'       => [ '@type' => 'Brand', 'name' => $store_name ],
            'offers'      => [
                '@type'           => 'Offer',
                'url'             => get_permalink( $product->get_id() ),
                'priceCurrency'   => $currency,
                'price'           => $product->get_price(),
                'priceValidUntil' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
                'itemCondition'   => 'https://schema.org/NewCondition',
                'availability'    => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'seller'          => [ '@type' => 'Organization', 'name' => $store_name ],
                'shippingDetails' => [
                    '@type'               => 'OfferShippingDetails',
                    'shippingRate'        => [ '@type' => 'MonetaryAmount', 'value' => '0', 'currency' => $currency ],
                    'shippingDestination' => [ '@type' => 'DefinedRegion', 'addressCountry' => LTMS_Core_Config::get( 'ltms_country', 'CO' ) ],
                ],
            ],
        ];
    }

    private static function build_organization_schema(): array {
        return [
            '@context' => 'https://schema.org/',
            '@type'    => 'Organization',
            'name'     => LTMS_Core_Config::get( 'ltms_og_site_name', get_bloginfo( 'name' ) ),
            'url'      => home_url(),
            'logo'     => get_site_icon_url(),
        ];
    }

    public static function inject_open_graph(): void {
        try {
            $og = self::get_og_data();
            if ( empty( $og ) ) return;

            $site_name = esc_attr( LTMS_Core_Config::get( 'ltms_og_site_name', get_bloginfo( 'name' ) ) );
            $locale    = esc_attr( LTMS_Core_Config::get( 'ltms_og_locale', 'es_CO' ) );
            echo '<meta property="og:site_name" content="' . $site_name . '">' . "\n";
            echo '<meta property="og:locale" content="' . $locale . '">' . "\n";
            foreach ( $og as $property => $content ) {
                echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( (string) $content ) . '">' . "\n";
            }
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr( (string) ( $og['og:title'] ?? '' ) ) . '">' . "\n";
            if ( ! empty( $og['og:description'] ) ) {
                echo '<meta name="twitter:description" content="' . esc_attr( (string) $og['og:description'] ) . '">' . "\n";
            }
            if ( ! empty( $og['og:image'] ) ) {
                echo '<meta name="twitter:image" content="' . esc_attr( (string) $og['og:image'] ) . '">' . "\n";
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS SEO: inject_open_graph failed — ' . $e->getMessage() );
        }
    }

    private static function get_og_data(): array {
        if ( is_singular( 'product' ) ) {
            $product = wc_get_product( get_the_ID() );
            if ( ! $product ) return [];
            return array_filter( [
                'og:type'                => 'product',
                'og:title'               => $product->get_name(),
                'og:description'         => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 30 ),
                'og:url'                 => get_permalink( $product->get_id() ),
                'og:image'               => wp_get_attachment_url( $product->get_image_id() ) ?: '',
                'product:price:amount'   => $product->get_price(),
                'product:price:currency' => get_woocommerce_currency(),
            ] );
        } elseif ( is_front_page() ) {
            return array_filter( [
                'og:type'        => 'website',
                'og:title'       => get_bloginfo( 'name' ),
                'og:description' => get_bloginfo( 'description' ),
                'og:url'         => home_url(),
                'og:image'       => get_site_icon_url( 512 ),
            ] );
        }
        return [];
    }

    public static function inject_search_console_tag(): void {
        self::inject_search_console();
    }

    public static function inject_search_console(): void {
        try {
            $verify = LTMS_Core_Config::get( 'ltms_google_search_console_verify', '' );
            if ( $verify ) {
                echo '<meta name="google-site-verification" content="' . esc_attr( $verify ) . '">' . "\n";
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS SEO: inject_search_console failed — ' . $e->getMessage() );
        }
    }

    public static function optimize_title_parts( array $title ): array {
        if ( is_singular( 'product' ) ) {
            $product    = wc_get_product( get_the_ID() );
            $vendor_id  = $product ? (int) get_post_field( 'post_author', $product->get_id() ) : 0;
            $store_name = $vendor_id ? get_user_meta( $vendor_id, 'ltms_store_name', true ) : '';
            if ( $store_name ) {
                $title['tagline'] = $store_name;
            }
        }
        return $title;
    }
}
