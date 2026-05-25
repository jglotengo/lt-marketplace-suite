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
                        // M-109: firma correcta = credit(vendor, amount, description:string, metadata:array)
                        LTMS_Business_Wallet::credit(
                            (int) $booking['vendor_id'],
                            (float) $booking['deposit_amount'],
                            sprintf( __( 'Depósito liberado — Reserva #%d', 'ltms' ), (int) $booking['id'] ),
                            [ 'type' => 'booking_deposit_release', 'booking_id' => (int) $booking['id'] ]
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
        wp_mail(
            $user->user_email,
            __( 'Recordatorio: Tu reserva está próxima', 'ltms' ),
            sprintf(
                /* translators: 1: check-in date */
                __( 'Hola %s, tu check-in está programado para el %s. ¡Nos vemos pronto!', 'ltms' ),
                esc_html( $user->display_name ),
                esc_html( $checkin )
            )
        );
        LTMS_Core_Logger::info( 'CHECKIN_REMINDER_SENT', "Recordatorio check-in enviado para reserva #{$booking['id']}" );
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
        wp_mail(
            $user->user_email,
            __( 'Saldo pendiente en tu reserva', 'ltms' ),
            sprintf(
                /* translators: 1: balance amount */
                __( 'Hola %s, tienes un saldo pendiente de $%s para completar tu reserva. Por favor realiza el pago antes del check-in.', 'ltms' ),
                esc_html( $user->display_name ),
                esc_html( $balance )
            )
        );
        LTMS_Core_Logger::info( 'BALANCE_REMINDER_SENT', "Recordatorio saldo enviado para reserva #{$booking['id']}" );
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
