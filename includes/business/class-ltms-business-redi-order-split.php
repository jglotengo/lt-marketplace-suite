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
        // AUDIT-RD-BK RD-2 FIX: la llamada a LTMS_Commission_Strategy::get_rate()
        // estaba ANTES del loop, referenciando $origin_vendor_id que NO existe en
        // este scope (solo se define dentro de process_item). El resultado era
        // que get_rate() recibía NULL/0 como vendor_id → las reglas per-vendor
        // (tier DB, plan, contrato negociado) NUNCA aplicaban a órdenes ReDi,
        // cayendo siempre al DEFAULT_RATE. Comparar con class-ltms-order-split.php
        // linea 110 donde get_rate() se llama DENTRO del foreach con $vendor_id
        // correctamente definido por iteración.
        //
        // FIX: mover el cálculo de $platform_rate dentro de process_item() para
        // que cada item use el origin_vendor_id correspondiente (un carrito ReDi
        // puede tener items de distintos origin vendors con distintos tiers).
        $country = LTMS_Core_Config::get_country();

        foreach ( $redi_items as $item_data ) {
            try {
                self::process_item( $order, $item_data, $country );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error(
                    'REDI_SPLIT_ITEM_FAILED',
                    sprintf( 'Order #%d item %d: %s', $order->get_id(), $item_data['item_id'] ?? 0, $e->getMessage() )
                );
            }
        }
    }

    private static function process_item( \WC_Order $order, array $item_data, string $country ): void {
        // FASE5 P0 FIX: idempotency check — if this order_item_id is already in
        // lt_redi_commissions, skip. Without this, a cron retry or double-firing
        // ltms_order_paid hook would double-credit wallets + duplicate commission rows.
        global $wpdb;
        $order_item_id = (int) ( $item_data['item_id'] ?? 0 );
        if ( $order_item_id > 0 ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}lt_redi_commissions WHERE order_id = %d AND order_item_id = %d LIMIT 1",
                $order->get_id(),
                $order_item_id
            ) );
            if ( $existing ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info(
                        'REDI_SPLIT_SKIP_DUPLICATE',
                        sprintf( 'Order #%d item %d already processed (commission row #%d) — skipping.', $order->get_id(), $order_item_id, $existing )
                    );
                }
                return;
            }
        }

        $gross              = (float) ( $item_data['gross'] ?? 0 );
        $reseller_id        = (int) ( $item_data['reseller_id'] ?? 0 );
        $origin_vendor_id   = (int) ( $item_data['origin_vendor_id'] ?? 0 );
        $redi_rate          = (float) ( $item_data['redi_rate'] ?? 0 );
        $agreement_id       = (int) ( $item_data['agreement_id'] ?? 0 );

        if ( $gross <= 0 || ! $reseller_id || ! $origin_vendor_id ) {
            return;
        }

        // AUDIT-RD-BK RD-2 FIX: calcular $platform_rate AQUÍ (dentro del item),
        // usando el $origin_vendor_id real de este item. Antes este cálculo
        // estaba en process() con $origin_vendor_id indefinido → siempre caía
        // al default 0.15.
        $platform_rate = class_exists( 'LTMS_Commission_Strategy' )
            ? LTMS_Commission_Strategy::get_rate( $origin_vendor_id, $order )
            : (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.15 );

        // AUDIT-RD-BK RD-3 FIX: anti-comisión-circular. Si el customer que
        // compró es el propio reseller (mismo user_id), NO se paga la comisión
        // ReDi al reseller — solo se acredita el neto al origin vendor como en
        // una venta directa. Sin este guardián, un reseller podía comprar su
        // propia copia ReDi y recibir de vuelta la comisión (descuento encubierto
        // = redi_rate * gross), abuso típico en modelos MLM. El origin vendor
        // sigue recibiendo su parte porque sí envió el producto.
        $customer_id        = (int) $order->get_customer_id();
        $is_self_purchase   = ( $customer_id > 0 && $customer_id === $reseller_id );
        if ( $is_self_purchase ) {
            LTMS_Core_Logger::warning(
                'REDI_CIRCULAR_PURCHASE_BLOCKED',
                sprintf(
                    'Order #%d: reseller #%d attempted to purchase own ReDi product (origin #%d). Reseller commission suppressed; origin vendor still credited.',
                    $order->get_id(), $reseller_id, $origin_vendor_id
                )
            );
        }

        // AUDIT-RD-BK RD-1 (related): si agreement_id es 0 después del fix de
        // get_agreement_id(), significa que NO hay acuerdo activo para este
        // par reseller+origin_product. Registramos para monitoreo pero NO
        // bloqueamos el pago al origin vendor (la venta fue legítima, el
        // producto estaba publicado cuando se hizo el pedido — la pausa del
        // acuerdo pudo ocurrir entre pedido y pago).
        if ( ! $agreement_id ) {
            LTMS_Core_Logger::warning(
                'REDI_NO_ACTIVE_AGREEMENT',
                sprintf(
                    'Order #%d item %d: no active agreement found for reseller #%d + origin_product (origin vendor #%d). Commission recorded with agreement_id=0.',
                    $order->get_id(), (int) ( $item_data['item_id'] ?? 0 ), $reseller_id, $origin_vendor_id
                )
            );
        }

        // Commission split formula.
        // RD-3: si es self-purchase, reseller_commission = 0 (no se paga al reseller).
        $platform_fee            = round( $gross * $platform_rate, 2 );
        $reseller_commission     = $is_self_purchase ? 0.0 : round( $gross * $redi_rate, 2 );
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

        // Retener comisión del vendedor origen durante el período de protección al consumidor
        $origin_held = false;
        $origin_tx_id = 0;
        if ( class_exists( 'LTMS_Business_Consumer_Protection' ) ) {
            $origin_held = LTMS_Business_Consumer_Protection::hold_commission( $origin_vendor_id, $origin_vendor_net, $order->get_id() );
        }
        if ( ! $origin_held ) {
            // FASE5 P0 FIX: capture the actual wallet tx_id returned by credit().
            // Previously the return value was discarded and origin_tx_id was set
            // to boolean true → ledger unreconcilable, audit trail broken.
            $origin_tx_id = (int) LTMS_Business_Wallet::credit(
                $origin_vendor_id,
                $origin_vendor_net,
                sprintf(
                    /* translators: %1$s: order number */
                    __( 'Comisión ReDi pedido #%1$s (origen)', 'ltms' ),
                    $order->get_order_number()
                ),
                [ 'type' => 'commission', 'order_id' => $order->get_id(), 'redi' => true, 'gross' => $gross ],
                $order->get_id()
            );
        }

        // Retener comisión del revendedor durante el período de protección al consumidor
        $reseller_held = false;
        $reseller_tx_id = 0;
        if ( $reseller_commission > 0 ) {
            if ( class_exists( 'LTMS_Business_Consumer_Protection' ) ) {
                $reseller_held = LTMS_Business_Consumer_Protection::hold_commission( $reseller_id, $reseller_commission, $order->get_id() );
            }
            if ( ! $reseller_held ) {
                $reseller_tx_id = (int) LTMS_Business_Wallet::credit(
                    $reseller_id,
                    $reseller_commission,
                    sprintf(
                        /* translators: %1$s: order number */
                        __( 'Comisión ReDi pedido #%1$s (revendedor)', 'ltms' ),
                        $order->get_order_number()
                    ),
                    [ 'type' => 'commission', 'order_id' => $order->get_id(), 'redi' => true ],
                    $order->get_id()
                );
            }
        }

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
        // M-QA-01: aligned key names with LTMS_Business_Order_Split::get_vendor_data()
        // so LTMS_Tax_Engine receives consistent field names regardless of order type.
        return [
            'vendor_id'             => $vendor_id,
            'tax_regime'            => get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'simplified',
            'nit'                   => get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '',
            'is_gran_contribuyente' => (bool) get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true ),
            'ciiu_code'             => get_user_meta( $vendor_id, 'ltms_ciiu_code', true ) ?: '4791',
            'municipality_code'     => get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: '',
            'monthly_income'        => (float) get_user_meta( $vendor_id, 'ltms_monthly_income_avg', true ),
        ];
    }
}

