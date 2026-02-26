<?php
/**
 * LTMS API Factory - Fábrica de Clientes de API
 *
 * Centraliza la instanciación de todos los clientes de API externa.
 * Implementa el patrón Factory con caché de instancias para evitar
 * múltiples objetos del mismo proveedor por request.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/factories
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Factory
 */
final class LTMS_Api_Factory {

    /**
     * Instancias cacheadas por proveedor.
     *
     * @var array<string, LTMS_Abstract_API_Client>
     */
    private static array $instances = [];

    /**
     * Mapa de slug → clase del cliente.
     *
     * @var array<string, string>
     */
    private static array $client_map = [
        'openpay'   => 'LTMS_Api_Openpay',
        'siigo'     => 'LTMS_Api_Siigo',
        'addi'      => 'LTMS_Api_Addi',
        'aveonline' => 'LTMS_Api_Aveonline',
        'zapsign'   => 'LTMS_Api_Zapsign',
        'tptc'      => 'LTMS_Api_TPTC',
        'xcover'    => 'LTMS_Api_XCover',
        'backblaze' => 'LTMS_Api_Backblaze',
        'uber'      => 'LTMS_Api_Uber',
    ];

    /**
     * Obtiene un cliente de API por su slug.
     *
     * @param string $provider Slug del proveedor (ej: 'openpay', 'siigo').
     * @return LTMS_Abstract_API_Client
     * @throws \InvalidArgumentException Si el proveedor no existe.
     * @throws \RuntimeException Si la clase del cliente no está disponible.
     */
    public static function get( string $provider ): LTMS_Abstract_API_Client {
        $provider = strtolower( trim( $provider ) );

        // Retornar instancia cacheada
        if ( isset( self::$instances[ $provider ] ) ) {
            return self::$instances[ $provider ];
        }

        if ( ! isset( self::$client_map[ $provider ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'LTMS API Factory: Proveedor "%s" no registrado. Disponibles: %s',
                    $provider,
                    implode( ', ', array_keys( self::$client_map ) )
                )
            );
        }

        $class_name = self::$client_map[ $provider ];

        if ( ! class_exists( $class_name ) ) {
            throw new \RuntimeException(
                sprintf( 'LTMS API Factory: La clase "%s" para el proveedor "%s" no está disponible.',
                    $class_name, $provider
                )
            );
        }

        self::$instances[ $provider ] = new $class_name();
        return self::$instances[ $provider ];
    }

    /**
     * Registra un nuevo proveedor de API (permite extensibilidad).
     *
     * @param string $slug       Identificador único del proveedor.
     * @param string $class_name Nombre completo de la clase cliente.
     * @return void
     */
    public static function register( string $slug, string $class_name ): void {
        self::$client_map[ strtolower( $slug ) ] = $class_name;
    }

    /**
     * Invalida la instancia cacheada de un proveedor (útil en tests).
     *
     * @param string $provider Slug del proveedor.
     * @return void
     */
    public static function reset( string $provider ): void {
        unset( self::$instances[ $provider ] );
    }

    /**
     * Invalida todas las instancias cacheadas.
     *
     * @return void
     */
    public static function reset_all(): void {
        self::$instances = [];
    }

    /**
     * Prevenir instanciación.
     */
    private function __construct() {}
}
