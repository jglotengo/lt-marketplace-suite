<?php
/**
 * Lógica de negocio: Guías de Envío del Vendedor
 *
 * Persiste guías generadas en la tabla local `lt_aveonline_guias` y expone
 * handlers AJAX para el dashboard del vendedor:
 *
 *  - ltms_aveonline_cotizar          → cotizar envío (no persiste)
 *  - ltms_aveonline_generar_guia     → genera guía en Aveonline y persiste
 *  - ltms_aveonline_mis_guias        → lista guías del vendedor logueado
 *  - ltms_aveonline_estado_guia      → consulta estado en Aveonline
 *  - ltms_aveonline_reimprimir_guia  → reimprime documentos de una/varias guías
 *  - ltms_aveonline_solicitar_recogida → solicita recogida de paquetes
 *
 * @package LTMS
 * @version 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Business_Aveonline_Guias {

    const TABLE = 'lt_aveonline_guias';

    // ── Inicialización ────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'ltms_plugin_activated', [ __CLASS__, 'maybe_create_table' ] );
        self::maybe_create_table();

        // AJAX handlers (solo usuarios logueados)
        add_action( 'wp_ajax_ltms_aveonline_cotizar',             [ __CLASS__, 'ajax_cotizar'            ] );
        add_action( 'wp_ajax_ltms_aveonline_generar_guia',        [ __CLASS__, 'ajax_generar_guia'       ] );
        add_action( 'wp_ajax_ltms_aveonline_mis_guias',           [ __CLASS__, 'ajax_mis_guias'          ] );
        add_action( 'wp_ajax_ltms_aveonline_estado_guia',         [ __CLASS__, 'ajax_estado_guia'        ] );
        add_action( 'wp_ajax_ltms_aveonline_reimprimir_guia',     [ __CLASS__, 'ajax_reimprimir_guia'    ] );
        add_action( 'wp_ajax_ltms_aveonline_solicitar_recogida',  [ __CLASS__, 'ajax_solicitar_recogida' ] );
    }

    // ── Tabla DB ──────────────────────────────────────────────────────────────

    public static function maybe_create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            numguia         VARCHAR(40)   NOT NULL DEFAULT '',
            transportadora  VARCHAR(60)   NOT NULL DEFAULT '',
            idtransportador VARCHAR(10)   NOT NULL DEFAULT '',
            destinatario    VARCHAR(120)  NOT NULL DEFAULT '',
            destino         VARCHAR(120)  NOT NULL DEFAULT '',
            origen          VARCHAR(120)  NOT NULL DEFAULT '',
            estado          VARCHAR(60)   NOT NULL DEFAULT 'GENERADA',
            rutaguia        TEXT          NOT NULL DEFAULT '',
            sticker         TEXT          NOT NULL DEFAULT '',
            rotulo          TEXT          NOT NULL DEFAULT '',
            valor_declarado DECIMAL(12,2) NOT NULL DEFAULT 0,
            valorrecaudo    DECIMAL(12,2) NOT NULL DEFAULT 0,
            contraentrega   TINYINT(1)    NOT NULL DEFAULT 0,
            created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY numguia (numguia),
            KEY vendor_id (vendor_id),
            KEY order_id (order_id),
            KEY estado (estado),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    private static function get_api(): LTMS_Api_Aveonline {
        return new LTMS_Api_Aveonline();
    }

    private static function check_vendor_nonce(): void {
        check_ajax_referer( 'ltms_vendor_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'No autenticado.' ], 401 );
        }
        // AO-BUG-6 FIX: `is_user_logged_in()` solo verifica sesión — cualquier
        // cliente (incluso `subscriber`) puede llegar hasta aquí. Exigimos rol
        // `ltms_vendor` o capacidad `manage_options` (admin).
        // v2.9.100 SEC-9 FIX: removed role-as-capability fallback, use is_ltms_vendor only.
        $is_vendor = class_exists( 'LTMS_Utils' ) && LTMS_Utils::is_ltms_vendor();
        if ( ! $is_vendor && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }
    }

    /**
     * AO-BUG-5 FIX: verifica que el usuario actual sea dueño de TODAS las
     * guías indicadas en la tabla local `lt_aveonline_guias`. Si alguna guía
     * no existe o no pertenece al vendor, se devuelve 403.
     *
     * @param string[] $guias Lista de números de guía a verificar.
     * @return bool True si el vendor posee todas las guías.
     */
    private static function vendor_owns_guias( array $guias ): bool {
        $guias = array_values( array_filter( array_map( 'strval', $guias ) ) );
        if ( empty( $guias ) ) {
            return false;
        }
        global $wpdb;
        $vendor_id = get_current_user_id();
        $table     = $wpdb->prefix . self::TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $guias ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $owned = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE numguia IN ({$placeholders}) AND vendor_id = %d",
            array_merge( $guias, [ $vendor_id ] )
        ) );
        return $owned === count( $guias );
    }

    /**
     * AO-BUG-7 FIX: bloqueo de envíos para vendors pendientes de onboarding
     * (flag `_ltms_ave_shipments_blocked` levantado por el cron de
     * recordatorios a los 14 días). Aplica tanto al cálculo de tarifas en
     * checkout como a la generación manual de guías desde el dashboard.
     *
     * @return bool True si el vendor actual tiene los envíos bloqueados.
     */
    private static function is_vendor_blocked(): bool {
        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            return false;
        }
        // Admins no se bloquean — pueden gestionar guías aunque el flag exista.
        if ( current_user_can( 'manage_options' ) ) {
            return false;
        }
        return (bool) get_user_meta( $vendor_id, '_ltms_ave_shipments_blocked', true );
    }

    /**
     * Persiste una guía generada en la tabla local.
     */
    private static function db_insert( array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->replace(
            $table,
            [
                'vendor_id'       => (int)   ( $data['vendor_id']       ?? 0 ),
                'order_id'        => (int)   ( $data['order_id']        ?? 0 ),
                'numguia'         => sanitize_text_field( $data['numguia']         ?? '' ),
                'transportadora'  => sanitize_text_field( $data['transportadora']  ?? '' ),
                'idtransportador' => sanitize_text_field( $data['idtransportador'] ?? '' ),
                'destinatario'    => sanitize_text_field( $data['destinatario']    ?? '' ),
                'destino'         => sanitize_text_field( $data['destino']         ?? '' ),
                'origen'          => sanitize_text_field( $data['origen']          ?? '' ),
                'estado'          => sanitize_text_field( $data['estado']          ?? 'GENERADA' ),
                'rutaguia'        => esc_url_raw( $data['rutaguia']  ?? '' ),
                'sticker'         => esc_url_raw( $data['sticker']   ?? '' ),
                'rotulo'          => esc_url_raw( $data['rotulo']    ?? '' ),
                'valor_declarado' => (float)  ( $data['valor_declarado'] ?? 0 ),
                'valorrecaudo'    => (float)  ( $data['valorrecaudo']    ?? 0 ),
                'contraentrega'   => (int)    ( $data['contraentrega']   ?? 0 ),
            ],
            [ '%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%d' ]
        );
    }

    // ── AJAX: Cotizar ─────────────────────────────────────────────────────────

    /**
     * Cotiza envío. No persiste nada, devuelve array de transportadoras.
     *
     * POST params: origen, destino, peso, alto, largo, ancho, valor_declarado,
     *              valorrecaudo, contraentrega, idasumecosto
     */
    public static function ajax_cotizar(): void {
        // FASE5 P0 FIX: removed dual-nonce dead code (check_ajax_referer('ltms_admin_nonce')
        // + current_user_can('edit_posts')) which blocked all vendors — vendors don't
        // have edit_posts cap and don't have admin nonces. check_vendor_nonce() already
        // verifies ltms_vendor_nonce + is_ltms_vendor().
        self::check_vendor_nonce();

        $origen  = sanitize_text_field( $_POST['origen']  ?? '' );  // phpcs:ignore
        $destino = sanitize_text_field( $_POST['destino'] ?? '' );  // phpcs:ignore

        if ( ! $origen || ! $destino ) {
            wp_send_json_error( [ 'message' => 'Origen y destino son requeridos.' ] );
        }

        $peso            = (float) ( $_POST['peso']            ?? 1 );     // phpcs:ignore
        $alto            = (float) ( $_POST['alto']            ?? 15 );    // phpcs:ignore
        $largo           = (float) ( $_POST['largo']           ?? 30 );    // phpcs:ignore
        $ancho           = (float) ( $_POST['ancho']           ?? 20 );    // phpcs:ignore
        $valor_declarado = (float) ( $_POST['valor_declarado'] ?? 50000 ); // phpcs:ignore
        $valorrecaudo    = (int)   ( $_POST['valorrecaudo']    ?? 0 );     // phpcs:ignore
        $contraentrega   = (int)   ( $_POST['contraentrega']   ?? 0 );     // phpcs:ignore
        $idasumecosto    = (int)   ( $_POST['idasumecosto']    ?? 0 );     // phpcs:ignore

        // FASE5 P0 FIX: validate numeric inputs — reject NaN/INF/negative/zero dimensions.
        if ( ! is_finite( $peso ) || $peso <= 0 || $peso > 1000 ) {
            wp_send_json_error( [ 'message' => __( 'Peso inválido (debe ser 0.1-1000 kg).', 'ltms' ) ] );
        }
        foreach ( [ 'alto' => $alto, 'largo' => $largo, 'ancho' => $ancho ] as $dim_name => $dim_val ) {
            if ( ! is_finite( $dim_val ) || $dim_val <= 0 || $dim_val > 500 ) {
                wp_send_json_error( [ 'message' => sprintf( __( '%s inválido (debe ser 1-500 cm).', 'ltms' ), $dim_name ) ] );
            }
        }
        if ( ! is_finite( $valor_declarado ) || $valor_declarado < 0 ) {
            wp_send_json_error( [ 'message' => __( 'Valor declarado inválido.', 'ltms' ) ] );
        }
        // FASE5 P0 FIX: valorrecaudo ↔ contraentrega consistency.
        if ( $contraentrega > 0 && $valorrecaudo <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Contraentrega activa requiere valor de recaudo > 0.', 'ltms' ) ] );
        }
        if ( $contraentrega === 0 && $valorrecaudo > 0 ) {
            $valorrecaudo = 0; // Clear COD amount if carrier won't collect.
        }

        try {
            $api          = self::get_api();
            $cotizaciones = $api->get_rates( [
                'origin_city'      => $origen,
                'destination_city' => $destino,
                'weight_kg'        => $peso,
                'height_cm'        => $alto,
                'length_cm'        => $largo,
                'width_cm'         => $ancho,
                'declared_value'   => $valor_declarado,
                'valorrecaudo'     => $valorrecaudo,
                'contraentrega'    => $contraentrega,
                'idasumecosto'     => $idasumecosto,
            ] );

            wp_send_json_success( [ 'cotizaciones' => $cotizaciones ] );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Generar guía ────────────────────────────────────────────────────

    /**
     * Genera una guía en Aveonline y la persiste en la tabla local.
     *
     * POST params: idtransportador, origen, destino, peso, alto, largo, ancho,
     *              valor_declarado, valorrecaudo, contraentrega, idasumecosto,
     *              destinatario, dir_dest, tel_dest, email_dest, nit_dest,
     *              contenido, tipo_entrega, order_id
     */
    public static function ajax_generar_guia(): void {
        self::check_vendor_nonce();

        // AO-BUG-7 FIX: vendors con onboarding incompleto (flag 14d) no pueden
        // generar guías nuevas. Esto evita que envíos sin KYC/contrato completado
        // lleguen a Aveonline y queden en limbo.
        if ( self::is_vendor_blocked() ) {
            wp_send_json_error( [
                'code'    => 'vendor_blocked',
                'message' => 'Tus envíos están bloqueados. Completa el onboarding de Aveonline para continuar.',
            ], 403 );
        }

        $vendor_id       = get_current_user_id();
        $idtransportador = sanitize_text_field( $_POST['idtransportador'] ?? '' ); // phpcs:ignore
        $origen          = sanitize_text_field( $_POST['origen']          ?? '' ); // phpcs:ignore
        $destino         = sanitize_text_field( $_POST['destino']         ?? '' ); // phpcs:ignore
        $destinatario    = sanitize_text_field( $_POST['destinatario']    ?? '' ); // phpcs:ignore
        $dir_dest        = sanitize_text_field( $_POST['dir_dest']        ?? '' ); // phpcs:ignore
        $tel_dest        = sanitize_text_field( $_POST['tel_dest']        ?? '' ); // phpcs:ignore
        $email_dest      = sanitize_email(      $_POST['email_dest']      ?? '' ); // phpcs:ignore
        $nit_dest        = sanitize_text_field( $_POST['nit_dest']        ?? '00000' ); // phpcs:ignore
        $contenido       = sanitize_text_field( $_POST['contenido']       ?? 'Mercancía' ); // phpcs:ignore
        $tipo_entrega    = in_array( $_POST['tipo_entrega'] ?? '1', [ '1', '2' ], true ) ? $_POST['tipo_entrega'] : '1'; // phpcs:ignore
        $order_id        = (int) ( $_POST['order_id'] ?? 0 ); // phpcs:ignore

        $peso            = (float) ( $_POST['peso']            ?? 1 );     // phpcs:ignore
        $alto            = (float) ( $_POST['alto']            ?? 15 );    // phpcs:ignore
        $largo           = (float) ( $_POST['largo']           ?? 30 );    // phpcs:ignore
        $ancho           = (float) ( $_POST['ancho']           ?? 20 );    // phpcs:ignore
        $valor_declarado = (float) ( $_POST['valor_declarado'] ?? 50000 ); // phpcs:ignore
        $valorrecaudo    = (int)   ( $_POST['valorrecaudo']    ?? 0 );     // phpcs:ignore
        $contraentrega   = (int)   ( $_POST['contraentrega']   ?? 0 );     // phpcs:ignore
        $idasumecosto    = (int)   ( $_POST['idasumecosto']    ?? 0 );     // phpcs:ignore

        if ( ! $idtransportador || ! $origen || ! $destino || ! $destinatario || ! $dir_dest || ! $tel_dest ) {
            wp_send_json_error( [ 'message' => 'Faltan campos requeridos.' ] );
        }

        // v2.9.118 SHIPPING-AUDIT P0-2 FIX: verify order ownership (IDOR).
        // Before, a vendor could pass order_id of ANOTHER vendor's order and
        // generate a shipping guide for it — the guide would be created with
        // the caller's vendor_id, but the order belongs to someone else.
        // Now we verify that the order's _ltms_vendor_id matches the caller.
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
            }
            $order_vendor = (int) $order->get_meta( '_ltms_vendor_id' );
            if ( $order_vendor && $order_vendor !== $vendor_id && ! current_user_can( 'manage_options' ) ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::security(
                        'AVEONLINE_GUIDE_IDOR_ATTEMPT',
                        sprintf( 'Vendor #%d intentó generar guía para order #%d del vendor #%d', $vendor_id, $order_id, $order_vendor ),
                        [ 'vendor_id' => $vendor_id, 'order_id' => $order_id, 'order_vendor_id' => $order_vendor ]
                    );
                }
                wp_send_json_error( [ 'message' => 'No tienes permiso para generar guía de este pedido.' ], 403 );
            }
        }

        // v2.9.118 SHIPPING-AUDIT P0-3 FIX: bound valorrecaudo to order total.
        // Before, vendor could set valorrecaudo (cash-on-delivery amount) to any
        // value — they could declare 0 recaudo for a paid order (pocketing the
        // cash) or declare an inflated recaudo (defrauding the customer at delivery).
        // Now we verify it doesn't exceed the order total if the order exists.
        if ( $order_id && $valorrecaudo > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order_total = (float) $order->get_total();
                if ( $valorrecaudo > $order_total ) {
                    wp_send_json_error( [
                        'message' => sprintf(
                            'El valor de recaudo (%s) no puede superar el total del pedido (%s).',
                            number_format( $valorrecaudo, 0, ',', '.' ),
                            number_format( $order_total, 0, ',', '.' )
                        ),
                    ] );
                }
            }
        }

        // AO-BUG-11 FIX: dedup por order_id. Un doble-clic en "Generar guía"
        // disparaba dos POST a Aveonline y creaba dos guías para la misma
        // orden. Si ya existe una guía persistida para este order_id (o el
        // meta `_ltms_aveonline_tracking` en el pedido), devolvemos la
        // existente en lugar de generar otra.
        if ( $order_id ) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing_numguia = $wpdb->get_var( $wpdb->prepare(
                "SELECT numguia FROM `{$table}` WHERE order_id = %d AND vendor_id = %d ORDER BY id DESC LIMIT 1",
                $order_id, $vendor_id
            ) );
            if ( $existing_numguia ) {
                wp_send_json_error( [
                    'code'    => 'guia_exists',
                    'message' => 'Ya existe una guía para esta orden.',
                    'numguia' => (string) $existing_numguia,
                ], 409 );
            }
        }

        // Datos del remitente (vendedor)
        $user       = get_userdata( $vendor_id );
        $store_name = get_user_meta( $vendor_id, 'ltms_store_name',      true ) ?: $user->display_name;
        $store_city = get_user_meta( $vendor_id, 'billing_city',         true ) ?: $origen;
        $store_addr = get_user_meta( $vendor_id, 'billing_address_1',    true ) ?: 'Bodega';
        $store_phone= get_user_meta( $vendor_id, 'billing_phone',        true ) ?: '0000000';
        $store_email= $user->user_email;
        $store_nit  = get_user_meta( $vendor_id, 'billing_company',      true ) ?: '00000';

        try {
            $api    = self::get_api();
            $result = $api->create_shipment( [
                'idtransportador' => $idtransportador,
                'IdTipoEntrega'   => $tipo_entrega,
                'origen'          => $store_city,
                'origin'          => [
                    'city'    => $store_city,
                    'address' => $store_addr,
                    'name'    => $store_name,
                    'phone'   => $store_phone,
                    'email'   => $store_email,
                    'nit'     => $store_nit,
                ],
                'destination'     => [
                    'city'    => $destino,
                    'address' => $dir_dest,
                    'name'    => $destinatario,
                    'phone'   => $tel_dest,
                    'email'   => $email_dest,
                    'nit'     => $nit_dest,
                ],
                'packages'        => [
                    [
                        'weight_kg'      => $peso,
                        'height_cm'      => $alto,
                        'length_cm'      => $largo,
                        'width_cm'       => $ancho,
                        'quantity'       => 1,
                        'nombre'         => $contenido,
                        'valor_declarado'=> $valor_declarado,
                    ],
                ],
                'dscontenido'     => $contenido,
                'valorrecaudo'    => $valorrecaudo,
                'contraentrega'   => $contraentrega,
                'idasumecosto'    => $idasumecosto,
                'orden_compra'    => $order_id ? (string) $order_id : '',
            ] );

            if ( ! $result['success'] ) {
                wp_send_json_error( [ 'message' => $result['mensaje'] ?: 'Error al generar la guía.' ] );
            }

            // Persistir en tabla local
            self::db_insert( [
                'vendor_id'       => $vendor_id,
                'order_id'        => $order_id,
                'numguia'         => $result['tracking_number'],
                'transportadora'  => $result['transportadora'],
                'idtransportador' => $idtransportador,
                'destinatario'    => $destinatario,
                'destino'         => $destino,
                'origen'          => $store_city,
                'estado'          => 'GENERADA',
                'rutaguia'        => $result['label_url'],
                'sticker'         => $result['label_url_sticker'] ?? '',
                'rotulo'          => $result['label_url'],
                'valor_declarado' => $valor_declarado,
                'valorrecaudo'    => $valorrecaudo,
                'contraentrega'   => $contraentrega,
            ] );

            wp_send_json_success( [
                'numguia'  => $result['tracking_number'],
                'rutaguia' => $result['label_url'],
                'sticker'  => $result['label_url_sticker'] ?? '',
                'mensaje'  => $result['mensaje'],
            ] );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Mis guías ───────────────────────────────────────────────────────

    /**
     * Devuelve las guías del vendedor logueado desde la tabla local.
     */
    public static function ajax_mis_guias(): void {
        // FASE5 P0 FIX: removed dual-nonce dead code — check_vendor_nonce() already verifies ltms_vendor_nonce + is_ltms_vendor().
        self::check_vendor_nonce();

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

        wp_send_json_success( [ 'guias' => $rows ?: [] ] );
    }

    // ── AJAX: Estado de guía ──────────────────────────────────────────────────

    /**
     * Consulta el estado actual de una guía en Aveonline y actualiza la tabla local.
     *
     * POST param: numguia
     */
    public static function ajax_estado_guia(): void {
        self::check_vendor_nonce();

        $numguia = sanitize_text_field( $_POST['numguia'] ?? '' ); // phpcs:ignore
        if ( ! $numguia ) {
            wp_send_json_error( [ 'message' => 'Número de guía requerido.' ] );
        }

        // AO-BUG-5 FIX: IDOR — el vendor solo puede consultar guías que él
        // generó. Sin este check, Vendor A podría rastrear las guías de Vendor B.
        if ( ! self::vendor_owns_guias( [ $numguia ] ) ) {
            wp_send_json_error( [ 'message' => 'Guía not found or not owned by you' ], 403 );
        }

        try {
            $api    = self::get_api();
            $result = $api->track_shipment( $numguia );

            // Actualizar estado en tabla local
            if ( ! empty( $result['status'] ) ) {
                global $wpdb;
                $table = $wpdb->prefix . self::TABLE;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $table,
                    [ 'estado' => strtoupper( $result['status'] ) ],
                    [ 'numguia' => $numguia ],
                    [ '%s' ],
                    [ '%s' ]
                );
            }

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Reimprimir guía ─────────────────────────────────────────────────

    /**
     * Obtiene URLs de impresión para una o varias guías.
     *
     * POST params: idoperador (int), guias (string separado por comas)
     */
    public static function ajax_reimprimir_guia(): void {
        // FASE5 P0 FIX: removed dual-nonce dead code — check_vendor_nonce() already verifies ltms_vendor_nonce + is_ltms_vendor().
        self::check_vendor_nonce();

        $idoperador = (int) ( $_POST['idoperador'] ?? 0 ); // phpcs:ignore
        $guias_raw  = sanitize_text_field( $_POST['guias'] ?? '' ); // phpcs:ignore

        if ( ! $idoperador || ! $guias_raw ) {
            wp_send_json_error( [ 'message' => 'Transportadora y guías son requeridas.' ] );
        }

        $guias = array_values( array_filter( array_map( 'trim', explode( ',', $guias_raw ) ) ) );

        if ( empty( $guias ) ) {
            wp_send_json_error( [ 'message' => 'Ingresa al menos un número de guía.' ] );
        }

        // AO-BUG-5 FIX: IDOR — solo se permiten reimprimir guías del vendor.
        if ( ! self::vendor_owns_guias( $guias ) ) {
            wp_send_json_error( [ 'message' => 'Guía not found or not owned by you' ], 403 );
        }

        $idcliente = (int) LTMS_Core_Config::get( 'ltms_aveonline_idempresa', 0 );

        try {
            $api    = self::get_api();
            $result = $api->reprint_guides( $idoperador, $idcliente, $guias );

            if ( ( $result['status'] ?? '' ) === 'error' && empty( $result['resultado'] ) ) {
                wp_send_json_error( [ 'message' => $result['message'] ?: 'Sin resultados.' ] );
            }

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── AJAX: Solicitar recogida ──────────────────────────────────────────────

    /**
     * Solicita recogida de paquetes. Solo disponible antes de las 11 AM.
     *
     * POST params: guias (string separado por comas), dscom (opcional)
     */
    public static function ajax_solicitar_recogida(): void {
        self::check_vendor_nonce();

        // Validar horario (hora Colombia = UTC-5)
        $hora_col = (int) ( new \DateTime( 'now', new \DateTimeZone( 'America/Bogota' ) ) )->format( 'H' );
        if ( $hora_col >= 11 ) {
            wp_send_json_error( [
                'code'    => 'late_pickup',
                'message' => 'Las recogidas solo se aceptan antes de las 11:00 AM. Tu solicitud quedará pendiente para mañana.',
            ] );
        }

        $guias_raw = sanitize_text_field( $_POST['guias'] ?? '' ); // phpcs:ignore
        $dscom     = sanitize_text_field( $_POST['dscom'] ?? '' );  // phpcs:ignore

        if ( ! $guias_raw ) {
            wp_send_json_error( [ 'message' => 'Ingresa al menos un número de guía.' ] );
        }

        $guias = array_values( array_filter( array_map( 'trim', explode( ',', $guias_raw ) ) ) );

        if ( empty( $guias ) ) {
            wp_send_json_error( [ 'message' => 'No se encontraron números de guía válidos.' ] );
        }

        // AO-BUG-5 FIX: IDOR — solo se permiten solicitar recogida de guías del vendor.
        if ( ! self::vendor_owns_guias( $guias ) ) {
            wp_send_json_error( [ 'message' => 'Guía not found or not owned by you' ], 403 );
        }

        try {
            $api    = self::get_api();
            $result = $api->request_pickup( $guias, $dscom );

            if ( ( $result['status'] ?? '' ) !== 'ok' ) {
                wp_send_json_error( [ 'message' => $result['message'] ?: 'Error al solicitar recogida.' ] );
            }

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }
}
