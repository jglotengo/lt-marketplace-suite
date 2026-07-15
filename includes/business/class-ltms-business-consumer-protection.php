<?php
/**
 * LTMS Business Consumer Protection — Protección al Consumidor / Vesting
 *
 * Implementa las reglas de retención de fondos (vesting period) para
 * garantizar protección al consumidor en disputas. Los fondos del vendedor
 * permanecen en estado "hold" durante el período configurado antes de
 * liberarse a su billetera disponible.
 *
 * Regla: Ley 1480 de Colombia (Estatuto del Consumidor) — 5 días hábiles.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Consumer_Protection
 */
class LTMS_Business_Consumer_Protection {

    use LTMS_Logger_Aware;

    /**
     * Días de retención por defecto.
     */
    const DEFAULT_HOLD_DAYS = 5;

    /**
     * Registra los hooks del módulo.
     *
     * @return void
     */
    public static function init(): void {
        // Verificar y liberar fondos retenidos — se ejecuta en el cron diario
        add_action( 'ltms_daily_cron', [ __CLASS__, 'release_eligible_holds' ] );
        add_action( 'ltms_release_vendor_hold', [ __CLASS__, 'release_single_hold' ], 10, 2 );

        // M-202: extender hold cuando shipping provider confirma entrega
        // (Uber, Aveonline, Heka disparan ltms_shipping_delivered desde sus webhook handlers).
        add_action( 'ltms_shipping_delivered', [ __CLASS__, 'on_shipping_delivered' ], 10, 1 );
        add_action( 'ltms_shipping_failed',    [ __CLASS__, 'on_shipping_failed' ],    10, 2 );

        // CP-BUG-6: Notificaciones por email al vendor y al customer en cada evento
        // del lifecycle de disputa. Los actions son disparados por file_dispute(),
        // approve_dispute() y reject_dispute() (ver métodos al final del archivo).
        add_action( 'ltms_dispute_filed',    [ __CLASS__, 'on_dispute_filed' ],    10, 4 );
        add_action( 'ltms_dispute_approved', [ __CLASS__, 'on_dispute_approved' ], 10, 3 );
        add_action( 'ltms_dispute_rejected', [ __CLASS__, 'on_dispute_rejected' ], 10, 2 );
    }

    /**
     * AUDIT-BOOKING-ENGINE #9 FIX: obtiene la fecha de check-out de una
     * reserva asociada a un WC order. Retorna null si el order no tiene
     * reserva asociada (producto normal, no bookable).
     *
     * @param int $order_id
     * @return string|null YYYY-MM-DD o null.
     */
    public static function get_booking_checkout_date( int $order_id ): ?string {
        global $wpdb;
        $checkout = $wpdb->get_var( $wpdb->prepare(
            "SELECT checkout_date FROM {$wpdb->prefix}lt_bookings WHERE wc_order_id = %d LIMIT 1",
            $order_id
        ) );
        return $checkout ?: null;
    }

    /**
     * Retiene los fondos de una comisión durante el período de protección.
     *
     * @param int   $vendor_id  ID del vendedor.
     * @param float $amount     Monto a retener.
     * @param int   $order_id   ID del pedido asociado.
     * @return bool
     */
    public static function hold_commission( int $vendor_id, float $amount, int $order_id ): bool {
        $hold_days   = (int) LTMS_Core_Config::get( 'ltms_consumer_protection_days', self::DEFAULT_HOLD_DAYS );
        $release_at  = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hold_days} weekdays" ) );

        // AUDIT-BOOKING-ENGINE #9 FIX: para reservas de turismo/hospedaje,
        // el período de protección al consumidor debe contar desde la fecha
        // de check-out (cuando el cliente termina su estadía), NO desde la
        // fecha de pago. Un cliente puede reservar 3 meses antes del viaje —
        // si el hold se libera 5 días después del pago, el cliente pierde
        // protección antes de llegar al hotel.
        // Ley 1558/2012 CO (turismo) + PROFECO MX LFPCE Art. 92.
        $booking_checkout = self::get_booking_checkout_date( $order_id );
        if ( $booking_checkout ) {
            $checkout_ts = strtotime( $booking_checkout . ' 23:59:59' );
            $release_ts  = strtotime( "+{$hold_days} weekdays", $checkout_ts );
            $release_at  = gmdate( 'Y-m-d H:i:s', $release_ts );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // OS-1b FIX (AUDIT-OS) CRÍTICO: idempotency en el INSERT de lt_wallet_holds.
        //
        // ANTES: el INSERT no verificaba duplicados. Si hold_commission() se llamaba
        // dos veces (race condition, webhook double-fire, order re-save), se insertaba
        // UNA FILA NUEVA por llamada. Las operaciones Wallet::credit/hold sí eran
        // idempotentes (CP1, OS-1), pero la tabla lt_wallet_holds acumulaba filas
        // fantasma → release_eligible_holds() iteraba sobre todas y llamaba
        // release_single_hold() para cada una. La segunda liberación lanzaba
        // excepción "Saldo pendiente insuficiente" (balance_pending ya había sido
        // reducido por la primera) y abortaba el cron de release para TODOS los
        // vendors restantes.
        //
        // Solución: antes de insertar, verificar si ya existe un hold NO liberado
        // (status='held' o 'frozen') para (vendor_id, order_id). Si existe, skip
        // insert + skip wallet ops + return true (la comisión ya está retenida).
        // RE-AUDIT P0 FIX (TOCTOU): wrap SELECT+INSERT in a transaction with
        // SELECT FOR UPDATE to prevent two concurrent hold_commission() calls
        // from both passing the check and both INSERTing → two hold rows →
        // release_eligible_holds releases twice (different hold_id = different
        // idempotency key) → second release fails (balance_pending=0) → stuck hold.
        $wpdb->query( 'START TRANSACTION' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing_hold_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE vendor_id = %d AND order_id = %d AND status IN ( 'held', 'frozen' ) LIMIT 1 FOR UPDATE",
                $vendor_id,
                $order_id
            )
        );

