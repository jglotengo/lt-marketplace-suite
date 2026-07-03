<?php
/**
 * Class LTMS_Business_Redi_Incident
 *
 * AUDIT-REDI-UX-GAPS GAP-9 FIX: Incident Manager para el modelo ReDi.
 *
 * Gestiona el ciclo de vida de "novedades" (incidencias) reportadas sobre
 * pedidos que involucran productos ReDi (Reseller Distribution). Permite
 * que vendedores origen y revendedores abran tickets, agreguen comentarios
 * y escalen a administradores cuando se exceden los SLA.
 *
 * Tablas:
 *   - lt_redi_incidents            (cabecera de la incidencia)
 *   - lt_redi_incident_comments    (hilo de comentarios)
 *
 * SLA:
 *   - Primera respuesta:   48 horas (SLA_FIRST_RESPONSE_HOURS)
 *   - Resolución total:    15 días  (SLA_RESOLUTION_DAYS)
 *
 * Auto-acciones en create():
 *   - type=stockout  → soft_pause_redi() del producto origen
 *   - type=complaint → freeze de comisiones retenidas del pedido
 *
 * @package LTMS
 * @subpackage LTMS/includes/business
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Business_Redi_Incident {

    /**
     * SLA para la primera respuesta (en horas).
     */
    public const SLA_FIRST_RESPONSE_HOURS = 48;

    /**
     * SLA para la resolución total (en días).
     */
    public const SLA_RESOLUTION_DAYS = 15;

    /**
     * Lista canónica de estados de una incidencia.
     */
    public const STATUSES = [ 'open', 'investigating', 'escalated', 'resolved', 'closed' ];

    /**
     * Lista canónica de tipos de incidencia.
     */
    public const TYPES = [ 'stockout', 'complaint', 'quality', 'shipping', 'payment', 'other' ];

    /**
     * Registra los hooks AJAX y el cron de SLA.
     *
     * @return void
     */
    public static function init(): void {
        // AJAX handlers — todos requieren nonce 'ltms_dashboard_nonce' (vendor)
        // o 'ltms_admin_nonce' (admin).
        add_action( 'wp_ajax_ltms_create_incident',         [ __CLASS__, 'ajax_create_incident' ] );
        add_action( 'wp_ajax_ltms_add_incident_comment',    [ __CLASS__, 'ajax_add_comment' ] );
        add_action( 'wp_ajax_ltms_get_incidents',           [ __CLASS__, 'ajax_get_incidents' ] );
        add_action( 'wp_ajax_ltms_get_incident_detail',     [ __CLASS__, 'ajax_get_incident_detail' ] );
        add_action( 'wp_ajax_ltms_admin_change_incident',   [ __CLASS__, 'ajax_admin_change_status' ] );

        // Cron hook — disparado hourly por wp-cron (registrado en activator).
        add_action( 'ltms_redi_incident_sla_check', [ __CLASS__, 'sla_check_cron' ] );
    }

    // ========================================================================
    // Creación de incidencias
    // ========================================================================

    /**
     * Crea una nueva incidencia ReDi.
     *
     * Datos esperados en $data:
     *   - order_id           (int)    Order ID (requerido)
     *   - origin_vendor_id   (int)    Origin vendor user ID (requerido)
     *   - reseller_vendor_id (int)    Reseller vendor user ID (opcional, 0 si no aplica)
     *   - customer_id        (int)    Customer user ID (opcional, 0 si no aplica)
     *   - type               (string) Uno de TYPES (requerido)
     *   - description        (string) Descripción legible (requerido)
     *   - created_by         (int)    User ID que abre el ticket (requerido)
     *
     * Auto-acciones:
     *   - type=stockout  → soft_pause_redi del producto origen
     *   - type=complaint → freeze de comisiones retenidas del pedido
     *
     * @param array $data Datos de la incidencia.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type int    $incident_id   ID del nuevo incident, 0 si falló.
     *     @type array  $auto_actions  Lista de acciones automáticas ejecutadas.
     * }
     */
    public static function create( array $data ): array {
        global $wpdb;

        $order_id           = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
        $origin_vendor_id   = isset( $data['origin_vendor_id'] ) ? (int) $data['origin_vendor_id'] : 0;
        $reseller_vendor_id = isset( $data['reseller_vendor_id'] ) ? (int) $data['reseller_vendor_id'] : 0;
        $customer_id        = isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
        $type               = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
        $description        = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
        $created_by         = isset( $data['created_by'] ) ? (int) $data['created_by'] : 0;

        // Validaciones.
        if ( ! $order_id || ! $origin_vendor_id || ! $created_by ) {
            return [
                'success'      => false,
                'message'      => __( 'Faltan datos obligatorios (order_id, origin_vendor_id, created_by)', 'ltms' ),
                'incident_id'  => 0,
                'auto_actions' => [],
            ];
        }

        if ( ! in_array( $type, self::TYPES, true ) ) {
            return [
                'success'      => false,
                'message'      => sprintf( __( 'Tipo de incidencia inválido: %s', 'ltms' ), $type ),
                'incident_id'  => 0,
                'auto_actions' => [],
            ];
        }

        if ( '' === $description ) {
            return [
                'success'      => false,
                'message'      => __( 'La descripción es obligatoria', 'ltms' ),
                'incident_id'  => 0,
                'auto_actions' => [],
            ];
        }

        $table = $wpdb->prefix . 'lt_redi_incidents';
        $now   = LTMS_Utils::now_utc();

        // SLA deadlines (en UTC, formato MySQL DATETIME).
        $sla_due_at        = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . self::SLA_FIRST_RESPONSE_HOURS . ' hours' ) );
        $resolution_due_at = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . self::SLA_RESOLUTION_DAYS . ' days' ) );

        // INSERT — phpcs:ignore por tabla personalizada.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'           => $order_id,
                'origin_vendor_id'   => $origin_vendor_id,
                'reseller_vendor_id' => $reseller_vendor_id,
                'customer_id'        => $customer_id,
                'type'               => $type,
                'description'        => $description,
                'status'             => 'open',
                'sla_due_at'         => $sla_due_at,
                'resolution_due_at'  => $resolution_due_at,
                'created_by'         => $created_by,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [
                'success'      => false,
                'message'      => __( 'Error al crear la incidencia en BD', 'ltms' ),
                'incident_id'  => 0,
                'auto_actions' => [],
            ];
        }

        $incident_id = (int) $wpdb->insert_id;

        // Auto-acciones según tipo.
        $auto_actions = self::run_auto_actions( $incident_id, $type, $order_id, $origin_vendor_id );

        // Notificar a ambos vendedores + admin.
        self::notify_incident_created( $incident_id, [
            'order_id'           => $order_id,
            'origin_vendor_id'   => $origin_vendor_id,
            'reseller_vendor_id' => $reseller_vendor_id,
            'type'               => $type,
            'description'        => $description,
            'created_by'         => $created_by,
        ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'REDI_INCIDENT_CREATED',
                sprintf(
                    'Incident #%d created (order #%d, type=%s, origin_vendor=%d, reseller=%d). Auto-actions: %d.',
                    $incident_id, $order_id, $type, $origin_vendor_id, $reseller_vendor_id,
                    count( $auto_actions )
                )
            );
        }

        return [
            'success'      => true,
            'message'      => sprintf( __( 'Incidencia #%d creada correctamente', 'ltms' ), $incident_id ),
            'incident_id'  => $incident_id,
            'auto_actions' => $auto_actions,
        ];
    }

    /**
     * Ejecuta las acciones automáticas asociadas al tipo de incidencia.
     *
     * - type=stockout  → soft_pause_redi del producto origen del pedido.
     * - type=complaint → freeze de comisiones retenidas (status='held') del pedido.
     *
     * @param int    $incident_id       ID de la incidencia recién creada.
     * @param string $type              Tipo de incidencia.
     * @param int    $order_id          Order ID.
     * @param int    $origin_vendor_id  Origin vendor user ID.
     * @return array Lista de acciones ejecutadas (para logging / feedback).
     */
    private static function run_auto_actions( int $incident_id, string $type, int $order_id, int $origin_vendor_id ): array {
        $actions = [];

        // stockout → soft pause del producto origen del primer item ReDi del pedido.
        if ( 'stockout' === $type ) {
            $order = wc_get_order( $order_id );
            if ( $order && class_exists( 'LTMS_Business_Redi_Manager' ) ) {
                foreach ( $order->get_items() as $item ) {
                    $product_id        = (int) $item->get_product_id();
                    $origin_product_id = (int) get_post_meta( $product_id, '_ltms_redi_origin_product_id', true );

                    if ( $origin_product_id ) {
                        $result = LTMS_Business_Redi_Manager::soft_pause_redi( $origin_product_id );
                        $actions[] = [
                            'action'              => 'soft_pause_redi',
                            'origin_product_id'   => $origin_product_id,
                            'agreements_affected' => $result['agreements_affected'] ?? 0,
                            'success'             => $result['success'] ?? false,
                        ];
                        // Solo se pausa el primer producto ReDi encontrado
                        // (un incidente de stockout típicamente afecta a un SKU).
                        break;
                    }
                }
            }
        }

        // complaint → freeze de comisiones retenidas (held) del pedido.
        if ( 'complaint' === $type ) {
            global $wpdb;
            $commis_table = $wpdb->prefix . 'lt_redi_commissions';

            // Marcar comisiones 'held' del pedido como 'disputed' (congeladas hasta resolver).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $frozen = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$commis_table}` SET status = 'disputed', metadata = JSON_SET(
                        COALESCE(metadata, '{}'),
                        '$.dispute_incident_id', %d,
                        '$.dispute_frozen_at', %s
                    ) WHERE order_id = %d AND status = 'held'",
                    $incident_id,
                    LTMS_Utils::now_utc(),
                    $order_id
                )
            );

            if ( false !== $frozen ) {
                $actions[] = [
                    'action'         => 'freeze_held_commissions',
                    'order_id'       => $order_id,
                    'rows_affected'  => (int) $frozen,
                    'success'        => true,
                ];
            }
        }

        return $actions;
    }

    // ========================================================================
    // Comentarios y cambio de estado
    // ========================================================================

    /**
     * Agrega un comentario a una incidencia existente.
     *
     * @param int    $incident_id ID de la incidencia.
     * @param int    $user_id     User ID del autor del comentario.
     * @param string $comment     Texto del comentario.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type int    $comment_id
     * }
     */
    public static function add_comment( int $incident_id, int $user_id, string $comment ): array {
        global $wpdb;

        if ( ! $incident_id || ! $user_id ) {
            return [
                'success'    => false,
                'message'    => __( 'Faltan datos obligatorios (incident_id, user_id)', 'ltms' ),
                'comment_id' => 0,
            ];
        }

        $comment = sanitize_textarea_field( $comment );
        if ( '' === $comment ) {
            return [
                'success'    => false,
                'message'    => __( 'El comentario no puede estar vacío', 'ltms' ),
                'comment_id' => 0,
            ];
        }

        $table = $wpdb->prefix . 'lt_redi_incident_comments';
        $now   = LTMS_Utils::now_utc();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'incident_id' => $incident_id,
                'user_id'     => $user_id,
                'comment'     => $comment,
                'created_at'  => $now,
            ],
            [ '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [
                'success'    => false,
                'message'    => __( 'Error al insertar comentario', 'ltms' ),
                'comment_id' => 0,
            ];
        }

        $comment_id = (int) $wpdb->insert_id;

        // Bump updated_at en la cabecera.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $wpdb->prefix . 'lt_redi_incidents',
            [ 'updated_at' => $now ],
            [ 'id' => $incident_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Notificar a la otra parte (origin o reseller, según quien comenta).
        self::notify_incident_comment( $incident_id, $user_id, $comment );

        return [
            'success'    => true,
            'message'    => __( 'Comentario agregado', 'ltms' ),
            'comment_id' => $comment_id,
        ];
    }

    /**
     * Cambia el estado de una incidencia.
     *
     * Solo administradores (manage_woocommerce) pueden cambiar el estado
     * manualmente. Los vendedores solo pueden abrir nuevos tickets y comentar.
     *
     * @param int    $incident_id ID de la incidencia.
     * @param string $new_status  Nuevo estado (debe estar en STATUSES).
     * @param string $notes       Notas opcionales del admin.
     * @param int    $changed_by  User ID del admin que cambia el estado.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     * }
     */
    public static function change_status( int $incident_id, string $new_status, string $notes, int $changed_by ): array {
        global $wpdb;

        // AUDIT-RD-BK RD-4 FIX: defense-in-depth authorization. Antes el único
        // check de capability estaba en ajax_admin_change_status() (que valida
        // manage_woocommerce). Pero change_status() es un método público llamado
        // también desde:
        //   - sla_check_cron() (líneas 562, 599) — auto-escalar vencidos
        //   - cualquier código futuro que invoque LTMS_Business_Redi_Incident::change_status()
        // Si un futuro endpoint AJAX o un hook REST llamaba change_status()
        // directamente, el bypass era total — un vendor podía resolver/cerrar
        // incidencias arbitrarias (incluidas las que abrió en su contra por
        // quejas de calidad) sin permiso admin.
        //
        // FIX: verificar SIEMPRE que $changed_by tiene manage_woocommerce (o es
        // 0 = llamada interna del cron, que se permite). Esto es consistente
        // con el docstring del método: "Solo administradores (manage_woocommerce)
        // pueden cambiar el estado manualmente."
        if ( $changed_by > 0 ) {
            $user = get_userdata( $changed_by );
            if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
                return [
                    'success' => false,
                    'message' => __( 'Permisos insuficientes: solo administradores pueden cambiar el estado de una incidencia.', 'ltms' ),
                ];
            }
        }

        if ( ! in_array( $new_status, self::STATUSES, true ) ) {
            return [
                'success' => false,
                'message' => sprintf( __( 'Estado inválido: %s', 'ltms' ), $new_status ),
            ];
        }

        $table = $wpdb->prefix . 'lt_redi_incidents';
        $now   = LTMS_Utils::now_utc();

        $update_data = [
            'status'           => $new_status,
            'updated_at'       => $now,
            'resolution_notes' => $notes,
        ];
        $update_fmt = [ '%s', '%s', '%s' ];

        // Si el nuevo estado es resolved o closed, marcar resolved_at.
        if ( in_array( $new_status, [ 'resolved', 'closed' ], true ) ) {
            $update_data['resolved_at'] = $now;
            $update_fmt[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $incident_id ],
            $update_fmt,
            [ '%d' ]
        );

        if ( false === $updated ) {
            return [
                'success' => false,
                'message' => __( 'Error al actualizar el estado', 'ltms' ),
            ];
        }

        // Notificar a ambas partes del cambio de estado.
        self::notify_incident_status_change( $incident_id, $new_status, $notes, $changed_by );

        return [
            'success' => true,
            'message' => sprintf( __( 'Incidencia #%d actualizada a "%s"', 'ltms' ), $incident_id, $new_status ),
        ];
    }

    // ========================================================================
    // Consultas
    // ========================================================================

    /**
     * Lista las incidencias de un vendedor (origin O reseller).
     *
     * @param int    $vendor_id     User ID del vendedor.
     * @param string $status_filter Filtro por estado ('' = todos).
     * @param int    $limit         Tamaño de página.
     * @param int    $offset        Offset (para paginación).
     * @return array Lista de incidencias (cada una como array asociativo).
     */
    public static function get_vendor_incidents( int $vendor_id, string $status_filter = '', int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_redi_incidents';

        $limit  = max( 1, min( 100, $limit ) );
        $offset = max( 0, $offset );

        $sql = "SELECT id, order_id, origin_vendor_id, reseller_vendor_id, customer_id, type,
                       description, status, sla_due_at, resolution_due_at, resolution_notes,
                       created_by, created_at, updated_at, resolved_at
                FROM `{$table}`
                WHERE (origin_vendor_id = %d OR reseller_vendor_id = %d)";

        $args = [ $vendor_id, $vendor_id ];

        if ( in_array( $status_filter, self::STATUSES, true ) ) {
            $sql .= " AND status = %s";
            $args[] = $status_filter;
        }

        $sql   .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Obtiene el detalle completo de una incidencia (cabecera + comentarios).
     *
     * @param int $incident_id ID de la incidencia.
     * @return array|null Datos de la incidencia o null si no existe.
     */
    public static function get_incident_detail( int $incident_id ): ?array {
        global $wpdb;

        if ( ! $incident_id ) {
            return null;
        }

        $inc_table    = $wpdb->prefix . 'lt_redi_incidents';
        $comm_table   = $wpdb->prefix . 'lt_redi_incident_comments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $incident = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$inc_table}` WHERE id = %d",
                $incident_id
            ),
            ARRAY_A
        );

        if ( ! $incident ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $comments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.incident_id, c.user_id, c.comment, c.created_at,
                        u.display_name AS user_name
                 FROM `{$comm_table}` c
                 LEFT JOIN `{$wpdb->users}` u ON u.ID = c.user_id
                 WHERE c.incident_id = %d
                 ORDER BY c.created_at ASC",
                $incident_id
            ),
            ARRAY_A
        );

        $incident['comments'] = is_array( $comments ) ? $comments : [];

        return $incident;
    }

    // ========================================================================
    // Cron — SLA check
    // ========================================================================

    /**
     * Cron: verifica incidencias que han excedido SLA y las escala.
     *
     * - Si passed > SLA_FIRST_RESPONSE_HOURS sin comentarios → escala a 'escalated'.
     * - Si passed > SLA_RESOLUTION_DAYS sin resolver → escala a 'escalated' y
     *   notifica al administrador del marketplace por email.
     *
     * Ejecutado hourly por wp-cron (hook 'ltms_redi_incident_sla_check').
     *
     * @return void
     */
    public static function sla_check_cron(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_redi_incidents';
        $comm_table = $wpdb->prefix . 'lt_redi_incident_comments';
        $now = LTMS_Utils::now_utc();

        // 1. Incidencias abiertas/investigando con SLA de primera respuesta vencido
        //    Y sin comentarios del admin/origin (sla_due_at < now AND no comments).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $first_response_overdue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.id, i.order_id, i.origin_vendor_id, i.reseller_vendor_id, i.type,
                        i.description, i.sla_due_at, i.resolution_due_at, i.created_at
                 FROM `{$table}` i
                 LEFT JOIN `{$comm_table}` c ON c.incident_id = i.id
                 WHERE i.status IN ('open','investigating')
                   AND i.sla_due_at < %s
                 GROUP BY i.id
                 HAVING COUNT(c.id) = 0",
                $now
            )
        );

        $escalated_count = 0;
        if ( is_array( $first_response_overdue ) ) {
            foreach ( $first_response_overdue as $incident ) {
                self::change_status(
                    (int) $incident->id,
                    'escalated',
                    sprintf(
                        __( 'SLA primera respuesta (%dh) vencido el %s', 'ltms' ),
                        self::SLA_FIRST_RESPONSE_HOURS,
                        $now
                    ),
                    0 // system
                );
                $escalated_count++;
            }
        }

        // 2. Incidencias no resueltas con SLA de resolución vencido.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $resolution_overdue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_id, origin_vendor_id, reseller_vendor_id, type,
                        description, sla_due_at, resolution_due_at, created_at
                 FROM `{$table}`
                 WHERE status NOT IN ('resolved','closed')
                   AND resolution_due_at < %s",
                $now
            )
        );

        $resolution_escalated = 0;
        if ( is_array( $resolution_overdue ) ) {
            foreach ( $resolution_overdue as $incident ) {
                // Si ya está escalated, solo mandar email de recordatorio.
                $current_status = $wpdb->get_var( $wpdb->prepare(
                    "SELECT status FROM `{$table}` WHERE id = %d",
                    (int) $incident->id
                ) );

                if ( 'escalated' !== $current_status ) {
                    self::change_status(
                        (int) $incident->id,
                        'escalated',
                        sprintf(
                            __( 'SLA resolución (%dd) vencido el %s', 'ltms' ),
                            self::SLA_RESOLUTION_DAYS,
                            $now
                        ),
                        0
                    );
                }
                $resolution_escalated++;
            }
        }

        // 3. Email al administrador con el resumen de escalados.
        if ( $escalated_count > 0 || $resolution_escalated > 0 ) {
            self::notify_admin_sla_overdue( $escalated_count, $resolution_escalated, $resolution_overdue );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) && ( $escalated_count > 0 || $resolution_escalated > 0 ) ) {
            LTMS_Core_Logger::info(
                'REDI_INCIDENT_SLA_CHECK',
                sprintf(
                    'SLA cron: %d first-response overdue, %d resolution overdue.',
                    $escalated_count, $resolution_escalated
                )
            );
        }
    }

    // ========================================================================
    // Notificaciones (private helpers)
    // ========================================================================

    /**
     * Notifica a ambos vendedores (origin y reseller) y al admin que se creó
     * una nueva incidencia.
     *
     * @param int   $incident_id ID de la incidencia.
     * @param array $data        Datos de la incidencia.
     * @return void
     */
    private static function notify_incident_created( int $incident_id, array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        $title = sprintf(
            /* translators: %d: incident ID */
            __( 'Nueva incidencia ReDi #%d', 'ltms' ),
            $incident_id
        );
        $message = sprintf(
            /* translators: 1: type, 2: order id, 3: description */
            __( 'Se ha abierto una incidencia (%1$s) para el pedido #%2$d. Descripción: %3$s', 'ltms' ),
            $data['type'],
            $data['order_id'],
            $data['description']
        );

        $recipients = array_filter( [
            $data['origin_vendor_id'],
            $data['reseller_vendor_id'],
        ] );

        foreach ( $recipients as $user_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'user_id'    => $user_id,
                    'type'       => 'redi_incident_created',
                    'channel'    => 'inapp',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => wp_json_encode( [
                        'incident_id' => $incident_id,
                        'order_id'    => $data['order_id'],
                        'type'        => $data['type'],
                    ] ),
                    'is_read'    => 0,
                    'created_at' => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            // AUDIT-REDI-UX-GAPS GAP-8 FIX: email con template HTML si disponible.
            $user = get_userdata( $user_id );
            if ( $user && $user->user_email ) {
                $site_name = get_bloginfo( 'name' );
                $subject   = sprintf( '[%s] ⚠️ %s', $site_name, $title );

                $template_path = defined( 'LTMS_PLUGIN_DIR' )
                    ? LTMS_PLUGIN_DIR . 'templates/emails/email-redi-incident.php'
                    : '';
                $email_body = '';

                if ( $template_path && file_exists( $template_path ) ) {
                    $email_data = [
                        'incident_id' => $incident_id,
                        'order_id'    => $data['order_id'],
                        'type'        => $data['type'],
                        'description' => $data['description'],
                        'sla_due_at'  => gmdate( 'Y-m-d H:i:s', time() + ( self::SLA_FIRST_RESPONSE_HOURS * HOUR_IN_SECONDS ) ),
                        'role'        => $user_id === (int) $data['origin_vendor_id'] ? 'origin' : 'reseller',
                    ];
                    ob_start();
                    include $template_path;
                    $email_body = ob_get_clean();
                }

                if ( empty( $email_body ) ) {
                    $email_body = nl2br( esc_html( $message . "\n\n--\n" . $site_name ) );
                }

                $headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ];
                wp_mail( $user->user_email, $subject, $email_body, $headers );
            }
        }

        // Notificar al admin (canal in-app al primer admin del marketplace).
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
        if ( ! empty( $admins ) ) {
            $admin_id = (int) $admins[0]->ID;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'user_id'    => $admin_id,
                    'type'       => 'redi_incident_created',
                    'channel'    => 'inapp',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => wp_json_encode( [
                        'incident_id' => $incident_id,
                        'order_id'    => $data['order_id'],
                        'type'        => $data['type'],
                    ] ),
                    'is_read'    => 0,
                    'created_at' => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );
        }
    }

    /**
     * Notifica a la otra parte que se agregó un comentario en la incidencia.
     *
     * @param int    $incident_id ID de la incidencia.
     * @param int    $user_id     User ID del autor del comentario.
     * @param string $comment     Texto del comentario.
     * @return void
     */
    private static function notify_incident_comment( int $incident_id, int $user_id, string $comment ): void {
        global $wpdb;
        $inc_table  = $wpdb->prefix . 'lt_redi_incidents';
        $notif_table = $wpdb->prefix . 'lt_notifications';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $incident = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT order_id, origin_vendor_id, reseller_vendor_id FROM `{$inc_table}` WHERE id = %d",
                $incident_id
            )
        );

        if ( ! $incident ) {
            return;
        }

        $title = sprintf(
            /* translators: %d: incident ID */
            __( 'Nuevo comentario en incidencia #%d', 'ltms' ),
            $incident_id
        );
        $message = sprintf(
            /* translators: 1: order id, 2: comment */
            __( 'Pedido #%1$d — Nuevo comentario: %2$s', 'ltms' ),
            $incident->order_id,
            mb_substr( $comment, 0, 200 )
        );

        // Notificar al otro lado (si comenta origin → notificar reseller, y viceversa).
        $recipients = [];
        if ( (int) $incident->origin_vendor_id !== $user_id ) {
            $recipients[] = (int) $incident->origin_vendor_id;
        }
        if ( (int) $incident->reseller_vendor_id && (int) $incident->reseller_vendor_id !== $user_id ) {
            $recipients[] = (int) $incident->reseller_vendor_id;
        }

        foreach ( $recipients as $user_id_to ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $notif_table,
                [
                    'user_id'    => $user_id_to,
                    'type'       => 'redi_incident_comment',
                    'channel'    => 'inapp',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => wp_json_encode( [
                        'incident_id'  => $incident_id,
                        'order_id'     => (int) $incident->order_id,
                        'comment_by'   => $user_id,
                    ] ),
                    'is_read'    => 0,
                    'created_at' => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            $user = get_userdata( $user_id_to );
            if ( $user && $user->user_email ) {
                $site_name = get_bloginfo( 'name' );
                $subject   = sprintf( '[%s] %s', $site_name, $title );
                $body      = $message . "\n\n--\n" . $site_name;
                wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
            }
        }
    }

    /**
     * Notifica a ambas partes del cambio de estado de la incidencia.
     *
     * @param int    $incident_id ID de la incidencia.
     * @param string $new_status  Nuevo estado.
     * @param string $notes       Notas del admin.
     * @param int    $changed_by  User ID del admin.
     * @return void
     */
    private static function notify_incident_status_change( int $incident_id, string $new_status, string $notes, int $changed_by ): void {
        global $wpdb;
        $inc_table  = $wpdb->prefix . 'lt_redi_incidents';
        $notif_table = $wpdb->prefix . 'lt_notifications';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $incident = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT order_id, origin_vendor_id, reseller_vendor_id FROM `{$inc_table}` WHERE id = %d",
                $incident_id
            )
        );

        if ( ! $incident ) {
            return;
        }

        $title = sprintf(
            /* translators: 1: incident id, 2: new status */
            __( 'Incidencia #%1$d actualizada a "%2$s"', 'ltms' ),
            $incident_id, $new_status
        );
        $message = sprintf(
            /* translators: 1: order id, 2: new status, 3: notes */
            __( 'Pedido #%1$d — La incidencia ahora está "%2$s". Notas: %3$s', 'ltms' ),
            $incident->order_id, $new_status, $notes ?: '—'
        );

        $recipients = array_filter( [
            (int) $incident->origin_vendor_id,
            (int) $incident->reseller_vendor_id,
        ] );

        foreach ( $recipients as $user_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $notif_table,
                [
                    'user_id'    => $user_id,
                    'type'       => 'redi_incident_status',
                    'channel'    => 'inapp',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => wp_json_encode( [
                        'incident_id' => $incident_id,
                        'order_id'    => (int) $incident->order_id,
                        'new_status'  => $new_status,
                    ] ),
                    'is_read'    => 0,
                    'created_at' => LTMS_Utils::now_utc(),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            $user = get_userdata( $user_id );
            if ( $user && $user->user_email ) {
                $site_name = get_bloginfo( 'name' );
                $subject   = sprintf( '[%s] %s', $site_name, $title );
                $body      = $message . "\n\n--\n" . $site_name;
                wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
            }
        }
    }

    /**
     * Email al admin con el resumen de incidencias escaladas por SLA vencido.
     *
     * @param int   $first_response_count Número de incidencias con SLA de 1era respuesta vencido.
     * @param int   $resolution_count     Número de incidencias con SLA de resolución vencido.
     * @param array $resolution_overdue   Lista de incidencias vencidas (para incluir en el email).
     * @return void
     */
    private static function notify_admin_sla_overdue( int $first_response_count, int $resolution_count, array $resolution_overdue ): void {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
        if ( empty( $admins ) ) {
            return;
        }

        $admin_email = $admins[0]->user_email;
        $site_name   = get_bloginfo( 'name' );

        $subject = sprintf(
            '[%s] %s',
            $site_name,
            __( 'ReDi: incidencias con SLA vencido', 'ltms' )
        );

        $body  = sprintf( __( "Resumen de incidencias ReDi con SLA vencido:\n\n", 'ltms' ) );
        $body .= sprintf( __( "- %d incidencias sin primera respuesta (>=%dh)\n", 'ltms' ), $first_response_count, self::SLA_FIRST_RESPONSE_HOURS );
        $body .= sprintf( __( "- %d incidencias sin resolver (>=%dd)\n\n", 'ltms' ), $resolution_count, self::SLA_RESOLUTION_DAYS );

        if ( ! empty( $resolution_overdue ) ) {
            $body .= __( "Detalle de incidencias no resueltas a tiempo:\n", 'ltms' );
            foreach ( $resolution_overdue as $inc ) {
                $body .= sprintf(
                    "  - #%d (pedido #%d, tipo=%s, venció el %s)\n",
                    $inc->id, $inc->order_id, $inc->type, $inc->resolution_due_at
                );
            }
        }

        $body .= "\n--\n" . $site_name;

        wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
    }

    // ========================================================================
    // AJAX handlers
    // ========================================================================

    /**
     * AJAX: crea una nueva incidencia ReDi.
     *
     * Requiere nonce 'ltms_dashboard_nonce' y estar logueado como vendedor.
     *
     * @return void (imprime JSON y muere)
     */
    public static function ajax_create_incident(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();

        $order_id   = isset( $_POST['order_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : 0;
        $type       = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $desc_input = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de pedido inválido', 'ltms' ) ], 400 );
        }

        // Determinar origin_vendor_id y reseller_vendor_id a partir del pedido.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado', 'ltms' ) ], 404 );
        }

        $origin_vendor_id   = 0;
        $reseller_vendor_id = 0;

        foreach ( $order->get_items() as $item ) {
            $product_id        = (int) $item->get_product_id();
            $origin_product_id = (int) get_post_meta( $product_id, '_ltms_redi_origin_product_id', true );
            if ( ! $origin_product_id ) {
                continue;
            }
            $origin_vendor_id   = (int) get_post_meta( $product_id, '_ltms_redi_origin_vendor_id', true );
            $reseller_vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
            break;
        }

        if ( ! $origin_vendor_id ) {
            wp_send_json_error( [ 'message' => __( 'El pedido no contiene productos ReDi', 'ltms' ) ], 400 );
        }

        // Capability check: el usuario debe ser origin_vendor, reseller_vendor, o admin.
        $is_admin = current_user_can( 'manage_woocommerce' );
        if ( ! $is_admin && $user_id !== $origin_vendor_id && $user_id !== $reseller_vendor_id ) {
            wp_send_json_error( [ 'message' => __( 'No tiene permisos para abrir una incidencia sobre este pedido', 'ltms' ) ], 403 );
        }

        $result = self::create( [
            'order_id'           => $order_id,
            'origin_vendor_id'   => $origin_vendor_id,
            'reseller_vendor_id' => $reseller_vendor_id,
            'customer_id'        => (int) $order->get_customer_id(),
            'type'               => $type,
            'description'        => $desc_input,
            'created_by'         => $user_id,
        ] );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 400 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: agrega un comentario a una incidencia.
     *
     * @return void
     */
    public static function ajax_add_comment(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $user_id      = get_current_user_id();
        $incident_id  = isset( $_POST['incident_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['incident_id'] ) ) : 0;
        $comment_text = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

        if ( ! $incident_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de incidencia inválido', 'ltms' ) ], 400 );
        }

        // Capability: debe ser origin_vendor, reseller_vendor, o admin.
        $detail = self::get_incident_detail( $incident_id );
        if ( ! $detail ) {
            wp_send_json_error( [ 'message' => __( 'Incidencia no encontrada', 'ltms' ) ], 404 );
        }

        $is_admin = current_user_can( 'manage_woocommerce' );
        if ( ! $is_admin
            && (int) $detail['origin_vendor_id'] !== $user_id
            && (int) $detail['reseller_vendor_id'] !== $user_id ) {
            wp_send_json_error( [ 'message' => __( 'No tiene permisos para comentar en esta incidencia', 'ltms' ) ], 403 );
        }

        $result = self::add_comment( $incident_id, $user_id, $comment_text );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 400 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: lista las incidencias del vendedor actual.
     *
     * @return void
     */
    public static function ajax_get_incidents(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $user_id       = get_current_user_id();
        $status_filter = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        $page          = isset( $_POST['page'] ) ? max( 1, (int) sanitize_text_field( wp_unslash( $_POST['page'] ) ) ) : 1;
        $per_page      = isset( $_POST['per_page'] ) ? max( 1, min( 100, (int) sanitize_text_field( wp_unslash( $_POST['per_page'] ) ) ) ) : 20;
        $offset        = ( $page - 1 ) * $per_page;

        $incidents = self::get_vendor_incidents( $user_id, $status_filter, $per_page, $offset );

        wp_send_json_success( [
            'incidents' => $incidents,
            'page'      => $page,
            'per_page'  => $per_page,
            'count'     => count( $incidents ),
        ] );
    }

    /**
     * AJAX: obtiene el detalle completo de una incidencia.
     *
     * @return void
     */
    public static function ajax_get_incident_detail(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $user_id     = get_current_user_id();
        $incident_id = isset( $_POST['incident_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['incident_id'] ) ) : 0;

        if ( ! $incident_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de incidencia inválido', 'ltms' ) ], 400 );
        }

        $detail = self::get_incident_detail( $incident_id );
        if ( ! $detail ) {
            wp_send_json_error( [ 'message' => __( 'Incidencia no encontrada', 'ltms' ) ], 404 );
        }

        // Capability: debe ser origin, reseller o admin.
        $is_admin = current_user_can( 'manage_woocommerce' );
        if ( ! $is_admin
            && (int) $detail['origin_vendor_id'] !== $user_id
            && (int) $detail['reseller_vendor_id'] !== $user_id ) {
            wp_send_json_error( [ 'message' => __( 'No tiene permisos para ver esta incidencia', 'ltms' ) ], 403 );
        }

        wp_send_json_success( [ 'incident' => $detail ] );
    }

    /**
     * AJAX: admin cambia el estado de una incidencia.
     *
     * Requiere capability 'manage_woocommerce'.
     *
     * @return void
     */
    public static function ajax_admin_change_status(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes (solo administradores)', 'ltms' ) ], 403 );
        }

        $incident_id = isset( $_POST['incident_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['incident_id'] ) ) : 0;
        $new_status  = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
        $notes       = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( ! $incident_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de incidencia inválido', 'ltms' ) ], 400 );
        }

        $result = self::change_status( $incident_id, $new_status, $notes, get_current_user_id() );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 400 );
        }

        wp_send_json_success( $result );
    }
}
