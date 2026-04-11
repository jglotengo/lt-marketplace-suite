<?php
/**
 * LTMS Core REST Controller
 *
 * Registra los endpoints REST públicos de la plataforma:
 *   GET  /wp-json/ltms/v1/status          — Health check
 *   GET  /wp-json/ltms/v1/vendor/{id}     — Perfil público del vendedor
 *   GET  /wp-json/ltms/v1/products        — Listado de productos con filtros
 *   POST /wp-json/ltms/v1/quote           — Cotización de envío anónima
 *
 * Los webhooks de APIs externas se registran en sus propias clases
 * (LTMS_Stripe_Webhook_Handler, LTMS_Uber_Direct_Webhook_Handler, etc.).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_REST_Controller
 */
class LTMS_Core_REST_Controller {

    const NAMESPACE = 'ltms/v1';

    /**
     * Registra todas las rutas REST del plugin.
     *
     * @return void
     */
    public function register_routes(): void {

        // Health check
        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => '__return_true',
        ] );

        // Perfil público del vendedor
        register_rest_route( self::NAMESPACE, '/vendor/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_vendor_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // Listado de productos
        register_rest_route( self::NAMESPACE, '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_products' ],
            'permission_callback' => '__return_true',
        ] );

        // Cotización de envío anónima
        register_rest_route( self::NAMESPACE, '/quote', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'get_shipping_quote' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * GET /ltms/v1/status — Health check del plugin.
     *
     * @return WP_REST_Response
     */
    public function get_status(): WP_REST_Response {
        return new WP_REST_Response( [
            'status'  => 'ok',
            'version' => defined( 'LTMS_VERSION' ) ? LTMS_VERSION : 'unknown',
            'time'    => gmdate( 'c' ),
        ], 200 );
    }

    /**
     * GET /ltms/v1/vendor/{id} — Perfil público.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_vendor_profile( WP_REST_Request $request ): WP_REST_Response {
        $vendor_id = (int) $request->get_param( 'id' );
        $user      = get_userdata( $vendor_id );

        if ( ! $user ) {
            return new WP_REST_Response( [ 'error' => 'Vendor not found' ], 404 );
        }

        $store_name = get_user_meta( $vendor_id, 'ltms_store_name', true ) ?: $user->display_name;

        return new WP_REST_Response( [
            'id'          => $vendor_id,
            'store_name'  => esc_html( $store_name ),
            'description' => esc_html( get_user_meta( $vendor_id, 'ltms_store_description', true ) ?: '' ),
            'city'        => esc_html( get_user_meta( $vendor_id, 'ltms_store_city', true ) ?: '' ),
        ], 200 );
    }

    /**
     * GET /ltms/v1/products — Listado de productos con filtros básicos.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_products( WP_REST_Request $request ): WP_REST_Response {
        $args = [
            'status'         => 'publish',
            'limit'          => min( 50, absint( $request->get_param( 'per_page' ) ?: 20 ) ),
            'page'           => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
            'orderby'        => sanitize_key( $request->get_param( 'orderby' ) ?: 'date' ),
            'order'          => in_array( strtoupper( $request->get_param( 'order' ) ?: 'DESC' ), [ 'ASC', 'DESC' ], true )
                                ? strtoupper( $request->get_param( 'order' ) )
                                : 'DESC',
            'category'       => sanitize_text_field( $request->get_param( 'category' ) ?: '' ),
        ];

        $products = wc_get_products( $args );
        $data     = [];

        foreach ( $products as $product ) {
            $data[] = [
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $product->get_price(),
                'url'   => get_permalink( $product->get_id() ),
            ];
        }

        return new WP_REST_Response( [ 'products' => $data ], 200 );
    }

    /**
     * POST /ltms/v1/quote — Cotización de envío.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_shipping_quote( WP_REST_Request $request ): WP_REST_Response {
        $body      = $request->get_json_params();
        $postal    = sanitize_text_field( $body['postal_code'] ?? '' );
        $vendor_id = absint( $body['vendor_id'] ?? 0 );
        $weight    = (float) ( $body['weight_kg'] ?? 0 );

        if ( ! $postal || ! $vendor_id || $weight <= 0 ) {
            return new WP_REST_Response( [ 'error' => 'Parámetros requeridos: postal_code, vendor_id, weight_kg' ], 422 );
        }

        // Delegar a LTMS_Shipping_Parallel_Quoter si está disponible
        if ( class_exists( 'LTMS_Shipping_Parallel_Quoter' ) ) {
            try {
                $quote = LTMS_Shipping_Parallel_Quoter::quote( [
                    'vendor_id'   => $vendor_id,
                    'postal_code' => $postal,
                    'weight_kg'   => $weight,
                ] );
                return new WP_REST_Response( $quote, 200 );
            } catch ( \Throwable $e ) {
                return new WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
            }
        }

        return new WP_REST_Response( [ 'error' => 'Quoter module unavailable' ], 503 );
    }
}
