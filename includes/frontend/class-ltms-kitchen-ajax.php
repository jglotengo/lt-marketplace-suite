<?php
/**
 * LTMS Kitchen Ajax — Backend del Kitchen Display System (KDS)
 *
 * AUDIT-RESTAURANT-ENGINE FIX: correcciones críticas:
 *   1. Status mismatch: JS enviaba 'preparing'/'ready' pero PHP solo aceptaba
 *      WC statuses (processing/on-hold/completed/cancelled) → siempre fallaba.
 *      Ahora se usan meta keys custom (_ltms_kitchen_status) para el flujo
 *      de cocina, independientes del WC order status.
 *   2. Field name mismatches: JS esperaba date_created, quantity, customer_name,
 *      notes, table_number pero PHP enviaba created_at, qty, customer, note.
 *   3. No filtraba por restaurante — TODOS los pedidos del vendor aparecían.
 *   4. Sin soporte para table_number, order_type, item notes.
 *   5. Stats con limit=-1 era ineficiente para alto volumen.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    AUDIT-RESTAURANT-ENGINE
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Kitchen_Ajax {

    use LTMS_Logger_Aware;

    /**
     * Kitchen statuses (meta key _ltms_kitchen_status).
     * Independientes del WC order status para no romper el flujo de WC.
     */
    const KITCHEN_STATUS_NEW       = 'new';
    const KITCHEN_STATUS_PREPARING = 'preparing';
    const KITCHEN_STATUS_READY     = 'ready';
    const KITCHEN_STATUS_SERVED    = 'served';
    const KITCHEN_STATUS_CANCELLED = 'cancelled';

    const ALL_KITCHEN_STATUSES = [
        self::KITCHEN_STATUS_NEW,
        self::KITCHEN_STATUS_PREPARING,
        self::KITCHEN_STATUS_READY,
        self::KITCHEN_STATUS_SERVED,
        self::KITCHEN_STATUS_CANCELLED,
    ];

    /**
     * WC order statuses que califican para mostrar en KDS.
     */
    const WC_ACTIVE_STATUSES = [ 'processing', 'on-hold' ];

    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_kitchen_get_orders',    [ $instance, 'ajax_get_orders' ] );
        add_action( 'wp_ajax_ltms_kitchen_update_status', [ $instance, 'ajax_update_status' ] );
        add_action( 'wp_ajax_ltms_kitchen_get_stats',     [ $instance, 'ajax_get_stats' ] );

        // AUDIT-RESTAURANT-ENGINE: auto-set kitchen_status='new' when order is paid.
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'auto_set_kitchen_status_new' ], 25 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'auto_set_kitchen_status_new' ], 25 );
    }

    /**
     * AUDIT-RESTAURANT-ENGINE: cuando un pedido se paga/entra en processing,
     * setear _ltms_kitchen_status='new' automáticamente para que aparezca en KDS.
     */
    public static function auto_set_kitchen_status_new( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Solo si el vendor tiene productos de restaurante.
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) return;

        // Verificar si el vendor tiene el flag de restaurante activado.
        $is_restaurant = get_user_meta( $vendor_id, 'ltms_is_restaurant', true ) === 'yes';
        if ( ! $is_restaurant ) return;

        // Solo si no tiene ya un kitchen_status.
        $existing = $order->get_meta( '_ltms_kitchen_status' );
        if ( ! $existing ) {
            $order->update_meta_data( '_ltms_kitchen_status', self::KITCHEN_STATUS_NEW );
            $order->update_meta_data( '_ltms_kitchen_status_at', current_time( 'mysql', true ) );
            $order->save();
        }
    }

    /**
     * AJAX: obtiene los pedidos activos del vendedor (solo restaurante).
     *
     * AUDIT-RESTAURANT-ENGINE FIX: field names alineados con el JS,
     * filtrado por restaurante, soporte para table_number/order_type/item notes.
     */
    public function ajax_get_orders(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) { // v2.9.126 P0-3: add vendor role check
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 403 );
        }

        $since = sanitize_text_field( $_POST['since'] ?? '' );

        $query_args = [
            'limit'      => 50,
            'status'     => self::WC_ACTIVE_STATUSES,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_ltms_vendor_id',
                    'value' => $vendor_id,
                    'type'  => 'NUMERIC',
                ],
                // AUDIT-RESTAURANT-ENGINE: solo pedidos con kitchen_status.
                [
                    'key'     => '_ltms_kitchen_status',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'    => 'date',
            'order'      => 'ASC',
        ];

        if ( $since ) {
            $query_args['date_query'] = [
                'after'     => $since,
                'inclusive' => false,
            ];
        }

        $orders_raw = wc_get_orders( $query_args );
        $orders     = [];

        foreach ( $orders_raw as $order ) {
            $kitchen_status = $order->get_meta( '_ltms_kitchen_status' ) ?: self::KITCHEN_STATUS_NEW;

            // Skip served/cancelled (already done in kitchen).
            if ( in_array( $kitchen_status, [ self::KITCHEN_STATUS_SERVED, self::KITCHEN_STATUS_CANCELLED ], true ) ) {
                continue;
            }

            $items = [];
            foreach ( $order->get_items() as $item ) {
                $items[] = [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(), // FIX: era 'qty', JS espera 'quantity'.
                    'notes'    => $item->get_meta( '_ltms_item_notes' ) ?: '', // FIX: item-level notes.
                    'modifiers' => $item->get_meta( '_ltms_item_modifiers' ) ?: [],
                ];
            }

            // FIX: field names alineados con JS.
            $orders[] = [
                'id'            => $order->get_id(),
                'number'        => $order->get_order_number(),
                'status'        => $kitchen_status, // Kitchen status, no WC status.
                'wc_status'     => $order->get_status(),
                'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), // FIX: era 'customer'.
                'items'         => $items,
                'notes'         => $order->get_customer_note(), // FIX: era 'note'.
                'table_number'  => $order->get_meta( '_ltms_table_number' ) ?: '', // FIX: nuevo.
                'order_type'    => $order->get_meta( '_ltms_order_type' ) ?: 'dine_in', // FIX: dine_in|takeout|delivery.
                'date_created'  => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '', // FIX: era 'created_at'.
                'items_summary' => $this->build_items_summary( $items ),
            ];
        }

        wp_send_json_success( [
            'orders'    => $orders,
            'timestamp' => gmdate( 'c' ),
        ] );
    }

    /**
     * AJAX: cambia el kitchen_status de un pedido.
     *
     * AUDIT-RESTAURANT-ENGINE FIX: usa meta key _ltms_kitchen_status
     * en lugar de WC order status. Antes JS enviaba 'preparing' pero
     * PHP validaba contra WC statuses → siempre fallaba con "Estado no permitido".
     */
    public function ajax_update_status(): void {
        // v2.9.126 BATCH-AUDIT P0-2 FIX: add is_user_logged_in + is_ltms_vendor check.
        // Before, only checked get_current_user_id() (which returns 0 for non-logged-in),
        // but did NOT check vendor role — a customer could call this endpoint with a
        // valid dashboard nonce and change kitchen_status of any order.
        if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 403 );
        }

        $order_id       = absint( $_POST['order_id'] ?? 0 );
        $new_status     = sanitize_key( $_POST['status'] ?? '' );

        // AUDIT-RESTAURANT-ENGINE FIX: validar contra kitchen statuses, no WC statuses.
        if ( ! in_array( $new_status, self::ALL_KITCHEN_STATUSES, true ) ) {
            wp_send_json_error( __( 'Estado no permitido.', 'ltms' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( __( 'Pedido no encontrado.', 'ltms' ) );
        }

        // Verificar ownership.
        $order_vendor = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( $order_vendor !== $vendor_id ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        // AUDIT-RESTAURANT-ENGINE FIX: actualizar meta key, no WC status.
        $order->update_meta_data( '_ltms_kitchen_status', $new_status );
        $order->update_meta_data( '_ltms_kitchen_status_at', current_time( 'mysql', true ) );
        $order->update_meta_data( '_ltms_kitchen_status_by', $vendor_id );

        // Si el kitchen status es 'served', marcar el WC order como completed.
        if ( $new_status === self::KITCHEN_STATUS_SERVED ) {
            $order->update_status( 'completed', sprintf( __( 'Pedido servido desde Kitchen Display por vendedor #%d.', 'ltms' ), $vendor_id ) );
        } elseif ( $new_status === self::KITCHEN_STATUS_CANCELLED ) {
            $order->update_status( 'cancelled', sprintf( __( 'Pedido cancelado desde Kitchen Display por vendedor #%d.', 'ltms' ), $vendor_id ) );
        } else {
            $order->save();
        }

        $this->log_info(
            'KITCHEN_STATUS_CHANGED',
            sprintf( 'Pedido #%d → kitchen_status=%s por vendedor #%d', $order_id, $new_status, $vendor_id ),
            [ 'order_id' => $order_id, 'new_status' => $new_status, 'vendor_id' => $vendor_id ]
        );

        wp_send_json_success( [
            'order_id'   => $order_id,
            'new_status' => $new_status,
        ] );
    }

    /**
     * AJAX: devuelve los contadores de la cabecera de la kitchen.
     *
     * AUDIT-RESTAURANT-ENGINE FIX: usar COUNT queries eficientes en vez
     * de wc_get_orders con limit=-1 (que carga todos los IDs en memoria).
     */
    public function ajax_get_stats(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) { // v2.9.126 P0-4: add vendor role check
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 403 );
        }

        global $wpdb;

        // AUDIT-RESTAURANT-ENGINE FIX: COUNT directa en DB, mucho más eficiente.
        $base_query = "SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
             INNER JOIN {$wpdb->prefix}wc_orders_meta m1 ON m1.order_id = o.id AND m1.meta_key = '_ltms_vendor_id' AND m1.meta_value = %d
             INNER JOIN {$wpdb->prefix}wc_orders_meta m2 ON m2.order_id = o.id AND m2.meta_key = '_ltms_kitchen_status' AND m2.meta_value = %s
             WHERE o.status IN ('processing', 'on-hold')";

        // HPOS fallback.
        $orders_table = $wpdb->prefix . 'wc_orders';
        $has_hpos = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $orders_table ) ) === $orders_table;

        if ( ! $has_hpos ) {
            // Legacy postmeta path.
            $base_query = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = '_ltms_vendor_id' AND pm1.meta_value = %d
                 INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_ltms_kitchen_status' AND pm2.meta_value = %s
                 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-processing', 'wc-on-hold')";
        }

        $new_count      = (int) $wpdb->get_var( $wpdb->prepare( $base_query, $vendor_id, self::KITCHEN_STATUS_NEW ) );
        $preparing_count = (int) $wpdb->get_var( $wpdb->prepare( $base_query, $vendor_id, self::KITCHEN_STATUS_PREPARING ) );
        $ready_count    = (int) $wpdb->get_var( $wpdb->prepare( $base_query, $vendor_id, self::KITCHEN_STATUS_READY ) );

        // Completed today (served).
        $served_query = str_replace( "o.status IN ('processing', 'on-hold')", "o.status = 'completed'", $base_query );
        if ( ! $has_hpos ) {
            $served_query = str_replace( "p.post_status IN ('wc-processing', 'wc-on-hold')", "p.post_status = 'wc-completed'", $served_query );
        }
        $served_query .= " AND DATE(o.date_created_gmt) = CURDATE()";
        if ( ! $has_hpos ) {
            $served_query = str_replace( "DATE(o.date_created_gmt)", "DATE(p.post_date)", $served_query );
        }

        $served_today = (int) $wpdb->get_var( $wpdb->prepare( $served_query, $vendor_id, self::KITCHEN_STATUS_SERVED ) );

        wp_send_json_success( [
            'new'           => $new_count,
            'preparing'     => $preparing_count,
            'ready'         => $ready_count,
            'served_today'  => $served_today,
            'total_active'  => $new_count + $preparing_count + $ready_count,
        ] );
    }

    /**
     * Helper: construye un resumen corto de items para notificaciones.
     *
     * @param array $items
     * @return string
     */
    private function build_items_summary( array $items ): string {
        $parts = [];
        foreach ( $items as $item ) {
            $parts[] = $item['quantity'] . '× ' . $item['name'];
        }
        return implode( ', ', array_slice( $parts, 0, 3 ) ) . ( count( $parts ) > 3 ? '...' : '' );
    }
}
