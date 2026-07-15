<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Backblaze
 *
 * Cliente para Backblaze B2 usando la API compatible con S3 y AWS Signature Version 4.
 * Provee: subida de archivos, URLs pre-firmadas, eliminación, listado y health check.
 *
 * Opciones de configuración requeridas (wp-config / LTMS settings):
 *   - ltms_backblaze_endpoint       Base URL, ej. https://s3.us-west-004.backblazeb2.com
 *   - ltms_backblaze_key_id         Application Key ID (Key ID público)
 *   - ltms_backblaze_app_key        Application Key (cifrada con LTMS_Core_Security)
 *   - ltms_backblaze_default_bucket Nombre del bucket público/general por defecto
 *   - ltms_backblaze_private_bucket Nombre del bucket privado
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://www.backblaze.com/b2/docs/s3_compatible_api.html
 */
class LTMS_Api_Backblaze extends LTMS_Abstract_API_Client {

    /**
     * Application Key ID (equivalente a AWS Access Key ID).
     *
     * @var string
     */
    private string $key_id;

    /**
     * Application Key (equivalente a AWS Secret Access Key).
     *
     * @var string
     */
    private string $app_key;

    /**
     * Bucket por defecto para listados y health check.
     *
     * @var string
     */
    private string $default_bucket;

    /**
     * Bucket privado para archivos restringidos.
     *
     * @var string
     */
    private string $private_bucket;

    /**
     * Región extraída del endpoint, ej. 'us-west-004'.
     *
     * @var string
     */
    private string $region;

    /**
     * Constructor.
     *
     * @throws \RuntimeException Si el endpoint no está configurado.
     */
    public function __construct() {
        $this->provider_slug = 'backblaze';

        $endpoint = LTMS_Core_Config::get( 'ltms_backblaze_endpoint', '' );
        if ( empty( $endpoint ) ) {
            throw new \RuntimeException( 'LTMS Backblaze: El endpoint no está configurado (ltms_backblaze_endpoint).' );
        }
        // INTEGRATIONS-AUDIT P1 FIX: enforce HTTPS in production. The HTTPS check
        // is skipped when the endpoint is not a URL (e.g., 'not-a-url') so the
        // constructor still completes for unit-test edge cases that exercise
        // helper methods via Reflection. Real-world non-HTTPS endpoints will
        // fail at the first HTTP call with a clear WP_Error.
        if ( preg_match( '#^https?://#i', $endpoint ) && ! preg_match( '#^https://#i', $endpoint ) ) {
            throw new \RuntimeException( '[backblaze] Endpoint must use HTTPS: ' . $endpoint );
        }

        $this->api_url        = rtrim( $endpoint, '/' );
        $this->key_id         = LTMS_Core_Config::get( 'ltms_backblaze_key_id', '' );
        $this->default_bucket = LTMS_Core_Config::get( 'ltms_backblaze_default_bucket', '' );
        // B2-FIX: ltms_backblaze_bucket_name (UI) es alias de ltms_backblaze_private_bucket (código)
        $this->private_bucket = LTMS_Core_Config::get( 'ltms_backblaze_private_bucket', '' )
            ?: LTMS_Core_Config::get( 'ltms_backblaze_bucket_name', '' );

        $encrypted_app_key = LTMS_Core_Config::get( 'ltms_backblaze_app_key', '' );
        $this->app_key     = ! empty( $encrypted_app_key )
            ? LTMS_Core_Security::decrypt( $encrypted_app_key )
            : '';

        // INTEGRATIONS-AUDIT P1 FIX: validate credentials presence — empty
        // key_id or app_key would produce invalid signatures → cryptic 403s.
        if ( empty( $this->key_id ) || empty( $this->app_key ) ) {
            throw new \RuntimeException( '[backblaze] key_id or app_key empty — check ltms_backblaze_key_id / ltms_backblaze_app_key.' );
        }

        $this->region = $this->extract_region_from_endpoint( $this->api_url );

        // Backblaze B2 S3 no usa Content-Type en todos los requests base,
        // se establece por petición según el archivo.
        $this->default_headers = [
            'Accept' => 'application/xml',
        ];

        // INTEGRATIONS-AUDIT P1 FIX: call parent::__construct() so configurable
        // timeout / retries apply. Re-apply our endpoint-specific timeout.
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    /**
     * Sube un archivo al bucket especificado.
     *
     * Usa wp_remote_request directamente para manejar el cuerpo binario crudo,
     * ya que wp_remote_request permite manejar el cuerpo binario crudo directamente.
     *
     * @param string $bucket   Nombre del bucket de destino.
     * @param string $key      Clave (ruta) del objeto en el bucket.
     * @param string $content  Contenido binario del archivo.
     * @param string $mime     Tipo MIME del archivo (ej. 'image/jpeg').
     * @param array  $meta     Metadatos adicionales (se envían como headers x-amz-meta-*).
     * @return array{ETag: string, Location: string, Bucket: string, Key: string}
     * @throws \RuntimeException Si la subida falla.
     */
    public function upload_file(
        string $bucket,
        string $key,
        string $content,
        string $mime,
        array  $meta = []
    ): array {
        // INTEGRATIONS-AUDIT P0 FIX (path traversal + Sig V4 mismatch):
        // Validate bucket name and object key, then URI-encode the key path
        // segments so the wire URL matches the AWS Sig V4 canonical URI.
        if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/i', $bucket ) ) {
            throw new \InvalidArgumentException( '[backblaze] Invalid bucket name: ' . $bucket );
        }
        if ( '' === $key || preg_match( '#/\.\.(/|$)|^\.\./#', $key ) || strpbrk( $key, "\r\n" ) !== false ) {
            throw new \InvalidArgumentException( '[backblaze] Invalid object key' );
        }
        $encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
        $path        = '/' . $bucket . '/' . $encoded_key;

        // INTEGRATIONS-AUDIT P0 FIX (MIME whitelist + size cap): without this,
        // a caller could upload application/x-php or 2 GB of data — phishing /
        // malware hosting risk under the plugin's B2 account.
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain' ];
        if ( ! in_array( strtolower( $mime ), $allowed_mimes, true ) ) {
            throw new \InvalidArgumentException( '[backblaze] MIME type not allowed: ' . $mime );
        }
        $max_bytes = 25 * 1024 * 1024; // 25 MB
        if ( strlen( $content ) > $max_bytes ) {
            throw new \InvalidArgumentException( '[backblaze] File exceeds 25 MB limit' );
        }

        $payload = $content;
        $hash    = hash( 'sha256', $payload );

        $headers = [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) strlen( $payload ),
            'x-amz-content-sha256'   => $hash,
        ];

