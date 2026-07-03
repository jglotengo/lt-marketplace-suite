<?php
/**
 * LTMS Aveonline Hub Listener
 *
 * Escucha el hook genérico `ltms_report_shipment_status_to_hub` y reporta
 * el evento de estado a Ave-Hub mediante LTMS_Api_Aveonline_Hub::push_events().
 *
 * Pensado para envíos que Lo Tengo gestiona directamente (domiciliario propio
 * del vendedor, recogida en tienda, etc.) y que no pasan por la generación de
 * guía de la API principal de Aveonline. Cualquier módulo (domiciliarios,
 * pickup, etc.) puede reportar un cambio de estado simplemente disparando:
 *
 *   do_action(
 *       'ltms_report_shipment_status_to_hub',
 *       $order_id,        // int    — ID del pedido WooCommerce (se usa como id_envio)
 *       $cod_estado,      // string — código de estado propio de Lo Tengo
 *       $nombre_estado,   // string — nombre legible del estado
 *       $extra            // array  — campos opcionales adicionales para build_event()
 *   );
 *
 * Cada intento (éxito o error) se registra en
 * LTMS_Business_Aveonline_Hub_Log para auditoría/debug.
 *
 * Requiere que `ltms_aveonline_hub_idtransportadora` esté configurado en
 * Ajustes → Aveonline → Ave-Hub. Si no está configurado, el evento se
 * registra como error sin interrumpir el flujo normal del pedido.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business/listeners
 * @since      2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Aveonline_Hub_Listener
 */
final class LTMS_Aveonline_Hub_Listener {

    use LTMS_Logger_Aware;

    /**
     * Registra el hook del listener.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'ltms_report_shipment_status_to_hub', [ __CLASS__, 'on_status_reported' ], 10, 4 );
    }

    /**
     * Construye el evento y lo envía a Ave-Hub.
     *
     * @param int    $order_id      ID del pedido WooCommerce (usado como id_envio).
     * @param string $cod_estado    Código de estado propio de Lo Tengo.
     * @param string $nombre_estado Nombre legible del estado.
     * @param array  $extra         Campos opcionales adicionales (ver LTMS_Api_Aveonline_Hub::build_event()):
     *                               cod_novedad, nombre_novedad, fecha_novedad, estado_novedad,
     *                               guia_reeemplazo, tipo_guia_reeemplazo, ruta_digitalizada,
     *                               base64_entrega, observaciones, fecha_estado (sobrescribe now()).
     * @return void
     */
    public static function on_status_reported( int $order_id, string $cod_estado, string $nombre_estado, array $extra = [] ): void {
        if ( ! $order_id || ! $cod_estado ) {
            return;
        }

        if ( ! class_exists( 'LTMS_Api_Aveonline_Hub' ) ) {
            return;
        }

        // AO-BUG-8 FIX (regression of LS-BUG-7): event_id idempotency. El Hub
        // puede reenviar el mismo evento en retries, y módulos del marketplace
        // pueden disparar `ltms_report_shipment_status_to_hub` varias veces para
        // el mismo order_id+estado dentro de la misma hora. Sin dedup, cada
        // disparo genera una nueva fila en el Hub Log y un POST duplicado a
        // Ave-Hub. Calculamos un event_id determinista (del extra o de los
        // campos clave) y lo gateamos con un transient de 1 hora.
        $fecha_estado = (string) ( $extra['fecha_estado'] ?? current_time( 'Y-m-d H:i:s' ) );
        $event_id     = (string) ( $extra['event_id'] ?? md5( implode( '|', [
            $order_id,
            $cod_estado,
            $fecha_estado,
            (string) floor( time() / 3600 ), // bucket de 1h: protege contra duplicados en la misma hora sin bloquear un estado legitimo en la siguiente hora
        ] ) ) );
        $cache_key    = 'ltms_avehub_seen_' . md5( $event_id );
        if ( get_transient( $cache_key ) ) {
            return; // Already processed
        }
        set_transient( $cache_key, true, HOUR_IN_SECONDS );

        $id_transportadora = (int) get_option( 'ltms_aveonline_hub_idtransportadora', 0 );
        if ( ! $id_transportadora ) {
            // No configurado: no es un error de negocio, solo no hay nada que reportar.
            return;
        }

        $event = LTMS_Api_Aveonline_Hub::build_event( array_merge( [
            'id_envio'      => (string) $order_id,
            'cod_estado'    => $cod_estado,
            'nombre_estado' => $nombre_estado,
            'fecha_estado'  => $fecha_estado,
        ], $extra ) );

        try {
            $client   = new LTMS_Api_Aveonline_Hub();
            $response = $client->push_events( [ $event ] );

            if ( class_exists( 'LTMS_Business_Aveonline_Hub_Log' ) ) {
                LTMS_Business_Aveonline_Hub_Log::record(
                    $event,
                    'success',
                    (string) ( $response['message'] ?? '' ),
                    $order_id
                );
            }

            self::log_info_static(
                'AVEONLINE_HUB_PUSH',
                sprintf(
                    'Ave-Hub: evento reportado — order_id=%d cod_estado=%s nombre_estado=%s',
                    $order_id, $cod_estado, $nombre_estado
                )
            );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'LTMS_Business_Aveonline_Hub_Log' ) ) {
                LTMS_Business_Aveonline_Hub_Log::record(
                    $event,
                    'error',
                    $e->getMessage(),
                    $order_id
                );
            }

            self::log_error_static(
                'AVEONLINE_HUB_PUSH_ERROR',
                sprintf(
                    'Ave-Hub: error al reportar evento — order_id=%d cod_estado=%s: %s',
                    $order_id, $cod_estado, $e->getMessage()
                )
            );
        }
    }
}
