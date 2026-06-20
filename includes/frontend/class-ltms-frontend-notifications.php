<?php
/**
 * LTMS Frontend Notifications Handler
 *
 * Provee los endpoints AJAX para el sistema de notificaciones in-app
 * del panel de vendedor:
 *
 *   ltms_get_notifications      — polling de notificaciones no leídas
 *   ltms_mark_notification_read — marcar una notificación como leída
 *
 * La tabla bkr_lt_notifications debe existir (creada en LTMS_DB_Migrations).
 * Si no existe aún (instalación antigua sin migrar), los handlers responden
 * con listas vacías en lugar de lanzar un error de DB que contaminaría el log.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @since      2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Notifications
 */
class LTMS_Frontend_Notifications {

    use LTMS_Logger_Aware;

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'wp_ajax_ltms_get_notifications',       [ self::class, 'ajax_get_notifications' ] );
        add_action( 'wp_ajax_ltms_mark_notification_read',  [ self::class, 'ajax_mark_read' ] );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Comprueba si la tabla lt_notifications existe en la DB actual.
     * Evita errores fatales en instalaciones que aún no han ejecutado migrate_2_4_0+.
     */
    private static function table_exists(): bool {
        global $wpdb;
        static $exists = null;
        if ( null === $exists ) {
            $exists = (bool) $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'lt_notifications' )
            );
        }
        return $exists;
    }

    // ── AJAX handlers ────────────────────────────────────────────────────

    /**
     * Handler: ltms_get_notifications
     *
     * Parámetros POST:
     *   nonce   string  ltms_nonce
     *   since   string  (opcional) datetime Y-m-d H:i:s — devuelve solo notificaciones
     *                   creadas DESPUÉS de este timestamp (para polling incremental).
     *
     * Respuesta JSON success:
     *   {
     *     count:         int,    // total no leídas (para el badge)
     *     notifications: [       // solo las nuevas desde `since`
     *       { id, type, title, message, created_at }
     *     ]
     *   }
     */
    public static function ajax_get_notifications(): void {
        check_ajax_referer( 'ltms_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            wp_send_json_success( [ 'count' => 0, 'notifications' => [] ] );
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $since   = sanitize_text_field( wp_unslash( $_POST['since'] ?? '' ) );

        // Total no leídas (para el badge) — siempre, independientemente de `since`.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lt_notifications
                  WHERE user_id = %d AND is_read = 0
                    AND ( expires_at IS NULL OR expires_at > NOW() )
                    AND channel = 'inapp'",
                $user_id
            )
        );

        // Notificaciones nuevas desde `since` (o las últimas 20 si no hay `since`).
        if ( $since && preg_match( '/^\d{4}-\d{2}-\d{2}/', $since ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, type, title, message, created_at
                       FROM {$wpdb->prefix}lt_notifications
                      WHERE user_id = %d AND is_read = 0
                        AND channel = 'inapp'
                        AND created_at > %s
                        AND ( expires_at IS NULL OR expires_at > NOW() )
                      ORDER BY created_at DESC
                      LIMIT 50",
                    $user_id,
                    $since
                ),
                ARRAY_A
            ) ?: [];
        } else {
            // Primera carga: devuelve las últimas 20 no leídas.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, type, title, message, created_at
                       FROM {$wpdb->prefix}lt_notifications
                      WHERE user_id = %d AND is_read = 0
                        AND channel = 'inapp'
                        AND ( expires_at IS NULL OR expires_at > NOW() )
                      ORDER BY created_at DESC
                      LIMIT 20",
                    $user_id
                ),
                ARRAY_A
            ) ?: [];
        }

        wp_send_json_success( [
            'count'         => $count,
            'notifications' => $rows,
        ] );
    }

    /**
     * Handler: ltms_mark_notification_read
     *
     * Parámetros POST:
     *   nonce           string  ltms_nonce
     *   notification_id int     ID de la notificación a marcar como leída.
     */
    public static function ajax_mark_read(): void {
        check_ajax_referer( 'ltms_nonce', 'nonce' );

        if ( ! self::table_exists() ) {
            wp_send_json_success( [] );
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $notif_id = absint( $_POST['notification_id'] ?? 0 );

        if ( ! $notif_id ) {
            wp_send_json_error( __( 'ID de notificación inválido.', 'ltms' ) );
        }

        // Verificar ownership antes de marcar (evita que un vendedor marque
        // notificaciones de otro).
        $owner = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}lt_notifications WHERE id = %d",
                $notif_id
            )
        );

        if ( $owner !== $user_id ) {
            wp_send_json_error( __( 'No tienes permiso sobre esta notificación.', 'ltms' ) );
        }

        $wpdb->update(
            $wpdb->prefix . 'lt_notifications',
            [
                'is_read' => 1,
                'read_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $notif_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [] );
    }

    // ── Utilidades para crear notificaciones desde otros módulos ─────────

    /**
     * Crea una notificación in-app para un usuario.
     *
     * @param int    $user_id  Destinatario.
     * @param string $type     Tipo (ej. 'new_booking', 'booking_cancelled', 'payout_approved').
     * @param string $title    Título corto.
     * @param string $message  Cuerpo del mensaje.
     * @param array  $data     Datos extra (JSON), opcional.
     * @return int|false       ID de la notificación creada, false si falla.
     */
    public static function create( int $user_id, string $type, string $title, string $message, array $data = [] ): int|false {
        if ( ! self::table_exists() ) return false;

        global $wpdb;

        $res = $wpdb->insert(
            $wpdb->prefix . 'lt_notifications',
            [
                'user_id' => $user_id,
                'type'    => sanitize_text_field( $type ),
                'channel' => 'inapp',
                'title'   => sanitize_text_field( $title ),
                'message' => sanitize_textarea_field( $message ),
                'data'    => ! empty( $data ) ? wp_json_encode( $data ) : null,
                'is_read' => 0,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return $res ? (int) $wpdb->insert_id : false;
    }
}
