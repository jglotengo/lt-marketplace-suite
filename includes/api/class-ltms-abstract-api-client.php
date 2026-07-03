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
     * Número máximo de intentos en caso de error de red (v1.7.0: configurable).
     *
     * API-BUG-13 FIX: default bumped from 3 to 4 to allow 1 initial attempt +
     * 3 retries with exponential backoff (1s, 2s, 4s). With max_retries=3 the
     * old code only produced 2 retry sleeps (1s, 2s).
     *
     * @var int
     */
    protected int $max_retries = 4;

    /**
     * Delay en segundos entre reintentos (v1.7.0: configurable).
     *
     * @var int
     */
    protected int $retry_delay = 1;

    /**
     * Constructor base — M-115: faltaba, todas las subclases llaman parent::__construct()
     * pero no existia en la clase abstracta generando: Cannot call constructor.
     */
    public function __construct() {
        $this->init_configurable_settings();
    }

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
     * Returns the default headers for every request.
     *
     * API-BUG-1 + API-BUG-2 FIX: subclasses (XCover, TPTC) override this method
     * to inject Authorization / X-Api-Key headers. Before this fix, perform_request()
     * merged $this->default_headers (the property) directly — never invoking the
     * override — so every XCover / TPTC call went out unauthenticated and got 401.
     *
     * Subclasses calling parent::get_default_headers() now receive the base set
     * (Content-Type, Accept) and may append provider-specific auth headers.
     *
     * @return array<string, string>
     */
    protected function get_default_headers(): array {
        return $this->default_headers;
    }

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
    public function perform_request(
        string $method,
        string $endpoint,
        array  $data    = [],
        array  $headers = [],
        bool   $retry   = true
    ): array {
        // M-OPCACHE: if subclass defines get_api_base_url(), use it as fallback when api_url is empty.
        if ( empty( $this->api_url ) && method_exists( $this, 'get_api_base_url' ) ) {
            $this->api_url = $this->get_api_base_url();
        }
        $url     = rtrim( $this->api_url, '/' ) . '/' . ltrim( $endpoint, '/' );
        $method  = strtoupper( $method );

        // API-BUG-1 + API-BUG-2 FIX: invoke get_default_headers() (which subclasses
        // like XCover and TPTC override to add Authorization / X-Api-Key) instead of
        // reading the $default_headers property directly. Per-request $headers wins.
        $headers = array_merge( $this->get_default_headers(), $headers );

        // API-BUG-7 FIX: set a User-Agent identifying the plugin. WP's default UA
        // leaks the full site URL through `WordPress/x.y; https://example.com`; we
        // send a compact `LTMS/<version>; <home_url>` instead so providers can
        // identify us without us leaking extra metadata.
        if ( ! isset( $headers['User-Agent'] ) ) {
            $headers['User-Agent'] = 'LTMS/' . ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '0.0.0' ) . '; ' . home_url();
        }

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

        $start_time      = microtime( true );
        $attempts        = 0;
        $last_error      = null;
        $skip_next_sleep = false; // Set true after a 429 Retry-After sleep to avoid double-sleeping.

        do {
            $attempts++;

            if ( $attempts > 1 && ! $skip_next_sleep ) {
                // API-BUG-13 FIX: exponential backoff producing 1s, 2s, 4s for 3 retries
                // (requires max_retries=4 — set as the new default above).
                sleep( $this->retry_delay * ( 2 ** ( $attempts - 2 ) ) );
            }
            $skip_next_sleep = false;

            $response = wp_remote_request( $url, $args );
            $duration = (int) round( ( microtime( true ) - $start_time ) * 1000 );

            if ( is_wp_error( $response ) ) {
                // API-CRASH-2 FIX: classify the WP_Error by cURL error code so we can
                // fail fast on DNS / SSL errors (no retry) and only retry recoverable
                // classes (timeout, connection refused, empty response).
                $error_class = $this->classify_wp_error( $response );
                $last_error  = sprintf( '%s: %s', $error_class['label'], $response->get_error_message() );

                $this->log_api_call(
                    $method, $endpoint, $data,
                    null, null, $duration,
                    'error', $last_error, $attempts
                );

                $should_retry = $retry
                    && $attempts < $this->max_retries
                    && $error_class['retry'];

                if ( ! $should_retry ) {
                    // SSL errors warrant an admin alert — surface them at WARNING level.
                    if ( $error_class['type'] === 'ssl_error' && class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning(
                            'API_SSL_ERROR',
                            sprintf( '[%s] SSL connect error: %s', $this->provider_slug, $response->get_error_message() ),
                            [ 'provider' => $this->provider_slug, 'endpoint' => $endpoint ]
                        );
                    }
                    throw new \RuntimeException(
                        sprintf( '[%s] Error de red (%s): %s (intentos: %d)', $this->provider_slug, $error_class['label'], $response->get_error_message(), $attempts )
                    );
                }

                continue;
            }

            $status_code   = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );

            // API-BUG-8 FIX: detect non-JSON responses. Previously a 200 OK with
            // an HTML body (Cloudflare interstitial, nginx error page) was silently
            // coerced to [] via `json_decode(...) ?? []`, masking real failures.
            $decoded = json_decode( $response_body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                if ( $status_code >= 200 && $status_code < 300 ) {
                    // 2xx with non-JSON body = malformed response, treat as error.
                    $malformed_preview = substr( $response_body, 0, 500 );
                    $this->log_api_call(
                        $method, $endpoint, $data,
                        $status_code, null, $duration,
                        'error', 'Malformed response from API (non-JSON body): ' . $malformed_preview, $attempts
                    );
                    throw new \RuntimeException(
                        sprintf( '[%s] Malformed response from API (non-JSON body). HTTP %d. Preview: %s', $this->provider_slug, $status_code, $malformed_preview ),
                        $status_code
                    );
                }
                // Non-2xx with non-JSON body: keep $decoded as [] for error message extraction.
                $decoded = [];
            }

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

            // API-BUG-6 FIX: HTTP 429 rate-limit handling. Respect Retry-After
            // header (seconds or HTTP-date); fall back to exponential backoff if
            // the header is missing. Retry counts toward max_retries.
            if ( $status_code === 429 && $retry && $attempts < $this->max_retries ) {
                $retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
                $sleep_sec          = $this->parse_retry_after( $retry_after_header );
                if ( $sleep_sec === null ) {
                    // No Retry-After header — exponential backoff (1s, 2s, 4s).
                    $sleep_sec = $this->retry_delay * ( 2 ** ( $attempts - 1 ) );
                }
                // Clamp to [1, 60] seconds to avoid blocking a PHP worker too long.
                $sleep_sec = max( 1, min( 60, $sleep_sec ) );

                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'API_RATE_LIMITED',
                        sprintf( 'Rate limited by %s, retrying after %ds (attempt %d/%d)', $this->provider_slug, $sleep_sec, $attempts, $this->max_retries ),
                        [ 'provider' => $this->provider_slug, 'retry_after_header' => $retry_after_header, 'sleep_seconds' => $sleep_sec ]
                    );
                }

                sleep( $sleep_sec );
                $skip_next_sleep = true; // Already slept via Retry-After; skip the next backoff.
                $last_error      = sprintf( 'HTTP 429 rate limited (retried after %ds)', $sleep_sec );
                continue;
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

        // API-BUG-14 FIX: redact PII from the response body BEFORE persisting it to
        // lt_api_logs. Previously the response was logged verbatim (up to 65535 chars),
        // leaking customer emails, phone numbers, card fingerprints, bank accounts.
        // We reuse redact_sensitive_data() which has been extended to cover response-side
        // PII keys (email, phone, bank_account, card_number, cvv, etc.).
        $safe_response = null;
        if ( $response_body !== null ) {
            $safe_response = $this->redact_sensitive_data( $response_body );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'provider'      => $this->provider_slug,
                'endpoint'      => substr( $this->api_url . $endpoint, 0, 500 ),
                'method'        => $method,
                'request_body'  => wp_json_encode( $safe_request ),
                'response_code' => $response_code,
                'response_body' => $safe_response ? substr( wp_json_encode( $safe_response ), 0, 65535 ) : null,
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
        // API-BUG-14 FIX: extended the sensitive-keys list to cover response-side
        // PII (email, phone, bank_account) in addition to request-side secrets.
        // Matching is case-insensitive substring via strtolower(); adding 'email'
        // catches customer_email, billing_email, holder.email, etc.
        $sensitive = [
            // Request-side secrets
            'card_number', 'cvv', 'cvv2', 'expiry', 'pin', 'password', 'secret', 'private_key', 'api_key',
            'access_token', 'refresh_token', 'authorization',
            // Fiscal / document identifiers (request + response)
            'document', 'document_number', 'nit', 'rfc', 'curp', 'cedula', 'nuip',
            // API-BUG-14: response-side PII
            'email', 'phone', 'bank_account', 'account_number', 'iban', 'swift', 'clabe',
            'card_fingerprint', 'card_last4', 'fingerprint',
        ];
        $redacted  = [];

        foreach ( $data as $key => $value ) {
            $is_sensitive = false;
            $key_lower    = strtolower( (string) $key );
            foreach ( $sensitive as $s ) {
                if ( str_contains( $key_lower, $s ) ) {
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
        $possible_keys = [ 'message', 'error_message', 'error', 'description', 'detail', 'msg', 'errorMessage', 'non_field_errors' ];

        foreach ( $possible_keys as $key ) {
            if ( isset( $response[ $key ] ) ) {
                if ( is_string( $response[ $key ] ) ) {
                    return $response[ $key ];
                }
                if ( is_array( $response[ $key ] ) ) {
                    return implode( ' | ', $response[ $key ] );
                }
            }
        }

        // Si el body tiene contenido significativo, incluirlo en el mensaje de error
        // para facilitar el diagnóstico (especialmente en HTTP 400 de APIs como ZapSign)
        if ( ! empty( $response ) ) {
            $raw = wp_json_encode( $response );
            if ( $raw && strlen( $raw ) < 500 ) {
                return "HTTP Error {$status_code}: {$raw}";
            }
        }

        return "HTTP Error {$status_code}";
    }

    /**
     * Classifies a WP_Error (network-level failure) by cURL error code.
     *
     * API-CRASH-2 FIX: distinguishes recoverable errors (timeout, connection
     * refused, empty response) from fail-fast errors (DNS, SSL). The returned
     * array contains:
     *   - 'type':  machine-readable class (timeout|dns_error|connection_refused|
     *              ssl_error|empty_response|connection_reset|network_error)
     *   - 'label': human-readable label for logs
     *   - 'retry': whether perform_request() should retry this class
     *
     * @param \WP_Error $error
     * @return array{type: string, label: string, retry: bool}
     */
    protected function classify_wp_error( \WP_Error $error ): array {
        $code = strtolower( (string) $error->get_error_code() );
        $msg  = $error->get_error_message();

        // Map of cURL error code names → classification. WP's HTTP API surfaces
        // these as the WP_Error code (e.g. 'curl_error_28' for CURLE_OPERATION_TIMEDOUT).
        $code_map = [
            'curl_error_28' => [ 'type' => 'timeout',            'label' => 'Timeout',              'retry' => true  ],
            'curl_error_6'  => [ 'type' => 'dns_error',          'label' => 'DNS Error',            'retry' => false ],
            'curl_error_7'  => [ 'type' => 'connection_refused', 'label' => 'Connection Refused',   'retry' => true  ],
            'curl_error_35' => [ 'type' => 'ssl_error',          'label' => 'SSL Connect Error',    'retry' => false ],
            'curl_error_52' => [ 'type' => 'empty_response',     'label' => 'Empty Response',       'retry' => true  ],
            'curl_error_56' => [ 'type' => 'connection_reset',   'label' => 'Connection Reset',     'retry' => true  ],
        ];

        if ( isset( $code_map[ $code ] ) ) {
            return $code_map[ $code ];
        }

        // Fallback: try to extract a cURL error number from the message text.
        if ( preg_match( '/curl error (\d+)/i', $msg, $m ) ) {
            $num_map = [
                28 => [ 'type' => 'timeout',            'label' => 'Timeout',              'retry' => true  ],
                6  => [ 'type' => 'dns_error',          'label' => 'DNS Error',            'retry' => false ],
                7  => [ 'type' => 'connection_refused', 'label' => 'Connection Refused',   'retry' => true  ],
                35 => [ 'type' => 'ssl_error',          'label' => 'SSL Connect Error',    'retry' => false ],
                52 => [ 'type' => 'empty_response',     'label' => 'Empty Response',       'retry' => true  ],
                56 => [ 'type' => 'connection_reset',   'label' => 'Connection Reset',     'retry' => true  ],
            ];
            $num = (int) $m[1];
            if ( isset( $num_map[ $num ] ) ) {
                return $num_map[ $num ];
            }
        }

        // Generic network error: retry (better to attempt again than fail spuriously).
        return [ 'type' => 'network_error', 'label' => 'Network Error', 'retry' => true ];
    }

    /**
     * Classifies an HTTP status code for retry decisions and logging.
     *
     * API-CRASH-2 FIX: provides a machine-readable error class for HTTP responses
     * (server_error|auth_error|client_error|rate_limited|unknown).
     *
     * @param int $status
     * @return string
     */
    protected function classify_http_status( int $status ): string {
        if ( $status === 429 ) return 'rate_limited';
        if ( in_array( $status, [ 401, 403 ], true ) ) return 'auth_error';
        if ( in_array( $status, [ 400, 404, 422 ], true ) ) return 'client_error';
        if ( in_array( $status, [ 502, 503, 504 ], true ) ) return 'server_error';
        if ( $status >= 500 ) return 'server_error';
        if ( $status >= 400 ) return 'client_error';
        return 'unknown';
    }

    /**
     * Parses a Retry-After header value into seconds.
     *
     * API-BUG-6 FIX: Retry-After may be either a delta-seconds integer (e.g. "30")
     * or an HTTP-date (e.g. "Wed, 21 Oct 2015 07:28:00 GMT"). Returns null when
     * the value cannot be parsed or is missing.
     *
     * @param string $header Raw header value.
     * @return int|null Seconds to wait, or null if unparseable.
     */
    protected function parse_retry_after( string $header ): ?int {
        $header = trim( $header );
        if ( $header === '' ) {
            return null;
        }

        // Form 1: delta-seconds (integer).
        if ( ctype_digit( $header ) ) {
            return (int) $header;
        }

        // Form 2: HTTP-date.
        $ts = strtotime( $header );
        if ( $ts !== false ) {
            $delta = $ts - time();
            return $delta > 0 ? $delta : 1;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // API-CRASH-1: Transaction Journal for Crash Recovery
    // -------------------------------------------------------------------------

    /**
     * Writes a 'pending' record to the lt_api_journal table BEFORE a critical
     * API call (payment, payout, refund, etc.).
     *
     * API-CRASH-1 FIX: if the PHP process crashes (timeout, fatal error, OOM)
     * during the API call, the record stays 'pending'. The recovery cron
     * `ltms_recover_pending_api_calls` (every 5 min) finds records pending
     * > 10 minutes, queries the provider to check if the operation was
     * processed, and reconciles the local state.
     *
     * H-3 FIX: the journal table is created lazily on the first call to this
     * method via CREATE TABLE IF NOT EXISTS (see below). If creation fails
     * (e.g. DB permissions), this method gracefully returns null so callers
     * can still operate without journaling.
     *
     * @param string $operation Operation name (e.g. 'payment', 'payout', 'refund').
     * @param string $entity_id Local entity ID (e.g. order_id, payout_id).
     * @param array  $payload   Request payload — only the SHA-256 hash is stored.
     * @return int|null Journal record ID, or null if journaling is unavailable.
     */
    protected function journal_begin( string $operation, string $entity_id, array $payload = [] ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_api_journal';

        // H-3 FIX: ensure the journal table exists before any read/write.
        // Previously the table was only documented as "created by the
        // activator (out of scope)" — but no activator ever created it,
        // so journal_begin() ALWAYS hit the SHOW TABLES short-circuit
        // below and returned null. That silently disabled crash recovery
        // for every API call (payments, payouts, refunds). We create the
        // table lazily here with IF NOT EXISTS so the very first
        // journal_begin() call after plugin activation bootstraps it,
        // without needing a separate migration step.
        $charset_collate = $wpdb->get_charset_collate();
        // H-3 FIX: schema now matches the columns referenced by journal_begin()
        // (operation, entity_id, payload_hash), journal_complete()
        // (response_hash) and journal_fail() (error_message). Previously the
        // CREATE TABLE omitted all five, so every INSERT/UPDATE silently
        // failed → $wpdb->insert_id was 0 → journal_begin() returned null →
        // crash recovery was disabled for every API call.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_id`     VARCHAR(64)  NULL,
                `provider`       VARCHAR(50)  NOT NULL,
                `operation`      VARCHAR(100) NULL,
                `entity_id`      BIGINT UNSIGNED NULL,
                `method`         VARCHAR(10)  NULL,
                `url`            TEXT         NULL,
                `payload`        LONGTEXT     NULL,
                `payload_hash`   VARCHAR(64)  NULL,
                `response_hash`  VARCHAR(64)  NULL,
                `status`         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
                `created_at`     DATETIME     NOT NULL,
                `completed_at`   DATETIME     NULL,
                `error`          TEXT         NULL,
                `error_message`  TEXT         NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `request_id` (`request_id`),
                KEY `idx_status_created` (`status`, `created_at`),
                KEY `idx_entity` (`entity_id`)
            ) {$charset_collate};"
        );

        // H-3 FIX: defensive migration for installs where the previous
        // (incomplete) CREATE TABLE already ran. IF NOT EXISTS is a no-op on
        // existing tables, so without this block those installs would never
        // gain the new columns and journaling would stay silently broken.
        // Column names are hard-coded literals (not user input), so direct
        // interpolation is safe here. The static flag ensures we only attempt
        // the migration once per request — SHOW COLUMNS × 5 on every
        // journal_begin() call would be wasteful for a critical-path method
        // (called for every payment/payout/refund).
        static $h3_migrated = false;
        if ( ! $h3_migrated ) {
            foreach ( [
                'operation'     => 'ADD COLUMN `operation` VARCHAR(100) NULL',
                'entity_id'     => 'ADD COLUMN `entity_id` BIGINT UNSIGNED NULL',
                'payload_hash'  => 'ADD COLUMN `payload_hash` VARCHAR(64) NULL',
                'response_hash' => 'ADD COLUMN `response_hash` VARCHAR(64) NULL',
                'error_message' => 'ADD COLUMN `error_message` TEXT NULL',
            ] as $col => $ddl ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $exists_col = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'" );
                if ( ! $exists_col ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query( "ALTER TABLE `{$table}` {$ddl}" );
                }
            }
            $h3_migrated = true;
        }

        // Gracefully degrade if the journal table is still not available
        // (e.g. CREATE TABLE failed due to DB permissions). This check is
        // now mostly redundant but kept as a defensive safety net.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return null;
        }

        $payload_hash = hash( 'sha256', wp_json_encode( $payload ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'provider'     => $this->provider_slug,
                'operation'    => substr( $operation, 0, 64 ),
                'entity_id'    => substr( (string) $entity_id, 0, 100 ),
                'payload_hash' => $payload_hash,
                'status'       => 'pending',
                'created_at'   => function_exists( 'LTMS_Utils::now_utc' ) ? LTMS_Utils::now_utc() : current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $wpdb->insert_id ? (int) $wpdb->insert_id : null;
    }

    /**
     * Marks a journal entry as 'completed' after a successful API call.
     *
     * @param int   $journal_id Journal record ID from journal_begin().
     * @param array $response   Response data — only the SHA-256 hash is stored.
     * @return void
     */
    protected function journal_complete( int $journal_id, array $response = [] ): void {
        if ( $journal_id <= 0 ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_api_journal';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $response_hash = hash( 'sha256', wp_json_encode( $response ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'        => 'completed',
                'response_hash' => $response_hash,
                'completed_at'  => function_exists( 'LTMS_Utils::now_utc' ) ? LTMS_Utils::now_utc() : current_time( 'mysql', true ),
            ],
            [ 'id' => $journal_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Marks a journal entry as 'failed' after an unsuccessful API call.
     *
     * @param int    $journal_id Journal record ID from journal_begin().
     * @param string $error      Error message (truncated to 500 chars).
     * @return void
     */
    protected function journal_fail( int $journal_id, string $error = '' ): void {
        if ( $journal_id <= 0 ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_api_journal';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'        => 'failed',
                'error_message' => substr( $error, 0, 500 ),
                'completed_at'  => function_exists( 'LTMS_Utils::now_utc' ) ? LTMS_Utils::now_utc() : current_time( 'mysql', true ),
            ],
            [ 'id' => $journal_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
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
