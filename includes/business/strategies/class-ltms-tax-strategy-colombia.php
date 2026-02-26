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
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Strategy_Colombia
 */
final class LTMS_Tax_Strategy_Colombia implements LTMS_Tax_Strategy_Interface {

    // ── Tasas IVA Colombia (2025) ─────────────────────────────────
    private const IVA_GENERAL     = 0.19;   // 19% - bienes y servicios estándar
    private const IVA_REDUCIDO    = 0.05;   // 5% - algunos alimentos, medicamentos
    private const IVA_EXENTO      = 0.00;   // 0% - canasta familiar básica

    // ── Retención en la Fuente (Honorarios/Servicios) ────────────
    private const RETEFUENTE_HONORARIOS      = 0.11;  // 11% honorarios > 1 UVT
    private const RETEFUENTE_SERVICIOS       = 0.04;  // 4% servicios generales
    private const RETEFUENTE_COMPRAS         = 0.025; // 2.5% compras bienes
    private const RETEFUENTE_SERVICIOS_TECH  = 0.06;  // 6% servicios tecnológicos

    // ── ReteIVA ──────────────────────────────────────────────────
    private const RETEIVA_RATE = 0.15; // 15% del IVA (para grandes contribuyentes)

    // ── Umbrales 2025 (en pesos colombianos) ─────────────────────
    private const RETEFUENTE_MIN_COMPRAS  = 533000;  // ~14 UVT = $533.000 COP (2025)
    private const RETEFUENTE_MIN_SERVICIOS = 133000; // ~3.5 UVT = $133.000 COP (2025)
    private const UVT_2025                = 49799;   // Valor UVT 2025 (Decreto 2229/2024)

    /**
     * Calcula todos los impuestos aplicables para una transacción en Colombia.
     *
     * @param float $gross_amount Monto bruto antes de impuestos.
     * @param array $order_data   Datos del pedido.
     * @param array $vendor_data  Datos del vendedor.
     * @return array
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
        $product_type = $order_data['product_type'] ?? 'physical';
        $vendor_regime = $vendor_data['tax_regime'] ?? 'simplified'; // simplified | common | special

        // 1. Calcular IVA según tipo de producto/servicio
        $iva_rate   = $this->get_iva_rate( $product_type, $order_data );
        $iva_amount = round( $gross_amount * $iva_rate, 2 );

        // 2. Calcular Retención en la Fuente
        $retefuente_rate   = 0.0;
        $retefuente_amount = 0.0;

        if ( $this->should_apply_withholding( $vendor_data ) ) {
            $retefuente_rate   = $this->get_retefuente_rate( $product_type, $gross_amount, $vendor_regime );
            $retefuente_amount = round( $gross_amount * $retefuente_rate, 2 );
        }

        // 3. Calcular ReteIVA (solo si hay IVA y el comprador es gran contribuyente)
        $reteiva_rate   = 0.0;
        $reteiva_amount = 0.0;

        $buyer_is_grande = $order_data['buyer_is_gran_contribuyente'] ?? false;
        if ( $buyer_is_grande && $iva_amount > 0 ) {
            $reteiva_rate   = self::RETEIVA_RATE;
            $reteiva_amount = round( $iva_amount * $reteiva_rate, 2 );
        }

        // 4. ReteICA (retención de industria y comercio)
        $reteica_rate   = $this->get_reteica_rate( $vendor_data['municipality_code'] ?? '', $vendor_data['ciiu_code'] ?? '' );
        $reteica_amount = round( $gross_amount * $reteica_rate, 2 );

        // 5. Impoconsumo (restaurantes, bares, discotecas - Ley 2010/2019)
        $inc_rate   = 0.0;
        $inc_amount = 0.0;

        if ( in_array( $product_type, [ 'food_service', 'restaurant', 'bar' ], true ) ) {
            $inc_rate   = 0.08;
            $inc_amount = round( $gross_amount * $inc_rate, 2 );
        }

        // 6. Calcular totales
        $total_taxes       = $iva_amount + $inc_amount; // Impuestos que paga el consumidor
        $total_withholding = $retefuente_amount + $reteiva_amount + $reteica_amount; // Retenciones al vendedor
        $net_to_vendor     = round( $gross_amount - $total_withholding, 2 );

        return [
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
            'strategy'           => self::class,
            'country'            => 'CO',
            'currency'           => 'COP',
            'uvt_value'          => self::UVT_2025,
        ];
    }

    /**
     * Determina si aplica retención al vendedor.
     * No aplica a: régimen simplificado, personas naturales sin actividad económica.
     *
     * @param array $vendor_data Datos del vendedor.
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool {
        // Régimen simplificado NO está sujeto a ReteFuente como agente retenido
        // (a partir de 2023, con la Ley 2277, algunas reglas cambiaron)
        $regime = $vendor_data['tax_regime'] ?? 'simplified';

        return in_array( $regime, [ 'common', 'special', 'gran_contribuyente' ], true );
    }

    /**
     * Obtiene el código del país.
     *
     * @return string
     */
    public function get_country_code(): string {
        return 'CO';
    }