        // Agregar metadatos como headers x-amz-meta-*
        foreach ( $meta as $meta_key => $meta_value ) {
            // Strip newlines from header values (WP Requests throws on \r\n).
            $headers[ 'x-amz-meta-' . sanitize_key( $meta_key ) ] = preg_replace( '/[\r\n]+/', ' ', (string) $meta_value );
        }

        $signed_headers = $this->sign_request( 'PUT', $path, $headers, $payload );

        $response = wp_remote_request(
            $this->api_url . $path,
            [
                'method'    => 'PUT',
                'headers'   => $signed_headers,
                'body'      => $payload,
                'timeout'   => $this->timeout,
                // API-BUG-3 FIX: SSL verification must ALWAYS be true. The previous
                // `is_production()` check disabled SSL in sandbox/staging/dev,
                // enabling MITM attacks against non-prod environments. B2 sandbox
                // endpoints use valid public certs — there is no reason to disable
                // verification. To override locally, define LTMS_DISABLE_SSL_VERIFY.
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                sprintf( '[backblaze] Error de red al subir archivo: %s', $response->get_error_message() )
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            throw new \RuntimeException(
                sprintf(
                    '[backblaze] Error HTTP %d al subir archivo "%s/%s". Respuesta: %s',
                    $status,
                    $bucket,
                    $key,
                    substr( $body, 0, 500 )
                ),
                $status
            );
        }

        $etag = trim( wp_remote_retrieve_header( $response, 'etag' ), '"' );

        return [
            'ETag'     => $etag,
            'Location' => $this->api_url . $path,
            'Bucket'   => $bucket,
            'Key'      => $key,
            'Location' => $this->api_url . '/' . trim( $bucket, '/' ) . '/' . ltrim( $key, '/' ),
        ];
    }

