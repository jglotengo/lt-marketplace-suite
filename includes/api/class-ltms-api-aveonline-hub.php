<?php
/**
 * LTMS_Api_Aveonline_Hub
 *
 * Cliente HTTP para el sistema Ave-Hub de Aveonline:
 *   - Autenticación propia (token JWT de 21 h, header Ave-Hub-signature)
 *   - Envío de eventos al hub:  POST /shipping-events-hook
 *   - Consulta de logs del hub: GET  /shipping-events-logs
 *
 * Rol de Lo Tengo en este flujo: Lo Tengo actúa como PROVEEDOR LOGÍSTICO
 * (transportadora) dentro del ecosistema Ave-Hub para los envíos que
 * gestiona directamente (domiciliarios propios del vendedor, recogida en
 * tienda, etc.) y que no pasan por la generación de guía de la API
 * principal de Aveonline. Cada vez que el estado de uno de esos envíos
 * cambia, Lo Tengo reporta el evento a Ave-Hub mediante push_events().
 *
 * DIFERENCIA vs LTMS_Api_Aveonline:
 *   - La API principal usa /api/comunes/v2.0/ con token de 12 h.
 *   - Ave-Hub usa /api-webhook/public/api/v1/ con token de 21 h y header propio.
 *   - Ave-Hub requiere idtransportadora (el ID del proveedor logístico en Aveonline,
 *     no el ID de empresa; se configura en ltms_aveonline_hub_idtransportadora).
 *
 * Credenciales de configuración (opciones de WordPress):
 *   ltms_aveonline_hub_idtransportadora  — ID del proveedor logístico en Ave-Hub
 *   ltms_aveonline_hub_token             — JWT almacenado en caché (no editar a mano)
 *   ltms_aveonline_hub_token_expires     — Unix timestamp de expiración
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @since      2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Api_Aveonline_Hub {

    // ── Endpoints ────────────────────────────────────────────────────────────

    const BASE_URL       = 'https://api.aveonline.co/api-webhook/public/api/v1';
    const ENDPOINT_LOGIN = '/login';
    const ENDPOINT_HOOK  = '/shipping-events-hook';
    const ENDPOINT_LOGS  = '/shipping-events-logs';

    /** Razón fija requerida por la API de Ave-Hub */
    const REASON = 'actualizar_estados_guias';

    /** Margen de seguridad: renovar el token 30 min antes de que expire */
    const TOKEN_MARGIN = 1800;

    // ── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Obtiene el token JWT del Ave-Hub (caché de 21 h).
     * Renueva automáticamente si está vencido o por vencer.
     *
     * @return string Token JWT.
     * @throws \RuntimeException Si la autenticación falla.
     */
    public function get_token(): string {
        $cached_token   = get_option( 'ltms_aveonline_hub_token', '' );
        $token_expires  = (int) get_option( 'ltms_aveonline_hub_token_expires', 0 );

        // Usar caché si es válido y no está próximo a expirar
        if ( $cached_token && $token_expires > ( time() + self::TOKEN_MARGIN ) ) {
            return $cached_token;
        }

        return $this->refresh_token();
    }

    /**
     * Fuerza renovación del token JWT del Ave-Hub.
     *
     * @return string Nuevo token.
     * @throws \RuntimeException Si las credenciales son inválidas.
     */
    public function refresh_token(): string {
        $id_transportadora = (int) get_option( 'ltms_aveonline_hub_idtransportadora', 0 );

        if ( ! $id_transportadora ) {
            throw new \RuntimeException(
                __( 'Ave-Hub: idtransportadora no configurado (ltms_aveonline_hub_idtransportadora).', 'ltms' )
            );
        }

        $response = wp_remote_post( self::BASE_URL . self::ENDPOINT_LOGIN, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'type' => 'webhook_auth',
                'data' => [
                    'idtransportadora' => $id_transportadora,
                    'reason'           => self::REASON,
                ],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                'Ave-Hub auth error de red: ' . $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 || ( $body['status'] ?? '' ) !== 'success' || empty( $body['data']['signature_hash'] ) ) {
            $detail = $body['errors'][0]['detail'] ?? $body['status'] ?? 'respuesta inesperada';
            throw new \RuntimeException( sprintf( 'Ave-Hub auth fallida (HTTP %d): %s', $http_code, $detail ) );
        }

        $token      = $body['data']['signature_hash'];
        $expires_at = $body['data']['signature_hash_expires_at'] ?? '';

        // Calcular timestamp de expiración
        $expires_ts = $expires_at
            ? ( new \DateTime( $expires_at ) )->getTimestamp()
            : ( time() + ( 21 * 3600 ) ); // fallback: 21 h

        update_option( 'ltms_aveonline_hub_token',         $token,      false );
        update_option( 'ltms_aveonline_hub_token_expires',  $expires_ts, false );

        return $token;
    }

    // ── Push de eventos al hub ────────────────────────────────────────────────

    /**
     * Envía uno o más eventos de estado al Ave-Hub.
     *
     * @param  array  $events  Array de eventos. Cada evento debe incluir al menos:
     *                         id_envio*, cod_estado*, nombre_estado*, fecha_estado*.
     *                         Opcionales: cod_novedad, nombre_novedad, fecha_novedad,
     *                         estado_novedad, guia_reeemplazo, tipo_guia_reeemplazo,
     *                         ruta_digitalizada, base64_entrega_digitalizada, observaciones.
     * @param  string $tipo    'json' (default) o 'xml'.
     * @return array  Respuesta de la API: { status, message, status_code }.
     * @throws \RuntimeException Si hay error de red o la API rechaza la petición.
     */
    public function push_events( array $events, string $tipo = 'json' ): array {
        $token = $this->get_token();

        // Aveonline exige que body siempre sea un array, aunque sea un solo evento
        $payload = [
            'tipo' => $tipo,
            'body' => array_values( $events ),
        ];

        $response = wp_remote_post( self::BASE_URL . self::ENDPOINT_HOOK, [
            'headers' => [
                'Content-Type'       => 'application/json',
                'Accept'             => 'application/json',
                'Ave-Hub-signature'  => $token,
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30, // v2.9.134 ERROR-AUDIT P0-1: add timeout
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Ave-Hub push error de red: ' . $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        // 401 → token vencido: limpiar caché e intentar una vez más
        if ( $http_code === 401 ) {
            delete_option( 'ltms_aveonline_hub_token' );
            delete_option( 'ltms_aveonline_hub_token_expires' );
            $token = $this->refresh_token();

            $response = wp_remote_post( self::BASE_URL . self::ENDPOINT_HOOK, [
                'headers' => [
                    'Content-Type'       => 'application/json',
                    'Accept'             => 'application/json',
                    'Ave-Hub-signature'  => $token,
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30, // v2.9.134 ERROR-AUDIT P0-1: add timeout
            ] );

            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        if ( $http_code !== 201 ) {
            $detail = $body['errors'][0]['detail'] ?? $body['message'] ?? "HTTP {$http_code}";
            throw new \RuntimeException( sprintf( 'Ave-Hub push rechazado (HTTP %d): %s', $http_code, $detail ) );
        }

        return $body;
    }

    // ── Consulta de logs ──────────────────────────────────────────────────────

    /**
     * Consulta el historial de eventos recibidos por el Ave-Hub.
     *
     * @param  array $filters Filtros opcionales:
     *   - id_envio      (string)  Número de guía específica.
     *   - fecha_inicio  (string)  AAAA-MM-DD.
     *   - fecha_fin     (string)  AAAA-MM-DD.
     *   - hoy           (bool)    Solo eventos de hoy.
     * @return array  Array con 'meta' y 'data' (eventos con su payload completo).
     * @throws \RuntimeException Si hay error de red.
     */
    public function get_logs( array $filters = [] ): array {
        $token = $this->get_token();

        $url = self::BASE_URL . self::ENDPOINT_LOGS;

        // Construir query string con los filtros
        $query_params = [];
        if ( ! empty( $filters['id_envio'] ) ) {
            $query_params['id_envio'] = sanitize_text_field( $filters['id_envio'] );
        }
        if ( ! empty( $filters['fecha_inicio'] ) ) {
            $query_params['fecha_inicio'] = sanitize_text_field( $filters['fecha_inicio'] );
        }
        if ( ! empty( $filters['fecha_fin'] ) ) {
            $query_params['fecha_fin'] = sanitize_text_field( $filters['fecha_fin'] );
        }
        if ( ! empty( $filters['hoy'] ) ) {
            $query_params['hoy'] = '1';
        }

        if ( ! empty( $query_params ) ) {
            $url .= '?' . http_build_query( $query_params );
        }

        $response = wp_remote_get( $url, [
            'headers' => [
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'Ave-Hub-signature' => $token,
            ],
            'timeout' => 20, // v2.9.134 P0-1: add timeout
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Ave-Hub logs error de red: ' . $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );

        // 401 → renovar token y reintentar
        if ( $http_code === 401 ) {
            delete_option( 'ltms_aveonline_hub_token' );
            delete_option( 'ltms_aveonline_hub_token_expires' );
            $token = $this->refresh_token();

            $response  = wp_remote_get( $url, [
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                    'Ave-Hub-signature' => $token,
                ],
                'timeout' => 20, // v2.9.134 P0-1: add timeout
            ] );
            $http_code = wp_remote_retrieve_response_code( $response );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 || ( $body['status'] ?? '' ) !== 'success' ) {
            $detail = $body['errors'][0]['detail'] ?? $body['message'] ?? "HTTP {$http_code}";
            throw new \RuntimeException( sprintf( 'Ave-Hub logs error (HTTP %d): %s', $http_code, $detail ) );
        }

        return [
            'meta' => $body['meta'] ?? [],
            'data' => $body['data'] ?? [],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Construye un evento con el schema exacto que espera Ave-Hub.
     * Útil como factory para no tener que recordar los nombres de campo.
     *
     * @param  array $args {
     *   @type string $id_envio               Requerido. Identificador interno de Lo Tengo (order_id).
     *   @type string $cod_estado             Requerido. Código de estado del proveedor.
     *   @type string $nombre_estado          Requerido. Nombre del estado.
     *   @type string $fecha_estado           Requerido. AAAA-MM-DD HH:MM:ss.
     *   @type string $cod_novedad            Opcional.
     *   @type string $nombre_novedad         Opcional.
     *   @type string $fecha_novedad          Opcional.
     *   @type string $estado_novedad         Opcional.
     *   @type string $guia_reeemplazo        Opcional (3 'e' es el campo real de la API).
     *   @type string $tipo_guia_reeemplazo   Opcional: DEV | REEMP | CONT.
     *   @type string $ruta_digitalizada      Opcional. URL del PDF.
     *   @type array  $base64_entrega         Opcional: ['base64'=>'...', 'mime_type'=>'...'].
     *   @type string $observaciones          Opcional.
     * }
     * @return array Evento listo para enviar en push_events().
     */
    public static function build_event( array $args ): array {
        return [
            'id_envio'                    => (string) ( $args['id_envio']              ?? '' ),
            'cod_estado'                  => (string) ( $args['cod_estado']             ?? '' ),
            'nombre_estado'               => (string) ( $args['nombre_estado']          ?? '' ),
            'fecha_estado'                => (string) ( $args['fecha_estado']           ?? '' ),
            'cod_novedad'                 => $args['cod_novedad']                ?? null,
            'nombre_novedad'              => $args['nombre_novedad']             ?? null,
            'fecha_novedad'               => $args['fecha_novedad']              ?? null,
            'estado_novedad'              => $args['estado_novedad']             ?? null,
            'guia_reeemplazo'             => $args['guia_reeemplazo']            ?? null, // 3 'e' per spec
            'tipo_guia_reeemplazo'        => $args['tipo_guia_reeemplazo']       ?? null,
            'ruta_digitalizada'           => $args['ruta_digitalizada']          ?? null,
            'base64_entrega_digitalizada' => [
                'base64'    => $args['base64_entrega']['base64']    ?? null,
                'mime_type' => $args['base64_entrega']['mime_type'] ?? null,
            ],
            'observaciones'               => $args['observaciones'] ?? null,
        ];
    }
}
