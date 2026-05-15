<?php
/**
 * LTMS Tax Strategy Colombia
 *
 * Implementa el cálculo de impuestos colombianos:
 * - IVA (19% general, 5% reducido, 0% exento)
 * - Retención en la Fuente (entre 2.5% y 11%)
 * - ReteIVA (15% del IVA para grandes contribuyentes)
 * - ReteICA (0.414% - 1.104% según municipio y actividad CIIU)
 * - INC (Impoconsumo 8% para restaurantes)
 *
 * Base legal: Estatuto Tributario, Decreto 1430/2023, Circular DIAN 0087/2022
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_Colombia
 */
final class LTMS_Tax_Strategy_Colombia implements LTMS_Tax_Strategy_Interface {

    // ── Tasas Colombia — configurables (v1.7.0) ───────────────────────────────

    private function get_uvt(): float {
        return (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 49799.0 );
    }

    private function get_iva_general(): float {
        return (float) LTMS_Core_Config::get( 'ltms_iva_general', 0.19 );
    }

    private function get_iva_reducido(): float {
        return (float) LTMS_Core_Config::get( 'ltms_iva_reducido', 0.05 );
    }

    private function get_retefuente_honorarios(): float {
        return (float) LTMS_Core_Config::get( 'ltms_retefuente_honorarios', 0.11 );
    }

    private function get_retefuente_servicios(): float {
        return (float) LTMS_Core_Config::get( 'ltms_retefuente_servicios', 0.04 );
    }

    private function get_retefuente_compras(): float {
        return (float) LTMS_Core_Config::get( 'ltms_retefuente_compras', 0.025 );
    }

    private function get_retefuente_servicios_tech(): float {
        return (float) LTMS_Core_Config::get( 'ltms_retefuente_tech', 0.06 );
    }

    private function get_reteiva_rate(): float {
        return (float) LTMS_Core_Config::get( 'ltms_reteiva_rate', 0.15 );
    }

    private function get_impoconsumo_rate(): float {
        return (float) LTMS_Core_Config::get( 'ltms_impoconsumo_rate', 0.08 );
    }

    private function get_retefuente_min_compras(): float {
        return $this->get_uvt() * (float) LTMS_Core_Config::get( 'ltms_retefuente_min_compras_uvt', 10.666 );
    }

    private function get_retefuente_min_servicios(): float {
        return $this->get_uvt() * (float) LTMS_Core_Config::get( 'ltms_retefuente_min_servicios_uvt', 2.666 );
    }

