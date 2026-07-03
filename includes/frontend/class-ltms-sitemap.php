<?php
/**
 * LTMS Sitemap
 *
 * Sitemap XML dinámico: productos, tiendas de vendedores y páginas del plugin.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Sitemap
 */
class LTMS_Sitemap {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'init', [ self::class, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
        add_action( 'template_redirect', [ self::class, 'handle_sitemap_request' ] );
    }

    public static function register_rewrite_rules(): void {
        add_rewrite_rule( '^ltms-sitemap\.xml$', 'index.php?ltms_sitemap=1', 'top' );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'ltms_sitemap';
        return $vars;
    }

    public static function handle_sitemap_request(): void {
        if ( ! get_query_var( 'ltms_sitemap' ) ) {
            return;
        }
        try {
            $xml = self::generate_sitemap_xml();
            header( 'Content-Type: application/xml; charset=UTF-8' );
            header( 'X-Robots-Tag: noindex' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $xml;
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Sitemap: generation failed — ' . $e->getMessage() );
            status_header( 500 );
        }
        exit;
    }

    private static function generate_sitemap_xml(): string {
        $exclude_outofstock = (bool) LTMS_Core_Config::get( 'ltms_sitemap_exclude_outofstock', true );
        $urls               = [];

        $product_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ( $exclude_outofstock ) {
            $product_args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'instock' ] ];
        }
        foreach ( get_posts( $product_args ) as $product_id ) {
            $urls[] = [
                'loc'        => get_permalink( $product_id ),
                'lastmod'    => get_the_modified_date( 'c', $product_id ),
                'changefreq' => 'weekly',
                'priority'   => '0.8',
            ];
        }

        $vendors = get_users( [ 'role' => 'ltms_vendor', 'fields' => [ 'ID', 'user_login' ] ] );
        foreach ( $vendors as $vendor ) {
            $store_slug = get_user_meta( $vendor->ID, 'ltms_store_slug', true ) ?: $vendor->user_login;
            $urls[]     = [
                'loc'        => home_url( '/tienda/' . sanitize_title( $store_slug ) . '/' ),
                'changefreq' => 'daily',
                'priority'   => '0.7',
            ];
        }

        foreach ( [ get_option( 'ltms_page_dashboard' ), get_option( 'ltms_page_login' ), get_option( 'ltms_page_register' ), get_option( 'ltms_page_store' ) ] as $page_id ) {
            if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
                $urls[] = [ 'loc' => get_permalink( $page_id ), 'changefreq' => 'monthly', 'priority' => '0.5' ];
            }
        }

        return self::build_xml( $urls );
    }

    private static function build_xml( array $urls ): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ( $urls as $url ) {
            $xml .= "\t<url>\n\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
            if ( ! empty( $url['lastmod'] ) ) $xml .= "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
            $xml .= "\t\t<changefreq>" . esc_html( $url['changefreq'] ) . "</changefreq>\n";
            $xml .= "\t\t<priority>" . esc_html( $url['priority'] ) . "</priority>\n\t</url>\n";
        }
        return $xml . '</urlset>';
    }
}
