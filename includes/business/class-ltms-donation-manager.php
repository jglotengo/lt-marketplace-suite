<?php
/**
 * LTMS Donation Manager
 *
 * Orchestrates donations to Fundación Cardio Infantil:
 *   - Creates donation records on order paid
 *   - Credits the foundation's wallet (vendor_id = -1)
 *   - Syncs to Alegra (contabilidad)
 *   - Manages monthly payout batches
 *   - Reverses donations on order refund
 *   - Generates statistics for transparency
 *
 * Depends on:
 *   - LTMS_Donation_Calculator (pure math)
 *   - LTMS_Business_Wallet      (ledger; credit/debit throw on failure)
 *   - LTMS_Core_Config          (cached settings)
 *   - LTMS_Core_Logger          (forensic log)
 *
 * Tables (created by separate migration — see db migration agent):
 *   - {prefix}lt_donations          (one row per order)
 *   - {prefix}lt_donation_payouts   (one row per monthly batch)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Donation_Manager
 *
 * Main motor for the donation feature.
 */
final class LTMS_Donation_Manager {

    /**
     * Special "vendor" ID used for the foundation wallet.
     * Negative to avoid collision with real WP user IDs.
     */
    const FOUNDATION_VENDOR_ID = -1;

    /**
     * Donation records table name (without prefix).
     */
    const TABLE_DONATIONS = 'lt_donations';

    /**
     * Payout batches table name (without prefix).
     */
    const TABLE_PAYOUTS = 'lt_donation_payouts';

    /**
     * Register hooks. Called from the plugin kernel.
     *
     * @return void
     */
    public static function init(): void {
        // Order paid → create donation. Fired by the order-split orchestrator
        // AFTER split has computed platform_fee/vendor_net/order_total.
        add_action( 'ltms_order_paid_after_split', [ __CLASS__, 'on_order_paid' ], 10, 2 );

        // Order refunded → reverse donation.
        add_action( 'woocommerce_order_refunded', [ __CLASS__, 'on_order_refunded' ], 10, 2 );

        // Cron: monthly payout batch.
        add_action( 'ltms_donation_payout_cron', [ __CLASS__, 'process_payout_batch' ] );

        // Cron: certificate generation.
        add_action( 'ltms_donation_certificate_cron', [ __CLASS__, 'generate_monthly_certificates' ] );

        // MGR-BUG-1: Recovery cron for donations stuck in 'processing' status.
        // Fires every 15 minutes. Handles the crash-after-Wallet::debit scenario:
        // if the wallet debit succeeded but the donation status update didn't
        // happen, mark the donation as 'paid'; otherwise revert to 'credited'
        // for the next payout cycle.
        add_filter( 'cron_schedules', [ __CLASS__, 'add_every_15_minutes_schedule' ] );
        add_action( 'ltms_donation_recover_processing', [ __CLASS__, 'recover_processing_donations' ] );
        if ( ! wp_next_scheduled( 'ltms_donation_recover_processing' ) ) {
            wp_schedule_event( time() + 900, 'every_15_minutes', 'ltms_donation_recover_processing' );
        }
    }

    /**
     * Register the custom 'every_15_minutes' cron recurrence.
     *
     * MGR-BUG-1: WordPress core does not ship a 15-minute interval; the kernel
     * registers 'every_5_minutes' and 'every_30_minutes' but neither fits the
     * recovery SLA. Registered via the cron_schedules filter.
     *
     * @param array $schedules Existing WP cron schedules.
     * @return array
     */
    public static function add_every_15_minutes_schedule( array $schedules ): array {
        if ( ! isset( $schedules['every_15_minutes'] ) ) {
            $schedules['every_15_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'ltms' ),
            ];
        }
        return $schedules;
    }

