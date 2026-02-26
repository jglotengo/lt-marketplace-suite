<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Uber
 *
 * Cliente para Uber Direct (Uber Eats Delivery API).
 * Provee: cotización de envíos, creación, consulta y cancelación de entregas.
 * Utiliza OAuth2 client_credentials con caché de token vía WordPress transients.
 *
 * Opciones de configuración requeridas (wp-config / LTMS settings):
 *   - ltms_uber_direct_client_id      Client ID de la aplicación Uber
 *   - ltms_uber_direct_client_secret  Client Secret (cifrado con LTMS_Core_Security)
 *   - ltms_uber_direct_customer_id    Customer ID de la cuenta Uber Direct
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://developer.uber.com/docs/deliveries/introduction
 */
class LTMS_Api_Uber extends LTMS_Abstract_API_Client {

    /**
     * URL del servidor de autenticación OAuth2 de Uber.
     */
    const AUTH_URL = 'https://auth.uber.com/oauth/v2/token';

    /**
     * Client ID de la aplicación Uber Direct.
     *
     * @var string
     */
    private string $client_id;

    /**
     * Client Secret de la aplicación Uber Direct.
     *
     * @var string
     */
    private string $client_secret;

    /**
     * Customer ID de la cuenta Uber Direct (para rutas de la API).
     *
     * @var string
     */
    private string $customer_id;

    /**
     * Constructor.
     *
     * @throws \RuntimeException Si las credenciales no están configuradas.
     */
    public function __construct() {
        $this->provider_slug = 'uber';
        $this->api_url       = 'https://api.uber.com';
        $this->timeout       = 30;

        $this->client_id   = LTMS_Core_Config::get( 'ltms_uber_direct_client_id', '' );
        $this->customer_id = LTMS_Core_Config::get( 'ltms_uber_direct_customer_id', '' );

        $encrypted_secret    = LTMS_Core_Config::get( 'ltms_uber_direct_client_secret', '' );
        $this->client_secret = ! empty( $encrypted_secret )
            ? LTMS_Core_Security::decrypt( $encrypted_secret )
            : '';

        if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
            throw new \RuntimeException( 'LTMS Uber Direct: Credenciales OAuth2 no configuradas.' );
        }

        if ( empty( $this->customer_id ) ) {
            throw new \RuntimeException( 'LTMS Uber Direct: Customer ID no configurado (ltms_uber_direct_customer_id).' );
        }
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    /**
     * Solicita una cotización de envío (delivery quote) a Uber Direct.
     *
     * @param array $quote_data {
     *     @type array  $pickup_address  Dirección de recogida con campos:
     *                                   street_address (array de líneas), city, state, zip_code, country.
     *     @type array  $dropoff_address Dirección de entrega con la misma estructura.
     *     @type array  $manifest_items  Lista de artículos, cada uno con name, quantity, size
     *                                   ('small'|'medium'|'large'|'xlarge').
     * }
     * @return array Respuesta de Uber con quote_id, fee, currency, expiration, etc.
     * @throws \RuntimeException Si la solicitud falla o el token no puede obtenerse.
     */
    public function get_quote( array $quote_data ): array {
        $this->refresh_auth_header();

        $endpoint = sprintf( '/v1/customers/%s/delivery_quotes', rawurlencode( $this->customer_id ) );

        return $this->perform_request( 'POST', $endpoint, $quote_data );
    }

