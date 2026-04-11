<?php
/**
 * LTMS Core Cache Manager
 *
 * Capa de abstracción sobre el Object Cache de WordPress.
 * Agrupa claves bajo prefijos por dominio para facilitar
 * la invalidación selectiva (flush por grupo).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Cache_Manager
 */
final class LTMS_Core_Cache_Manager {

    /** Tiempo de vida por defecto: 1 hora */
    const DEFAULT_TTL = 3600;

    /** Grupo base del object cache */
    const CACHE_GROUP = 'ltms';

    /**
     * Inicializa el sistema de caché.
     *
     * @return void
     */
    public static function init(): void {
        // Nada que registrar; la clase actúa como helper estático.
    }

    /**
     * Obtiene un valor de la caché.
     *
     * @param string $key   Clave.
     * @param string $group Sub-grupo (ej: 'wallet', 'config').
     * @return mixed|false Valor cacheado o false si no existe.
     */
    public static function get( string $key, string $group = '' ) {
        return wp_cache_get( self::build_key( $key, $group ), self::CACHE_GROUP );
    }

    /**
     * Guarda un valor en la caché.
     *
     * @param string $key   Clave.
     * @param mixed  $value Valor.
     * @param string $group Sub-grupo.
     * @param int    $ttl   Segundos de vida (0 = indefinido).
     * @return bool
     */
    public static function set( string $key, $value, string $group = '', int $ttl = self::DEFAULT_TTL ): bool {
        return wp_cache_set( self::build_key( $key, $group ), $value, self::CACHE_GROUP, $ttl );
    }

    /**
     * Elimina una clave de la caché.
     *
     * @param string $key   Clave.
     * @param string $group Sub-grupo.
     * @return bool
     */
    public static function delete( string $key, string $group = '' ): bool {
        return wp_cache_delete( self::build_key( $key, $group ), self::CACHE_GROUP );
    }

    /**
     * Obtiene o genera un valor con callback (cache-aside).
     *
     * @param string   $key      Clave.
     * @param callable $callback Función que genera el valor si no está en caché.
     * @param string   $group    Sub-grupo.
     * @param int      $ttl      TTL en segundos.
     * @return mixed
     */
    public static function remember( string $key, callable $callback, string $group = '', int $ttl = self::DEFAULT_TTL ) {
        $cached = self::get( $key, $group );
        if ( false !== $cached ) {
            return $cached;
        }
        $value = $callback();
        self::set( $key, $value, $group, $ttl );
        return $value;
    }

    /**
     * Invalida todas las claves de un sub-grupo incrementando su versión.
     *
     * @param string $group Sub-grupo a limpiar.
     * @return void
     */
    public static function flush_group( string $group ): void {
        $version_key = self::CACHE_GROUP . ':version:' . $group;
        $version     = (int) wp_cache_get( $version_key, self::CACHE_GROUP );
        wp_cache_set( $version_key, $version + 1, self::CACHE_GROUP, 0 );
    }

    /**
     * Construye la clave completa con versión de grupo.
     *
     * @param string $key   Clave base.
     * @param string $group Sub-grupo.
     * @return string
     */
    private static function build_key( string $key, string $group ): string {
        if ( empty( $group ) ) {
            return sanitize_key( $key );
        }
        $version_key = self::CACHE_GROUP . ':version:' . $group;
        $version     = (int) wp_cache_get( $version_key, self::CACHE_GROUP );
        return sanitize_key( $group . ':v' . $version . ':' . $key );
    }

    /** Prevenir instanciación */
    private function __construct() {}
}
