<?php
/**
 * LTMS Frontend Booking Handler
 *
 * Handlers AJAX para el panel del vendedor — sección "Mis Reservas".
 *
 * Acciones registradas:
 *   ltms_get_vendor_bookings        — lista paginada + stats
 *   ltms_get_vendor_booking_detail  — detalle de una reserva
 *   ltms_vendor_cancel_booking      — cancelar una reserva propia
 *
 * Todas requieren nonce `ltms_dashboard_nonce` y rol de vendedor LTMS.
 *
 * @package LTMS
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class LTMS_Frontend_Booking_Handler
 */
final class LTMS_Frontend_Booking_Handler {

    use LTMS_Logger_Aware;

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        $instance = new self();
        add_action( 'wp_ajax_ltms_get_vendor_bookings',       [ $instance, 'ajax_get_vendor_bookings' ] );
        add_action( 'wp_ajax_ltms_get_vendor_booking_detail', [ $instance, 'ajax_get_vendor_booking_detail' ] );
        add_action( 'wp_ajax_ltms_vendor_cancel_booking',     [ $instance, 'ajax_vendor_cancel_booking' ] );
        // M-BOOKING-UI-02: exportación CSV de reservas, filtrada al vendedor logueado.
        add_action( 'wp_ajax_ltms_export_vendor_bookings_csv', [ $instance, 'export_vendor_bookings_csv' ] );
    }

    // ── Helpers privados ───────────────────────────────────────────────────

    private function get_vendor_id(): int {
        return (int) get_current_user_id();
    }

    private function is_ltms_vendor(): bool {
        $user = wp_get_current_user();
        return ! empty( array_intersect(
            (array) $user->roles,
            [ 'ltms_vendor', 'ltms_vendor_premium', 'administrator' ]
        ) );
    }

    private function owns_booking( int $booking_id, int $vendor_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id FROM {$wpdb->prefix}lt_bookings WHERE id = %d AND vendor_id = %d",
            $booking_id,
            $vendor_id
        ) );
    }

    // ── AJAX: lista de reservas ───────────────────────────────────────────

    public function ajax_get_vendor_bookings(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! $this->is_ltms_vendor() ) {
            wp_send_json_error( __( 'Sin permiso.', 'ltms' ) );
            return;
        }

        global $wpdb;
        $vendor_id = $this->get_vendor_id();

        $status    = sanitize_text_field( wp_unslash( $_POST['status']    ?? '' ) );
        $date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
        $date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
        $page      = max( 1, (int) ( $_POST['page']     ?? 1 ) );
        $per_page  = min( 50, max( 5, (int) ( $_POST['per_page'] ?? 20 ) ) );
        $offset    = ( $page - 1 ) * $per_page;

        // WHERE dinámica con prepared statements
        $conds  = [ 'b.vendor_id = %d' ];
        $params = [ $vendor_id ];

        if ( $status && in_array( $status, [ 'pending','confirmed','checked_in','completed','cancelled' ], true ) ) {
            $conds[]  = 'b.status = %s';
            $params[] = $status;
        }
        if ( $date_from ) {
            $conds[]  = 'b.checkin_date >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $conds[]  = 'b.checkout_date <= %s';
            $params[] = $date_to;
        }

        $where = implode( ' AND ', $conds );
        $table = $wpdb->prefix . 'lt_bookings';

        // Contar total
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} b WHERE {$where}",
            ...$params
        ) );

        // Obtener página
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.wc_order_id, b.product_id, b.customer_id,
                    b.checkin_date, b.checkout_date, b.guests,
                    b.total_price, b.vendor_net, b.status, b.payment_mode, b.currency,
                    b.created_at,
                    p.post_title AS product_name,
                    u.display_name AS customer_name
             FROM {$table} b
             LEFT JOIN {$wpdb->posts} p   ON p.ID  = b.product_id
             LEFT JOIN {$wpdb->users} u   ON u.ID  = b.customer_id
             WHERE {$where}
             ORDER BY b.created_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge( $params, [ $per_page, $offset ] )
        ), ARRAY_A ) ?: [];

        // Stats globales (sin filtros de fecha ni estado, solo de este vendedor)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(status='pending')   AS pending,
               SUM(status='confirmed') AS confirmed,
               SUM(CASE WHEN status NOT IN ('cancelled') THEN COALESCE(vendor_net,0) ELSE 0 END) AS vendor_net
             FROM {$table}
             WHERE vendor_id = %d",
            $vendor_id
        ), ARRAY_A );

        wp_send_json_success( [
            'bookings' => $bookings,
            'total'    => $total,
            'stats'    => $stats_row ?: [],
        ] );
    }

    // ── AJAX: detalle de una reserva ──────────────────────────────────────

    public function ajax_get_vendor_booking_detail(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! $this->is_ltms_vendor() ) {
            wp_send_json_error( __( 'Sin permiso.', 'ltms' ) );
            return;
        }

        global $wpdb;
        $booking_id = (int) ( $_POST['booking_id'] ?? 0 );
        $vendor_id  = $this->get_vendor_id();

        if ( ! $booking_id || ! $this->owns_booking( $booking_id, $vendor_id ) ) {
            wp_send_json_error( __( 'Reserva no encontrada.', 'ltms' ) );
            return;
        }

        $table = $wpdb->prefix . 'lt_bookings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $b = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    p.post_title AS product_name,
                    u.display_name AS customer_name,
                    u.user_email   AS customer_email
             FROM {$table} b
             LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id
             LEFT JOIN {$wpdb->users} u ON u.ID = b.customer_id
             WHERE b.id = %d AND b.vendor_id = %d",
            $booking_id,
            $vendor_id
        ), ARRAY_A );

        if ( ! $b ) {
            wp_send_json_error( __( 'Reserva no encontrada.', 'ltms' ) );
            return;
        }

        wp_send_json_success( $b );
    }

    // ── AJAX: cancelar reserva propia ─────────────────────────────────────

    public function ajax_vendor_cancel_booking(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! $this->is_ltms_vendor() ) {
            wp_send_json_error( __( 'Sin permiso.', 'ltms' ) );
            return;
        }

        $booking_id = (int) ( $_POST['booking_id'] ?? 0 );
        $reason     = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
        $vendor_id  = $this->get_vendor_id();

        if ( ! $booking_id || ! $reason ) {
            wp_send_json_error( __( 'Datos incompletos.', 'ltms' ) );
            return;
        }

        if ( ! $this->owns_booking( $booking_id, $vendor_id ) ) {
            wp_send_json_error( __( 'Reserva no encontrada.', 'ltms' ) );
            return;
        }

        // Solo se puede cancelar si está pending o confirmed
        global $wpdb;
        $current_status = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT status FROM {$wpdb->prefix}lt_bookings WHERE id = %d",
            $booking_id
        ) );

        if ( ! in_array( $current_status, [ 'pending', 'confirmed' ], true ) ) {
            wp_send_json_error( __( 'Solo se pueden cancelar reservas pendientes o confirmadas.', 'ltms' ) );
            return;
        }

        if ( ! class_exists( 'LTMS_Booking_Manager' ) ) {
            wp_send_json_error( __( 'Módulo de reservas no disponible.', 'ltms' ) );
            return;
        }

        $result = LTMS_Booking_Manager::cancel_booking( $booking_id, 'vendor', $reason );

        if ( is_wp_error( $result ) ) {
            $this->log_warning( 'vendor_cancel_booking', 'booking #' . $booking_id . ': ' . $result->get_error_message() );
            wp_send_json_error( $result->get_error_message() );
            return;
        }

        wp_send_json_success( __( 'Reserva cancelada correctamente.', 'ltms' ) );
    }

    // ── Exportar CSV ───────────────────────────────────────────────────────

    /**
     * M-BOOKING-UI-02: exporta las reservas del vendedor logueado a CSV,
     * respetando los mismos filtros (status/date_from/date_to) que la tabla.
     * Usa nonce vía GET porque es una descarga directa (no AJAX/JSON).
     */
    public function export_vendor_bookings_csv(): void {
        if ( ! $this->is_ltms_vendor() ) {
            wp_die( esc_html__( 'Sin permiso.', 'ltms' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'ltms_export_vendor_bookings', 'nonce' );

        global $wpdb;
        $vendor_id = $this->get_vendor_id();
        $status    = sanitize_text_field( wp_unslash( $_GET['status']    ?? '' ) );
        $date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
        $date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) );

        $conds  = [ 'b.vendor_id = %d' ];
        $params = [ $vendor_id ];
        if ( $status && in_array( $status, [ 'pending', 'confirmed', 'checked_in', 'completed', 'cancelled' ], true ) ) {
            $conds[]  = 'b.status = %s';
            $params[] = $status;
        }
        if ( $date_from ) {
            $conds[]  = 'b.checkin_date >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $conds[]  = 'b.checkout_date <= %s';
            $params[] = $date_to;
        }
        $where = implode( ' AND ', $conds );
        $table = $wpdb->prefix . 'lt_bookings';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.wc_order_id, p.post_title AS product_name, u.display_name AS customer_name,
                    b.checkin_date, b.checkout_date, b.guests, b.total_price, b.vendor_net,
                    b.status, b.payment_mode, b.currency, b.created_at
             FROM {$table} b
             LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id
             LEFT JOIN {$wpdb->users} u ON u.ID = b.customer_id
             WHERE {$where}
             ORDER BY b.created_at DESC",
            ...$params
        ), ARRAY_A ) ?: [];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="mis-reservas-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Orden WC', 'Producto', 'Huésped', 'Check-in', 'Check-out', 'Huéspedes', 'Total', 'Neto', 'Estado', 'Modo Pago', 'Moneda', 'Creada' ] );
        foreach ( $bookings as $row ) {
            // FASE2 P1 FIX (CSV injection): prefix cells starting with =+-@
            // with a single quote to prevent formula injection in Excel/LibreOffice.
            // Customer display_name and product name are user-controlled.
            $safe_row = array_map( static function( $val ) {
                if ( is_string( $val ) && preg_match( '/^[=+\-@]/', $val ) ) {
                    return "'" . $val;
                }
                return $val;
            }, array_values( $row ) );
            fputcsv( $out, $safe_row );
        }
        fclose( $out );
        exit;
    }
}
