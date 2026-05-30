<?php
/**
 * LTMS Shipping Mode — F-08
 * Modos: quoted (cotización paralela), flat (tarifa fija), free (gratis),
 *        free_absorbed (vendedor absorbe), hybrid (gratis desde X monto).
 *
 * @package LTMS\Business
 * @version 2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Mode {

    // ── Constantes de modo ────────────────────────────────────────────────
    const MODE_QUOTED       = 'quoted';
    const MODE_FLAT         = 'flat';
    const MODE_FREE         = 'free';
    const MODE_FREE_ABSORBED = 'free_absorbed';
    const MODE_HYBRID       = 'hybrid';

    // ── Modo global de la plataforma ──────────────────────────────────────

    public static function get_global_mode(): string {
        return class_exists( 'LTMS_Core_Config' )
            ? (string) LTMS_Core_Config::get( 'ltms_shipping_mode', self::MODE_FLAT )
            : self::MODE_FLAT;
    }

    /**
     * Modo efectivo para un vendedor concreto.
     * Si el vendedor tiene override configurado, ese tiene prioridad.
     */
    public static function get_vendor_mode( int $vendor_id ): string {
        if ( $vendor_id > 0 ) {
            $override = get_user_meta( $vendor_id, '_ltms_shipping_mode', true );
            if ( $override && in_array( $override, self::valid_modes(), true ) ) {
                return $override;
            }
        }
        return self::get_global_mode();
    }

    public static function valid_modes(): array {
        return [
            self::MODE_QUOTED,
            self::MODE_FLAT,
            self::MODE_FREE,
            self::MODE_FREE_ABSORBED,
            self::MODE_HYBRID,
        ];
    }

    // ── Cálculo principal ─────────────────────────────────────────────────

    /**
     * Calcula el costo de envío según el modo configurado.
     *
     * @param array $package  Paquete WooCommerce.
     * @param int   $vendor_id Vendedor (0 = global).
     * @return float|null  null = WC calcula, 0.0 = gratis, float = tarifa fija.
     */
    public static function calculate_shipping( array $package, int $vendor_id = 0 ): ?float {
        try {
            $mode = self::get_vendor_mode( $vendor_id );

            switch ( $mode ) {
                case self::MODE_FREE:
                case self::MODE_FREE_ABSORBED:
                    return 0.0;

                case self::MODE_HYBRID:
                    return self::calculate_hybrid( $package, $vendor_id );

                case self::MODE_FLAT:
                    return self::get_flat_rate( $vendor_id );

                case self::MODE_QUOTED:
                default:
                    return null; // WC cotiza con proveedores
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS ShippingMode: ' . $e->getMessage() );
            return null;
        }
    }

    // ── Modo hybrid ───────────────────────────────────────────────────────

    /**
     * Gratis si el subtotal del paquete supera el umbral configurado.
     */
    private static function calculate_hybrid( array $package, int $vendor_id ): float {
        $threshold = self::get_hybrid_threshold( $vendor_id );
        $subtotal  = self::package_subtotal( $package );
        return ( $subtotal >= $threshold ) ? 0.0 : self::get_flat_rate( $vendor_id );
    }

    private static function get_hybrid_threshold( int $vendor_id ): float {
        if ( $vendor_id > 0 ) {
            $v = (float) get_user_meta( $vendor_id, '_ltms_hybrid_threshold', true );
            if ( $v > 0 ) return $v;
        }
        return class_exists( 'LTMS_Core_Config' )
            ? (float) LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 )
            : 100000.0;
    }

    private static function get_flat_rate( int $vendor_id ): float {
        if ( $vendor_id > 0 ) {
            $v = (float) get_user_meta( $vendor_id, '_ltms_flat_shipping_rate', true );
            if ( $v > 0 ) return $v;
        }
        return class_exists( 'LTMS_Core_Config' )
            ? (float) LTMS_Core_Config::get( 'ltms_shipping_flat_rate', 8500 )
            : 8500.0;
    }

    private static function package_subtotal( array $package ): float {
        $total = 0.0;
        foreach ( $package['contents'] ?? [] as $item ) {
            $total += (float) ( $item['line_total'] ?? 0 );
        }
        return $total;
    }

    // ── Calculadora de flete (endpoint REST) ──────────────────────────────

    /**
     * Estima el costo de envío para un producto antes de publicarlo.
     * Usado por el panel vendedor al crear/editar productos.
     *
     * @param float  $weight_kg   Peso en kg.
     * @param array  $dimensions  ['length', 'width', 'height'] en cm.
     * @param int    $vendor_id   ID del vendedor.
     * @return array  [ ['city' => string, 'cost' => float, 'provider' => string] ]
     */
    public static function estimate_shipping_cost(
        float $weight_kg,
        array $dimensions,
        int   $vendor_id
    ): array {
        $estimates = [];
        $cities    = self::main_cities();
        $mode      = self::get_vendor_mode( $vendor_id );

        // En modo free_absorbed el costo siempre es 0 para el cliente
        if ( in_array( $mode, [ self::MODE_FREE, self::MODE_FREE_ABSORBED ], true ) ) {
            foreach ( $cities as $city ) {
                $estimates[] = [
                    'city'     => $city['name'],
                    'cost'     => 0.0,
                    'provider' => 'Incluido en precio',
                    'note'     => 'El vendedor absorbe el envío',
                ];
            }
            return $estimates;
        }

        // Cotización paralela si está disponible
        if ( class_exists( 'LTMS_Shipping_Parallel_Quoter' ) ) {
            foreach ( $cities as $city ) {
                $package = self::build_fake_package( $weight_kg, $dimensions, $city );
                $quote   = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote( $package );
                $estimates[] = [
                    'city'     => $city['name'],
                    'cost'     => $quote ? $quote['cost'] : self::estimate_by_weight( $weight_kg ),
                    'provider' => $quote ? $quote['provider'] : 'Estimado',
                    'note'     => $quote ? '' : 'Estimación por peso',
                ];
            }
            return $estimates;
        }

        // Fallback: estimación por peso
        foreach ( $cities as $city ) {
            $estimates[] = [
                'city'     => $city['name'],
                'cost'     => self::estimate_by_weight( $weight_kg, $city['distance_factor'] ),
                'provider' => 'Estimado',
                'note'     => 'Estimación por peso',
            ];
        }
        return $estimates;
    }

    /**
     * Estimación básica por peso cuando no hay cotizador disponible.
     */
    public static function estimate_by_weight( float $weight_kg, float $factor = 1.0 ): float {
        // Tarifa base Colombia: ~7.500 COP/kg + base 5.000
        $base = 5000.0;
        $per_kg = 7500.0;
        return round( ( $base + $per_kg * $weight_kg ) * $factor, -2 );
    }

    private static function main_cities(): array {
        return [
            [ 'name' => 'Bogotá',       'distance_factor' => 1.0  ],
            [ 'name' => 'Medellín',     'distance_factor' => 1.1  ],
            [ 'name' => 'Cali',         'distance_factor' => 1.1  ],
            [ 'name' => 'Barranquilla', 'distance_factor' => 1.3  ],
            [ 'name' => 'Bucaramanga',  'distance_factor' => 1.2  ],
            [ 'name' => 'Pereira',      'distance_factor' => 1.15 ],
        ];
    }

    private static function build_fake_package( float $weight, array $dims, array $city ): array {
        return [
            'destination' => [
                'city'    => $city['name'],
                'state'   => '',
                'country' => 'CO',
            ],
            'contents' => [
                [
                    'data'       => null,
                    'quantity'   => 1,
                    'line_total' => 50000,
                ],
            ],
            'weight'     => $weight,
            'dimensions' => $dims,
        ];
    }

    // ── REST API ──────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
        // Hook en WooCommerce para interceptar el cálculo de envío
        add_filter( 'woocommerce_package_rates', [ self::class, 'filter_wc_rates' ], 20, 2 );
    }

    public static function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/shipping/estimate', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_estimate' ],
            'permission_callback' => [ self::class, 'rest_permission' ],
            'args'                => [
                'weight'    => [ 'required' => true, 'type' => 'number' ],
                'length'    => [ 'required' => false, 'type' => 'number', 'default' => 20 ],
                'width'     => [ 'required' => false, 'type' => 'number', 'default' => 15 ],
                'height'    => [ 'required' => false, 'type' => 'number', 'default' => 10 ],
                'vendor_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            ],
        ] );

        register_rest_route( 'ltms/v1', '/shipping/mode', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'rest_get_mode' ],
            'permission_callback' => [ self::class, 'rest_permission' ],
        ] );

        register_rest_route( 'ltms/v1', '/shipping/mode', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_set_mode' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_woocommerce' );
            },
            'args'                => [
                'mode'      => [ 'required' => true, 'type' => 'string' ],
                'vendor_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            ],
        ] );
    }

    public static function rest_permission(): bool {
        return is_user_logged_in();
    }

    public static function rest_estimate( \WP_REST_Request $request ): \WP_REST_Response {
        $estimates = self::estimate_shipping_cost(
            (float) $request->get_param( 'weight' ),
            [
                'length' => (float) $request->get_param( 'length' ),
                'width'  => (float) $request->get_param( 'width' ),
                'height' => (float) $request->get_param( 'height' ),
            ],
            (int) $request->get_param( 'vendor_id' )
        );
        return new \WP_REST_Response( [ 'estimates' => $estimates ], 200 );
    }

    public static function rest_get_mode( \WP_REST_Request $request ): \WP_REST_Response {
        $vendor_id = (int) $request->get_param( 'vendor_id' );
        return new \WP_REST_Response( [
            'global_mode' => self::get_global_mode(),
            'vendor_mode' => self::get_vendor_mode( $vendor_id ),
            'vendor_id'   => $vendor_id,
        ], 200 );
    }

    public static function rest_set_mode( \WP_REST_Request $request ): \WP_REST_Response {
        $mode      = sanitize_text_field( $request->get_param( 'mode' ) );
        $vendor_id = (int) $request->get_param( 'vendor_id' );

        if ( ! in_array( $mode, self::valid_modes(), true ) ) {
            return new \WP_REST_Response( [ 'error' => 'Modo inválido' ], 400 );
        }

        if ( $vendor_id > 0 ) {
            update_user_meta( $vendor_id, '_ltms_shipping_mode', $mode );
            $msg = "Modo de envío del vendedor #{$vendor_id} actualizado a: {$mode}";
        } else {
            if ( class_exists( 'LTMS_Core_Config' ) ) {
                LTMS_Core_Config::set( 'ltms_shipping_mode', $mode );
            }
            $msg = "Modo de envío global actualizado a: {$mode}";
        }

        return new \WP_REST_Response( [ 'success' => true, 'message' => $msg ], 200 );
    }

    // ── Filtro WooCommerce ────────────────────────────────────────────────

    /**
     * Intercepta las tarifas de WooCommerce.
     * En modo free/free_absorbed fuerza $0. En hybrid aplica umbral.
     */
    public static function filter_wc_rates( array $rates, array $package ): array {
        $vendor_id = self::extract_vendor_from_package( $package );
        $mode      = self::get_vendor_mode( $vendor_id );

        if ( in_array( $mode, [ self::MODE_FREE, self::MODE_FREE_ABSORBED ], true ) ) {
            return self::force_free_rates( $rates );
        }

        if ( $mode === self::MODE_HYBRID ) {
            $subtotal   = self::package_subtotal( $package );
            $threshold  = self::get_hybrid_threshold( $vendor_id );
            if ( $subtotal >= $threshold ) {
                return self::force_free_rates( $rates );
            }
        }

        return $rates;
    }

    private static function force_free_rates( array $rates ): array {
        foreach ( $rates as $key => $rate ) {
            $rates[ $key ]->cost  = 0;
            $rates[ $key ]->taxes = [];
        }
        return $rates;
    }

    private static function extract_vendor_from_package( array $package ): int {
        foreach ( $package['contents'] ?? [] as $item ) {
            $product_id = $item['product_id'] ?? 0;
            if ( $product_id ) {
                $vendor_id = (int) get_post_field( 'post_author', $product_id );
                if ( $vendor_id ) return $vendor_id;
            }
        }
        return 0;
    }
}
