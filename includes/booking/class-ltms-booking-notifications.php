<?php
/**
 * LTMS Booking Notifications
 *
 * Envía los emails de reserva (cliente y vendedor) usando los templates
 * que ya existen en templates/emails/email-booking-*.php.
 *
 * M-BOOKING-UI-03: estos templates estaban completos y bien construidos,
 * pero ningún punto del código los cargaba — LTMS_Booking_Manager nunca
 * tenía una sola referencia a 'mail'/'email'/'notify'. El comprador no
 * recibía confirmación de reserva con check-in/check-out/política de
 * cancelación (solo el email genérico de "pedido completado" de WooCommerce,
 * que no incluye esos datos), y el vendedor no se enteraba de nuevas
 * reservas salvo entrando manualmente a su panel.
 *
 * Se conecta vía hooks (ltms_booking_confirmed, ltms_booking_cancelled,
 * y woocommerce_checkout_order_created vía create_booking_from_order) en
 * lugar de tocar LTMS_Booking_Manager directamente, siguiendo el mismo
 * patrón de bajo acoplamiento que el resto de listeners del plugin
 * (ej. LTMS_Order_Paid_Listener).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @since      2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class LTMS_Booking_Notifications
 */
final class LTMS_Booking_Notifications {

    use LTMS_Logger_Aware;

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        // M-BOOKING-UI-03: nueva reserva creada (pending) -> avisar al vendedor.
        // create_booking_from_order() no dispara su propio hook de "creada",
        // así que nos enganchamos al mismo hook de WC que ya usa LTMS_Booking_Calendar
        // para capturar la reserva, en prioridad posterior (20) para asegurar que
        // create_booking_from_order ya corrió y el registro existe en bkr_lt_bookings.
        add_action( 'woocommerce_checkout_order_created', [ self::class, 'on_order_created' ], 20 );

