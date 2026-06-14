<?php
/**
 * Lógica de negocio: Órdenes de Compra Aveonline
 *
 * Persiste OC generadas en la tabla local `lt_aveonline_ordenes_compra`
 * y expone handlers AJAX para el dashboard del vendedor:
 *
 *  - ltms_aveonline_oc_proveedores  → lista proveedores de Aveonline
 *  - ltms_aveonline_oc_generar      → genera OC en Aveonline y persiste
 *  - ltms_aveonline_oc_mis_ordenes  → lista OC del vendedor desde tabla local
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Business_Aveonline_OrdenCompra {

    const TABLE        = 'lt_aveonline_ordenes_compra';
    const TABLE_DETAIL = 'lt_aveonline_ordenes_compra_detalle';
    const ENDPOINT     = '/nal/v2.0/ordendeCompra.php';

    // ── Inicialización ────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'ltms_plugin_activated', [ __CLASS__, 'maybe_create_tables' ] );
        self::maybe_create_tables();

        add_action( 'wp_ajax_ltms_aveonline_oc_proveedores', [ __CLASS__, 'ajax_proveedores'  ] );
        add_action( 'wp_ajax_ltms_aveonline_oc_generar',     [ __CLASS__, 'ajax_generar'      ] );
        add_action( 'wp_ajax_ltms_aveonline_oc_mis_ordenes', [ __CLASS__, 'ajax_mis_ordenes'  ] );
    }

    // ── Tablas DB ─────────────────────────────────────────────────────────────

    public static function maybe_create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $table_oc = $wpdb->prefix . self::TABLE;
        $sql_oc   = "CREATE TABLE IF NOT EXISTS `{$table_oc}` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ordencompra     VARCHAR(60)  NOT NULL DEFAULT '',
            idproveedor     INT          NOT NULL DEFAULT 0,
            nombreproveedor VARCHAR(120) NOT NULL DEFAULT '',
            idtransportador VARCHAR(10)  NOT NULL DEFAULT '',
            modoenvio       TINYINT      NOT NULL DEFAULT 1,
            estado          VARCHAR(30)  NOT NULL DEFAULT 'GENERADA',
            mensaje         TEXT         NOT NULL DEFAULT '',
            aveonline_resp  TEXT         NOT NULL DEFAULT '',
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ordencompra (ordencompra),
            KEY vendor_id (vendor_id),
            KEY estado (estado),
            KEY created_at (created_at)
        ) {$charset};";

        $table_det = $wpdb->prefix . self::TABLE_DETAIL;
        $sql_det   = "CREATE TABLE IF NOT EXISTS `{$table_det}` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            oc_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pedido          VARCHAR(40)  NOT NULL DEFAULT '',
            plu             VARCHAR(40)  NOT NULL DEFAULT '',
            ean             VARCHAR(40)  NOT NULL DEFAULT '',
            referencia      VARCHAR(80)  NOT NULL DEFAULT '',
            nombre_articulo VARCHAR(200) NOT NULL DEFAULT '',
            cantidad        INT          NOT NULL DEFAULT 1,
            precio          DECIMAL(12,2) NOT NULL DEFAULT 0,
            total           DECIMAL(12,2) NOT NULL DEFAULT 0,
            valoracion      DECIMAL(12,2) NOT NULL DEFAULT 0,
            cliente         VARCHAR(200) NOT NULL DEFAULT '',
            ciudad          VARCHAR(80)  NOT NULL DEFAULT '',
            departamento    VARCHAR(80)  NOT NULL DEFAULT '',
            direccion       VARCHAR(200) NOT NULL DEFAULT '',
            tel             VARCHAR(20)  NOT NULL DEFAULT '',
            correo          VARCHAR(120) NOT NULL DEFAULT '',
            peso            DECIMAL(8,3) NOT NULL DEFAULT 0,
            codigo_dane     VARCHAR(10)  NOT NULL DEFAULT '',
            guia            VARCHAR(40)  NOT NULL DEFAULT '',
            estado_linea    VARCHAR(30)  NOT NULL DEFAULT 'PENDIENTE',
            PRIMARY KEY (id),
            KEY oc_id (oc_id),
            KEY pedido (pedido)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_oc );
        dbDelta( $sql_det );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function check_nonce(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'No autenticado.' ], 401 );
        }
    }

    private static function get_token(): string {
        $cached = get_transient( 'ltms_aveonline_jwt' );
        if ( $cached ) {
            return $cached;
        }
        if ( class_exists( 'LTMS_Api_Aveonline' ) ) {
            $api = new LTMS_Api_Aveonline();
            $api->health_check();
        }
        return (string) get_transient( 'ltms_aveonline_jwt' );
    }

    private static function get_idempresa(): int {
        return (int) LTMS_Core_Config::get( 'ltms_aveonline_idempresa', 0 );
    }

    private static function api_post( string $payload_json ): array {
        $api_base = defined( 'LTMS_ENVIRONMENT' ) && LTMS_ENVIRONMENT === 'production'
            ? 'https://app.aveonline.co/api'
            : 'https://sandbox.aveonline.co/api';

        $url = $api_base . self::ENDPOINT;

        $raw = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => $payload_json,
            'timeout' => 30,
        ] );

        if ( is_wp_error( $raw ) ) {
            throw new \RuntimeException( 'Aveonline OC HTTP error: ' . $raw->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $raw ), true );
        return is_array( $body ) ? $body : [];
    }

    // ── AJAX: Listar proveedores ──────────────────────────────────────────────

    public static function ajax_proveedores(): void {
        self::check_nonce();

        $cached = get_transient( 'ltms_aveonline_oc_proveedores' );
        if ( false !== $cached ) {
            wp_send_json_success( [ 'proveedores' => $cached ] );
        }

        try {
            $token     = self::get_token();
            $idempresa = self::get_idempresa();

            $body = self::api_post( wp_json_encode( [
                'tipo'      => 'listarproveedores',
                'token'     => $token,
                'idempresa' => $idempresa,
            ] ) );

            if ( ( $body['status'] ?? '' ) !== 'ok' ) {
                wp_send_json_error( [ 'message' => $body['message'] ?? 'Error al obtener proveedores.' ] );
            }

            $proveedores = $body['agentes'] ?? $body['proveedores'] ?? [];
            set_transient( 'ltms_aveonline_oc_proveedores', $proveedores, HOUR_IN_SECONDS );

            wp_send_json_success( [ 'proveedores' => $proveedores ] );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Generar OC ──────────────────────────────────────────────────────

    public static function ajax_generar(): void {
        self::check_nonce();

        $vendor_id       = get_current_user_id();
        $ordencompra     = sanitize_text_field( $_POST['ordencompra']     ?? '' ); // phpcs:ignore
        $idproveedor     = (int) ( $_POST['idproveedor']     ?? 0 );               // phpcs:ignore
        $idtransportador = sanitize_text_field( $_POST['idtransportador'] ?? '' ); // phpcs:ignore
        $modoenvio       = (int) ( $_POST['modoenvio']       ?? 1 );               // phpcs:ignore
        $detalle_raw     = wp_unslash( $_POST['detalle']     ?? '[]' );            // phpcs:ignore
        $idagente        = sanitize_text_field( $_POST['idagente']        ?? '' ); // phpcs:ignore

        if ( ! $ordencompra || ! $idproveedor ) {
            wp_send_json_error( [ 'message' => 'Número de OC e ID de proveedor son requeridos.' ] );
        }

        $detalle = json_decode( $detalle_raw, true );
        if ( ! is_array( $detalle ) || empty( $detalle ) ) {
            wp_send_json_error( [ 'message' => 'Debe incluir al menos una línea de detalle.' ] );
        }

        $detalle_clean = [];
        foreach ( $detalle as $linea ) {
            $detalle_clean[] = [
                'pedido'               => sanitize_text_field( $linea['pedido']               ?? '' ),
                'fecha_min'            => sanitize_text_field( $linea['fecha_min']            ?? '' ),
                'fecha_max'            => sanitize_text_field( $linea['fecha_max']            ?? '' ),
                'plu'                  => sanitize_text_field( $linea['plu']                  ?? '' ),
                'ean'                  => sanitize_text_field( $linea['ean']                  ?? '' ),
                'referencia'           => sanitize_text_field( $linea['referencia']           ?? '' ),
                'nombre_articulo'      => sanitize_text_field( $linea['nombre_articulo']      ?? '' ),
                'descripcion'          => sanitize_text_field( $linea['descripcion']          ?? '' ),
                'cantidad_solicitada'  => sanitize_text_field( $linea['cantidad_solicitada']  ?? '1' ),
                'precio'               => (string) (int) ( $linea['precio']      ?? 0 ),
                'total'                => (string) (int) ( $linea['total']       ?? 0 ),
                'valoracion'           => (string) (int) ( $linea['valoracion']  ?? 0 ),
                'cliente'              => sanitize_text_field( $linea['cliente']              ?? '' ),
                'puntoventa'           => sanitize_text_field( $linea['puntoventa']           ?? '' ),
                'ciudad'               => sanitize_text_field( $linea['ciudad']               ?? '' ),
                'departamento'         => sanitize_text_field( $linea['departamento']         ?? '' ),
                'direccion'            => sanitize_text_field( $linea['direccion']            ?? '' ),
                'tel'                  => sanitize_text_field( $linea['tel']                  ?? '' ),
                'correo'               => sanitize_email(      $linea['correo']               ?? '' ),
                'observaciones'        => sanitize_text_field( $linea['observaciones']        ?? '' ),
                'peso'                 => (string) (int) ( $linea['peso']        ?? 1 ),
                'alto'                 => (string) (int) ( $linea['alto']        ?? 10 ),
                'largo'                => (string) (int) ( $linea['largo']       ?? 20 ),
                'ancho'                => (string) (int) ( $linea['ancho']       ?? 30 ),
                'cartaporte'           => sanitize_text_field( $linea['cartaporte']           ?? '' ),
                'campana'              => sanitize_text_field( $linea['campana']              ?? '' ),
                'guia'                 => sanitize_text_field( $linea['guia']                 ?? '' ),
                'factura'              => sanitize_text_field( $linea['factura']              ?? '' ),
                'fecha_redencion'      => sanitize_text_field( $linea['fecha_redencion']      ?? '' ),
                'codigo_dane'          => sanitize_text_field( $linea['codigo_dane']          ?? '' ),
            ];
        }

        try {
            $token     = self::get_token();
            $idempresa = self::get_idempresa();

            $payload = [
                'tipo'        => 'generarorden',
                'token'       => $token,
                'idempresa'   => $idempresa,
                'idproveedor' => $idproveedor,
                'ordencompra' => $ordencompra,
                'detalle'     => $detalle_clean,
            ];

            if ( $idtransportador ) { $payload['idtransportador'] = $idtransportador; }
            if ( $modoenvio )       { $payload['modoenvio']       = $modoenvio; }
            if ( $idagente )        { $payload['idagente']        = $idagente; }

            $body   = self::api_post( wp_json_encode( $payload ) );
            $success = ( $body['status'] ?? '' ) === 'ok';
            $mensaje = $body['message'] ?? '';

            // Nombre del proveedor desde caché
            $nombreproveedor = '';
            $prov_cache = get_transient( 'ltms_aveonline_oc_proveedores' );
            if ( is_array( $prov_cache ) ) {
                foreach ( $prov_cache as $p ) {
                    if ( (int) ( $p['idproveedor'] ?? 0 ) === $idproveedor ) {
                        $nombreproveedor = $p['nombreproveedor'] ?? '';
                        break;
                    }
                }
            }

            global $wpdb;
            $table_oc = $wpdb->prefix . self::TABLE;

            $wpdb->replace( $table_oc, [
                'vendor_id'       => $vendor_id,
                'ordencompra'     => $ordencompra,
                'idproveedor'     => $idproveedor,
                'nombreproveedor' => $nombreproveedor,
                'idtransportador' => $idtransportador,
                'modoenvio'       => $modoenvio,
                'estado'          => $success ? 'GENERADA' : 'ERROR',
                'mensaje'         => sanitize_text_field( $mensaje ),
                'aveonline_resp'  => wp_json_encode( $body['codigo'] ?? [] ),
            ], [ '%d','%s','%d','%s','%s','%d','%s','%s','%s' ] );

            $oc_id = (int) $wpdb->insert_id;

            if ( $success && $oc_id ) {
                $table_det = $wpdb->prefix . self::TABLE_DETAIL;
                foreach ( $detalle_clean as $linea ) {
                    $wpdb->insert( $table_det, [
                        'oc_id'          => $oc_id,
                        'pedido'         => $linea['pedido'],
                        'plu'            => $linea['plu'],
                        'ean'            => $linea['ean'],
                        'referencia'     => $linea['referencia'],
                        'nombre_articulo'=> $linea['nombre_articulo'],
                        'cantidad'       => (int) $linea['cantidad_solicitada'],
                        'precio'         => (float) $linea['precio'],
                        'total'          => (float) $linea['total'],
                        'valoracion'     => (float) $linea['valoracion'],
                        'cliente'        => $linea['cliente'],
                        'ciudad'         => $linea['ciudad'],
                        'departamento'   => $linea['departamento'],
                        'direccion'      => $linea['direccion'],
                        'tel'            => $linea['tel'],
                        'correo'         => $linea['correo'],
                        'peso'           => (float) $linea['peso'],
                        'codigo_dane'    => $linea['codigo_dane'],
                        'guia'           => $linea['guia'],
                        'estado_linea'   => 'PENDIENTE',
                    ], [ '%d','%s','%s','%s','%s','%s','%d','%f','%f','%f','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s' ] );
                }
            }

            if ( $success ) {
                wp_send_json_success( [
                    'message'     => $mensaje,
                    'ordencompra' => $ordencompra,
                    'codigo'      => $body['codigo'] ?? [],
                ] );
            } else {
                wp_send_json_error( [
                    'message' => $mensaje ?: 'Error al generar la orden de compra.',
                    'codigo'  => $body['codigo'] ?? [],
                ] );
            }

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Mis órdenes ─────────────────────────────────────────────────────

    public static function ajax_mis_ordenes(): void {
        self::check_nonce();

        global $wpdb;
        $vendor_id = get_current_user_id();
        $table     = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 100",
                $vendor_id
            ),
            ARRAY_A
        );

        $table_det = $wpdb->prefix . self::TABLE_DETAIL;
        foreach ( $rows as &$row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row['detalle'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table_det}` WHERE oc_id = %d",
                    (int) $row['id']
                ),
                ARRAY_A
            );
        }
        unset( $row );

        wp_send_json_success( [ 'ordenes' => $rows ?: [] ] );
    }
}
