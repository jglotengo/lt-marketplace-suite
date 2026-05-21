<?php
/**
 * Webhook handler for Aveonline v2.
 *
 * Aveonline envía a la ruta registrada el siguiente payload:
 * {
 *   "status": "ok",
 *   "message": "estado encontrado con exito",
 *   "guia": 892349021,
 *   "pedido_id": 903,
 *   "estado": [
 *     { "estado_id": 12, "nombre_estado": "ENTREGADA", "fecha": "2020-12-11 11:04:43" }
 *   ]
 * }
 *
 * El pedido_id corresponde al order_id de WooCommerce que se registró
 * al momento de crear la guía (campo dsordendecompra).
 */
class LTMS_Aveonline_Webhook_Handler {

    public static function init(): void {}

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        // Campos del formato real de Aveonline v2
        $guia       = sanitize_text_field( (string) ( $body['guia'] ?? '' ) );
        $pedido_id  = (int) ( $body['pedido_id'] ?? 0 );
        $estados    = $body['estado'] ?? [];

        // Validación básica del payload
        if ( ! $guia && ! $pedido_id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload: missing guia and pedido_id' ], 400 );
        }
        if ( empty( $estados ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload: empty estado array' ], 400 );
        }

        // El estado más reciente es el último elemento del array
        $last_estado   = end( $estados );
        $estado_id     = (int) ( $last_estado['estado_id'] ?? 0 );
        $nombre_estado = sanitize_text_field( $last_estado['nombre_estado'] ?? '' );
        $fecha         = sanitize_text_field( $last_estado['fecha'] ?? '' );

        // Resolver order_id: primero por pedido_id directo, luego buscando por número de guía
        $order_id = $pedido_id;
        if ( ! $order_id && $guia ) {
            global $wpdb;
            $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ltms_aveonline_tracking'
                   AND meta_value = %s
                 LIMIT 1",
                $guia
            ) );
        }

        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_aveonline_tracking', $guia );
                $order->update_meta_data( '_ltms_shipping_status', $nombre_estado );
                $order->update_meta_data( '_ltms_aveonline_estado_id', $estado_id );
                $order->add_order_note( sprintf(
                    /* translators: 1: guía, 2: estado, 3: fecha */
                    __( 'Aveonline guía %1$s — %2$s (%3$s)', 'ltms' ),
                    $guia,
                    $nombre_estado,
                    $fecha
                ) );

                $nombre_upper = strtoupper( $nombre_estado );

                // Estado 12 = ENTREGADA según la doc de Aveonline
                if ( 'ENTREGADA' === $nombre_upper || $estado_id === 12 ) {
                    // Solo completar si no está ya completado
                    if ( ! $order->has_status( 'completed' ) ) {
                        $order->update_status( 'completed', __( 'Entregado por Aveonline.', 'ltms' ) );
                        do_action( 'ltms_shipping_delivered', $order_id, 'aveonline' );
                    }
                } elseif ( in_array( $nombre_upper, [ 'DEVUELTA', 'ANULADA', 'CANCELADA', 'NO ENTREGADA' ], true ) ) {
                    do_action( 'ltms_shipping_failed', $order_id, 'aveonline:' . sanitize_key( $nombre_estado ) );
                } elseif ( in_array( $nombre_upper, [ 'EN TRANSITO', 'EN NOVEDAD', 'EN REPARTO' ], true ) ) {
                    do_action( 'ltms_shipping_in_transit', $order_id, 'aveonline:' . sanitize_key( $nombre_estado ) );
                }

                $order->save();
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AVEONLINE_WEBHOOK',
                "guia={$guia}, pedido_id={$pedido_id}, estado_id={$estado_id}, nombre={$nombre_estado}"
            );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }
}
// ─────────────────────────────────────────────────────────────────────────────
// ZAPSIGN
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Zapsign_Webhook_Handler
 *
 * Procesa eventos de firma electrónica de ZapSign.
 * Actualiza el estado KYC del vendedor cuando el documento es firmado.
 */
