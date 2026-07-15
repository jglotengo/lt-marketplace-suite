<?php
/**
 * LTMS Core Cron Manager
 *
 * 6 cron jobs del módulo de reservas. Todos idempotentes con try/catch.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Cron_Manager
 */
class LTMS_Core_Cron_Manager {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'ltms_cron_cleanup_pending_bookings', [ self::class, 'cleanup_pending_bookings' ] );
        add_action( 'ltms_cron_send_checkin_reminders',   [ self::class, 'send_checkin_reminders' ] );
        add_action( 'ltms_cron_balance_due_reminders',    [ self::class, 'send_balance_due_reminders' ] );
        add_action( 'ltms_cron_auto_checkout',            [ self::class, 'auto_checkout' ] );
        // INT-BUG-7 FIX: removed 'ltms_cron_check_rnt_expiry' action registration.
        // The daily 'ltms_check_rnt_expiry' cron in class-ltms-kernel.php is the canonical one.
        add_action( 'ltms_cron_release_booking_deposits', [ self::class, 'release_booking_deposits' ] );
        add_action( 'ltms_update_tracking',               [ self::class, 'update_tracking' ] );
        add_action( 'ltms_sync_siigo',                    [ self::class, 'sync_siigo' ] );
        add_action( 'ltms_integrity_check',               [ self::class, 'integrity_check' ] );
        add_action( 'ltms_clean_logs',                    [ self::class, 'clean_logs' ] );
        add_action( 'ltms_process_job_queue',             [ self::class, 'process_job_queue' ] );
        add_action( 'ltms_send_notifications',            [ self::class, 'send_notifications' ] );

        // M-44: listeners para hooks disparados sin receptor registrado
        add_action( 'ltms_dispatch_notification',             [ self::class, 'handle_dispatch_notification' ], 10, 1 );
        add_action( 'ltms_sync_siigo_invoice',                [ self::class, 'handle_sync_siigo_invoice'    ], 10, 1 );
        add_action( 'ltms_booking_created',                   [ self::class, 'handle_booking_created'       ], 10, 2 );
        add_action( 'ltms_booking_confirmed',                 [ self::class, 'handle_booking_confirmed'     ], 10, 1 );
        add_action( 'ltms_booking_cancelled',                 [ self::class, 'handle_booking_cancelled'     ], 10, 3 );
        add_action( 'ltms_booking_deposit_released',          [ self::class, 'handle_deposit_released'      ], 10, 2 );
        add_action( 'ltms_booking_refund_processed',          [ self::class, 'handle_booking_refund'        ], 10, 3 );
        add_action( 'ltms_send_booking_checkin_reminder',     [ self::class, 'handle_checkin_reminder'      ], 10, 1 );
        add_action( 'ltms_send_booking_balance_reminder',     [ self::class, 'handle_balance_reminder'      ], 10, 1 );
        add_action( 'ltms_rnt_approved',                      [ self::class, 'handle_rnt_approved'          ], 10, 1 );
        add_action( 'ltms_rnt_rejected',                      [ self::class, 'handle_rnt_rejected'          ], 10, 2 );
        add_action( 'ltms_rnt_expired',                       [ self::class, 'handle_rnt_expired'           ], 10, 2 );

        // CRON-CRASH-1 FIX (Task 57-E): Recover stuck cron locks on every
        // admin page load. Low overhead (single SELECT on options table) and
        // ensures that if a cron process dies (PHP fatal, OOM, DB timeout),
        // its lock is force-released within ~30 minutes instead of waiting
        // for the transient TTL to expire (which can be up to 1 hour).
        add_action( 'admin_init', [ self::class, 'recover_interrupted_crons' ] );

        self::schedule_jobs();
    }

    private static function schedule_jobs(): void {
        $jobs = [
            'ltms_cron_cleanup_pending_bookings' => [ 'every_30_minutes', null ],
            'ltms_cron_send_checkin_reminders'   => [ 'daily',            '10:00:00' ],
            'ltms_cron_balance_due_reminders'    => [ 'daily',            '09:00:00' ],
            'ltms_cron_auto_checkout'            => [ 'daily',            '12:00:00' ],
            // INT-BUG-7 FIX: removed duplicate 'ltms_cron_check_rnt_expiry' (weekly).
            // The canonical cron is 'ltms_check_rnt_expiry' (daily), registered in
            // class-ltms-kernel.php and class-ltms-activator.php (Task 55-D INT-BUG-4 fix).
            // Keeping both caused the same handler to fire on two different schedules.
            'ltms_cron_release_booking_deposits' => [ 'daily',            '08:00:00' ],
        ];
        foreach ( $jobs as $hook => $config ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $ts = $config[1] ? strtotime( gmdate( 'Y-m-d' ) . ' ' . $config[1] ) : time();
                wp_schedule_event( $ts, $config[0], $hook );
            }
        }
    }

    // ── ME-1/ME-2/CRON-CRASH-1 (Task 57-E): Locking + recovery helpers ────

    /**
     * Acquire a cron lock for the given hook name.
     *
     * ME-1: prevents overlapping runs when a long cron job is already in
     *       progress (WP's native 60s lock is insufficient for batches that
     *       take several minutes).
     * ME-2: if a lock exists AND is older than 1 hour, the cron is considered
     *       stuck (likely a PHP fatal or DB timeout) and the lock is
     *       force-released so this run can proceed.
     *
     * Two transients are used:
     *   - `ltms_cron_lock_<hook>`       — 30 min TTL, presence = "running".
     *   - `ltms_cron_lock_<hook>_time`  — 1 hour TTL, stores acquisition timestamp.
     * The timestamp transient has a LONGER TTL than the lock itself so that
     * we can detect "stuck" crons AFTER the lock transient has expired
     * naturally but BEFORE the timestamp transient expires.
     *
     * @param string $hook_name Cron hook name (e.g. 'ltms_clean_logs').
     * @return bool True if the lock was acquired, false if another run is in progress.
     */
    private static function acquire_lock( string $hook_name ): bool {
        $lock_key      = 'ltms_cron_lock_' . $hook_name;
        $lock_time_key = $lock_key . '_time';

        $lock_value = get_transient( $lock_key );
        $lock_time  = (int) get_transient( $lock_time_key );

        if ( $lock_value ) {
            // ME-2: Force-release locks older than 1 hour (stuck cron).
            if ( $lock_time && ( time() - $lock_time ) > HOUR_IN_SECONDS ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'CRON_STUCK',
                        sprintf( 'Cron stuck >1h, force-releasing: %s', $hook_name )
                    );
                } else {
                    error_log( 'LTMS Cron: stuck >1h, force-releasing: ' . $hook_name );
                }
                delete_transient( $lock_key );
                delete_transient( $lock_time_key );
                // Fall through and re-acquire below.
            } else {
                // Another run is in progress — skip this tick.
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info(
                        'CRON_SKIPPED',
                        sprintf( 'Cron already running: %s', $hook_name )
                    );
                }
                return false;
            }
        }

        set_transient( $lock_key, true, 30 * MINUTE_IN_SECONDS );
        set_transient( $lock_time_key, time(), HOUR_IN_SECONDS );
        return true;
    }

    /**
     * Release the cron lock for the given hook name.
     *
     * Called from a `finally` block so the lock is always released even if
     * the cron job throws an uncaught exception.
     *
     * @param string $hook_name Cron hook name.
     * @return void
     */
    private static function release_lock( string $hook_name ): void {
        $lock_key = 'ltms_cron_lock_' . $hook_name;
        delete_transient( $lock_key );
        delete_transient( $lock_key . '_time' );
    }

    /**
     * CRON-CRASH-1 FIX (Task 57-E): Recover interrupted crons.
     *
     * Scans all `ltms_cron_lock_*` transients in the options table and
     * force-releases any whose stored timestamp is older than 30 minutes
     * (stuck). Logs a CRITICAL alert for each. Hooked to `admin_init` so it
     * runs on every admin page load — low overhead (single indexed SELECT)
     * and ensures crashed cron processes don't block subsequent runs.
     *
     * Why 30 min for recovery vs 1 hour for in-flight force-acquire (ME-2)?
     * The lock transient itself has a 30 min TTL. After 30 min, the lock
     * expires naturally — but the lock_time transient (1h TTL) survives, so
     * we can still detect "this cron ran 31+ min ago and never released".
     * Recovery at 30 min catches crons that crashed after their lock expired
     * naturally but before the lock_time transient expired.
     *
     * @return void
     */
    public static function recover_interrupted_crons(): void {
        global $wpdb;

        // Find all lock-related transients (NOT their timeout rows).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $lock_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND option_name NOT LIKE %s",
                $wpdb->esc_like( '_transient_ltms_cron_lock_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_ltms_cron_lock_' ) . '%'
            )
        );

        if ( ! $lock_rows ) {
            return;
        }

        $stuck_threshold = 30 * MINUTE_IN_SECONDS;
        $now             = time();
        $processed       = []; // dedupe by hook name.

        foreach ( $lock_rows as $option_name ) {
            // option_name is '_transient_ltms_cron_lock_<hook>' or
            // '_transient_ltms_cron_lock_<hook>_time'.
            $suffix = substr( $option_name, strlen( '_transient_ltms_cron_lock_' ) );
            $hook_name = ( substr( $suffix, -5 ) === '_time' )
                ? substr( $suffix, 0, -5 )
                : $suffix;

            if ( isset( $processed[ $hook_name ] ) ) {
                continue;
            }
            $processed[ $hook_name ] = true;

            // The lock_time transient stores the acquisition timestamp.
            $lock_time = (int) get_transient( 'ltms_cron_lock_' . $hook_name . '_time' );
            if ( ! $lock_time ) {
                continue;
            }

            $age = $now - $lock_time;
            if ( $age > $stuck_threshold ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'CRON_STUCK',
                        sprintf(
                            'Cron lock for "%s" stuck for %d min (>30 min threshold), force-releasing.',
                            $hook_name,
                            (int) ( $age / 60 )
                        )
                    );
                } else {
                    error_log( sprintf(
                        'LTMS Cron: stuck lock for "%s" released after %d min',
                        $hook_name,
                        (int) ( $age / 60 )
                    ) );
                }
                delete_transient( 'ltms_cron_lock_' . $hook_name );
                delete_transient( 'ltms_cron_lock_' . $hook_name . '_time' );
            }
        }
    }

    // ── Cron job handlers ───────────────────────────────────────────────

    /** Cron 1: Libera slots de reservas pending > 30 min sin pago. */
    public static function cleanup_pending_bookings(): void {
        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        if ( ! self::acquire_lock( 'ltms_cron_cleanup_pending_bookings' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        try {
            if ( class_exists( 'LTMS_Booking_Manager' ) ) {
                LTMS_Booking_Manager::cleanup_pending_bookings();
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: cleanup_pending_bookings — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_cron_cleanup_pending_bookings' );
        }
    }

    /** Cron 2: Recordatorios de check-in (48h antes). */
    public static function send_checkin_reminders(): void {
        global $wpdb;
        try {
            $hours       = (int) LTMS_Core_Config::get( 'ltms_booking_checkin_reminder_hours', 48 );
            $target_date = gmdate( 'Y-m-d', strtotime( "+{$hours} hours" ) );
            $bookings    = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_bookings WHERE checkin_date = %s AND status = 'confirmed'", $target_date ),
                ARRAY_A
            ) ?: [];
            foreach ( $bookings as $booking ) {
                do_action( 'ltms_send_booking_checkin_reminder', $booking );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: send_checkin_reminders — ' . $e->getMessage() );
        }
    }

    /** Cron 3: Saldo pendiente que vence en 7 días. */
    public static function balance_due_reminders(): void {
        global $wpdb;
        try {
            $bookings = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}lt_bookings WHERE payment_mode = 'deposit' AND status = 'confirmed' AND balance_amount > 0 AND checkin_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
                ARRAY_A
            ) ?: [];
            foreach ( $bookings as $booking ) {
                do_action( 'ltms_send_booking_balance_reminder', $booking );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: balance_due_reminders — ' . $e->getMessage() );
        }
    }

    /** Cron 4: Auto check-out para reservas pasadas. */
    public static function auto_checkout(): void {
        global $wpdb;
        try {
            // v2.9.133 CYBER-AUDIT: this query has NO user input — all values are
            // hardcoded SQL constants (status strings, CURDATE(), NOW()). Safe.
            $wpdb->query( "UPDATE {$wpdb->prefix}lt_bookings SET status = 'checked_out', updated_at = NOW() WHERE checkout_date < CURDATE() AND status = 'checked_in'" );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: auto_checkout — ' . $e->getMessage() );
        }
    }

    /** Cron 5: Verifica vencimiento de RNT. */
    public static function check_rnt_expiry(): void {
        try {
            if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
                LTMS_Business_Tourism_Compliance::check_rnt_expiry();
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: check_rnt_expiry — ' . $e->getMessage() );
        }
    }

    /** Cron 8: Sincroniza facturas pendientes con Siigo. */
    public static function sync_siigo(): void {
        global $wpdb;
        try {
            // Dispatch pending ltms_sync_siigo_invoice jobs from job queue
            $jobs = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}lt_job_queue
                 WHERE hook = 'ltms_sync_siigo_invoice' AND status = 'pending'
                 ORDER BY priority ASC, scheduled_at ASC LIMIT 20",
                ARRAY_A
            ) ?: [];

            foreach ( $jobs as $job ) {
                try {
                    // M-116: Atomic claim for siigo sync jobs
                    $claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        "UPDATE `{$wpdb->prefix}lt_job_queue`
                         SET status = 'processing', started_at = %s, attempts = attempts + 1
                         WHERE id = %d AND status = 'pending'",
                        current_time( 'mysql' ),
                        (int) $job['id']
                    ) );
                    if ( ! $claimed ) {
                        continue;
                    }
                    $args = $job['args'] ? json_decode( $job['args'], true ) : [];
                    do_action( 'ltms_sync_siigo_invoice', $args );
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [ 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                } catch ( \Throwable $inner ) {
                    $max = (int) $job['max_attempts'];
                    $att = (int) $job['attempts'] + 1;
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [
                            'status'        => $att >= $max ? 'failed' : 'pending',
                            'error_message' => $inner->getMessage(),
                        ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    error_log( 'LTMS Cron: sync_siigo job #' . $job['id'] . ' — ' . $inner->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: sync_siigo — ' . $e->getMessage() );
        }
    }

    /** Cron 9: Verifica integridad de comisiones y wallets vs transacciones WC. */
    public static function integrity_check(): void {
        global $wpdb;

        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        if ( ! self::acquire_lock( 'ltms_integrity_check' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        try {
            // CRON-CRASH-2 (Task 57-E): Resume capability for batch integrity
            // check. If a previous run crashed mid-batch, the progress
            // transient stores the last commission ID processed; we resume
            // from there instead of re-querying from scratch.
            $progress_key = 'ltms_cron_progress_ltms_integrity_check';
            $last_id      = (int) get_transient( $progress_key );

            // Detectar comisiones huérfanas (orden cancelada pero comisión pendiente).
            // ME-4 (Task 57-E): prepared statement for WPCS compliance.
            // Process in batches of 200 with `id > $last_id` ordering for resume.
            $orphaned = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT c.id FROM {$wpdb->prefix}lt_commissions c
                     LEFT JOIN {$wpdb->posts} p ON p.ID = c.order_id
                     WHERE c.status = 'pending'
                       AND c.id > %d
                       AND ( p.ID IS NULL OR p.post_status IN ('cancelled','trash','wc-cancelled','wc-failed') )
                     ORDER BY c.id ASC LIMIT 200",
                    $last_id
                )
            ) ?: [];

            if ( empty( $orphaned ) ) {
                // Batch complete — clear progress so next run starts fresh.
                delete_transient( $progress_key );
                LTMS_Core_Logger::info( 'INTEGRITY_CHECK', 'Sin anomalías detectadas.' );
                return;
            }

            foreach ( $orphaned as $commission_id ) {
                $commission_id = (int) $commission_id;
                $wpdb->update(
                    $wpdb->prefix . 'lt_commissions',
                    [ 'status' => 'cancelled' ],
                    [ 'id' => $commission_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                // Record progress so a crash before batch end can resume.
                set_transient( $progress_key, $commission_id, DAY_IN_SECONDS );
            }

            LTMS_Core_Logger::warning(
                'INTEGRITY_CHECK',
                sprintf( '%d comisiones huérfanas marcadas como canceladas.', count( $orphaned ) )
            );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: integrity_check — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_integrity_check' );
        }
    }

    /** Cron 10: Elimina logs antiguos según la política de retención configurada. */
    public static function clean_logs(): void {
        global $wpdb;

        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        // clean_logs runs a single DELETE but on tables that can grow to
        // millions of rows; lock prevents overlap with concurrent runs.
        if ( ! self::acquire_lock( 'ltms_clean_logs' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        try {
            $retention_days = (int) LTMS_Core_Config::get( 'ltms_log_retention_days', 90 );
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}lt_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $retention_days
                )
            );
            LTMS_Core_Logger::info(
                'CLEAN_LOGS',
                sprintf( 'Audit logs eliminados: %d (retención: %d días).', (int) $deleted, $retention_days )
            );

            // RET-BUG-1 FIX: Per-level retention for lt_logs (SAGRILAFT compliance).
            // SECURITY logs must be retained 7 years; DEBUG only 7 days, etc.
            $per_level_retention = [
                'DEBUG'    => (int) LTMS_Core_Config::get( 'ltms_log_retention_debug', 7 ),
                'INFO'     => (int) LTMS_Core_Config::get( 'ltms_log_retention_info', 30 ),
                'WARNING'  => (int) LTMS_Core_Config::get( 'ltms_log_retention_warning', 90 ),
                'ERROR'    => (int) LTMS_Core_Config::get( 'ltms_log_retention_error', 365 ),
                'CRITICAL' => (int) LTMS_Core_Config::get( 'ltms_log_retention_critical', 1825 ), // 5 years
                'SECURITY' => (int) LTMS_Core_Config::get( 'ltms_log_retention_security', 2555 ), // 7 years (SAGRILAFT)
            ];

            $logs_table = $wpdb->prefix . 'lt_logs';
            // Check if lt_logs table exists (graceful degradation)
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
            if ( $table_exists ) {
                $total_logs_deleted = 0;
                foreach ( $per_level_retention as $level => $days ) {
                    $result = $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$logs_table} WHERE level = %s AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                            $level,
                            $days
                        )
                    );
                    if ( $result !== false ) {
                        $total_logs_deleted += (int) $result;
                    }
                }
                if ( $total_logs_deleted > 0 ) {
                    LTMS_Core_Logger::info(
                        'CLEAN_LOGS_PER_LEVEL',
                        sprintf( 'Logs eliminados por nivel: %d (DEBUG 7d, INFO 30d, WARN 90d, ERR 365d, CRIT 5y, SEC 7y).', $total_logs_deleted )
                    );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: clean_logs — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_clean_logs' );
        }
    }

    /** Cron 11: Procesa la cola de jobs pendientes (genérico). */
    public static function process_job_queue(): void {
        global $wpdb;

        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        if ( ! self::acquire_lock( 'ltms_process_job_queue' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        try {
            $jobs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}lt_job_queue
                     WHERE status = 'pending' AND scheduled_at <= %s
                     ORDER BY priority ASC, scheduled_at ASC LIMIT 10",
                    current_time( 'mysql' )
                ),
                ARRAY_A
            ) ?: [];

            foreach ( $jobs as $job ) {
                // Skip siigo jobs — handled by sync_siigo cron
                if ( 'ltms_sync_siigo_invoice' === $job['hook'] ) {
                    continue;
                }
                try {
                    // M-116: Atomic claim — only proceed if we can transition from pending→processing.
                    // Prevents double-execution when two cron workers run concurrently.
                    $claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        "UPDATE `{$wpdb->prefix}lt_job_queue`
                         SET status = 'processing', started_at = %s, attempts = attempts + 1
                         WHERE id = %d AND status = 'pending'",
                        current_time( 'mysql' ),
                        (int) $job['id']
                    ) );
                    if ( ! $claimed ) {
                        continue; // Another worker already claimed this job
                    }
                    $args = $job['args'] ? json_decode( $job['args'], true ) : [];
                    do_action( $job['hook'], $args );
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [ 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                } catch ( \Throwable $inner ) {
                    $max = (int) $job['max_attempts'];
                    $att = (int) $job['attempts'] + 1;
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [
                            'status'        => $att >= $max ? 'failed' : 'pending',
                            'error_message' => $inner->getMessage(),
                        ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    error_log( 'LTMS Cron: job_queue hook=' . $job['hook'] . ' — ' . $inner->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: process_job_queue — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_process_job_queue' );
        }
    }

    /**
     * Cron 12: Envía notificaciones en cola (email/push/whatsapp pendientes).
     *
     * La tabla lt_notifications no tiene status — identifica notificaciones
     * no enviadas por sent_at IS NULL y las despacha vía do_action.
     */
    public static function send_notifications(): void {
        global $wpdb;

        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        if ( ! self::acquire_lock( 'ltms_send_notifications' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        try {
            // CRON-CRASH-2 (Task 57-E): Resume capability. If a previous run
            // crashed mid-batch, the progress transient stores the last
            // notification ID processed; we resume from there instead of
            // re-querying from scratch.
            $progress_key = 'ltms_cron_progress_ltms_send_notifications';
            $last_id      = (int) get_transient( $progress_key );

            // Notificaciones aún no enviadas (sent_at IS NULL) creadas hace > 1 min
            // para evitar doble-envío en requests concurrentes.
            $notifications = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}lt_notifications
                     WHERE sent_at IS NULL
                       AND channel != 'inapp'
                       AND id > %d
                       AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                     ORDER BY id ASC LIMIT 50",
                    $last_id
                ),
                ARRAY_A
            ) ?: [];

            if ( empty( $notifications ) ) {
                // Batch complete — clear progress so next run starts fresh.
                delete_transient( $progress_key );
                return;
            }

            foreach ( $notifications as $notif ) {
                try {
                    // Marcar sent_at optimistamente antes de despachar
                    $wpdb->update(
                        $wpdb->prefix . 'lt_notifications',
                        [ 'sent_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $notif['id'] ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                    do_action( 'ltms_dispatch_notification', $notif );
                    // Record progress so a crash before batch end can resume.
                    set_transient( $progress_key, (int) $notif['id'], DAY_IN_SECONDS );
                } catch ( \Throwable $inner ) {
                    // Revertir sent_at para reintento en el próximo ciclo
                    $wpdb->update(
                        $wpdb->prefix . 'lt_notifications',
                        [ 'sent_at' => null ],
                        [ 'id' => (int) $notif['id'] ],
                        [ null ],
                        [ '%d' ]
                    );
                    error_log( 'LTMS Cron: send_notifications notif #' . $notif['id'] . ' — ' . $inner->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: send_notifications — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_send_notifications' );
        }
    }

    /** Cron 7: Actualiza el estado de los envíos en tránsito consultando Heka y Aveonline.
     *
     * Busca órdenes WooCommerce con meta '_ltms_aveonline_tracking' o
     * '_ltms_absorbed_shipping_provider' = 'heka' que sigan en estado 'processing'
     * o 'shipped', consulta la API correspondiente y actualiza la orden.
     */
    public static function update_tracking(): void {
        try {
            if ( ! function_exists( 'wc_get_orders' ) ) {
                return;
            }

            // TS-BUG-3: Process in batches of 50 with pagination so orders
            // beyond the first page are not silently ignored.
            $page_size = 50;
            $offset    = 0;

            while ( true ) {
                $orders = wc_get_orders( [
                    'status'     => [ 'processing', 'wc-shipped' ],
                    'limit'      => $page_size,
                    'offset'     => $offset,
                    'meta_query' => [
                        'relation' => 'OR',
                        [
                            'key'     => '_ltms_aveonline_tracking',
                            'compare' => 'EXISTS',
                        ],
                        [
                            'key'     => '_ltms_absorbed_shipping_provider',
                            'value'   => 'heka',
                        ],
                    ],
                ] );

                if ( empty( $orders ) ) {
                    break;
                }

                foreach ( $orders as $order ) {
                    try {
                        self::process_tracking_for_order( $order );
                    } catch ( \Throwable $inner ) {
                        error_log( 'LTMS Cron: update_tracking order #' . $order->get_id() . ' — ' . $inner->getMessage() );
                    }
                }

                // If we received fewer than the page size, we've reached the end.
                if ( count( $orders ) < $page_size ) {
                    break;
                }
                $offset += $page_size;
            }

            // TS-BUG-5: Detect shipments stuck >30 days in transit and log critical alert.
            self::detect_stuck_shipments();
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: update_tracking — ' . $e->getMessage() );
        }
    }

    /**
     * Processes a single order's tracking update.
     *
     * Extracted from update_tracking() so the main loop stays readable while
     * each order is handled in its own try/catch boundary.
     *
     * Fixes applied here:
     *   - TS-BUG-2: idempotency (skip when status unchanged).
     *   - TS-BUG-1: fire ltms_shipping_delivered/_returned/_lost/_failed
     *               for any carrier, not just Uber webhook.
     *   - TS-BUG-4: send customer + vendor email on status change.
     *
     * @param \WC_Order $order Order object.
     * @return void
     */
    private static function process_tracking_for_order( $order ): void {
        $provider        = $order->get_meta( '_ltms_absorbed_shipping_provider' );
        $aveonline_track = $order->get_meta( '_ltms_aveonline_tracking' );

        $new_status = '';
        $carrier    = '';
        $tracking_n = '';

        if ( $aveonline_track && class_exists( 'LTMS_Api_Factory' ) ) {
            /** @var \LTMS_Api_Aveonline $api */
            $api        = LTMS_Api_Factory::get( 'aveonline' );
            $result     = $api->track_shipment( $aveonline_track );
            $new_status = (string) ( $result['status'] ?? '' );
            $carrier    = 'aveonline';
            $tracking_n = $aveonline_track;
        } elseif ( 'heka' === $provider && class_exists( 'LTMS_Api_Factory' ) ) {
            $tracking_number = $order->get_meta( '_ltms_heka_tracking_number' );
            if ( $tracking_number ) {
                /** @var \LTMS_Api_Heka $api */
                $api        = LTMS_Api_Factory::get( 'heka' );
                $result     = $api->track_shipment( $tracking_number );
                $new_status = (string) ( $result['status'] ?? '' );
                $carrier    = 'heka';
                $tracking_n = $tracking_number;
            }
        }

        if ( '' === $new_status ) {
            return;
        }

        $order_id        = $order->get_id();
        $previous_status = (string) get_post_meta( $order_id, '_ltms_tracking_status', true );

        // TS-BUG-2: Idempotency — skip the order note, status transition,
        // lifecycle action and emails if the status has not changed since
        // the previous run. Prevents duplicate order notes every 30 min.
        if ( $previous_status === $new_status ) {
            return;
        }

        update_post_meta( $order_id, '_ltms_tracking_status', $new_status );
        update_post_meta( $order_id, '_ltms_tracking_updated_at', current_time( 'mysql', true ) );

        $order->add_order_note(
            sprintf(
                /* translators: 1: carrier name, 2: tracking number, 3: new status */
                __( '%1$s tracking %2$s — estado actualizado: %3$s', 'ltms' ),
                ucfirst( $carrier ),
                $tracking_n,
                sanitize_text_field( $new_status )
            )
        );

        // TS-BUG-1: Fire lifecycle actions for every carrier (Aveonline, Heka,
        // Deprisa), not just the Uber webhook. Consumer-protection holds for
        // cron-polled shipments are now released on delivery.
        $status_lower      = strtolower( $new_status );
        $delivered_markers = [ 'delivered', 'entregado', 'completed', 'delivered_to_recipient' ];
        $returned_markers  = [ 'returned', 'devuelto', 'return_to_sender' ];
        $lost_markers      = [ 'lost', 'perdido' ];
        $failed_markers    = [ 'delivery_failed', 'failed', 'no_entregado', 'undeliverable' ];

        if ( in_array( $status_lower, array_map( 'strtolower', $delivered_markers ), true ) ) {
            $already_fired = get_post_meta( $order_id, '_ltms_shipping_delivered_fired', true );
            if ( ! $already_fired ) {
                update_post_meta( $order_id, '_ltms_shipping_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
                do_action( 'ltms_shipping_delivered', $order_id, $carrier );
                update_post_meta( $order_id, '_ltms_shipping_delivered_fired', true );
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info(
                        'TRACKING_DELIVERED',
                        sprintf( 'Order #%d marked delivered via cron (%s)', $order_id, $carrier )
                    );
                }
                if ( 'completed' !== $order->get_status() ) {
                    $order->update_status(
                        'completed',
                        sprintf(
                            /* translators: %s: carrier name */
                            __( 'Entregado vía %s.', 'ltms' ),
                            ucfirst( $carrier )
                        )
                    );
                }
            }
        } elseif ( in_array( $status_lower, array_map( 'strtolower', $returned_markers ), true ) ) {
            do_action( 'ltms_shipping_returned', $order_id, $carrier );
        } elseif ( in_array( $status_lower, array_map( 'strtolower', $lost_markers ), true ) ) {
            do_action( 'ltms_shipping_lost', $order_id, $carrier );
        } elseif ( in_array( $status_lower, array_map( 'strtolower', $failed_markers ), true ) ) {
            do_action( 'ltms_shipping_failed', $order_id, $carrier );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'TRACKING_STATUS_CHANGED',
                sprintf(
                    'Order #%d tracking changed: %s -> %s (%s)',
                    $order_id,
                    $previous_status ?: '(none)',
                    $new_status,
                    $carrier
                ),
                [
                    'order_id'        => $order_id,
                    'carrier'         => $carrier,
                    'previous_status' => $previous_status,
                    'new_status'      => $new_status,
                ]
            );
        }

        // TS-BUG-4: Notify customer and vendor on status change.
        self::notify_tracking_change( $order, $new_status, $carrier );

        $order->save();
    }

    /**
     * Sends customer + vendor email notifications when tracking status changes.
     *
     * TS-BUG-4: previously the cron-pulled status updates were silent —
     * neither customers nor vendors learned about delivery / failed / returned
     * transitions until they manually checked the order.
     *
     * @param \WC_Order $order      Order object.
     * @param string    $new_status New tracking status (raw carrier string).
     * @param string    $carrier    Carrier slug (aveonline|heka).
     * @return void
     */
    private static function notify_tracking_change( $order, string $new_status, string $carrier ): void {
        $order_id = $order->get_id();
        $subject  = sprintf(
            /* translators: 1: site name, 2: order id */
            __( '[%1$s] Pedido #%2$d — actualización de envío', 'ltms' ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            $order_id
        );

        $body = sprintf(
            /* translators: 1: order id, 2: carrier, 3: new status */
            __( "Actualización del envío del pedido #%1\$d.\nTransportadora: %2\$s\nEstado: %3\$s\n", 'ltms' ),
            $order_id,
            ucfirst( $carrier ),
            $new_status
        );

        // Notify customer.
        $customer_email = '';
        $customer_id    = $order->get_customer_id();
        if ( $customer_id ) {
            $customer = get_userdata( $customer_id );
            if ( $customer && ! empty( $customer->user_email ) ) {
                $customer_email = $customer->user_email;
            }
        }
        if ( ! $customer_email ) {
            $customer_email = (string) $order->get_billing_email();
        }
        if ( $customer_email ) {
            wp_mail( $customer_email, $subject, $body );
        }

        // Notify vendor.
        $vendor_id = (int) get_post_meta( $order_id, '_ltms_vendor_id', true );
        if ( $vendor_id ) {
            $vendor = get_userdata( $vendor_id );
            if ( $vendor && ! empty( $vendor->user_email ) ) {
                wp_mail( $vendor->user_email, $subject, $body );
            }
        }
    }

    /**
     * Detects shipments stuck >30 days in transit and logs a critical alert.
     *
     * TS-BUG-5: previously no stuck shipment detection — orders lost in
     * transit were silently unflagged, holding vendor payouts indefinitely.
     *
     * Uses the `_ltms_tracking_status` / `_ltms_tracking_updated_at` meta
     * keys written by process_tracking_for_order(). "In transit" is matched
     * case-insensitively against a set of common carrier synonyms because
     * raw carrier strings are stored (status normalization is ME-3, out of
     * scope here).
     *
     * @return void
     */
    private static function detect_stuck_shipments(): void {
        global $wpdb;

        $in_transit = [
            'in_transit',
            'en_camino',
            'en_transito',
            'enviado',
            'shipped',
            'picked_up',
            'recogido',
            'en_proceso_de_entrega',
            'out_for_delivery',
        ];

        $threshold = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $placehold = implode( ',', array_fill( 0, count( $in_transit ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placehold is computed from a static whitelist
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_status
                 ON pm_status.post_id = p.ID
                AND pm_status.meta_key = '_ltms_tracking_status'
                AND LOWER(pm_status.meta_value) IN ( {$placehold} )
             INNER JOIN {$wpdb->postmeta} pm_updated
                 ON pm_updated.post_id = p.ID
                AND pm_updated.meta_key = '_ltms_tracking_updated_at'
                AND pm_updated.meta_value < %s
             WHERE p.post_type = %s",
            array_merge( $in_transit, [ $threshold, 'shop_order' ] )
        );
        // phpcs:enable

        $stuck = (int) $wpdb->get_var( $sql );

        if ( $stuck > 0 && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::critical(
                'STUCK_SHIPMENTS',
                sprintf( '%d shipments stuck >30 days in transit', $stuck ),
                [ 'count' => $stuck ]
            );
        }
    }

    /**
     * Cron 6: Libera depósito a billetera del vendedor tras ventana de disputa.
     *
     * CR-3 FIX (Task 57-E): Atomicity. Previously `Wallet::credit()` ran
     * BEFORE `$wpdb->update(status='completed')` — if the credit succeeded
     * but the status update failed (DB timeout, deadlock), the next cron
     * tick would re-credit (status was still 'checked_out') → double payout.
     *
     * Now we wrap the whole operation in a DB transaction:
     *   1. SELECT ... FOR UPDATE locks the booking row.
     *   2. UPDATE status='completed' FIRST (idempotency: another worker
     *      that finds status != 'checked_out' will SKIP).
     *   3. Only THEN credit the wallet. If credit throws, ROLLBACK undoes
     *      the status update so the next tick can retry safely.
     *   4. COMMIT.
     *
     * ME-1/ME-3 (Task 57-E): also acquires a cron lock + raises time/memory
     * limits so a long batch of deposits doesn't get killed mid-way by PHP.
     */
    public static function release_booking_deposits(): void {
        global $wpdb;

        // ME-1/ME-3 (Task 57-E): cron lock + raise time/memory limits.
        if ( ! self::acquire_lock( 'ltms_cron_release_booking_deposits' ) ) {
            return;
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        $table = $wpdb->prefix . 'lt_bookings';

        try {
            $dispute_days = (int) LTMS_Core_Config::get( 'ltms_booking_dispute_window_days', 3 );
            // CR-3: fetch only the IDs of eligible bookings first — we re-read
            // each row inside the transaction with FOR UPDATE so the lock is
            // acquired precisely when we start processing, not when we list.
            $booking_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE status = 'checked_out'
                       AND deposit_amount > 0
                       AND checkout_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                    $dispute_days
                )
            ) ?: [];

            foreach ( $booking_ids as $booking_id ) {
                $booking_id = (int) $booking_id;
                try {
                    $wpdb->query( 'START TRANSACTION' );

                    // CR-3: Lock the row AND verify it's still 'checked_out'.
                    // FOR UPDATE prevents another cron worker from reading the
                    // same row until we COMMIT/ROLLBACK.
                    $booking = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$table} WHERE id = %d AND status = 'checked_out' FOR UPDATE",
                            $booking_id
                        ),
                        ARRAY_A
                    );

                    if ( ! $booking ) {
                        // Another worker already processed it, or status changed
                        // since we listed — nothing to do.
                        $wpdb->query( 'ROLLBACK' );
                        continue;
                    }

                    // CR-3: Mark as completed FIRST (idempotency guard).
                    // The WHERE clause `status = 'checked_out'` ensures only
                    // one worker can perform this transition; if 0 rows are
                    // affected, another worker beat us — ROLLBACK and skip.
                    $updated = $wpdb->update(
                        $table,
                        [
                            'status'     => 'completed',
                            'updated_at' => current_time( 'mysql' ),
                        ],
                        [
                            'id'     => $booking_id,
                            'status' => 'checked_out',
                        ],
                        [ '%s', '%s' ],
                        [ '%d', '%s' ]
                    );

                    if ( 0 === $updated ) {
                        // Race lost — another process already transitioned the row.
                        $wpdb->query( 'ROLLBACK' );
                        continue;
                    }

                    // CR-3: NOW credit the wallet. If this throws, ROLLBACK
                    // will undo the status update above so the next tick can
                    // retry safely (no double payout).
                    if ( class_exists( 'LTMS_Business_Wallet' ) && (float) $booking['deposit_amount'] > 0 ) {
                        // M-109: firma correcta = credit(vendor, amount, description:string, metadata:array)
                        LTMS_Business_Wallet::credit(
                            (int) $booking['vendor_id'],
                            (float) $booking['deposit_amount'],
                            sprintf( __( 'Depósito liberado — Reserva #%d', 'ltms' ), $booking_id ),
                            [
                                'type'       => 'booking_deposit_release',
                                'booking_id' => $booking_id,
                            ]
                        );
                    }

                    $wpdb->query( 'COMMIT' );

                    // Fire event AFTER commit so listeners see the final state.
                    do_action( 'ltms_booking_deposit_released', $booking_id, $booking );
                } catch ( \Throwable $inner ) {
                    // Roll back any in-flight transaction so the status update
                    // (if any) is undone — next cron tick will retry.
                    $wpdb->query( 'ROLLBACK' );
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::error(
                            'DEPOSIT_RELEASE_FAILED',
                            sprintf( 'Booking #%d: %s', $booking_id, $inner->getMessage() ),
                            [ 'booking_id' => $booking_id ]
                        );
                    } else {
                        error_log( 'LTMS Cron: deposit release booking #' . $booking_id . ' — ' . $inner->getMessage() );
                    }
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: release_booking_deposits — ' . $e->getMessage() );
        } finally {
            self::release_lock( 'ltms_cron_release_booking_deposits' );
        }
    }

    // ── M-44: Handlers para hooks sin listener ────────────────────

    /**
     * Despacha una notificación según su canal (email, whatsapp, inapp, etc.).
     *
     * @param array $notif Fila de lt_notifications.
     */
    public static function handle_dispatch_notification( array $notif ): void {
        $channel = $notif['channel'] ?? 'inapp';
        $user_id = (int) ( $notif['user_id'] ?? 0 );

        if ( ! $user_id ) return;

        if ( 'email' === $channel ) {
            $user = get_userdata( $user_id );
            if ( $user && $user->user_email ) {
                wp_mail(
                    $user->user_email,
                    wp_strip_all_tags( $notif['title'] ?? '' ),
                    wp_kses_post( $notif['message'] ?? '' )
                );
            }
        }
        // inapp/push: ya está registrado en lt_notifications por el cron send_notifications.
        // whatsapp/sms: requiere integración externa — loguear para revisión manual.
        if ( in_array( $channel, [ 'whatsapp', 'sms' ], true ) ) {
            LTMS_Core_Logger::info(
                'NOTIFICATION_CHANNEL_PENDING',
                sprintf( 'Notificación #%d canal %s pendiente de integración externa', (int) $notif['id'], $channel ),
                [ 'user_id' => $user_id, 'type' => $notif['type'] ?? '' ]
            );
        }
    }

    /**
     * Procesa un job individual de sincronización de factura con Siigo.
     *
     * @param array $args Argumentos del job: order_id, retry_count.
     */
    public static function handle_sync_siigo_invoice( array $args ): void {
        $order_id = (int) ( $args['order_id'] ?? 0 );
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // T-16 FIX: Idempotencia — si el pedido ya tiene factura Siigo, no volver a emitir.
        // Esto previene facturas duplicadas en Siigo/DIAN cuando el job se reintenta.
        $existing_invoice_id = $order->get_meta( '_ltms_siigo_invoice_id', true );
        if ( ! empty( $existing_invoice_id ) ) {
            LTMS_Core_Logger::info(
                'SIIGO_INVOICE_ALREADY_EXISTS',
                "Pedido #{$order_id} ya tiene factura Siigo: {$existing_invoice_id}. Saltando.",
                [ 'order_id' => $order_id, 'invoice_id' => $existing_invoice_id ]
            );
            return;
        }

        try {
            $siigo    = LTMS_Api_Factory::get( 'siigo' );

            // T-17 FIX: pasar todos los campos de billing necesarios para crear cliente en Siigo.
            // Sin identification (NIT/CC), Siigo rechaza la creación del cliente con error 422.
            // Sin address y city_code, el cliente se crea sin datos fiscales.
            $customer = $siigo->get_or_create_customer( [
                'email'          => $order->get_billing_email(),
                'first_name'     => $order->get_billing_first_name(),
                'last_name'      => $order->get_billing_last_name(),
                'phone'          => $order->get_billing_phone(),
                'identification' => $order->get_meta( '_billing_cedula', true ) ?: $order->get_meta( '_billing_nit', true ) ?: '',
                'address'        => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
                'city_code'      => $order->get_meta( '_billing_siigo_city_code', true ) ?: '11001', // 11001 = Bogotá por defecto
            ] );

            // M-96: pasar el tax_breakdown guardado en metadata de la comisión
            $tax_data = [];
            global $wpdb;
            $commission_meta = $wpdb->get_var( $wpdb->prepare(
                "SELECT metadata FROM {$wpdb->prefix}lt_commissions WHERE order_id = %d LIMIT 1",
                $order_id
            ) );
            if ( $commission_meta ) {
                $tax_data = json_decode( $commission_meta, true ) ?: [];
            }
            $invoice_data = $siigo->build_invoice_payload( $order, $customer, $tax_data );
            $result       = $siigo->create_invoice( $invoice_data );

            if ( ! empty( $result['id'] ) ) {
                $order->update_meta_data( '_ltms_siigo_invoice_id', $result['id'] );
                $order->save();
                LTMS_Core_Logger::info( 'SIIGO_INVOICE_SYNCED', "Pedido #{$order_id} facturado en Siigo: {$result['id']}" );
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'SIIGO_INVOICE_SYNC_ERROR', $e->getMessage(), [ 'order_id' => $order_id ] );
            throw $e; // Re-lanzar para que el Cron Manager marque el job como fallido/reintento
        }
    }

    /**
     * Acciones al crear una nueva reserva (log + notificación inapp al vendedor).
     *
     * @param int   $booking_id ID de la reserva.
     * @param array $data       Datos de la reserva (product_id, vendor_id, customer_id, etc.).
     */
    public static function handle_booking_created( int $booking_id, array $data ): void {
        LTMS_Core_Logger::info(
            'BOOKING_CREATED',
            sprintf( 'Nueva reserva #%d creada — producto #%d, vendedor #%d', $booking_id, (int) ( $data['product_id'] ?? 0 ), (int) ( $data['vendor_id'] ?? 0 ) ),
            [ 'booking_id' => $booking_id ]
        );
    }

    /**
     * Acciones al confirmar una reserva (log + nota de pago).
     *
     * @param int $booking_id ID de la reserva.
     */
    public static function handle_booking_confirmed( int $booking_id ): void {
        LTMS_Core_Logger::info( 'BOOKING_CONFIRMED', "Reserva #{$booking_id} confirmada", [ 'booking_id' => $booking_id ] );
    }

    /**
     * Acciones al cancelar una reserva (log).
     *
     * @param int    $booking_id    ID de la reserva.
     * @param array  $booking       Datos completos de la reserva.
     * @param string $cancelled_by  'customer'|'vendor'|'admin'|'system'.
     */
    public static function handle_booking_cancelled( int $booking_id, array $booking, string $cancelled_by ): void {
        LTMS_Core_Logger::info(
            'BOOKING_CANCELLED',
            sprintf( 'Reserva #%d cancelada por: %s', $booking_id, $cancelled_by ),
            [ 'booking_id' => $booking_id, 'cancelled_by' => $cancelled_by ]
        );
    }

    /**
     * Acciones al liberar el depósito de una reserva (log).
     *
     * @param int   $booking_id ID de la reserva.
     * @param array $booking    Datos de la reserva.
     */
    public static function handle_deposit_released( int $booking_id, array $booking ): void {
        LTMS_Core_Logger::info(
            'BOOKING_DEPOSIT_RELEASED',
            sprintf( 'Depósito de reserva #%d liberado al vendedor #%d', $booking_id, (int) ( $booking['vendor_id'] ?? 0 ) ),
            [ 'booking_id' => $booking_id ]
        );
    }

    /**
     * Acciones al procesar un reembolso de reserva (log).
     *
     * @param int    $booking_id    ID de la reserva.
     * @param float  $refund_amount Monto reembolsado.
     * @param mixed  $refund        Objeto WC_Order_Refund o array.
     */
    public static function handle_booking_refund( int $booking_id, float $refund_amount, $refund ): void {
        LTMS_Core_Logger::info(
            'BOOKING_REFUND_PROCESSED',
            sprintf( 'Reembolso de %.2f procesado para reserva #%d', $refund_amount, $booking_id ),
            [ 'booking_id' => $booking_id, 'refund_amount' => $refund_amount ]
        );
    }

    /**
     * Envía recordatorio de check-in al cliente.
     *
     * @param array $booking Datos de la reserva desde lt_bookings.
     */
    public static function handle_checkin_reminder( array $booking ): void {
        $customer_id = (int) ( $booking['customer_id'] ?? 0 );
        if ( ! $customer_id ) return;

        $user = get_userdata( $customer_id );
        if ( ! $user ) return;

        $checkin = $booking['checkin_date'] ?? '';

        // AUDIT-BOOKING-ENGINE #12 FIX: usar template HTML existente en vez
        // de wp_mail texto plano. El template email-booking-checkin-reminder.php
        // ya existe (con header, styling, datos de la reserva) pero era dead code.
        $template_path = defined( 'LTMS_PLUGIN_DIR' )
            ? LTMS_PLUGIN_DIR . 'templates/emails/email-booking-checkin-reminder.php'
            : '';

        $email_body = '';
        if ( $template_path && file_exists( $template_path ) ) {
            ob_start();
            include $template_path;
            $email_body = ob_get_clean();
        }

        if ( empty( $email_body ) ) {
            // Fallback texto plano.
            $email_body = nl2br( esc_html( sprintf(
                __( 'Hola %s, tu check-in está programado para el %s. ¡Nos vemos pronto!', 'ltms' ),
                $user->display_name, $checkin
            ) ) );
        }

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $user->user_email, __( 'Recordatorio: Tu reserva está próxima', 'ltms' ), $email_body );
        remove_filter( 'wp_mail_content_type', fn() => 'text/html' );

        LTMS_Core_Logger::info( 'CHECKIN_REMINDER_SENT', "Recordatorio check-in enviado para reserva #{$booking['id']} (template: " . ( $email_body ? 'HTML' : 'plain' ) . ')' );
    }

    /**
     * Envía recordatorio de saldo pendiente al cliente.
     *
     * @param array $booking Datos de la reserva desde lt_bookings.
     */
    public static function handle_balance_reminder( array $booking ): void {
        $customer_id = (int) ( $booking['customer_id'] ?? 0 );
        if ( ! $customer_id ) return;

        $user = get_userdata( $customer_id );
        if ( ! $user ) return;

        $balance = number_format( (float) ( $booking['balance_amount'] ?? 0 ), 2 );

        // AUDIT-BOOKING-ENGINE #12 FIX: usar template HTML existente.
        $template_path = defined( 'LTMS_PLUGIN_DIR' )
            ? LTMS_PLUGIN_DIR . 'templates/emails/email-booking-balance-reminder.php'
            : '';

        $email_body = '';
        if ( $template_path && file_exists( $template_path ) ) {
            ob_start();
            include $template_path;
            $email_body = ob_get_clean();
        }

        if ( empty( $email_body ) ) {
            $email_body = nl2br( esc_html( sprintf(
                __( 'Hola %s, tienes un saldo pendiente de $%s para completar tu reserva. Por favor realiza el pago antes del check-in.', 'ltms' ),
                $user->display_name, $balance
            ) ) );
        }

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $user->user_email, __( 'Saldo pendiente en tu reserva', 'ltms' ), $email_body );
        remove_filter( 'wp_mail_content_type', fn() => 'text/html' );

        LTMS_Core_Logger::info( 'BALANCE_REMINDER_SENT', "Recordatorio saldo enviado para reserva #{$booking['id']} (template: " . ( $email_body ? 'HTML' : 'plain' ) . ')' );
    }

    /**
     * Acciones al aprobar RNT de un vendedor (log + notificación).
     *
     * @param int $vendor_id ID del vendedor.
     */
    public static function handle_rnt_approved( int $vendor_id ): void {
        update_user_meta( $vendor_id, 'ltms_rnt_status', 'approved' );
        LTMS_Core_Logger::info( 'RNT_APPROVED', "RNT aprobado para vendedor #{$vendor_id}" );
    }

    /**
     * Acciones al rechazar RNT de un vendedor (log + meta).
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $notes     Motivo del rechazo.
     */
    public static function handle_rnt_rejected( int $vendor_id, string $notes ): void {
        update_user_meta( $vendor_id, 'ltms_rnt_status', 'rejected' );
        update_user_meta( $vendor_id, 'ltms_rnt_rejection_notes', sanitize_textarea_field( $notes ) );
        LTMS_Core_Logger::info( 'RNT_REJECTED', "RNT rechazado para vendedor #{$vendor_id}: {$notes}" );
    }

    /**
     * Acciones al expirar RNT de un vendedor (log + suspensión preventiva).
     *
     * @param int   $vendor_id ID del vendedor.
     * @param array $row       Fila de la tabla de cumplimiento.
     */
    public static function handle_rnt_expired( int $vendor_id, array $row ): void {
        update_user_meta( $vendor_id, 'ltms_rnt_status', 'expired' );
        LTMS_Core_Logger::warning(
            'RNT_EXPIRED',
            sprintf( 'RNT expirado para vendedor #%d — requiere renovación', $vendor_id ),
            [ 'vendor_id' => $vendor_id, 'rnt_number' => $row['rnt_number'] ?? '' ]
        );
    }
}
