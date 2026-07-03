<?php
/**
 * Catalogo dinamico de transportadoras Aveonline.
 *
 * Sincroniza la lista oficial de operadores logisticos desde la API
 * (listarTransportadorasPorEmpresa) y la guarda en wp_options con
 * cache de 24h. Reemplaza el array CARRIERS hardcodeado en
 * LTMS_Business_Aveonline_Offices.
 *
 * Transportadoras con soporte de oficinas via /offices/all:
 *   coordinadora (1009), tcc (1010), inter (1016), servientrega (33)
 *
 * @package LTMS
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Aveonline_Carriers
 */
class LTMS_Business_Aveonline_Carriers {

    /** Clave de wp_options donde se guarda el catalogo sincronizado. */
    private const OPTION_KEY = 'ltms_aveonline_carriers_cache';

    /** TTL del cache en segundos (24 horas). */
    private const CACHE_TTL = 86400;

    /**
     * Slugs de oficinas por codigo de transportadora.
     * Solo las transportadoras con soporte en /offices/all tienen slug.
     */
    private const OFFICE_SLUGS = [
        1009 => 'coordinadora',
        1010 => 'tcc',
        1016 => 'inter',
        33   => 'servientrega',
    ];

    /**
     * Registra el cron de sincronizacion diaria y el hook de activacion.
     */
    public static function init(): void {
        add_action( 'ltms_sync_aveonline_carriers', [ __CLASS__, 'sync' ] );

        if ( ! wp_next_scheduled( 'ltms_sync_aveonline_carriers' ) ) {
            wp_schedule_event( time(), 'daily', 'ltms_sync_aveonline_carriers' );
        }

        // Sincronizar tambien al activar el plugin (si el cache esta vacio).
        add_action( 'ltms_plugin_activated', static function () {
            if ( ! self::get_cached() ) {
                self::sync();
            }
        } );

        // Handler AJAX del boton de sincronizacion en el panel admin.
        add_action( 'wp_ajax_ltms_sync_aveonline_carriers', [ __CLASS__, 'ajax_sync' ] );
    }

    /**
     * Descarga la lista de transportadoras desde la API y la guarda en cache.
     *
     * @return int Numero de transportadoras sincronizadas, o 0 en error.
     */
    public static function sync(): int {
        try {
            $api      = new LTMS_Api_Aveonline();
            $carriers = $api->get_carriers();
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning( 'AVEONLINE_CARRIERS_SYNC_FAILED', $e->getMessage() );
            return 0;
        }

        if ( empty( $carriers ) ) {
            return 0;
        }

        $normalized = [];
        foreach ( $carriers as $c ) {
            $id = (int) ( $c['id'] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }
            $normalized[ $id ] = [
                'id'      => $id,
                'label'   => sanitize_text_field( $c['text']    ?? '' ),
                'imagen'  => sanitize_text_field( $c['imagen']  ?? '' ),
                'imagen2' => sanitize_text_field( $c['imagen2'] ?? '' ),
                'slug'    => self::OFFICE_SLUGS[ $id ] ?? null,
                'synced'  => time(),
            ];
        }

        update_option( self::OPTION_KEY, [
            'carriers'   => $normalized,
            'synced_at'  => time(),
            'expires_at' => time() + self::CACHE_TTL,
        ], false );

        return count( $normalized );
    }

    // -------------------------------------------------------------------------
    // Consultas
    // -------------------------------------------------------------------------

    /**
     * Retorna todas las transportadoras del catalogo.
     * Si el cache expiró, intenta re-sincronizar en segundo plano (no bloquea).
     *
     * @return array [ id => [ 'id', 'label', 'imagen', 'imagen2', 'slug' ] ]
     */
    public static function all(): array {
        $cached = self::get_cached();
        if ( $cached ) {
            return $cached['carriers'] ?? [];
        }
        // Cache expirado — sync en background y retorna array vacio temporalmente.
        wp_schedule_single_event( time(), 'ltms_sync_aveonline_carriers' );
        return [];
    }

