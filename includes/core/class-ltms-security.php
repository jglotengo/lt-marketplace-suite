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
     */
    private const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * Longitud del IV para AES-256-CBC.
     */
    private const IV_LENGTH = 16;

    /**
     * Versión del esquema de cifrado (para migración futura).
     */
    private const ENCRYPTION_VERSION = 'v1';

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

        // Formato: version:base64(iv):base64(ciphertext)
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

        $parts = explode( ':', $ciphertext, 3 );

        if ( count( $parts ) !== 3 ) {
            throw new \InvalidArgumentException( 'LTMS: Formato de datos cifrados inválido.' );
        }

        [ $version, $iv_b64, $cipher_b64 ] = $parts;

        if ( $version !== self::ENCRYPTION_VERSION ) {
            throw new \InvalidArgumentException( "LTMS: Versión de cifrado desconocida: {$version}" );
        }

        $key       = self::derive_key( LTMS_Core_Config::get_encryption_key() );
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
        $salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'ltms-fallback-salt-2025';
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
        $site_salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : site_url();
        return hash_pbkdf2( 'sha256', $master_key, $site_salt, 10000, 32, true );
    }
}