    /**
     * Calcula todos los impuestos aplicables para una transacción en Colombia.
     *
     * @param float $gross_amount Monto bruto antes de impuestos.
     * @param array $order_data   Datos del pedido.
     * @param array $vendor_data  Datos del vendedor.
     * @return array
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
        $product_type  = $order_data['product_type'] ?? 'physical';
        $vendor_regime = $vendor_data['tax_regime']  ?? 'simplified';

        // 1. IVA según tipo de producto/servicio
        $iva_rate   = $this->get_iva_rate( $product_type, $order_data );
        $iva_amount = round( $gross_amount * $iva_rate, 2 );

        // 2. Retención en la Fuente
        $retefuente_rate   = 0.0;
        $retefuente_amount = 0.0;

        if ( $this->should_apply_withholding( $vendor_data ) ) {
            $retefuente_rate   = $this->get_retefuente_rate( $product_type, $gross_amount, $vendor_regime );
            $retefuente_amount = round( $gross_amount * $retefuente_rate, 2 );
        }

        // 3. ReteIVA (solo si hay IVA y el comprador es gran contribuyente)
        $reteiva_rate   = 0.0;
        $reteiva_amount = 0.0;

        $buyer_is_grande = $order_data['buyer_is_gran_contribuyente'] ?? false;
        if ( $buyer_is_grande && $iva_amount > 0 ) {
            $reteiva_rate   = $this->get_reteiva_rate();
            $reteiva_amount = round( $iva_amount * $reteiva_rate, 2 );
        }

        // 4. ReteICA — territorialidad municipal con regla híbrida.
        // El municipio aplicable depende de si el comprador es agente retenedor en su municipio:
        // si sí, se usa el estatuto del municipio destino; si no, el del vendedor (donde tributa ICA).
        $applicable_municipality = $this->resolve_applicable_municipality( $order_data, $vendor_data );
        $reteica_rate   = $this->get_reteica_rate(
            $applicable_municipality,
            $vendor_data['ciiu_code'] ?? ''
        );
        $reteica_amount = round( $gross_amount * $reteica_rate, 2 );

        // 5. Impoconsumo (restaurantes, bares, discotecas - Ley 2010/2019)
        $inc_rate   = 0.0;
        $inc_amount = 0.0;

        if ( in_array( $product_type, [ 'food_service', 'restaurant', 'bar' ], true ) ) {
            $inc_rate   = $this->get_impoconsumo_rate();
            $inc_amount = round( $gross_amount * $inc_rate, 2 );
        }

        // 6. Totales
        $total_taxes       = $iva_amount + $inc_amount;
        $total_withholding = $retefuente_amount + $reteiva_amount + $reteica_amount;
        $net_to_vendor     = round( $gross_amount - $total_withholding, 2 );

        return [
            'gross'              => $gross_amount,
            'iva'                => $iva_amount,
            'iva_rate'           => $iva_rate,
            'retefuente'         => $retefuente_amount,
            'retefuente_rate'    => $retefuente_rate,
            'reteiva'            => $reteiva_amount,
            'reteiva_rate'       => $reteiva_rate,
            'reteica'            => $reteica_amount,
            'reteica_rate'       => $reteica_rate,
            'impoconsumo'        => $inc_amount,
            'impoconsumo_rate'   => $inc_rate,
            'isr'                => 0.0,
            'isr_rate'           => 0.0,
            'ieps'               => 0.0,
            'ieps_rate'          => 0.0,
            'total_taxes'        => $total_taxes,
            'total_withholding'  => $total_withholding,
            'net_to_vendor'      => $net_to_vendor,
            'platform_fee'       => 0.0,
            'strategy'           => self::class,
            'country'            => 'CO',
            'currency'           => 'COP',
            'uvt_value'          => $this->get_uvt(),
        ];
    }

    /**
     * Determina si aplica retención al vendedor.
     * Régimen simplificado NO está sujeto a ReteFuente como agente retenido.
     *
     * @param array $vendor_data Datos del vendedor.
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        $regime = $vendor_data['tax_regime'] ?? 'simplified';
        return in_array( $regime, [ 'common', 'special', 'gran_contribuyente' ], true );
    }

    /**
     * Código de país.
     */
    public function get_country_code(): string {
        return 'CO';
    }

    /**
     * Tasa de IVA según tipo de producto.
     */
    private function get_iva_rate( string $product_type, array $order_data ): float {
        $exempt_types = [ 'basic_food', 'medicine', 'health_service', 'education', 'agricultural_basic' ];
        if ( in_array( $product_type, $exempt_types, true ) ) {
            return 0.0;
        }

        $reduced_types = [ 'coffee', 'cacao', 'eggs_retail', 'sanitary_supplies', 'agricultural_machinery' ];
        if ( in_array( $product_type, $reduced_types, true ) ) {
            return $this->get_iva_reducido();
        }

        return $this->get_iva_general();
    }

    /**
     * Tasa de ReteFuente según tipo de actividad y monto.
     */
    private function get_retefuente_rate( string $product_type, float $gross_amount, string $vendor_regime ): float {
        if ( in_array( $product_type, [ 'software', 'digital_service', 'saas', 'tech_service' ], true ) ) {
            return $gross_amount >= $this->get_retefuente_min_servicios()
                ? $this->get_retefuente_servicios_tech()
                : 0.0;
        }

        if ( in_array( $product_type, [ 'consulting', 'professional_service', 'freelance' ], true ) ) {
            $base_honorarios = 1 * $this->get_uvt();
            return $gross_amount >= $base_honorarios
                ? $this->get_retefuente_honorarios()
                : 0.0;
        }

        if ( in_array( $product_type, [ 'physical', 'product' ], true ) ) {
            return $gross_amount >= $this->get_retefuente_min_compras()
                ? $this->get_retefuente_compras()
                : 0.0;
        }

        // Servicios generales (incluye 'general', 'food_service', etc.)
        return $gross_amount >= $this->get_retefuente_min_servicios()
            ? $this->get_retefuente_servicios()
            : 0.0;
    }

