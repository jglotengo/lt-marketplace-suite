<?php
/**
 * LTMS Singleton Trait
 *
 * Implementación segura del patrón Singleton para todas las
 * clases de servicio del plugin que requieran instancia única.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/traits
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait LTMS_Singleton
 */
trait LTMS_Singleton {

    /**
     * Instancias almacenadas por nombre de clase.
     *
     * @var array<string, static>
     */
    private static array $instances = [];

    /**
     * Obtiene la instancia única de la clase.
     *
     * @return static
     */
    public static function get_instance(): static {
        $class = static::class;
        if ( ! isset( self::$instances[ $class ] ) ) {
            self::$instances[ $class ] = new static();
        }
        return self::$instances[ $class ];
    }

    /**
     * Constructor privado para prevenir instanciación directa.
     */
    private function __construct() {}

    /**
     * Prevenir clonación.
     */
    private function __clone() {}

    /**
     * Prevenir deserialización.
     *
     * @throws \RuntimeException
     */
    public function __wakeup(): void {
        throw new \RuntimeException( 'No se puede deserializar un Singleton.' );
    }
}
