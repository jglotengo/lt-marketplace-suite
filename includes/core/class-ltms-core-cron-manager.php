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
        add_action( 'ltms_cron_balance_due_reminders',    [ self::class, 'balance_due_reminders' ] );
        add_action( 'ltms_cron_auto_checkout',            [ self::class, 'auto_checkout' ] );
        add_action( 'ltms_cron_check_rnt_expiry',         [ self::class, 'check_rnt_expiry' ] );
        add_action( 'ltms_cron_release_booking_deposits', [ self::class, 'release_booking_deposits' ] );

        self::schedule_jobs();
    }

    private static function schedule_jobs(): void {
        $jobs = [
            'ltms_cron_cleanup_pending_bookings' => [ 'every_30_minutes', null ],
            'ltms_cron_send_checkin_reminders'   => [ 'daily',            '10:00:00' ],
            'ltms_cron_balance_due_reminders'    => [ 'daily',            '09:00:00' ],
            'ltms_cron_auto_checkout'            => [ 'daily',            '12:00:00' ],
            'ltms_cron_check_rnt_expiry'         => [ 'weekly',           null ],
            'ltms_cron_release_booking_deposits' => [ 'daily',            '08:00:00' ],
        ];
        foreach ( $jobs as $hook => $config ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $ts = $config[1] ? strtotime( gmdate( 'Y-m-d' ) . ' ' . $config[1] ) : time();
                wp_schedule_event( $ts, $config[0], $hook );
            }
        }
    }

    /** Cron 1: Libera slots de reservas pending > 30 min sin pago. */
    public static function cleanup_pending_bookings(): void {
        try {
            if ( class_exists( 'LTMS_Booking_Manager' ) ) {
                LTMS_Booking_Manager::cleanup_pending_bookings();
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: cleanup_pending_bookings — ' . $e->getMessage() );
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

    /** Cron 6: Libera depósito a billetera del vendedor tras ventana de disputa. */
    public static function release_booking_deposits(): void {
        global $wpdb;
        try {
            $dispute_days = (int) LTMS_Core_Config::get( 'ltms_booking_dispute_window_days', 3 );
            $bookings     = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}lt_bookings WHERE status = 'checked_out' AND deposit_amount > 0 AND checkout_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                    $dispute_days
                ),
                ARRAY_A
            ) ?: [];

            foreach ( $bookings as $booking ) {
                try {
                    if ( class_exists( 'LTMS_Business_Wallet' ) && (float) $booking['deposit_amount'] > 0 ) {
                        LTMS_Business_Wallet::credit(
                            (int) $booking['vendor_id'],
                            (float) $booking['deposit_amount'],
                            'booking_deposit_release',
                            sprintf( __( 'Depósito liberado — Reserva #%d', 'ltms' ), (int) $booking['id'] )
                        );
                    }
                    $wpdb->update(
                        $wpdb->prefix . 'lt_bookings',
                        [ 'status' => 'completed', 'updated_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $booking['id'] ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    do_action( 'ltms_booking_deposit_released', (int) $booking['id'], $booking );
                } catch ( \Throwable $inner ) {
                    error_log( 'LTMS Cron: deposit release booking #' . $booking['id'] . ' — ' . $inner->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: release_booking_deposits — ' . $e->getMessage() );
        }
    }
}
