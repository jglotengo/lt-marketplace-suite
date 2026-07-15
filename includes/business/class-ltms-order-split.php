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
     * OS-BUG-A FIX: multi-vendor split. Antes se extraía UN vendor_id (del meta
     * del pedido o del primer item) y se acreditaba el net total a esa wallet —
     * órdenes con items de múltiples vendedores no se spliteaban: el primer vendor
     * recibía el 100% de la comisión de toda la orden, los demás recibían 0.
     *
     * Ahora agrupamos items por vendor_id (via _ltms_vendor_id meta del producto
     * o post_author como fallback) y procesamos cada vendor por separado:
     *   1. Para cada vendor: calcular gross/fee/net/withholding.
     *   2. Para cada vendor: hold_commission (si Consumer_Protection disponible).
     *   3. UNA transacción externa para TODOS los credit + record_commission
     *      (atomic — si cualquiera falla, ROLLBACK deshace TODOS los credits).
     *   4. Después del COMMIT: comisiones de referidos (fuera de la tx externa
     *      porque Referral_Tree::distribute_commissions usa Wallet::credit que
     *      abre su propia transacción interna y rompería la outer).
     *
     * OS-BUG-B FIX: usa `Wallet::credit_within_transaction()` en vez de
     * `Wallet::credit()` dentro de la transacción externa. MySQL no soporta
     * transacciones anidadas — el START TRANSACTION interno de Wallet::credit()
     * hace COMMIT implícito del externo, haciendo que el ROLLBACK del catch
     * no pueda deshacer el crédito. credit_within_transaction() NO abre su
     * propia transacción (caller-managed), así que el ROLLBACK sí funciona.
     *
     * OS-BUG-C FIX: record_commission() ahora lanza excepción si $wpdb->insert
     * falla — antes el retorno no se verificaba y la comisión se consideraba
     * registrada aunque el insert hubiera fallado.
     *
     * WL-CRASH-2 FIX: cada credit_within_transaction recibe un idempotency_key
     * derivado de (order_id, vendor_id). Si el pedido se reprocesa tras un
     * crash parcial, los credits ya hechos se detectan y se saltan (idempotentes).
     *
     * @param \WC_Order $order Pedido completado.
     * @return void
     * @throws \RuntimeException Si no se puede procesar el pedido.
     */
    public static function process( \WC_Order $order ): void {
        // v1.6.0: Skip entirely if all items are ReDi (handled by LTMS_Redi_Order_Listener)
        if ( self::order_is_full_redi( $order ) ) {
            return;
        }

        // OS-BUG-A: group items by vendor_id (multi-vendor split).
        // Returns: [vendor_id => ['gross' => float, 'items' => WC_Order_Item[]]]
        $vendor_totals = self::group_items_by_vendor( $order );

        if ( empty( $vendor_totals ) ) {
            LTMS_Core_Logger::warning(
                'ORDER_SPLIT_NO_VENDOR',
                sprintf( 'Pedido #%d sin vendor_id en items. No se procesarán comisiones.', $order->get_id() ),
                [ 'order_id' => $order->get_id() ]
            );
            return;
        }

        // Single-vendor backward-compat: if only one vendor, populate _ltms_vendor_id
        // meta on the order so downstream filters (commission strategy, tax engine)
        // that read this meta keep working.
        if ( count( $vendor_totals ) === 1 ) {
            $single_vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            if ( ! $single_vendor_id ) {
                $single_vendor_id = (int) array_key_first( $vendor_totals );
                $order->update_meta_data( '_ltms_vendor_id', $single_vendor_id );
                $order->save();
            }
        }

        $country = LTMS_Core_Config::get_country();

        // Phase 1: pre-compute amounts per vendor + hold_commission (outside outer tx
        // because Wallet::hold opens its own transaction and would commit the outer).
        $vendor_results = []; // vendor_id => [gross, platform_fee, vendor_net, withholding_total, tax_breakdown, held]
        foreach ( $vendor_totals as $vendor_id => $data ) {
            $vendor_id     = (int) $vendor_id;
            $gross_amount  = (float) $data['gross'];

            if ( $gross_amount <= 0.0 ) {
                continue;
            }

            // CS-CASCADE: per-vendor commission rate via LTMS_Commission_Strategy cascade.
            $platform_rate = class_exists( 'LTMS_Commission_Strategy' )
                ? LTMS_Commission_Strategy::get_rate( $vendor_id, $order )
                : (float) LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.15 );

            $tax_breakdown = self::calculate_tax_breakdown( $gross_amount, $order, $vendor_id, $country );

            $platform_fee = round( $gross_amount * $platform_rate, 2 );
            $vendor_gross = $gross_amount - $platform_fee;

            // M-QA-02: normalised key. Tax engine returns 'withholding_total';
            // 'total_withholding' kept as defensive fallback.
            $withholding_total = $tax_breakdown['withholding_total'] ?? $tax_breakdown['total_withholding'] ?? 0.0;
            $vendor_net        = max( 0.0, $vendor_gross - $withholding_total );

            // Retener comisión durante el período de protección al consumidor (Ley 1480 Colombia).
            // Wallet::hold() abre su propia transacción — debe llamarse FUERA de la outer tx.
            $held = false;
            if ( class_exists( 'LTMS_Business_Consumer_Protection' ) ) {
                $held = LTMS_Business_Consumer_Protection::hold_commission( $vendor_id, $vendor_net, $order->get_id() );
            }

            $vendor_results[ $vendor_id ] = [
                'gross_amount'      => $gross_amount,
                'platform_fee'      => $platform_fee,
                'vendor_net'        => $vendor_net,
                'withholding_total' => $withholding_total,
                'tax_breakdown'     => $tax_breakdown,
                'held'              => $held,
            ];
        }

        // OS-4 FIX (AUDIT-OS) CRÍTICO: persistir en el order los metas
        // `_ltms_vendor_net`, `_ltms_platform_fee`, `_ltms_withholding_total`.
        //
        // ANTES: Consumer_Protection::approve_dispute() leía `_ltms_vendor_net`
        // para debitar al vendor SOLO lo que realmente recibió (no order_total).
        // Pero Order_Split NUNCA persistía este meta → approve_dispute() caía
        // siempre al fallback `$vendor_net = $refund_amount` (order_total) → el
        // vendor era debitado por (platform_fee + withholding) MÁS de lo que
        // recibió. La corrección CP4 (v2.8.8) era dead code.
        //
        // Adicionalmente, Alegra_Sync lee `_ltms_platform_fee` para emitir la
        // factura electrónica con la comisión correcta — sin este meta, la
        // factura siempre reportaba commission=0.
        //
        // Para single-vendor: se guarda el valor directo (compatible con
        // approve_dispute() y Alegra_Sync). Para multi-vendor: se guarda la
        // suma agregada (un order solo tiene un meta por clave) — el caso
        // multi-vendor requiere una refactorización más profunda de
        // approve_dispute() para debitar por-vendor, fuera del scope de este fix.
        if ( ! empty( $vendor_results ) ) {
            $agg_vendor_net   = 0.0;
            $agg_platform_fee = 0.0;
            $agg_withholding  = 0.0;
            foreach ( $vendor_results as $r ) {
                $agg_vendor_net   += (float) $r['vendor_net'];
                $agg_platform_fee += (float) $r['platform_fee'];
                $agg_withholding  += (float) $r['withholding_total'];
            }
            $order->update_meta_data( '_ltms_vendor_net', round( $agg_vendor_net, 2 ) );
            $order->update_meta_data( '_ltms_platform_fee', round( $agg_platform_fee, 2 ) );
            $order->update_meta_data( '_ltms_withholding_total', round( $agg_withholding, 2 ) );

            // Para multi-vendor, guardar también el desglose por vendor para
            // que una futura refactorización de approve_dispute() pueda debitar
            // solo al vendor disputado. Formato: [vendor_id => [net, fee, withholding]]
            if ( count( $vendor_results ) > 1 ) {
                $per_vendor = [];
                foreach ( $vendor_results as $vid => $r ) {
                    $per_vendor[ (int) $vid ] = [
                        'vendor_net'        => round( (float) $r['vendor_net'], 2 ),
                        'platform_fee'      => round( (float) $r['platform_fee'], 2 ),
                        'withholding_total' => round( (float) $r['withholding_total'], 2 ),
                        'gross_amount'      => round( (float) $r['gross_amount'], 2 ),
                    ];
                }
                $order->update_meta_data( '_ltms_vendor_split_breakdown', $per_vendor );
            }

            $order->save();
        }

        // Phase 2: ONE outer transaction for ALL credits + record_commissions.
        // OS-BUG-B: credit_within_transaction does NOT open its own tx → ROLLBACK here
        // can undo ALL credits atomically if any record_commission fails.
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        try {
            foreach ( $vendor_results as $vendor_id => $r ) {
                $vendor_id = (int) $vendor_id;

                // WL-CRASH-2: idempotency key per (order, vendor, type). If the order is
                // reprocessed after a partial crash, the credit is skipped (already done).
                $idempotency_key = sprintf( 'os_credit_o%d_v%d', $order->get_id(), $vendor_id );

                // Fallback: si Consumer Protection no está disponible o no hizo hold,
                // acreditar directamente al vendedor.
                if ( ! $r['held'] ) {
                    // OS-5 FIX (AUDIT-OS) CRÍTICO: defensive double-credit guard.
                    //
                    // hold_commission() hace dos operaciones Wallet (credit + hold)
                    // que NO son atómicas entre sí — cada una abre su propia tx
                    // MySQL. Si `credit` SUCCEEDE pero `hold` FALLA (deadlock, wallet
                    // frozen, etc.), hold_commission() retorna false (CP-BUG-4),
                    // PERO el crédito ya quedó aplicado en la wallet del vendor.
                    //
                    // Sin este guard, process() vería held=false y caería al
                    // credit_within_transaction() de abajo — creditando al vendor
                    // DOS VECES por la misma comisión (una vez por hold_commission
                    // con key `cp_hold_credit_o{order}_v{vendor}`, otra vez aquí
                    // con key `os_credit_o{order}_v{vendor}`). Los keys son
                    // distintos → el idempotency check del Wallet no los detecta
                    // como duplicados.
                    //
                    // Solución: antes de credit_within_transaction, verificar si
                    // hold_commission ya aplicó un crédito (parcial failure) con
                    // el key `cp_hold_credit_o{order}_v{vendor}`. Si existe, skip.
                    //
                    // La query corre DENTRO de la outer tx (consistencia con el
                    // ROLLBACK posterior si record_commission falla).
                    $cp_credit_key = sprintf( 'cp_hold_credit_o%d_v%d', $order->get_id(), $vendor_id );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $existing_cp_credit = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM `{$wpdb->prefix}lt_wallet_transactions` WHERE `reference` = %s LIMIT 1",
                            $cp_credit_key
                        )
                    );

                    if ( $existing_cp_credit > 0 ) {
                        // hold_commission aplicó un crédito que quedó "huérfano"
                        // (sin hold) — NO re-acreditar. Log CRITICAL para que
                        // ops revise por qué el hold falló y compense manualmente
                        // (el vendor tiene el dinero disponible sin retención).
                        LTMS_Core_Logger::error(
                            'ORDER_SPLIT_HOLD_PARTIAL_FAILURE',
                            sprintf(
                                'Pedido #%d vendor #%d: hold_commission aplicó crédito (#%d) pero no hold — skip credit_within_transaction para evitar doble crédito. Revisar manualmente.',
                                $order->get_id(),
                                $vendor_id,
                                $existing_cp_credit
                            ),
                            [
                                'order_id'           => $order->get_id(),
                                'vendor_id'          => $vendor_id,
                                'existing_credit_id' => $existing_cp_credit,
                                'idempotency_key'    => $cp_credit_key,
                            ]
                        );
                    } else {
                        // OS-BUG-2 FIX (Task 65-C): credit in ORDER currency, not config currency.
                        // Previously, currency='' caused execute_transaction to default to
                        // LTMS_Core_Config::get_currency() (base). But $r['vendor_net'] is
                        // derived from $item->get_total() which is in WC order currency. If
                        // order_currency != config_currency (e.g. order USD, config COP), the
                        // vendor was credited in the wrong currency (e.g. COP $100 instead of
                        // ~400,000 COP). Downstream convert_balance() then failed because the
                        // order_currency wallet had 0 balance. Crediting in order_currency
                        // makes convert_balance()'s debit work correctly.
                        $order_currency = $order->get_currency();

                        LTMS_Business_Wallet::credit_within_transaction(
                            $vendor_id,
                            $r['vendor_net'],
                            sprintf(
                                /* translators: 1: número de pedido, 2: vendor id, 3: total bruto */
                                __( 'Comisión pedido #%1$s (vendor #%2$d) - Total bruto: %3$s', 'ltms' ),
                                $order->get_order_number(),
                                $vendor_id,
                                LTMS_Utils::format_money( $r['gross_amount'] )
                            ),
                            [
                                'type'          => 'commission',
                                'gross_amount'  => $r['gross_amount'],
                                'platform_fee'  => $r['platform_fee'],
                                'withholding'   => $r['withholding_total'],
                                'tax_breakdown' => $r['tax_breakdown'],
                            ],
                            $order->get_id(),
                            $order_currency, // OS-BUG-2: explicit order currency (not config default)
                            $idempotency_key
                        );
                    }
                }

                // OS-2 FIX (AUDIT-OS) CRÍTICO: record_commission idempotency check.
                // Si process() se llama dos veces (race condition, webhook double-fire,
                // order re-save), credit_within_transaction es idempotente (skip por
                // idempotency_key) — pero record_commission() insertaba OTRA fila en
                // lt_commissions → duplicado en reportes de vendor earnings y
                // reconciliación. La tabla lt_commissions no tiene UNIQUE(order_id,
                // vendor_id) — verificar existencia antes de insertar.
                //
                // Nota: la verificación corre DENTRO de la outer tx → consistente
                // con el ROLLBACK si algo falla después.
                $existing_commission_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        "SELECT id FROM `{$wpdb->prefix}lt_commissions` WHERE order_id = %d AND vendor_id = %d LIMIT 1",
                        $order->get_id(),
                        $vendor_id
                    )
                );

                if ( $existing_commission_id > 0 ) {
                    LTMS_Core_Logger::info(
                        'ORDER_SPLIT_COMMISSION_ALREADY_RECORDED',
                        sprintf(
                            'Pedido #%d vendor #%d: comisión #%d ya registrada — skip insert (idempotency).',
                            $order->get_id(),
                            $vendor_id,
                            $existing_commission_id
                        ),
                        [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id, 'commission_id' => $existing_commission_id ]
                    );
                } else {
                    // OS-BUG-C: record_commission lanza excepción si $wpdb->insert falla.
                    self::record_commission( $order, $vendor_id, $r['gross_amount'], $r['platform_fee'], $r['vendor_net'], $r['tax_breakdown'] );
                }

                LTMS_Core_Logger::info(
                    'ORDER_SPLIT_DONE',
                    sprintf(
                        'Comisión acreditada al vendedor #%d: %s (bruto: %s, plataforma: %s, retenciones: %s)',
                        $vendor_id,
                        LTMS_Utils::format_money( $r['vendor_net'] ),
                        LTMS_Utils::format_money( $r['gross_amount'] ),
                        LTMS_Utils::format_money( $r['platform_fee'] ),
                        LTMS_Utils::format_money( $r['withholding_total'] )
                    ),
                    [
                        'order_id'     => $order->get_id(),
                        'vendor_id'    => $vendor_id,
                        'multi_vendor' => count( $vendor_results ) > 1,
                        'held'         => $r['held'],
                    ]
                );
            }

            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            LTMS_Core_Logger::error(
                'ORDER_SPLIT_TX_FAILED',
                sprintf(
                    'Transacción de comisión fallida para pedido #%d (%d vendors): %s',
                    $order->get_id(),
                    count( $vendor_results ),
                    $e->getMessage()
                ),
                [
                    'order_id'   => $order->get_id(),
                    'vendor_ids' => array_keys( $vendor_results ),
                    'exception'  => get_class( $e ),
                ]
            );

            throw $e;
        }

        // Phase 3: referral commissions — OUTSIDE the outer tx because
        // Referral_Tree::distribute_commissions calls Wallet::credit() which opens
        // its own transaction (would auto-commit the outer). Each referral credit
        // catches its own exceptions internally, so a failure in one referral level
        // doesn't break the main commission (which is already committed).
        if ( LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) === 'yes' ) {
            foreach ( $vendor_results as $vendor_id => $r ) {
                self::process_referral_commissions( (int) $vendor_id, $r['platform_fee'], $order );
            }
        }

        // Phase 4: Donation motor hook — fired AFTER the COMMIT and AFTER referral
        // commissions so the donation is only recorded if the entire split succeeded
        // (atomicity guarantee: a ROLLBACK above never reaches this point).
        //
        // Loose coupling: the donation motor (LTMS_Donation_Manager, Task 60-B) listens
        // on 'ltms_order_paid_after_split' and decides whether a donation applies
        // (config flag, foundation wallet, calculator). Order_Split does NOT depend
        // on the donation motor — if the motor is disabled or absent, the action
        // simply has no listeners and is a no-op.
        //
        // Aggregates: $total_platform_fee and $total_vendor_net are summed across
        // all vendors in the split (multi-vendor support). $first_vendor_id is 0
        // when the order has more than one vendor (donation calculator falls back
        // to platform-level donation in that case).
        $total_platform_fee = 0.0;
        $total_vendor_net   = 0.0;
        foreach ( $vendor_results as $r ) {
            $total_platform_fee += (float) $r['platform_fee'];
            $total_vendor_net   += (float) $r['vendor_net'];
        }
        $total_platform_fee = round( $total_platform_fee, 2 );
        $total_vendor_net   = round( $total_vendor_net, 2 );

        $first_vendor_id = count( $vendor_results ) === 1
            ? (int) array_key_first( $vendor_results )
            : 0;

        /**
         * Fires after the order split has committed successfully (all vendor wallets
         * credited + commissions recorded + referral commissions distributed).
         *
         * @param int   $order_id  WooCommerce order ID.
         * @param array $args      {
         *     @type float  $platform_fee Total platform commission across all vendors.
         *     @type float  $order_total  Order grand total ($order->get_total()).
         *     @type float  $vendor_net   Total net credited to vendor wallets.
         *     @type int    $vendor_id    First vendor ID (single-vendor) or 0 (multi-vendor).
         *     @type string $currency     Order currency (e.g. 'COP', 'MXN').
         * }
         */
        do_action( 'ltms_order_paid_after_split', $order->get_id(), [
            'platform_fee' => $total_platform_fee,
            'order_total'  => (float) $order->get_total(),
            'vendor_net'   => $total_vendor_net,
            'vendor_id'    => $first_vendor_id,
            'currency'     => $order->get_currency(),
        ] );

        // RB-3 FIX (v2.9.19): Disparar ltms_order_paid para que los listeners
        // de Cross-Border (CB-1 cert origin, CB-4 AES/EEI, CB-7 VUCE, CB-8 EUR.1)
        // se ejecuten. Antes de este fix, esos listeners estaban registrados
        // (add_action) pero NUNCA se disparaban → silent dead code en v2.9.18.
        // Se dispara DESPUÉS de ltms_order_paid_after_split para asegurar que
        // los metadatos del split (vendor_id, currency) ya estén persistidos.
        do_action( 'ltms_order_paid', $order->get_id() );

        // ────────────────────────────────────────────────────────────────────
        // v3.1.0 — Cross-Border motor (Task 63-D).
        //
        // For each vendor, detect whether the order is international (vendor's
        // origin country ≠ shipping destination country). If so:
        //   1. Calculate customs duties via LTMS_Customs_Calculator.
        //   2. Persist the declaration to `lt_customs_declarations`.
        //   3. Convert the vendor's net commission to the vendor's settlement
        //      currency via LTMS_Currency_Manager and credit that wallet
        //      (the legacy credit above already credited in display currency
        //      when no FX is needed — we only FX-credit when the currencies
        //      differ to keep the audit trail clean).
        //   4. If the incoterm is DDP, debit the duties from the vendor wallet
        //      (the platform collected them from the buyer at checkout).
        //   5. Fire `ltms_cross_border_order` so listeners (Alegra, reports,
        //      shipping carrier integrations) can react.
        //
        // Defensive: the entire block is wrapped in try/catch so a failure
        // in the cross-border enhancement never rolls back the main split
        // (which is already committed at this point).
        // ────────────────────────────────────────────────────────────────────
        if ( class_exists( 'LTMS_Customs_Calculator' ) ) {
            try {
                foreach ( $vendor_results as $vendor_id => $r ) {
                    $vendor_id = (int) $vendor_id;
                    self::process_cross_border_for_vendor( $order, $vendor_id, $r );
                }
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error(
                    'ORDER_SPLIT_CROSS_BORDER_FAILED',
                    sprintf( 'Cross-border enhancement failed for order #%d: %s', $order->get_id(), $e->getMessage() ),
                    [ 'order_id' => $order->get_id(), 'exception' => get_class( $e ) ]
                );
            }
        }
    }

    /**
     * OS-BUG-A FIX: agrupa los items de un pedido por vendor_id y suma el gross.
     *
     * Para cada item, resuelve el vendor_id (via _ltms_vendor_id meta del producto
     * o post_author como fallback). Agrupa por vendor_id y suma el total (get_total)
     * de los items de cada vendor. Excluye items ReDi (handled by ReDi order listener).
     *
     * @param \WC_Order $order Pedido.
     * @return array<int, array{gross: float, items: array<int, \WC_Order_Item>}> vendor_id => data.
     */
    private static function group_items_by_vendor( \WC_Order $order ): array {
        $vendor_totals = []; // vendor_id => ['gross' => float, 'items' => []]

        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            if ( ! $product_id ) {
                continue;
            }

            // Skip ReDi items — handled by LTMS_Redi_Order_Listener exclusively.
            if ( get_post_meta( $product_id, '_ltms_redi_origin_product_id', true ) ) {
                continue;
            }

            $line_total = (float) $item->get_total();
            if ( $line_total <= 0.0 ) {
                continue;
            }

            // Resolve vendor_id: _ltms_vendor_id meta first, post_author fallback.
            $vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
            if ( $vendor_id <= 0 ) {
                // M-210 fallback: post_author del producto.
                $vendor_id = (int) get_post_field( 'post_author', $product_id );
            }

            if ( $vendor_id <= 0 ) {
                // No se pudo determinar el vendor del item — loguear y skip.
                LTMS_Core_Logger::warning(
                    'ORDER_SPLIT_ITEM_NO_VENDOR',
                    sprintf( 'Pedido #%d: item "%s" (product #%d) sin vendor_id resoluble — item omitido del split.', $order->get_id(), $item->get_name(), $product_id ),
                    [ 'order_id' => $order->get_id(), 'product_id' => $product_id, 'line_total' => $line_total ]
                );
                continue;
            }

            if ( ! isset( $vendor_totals[ $vendor_id ] ) ) {
                $vendor_totals[ $vendor_id ] = [ 'gross' => 0.0, 'items' => [] ];
            }
            $vendor_totals[ $vendor_id ]['gross'] += $line_total;
            $vendor_totals[ $vendor_id ]['items'][] = $item;
        }

        // Round each vendor's gross to 2 decimals (sum of floats may have FP drift).
        foreach ( $vendor_totals as $vid => $data ) {
            $vendor_totals[ $vid ]['gross'] = round( $data['gross'], 2 );
        }

        return $vendor_totals;
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
            'buyer_regime'              => $order->get_meta( '_ltms_buyer_regime' ) ?: 'persona_natural',
            'buyer_is_gran_contribuyente' => (bool) $order->get_meta( '_ltms_buyer_is_gran_contribuyente' ),
            'municipality' => $order->get_billing_city(),
            // M-200: territorialidad ReteICA — capturado por dropdown DANE en checkout (task #9).
            // Si está vacío en pedidos previos al dropdown, el strategy hace fallback al municipio del vendedor.
            'buyer_municipality_code' => (string) $order->get_meta( '_ltms_billing_municipality_code' ),
            'items'        => self::get_order_items_summary( $order ),
        ];

        try {
            return LTMS_Tax_Engine::calculate( $gross_amount, $order_data, $vendor_data, $country );
        } catch ( \Throwable $e ) {
            // RE-AUDIT P1 FIX: returning withholding_total=0 on failure → vendor
            // receives full vendor_gross with ZERO tax withholding → platform
            // undercollects taxes (ReteIVA, ReteICA). Now: log as critical and
            // return a conservative estimate (default withholding rate) rather
            // than zero, so taxes are over-collected rather than under-collected.
            $default_rate = class_exists( 'LTMS_Core_Config' )
                ? (float) LTMS_Core_Config::get( 'ltms_tax_fallback_withholding_rate', 0.10 )
                : 0.10;
            $fallback_withholding = round( $gross_amount * $default_rate, 2 );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'TAX_BREAKDOWN_FAILED',
                    sprintf( 'Pedido #%d: tax engine failed: %s. Using fallback withholding $%.2f (rate=%.1f%%).', $order->get_id(), $e->getMessage(), $fallback_withholding, $default_rate * 100 ),
                    [ 'order_id' => $order->get_id(), 'gross' => $gross_amount, 'fallback_withholding' => $fallback_withholding, 'error' => $e->getMessage() ]
                );
            }
            return [ 'withholding_total' => $fallback_withholding ];
        }
    }

    /**
     * Registra la comisión en la tabla lt_commissions.
     *
     * OS-BUG-C FIX: verifica el retorno de $wpdb->insert. Antes el retorno no se
     * verificaba — si el insert fallaba (tabla corrupta, deadlock no reintegrado,
     * constraint violation silenciosa), la comisión se consideraba registrada pero en
     * realidad no existía. Esto causaba inconsistencias entre lt_wallet_transactions
     * (donde el crédito SÍ se aplicaba) y lt_commissions (donde faltaba el registro).
     * Ahora lanza una excepción que el caller puede ROLLBACK en la tx externa.
     *
     * @param \WC_Order $order         Pedido.
     * @param int       $vendor_id     ID vendedor.
     * @param float     $gross_amount  Monto bruto.
     * @param float     $platform_fee  Comisión plataforma.
     * @param float     $vendor_net    Monto neto vendedor.
     * @param array     $tax_breakdown Desglose fiscal.
     * @return void
     * @throws \RuntimeException Si el insert falla (caller debe ROLLBACK la tx externa).
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

        // Obtener el primer product_id del pedido (este registro es agregado por pedido/vendedor)
        $first_product_id = 0;
        foreach ( $order->get_items() as $item ) {
            $first_product_id = (int) $item->get_product_id();
            break;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $table,
            [
                'order_id'          => $order->get_id(),
                'vendor_id'         => $vendor_id,
                'product_id'        => $first_product_id,          // NOT NULL — primer producto del pedido
                'gross_amount'      => $gross_amount,
                'commission_amount' => $platform_fee,               // comisión de la plataforma
                'vendor_amount'     => $vendor_net,                 // monto neto para el vendedor
                'tax_withholding'   => $tax_breakdown['withholding_total'] ?? $tax_breakdown['total_withholding'] ?? 0.0, // M-QA-02: normalised
                'iva_amount'        => $tax_breakdown['iva'] ?? $tax_breakdown['iva_amount'] ?? 0.0, // NOT NULL DEFAULT 0
                'currency'          => $order->get_currency(),
                'country_code'      => LTMS_Core_Config::get_country(),
                'status'            => 'paid',
                'metadata'          => wp_json_encode( $tax_breakdown ),
                'created_at'        => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );

        // OS-BUG-C FIX: el insert puede fallar por deadlock, constraint violation,
        // tabla corrupta, etc. Antes este caso era silencioso — la comisión NO se
        // registraba pero el crédito a la wallet SÍ. Ahora lanzamos excepción para
        // que el caller (process()) haga ROLLBACK en la transacción externa, dejando
        // lt_wallet_transactions y lt_commissions consistentes (ambos vacíos para
        // este pedido/vendor).
        if ( $result === false ) {
            throw new \RuntimeException(
                sprintf(
                    'LTMS Order Split: Failed to record commission for order #%d vendor #%d: %s',
                    $order->get_id(),
                    $vendor_id,
                    $wpdb->last_error ?: '(unknown DB error)'
                )
            );
        }

        // $result === 0 (no rows inserted) también es un fallo — lanzar igual.
        if ( $result === 0 ) {
            throw new \RuntimeException(
                sprintf(
                    'LTMS Order Split: record_commission inserted 0 rows for order #%d vendor #%d (no DB error reported).',
                    $order->get_id(),
                    $vendor_id
                )
            );
        }
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
            // Fallback M-210: usar post_author del producto cuando _ltms_vendor_id no está seteado
            $author_id = (int) get_post_field( 'post_author', $product_id );
            if ( $author_id > 0 ) {
                return $author_id;
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
            'vendor_id'               => $vendor_id,
            'tax_regime'              => get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'simplified', // M-81: era 'regime', tax-strategy espera 'tax_regime'
            'nit'                     => get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '',
            'is_gran_contribuyente'   => (bool) get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true ),   // M-83: era 'is_gran_contrib'
            'ciiu_code'               => get_user_meta( $vendor_id, 'ltms_ciiu_code', true ) ?: '4791',
            'municipality_code'       => self::resolve_vendor_municipality_dane( $vendor_id ),                    // M-200: resuelve slug legacy → DANE para lookup ReteICA municipal
            'monthly_income'          => (float) get_user_meta( $vendor_id, 'ltms_monthly_income_avg', true ),
        ];
    }

    /**
     * Resuelve el municipio del vendedor a código DANE (5 dígitos).
     *
     * Vendedores registrados antes del dropdown DANE (task #10) tienen ltms_municipality como slug
     * ('bogota', 'cali', etc.). Mapeamos las ciudades top a su código DANE para que el lookup ReteICA
     * municipal funcione mientras se completa la migración del dropdown.
     *
     * @param int $vendor_id ID del vendedor.
     * @return string Código DANE 5-dig o '' si no se puede resolver.
     */
    private static function resolve_vendor_municipality_dane( int $vendor_id ): string {
        $value = (string) ( get_user_meta( $vendor_id, 'ltms_municipality', true ) ?: '' );
        if ( $value === '' ) {
            return '';
        }
        // Si ya es código DANE (5 dígitos numéricos), usarlo directo.
        if ( preg_match( '/^\d{5}$/', $value ) ) {
            return $value;
        }
        // Slug legacy → DANE. Cobertura: capitales departamentales + área metropolitana principal.
        $slug_to_dane = [
            'bogota'        => '11001', 'bogotá'        => '11001',
            'medellin'      => '05001', 'medellín'      => '05001',
            'cali'          => '76001',
            'barranquilla'  => '08001',
            'cartagena'     => '13001',
            'bucaramanga'   => '68001',
            'pereira'       => '66001',
            'manizales'     => '17001',
            'cucuta'        => '54001', 'cúcuta'        => '54001',
            'ibague'        => '73001', 'ibagué'        => '73001',
            'villavicencio' => '50001',
            'pasto'         => '52001',
            'monteria'      => '23001', 'montería'      => '23001',
            'neiva'         => '41001',
            'armenia'       => '63001',
            'santa marta'   => '47001', 'santamarta'    => '47001',
            'valledupar'    => '20001',
            'tunja'         => '15001',
            'popayan'       => '19001', 'popayán'       => '19001',
        ];
        $key = strtolower( trim( $value ) );
        return $slug_to_dane[ $key ] ?? '';
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

    // =========================================================================
    // v3.1.0 — Cross-Border motor (Task 63-D)
    // =========================================================================

    /**
     * Ensures the `lt_customs_declarations` table exists (lazy, idempotent via dbDelta).
     *
     * The table is created on first cross-border order rather than via a formal
     * migration — this avoids coupling the cross-border feature to a specific
     * plugin version bump. A formal migration should be added in a future task
     * to consolidate the schema.
     *
     * @return void
     */
    private static function ensure_customs_table(): void {
        static $ensured = false;
        if ( $ensured ) {
            return;
        }
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        global $wpdb;
        $table   = $wpdb->prefix . 'lt_customs_declarations';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id`            BIGINT UNSIGNED NOT NULL,
            `vendor_id`           BIGINT UNSIGNED NOT NULL,
            `origin_country`      CHAR(2)         NOT NULL,
            `destination_country` CHAR(2)         NOT NULL,
            `incoterm`            VARCHAR(8)      NOT NULL DEFAULT 'DDU',
            `hs_code`             VARCHAR(20)     DEFAULT NULL,
            `cif_value`           DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `duty_rate`           DECIMAL(6,3)    NOT NULL DEFAULT 0,
            `duty_amount`         DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `vat_rate`            DECIMAL(6,3)    NOT NULL DEFAULT 0,
            `vat_amount`          DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `other_taxes`         DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `customs_fee`         DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `total_duties_taxes`  DECIMAL(15,2)   NOT NULL DEFAULT 0,
            `paid_by`             VARCHAR(10)     NOT NULL DEFAULT 'buyer',
            `below_de_minimis`    TINYINT(1)      NOT NULL DEFAULT 0,
            `currency`            CHAR(3)         NOT NULL,
            `breakdown`           LONGTEXT        DEFAULT NULL,
            `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_vendor_id` (`vendor_id`),
            KEY `idx_origin_dest` (`origin_country`, `destination_country`)
        ) {$charset}";
        dbDelta( $sql );
        $ensured = true;
    }

    /**
     * Cross-Border motor (Task 63-D): processes a single vendor's slice of an
     * order for customs declaration + settlement-currency wallet credit.
     *
     * Called from process() for each vendor after the main split has been
     * committed. The method is best-effort: any failure is logged but does
     * NOT roll back the main commission credit (already committed). This
     * guarantees that a misconfiguration of the cross-border motor never
     * leaves the vendor without their commission.
     *
     * @param \WC_Order $order      Pedido.
     * @param int       $vendor_id  ID del vendedor.
     * @param array     $split_data {
     *     @type float $gross_amount      Gross amount for this vendor.
     *     @type float $platform_fee      Platform commission.
     *     @type float $vendor_net        Net to vendor (after tax withholding).
     *     @type float $withholding_total Total tax withholding.
     *     @type array $tax_breakdown     Tax breakdown from the strategy.
     *     @type bool  $held               Whether the commission was held (consumer protection).
     * }
     * @return void
     */
    private static function process_cross_border_for_vendor( \WC_Order $order, int $vendor_id, array $split_data ): void {
        global $wpdb;
        $order_id = (int) $order->get_id();

        // OS-BUG-1 FIX (Task 65-C): Idempotency — if a customs declaration
        // already exists for this order, skip re-processing. The wallet
        // operations (credit/debit/FX-convert) are already idempotent via
        // idempotency_keys, but the customs declaration INSERT was NOT —
        // a WooCommerce webhook double-fire or order re-save would create
        // duplicate rows in lt_customs_declarations, inflating dashboard
        // stats (cross_border_orders, duties_collected) and leaving orphan
        // declaration_ids. The first declaration's ID is persisted on the
        // order meta as _ltms_customs_declaration_id; we check both the
        // meta AND the table to be robust against meta-cleanup edge cases.
        $existing_declaration_id = (int) $order->get_meta( '_ltms_customs_declaration_id' );
        if ( $existing_declaration_id < 1 ) {
            // Meta missing — defensive fallback: query the table directly.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $existing_declaration_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}lt_customs_declarations WHERE order_id = %d LIMIT 1",
                    $order_id
                )
            );
        }

        if ( $existing_declaration_id > 0 ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'CROSS_BORDER_SKIP',
                    sprintf(
                        'Order #%d already has customs declaration #%d — skipping re-processing (OS-BUG-1 idempotency).',
                        $order_id,
                        $existing_declaration_id
                    ),
                    [ 'order_id' => $order_id, 'declaration_id' => $existing_declaration_id, 'vendor_id' => $vendor_id ]
                );
            }
            return; // Already processed — idempotent skip.
        }

        // Resolve vendor origin country: explicit meta → platform country fallback.
        $origin_country = (string) ( get_user_meta( $vendor_id, 'ltms_vendor_country', true ) ?: '' );
        if ( ! $origin_country ) {
            $origin_country = LTMS_Core_Config::get_country();
        }

        $destination_country = (string) $order->get_shipping_country();
        if ( ! $destination_country ) {
            $destination_country = $order->get_billing_country();
        }

        // Domestic order → no cross-border processing needed. Bail early.
        if ( strtoupper( $origin_country ) === strtoupper( $destination_country ) ) {
            return;
        }

        $gross_amount = (float) ( $split_data['gross_amount'] ?? 0 );
        $vendor_net   = (float) ( $split_data['vendor_net']   ?? 0 );
        if ( $gross_amount <= 0 ) {
            return;
        }

        $incoterm  = (string) LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' );
        $order_currency = $order->get_currency() ?: LTMS_Core_Config::get_currency();

        // ── 1. Calculate customs duties via the Customs Calculator. ──────────
        // RB-10 FIX (v2.9.19): Disparar filter ltms_shipping_quote_args para que
        // el listener LT-8 (calculate_dva) pueda enriquecer los argumentos con
        // el DVA (Declaración de Valor Aduanero = CIF) antes de pasarlos al
        // customs calculator. Antes de este fix, LT-8 era silent dead code desde
        // v2.9.17. Se dispara justo antes de LTMS_Customs_Calculator::calculate().
        $customs_args = apply_filters( 'ltms_shipping_quote_args', [
            'item_value'          => $gross_amount,
            'origin_country'      => $origin_country,
            'destination_country' => $destination_country,
            'shipping_cost'       => (float) $order->get_shipping_total(),
            'incoterm'            => $incoterm,
            'currency'            => $order_currency,
        ], [
            'origin_country'      => $origin_country,
            'country_dest'        => $destination_country,
        ] );

        $customs = LTMS_Customs_Calculator::calculate( $customs_args );

        // ── 2. Persist the declaration to the customs table. ─────────────────
        $declaration_id = self::create_customs_declaration( $order->get_id(), $customs, [
            'vendor_id'           => $vendor_id,
            'origin_country'      => $origin_country,
            'destination_country' => $destination_country,
            'currency'            => $order_currency,
        ] );

        // Store declaration reference on the order for traceability.
        $order->update_meta_data( '_ltms_customs_declaration', $customs );
        $order->update_meta_data( '_ltms_customs_declaration_id', $declaration_id );
        $order->update_meta_data( '_ltms_cross_border_origin', $origin_country );
        $order->update_meta_data( '_ltms_cross_border_destination', $destination_country );
        $order->update_meta_data( '_ltms_cross_border_incoterm', $customs['incoterm'] ?? $incoterm );
        $order->save();

        // ── 3. Settlement currency conversion (display → vendor currency). ────
        // Only run if the Currency Manager is loaded. The vendor's wallet was
        // already credited in the display currency during the main split — if
        // the settlement currency differs, we perform a wallet-to-wallet FX
        // conversion so the vendor receives their payout in their preferred
        // currency. The conversion is logged as two wallet transactions
        // (debit display wallet / credit settlement wallet).
        $settlement_currency = $order_currency;
        if ( class_exists( 'LTMS_Currency_Manager' ) ) {
            $settlement_currency = LTMS_Currency_Manager::get_vendor_currency( $vendor_id );
        }

        if ( $settlement_currency !== $order_currency && $vendor_net > 0 && class_exists( 'LTMS_FX_Rate_Provider' ) ) {
            // Best-effort conversion. A failure here is logged but does NOT
            // block the cross-border flow — the vendor keeps their commission
            // in the display currency and can convert manually later.
            try {
                $fx = LTMS_Business_Wallet::convert_balance(
                    $vendor_id,
                    $order_currency,
                    $settlement_currency,
                    $vendor_net,
                    sprintf( 'settlement_o%d_v%d', $order->get_id(), $vendor_id )
                );

                if ( ! empty( $fx['success'] ) ) {
                    $order->update_meta_data( '_ltms_settlement_fx', [
                        'from'           => $order_currency,
                        'to'             => $settlement_currency,
                        'source_amount'  => $fx['source_amount'],
                        'target_amount'  => $fx['target_amount'],
                        'rate'           => $fx['rate'],
                    ] );
                    $order->save();
                }
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning(
                    'ORDER_SPLIT_FX_CONVERSION_FAILED',
                    sprintf( 'FX conversion failed for order #%d vendor #%d (%s→%s): %s', $order->get_id(), $vendor_id, $order_currency, $settlement_currency, $e->getMessage() ),
                    [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id ]
                );
            }
        }

        // ── 4. DDP: vendor pays the duties (debit from vendor wallet). ────────
        // The platform collected the duties from the buyer at checkout (DDP
        // = Delivered Duty Paid). The vendor is responsible for paying them
        // to customs at import. We debit the duty amount from the vendor's
        // wallet in the order currency so the audit trail is consistent.
        //
        // OS-BUG-3 FIX (Task 65-C): If the vendor's wallet has insufficient
        // balance for the full duty amount, we debit what's available and
        // log a warning. Previously, Wallet::debit threw an exception
        // ("Saldo insuficiente") and the platform lost the collected duties
        // (the buyer paid them, but the vendor's wallet never covered them
        // back to the platform). The platform now absorbs the difference
        // and the vendor's next sale can cover the outstanding balance —
        // a future enhancement could implement a true hold+release pattern.
        if ( ( $customs['incoterm'] ?? '' ) === LTMS_Customs_Calculator::INCOTERM_DDP
             && (float) ( $customs['total_duties_taxes'] ?? 0 ) > 0 ) {
            $ddp_duties = (float) $customs['total_duties_taxes'];
            $ddp_metadata = [
                'type'             => 'customs_ddp',
                'order_id'         => $order->get_id(),
                'declaration_id'   => $declaration_id,
                'origin_country'   => $origin_country,
                'dest_country'     => $destination_country,
                'duty_amount'      => $customs['duty_amount'] ?? 0,
                'vat_amount'       => $customs['vat_amount'] ?? 0,
                'customs_fee'      => $customs['customs_fee'] ?? 0,
            ];
            $ddp_description = sprintf(
                'Customs duties (DDP) — Order #%d %s→%s',
                $order->get_id(),
                $origin_country,
                $destination_country
            );
            $ddp_idempotency = sprintf( 'ddp_o%d_v%d', $order->get_id(), $vendor_id );

            // Pre-check the wallet balance to decide partial vs full debit.
            $wallet_balance = 0.0;
            if ( class_exists( 'LTMS_Business_Wallet' ) ) {
                try {
                    $wallet_info   = LTMS_Business_Wallet::get_balance_for_currency( $vendor_id, $order_currency );
                    $wallet_balance = (float) ( $wallet_info['balance'] ?? 0 );
                } catch ( \Throwable $e ) {
                    // Defensive: if the wallet lookup fails, fall back to a
                    // best-effort full debit attempt below.
                    $wallet_balance = 0.0;
                }
            }

            if ( $wallet_balance >= $ddp_duties ) {
                // Sufficient balance — full debit.
                try {
                    LTMS_Business_Wallet::debit(
                        $vendor_id,
                        $ddp_duties,
                        $ddp_description,
                        $ddp_metadata,
                        $order->get_id(),
                        $order_currency,
                        $ddp_idempotency
                    );
                } catch ( \Throwable $e ) {
                    // RE-AUDIT P0 FIX: race condition — balance changed between
                    // pre-check and debit. PHP catch blocks do NOT fall through to
                    // the else branch. Previously, the platform silently absorbed
                    // the full duties with no vendor debit and no debt record.
                    // Now: attempt partial debit (debit whatever balance is left).
                    LTMS_Core_Logger::warning(
                        'DDP_DEBIT_RACE_FALLBACK',
                        sprintf(
                            'Vendor #%d full DDP debit failed (race): %s. Attempting partial debit.',
                            $vendor_id,
                            $e->getMessage()
                        ),
                        [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id, 'ddp_duties' => $ddp_duties ]
                    );
                    // Re-read balance and attempt partial debit.
                    try {
                        $wallet_info   = LTMS_Business_Wallet::get_balance_for_currency( $vendor_id, $order_currency );
                        $partial_amount = max( 0.0, (float) ( $wallet_info['balance'] ?? 0 ) );
                        if ( $partial_amount > 0 ) {
                            LTMS_Business_Wallet::debit(
                                $vendor_id,
                                min( $partial_amount, $ddp_duties ),
                                $ddp_description . ' (partial — race fallback)',
                                $ddp_metadata,
                                $order->get_id(),
                                $order_currency,
                                $ddp_idempotency . '_partial'
                            );
                            $platform_covers = $ddp_duties - min( $partial_amount, $ddp_duties );
                            LTMS_Core_Logger::warning(
                                'DDP_PARTIAL_DEBIT',
                                sprintf( 'Vendor #%d partial DDP debit $%.2f, platform covers $%.2f.', $vendor_id, min( $partial_amount, $ddp_duties ), $platform_covers ),
                                [ 'vendor_debited' => min( $partial_amount, $ddp_duties ), 'platform_covers' => $platform_covers ]
                            );
                        } else {
                            LTMS_Core_Logger::critical(
                                'DDP_DEBIT_TOTAL_FAILURE',
                                sprintf( 'Vendor #%d: zero balance for DDP duties $%.2f. Platform covers full amount.', $vendor_id, $ddp_duties ),
                                [ 'vendor_id' => $vendor_id, 'ddp_duties' => $ddp_duties, 'platform_covers' => $ddp_duties ]
                            );
                        }
                    } catch ( \Throwable $partial_e ) {
                        LTMS_Core_Logger::critical(
                            'DDP_DEBIT_TOTAL_FAILURE',
                            sprintf( 'Vendor #%d: both full and partial DDP debit failed. Platform covers $%.2f. Error: %s', $vendor_id, $ddp_duties, $partial_e->getMessage() ),
                            [ 'vendor_id' => $vendor_id, 'ddp_duties' => $ddp_duties, 'platform_covers' => $ddp_duties, 'error' => $partial_e->getMessage() ]
                        );
                    }
                }
            } else {
                // Insufficient balance — debit what's available (if any) and
                // log a warning. The platform covers the difference.
                $debit_amount = max( 0.0, $wallet_balance );

                if ( $debit_amount > 0 ) {
                    try {
                        LTMS_Business_Wallet::debit(
                            $vendor_id,
                            $debit_amount,
                            $ddp_description . ' (partial — insufficient balance)',
                            array_merge( $ddp_metadata, [
                                'partial_debit'      => true,
                                'full_duties'        => $ddp_duties,
                                'platform_covers'    => round( $ddp_duties - $debit_amount, 2 ),
                            ] ),
                            $order->get_id(),
                            $order_currency,
                            $ddp_idempotency
                        );
                    } catch ( \Throwable $e ) {
                        LTMS_Core_Logger::warning(
                            'DDP_DEBIT_INSUFFICIENT',
                            sprintf(
                                'Vendor #%d partial DDP debit ($%.2f of $%.2f) also failed: %s',
                                $vendor_id,
                                $debit_amount,
                                $ddp_duties,
                                $e->getMessage()
                            ),
                            [
                                'order_id'        => $order->get_id(),
                                'vendor_id'       => $vendor_id,
                                'attempted_debit' => $debit_amount,
                                'full_duties'     => $ddp_duties,
                                'currency'        => $order_currency,
                                'platform_covers' => $ddp_duties,
                            ]
                        );
                    }
                }

                // Log the warning — platform covers the outstanding difference.
                LTMS_Core_Logger::warning(
                    'DDP_DEBIT_INSUFFICIENT',
                    sprintf(
                        'Vendor #%d insufficient balance for DDP duties $%.2f (wallet: $%.2f) — platform covers $%.2f difference',
                        $vendor_id,
                        $ddp_duties,
                        $wallet_balance,
                        $ddp_duties,
                        round( $ddp_duties - $debit_amount, 2 )
                    ),
                    [
                        'order_id'         => $order->get_id(),
                        'vendor_id'        => $vendor_id,
                        'ddp_duties'       => $ddp_duties,
                        'wallet_balance'   => $wallet_balance,
                        'debited'          => $debit_amount,
                        'currency'         => $order_currency,
                        'platform_covers'  => round( $ddp_duties - $debit_amount, 2 ),
                    ]
                );
            }
        }

        // ── 5. Fire the cross-border action so listeners can react. ──────────
        /**
         * Fires when an order is identified as cross-border (vendor's origin
         * country ≠ shipping destination country) after the main split.
         *
         * @param int   $order_id       WooCommerce order ID.
         * @param int   $vendor_id      Vendor ID.
         * @param array $customs        Customs calculation result (duties, VAT, etc.).
         * @param array $context        {
         *     @type string $origin_country      Vendor origin country.
         *     @type string $destination_country Shipping destination country.
         *     @type string $incoterm            DDP or DDU.
         *     @type string $display_currency    Order display currency.
         *     @type string $settlement_currency Vendor settlement currency.
         *     @type float  $vendor_net          Net commission (in display currency).
         *     @type int    $declaration_id      Customs declaration ID.
         * }
         */
        do_action( 'ltms_cross_border_order', $order->get_id(), $vendor_id, $customs, [
            'origin_country'      => $origin_country,
            'destination_country' => $destination_country,
            'incoterm'            => $customs['incoterm'] ?? $incoterm,
            'display_currency'    => $order_currency,
            'settlement_currency' => $settlement_currency,
            'vendor_net'          => $vendor_net,
            'declaration_id'      => $declaration_id,
        ] );

        LTMS_Core_Logger::info(
            'ORDER_SPLIT_CROSS_BORDER',
            sprintf(
                'Cross-border order #%d vendor #%d: %s→%s (%s) — duties %s %s, declaration #%d',
                $order->get_id(),
                $vendor_id,
                $origin_country,
                $destination_country,
                $customs['incoterm'] ?? $incoterm,
                $customs['total_duties_taxes'] ?? 0,
                $order_currency,
                $declaration_id
            ),
            [
                'order_id'      => $order->get_id(),
                'vendor_id'     => $vendor_id,
                'origin'        => $origin_country,
                'destination'   => $destination_country,
                'incoterm'      => $customs['incoterm'] ?? $incoterm,
                'declaration_id'=> $declaration_id,
            ]
        );
    }

    /**
     * Cross-Border motor (Task 63-D): persists a customs declaration row.
     *
     * @param int   $order_id WooCommerce order ID.
     * @param array $customs  Customs calculation result from LTMS_Customs_Calculator::calculate().
     * @param array $context  {
     *     Optional context. Defaults are resolved from the order + vendor meta.
     *     @type int    $vendor_id           Vendor ID (default: order's _ltms_vendor_id meta).
     *     @type string $origin_country      Vendor origin country (default: vendor meta or platform country).
     *     @type string $destination_country Shipping destination (default: order's shipping country).
     *     @type string $currency            Currency (default: order's currency).
     * }
     * @return int Declaration row ID (0 on failure).
     */
    private static function create_customs_declaration(
        int $order_id,
        array $customs,
        array $context = []
    ): int {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return 0;
        }

        $vendor_id           = (int) ( $context['vendor_id'] ?? $order->get_meta( '_ltms_vendor_id' ) );
        if ( $vendor_id <= 0 ) {
            // Fallback: extract from items.
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                $vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
                if ( $vendor_id > 0 ) {
                    break;
                }
                $vendor_id = (int) get_post_field( 'post_author', $pid );
                if ( $vendor_id > 0 ) {
                    break;
                }
            }
        }

        $origin_country = (string) ( $context['origin_country'] ?? '' );
        if ( ! $origin_country && $vendor_id > 0 ) {
            $origin_country = (string) ( get_user_meta( $vendor_id, 'ltms_vendor_country', true ) ?: '' );
        }
        if ( ! $origin_country ) {
            $origin_country = LTMS_Core_Config::get_country();
        }

        $destination_country = (string) ( $context['destination_country'] ?? '' );
        if ( ! $destination_country ) {
            $destination_country = (string) $order->get_shipping_country() ?: (string) $order->get_billing_country();
        }
        if ( ! $destination_country ) {
            $destination_country = $origin_country;
        }

        $currency = (string) ( $context['currency'] ?? '' );
        if ( ! $currency ) {
            $currency = $order->get_currency() ?: LTMS_Core_Config::get_currency();
        }

        self::ensure_customs_table();

        global $wpdb;
        $table = $wpdb->prefix . 'lt_customs_declarations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $table,
            [
                'order_id'            => $order_id,
                'vendor_id'           => $vendor_id,
                'origin_country'      => strtoupper( $origin_country ),
                'destination_country' => strtoupper( $destination_country ),
                'incoterm'            => $customs['incoterm'] ?? 'DDU',
                'cif_value'           => (float) ( $customs['cif_value'] ?? 0 ),
                'duty_rate'           => (float) ( $customs['duty_rate'] ?? 0 ),
                'duty_amount'         => (float) ( $customs['duty_amount'] ?? 0 ),
                'vat_rate'            => (float) ( $customs['vat_rate'] ?? 0 ),
                'vat_amount'          => (float) ( $customs['vat_amount'] ?? 0 ),
                'other_taxes'         => (float) ( $customs['other_taxes'] ?? 0 ),
                'customs_fee'         => (float) ( $customs['customs_fee'] ?? 0 ),
                'total_duties_taxes'  => (float) ( $customs['total_duties_taxes'] ?? 0 ),
                'paid_by'             => $customs['paid_by'] ?? 'buyer',
                'below_de_minimis'    => ! empty( $customs['below_de_minimis'] ) ? 1 : 0,
                'currency'            => strtoupper( $currency ),
                'breakdown'           => wp_json_encode( $customs['breakdown'] ?? $customs ),
                'created_at'          => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( ! $result ) {
            LTMS_Core_Logger::warning(
                'CUSTOMS_DECLARATION_INSERT_FAILED',
                sprintf( 'Could not insert customs declaration for order #%d vendor #%d: %s', $order_id, $vendor_id, $wpdb->last_error )
            );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }
}

