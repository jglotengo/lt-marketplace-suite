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
                // Sin ventana de reembolso parcial: el valor anterior
                // (partial_refund_hours=48) era MAYOR que free_cancel_hours
                // (24), lo que invertía el orden que calculate_refund_amount()
                // espera (free_cancel_hours debe ser el límite SUPERIOR de la
                // ventana parcial, no el inferior — ver el seed de "Moderada"
                // más abajo: free=168, partial=72, ventana 72h-168h). Con el
                // orden invertido, esa rama nunca se ejecutaba: toda
                // cancelación con >=24h ya resolvía 100% en el primer if, y
                // cualquier otra caía directo a 0%, saltándose el tramo
                // intermedio por completo.
                //
                // En vez de solo corregir el orden, se elimina el tramo:
                // esto replica la política "Flexible" estándar de la
                // industria (Airbnb Flexible = reembolso completo hasta 24h
                // antes del check-in, sin reembolso después, sin tramo
                // parcial intermedio). El tramo parcial sigue existiendo y
                // funcionando correctamente en "Moderada", que es donde el
                // mercado sí lo usa.
                'partial_refund_pct'  => 0,
                'partial_refund_hours'=> 0,
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

        // v2.9.117 BOOKING-AUDIT P0-1 FIX: try BOTH meta keys for policy_id.
        // Before, this method read '_ltms_policy_id' but create_booking() saves
        // to '_ltms_booking_policy_id' (different key). The policy lookup ALWAYS
        // fell through to the vendor default, ignoring product-specific policies.
        // Now we try both keys (product-specific first, then vendor default).
        $policy_id = (int) get_post_meta( (int) $booking['product_id'], '_ltms_booking_policy_id', true );
        if ( ! $policy_id ) {
            $policy_id = (int) get_post_meta( (int) $booking['product_id'], '_ltms_policy_id', true );
        }
        // Also check the booking row's policy_id (set at create time by create_booking).
        if ( ! $policy_id && ! empty( $booking['policy_id'] ) ) {
            $policy_id = (int) $booking['policy_id'];
        }
        if ( $policy_id ) {
            // FASE1-REAUDIT P0 FIX (IDOR): verify the policy belongs to the booking's
            // vendor. Without this, a misconfigured product meta pointing to another
            // vendor's policy would return the wrong policy → wrong refund amount.
            $policy = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}lt_booking_policies WHERE id = %d AND vendor_id = %d",
                    $policy_id,
                    (int) $booking['vendor_id']
                ),
                ARRAY_A
            );
            if ( $policy ) return $policy;
        }

        // Vendor default policy.
        // FASE1-REAUDIT P1 FIX: ORDER BY is_default DESC so the vendor's marked
        // default is returned first, not just the oldest by ID.
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lt_booking_policies WHERE vendor_id = %d ORDER BY is_default DESC, id ASC LIMIT 1",
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

        // FASE1-REAUDIT P1 FIX (timezone bug): strtotime() parses in the server
        // timezone (PHP date.timezone) while time() is UTC. If the server is UTC
        // but WP is configured for America/Bogota (UTC-5), and checkin_date is
        // stored in WP local time, the calculation was off by 5 hours → wrong
        // refund tier (100% instead of 50%, or vice versa).
        // Now: use mysql2date with $gmt=true to force GMT interpretation.
        $checkin_ts = function_exists( 'mysql2date' )
            ? (int) mysql2date( 'U', $booking['checkin_date'], true )
            : (int) strtotime( $booking['checkin_date'] . ' UTC' );
        $hours_until_checkin = ( $checkin_ts - time() ) / HOUR_IN_SECONDS;
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
            // v2.9.117 BOOKING-AUDIT P0-2 FIX: prevent double refund.
            // FASE1-REAUDIT P0 REGRESSION FIX: the original fix checked for a refund
            // by substring-matching the refund REASON. Two fatal flaws:
            //   1. Translation mismatch: prefix hardcoded Spanish ("Cancelación de
            //      reserva #%d") but reason uses __() → on English site, no match.
            //   2. Substring collision: "#1" matches "#11" → booking #1's refund
            //      is skipped if booking #11 was refunded first.
            // Now we store booking_id as refund POST META and check that — immune
            // to translation and substring issues.
            $wc_order_id = (int) $booking['wc_order_id'];
            if ( ! $wc_order_id ) return;

            $order = wc_get_order( $wc_order_id );
            if ( ! $order ) return;

            // Check if a refund with this booking_id already exists via post meta.
            $existing_refunds = $order->get_refunds();
            foreach ( $existing_refunds as $existing_refund ) {
                $refund_id          = $existing_refund->get_id();
                $existing_booking_id = (int) get_post_meta( $refund_id, '_ltms_booking_id', true );
                if ( $existing_booking_id === $booking_id ) {
                    // Already refunded for this booking — skip.
                    self::log_info_static( 'booking', sprintf(
                        'Booking #%d: refund already exists (refund_id=%d, amount=%s), skipping double refund.',
                        $booking_id,
                        $refund_id,
                        $existing_refund->get_amount()
                    ) );
                    return;
                }
            }

            $refund_amount = self::calculate_refund_amount( $booking_id, $booking );
            if ( $refund_amount <= 0 ) return;

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
                // FASE1-REAUDIT P0 FIX: store booking_id on the refund post meta so
                // future double-refund checks are O(1) and translation-independent.
                update_post_meta( $refund->get_id(), '_ltms_booking_id', $booking_id );
                // FASE1-REAUDIT P1 FIX: verify refund status before firing action.
                if ( $refund->get_status() === 'completed' ) {
                    do_action( 'ltms_booking_refund_processed', $booking_id, $refund_amount, $refund );
                } else {
                    self::log_warning_static( 'booking', sprintf(
                        'Booking #%d: refund created but status is "%s" (not completed) — not firing ltms_booking_refund_processed.',
                        $booking_id,
                        $refund->get_status()
                    ) );
                }
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

        // v2.9.117 BOOKING-AUDIT P1-2 FIX: sanitize policy_type against allowlist.
        // Before, policy_type was passed raw from $_POST → a vendor could set
        // policy_type to any string, which would break calculate_refund_amount's
        // switch statement (no default case → fallthrough to 0% refund).
        $policy_type = sanitize_key( $data['policy_type'] ?? 'flexible' );
        $valid_types = [ 'flexible', 'moderate', 'strict', 'non_refundable' ];
        if ( ! in_array( $policy_type, $valid_types, true ) ) {
            $policy_type = 'flexible';
        }

        $fields = [
            'vendor_id'             => $vendor_id,
            'name'                  => sanitize_text_field( $data['name'] ?? '' ),
            'policy_type'           => $policy_type,
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
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        // FASE1-REAUDIT P1 FIX: add is_ltms_vendor() check (was missing — customers
        // could call this endpoint, though they got an empty list).
        if ( ! method_exists( 'LTMS_Utils', 'is_ltms_vendor' ) || ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => __( 'Solo vendedores pueden gestionar políticas.', 'ltms' ) ], 403 );
        }
        wp_send_json_success( self::get_vendor_policies( get_current_user_id() ) );
    }

    public static function ajax_save_vendor_policy(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        // v2.9.117 BOOKING-AUDIT P0-3 FIX: verify vendor is authenticated.
        // Before, any logged-in user (including customers) could call this endpoint.
        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        // v2.9.117 P0-4 FIX: if updating an existing policy, verify ownership (IDOR).
        // Before, a vendor could pass policy_id of ANOTHER vendor's policy and
        // the save_policy method would try to UPDATE (with vendor_id in WHERE,
        // so 0 rows affected) then INSERT a new policy. The real risk: a vendor
        // could probe policy_ids to discover other vendors' policy names/types.
        $policy_id = absint( $_POST['policy_id'] ?? 0 );
        if ( $policy_id ) {
            global $wpdb;
            $owner = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT vendor_id FROM {$wpdb->prefix}lt_booking_policies WHERE id = %d",
                $policy_id
            ) );
            if ( $owner && $owner !== $vendor_id ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::security(
                        'BOOKING_POLICY_IDOR_ATTEMPT',
                        sprintf( 'Vendor #%d intentó editar política #%d que pertenece al vendor #%d', $vendor_id, $policy_id, $owner ),
                        [ 'vendor_id' => $vendor_id, 'policy_id' => $policy_id, 'owner_id' => $owner ]
                    );
                }
                wp_send_json_error( [ 'message' => __( 'No autorizado sobre esta política.', 'ltms' ) ], 403 );
            }
        }

        $result    = self::save_policy( [
            'id'                    => $policy_id,
            'vendor_id'             => $vendor_id,
            'name'                  => sanitize_text_field( wp_unslash( $_POST['policy_name'] ?? '' ) ),
            'policy_type'           => sanitize_key( $_POST['policy_type'] ?? 'flexible' ),
            'free_cancel_hours'     => absint( $_POST['free_cancel_hours'] ?? 24 ),
            'partial_refund_pct'    => absint( $_POST['partial_refund_pct'] ?? 50 ),
            'partial_refund_hours'  => absint( $_POST['partial_refund_hours'] ?? 0 ),
            'is_default'            => ! empty( $_POST['is_default'] ) ? 1 : 0,
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => __( 'Política guardada correctamente.', 'ltms' ), 'id' => $result ] );
    }

    public static function ajax_delete_vendor_policy(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        // FASE1-REAUDIT P1 FIX: add is_ltms_vendor() check (was missing — any
        // logged-in user including customers could call this endpoint).
        if ( ! method_exists( 'LTMS_Utils', 'is_ltms_vendor' ) || ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => __( 'Solo vendedores pueden gestionar políticas.', 'ltms' ) ], 403 );
        }

        $vendor_id = get_current_user_id();
        $policy_id = absint( $_POST['policy_id'] ?? 0 );

        if ( ! $policy_id || ! self::delete_policy( $policy_id, $vendor_id ) ) {
            wp_send_json_error( __( 'No se pudo eliminar la política (verifica que te pertenezca).', 'ltms' ) );
        }

        wp_send_json_success( [ 'message' => __( 'Política eliminada.', 'ltms' ) ] );
    }
}
