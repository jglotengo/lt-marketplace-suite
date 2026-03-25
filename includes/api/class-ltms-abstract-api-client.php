<?php
/**
 * LTMS Abstract API Client
 *
 * Clase base para todos los clientes de API externas.
 * Provee: HTTP con reintentos, logging, manejo de errores,
 * rate limiting, circuit breaker y timeout configurables.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract Class LTMS_Abstract_API_Client
 */
abstract class LTMS_Abstract_API_Client implements LTMS_API_Client_Interface {

    use LTMS_Logger_Aware;

    /**
     * URL base de la API.
     *
     * @var string
     */
    protected string $api_url = '';

    /**
     * Slug del proveedor (para logs y configuración).
     *
     * @var string
     */
    protected string $provider_slug = '';

    /**
     * Timeout en segundos para las peticiones HTTP (v1.7.0: configurable).
     *
     * @var int
     */
    protected int $timeout = 30;

    /**
     * Número máximo de reintentos en caso de error de red (v1.7.0: configurable).
     *
     * @var int
     */
    protected int $max_retries = 3;

    /**
     * Delay en segundos entre reintentos (v1.7.0: configurable).
     *
     * @var int
     */
    protected int $retry_delay = 1;

    /**
     * Inicializa valores configurables desde LTMS_Core_Config.
     * Llamar en el constructor de subclases (o usar __construct).
     */
    protected function init_configurable_settings(): void {
        if ( class_exists( 'LTMS_Core_Config' ) ) {
            $this->timeout     = (int) LTMS_Core_Config::get( 'ltms_api_timeout_seconds', 30 );
            $this->max_retries = (int) LTMS_Core_Config::get( 'ltms_api_max_retries', 3 );
            $this->retry_delay = (int) LTMS_Core_Config::get( 'ltms_api_retry_delay_seconds', 1 );
        }
    }

    /**
     * Headers por defecto para todas las peticiones.
     *
     * @var array<string, string>
     */
    protected array $default_headers = [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
    ];

    /**
     * Realiza una petición HTTP al endpoint especificado.
     *
     * @param string $method    Método HTTP (GET, POST, PUT, DELETE, PATCH).
     * @param string $endpoint  Endpoint relativo (ej: '/charges').
     * @param array  $data      Datos del body (para POST/PUT/PATCH).
     * @param array  $headers   Headers adicionales.
     * @param bool   $retry     Si se deben reintentar errores de red.
     * @return array Respuesta decodificada como array.
     * @throws \RuntimeException En errores de red o HTTP no-2xx.
     */
    protected function perform_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        $url     = rtrim( $this->api_url, '/' ) . '/' . ltrim( $endpoint, '/' );
        $method  = strtoupper( $method );
        $headers = array_merge( $this->default_headers, $headers );

        $args = [
            'method'    => $method,
            'headers'   => $headers,
            'timeout'   => $this->timeout,
            // SSL is always verified. Set LTMS_DISABLE_SSL_VERIFY=true in wp-config.php
            // ONLY for local development with self-signed certificates.
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
        ];

        if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $start_time = microtime( true );
        $attempts   = 0;
        $last_error = null;

        do {
            $attempts++;

            if ( $attempts > 1 ) {
                // Delay exponencial: 1s, 2s, 4s...
                sleep( $this->retry_delay * ( 2 ** ( $attempts - 2 ) ) );
            }

            $response = wp_remote_request( $url, $args );
            $duration = (int) round( ( microtime( true ) - $start_time ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $last_error = $response->get_error_message();

                $this->log_api_call(
                    $method, $endpoint, $data,
                    null, null, $duration,
                    'error', $last_error, $attempts
                );

                if ( ! $retry || $attempts >= $this->max_retries ) {
                    throw new \RuntimeException(
                        sprintf( '[%s] Error de red: %s (intentos: %d)', $this->provider_slug, $last_error, $attempts )
                    );
                }

                continue;
            }

            $status_code   = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $decoded       = json_decode( $response_body, true ) ?? [];

            $this->log_api_call(
                $method, $endpoint, $data,
                $status_code, $decoded, $duration,
                $status_code >= 200 && $status_code < 300 ? 'success' : 'error',
                null, $attempts
            );

            // Éxito (2xx)
            if ( $status_code >= 200 && $status_code < 300 ) {
                return $decoded;
            }

            // Error del servidor (5xx) - reintentable
            if ( $status_code >= 500 && $retry && $attempts < $this->max_retries ) {
                $last_error = sprintf( 'HTTP %d del servidor (reintentando...)', $status_code );
                continue;
            }

            // Error del cliente (4xx) - no reintentable
            $error_message = $this->extract_error_message( $decoded, $status_code );

            throw new \RuntimeException(
                sprintf(
                    '[%s] Error HTTP %d: %s | URL: %s',
                    $this->provider_slug,
                    $status_code,
                    $error_message,
                    $url
                ),
                $status_code
            );

        } while ( $attempts < $this->max_retries );

        throw new \RuntimeException(
            sprintf(
                '[%s] Máximo de reintentos alcanzado (%d). Último error: %s',
                $this->provider_slug,
                $this->max_retries,
                $last_error ?? 'Error desconocido'
            )
        );
    }

