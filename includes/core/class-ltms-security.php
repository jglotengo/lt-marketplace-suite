<?php
/**
 * LTMS Core Security - Encriptación AES-256 y Sanitización
 *
 * Provee cifrado AES-256-CBC para datos sensibles (NIT, cuentas bancarias,
 * claves API), hashing seguro y utilidades de sanitización robustas.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Security
 */
final class LTMS_Core_Security {

    /**
     * Algoritmo de cifrado.
     * SEC-BUG-1 FIX: v2 uses aes-256-gcm (authenticated encryption, no padding oracle).
     * v1 (aes-256-cbc) kept for backward-compat decrypt of legacy data.
     */
    private const CIPHER_ALGO = 'aes-256-cbc';
    private const CIPHER_ALGO_GCM = 'aes-256-gcm';
    private const GCM_TAG_LENGTH = 16;

    /**
     * Longitud del IV para AES-256-CBC/GCM.
     */
    private const IV_LENGTH = 16;

    /**
     * Versión del esquema de cifrado.
     * v1 = legacy CBC (no auth), v2 = GCM (authenticated).
     */
    private const ENCRYPTION_VERSION = 'v1';
    private const ENCRYPTION_VERSION_GCM = 'v2';

    /**
     * Inicializa hooks de seguridad.
     *
     * @return void
     */
    public static function init(): void {
        // Forzar HTTPS en producción
        if ( LTMS_Core_Config::is_production() && ! is_ssl() ) {
            add_action( 'template_redirect', [ __CLASS__, 'force_https_redirect' ] );
        }

        // Eliminar headers que revelan versiones
        add_filter( 'the_generator', '__return_empty_string' );
        remove_action( 'wp_head', 'wp_generator' );

        // M-201: Agregar headers de seguridad HTTP en todas las respuestas
        add_action( 'send_headers', [ __CLASS__, 'send_security_headers' ] );
        // También en REST API
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'add_rest_security_headers' ], 10, 1 );
    }

    /**
     * Envía headers de seguridad HTTP en respuestas de página.
     * M-201: X-Content-Type-Options, X-Frame-Options, HSTS ausentes.
     *
     * @return void
     */
    public static function send_security_headers(): void {
        // Prevenir MIME sniffing.
        if ( ! headers_sent() ) {
            header( 'X-Content-Type-Options: nosniff' );
        }

        // Prevenir clickjacking. Usar CSP frame-ancestors en producción es preferible,
        // pero SAMEORIGIN es el mínimo aceptable.
        if ( ! headers_sent() ) {
            header( 'X-Frame-Options: SAMEORIGIN' );
        }

        // HSTS: sólo en HTTPS y producción para evitar romper entornos locales.
        // SEC-16 FIX (v2.9.26): añadido `preload` para permitir HSTS preload list.
        if ( is_ssl() && LTMS_Core_Config::is_production() && ! headers_sent() ) {
            header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );
        }

        // Ocultar versión de PHP si aún aparece.
        if ( ! headers_sent() ) {
            header_remove( 'X-Powered-By' );
        }

        // SEC-17 FIX (v2.9.26): X-XSS-Protection para navegadores legacy (IE/old Edge).
        // No reemplaza CSP pero añade capa de defensa para navegadores que no soportan CSP.
        if ( ! headers_sent() ) {
            header( 'X-XSS-Protection: 1; mode=block' );
        }

        // Referrer policy: no enviar referrer a terceros.
        if ( ! headers_sent() ) {
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        }

        // Permissions policy: deshabilitar APIs sensibles que el plugin no usa.
        if ( ! headers_sent() ) {
            header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
        }
    }

    /**
     * Agrega headers de seguridad a respuestas de la REST API.
     *
     * @param WP_REST_Response $response Respuesta REST.
     * @return WP_REST_Response
     */
    public static function add_rest_security_headers( WP_REST_Response $response ): WP_REST_Response {
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        $response->header( 'X-Frame-Options', 'SAMEORIGIN' );
        $response->header( 'Referrer-Policy', 'strict-origin-when-cross-origin' );
        return $response;
    }

    /**
     * Redirige a HTTPS si se accede por HTTP en producción.
     *
     * @return void
     */
    public static function force_https_redirect(): void {
        if ( ! is_ssl() ) {
            wp_safe_redirect( set_url_scheme( home_url( $_SERVER['REQUEST_URI'] ), 'https' ), 301 );
            exit;
        }
    }

    /**
     * Cifra un valor con AES-256-CBC.
     *
     * @param string $plaintext Texto plano a cifrar.
     * @return string Texto cifrado en Base64 con IV embebido.
     * @throws \RuntimeException Si OpenSSL no está disponible.
     */
    public static function encrypt( string $plaintext ): string {
        if ( empty( $plaintext ) ) {
            return '';
        }

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            throw new \RuntimeException( 'LTMS: La extensión OpenSSL es requerida para el cifrado.' );
        }

        $key = self::derive_key( LTMS_Core_Config::get_encryption_key() );
        $iv  = random_bytes( self::IV_LENGTH );

        // SEC-BUG-1 FIX: Use AES-256-GCM (authenticated encryption) if available.
        // GCM provides integrity + authenticity (no padding oracle attack).
        if ( in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
            $tag = ''; // filled by reference
            $encrypted = openssl_encrypt(
                $plaintext,
                self::CIPHER_ALGO_GCM,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::GCM_TAG_LENGTH
            );

            if ( $encrypted === false ) {
                throw new \RuntimeException( 'LTMS: Error al cifrar con OpenSSL GCM: ' . openssl_error_string() );
            }

            // Formato v2: version:base64(iv):base64(tag):base64(ciphertext)
            return self::ENCRYPTION_VERSION_GCM . ':' .
                   base64_encode( $iv ) . ':' .
                   base64_encode( $tag ) . ':' .
                   base64_encode( $encrypted );
        }

        // Fallback: legacy CBC (should not happen on modern PHP 7.2+)
        $encrypted = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ( $encrypted === false ) {
            throw new \RuntimeException( 'LTMS: Error al cifrar con OpenSSL: ' . openssl_error_string() );
        }

        return self::ENCRYPTION_VERSION . ':' .
               base64_encode( $iv ) . ':' .
               base64_encode( $encrypted );
    }

    /**
     * Descifra un valor cifrado con AES-256-CBC.
     *
     * @param string $ciphertext Texto cifrado (formato version:iv:cipher).
     * @return string Texto plano.
     * @throws \InvalidArgumentException Si el formato es inválido.
     * @throws \RuntimeException Si el descifrado falla.
     */
    public static function decrypt( string $ciphertext ): string {
        if ( empty( $ciphertext ) ) {
            return '';
        }

        $parts = explode( ':', $ciphertext, 4 );

        if ( count( $parts ) < 3 ) {
            throw new \InvalidArgumentException( 'LTMS: Formato de datos cifrados inválido.' );
        }

        $version = $parts[0];
        $key     = self::derive_key( LTMS_Core_Config::get_encryption_key() );

        // SEC-BUG-1 FIX: v2 = GCM (authenticated), v1 = legacy CBC (backward-compat)
        if ( $version === self::ENCRYPTION_VERSION_GCM ) {
            if ( count( $parts ) !== 4 ) {
                throw new \InvalidArgumentException( 'LTMS: Formato GCM inválido (esperados 4 partes).' );
            }
            [ $version, $iv_b64, $tag_b64, $cipher_b64 ] = $parts;

            $iv        = base64_decode( $iv_b64, true );
            $tag       = base64_decode( $tag_b64, true );
            $encrypted = base64_decode( $cipher_b64, true );

            if ( $iv === false || $tag === false || $encrypted === false ) {
                throw new \InvalidArgumentException( 'LTMS: Los datos cifrados están corruptos (Base64 inválido).' );
            }

            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_ALGO_GCM,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ( $decrypted === false ) {
                // SEC-BUG-1: GCM tag verification failed = tampered ciphertext
                throw new \RuntimeException( 'LTMS: Verificación de autenticidad fallida (datos manipulados).' );
            }

            return $decrypted;
        }

        // Legacy v1 (CBC) — backward-compat for data encrypted before SEC-BUG-1 fix
        if ( $version !== self::ENCRYPTION_VERSION ) {
            throw new \InvalidArgumentException( "LTMS: Versión de cifrado desconocida: {$version}" );
        }

        [ $version, $iv_b64, $cipher_b64 ] = $parts;

        $iv        = base64_decode( $iv_b64, true );
        $encrypted = base64_decode( $cipher_b64, true );

        if ( $iv === false || $encrypted === false ) {
            throw new \InvalidArgumentException( 'LTMS: Los datos cifrados están corruptos (Base64 inválido).' );
        }

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ( $decrypted === false ) {
            throw new \RuntimeException( 'LTMS: El descifrado falló. La clave puede ser incorrecta o los datos están corruptos.' );
        }

        return $decrypted;
    }

    /**
     * Genera un hash seguro usando SHA-256 con salt del sitio.
     *
     * @param string $value  Valor a hashear.
     * @param string $pepper Salt adicional (opcional).
     * @return string Hash hexadecimal de 64 caracteres.
     */
    public static function hash( string $value, string $pepper = '' ): string {
        // Prefer WordPress salts (unique per installation). All three are set by
        // wp-config.php; the explicit fallback only triggers during unit tests or
        // incomplete installations and is deliberately not a static string.
        if ( defined( 'SECURE_AUTH_SALT' ) && '' !== SECURE_AUTH_SALT ) {
            $salt = SECURE_AUTH_SALT;
        } elseif ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
            $salt = AUTH_SALT;
        } elseif ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            $salt = AUTH_KEY;
        } else {
            // Last-resort: derive something installation-specific rather than a
            // hard-coded constant.  Still weaker — operators MUST set WP salts.
            $salt = hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' )
                        . ( defined( 'DB_USER' ) ? DB_USER : '' )
                        . get_option( 'siteurl', 'ltms' ) );
        }
        return hash_hmac( 'sha256', $value . $pepper, $salt );
    }

    /**
     * Verifica si un hash coincide con un valor.
     *
     * @param string $value    Valor original.
     * @param string $hash     Hash a comparar.
     * @param string $pepper   Salt adicional.
     * @return bool
     */
    public static function verify_hash( string $value, string $hash, string $pepper = '' ): bool {
        return hash_equals( self::hash( $value, $pepper ), $hash );
    }

    /**
     * Genera un token criptográficamente seguro.
     *
     * @param int $length Longitud en bytes (el token tendrá longitud*2 en hex).
     * @return string Token hexadecimal.
     */
    public static function generate_token( int $length = 32 ): string {
        return bin2hex( random_bytes( $length ) );
    }

    /**
     * WH3 FIX (v2.8.9): Resuelve la IP del cliente de forma segura.
     *
     * Solo confía en X-Forwarded-For si el request viene de un proxy confiable
     * (configurable via ltms_trusted_proxies CSV). Antes, los webhook handlers
     * usaban X-Forwarded-For sin validar el proxy → spoofing de IP para
     * bypassear rate limits (cada IP spoofed tenía su propio counter).
     *
     * @return string IP del cliente (no spoofable si no hay proxy confiable).
     */
    public static function get_client_ip_safe(): string {
        $remote_addr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );

        // Solo confiar en X-Forwarded-For si REMOTE_ADDR es un proxy confiable.
        $trusted_proxies_str = trim( (string) get_option( 'ltms_trusted_proxies', '' ) );
        if ( $trusted_proxies_str !== '' ) {
            $trusted_proxies = array_filter( array_map( 'trim', explode( ',', $trusted_proxies_str ) ) );
            if ( in_array( $remote_addr, $trusted_proxies, true ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $forwarded = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
                if ( ! empty( $forwarded ) ) {
                    return end( $forwarded );
                }
            }
        }

        // Sin proxy confiable: REMOTE_ADDR no es spoofable.
        return $remote_addr;
    }

    /**
     * Genera un código de referido único (alfanumérico, 8 caracteres).
     *
     * @return string
     */
    public static function generate_referral_code(): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sin O,0,I,1 (confusos)
        $code  = '';
        $max   = strlen( $chars ) - 1;

        for ( $i = 0; $i < 8; $i++ ) {
            $code .= $chars[ random_int( 0, $max ) ];
        }

        return $code;
    }

    /**
     * Verifica la firma HMAC-SHA256 de un webhook.
     *
     * @param string $payload   Payload crudo recibido.
     * @param string $signature Firma recibida en el header.
     * @param string $secret    Secret del proveedor.
     * @param string $prefix    Prefijo de la firma (ej: 'sha256=').
     * @return bool
     */
    public static function verify_webhook_signature(
        string $payload,
        string $signature,
        string $secret,
        string $prefix = 'sha256='
    ): bool {
        if ( ! empty( $prefix ) ) {
            $signature = str_replace( $prefix, '', $signature );
        }

        $expected = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, strtolower( $signature ) );
    }

    /**
     * Sanitiza y valida una dirección de correo electrónico.
     *
     * @param string $email Email a sanitizar.
     * @return string Email sanitizado o cadena vacía si es inválido.
     */
    public static function sanitize_email( string $email ): string {
        $clean = sanitize_email( $email );
        return is_email( $clean ) ? $clean : '';
    }

    /**
     * Sanitiza un número de documento (NIT/Cédula/RFC).
     * Permite solo alfanuméricos, guiones y puntos.
     *
     * @param string $doc_number Número de documento.
     * @return string
     */
    public static function sanitize_document_number( string $doc_number ): string {
        return preg_replace( '/[^a-zA-Z0-9\-\.]/', '', trim( $doc_number ) );
    }

    /**
     * Sanitiza un número telefónico (solo dígitos y +).
     *
     * @param string $phone Teléfono.
     * @return string
     */
    public static function sanitize_phone( string $phone ): string {
        return preg_replace( '/[^0-9+\-\(\) ]/', '', trim( $phone ) );
    }

    /**
     * Verifica si el usuario actual tiene permisos para una acción.
     *
     * @param string $capability Capacidad requerida.
     * @return bool
     */
    public static function current_user_can( string $capability ): bool {
        return (bool) current_user_can( $capability );
    }

    /**
     * Deriva una clave AES de 256 bits desde la clave maestra usando PBKDF2.
     *
     * @param string $master_key Clave maestra.
     * @return string Clave derivada de 32 bytes.
     */
    private static function derive_key( string $master_key ): string {
        // Usar site_url como salt adicional para que la clave sea única por instalación
        // Use site_url as salt so the derived key is unique per installation.
        // 600,000 iterations aligns with NIST SP 800-132 (2024) recommendation for SHA-256.
        $site_salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : site_url();
        return hash_pbkdf2( 'sha256', $master_key, $site_salt, 600000, 32, true );
    }
}
