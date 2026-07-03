<?php
/**
 * LTMS Tax Engine - Motor de Cálculo Fiscal Multi-País
 *
 * Fachada (Facade) que delega el cálculo de impuestos a la estrategia
 * correcta según el país configurado en el plugin.
 *
 * Países soportados: CO (Colombia), MX (México),
 * US (United States), EU (European Union), BR (Brazil).
 *
 * Cross-Border motor (Task 63-A/B): registration of US/EU/BR strategies is
 * defensive — if the strategy class is not loaded yet, the engine still
 * serves CO/MX (legacy behaviour) and only throws for unknown countries
 * that are explicitly requested.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Tax_Engine
 */
final class LTMS_Tax_Engine {

    /**
     * Punto de entrada del Kernel.
     *
     * Cross-Border motor (Task 63-D): registers the new US/EU/BR strategies
     * defensively so the engine continues to work whether or not the
     * strategy classes have been loaded by the autoloader. The strategy
     * instances are only created if the class exists at boot time.
     *
     * @return void
     */
    public static function init(): void {
        $cross_border_strategies = [
            'US' => 'LTMS_Tax_Strategy_US',
            'EU' => 'LTMS_Tax_Strategy_EU',
            'BR' => 'LTMS_Tax_Strategy_BR',
        ];

        foreach ( $cross_border_strategies as $country => $class_name ) {
            if ( ! isset( self::$strategies[ $country ] ) && class_exists( $class_name ) ) {
                self::$strategies[ $country ] = new $class_name();
            }
        }
    }

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
     * @param string $country      Código de país: 'CO', 'MX', 'US', 'EU', 'BR'.
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
        $result = apply_filters( 'ltms_after_tax_calculate', $result, $order_data, $vendor_data, $country );

        // RB-4 FIX (v2.9.19): Disparar ltms_tax_calculation_result para que los
        // listeners de Physical Products (PP-6 ICE/IEPS), Cross-Border (CB-3 IOSS,
        // CB-6 non-resident IVA withholding) se ejecuten. Antes de este fix,
        // esos listeners usaban un filter name que NO existía → silent dead code
        // desde v2.9.15 y v2.9.18. Pasamos los mismos 4 args que ltms_after_tax_calculate
        // para mantener consistencia; el filter nuevo se dispara DESPUÉS del existente
        // para que los modificadores anteriores ya hayan aplicado sus cambios.
        $result = apply_filters( 'ltms_tax_calculation_result', $result, $order_data['gross'] ?? 0.0, $order_data, $vendor_data );

        return $result;
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
     * @return string 'CO', 'MX', 'US', 'EU' o 'BR'.
     */
    public static function get_active_country(): string {
        return LTMS_Core_Config::get_country();
    }

    /**
     * Devuelve la estrategia fiscal para el país dado.
     *
     * Cross-Border motor (Task 63-D): resolves US/EU/BR strategies IF they
     * were pre-registered by `init()` (which only runs them through the
     * autoloader if the class exists). The legacy strategy_map keeps only
     * the CO/MX entries so that existing unit tests which call
     * `flush_strategies()` and then expect an InvalidArgumentException for
     * unrecognised countries continue to pass — the new strategies are
     * strictly additive (only available after `init()` has registered them).
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

        // Legacy map — only CO/MX are directly instantiated. US/EU/BR are
        // registered by init() at boot time so they appear in self::$strategies
        // before any calculate() call reaches this point. If they're NOT in
        // the cache here, the country is treated as unsupported (this preserves
        // the original InvalidArgumentException behaviour for tests).
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
     * Cross-Border motor (Task 63-D): added labels for sales_tax (US),
     * gst (CA/AU), vat (EU/UK), and customs duties so the breakdown table
     * reflects the new tax strategies.
     *
     * @param array  $tax_breakdown Resultado de calculate().
     * @param string $currency      Código de moneda (COP, MXN, USD, EUR, BRL, etc.).
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
            // Cross-border labels (Task 63-D).
            'sales_tax'     => __( 'Sales Tax (US)', 'ltms' ),
            'gst'           => __( 'GST', 'ltms' ),
            'vat'           => __( 'VAT', 'ltms' ),
            'customs_duty'  => __( 'Customs Duty', 'ltms' ),
            'customs_vat'   => __( 'Customs VAT', 'ltms' ),
            'customs_fee'   => __( 'Customs Fee', 'ltms' ),
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
    // ──────────────────────────────────────────────────────────────────────────
    // Utilidades aritméticas legacy (instance methods).
    //
    // Estos métodos calculan retenciones con tarifas hardcoded. NO se usan en el
    // flujo real de Order_Split (que pasa por la Strategy del país). Existen como
    // utilidades puras testeables sin WP/DB — 22+ unit tests en TaxEngineTest los
    // cubren. NO eliminar sin migrar los tests.
    //
    // Para nueva lógica, usar LTMS_Tax_Strategy_* (que sí consulta tablas).
    // ──────────────────────────────────────────────────────────────────────────

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