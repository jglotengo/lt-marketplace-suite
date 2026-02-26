<?php
/**
 * LTMS Core Config - Gestor de Entorno y Configuración
 *
 * Abstrae la lectura de opciones de WordPress y constantes de wp-config.php
 * en una interfaz unificada con soporte multi-país (CO/MX).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Config
 */
final class LTMS_Core_Config {

    /**
     * Cache interno para evitar lecturas repetidas de la BD.
     *
     * @var array<string, mixed>
     */
    private static array $cache = [];

    /**
     * Opciones de settings cargadas del grupo 'ltms_settings'.
     *
     * @var array<string, mixed>
     */
    private static array $settings = [];

    /**
     * Indica si el grupo de opciones ya fue cargado.
     *
     * @var bool
     */
    private static bool $settings_loaded = false;

    /**
     * Inicializa la clase cargando el grupo de opciones.
     *
     * @return void
     */
    public static function init(): void {
        self::load_settings();
    }

    /**
     * Carga las opciones del grupo 'ltms_settings' de la BD.
     *
     * @return void
     */
    private static function load_settings(): void {
        if ( self::$settings_loaded ) {
            return;
        }
        $raw = get_option( 'ltms_settings', [] );
        self::$settings = is_array( $raw ) ? $raw : [];
        self::$settings_loaded = true;
    }

    /**
     * Obtiene un valor de configuración.
     * Prioridad: Constante wp-config.php → Opción BD → Valor por defecto.
     *
     * @param string $key     Clave de configuración (ej: 'ltms_openpay_key').
     * @param mixed  $default Valor por defecto si no se encuentra.
     * @return mixed
     */
    public static function get( string $key, mixed $default = null ): mixed {
        // 1. Cache local
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        // 2. Constante en wp-config.php (mayor prioridad)
        $const_name = strtoupper( $key );
        if ( defined( $const_name ) ) {
            $value = constant( $const_name );
            self::$cache[ $key ] = $value;
            return $value;
        }

        // 3. Grupo de opciones en BD
        self::load_settings();
        if ( isset( self::$settings[ $key ] ) ) {
            $value = self::$settings[ $key ];
            self::$cache[ $key ] = $value;
            return $value;
        }

        // 4. Opción individual en BD
        $value = get_option( $key, null );
        if ( $value !== null ) {
            self::$cache[ $key ] = $value;
            return $value;
        }

        return $default;
    }

    /**
     * Establece un valor en el grupo de opciones de la BD.
     *
     * @param string $key   Clave.
     * @param mixed  $value Valor.
     * @return bool
     */
    public static function set( string $key, mixed $value ): bool {
        self::load_settings();
        self::$settings[ $key ] = $value;
        self::$cache[ $key ]    = $value;
        return update_option( 'ltms_settings', self::$settings, true );
    }

    /**
     * Obtiene el código de país de operación.
     *
     * @return string 'CO' o 'MX'
     */
    public static function get_country(): string {
        $country = self::get( 'LTMS_COUNTRY', defined( 'LTMS_COUNTRY' ) ? LTMS_COUNTRY : 'CO' );
        return in_array( strtoupper( $country ), [ 'CO', 'MX' ], true )
            ? strtoupper( $country )
            : 'CO';
    }

    /**
     * Alias de get_country para compatibilidad con código existente.
     *
     * @return string 'CO' o 'MX'
     */
    public static function get_context_country(): string {
        return self::get_country();
    }

    /**
     * Verifica si el entorno es producción.
     *
     * @return bool
     */
    public static function is_production(): bool {
        $env = self::get( 'LTMS_ENVIRONMENT', LTMS_ENVIRONMENT );
        return $env === 'production';
    }

    /**
     * Verifica si el entorno es desarrollo/staging.
     *
     * @return bool
     */
    public static function is_development(): bool {
        return ! self::is_production();
    }

    /**
     * Obtiene la moneda base según el país.
     *
     * @return string ISO 4217 (COP o MXN)
     */
    public static function get_currency(): string {
        return self::get_country() === 'MX' ? 'MXN' : 'COP';
    }

    /**
     * Obtiene la llave de cifrado AES-256 del entorno.
     * Nunca almacenarla en la BD, siempre desde wp-config.php.
     *
     * @return string
     * @throws \RuntimeException Si la clave no está definida.
     */
    public static function get_encryption_key(): string {
        if ( defined( 'LTMS_ENCRYPTION_KEY' ) && ! empty( LTMS_ENCRYPTION_KEY ) ) {
            return LTMS_ENCRYPTION_KEY;
        }
        // Fallback: usar AUTH_KEY de WordPress (menos seguro, pero funcional)
        if ( defined( 'AUTH_KEY' ) && strlen( AUTH_KEY ) >= 32 ) {
            return substr( AUTH_KEY, 0, 32 );
        }
        throw new \RuntimeException(
            'LTMS: La constante LTMS_ENCRYPTION_KEY no está definida en wp-config.php. ' .
            'Ejecuta bin/generate-secrets.sh para generarla.'
        );
    }

    /**
     * Invalida el cache interno (útil después de guardar settings).
     *
     * @return void
     */
    public static function flush_cache(): void {
        self::$cache           = [];
        self::$settings        = [];
        self::$settings_loaded = false;
    }

    /**
     * Obtiene todas las configuraciones cargadas (sin datos sensibles).
     *
     * @return array<string, mixed>
     */
    public static function get_all_safe(): array {
        self::load_settings();
        $sensitive_keys = [ 'api_key', 'secret', 'password', 'token', 'private', 'encryption_key' ];
        $safe           = [];

        foreach ( self::$settings as $key => $value ) {
            $is_sensitive = false;
            foreach ( $sensitive_keys as $sensitive ) {
                if ( str_contains( strtolower( $key ), $sensitive ) ) {
                    $is_sensitive = true;
                    break;
                }
            }
            $safe[ $key ] = $is_sensitive ? '***REDACTED***' : $value;
        }

        return $safe;
    }
}
