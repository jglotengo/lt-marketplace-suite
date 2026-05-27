<?php
/**
 * LTMS — Cron de tracking automático Deprisa v1.10.0
 *
 * Consulta el estado de todas las guías activas cada hora y actualiza
 * los metadatos del pedido. Dispara notificaciones cuando el estado cambia.
 *
 * @package LTMS
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Tracking_Cron {

    const CRON_HOOK    = 'ltms_deprisa_tracking_cron';
    const META_LAST    = '_ltms_deprisa_tracking_last';
    const META_ESTADO  = '_ltms_deprisa_tracking_';      // + numero_envio
    const META_DETAIL  = '_ltms_deprisa_tracking_';      // + numero_envio + _detail

    /* ------------------------------------------------------------------ */
    /* Activación / desactivación del cron                                  */
    /* ------------------------------------------------------------------ */

    public static function activate(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /* ------------------------------------------------------------------ */
    /* Registro de hooks                                                    */
    /* ------------------------------------------------------------------ */

    public static function register(): void {
        add_action( self::CRON_HOOK, [ self::class, 'run' ] );

        // AJAX manual desde el metabox
        add_action( 'wp_ajax_ltms_deprisa_tracking_manual', [ self::class, 'ajax_tracking_manual' ] );
    }

    /* ------------------------------------------------------------------ */
    /* Ejecución del cron                                                   */
    /* ------------------------------------------------------------------ */

    public static function run(): void {
        if ( ! get_option( 'ltms_deprisa_enabled' ) ) return;

        $api = new LTMS_Deprisa_API();

        // Buscar pedidos con guías en estado activo (no entregados/cancelados)
        $orders = wc_get_orders( [
            'status'     => [ 'processing', 'on-hold', 'wc-shipped' ],
            'limit'      => 50,
            'meta_query' => [
                [
                    'key'     => LTMS_Deprisa_Order_Split::META_GUIAS,
                    'compare' => 'EXISTS',
                ],
            ],
        ] );

        foreach ( $orders as $order ) {
            self::update_order_tracking( $order, $api );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Actualizar tracking de un pedido                                     */
    /* ------------------------------------------------------------------ */

    public static function update_order_tracking( WC_Order $order, ?LTMS_Deprisa_API $api = null ): array {
        if ( ! $api ) $api = new LTMS_Deprisa_API();

        $guias    = LTMS_Deprisa_Order_Split::get_guias( $order );
        $updated  = [];
        $now      = current_time( 'mysql' );

        foreach ( $guias as $resultado ) {
            if ( empty( $resultado['ok'] ) || empty( $resultado['numero_envio'] ) ) continue;

            $numero_envio = $resultado['numero_envio'];

            $response = $api->consultar_tracking( $numero_envio );

            if ( is_wp_error( $response ) ) {
                $updated[] = [
                    'numero_envio' => $numero_envio,
                    'error'        => $response->get_error_message(),
                ];
                continue;
            }

            $estado_nuevo  = $response['estado']  ?? '';
            $estado_previo = $order->get_meta( self::META_ESTADO . $numero_envio );

            // Guardar estado y detalle
            $order->update_meta_data( self::META_ESTADO . $numero_envio, $estado_nuevo );
            $order->update_meta_data( self::META_ESTADO . $numero_envio . '_detail', wp_json_encode( $response ) );

            // Notificar si cambió el estado
            if ( $estado_nuevo && $estado_nuevo !== $estado_previo ) {
                do_action( 'ltms_deprisa_tracking_changed', $order, $numero_envio, $estado_nuevo, $estado_previo );
            }

            // Actualizar estado de WooCommerce si fue entregado
            if ( in_array( strtoupper( $estado_nuevo ), [ 'ENTREGADO', 'ENTREGA' ], true ) ) {
                if ( $order->get_status() === 'processing' ) {
                    $order->update_status( 'completed', __( 'Entregado según tracking Deprisa.', 'ltms' ) );
                }
            }

            $updated[] = [
                'numero_envio'  => $numero_envio,
                'estado_previo' => $estado_previo,
                'estado_nuevo'  => $estado_nuevo,
            ];
        }

        $order->update_meta_data( self::META_LAST, $now );
        $order->save();

        return $updated;
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: tracking manual desde metabox                                  */
    /* ------------------------------------------------------------------ */

    public static function ajax_tracking_manual(): void {
        check_ajax_referer( 'ltms_deprisa_tracking' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
        }

        $updated = self::update_order_tracking( $order );

        if ( empty( $updated ) ) {
            wp_send_json_error( [ 'message' => 'No hay guías para consultar.' ] );
        }

        $resumen = implode( ', ', array_map( fn( $u ) =>
            ( $u['numero_envio'] ?? '' ) . ': ' . ( $u['estado_nuevo'] ?? $u['error'] ?? '?' ),
            $updated
        ) );

        wp_send_json_success( [ 'message' => "Tracking actualizado — $resumen" ] );
    }
}