    /**
     * Crea una entrega (delivery) en Uber Direct usando una cotización previa.
     *
     * @param string $quote_id     Quote ID obtenido de get_quote().
     * @param array  $delivery_data {
     *     @type string $pickup_name       Nombre del remitente.
     *     @type string $pickup_address    Dirección de recogida (texto completo).
     *     @type string $pickup_phone_number Teléfono del remitente en formato E.164.
     *     @type string $dropoff_name      Nombre del destinatario.
     *     @type string $dropoff_address   Dirección de entrega (texto completo).
     *     @type string $dropoff_phone_number Teléfono del destinatario en formato E.164.
     *     @type array  $manifest_items    Lista de artículos del pedido.
     *     @type string $external_id       ID externo del pedido (ej. ID de WooCommerce).
     * }
     * @return array Respuesta de Uber con delivery_id, status, tracking_url, etc.
     * @throws \RuntimeException Si la creación falla.
     */
    public function create_delivery( string $quote_id, array $delivery_data ): array {
        $this->refresh_auth_header();

        $endpoint = sprintf( '/v1/customers/%s/deliveries', rawurlencode( $this->customer_id ) );

        $payload = array_merge(
            $delivery_data,
            [ 'quote_id' => $quote_id ]
        );

        return $this->perform_request( 'POST', $endpoint, $payload );
    }

    /**
     * Obtiene el estado y detalles de una entrega existente.
     *
     * @param string $delivery_id ID de la entrega en Uber Direct.
     * @return array Datos de la entrega: status, courier, tracking_url, etc.
     * @throws \RuntimeException Si la consulta falla.
     */
    public function get_delivery( string $delivery_id ): array {
        $this->refresh_auth_header();

        $endpoint = sprintf(
            '/v1/customers/%s/deliveries/%s',
            rawurlencode( $this->customer_id ),
            rawurlencode( $delivery_id )
        );

        return $this->perform_request( 'GET', $endpoint );
    }

    /**
     * Cancela una entrega activa en Uber Direct.
     *
     * @param string $delivery_id ID de la entrega a cancelar.
     * @return array Respuesta de Uber con el estado de la cancelación.
     * @throws \RuntimeException Si la cancelación falla o la entrega no es cancelable.
     */
    public function cancel_delivery( string $delivery_id ): array {
        $this->refresh_auth_header();

        $endpoint = sprintf(
            '/v1/customers/%s/deliveries/%s/cancel',
            rawurlencode( $this->customer_id ),
            rawurlencode( $delivery_id )
        );

        return $this->perform_request( 'POST', $endpoint );
    }

    /**
     * Verifica la conectividad con la API de Uber Direct obteniendo un access token.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array {
        $start = microtime( true );

        try {
            $this->get_access_token();
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Obtiene un access token OAuth2 de Uber, usando caché de transient.
     *
     * Si existe un token vigente en el transient 'ltms_uber_access_token' lo devuelve
     * directamente. Si no, solicita uno nuevo con grant_type=client_credentials y
     * scope=eats.deliveries, y lo almacena en el transient por (expires_in - 60) segundos.
     *
     * @return string Bearer token listo para usar en el header Authorization.
     * @throws \RuntimeException Si la autenticación con Uber falla.
     */
    private function get_access_token(): string {
        $transient_key = 'ltms_uber_access_token';
        $cached        = get_transient( $transient_key );

        if ( ! empty( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_post( self::AUTH_URL, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'client_credentials',
                'scope'         => 'eats.deliveries',
            ],
            'timeout'   => 20,
            'sslverify' => LTMS_Core_Config::is_production(),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                '[uber] Error de red al autenticar: ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            $err = $body['error_description'] ?? $body['error'] ?? "HTTP {$status}";
            throw new \RuntimeException(
                sprintf( '[uber] Error de autenticación OAuth2: %s', $err )
            );
        }

        $token      = $body['access_token'];
        $expires_in = (int) ( $body['expires_in'] ?? 3600 );
        $ttl        = max( 60, $expires_in - 60 );

        set_transient( $transient_key, $token, $ttl );

        return $token;
    }

    /**
     * Refresca el header Authorization con un token válido antes de cada petición.
     *
     * @return void
     * @throws \RuntimeException Si no se puede obtener el token.
     */
    private function refresh_auth_header(): void {
        $this->default_headers['Authorization'] = 'Bearer ' . $this->get_access_token();
    }
}