    /**
     * Create a donation record when an order is paid.
     * Called AFTER order split has computed platform_fee.
     *
     * Idempotent: if a donation already exists for this order, returns its ID.
     *
     * @param int   $order_id   WooCommerce order ID.
     * @param array $split_data {
     *     @type float  $platform_fee Comisión del marketplace.
     *     @type float  $order_total  Total de la orden.
     *     @type float  $vendor_net   Neto del vendedor.
     *     @type string $currency     COP, MXN.
     *     @type int    $vendor_id    (optional) Primary vendor ID.
     * }
     * @return int|WP_Error Donation ID on success, existing ID if already processed, WP_Error on failure.
     */
    public static function on_order_paid( int $order_id, array $split_data ) {
        global $wpdb;

        $donations_table = $wpdb->prefix . self::TABLE_DONATIONS;

        // Idempotency: don't create duplicate donation for same order.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$donations_table}` WHERE order_id = %d LIMIT 1",
                $order_id
            )
        );
        if ( $existing > 0 ) {
            return $existing; // Already processed.
        }

        // Customer opt-in extra donation (set at checkout).
        $customer_extra = (float) get_post_meta( $order_id, '_ltms_customer_donation_extra', true );

        // MGR-BUG-3: Compute platform_profit from cost components when not
        // explicitly provided. The admin setting exposes 'platform_profit' as a
        // distinct basis ("Ganancia neta del marketplace = platform_fee - costos"),
        // so when the split orchestrator provides the cost breakdown we use it;
        // otherwise we fall back to platform_fee as a simplification.
        if ( array_key_exists( 'platform_profit', $split_data ) ) {
            $platform_profit = (float) $split_data['platform_profit'];
        } elseif ( isset( $split_data['shipping_cost'] ) || isset( $split_data['payment_fee'] ) || isset( $split_data['tax_withholding'] ) ) {
            $platform_profit = (float) ( $split_data['platform_fee'] ?? 0.0 )
                - (float) ( $split_data['shipping_cost'] ?? 0.0 )
                - (float) ( $split_data['payment_fee'] ?? 0.0 )
                - (float) ( $split_data['tax_withholding'] ?? 0.0 );
        } else {
            // TODO: platform_profit = platform_fee - costs (currently simplified to platform_fee).
            $platform_profit = (float) ( $split_data['platform_fee'] ?? 0.0 );
        }

        // Calculate donation.
        $calc = LTMS_Donation_Calculator::calculate(
            [
                'platform_fee'    => (float) ( $split_data['platform_fee'] ?? 0.0 ),
                'order_total'     => (float) ( $split_data['order_total'] ?? 0.0 ),
                'vendor_net'      => (float) ( $split_data['vendor_net'] ?? 0.0 ),
                'platform_profit' => $platform_profit,
                'currency'        => (string) ( $split_data['currency'] ?? LTMS_Core_Config::get_currency() ),
                'customer_extra'  => $customer_extra,
            ]
        );

        if ( ! $calc['enabled'] || $calc['total_donation'] <= 0 ) {
            return new WP_Error( 'donation_zero', 'Donación calculada en cero o deshabilitada' );
        }

        // Resolve vendor + customer IDs (informational; donation is per-order, not per-vendor).
        $vendor_id   = (int) ( $split_data['vendor_id'] ?? get_post_meta( $order_id, '_ltms_vendor_id', true ) );
        $customer_id = (int) get_post_meta( $order_id, '_customer_user', true );

        // Insert donation record.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $donations_table,
            [
                'order_id'              => $order_id,
                'vendor_id'             => $vendor_id,
                'customer_id'           => $customer_id,
                'basis_amount'          => $calc['basis_amount'],
                'basis_type'            => $calc['basis_type'],
                'donation_percentage'   => $calc['percentage'],
                'donation_amount'       => $calc['final_amount'],
                'currency'              => $calc['currency'],
                'customer_extra_amount' => $calc['customer_extra'],
                'total_donation'        => $calc['total_donation'],
                'status'                => 'pending',
                'created_at'            => current_time( 'mysql', true ),
                'metadata'              => wp_json_encode( $calc ),
            ]
        );

        $donation_id = (int) $wpdb->insert_id;
        if ( ! $inserted || $donation_id <= 0 ) {
            // DM-2 FIX (AUDIT-BATCH2): RACE entre el SELECT de idempotencia y el
            // INSERT (dos webhooks de WC disparándose concurrentemente, cron +
            // admin manual, etc.). La UNIQUE KEY `uniq_order` (INT-BUG-5) bloquea
            // el segundo INSERT, pero el código original retornaba WP_Error —
            // ensuciando los logs con 'DONATION_INSERT_FAILED' y haciendo creer
            // al caller que la donación falló cuando en realidad YA EXISTE.
            //
            // Tratamos el duplicate-key como éxito idempotente: re-SELECT y
            // devolver el donation_id existente. Solo propagamos WP_Error si el
            // error NO es por duplicado (DB real down, schema corrupto, etc.).
            $existing_after_race = (int) $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
                    "SELECT id FROM `{$donations_table}` WHERE order_id = %d LIMIT 1",
                    $order_id
                )
            );
            if ( $existing_after_race > 0 ) {
                LTMS_Core_Logger::info(
                    'DONATION_INSERT_RACE_WON_BY_OTHER',
                    sprintf( 'Donación para orden #%d ya existía (id #%d) — carrera resuelta como idempotente', $order_id, $existing_after_race ),
                    [ 'order_id' => $order_id, 'donation_id' => $existing_after_race ]
                );
                return $existing_after_race;
            }
            LTMS_Core_Logger::error(
                'DONATION_INSERT_FAILED',
                sprintf( 'Error al registrar donación para orden #%d: %s', $order_id, $wpdb->last_error ),
                [ 'order_id' => $order_id, 'last_error' => $wpdb->last_error ]
            );
            return new WP_Error( 'donation_insert_failed', 'Error al registrar donación' );
        }

        // Credit foundation wallet.
        // Wallet::credit() throws InvalidArgumentException / RuntimeException on failure
        // (returns int transaction ID on success). Wrap in try/catch.
        $foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación Cardio Infantil' );
        try {
            LTMS_Business_Wallet::credit(
                self::FOUNDATION_VENDOR_ID,
                (float) $calc['total_donation'],
                sprintf( 'Donación orden #%d — %s', $order_id, $foundation_name ),
                [
                    'type'        => 'donation',
                    'order_id'    => $order_id,
                    'donation_id' => $donation_id,
                    'currency'    => $calc['currency'],
                ],
                $order_id,
                $calc['currency'],
                sprintf( 'donation_credit_o%d_d%d', $order_id, $donation_id ) // idempotency key
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'DONATION_WALLET_CREDIT_FAILED',
                sprintf( 'Donación #%d: fallo al acreditar billetera: %s', $donation_id, $e->getMessage() ),
                [ 'donation_id' => $donation_id, 'order_id' => $order_id, 'amount' => $calc['total_donation'] ]
            );
            // Mark donation as failed but keep record for forensic audit.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $donations_table, [ 'status' => 'failed' ], [ 'id' => $donation_id ] );
            return new WP_Error( 'donation_wallet_credit_failed', $e->getMessage() );
        }

        // Mark donation as credited.
        // MGR-BUG-12: Check the return value of $wpdb->update(). If it returns
        // false (DB error), log an error and DO NOT fire ltms_donation_credited —
        // the wallet is credited but the donation status is stale; firing the
        // action would mislead Alegra/other listeners.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $credited_update = $wpdb->update( $donations_table, [ 'status' => 'credited' ], [ 'id' => $donation_id ] );

        if ( false === $credited_update ) {
            LTMS_Core_Logger::error(
                'DONATION_STATUS_UPDATE_FAILED',
                sprintf( 'Donación #%d: fallo al actualizar estado a "credited" tras acreditar billetera: %s', $donation_id, $wpdb->last_error ),
                [ 'donation_id' => $donation_id, 'order_id' => $order_id, 'last_error' => $wpdb->last_error ]
            );
        }

        // Sync to Alegra (contabilidad) — fire action only if status update succeeded.
        if ( false !== $credited_update ) {
            $alegra_account_id = (int) LTMS_Core_Config::get( 'ltms_donation_alegra_account_id', 0 );
            if ( $alegra_account_id > 0 && class_exists( 'LTMS_Alegra_Sync' ) ) {
                /**
                 * Fires when a donation has been credited to the foundation wallet
                 * and Alegra sync is enabled. Listeners (typically LTMS_Alegra_Sync)
                 * should post an ingresso/donación entry to Alegra.
                 *
                 * @param int    $donation_id Donation record ID.
                 * @param int    $order_id    WooCommerce order ID.
                 * @param float  $amount      Total donation amount.
                 * @param string $currency    ISO 4217 currency code.
                 */
                do_action( 'ltms_donation_credited', $donation_id, $order_id, $calc['total_donation'], $calc['currency'] );
            }
        }

        /**
         * Fires after a donation record has been fully created and credited.
         * Use for reports, notifications, transparency pages, etc.
         *
         * @param int   $donation_id Donation record ID.
         * @param int   $order_id    WooCommerce order ID.
         * @param array $calc        Full calculation result from LTMS_Donation_Calculator::calculate().
         */
        do_action( 'ltms_donation_recorded', $donation_id, $order_id, $calc );

        LTMS_Core_Logger::info(
            'DONATION_RECORDED',
            sprintf( 'Donación #%d registrada: $%.2f para orden #%d', $donation_id, $calc['total_donation'], $order_id ),
            [
                'donation_id' => $donation_id,
                'order_id'    => $order_id,
                'amount'      => $calc['total_donation'],
                'currency'    => $calc['currency'],
            ]
        );

        return $donation_id;
    }

    /**
     * Reverse a donation when an order is refunded.
     *
     * @param int $order_id  WooCommerce order ID.
     * @param int $refund_id WooCommerce refund ID.
     * @return void
     */
    public static function on_order_refunded( int $order_id, int $refund_id ): void {
        global $wpdb;

        $donations_table = $wpdb->prefix . self::TABLE_DONATIONS;

        // MGR-BUG-7: Use get_results() and loop through ALL matching donations
        // (the original get_row() with LIMIT 1 only reversed the first one,
        // orphaning the rest if idempotency ever failed and multiple donations
        // existed for the same order).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $donations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$donations_table}` WHERE order_id = %d AND status IN ('pending','credited') ORDER BY id ASC",
                $order_id
            )
        );
        if ( empty( $donations ) ) {
            return;
        }

        foreach ( $donations as $donation ) {
            // Debit foundation wallet (funds leaving the foundation back to platform).
            // Wallet::debit() throws on insufficient funds — catch and log.
            try {
                LTMS_Business_Wallet::debit(
                    self::FOUNDATION_VENDOR_ID,
                    (float) $donation->total_donation,
                    sprintf( 'Reverso donación — reembolso orden #%d', $order_id ),
                    [
                        'type'        => 'donation_reversal',
                        'order_id'    => $order_id,
                        'donation_id' => (int) $donation->id,
                        'refund_id'   => $refund_id,
                        'currency'    => $donation->currency,
                    ],
                    $order_id,
                    $donation->currency,
                    sprintf( 'donation_reversal_o%d_d%d_r%d', $order_id, $donation->id, $refund_id )
                );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::critical(
                    'DONATION_REVERSAL_WALLET_FAILED',
                    sprintf(
                        'Donación #%d: fallo al reversar billetera (orden #%d, refund #%d): %s',
                        $donation->id, $order_id, $refund_id, $e->getMessage()
                    ),
                    [ 'donation_id' => $donation->id, 'order_id' => $order_id, 'refund_id' => $refund_id ]
                );
                // Do NOT mark as reversed — leave it 'credited' so admin can investigate.
                // Continue to the next donation rather than aborting the whole loop.
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $donations_table, [ 'status' => 'reversed' ], [ 'id' => $donation->id ] );

            /**
             * Fires after a donation has been reversed due to an order refund.
             *
             * @param int $donation_id Donation record ID.
             * @param int $order_id    WooCommerce order ID.
             * @param int $refund_id   WooCommerce refund ID.
             */
            do_action( 'ltms_donation_reversed', (int) $donation->id, $order_id, $refund_id );

            LTMS_Core_Logger::info(
                'DONATION_REVERSED',
                sprintf( 'Donación #%d reversada — reembolso orden #%d', $donation->id, $order_id ),
                [ 'donation_id' => $donation->id, 'order_id' => $order_id, 'refund_id' => $refund_id ]
            );
        }
    }

    /**
     * Process the monthly/weekly payout batch.
     * Transfers accumulated donations from the foundation wallet to the
     * foundation's bank account (off-platform).
     *
     * MGR-BUG-1: Calls recover_processing_donations() at the start to reconcile
     * any donations stuck in 'processing' from a previous crashed run.
     * MGR-BUG-5: Accepts an optional $admin_id (0 = cron/system) used as the
     * batch's `created_by` value for audit trail.
     *
     * @param int $admin_id Optional WP user ID of the admin triggering the payout.
     *                      Default 0 (system / cron).
     * @return int|WP_Error|null Batch ID on success, WP_Error on failure, null when nothing to do
     *                           (frequency='manual' or no pending donations).
     */
    public static function process_payout_batch( int $admin_id = 0 ) {
        global $wpdb;

        // INT-BUG-8 FIX: Removed `if ($frequency === 'manual') return null;` check.
        // When frequency='manual', the cron is NOT scheduled (activator handles this),
        // so process_payout_batch() only runs when manual_payout() calls it.
        // The old check made manual payouts impossible from both cron AND admin UI.
        $frequency = LTMS_Core_Config::get( 'ltms_donation_payout_frequency', 'monthly' );

        // MGR-BUG-1: Reconcile donations stuck in 'processing' from a previous
        // crashed run BEFORE selecting a new batch — otherwise those donations
        // would be orphaned forever (the query below only selects 'credited').
        self::recover_processing_donations();

        $now           = current_time( 'mysql', true );
        $donations_tbl = $wpdb->prefix . self::TABLE_DONATIONS;
        $payouts_tbl   = $wpdb->prefix . self::TABLE_PAYOUTS;

        // MGR-BUG-8: Select ALL credited donations (no date filter). The
        // original code only selected donations from the current month,
        // silently excluding any unpaid donations from previous months.
        // The batch's period_start/period_end will be the actual range of
        // the included donations (computed below).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $pending = $wpdb->get_results(
            "SELECT * FROM `{$donations_tbl}` WHERE status = 'credited' ORDER BY id ASC"
        );

        if ( empty( $pending ) ) {
            LTMS_Core_Logger::info( 'DONATION_PAYOUT_EMPTY', 'No hay donaciones pendientes para transferir' );
            return null;
        }

        $total       = 0.0;
        $currency    = LTMS_Core_Config::get_currency();
        $min_created = null;
        $max_created = null;
        foreach ( $pending as $d ) {
            $total    += (float) $d->total_donation;
            $currency  = $d->currency ?: $currency;
            if ( null === $min_created || $d->created_at < $min_created ) {
                $min_created = $d->created_at;
            }
            if ( null === $max_created || $d->created_at > $max_created ) {
                $max_created = $d->created_at;
            }
        }
        $total = round( $total, 2 );

        // MGR-BUG-8: period_start/end = actual range of donations included.
        // Schema columns are DATE, so we extract the date portion of the
        // min/max created_at timestamps (stored as UTC).
        $period_start = $min_created ? gmdate( 'Y-m-d', strtotime( $min_created ) ) : gmdate( 'Y-m-d', strtotime( $now ) );
        $period_end   = $max_created ? gmdate( 'Y-m-d', strtotime( $max_created ) ) : gmdate( 'Y-m-d', strtotime( $now ) );

        // MGR-BUG-10: Generate a unique batch_number with a retry loop. The
        // original wp_generate_password(4, false) only had ~10k possible values
        // per month; the UNIQUE constraint on batch_number would silently
        // break the INSERT on collision. Now uses 6 chars + checks DB existence,
        // retrying up to 3 times.
        $batch_number = '';
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            $candidate = 'DON-' . gmdate( 'Ym' ) . '-' . wp_generate_password( 6, false );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$payouts_tbl}` WHERE batch_number = %s",
                    $candidate
                )
            );
            if ( ! $exists ) {
                $batch_number = $candidate;
                break;
            }
        }
        if ( '' === $batch_number ) {
            LTMS_Core_Logger::critical(
                'DONATION_PAYOUT_BATCH_NUMBER_COLLISION',
                'No se pudo generar un batch_number único tras 3 intentos',
                [ 'attempts' => 3 ]
            );
            return new WP_Error( 'donation_payout_batch_number_collision', 'No se pudo generar un batch_number único' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $payouts_tbl,
            [
                'batch_number'      => $batch_number,
                'period_start'      => $period_start,
                'period_end'        => $period_end,
                'total_amount'      => $total,
                'currency'          => $currency,
                'transaction_count' => count( $pending ),
                'status'            => 'pending',
                'created_by'        => $admin_id, // MGR-BUG-5: 0 for cron, admin user ID for manual.
                'created_at'        => $now,
            ]
        );
        $batch_id = (int) $wpdb->insert_id;

        if ( ! $inserted || $batch_id <= 0 ) {
            LTMS_Core_Logger::critical(
                'DONATION_PAYOUT_INSERT_FAILED',
                sprintf( 'No se pudo crear el lote de payout: %s', $wpdb->last_error ),
                [ 'last_error' => $wpdb->last_error, 'total' => $total ]
            );
            return new WP_Error( 'donation_payout_insert_failed', 'Error al crear lote de payout' );
        }

        // ─── DM-1 FIX (AUDIT-BATCH2): Atomic claim to prevent double-debit ───
        // RACE CONDITION: si dos runs paralelos del cron (o cron + admin manual)
        // ejecutan process_payout_batch() concurrentemente, AMBOS ven las mismas
        // filas con status='credited' (snapshot de transacción), AMBOS insertan
        // un batch distinto, y AMBOS llaman Wallet::debit() con idempotency keys
        // `donation_payout_b{batch_id}` diferentes → la billetera se debita DOS
        // VECES por las mismas donaciones (double-debit de la fundación).
        //
        // FIX: claim atómico. Por cada donación, ejecutar
        //   UPDATE lt_donations SET status='processing', payout_batch_id=$batch_id
        //   WHERE id=$d->id AND status='credited'
        // y verificar $wpdb->rows_affected. Si 0 filas afectadas, otra run ya
        // claimed esa donación → excluir del total a debitar. Solo las donaciones
        // realmente claimed por ESTA run se incluyen en el debit del wallet.
        $claimed_donations = [];
        $claimed_total     = 0.0;
        $claimed_min       = null;
        $claimed_max       = null;
        foreach ( $pending as $d ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$donations_tbl}` SET status = 'processing', payout_batch_id = %d WHERE id = %d AND status = 'credited'",
                $batch_id,
                (int) $d->id
            ) );
            if ( $wpdb->rows_affected > 0 ) {
                $claimed_donations[] = $d;
                $claimed_total      += (float) $d->total_donation;
                if ( null === $claimed_min || $d->created_at < $claimed_min ) {
                    $claimed_min = $d->created_at;
                }
                if ( null === $claimed_max || $d->created_at > $claimed_max ) {
                    $claimed_max = $d->created_at;
                }
            }
        }

        // DM-1: Si NINGUNA donación fue claimed (otra run las tomó todas),
        // borrar el batch vacío y salir sin debitar la billetera.
        if ( empty( $claimed_donations ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $payouts_tbl, [ 'id' => $batch_id ] );
            LTMS_Core_Logger::info(
                'DONATION_PAYOUT_NO_CLAIM',
                'Lote descartado: todas las donaciones pendientes ya estaban being processed por otra run concurrente',
                [ 'batch_id_discarded' => $batch_id ]
            );
            return null;
        }

        // Re-calcular total / period_start / period_end sobre las donaciones
        // realmente claimed (pueden ser menos que las $pending originales).
        $claimed_total = round( $claimed_total, 2 );
        if ( $claimed_min ) {
            $period_start = gmdate( 'Y-m-d', strtotime( $claimed_min ) );
        }
        if ( $claimed_max ) {
            $period_end = gmdate( 'Y-m-d', strtotime( $claimed_max ) );
        }

        // Actualizar el batch row con los totales reales (los del INSERT inicial
        // eran estimaciones basadas en $pending; ahora tenemos el valor exacto).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $payouts_tbl,
            [
                'total_amount'      => $claimed_total,
                'transaction_count' => count( $claimed_donations ),
                'period_start'      => $period_start,
                'period_end'        => $period_end,
            ],
            [ 'id' => $batch_id ]
        );

        // Reasignamos $pending y $total para que el resto del flujo use solo
        // las donaciones claimed por esta run.
        $pending = $claimed_donations;
        $total   = $claimed_total;

        // Debit foundation wallet (funds leaving the platform → foundation bank account).
        $foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación Cardio Infantil' );
        try {
            LTMS_Business_Wallet::debit(
                self::FOUNDATION_VENDOR_ID,
                $total,
                sprintf( 'Transferencia a %s — lote %s', $foundation_name, $batch_number ),
                [
                    'type'         => 'donation_payout',
                    'payout_batch' => $batch_id,
                    'currency'     => $currency,
                ],
                0,
                $currency,
                sprintf( 'donation_payout_b%d', $batch_id ) // idempotency key
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::critical(
                'DONATION_PAYOUT_WALLET_FAILED',
                sprintf( 'Lote #%d: fallo al debitar billetera: %s', $batch_id, $e->getMessage() ),
                [ 'batch_id' => $batch_id, 'total' => $total, 'currency' => $currency ]
            );
            // Revert donations back to 'credited' so they can be retried next run.
            // Also clear payout_batch_id so the donation is fully detached from
            // the failed batch (MGR-BUG-6 cleanup).
            // DM-1: solo revertir las donations CLAIMED por esta run.
            foreach ( $claimed_donations as $d ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $donations_tbl,
                    [
                        'status'          => 'credited',
                        'payout_batch_id' => 0,
                    ],
                    [ 'id' => $d->id ]
                );
            }
            // MGR-BUG-6: Delete the failed batch row to avoid orphan accumulation.
            // Subsequent runs create new batches; leaving failed rows in the table
            // would pollute the admin UI and statistics forever.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $payouts_tbl, [ 'id' => $batch_id ] );
            return new WP_Error( 'donation_payout_wallet_failed', $e->getMessage() );
        }

        // MGR-BUG-2: Mark batch as 'paid' (Admin UI expects 'paid', not 'transferred').
        // The transferred_at timestamp is still recorded for audit purposes.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $payouts_tbl,
            [
                'status'         => 'paid',
                'transferred_at' => $now,
            ],
            [ 'id' => $batch_id ]
        );

        // Mark donations as paid.
        foreach ( $pending as $d ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $donations_tbl,
                [
                    'status'  => 'paid',
                    'paid_at' => $now,
                ],
                [ 'id' => $d->id ]
            );
        }

        /**
         * Fires after a payout batch has been successfully transferred.
         * Use for Alegra sync, bank reconciliation, notifications, etc.
         *
         * @param int    $batch_id Payout batch ID.
         * @param float  $total    Total amount transferred.
         * @param string $currency ISO 4217 currency code.
         */
        do_action( 'ltms_donation_payout_completed', $batch_id, $total, $currency );

        // Generate certificate (if enabled).
        if ( LTMS_Core_Config::get( 'ltms_donation_certificate_enabled', 'yes' ) === 'yes' ) {
            /**
             * Fires to request certificate generation for a completed payout batch.
             *
             * @param int $batch_id Payout batch ID.
             */
            do_action( 'ltms_donation_certificate_generate', $batch_id );
        }

        LTMS_Core_Logger::info(
            'DONATION_PAYOUT_COMPLETED',
            sprintf(
                'Lote %s: $%.2f transferido a %s (%d donaciones)',
                $batch_number, $total, $foundation_name, count( $pending )
            ),
            [
                'batch_id'   => $batch_id,
                'batch_no'   => $batch_number,
                'total'      => $total,
                'currency'   => $currency,
                'count'      => count( $pending ),
            ]
        );

        return $batch_id;
    }

    /**
     * Get donation statistics for a period.
     *
     * @param string $start_date Y-m-d (inclusive).
     * @param string $end_date   Y-m-d (inclusive).
     * @return array {
     *     @type int   $total_donations
     *     @type float $total_amount
     *     @type float $platform_donation
     *     @type float $customer_donation
     *     @type float $paid_amount
     *     @type float $pending_amount
     *     @type float $reversed_amount
     * }
     */
    public static function get_statistics( string $start_date, string $end_date ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_DONATIONS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_donations,
                    COALESCE(SUM(total_donation), 0) as total_amount,
                    COALESCE(SUM(donation_amount), 0) as platform_donation,
                    COALESCE(SUM(customer_extra_amount), 0) as customer_donation,
                    COALESCE(SUM(CASE WHEN status='paid' THEN total_donation ELSE 0 END), 0) as paid_amount,
                    COALESCE(SUM(CASE WHEN status='credited' THEN total_donation ELSE 0 END), 0) as pending_amount,
                    COALESCE(SUM(CASE WHEN status='reversed' THEN total_donation ELSE 0 END), 0) as reversed_amount
                 FROM `{$table}`
                 WHERE created_at >= %s AND created_at <= %s",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ),
            ARRAY_A
        );

        if ( ! $stats ) {
            return [
                'total_donations'   => 0,
                'total_amount'      => 0.0,
                'platform_donation' => 0.0,
                'customer_donation' => 0.0,
                'paid_amount'       => 0.0,
                'pending_amount'    => 0.0,
                'reversed_amount'   => 0.0,
            ];
        }

        return [
            'total_donations'   => (int) $stats['total_donations'],
            'total_amount'      => (float) $stats['total_amount'],
            'platform_donation' => (float) $stats['platform_donation'],
            'customer_donation' => (float) $stats['customer_donation'],
            'paid_amount'       => (float) $stats['paid_amount'],
            'pending_amount'    => (float) $stats['pending_amount'],
            'reversed_amount'   => (float) $stats['reversed_amount'],
        ];
    }

    /**
     * Get donations for a specific vendor (transparency page).
     * Excludes reversed donations.
     *
     * @param int $vendor_id Vendor ID.
     * @param int $limit     Max rows.
     * @param int $offset    Pagination offset.
     * @return array<array<string,mixed>>
     */
    public static function get_vendor_donations( int $vendor_id, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_DONATIONS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, o.post_title as order_title
                 FROM `{$table}` d
                 LEFT JOIN {$wpdb->posts} o ON d.order_id = o.ID
                 WHERE d.vendor_id = %d AND d.status != 'reversed'
                 ORDER BY d.created_at DESC
                 LIMIT %d OFFSET %d",
                $vendor_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * v2.9.31: Estadísticas de donaciones de un vendor (transparency page).
     *
     * @param int $vendor_id Vendor ID.
     * @return array{total: float, orders: int}
     */
    public static function get_vendor_donation_stats( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_DONATIONS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(total_donation), 0) AS total,
                    COUNT(*) AS orders
                 FROM `{$table}`
                 WHERE vendor_id = %d AND status != 'reversed'",
                $vendor_id
            ),
            ARRAY_A
        );

        return [
            'total'  => (float) ( $row['total'] ?? 0 ),
            'orders' => (int) ( $row['orders'] ?? 0 ),
        ];
    }

    /**
     * Manually trigger a payout (admin action).
     *
     * @param int $admin_id WP user ID of the admin triggering the payout.
     * @return int|WP_Error|null Same as process_payout_batch().
     */
    public static function manual_payout( int $admin_id ) {
        LTMS_Core_Logger::info(
            'DONATION_MANUAL_PAYOUT',
            sprintf( 'Admin #%d triggered manual payout', $admin_id ),
            [ 'admin_id' => $admin_id ]
        );
        // MGR-BUG-5: Pass $admin_id through so it lands on the batch's `created_by`
        // column for audit trail (the original code always logged 0 here).
        return self::process_payout_batch( $admin_id );
    }

    /**
     * Generate monthly donation certificates (cron handler).
     *
     * Placeholder implementation — actual PDF generation is delegated to
     * the ltms_donation_certificate_generate action listener (PDF/Certificate agent).
     *
     * @return void
     */
    public static function generate_monthly_certificates(): void {
        $last_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );
        $last_month_end   = gmdate( 'Y-m-t 23:59:59', strtotime( 'last day of last month' ) );

        global $wpdb;
        $payouts_tbl = $wpdb->prefix . self::TABLE_PAYOUTS;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $batches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM `{$payouts_tbl}` WHERE status = 'paid' AND created_at >= %s AND created_at <= %s",
                $last_month_start,
                $last_month_end
            )
        );

        if ( empty( $batches ) ) {
            LTMS_Core_Logger::info( 'DONATION_CERTIFICATE_NO_BATCHES', 'No hay lotes transferidos para certificar' );
            return;
        }

        foreach ( $batches as $batch ) {
            /**
             * Fires to request certificate generation for a single payout batch.
             *
             * @param int $batch_id Payout batch ID.
             */
            do_action( 'ltms_donation_certificate_generate', (int) $batch->id );
        }

        LTMS_Core_Logger::info(
            'DONATION_CERTIFICATES_GENERATED',
            sprintf( 'Certificates requested for %d batches', count( $batches ) ),
            [ 'batch_count' => count( $batches ) ]
        );
    }

    /**
     * Recover donations stuck in 'processing' status.
     *
     * MGR-BUG-1: If a cron or admin-triggered payout crashes AFTER
     * Wallet::debit() but BEFORE the donation status is updated to 'paid',
     * the donation row is orphaned in 'processing' while the funds have
     * already left the foundation wallet. The next process_payout_batch()
     * run only selects 'credited' donations, so 'processing' donations are
     * silently skipped forever.
     *
     * This method (called from the ltms_donation_recover_processing cron
     * every 15 min, and at the START of process_payout_batch()) reconciles
     * those orphaned rows:
     *   - Finds donations with status='processing' older than 10 minutes.
     *   - Checks the wallet transactions table for the payout debit (via the
     *     idempotency key `donation_payout_b{batch_id}` stored as `reference`).
     *   - If the debit happened: marks the donation as 'paid' and ensures the
     *     batch row is 'paid' (so the admin UI / certificate generator can see it).
     *   - If the debit didn't happen: reverts the donation to 'credited' (and
     *     clears payout_batch_id) so it is picked up by the next payout cycle.
     *
     * @return array{recovered_paid:int,reverted:int,total:int} Stats for logging/testing.
     */
    public static function recover_processing_donations(): array {
        global $wpdb;

        $donations_tbl = $wpdb->prefix . self::TABLE_DONATIONS;
        $payouts_tbl   = $wpdb->prefix . self::TABLE_PAYOUTS;
        $tx_tbl        = $wpdb->prefix . 'lt_wallet_transactions';

        // Find donations stuck in 'processing' for more than 10 minutes.
        // The 10-minute grace window avoids racing an in-flight payout.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $stuck = $wpdb->get_results(
            "SELECT * FROM `{$donations_tbl}` WHERE status = 'processing' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) ORDER BY id ASC LIMIT 100"
        );

        $recovered_paid = 0;
        $reverted       = 0;
        $now            = current_time( 'mysql', true );

        if ( empty( $stuck ) ) {
            return [
                'recovered_paid' => 0,
                'reverted'       => 0,
                'total'          => 0,
            ];
        }

        foreach ( $stuck as $donation ) {
            $batch_id = (int) $donation->payout_batch_id;

            if ( $batch_id <= 0 ) {
                // No batch assignment — defensive: revert to 'credited' so the
                // next payout picks it up.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $donations_tbl,
                    [
                        'status'          => 'credited',
                        'payout_batch_id' => 0,
                    ],
                    [ 'id' => $donation->id ]
                );
                $reverted++;
                continue;
            }

            // Check if the wallet debit actually happened via idempotency key.
            $idempotency_key = sprintf( 'donation_payout_b%d', $batch_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $debit_tx_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$tx_tbl}` WHERE `reference` = %s LIMIT 1",
                    $idempotency_key
                )
            );

            if ( $debit_tx_id > 0 ) {
                // Debit happened → mark donation as 'paid'.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $donations_tbl,
                    [
                        'status'  => 'paid',
                        'paid_at' => $now,
                    ],
                    [ 'id' => $donation->id ]
                );
                // Also reconcile the batch row if it is still 'pending' or 'failed'
                // (MGR-BUG-6 now deletes failed rows, but defensive in case of legacy).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
                $wpdb->update(
                    $payouts_tbl,
                    [
                        'status'         => 'paid',
                        'transferred_at' => $now,
                    ],
                    [
                        'id'     => $batch_id,
                        'status' => 'pending',
                    ]
                );
                $recovered_paid++;
            } else {
                // Debit didn't happen → revert donation to 'credited' for the
                // next payout cycle, and detach it from this (presumably failed)
                // batch.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $donations_tbl,
                    [
                        'status'          => 'credited',
                        'payout_batch_id' => 0,
                    ],
                    [ 'id' => $donation->id ]
                );
                $reverted++;
            }
        }

        LTMS_Core_Logger::info(
            'DONATION_RECOVER_PROCESSING',
            sprintf(
                'Recuperadas %d donaciones en "processing" (%d marcadas pagadas, %d revertidas a credited)',
                count( $stuck ),
                $recovered_paid,
                $reverted
            ),
            [
                'total'          => count( $stuck ),
                'recovered_paid' => $recovered_paid,
                'reverted'       => $reverted,
            ]
        );

        return [
            'recovered_paid' => $recovered_paid,
            'reverted'       => $reverted,
            'total'          => count( $stuck ),
        ];
    }
}
