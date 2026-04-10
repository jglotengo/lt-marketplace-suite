<?php
/**
 * LTMS Order Split - Cálculo y Acreditación de Comisiones
 *
 * Divide el total de un pedido pagado entre:
 * 1. Comisión de la plataforma
 * 2. Comisión del vendedor (neto)
 * 3. Retenciones fiscales (según país y régimen del vendedor)
 * 4. Comisiones de referidos (red MLM si aplica)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Order_Split
 */
final class LTMS_Business_Order_Split {

    use LTMS_Logger_Aware;

    /**
     * Procesa la división de comisiones de un pedido pagado.
     *
     * @param \WC_Order $order Pedido completado.
     * @return void
     * @throws \RuntimeException Si no se puede procesar el pedido.
     */
    public static function process( \WC_Order $order ): void {
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            // Intento: extraer vendor_id desde los items del pedido
            $vendor_id = self::extract_vendor_from_items( $order );
        }

        if ( ! $vendor_id ) {
            LTMS_Core_Logger::warning(
                'ORDER_SPLIT_NO_VENDOR',
                sprintf( 'Pedido #%d sin vendor_id. No se procesarán comisiones.', $order->get_id() ),
                [ 'order_id' => $order->get_id() ]
            );
            return;
        }

        // v1.6.0: Skip entirely if all items are ReDi (handled by LTMS_Redi_Order_Listener)
        if ( self::order_is_full_redi( $order ) ) {
            return;
        }

        $gross_amount  = self::get_non_redi_gross( $order );
        if ( $gross_amount <= 0.0 ) {
            return;
        }

