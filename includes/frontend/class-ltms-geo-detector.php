<?php
/**
 * LTMS Geo Detector
 *
 * Detecta ciudad del visitante vía ip-api.com (gratuito, sin API key).
 * Cache en transient por IP 24 horas. No almacena IPs en DB.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Geo_Detector
 */
class LTMS_Geo_Detector {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        if ( ! (bool) LTMS_Core_Config::get( 'ltms_geo_detection_enabled', true ) ) return;

        add_action( 'init', [ self::class, 'init_session_location' ], 5 );
        add_action( 'init', [ self::class, 'register_city_rewrite_rules' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_city_selector' ] );
    }

    /**
     * Detecta ciudad del visitante.
     *
     * @return array ['city' => string, 'region' => string, 'country' => string]
     */
    public static function get_visitor_location(): array {
        $default = [
            'city'    => LTMS_Core_Config::get( 'ltms_geo_default_city', 'Bogotá' ),
            'region'  => '',
            'country' => LTMS_Core_Config::get( 'ltms_geo_default_country', 'CO' ),
        ];
        try {
            $ip = self::get_client_ip();
            if ( ! $ip || self::is_private_ip( $ip ) ) return $default;

            $cache_key = 'ltms_geo_' . md5( $ip );
            $cached    = get_transient( $cache_key );
            if ( $cached !== false ) return is_array( $cached ) ? $cached : $default;

            $response = wp_remote_get(
                'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=city,regionName,countryCode',
                [ 'timeout' => 2, 'sslverify' => false ]
            );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return $default;

            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $data['city'] ) ) return $default;

            $location = [
                'city'    => sanitize_text_field( $data['city'] ),
                'region'  => sanitize_text_field( $data['regionName'] ?? '' ),
                'country' => sanitize_text_field( $data['countryCode'] ?? 'CO' ),
            ];
            set_transient( $cache_key, $location, DAY_IN_SECONDS );
            return $location;
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Geo: detection failed — ' . $e->getMessage() );
            return $default;
        }
    }

    public static function init_session_location(): void {
        try {
            // phpcs:disable WordPress.Security.NonceVerification
            if ( ! empty( $_GET['ltms_city'] ) ) {
                $city = sanitize_text_field( wp_unslash( $_GET['ltms_city'] ) );
                if ( WC()->session ) WC()->session->set( 'ltms_city', $city );
                setcookie( 'ltms_city', $city, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
                return;
            }
            // phpcs:enable
            if ( ! empty( $_COOKIE['ltms_city'] ) ) {
                if ( WC()->session ) WC()->session->set( 'ltms_city', sanitize_text_field( $_COOKIE['ltms_city'] ) );
                return;
            }
            $location = self::get_visitor_location();
            if ( WC()->session ) WC()->session->set( 'ltms_city', $location['city'] );
            setcookie( 'ltms_city', $location['city'], time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Geo: init_session_location failed — ' . $e->getMessage() );
        }
    }

    public static function register_city_rewrite_rules(): void {
        add_rewrite_rule( '^productos/([a-z-]+)/?$',  'index.php?ltms_geo_productos=1&ltms_ciudad=$matches[1]', 'top' );
        add_rewrite_rule( '^vendedores/([a-z-]+)/?$', 'index.php?ltms_geo_vendedores=1&ltms_ciudad=$matches[1]', 'top' );
        add_filter( 'query_vars', static function( array $vars ): array {
            $vars[] = 'ltms_geo_productos';
            $vars[] = 'ltms_geo_vendedores';
            $vars[] = 'ltms_ciudad';
            return $vars;
        } );
    }

    public static function enqueue_city_selector(): void {
        wp_localize_script( 'jquery', 'ltmsGeo', [
            'currentCity' => self::get_current_city(),
            'cities'      => self::get_available_cities(),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        ] );
    }

    public static function get_current_city(): string {
        if ( ! empty( $_COOKIE['ltms_city'] ) ) return sanitize_text_field( $_COOKIE['ltms_city'] );
        return LTMS_Core_Config::get( 'ltms_geo_default_city', 'Bogotá' );
    }

    private static function get_available_cities(): array {
        return [
            'CO' => [ 'Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Bucaramanga', 'Pereira', 'Manizales', 'Santa Marta', 'Cúcuta' ],
            'MX' => [ 'Ciudad de México', 'Guadalajara', 'Monterrey', 'Puebla', 'Tijuana' ],
        ];
    }

    private static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '';
    }

    private static function is_private_ip( string $ip ): bool {
        return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }
}
