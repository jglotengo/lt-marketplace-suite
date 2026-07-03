<?php
/**
 * Gestión de oficinas y puntos de atención de transportadoras Aveonline.
 *
 * Wrappea el endpoint GET /api-oficinas/public/api/v1/offices/all (con JWT)
 * con caché transitoria de 6 horas por combinación de parámetros.
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
     * Retorna las transportadoras con soporte de oficinas.
     * Delega a LTMS_Business_Aveonline_Carriers para el catalogo dinamico.
     * Mantiene compatibilidad con el formato anterior [ id => ['slug','label'] ].
     *
     * @return array [ carrier_code => [ 'slug' => string, 'label' => string ] ]
     */
    public static function carriers(): array {
        if ( class_exists( 'LTMS_Business_Aveonline_Carriers' ) ) {
            $result = [];
            foreach ( LTMS_Business_Aveonline_Carriers::with_offices() as $id => $c ) {
                $result[ $id ] = [ 'slug' => $c['slug'], 'label' => $c['label'] ];
            }
            return $result;
        }
        // Fallback hardcodeado si la clase no esta disponible.
        return [
            1009 => [ 'slug' => 'coordinadora', 'label' => 'Coordinadora' ],
            1010 => [ 'slug' => 'tcc',          'label' => 'TCC' ],
            1016 => [ 'slug' => 'inter',        'label' => 'Interrapidisimo' ],
            33   => [ 'slug' => 'servientrega', 'label' => 'Servientrega' ],
        ];
    }

    /**
     * Retorna las oficinas de una transportadora, opcionalmente filtradas por ciudad.
     *
     * @param int|string  $carrier Código o slug de la transportadora.
     * @param string|null $city_id Código DANE de 8 dígitos. Null = consulta nacional.
     * @param string|null $nombre  Filtro parcial por nombre del punto de venta.
     * @param string|null $direccion Filtro parcial por dirección.
     * @return array Lista de oficinas. Cada elemento:
     *               [ 'nombre' => string, 'direccion' => string, 'ciudad' => string ]
     */
    public static function get_offices( $carrier, ?string $city_id = null, ?string $nombre = null, ?string $direccion = null ): array {
        $cache_key = self::CACHE_PREFIX . sanitize_key( (string) $carrier )
            . '_' . sanitize_key( (string) $city_id )
            . ( $nombre    ? '_n' . sanitize_key( $nombre )    : '' )
            . ( $direccion ? '_d' . sanitize_key( $direccion ) : '' );

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $api      = new LTMS_Api_Aveonline();
        $response = $api->get_carrier_offices( $carrier, $city_id, $nombre, $direccion );

        $offices = [];
        if ( isset( $response['status'] ) && 'success' === $response['status'] && ! empty( $response['operadores'] ) ) {
            // La respuesta agrupa por operador; aplanamos todas las oficinas en un array único.
            foreach ( $response['operadores'] as $operador ) {
                if ( is_array( $operador['oficinas'] ?? null ) ) {
                    foreach ( $operador['oficinas'] as $oficina ) {
                        $offices[] = [
                            'nombre'    => $oficina['nombre']    ?? '',
                            'direccion' => $oficina['direccion'] ?? '',
                            'ciudad'    => $oficina['ciudad']    ?? '',
                        ];
                    }
                }
            }
        }

        set_transient( $cache_key, $offices, self::CACHE_TTL );

        return $offices;
    }

    /**
     * Invalida la caché de oficinas de una ciudad concreta para todas las transportadoras.
     *
     * @param string $city_id Código DANE de 8 dígitos.
     * @return void
     */
    public static function invalidate_city_cache( string $city_id ): void {
        foreach ( array_keys( self::carriers() ) as $carrier ) {
            // Invalida la clave base por carrier+ciudad (sin filtros adicionales).
            $cache_key = self::CACHE_PREFIX . sanitize_key( (string) $carrier ) . '_' . sanitize_key( $city_id );
            delete_transient( $cache_key );
        }
    }

    /**
     * Retorna las oficinas formateadas como opciones para un <select>.
     *
     * @param int|string  $carrier Código o slug de la transportadora.
     * @param string|null $city_id Código DANE de 8 dígitos.
     * @return array [ value => label ] donde value = 'nombre||direccion' y label = 'Nombre — Dirección'.
     */
    public static function get_select_options( $carrier, ?string $city_id = null ): array {
        $offices = self::get_offices( $carrier, $city_id );
        $options = [];

        foreach ( $offices as $office ) {
            $nombre    = $office['nombre']    ?? '';
            $direccion = $office['direccion'] ?? '';
            if ( '' === $nombre && '' === $direccion ) {
                continue;
            }
            $value            = esc_attr( $nombre . '||' . $direccion );
            $label            = $nombre . ( $direccion ? ' — ' . $direccion : '' );
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
        $c = self::carriers();
        return $c[ (int) $carrier ]['label'] ?? ( class_exists( 'LTMS_Business_Aveonline_Carriers' ) ? LTMS_Business_Aveonline_Carriers::label( $carrier ) : (string) $carrier );
    }

    /**
     * Retorna el slug de una transportadora dado su código numérico.
     *
     * @param int|string $carrier Código de transportadora.
     * @return string Slug o el código como string si no se encuentra.
     */
    public static function carrier_slug( $carrier ): string {
        $c = self::carriers();
        return $c[ (int) $carrier ]['slug'] ?? (string) $carrier;
    }

    /**
     * Retorna true si el código de transportadora es válido para el endpoint /offices/all.
     *
     * @param int|string $carrier Código de transportadora.
     * @return bool
     */
    public static function is_valid_carrier( $carrier ): bool {
        return array_key_exists( (int) $carrier, self::carriers() );
    }
}
