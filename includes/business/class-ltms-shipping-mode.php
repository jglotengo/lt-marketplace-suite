<?php
/**
 * LTMS Shipping Mode — F-08 (v2.8.4)
 * Modos: quoted (cotización paralela), flat (tarifa fija), free (gratis),
 *        free_absorbed (vendedor absorbe), hybrid (gratis desde X monto),
 *        shared (cliente paga %, resto absorbido).
 *
 * Estrategia multi-capa (v2.8.4):
 *  1. Override por categoría (mapeo cat_id → modo).
 *  2. Override por vendedor (user_meta _ltms_shipping_mode).
 *  3. Modo global (default: hybrid).
 *
 * Resolución: categoría > vendedor > global.
 *
 * @package LTMS\Business
 * @version 2.8.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Shipping_Mode {

    // ── Constantes de modo ────────────────────────────────────────────────
    const MODE_QUOTED       = 'quoted';
    const MODE_FLAT         = 'flat';
    const MODE_FREE         = 'free';
    const MODE_FREE_ABSORBED = 'free_absorbed';
    const MODE_HYBRID       = 'hybrid';
    const MODE_SHARED       = 'shared'; // v2.8.4: cliente paga %, resto absorbido.

    // ── Modo global de la plataforma ──────────────────────────────────────

    public static function get_global_mode(): string {
        // v2.8.4: default cambia de 'flat' a 'hybrid' (estrategia recomendada).
        return class_exists( 'LTMS_Core_Config' )
            ? (string) LTMS_Core_Config::get( 'ltms_shipping_mode', self::MODE_HYBRID )
            : self::MODE_HYBRID;
    }

    /**
     * Modo efectivo para un vendedor concreto.
     * Si el vendedor tiene override configurado, ese tiene prioridad sobre el global.
     * Las categorías tienen prioridad sobre el vendedor (ver get_effective_mode_for_package).
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
            self::MODE_SHARED,
        ];
    }

    // ── v2.8.4: Override por categoría ────────────────────────────────────

    /**
     * Obtiene el modo configurado para una categoría específica.
     *
     * @param int $category_id ID de la categoría (product_cat).
     * @return string|null Modo o null si no hay override.
     */
    public static function get_category_mode( int $category_id ): ?string {
        if ( $category_id <= 0 ) return null;
        $mode = get_term_meta( $category_id, '_ltms_shipping_mode', true );
        if ( $mode && in_array( $mode, self::valid_modes(), true ) ) {
            return $mode;
        }
        return null;
    }

    /**
     * Configura el modo de envío para una categoría.
     *
     * @param int    $category_id
     * @param string $mode  Modo válido o '' para limpiar.
     * @return bool
     */
    public static function set_category_mode( int $category_id, string $mode ): bool {
        if ( $category_id <= 0 ) return false;
        if ( empty( $mode ) ) {
            return delete_term_meta( $category_id, '_ltms_shipping_mode' );
        }
        if ( ! in_array( $mode, self::valid_modes(), true ) ) return false;
        return (bool) update_term_meta( $category_id, '_ltms_shipping_mode', $mode );
    }

    /**
     * Obtiene TODAS las categorías con override de modo configurado.
     *
     * @return array [cat_id => ['name' => string, 'mode' => string]]
     */
    public static function get_all_category_overrides(): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'number'     => 0,
            'meta_query' => [
                [
                    'key'     => '_ltms_shipping_mode',
                    'compare' => 'EXISTS',
                ],
            ],
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $result = [];
        foreach ( $terms as $term ) {
            $mode = self::get_category_mode( $term->term_id );
            if ( $mode ) {
                $result[ $term->term_id ] = [
                    'name' => $term->name,
                    'mode' => $mode,
                ];
            }
        }
        return $result;
    }

    /**
     * Resuelve el modo efectivo para un paquete WC completo (todos sus items).
     *
     * Estrategia de resolución:
     *  1. Si TODOS los items del paquete pertenecen a categorías con el MISMO
     *     override → usar ese modo.
     *  2. Si los items tienen overrides DISTINTOS → usar el modo del item más
     *     restrictivo (prioridad: quoted > flat > shared > hybrid > free_absorbed > free).
     *     Razón: si un carrito tiene un mueble (quoted) y un libro (free), el cliente
     *     debe ver el costo del mueble para evitar pérdida.
     *  3. Si NINGÚN item tiene override → usar modo del vendor o global.
     *
     * @param array $package
     * @return string Modo efectivo.
     */
    public static function get_effective_mode_for_package( array $package ): string {
        $vendor_id = self::extract_vendor_from_package( $package );

        // 1. Recopilar modos por categoría de cada item.
        $item_modes = [];
        foreach ( $package['contents'] ?? [] as $item ) {
            $product_id = (int) ( $item['product_id'] ?? 0 );
            if ( ! $product_id ) continue;

            $cat_ids = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
            if ( is_wp_error( $cat_ids ) || empty( $cat_ids ) ) {
                $item_modes[] = null; // Sin categoría → usa fallback.
                continue;
            }

            // Buscar el primer override de categoría encontrado (jerarquía: más específica primero).
            $mode_found = null;
            foreach ( $cat_ids as $cat_id ) {
                $cat_mode = self::get_category_mode( (int) $cat_id );
                if ( $cat_mode ) {
                    $mode_found = $cat_mode;
                    break;
                }
            }
            $item_modes[] = $mode_found;
        }

        // 2. Si todos los items con override coinciden → ese es el modo.
        $overrides_only = array_filter( $item_modes, fn( $m ) => $m !== null );
        if ( ! empty( $overrides_only ) ) {
            $unique = array_unique( $overrides_only );
            if ( count( $unique ) === 1 ) {
                return reset( $unique );
            }
            // 3. Modos distintos → resolver por prioridad restrictiva.
            return self::resolve_mode_conflict( $overrides_only );
        }

        // 4. Sin overrides → modo vendor o global.
        return self::get_vendor_mode( $vendor_id );
    }

    /**
     * Resuelve conflicto de modos en carrito multi-categoría.
     * Prioridad: el modo MÁS restrictivo gana (para evitar pérdida).
     *
     * Orden restrictivo (1 = más restrictivo, 6 = menos):
     *  1. quoted   (cliente siempre paga)
     *  2. flat     (cliente paga tarifa fija)
     *  3. shared   (cliente paga %)
     *  4. hybrid   (cliente paga si < umbral)
     *  5. free_absorbed (vendor absorbe)
     *  6. free     (plataforma absorbe)
     */
    private static function resolve_mode_conflict( array $modes ): string {
        $priority = [
            self::MODE_QUOTED       => 1,
            self::MODE_FLAT         => 2,
            self::MODE_SHARED       => 3,
            self::MODE_HYBRID       => 4,
            self::MODE_FREE_ABSORBED=> 5,
            self::MODE_FREE         => 6,
        ];
        $best_mode = self::MODE_HYBRID;
        $best_priority = 99;
        foreach ( $modes as $m ) {
            $p = $priority[ $m ] ?? 99;
            if ( $p < $best_priority ) {
                $best_priority = $p;
                $best_mode = $m;
            }
        }
        return $best_mode;
    }

    // ── Cálculo principal ─────────────────────────────────────────────────

    /**
     * Calcula el costo de envío según el modo efectivo del paquete.
     * Resolución: categoría > vendedor > global.
     *
     * @param array $package  Paquete WooCommerce.
     * @param int   $vendor_id Vendedor (0 = global). Solo usado como fallback.
     * @return float|null  null = WC calcula, 0.0 = gratis, float = tarifa.
     */
    public static function calculate_shipping( array $package, int $vendor_id = 0 ): ?float {
        try {
            // v2.8.4: usar modo efectivo del paquete (incluye override por categoría).
            $mode = self::get_effective_mode_for_package( $package );

            switch ( $mode ) {
                case self::MODE_FREE:
                case self::MODE_FREE_ABSORBED:
                    return 0.0;

                case self::MODE_HYBRID:
                    return self::calculate_hybrid( $package, $vendor_id );

                case self::MODE_SHARED:
                    return self::calculate_shared( $package, $vendor_id );

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

    // ── Modo shared (v2.8.4) ──────────────────────────────────────────────

    /**
     * Cliente paga un % configurable del costo real del carrier; el resto lo
     * absorbe el vendedor (debitado de su billetera).
     *
     * Ejemplo: si % compartido = 60% y cotización carrier = $10.000:
     *   - Cliente paga $6.000 al checkout.
     *   - Vendor absorbe $4.000 (debitado de su billetera al pagar el pedido).
     *
     * Si no hay cotización disponible (carrier caído), usa flat_rate * %.
     */
    private static function calculate_shared( array $package, int $vendor_id ): ?float {
        $share_pct = self::get_shared_customer_pct();

        // Intentar obtener cotización real del carrier.
        $quote_cost = 0.0;
        try {
            if ( class_exists( 'LTMS_Shipping_Parallel_Quoter' ) ) {
                $quote = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote( $package );
                if ( $quote && isset( $quote['cost'] ) ) {
                    $quote_cost = (float) $quote['cost'];
                }
            }
        } catch ( \Throwable $e ) {
            // Sin cotización, usar flat_rate como base.
        }

        // Fallback: si no hay cotización, usar flat_rate del vendor.
        if ( $quote_cost <= 0 ) {
            $quote_cost = self::get_flat_rate( $vendor_id );
        }

        // Cliente paga su %.
        $customer_pays = round( $quote_cost * ( $share_pct / 100 ), 2 );

        // Persistir para que el listener post-pago debite el resto al vendor.
        if ( WC()->session ) {
            WC()->session->set( 'ltms_shared_shipping_quote', [
                'provider'      => 'shared',
                'cost'          => $quote_cost,
                'customer_pays' => $customer_pays,
                'vendor_pays'   => round( $quote_cost - $customer_pays, 2 ),
                'share_pct'     => $share_pct,
            ] );
        }

        return $customer_pays;
    }

    /**
     * % que paga el cliente en modo SHARED (default 60%).
     * El resto (40%) lo absorbe el vendedor.
     */
    public static function get_shared_customer_pct(): float {
        return class_exists( 'LTMS_Core_Config' )
            ? (float) LTMS_Core_Config::get( 'ltms_shipping_shared_customer_pct', 60.0 )
            : 60.0;
    }

    public static function set_shared_customer_pct( float $pct ): bool {
        if ( $pct < 0 || $pct > 100 ) return false;
        if ( ! class_exists( 'LTMS_Core_Config' ) ) return false;
        return LTMS_Core_Config::set( 'ltms_shipping_shared_customer_pct', $pct );
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
     * v2.8.4: usa modo efectivo del paquete (categoría > vendor > global).
     *
     * Comportamiento por modo:
     *  - free / free_absorbed: fuerza $0 al cliente.
     *  - hybrid: $0 si subtotal >= umbral, si no deja tarifas de WC.
     *  - shared: ajusta cada tarifa al % que paga el cliente (default 60%).
     *  - flat: reemplaza todas las tarifas por una única tarifa plana.
     *  - quoted: no interviene (WC muestra todas las cotizaciones).
     */
    public static function filter_wc_rates( array $rates, array $package ): array {
        $vendor_id = self::extract_vendor_from_package( $package );
        $mode      = self::get_effective_mode_for_package( $package );

        if ( in_array( $mode, [ self::MODE_FREE, self::MODE_FREE_ABSORBED ], true ) ) {
            return self::force_free_rates( $rates );
        }

        if ( $mode === self::MODE_HYBRID ) {
            $subtotal   = self::package_subtotal( $package );
            $threshold  = self::get_hybrid_threshold( $vendor_id );
            if ( $subtotal >= $threshold ) {
                return self::force_free_rates( $rates );
            }
            // Por debajo del umbral: dejar tarifas de WC (quoted).
            // v2.8.4 FIX: si no hay tarifas (carriers caídos), agregar fallback flat_rate.
            $carrier_rates_only = array_filter( $rates, function( $rate ) {
                $method_id = $rate->get_method_id() ?: '';
                return ! in_array( $method_id, [ 'pickup', 'local_pickup', 'ltms_pickup', 'ltms_own_delivery', 'own_delivery', 'free_absorbed', 'ltms_free_absorbed' ], true );
            });
            if ( empty( $carrier_rates_only ) && class_exists( 'WC_Shipping_Rate' ) ) {
                $flat = self::get_flat_rate( $vendor_id );
                $fallback = new \WC_Shipping_Rate(
                    'ltms_hybrid_fallback',
                    __( 'Envío estándar', 'ltms' ),
                    $flat,
                    [],
                    'flat_rate'
                );
                return [ 'ltms_hybrid_fallback' => $fallback ];
            }
            return $rates;
        }

        if ( $mode === self::MODE_SHARED ) {
            return self::apply_shared_rates( $rates, $package, $vendor_id );
        }

        if ( $mode === self::MODE_FLAT ) {
            $flat = self::get_flat_rate( $vendor_id );
            return self::force_flat_rate( $rates, $flat );
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

    /**
     * Reemplaza todas las tarifas por una única tarifa plana.
     * v2.8.4 FIX: crea una tarifa nueva con etiqueta clara en lugar de
     * reusar la primera (que podría ser pickup/free_absorbed con etiqueta engañosa).
     */
    private static function force_flat_rate( array $rates, float $flat ): array {
        if ( empty( $rates ) ) return $rates;

        // Tomar la primera tarifa como plantilla para preservar taxes/meta.
        $keys = array_keys( $rates );
        $first_key = $keys[0];
        $first_rate = $rates[ $first_key ];

        // Crear una tarifa nueva con la etiqueta correcta del modo flat.
        if ( class_exists( 'WC_Shipping_Rate' ) ) {
            $flat_rate = new \WC_Shipping_Rate(
                'ltms_flat_rate',
                __( 'Envío estándar', 'ltms' ),
                $flat,
                [],
                'flat_rate'
            );
            return [ 'ltms_flat_rate' => $flat_rate ];
        }

        // Fallback: ajustar la primera tarifa.
        $first_rate->cost = $flat;
        $first_rate->taxes = [];
        return [ $first_key => $first_rate ];
    }

    /**
     * Modo SHARED: ajusta cada tarifa al % que paga el cliente.
     * El resto se debita del vendor al pagar el pedido (save_shared_shipping_quote).
     *
     * v2.8.4 FIX: no ajusta tarifas de pickup (cost=0) ni own_delivery
     * (que son entregas del propio vendor, no carriers externos).
     */
    private static function apply_shared_rates( array $rates, array $package, int $vendor_id ): array {
        $share_pct = self::get_shared_customer_pct();

        // Carrier methods que NO aplican para shared (son entregas del vendor).
        $skip_methods = [ 'pickup', 'local_pickup', 'ltms_pickup', 'ltms_own_delivery', 'own_delivery' ];

        // Persistir la cotización para el listener post-pago (usar la tarifa más baja > 0).
        $quote_cost = 0.0;
        $provider   = 'unknown';
        $cheapest_rate = null;
        foreach ( $rates as $key => $rate ) {
            $method_id = $rate->get_method_id() ?: '';
            $cost = (float) $rate->cost;
            // Skip pickup/own_delivery.
            if ( in_array( $method_id, $skip_methods, true ) ) continue;
            if ( $cost > 0 && ( $quote_cost === 0.0 || $cost < $quote_cost ) ) {
                $quote_cost = $cost;
                $provider   = $method_id;
                $cheapest_rate = $rate;
            }
        }

        if ( $quote_cost > 0 && WC()->session ) {
            $customer_pays = round( $quote_cost * ( $share_pct / 100 ), 2 );
            $vendor_pays   = round( $quote_cost - $customer_pays, 2 );
            WC()->session->set( 'ltms_shared_shipping_quote', [
                'provider'      => $provider,
                'cost'          => $quote_cost,
                'customer_pays' => $customer_pays,
                'vendor_pays'   => $vendor_pays,
                'share_pct'     => $share_pct,
            ] );
        }

        // Ajustar solo las tarifas de carriers (no pickup/own_delivery).
        foreach ( $rates as $key => $rate ) {
            $method_id = $rate->get_method_id() ?: '';
            if ( in_array( $method_id, $skip_methods, true ) ) {
                continue; // No tocar tarifas del vendor.
            }
            $original = (float) $rate->cost;
            if ( $original <= 0 ) continue; // No tocar tarifas $0 (free_absorbed).
            $rates[ $key ]->cost = round( $original * ( $share_pct / 100 ), 2 );
            $rates[ $key ]->taxes = []; // Recalcular taxes lo hace WC si aplica.
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
