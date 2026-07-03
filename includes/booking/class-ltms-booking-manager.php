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

    use LTMS_Logger_Aware;

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'woocommerce_checkout_order_created', [ self::class, 'create_booking_from_order' ], 20 );
        // AUDIT-BOOKING-ENGINE #1 FIX: confirmar reserva cuando se completa el pago.
        add_action( 'woocommerce_payment_complete',        [ self::class, 'confirm_booking_on_payment' ], 15 );
        add_action( 'woocommerce_order_status_completed',  [ self::class, 'confirm_booking_on_payment' ], 15 );
        add_action( 'woocommerce_order_status_cancelled',  [ self::class, 'on_order_cancelled' ], 10, 2 );
        add_action( 'woocommerce_order_status_refunded',   [ self::class, 'on_order_cancelled' ], 10, 2 );

        // AUDIT-BOOKING-ENGINE #6 FIX: AJAX handlers para bloquear/desbloquear fechas.
        add_action( 'wp_ajax_ltms_booking_block_dates',   [ self::class, 'ajax_block_dates' ] );
        add_action( 'wp_ajax_ltms_booking_unblock_dates', [ self::class, 'ajax_unblock_dates' ] );

        // AUDIT-BOOKING-ENGINE #11 FIX: AJAX handlers para lifecycle management.
        add_action( 'wp_ajax_ltms_booking_check_in',      [ self::class, 'ajax_check_in' ] );
        add_action( 'wp_ajax_ltms_booking_check_out',     [ self::class, 'ajax_check_out' ] );
        add_action( 'wp_ajax_ltms_booking_complete',      [ self::class, 'ajax_complete' ] );
        add_action( 'wp_ajax_ltms_booking_confirm',       [ self::class, 'ajax_confirm' ] );
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

            // AUDIT-BOOKING-ENGINE #23 FIX: if no slots exist, the product is
            // brand new and has never been booked. Auto-generate slots so the
            // first customer can book. Without this, is_available() returns
            // false for new products and nobody can ever make the first booking.
            if ( empty( $slots ) ) {
                self::ensure_slots( $product_id, $checkin_date, $checkout_date );
                // Re-fetch after auto-generation.
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
            }

            // AUDIT-RD-BK BK-3 FIX: si $slots sigue vacío tras ensure_slots,
            // significa que TODOS los slots del rango existen pero están
            // bloqueados (is_blocked=1) por el vendor (mantenimiento, holiday,
            // closure) O que ensure_slots no pudo crearlos. Antes este caso
            // caía silenciosamente: el foreach de verificación no iteraba, el
            // INSERT de la reserva procedía, y el foreach de incremento de
            // booked tampoco iteraba → se creaba una reserva sobre fechas
            // bloqueadas sin contar contra capacidad, y el cliente pagaba por
            // una reserva que el vendor no aceptaría.
            //
            // Race real que esto previene: customer añade al carrito (slots
            // abiertos), vendor bloquea las fechas vía ajax_block_dates, customer
            // paga antes de que el carrito re-valide disponibilidad. Sin este
            // guardián, la reserva se creaba sobre fechas ya bloqueadas.
            $expected_nights = (int) floor( ( strtotime( $checkout_date ) - strtotime( $checkin_date ) ) / DAY_IN_SECONDS );
            if ( $expected_nights <= 0 || count( $slots ) < $expected_nights ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error(
                    'no_available_slots',
                    __( 'Las fechas seleccionadas no están disponibles (bloqueadas o sin capacidad).', 'ltms' )
                );
            }

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

            // AUDIT-BOOKING-ENGINE #3 FIX: calcular vendor_net + policy_id.
            // Antes estas columnas nunca se escribían → el dashboard del
            // vendor siempre mostraba "Ingresos (neto): COP 0" y el cálculo
            // de reembolsos caía al default 'flexible' porque el JOIN
            // bp.id = b.policy_id retornaba NULL.
            $platform_rate = (float) ( $meta['platform_rate'] ?? LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.15 ) );
            $platform_fee  = round( $total_price * $platform_rate, 2 );
            $vendor_net    = round( $total_price - $platform_fee, 2 );

            // Buscar la política de cancelación aplicable al producto.
            $policy_id = (int) ( $meta['policy_id'] ?? 0 );
            if ( ! $policy_id ) {
                $policy_id = (int) get_post_meta( $product_id, '_ltms_booking_policy_id', true );
            }

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
                    'booking_type'       => sanitize_key( $meta['booking_type'] ?? 'accommodation' ),
                    'checkin_time'       => sanitize_text_field( $meta['checkin_time'] ?? '' ),
                    'checkout_time'      => sanitize_text_field( $meta['checkout_time'] ?? '' ),
                    'ip_address'         => function_exists( 'WC_Geolocation' ) ? \WC_Geolocation::get_ip_address() : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ), // phpcs:ignore
                    // AUDIT-BOOKING-ENGINE #3 FIX: campos que antes nunca se escribían.
                    'vendor_net'         => $vendor_net,
                    'policy_id'          => $policy_id,
                    'created_at'         => current_time( 'mysql' ),
                    'updated_at'         => current_time( 'mysql' ),
                ],
                [ '%d','%d','%d','%d','%s','%s','%d','%f','%f','%f','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%f','%d','%s','%s' ]
            );

            if ( ! $wpdb->insert_id ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'db_insert_failed', __( 'Error al crear la reserva.', 'ltms' ) . ' (' . $wpdb->last_error . ')' );
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
                'product_id','customer_id','vendor_id','checkin_date','checkout_date','total_price','payment_mode','vendor_net','policy_id'
            ) );

            return $booking_id;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            self::log_warning_static( 'booking', 'create_booking exception: ' . $e->getMessage() );
            return new \WP_Error( 'booking_exception', $e->getMessage() );
        }
    }

    /**
     * Confirma una reserva pending (ej: al recibir pago).
     *
     * AUDIT-RD-BK BK-2 NOTE: el guardián de "no confirmar sin pago" vive en
     * ajax_confirm() (único path que puede invocar confirmación sin que WC
     * haya disparado woocommerce_payment_complete). Mantenemos la firma bool
     * para no romper el contrato de los tests existentes (BookingManagerTest
     * secciones 3 y 4) — el WP_Error se devuelve desde ajax_confirm, no desde
     * aquí. Si en el futuro se quiere defense-in-depth adicional, agregar un
     * método wrapper confirm_booking_if_paid() que sí devuelva WP_Error.
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
     *
     * BK-BUG-A FIX: race condition entre dos procesos concurrentes de cancelación.
     * ANTES: el SELECT fuera de la transacción + UPDATE sin status guard permitía que
     * dos procesos (ej: WC order-status-changed hook + cron auto-cancel) leyeran ambos
     * el booking como 'confirmed', ambos entraran al bloque de cancelación, y ambos
     * ejecutaran el UPDATE — el segundo UPDATE pisaba al primero, y el hook
     * `ltms_booking_cancelled` se disparaba dos veces, causando:
     *   - Doble liberación de slots (booked podría quedar negativo si no fuera por GREATEST(0, ...)).
     *   - Doble procesamiento de reembolso en LTMS_Booking_Policy_Handler.
     *   - Doble ejecución de listeners de ltms_booking_cancelled (emails, comisiones, etc.).
     *
     * FIX:
     *   1. START TRANSACTION al inicio (antes del SELECT).
     *   2. SELECT ... FOR UPDATE — bloquea la fila para que el segundo proceso espere.
     *   3. Verificación de status dentro de la transacción (atómica respecto al FOR UPDATE).
     *   4. UPDATE con status guard: WHERE id = %d AND status = %s (el status que leímos).
     *      Si affected_rows = 0, otro proceso ya cambió el status → ROLLBACK + WP_Error('concurrent_cancel').
     *
     * @param int    $booking_id   ID de la reserva.
     * @param string $cancelled_by Quien cancela ('system', 'woocommerce', 'admin', etc.).
     * @param string $notes        Notas / motivo de cancelación.
     * @return true|\WP_Error True si se canceló, WP_Error si no se pudo cancelar.
     */
    public static function cancel_booking( int $booking_id, string $cancelled_by = 'system', string $notes = '' ): bool|\WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'lt_bookings';

        // BK-BUG-A FIX: START TRANSACTION BEFORE the SELECT so the FOR UPDATE lock
        // is held for the duration of the read-modify-write cycle. Without this, two
        // concurrent cancel_booking() calls could both SELECT, both see 'confirmed',
        // and both UPDATE — even with a status guard, the second UPDATE would see
        // affected_rows=0 and we'd waste work. With FOR UPDATE, the second call blocks
        // until the first COMMITs, then sees the new 'cancelled' status and exits early.
        $wpdb->query( 'START TRANSACTION' );

        try {
            // BK-BUG-A FIX: SELECT ... FOR UPDATE — adquiere un lock exclusivo sobre la
            // fila del booking. Cualquier otro proceso que intente cancelar la misma
            // reserva se bloqueará aquí hasta que hagamos COMMIT o ROLLBACK.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $booking = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d FOR UPDATE", $booking_id ),
                ARRAY_A
            );

            if ( ! $booking ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'not_found', __( 'Reserva no encontrada.', 'ltms' ) );
            }

            // BK-BUG-A FIX: verificación de status DENTRO de la transacción (atómica
            // respecto al FOR UPDATE). Antes esta verificación era fuera de la tx, así
            // que entre el SELECT y el UPDATE otro proceso podía cambiar el status.
            if ( in_array( $booking['status'], [ 'cancelled', 'checked_out', 'completed' ], true ) ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'already_cancelled', __( 'La reserva ya está cancelada o no se puede cancelar en su estado actual.', 'ltms' ) );
            }

            // BK-BUG-A FIX: UPDATE con status guard. El WHERE incluye el status que
            // leímos en el SELECT FOR UPDATE — si otro proceso ya cambió el status entre
            // nuestro SELECT y nuestro UPDATE (lo cual no debería pasar porque tenemos
            // el lock, pero defense in depth), el UPDATE afectará 0 filas y sabremos
            // que hubo una carrera.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $updated = $wpdb->update(
                $table,
                [
                    'status'              => 'cancelled',
                    'cancelled_by'        => sanitize_text_field( $cancelled_by ),
                    'cancel_notes'        => sanitize_textarea_field( $notes ),
                    'cancellation_reason' => sanitize_textarea_field( $notes ),
                    'updated_at'          => current_time( 'mysql' ),
                ],
                [
                    'id'     => $booking_id,
                    // Status guard: solo actualizar si el status actual es el que leímos
                    // en el SELECT FOR UPDATE. Esto previene la doble-cancelación incluso
                    // si el lock FOR UPDATE fallara (ej: MySQL REPEATABLE READ con isolation level raro).
                    'status' => $booking['status'],
                ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d', '%s' ]
            );

            if ( false === $updated ) {
                $wpdb->query( 'ROLLBACK' );
                self::log_warning_static( 'booking', 'cancel_booking DB error on UPDATE: ' . $wpdb->last_error );
                return new \WP_Error( 'cancel_db_error', __( 'Error de base de datos al cancelar la reserva.', 'ltms' ) );
            }

            if ( 0 === $updated ) {
                // BK-BUG-A: 0 filas afectadas → otro proceso cambió el status entre nuestro
                // SELECT FOR UPDATE y nuestro UPDATE. Esto no debería ocurrir bajo FOR UPDATE,
                // pero si ocurre (ej: trigger que modifica status), abortar para evitar
                // procesar la cancelación dos veces.
                $wpdb->query( 'ROLLBACK' );
                self::log_warning_static( 'booking', sprintf( 'cancel_booking concurrent_cancel: booking #%d status changed between SELECT FOR UPDATE and UPDATE.', $booking_id ) );
                return new \WP_Error( 'concurrent_cancel', __( 'La reserva fue cancelada por otro proceso simultáneamente.', 'ltms' ) );
            }

            // Release slots.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
            self::log_warning_static( 'booking', 'cancel_booking exception: ' . $e->getMessage() );
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
     * AUDIT-BOOKING-ENGINE #6 FIX: bloquea fechas para un producto (mantenimiento,
     * holidays, closure). Crea o actualiza slots con is_blocked=1.
     *
     * @param int    $product_id
     * @param string $date_from   YYYY-MM-DD
     * @param string $date_to     YYYY-MM-DD (inclusive)
     * @param string $reason      Motivo del bloqueo (ej: 'Mantenimiento', 'Holiday').
     * @return int Número de fechas bloqueadas.
     */
    public static function block_dates( int $product_id, string $date_from, string $date_to, string $reason = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_booking_slots';

        // Ensure slots exist for the range.
        self::ensure_slots( $product_id, $date_from, $date_to );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET is_blocked = 1, block_reason = %s, updated_at = %s
             WHERE product_id = %d AND slot_date >= %s AND slot_date <= %s",
            sanitize_text_field( $reason ),
            current_time( 'mysql' ),
            $product_id,
            $date_from,
            $date_to
        ) );

        if ( class_exists( 'LTMS_Core_Logger' ) && $affected ) {
            LTMS_Core_Logger::info( 'BOOKING_DATES_BLOCKED',
                sprintf( 'Product #%d: %d dates blocked (%s to %s, reason: %s)', $product_id, $affected, $date_from, $date_to, $reason ),
                [ 'product_id' => $product_id, 'from' => $date_from, 'to' => $date_to, 'reason' => $reason ]
            );
        }

        return (int) $affected;
    }

    /**
     * AUDIT-BOOKING-ENGINE #6 FIX: desbloquea fechas previamente bloqueadas.
     *
     * @param int    $product_id
     * @param string $date_from
     * @param string $date_to
     * @return int Número de fechas desbloqueadas.
     */
    public static function unblock_dates( int $product_id, string $date_from, string $date_to ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_booking_slots';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET is_blocked = 0, block_reason = '', updated_at = %s
             WHERE product_id = %d AND slot_date >= %s AND slot_date <= %s",
            current_time( 'mysql' ),
            $product_id,
            $date_from,
            $date_to
        ) );

        if ( class_exists( 'LTMS_Core_Logger' ) && $affected ) {
            LTMS_Core_Logger::info( 'BOOKING_DATES_UNBLOCKED',
                sprintf( 'Product #%d: %d dates unblocked (%s to %s)', $product_id, $affected, $date_from, $date_to ),
                [ 'product_id' => $product_id, 'from' => $date_from, 'to' => $date_to ]
            );
        }

        return (int) $affected;
    }

    /**
     * AUDIT-BOOKING-ENGINE #6 FIX: AJAX handler para bloquear fechas.
     */
    public static function ajax_block_dates(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id || ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $date_from  = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to    = sanitize_text_field( $_POST['date_to'] ?? '' );
        $reason     = sanitize_text_field( $_POST['reason'] ?? '' );

        if ( ! $product_id || ! $date_from || ! $date_to ) {
            wp_send_json_error( [ 'message' => __( 'Faltan datos.', 'ltms' ) ] );
        }

        // Verificar ownership.
        $owner = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
        if ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado.', 'ltms' ) ], 403 );
        }

        $count = self::block_dates( $product_id, $date_from, $date_to, $reason );
        wp_send_json_success( [ 'blocked' => $count, 'message' => sprintf( _n( '%d fecha bloqueada.', '%d fechas bloqueadas.', $count, 'ltms' ), $count ) ] );
    }

    /**
     * AUDIT-BOOKING-ENGINE #6 FIX: AJAX handler para desbloquear fechas.
     */
    public static function ajax_unblock_dates(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id || ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $date_from  = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to    = sanitize_text_field( $_POST['date_to'] ?? '' );

        if ( ! $product_id || ! $date_from || ! $date_to ) {
            wp_send_json_error( [ 'message' => __( 'Faltan datos.', 'ltms' ) ] );
        }

        $owner = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
        if ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado.', 'ltms' ) ], 403 );
        }

        $count = self::unblock_dates( $product_id, $date_from, $date_to );
        wp_send_json_success( [ 'unblocked' => $count, 'message' => sprintf( _n( '%d fecha desbloqueada.', '%d fechas desbloqueadas.', $count, 'ltms' ), $count ) ] );
    }

    /**
     * Limpia reservas pending sin pago > 30 min.
     *
     * AUDIT-BOOKING-ENGINE #5 FIX: NO cancelar reservas cuyo WC order tiene
     * status 'pending' o 'on-hold' — esos pedidos pueden estar esperando
     * pago via PSE/bank transfer/OXXO que tarda más de 30 min. Solo
     * cancelar reservas donde el WC order NO existe o ya fue cancelado/failed.
     */
    public static function cleanup_pending_bookings(): void {
        global $wpdb;
        $minutes = (int) LTMS_Core_Config::get( 'ltms_booking_pending_timeout_minutes', 30 );

        // AUDIT-BOOKING-ENGINE #5: excluir reservas con WC order en estado
        // pendiente/on-hold (pago asíncrono en proceso).
        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.product_id, b.checkin_date, b.checkout_date, b.wc_order_id
                 FROM {$wpdb->prefix}lt_bookings b
                 LEFT JOIN {$wpdb->prefix}wc_orders o ON o.id = b.wc_order_id
                 WHERE b.status = 'pending'
                   AND b.created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
                   AND (
                       b.wc_order_id = 0
                       OR o.status IS NULL
                       OR o.status NOT IN ('pending', 'on-hold', 'processing')
                   )",
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
                [ 'status' => 'cancelled', 'cancel_notes' => 'auto-expired (no payment)', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $b['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'BOOKING_AUTO_EXPIRED',
                    sprintf( 'Booking #%d auto-expired (no payment after %d min, wc_order=%d)', $b['id'], $minutes, $b['wc_order_id'] ),
                    [ 'booking_id' => $b['id'], 'wc_order_id' => $b['wc_order_id'] ]
                );
            }
        }
    }

    // ── WooCommerce hooks ────────────────────────────────────────────────

    /**
     * Crea la reserva cuando se crea la orden WC.
     *
     * AUDIT-BOOKING-ENGINE #2 FIX: manejar WP_Error de create_booking.
     * Antes solo se capturaba Throwable, no WP_Error → si create_booking
     * retornaba WP_Error (slot taken, DB error), el cliente pagaba pero
     * la reserva nunca se creaba → dinero sin reserva.
     */
    public static function create_booking_from_order( \WC_Order $order ): void {
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

            $vendor_id = (int) get_post_meta( $product->get_id(), '_ltms_vendor_id', true );

            $result = self::create_booking(
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
                    'booking_type'    => method_exists( $product, 'get_booking_type' )  ? $product->get_booking_type()  : 'accommodation',
                    'checkin_time'    => method_exists( $product, 'get_checkin_time' )  ? $product->get_checkin_time()  : '',
                    'checkout_time'   => method_exists( $product, 'get_checkout_time' ) ? $product->get_checkout_time() : '',
                ]
            );

            // AUDIT-BOOKING-ENGINE #2 FIX: manejar WP_Error.
            if ( is_wp_error( $result ) ) {
                self::log_warning_static( 'booking',
                    sprintf( 'create_booking_from_order FAILED for order #%d: %s (%s)',
                        $order->get_id(),
                        $result->get_error_message(),
                        $result->get_error_code()
                    )
                );
                // Marcar el order con error para que el admin pueda reintentar manualmente.
                $order->update_meta_data( '_ltms_booking_error', $result->get_error_message() );
                $order->update_meta_data( '_ltms_booking_error_code', $result->get_error_code() );
                $order->add_order_note( sprintf( __( 'Error al crear reserva: %s', 'ltms' ), $result->get_error_message() ) );
                $order->save();
            }
        }
    }

    /**
     * AUDIT-BOOKING-ENGINE #1 FIX: confirma reservas pending cuando se
     * completa el pago del WC order.
     *
     * Antes confirm_booking() solo se llamaba desde unit tests — en producción
     * las reservas quedaban en 'pending' para siempre. El cliente nunca
     * recibía el email de "Reserva Confirmada", los reminders nunca se
     * agendaban, y el lifecycle post-confirmación estaba muerto.
     *
     * @param int $order_id WC order ID.
     * @return void
     */
    public static function confirm_booking_on_payment( int $order_id ): void {
        global $wpdb;

        // Buscar reservas pendientes para este order.
        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}lt_bookings WHERE wc_order_id = %d AND status = 'pending'",
                $order_id
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $bookings as $b ) {
            $booking_id = (int) $b['id'];
            $confirmed  = self::confirm_booking( $booking_id );

            if ( $confirmed && class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'BOOKING_CONFIRMED_ON_PAYMENT',
                    sprintf( 'Booking #%d confirmed after payment of order #%d', $booking_id, $order_id ),
                    [ 'booking_id' => $booking_id, 'order_id' => $order_id ]
                );
            }
        }
    }

    /**
     * AUDIT-BOOKING-ENGINE #11 FIX: transiciones de lifecycle de reservas.
     * Antes el admin/vendor dashboard solo exponía CANCEL — Confirm,
     * Check-in, Check-out, y Complete eran imposibles desde la UI.
     */

    /**
     * Marca una reserva como check-in (cliente llega al establecimiento).
     *
     * @param int $booking_id
     * @return bool
     */
    public static function check_in( int $booking_id ): bool {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'lt_bookings',
            [ 'status' => 'checked_in', 'check_in_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $booking_id, 'status' => 'confirmed' ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( $rows ) {
            do_action( 'ltms_booking_checked_in', $booking_id );
        }
        return (bool) $rows;
    }

    /**
     * Marca una reserva como check-out (cliente deja el establecimiento).
     *
     * @param int $booking_id
     * @return bool
     */
    public static function check_out( int $booking_id ): bool {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'lt_bookings',
            [ 'status' => 'checked_out', 'check_out_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $booking_id, 'status' => 'checked_in' ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( $rows ) {
            do_action( 'ltms_booking_checked_out', $booking_id );
        }
        return (bool) $rows;
    }

    /**
     * Marca una reserva como completada (post check-out, todo OK).
     *
     * @param int $booking_id
     * @return bool
     */
    public static function complete_booking( int $booking_id ): bool {
        global $wpdb;
        $rows = $wpdb->update(
            $wpdb->prefix . 'lt_bookings',
            [ 'status' => 'completed', 'completed_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $booking_id, 'status' => 'checked_out' ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
        if ( $rows ) {
            do_action( 'ltms_booking_completed', $booking_id );
        }
        return (bool) $rows;
    }

    /**
     * AUDIT-RD-BK BK-1 FIX: verifica que el usuario actual es el vendor dueño
     * del booking (o un admin). Los handlers ajax_confirm / ajax_check_in /
     * ajax_check_out / ajax_complete NO validaban ownership — cualquier vendor
     * autenticado podía cambiar el lifecycle de CUALQUIER reserva del marketplace
     * (confirmar sin pago, forzar check-in anticipado en reservas ajenas, etc.).
     *
     * @param int $booking_id
     * @return bool True si el current user es el vendor del booking o admin.
     */
    private static function current_user_owns_booking( int $booking_id ): bool {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $vendor_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT vendor_id FROM `{$wpdb->prefix}lt_bookings` WHERE id = %d", $booking_id )
        );
        if ( ! $vendor_id ) {
            return false;
        }
        return $vendor_id === get_current_user_id();
    }

    /**
     * AUDIT-RD-BK BK-2 FIX: verifica que el WC order asociado al booking esté
     * efectivamente pagado antes de permitir confirmación manual vía AJAX.
     * El path legítimo (woocommerce_payment_complete → confirm_booking_on_payment)
     * ya garantiza pago; este check bloquea el bypass donde un vendor confirmaba
     * reservas con WC order en 'pending'/'on-hold'/'failed'.
     *
     * @param int $booking_id
     * @return true|\WP_Error  True si está pagado (o sin WC order). WP_Error si no.
     */
    private static function assert_booking_paid( int $booking_id ): bool|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wc_order_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT wc_order_id FROM `{$wpdb->prefix}lt_bookings` WHERE id = %d", $booking_id )
        );
        // wc_order_id=0 (booking legacy sin WC order) se permite confirmar.
        if ( $wc_order_id <= 0 ) {
            return true;
        }
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) {
            return new \WP_Error( 'order_not_found', __( 'No se puede confirmar: el pedido asociado no existe.', 'ltms' ) );
        }
        if ( ! $order->is_paid() ) {
            return new \WP_Error(
                'not_paid',
                __( 'No se puede confirmar la reserva: el pedido asociado aún no ha sido pagado.', 'ltms' )
            );
        }
        return true;
    }

    /**
     * AUDIT-BOOKING-ENGINE #11 FIX: AJAX handler para confirmar reserva manualmente.
     */
    public static function ajax_confirm(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        // AUDIT-RD-BK BK-1 FIX: ownership check.
        if ( ! self::current_user_owns_booking( $booking_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado sobre esta reserva.', 'ltms' ) ], 403 );
        }
        // AUDIT-RD-BK BK-2 FIX: payment check — no confirmar sin pago.
        $paid = self::assert_booking_paid( $booking_id );
        if ( is_wp_error( $paid ) ) {
            wp_send_json_error( [ 'message' => $paid->get_error_message() ], 400 );
        }
        $ok = self::confirm_booking( $booking_id );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Reserva confirmada.', 'ltms' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudo confirmar (¿ya está confirmada?).', 'ltms' ) ] );
        }
    }

    /**
     * AUDIT-BOOKING-ENGINE #11 FIX: AJAX handler para check-in.
     */
    public static function ajax_check_in(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        // AUDIT-RD-BK BK-1 FIX: ownership check.
        if ( ! self::current_user_owns_booking( $booking_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado sobre esta reserva.', 'ltms' ) ], 403 );
        }
        $ok = self::check_in( $booking_id );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Check-in registrado.', 'ltms' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudo hacer check-in (¿no está confirmada?).', 'ltms' ) ] );
        }
    }

    /**
     * AUDIT-BOOKING-ENGINE #11 FIX: AJAX handler para check-out.
     */
    public static function ajax_check_out(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        // AUDIT-RD-BK BK-1 FIX: ownership check.
        if ( ! self::current_user_owns_booking( $booking_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado sobre esta reserva.', 'ltms' ) ], 403 );
        }
        $ok = self::check_out( $booking_id );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Check-out registrado.', 'ltms' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudo hacer check-out (¿no está en check-in?).', 'ltms' ) ] );
        }
    }

    /**
     * AUDIT-BOOKING-ENGINE #11 FIX: AJAX handler para completar reserva.
     */
    public static function ajax_complete(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }
        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        // AUDIT-RD-BK BK-1 FIX: ownership check.
        if ( ! self::current_user_owns_booking( $booking_id ) ) {
            wp_send_json_error( [ 'message' => __( 'No autorizado sobre esta reserva.', 'ltms' ) ], 403 );
        }
        $ok = self::complete_booking( $booking_id );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Reserva completada.', 'ltms' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudo completar (¿no está en check-out?).', 'ltms' ) ] );
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
            self::log_warning_static( 'booking', 'on_order_cancelled exception: ' . $e->getMessage() );
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
