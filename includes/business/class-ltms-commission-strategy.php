<?php
/**
 * LTMS Commission Strategy - Gestión de Estrategias de Comisiones
 *
 * Define las diferentes tasas y lógicas de comisión según:
 * - Categoría del producto
 * - Plan del vendedor (básico, premium)
 * - Volumen de ventas (tiers)
 * - País / moneda
 * - Acuerdos especiales por vendedor
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Commission_Strategy
 */
final class LTMS_Commission_Strategy {

    use LTMS_Logger_Aware;

    /** Tasa base de comisión por defecto. */
    const DEFAULT_RATE = 0.10; // 10%

    /**
     * Calcula la tasa de comisión efectiva para un vendedor y pedido.
     *
     * @param int       $vendor_id ID del vendedor.
     * @param \WC_Order $order     Pedido.
     * @return float Tasa decimal (0.10 = 10%).
     */
    public static function get_rate( int $vendor_id, \WC_Order $order ): float {
        // 1. Verificar tasa especial por contrato individual
        $custom_rate = get_user_meta( $vendor_id, 'ltms_custom_commission_rate', true );
        if ( $custom_rate !== '' && is_numeric( $custom_rate ) ) {
            $rate = (float) $custom_rate;
            if ( $rate >= 0 && $rate <= 1 ) {
                return $rate;
            }
        }

        // 2. Tasa por tier de volumen de ventas
        $tier_rate = self::get_volume_tier_rate( $vendor_id );
        if ( $tier_rate !== null ) {
            return $tier_rate;
        }

        // 3. Tasa por categoría del producto
        $category_rate = self::get_category_rate( $order );
        if ( $category_rate !== null ) {
            return $category_rate;
        }

        // 4. Tasa según plan del vendedor (premium vs básico)
        $plan_rate = self::get_plan_rate( $vendor_id );
        if ( $plan_rate !== null ) {
            return $plan_rate;
        }

        // 5. Tasa global configurada
        $global_rate = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', self::DEFAULT_RATE );

        return max( 0.0, min( 1.0, $global_rate ) );
    }

    /**
     * Calcula la tasa de comisión según el tier de volumen mensual de ventas.
     *
     * Tiers (CO):
     * - Tier 1: < $5.000.000 COP/mes → 12%
     * - Tier 2: $5M - $20M COP/mes → 10%
     * - Tier 3: $20M - $50M COP/mes → 8%
     * - Tier 4: > $50M COP/mes → 6%
     *
     * @param int $vendor_id ID del vendedor.
     * @return float|null Tasa del tier o null si no aplica.
     */
    private static function get_volume_tier_rate( int $vendor_id ): ?float {
        $tiers_enabled = LTMS_Core_Config::get( 'ltms_volume_tiers_enabled', 'no' );
        if ( $tiers_enabled !== 'yes' ) {
            return null;
        }

        // Obtener ventas del mes actual del vendedor
        $monthly_sales = self::get_vendor_monthly_sales( $vendor_id );
        $country       = LTMS_Core_Config::get_country();

        if ( $country === 'CO' ) {
            // Tiers en COP
            if ( $monthly_sales >= 50_000_000 ) return 0.06;
            if ( $monthly_sales >= 20_000_000 ) return 0.08;
            if ( $monthly_sales >=  5_000_000 ) return 0.10;
            return 0.12;
        }

        if ( $country === 'MX' ) {
            // Tiers en MXN
            if ( $monthly_sales >= 300_000 ) return 0.06;
            if ( $monthly_sales >= 100_000 ) return 0.08;
            if ( $monthly_sales >=  25_000 ) return 0.10;
            return 0.12;
        }

        return null;
    }

    /**
     * Obtiene la tasa de comisión según la categoría del producto.
     *
     * Categorías especiales configuradas en ltms_category_rates.
     *
     * @param \WC_Order $order Pedido.
     * @return float|null
     */
    private static function get_category_rate( \WC_Order $order ): ?float {
        $category_rates = LTMS_Core_Config::get( 'ltms_category_commission_rates', [] );
        if ( empty( $category_rates ) || ! is_array( $category_rates ) ) {
            return null;
        }

        // Obtener categorías de los productos del pedido
        foreach ( $order->get_items() as $item ) {
            $product_id  = $item->get_product_id();
            $term_ids    = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );

            foreach ( $term_ids as $term_id ) {
                if ( isset( $category_rates[ $term_id ] ) ) {
                    return (float) $category_rates[ $term_id ];
                }
            }
        }

        return null;
    }

    /**
     * Obtiene la tasa de comisión según el plan del vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return float|null
     */
    private static function get_plan_rate( int $vendor_id ): ?float {
        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            return null;
        }

        if ( in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {
            return (float) LTMS_Core_Config::get( 'ltms_premium_commission_rate', 0.08 );
        }

        if ( in_array( 'ltms_vendor', (array) $user->roles, true ) ) {
            return (float) LTMS_Core_Config::get( 'ltms_basic_commission_rate', 0.10 );
        }

        return null;
    }

    /**
     * Calcula el total de ventas del vendedor en el mes actual.
     *
     * @param int $vendor_id ID del vendedor.
     * @return float Total en la moneda local.
     */
    private static function get_vendor_monthly_sales( int $vendor_id ): float {
        global $wpdb;

        $first_day = gmdate( 'Y-m-01 00:00:00' );
        $last_day  = gmdate( 'Y-m-t 23:59:59' );
        $table     = $wpdb->prefix . 'lt_commissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(gross_amount) FROM `{$table}` WHERE vendor_id = %d AND created_at BETWEEN %s AND %s AND status = 'paid'",
                $vendor_id,
                $first_day,
                $last_day
            )
        );

        return (float) $result;
    }

    /**
     * Devuelve un resumen de las tasas aplicables a un vendedor (para UI).
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{current_rate: float, tier: string, monthly_sales: float}
     */
    public static function get_rate_summary( int $vendor_id ): array {
        $monthly_sales = self::get_vendor_monthly_sales( $vendor_id );
        $country       = LTMS_Core_Config::get_country();

        $tier = 'base';
        if ( $country === 'CO' ) {
            if ( $monthly_sales >= 50_000_000 )     $tier = 'platinum';
            elseif ( $monthly_sales >= 20_000_000 ) $tier = 'gold';
            elseif ( $monthly_sales >=  5_000_000 ) $tier = 'silver';
        } elseif ( $country === 'MX' ) {
            if ( $monthly_sales >= 300_000 )        $tier = 'platinum';
            elseif ( $monthly_sales >= 100_000 )    $tier = 'gold';
            elseif ( $monthly_sales >=  25_000 )    $tier = 'silver';
        }

        return [
            'current_rate'  => self::get_volume_tier_rate( $vendor_id ) ?? self::DEFAULT_RATE,
            'tier'          => $tier,
            'monthly_sales' => $monthly_sales,
        ];
    }
}
