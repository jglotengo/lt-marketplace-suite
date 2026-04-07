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

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;
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
                'policy_type'         => 'flexible',
                'free_cancel_hours'   => 24,
                'partial_refund_pct'  => 100,
                'partial_refund_hours'=> 48,
                'non_refundable_pct'  => 0,
            ],
            [
                'policy_type'         => 'moderate',
                'free_cancel_hours'   => 72,
                'partial_refund_pct'  => 50,
                'partial_refund_hours'=> 168, // 7 days
                'non_refundable_pct'  => 0,
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

        if ( $hours_until_checkin >= (int) $policy['free_cancel_hours'] ) {
            return $paid; // Full refund within free cancel window.
        }

        if ( isset( $policy['partial_refund_hours'] ) && $hours_until_checkin >= (int) $policy['partial_refund_hours'] ) {
            return round( $paid * (float) $policy['partial_refund_pct'] / 100, 2 );
        }

        if ( isset( $policy['non_refundable_pct'] ) && (float) $policy['non_refundable_pct'] > 0 ) {
            // Non-refundable portion stays with vendor.
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
                error_log( 'LTMS refund error booking #' . $booking_id . ': ' . $refund->get_error_message() );
            } else {
                do_action( 'ltms_booking_refund_processed', $booking_id, $refund_amount, $refund );
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS process_cancellation_refund: ' . $e->getMessage() );
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
}