    /**
     * Retorna el nombre legible de una transportadora dado su codigo.
     *
     * @param int|string $carrier_id Codigo de transportadora.
     * @return string Nombre o el codigo como string si no se encuentra.
     */
    public static function label( $carrier_id ): string {
        $id      = (int) $carrier_id;
        $cached  = self::get_cached();
        $carrier = $cached['carriers'][ $id ] ?? null;

        if ( $carrier ) {
            return $carrier['label'];
        }

        // Fallback a lista conocida para no romper si el cache esta vacio.
        $fallback = [
            1009 => 'Coordinadora',
            1010 => 'TCC',
            1016 => 'Interrapidisimo',
            1028 => '99Minutos',
            1026 => 'Domina',
            29   => 'Envia',
            33   => 'Servientrega',
        ];
        return $fallback[ $id ] ?? (string) $carrier_id;
    }

    /**
     * Retorna el slug de oficinas de una transportadora (null si no tiene soporte).
     *
     * @param int|string $carrier_id Codigo de transportadora.
     * @return string|null Slug o null.
     */
    public static function office_slug( $carrier_id ): ?string {
        return self::OFFICE_SLUGS[ (int) $carrier_id ] ?? null;
    }

    /**
     * Retorna true si la transportadora tiene soporte de oficinas via /offices/all.
     *
     * @param int|string $carrier_id Codigo de transportadora.
     * @return bool
     */
    public static function has_offices( $carrier_id ): bool {
        return isset( self::OFFICE_SLUGS[ (int) $carrier_id ] );
    }

    /**
     * Retorna las transportadoras con soporte de oficinas.
     *
     * @return array [ id => [ 'id', 'label', 'slug', ... ] ]
     */
    public static function with_offices(): array {
        $all    = self::all();
        $result = [];
        foreach ( $all as $id => $carrier ) {
            if ( isset( self::OFFICE_SLUGS[ $id ] ) ) {
                $result[ $id ] = array_merge( $carrier, [ 'slug' => self::OFFICE_SLUGS[ $id ] ] );
            }
        }
        // Si el catalogo esta vacio, devuelve el fallback hardcodeado de officinas.
        if ( empty( $result ) ) {
            foreach ( self::OFFICE_SLUGS as $id => $slug ) {
                $result[ $id ] = [
                    'id'    => $id,
                    'label' => self::label( $id ),
                    'slug'  => $slug,
                ];
            }
        }
        return $result;
    }

    /**
     * Retorna la URL completa del logo de una transportadora.
     *
     * @param int|string $carrier_id Codigo de transportadora.
     * @param bool       $second     Si true, retorna imagen2.
     * @return string URL o string vacio.
     */
    public static function logo_url( $carrier_id, bool $second = false ): string {
        $id      = (int) $carrier_id;
        $cached  = self::get_cached();
        $carrier = $cached['carriers'][ $id ] ?? null;
        if ( ! $carrier ) {
            return '';
        }
        $base = 'https://app.aveonline.co/app/temas/imagen_transpo/';
        $file = $second ? ( $carrier['imagen2'] ?? '' ) : ( $carrier['imagen'] ?? '' );
        return $file ? $base . $file : '';
    }

    /**
     * Timestamp de la ultima sincronizacion, o null si nunca se ha sincronizado.
     *
     * @return int|null
     */
    public static function last_sync_at(): ?int {
        $cached = self::get_cached();
        return $cached ? ( $cached['synced_at'] ?? null ) : null;
    }

    /**
     * Cantidad de transportadoras en el catalogo.
     *
     * @return int
     */
    public static function count(): int {
        $cached = self::get_cached();
        return $cached ? count( $cached['carriers'] ?? [] ) : 0;
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    /**
     * Handler AJAX del boton "Sincronizar transportadoras" en el panel admin.
     */
    public static function ajax_sync(): void {
        check_ajax_referer( 'ltms_sync_aveonline_carriers', '_wpnonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Sin permisos', 'ltms' ) );
        }

        $count = self::sync();

        if ( $count > 0 ) {
            wp_send_json_success( [
                'message' => sprintf(
                    __( '%d transportadoras sincronizadas correctamente.', 'ltms' ),
                    $count
                ),
                'count'   => $count,
            ] );
        } else {
            wp_send_json_error( __( 'No se pudieron sincronizar las transportadoras. Verifica las credenciales Aveonline.', 'ltms' ) );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Retorna el cache si existe y no ha expirado.
     *
     * @return array|null
     */
    private static function get_cached(): ?array {
        $cached = get_option( self::OPTION_KEY );
        if ( ! $cached || ! isset( $cached['expires_at'] ) ) {
            return null;
        }
        if ( time() > $cached['expires_at'] ) {
            return null;
        }
        return $cached;
    }
}
