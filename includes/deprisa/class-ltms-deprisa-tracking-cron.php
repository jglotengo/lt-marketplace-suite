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
    const META_FECHA_EVENTO = '_ltms_deprisa_tracking_fecha_'; // + numero_envio (DP-BUG-11)

    /** Transient key para el lock del cron (DP-BUG-9). */
    const LOCK_KEY     = 'ltms_deprisa_tracking_lock';

    /** Transient key para throttle de notificación de envíos atascados (DP-BUG-11). */
    const STUCK_NOTIFY_KEY = 'ltms_deprisa_stuck_notify';

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

        // DP-BUG-9: Cron lock — evita solapamiento si la corrida anterior
        // aún no terminó (API lento, muchos pedidos). TTL 30 min.
        if ( get_transient( self::LOCK_KEY ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::debug(
                    'DEPRISA_TRACKING_SKIP',
                    'Cron ya en ejecución (lock activo). Se omite esta corrida.'
                );
            }
            return;
        }
        set_transient( self::LOCK_KEY, true, 30 * MINUTE_IN_SECONDS );

        try {
            // AUDIT-SHIPPING-ENGINE #6 FIX: usar LTMS_Api_Deprisa (clase canónica).
            $username = get_option( 'ltms_deprisa_username', '' );
            $password_raw = get_option( 'ltms_deprisa_password', '' );
            $password = ( str_starts_with( $password_raw, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) )
                ? LTMS_Core_Security::decrypt( $password_raw ) : $password_raw;
            $sandbox = (bool) get_option( 'ltms_deprisa_sandbox', true );
            $api = new LTMS_Api_Deprisa( $username, $password, $sandbox );

            // DP-BUG-10: Paginación — procesar en lotes de 50 hasta agotar.
            $batch_size  = 50;
            $offset      = 0;
            $max_batches = 100; // límite de seguridad (5000 pedidos/ciclo).
            $stuck_count = 0;

            for ( $i = 0; $i < $max_batches; $i++ ) {
                $orders = wc_get_orders( [
                    'status'     => [ 'processing', 'on-hold', 'wc-shipped' ],
                    'limit'      => $batch_size,
                    'offset'     => $offset,
                    'meta_query' => [
                        [
                            'key'     => LTMS_Deprisa_Order_Split::META_GUIAS,
                            'compare' => 'EXISTS',
                        ],
                    ],
                ] );

                if ( empty( $orders ) ) break;

                foreach ( $orders as $order ) {
                    $updated = self::update_order_tracking( $order, $api );
                    foreach ( $updated as $u ) {
                        if ( ! empty( $u['stuck'] ) ) $stuck_count++;
                    }
                }

                $offset += $batch_size;
                if ( count( $orders ) < $batch_size ) break;
            }

            // DP-BUG-11: Alerta CRITICAL por envíos atascados >30 días en tránsito.
            if ( $stuck_count > 0 ) {
                self::notify_stuck_shipments( $stuck_count );
            }
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }

    /**
     * DP-BUG-11: Registra alerta CRITICAL + notifica al admin (throttle 1/día).
     */
    private static function notify_stuck_shipments( int $count ): void {
        $msg = sprintf( '%d envío(s) Deprisa atascado(s) >30 días en tránsito.', $count );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::critical( 'DEPRISA_STUCK_SHIPMENTS', $msg );
        } else {
            error_log( '[LTMS][CRITICAL][DEPRISA_STUCK_SHIPMENTS] ' . $msg );
        }

        // Notificar al admin por email (throttled a 1/día).
        if ( ! get_transient( self::STUCK_NOTIFY_KEY ) ) {
            $admin_email = get_option( 'admin_email' );
            if ( $admin_email ) {
                wp_mail(
                    $admin_email,
                    sprintf( '[%s] Deprisa: envíos atascados >30 días', get_bloginfo( 'name' ) ),
                    $msg . "\n\nRevise los pedidos con tracking pendiente en el panel WooCommerce."
                );
            }
            set_transient( self::STUCK_NOTIFY_KEY, true, DAY_IN_SECONDS );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Actualizar tracking de un pedido                                     */
    /* ------------------------------------------------------------------ */

    public static function update_order_tracking( WC_Order $order, ?LTMS_Api_Deprisa $api = null ): array {
        // AUDIT-SHIPPING-ENGINE #6 FIX: usar LTMS_Api_Deprisa (clase canónica).
        if ( ! $api ) {
            $username = get_option( 'ltms_deprisa_username', '' );
            $password_raw = get_option( 'ltms_deprisa_password', '' );
            $password = ( str_starts_with( $password_raw, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) )
                ? LTMS_Core_Security::decrypt( $password_raw ) : $password_raw;
            $sandbox = (bool) get_option( 'ltms_deprisa_sandbox', true );
            $api = new LTMS_Api_Deprisa( $username, $password, $sandbox );
        }

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

            // DP-BUG-7: consultar_tracking() devuelve ['exito','http_code','envio',
            // 'estados','incidencias','raw'] — NO existe la clave 'estado'.
            // El estado actual se deriva del último elemento de $response['estados'].
            $estados_resp           = $response['estados'] ?? [];
            $ultimo_estado          = ! empty( $estados_resp ) ? end( $estados_resp ) : [];
            $estado_nuevo           = $ultimo_estado['tipo_evento'] ?? '';
            $estado_descripcion_nva = $ultimo_estado['descripcion'] ?? '';
            $fecha_evento_nueva     = $ultimo_estado['fecha_evento'] ?? '';

            $estado_previo = $order->get_meta( self::META_ESTADO . $numero_envio );

            // Guardar estado y detalle
            $order->update_meta_data( self::META_ESTADO . $numero_envio, $estado_nuevo );
            $order->update_meta_data( self::META_ESTADO . $numero_envio . '_detail', wp_json_encode( $response ) );
            // DP-BUG-11: Guardar fecha del último evento para detectar atascos.
            if ( $fecha_evento_nueva ) {
                $order->update_meta_data( self::META_FECHA_EVENTO . $numero_envio, $fecha_evento_nueva );
            }

            // Notificar si cambió el estado
            if ( $estado_nuevo && $estado_nuevo !== $estado_previo ) {
                do_action( 'ltms_deprisa_tracking_changed', $order, $numero_envio, $estado_nuevo, $estado_previo );
            }

            // Detectar entrega por codigo (tipo_evento) o descripcion.
            $estado_upper = strtoupper( $estado_descripcion_nva ?: $estado_nuevo );
            $is_delivered = in_array( $estado_upper, [ 'ENTREGADO', 'ENTREGA' ], true )
                || ( $estado_descripcion_nva && stripos( $estado_descripcion_nva, 'entreg' ) !== false );

            // Actualizar estado de WooCommerce si fue entregado
            if ( $is_delivered ) {
                $current_status = $order->get_status();
                if ( in_array( $current_status, [ 'processing', 'on-hold', 'wc-shipped' ], true ) ) {
                    $order->update_status( 'completed', __( 'Entregado según tracking Deprisa.', 'ltms' ) );
                }

                // AUDIT-SHIPPING-ENGINE #2 FIX: disparar ltms_shipping_delivered
                // para que el consumer protection hold se libere y el vendor
                // reciba su comisión. Antes el cron marcaba el order como
                // completed pero NUNCA disparaba la action → los holds de
                // pedidos Deprisa nunca se liberaban por entrega confirmada.
                if ( ! $order->get_meta( '_ltms_shipping_delivered_fired' ) ) {
                    $order->update_meta_data( '_ltms_shipping_delivered_fired', 1 );
                    $order->update_meta_data( '_ltms_delivered_at', gmdate( 'Y-m-d H:i:s' ) );
                    $order->save();
                    do_action( 'ltms_shipping_delivered', $order->get_id() );
                }
            }

            // DP-BUG-11: Detectar atasco — estado no-terminal + fecha_evento >30 días.
            $stuck = false;
            if ( $estado_nuevo && ! $is_delivered && $fecha_evento_nueva ) {
                $ts_evento = strtotime( $fecha_evento_nueva );
                if ( $ts_evento && ( time() - $ts_evento ) > ( 30 * DAY_IN_SECONDS ) ) {
                    $stuck = true;
                }
            }

            $updated[] = [
                'numero_envio'  => $numero_envio,
                'estado_previo' => $estado_previo,
                'estado_nuevo'  => $estado_nuevo,
                'descripcion'   => $estado_descripcion_nva,
                'stuck'         => $stuck,
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