        $platform_rate   = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.10 ); // 10% default
        $country         = LTMS_Core_Config::get_country();

        // Calcular retenciones fiscales
        $tax_breakdown = self::calculate_tax_breakdown( $gross_amount, $order, $vendor_id, $country );

        // Calcular comisión plataforma
        $platform_fee   = round( $gross_amount * $platform_rate, 2 );
        $vendor_gross   = $gross_amount - $platform_fee;

        // Calcular retenciones a descontar del pago al vendedor
        $withholding_total = $tax_breakdown['withholding_total'] ?? 0.0;
        $vendor_net        = max( 0.0, $vendor_gross - $withholding_total );

        // Acreditar en billetera del vendedor
        LTMS_Wallet::credit(
            $vendor_id,
            $vendor_net,
            sprintf(
                /* translators: %1$s: número de pedido, %2$s: total del pedido */
                __( 'Comisión pedido #%1$s - Total bruto: %2$s', 'ltms' ),
                $order->get_order_number(),
                LTMS_Utils::format_money( $gross_amount )
            ),
            [
                'type'             => 'commission',
                'gross_amount'     => $gross_amount,
                'platform_fee'     => $platform_fee,
                'withholding'      => $withholding_total,
                'tax_breakdown'    => $tax_breakdown,
            ],
            $order->get_id()
        );

        // Registrar comisión en la tabla de comisiones
        self::record_commission( $order, $vendor_id, $gross_amount, $platform_fee, $vendor_net, $tax_breakdown );

        // Procesar comisiones de referidos si aplica
        if ( LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) === 'yes' ) {
            self::process_referral_commissions( $vendor_id, $platform_fee, $order );
        }

        LTMS_Core_Logger::info(
            'ORDER_SPLIT_DONE',
            sprintf(
                'Comisión acreditada al vendedor #%d: %s (bruto: %s, plataforma: %s, retenciones: %s)',
                $vendor_id,
                LTMS_Utils::format_money( $vendor_net ),
                LTMS_Utils::format_money( $gross_amount ),
                LTMS_Utils::format_money( $platform_fee ),
                LTMS_Utils::format_money( $withholding_total )
            ),
            [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id ]
        );
    }

    /**
     * Calcula el desglose de impuestos usando la estrategia del país.
     *
     * @param float     $gross_amount Monto bruto.
     * @param \WC_Order $order        Pedido.
     * @param int       $vendor_id    ID del vendedor.
     * @param string    $country      Código de país.
     * @return array
     */
    private static function calculate_tax_breakdown( float $gross_amount, \WC_Order $order, int $vendor_id, string $country ): array {
        if ( ! class_exists( 'LTMS_Tax_Engine' ) ) {
            return [ 'withholding_total' => 0.0 ];
        }

        $vendor_data = self::get_vendor_data( $vendor_id );
        $order_data  = [
            'order_id'     => $order->get_id(),
            'product_type' => $order->get_meta( '_ltms_product_type' ) ?: 'physical',
            'buyer_type'   => $order->get_meta( '_ltms_buyer_type' ) ?: 'person',
            'buyer_regime' => $order->get_meta( '_ltms_buyer_regime' ) ?: 'persona_natural',
            'municipality' => $order->get_billing_city(),
            'items'        => self::get_order_items_summary( $order ),
        ];

        try {
            return LTMS_Tax_Engine::calculate( $gross_amount, $order_data, $vendor_data, $country );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'TAX_BREAKDOWN_FAILED',
                sprintf( 'Error calculando impuestos pedido #%d: %s', $order->get_id(), $e->getMessage() )
            );
            return [ 'withholding_total' => 0.0 ];
        }
    }

    /**
     * Registra la comisión en la tabla lt_commissions.
     *
     * @param \WC_Order $order         Pedido.
     * @param int       $vendor_id     ID vendedor.
     * @param float     $gross_amount  Monto bruto.
     * @param float     $platform_fee  Comisión plataforma.
     * @param float     $vendor_net    Monto neto vendedor.
     * @param array     $tax_breakdown Desglose fiscal.
     * @return void
     */
    private static function record_commission(
        \WC_Order $order,
        int $vendor_id,
        float $gross_amount,
        float $platform_fee,
        float $vendor_net,
        array $tax_breakdown
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'lt_commissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'order_id'      => $order->get_id(),
                'vendor_id'     => $vendor_id,
                'gross_amount'  => $gross_amount,
                'platform_fee'  => $platform_fee,
                'vendor_net'    => $vendor_net,
                'tax_breakdown' => wp_json_encode( $tax_breakdown ),
                'currency'      => $order->get_currency(),
                'status'        => 'paid',
                'created_at'    => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Returns true if every line item in the order is a ReDi reseller product.
     * ReDi items are handled exclusively by LTMS_Redi_Order_Listener (priority 20).
     *
     * @param \WC_Order $order Pedido.
     * @return bool
     */
    private static function order_is_full_redi( \WC_Order $order ): bool {
        $items = $order->get_items();
        if ( empty( $items ) ) {
            return false;
        }
        foreach ( $items as $item ) {
            $pid = $item->get_product_id();
            if ( ! get_post_meta( $pid, '_ltms_redi_origin_product_id', true ) ) {
                return false; // At least one non-ReDi item
            }
        }
        return true;
    }

    /**
     * Returns the gross total of non-ReDi items only.
     * Used when an order contains a mix of regular and ReDi items.
     *
     * @param \WC_Order $order Pedido.
     * @return float
     */
    private static function get_non_redi_gross( \WC_Order $order ): float {
        $non_redi_total = 0.0;
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( ! get_post_meta( $pid, '_ltms_redi_origin_product_id', true ) ) {
                $non_redi_total += (float) $item->get_total();
            }
        }
        return $non_redi_total;
    }

    /**
     * Extrae el vendor_id desde los items del pedido (primer item con _vendor_id).
     *
     * @param \WC_Order $order Pedido.
     * @return int
     */
    private static function extract_vendor_from_items( \WC_Order $order ): int {
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $vendor_id  = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
            if ( $vendor_id > 0 ) {
                return $vendor_id;
            }
        }
        return 0;
    }

    /**
     * Obtiene los datos del vendedor para el cálculo fiscal.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array
     */
    private static function get_vendor_data( int $vendor_id ): array {
        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            return [];
        }

        return [
            'vendor_id'      => $vendor_id,
            'regime'         => get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'responsable_iva',
            'nit'            => get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '',
            'is_gran_contrib' => (bool) get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true ),
            'ciiu_code'      => get_user_meta( $vendor_id, 'ltms_ciiu_code', true ) ?: '4791',
            'municipality'   => get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: 'bogota',
            'monthly_income' => (float) get_user_meta( $vendor_id, 'ltms_monthly_income_avg', true ),
        ];
    }

    /**
     * Obtiene un resumen de los items del pedido.
     *
     * @param \WC_Order $order Pedido.
     * @return array
     */
    private static function get_order_items_summary( \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [
                'product_id'   => $item->get_product_id(),
                'product_name' => $item->get_name(),
                'subtotal'     => (float) $item->get_subtotal(),
                'total'        => (float) $item->get_total(),
                'quantity'     => $item->get_quantity(),
            ];
        }
        return $items;
    }

    /**
     * Procesa las comisiones de la red de referidos (MLM).
     *
     * @param int       $vendor_id    ID del vendedor que generó la venta.
     * @param float     $platform_fee Fee de la plataforma (se reparte entre la red).
     * @param \WC_Order $order        Pedido original.
     * @return void
     */
    private static function process_referral_commissions( int $vendor_id, float $platform_fee, \WC_Order $order ): void {
        if ( ! class_exists( 'LTMS_Referral_Tree' ) ) {
            return;
        }

        try {
            LTMS_Referral_Tree::distribute_commissions( $vendor_id, $platform_fee, $order->get_id() );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'REFERRAL_COMMISSION_FAILED',
                sprintf( 'Error en comisiones referidos pedido #%d: %s', $order->get_id(), $e->getMessage() )
            );
        }
    }
}
