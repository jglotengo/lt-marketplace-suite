<?php
/**
 * LTMS Vendor Followers — Seguidores de vendedor (Plaza Viva)
 *
 * Persiste la relación follower→vendor disparada por el botón "Seguir
 * vendedor" del design system Plaza Viva (vendor-store.php:353,
 * data-pv-follow-vendor). Antes el follow era solo cosmético (JS inline
 * cambiaba el label sin persistir nada en backend — ver AUDIT-FE-SF-006).
 *
 * Tabla: `bkr_lt_vendor_followers` (creada en
 * LTMS_DB_Migrations::create_tables() vía dbDelta).
 *
 * Endpoints AJAX:
 *   - ltms_follow_vendor        (logueado o guest, ambos permitidos)
 *     Toggle del follow. Devuelve { is_following: bool, followers_count: int }.
 *
 * @package LTMS
 * @since 2.9.306
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Vendor_Followers {

    public static function init(): void {
        add_action( 'wp_ajax_ltms_follow_vendor',        [ __CLASS__, 'ajax_toggle_follow' ] );
        add_action( 'wp_ajax_nopriv_ltms_follow_vendor', [ __CLASS__, 'ajax_toggle_follow' ] );
    }

    /**
     * AJAX: ltms_follow_vendor
     *
     * Toggle de follow. Idempotente: si ya sigue, deja de seguir; si no,
     * empieza. Devuelve el conteo actualizado de seguidores del vendor.
     *
     * Respuesta JSON:
     *   success: { is_following: bool, followers_count: int, message: string }
     *
     * @return void
     */
    public static function ajax_toggle_follow(): void {
        // AUDIT-FE-SF-006 FIX (Fase 1.4): validamos contra el nonce global
        // `ltms_plaza_viva` que el helper PV.ajax envía automáticamente via
        // PV.config.nonce (ver class-ltms-native-templates.php:327). Antes se
        // validaba contra 'ltms_follow_vendor' — el botón HTML emitía un nonce
        // específico en data-pv-follow-nonce, pero PV.ajax siempre envía el
        // nonce global; por tanto el handler rechazaba 100% de las calls con
        // 403. Alinearlo al nonce que el JS realmente envía (mismo patrón que
        // ajax_plaza_viva_add_to_cart y ajax_quick_view).
        check_ajax_referer( 'ltms_plaza_viva', 'nonce' );

        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );
        if ( ! $vendor_id ) {
            wp_send_json_error( [ 'message' => __( 'Vendedor no especificado', 'ltms' ) ], 400 );
        }

        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor ) {
            wp_send_json_error( [ 'message' => __( 'Vendedor no encontrado', 'ltms' ) ], 404 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_followers';

        // Resolver la identidad del follower: usuario logueado (follower_id>0,
        // ip_hash vacío) o anónimo (follower_id=0, ip_hash=SHA-256 IP+UA).
        $follower_id = get_current_user_id();
        $ip_hash     = '';
        if ( ! $follower_id ) {
            $raw    = ( $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
            $ip_hash = hash( 'sha256', $raw );
            if ( ! $ip_hash || strlen( $ip_hash ) !== 64 ) {
                wp_send_json_error( [ 'message' => __( 'No se pudo identificar al seguidor', 'ltms' ) ], 400 );
            }
        }

        // ¿Ya existe la relación?
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE vendor_id = %d AND follower_id = %d AND follower_ip_hash = %s LIMIT 1",
            $vendor_id, $follower_id, $ip_hash
        ) );

        if ( $existing ) {
            // Ya sigue → dejar de seguir (DELETE idempotente).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->delete( $table, [ 'id' => (int) $existing ], [ '%d' ] );
            $is_following = false;
        } else {
            // No sigue → empezar (INSERT).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->insert(
                $table,
                [
                    'vendor_id'        => $vendor_id,
                    'follower_id'      => $follower_id,
                    'follower_ip_hash' => $ip_hash,
                    'created_at'       => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
            $is_following = true;
        }

        $count = self::count_followers( $vendor_id );

        wp_send_json_success( [
            'is_following'    => $is_following,
            'followers_count' => $count,
            'message'         => $is_following
                ? __( 'Ahora sigues a este vendedor', 'ltms' )
                : __( 'Dejaste de seguir a este vendedor', 'ltms' ),
        ] );
    }

    /**
     * Cuenta los seguidores de un vendor. Cacheeable vía transient externo
     * (no se cachea aquí para no acoplar — el caller decide).
     *
     * @param int $vendor_id ID del vendedor.
     * @return int
     */
    public static function count_followers( int $vendor_id ): int {
        if ( $vendor_id <= 0 ) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_followers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d",
            $vendor_id
        ) );
    }

    /**
     * Verifica si un usuario (o visitante anónimo por su hash) sigue a un
     * vendor. Útil para renderizar el botón "Seguir"/"Siguiendo" con el
     * estado correcto al cargar la vitrina.
     *
     * @param int    $vendor_id  ID del vendedor.
     * @param int    $follower_id ID del usuario (0 = verificar hash anónimo).
     * @param string $ip_hash     Hash SHA-256 si follower_id=0.
     * @return bool
     */
    public static function is_following( int $vendor_id, int $follower_id, string $ip_hash = '' ): bool {
        if ( $vendor_id <= 0 ) {
            return false;
        }
        if ( ! $follower_id && ! $ip_hash ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_followers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE vendor_id = %d AND follower_id = %d AND follower_ip_hash = %s LIMIT 1",
            $vendor_id, $follower_id, $ip_hash
        ) );
        return $id !== null;
    }
}
