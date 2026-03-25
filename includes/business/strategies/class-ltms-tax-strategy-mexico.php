<?php
/**
 * LTMS Tax Strategy México
 *
 * Implementa el cálculo de impuestos mexicanos:
 * - IVA (16% general, 0% frontera norte/bienes básicos)
 * - ISR (retención Impuesto Sobre la Renta: 10-35% según régimen)
 * - IEPS (Impuesto Especial sobre Producción y Servicios: 8-160%)
 * - Retención IVA (10.67% para personas morales)
 *
 * Base legal: LIVA, LISR, LIEPS, CFF, RMF 2025
 * Obligaciones CFDI: Factura electrónica 4.0 (SAT 2025)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_Mexico
 */
final class LTMS_Tax_Strategy_Mexico implements LTMS_Tax_Strategy_Interface {

    // ── Tasas México — ahora configurables (v1.7.0) ──────────────
    // Valores por defecto como fallback

    private function get_iva_general_mx(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_iva_general', 0.16 );
    }

    private function get_iva_frontera(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_iva_frontera', 0.08 );
    }

    private function get_isr_honorarios_mx(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_isr_honorarios', 0.10 );
    }

    private function get_isr_plataformas(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_isr_plataformas', 0.04 );
    }

    private function get_retencion_iva_pm(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_retencion_iva_pm', 0.1067 );
    }

