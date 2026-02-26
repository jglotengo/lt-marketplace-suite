<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_Business_Redi_Order_Split
 * Processes the commission split for ReDi (reseller distribution) items.
 */
class LTMS_Business_Redi_Order_Split {

    use LTMS_Logger_Aware;

    /**
     * Processes ReDi commission splits for all ReDi items in an order.
     *
     * @param \WC_Order $order      WC Order.
     * @param array     $redi_items Array from LTMS_Business_Redi_Manager::detect_redi_items().
     * @return void
     */
    public static function process( \WC_Order $order, array $redi_items ): void {
        $platform_rate = (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.10 );
        $country       = LTMS_Core_Config::get_country();

        foreach ( $redi_items as $item_data ) {
            try {
                self::process_item( $order, $item_data, $platform_rate, $country );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error(
                    'REDI_SPLIT_ITEM_FAILED',
                    sprintf( 'Order #%d item %d: %s', $order->get_id(), $item_data['item_id'] ?? 0, $e->getMessage() )
                );
            }
        }
    }

    private static function process_item( \WC_Order $order, array $item_data, float $platform_rate, string $country ): void {
        $gross              = (float) ( $item_data['gross'] ?? 0 );
        $reseller_id        = (int) ( $item_data['reseller_id'] ?? 0 );
        $origin_vendor_id   = (int) ( $item_data['origin_vendor_id'] ?? 0 );
        $redi_rate          = (float) ( $item_data['redi_rate'] ?? 0 );
        $agreement_id       = (int) ( $item_data['agreement_id'] ?? 0 );

        if ( $gross <= 0 || ! $reseller_id || ! $origin_vendor_id ) {
            return;
        }

        // Commission split formula
        $platform_fee            = round( $gross * $platform_rate, 2 );
        $reseller_commission     = round( $gross * $redi_rate, 2 );
        $origin_vendor_gross     = $gross - $platform_fee - $reseller_commission;
        $origin_vendor_gross     = max( 0.0, $origin_vendor_gross );

        // Tax withholding on origin vendor gross
        $origin_vendor_data = self::get_vendor_data( $origin_vendor_id );
        $order_data         = [
            'order_id'     => $order->get_id(),
            'product_type' => 'physical',
            'buyer_type'   => $order->get_meta( '_ltms_buyer_type' ) ?: 'person',
            'municipality' => $order->get_billing_city(),
        ];

        $tax_breakdown  = [];
        $tax_withholding = 0.0;
        if ( class_exists( 'LTMS_Tax_Engine' ) ) {
            try {
                $tax_breakdown   = LTMS_Tax_Engine::calculate( $origin_vendor_gross, $order_data, $origin_vendor_data, $country );
                $tax_withholding = (float) ( $tax_breakdown['withholding_total'] ?? 0.0 );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'REDI_TAX_CALC_FAILED', $e->getMessage() );
            }
        }

        $origin_vendor_net = max( 0.0, $origin_vendor_gross - $tax_withholding );

        // Credit origin vendor
        $origin_tx_id = LTMS_Wallet::credit(
            $origin_vendor_id,
            $origin_vendor_net,
            'commission',
            sprintf(
                /* translators: %1$s: order number */
                __( 'Comisión ReDi pedido #%1$s (origen)', 'ltms' ),
                $order->get_order_number()
            ),
            [ 'order_id' => $order->get_id(), 'redi' => true, 'gross' => $gross ]
        );

        // Credit reseller
        $reseller_tx_id = LTMS_Wallet::credit(
            $reseller_id,
            $reseller_commission,
            'commission',
            sprintf(
                /* translators: %1$s: order number */
                __( 'Comisión ReDi pedido #%1$s (revendedor)', 'ltms' ),
                $order->get_order_number()
            ),
            [ 'order_id' => $order->get_id(), 'redi' => true ]
        );

        self::record_redi_commission(
            $order, $origin_vendor_id, $reseller_id,
            $gross, $platform_fee, $reseller_commission,
            $origin_vendor_gross, $origin_vendor_net, $tax_withholding,
            $redi_rate, $tax_breakdown, $agreement_id,
            (int) $origin_tx_id, (int) $reseller_tx_id,
            $item_data['item_id'] ?? null
        );

        LTMS_Core_Logger::info(
            'REDI_SPLIT_DONE',
            sprintf(
                'Order #%d ReDi split: origin #%d net=%s, reseller #%d commission=%s',
                $order->get_id(), $origin_vendor_id,
                LTMS_Utils::format_money( $origin_vendor_net ),
                $reseller_id,
                LTMS_Utils::format_money( $reseller_commission )
            )
        );
    }

    private static function record_redi_commission(
        \WC_Order $order,
        int $origin_id,
        int $reseller_id,
        float $gross,
        float $platform_fee,
        float $reseller_commission,
        float $origin_gross,
        float $origin_net,
        float $tax_withholding,
        float $redi_rate,
        array $tax_breakdown,
        int $agreement_id,
        int $origin_tx_id,
        int $reseller_tx_id,
        ?int $item_id
    ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'lt_redi_commissions',
            [
                'agreement_id'          => $agreement_id,
                'order_id'              => $order->get_id(),
                'order_item_id'         => $item_id,
                'origin_vendor_id'      => $origin_id,
                'reseller_vendor_id'    => $reseller_id,
                'gross_amount'          => $gross,
                'platform_fee'          => $platform_fee,
                'reseller_commission'   => $reseller_commission,
                'origin_vendor_gross'   => $origin_gross,
                'tax_withholding'       => $tax_withholding,
                'origin_vendor_net'     => $origin_net,
                'redi_rate'             => $redi_rate,
                'currency'              => $order->get_currency(),
                'status'                => 'paid',
                'origin_tx_id'          => $origin_tx_id ?: null,
                'reseller_tx_id'        => $reseller_tx_id ?: null,
                'metadata'              => wp_json_encode( [ 'tax_breakdown' => $tax_breakdown ] ),
                'created_at'            => LTMS_Utils::now_utc(),
            ],
            [ '%d','%d','%d','%d','%d','%f','%f','%f','%f','%f','%f','%f','%s','%s','%d','%d','%s','%s' ]
        );
    }

    private static function get_vendor_data( int $vendor_id ): array {
        return [
            'vendor_id'       => $vendor_id,
            'regime'          => get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'responsable_iva',
            'nit'             => get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '',
            'is_gran_contrib' => (bool) get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true ),
            'ciiu_code'       => get_user_meta( $vendor_id, 'ltms_ciiu_code', true ) ?: '4791',
            'municipality'    => get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: 'bogota',
            'monthly_income'  => (float) get_user_meta( $vendor_id, 'ltms_monthly_income_avg', true ),
        ];
    }
}
