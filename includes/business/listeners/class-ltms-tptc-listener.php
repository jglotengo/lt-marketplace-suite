<?php
/**
 * LTMS TPTC Listener — Sincronización con Te Paga Tus Compras.
 *
 * LG-2 FIX (v2.9.7): tasas TPTC alineadas con contrato v4.1:
 *   Nivel 1 = 0.75%, Nivel 2 = 1.5%, Nivel 3 = 0.5%
 *   Activación: compra mínima $511 COP/mes en la Plataforma.
 *   Operador: TPTC S.A.S. (entidad independiente).
 */

class LTMS_TPTC_Listener {

    use LTMS_Logger_Aware;

    // LG-2: Tasas TPTC del contrato (Cláusula Décima Sexta).
    const TPTC_RATE_LEVEL_1 = 0.0075; // 0.75%
    const TPTC_RATE_LEVEL_2 = 0.015;  // 1.5%
    const TPTC_RATE_LEVEL_3 = 0.005;  // 0.5%
    const TPTC_MIN_PURCHASE = 511;    // $511 COP mensual

    /**
     * Registra el hook sobre el pago completado.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
        // TPTC-BUG-3 FIX (regresión de LS-BUG-6 / Task 53-C): registrar el hook
        // de reembolso para revertir la venta en TPTC y mantener consistencia
        // contable/compliance. Antes no existía y los puntos quedaban registrados
        // tras un reembolso.
        add_action( 'woocommerce_order_refunded', [ __CLASS__, 'on_order_refunded' ], 10, 2 );
    }

    /**
     * Maneja el evento de pago completado.
     *
     * @param int $order_id
     * @return void
     */
    public static function on_order_paid( int $order_id ): void {
        $tptc_enabled = LTMS_Core_Config::get( 'ltms_tptc_enabled', 'no' );
        if ( 'yes' !== $tptc_enabled ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Solo pedidos con vendor LTMS
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            return;
        }

        // H-5 FIX: atomic SQL claim to prevent double-sync race condition.
        // The previous $order->get_meta() + update_meta_data() pattern was
        // non-atomic: two concurrent processes (payment_complete +
        // status_completed, or a cron + webhook retry) could both read '0',
        // both pass the guard, and both call sync_sale() → double TPTC
        // points/commissions credited. The claim is placed AFTER the
        // vendor_id check so non-LTMS orders are not marked as TPTC-synced
        // (preserves the ability to re-sync if a vendor is assigned later).
        //
        // NOTE: this uses $wpdb->postmeta directly (per H-5 spec) rather than
        // $order->update_meta_data(). Under HPOS in "sync" mode the postmeta
        // row stays mirrored for legacy reads, and the atomic UPDATE is the
        // authoritative guard. On failure we reset the meta (see catch below)
        // so the manual/cron retry path still works.
        global $wpdb;
        add_post_meta( $order_id, '_ltms_tptc_synced', '0', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = %s AND (meta_value IS NULL OR meta_value != '1')",
            $order_id, '_ltms_tptc_synced'
        ) );
        if ( ! $claimed ) {
            return; // Already claimed by another process
        }

