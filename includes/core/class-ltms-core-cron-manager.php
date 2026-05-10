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
        add_action( 'ltms_update_tracking',               [ self::class, 'update_tracking' ] );
        add_action( 'ltms_sync_siigo',                    [ self::class, 'sync_siigo' ] );
        add_action( 'ltms_integrity_check',               [ self::class, 'integrity_check' ] );
        add_action( 'ltms_clean_logs',                    [ self::class, 'clean_logs' ] );
        add_action( 'ltms_process_job_queue',             [ self::class, 'process_job_queue' ] );
        add_action( 'ltms_send_notifications',            [ self::class, 'send_notifications' ] );

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
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [ 'status' => 'processing', 'started_at' => current_time( 'mysql' ), 'attempts' => (int) $job['attempts'] + 1 ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s', '%d' ],
                        [ '%d' ]
                    );
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
        try {
            // Detectar comisiones huérfanas (orden cancelada pero comisión pendiente)
            $orphaned = $wpdb->get_col(
                "SELECT c.id FROM {$wpdb->prefix}lt_commissions c
                 LEFT JOIN {$wpdb->posts} p ON p.ID = c.order_id
                 WHERE c.status = 'pending'
                 AND ( p.ID IS NULL OR p.post_status IN ('cancelled','trash','wc-cancelled','wc-failed') )"
            ) ?: [];

            foreach ( $orphaned as $commission_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'lt_commissions',
                    [ 'status' => 'cancelled' ],
                    [ 'id' => (int) $commission_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }

            if ( ! empty( $orphaned ) ) {
                LTMS_Core_Logger::warning(
                    'INTEGRITY_CHECK',
                    sprintf( '%d comisiones huérfanas marcadas como canceladas.', count( $orphaned ) )
                );
            } else {
                LTMS_Core_Logger::info( 'INTEGRITY_CHECK', 'Sin anomalías detectadas.' );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: integrity_check — ' . $e->getMessage() );
        }
    }

    /** Cron 10: Elimina logs antiguos según la política de retención configurada. */
    public static function clean_logs(): void {
        global $wpdb;
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
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: clean_logs — ' . $e->getMessage() );
        }
    }

    /** Cron 11: Procesa la cola de jobs pendientes (genérico). */
    public static function process_job_queue(): void {
        global $wpdb;
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
                    $wpdb->update(
                        $wpdb->prefix . 'lt_job_queue',
                        [ 'status' => 'processing', 'started_at' => current_time( 'mysql' ), 'attempts' => (int) $job['attempts'] + 1 ],
                        [ 'id' => (int) $job['id'] ],
                        [ '%s', '%s', '%d' ],
                        [ '%d' ]
                    );
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
        try {
            // Notificaciones aún no enviadas (sent_at IS NULL) creadas hace > 1 min
            // para evitar doble-envío en requests concurrentes.
            $notifications = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}lt_notifications
                 WHERE sent_at IS NULL
                   AND channel != 'inapp'
                   AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                 ORDER BY created_at ASC LIMIT 50",
                ARRAY_A
            ) ?: [];

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

            $orders = wc_get_orders( [
                'status'       => [ 'processing', 'wc-shipped' ],
                'limit'        => 50,
                'meta_query'   => [
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

            foreach ( $orders as $order ) {
                try {
                    $provider        = $order->get_meta( '_ltms_absorbed_shipping_provider' );
                    $aveonline_track = $order->get_meta( '_ltms_aveonline_tracking' );

                    if ( $aveonline_track && class_exists( 'LTMS_Api_Factory' ) ) {
                        /** @var \LTMS_Api_Aveonline $api */
                        $api    = LTMS_Api_Factory::get( 'aveonline' );
                        $result = $api->track_shipment( $aveonline_track );
                        $status = $result['status'] ?? '';
                        if ( $status ) {
                            $order->add_order_note(
                                sprintf( __( 'Aveonline tracking %s — estado actualizado: %s', 'ltms' ), $aveonline_track, sanitize_text_field( $status ) )
                            );
                            if ( in_array( $status, [ 'delivered', 'entregado', 'DELIVERED' ], true ) ) {
                                $order->update_status( 'completed', __( 'Entregado vía Aveonline.', 'ltms' ) );
                            }
                        }
                    } elseif ( 'heka' === $provider && class_exists( 'LTMS_Api_Factory' ) ) {
                        $tracking_number = $order->get_meta( '_ltms_heka_tracking_number' );
                        if ( $tracking_number ) {
                            /** @var \LTMS_Api_TPTC $api */
                            $api    = LTMS_Api_Factory::get( 'heka' );
                            $result = $api->track_shipment( $tracking_number );
                            $status = $result['status'] ?? '';
                            if ( $status ) {
                                $order->add_order_note(
                                    sprintf( __( 'Heka tracking %s — estado actualizado: %s', 'ltms' ), $tracking_number, sanitize_text_field( $status ) )
                                );
                                if ( in_array( $status, [ 'delivered', 'entregado', 'DELIVERED' ], true ) ) {
                                    $order->update_status( 'completed', __( 'Entregado vía Heka.', 'ltms' ) );
                                }
                            }
                        }
                    }

                    $order->save();
                } catch ( \Throwable $inner ) {
                    error_log( 'LTMS Cron: update_tracking order #' . $order->get_id() . ' — ' . $inner->getMessage() );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Cron: update_tracking — ' . $e->getMessage() );
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
