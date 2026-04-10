<?php
/**
 * LTMS Tax Engine - Motor de Cálculo Fiscal Multi-País
 *
 * Fachada (Facade) que delega el cálculo de impuestos a la estrategia
 * correcta según el país configurado en el plugin.
 *
 * Países soportados: CO (Colombia), MX (México)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Engine
 */
final class LTMS_Tax_Engine {

    /**
     * Punto de entrada del Kernel. Sin hooks que registrar para esta clase.
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * Instancias cacheadas de estrategias fiscales.
     *
     * @var array<string, LTMS_Tax_Strategy_Interface>
     */
    private static array $strategies = [];

    /**
     * Calcula el desglose fiscal completo para una transacción.
     *
     * @param float  $gross_amount Monto bruto de la transacción.
     * @param array  $order_data   Datos del pedido (tipo de producto, tipo comprador, municipio, etc.).
     * @param array  $vendor_data  Datos del vendedor (régimen, NIT/RFC, CIIU, municipio, etc.).
     * @param string $country      Código de país: 'CO' o 'MX'.
     * @return array Desglose fiscal con todos los impuestos y retenciones.
     * @throws \InvalidArgumentException Si el país no está soportado.
     */
    public static function calculate( float $gross_amount, array $order_data, array $vendor_data, string $country ): array {
        $strategy = self::get_strategy( $country );
        $result   = $strategy->calculate( $gross_amount, $order_data, $vendor_data );

        /**
         * Filters the tax calculation result after computation.
         * Allows modules (e.g. pickup handler for ICA municipality) to adjust the result.
         *
         * @param array  $result      Tax calculation result.
         * @param array  $order_data  Order context.
         * @param array  $vendor_data Vendor context.
         * @param string $country     Country code.
         */
        return apply_filters( 'ltms_after_tax_calculate', $result, $order_data, $vendor_data, $country );
    }

    /**
     * Verifica si aplica retención para un vendedor según su país y datos.
     *
     * @param array  $vendor_data Datos del vendedor.
     * @param string $country     Código de país.
     * @return bool
     */
    public static function should_apply_withholding( array $vendor_data, string $country ): bool {
        $strategy = self::get_strategy( $country );
        return $strategy->should_apply_withholding( $vendor_data );
    }

    /**
     * Obtiene el código de país de la estrategia activa.
     *
     * @return string 'CO' o 'MX'.
     */
    public static function get_active_country(): string {
        return LTMS_Core_Config::get_country();
    }

    /**
     * Devuelve la estrategia fiscal para el país dado.
     *
     * @param string $country Código de país.
     * @return LTMS_Tax_Strategy_Interface
     * @throws \InvalidArgumentException
     */
    private static function get_strategy( string $country ): LTMS_Tax_Strategy_Interface {
        $country = strtoupper( $country );

        if ( isset( self::$strategies[ $country ] ) ) {
            return self::$strategies[ $country ];
        }

        $strategy_map = [
            'CO' => 'LTMS_Tax_Strategy_Colombia',
            'MX' => 'LTMS_Tax_Strategy_Mexico',
        ];

        if ( ! isset( $strategy_map[ $country ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( '[LTMS Tax Engine] País no soportado: %s', $country )
            );
        }

        $class_name = $strategy_map[ $country ];

        if ( ! class_exists( $class_name ) ) {
            throw new \RuntimeException(
                sprintf( '[LTMS Tax Engine] Clase de estrategia no encontrada: %s', $class_name )
            );
        }

        self::$strategies[ $country ] = new $class_name();
        return self::$strategies[ $country ];
    }

    /**
     * Registra una estrategia fiscal personalizada (extensibilidad).
     *
     * @param string                      $country_code Código de país (2 letras).
     * @param LTMS_Tax_Strategy_Interface $strategy     Instancia de la estrategia.
     * @return void
     */
    public static function register_strategy( string $country_code, LTMS_Tax_Strategy_Interface $strategy ): void {
        self::$strategies[ strtoupper( $country_code ) ] = $strategy;
    }

    /**
     * Limpia la caché de estrategias (útil en tests).
     *
     * @return void
     */
    public static function flush_strategies(): void {
        self::$strategies = [];
    }

    /**
     * Formatea el desglose fiscal para mostrar en facturas/recibos.
     *
     * @param array  $tax_breakdown Resultado de calculate().
     * @param string $currency      Código de moneda (COP, MXN).
     * @return string HTML formateado.
     */
    public static function format_breakdown_html( array $tax_breakdown, string $currency ): string {
        $lines = [];

        $fields = [
            'subtotal'      => __( 'Subtotal', 'ltms' ),
            'iva'           => __( 'IVA', 'ltms' ),
            'iva_reducido'  => __( 'IVA Reducido (5%)', 'ltms' ),
            'impoconsumo'   => __( 'Impoconsumo', 'ltms' ),
            'ieps'          => __( 'IEPS', 'ltms' ),
            'retefuente'    => __( 'ReteFuente', 'ltms' ),
            'reteiva'       => __( 'ReteIVA', 'ltms' ),
            'reteica'       => __( 'ReteICA', 'ltms' ),
            'isr_platform'  => __( 'ISR Plataformas (art. 113-A LISR)', 'ltms' ),
            'total'         => __( 'Total', 'ltms' ),
        ];

        foreach ( $fields as $key => $label ) {
            if ( isset( $tax_breakdown[ $key ] ) && $tax_breakdown[ $key ] > 0 ) {
                $lines[] = sprintf(
                    '<tr><td>%s</td><td>%s</td></tr>',
                    esc_html( $label ),
                    esc_html( LTMS_Utils::format_money( $tax_breakdown[ $key ], $currency ) )
                );
            }
        }

        if ( empty( $lines ) ) {
            return '';
        }

        return '<table class="ltms-tax-breakdown">' . implode( '', $lines ) . '</table>';
    }
    public function calculate_retefuente( float $base, string $tipo ): float {
        $tarifas = [
            'honorarios' => 0.11,
            'compras'    => 0.025,
            'servicios'  => 0.04,
        ];
        $tarifa = $tarifas[ $tipo ] ?? 0.04;
        return round( $base * $tarifa, 2 );
    }

    public function calculate_reteiva( float $base_gravable, float $iva_rate = 0.19 ): float {
        return round( $base_gravable * $iva_rate * 0.15, 2 );
    }

    public function calculate_reteica( float $base, string $ciiu ): float {
        $tarifas = [ '4100' => 0.0069 ];
        $tarifa = $tarifas[ $ciiu ] ?? 0.00414;
        return round( $base * $tarifa, 2 );
    }

    public function calculate_total_retenciones( array $params ): array {
        $base     = (float) ( $params['base']     ?? 0 );
        $tipo     = (string) ( $params['tipo']     ?? 'servicios' );
        $ciiu     = (string) ( $params['ciiu']     ?? '4711' );
        $iva_rate = (float) ( $params['iva_rate'] ?? 0.19 );
        $retefuente = $this->calculate_retefuente( $base, $tipo );
        $reteiva    = $this->calculate_reteiva( $base, $iva_rate );
        $reteica    = $this->calculate_reteica( $base, $ciiu );
        $total      = round( $retefuente + $reteiva + $reteica, 2 );
        return [
            'retefuente'  => $retefuente,
            'reteiva'     => $reteiva,
            'reteica'     => $reteica,
            'total'       => $total,
            'neto_vendor' => round( $base - $total, 2 ),
        ];
    }
}