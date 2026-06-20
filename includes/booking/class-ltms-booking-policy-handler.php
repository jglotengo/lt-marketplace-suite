<?php
/**
 * LTMS Booking Policy Handler
 *
 * Gestiona políticas de cancelación y procesa reembolsos según reglas.
 * Tipos: flexible, moderate, strict, non_refundable.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/booking
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Booking_Policy_Handler
 */
class LTMS_Booking_Policy_Handler {

    use LTMS_Logger_Aware;

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        // M-BOOKING-PLAN-03: AJAX del panel de vendedor (tab Políticas).
        add_action( 'wp_ajax_ltms_get_vendor_policies',   [ self::class, 'ajax_get_vendor_policies' ] );
        add_action( 'wp_ajax_ltms_save_vendor_policy',    [ self::class, 'ajax_save_vendor_policy' ] );
        add_action( 'wp_ajax_ltms_delete_vendor_policy',  [ self::class, 'ajax_delete_vendor_policy' ] );
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Crea políticas por defecto para un vendedor recién aprobado.
     */
    public static function setup_default_policies( int $vendor_id ): void {
        global $wpdb;

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lt_booking_policies WHERE vendor_id = %d",
                $vendor_id
            )
        );
        if ( $exists ) return;

        $defaults = [
            [
                'name'                => __( 'Flexible', 'ltms' ),
                'policy_type'         => 'flexible',
                'free_cancel_hours'   => 24,
                'partial_refund_pct'  => 100,
                'partial_refund_hours'=> 48,
                'non_refundable_pct'  => 0,
                'is_default'          => 1,
            ],
            [
                'name'                => __( 'Moderada', 'ltms' ),
                'policy_type'         => 'moderate',
                'free_cancel_hours'   => 168, // 7 days
                'partial_refund_pct'  => 50,
                'partial_refund_hours'=> 72,  // 3 days — partial window between 72h and 168h
                'non_refundable_pct'  => 0,
                'is_default'          => 0,
            ],
        ];

        foreach ( $defaults as $policy ) {
            $wpdb->insert(
                $wpdb->prefix . 'lt_booking_policies',
                array_merge( $policy, [
                    'vendor_id'  => $vendor_id,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ] )
            );
        }
    }

    /**
     * Obtiene la política de cancelación para una reserva.
     *
     * @param int $booking_id
     * @return array|null
     */
    public static function get_policy_for_booking( int $booking_id ): ?array {
        global $wpdb;

        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, p.post_title FROM {$wpdb->prefix}lt_bookings b LEFT JOIN {$wpdb->posts} p ON p.ID = b.product_id WHERE b.id = %d",
                $booking_id
            ),
            ARRAY_A
        );
        if ( ! $booking ) return null;

        $policy_id = (int) get_post_meta( (int) $booking['product_id'], '_ltms_policy_id', true );
        if ( $policy_id ) {
            $policy = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_booking_policies WHERE id = %d", $policy_id ),
                ARRAY_A
            );
            if ( $policy ) return $policy;
        }

        // Vendor default policy.
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lt_booking_policies WHERE vendor_id = %d ORDER BY id ASC LIMIT 1",
                (int) $booking['vendor_id']
            ),
            ARRAY_A
        );
    }

    /**
     * Calcula el monto de reembolso basado en la política y horas hasta check-in.
     *
     * @param int   $booking_id
     * @param array $booking    Row completo de la BD.
     * @return float Monto a reembolsar.
     */
    public static function calculate_refund_amount( int $booking_id, array $booking ): float {
        $policy = self::get_policy_for_booking( $booking_id );
        if ( ! $policy ) return 0.0;

        $hours_until_checkin = ( strtotime( $booking['checkin_date'] ) - time() ) / HOUR_IN_SECONDS;
        $total               = (float) $booking['total_price'];
        $deposit             = (float) ( $booking['deposit_amount'] ?? 0 );
        $paid                = 'deposit' === $booking['payment_mode'] ? $deposit : $total;

        $free_cancel_hours = (int) $policy['free_cancel_hours'];
        $partial_hours     = isset( $policy['partial_refund_hours'] ) ? (int) $policy['partial_refund_hours'] : 0;

        // Orden de ventanas (de más cercana al check-in a más lejana):
        //   >= free_cancel_hours → reembolso completo (cancelación gratuita).
        //   >= partial_hours     → reembolso parcial (ventana exterior).
        //   < partial_hours      → sin reembolso (o non_refundable_pct).
        //
        // Ejemplo con free_cancel_hours=24, partial_hours=48:
        //   Cancela con 50h de anticipación → cae fuera de la ventana gratuita (50h > 48h > 24h)
        //     pero como 50h >= free_cancel(24h): reembolso completo.   ← este caso
        //   Cancela con 30h de anticipación → 30h >= free_cancel(24h): reembolso completo.
        //   Cancela con 10h de anticipación → 10h < 24h: sin reembolso (o parcial si partial_hours < 24).
        //
        // Este orden es consistente con estimate_refund() en LTMS_Frontend_Customer_Bookings.

        if ( $hours_until_checkin >= $free_cancel_hours ) {
            // Cancelación gratuita — reembolso completo.
            return $paid;
        }

        if ( $partial_hours > 0 && $hours_until_checkin >= $partial_hours ) {
            // Dentro de la ventana de reembolso parcial.
            return round( $paid * (float) $policy['partial_refund_pct'] / 100, 2 );
        }

        if ( isset( $policy['non_refundable_pct'] ) && (float) $policy['non_refundable_pct'] > 0 ) {
            // Porción no reembolsable del vendedor.
            $refund_pct = 100 - (float) $policy['non_refundable_pct'];
            return round( $paid * $refund_pct / 100, 2 );
        }

        return 0.0;
    }

    /**
     * Procesa el reembolso según política al cancelar.
     */
    public static function process_cancellation_refund( int $booking_id, array $booking, string $cancelled_by ): void {
        try {
            $refund_amount = self::calculate_refund_amount( $booking_id, $booking );
            if ( $refund_amount <= 0 ) return;

            $wc_order_id = (int) $booking['wc_order_id'];
            if ( ! $wc_order_id ) return;

            $order = wc_get_order( $wc_order_id );
            if ( ! $order ) return;

            // Only auto-refund if order is paid.
            if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ], true ) ) return;

            $refund = wc_create_refund( [
                'amount'         => $refund_amount,
                'reason'         => sprintf(
                    /* translators: %s: cancelled_by */
                    __( 'Cancelación de reserva #%d — %s', 'ltms' ),
                    $booking_id,
                    $cancelled_by
                ),
                'order_id'       => $wc_order_id,
                'refund_payment' => true,
            ] );

            if ( is_wp_error( $refund ) ) {
                self::log_warning_static( 'booking', 'refund error booking #' . $booking_id . ': ' . $refund->get_error_message() );
            } else {
                do_action( 'ltms_booking_refund_processed', $booking_id, $refund_amount, $refund );
            }
        } catch ( \Throwable $e ) {
            self::log_warning_static( 'booking', 'process_cancellation_refund exception: ' . $e->getMessage() );
        }
    }

    /**
     * Retorna todas las políticas de un vendedor.
     */
    public static function get_vendor_policies( int $vendor_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lt_booking_policies WHERE vendor_id = %d ORDER BY id ASC",
                $vendor_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Guarda (upsert) una política de cancelación de un vendedor.
     *
     * @param array $data Keys: id, vendor_id, name, policy_type, free_cancel_hours,
     *                     partial_refund_pct, partial_refund_hours, is_default
     * @return int|\WP_Error
     */
    public static function save_policy( array $data ): int|\WP_Error {
        global $wpdb;

        $vendor_id = (int) ( $data['vendor_id'] ?? 0 );
        if ( ! $vendor_id ) {
            return new \WP_Error( 'invalid_vendor', __( 'Vendedor no válido.', 'ltms' ) );
        }

        $fields = [
            'vendor_id'             => $vendor_id,
            'name'                  => sanitize_text_field( $data['name'] ?? '' ),
            'policy_type'           => sanitize_text_field( $data['policy_type'] ?? 'flexible' ),
            'free_cancel_hours'     => absint( $data['free_cancel_hours'] ?? 24 ),
            'partial_refund_pct'    => min( 100, absint( $data['partial_refund_pct'] ?? 50 ) ),
            'partial_refund_hours'  => absint( $data['partial_refund_hours'] ?? 0 ),
            'is_default'            => ! empty( $data['is_default'] ) ? 1 : 0,
            'updated_at'            => current_time( 'mysql' ),
        ];

        if ( ! $fields['name'] ) {
            return new \WP_Error( 'missing_name', __( 'El nombre es obligatorio.', 'ltms' ) );
        }

        $id = (int) ( $data['id'] ?? 0 );

        // Si se marca como predeterminada, desmarcar las demás del vendedor.
        if ( $fields['is_default'] ) {
            $wpdb->update(
                $wpdb->prefix . 'lt_booking_policies',
                [ 'is_default' => 0 ],
                [ 'vendor_id' => $vendor_id ]
            );
        }

        if ( $id ) {
            $wpdb->update( $wpdb->prefix . 'lt_booking_policies', $fields, [ 'id' => $id, 'vendor_id' => $vendor_id ] );
            return $id;
        }

        $fields['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'lt_booking_policies', $fields );
        if ( ! $wpdb->insert_id ) {
            return new \WP_Error( 'db_error', __( 'Error al guardar la política.', 'ltms' ) );
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Elimina una política de cancelación de un vendedor (con verificación de ownership).
     */
    public static function delete_policy( int $id, int $vendor_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'lt_booking_policies',
            [ 'id' => $id, 'vendor_id' => $vendor_id ]
        );
    }

    // ── AJAX (panel de vendedor) ────────────────────────────────────────

    public static function ajax_get_vendor_policies(): void {
        check_ajax_referer( 'ltms_nonce', 'nonce' );
        wp_send_json_success( self::get_vendor_policies( get_current_user_id() ) );
    }

    public static function ajax_save_vendor_policy(): void {
        check_ajax_referer( 'ltms_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        $result    = self::save_policy( [
            'id'                    => absint( $_POST['policy_id'] ?? 0 ),
            'vendor_id'             => $vendor_id,
            'name'                  => wp_unslash( $_POST['policy_name'] ?? '' ),
            'policy_type'           => $_POST['policy_type'] ?? 'flexible',
            'free_cancel_hours'     => $_POST['free_cancel_hours'] ?? 24,
            'partial_refund_pct'    => $_POST['partial_refund_pct'] ?? 50,
            'partial_refund_hours'  => $_POST['partial_refund_hours'] ?? 0,
            'is_default'            => $_POST['is_default'] ?? 0,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => __( 'Política guardada correctamente.', 'ltms' ), 'id' => $result ] );
    }

    public static function ajax_delete_vendor_policy(): void {
        check_ajax_referer( 'ltms_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        $policy_id = absint( $_POST['policy_id'] ?? 0 );

        if ( ! $policy_id || ! self::delete_policy( $policy_id, $vendor_id ) ) {
            wp_send_json_error( __( 'No se pudo eliminar la política (verifica que te pertenezca).', 'ltms' ) );
        }

        wp_send_json_success( [ 'message' => __( 'Política eliminada.', 'ltms' ) ] );
    }
}
