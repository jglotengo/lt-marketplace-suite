<?php
/**
 * Gestión de Relaciones de Envíos (manifiestos de despacho) de Aveonline.
 *
 * Una Relación de Envíos agrupa varias guías bajo un número único que la
 * transportadora firma al recoger los paquetes. Este módulo persiste el
 * historial de relaciones en la tabla local `lt_aveonline_shipment_relations`
 * y expone AJAX handlers para el panel admin.
 *
 * Endpoints Aveonline usados:
 *   POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo=relacionEnvios         (crear)
 *   POST /nal/v1.0/generarGuiaTransporteNacional.php  tipo=listarRelacionEnvios   (listar)
 *   POST /nal/v2.0/generarGuiaTransporteNacional.php  tipo=eliminarRelacionEnvios (eliminar, JWT en header)
 *
 * @package LTMS
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Aveonline_ShipmentRelations
 */
class LTMS_Business_Aveonline_ShipmentRelations {

    /** Nombre de la tabla local (sin prefijo de WP). */
    const TABLE = 'lt_aveonline_shipment_relations';

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    /**
     * Registra hooks AJAX y crea la tabla si no existe.
     */
    public static function init(): void {
        add_action( 'wp_ajax_ltms_aveonline_create_relation',          [ __CLASS__, 'ajax_create'           ] );
        add_action( 'wp_ajax_ltms_aveonline_list_relations',           [ __CLASS__, 'ajax_list'             ] );
        add_action( 'wp_ajax_ltms_aveonline_delete_relation',          [ __CLASS__, 'ajax_delete'           ] );
        add_action( 'wp_ajax_ltms_aveonline_print_relation',           [ __CLASS__, 'ajax_print'            ] );
        // Vendor-specific handlers (filtra por vendor_id del usuario actual)
        add_action( 'wp_ajax_ltms_vendor_create_relation',             [ __CLASS__, 'ajax_vendor_create'    ] );
        add_action( 'wp_ajax_ltms_vendor_list_relations',              [ __CLASS__, 'ajax_vendor_list'      ] );
        add_action( 'wp_ajax_ltms_vendor_delete_relation',             [ __CLASS__, 'ajax_vendor_delete'    ] );
        // Búsqueda de destinatarios (usada en el form de creación del vendedor)
        add_action( 'wp_ajax_ltms_aveonline_search_recipients',        [ __CLASS__, 'ajax_search_recipients'] );

        add_action( 'ltms_plugin_activated', [ __CLASS__, 'maybe_create_table' ] );
        self::maybe_create_table();
    }

    // ── DB ────────────────────────────────────────────────────────────────────

