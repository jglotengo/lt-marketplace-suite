<?php
/**
 * LTMS_Business_Aveonline_Hub_Log
 *
 * Auditoría local de los eventos enviados a Ave-Hub mediante
 * LTMS_Api_Aveonline_Hub::push_events(). Cada intento (exitoso o no) se
 * registra en `lt_aveonline_hub_push_log` para poder depurar y para
 * alimentar la UI de administración de Ave-Hub.
 *
 * @package LTMS
 * @since   2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Aveonline_Hub_Log
 */
class LTMS_Business_Aveonline_Hub_Log {

    /** Nombre de la tabla local (sin prefijo de WP). */
    const TABLE = 'lt_aveonline_hub_push_log';

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    /**
     * Crea la tabla si no existe.
     */
    public static function init(): void {
        add_action( 'ltms_plugin_activated', [ __CLASS__, 'maybe_create_table' ] );
        self::maybe_create_table();
    }

    // ── DB ────────────────────────────────────────────────────────────────────

    /**
     * Crea la tabla local de log si no existe.
     */
    public static function maybe_create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            id_envio        VARCHAR(60)  NOT NULL DEFAULT '',
            cod_estado      VARCHAR(20)  NOT NULL DEFAULT '',
            nombre_estado   VARCHAR(120) NOT NULL DEFAULT '',
            fecha_estado    VARCHAR(40)  NOT NULL DEFAULT '',
            status          VARCHAR(20)  NOT NULL DEFAULT '',
            response_message TEXT        NOT NULL DEFAULT '',
            payload_raw     LONGTEXT     NOT NULL DEFAULT '',
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id   (order_id),
            KEY id_envio   (id_envio),
            KEY status     (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Registro de eventos ──────────────────────────────────────────────────

    /**
     * Registra el resultado de un push a Ave-Hub.
     *
     * @param array  $event    Evento enviado (formato LTMS_Api_Aveonline_Hub::build_event()).
     * @param string $status   'success' | 'error'.
     * @param string $message  Mensaje de la respuesta (o del error).
     * @param int    $order_id ID del pedido WooCommerce relacionado (opcional).
     * @return int ID del registro insertado, o 0 si falló.
     */
    public static function record( array $event, string $status, string $message = '', int $order_id = 0 ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'         => $order_id,
                'id_envio'         => (string) ( $event['id_envio']      ?? '' ),
                'cod_estado'       => (string) ( $event['cod_estado']    ?? '' ),
                'nombre_estado'    => (string) ( $event['nombre_estado'] ?? '' ),
                'fecha_estado'     => (string) ( $event['fecha_estado']  ?? '' ),
                'status'           => $status,
                'response_message' => $message,
                'payload_raw'      => wp_json_encode( $event ),
                'created_at'       => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Obtiene los registros más recientes, con filtros opcionales.
     *
     * @param int   $limit   Máximo de registros.
     * @param int   $offset  Offset de paginación.
     * @param array $filters Filtros opcionales:
     *   - id_envio     (string) Coincidencia exacta con id_envio (= order_id).
     *   - fecha_inicio (string) AAAA-MM-DD, filtra created_at >= fecha_inicio 00:00:00.
     *   - fecha_fin    (string) AAAA-MM-DD, filtra created_at <= fecha_fin 23:59:59.
     *   - status       (string) 'success' | 'error'.
     * @return array
     */
    public static function get_recent( int $limit = 50, int $offset = 0, array $filters = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where  = [];
        $params = [];

        if ( ! empty( $filters['id_envio'] ) ) {
            $where[]  = 'id_envio = %s';
            $params[] = sanitize_text_field( (string) $filters['id_envio'] );
        }

        if ( ! empty( $filters['status'] ) && in_array( $filters['status'], [ 'success', 'error' ], true ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['fecha_inicio'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = sanitize_text_field( (string) $filters['fecha_inicio'] ) . ' 00:00:00';
        }

        if ( ! empty( $filters['fecha_fin'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = sanitize_text_field( (string) $filters['fecha_fin'] ) . ' 23:59:59';
        }

        $sql = "SELECT * FROM `{$table}`";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
    }

    /**
     * Cuenta registros, con los mismos filtros que get_recent().
     *
     * @param array $filters Ver get_recent().
     * @return int
     */
    public static function count_filtered( array $filters = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where  = [];
        $params = [];

        if ( ! empty( $filters['id_envio'] ) ) {
            $where[]  = 'id_envio = %s';
            $params[] = sanitize_text_field( (string) $filters['id_envio'] );
        }

        if ( ! empty( $filters['status'] ) && in_array( $filters['status'], [ 'success', 'error' ], true ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['fecha_inicio'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = sanitize_text_field( (string) $filters['fecha_inicio'] ) . ' 00:00:00';
        }

        if ( ! empty( $filters['fecha_fin'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = sanitize_text_field( (string) $filters['fecha_fin'] ) . ' 23:59:59';
        }

        $sql = "SELECT COUNT(*) FROM `{$table}`";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Obtiene los registros asociados a un pedido.
     *
     * @param int $order_id ID del pedido WooCommerce.
     * @return array
     */
    public static function get_by_order( int $order_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY created_at DESC",
                $order_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Cuenta el total de registros en el log.
     *
     * @return int
     */
    public static function count(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    }
}