    /**
     * Registra la llamada API en la tabla lt_api_logs.
     *
     * @param string     $method         Método HTTP.
     * @param string     $endpoint       Endpoint.
     * @param array      $request_data   Datos enviados.
     * @param int|null   $response_code  Código HTTP recibido.
     * @param array|null $response_body  Respuesta decodificada.
     * @param int        $duration_ms    Duración en ms.
     * @param string     $status         'success'|'error'|'timeout'|'retry'.
     * @param string|null $error_msg     Mensaje de error si aplica.
     * @param int        $attempts       Número de intentos realizados.
     * @return void
     */
    private function log_api_call(
        string   $method,
        string   $endpoint,
        array    $request_data,
        ?int     $response_code,
        ?array   $response_body,
        int      $duration_ms,
        string   $status,
        ?string  $error_msg,
        int      $attempts
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_api_logs';

        // Sanitizar request_data eliminando secretos
        $safe_request = $this->redact_sensitive_data( $request_data );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'provider'      => $this->provider_slug,
                'endpoint'      => substr( $this->api_url . $endpoint, 0, 500 ),
                'method'        => $method,
                'request_body'  => wp_json_encode( $safe_request ),
                'response_code' => $response_code,
                'response_body' => $response_body ? substr( wp_json_encode( $response_body ), 0, 65535 ) : null,
                'duration_ms'   => $duration_ms,
                'status'        => $status,
                'error_message' => $error_msg ? substr( $error_msg, 0, 500 ) : null,
                'created_at'    => LTMS_Utils::now_utc(),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Elimina datos sensibles del request antes de loguear.
     *
     * @param array $data Datos originales.
     * @return array Datos con campos sensibles redactados.
     */
    protected function redact_sensitive_data( array $data ): array {
        $sensitive = [
            'card_number', 'cvv', 'cvv2', 'expiry', 'pin', 'password', 'secret', 'private_key', 'api_key',
            'document', 'document_number', 'nit', 'rfc', 'curp', 'cedula', 'nuip',
        ];
        $redacted  = [];

        foreach ( $data as $key => $value ) {
            $is_sensitive = false;
            foreach ( $sensitive as $s ) {
                if ( str_contains( strtolower( (string) $key ), $s ) ) {
                    $is_sensitive = true;
                    break;
                }
            }
            if ( is_array( $value ) ) {
                $redacted[ $key ] = $this->redact_sensitive_data( $value );
            } else {
                $redacted[ $key ] = $is_sensitive ? '[REDACTED]' : $value;
            }
        }

        return $redacted;
    }

    /**
     * Extrae el mensaje de error de una respuesta de API.
     *
     * @param array $response    Respuesta decodificada.
     * @param int   $status_code Código HTTP.
     * @return string
     */
    protected function extract_error_message( array $response, int $status_code ): string {
        $possible_keys = [ 'message', 'error_message', 'error', 'description', 'detail', 'msg', 'errorMessage' ];

        foreach ( $possible_keys as $key ) {
            if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
                return $response[ $key ];
            }
        }

        return "HTTP Error {$status_code}";
    }

    /**
     * Obtiene el slug del proveedor.
     *
     * @return string
     */
    public function get_provider_slug(): string {
        return $this->provider_slug;
    }

    /**
     * Verifica la conectividad con la API.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array {
        $start = microtime( true );

        try {
            $this->perform_request( 'GET', '/health', [], [], false );
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

            return [ 'status' => 'ok', 'message' => 'Conectado', 'latency_ms' => $latency ];
        } catch ( \Throwable $e ) {
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }
}
