<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Heka
 *
 * Cliente para Heka Entrega, operador logístico colombiano.
 * Provee: consulta de tarifas, creación de envíos, rastreo y health check.
 * Autenticación mediante API Key estática en el header X-API-Key.
 *
 * Opciones de configuración requeridas (wp-config / LTMS settings):
 *   - ltms_heka_api_key     API Key de Heka (cifrada con LTMS_Core_Security)
 *   - ltms_heka_account_id  ID de cuenta Heka para facturación y reportes
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://api.hekaentrega.com/docs
 */
class LTMS_Api_Heka extends LTMS_Abstract_API_Client {

    /**
     * ID de la cuenta Heka, para incluir en payloads cuando aplica.
     *
     * @var string
     */
    private string $account_id;

    /**
     * Constructor.
     *
     * Lee la API Key cifrada, la descifra y la coloca en el header X-API-Key
     * de modo que todas las peticiones la envíen automáticamente.
     *
     * @throws \RuntimeException Si la API Key no está configurada.
     */
    public function __construct() {
        $this->provider_slug = 'heka';
        $this->api_url       = 'https://api.hekaentrega.com';
        $this->timeout       = 30;

        $encrypted_key = LTMS_Core_Config::get( 'ltms_heka_api_key', '' );
        if ( empty( $encrypted_key ) ) {
            throw new \RuntimeException( 'LTMS Heka: La API Key no está configurada (ltms_heka_api_key).' );
        }

        $api_key            = LTMS_Core_Security::decrypt( $encrypted_key );
        $this->account_id   = LTMS_Core_Config::get( 'ltms_heka_account_id', '' );

        // La API Key se incluye en todos los requests mediante este header.
        $this->default_headers['X-API-Key'] = $api_key;
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    /**
     * Consulta las tarifas de envío disponibles para un origen/destino y peso.
     *
     * @param array $rate_query {
     *     @type string $origin_city      Ciudad de origen (nombre o código DANE).
     *     @type string $destination_city Ciudad de destino (nombre o código DANE).
     *     @type float  $weight_kg        Peso del paquete en kilogramos.
     *     @type float  $declared_value   Valor declarado del contenido en COP.
     *     @type int    $items_count      Número de unidades en el paquete.
     * }
     * @return array Lista de opciones de servicio con carrier, price, eta_days, service_type.
     * @throws \RuntimeException Si la consulta de tarifas falla.
     */
    public function get_rates( array $rate_query ): array {
        $payload = [
            'origin_city'      => sanitize_text_field( $rate_query['origin_city']      ?? '' ),
            'destination_city' => sanitize_text_field( $rate_query['destination_city'] ?? '' ),
            'weight_kg'        => (float) ( $rate_query['weight_kg']       ?? 0.0 ),
            'declared_value'   => (float) ( $rate_query['declared_value']  ?? 0.0 ),
            'items_count'      => (int)   ( $rate_query['items_count']     ?? 1 ),
        ];

        if ( ! empty( $this->account_id ) ) {
            $payload['account_id'] = $this->account_id;
        }

        return $this->perform_request( 'POST', '/v1/rates', $payload );
    }

    /**
     * Crea un nuevo envío en Heka Entrega.
     *
     * El array $data debe incluir la información completa del remitente, destinatario,
     * paquete y servicio seleccionado (obtenido de get_rates()).
     *
     * Campos esperados (referencia):
     *   - service_type        : Tipo de servicio seleccionado (de get_rates).
     *   - origin              : array con name, phone, address, city, country.
     *   - destination         : array con name, phone, address, city, country.
     *   - package             : array con weight_kg, width_cm, height_cm, length_cm.
     *   - declared_value      : float Valor asegurado del contenido en COP.
     *   - external_reference  : string ID del pedido WooCommerce para trazabilidad.
     *   - description         : string Descripción del contenido.
     *
     * @param array $data Datos completos del envío.
     * @return array Respuesta de Heka con shipment_id, tracking_number, label_url, etc.
     * @throws \RuntimeException Si la creación del envío falla.
     */
    public function create_shipment( array $data ): array {
        if ( ! empty( $this->account_id ) && empty( $data['account_id'] ) ) {
            $data['account_id'] = $this->account_id;
        }

        $response = $this->perform_request( 'POST', '/v1/shipments', $data );

        if ( ! empty( $response['tracking_number'] ) ) {
            LTMS_Core_Logger::info(
                'HEKA_SHIPMENT_CREATED',
                sprintf(
                    'Envío Heka creado. Tracking: %s, ID: %s',
                    $response['tracking_number'],
                    $response['shipment_id'] ?? '?'
                ),
                [
                    'tracking_number' => $response['tracking_number'],
                    'shipment_id'     => $response['shipment_id'] ?? '',
                    'external_ref'    => $data['external_reference'] ?? '',
                ]
            );
        }

        return $response;
    }

    /**
     * Rastrea el estado actual de un envío por su número de tracking.
     *
     * @param string $tracking_number Número de guía asignado por Heka al crear el envío.
     * @return array Estado del envío: status, location, estimated_delivery, events[], etc.
     * @throws \RuntimeException Si el número de tracking no existe o la consulta falla.
     */
    public function track_shipment( string $tracking_number ): array {
        $tracking_number = sanitize_text_field( $tracking_number );
        $endpoint        = '/v1/shipments/track/' . rawurlencode( $tracking_number );

        return $this->perform_request( 'GET', $endpoint );
    }

    /**
     * Verifica la conectividad con la API de Heka Entrega.
     *
     * Sobreescribe el health_check() base llamando a /v1/health sin reintentos.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array {
        $start = microtime( true );

        try {
            $this->perform_request( 'GET', '/v1/health', [], [], false );
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

            return [
                'status'     => 'ok',
                'message'    => 'Conectado',
                'latency_ms' => $latency,
            ];
        } catch ( \Throwable $e ) {
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
