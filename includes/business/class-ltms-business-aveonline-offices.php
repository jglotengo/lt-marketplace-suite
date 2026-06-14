<?php
/**
 * Gestión de oficinas y puntos de atención de transportadoras Aveonline.
 *
 * Wrappea el endpoint GET /api-oficinas/public/api/v1/offices/{carrier}/{cityId}
 * con caché transitoria de 6 horas por par (carrier, cityId).
 *
 * @package LTMS
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Aveonline_Offices
 */
class LTMS_Business_Aveonline_Offices {

    /**
     * TTL de caché en segundos (6 horas).
     */
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Prefijo de clave de caché.
     */
    private const CACHE_PREFIX = 'ltms_aveonline_offices_';

    /**
     * Transportadoras disponibles según doc oficial Aveonline.
     * carrier_code => nombre legible.
     */
    public const CARRIERS = [
        1016 => 'Interrápidísimo',
        1010 => 'TCC',
        1009 => 'Coordinadora',
        29   => 'Envía',
        33   => 'Servientrega',
    ];

    /**
     * Retorna las oficinas de una transportadora en la ciudad indicada.
     *
     * Usa caché transitoria de 6 horas para evitar llamadas repetidas.
     * El city_id debe ser el código DANE de 9 dígitos, tal como lo almacena
     * la tabla lt_aveonline_cities en la columna `codigodane`.
     *
     * @param int|string $carrier Código de transportadora.
     * @param string     $city_id Código DANE de 9 dígitos (ej: 11001000).
     * @return array Lista de oficinas. Cada elemento:
     *               [ 'location' => string, 'name' => string, 'id' => string, 'city' => string ]
     */
    public static function get_offices( $carrier, string $city_id ): array {
        $cache_key = self::CACHE_PREFIX . (int) $carrier . '_' . sanitize_key( $city_id );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $api      = new LTMS_Api_Aveonline();
        $response = $api->get_carrier_offices( $carrier, $city_id );

        $offices = [];
        if ( isset( $response['status'] ) && 'success' === $response['status'] && is_array( $response['data'] ) ) {
            $offices = $response['data'];
        }

        set_transient( $cache_key, $offices, self::CACHE_TTL );

        return $offices;
    }

    /**
     * Invalida la caché de oficinas de una ciudad concreta para todas las transportadoras.
     *
     * Útil cuando se actualiza el catálogo de ciudades.
     *
     * @param string $city_id Código DANE de 9 dígitos.
     * @return void
     */
    public static function invalidate_city_cache( string $city_id ): void {
        foreach ( array_keys( self::CARRIERS ) as $carrier ) {
            $cache_key = self::CACHE_PREFIX . $carrier . '_' . sanitize_key( $city_id );
            delete_transient( $cache_key );
        }
    }

    /**
     * Retorna las oficinas formateadas como opciones para un <select>.
     *
     * @param int|string $carrier Código de transportadora.
     * @param string     $city_id Código DANE de 9 dígitos.
     * @return array [ value => label ] donde value = 'name||location' y label = 'Nombre — Dirección'.
     */
    public static function get_select_options( $carrier, string $city_id ): array {
        $offices = self::get_offices( $carrier, $city_id );
        $options = [];

        foreach ( $offices as $office ) {
            $name     = $office['name']     ?? '';
            $location = $office['location'] ?? '';
            if ( '' === $name && '' === $location ) {
                continue;
            }
            $value            = esc_attr( $name . '||' . $location );
            $label            = $name . ( $location ? ' — ' . $location : '' );
            $options[ $value ] = $label;
        }

        return $options;
    }

    /**
     * Retorna el nombre legible de una transportadora dado su código.
     *
     * @param int|string $carrier Código de transportadora.
     * @return string Nombre o el código como string si no se encuentra.
     */
    public static function carrier_name( $carrier ): string {
        return self::CARRIERS[ (int) $carrier ] ?? (string) $carrier;
    }

    /**
     * Retorna true si el código de transportadora es válido según la doc oficial.
     *
     * @param int|string $carrier Código de transportadora.
     * @return bool
     */
    public static function is_valid_carrier( $carrier ): bool {
        return array_key_exists( (int) $carrier, self::CARRIERS );
    }
}