    /** Returns IEPS rate for a given product category. */
    private function get_ieps_rate( string $product_type ): float {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rate FROM `{$wpdb->prefix}lt_mx_ieps_rates` WHERE category = %s ORDER BY valid_from DESC LIMIT 1",
                $product_type
            )
        );
        if ( $rate !== null ) {
            return (float) $rate;
        }
        // Fallback defaults
        $defaults = [
            'sugary_drinks'     => 0.08,
            'tobacco'           => 1.60,
            'beer'              => 0.26,
            'wine'              => 0.26,
            'energy_drinks'     => 0.25,
            'junk_food'         => 0.08,
        ];
        return $defaults[ $product_type ] ?? 0.0;
    }

    /** Returns ISR rate for platform digital income from lt_mx_isr_tramos. */
    private function get_isr_platform_rate( float $monthly_income ): float {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rate FROM `{$wpdb->prefix}lt_mx_isr_tramos`
                 WHERE min_amount <= %f AND max_amount >= %f
                 ORDER BY valid_from DESC LIMIT 1",
                $monthly_income,
                $monthly_income
            )
        );
        if ( $rate !== null ) {
            return (float) $rate;
        }
        // Fallback: Art. 113-A tiers
        if ( $monthly_income <= 25000 )  return 0.02;
        if ( $monthly_income <= 100000 ) return 0.06;
        if ( $monthly_income <= 300000 ) return 0.10;
        return 0.17;
    }

    /**
     * Calcula todos los impuestos mexicanos para una transacción.
     *
     * @param float $gross_amount Monto bruto antes de impuestos.
     * @param array $order_data   Datos del pedido.
     * @param array $vendor_data  Datos del vendedor.
     * @return array
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
        $product_type   = $order_data['product_type'] ?? 'physical';
        $is_border_zone = $vendor_data['is_border_north_zone'] ?? false;
        $vendor_regime  = $vendor_data['tax_regime'] ?? 'resico'; // resico | pm | pf | pf_actividad

        // 1. IVA
        $iva_rate   = $this->get_iva_rate( $product_type, $is_border_zone );
        $iva_amount = round( $gross_amount * $iva_rate, 2 );

        // 2. Retención IVA (cuando la plataforma actúa como retenedor)
        $retencion_iva_rate   = 0.0;
        $retencion_iva_amount = 0.0;

        $platform_is_pm = $order_data['platform_is_persona_moral'] ?? true;
        if ( $platform_is_pm && $iva_amount > 0 ) {
            $retencion_iva_rate   = $this->get_retencion_iva_pm();
            $retencion_iva_amount = round( $gross_amount * $retencion_iva_rate, 2 );
        }

        // 3. ISR
        $isr_rate   = 0.0;
        $isr_amount = 0.0;

        if ( $this->should_apply_withholding( $vendor_data ) ) {
            $isr_rate   = $this->get_isr_rate( $vendor_regime, $gross_amount, $vendor_data['monthly_income'] ?? 0 );
            $isr_amount = round( $gross_amount * $isr_rate, 2 );
        }

        // 4. IEPS
        $ieps_rate   = $this->get_ieps_rate( $product_type );
        $ieps_amount = round( $gross_amount * $ieps_rate, 2 );

        // 5. Totales
        $total_taxes       = $iva_amount + $ieps_amount; // Paga el consumidor
        $total_withholding = $retencion_iva_amount + $isr_amount; // Retiene la plataforma
        $net_to_vendor     = round( $gross_amount - $total_withholding, 2 );

        return [
            'iva'                => $iva_amount,
            'iva_rate'           => $iva_rate,
            'retefuente'         => 0.0,    // No aplica en MX con este nombre
            'retefuente_rate'    => 0.0,
            'reteiva'            => $retencion_iva_amount,
            'reteiva_rate'       => $retencion_iva_rate,
            'reteica'            => 0.0,
            'reteica_rate'       => 0.0,
            'isr'                => $isr_amount,
            'isr_rate'           => $isr_rate,
            'ieps'               => $ieps_amount,
            'ieps_rate'          => $ieps_rate,
            'total_taxes'        => $total_taxes,
            'total_withholding'  => $total_withholding,
            'net_to_vendor'      => $net_to_vendor,
            'strategy'           => self::class,
            'country'            => 'MX',
            'currency'           => 'MXN',
            'cfdi_required'      => $gross_amount >= 2000, // CFDI obligatorio > $2,000 MXN
        ];
    }

    /**
     * Determina si aplica retención al vendedor.
     *
     * @param array $vendor_data Datos del vendedor.
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        $regime = $vendor_data['tax_regime'] ?? 'resico';
        // RESICO (Régimen Simplificado de Confianza) - retención reducida del 1.25%
        // PF con actividad empresarial - retención plataformas art. 113-A
        return in_array( $regime, [ 'resico', 'pf_actividad', 'pm', 'pf_honorarios', 'arrendamiento' ], true );
    }

    /**
     * Obtiene el código del país.
     *
     * @return string
     */
    public function get_country_code(): string {
        return 'MX';
    }

    /**
     * Determina la tasa de IVA según producto y zona geográfica.
     *
     * @param string $product_type    Tipo de producto.
     * @param bool   $is_border_zone  Si está en zona frontera norte.
     * @return float
     */
    private function get_iva_rate( string $product_type, bool $is_border_zone ): float {
        $exempt_types = [ 'basic_food', 'medicine', 'baby_food', 'tortillas', 'masa', 'tamales' ];
        if ( in_array( $product_type, $exempt_types, true ) ) {
            return 0.0; // IVA exento
        }

        // Frontera Norte: tasa reducida
        if ( $is_border_zone ) {
            return $this->get_iva_frontera();
        }

        return $this->get_iva_general_mx();
    }

    /**
     * Obtiene la tasa de ISR según régimen y monto.
     *
     * @param string $vendor_regime   Régimen fiscal del vendedor.
     * @param float  $gross_amount    Monto de la transacción.
     * @param float  $monthly_income  Ingresos mensuales del vendedor (para RESICO).
     * @return float
     */
    private function get_isr_rate( string $vendor_regime, float $gross_amount, float $monthly_income ): float {
        switch ( $vendor_regime ) {
            case 'resico':
                if ( $monthly_income <= 25000 )       return 0.0125;
                elseif ( $monthly_income <= 50000 )   return 0.015;
                elseif ( $monthly_income <= 83333 )   return 0.02;
                elseif ( $monthly_income <= 166666 )  return 0.025;
                else                                  return 0.03;

            case 'pf_actividad':
                // Lee de lt_mx_isr_tramos o usa defaults art. 113-A
                return $this->get_isr_platform_rate( $monthly_income );

            case 'pf_honorarios':
                return $this->get_isr_honorarios_mx();

            case 'arrendamiento':
                return $this->get_isr_honorarios_mx();

            case 'pm':
                return 0.0;

            default:
                return 0.0;
        }
    }
}
