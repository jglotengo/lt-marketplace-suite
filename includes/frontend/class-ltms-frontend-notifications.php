<?php
/**
 * LTMS Frontend Notifications — Utilidad de creación de notificaciones in-app
 *
 * NOTA (audit pedidos, fix hook duplicado): esta clase registraba previamente
 * sus propios handlers wp_ajax_ltms_get_notifications / wp_ajax_ltms_mark_notification_read,
 * duplicando los que ya existían y funcionaban en LTMS_Dashboard_Logic (registrado antes
 * en el kernel, por lo que SIEMPRE ganaba y dejaba estos como código muerto e inalcanzable).
 * Además, esta versión usaba check_ajax_referer('ltms_nonce', ...) mientras que el JS del
 * dashboard envía un nonce generado con la acción 'ltms_dashboard_nonce' — un nonce mismatch
 * que habría fallado igual si alguna vez se hubiera vuelto alcanzable.
 *
 * Se eliminaron esos dos handlers duplicados. Esta clase ahora solo expone create(),
 * la utilidad real que otros módulos (ej. LTMS_Booking_Notifications) usan para insertar
 * notificaciones in-app en bkr_lt_notifications.
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

    /**
     * No-op intencional: ya no registra hooks AJAX (ver nota de cabecera).
     * Se mantiene por compatibilidad con cualquier llamada existente a ::init().
     */
    public static function init(): void {}

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
