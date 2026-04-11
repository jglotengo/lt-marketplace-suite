<?php
/**
 * LTMS Kitchen Ajax — Backend del Kitchen Display
 *
 * Registra y maneja las acciones AJAX consumidas por view-kitchen.php:
 *   - ltms_kitchen_get_orders    → lista pedidos activos del vendedor
 *   - ltms_kitchen_update_status → cambia el estado de un pedido
 *   - ltms_kitchen_get_stats     → contadores de la cabecera
 *
 * Nonce: ltms_dashboard_nonce (mismo que el dashboard SPA).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Kitchen_Ajax
 */
class LTMS_Kitchen_Ajax {

    use LTMS_Logger_Aware;

    /**
     * Estados mostrados en la kitchen.
     */
    const KITCHEN_STATUSES = [ 'processing', 'on-hold' ];

    /**
     * Registra los hooks AJAX.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_kitchen_get_orders',    [ $instance, 'ajax_get_orders' ] );
        add_action( 'wp_ajax_ltms_kitchen_update_status', [ $instance, 'ajax_update_status' ] );
        add_action( 'wp_ajax_ltms_kitchen_get_stats',     [ $instance, 'ajax_get_stats' ] );
    }

    /**
     * AJAX: obtiene los pedidos activos del vendedor.
     *
     * @return void
     */
    public function ajax_get_orders(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $since = sanitize_text_field( $_POST['since'] ?? '' );

        $query_args = [
            'limit'      => 50,
            'status'     => self::KITCHEN_STATUSES,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_ltms_vendor_id',
                    'value' => $vendor_id,
                    'type'  => 'NUMERIC',
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
            $items = [];
            foreach ( $order->get_items() as $item ) {
                $items[] = [
                    'name' => $item->get_name(),
                    'qty'  => $item->get_quantity(),
                ];
            }

            $orders[] = [
                'id'         => $order->get_id(),
                'number'     => $order->get_order_number(),
                'status'     => $order->get_status(),
                'customer'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'items'      => $items,
                'note'       => $order->get_customer_note(),
                'created_at' => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
            ];
        }

        wp_send_json_success( [
            'orders'    => $orders,
            'timestamp' => gmdate( 'c' ),
        ] );
    }

    /**
     * AJAX: cambia el estado de un pedido.
     *
     * @return void
     */
    public function ajax_update_status(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $order_id   = absint( $_POST['order_id'] ?? 0 );
        $new_status = sanitize_key( $_POST['status'] ?? '' );

        $allowed_statuses = [ 'processing', 'on-hold', 'completed', 'cancelled' ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            wp_send_json_error( __( 'Estado no permitido.', 'ltms' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( __( 'Pedido no encontrado.', 'ltms' ) );
        }

        // Verificar que el pedido pertenece al vendedor
        $order_vendor = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( $order_vendor !== $vendor_id ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $order->update_status(
            $new_status,
            sprintf( __( 'Estado cambiado desde Kitchen Display por vendedor #%d.', 'ltms' ), $vendor_id )
        );

        self::log_info(
            'KITCHEN_STATUS_CHANGED',
            sprintf( 'Pedido #%d → %s por vendedor #%d', $order_id, $new_status, $vendor_id )
        );

        wp_send_json_success( [
            'order_id'   => $order_id,
            'new_status' => $new_status,
        ] );
    }

    /**
     * AJAX: devuelve los contadores de la cabecera de la kitchen.
     *
     * @return void
     */
    public function ajax_get_stats(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $vendor_meta = [
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_ltms_vendor_id',
                    'value' => $vendor_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ];

        $pending    = wc_get_orders( array_merge( $vendor_meta, [ 'status' => 'processing', 'limit' => -1, 'return' => 'ids' ] ) );
        $on_hold    = wc_get_orders( array_merge( $vendor_meta, [ 'status' => 'on-hold',    'limit' => -1, 'return' => 'ids' ] ) );
        $completed  = wc_get_orders( array_merge( $vendor_meta, [
            'status'     => 'completed',
            'limit'      => -1,
            'return'     => 'ids',
            'date_query' => [ 'after' => gmdate( 'Y-m-d 00:00:00' ) ],
        ] ) );

        wp_send_json_success( [
            'pending'         => count( $pending ),
            'on_hold'         => count( $on_hold ),
            'completed_today' => count( $completed ),
        ] );
    }
}