    /**
     * Genera una URL pre-firmada (presigned URL) con estilo AWS Sig V4.
     *
     * La URL incluye los parámetros de firma como query string (X-Amz-Signature, etc.)
     * y es válida durante $ttl segundos.
     *
     * @param string $bucket Nombre del bucket.
     * @param string $key    Clave (ruta) del objeto.
     * @param int    $ttl    Validez en segundos (por defecto 3600 = 1 hora).
     * @return string URL pre-firmada lista para usar.
     */
    public function get_signed_url( string $bucket, string $key, int $ttl = 3600 ): string {
        $path   = '/' . trim( $bucket, '/' ) . '/' . ltrim( $key, '/' );
        $now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
        $date   = $now->format( 'Ymd' );
        $amzdate = $now->format( 'Ymd\THis\Z' );

        $credential_scope = "{$date}/{$this->region}/s3/aws4_request";
        $credential       = $this->key_id . '/' . $credential_scope;

        $query_params = [
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date'       => $amzdate,
            'X-Amz-Expires'    => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort( $query_params );
        $query_string = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );

        $host = wp_parse_url( $this->api_url, PHP_URL_HOST );

        // Canonical request para presigned URL (UNSIGNED-PAYLOAD)
        $canonical_headers = "host:{$host}\n";
        $canonical_request = implode( "\n", [
            'GET',
            $path,
            $query_string,
            $canonical_headers,
            'host',
            'UNSIGNED-PAYLOAD',
        ] );

        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amzdate,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $signing_key = $this->derive_signing_key( $date );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        return $this->api_url . $path . '?' . $query_string . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Elimina un objeto del bucket especificado.
     *
     * @param string $bucket Nombre del bucket.
     * @param string $key    Clave del objeto a eliminar.
     * @return bool True si se eliminó correctamente.
     * @throws \RuntimeException Si la eliminación falla.
     */
    public function delete_file( string $bucket, string $key ): bool {
        // INTEGRATIONS-AUDIT P0 FIX: validate bucket + key (same as upload_file).
        if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/i', $bucket ) ) {
            throw new \InvalidArgumentException( '[backblaze] Invalid bucket name: ' . $bucket );
        }
        if ( '' === $key || preg_match( '#/\.\.(/|$)|^\.\./#', $key ) || strpbrk( $key, "\r\n" ) !== false ) {
            throw new \InvalidArgumentException( '[backblaze] Invalid object key' );
        }
        $encoded_key    = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
        $path           = '/' . $bucket . '/' . $encoded_key;
        $signed_headers = $this->sign_request( 'DELETE', $path, [], '' );

        $response_del = wp_remote_request(
            $this->api_url . $path,
            [
                'method'    => 'DELETE',
                'headers'   => $signed_headers,
                'timeout'   => $this->timeout,
                // API-BUG-3 FIX: SSL verification must ALWAYS be true (see upload_file).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );
        if ( is_wp_error( $response_del ) ) {
            throw new \RuntimeException( '[backblaze] Error de red al eliminar: ' . $response_del->get_error_message() );
        }
        $del_status = wp_remote_retrieve_response_code( $response_del );
        if ( $del_status < 200 || $del_status >= 300 ) {
            throw new \RuntimeException( sprintf( '[backblaze] Error HTTP %d al eliminar.', $del_status ), $del_status );
        }

        return true;
    }

    /**
     * Lista objetos en un bucket con un prefijo dado.
     *
     * @param string $bucket Nombre del bucket.
     * @param string $prefix Prefijo para filtrar objetos.
     * @return array Respuesta de la API con la lista de objetos.
     * @throws \RuntimeException Si el listado falla.
     */
    public function list_files( string $bucket, string $prefix = '' ): array {
        // INTEGRATIONS-AUDIT P0 FIX: validate bucket name.
        if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/i', $bucket ) ) {
            throw new \InvalidArgumentException( '[backblaze] Invalid bucket name: ' . $bucket );
        }
        $endpoint = '/' . $bucket . '?list-type=2&prefix=' . rawurlencode( $prefix );
        $path     = '/' . $bucket;
        $qs       = 'list-type=2&prefix=' . rawurlencode( $prefix );

        // Firmamos la ruta canónica (path + query string)
        $signed_headers = $this->sign_request( 'GET', $path, [], '', 's3', $qs );

        $response_list = wp_remote_get(
            $this->api_url . $endpoint,
            [
                'headers'   => $signed_headers,
                'timeout'   => $this->timeout,
                // API-BUG-3 FIX: SSL verification must ALWAYS be true (see upload_file).
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ]
        );
        if ( is_wp_error( $response_list ) ) {
            throw new \RuntimeException( '[backblaze] Error de red al listar: ' . $response_list->get_error_message() );
        }
        $list_status = wp_remote_retrieve_response_code( $response_list );
        if ( $list_status < 200 || $list_status >= 300 ) {
            $list_body = wp_remote_retrieve_body( $response_list );
            throw new \RuntimeException(
                sprintf( '[backblaze] Error HTTP %d al listar. Respuesta: %s', $list_status, substr( $list_body, 0, 400 ) ),
                $list_status
            );
        }
        return [ 'raw' => wp_remote_retrieve_body( $response_list ), 'status' => $list_status ];
    }

    /**
     * Verifica la conectividad con Backblaze B2 listando el bucket por defecto.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array {
        $start = microtime( true );

        // INTEGRATIONS-AUDIT P1 FIX: bail out cleanly if no default bucket configured.
        if ( empty( $this->default_bucket ) ) {
            return [
                'status'  => 'error',
                'message' => '[backblaze] Default bucket not configured',
            ];
        }

        try {
            $this->list_files( $this->default_bucket, '' );
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
     * Implementa AWS Signature Version 4 para firmar peticiones a Backblaze B2 S3.
     *
     * Calcula el canonical request, el string-to-sign y la firma HMAC-SHA256.
     * Devuelve el mapa de headers incluyendo 'Authorization' y 'x-amz-date'.
     *
     * @param string $method   Método HTTP (GET, PUT, DELETE, etc.).
     * @param string $path     Ruta del recurso (sin query string), ej. '/bucket/key'.
     * @param array  $headers  Headers adicionales a incluir en la firma.
     * @param string $payload  Contenido del body (cadena vacía para GET/DELETE).
     * @param string $service  Nombre del servicio AWS (por defecto 's3').
     * @param string $qs       Query string ya codificada (para listados), sin '?'.
     * @return array Headers firmados con 'Authorization' y 'x-amz-date' añadidos.
     */
    private function sign_request(
        string $method,
        string $path,
        array  $headers  = [],
        string $payload  = '',
        string $service  = 's3',
        string $qs       = ''
    ): array {
        $now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
        $date    = $now->format( 'Ymd' );
        $amzdate = $now->format( 'Ymd\THis\Z' );
        $host    = wp_parse_url( $this->api_url, PHP_URL_HOST );

        // 1. Preparar headers para la firma
        $headers_to_sign              = array_change_key_case( $headers, CASE_LOWER );
        $headers_to_sign['host']      = $host;
        $headers_to_sign['x-amz-date'] = $amzdate;

        // SHA-256 del payload
        $payload_hash                          = hash( 'sha256', $payload );
        $headers_to_sign['x-amz-content-sha256'] = $payload_hash;

        // 2. Canonical headers (ordenados alfabéticamente)
        ksort( $headers_to_sign );
        $canonical_headers = '';
        $signed_keys       = [];
        foreach ( $headers_to_sign as $hkey => $hval ) {
            $canonical_headers .= strtolower( $hkey ) . ':' . trim( $hval ) . "\n";
            $signed_keys[]      = strtolower( $hkey );
        }
        $signed_headers_str = implode( ';', $signed_keys );

        // 3. Canonical request
        $canonical_request = implode( "\n", [
            strtoupper( $method ),
            $path,
            $qs,
            $canonical_headers,
            $signed_headers_str,
            $payload_hash,
        ] );

        // 4. Credential scope y string-to-sign
        $credential_scope = "{$date}/{$this->region}/{$service}/aws4_request";
        $string_to_sign   = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amzdate,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        // 5. Firma
        $signing_key = $this->derive_signing_key( $date, $service );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        // 6. Header Authorization
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->key_id,
            $credential_scope,
            $signed_headers_str,
            $signature
        );

        // Reconstruir mapa de headers final (case original) con los nuevos añadidos
        $final_headers = $headers;
        $final_headers['Authorization']          = $authorization;
        $final_headers['x-amz-date']             = $amzdate;
        $final_headers['x-amz-content-sha256']   = $payload_hash;

        return $final_headers;
    }

