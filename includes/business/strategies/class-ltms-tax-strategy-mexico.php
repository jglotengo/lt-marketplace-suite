<?php
/**
 * LTMS Tax Strategy México
 *
 * Implementa el cálculo de impuestos mexicanos:
 * - IVA (16% general, 0% frontera norte/bienes básicos)
 * - ISR (retención Impuesto Sobre la Renta: art. 113-A LISR)
 * - IEPS (Impuesto Especial sobre Producción y Servicios: 8-160%)
 * - Retención IVA (10.67% para personas morales)
 *
 * Base legal: LIVA, LISR, LIEPS, CFF, RMF 2025
 * Obligaciones CFDI: Factura electrónica 4.0 (SAT 2025)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/strategies
 * @version    1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_Mexico
 */
final class LTMS_Tax_Strategy_Mexico implements LTMS_Tax_Strategy_Interface {

    // ── Tasas México — configurables (v1.7.0) ────────────────────────────────

    private function get_iva_general_mx(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_iva_general', 0.16 );
    }

    private function get_iva_frontera(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_iva_frontera', 0.08 );
    }

    private function get_isr_honorarios_mx(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_isr_honorarios', 0.10 );
    }

    private function get_retencion_iva_pm(): float {
        return (float) LTMS_Core_Config::get( 'ltms_mx_retencion_iva_pm', 0.1067 );
    }

    /**
     * Retorna la tasa IEPS para una categoría de producto.
     * En producción consulta la tabla lt_mx_ieps_rates; en unit tests usa fallbacks.
     */
    private function get_ieps_rate( string $product_type ): float {
        // Intentar consulta BD solo cuando wpdb está disponible y conectado.
        if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
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
        }

        // Fallback defaults (art. 2 LIEPS vigente 2025)
        $defaults = [
            'sugary_drinks'      => 0.08,
            'bebidas_azucaradas' => 0.08,
            'tobacco'            => 1.60,
            'beer'               => 0.26,
            'wine'               => 0.26,
            'energy_drinks'      => 0.25,
            'junk_food'          => 0.08,
        ];

        return $defaults[ $product_type ] ?? 0.0;
    }

    /**
     * Retorna la tasa ISR art. 113-A según ingresos mensuales.
     * En producción consulta la tabla lt_mx_isr_tramos; en unit tests usa fallbacks.
     */
    private function get_isr_platform_rate( float $monthly_income ): float {
        // Intentar consulta BD solo cuando wpdb está disponible.
        if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
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
        }

        // Fallback: Art. 113-A LISR — tramos vigentes 2025
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
        $vendor_regime  = $vendor_data['tax_regime'] ?? 'resico';

        // 1. IVA
        $iva_rate   = $this->get_iva_rate( $product_type, $is_border_zone );
        $iva_amount = round( $gross_amount * $iva_rate, 2 );

        // 2. Retención IVA (cuando la plataforma actúa como retenedor PM)
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
        $total_taxes       = $iva_amount + $ieps_amount;
        $total_withholding = $retencion_iva_amount + $isr_amount;
        $net_to_vendor     = round( $gross_amount - $total_withholding, 2 );

        return [
            'gross'              => $gross_amount,
            'iva'                => $iva_amount,
            'iva_rate'           => $iva_rate,
            'retefuente'         => 0.0,
            'retefuente_rate'    => 0.0,
            'reteiva'            => $retencion_iva_amount,
            'reteiva_rate'       => $retencion_iva_rate,
            'reteica'            => 0.0,
            'reteica_rate'       => 0.0,
            'isr'                => $isr_amount,
            'isr_rate'           => $isr_rate,
            'ieps'               => $ieps_amount,
            'ieps_rate'          => $ieps_rate,
            'impoconsumo'        => 0.0,
            'impoconsumo_rate'   => 0.0,
            'total_taxes'        => $total_taxes,
            'total_withholding'  => $total_withholding,
            'net_to_vendor'      => $net_to_vendor,
            'platform_fee'       => 0.0,
            'strategy'           => self::class,
            'country'            => 'MX',
            'currency'           => 'MXN',
            'cfdi_required'      => $gross_amount >= 2000,
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
        return in_array( $regime, [ 'resico', 'pf_actividad', 'pm', 'pf_honorarios', 'arrendamiento' ], true );
    }

    /**
     * Código de país.
     */
    public function get_country_code(): string {
        return 'MX';
    }

    /**
     * Tasa de IVA según producto y zona geográfica.
     */
    private function get_iva_rate( string $product_type, bool $is_border_zone ): float {
        $exempt_types = [ 'basic_food', 'medicine', 'baby_food', 'tortillas', 'masa', 'tamales' ];
        if ( in_array( $product_type, $exempt_types, true ) ) {
            return 0.0;
        }

        if ( $is_border_zone ) {
            return $this->get_iva_frontera();
        }

        return $this->get_iva_general_mx();
    }

    /**
     * Tasa de ISR según régimen y monto.
     */
    private function get_isr_rate( string $vendor_regime, float $gross_amount, float $monthly_income ): float {
        switch ( $vendor_regime ) {
            case 'resico':
                if ( $monthly_income <= 25000 )      return 0.0125;
                elseif ( $monthly_income <= 50000 )  return 0.015;
                elseif ( $monthly_income <= 83333 )  return 0.02;
                elseif ( $monthly_income <= 166666 ) return 0.025;
                else                                 return 0.03;

            case 'pf_actividad':
                return $this->get_isr_platform_rate( $monthly_income );

            case 'pf_honorarios':
            case 'arrendamiento':
                return $this->get_isr_honorarios_mx();

            case 'pm':
            default:
                return 0.0;
        }
    }
}