        add_action( 'ltms_booking_confirmed', [ self::class, 'on_booking_confirmed' ] );
        add_action( 'ltms_booking_cancelled', [ self::class, 'on_booking_cancelled' ], 10, 3 );
    }

    // ── Listeners ────────────────────────────────────────────────────────

    /**
     * Tras crear el pedido (y por tanto la reserva vía create_booking_from_order,
     * que corre en el mismo hook con prioridad por defecto 10), notifica al
     * vendedor de la nueva reserva pendiente.
     */
    public static function on_order_created( \WC_Order $order ): void {
        try {
            $booking = self::get_booking_by_order( $order->get_id() );
            if ( ! $booking ) {
                return; // El pedido no contenía ningún item reservable.
            }
            self::send_vendor_new_booking( $booking );
        } catch ( \Throwable $e ) {
            self::log_warning_static( 'booking', 'on_order_created notification failed: ' . $e->getMessage() );
        }
    }

    public static function on_booking_confirmed( int $booking_id ): void {
        try {
            $booking = self::get_booking( $booking_id );
            if ( ! $booking ) return;
            self::send_customer_confirmed( $booking );
        } catch ( \Throwable $e ) {
            self::log_warning_static( 'booking', 'on_booking_confirmed notification failed: ' . $e->getMessage() );
        }
    }

    /**
     * @param int    $booking_id
     * @param array  $booking      Snapshot del booking ANTES de cancelar (lo pasa cancel_booking()).
     * @param string $cancelled_by 'system' | 'vendor' | 'admin' | 'customer'
     */
    public static function on_booking_cancelled( int $booking_id, array $booking, string $cancelled_by ): void {
        try {
            // El snapshot que llega por el hook es anterior al UPDATE; recargamos
            // para tener el customer_id/product_id resueltos en la consulta join.
            $fresh = self::get_booking( $booking_id ) ?? $booking;
            $refund_amount = class_exists( 'LTMS_Booking_Policy_Handler' )
                ? LTMS_Booking_Policy_Handler::calculate_refund_amount( $booking_id, $booking )
                : 0.0;
            self::send_customer_cancelled( $fresh, $refund_amount );
        } catch ( \Throwable $e ) {
            self::log_warning_static( 'booking', 'on_booking_cancelled notification failed: ' . $e->getMessage() );
        }
    }

    // ── Envío de emails ──────────────────────────────────────────────────

    private static function send_customer_confirmed( array $booking ): void {
        if ( get_option( 'ltms_email_booking_confirmed', 'yes' ) !== 'yes' ) return;

        $customer = get_userdata( (int) $booking['customer_id'] );
        if ( ! $customer || ! $customer->user_email ) return;

        $html = self::render_template( 'email-booking-confirmed.php', [
            'booking' => $booking,
            'product' => null,
        ] );
        if ( ! $html ) return;

        self::wc_mail( $customer->user_email, __( 'Tu reserva ha sido confirmada', 'ltms' ), $html );
    }

    private static function send_customer_cancelled( array $booking, float $refund_amount ): void {
        if ( get_option( 'ltms_email_booking_cancelled', 'yes' ) !== 'yes' ) return;

        $customer = get_userdata( (int) $booking['customer_id'] );
        if ( ! $customer || ! $customer->user_email ) return;

        $html = self::render_template( 'email-booking-cancelled.php', [
            'booking'        => $booking,
            'refund_amount'  => $refund_amount,
        ] );
        if ( ! $html ) return;

        self::wc_mail( $customer->user_email, __( 'Tu reserva ha sido cancelada', 'ltms' ), $html );
    }

    private static function send_vendor_new_booking( array $booking ): void {
        if ( get_option( 'ltms_email_vendor_new_booking', 'yes' ) !== 'yes' ) return;

        $vendor = get_userdata( (int) $booking['vendor_id'] );
        if ( ! $vendor || ! $vendor->user_email ) return;

        $html = self::render_template( 'email-vendor-booking-new.php', [
            'booking' => $booking,
        ] );
        if ( ! $html ) return;

        self::wc_mail( $vendor->user_email, __( '¡Tienes una nueva reserva!', 'ltms' ), $html );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Carga un template de templates/emails/ extrayendo las variables dadas,
     * envuelto por el wrapper de email de WooCommerce (header/footer ya
     * están incluidos dentro de cada template).
     */
    private static function render_template( string $file, array $vars ): string {
        $path = LTMS_PLUGIN_DIR . 'templates/emails/' . $file;
        if ( ! file_exists( $path ) ) {
            self::log_warning_static( 'booking', "Template de email no encontrado: {$file}" );
            return '';
        }
        extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    /**
     * Envía el HTML ya renderizado usando el mailer de WooCommerce si está
     * disponible (mismo wrapper visual que el resto de emails transaccionales
     * del sitio), con fallback a wp_mail si WC()->mailer() no existe.
     */
    private static function wc_mail( string $to, string $subject, string $html_body ): void {
        if ( function_exists( 'WC' ) && WC()->mailer() ) {
            $mailer = WC()->mailer();
            $mailer->send( $to, $subject, $html_body, "Content-Type: text/html\r\n" );
            return;
        }
        // v2.9.119 NOTIFICATIONS-AUDIT P1-1 FIX: use named function for filter removal.
        // Before, arrow functions (fn() => 'text/html') created a NEW closure on each
        // call, so remove_filter() with another arrow function would NOT remove the
        // original filter (different closure instance). This left the content_type
        // filter permanently changed to text/html, affecting all subsequent wp_mail
        // calls in the same request. Now we use a static method reference which is
        // stable and can be properly removed.
        add_filter( 'wp_mail_content_type', [ __CLASS__, 'set_html_content_type' ] );
        wp_mail( $to, $subject, $html_body );
        remove_filter( 'wp_mail_content_type', [ __CLASS__, 'set_html_content_type' ] );
    }

    /**
     * v2.9.119 P1-1: Static method for wp_mail_content_type filter (stable reference).
     */
    public static function set_html_content_type(): string {
        return 'text/html';
    }

    private static function get_booking( int $booking_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, p.post_title AS product_name
                 FROM {$wpdb->prefix}lt_bookings b
                 LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id
                 WHERE b.id = %d",
                $booking_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private static function get_booking_by_order( int $wc_order_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, p.post_title AS product_name
                 FROM {$wpdb->prefix}lt_bookings b
                 LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id
                 WHERE b.wc_order_id = %d
                 ORDER BY b.id DESC LIMIT 1",
                $wc_order_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
