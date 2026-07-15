<?php
/**
 * Webhook handler: Estados de guía — Aveonline (schema v2 / "webhook personalizado")
 *
 * Aveonline envía un POST a la URL registrada en "Mis integraciones → Webhook personalizado"
 * cada vez que una guía cambia de estado. Este handler:
 *
 *  1. Valida el token de la integración contra `ltms_aveonline_webhook_token`.
 *  2. Persiste todos los estados recibidos en `lt_aveonline_tracking_events`.
 *  3. Actualiza el meta del pedido WooCommerce (_ltms_shipping_status, etc.).
 *  4. Dispara acciones LTMS según el estado semántico (entregada, devuelta, en tránsito).
 *  5. Guarda `guiadigitalizada` y `fechaentrega` en el pedido cuando llegan.
 *
 * Schema de entrada (Aveonline webhook personalizado):
 * {
 *   "token":               "000000^cZtUEYw",
 *   "guia":                "1112016134910111",
 *   "pedido_id":           "2222281115431432",
 *   "numeropedidoExterno": "00000",
 *   "estado": [
 *     {
 *       "estado_id":              "12",
 *       "nombre_estado":          "Entregada",
 *       "fechacreacion":          "2023/06/02 12:37:58 pm",
 *       "fechanovedad":           "2023-12-02 16:04:06",
 *       "comentarionovedad":      "DESTINATARIO SE TRASLADO",
 *       "complementariosnovedad": "13:21 : NOMBRE CONFIRMA...",
 *       "tiponovedad":            "SC"
 *     }
 *   ],
 *   "guiadigitalizada": "",
 *   "fechaentrega":     "2023/06/02 10:01:00 am"
 * }
 *
 * Respuesta esperada por Aveonline:
 *   success → { "success": true,  "messages": "Proceso realizado" }
 *   error   → { "success": false, "messages": "..." }
 *
 * Endpoint REST: POST /wp-json/ltms/v1/webhooks/aveonline
 * URL webhook a registrar en Aveonline: {site_url}/wp-json/ltms/v1/webhooks/aveonline
 *
 * @package LTMS
 * @version 2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Aveonline_Webhook_Handler {

    const TABLE_EVENTS = 'lt_aveonline_tracking_events';

    // Mapa semántico de estado_id → acción LTMS (basado en doc oficial Aveonline)
    const ESTADO_MAP = [
        // Entregadas
        '12'  => 'delivered',
        '9'   => 'delivered',   // Entregada con novedad
        // En tránsito / reparto
        '1'   => 'in_transit',
        '3'   => 'in_transit',  // En reparto
        '5'   => 'in_transit',  // Recogida por transportadora
        '6'   => 'in_transit',  // En bodega origen
        '7'   => 'in_transit',  // En bodega destino
        '10'  => 'in_transit',  // En novedad
        '11'  => 'in_transit',  // En gestión
        // Devueltas / canceladas
        '2'   => 'failed',
        '4'   => 'failed',      // Anulada
        '8'   => 'failed',      // Devuelta
        '13'  => 'failed',      // No entregada
        '14'  => 'failed',      // Cancelada
    ];

    // Palabras clave en nombre_estado para clasificación por texto cuando estado_id no coincide
    const NOMBRE_DELIVERED = [ 'ENTREGADA', 'ENTREGADO' ];
    const NOMBRE_FAILED    = [ 'DEVUELTA', 'DEVUELTO', 'ANULADA', 'CANCELADA', 'NO ENTREGADA' ];
    const NOMBRE_TRANSIT   = [ 'EN TRANSITO', 'EN TRÁNSITO', 'EN REPARTO', 'EN NOVEDAD', 'EN GESTION', 'EN BODEGA', 'RECOGIDA' ];

    // ── Inicialización ────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'ltms_plugin_activated', [ __CLASS__, 'maybe_create_table' ] );
        self::maybe_create_table();
    }

    // ── Tabla de eventos de tracking ──────────────────────────────────────────

    public static function maybe_create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_EVENTS;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            guia                   VARCHAR(60)  NOT NULL DEFAULT '',
            pedido_id              VARCHAR(60)  NOT NULL DEFAULT '',
            numero_pedido_externo  VARCHAR(60)  NOT NULL DEFAULT '',
            order_id               BIGINT UNSIGNED NOT NULL DEFAULT 0,
            estado_id              VARCHAR(10)  NOT NULL DEFAULT '',
            nombre_estado          VARCHAR(120) NOT NULL DEFAULT '',
            fecha_creacion         VARCHAR(40)  NOT NULL DEFAULT '',
            fecha_novedad          VARCHAR(40)  NOT NULL DEFAULT '',
            comentario_novedad     TEXT         NOT NULL DEFAULT '',
            complementarios        TEXT         NOT NULL DEFAULT '',
            tipo_novedad           VARCHAR(20)  NOT NULL DEFAULT '',
            guia_digitalizada      VARCHAR(500) NOT NULL DEFAULT '',
            fecha_entrega          VARCHAR(40)  NOT NULL DEFAULT '',
            accion_semantica       VARCHAR(20)  NOT NULL DEFAULT '',
            payload_raw            LONGTEXT     NOT NULL DEFAULT '',
            received_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY guia        (guia),
            KEY order_id    (order_id),
            KEY estado_id   (estado_id),
            KEY received_at (received_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Handler principal ─────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        // API-BUG-19 FIX: per-IP rate limit (max 100 webhooks/min) — protects DB.
        // Applied before token check to protect against DoS from invalid webhooks.
        $rate_key = 'ltms_wh_rate_' . md5( self::client_ip() );
        $count    = (int) get_transient( $rate_key );
        if ( $count > 100 ) {
            return new WP_REST_Response(
                [ 'success' => false, 'messages' => 'Too many requests' ],
                429
            );
        }
        set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

        // AO-BUG-12 FIX: IP allowlist (regression of M-9). AveOnline source IPs
        // are configurable via `ltms_aveonline_webhook_ips` (CSV). If configured,
        // reject any request from an unlisted IP. If not configured, log a
        // warning and continue (fail-open by config, but visible to admins).
        $client_ip   = self::client_ip();
        $allowlist   = trim( (string) ( method_exists( 'LTMS_Core_Config', 'get' ) ? LTMS_Core_Config::get( 'ltms_aveonline_webhook_ips', '' ) : get_option( 'ltms_aveonline_webhook_ips', '' ) ) );
        if ( $allowlist !== '' ) {
            $allowed_ips = array_filter( array_map( 'trim', explode( ',', $allowlist ) ) );
            if ( ! in_array( $client_ip, $allowed_ips, true ) ) {
                self::log_warning( 'IP_NOT_ALLOWED', "ip={$client_ip}" );
                return new WP_REST_Response(
                    [ 'success' => false, 'messages' => 'Origen no permitido.' ],
                    403
                );
            }
        } else {
            self::log_warning( 'IP_ALLOWLIST_EMPTY', 'ltms_aveonline_webhook_ips no configurado; saltando verificación de IP.' );
        }

        // AO-BUG-2 FIX: event-level idempotency. AveOnline retries the same
        // webhook on timeout. Without dedup, retries produce duplicate rows in
        // lt_aveonline_tracking_events and re-fire `ltms_shipping_delivered`
        // (double payouts). We compute a deterministic event_id from the payload
        // and gate processing on a 1-hour transient.
        $event_id  = isset( $body['event_id'] ) && $body['event_id'] !== ''
            ? (string) $body['event_id']
            : md5( wp_json_encode( [
                'guia'   => $body['guia'] ?? '',
                'estado' => $body['estado'] ?? [],
                'fecha'  => $body['fechaentrega'] ?? '',
            ] ) );
        $cache_key = 'ltms_ave_wh_seen_' . md5( $event_id );
        if ( get_transient( $cache_key ) ) {
            return new WP_REST_Response(
                [ 'success' => true, 'messages' => 'Evento ya procesado.' ],
                200
            );
        }
        set_transient( $cache_key, true, HOUR_IN_SECONDS );

        // ── 1. Validar token ──────────────────────────────────────────────────
        // AO-BUG-1 FIX: fail-closed when the configured token is empty AND use
        // hash_equals() for timing-safe comparison (prevents timing attacks on
        // the token). Previously, an empty option made the check pass for any
        // payload, allowing forged delivery webhooks.
        $token_recibido  = sanitize_text_field( (string) ( $body['token'] ?? '' ) );
        $token_esperado  = trim( (string) get_option( 'ltms_aveonline_webhook_token', '' ) );

        if ( $token_esperado === '' ) {
            self::log_warning( 'TOKEN_NOT_CONFIGURED', 'ltms_aveonline_webhook_token vacío — fail-closed.' );
            return new WP_REST_Response(
                [ 'success' => false, 'messages' => 'Webhook no configurado.' ],
                403
            );
        }

        if ( ! hash_equals( $token_esperado, $token_recibido ) ) {
            self::log_warning( 'TOKEN_MISMATCH', "recibido={$token_recibido}" );
            return new WP_REST_Response(
                [ 'success' => false, 'messages' => 'Token inválido.' ],
                401
            );
        }

        // ── 2. Extraer campos del payload ─────────────────────────────────────
        $guia                   = sanitize_text_field( (string) ( $body['guia']                ?? '' ) );
        $pedido_id_raw          = sanitize_text_field( (string) ( $body['pedido_id']           ?? '' ) );
        $numero_pedido_externo  = sanitize_text_field( (string) ( $body['numeropedidoExterno'] ?? '' ) );
        $estados                = $body['estado'] ?? [];
        $guia_digitalizada      = esc_url_raw( (string) ( $body['guiadigitalizada'] ?? '' ) );
        $fecha_entrega          = sanitize_text_field( (string) ( $body['fechaentrega']         ?? '' ) );

        if ( ! $guia && ! $pedido_id_raw ) {
            return new WP_REST_Response(
                [ 'success' => false, 'messages' => 'No hay numero de guia valido o la empresa no posee webhook' ],
                400
            );
        }

        if ( empty( $estados ) || ! is_array( $estados ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'messages' => 'Payload sin array de estados.' ],
                400
            );
        }

        // ── 3. Resolver order_id de WooCommerce ───────────────────────────────
        $order_id = self::resolve_order_id( $pedido_id_raw, $numero_pedido_externo, $guia );

        // ── 4. Tomar el estado más reciente (último elemento del array) ────────
        $last = end( $estados );
        $estado_id     = sanitize_text_field( (string) ( $last['estado_id']              ?? '' ) );
        $nombre_estado = sanitize_text_field( (string) ( $last['nombre_estado']          ?? '' ) );
        $fecha_creacion= sanitize_text_field( (string) ( $last['fechacreacion']          ?? '' ) );
        $fecha_novedad = sanitize_text_field( (string) ( $last['fechanovedad']           ?? '' ) );
        $comentario    = sanitize_textarea_field( (string) ( $last['comentarionovedad']     ?? '' ) );
        $complementarios= sanitize_textarea_field( (string) ( $last['complementariosnovedad'] ?? '' ) );
        $tipo_novedad  = sanitize_text_field( (string) ( $last['tiponovedad']            ?? '' ) );

        $accion = self::resolve_accion( $estado_id, $nombre_estado );

        // ── 5. Persistir eventos (todos los estados del array) ────────────────
        global $wpdb;
        $table_events = $wpdb->prefix . self::TABLE_EVENTS;

        foreach ( $estados as $est ) {
            $wpdb->insert( $table_events, [
                'guia'                  => $guia,
                'pedido_id'             => $pedido_id_raw,
                'numero_pedido_externo' => $numero_pedido_externo,
                'order_id'              => $order_id,
                'estado_id'             => sanitize_text_field( (string) ( $est['estado_id']              ?? '' ) ),
                'nombre_estado'         => sanitize_text_field( (string) ( $est['nombre_estado']          ?? '' ) ),
                'fecha_creacion'        => sanitize_text_field( (string) ( $est['fechacreacion']          ?? '' ) ),
                'fecha_novedad'         => sanitize_text_field( (string) ( $est['fechanovedad']           ?? '' ) ),
                'comentario_novedad'    => sanitize_textarea_field( (string) ( $est['comentarionovedad']     ?? '' ) ),
                'complementarios'       => sanitize_textarea_field( (string) ( $est['complementariosnovedad'] ?? '' ) ),
                'tipo_novedad'          => sanitize_text_field( (string) ( $est['tiponovedad']            ?? '' ) ),
                'guia_digitalizada'     => $guia_digitalizada,
                'fecha_entrega'         => $fecha_entrega,
                'accion_semantica'      => self::resolve_accion(
                    sanitize_text_field( (string) ( $est['estado_id']     ?? '' ) ),
                    sanitize_text_field( (string) ( $est['nombre_estado'] ?? '' ) )
                ),
                'payload_raw'           => wp_json_encode( $body ),
            ], [ '%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ] );
        }

        // ── 6. Actualizar pedido WooCommerce ──────────────────────────────────
        if ( $order_id ) {
            self::update_order( $order_id, $guia, $estado_id, $nombre_estado,
                $fecha_creacion, $fecha_novedad, $comentario, $tipo_novedad,
                $guia_digitalizada, $fecha_entrega, $accion );
        }

        // ── 7. Log ────────────────────────────────────────────────────────────
        self::log_info(
            "guia={$guia} estado_id={$estado_id} nombre={$nombre_estado} accion={$accion} order_id={$order_id}"
        );

        return new WP_REST_Response(
            [ 'success' => true, 'messages' => 'Proceso realizado' ],
            200
        );
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Resuelve el order_id de WooCommerce buscando por:
     * 1. pedido_id directamente como order_id
     * 2. numeropedidoExterno como order number
     * 3. Meta _ltms_aveonline_tracking que contenga el número de guía
     */
    private static function resolve_order_id( string $pedido_id, string $externo, string $guia ): int {
        global $wpdb;

        // Intento 1: pedido_id como order_id WooCommerce
        if ( $pedido_id ) {
            $order = wc_get_order( (int) $pedido_id );
            if ( $order ) {
                return $order->get_id();
            }
        }

        // Intento 2: numeropedidoExterno como order_id
        if ( $externo ) {
            $order = wc_get_order( (int) $externo );
            if ( $order ) {
                return $order->get_id();
            }
        }

        // Intento 3: buscar por número de guía en meta
        if ( $guia ) {
            $found = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ltms_aveonline_tracking'
                   AND meta_value = %s
                 LIMIT 1",
                $guia
            ) );
            if ( $found ) {
                return $found;
            }

            // HPOS (WooCommerce High-Performance Order Storage)
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'wc_orders' ) ) ) {
                $found = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
                     WHERE meta_key = '_ltms_aveonline_tracking'
                       AND meta_value = %s
                     LIMIT 1",
                    $guia
                ) );
                if ( $found ) {
                    return $found;
                }
            }
        }

        return 0;
    }

    /**
     * Determina la acción semántica según estado_id y/o nombre_estado.
     */
    private static function resolve_accion( string $estado_id, string $nombre_estado ): string {
        // Primero por ID numérico
        if ( isset( self::ESTADO_MAP[ $estado_id ] ) ) {
            return self::ESTADO_MAP[ $estado_id ];
        }

        $upper = strtoupper( $nombre_estado );

        foreach ( self::NOMBRE_DELIVERED as $kw ) {
            if ( str_contains( $upper, $kw ) ) return 'delivered';
        }
        foreach ( self::NOMBRE_FAILED as $kw ) {
            if ( str_contains( $upper, $kw ) ) return 'failed';
        }
        foreach ( self::NOMBRE_TRANSIT as $kw ) {
            if ( str_contains( $upper, $kw ) ) return 'in_transit';
        }

        return 'unknown';
    }

    /**
     * Actualiza el pedido WooCommerce con la información del webhook.
     */
    private static function update_order(
        int    $order_id,
        string $guia,
        string $estado_id,
        string $nombre_estado,
        string $fecha_creacion,
        string $fecha_novedad,
        string $comentario,
        string $tipo_novedad,
        string $guia_digitalizada,
        string $fecha_entrega,
        string $accion
    ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Meta del pedido
        $order->update_meta_data( '_ltms_aveonline_tracking',    $guia );
        $order->update_meta_data( '_ltms_shipping_status',       $nombre_estado );
        $order->update_meta_data( '_ltms_aveonline_estado_id',   $estado_id );
        // AUDIT-SHIPPING-ENGINE #11 FIX: también guardar en _ltms_aveonline_status
        // (la key que Consumer_Protection::is_order_delivered_or_no_shipping() busca).
        // Antes el estado se guardaba solo en _ltms_shipping_status pero
        // Consumer Protection busca en _ltms_aveonline_status → nunca encontraba
        // el estado de entrega → los holds no se liberaban por la vía de
        // is_order_delivered_or_no_shipping() (aunque sí por el do_action
        // del case 'delivered').
        $order->update_meta_data( '_ltms_aveonline_status',      $accion ?: $nombre_estado );

        if ( $guia_digitalizada ) {
            $order->update_meta_data( '_ltms_aveonline_guia_pdf', $guia_digitalizada );
        }
        if ( $fecha_entrega ) {
            $order->update_meta_data( '_ltms_aveonline_fecha_entrega', $fecha_entrega );
        }

        // Nota de orden
        $nota = sprintf(
            /* translators: 1: guía, 2: estado, 3: estado_id, 4: fecha */
            __( 'Aveonline 🚚 Guía %1$s — %2$s [ID %3$s] (%4$s)', 'ltms' ),
            $guia, $nombre_estado, $estado_id, $fecha_creacion
        );
        if ( $comentario ) {
            $nota .= ' | ' . $comentario;
        }
        if ( $tipo_novedad ) {
            $nota .= ' [' . $tipo_novedad . ']';
        }
        $order->add_order_note( $nota );

        // Acciones semánticas y estado WooCommerce
        switch ( $accion ) {
            case 'delivered':
                if ( $guia_digitalizada ) {
                    $order->update_meta_data( '_ltms_aveonline_comprobante', $guia_digitalizada );
                }
                if ( ! $order->has_status( 'completed' ) ) {
                    $order->update_status( 'completed', __( 'Entregado por Aveonline.', 'ltms' ) );
                }
                // AO-BUG-13 FIX: make `ltms_shipping_delivered` idempotent.
                // Without this guard, a single Aveonline retry would fire the
                // action twice, causing Consumer Protection to release the hold
                // twice and double-crediting the vendor wallet.
                if ( ! $order->get_meta( '_ltms_shipping_delivered_fired' ) ) {
                    do_action( 'ltms_shipping_delivered', $order_id, 'aveonline' );
                    $order->update_meta_data( '_ltms_shipping_delivered_fired', current_time( 'mysql', true ) );
                }
                break;

            case 'failed':
                do_action( 'ltms_shipping_failed', $order_id, 'aveonline:' . sanitize_key( $nombre_estado ) );
                break;

            case 'in_transit':
                do_action( 'ltms_shipping_in_transit', $order_id, 'aveonline:' . sanitize_key( $nombre_estado ) );
                break;
        }

        $order->save();
    }

    private static function log_info( string $msg ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'AVEONLINE_WEBHOOK', $msg );
        }
    }

    private static function log_warning( string $code, string $msg ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning( 'AVEONLINE_WEBHOOK_' . $code, $msg );
        }
    }

    /**
     * Resuelve la IP del cliente para rate limiting (API-BUG-19).
     *
     * @return string
     */
    private static function client_ip(): string {
        // WH3 FIX (v2.8.9): delegar a LTMS_Core_Security::get_client_ip_safe().
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            return LTMS_Core_Security::get_client_ip_safe();
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