        try {
            if ( ! class_exists( 'LTMS_Api_TPTC' ) ) {
                return;
            }

            $client = LTMS_Api_Factory::get( 'tptc' );
            // TPTC-BUG-1 FIX (regresión de LS-BUG-1 / Task 53-C): el API client
            // define sync_sale(), NO register_sale(). Llamar a register_sale()
            // lanzaba "Call to undefined method" capturado por el catch → TPTC
            // nunca se sincronizaba. Adicionalmente la key 'date' se renombró a
            // 'sale_date' para matchear el API client.
            $result = $client->sync_sale( [
                'order_id'       => $order_id,
                'vendor_id'      => $vendor_id,
                'customer_email' => $order->get_billing_email(),
                'amount'         => (float) $order->get_total(),
                'currency'       => get_woocommerce_currency(),
                'sale_date'      => $order->get_date_paid() ? $order->get_date_paid()->format( 'c' ) : gmdate( 'c' ),
                'items'          => array_map( fn( $item ) => [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total'    => (float) $item->get_total(),
                ], $order->get_items() ),
            ] );

            // H-5 FIX: _ltms_tptc_synced was already atomically set to '1' by
            // the claim above — no need to set it again here. We still persist
            // the TPTC transaction_id via the HPOS-compatible API so it lands
            // in the order's meta regardless of storage backend.
            $order->update_meta_data( '_ltms_tptc_transaction_id', $result['transaction_id'] ?? '' );
            $order->save();

            self::log_info_static( 'TPTC_SYNCED', "Pedido #{$order_id} sincronizado con TPTC." );

        } catch ( \Throwable $e ) {
            // TPTC-BUG-1 FIX (cont.): marcar el fallo para diagnóstico/retry en
            // lugar de silenciarlo. El pedido no se marca como synced, así que un
            // reintento posterior (manual o vía cron) puede volver a intentarlo.
            //
            // H-5 FIX: because the atomic claim above already flipped
            // _ltms_tptc_synced to '1' BEFORE calling sync_sale(), we must
            // RESET it back to '0' here on failure — otherwise the retry path
            // (which reads the same meta) would see '1' and bail forever.
            // The reset uses direct $wpdb->postmeta SQL to stay consistent
            // with the claim mechanism above (so the same storage layer that
            // set the flag also clears it).
            // CICLO12-P1-TL-028 FIX: el UPDATE de reset no se verificaba -
            // si fallaba silenciosamente (false = error DB con last_error,
            // 0 = no rows por schema drift), el flag quedaba en '1' y el
            // retry nunca ocurria -> el pedido quedaba sin sincronizar con
            // TPTC para siempre (puntos/comisiones TPTC nunca se
            // acreditan al vendor, compliance contable TPTC pendiente
            // permanentemente, remaindere de la traza regulatoria). Patron
            // recurrente documentado en Ciclos 5-11 (ver P1-RL-025 del
            // Ciclo 11 para el mismo bug en ReDi listener). Mismo fix
            // estandar: capturar + check explicito false/0 + log critico
            // con SQL de reconciliacion manual.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reset_result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE post_id = %d AND meta_key = %s",
                $order_id, '_ltms_tptc_synced'
            ) );
            if ( false === $reset_result || 0 === (int) $reset_result ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'TPTC_SYNC_FLAG_RESET_FAILED',
                        sprintf(
                            'TPTC sync fallo Y el reset del flag _ltms_tptc_synced tambien fallo. order_id=%d exception=%s reset_result=%s last_error=%s. El pedido queda sin sincronizar con TPTC para siempre - puntos/comisiones TPTC no se acreditan, compliance contable pendiente. Reconciliacion manual: UPDATE %spostmeta SET meta_value=\'0\' WHERE post_id=%d AND meta_key=\'_ltms_tptc_synced\'.',
                            $order_id,
                            get_class( $e ),
                            var_export( $reset_result, true ),
                            $wpdb->last_error ?: '(no error)',
                            $wpdb->prefix,
                            $order_id
                        ),
                        [
                            'order_id'      => $order_id,
                            'exception'     => get_class( $e ),
                            'exception_msg' => $e->getMessage(),
                            'reset_result'  => var_export( $reset_result, true ),
                            'last_error'    => $wpdb->last_error ?: '(no error)',
                        ]
                    );
                }
            }
            $order->update_meta_data( '_ltms_tptc_failed', 1 );
            $order->update_meta_data( '_ltms_tptc_last_error', $e->getMessage() );
            $order->save();
            self::log_error_static( 'TPTC_SYNC_FAILED', "Pedido #{$order_id}: " . $e->getMessage() );
        }
    }

    /**
     * Revierte la venta en TPTC cuando el pedido es reembolsado.
     *
     * TPTC-BUG-3 FIX (regresión de LS-BUG-6 / Task 53-C): el método fue
     * documentado como añadido en Task 53-C pero no existía en el código actual.
     * Sin este handler, los puntos/comisiones de TPTC quedaban acreditados tras
     * un reembolso → inconsistencia contable/compliance permanente.
     *
     * @param int $order_id  ID del pedido reembolsado.
     * @param int $refund_id ID del objeto refund (no usado directamente).
     * @return void
     */
    public static function on_order_refunded( int $order_id, int $refund_id ): void {
        $tptc_enabled = LTMS_Core_Config::get( 'ltms_tptc_enabled', 'no' );
        if ( 'yes' !== $tptc_enabled ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // AUDIT-LISTENERS-001 P1-3 FIX (Ciclo 1.5): atomic SQL claim para
        // _ltms_tptc_reversed. Antes, get_meta() + update_meta_data() era
        // no atómico — dos procesos concurrentes (wp-cli refund + admin
        // UI refund, o cron retry de reembolsos pendientes) podían ambos
        // leer 'false', ambos pasar el guard, ambos llamar reverse_sale.
        // El API client de TPTC (según se conoce) no dedup por idempotency
        // key en reverse_sale → doble reversión contable (puntos quitados
        // dos veces al vendor en TPTC). Mismo patrón que H-4/H-5 FIX.
        global $wpdb;
        add_post_meta( $order_id, '_ltms_tptc_reversed', '0', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $reversal_claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d AND meta_key = %s AND (meta_value IS NULL OR meta_value != '1')",
            $order_id, '_ltms_tptc_reversed'
        ) );
        if ( ! $reversal_claimed ) {
            return; // Already reversed by another process
        }

        // Solo revertir si la venta fue previamente sincronizada con TPTC.
        if ( ! $order->get_meta( '_ltms_tptc_synced' ) ) {
            // AUDIT-LISTENERS-001 P1-3 FIX (cont.): si no estaba synced,
            // resetear el claim para permitir undo si el pedido se_sync
            // más tarde (escenario raro pero defense-in-depth).
            // CICLO12-P1-TL-030 FIX: el reset del claim en este path
            // defense-in-depth tampoco se verificaba - si fallaba
            // silenciosamente (false = error DB, 0 = no rows), el claim
            // _ltms_tptc_reversed quedaba en '1' aunque la reversión NO
            // ocurrio. Si el pedido se sincronizaba con TPTC mas tarde y
            // entonces se reembolsaba, una nueva ejecucion de
            // on_order_refunded salia por el early-return "Already
            // reversed by another process" sin aplicar el reverse_sale
            // -> la venta TPTC quedaba activa tras reembolso hechos
            // posteriores, inconsistencia contable. Mismo patron
            // recurrente Ciclos 5-11. Log critico (no warning) +
            // SQL de reconciliacion manual.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reset_not_synced = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE post_id = %d AND meta_key = %s",
                $order_id, '_ltms_tptc_reversed'
            ) );
            if ( false === $reset_not_synced || 0 === (int) $reset_not_synced ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED',
                        sprintf(
                            'Pedido reembolsado NO estaba sincronizado con TPTC, Y el reset del claim _ltms_tptc_reversed tambien fallo. order_id=%d reset_result=%s last_error=%s. Si el pedido se sincroniza con TPTC mas tarde y entonces se reembolsa de nuevo, una nueva ejecucion de on_order_refunded saldra por early-return sin aplicar reverse_sale -> la venta TPTC quedara activa tras reembolso. Reconciliacion manual: UPDATE %spostmeta SET meta_value=\'0\' WHERE post_id=%d AND meta_key=\'_ltms_tptc_reversed\'.',
                            $order_id,
                            var_export( $reset_not_synced, true ),
                            $wpdb->last_error ?: '(no error)',
                            $wpdb->prefix,
                            $order_id
                        ),
                        [
                            'order_id'         => $order_id,
                            'reset_not_synced' => var_export( $reset_not_synced, true ),
                            'last_error'       => $wpdb->last_error ?: '(no error)',
                        ]
                    );
                }
            }
            return;
        }

        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            return;
        }

        try {
            if ( ! class_exists( 'LTMS_Api_TPTC' ) ) {
                return;
            }

            $client = LTMS_Api_Factory::get( 'tptc' );
            $client->reverse_sale( [
                'vendor_id'     => $vendor_id,
                'order_id'      => $order_id,
                'amount'        => (float) $order->get_total(),
                'currency'      => get_woocommerce_currency(),
                'reason'        => 'order_refunded',
                'reversal_date' => gmdate( 'c' ),
            ] );

            // AUDIT-LISTENERS-001 P1-3 FIX (cont.): limpiar flag de fallo
            // previo (si hubo retries fallidos anteriores) para que el
            // pedido no quede marcado como fallido tras un éxito.
            $order->delete_meta_data( '_ltms_tptc_reversal_failed' );
            $order->delete_meta_data( '_ltms_tptc_reversal_last_error' );
            $order->save();

            self::log_info_static( 'TPTC_REVERSED', "Pedido #{$order_id} revertido en TPTC (refund #{$refund_id})." );

        } catch ( \Throwable $e ) {
            // AUDIT-LISTENERS-001 P1-3 FIX (Ciclo 1.5): ante fallo,
            // resetear el atomic claim (permitir retry) + marcar
            // _tptc_reversal_failed meta para diagnóstico/monitoreo.
            // Antes, el catch solo logueaba — no había forma de distinguir
            // en admin un pedido con reversión pendiente de uno revertido,
            // ni había path de retry (claim quedaba en '1' permanentemente).
            // CICLO12-P1-TL-029 FIX: el UPDATE de reset del claim
            // _ltms_tptc_reversed no se verificaba - mismo patron que
            // P1-TL-028 del catch de on_order_paid. Si el reset fallaba
            // silenciosamente (false = error DB, 0 = no rows), el claim
            // quedaba en '1' y el retry nunca ocurria -> la reversión
            // TPTC quedaba pendiente para siempre (puntos/comisiones NO
            // reversados al vendor en TPTC, inconsistencia contable
            // permanente - el reembolso se proceso en WooCommerce pero
            // TPTC sigue mostrando la venta como activa). Patron
            // recurrente documentado en Ciclos 5-11. Mismo fix
            // estandar: capturar + check explicito false/0 + log
            // critico con SQL de reconciliacion manual.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reset_reversal = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE post_id = %d AND meta_key = %s",
                $order_id, '_ltms_tptc_reversed'
            ) );
            if ( false === $reset_reversal || 0 === (int) $reset_reversal ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'TPTC_REVERSAL_FLAG_RESET_FAILED',
                        sprintf(
                            'TPTC reversal fallo Y el reset del claim _ltms_tptc_reversed tambien fallo. order_id=%d refund_id=%d exception=%s reset_reversal=%s last_error=%s. La reversión TPTC queda pendiente para siempre - el reembolso se proceso en WooCommerce pero TPTC sigue mostrando la venta como activa (puntos/comisiones NO reversados al vendor). Reconciliacion manual: UPDATE %spostmeta SET meta_value=\'0\' WHERE post_id=%d AND meta_key=\'_ltms_tptc_reversed\'.',
                            $order_id,
                            $refund_id,
                            get_class( $e ),
                            var_export( $reset_reversal, true ),
                            $wpdb->last_error ?: '(no error)',
                            $wpdb->prefix,
                            $order_id
                        ),
                        [
                            'order_id'        => $order_id,
                            'refund_id'       => $refund_id,
                            'exception'       => get_class( $e ),
                            'exception_msg'   => $e->getMessage(),
                            'reset_reversal'  => var_export( $reset_reversal, true ),
                            'last_error'      => $wpdb->last_error ?: '(no error)',
                        ]
                    );
                }
            }
            $order->update_meta_data( '_ltms_tptc_reversal_failed', 1 );
            $order->update_meta_data( '_ltms_tptc_reversal_last_error', $e->getMessage() );
            $order->update_meta_data( '_ltms_tptc_reversal_last_refund_id', $refund_id );
            $order->save();
            self::log_error_static( 'TPTC_REVERSAL_FAILED', "Pedido #{$order_id}: " . $e->getMessage() );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// COUPON ATTRIBUTION LISTENER — lives in its own file:
// includes/business/listeners/class-ltms-coupon-attribution-listener.php
// (Kept as a pointer comment for readers; the class is NOT defined here.)
// ─────────────────────────────────────────────────────────────────────────────