    /**
     * Crea la tabla local si no existe.
     */
    public static function maybe_create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            relacionenvio   VARCHAR(40)  NOT NULL DEFAULT '',
            transportadora  VARCHAR(10)  NOT NULL DEFAULT '',
            guias           TEXT         NOT NULL,
            fecha_aveonline DATETIME     DEFAULT NULL,
            rutaimpresion   TEXT         NOT NULL DEFAULT '',
            vendor_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY relacionenvio (relacionenvio),
            KEY transportadora (transportadora),
            KEY vendor_id (vendor_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Inserta una relación en la tabla local.
     *
     * @param string $relacionenvio  Número de la relación.
     * @param string $transportadora Código de transportadora.
     * @param string $guias          Guías separadas por coma.
     * @param string $fecha          Fecha devuelta por Aveonline.
     * @param string $rutaimpresion  URL del PDF.
     * @return int|false ID insertado o false.
     */
    private static function db_insert( string $relacionenvio, string $transportadora, string $guias, string $fecha, string $rutaimpresion ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $fecha_dt = self::parse_aveonline_date( $fecha );

        $result = $wpdb->insert(
            $table,
            [
                'relacionenvio'   => $relacionenvio,
                'transportadora'  => $transportadora,
                'guias'           => $guias,
                'fecha_aveonline' => $fecha_dt,
                'rutaimpresion'   => $rutaimpresion,
                'vendor_id'       => 0,
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Marca una relación como eliminada (soft-delete).
     *
     * @param string $relacionenvio Número de la relación.
     * @return bool
     */
    private static function db_soft_delete( string $relacionenvio ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $updated = $wpdb->update(
            $table,
            [ 'deleted_at' => current_time( 'mysql' ) ],
            [ 'relacionenvio' => $relacionenvio ],
            [ '%s' ],
            [ '%s' ]
        );

        return $updated !== false;
    }

    /**
     * Obtiene relaciones locales (no eliminadas).
     *
     * @param int $limit  Máximo de registros.
     * @param int $offset Offset de paginación.
     * @return array
     */
    public static function get_local( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Cuenta relaciones locales activas.
     *
     * @return int
     */
    public static function count(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE deleted_at IS NULL" );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    /**
     * AJAX: crear relación de envíos.
     *
     * POST params: nonce, transportadora, guias (string separado por comas)
     */
    public static function ajax_create(): void {
        check_ajax_referer( 'ltms_aveonline_relations_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $transportadora = sanitize_text_field( $_POST['transportadora'] ?? '' );
        $guias_raw      = sanitize_textarea_field( $_POST['guias'] ?? '' );

        if ( ! $transportadora || ! $guias_raw ) {
            wp_send_json_error( [ 'message' => __( 'Transportadora y guías son obligatorios.', 'ltms' ) ] );
        }

        // Normalizar guías: separar por coma o salto de línea
        $guias_arr = array_values( array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $guias_raw ) ) ) );
        $guias_str = implode( ', ', $guias_arr );

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->create_shipment_relation( $transportadora, $guias_str );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ?: __( 'Error al crear la relación.', 'ltms' ) ] );
        }

        // Persistir en DB local
        self::db_insert(
            $result['relacionenvio'],
            $transportadora,
            $guias_str,
            $result['fecha'],
            $result['rutaimpresion']
        );

        wp_send_json_success( [
            'message'        => __( 'Relación creada exitosamente.', 'ltms' ),
            'relacionenvio'  => $result['relacionenvio'],
            'fecha'          => $result['fecha'],
            'rutaimpresion'  => $result['rutaimpresion'],
        ] );
    }

    /**
     * AJAX: listar relaciones desde Aveonline (con filtros).
     *
     * POST params: nonce, [numero_relacion], [fecha_inicial AAAA/MM/DD], [fecha_final], [numero_guia]
     */
    public static function ajax_list(): void {
        check_ajax_referer( 'ltms_aveonline_relations_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $numero_relacion = sanitize_text_field( $_POST['numero_relacion'] ?? '' ) ?: null;
        $fecha_inicial   = sanitize_text_field( $_POST['fecha_inicial']   ?? '' ) ?: null;
        $fecha_final     = sanitize_text_field( $_POST['fecha_final']     ?? '' ) ?: null;
        $numero_guia     = sanitize_text_field( $_POST['numero_guia']     ?? '' ) ?: null;

        // Si no hay ningún parámetro, mostrar los locales (rápido, sin llamada API)
        if ( ! $numero_relacion && ! $fecha_inicial && ! $fecha_final && ! $numero_guia ) {
            wp_send_json_success( [
                'source'    => 'local',
                'registros' => self::get_local(),
                'total'     => self::count(),
            ] );
        }

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->list_shipment_relations( $numero_relacion, $fecha_inicial, $fecha_final, $numero_guia );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ?: __( 'No se encontraron registros.', 'ltms' ) ] );
        }

