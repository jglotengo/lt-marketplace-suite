<?php
/**
 * LTMS API Webhook Router
 *
 * Registra el endpoint REST genérico de webhooks y despacha las
 * peticiones entrantes al handler específico según el proveedor.
 *
 * Endpoint: POST /wp-json/ltms/v1/webhooks/{provider}
 * Providers soportados: addi, openpay, siigo, aveonline, zapsign
 *
 * Los handlers de Stripe y Uber Direct registran sus propias rutas.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/webhooks
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Webhook_Router
 */
final class LTMS_Api_Webhook_Router {

    /**
     * Mapa provider → clase handler.
     *
     * @var array<string,string>
     */
    private static array $handlers = [
        'addi'      => 'LTMS_Addi_Webhook_Handler',
        'openpay'   => 'LTMS_Openpay_Webhook_Handler',
        'siigo'     => 'LTMS_Siigo_Webhook_Handler',
        'aveonline' => 'LTMS_Aveonline_Webhook_Handler',
        'zapsign'   => 'LTMS_Zapsign_Webhook_Handler',
        'alegra'    => 'LTMS_Alegra_Webhook_Handler',
    ];

    /**
     * Registra la ruta REST genérica.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    /**
     * Registra el endpoint de webhooks.
     *
     * @return void
     */
    public static function register_route(): void {
        register_rest_route( 'ltms/v1', '/webhooks/(?P<provider>[a-z0-9_\-]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'dispatch' ],
            // SEC-H3: autenticación delegada a cada handler (HMAC/token propio de cada proveedor)
            'permission_callback' => '__return_true',
            'args'                => [
                'provider' => [
                    'validate_callback' => fn( $v ) => preg_match( '/^[a-z0-9_\-]+$/', $v ),
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ] );
    }

    /**
     * Despacha la petición al handler correspondiente.
     *
     * @param WP_REST_Request $request Petición REST entrante.
     * @return WP_REST_Response
     */
    public static function dispatch( WP_REST_Request $request ): WP_REST_Response {
        $provider = sanitize_key( $request->get_param( 'provider' ) );

        if ( ! isset( self::$handlers[ $provider ] ) ) {
            return new WP_REST_Response( [ 'error' => 'Unknown provider' ], 404 );
        }

        $class = self::$handlers[ $provider ];

        if ( ! class_exists( $class ) ) {
            return new WP_REST_Response( [ 'error' => 'Handler unavailable' ], 503 );
        }

        // Registrar recepción antes de procesar
        self::log_incoming( $provider, $request );

        try {
            return $class::handle( $request );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'WEBHOOK_DISPATCH_ERROR',
                    sprintf( 'Error procesando webhook de %s: %s', $provider, $e->getMessage() )
                );
            }
            return new WP_REST_Response( [ 'error' => 'Internal error' ], 500 );
        }
    }

    /**
     * Registra el webhook entrante en la tabla de logs.
     *
     * @param string          $provider Proveedor.
     * @param WP_REST_Request $request  Petición.
     * @return void
     */
    private static function log_incoming( string $provider, WP_REST_Request $request ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_webhook_logs';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return;
        }
        // CICLO25-P2-AD-GAP-004 FIX: delegar resolucion de IP a get_client_ip_safe()
        // (consistencia con handlers). Antes usaba $_SERVER['REMOTE_ADDR'] directo,
        // que ofrece la IP del proxy (no del cliente real) cuando hay reverse proxy
        // — inutil para forensic/audit tras un webhook malicioso desde un cliente
        // real escondido detras del proxy. get_client_ip_safe() valida trusted
        // proxies y resuelve el X-Forwarded-For solo si REMOTE_ADDR es un proxy
        // confiable declarado en ltms_trusted_proxies.
        $ip_address = class_exists( 'LTMS_Core_Security' )
            ? LTMS_Core_Security::get_client_ip_safe()
            : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'provider'   => $provider,
            'event_type' => sanitize_text_field( $request->get_header( 'x-event-type' ) ?: 'unknown' ),
            'payload'    => wp_json_encode( $request->get_params() ),
            'ip_address' => $ip_address,
            'status'     => 'received',
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%s', '%s', '%s', '%s', '%s', '%s' ] );
    }

    /** Prevenir instanciación */
    private function __construct() {}
}
