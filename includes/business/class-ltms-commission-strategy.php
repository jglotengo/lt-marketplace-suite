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
    const DEFAULT_RATE = 0.15; // 15% — M-QA-05: synced with ltms_platform_commission_rate admin default

    /** Tipos de producto válidos para comisión diferenciada. */
    const PRODUCT_TYPES = [ 'physical', 'digital', 'service', 'booking' ];

    /** Defaults por tipo (físico 10%, resto 15%). */
    const PRODUCT_TYPE_DEFAULTS = [
        'physical' => 0.10,
        'digital'  => 0.15,
        'service'  => 0.15,
        'booking'  => 0.15,
    ];

    /**
     * Punto de entrada del Kernel. Sin hooks que registrar para esta clase.
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * Calcula la tasa de comisión efectiva para un vendedor y pedido.
     *
     * Cascada de prioridades (M-QA-08):
     * CS-00 contrato individual del vendedor → CS-01 producto individual →
     * CS-02 tipo de producto → CS-03 tier de volumen → CS-04 categoría →
     * CS-05 plan del vendedor → CS-06 tasa global.
     *
     * @param int       $vendor_id ID del vendedor.
     * @param \WC_Order $order     Pedido.
     * @return float Tasa decimal (0.10 = 10%).
     */
    public static function get_rate( int $vendor_id, \WC_Order $order ): float {
        // CS-00: contrato individual negociado con el vendedor (máxima prioridad).
        // M-QA-08: un acuerdo comercial explícito con el vendedor debe pesar más que
        // cualquier configuración genérica por producto o tipo; de lo contrario CS-01/CS-02
        // la anulan en silencio (ej: vendedor con 12% negociado pagando 15% por tipo físico).
        $custom_rate = self::get_custom_contract_rate( $vendor_id );
        if ( $custom_rate !== null ) {
            return $custom_rate;
        }

        // CS-01: tasa individual por producto (_ltms_commission_rate)
        $individual_rate = self::get_product_individual_rate( $order );
        if ( $individual_rate !== null ) {
            return $individual_rate;
        }

        // CS-02: tasa por tipo de producto (physical/digital/service/booking)
        $type_rate = self::get_product_type_rate( $order );
        if ( $type_rate !== null ) {
            return $type_rate;
        }

        // CS-03: tasa por tier de volumen de ventas
        $tier_rate = self::get_volume_tier_rate( $vendor_id );
        if ( $tier_rate !== null ) {
            return $tier_rate;
        }

        // CS-04: tasa por categoría del producto
        $category_rate = self::get_category_rate( $order );
        if ( $category_rate !== null ) {
            return $category_rate;
        }

        // CS-05: tasa según plan del vendedor (premium vs básico)
        $plan_rate = self::get_plan_rate( $vendor_id );
        if ( $plan_rate !== null ) {
            return $plan_rate;
        }

        // CS-06: tasa global configurada
        $global_rate = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', self::DEFAULT_RATE );

        return max( 0.0, min( 1.0, $global_rate ) );
    }

    /**
     * CS-00: Tasa negociada por contrato individual con el vendedor.
     *
     * Lee ltms_custom_commission_rate de user meta. Acepta tanto formato decimal
     * (0.12 = 12%) como porcentaje (12 = 12%), igual que CS-01, para tolerar cómo
     * se haya guardado el dato manualmente o desde la futura UI de admin.
     *
     * M-QA-09: el umbral de auto-conversión a porcentaje es >= 2, no > 1. Valores
     * apenas superiores a 1 (ej. 1.1) no son un porcentaje creíble de dos cifras
     * (nadie negocia una comisión de "1.1%"); son datos corruptos y deben
     * descartarse, en vez de convertirse silenciosamente a 0.011 (1.1%).
     * 1.0 (100%) sigue siendo válido como límite superior legítimo.
     *
     * @param int $vendor_id ID del vendedor.
     * @return float|null Tasa decimal (0–1) o null si el vendedor no tiene contrato propio.
     */
    private static function get_custom_contract_rate( int $vendor_id ): ?float {
        $stored_rate = get_user_meta( $vendor_id, 'ltms_custom_commission_rate', true );
        if ( $stored_rate === '' || ! is_numeric( $stored_rate ) ) {
            return null;
        }
        $rate = (float) $stored_rate;
        if ( $rate >= 2 ) {
            $rate = $rate / 100;
        }
        return ( $rate >= 0 && $rate <= 1 ) ? $rate : null;
    }

    /**
     * CS-01: Tasa de comisión individual por producto.
     *
     * Si el producto tiene _ltms_commission_rate en post_meta (valor 0–100),
     * lo convierte a decimal y lo devuelve. Aplica solo si hay un único
     * producto en el pedido; si hay varios, no aplica (ambigüedad).
     *
     * @param \WC_Order $order Pedido.
     * @return float|null Tasa decimal o null si no aplica.
     */
    private static function get_product_individual_rate( \WC_Order $order ): ?float {
        $items = array_values( $order->get_items() );
        if ( count( $items ) !== 1 ) {
            return null; // Solo aplica en pedidos de un solo producto
        }
        $product_id  = $items[0]->get_product_id();
        $stored_rate = get_post_meta( $product_id, '_ltms_commission_rate', true );
        if ( $stored_rate === '' || ! is_numeric( $stored_rate ) ) {
            return null;
        }
        $rate = (float) $stored_rate;
        // Acepta tanto porcentaje (>1) como decimal (<=1)
        if ( $rate > 1 ) {
            $rate = $rate / 100;
        }
        return ( $rate >= 0 && $rate <= 1 ) ? $rate : null;
    }

    /**
     * CS-02: Tasa de comisión por tipo de producto (physical/digital/service/booking).
     *
     * Lee _ltms_product_type del primer producto del pedido y busca la opción
     * ltms_commission_{type} en la configuración. Si no está configurada
     * explícitamente en el admin, devuelve null para que la cascada continúe
     * hasta el global rate (CS-06). PRODUCT_TYPE_DEFAULTS solo sirve como
     * referencia documental, no como fallback en runtime.
     *
     * Mapeo legacy: 'product' → 'physical' para compatibilidad con registros
     * creados antes de la v2.x donde el valor era 'product'.
     *
     * @param \WC_Order $order Pedido.
     * @return float|null Tasa decimal o null si el tipo no está reconocido.
     */
    private static function get_product_type_rate( \WC_Order $order ): ?float {
        $items = array_values( $order->get_items() );
        if ( empty( $items ) ) {
            return null;
        }
        $product_id   = $items[0]->get_product_id();
        $product_type = get_post_meta( $product_id, '_ltms_product_type', true );

        // Mapeo legacy: 'product' era el valor anterior a 'physical'
        if ( $product_type === 'product' || $product_type === '' ) {
            $product_type = 'physical';
        }

        // Verificar tipo reconocido
        if ( ! in_array( $product_type, self::PRODUCT_TYPES, true ) ) {
            return null;
        }

        $option_key     = 'ltms_commission_' . $product_type;
        $default        = self::PRODUCT_TYPE_DEFAULTS[ $product_type ];
        $configured_pct = LTMS_Core_Config::get( $option_key, '' );

        if ( $configured_pct !== '' && is_numeric( $configured_pct ) ) {
            $rate = (float) $configured_pct;
            // Admin guarda porcentaje (10 = 10%), convertir a decimal
            if ( $rate > 1 ) {
                $rate = $rate / 100;
            }
            return max( 0.0, min( 1.0, $rate ) );
        }

        // No configurado explícitamente → dejar que la cascada continúe al global rate (CS-06).
        return null;
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

        // v1.7.0 — Lee tiers desde BD (configurable desde admin)
        return self::get_db_tier_rate( $monthly_sales, $country );
    }

    /**
     * Lee el tier de comisión desde la tabla lt_commission_tiers (v1.7.0).
     *
     * @param float  $monthly_sales Ventas mensuales del vendedor.
     * @param string $country       País ('CO' | 'MX').
     * @return float|null Tasa del tier activo más apropiado, o null.
     */
    private static function get_db_tier_rate( float $monthly_sales, string $country ): ?float {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rate FROM `{$wpdb->prefix}lt_commission_tiers`
                 WHERE country = %s AND is_active = 1
                   AND min_amount <= %f AND max_amount >= %f
                 ORDER BY sort_order ASC LIMIT 1",
                $country,
                $monthly_sales,
                $monthly_sales
            )
        );
        if ( $rate === null ) {
            return null;
        }
        $rate_float = (float) $rate;
        // lt_commission_tiers.rate is stored as integer percentage (e.g. 10 = 10%).
        // Convert to decimal before returning so callers receive a value in the 0–1 range.
        if ( $rate_float > 1 ) {
            $rate_float = $rate_float / 100;
        }
        return $rate_float;
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




