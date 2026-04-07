<?php
/**
 * LTMS Admin Bookings
 *
 * Panel de administración global de reservas y compliance turístico.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Bookings
 */
class LTMS_Admin_Bookings {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'admin_menu', [ self::class, 'add_menu_pages' ] );
        add_action( 'wp_ajax_ltms_admin_booking_action', [ self::class, 'handle_ajax' ] );
        add_action( 'wp_ajax_ltms_admin_verify_rnt',     [ self::class, 'ajax_verify_rnt' ] );
        add_action( 'admin_post_ltms_export_bookings_csv', [ self::class, 'export_csv' ] );
    }

    public static function add_menu_pages(): void {
        add_submenu_page( 'lt-marketplace-suite', __( 'Reservas', 'ltms' ),            __( 'Reservas', 'ltms' ),        'manage_options', 'ltms-bookings',            [ self::class, 'render_bookings_list' ] );
        add_submenu_page( 'lt-marketplace-suite', __( 'Calendario', 'ltms' ),          __( 'Calendario', 'ltms' ),       'manage_options', 'ltms-booking-calendar',    [ self::class, 'render_booking_calendar' ] );
        add_submenu_page( 'lt-marketplace-suite', __( 'Compliance Turístico', 'ltms' ), __( 'Turismo / RNT', 'ltms' ),   'manage_options', 'ltms-tourism-compliance',   [ self::class, 'render_compliance_panel' ] );
    }

    public static function render_bookings_list(): void {
        global $wpdb;
        try {
            // phpcs:disable WordPress.Security.NonceVerification
            $status    = sanitize_text_field( $_GET['status']    ?? '' );
            $vendor_id = (int) ( $_GET['vendor_id']  ?? 0 );
            $date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
            $date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );
            $per_page  = 30;
            $page      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
            // phpcs:enable
            $offset  = ( $page - 1 ) * $per_page;
            $where   = '1=1';
            $params  = [];
            if ( $status )    { $where .= ' AND b.status = %s';          $params[] = $status; }
            if ( $vendor_id ) { $where .= ' AND b.vendor_id = %d';       $params[] = $vendor_id; }
            if ( $date_from ) { $where .= ' AND b.checkin_date >= %s';   $params[] = $date_from; }
            if ( $date_to )   { $where .= ' AND b.checkout_date <= %s';  $params[] = $date_to; }

            $sql      = "SELECT b.*, p.post_title AS product_name FROM {$wpdb->prefix}lt_bookings b LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id WHERE $where ORDER BY b.created_at DESC LIMIT $per_page OFFSET $offset";
            $bookings = empty( $params ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore
            include LTMS_PLUGIN_DIR . 'includes/admin/views/html-admin-bookings.php';
        } catch ( \Throwable $e ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
        }
    }

    public static function render_booking_calendar(): void {
        wp_enqueue_script( 'fullcalendar', 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.10/index.global.min.js', [], '6.1.10', true );
        echo '<div class="wrap"><h1>' . esc_html__( 'Calendario de Reservas', 'ltms' ) . '</h1><div id="ltms-admin-booking-calendar" style="max-width:1100px"></div></div>';
        wp_add_inline_script( 'fullcalendar', "document.addEventListener('DOMContentLoaded',function(){var el=document.getElementById('ltms-admin-booking-calendar');if(!el)return;new FullCalendar.Calendar(el,{initialView:'dayGridMonth',locale:'es',height:700,headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,listWeek'}}).render();});" );
    }

    public static function render_compliance_panel(): void {
        include LTMS_PLUGIN_DIR . 'includes/admin/views/html-admin-tourism-compliance.php';
    }

    public static function handle_ajax(): void {
        try {
            check_ajax_referer( 'ltms_admin_booking', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( __( 'Sin permiso.', 'ltms' ) ); return; }
            $booking_action = sanitize_text_field( $_POST['booking_action'] ?? '' );
            $booking_id     = (int) ( $_POST['booking_id'] ?? 0 );
            if ( 'cancel' === $booking_action && $booking_id && class_exists( 'LTMS_Booking_Manager' ) ) {
                $result = LTMS_Booking_Manager::cancel_booking( $booking_id, 'admin', sanitize_textarea_field( $_POST['notes'] ?? '' ) );
                is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( __( 'Reserva cancelada.', 'ltms' ) );
            } else {
                wp_send_json_error( __( 'Acción no reconocida.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public static function ajax_verify_rnt(): void {
        try {
            check_ajax_referer( 'ltms_admin_verify_rnt', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( __( 'Sin permiso.', 'ltms' ) ); return; }
            $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
            $approved  = (bool) ( $_POST['approved'] ?? false );
            $notes     = sanitize_textarea_field( $_POST['notes'] ?? '' );
            if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
                LTMS_Business_Tourism_Compliance::verify_rnt( $vendor_id, $approved, $notes );
                wp_send_json_success( $approved ? __( 'RNT aprobado.', 'ltms' ) : __( 'RNT rechazado.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public static function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
        check_admin_referer( 'ltms_export_bookings_csv' );
        global $wpdb;
        $bookings = $wpdb->get_results( "SELECT id, wc_order_id, product_id, vendor_id, customer_id, checkin_date, checkout_date, guests, total_price, status, payment_mode, currency, created_at FROM {$wpdb->prefix}lt_bookings ORDER BY created_at DESC", ARRAY_A ) ?: [];
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="ltms-bookings-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Orden WC', 'Producto', 'Vendedor', 'Cliente', 'Check-in', 'Check-out', 'Huéspedes', 'Total', 'Estado', 'Modo Pago', 'Moneda', 'Creada' ] );
        foreach ( $bookings as $row ) fputcsv( $out, array_values( $row ) );
        fclose( $out );
        exit;
    }
}