    /**
     * Tasa de ReteICA según municipio y actividad CIIU.
     *
     * 1. Si hay municipio: consulta tabla `lt_co_reteica_rates_municipal` (CIIU exacto, luego prefijo).
     * 2. Fallback: tabla hardcoded por prefijo CIIU (preserva tests existentes y comportamiento legacy).
     */
    private function get_reteica_rate( string $municipality_code, string $ciiu_code ): float {
        if ( $municipality_code !== '' && $ciiu_code !== '' ) {
            $db_rate = $this->lookup_reteica_rate_from_db( $municipality_code, $ciiu_code );
            if ( $db_rate !== null ) {
                return $db_rate;
            }
        }

        $rates_by_ciiu_prefix = [
            '4' => 0.00414,
            '5' => 0.00966,
            '6' => 0.00966,
            '7' => 0.00966,
            '8' => 0.00966,
            '9' => 0.00690,
        ];

        $prefix = substr( $ciiu_code, 0, 1 );
        return $rates_by_ciiu_prefix[ $prefix ] ?? 0.00414;
    }

    /**
     * Resuelve el municipio aplicable para ReteICA según regla híbrida.
     *
     * Cuando el comprador es agente retenedor/gran contribuyente registrado en su municipio,
     * el estatuto que aplica es el del municipio destino (donde el comprador practica retención).
     * En B2C sin agente retenedor, aplica el municipio del vendedor (donde tributa ICA).
     *
     * Extensible vía filtro `ltms_reteica_applicable_municipality`.
     *
     * @param array $order_data  Datos del pedido (debe traer buyer_municipality_code y buyer_is_gran_contribuyente).
     * @param array $vendor_data Datos del vendedor (debe traer municipality_code).
     * @return string Código DANE del municipio aplicable (puede ser '').
     */
    private function resolve_applicable_municipality( array $order_data, array $vendor_data ): string {
        $buyer_is_gran  = (bool) ( $order_data['buyer_is_gran_contribuyente'] ?? false );
        $buyer_muni     = (string) ( $order_data['buyer_municipality_code'] ?? '' );
        $vendor_muni    = (string) ( $vendor_data['municipality_code'] ?? '' );

        $resolved = ( $buyer_is_gran && $buyer_muni !== '' ) ? $buyer_muni : $vendor_muni;

        return (string) apply_filters(
            'ltms_reteica_applicable_municipality',
            $resolved,
            $vendor_muni,
            $buyer_muni,
            $order_data,
            $vendor_data
        );
    }

    /**
     * Consulta la tabla `lt_co_reteica_rates_municipal` para obtener la tarifa aplicable.
     * Retorna el rate como decimal directo (por mil ÷ 1000). Null si no hay match.
     *
     * Defensive: si $wpdb no está disponible (entornos de test sin WP), retorna null.
     *
     * @param string $municipality_code Código DANE.
     * @param string $ciiu_code         CIIU del vendedor.
     * @return float|null
     */
    private function lookup_reteica_rate_from_db( string $municipality_code, string $ciiu_code ): ?float {
        if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
            return null;
        }
        global $wpdb;

        $table = $wpdb->prefix . 'lt_co_reteica_rates_municipal';
        $today = gmdate( 'Y-m-d' );

        $sql = "SELECT rate_per_thousand FROM `{$table}`
                WHERE municipality_code = %s
                  AND ciiu_code         = %s
                  AND valid_from       <= %s
                  AND ( valid_to IS NULL OR valid_to >= %s )
                ORDER BY valid_from DESC LIMIT 1";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rate = $wpdb->get_var( $wpdb->prepare( $sql, $municipality_code, $ciiu_code, $today, $today ) );
        if ( $rate !== null ) {
            return (float) $rate / 1000.0;
        }

        $prefix = substr( $ciiu_code, 0, 1 );
        if ( $prefix !== '' && $prefix !== $ciiu_code ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rate = $wpdb->get_var( $wpdb->prepare( $sql, $municipality_code, $prefix, $today, $today ) );
            if ( $rate !== null ) {
                return (float) $rate / 1000.0;
            }
        }

        return null;
    }
}