    /**
     * Determina la tasa de IVA según el tipo de producto.
     *
     * @param string $product_type Tipo de producto.
     * @param array  $order_data   Datos del pedido.
     * @return float Tasa de IVA.
     */
    private function get_iva_rate( string $product_type, array $order_data ): float {
        // Categorías exentas de IVA (canasta básica)
        $exempt_types = [ 'basic_food', 'medicine', 'health_service', 'education', 'agricultural_basic' ];
        if ( in_array( $product_type, $exempt_types, true ) ) {
            return self::IVA_EXENTO;
        }

        // Tasa reducida 5%
        $reduced_types = [ 'coffee', 'cacao', 'eggs_retail', 'sanitary_supplies', 'agricultural_machinery' ];
        if ( in_array( $product_type, $reduced_types, true ) ) {
            return self::IVA_REDUCIDO;
        }

        // Tasa general 19%
        return self::IVA_GENERAL;
    }

    /**
     * Determina la tasa de ReteFuente según tipo de actividad y monto.
     *
     * @param string $product_type  Tipo de producto/servicio.
     * @param float  $gross_amount  Monto bruto.
     * @param string $vendor_regime Régimen tributario del vendedor.
     * @return float Tasa de retención.
     */
    private function get_retefuente_rate( string $product_type, float $gross_amount, string $vendor_regime ): float {
        // Servicios tecnológicos / Software
        if ( in_array( $product_type, [ 'software', 'digital_service', 'saas', 'tech_service' ], true ) ) {
            return $gross_amount >= self::RETEFUENTE_MIN_SERVICIOS
                ? self::RETEFUENTE_SERVICIOS_TECH
                : 0.0;
        }

        // Honorarios (consultoría, servicios profesionales)
        if ( in_array( $product_type, [ 'consulting', 'professional_service', 'freelance' ], true ) ) {
            $base_honorarios = 1 * self::UVT_2025; // 1 UVT
            return $gross_amount >= $base_honorarios
                ? self::RETEFUENTE_HONORARIOS
                : 0.0;
        }

        // Compras de bienes
        if ( in_array( $product_type, [ 'physical', 'product' ], true ) ) {
            return $gross_amount >= self::RETEFUENTE_MIN_COMPRAS
                ? self::RETEFUENTE_COMPRAS
                : 0.0;
        }

        // Servicios generales
        return $gross_amount >= self::RETEFUENTE_MIN_SERVICIOS
            ? self::RETEFUENTE_SERVICIOS
            : 0.0;
    }

    /**
     * Obtiene la tasa de ReteICA según municipio y actividad CIIU.
     *
     * @param string $municipality_code Código del municipio DANE.
     * @param string $ciiu_code         Código CIIU de la actividad.
     * @return float Tasa de ReteICA.
     */
    private function get_reteica_rate( string $municipality_code, string $ciiu_code ): float {
        // Tabla simplificada de tasas ICA por tipo de actividad
        // (En producción, esto vendría de la tabla de municipios DANE)
        $rates_by_ciiu_prefix = [
            '4'  => 0.00414, // Comercio al por menor: 4.14 por mil
            '5'  => 0.00966, // Servicios de transporte: 9.66 por mil
            '6'  => 0.00966, // Actividades financieras: 9.66 por mil
            '7'  => 0.00966, // Actividades profesionales: 9.66 por mil
            '8'  => 0.00966, // Administración pública: 9.66 por mil
            '9'  => 0.00690, // Entretenimiento/Cultura: 6.90 por mil
        ];

        $prefix = substr( $ciiu_code, 0, 1 );
        return $rates_by_ciiu_prefix[ $prefix ] ?? 0.00414; // Por defecto: comercio
    }
}
