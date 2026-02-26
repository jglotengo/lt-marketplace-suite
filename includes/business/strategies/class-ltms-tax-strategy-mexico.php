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

    // ── Tasas IVA México (LIVA 2025) ─────────────────────────────
    private const IVA_GENERAL      = 0.16;  // 16% - Tasa general
    private const IVA_REDUCIDO     = 0.08;  // 8% - Zona frontera norte (D.O.F. 31-XII-2018)
    private const IVA_EXENTO       = 0.00;  // 0% - Alimentos básicos, medicinas

    // ── Tasas ISR (Retención a prestadores de servicios) ────────
    private const ISR_SERVICIOS_PROFESIONALES = 0.10; // 10% honorarios RIF/PF
    private const ISR_ARRENDAMIENTO           = 0.10; // 10% arrendamiento
    private const ISR_PM_PLATAFORMAS          = 0.04; // 4% ingresos por plataformas digitales (art. 113-A LISR)

    // ── Retención IVA a proveedores (art. 1-A LIVA) ─────────────
    private const RETENCION_IVA_PM            = 0.1067; // 10.67% personas morales → personas físicas

    // ── IEPS (por categoría de producto) ─────────────────────────
    private const IEPS_BEBIDAS_AZUCARADAS     = 0.01;   // $1 MXN por litro
    private const IEPS_TABACO                 = 1.60;   // 160%
    private const IEPS_CERVEZAS               = 0.26;   // 26%
    private const IEPS_VINOS                  = 0.26;   // 26%
    private const IEPS_BEBIDAS_ENERGETICAS    = 0.25;   // 25%
    private const IEPS_COMIDA_CHATARRA        = 0.08;   // 8%

    // ── Umbrales ISR (Plataformas digitales 2025) ─────────────────
    private const ISR_PLAT_UMBRAL_1    = 25000;  // Hasta $25K/mes: 2%
    private const ISR_PLAT_UMBRAL_2    = 100000; // $25K-$100K/mes: 6%
    private const ISR_PLAT_UMBRAL_3    = 300000; // $100K-$300K/mes: 10%
    // Más de $300K: 17% (art. 113-A LISR)

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
            $retencion_iva_rate   = self::RETENCION_IVA_PM;
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
            return self::IVA_EXENTO;
        }

        // Frontera Norte: 8% en lugar de 16% (D.O.F. 31-XII-2018)
        if ( $is_border_zone ) {
            return self::IVA_REDUCIDO;
        }

        return self::IVA_GENERAL;
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
                // RESICO: tasas escalonadas mensuales (art. 113-E LISR)
                if ( $monthly_income <= 25000 ) {
                    return 0.0125; // 1.25%
                } elseif ( $monthly_income <= 50000 ) {
                    return 0.015;  // 1.5%
                } elseif ( $monthly_income <= 83333 ) {
                    return 0.02;   // 2%
                } elseif ( $monthly_income <= 166666 ) {
                    return 0.025;  // 2.5%
                } else {
                    return 0.03;   // 3% - Máximo RESICO
                }

            case 'pf_actividad':
                // Plataformas digitales (art. 113-A): tasas por monto mensual
                if ( $monthly_income <= self::ISR_PLAT_UMBRAL_1 ) {
                    return 0.02; // 2%
                } elseif ( $monthly_income <= self::ISR_PLAT_UMBRAL_2 ) {
                    return 0.06; // 6%
                } elseif ( $monthly_income <= self::ISR_PLAT_UMBRAL_3 ) {
                    return 0.10; // 10%
                } else {
                    return 0.17; // 17%
                }

            case 'pf_honorarios':
                return self::ISR_SERVICIOS_PROFESIONALES; // 10%

            case 'arrendamiento':
                return self::ISR_ARRENDAMIENTO; // 10%

            case 'pm':
                return 0.0; // PM declara directamente, no hay retención en ventas normales

            default:
                return 0.0;
        }
    }

    /**
     * Obtiene la tasa de IEPS según tipo de producto.
     *
     * @param string $product_type Tipo de producto.
     * @return float
     */
    private function get_ieps_rate( string $product_type ): float {
        $ieps_map = [
            'tobacco'              => self::IEPS_TABACO,
            'beer'                 => self::IEPS_CERVEZAS,
            'wine'                 => self::IEPS_VINOS,
            'spirits'              => self::IEPS_CERVEZAS, // Misma tasa
            'energy_drink'         => self::IEPS_BEBIDAS_ENERGETICAS,
            'junk_food'            => self::IEPS_COMIDA_CHATARRA,
            'sugary_beverage'      => 0.01, // $1 MXN/litro (cuota fija, no porcentaje)
        ];

        return $ieps_map[ $product_type ] ?? 0.0;
    }
}
