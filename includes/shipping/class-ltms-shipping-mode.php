<?php
/**
 * LTMS Shipping Mode
 *
 * Gestiona el modo de envío por vendedor:
 *   - flat          : flete cotizado visible al cliente (default)
 *   - free_absorbed : precio todo incluido, flete absorbido por el vendedor
 *   - hybrid        : por producto; categorías configurables muestran flete o lo absorben
 *
 * Meta del vendedor: _ltms_shipping_mode  (flat|free_absorbed|hybrid)
 * Opción global    : ltms_shipping_mode   (fallback)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/shipping
 * @version    2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Shipping_Mode
 */
class LTMS_Shipping_Mode {

    // ── Constantes de modo ────────────────────────────────────────────────
    public const MODE_FLAT          = 'flat';
    public const MODE_FREE_ABSORBED = 'free_absorbed';
    public const MODE_HYBRID        = 'hybrid';

    /** Modos válidos. */
    public const VALID_MODES = [
        self::MODE_FLAT,
        self::MODE_FREE_ABSORBED,
        self::MODE_HYBRID,
    ];

    private static bool $initialized = false;

    // ── Boot ──────────────────────────────────────────────────────────────

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        // Registrar endpoint REST de cotización.
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

        // Guardar modo al salvar configuración del vendedor.
        add_action( 'ltms_vendor_settings_saved', [ self::class, 'save_vendor_mode_from_post' ], 10, 2 );
    }

    // ── API pública ───────────────────────────────────────────────────────

    /**
     * Obtiene el modo de envío efectivo para un vendedor.
     * Prioridad: meta del vendedor → opción global → 'flat'.
     *
     * @param int $vendor_id  0 = usar solo la opción global.
     * @return string  Una de las constantes MODE_*.
     */
    public static function get_mode_for_vendor( int $vendor_id ): string {
        if ( $vendor_id > 0 ) {
            $meta = get_user_meta( $vendor_id, '_ltms_shipping_mode', true );
            if ( $meta && in_array( $meta, self::VALID_MODES, true ) ) {
                return $meta;
            }
        }

        $global = LTMS_Core_Config::get( 'ltms_shipping_mode', self::MODE_FLAT );
        return in_array( $global, self::VALID_MODES, true ) ? $global : self::MODE_FLAT;
    }

    /**
     * Guarda el modo de envío para un vendedor.
     *
     * @param int    $vendor_id
     * @param string $mode  Una de las constantes MODE_*.
     * @return bool  True si el valor fue actualizado o ya era el mismo.
     */
    public static function set_mode_for_vendor( int $vendor_id, string $mode ): bool {
        if ( $vendor_id <= 0 ) return false;
        if ( ! in_array( $mode, self::VALID_MODES, true ) ) return false;

        return (bool) update_user_meta( $vendor_id, '_ltms_shipping_mode', $mode );
    }

    /**
     * Calcula el costo de envío para un paquete WC según el modo del vendedor.
     *
     * - flat          → devuelve la tarifa plana configurada (ltms_flat_rate_{vendor_id} o global)
     * - free_absorbed → devuelve 0.0 siempre
     * - hybrid        → 0.0 si todos los productos del paquete tienen flete absorbido,
     *                   tarifa plana si alguno requiere cotización visible
     *
     * @param int   $vendor_id
     * @param float $cart_total  Subtotal del carrito (para posibles umbrales).
     * @param array $package     Paquete WooCommerce (con 'contents').
     * @return float  Costo calculado.
     */
    public static function calculate_shipping( int $vendor_id, float $cart_total, array $package ): float {
        $mode = self::get_mode_for_vendor( $vendor_id );

        switch ( $mode ) {

            case self::MODE_FREE_ABSORBED:
                return 0.0;

            case self::MODE_HYBRID:
                return self::calculate_hybrid( $vendor_id, $cart_total, $package );

            case self::MODE_FLAT:
            default:
                return self::get_flat_rate( $vendor_id );
        }
    }

    /**
     * Indica si un paquete debería mostrar el flete como gratuito al cliente.
     * Útil para condicionar la visualización en el checkout.
     */
    public static function is_free_for_customer( int $vendor_id, array $package = [] ): bool {
        $mode = self::get_mode_for_vendor( $vendor_id );
        if ( self::MODE_FREE_ABSORBED === $mode ) return true;
        if ( self::MODE_HYBRID === $mode ) {
            return self::calculate_hybrid( $vendor_id, 0.0, $package ) === 0.0;
        }
        return false;
    }

    // ── REST endpoint ─────────────────────────────────────────────────────

    /**
     * Registra GET /ltms/v1/shipping/quote
     * Parámetros: vendor_id (int), cart_total (float), product_ids (string CSV)
     */
    public static function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/shipping/quote', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'rest_quote' ],
            'permission_callback' => [ self::class, 'rest_permission' ],
            'args'                => [
                'vendor_id'   => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
                'cart_total'  => [ 'type' => 'number',  'default'  => 0 ],
                'product_ids' => [ 'type' => 'string',  'default'  => '' ],
            ],
        ] );
    }

    /** Solo vendedores autenticados o admins pueden cotizar. */
    public static function rest_permission( \WP_REST_Request $request ): bool {
        return is_user_logged_in();
    }

    public static function rest_quote( \WP_REST_Request $request ): \WP_REST_Response {
        $vendor_id  = (int) $request->get_param( 'vendor_id' );
        $cart_total = (float) $request->get_param( 'cart_total' );
        $mode       = self::get_mode_for_vendor( $vendor_id );
        $cost       = self::calculate_shipping( $vendor_id, $cart_total, [] );

        return new \WP_REST_Response( [
            'vendor_id'   => $vendor_id,
            'mode'        => $mode,
            'cost'        => $cost,
            'free'        => $cost === 0.0,
            'flat_rate'   => self::get_flat_rate( $vendor_id ),
        ], 200 );
    }

    // ── Hooks ─────────────────────────────────────────────────────────────

    /**
     * Guarda el modo desde el POST del panel vendedor.
     * Se activa via do_action( 'ltms_vendor_settings_saved', $vendor_id, $_POST ).
     */
    public static function save_vendor_mode_from_post( int $vendor_id, array $post_data ): void {
        $mode = sanitize_key( $post_data['ltms_shipping_mode'] ?? '' );
        if ( $mode ) {
            self::set_mode_for_vendor( $vendor_id, $mode );
        }
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * Tarifa plana para un vendedor.
     * Busca primero el meta del vendedor; si no, la opción global.
     */
    private static function get_flat_rate( int $vendor_id ): float {
        if ( $vendor_id > 0 ) {
            $rate = get_user_meta( $vendor_id, '_ltms_flat_shipping_rate', true );
            if ( $rate !== '' && $rate !== false ) {
                return max( 0.0, (float) $rate );
            }
        }
        return max( 0.0, (float) LTMS_Core_Config::get( 'ltms_flat_shipping_rate', 0 ) );
    }

    /**
     * Lógica híbrida: si TODOS los productos del paquete pertenecen a categorías
     * de "flete absorbido", devuelve 0; de lo contrario, tarifa plana.
     */
    private static function calculate_hybrid( int $vendor_id, float $cart_total, array $package ): float {
        $free_cats_raw = LTMS_Core_Config::get( 'ltms_shipping_free_categories', '' );
        if ( ! $free_cats_raw ) {
            return self::get_flat_rate( $vendor_id );
        }

        $free_cat_ids = array_filter( array_map( 'intval', explode( ',', $free_cats_raw ) ) );
        if ( empty( $free_cat_ids ) ) {
            return self::get_flat_rate( $vendor_id );
        }

        $contents = $package['contents'] ?? [];
        if ( empty( $contents ) ) {
            return 0.0;
        }

        foreach ( $contents as $item ) {
            $product_id   = (int) ( $item['product_id'] ?? 0 );
            $product_cats = function_exists( 'wc_get_product_term_ids' )
                ? wc_get_product_term_ids( $product_id, 'product_cat' )
                : [];

            // Si este producto NO está en ninguna categoría de flete absorbido → tarifa plana.
            if ( empty( array_intersect( $product_cats, $free_cat_ids ) ) ) {
                return self::get_flat_rate( $vendor_id );
            }
        }

        // Todos los productos tienen flete absorbido.
        return 0.0;
    }
}