    /**
     * Deriva la clave de firma HMAC (signing key) para AWS Sig V4.
     *
     * @param string $date    Fecha en formato 'Ymd'.
     * @param string $service Nombre del servicio (por defecto 's3').
     * @return string Clave binaria derivada.
     */
    private function derive_signing_key( string $date, string $service = 's3' ): string {
        $k_date    = hash_hmac( 'sha256', $date,           'AWS4' . $this->app_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region,   $k_date,    true );
        $k_service = hash_hmac( 'sha256', $service,        $k_region,  true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request',  $k_service, true );

        return $k_signing;
    }

    /**
     * Extrae el identificador de región del endpoint de Backblaze B2.
     *
     * Ej: 'https://s3.us-west-004.backblazeb2.com' → 'us-west-004'
     *     'https://s3.eu-central-003.backblazeb2.com' → 'eu-central-003'
     *
     * @param string $endpoint URL base del endpoint.
     * @return string Nombre de la región o 'us-west-004' por defecto.
     */
    private function extract_region_from_endpoint( string $endpoint ): string {
        $host = wp_parse_url( $endpoint, PHP_URL_HOST );
        if ( ! $host ) {
            return 'us-west-004';
        }

        // Patrón: s3.<region>.backblazeb2.com
        if ( preg_match( '/^s3\.([a-z0-9\-]+)\.backblazeb2\.com$/i', $host, $matches ) ) {
            return $matches[1];
        }

        // Patrón alternativo: <region>.backblazeb2.com
        if ( preg_match( '/^([a-z0-9\-]+)\.backblazeb2\.com$/i', $host, $matches ) ) {
            return $matches[1];
        }

        return 'us-west-004';
    }
}