        if ( $existing_hold_id > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'COMMISSION_HOLD_ALREADY_EXISTS',
                    sprintf(
                        'Hold o%d v%d skip — ya existe hold #%d (status=held|frozen).',
                        $order_id, $vendor_id, $existing_hold_id
                    )
                );
            }
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert( $table, [
            'vendor_id'  => $vendor_id,
            'amount'     => $amount,
            'order_id'   => $order_id,
            'reason'     => 'consumer_protection',
            'status'     => 'held',
            'release_at' => $release_at,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%d', '%f', '%d', '%s', '%s', '%s', '%s' ] );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        $wpdb->query( 'COMMIT' );

        // M-84: Acreditar y retener en wallet para que balance_pending refleje los fondos
        // retenidos y el vendedor NO los vea como disponibles hasta que venza el periodo.
        // CP-BUG-4 FIX: si Wallet::credit()/hold() falla (lanza excepción), retornamos
        // `false` en lugar de `true` para que el caller sepa que el hold NO se aplicó
        // correctamente y pueda tomar acción compensatoria (ej: reintentar, alertar).
        //
        // CP1 FIX (v2.8.8): idempotency keys para prevenir doble crédito/hold si
        // hold_commission() se llama dos veces para el mismo order_id (race condition
        // o re-procesamiento de Order_Split tras crash).
        //
        // OS-1 FIX (AUDIT-OS): scope por (order_id, vendor_id). Antes el key era
        // solo `cp_hold_credit_o{order_id}` — en órdenes multi-vendor, Order_Split
        // llama hold_commission() una vez por vendor con el MISMO order_id. El
        // primer vendor aplicaba credit+hold OK; el segundo vendor hacía hit de
        // idempotency (collision) → NO se acreditaba NI se retenía nada para los
        // vendors 2..N, pero la función retornaba true (sin excepción). Resultado:
        // vendors 2..N quedaban SIN crédito en wallet pero CON fila en
        // lt_wallet_holds → release_single_hold() posterior lanzaba excepción
        // (balance_pending=0 < amount) y abortaba el cron release_eligible_holds
        // para TODOS los vendors restantes.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            try {
                $credit_idem_key = sprintf( 'cp_hold_credit_o%d_v%d', $order_id, $vendor_id );
                $hold_idem_key   = sprintf( 'cp_hold_hold_o%d_v%d', $order_id, $vendor_id );

                // OS-1 BACKWARD-COMPAT (AUDIT-OS): antes de este fix, los keys eran
                // solo `cp_hold_credit_o{order_id}` (sin vendor_id). Para órdenes
                // single-vendor procesadas con la versión anterior y re-procesadas
                // tras el upgrade, el nuevo key NO colisiona con el viejo → el
                // idempotency check del Wallet no encuentra el tx previo → aplica
                // un crédito DUPLICADO.
                //
                // Solución: verificar manualmente ambos formatos de key. Si cualquiera
                // existe, skip ambas operaciones (credit + hold) — ya se aplicaron.
                $legacy_credit_key = sprintf( 'cp_hold_credit_o%d', $order_id );
                // RE-AUDIT P0 FIX: legacy key (no vendor_id) belongs to vendor_1.
                // For vendors 2..N, the legacy key matches vendor_1's tx → skip
                // → vendor_2 gets hold row but ZERO wallet credit. Now: scope the
                // legacy key check to ALSO match vendor_id, so vendor_2 doesn't
                // get a false idempotency hit from vendor_1's legacy tx.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $prior_credit_tx = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM `{$wpdb->prefix}lt_wallet_transactions`
                         WHERE `reference` IN ( %s, %s ) AND `vendor_id` = %d LIMIT 1",
                        $credit_idem_key,
                        $legacy_credit_key,
                        $vendor_id
                    )
                );

                if ( $prior_credit_tx > 0 ) {
                    // Idempotency hit — el crédito ya fue aplicado (formato nuevo o
                    // legacy). No aplicar de nuevo. No aplicar hold tampoco (si el
                    // crédito ya estaba, el hold también).
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::info(
                            'COMMISSION_HOLD_IDEMPOTENT_SKIP',
                            sprintf(
                                'Hold o%d v%d skip — crédito previo #%d ya aplicado (key=%s o legacy=%s).',
                                $order_id, $vendor_id, $prior_credit_tx, $credit_idem_key, $legacy_credit_key
                            )
                        );
                    }
                } else {
                    // RE-AUDIT P0 FIX (partial failure): credit and hold are separate
                    // wallet operations. If credit SUCCEEDS but hold FAILS, the vendor
                    // has the full amount in AVAILABLE balance (not held) — consumer
                    // protection is bypassed, vendor can withdraw immediately.
                    // Now: wrap both in a try, and if hold fails after credit succeeds,
                    // attempt to reverse the credit (debit it back) so the vendor doesn't
                    // keep unheld funds. Log critical if reversal also fails.
                    $credit_succeeded = false;
                    try {
                        LTMS_Business_Wallet::credit(
                            $vendor_id,
                            $amount,
                            sprintf( 'Comision pedido #%d - en retencion (proteccion al consumidor)', $order_id ),
                            [ 'type' => 'commission', 'order_id' => $order_id, 'held_until' => $release_at ],
                            $order_id,
                            '',
                            $credit_idem_key
                        );
                        $credit_succeeded = true;
                    } catch ( \Throwable $credit_e ) {
                        throw $credit_e; // Re-throw — outer catch handles it.
                    }
                    // Credit succeeded — now attempt hold.
                    try {
                        LTMS_Business_Wallet::hold(
                            $vendor_id,
                            $amount,
                            sprintf( 'Retencion Ley 1480 - pedido #%d, libera: %s', $order_id, $release_at ),
                            [ 'type' => 'consumer_protection', 'order_id' => $order_id, 'release_at' => $release_at ],
                            0,
                            '',
                            $hold_idem_key
                        );
                    } catch ( \Throwable $hold_e ) {
                        // Hold failed after credit succeeded — vendor has unheld funds.
                        // Attempt to reverse the credit (debit it back).
                        if ( class_exists( 'LTMS_Core_Logger' ) ) {
                            LTMS_Core_Logger::critical(
                                'COMMISSION_HOLD_FAILED_AFTER_CREDIT',
                                sprintf( 'Vendor #%d order #%d: credit succeeded but hold FAILED: %s. Attempting reversal.', $vendor_id, $order_id, $hold_e->getMessage() ),
                                [ 'vendor_id' => $vendor_id, 'order_id' => $order_id, 'amount' => $amount ]
                            );
                        }
                        try {
                            LTMS_Business_Wallet::debit(
                                $vendor_id,
                                $amount,
                                sprintf( 'Reversion: hold fallo para pedido #%d', $order_id ),
                                [ 'type' => 'hold_reversal', 'order_id' => $order_id ],
                                $order_id,
                                '',
                                $credit_idem_key . '_reversal'
                            );
                            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                                LTMS_Core_Logger::info(
                                    'COMMISSION_HOLD_REVERSAL_OK',
                                    sprintf( 'Vendor #%d order #%d: credit reversed after hold failure. Manual review required.', $vendor_id, $order_id )
                                );
                            }
                        } catch ( \Throwable $reversal_e ) {
                            // Reversal also failed — vendor has unheld funds, no hold.
                            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                                LTMS_Core_Logger::critical(
                                    'COMMISSION_HOLD_REVERSAL_FAILED',
                                    sprintf( 'Vendor #%d order #%d: hold failed AND reversal failed: %s. Vendor has unheld credit $%.2f — MANUAL INTERVENTION REQUIRED.', $vendor_id, $order_id, $reversal_e->getMessage(), $amount ),
                                    [ 'vendor_id' => $vendor_id, 'order_id' => $order_id, 'amount' => $amount ]
                                );
                            }
                        }
                        throw $hold_e; // Re-throw so outer catch returns false.
                    }
                }
            } catch ( \Throwable $e ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'COMMISSION_HOLD_WALLET_FAILED',
                        sprintf( 'Error al registrar hold en wallet vendedor #%d: %s', $vendor_id, $e->getMessage() ),
                        [ 'vendor_id' => $vendor_id, 'order_id' => $order_id, 'amount' => $amount, 'error' => $e->getMessage() ]
                    );
                }
                return false; // CP-BUG-4: NO reportar éxito si el crédito/hold falló.
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::log(
            'COMMISSION_HELD',
            sprintf( 'Fondos retenidos: %.2f para vendedor #%d, pedido #%d, liberacion: %s', $amount, $vendor_id, $order_id, $release_at )
        );

        return true;
    }

    /**
     * Libera todos los holds elegibles (fecha de liberación pasada).
     * Se ejecuta desde el cron diario.
     *
     * M-202: si `ltms_payout_require_delivery` = 'yes' (default), solo libera holds
     * de pedidos con entrega confirmada por shipping provider o productos digitales
     * (sin shipping). El resto queda en hold hasta confirmación o decisión manual.
     *
     * @return void
     */
    public static function release_eligible_holds(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $holds = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'held' AND release_at <= %s LIMIT 100",
            $now
        ) );

        $require_delivery = LTMS_Core_Config::get( 'ltms_payout_require_delivery', 'yes' ) === 'yes';

        // CP6 FIX (v2.8.8): contadores para log de auditoría del cron.
        $released_count  = 0;
        $released_amount = 0.0;
        $skipped_count   = 0;
        $skipped_orders  = [];

        $failed_count  = 0;
        $failed_orders = [];

        foreach ( $holds as $hold ) {
            if ( $require_delivery && ! self::is_order_delivered_or_no_shipping( (int) $hold->order_id ) ) {
                // No liberar: el pedido no se ha entregado y el flag de protección está activo.
                // El hold se libera cuando llegue el evento ltms_shipping_delivered o cuando admin lo apruebe manualmente.
                $skipped_count++;
                $skipped_orders[] = (int) $hold->order_id;
                continue;
            }
            // H-1 FIX: wrap the per-hold release in try/catch so a single
            // failing hold (e.g. Wallet::release() throws inside
            // release_single_hold) does NOT abort the whole cron batch —
            // which previously left the remaining 99 holds in 'held' state
            // and silently lost payouts until the next day's cron run.
            try {
                $release_ok = self::release_single_hold( (int) $hold->id, (int) $hold->vendor_id );
                // RE-AUDIT P1 FIX: release_single_hold returns bool. Previously
                // counters were incremented unconditionally even when the method
                // silently exited (hold not found, already released by concurrent
                // process). Now: only count if release_single_hold returned true.
                if ( $release_ok ) {
                    $released_count++;
                    $released_amount += (float) $hold->amount;
                } else {
                    $skipped_count++;
                    $skipped_orders[] = (int) $hold->order_id;
                }
            } catch ( \Throwable $e ) {
                $failed_count++;
                $failed_orders[] = (int) $hold->order_id;
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::error(
                        'HOLD_RELEASE_CRON_ERROR',
                        sprintf(
                            'release_eligible_holds: hold #%d (order #%d, vendor #%d) lanzó excepción: %s',
                            (int) $hold->id,
                            (int) $hold->order_id,
                            (int) $hold->vendor_id,
                            $e->getMessage()
                        ),
                        [
                            'hold_id'   => (int) $hold->id,
                            'order_id'  => (int) $hold->order_id,
                            'vendor_id' => (int) $hold->vendor_id,
                            'exception' => $e->getMessage(),
                        ]
                    );
                }
                // Continue to the next hold — do NOT abort the whole batch.
            }
        }

        // CP6: log de auditoría del cron — qué se liberó, qué se retuvo y qué falló.
        if ( class_exists( 'LTMS_Core_Logger' ) && ( $released_count > 0 || $skipped_count > 0 || $failed_count > 0 ) ) {
            LTMS_Core_Logger::info(
                'HOLD_RELEASE_CRON',
                sprintf(
                    'Cron release_eligible_holds: %d holds liberados ($%.2f), %d retenidos (pendientes entrega), %d fallidos. Pedidos retenidos: %s. Pedidos fallidos: %s',
                    $released_count,
                    $released_amount,
                    $skipped_count,
                    $failed_count,
                    implode( ',', array_slice( $skipped_orders, 0, 20 ) ),
                    implode( ',', array_slice( $failed_orders, 0, 20 ) )
                ),
                [
                    'released_count'  => $released_count,
                    'released_amount' => $released_amount,
                    'skipped_count'   => $skipped_count,
                    'skipped_orders'  => $skipped_orders,
                    'failed_count'    => $failed_count,
                    'failed_orders'   => $failed_orders,
                ]
            );
        }
    }

    /**
     * Detecta si un pedido ya fue entregado (shipping provider confirmó) o
     * no requiere shipping (productos digitales/servicios).
     *
     * M-202: usado para gating de liberación de holds.
     *
     * @param int $order_id ID del pedido WooCommerce.
     * @return bool
     */
    public static function is_order_delivered_or_no_shipping( int $order_id ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // Pedido sin shipping (todos los items virtuales/descargables) — libera sin esperar entrega.
        if ( ! $order->needs_shipping_address() ) {
            return true;
        }

        $delivered_statuses = [
            'delivered',
            'dropoff_complete',
            'entregado',
            // AUDIT-SHIPPING-ENGINE #11 FIX: Aveonline webhook stores the
            // semantic action ('delivered', 'failed', 'in_transit') in
            // _ltms_aveonline_status. 'delivered' is already in the list
            // above, but Aveonline's $nombre_estado (Spanish state name)
            // can also be 'ENTREGADO' or 'ENTREGA' — add lowercase variants.
            'entrega',
        ];

        // Cualquier provider confirmó entrega.
        $provider_status_meta = [
            '_ltms_uber_delivery_status',
            '_ltms_aveonline_status',
            '_ltms_heka_status',
            '_ltms_proships_status',
        ];
        foreach ( $provider_status_meta as $key ) {
            $status = strtolower( (string) $order->get_meta( $key ) );
            if ( $status !== '' && in_array( $status, $delivered_statuses, true ) ) {
                return true;
            }
        }

        // Marca explícita por listener (eventos ltms_shipping_delivered).
        if ( $order->get_meta( '_ltms_delivered_at' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Shipping provider confirmó entrega: actualiza release_at del hold para que el
     * período Ley 1480 cuente DESDE la entrega real, no desde la fecha de pago.
     *
     * @param int $order_id ID del pedido.
     * @return void
     */
    public static function on_shipping_delivered( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Marca canónica para que is_order_delivered_or_no_shipping() la lea sin importar el provider.
        $order->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->save();

        global $wpdb;
        $table      = $wpdb->prefix . 'lt_wallet_holds';
        $hold_days  = (int) LTMS_Core_Config::get( 'ltms_consumer_protection_days', self::DEFAULT_HOLD_DAYS );
        $release_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hold_days} weekdays" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'release_at' => $release_at ],
            [ 'order_id' => $order_id, 'status' => 'held' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'HOLD_DELIVERY_CONFIRMED',
                sprintf( 'Pedido #%d entregado — hold release_at = %s', $order_id, $release_at )
            );
        }
    }

    /**
     * Shipping provider reportó fallo/cancelación — congela el hold hasta revisión manual.
     *
     * @param int    $order_id ID del pedido.
     * @param string $reason   Motivo del fallo (opcional).
     * @return void
     */
    public static function on_shipping_failed( int $order_id, string $reason = 'shipping_failed' ): void {
        self::freeze_hold_for_dispute( $order_id, sanitize_text_field( $reason ) );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'HOLD_FROZEN_SHIPPING_FAILED',
                sprintf( 'Pedido #%d: shipping reportó fallo (%s) — hold congelado.', $order_id, $reason )
            );
        }
    }

    /**
     * Libera un hold individual y acredita los fondos en la billetera.
     *
     * @param int $hold_id   ID del hold.
     * @param int $vendor_id ID del vendedor.
     * @return void
     */
    public static function release_single_hold( int $hold_id, int $vendor_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $hold = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND vendor_id = %d AND status = 'held'",
            $hold_id,
            $vendor_id
        ) );

        if ( ! $hold ) {
            return false; // RE-AUDIT P1 FIX: return false so caller can track skipped holds.
        }

        // H-2 FIX: previously this method marked the hold as 'released' FIRST
        // and only then called Wallet::release(). If Wallet::release() threw
        // (insufficient balance_pending, DB error, race condition, fatal
        // during the inner transaction) the hold was already 'released' in
        // the DB but the vendor NEVER received the funds in their available
        // balance → silent money loss with no way to retry (the cron would
        // skip 'released' holds on the next run).
        //
        // New order: release the wallet funds FIRST; only on success do we
        // flip the hold to 'released'. Double-release is still prevented by
        // the idempotency key on Wallet::release() (CP2 FIX v2.8.8) — even
        // if two concurrent processes both call release_single_hold(), only
        // the first one actually moves funds; the second is a no-op.
        //
        // M-84: Liberar desde balance_pending → balance usando release() (NO credit).
        // El dinero ya fue acreditado en hold_commission() — solo hay que moverlo
        // de retenido a disponible para evitar doble acreditación.
        //
        // CP2 FIX (v2.8.8): idempotency key para prevenir doble liberación si el cron
        // se ejecuta dos veces o si release_single_hold se reintenta.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            $release_idem_key = sprintf( 'cp_release_h%d', $hold_id );
            try {
                LTMS_Business_Wallet::release(
                    $vendor_id,
                    (float) $hold->amount,
                    sprintf( 'Fondos liberados por vencimiento de retencion — Hold #%d, Pedido #%d', $hold_id, $hold->order_id ),
                    [ 'hold_id' => $hold_id, 'order_id' => $hold->order_id ],
                    0,
                    '',
                    $release_idem_key
                );
            } catch ( \Throwable $e ) {
                // Wallet release failed. We have NOT yet changed the hold
                // status, so it is still 'held'. Defensive: explicitly
                // revert any partial state back to 'held' in case a
                // concurrent process or a previous crashed attempt left
                // the row in a transitional state, then log CRITICAL so
                // ops can intervene. The cron will retry on the next run.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $table,
                    [ 'status' => 'held' ],
                    [ 'id' => $hold_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'HOLD_RELEASE_FAILED',
                        sprintf(
                            'Hold #%d (order #%d, vendor #%d) NO liberado: Wallet::release() lanzó excepción. Hold revertido a "held" para reintento. Excepción: %s',
                            $hold_id,
                            (int) $hold->order_id,
                            $vendor_id,
                            $e->getMessage()
                        ),
                        [
                            'hold_id'   => $hold_id,
                            'order_id'  => (int) $hold->order_id,
                            'vendor_id' => $vendor_id,
                            'amount'    => (float) $hold->amount,
                            'exception' => $e->getMessage(),
                        ]
                    );
                }
                // Re-throw so the caller (release_eligible_holds / cron)
                // can mark this hold as failed in its audit log and move on.
                throw $e;
            }
        }

        // Wallet release succeeded → NOW mark the hold as released.
        // CP-BUG-1 FIX: the WHERE clause includes `status='held'` so two
        // concurrent processes cannot both flip the row — the loser gets
        // 0 rows affected and silently exits (the idempotency key on
        // Wallet::release() already prevented any double spend).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $table,
            [ 'status' => 'released', 'released_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $hold_id, 'status' => 'held' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( ! $updated ) {
            return false; // Already released by concurrent process.
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) LTMS_Core_Logger::log(
            'HOLD_RELEASED',
            sprintf( 'Hold #%d liberado: %.2f para vendedor #%d', $hold_id, $hold->amount, $vendor_id )
        );
        return true;
    }

    /**
     * Congela un hold (ej: cuando se abre una disputa).
     *
     * @param int    $order_id ID del pedido.
     * @param string $reason   Razón del congelamiento.
     * @return bool
     */
    public static function freeze_hold_for_dispute( int $order_id, string $reason = 'dispute' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_holds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->update(
            $table,
            [ 'status' => 'frozen', 'freeze_reason' => sanitize_text_field( $reason ) ],
            [ 'order_id' => $order_id, 'status' => 'held' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * CP-BUG-2 / CP-BUG-3 / CP-BUG-5 / CP-BUG-6 — Dispute lifecycle
     * ---------------------------------------------------------------------------
     * Implementa el ciclo completo de disputas (reclamaciones del consumidor)
     * con cumplimiento Ley 1480 (Colombia, 5 días hábiles) y PROFECO (México,
     * 10 días naturales — derecho de retracto), más integración con XCover
     * para reclamos de seguro (mercancía dañada/perdida) y notificaciones
     * por email al vendor y al customer.
     *
     * Schema requerido (MIGRATION NEEDED — no incluida en este scope):
     *   CREATE TABLE `{prefix}lt_consumer_disputes` (
     *     `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     *     `order_id`        BIGINT UNSIGNED NOT NULL,
     *     `customer_id`     BIGINT UNSIGNED NOT NULL,
     *     `reason`          VARCHAR(50)  NOT NULL,  -- damaged|lost|not_as_described|never_arrived|other
     *     `description`     TEXT         NULL,
     *     `evidence`        TEXT         NULL,      -- JSON: URLs/filenames
     *     `status`          VARCHAR(20)  NOT NULL DEFAULT 'filed', -- filed|under_review|approved|rejected|cancelled
     *     `hold_frozen`     TINYINT(1)   NOT NULL DEFAULT 0,
     *     `reviewed_by`     BIGINT UNSIGNED NULL,
     *     `reviewed_at`     DATETIME     NULL,
     *     `resolved_by`     BIGINT UNSIGNED NULL,
     *     `resolved_at`     DATETIME     NULL,
     *     `resolution_note` TEXT         NULL,
     *     `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
     *     PRIMARY KEY (`id`),
     *     KEY `idx_order`   (`order_id`),
     *     KEY `idx_status`  (`status`),
     *     KEY `idx_customer`(`customer_id`)
     *   );
     * ---------------------------------------------------------------------------
     */

    /**
     * CP-BUG-3 FIX: Devuelve la ventana legal de disputa según el país.
     *
     * Colombia (Ley 1480/2011) — 5 días hábiles (default).
     * México (PROFECO, derecho de retracto) — 10 días naturales.
     *
     * @param int $order_id ID del pedido (reservado para futura lógica por categoría).
     * @return int
     */
    public static function get_dispute_window_days( int $order_id = 0 ): int {
        $country = LTMS_Core_Config::get_country();
        if ( $country === 'MX' ) {
            return (int) LTMS_Core_Config::get( 'ltms_profeco_withdrawal_days', 10 );
        }
        return (int) LTMS_Core_Config::get( 'ltms_ley1480_hold_days', 5 );
    }

    /**
     * CP-BUG-2 FIX: Customer presenta una disputa dentro del período de hold.
     *
     * @param int    $order_id    ID del pedido WooCommerce.
     * @param int    $customer_id ID del usuario cliente.
     * @param string $reason      damaged|lost|not_as_described|never_arrived|other.
     * @param string $description Detalle libre-texto del cliente.
     * @param array  $evidence    URLs/paths de evidencia adjunta.
     * @return int|WP_Error ID de la disputa creada, o WP_Error.
     */
    public static function file_dispute( int $order_id, int $customer_id, string $reason, string $description = '', array $evidence = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_consumer_disputes';

        // C-1 FIX: ensure the disputes table exists before any read/write.
        // Previously the CREATE TABLE lived only inside a docblock comment
        // (see "Schema requerido" above) so fresh installs had no table and
        // every file_dispute() call failed with "Table doesn't exist".
        // We use IF NOT EXISTS so this is idempotent and cheap on hot paths.
        $charset_collate = $wpdb->get_charset_collate();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`        BIGINT UNSIGNED NOT NULL,
                `customer_id`     BIGINT UNSIGNED NOT NULL,
                `reason`          VARCHAR(50)  NOT NULL,
                `description`     TEXT         NULL,
                `evidence`        TEXT         NULL,
                `status`          VARCHAR(20)  NOT NULL DEFAULT 'filed',
                `hold_frozen`     TINYINT(1)   NOT NULL DEFAULT 0,
                `reviewed_by`     BIGINT UNSIGNED NULL,
                `reviewed_at`     DATETIME     NULL,
                `resolved_by`     BIGINT UNSIGNED NULL,
                `resolved_at`     DATETIME     NULL,
                `resolution_note` TEXT         NULL,
                `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order`   (`order_id`),
                KEY `idx_status`  (`status`),
                KEY `idx_customer`(`customer_id`)
            ) {$charset_collate};"
        );

        // Verificar que el pedido existe.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found', 'ltms' ) );
        }

        // CP5 FIX (v2.8.8): validar que customer_id sea el dueño del pedido.
        // Antes, cualquier usuario podía abrir disputas para pedidos ajenos.
        $order_customer_id = (int) $order->get_customer_id();
        if ( $order_customer_id > 0 && $order_customer_id !== $customer_id ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'DISPUTE_UNAUTHORIZED',
                    sprintf(
                        'Disputa rechazada: user #%d intentó disputar order #%d (dueño: #%d)',
                        $customer_id, $order_id, $order_customer_id
                    )
                );
            }
            return new WP_Error( 'unauthorized', __( 'You can only file disputes for your own orders', 'ltms' ) );
        }

        // Idempotencia: no permitir doble disputa activa para el mismo pedido.
        // RE-AUDIT P0 FIX (TOCTOU): wrap SELECT+INSERT in a transaction with
        // SELECT FOR UPDATE to prevent two concurrent file_dispute() calls from
        // both passing the check and both INSERTing → double dispute → double
        // vendor debit (approve_dispute uses dispute_id in idempotency key).
        $wpdb->query( 'START TRANSACTION' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE order_id = %d AND status IN ('filed','under_review') FOR UPDATE",
            $order_id
        ) );
        if ( $existing ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'dispute_exists', __( 'Dispute already filed for this order', 'ltms' ) );
        }

        // Verificar ventana legal de disputa (CP-BUG-3: country-aware).
        $delivered_date = $order->get_meta( '_ltms_delivered_at' );
        if ( $delivered_date ) {
            $window_days = self::get_dispute_window_days( $order_id );
            $days_since  = ( time() - strtotime( $delivered_date ) ) / DAY_IN_SECONDS;
            if ( $days_since > $window_days ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error(
                    'window_expired',
                    sprintf(
                        __( 'Dispute window expired (%d days)', 'ltms' ),
                        $window_days
                    )
                );
            }
        }

        // Congelar el hold para impedir liberación automática mientras se revisa.
        $hold_frozen = self::freeze_hold_for_dispute( $order_id, 'dispute_filed' );

        // Insertar la disputa.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $inserted = $wpdb->insert( $table, [
            'order_id'     => $order_id,
            'customer_id'  => $customer_id,
            'reason'       => sanitize_text_field( $reason ),
            'description'  => sanitize_textarea_field( $description ),
            'evidence'     => wp_json_encode( $evidence ),
            'status'       => 'filed',
            'hold_frozen'  => $hold_frozen ? 1 : 0,
            'created_at'   => current_time( 'mysql', true ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ] );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'insert_failed', __( 'Could not create dispute record', 'ltms' ) );
        }
        $wpdb->query( 'COMMIT' );

        $dispute_id = (int) $wpdb->insert_id;

        // CP-BUG-5: disparar claim de seguro XCover si la razón aplica y existe póliza.
        self::maybe_trigger_insurance_claim( $dispute_id, $order_id, $reason );

        // Notificar al vendor (y al customer vía hook — ver CP-BUG-6).
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        do_action( 'ltms_dispute_filed', $dispute_id, $order_id, $vendor_id, $customer_id );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DISPUTE_FILED',
                sprintf( 'Dispute #%d filed for order #%d (customer #%d, reason: %s)', $dispute_id, $order_id, $customer_id, $reason )
            );
        }

        return $dispute_id;
    }

    /**
     * CP-BUG-2 FIX: Admin toma revisión de una disputa (filed → under_review).
     *
     * @param int $dispute_id ID de la disputa.
     * @param int $admin_id   ID del usuario admin.
     * @return bool|WP_Error
     */
    public static function review_dispute( int $dispute_id, int $admin_id ) {
        // RE-AUDIT P1 FIX: no capability check — any authenticated user could
        // transition disputes to 'under_review' if exposed via REST/AJAX.
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'ltms_manage_disputes' ) ) {
            return new \WP_Error( 'unauthorized', __( 'Permisos insuficientes para revisar disputas.', 'ltms' ) );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_consumer_disputes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->update(
            $table,
            [
                'status'      => 'under_review',
                'reviewed_by' => $admin_id,
                'reviewed_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $dispute_id, 'status' => 'filed' ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );

        if ( ! $updated ) {
            return new WP_Error( 'invalid_dispute', __( 'Dispute not found or already under review', 'ltms' ) );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DISPUTE_REVIEW',
                sprintf( 'Dispute #%d under review by admin #%d', $dispute_id, $admin_id )
            );
        }

        return true;
    }

    /**
     * CP-BUG-2 FIX: Admin aprueba la disputa (gana el cliente — refund + debit vendor).
     *
     * Secuencia: debitar wallet del vendor → crear WC refund → disparar hook para
     * reversión de comisión (escuchado por el motor de comisiones).
     *
     * @param int    $dispute_id       ID de la disputa.
     * @param int    $admin_id         ID del admin que resuelve.
     * @param string $resolution_note  Nota interna de la resolución.
     * @return bool|WP_Error
     */
    public static function approve_dispute( int $dispute_id, int $admin_id, string $resolution_note = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_consumer_disputes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dispute = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND status = 'under_review'",
            $dispute_id
        ) );
        if ( ! $dispute ) {
            return new WP_Error( 'invalid_dispute', __( 'Dispute not found or not under review', 'ltms' ) );
        }

        // Marcar resuelta PRIMERO (atomic) — evita doble aprobación por race condition.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $resolved = $wpdb->update(
            $table,
            [
                'status'          => 'approved',
                'resolved_by'     => $admin_id,
                'resolved_at'     => current_time( 'mysql', true ),
                'resolution_note' => sanitize_textarea_field( $resolution_note ),
            ],
            [ 'id' => $dispute_id, 'status' => 'under_review' ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( ! $resolved ) {
            return new WP_Error( 'invalid_dispute', __( 'Dispute could not be approved (concurrent resolution?)', 'ltms' ) );
        }

        $order_id = (int) $dispute->order_id;
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found', 'ltms' ) );
        }

        $vendor_id      = (int) $order->get_meta( '_ltms_vendor_id' );
        $refund_amount  = (float) $order->get_total();

        // CP4 FIX (v2.8.8) CRÍTICO: el vendor solo recibió vendor_net (gross - platform_fee
        // - withholding), NO el order_total. Debitar order_total al vendor significa que
        // pierde $15+ más de lo que recibió (la comisión de la plataforma + retenciones
        // nunca llegaron a su wallet).
        //
        // Estrategia correcta:
        //   - Customer recibe: refund_amount (order_total) via wc_create_refund.
        //   - Vendor debit: vendor_net (lo que realmente recibió en su wallet).
        //   - Plataforma absorbe: platform_fee + withholding (reversión de comisión).
        //
        // vendor_net se guarda en order meta _ltms_vendor_net por Order_Split.
        //
        // FU4 FIX (v2.9.1) CRÍTICO: soporte multi-vendor. Si el pedido tiene
        // _ltms_vendor_split_breakdown, debitar a CADA vendor su vendor_net
        // individual. Antes, solo se debitaba al primer vendor (_ltms_vendor_id),
        // dejando a los demás vendors con saldo indebido.
        $vendor_net = (float) $order->get_meta( '_ltms_vendor_net' );
        if ( $vendor_net <= 0 ) {
            // Fallback: si no hay meta (pedido legacy), usar order_total como antes.
            $vendor_net = $refund_amount;
        }

        // FU4: construir lista de vendors a debitar con sus montos individuales.
        $split_breakdown = $order->get_meta( '_ltms_vendor_split_breakdown' );
        $vendors_to_debit = [];

        if ( is_array( $split_breakdown ) && ! empty( $split_breakdown ) ) {
            // Multi-vendor: debitar cada vendor su vendor_net.
            foreach ( $split_breakdown as $vid => $data ) {
                $vid = (int) $vid;
                $v_net = (float) ( $data['vendor_net'] ?? 0 );
                if ( $vid > 0 && $v_net > 0 ) {
                    $vendors_to_debit[] = [
                        'vendor_id'  => $vid,
                        'vendor_net' => $v_net,
                    ];
                }
            }
        } elseif ( $vendor_id > 0 && $vendor_net > 0 ) {
            // Single-vendor (o legacy sin breakdown).
            $vendors_to_debit[] = [
                'vendor_id'  => $vendor_id,
                'vendor_net' => $vendor_net,
            ];
        }

        // Debitar a cada vendor su vendor_net.
        // CP4: idempotency key por (dispute_id, vendor_id) para prevenir doble débito.
        $total_debited = 0.0;
        foreach ( $vendors_to_debit as $vtd ) {
            $v_id   = (int) $vtd['vendor_id'];
            $v_net  = (float) $vtd['vendor_net'];

            if ( $v_id && class_exists( 'LTMS_Business_Wallet' ) ) {
                try {
                    $debit_idem_key = sprintf( 'cp_dispute_debit_d%d_v%d', $dispute_id, $v_id );
                    LTMS_Business_Wallet::debit(
                        $v_id,
                        $v_net,
                        sprintf( 'Dispute #%d approved — refund to customer (vendor net)', $dispute_id ),
                        [
                            'type'           => 'dispute_refund',
                            'order_id'       => $order_id,
                            'dispute_id'     => $dispute_id,
                            'order_total'    => $refund_amount,
                            'vendor_net'     => $v_net,
                            'platform_loss'  => 0, // Calculado abajo como agregado.
                        ],
                        $order_id,
                        '',
                        $debit_idem_key
                    );
                    $total_debited += $v_net;
                } catch ( \Throwable $e ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        // RE-AUDIT P0 FIX: upgrade from error → critical. Previously the
                        // exception was swallowed — customer gets refunded, vendor is NOT
                        // debited, platform absorbs the full vendor_net as a loss with
                        // only a log entry. Now: log as critical so monitoring alerts fire.
                        LTMS_Core_Logger::critical(
                            'DISPUTE_DEBIT_FAILED',
                            sprintf( 'Dispute #%d: vendor #%d wallet debit FAILED: %s. Platform covers $%.2f. Manual reconciliation required.', $dispute_id, $v_id, $e->getMessage(), $v_net ),
                            [ 'dispute_id' => $dispute_id, 'vendor_id' => $v_id, 'amount' => $v_net, 'error' => $e->getMessage() ]
                        );
                    }
                    // Continue to refund the customer — they shouldn't suffer for
                    // a platform-side wallet issue. The debit failure is logged as
                    // critical for manual reconciliation.
                }
            }
        }

        // CP4: log de la pérdida de la plataforma (platform_fee + withholding absorbidos).
        // FU4: platform_loss = order_total - sum(vendor_nets debited).
        $platform_loss = round( $refund_amount - $total_debited, 2 );
        if ( $platform_loss > 0 && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'DISPUTE_PLATFORM_LOSS',
                sprintf(
                    'Dispute #%d: plataforma absorbe $%.2f (commission + withholding) — order_total=$%.2f, vendors_debited=$%.2f (%d vendors)',
                    $dispute_id, $platform_loss, $refund_amount, $total_debited, count( $vendors_to_debit )
                )
            );
        }

        // Crear WooCommerce refund (devuelve el dinero al medio de pago del cliente).
        // RE-AUDIT P0 FIX: check return value of wc_create_refund. Previously the
        // return was discarded — if the refund failed (gateway error, order already
        // refunded), the dispute was marked 'approved', vendor was debited, but
        // the customer received no refund.
        if ( function_exists( 'wc_create_refund' ) ) {
            $refund = wc_create_refund( [
                'order_id' => $order_id,
                'amount'   => $refund_amount,
                'reason'   => sprintf( 'Dispute #%d approved', $dispute_id ),
            ] );
            if ( is_wp_error( $refund ) ) {
                // Refund failed — log critical and return error so admin can retry.
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'DISPUTE_REFUND_FAILED',
                        sprintf( 'Dispute #%d: wc_create_refund failed for order #%d: %s', $dispute_id, $order_id, $refund->get_error_message() ),
                        [ 'dispute_id' => $dispute_id, 'order_id' => $order_id, 'amount' => $refund_amount ]
                    );
                }
                return new \WP_Error( 'refund_failed', sprintf( __( 'El reembolso al cliente falló: %s', 'ltms' ), $refund->get_error_message() ) );
            }
        }

        // Reversión de comisión + liberación del hold frozen lo escuchan otros motores.
        // RE-AUDIT P1 FIX: do_action passed only $vendor_id (first vendor) →
        // listeners (insurance, notifications, commission reversal) only reacted
        // for vendor_1. Vendors 2..N were silently skipped. Now: pass the full
        // $vendors_to_debit array so listeners can react for ALL affected vendors.
        do_action( 'ltms_dispute_approved', $dispute_id, $order_id, $vendor_id, $vendors_to_debit );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DISPUTE_APPROVED',
                sprintf( 'Dispute #%d approved — refund $%.2f to customer, debit vendor #%d', $dispute_id, $refund_amount, $vendor_id )
            );
        }

        return true;
    }

    /**
     * CP-BUG-2 FIX: Admin rechaza la disputa (gana el vendor — libera el hold).
     *
     * @param int    $dispute_id       ID de la disputa.
     * @param int    $admin_id         ID del admin que resuelve.
     * @param string $resolution_note  Nota interna de la resolución.
     * @return bool|WP_Error
     */
    public static function reject_dispute( int $dispute_id, int $admin_id, string $resolution_note = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_consumer_disputes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dispute = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND status = 'under_review'",
            $dispute_id
        ) );
        if ( ! $dispute ) {
            return new WP_Error( 'invalid_dispute', __( 'Dispute not found or not under review', 'ltms' ) );
        }

        // Marcar resuelta PRIMERO (atomic).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $resolved = $wpdb->update(
            $table,
            [
                'status'          => 'rejected',
                'resolved_by'     => $admin_id,
                'resolved_at'     => current_time( 'mysql', true ),
                'resolution_note' => sanitize_textarea_field( $resolution_note ),
            ],
            [ 'id' => $dispute_id, 'status' => 'under_review' ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( ! $resolved ) {
            return new WP_Error( 'invalid_dispute', __( 'Dispute could not be rejected (concurrent resolution?)', 'ltms' ) );
        }

        $order_id = (int) $dispute->order_id;

        // Descongelar el hold y disparar liberación al vendor.
        // El action lo escucha un handler que vuelve el hold a 'held' para que el cron
        // diario lo libere normalmente, o lo libera inmediatamente.
        do_action( 'ltms_dispute_rejected', $dispute_id, $order_id );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'DISPUTE_REJECTED',
                sprintf( 'Dispute #%d rejected — hold released to vendor', $dispute_id )
            );
        }

        return true;
    }

    /**
     * CP-BUG-5 FIX: Dispara un claim de seguro XCover para mercancía dañada/perdida.
     *
     * Solo actúa si el motivo es 'damaged' o 'lost' y si el pedido tiene una
     * póliza XCover asociada (meta `_ltms_xcover_policy_id`). El claim real lo
     * procesa un listener registrado en el action `ltms_xcover_file_claim` (ver
     * class-ltms-xcover-policy-listener.php). Si no hay póliza, no hace nada.
     *
     * @param int    $dispute_id ID de la disputa recién creada.
     * @param int    $order_id   ID del pedido.
     * @param string $reason     Razón de la disputa.
     * @return void
     */
    public static function maybe_trigger_insurance_claim( int $dispute_id, int $order_id, string $reason ): void {
        if ( $reason !== 'damaged' && $reason !== 'lost' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $policy_id = $order->get_meta( '_ltms_xcover_policy_id' );
        if ( ! $policy_id ) {
            // No hay póliza XCover para este pedido — nothing to do.
            return;
        }

        /**
         * Dispara el claim de seguro. Lo escucha el XCover policy listener, que
         * invoca LTMS_Api_XCover::create_claim() con idempotencia.
         *
         * @param string $policy_id  ID de la póliza XCover.
         * @param int    $dispute_id ID de la disputa.
         * @param int    $order_id   ID del pedido.
         * @param string $reason     damaged|lost.
         */
        do_action( 'ltms_xcover_file_claim', $policy_id, $dispute_id, $order_id, $reason );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'INSURANCE_CLAIM_TRIGGERED',
                sprintf( 'XCover claim triggered for dispute #%d, policy #%s', $dispute_id, $policy_id )
            );
        }
    }

    /**
     * CP-BUG-6 FIX: Envía notificaciones por email al vendor y al customer
     * en cada evento del lifecycle de disputa.
     *
     * NOTE: Esta es una implementación mínima (asunto + cuerpo plano). Un listener
     * de notificaciones más sofisticado (con templates HTML, preferencias de usuario,
     * internacionalización) debería registrarse en los mismos actions y reemplazar
     * este método vía __return_false en `ltms_dispute_filed` / `ltms_dispute_approved`
     * / `ltms_dispute_rejected` si se desea customizar.
     *
     * @param int    $dispute_id ID de la disputa.
     * @param int    $order_id   ID del pedido.
     * @param string $event      filed|approved|rejected.
     * @return void
     */
    public static function send_dispute_notifications( int $dispute_id, int $order_id, string $event ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $vendor_id   = (int) $order->get_meta( '_ltms_vendor_id' );
        $customer_id = (int) $order->get_customer_id();

        // Notificar al vendor.
        if ( $vendor_id ) {
            $vendor_user = get_userdata( $vendor_id );
            $vendor_email = $vendor_user ? $vendor_user->user_email : '';
            if ( $vendor_email ) {
                wp_mail(
                    $vendor_email,
                    sprintf( '[%s] Dispute #%d %s', get_bloginfo( 'name' ), $dispute_id, $event ),
                    sprintf( "Order #%d — dispute #%d has been %s.\n\nReview the full details in your vendor dashboard.\n", $order_id, $dispute_id, $event )
                );
            }
        }

        // Notificar al customer.
        if ( $customer_id ) {
            $customer_user = get_userdata( $customer_id );
            $customer_email = $customer_user ? $customer_user->user_email : '';
            if ( $customer_email ) {
                wp_mail(
                    $customer_email,
                    sprintf( '[%s] Your dispute #%d %s', get_bloginfo( 'name' ), $dispute_id, $event ),
                    sprintf( "Order #%d — your dispute #%d has been %s.\n\nIf you have questions, reply to this email.\n", $order_id, $dispute_id, $event )
                );
            }
        }
    }

    /**
     * CP-BUG-6 FIX: Hook wrapper — adapta el action `ltms_dispute_filed`
     * (4 args) a `send_dispute_notifications` (3 args).
     *
     * @param int $dispute_id  ID de la disputa.
     * @param int $order_id    ID del pedido.
     * @param int $vendor_id   ID del vendor (no usado aquí, pasado por el action).
     * @param int $customer_id ID del customer (no usado aquí).
     * @return void
     */
    public static function on_dispute_filed( int $dispute_id, int $order_id, int $vendor_id = 0, int $customer_id = 0 ): void {
        self::send_dispute_notifications( $dispute_id, $order_id, 'filed' );
    }

    /**
     * CP-BUG-6 FIX: Hook wrapper — adapta el action `ltms_dispute_approved`
     * (3 args) a `send_dispute_notifications` (3 args).
     *
     * @param int $dispute_id ID de la disputa.
     * @param int $order_id   ID del pedido.
     * @param int $vendor_id  ID del vendor (no usado aquí).
     * @return void
     */
    public static function on_dispute_approved( int $dispute_id, int $order_id, int $vendor_id = 0 ): void {
        self::send_dispute_notifications( $dispute_id, $order_id, 'approved' );
    }

    /**
     * CP-BUG-6 FIX: Hook wrapper — adapta el action `ltms_dispute_rejected`
     * (2 args) a `send_dispute_notifications` (3 args).
     *
     * @param int $dispute_id ID de la disputa.
     * @param int $order_id   ID del pedido.
     * @return void
     */
    public static function on_dispute_rejected( int $dispute_id, int $order_id ): void {
        self::send_dispute_notifications( $dispute_id, $order_id, 'rejected' );
    }
}
