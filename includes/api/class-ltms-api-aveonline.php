<?php
/**
 * LTMS API Client - Aveonline (Logística y Envíos)
 *
 * Integración con Aveonline para gestión de envíos:
 * - Creación de guías de envío
 * - Consulta de estados de tracking
 * - Cálculo de tarifas de envío
 * - Generación de etiquetas PDF
 * - Gestión de devoluciones
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Aveonline
 */
class LTMS_Api_Aveonline extends LTMS_Abstract_Api_Client {

    const API_BASE_LIVE    = 'https://api.aveonline.co/v1';
    const API_BASE_SANDBOX = 'https://sandbox.aveonline.co/v1';

    /**
     * @var string API key de Aveonline.
     */
    private string $api_key;

    /**
     * @var string Account ID de Aveonline.
     */
    private string $account_id;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->base_url   = LTMS_ENVIRONMENT === 'production' ? self::API_BASE_LIVE : self::API_BASE_SANDBOX;
        $this->api_key    = LTMS_Core_Security::decrypt( LTMS_Core_Config::get( 'ltms_aveonline_api_key', '' ) );
        $this->account_id = LTMS_Core_Config::get( 'ltms_aveonline_account_id', '' );
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_slug(): string {
        return 'aveonline';
    }

    /**
     * Crea una guía de envío.
     *
     * @param array $shipment_data Datos del envío.
     * @return array{success: bool, tracking_number: string, label_url: string, cost: float}
     */
    public function create_shipment( array $shipment_data ): array {
        $payload = [
            'account_id'  => $this->account_id,
            'reference'   => $shipment_data['reference'] ?? LTMS_Utils::generate_reference( 'AVE' ),
            'shipper'     => $this->format_address( $shipment_data['origin'] ),
            'consignee'   => $this->format_address( $shipment_data['destination'] ),
            'packages'    => $this->format_packages( $shipment_data['packages'] ?? [] ),
            'service'     => $shipment_data['service'] ?? 'express',
            'declared_value' => (float) ( $shipment_data['declared_value'] ?? 0 ),
            'description' => $shipment_data['description'] ?? '',
        ];

        $response = $this->perform_request( 'POST', '/shipments', $payload );

        return [
            'success'         => isset( $response['tracking_number'] ),
            'tracking_number' => $response['tracking_number'] ?? '',
            'label_url'       => $response['label_url'] ?? '',
            'cost'            => (float) ( $response['cost'] ?? 0 ),
            'shipment_id'     => $response['id'] ?? '',
        ];
    }

    /**
     * Consulta el estado de un envío.
     *
     * @param string $tracking_number Número de guía.
     * @return array{status: string, events: array, estimated_delivery: string}
     */
    public function track_shipment( string $tracking_number ): array {
        $response = $this->perform_request( 'GET', '/tracking/' . urlencode( $tracking_number ) );

        return [
            'status'             => $response['status'] ?? 'unknown',
            'events'             => $response['events'] ?? [],
            'estimated_delivery' => $response['estimated_delivery'] ?? '',
            'current_location'   => $response['current_location'] ?? '',
        ];
    }

    /**
     * Calcula tarifas de envío para origen-destino y paquete.
     *
     * @param array $rate_query Datos para cotización.
     * @return array Lista de opciones de servicio con tarifas.
     */
    public function get_rates( array $rate_query ): array {
        $payload = [
            'origin'      => $rate_query['origin_city'] ?? '',
            'destination' => $rate_query['destination_city'] ?? '',
            'weight'      => (float) ( $rate_query['weight_kg'] ?? 1 ),
            'dimensions'  => [
                'length' => (float) ( $rate_query['length_cm'] ?? 30 ),
                'width'  => (float) ( $rate_query['width_cm'] ?? 20 ),
                'height' => (float) ( $rate_query['height_cm'] ?? 15 ),
            ],
            'declared_value' => (float) ( $rate_query['declared_value'] ?? 0 ),
        ];

        $response = $this->perform_request( 'POST', '/rates', $payload );

        return $response['rates'] ?? [];
    }

    /**
     * Descarga la etiqueta de envío en base64.
     *
     * @param string $shipment_id ID del envío.
     * @return string Base64 del PDF o string vacío si falla.
     */
    public function get_label( string $shipment_id ): string {
        $response = $this->perform_request( 'GET', '/shipments/' . $shipment_id . '/label' );
        return $response['label_base64'] ?? '';
    }

    /**
     * Cancela un envío (antes de despacho).
     *
     * @param string $shipment_id ID del envío.
     * @return bool
     */
    public function cancel_shipment( string $shipment_id ): bool {
        $response = $this->perform_request( 'DELETE', '/shipments/' . $shipment_id );
        return isset( $response['cancelled'] ) && $response['cancelled'] === true;
    }

    /**
     * {@inheritdoc}
     */
    public function health_check(): array {
        try {
            $response = $this->perform_request( 'GET', '/health' );
            return [
                'status'  => ( $response['status'] ?? '' ) === 'ok' ? 'ok' : 'error',
                'message' => 'Aveonline API conectado',
            ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    protected function get_default_headers(): array {
        return array_merge( parent::get_default_headers(), [
            'X-Api-Key'     => $this->api_key,
            'X-Account-Id'  => $this->account_id,
        ]);
    }

    /**
     * Formatea una dirección para el payload de Aveonline.
     *
     * @param array $address Datos de dirección.
     * @return array
     */
    private function format_address( array $address ): array {
        return [
            'name'     => $address['name'] ?? '',
            'phone'    => LTMS_Utils::format_phone_e164( $address['phone'] ?? '' ),
            'email'    => $address['email'] ?? '',
            'address'  => $address['address'] ?? '',
            'city'     => $address['city'] ?? '',
            'state'    => $address['state'] ?? '',
            'country'  => 'CO',
            'zip_code' => $address['zip_code'] ?? '',
        ];
    }

    /**
     * Formatea los paquetes para el payload.
     *
     * @param array $packages Lista de paquetes.
     * @return array
     */
    private function format_packages( array $packages ): array {
        if ( empty( $packages ) ) {
            return [[
                'weight'  => 1.0,
                'length'  => 30,
                'width'   => 20,
                'height'  => 15,
                'quantity' => 1,
            ]];
        }

        return array_map( fn( $p ) => [
            'weight'   => (float) ( $p['weight_kg'] ?? 1 ),
            'length'   => (int) ( $p['length_cm'] ?? 30 ),
            'width'    => (int) ( $p['width_cm'] ?? 20 ),
            'height'   => (int) ( $p['height_cm'] ?? 15 ),
            'quantity' => (int) ( $p['quantity'] ?? 1 ),
        ], $packages );
    }
}
