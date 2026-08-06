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

        // CICLO18-P1-AO-055 FIX: validar class_exists ANTES de cualquier
        // transient. Antes, el set_transient ocurria antes de este check; si
        // faltaba la clase (raro, pero posible en boot temprano), el evento
        // quedaba marcado como "ya procesado" por 1h sin llegar a Ave-Hub —
        // evento perdido. Ahora el return es limpio y el reintento se permite.
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
        //
        // CICLO18-P1-AO-052 DOC/GUARD: si el caller pasa $extra['event_id']
        // explicito, ese event_id se usa SIN bucketing por hora — el caller
        // asume responsabilidad de unicity dentro de su dominio. Reintentos >1h
        // con el MISMO event_id externo SI se deduplican (transient 1h). Si el
        // caller necesita reintentar el mismo evento despues de 1h, debe pasar
        // un event_id DISTINTO (sufijo -retry-N, timestamp, etc.). El path por
        // defecto (sin event_id externo) genera un bucket de 1h con
        // floor(time()/3600) que permite un estado legitimo en la siguiente hora
        // sin duplicarse dentro de la misma hora.
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

        // CICLO18-P1-AO-054 FIX: idtransportadora check ANTES de cualquier
        // set_transient. Antes, si idtransportadora era 0, el set_transient
        // ya habia marcado el evento como "ya procesado" — el return limpio
        // descartaba el envio, pero el bloque de reintento en la siguiente hora
        // podia generar un nuevo event_id (bucket nuevo) y reintentar. Eso era
        // "ruido" pero no perdida. El problema real era el orden combinado con
        // AO-051: el set_transient ejecutaba ANTES del push y de este check,
        // por lo que cualquier fallo below-la-linea dejaba el evento marcado 1h
        // sin enviar. Ahora el set_transient (mas abajo) solo ocurre tras un
        // push exitoso — este return es safe-retry.
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
                // CICLO18-P1-AO-057 FIX: capturar el retorno de record(). Si
                // retorna 0, el INSERT falto (DB down, schema mismatch, etc.)
                // — el push SI llego a Ave-Hub pero NO quedo auditoria local.
                // Log critico para reconciliation manual; el evento NO se
                // reintenta (ya esta en Ave-Hub).
                $log_id = LTMS_Business_Aveonline_Hub_Log::record(
                    $event,
                    'success',
                    (string) ( $response['message'] ?? '' ),
                    $order_id
                );
                if ( ! $log_id ) {
                    // CICLO18-P1-AO-053 FIX: el success-path podia fallar el
                    // INSERT del log y el listener lo tragaba silenciosamente.
                    // Ahora queda solo log critico - el push SI exito, pero el
                    // log local no quedo. Mismo defecto que OP-050 del Ciclo 17.
                    self::log_error_static(
                        'AVEONLINE_HUB_LOG_INSERT_FAILED',
                        sprintf(
                            'Ave-Hub: push OK pero log INSERT fallo — order_id=%d cod_estado=%s id_envio=%s (reconciliar manualmente en lt_aveonline_hub_push_log)',
                            $order_id, $cod_estado, $event['id_envio'] ?? ''
                        )
                    );
                }
            }

            // CICLO18-P1-AO-051 FIX: set_transient MOVED here. Antes se
            // ejecutaba en linea 92 (antes de push_events). Si el proceso
            // moria entre set_transient y push, o si push lanzaba excepcion,
            // el evento quedaba marcado como "ya procesado" por 1h — el
            // reintento dentro de esa hora se descartaba, el evento se perdia.
            // Ahora el set_transient solo ocurre tras un push exitoso. Si
            // push_events lanza o el proceso muere antes de llegar aqui, no
            // se setea_transient y el reintento (en cualquier momento) puede
            // reintar legítimamente. Trade-off: si Ave-Hub responde 201 pero
            // el POST tarda mucho y el caller re-intenta en paralelo (race),
            // el segundo disparo genera un nuevo event_id en el bucket actual
            // (mismo segundo) y puede producir un POST duplicado. Es aceptable:
            // Ave-Hub tiene Idempotency-Key en push_events() (linea 162 API)
            // que deduplica en el lado del server. Es defensa en profundidad.
            set_transient( $cache_key, true, HOUR_IN_SECONDS );

            self::log_info_static(
                'AVEONLINE_HUB_PUSH',
                sprintf(
                    'Ave-Hub: evento reportado — order_id=%d cod_estado=%s nombre_estado=%s',
                    $order_id, $cod_estado, $nombre_estado
                )
            );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'LTMS_Business_Aveonline_Hub_Log' ) ) {
                // CICLO18-P1-AO-057 FIX (error path): mismo guard que el
                // success path. Si el INSERT del log de error falla, el error
                // solo queda en ltms-error log (log_error_static siguiente).
                $log_id = LTMS_Business_Aveonline_Hub_Log::record(
                    $event,
                    'error',
                    $e->getMessage(),
                    $order_id
                );
                if ( ! $log_id ) {
                    self::log_error_static(
                        'AVEONLINE_HUB_LOG_INSERT_FAILED',
                        sprintf(
                            'Ave-Hub: error-path log INSERT fallo - order_id=%d cod_estado=%s error=%s (auditar estado del evento manualmente)',
                            $order_id, $cod_estado, $e->getMessage()
                        )
                    );
                }
            }

            self::log_error_static(
                'AVEONLINE_HUB_PUSH_ERROR',
                sprintf(
                    'Ave-Hub: error al reportar evento — order_id=%d cod_estado=%s: %s',
                    $order_id, $cod_estado, $e->getMessage()
                )
            );

            // CICLO18-P1-AO-051 FIX (error path): NO set_transient aqui. El
            // push fallo (token expirado sin retry, red caida, 4xx/5xx HTTP).
            // Permitir reintento en cualquier momento (dentro de la hora, el
            // event_id generado es el mismo -> mismo cache_key -> transient no
            // seteado -> reintento permitido). En la siguiente hora el bucket
            // cambia y se genera un nuevo event_id (tambien reintento OK).
        }
    }
}
