<?php
/**
 * LTMS Booking Manager
 *
 * Motor de reservas ACID. Usa SELECT…FOR UPDATE para evitar
 * doble-reserva. Soporta pago completo, depósito y reserve_only.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Booking_Manager
 */
class LTMS_Booking_Manager {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'woocommerce_checkout_order_created', [ self::class, 'create_booking_from_order' ], 20 );
        add_action( 'woocommerce_order_status_cancelled',  [ self::class, 'on_order_cancelled' ], 10, 2 );
        add_action( 'woocommerce_order_status_refunded',   [ self::class, 'on_order_cancelled' ], 10, 2 );
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Crea una reserva con bloqueo FOR UPDATE.
     *
     * @param int    $product_id
     * @param int    $customer_id
     * @param int    $vendor_id
     * @param string $checkin_date  Y-m-d
     * @param string $checkout_date Y-m-d
     * @param int    $guests
     * @param float  $total_price
     * @param array  $meta          Datos extra (wc_order_id, payment_mode, etc.)
     * @return int|\WP_Error  Booking ID o WP_Error.
     */
    public static function create_booking(
        int    $product_id,
        int    $customer_id,
        int    $vendor_id,
        string $checkin_date,
        string $checkout_date,
        int    $guests,
        float  $total_price,
        array  $meta = []
    ): int|\WP_Error {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Lock all slots in range.
            $slots = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, booked, capacity FROM {$wpdb->prefix}lt_booking_slots
                     WHERE product_id = %d AND slot_date >= %s AND slot_date < %s AND is_blocked = 0
                     FOR UPDATE",
                    $product_id,
                    $checkin_date,
                    $checkout_date
                ),
                ARRAY_A
            );

            // Verify availability.
            foreach ( $slots as $slot ) {
                if ( (int) $slot['booked'] >= (int) $slot['capacity'] ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'slot_unavailable', __( 'Una o más fechas ya no están disponibles.', 'ltms' ) );
                }
            }

            $currency     = get_woocommerce_currency();
            $deposit_pct  = (float) ( $meta['deposit_pct'] ?? 0 );
            $deposit_amt  = $deposit_pct > 0 ? round( $total_price * $deposit_pct / 100, 2 ) : 0.0;
            $balance_amt  = $total_price - $deposit_amt;
            $payment_mode = $meta['payment_mode'] ?? 'full';

            $wpdb->insert(
                $wpdb->prefix . 'lt_bookings',
                [
                    'product_id'         => $product_id,
                    'customer_id'        => $customer_id,
                    'vendor_id'          => $vendor_id,
                    'wc_order_id'        => (int) ( $meta['wc_order_id'] ?? 0 ),
                    'checkin_date'       => $checkin_date,
                    'checkout_date'      => $checkout_date,
                    'guests'             => $guests,
                    'total_price'        => $total_price,
                    'deposit_amount'     => $deposit_amt,
                    'balance_amount'     => $balance_amt,
                    'currency'           => $currency,
                    'payment_mode'       => $payment_mode,
                    'status'             => 'pending',
                    'instant_booking'    => (int) ( $meta['instant_booking'] ?? 0 ),
                    'zapsign_doc_token'  => sanitize_text_field( $meta['zapsign_doc_token'] ?? '' ),
                    'insurance_quote_id' => sanitize_text_field( $meta['insurance_quote_id'] ?? '' ),
                    'notes'              => sanitize_textarea_field( $meta['notes'] ?? '' ),
                    'created_at'         => current_time( 'mysql' ),
                    'updated_at'         => current_time( 'mysql' ),
                ],
                [ '%d','%d','%d','%d','%s','%s','%d','%f','%f','%f','%s','%s','%s','%d','%s','%s','%s','%s','%s' ]
            );

            if ( ! $wpdb->insert_id ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'db_insert_failed', __( 'Error al crear la reserva.', 'ltms' ) );
            }

            $booking_id = (int) $wpdb->insert_id;

            // Increment slot counters.
            foreach ( $slots as $slot ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->prefix}lt_booking_slots SET booked = booked + 1 WHERE id = %d",
                        (int) $slot['id']
                    )
                );
            }

            // Auto-generate missing slots for the date range.
            self::ensure_slots( $product_id, $checkin_date, $checkout_date );

            $wpdb->query( 'COMMIT' );

            do_action( 'ltms_booking_created', $booking_id, compact(
                'product_id','customer_id','vendor_id','checkin_date','checkout_date','total_price','payment_mode'
            ) );

            return $booking_id;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'LTMS Booking create error: ' . $e->getMessage() );
            return new \WP_Error( 'booking_exception', $e->getMessage() );
        }
    }

    /**
     * Confirma una reserva pending (ej: al recibir pago).
     */
    public static function confirm_booking( int $booking_id ): bool {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'lt_bookings',
            [ 'status' => 'confirmed', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $booking_id, 'status' => 'pending' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( $rows ) {
            do_action( 'ltms_booking_confirmed', $booking_id );
        }
        return (bool) $rows;
    }

    /**
     * Cancela una reserva y libera los slots.
     */
    public static function cancel_booking( int $booking_id, string $cancelled_by = 'system', string $notes = '' ): bool|\WP_Error {
        global $wpdb;

        $booking = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_bookings WHERE id = %d", $booking_id ),
            ARRAY_A
        );
        if ( ! $booking ) {
            return new \WP_Error( 'not_found', __( 'Reserva no encontrada.', 'ltms' ) );
        }
        if ( in_array( $booking['status'], [ 'cancelled', 'checked_out', 'completed' ], true ) ) {
            return new \WP_Error( 'invalid_status', __( 'La reserva no se puede cancelar en su estado actual.', 'ltms' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        try {
            $wpdb->update(
                $wpdb->prefix . 'lt_bookings',
                [
                    'status'       => 'cancelled',
                    'cancelled_by' => sanitize_text_field( $cancelled_by ),
                    'cancel_notes' => sanitize_textarea_field( $notes ),
                    'updated_at'   => current_time( 'mysql' ),
                ],
                [ 'id' => $booking_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            // Release slots.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}lt_booking_slots
                     SET booked = GREATEST(0, booked - 1)
                     WHERE product_id = %d AND slot_date >= %s AND slot_date < %s",
                    (int) $booking['product_id'],
                    $booking['checkin_date'],
                    $booking['checkout_date']
                )
            );

            $wpdb->query( 'COMMIT' );

            // Refund logic delegated to policy handler.
            if ( class_exists( 'LTMS_Booking_Policy_Handler' ) ) {
                LTMS_Booking_Policy_Handler::process_cancellation_refund( $booking_id, $booking, $cancelled_by );
            }

            do_action( 'ltms_booking_cancelled', $booking_id, $booking, $cancelled_by );
            return true;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'LTMS cancel_booking error: ' . $e->getMessage() );
            return new \WP_Error( 'cancel_exception', $e->getMessage() );
        }
    }

    /**
     * Check disponibilidad de un producto para un rango de fechas.
     *
     * @return bool True si hay al menos un slot disponible por día.
     */
    public static function is_available( int $product_id, string $checkin_date, string $checkout_date ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lt_booking_slots
                 WHERE product_id = %d AND slot_date >= %s AND slot_date < %s
                   AND is_blocked = 0 AND booked < capacity",
                $product_id,
                $checkin_date,
                $checkout_date
            )
        );
        $expected_nights = (int) floor( ( strtotime( $checkout_date ) - strtotime( $checkin_date ) ) / DAY_IN_SECONDS );
        return $expected_nights > 0 && $count >= $expected_nights;
    }

    /**
     * Retorna fechas bloqueadas para un producto (uso: calendario frontend).
     *
     * @return array Array de fechas Y-m-d
     */
    public static function get_blocked_dates( int $product_id, string $from = '', string $to = '' ): array {
        global $wpdb;
        $from = $from ?: gmdate( 'Y-m-d' );
        $to   = $to   ?: gmdate( 'Y-m-d', strtotime( '+365 days' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slot_date FROM {$wpdb->prefix}lt_booking_slots
                 WHERE product_id = %d AND slot_date BETWEEN %s AND %s
                   AND (is_blocked = 1 OR booked >= capacity)
                 ORDER BY slot_date",
                $product_id,
                $from,
                $to
            ),
            ARRAY_A
        ) ?: [];

        return array_column( $rows, 'slot_date' );
    }

    /**
     * Limpia reservas pending sin pago > 30 min.
     */
    public static function cleanup_pending_bookings(): void {
        global $wpdb;
        $minutes = (int) LTMS_Core_Config::get( 'ltms_booking_pending_timeout_minutes', 30 );
        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, product_id, checkin_date, checkout_date FROM {$wpdb->prefix}lt_bookings
                 WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
                $minutes
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $expired as $b ) {
            // Release slots.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}lt_booking_slots
                     SET booked = GREATEST(0, booked - 1)
                     WHERE product_id = %d AND slot_date >= %s AND slot_date < %s",
                    (int) $b['product_id'],
                    $b['checkin_date'],
                    $b['checkout_date']
                )
            );
            $wpdb->update(
                $wpdb->prefix . 'lt_bookings',
                [ 'status' => 'cancelled', 'cancel_notes' => 'auto-expired', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $b['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    // ── WooCommerce hooks ────────────────────────────────────────────────

    /**
     * Crea la reserva cuando se crea la orden WC.
     */
    public static function create_booking_from_order( \WC_Order $order ): void {
        try {
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product || 'ltms_bookable' !== $product->get_type() ) continue;

                $meta         = $item->get_meta_data();
                $meta_map     = [];
                foreach ( $meta as $m ) { $meta_map[ $m->key ] = $m->value; }

                $checkin_date  = sanitize_text_field( $meta_map['_ltms_checkin_date']  ?? '' );
                $checkout_date = sanitize_text_field( $meta_map['_ltms_checkout_date'] ?? '' );
                $guests        = (int) ( $meta_map['_ltms_guests'] ?? 1 );

                if ( ! $checkin_date || ! $checkout_date ) continue;

                $vendor_id = (int) get_post_meta( $product->get_id(), '_vendor_id', true );

                self::create_booking(
                    $product->get_id(),
                    (int) $order->get_customer_id(),
                    $vendor_id,
                    $checkin_date,
                    $checkout_date,
                    $guests,
                    (float) $item->get_total(),
                    [
                        'wc_order_id'     => $order->get_id(),
                        'payment_mode'    => $product->get_payment_mode(),
                        'deposit_pct'     => $product->get_deposit_pct(),
                        'instant_booking' => (int) $product->is_instant_booking(),
                    ]
                );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS create_booking_from_order: ' . $e->getMessage() );
        }
    }

    public static function on_order_cancelled( int $order_id, \WC_Order $order ): void {
        try {
            global $wpdb;
            $bookings = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}lt_bookings WHERE wc_order_id = %d AND status NOT IN ('cancelled','completed')",
                    $order_id
                ),
                ARRAY_A
            ) ?: [];
            foreach ( $bookings as $b ) {
                self::cancel_booking( (int) $b['id'], 'woocommerce', 'Order ' . $order_id . ' cancelled' );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS on_order_cancelled: ' . $e->getMessage() );
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Crea slots faltantes en el rango (idempotente por IGNORE).
     */
    private static function ensure_slots( int $product_id, string $from, string $to ): void {
        global $wpdb;
        $capacity = (int) get_post_meta( $product_id, '_ltms_capacity', true ) ?: 1;
        $current  = strtotime( $from );
        $end      = strtotime( $to );
        while ( $current < $end ) {
            $date = gmdate( 'Y-m-d', $current );
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}lt_booking_slots
                     (product_id, slot_date, capacity, booked, is_blocked, created_at, updated_at)
                     VALUES (%d, %s, %d, 0, 0, NOW(), NOW())",
                    $product_id,
                    $date,
                    $capacity
                )
            );
            $current += DAY_IN_SECONDS;
        }
    }
}
