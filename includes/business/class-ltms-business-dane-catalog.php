<?php
/**
 * LTMS Business DANE Catalog
 *
 * Acceso unificado al catálogo DANE de municipios Colombia (`lt_co_dane_municipalities`).
 * Provee métodos para construir dropdowns, validar códigos y resolver nombres.
 * Cache via transient (12h, el catálogo es estable).
 *
 * Consumido por:
 *  - Checkout WooCommerce (LTMS_Frontend_Checkout_Municipality_Field)
 *  - Perfil vendedor (view-settings.php)
 *  - Registro vendedor (form-register.php)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Business_Dane_Catalog {

    public const TRANSIENT_KEY = 'ltms_dane_municipality_options';
    public const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS; // 43200s

    public static function init(): void {}

    /**
     * Devuelve opciones para un <select>: [code => "Nombre — Departamento"].
     * Opcionalmente añade una opción vacía al inicio.
     *
     * @param bool $include_blank Si true, incluye `'' => '— Selecciona —'` al inicio.
     * @return array<string,string>
     */
    public static function get_options( bool $include_blank = true ): array {
        $cached = get_transient( self::TRANSIENT_KEY );
        $rows   = is_array( $cached ) ? $cached : null;

        if ( $rows === null ) {
            $rows = self::load_from_db();
            if ( ! empty( $rows ) ) {
                set_transient( self::TRANSIENT_KEY, $rows, self::TRANSIENT_TTL );
            }
        }

        $options = [];
        if ( $include_blank ) {
            $options[''] = __( '— Selecciona tu municipio —', 'ltms' );
        }
        foreach ( $rows as $row ) {
            $code  = (string) ( $row['code'] ?? '' );
            $label = sprintf( '%s — %s', $row['municipality_name'] ?? '', $row['department_name'] ?? '' );
            if ( $code !== '' ) {
                $options[ $code ] = $label;
            }
        }
        return $options;
    }

    /**
     * Devuelve el nombre del municipio dado su código DANE.
     *
     * @param string $code Código DANE 5-dig.
     * @return string
     */
    public static function get_name( string $code ): string {
        if ( ! self::is_valid_code( $code ) ) {
            return '';
        }
        foreach ( self::get_rows() as $row ) {
            if ( (string) ( $row['code'] ?? '' ) === $code ) {
                return (string) ( $row['municipality_name'] ?? '' );
            }
        }
        return '';
    }

    /**
     * Valida que el código tenga formato DANE (5 dígitos) y exista en catálogo activo.
     *
     * @param string $code Código a validar.
     * @return bool
     */
    public static function exists( string $code ): bool {
        if ( ! self::is_valid_code( $code ) ) {
            return false;
        }
        foreach ( self::get_rows() as $row ) {
            if ( (string) ( $row['code'] ?? '' ) === $code ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Solo valida formato (5 dígitos numéricos) — no consulta BD.
     */
    public static function is_valid_code( string $code ): bool {
        return (bool) preg_match( '/^\d{5}$/', $code );
    }

    /**
     * Limpia el cache. Llamar después de modificar `lt_co_dane_municipalities`.
     */
    public static function flush_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    /**
     * Devuelve filas en bruto desde cache o BD.
     *
     * @return array<int,array{code:string,department_code:string,department_name:string,municipality_name:string}>
     */
    private static function get_rows(): array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $rows = self::load_from_db();
        if ( ! empty( $rows ) ) {
            set_transient( self::TRANSIENT_KEY, $rows, self::TRANSIENT_TTL );
        }
        return $rows;
    }

    /**
     * Carga el catálogo desde la BD.
     *
     * @return array
     */
    private static function load_from_db(): array {
        if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_co_dane_municipalities';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT code, department_code, department_name, municipality_name
               FROM `{$table}`
              WHERE is_active = 1
              ORDER BY municipality_name ASC",
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Busca el código DANE de un municipio dado su nombre (case-insensitive).
     * Útil para mapear ciudades de pedidos WC a códigos DANE de Alegra Colombia.
     *
     * @param string $city_name Nombre del municipio (ej: 'Cali', 'BOGOTÁ').
     * @return string Código DANE 5-dig o vacío si no existe.
     */
    public static function get_city_code( string $city_name ): string {
        if ( ! $city_name ) {
            return '';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_co_dane_municipalities';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $code = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT code FROM `{$table}` WHERE LOWER(municipality_name) = LOWER(%s) AND is_active = 1 LIMIT 1",
                trim( $city_name )
            )
        );

        return (string) ( $code ?: '' );
    }
}
