<?php
/**
 * LTMS Geo Detector — sync de ciudad (cookie + sesión WC).
 *
 * E2E-BUYER-GEO-DEAD (2026-11-24, P3): el detector original consultaba una
 * API pública de geolocalización SINCRÓNAMENTE en init (HTTP bloqueante,
 * timeout 2s) para TODO visitante sin cookie — hasta 2s de TTFB extra por
 * visitante nuevo (y la API gratuita limita 45 req/min, así que la mayoría
 * de llamadas fallaban e igualmente quemaban el timeout). Además:
 *  - la sesión/cookie que escribía NO tiene ningún consumer de negocio (ni
 *    envíos, ni render, ni JS — verificado por grep en el ciclo),
 *  - las rewrite rules ^productos/{ciudad} y ^vendedores/{ciudad} registraban
 *    query vars que nadie lee (el feed de Merchant de seo-enhanced usa su
 *    propia ruta add_feed, no estas rewrites),
 *  - el localize del selector de ciudad no tenía ningún JS que lo consumiera
 *    (objeto JS fantasma para una feature que nunca se construyó).
 * Se eliminaron la llamada HTTP, las rewrites y el localize. Se conservan el
 * ?ltms_city= (deep link) y el sync cookie→sesión: la cookie ltms_city está
 * DOCUMENTADA en el catálogo de privacidad (LTMS_Legal_Compliance) y la
 * limpia LTMS_Privacy_Toolkit — no eliminarla sin actualizar el catálogo.
 * Si algún día se construye el selector de ciudad, hacer la detección vía
 * AJAX (no bloqueante) y dar un consumer real a la sesión.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.1.0
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
    }

    public static function init_session_location(): void {
        try {
            // phpcs:disable WordPress.Security.NonceVerification
            if ( ! empty( $_GET['ltms_city'] ) ) {
                $city = sanitize_text_field( wp_unslash( $_GET['ltms_city'] ) );
                if ( WC()->session ) WC()->session->set( 'ltms_city', $city );
                setcookie( 'ltms_city', $city, [ 'expires' => time() + MONTH_IN_SECONDS, 'path' => COOKIEPATH, 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict', ] );
                return;
            }
            // phpcs:enable
            if ( ! empty( $_COOKIE['ltms_city'] ) ) {
                if ( WC()->session ) WC()->session->set( 'ltms_city', sanitize_text_field( $_COOKIE['ltms_city'] ) );
                return;
            }
            $city = (string) LTMS_Core_Config::get( 'ltms_geo_default_city', 'Bogotá' );
            if ( WC()->session ) WC()->session->set( 'ltms_city', $city );
            setcookie( 'ltms_city', $city, [ 'expires' => time() + MONTH_IN_SECONDS, 'path' => COOKIEPATH, 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict', ] );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Geo: init_session_location failed — ' . $e->getMessage() );
        }
    }
}