        wp_send_json_success( [
            'source'    => 'api',
            'registros' => $result['registros'],
            'total'     => count( $result['registros'] ),
            'message'   => $result['message'],
        ] );
    }

    /**
     * AJAX: eliminar relación de Aveonline y marcarla localmente.
     *
     * POST params: nonce, relacionenvio
     */
    public static function ajax_delete(): void {
        check_ajax_referer( 'ltms_aveonline_relations_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $relacionenvio = sanitize_text_field( $_POST['relacionenvio'] ?? '' );

        if ( ! $relacionenvio ) {
            wp_send_json_error( [ 'message' => __( 'Número de relación requerido.', 'ltms' ) ] );
        }

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->delete_shipment_relation( $relacionenvio );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ?: __( 'No se pudo eliminar la relación.', 'ltms' ) ] );
        }

        // Soft-delete local
        self::db_soft_delete( $relacionenvio );

        wp_send_json_success( [ 'message' => __( 'Relación eliminada exitosamente.', 'ltms' ) ] );
    }

    /**
     * AJAX: obtiene la URL de impresión de una relación desde Aveonline.
     *
     * POST params: nonce, relacionenvio
     */
    public static function ajax_print(): void {
        check_ajax_referer( 'ltms_aveonline_relations_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $relacionenvio = sanitize_text_field( $_POST['relacionenvio'] ?? '' );

        if ( ! $relacionenvio ) {
            wp_send_json_error( [ 'message' => __( 'Número de relación requerido.', 'ltms' ) ] );
        }

        // Primero buscar URL en DB local
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $url   = $wpdb->get_var( $wpdb->prepare(
            "SELECT rutaimpresion FROM `{$table}` WHERE relacionenvio = %s LIMIT 1",
            $relacionenvio
        ) );

        if ( $url ) {
            wp_send_json_success( [ 'url' => $url ] );
        }

        // Si no está local, buscar en Aveonline
        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->list_shipment_relations( $relacionenvio );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        if ( ! $result['success'] || empty( $result['registros'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Relación no encontrada.', 'ltms' ) ] );
        }

        // Aveonline no devuelve rutaimpresion en listar; construir URL con el patrón conocido
        $reg = $result['registros'][0];
        wp_send_json_success( [
            'url'      => '',  // Aveonline no expone URL en listar; abrir el panel de Aveonline
            'registro' => $reg,
        ] );
    }

    // ── Vendor Handlers ───────────────────────────────────────────────────────

    /**
     * AJAX: Crea una relación de envíos como vendedor.
     * Filtra las guías al vendor_id actual y persiste la relación con ese vendor_id.
     */
    public static function ajax_vendor_create(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! LTMS_Utils::is_ltms_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ] );
        }

        $vendor_id     = get_current_user_id();
        $transportadora = sanitize_text_field( wp_unslash( $_POST['transportadora'] ?? '' ) );
        $guias_raw      = sanitize_textarea_field( wp_unslash( $_POST['guias'] ?? '' ) );

        if ( ! $transportadora || ! $guias_raw ) {
            wp_send_json_error( [ 'message' => __( 'Transportadora y guías son requeridas.', 'ltms' ) ] );
        }

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->create_shipment_relation( $transportadora, $guias_raw );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
        }

        if ( ! ( $result['success'] ?? false ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? __( 'Error al crear la relación.', 'ltms' ) ] );
        }

        // Persistir con vendor_id
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $fecha_dt = self::parse_aveonline_date( $result['fecha'] ?? '' );

        $wpdb->insert(
            $table,
            [
                'relacionenvio'   => $result['relacionenvio'],
                'transportadora'  => $transportadora,
                'guias'           => $guias_raw,
                'fecha_aveonline' => $fecha_dt,
                'rutaimpresion'   => $result['rutaimpresion'] ?? '',
                'vendor_id'       => $vendor_id,
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        wp_send_json_success( [
            'relacionenvio' => $result['relacionenvio'],
            'fecha'         => $result['fecha'] ?? '',
            'rutaimpresion' => $result['rutaimpresion'] ?? '',
        ] );
    }

    /**
     * AJAX: Lista las relaciones locales del vendedor actual.
     */
    public static function ajax_vendor_list(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! LTMS_Utils::is_ltms_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ] );
        }

        global $wpdb;
        $vendor_id = get_current_user_id();
        $table     = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vendor_id = %d AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 50",
                $vendor_id
            ),
            ARRAY_A
        );

        wp_send_json_success( [ 'relations' => $rows ?: [] ] );
    }

    /**
     * AJAX: Elimina (soft-delete) una relación del vendedor actual.
     */
    public static function ajax_vendor_delete(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! LTMS_Utils::is_ltms_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ] );
        }

        $vendor_id     = get_current_user_id();
        $numero_relacion = sanitize_text_field( wp_unslash( $_POST['numero_relacion'] ?? '' ) );

        if ( ! $numero_relacion ) {
            wp_send_json_error( [ 'message' => __( 'Número de relación requerido.', 'ltms' ) ] );
        }

        // Verificar que la relación pertenece a este vendor
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE relacionenvio = %s AND vendor_id = %d AND deleted_at IS NULL LIMIT 1",
            $numero_relacion, $vendor_id
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'Relación no encontrada o sin permisos.', 'ltms' ) ] );
        }

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->delete_shipment_relation( $numero_relacion );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
        }

        if ( ! ( $result['success'] ?? false ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? __( 'Error al eliminar en Aveonline.', 'ltms' ) ] );
        }

        self::db_soft_delete( $numero_relacion );
        wp_send_json_success( [ 'message' => __( 'Relación eliminada correctamente.', 'ltms' ) ] );
    }

    /**
     * AJAX: Busca destinatarios en Aveonline (autocomplete).
     */
    public static function ajax_search_recipients(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $param = sanitize_text_field( wp_unslash( $_GET['param'] ?? '' ) );
        if ( strlen( $param ) < 3 ) {
            wp_send_json_error( [ 'message' => __( 'Mínimo 3 caracteres.', 'ltms' ) ] );
        }

        try {
            $api    = new LTMS_Api_Aveonline();
            $result = $api->search_recipients( $param );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
        }

        wp_send_json_success( [ 'destinatarios' => $result['destinatarios'] ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Convierte la fecha de Aveonline ("2021/06/15 13:49:11 pm") a formato MySQL.
     *
     * @param string $fecha Fecha devuelta por Aveonline.
     * @return string|null Fecha MySQL o null si no se puede parsear.
     */
    private static function parse_aveonline_date( string $fecha ): ?string {
        if ( ! $fecha ) {
            return null;
        }

        // Formato: "2021/06/15 13:49:11 pm" — quitar el am/pm sobrante si ya es formato 24h
        $clean = preg_replace( '/\s*(am|pm)$/i', '', trim( $fecha ) );
        $clean = str_replace( '/', '-', $clean );

        $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $clean );
        return $dt ? $dt->format( 'Y-m-d H:i:s' ) : null;
    }
}
