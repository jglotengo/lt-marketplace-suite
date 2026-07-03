<?php
/**
 * LTMS Fiscal Online Access — Acceso en línea para autoridades fiscales.
 *
 * Implementa el cumplimiento del Art. 30-B CFF (SAT México) y E.T. 437-2 (DIAN Colombia):
 *  - NF-3: Generación de usuario/contraseña para SAT/DIAN (Ficha 168/CFF).
 *  - NF-3: REST endpoint para acceso en línea a datos fiscales.
 *  - NF-3: Log de cada acceso en lt_sat_online_access / lt_dian_online_access.
 *  - NF-4: Cron T+1 que verifica disponibilidad de datos del día anterior.
 *
 * @package LTMS
 * @version 2.9.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Fiscal_Online_Access {

    public static function init(): void {
        // REST endpoint para acceso de la autoridad fiscal.
        add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ] );

        // NF-4: Cron T+1 — verifica que los datos del día D estén disponibles en D+1.
        add_action( 'ltms_fiscal_t1_availability_check', [ __CLASS__, 'run_t1_check' ] );
        if ( ! wp_next_scheduled( 'ltms_fiscal_t1_availability_check' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 01:00:00' ), 'daily', 'ltms_fiscal_t1_availability_check' );
        }

        // Admin: AJAX para generar/revocar credenciales.
        add_action( 'wp_ajax_ltms_generate_fiscal_credentials', [ __CLASS__, 'ajax_generate_credentials' ] );
        add_action( 'wp_ajax_ltms_revoke_fiscal_credentials', [ __CLASS__, 'ajax_revoke_credentials' ] );
        add_action( 'wp_ajax_ltms_list_fiscal_credentials', [ __CLASS__, 'ajax_list_credentials' ] );
    }

    // ================================================================
    // NF-3: REST ENDPOINTS PARA ACCESO SAT/DIAN
    // ================================================================

    public static function register_endpoints(): void {
        // Endpoint principal: GET /ltms/v1/fiscal/transactions
        // Requiere Basic Auth con credenciales generadas por el admin.
        register_rest_route( 'ltms/v1', '/fiscal/transactions', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_fiscal_transactions' ],
            'permission_callback' => [ __CLASS__, 'verify_fiscal_access' ],
        ] );

        // Endpoint: GET /ltms/v1/fiscal/vendors
        register_rest_route( 'ltms/v1', '/fiscal/vendors', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_fiscal_vendors' ],
            'permission_callback' => [ __CLASS__, 'verify_fiscal_access' ],
        ] );

        // Endpoint: GET /ltms/v1/fiscal/summary
        register_rest_route( 'ltms/v1', '/fiscal/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_fiscal_summary' ],
            'permission_callback' => [ __CLASS__, 'verify_fiscal_access' ],
        ] );
    }

    /**
     * Verifica las credenciales Basic Auth de la autoridad fiscal.
     */
    public static function verify_fiscal_access( \WP_REST_Request $request ): bool {
        $headers = $request->get_headers();
        $auth_header = $headers['authorization'][0] ?? '';

        if ( ! $auth_header || strpos( $auth_header, 'Basic ' ) !== 0 ) {
            return false;
        }

        $decoded = base64_decode( substr( $auth_header, 6 ) );
        if ( ! $decoded || strpos( $decoded, ':' ) === false ) {
            return false;
        }

        list( $username, $password ) = explode( ':', $decoded, 2 );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_fiscal_access_credentials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $cred = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE username = %s AND revoked_at IS NULL LIMIT 1",
            sanitize_text_field( $username )
        ) );

        if ( ! $cred ) {
            return false;
        }

        if ( ! wp_check_password( $password, $cred->password_hash ) ) {
            return false;
        }

        // Log del acceso.
        self::log_access( $cred->authority, $cred->id, $request );

        // Actualizar contador y último acceso.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, [
            'last_access_at' => current_time( 'mysql', true ),
            'access_count'   => (int) $cred->access_count + 1,
        ], [ 'id' => (int) $cred->id ] );

        return true;
    }

    /**
     * GET /fiscal/transactions — devuelve transacciones fiscales Art. 30-B Fracción I.
     */
    public static function get_fiscal_transactions( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';

        $date_from = sanitize_text_field( $request->get_param( 'date_from' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = sanitize_text_field( $request->get_param( 'date_to' ) ?: gmdate( 'Y-m-d' ) );
        $limit     = min( 10000, (int) ( $request->get_param( 'limit' ) ?: 1000 ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_id, vendor_id, service_type, gross_amount, iva_amount,
                    iva_retenido, ieps_amount, ieps_retenido, tax_withholding,
                    vendor_amount, currency, country_code, created_at,
                    cfdi_folio, sat_cfdi_folio, customer_rfc,
                    payment_method_buyer, payment_method_vendor, payment_method_platform,
                    is_import, aranceles_amount, is_hospedaje, property_address_mx
             FROM `{$c}`
             WHERE created_at BETWEEN %s AND %s
             ORDER BY created_at ASC
             LIMIT %d",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59',
            $limit
        ), ARRAY_A );

        $transactions = [];
        foreach ( ( $rows ?: [] ) as $r ) {
            $gross = (float) $r['gross_amount'];
            $iva   = (float) $r['iva_amount'];
            $transactions[] = [
                'id_transaccion'      => (int) $r['id'],
                'id_orden'            => (int) $r['order_id'],
                'fecha_operacion'     => $r['created_at'],
                'pais'                => $r['country_code'],
                // Fracción I
                'a_tipo_servicio'     => $r['service_type'] ?: 'producto',
                'b_rfc_cliente'       => $r['customer_rfc'] ?: '',
                'c_precio_sin_iva'    => round( $gross - $iva, 2 ),
                'd_iva_trasladado'    => $iva,
                'e_precio_final_iva'  => $gross,
                'f_folio_cfdi'        => $r['sat_cfdi_folio'] ?: $r['cfdi_folio'] ?: '',
                'g_metodo_pago'       => $r['payment_method_buyer'] ?: '',
                // Fracción II (por vendor)
                'vendor_id'           => (int) $r['vendor_id'],
                'iva_retenido'        => (float) $r['iva_retenido'],
                'ieps_retenido'       => (float) $r['ieps_retenido'],
                'isr_retenido'        => (float) $r['tax_withholding'],
                'is_import'           => (int) $r['is_import'],
                'aranceles'           => (float) $r['aranceles_amount'],
                'is_hospedaje'        => (int) $r['is_hospedaje'],
                'direccion_inmueble'  => $r['property_address_mx'] ?: '',
            ];
        }

        return new \WP_REST_Response( [
            'norma'        => 'Art. 30-B CFF / E.T. 437-2',
            'periodo'      => [ 'from' => $date_from, 'to' => $date_to ],
            'total'        => count( $transactions ),
            'transactions' => $transactions,
        ], 200 );
    }

    /**
     * GET /fiscal/vendors — devuelve datos de vendedores Art. 30-B Fracción II.
     */
    public static function get_fiscal_vendors( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';

        $date_from = sanitize_text_field( $request->get_param( 'date_from' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = sanitize_text_field( $request->get_param( 'date_to' ) ?: gmdate( 'Y-m-d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.vendor_id, u.display_name, u.user_email,
                    MAX(c.vendor_rfc) as rfc, MAX(c.vendor_curp) as curp,
                    MAX(c.vendor_clabe) as clabe,
                    SUM(c.gross_amount) as total_isr,
                    SUM(c.iva_amount) as total_iva,
                    SUM(c.ieps_amount) as total_ieps,
                    SUM(c.iva_retenido) as iva_ret,
                    SUM(c.ieps_retenido) as ieps_ret,
                    SUM(c.tax_withholding) as isr_ret,
                    COUNT(*) as ops,
                    MIN(c.created_at) as primera, MAX(c.created_at) as ultima
             FROM `{$c}` c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.vendor_id
             WHERE c.created_at BETWEEN %s AND %s
             GROUP BY c.vendor_id
             ORDER BY total_isr DESC",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A );

        $vendors = [];
        foreach ( ( $rows ?: [] ) as $r ) {
            // NF-5: obtener datos fiscales del user_meta.
            $vid = (int) $r['vendor_id'];
            $vendors[] = [
                'vendor_id'           => $vid,
                'a_nombre_razon'      => $r['display_name'] ?: '',
                'b_rfc_nif'           => $r['rfc'] ?: get_user_meta( $vid, 'ltms_vendor_rfc', true ) ?: '',
                'c_curp'              => $r['curp'] ?: get_user_meta( $vid, 'ltms_vendor_curp', true ) ?: '',
                'd_domicilio_fiscal'  => get_user_meta( $vid, 'ltms_vendor_domicilio', true ) ?: '',
                'd_pais_residencia'   => get_user_meta( $vid, 'ltms_vendor_pais', true ) ?: '',
                'e_institucion'       => get_user_meta( $vid, 'ltms_vendor_banco', true ) ?: '',
                'e_clabe_cuenta'      => $r['clabe'] ?: get_user_meta( $vid, 'ltms_vendor_clabe', true ) ?: '',
                'f_i_monto_isr'       => round( (float) $r['total_isr'], 2 ),
                'f_ii_monto_iva'      => round( (float) $r['total_iva'], 2 ),
                'f_iii_monto_ieps'    => round( (float) $r['total_ieps'], 2 ),
                'f_v_isr_retenido'    => round( (float) $r['isr_ret'], 2 ),
                'f_vi_iva_retenido'   => round( (float) $r['iva_ret'], 2 ),
                'f_vii_ieps_retenido' => round( (float) $r['ieps_ret'], 2 ),
                'total_operaciones'   => (int) $r['ops'],
                'primera_operacion'   => $r['primera'],
                'ultima_operacion'    => $r['ultima'],
            ];
        }

        return new \WP_REST_Response( [
            'norma'   => 'Art. 30-B CFF Fracción II / E.T. 437-2',
            'periodo' => [ 'from' => $date_from, 'to' => $date_to ],
            'total'   => count( $vendors ),
            'vendors' => $vendors,
        ], 200 );
    }

    /**
     * GET /fiscal/summary — resumen agregado por período.
     */
    public static function get_fiscal_summary( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';

        $date_from = sanitize_text_field( $request->get_param( 'date_from' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
        $date_to   = sanitize_text_field( $request->get_param( 'date_to' ) ?: gmdate( 'Y-m-d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_transactions,
                COUNT(DISTINCT vendor_id) as total_vendors,
                COALESCE(SUM(gross_amount), 0) as total_gross,
                COALESCE(SUM(iva_amount), 0) as total_iva,
                COALESCE(SUM(iva_retenido), 0) as total_iva_ret,
                COALESCE(SUM(ieps_amount), 0) as total_ieps,
                COALESCE(SUM(ieps_retenido), 0) as total_ieps_ret,
                COALESCE(SUM(tax_withholding), 0) as total_isr_ret
             FROM `{$c}`
             WHERE created_at BETWEEN %s AND %s",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A );

        return new \WP_REST_Response( [
            'norma'              => 'Art. 30-B CFF / E.T. 437-2',
            'periodo'            => [ 'from' => $date_from, 'to' => $date_to ],
            'total_transacciones'=> (int) ( $summary['total_transactions'] ?? 0 ),
            'total_vendedores'   => (int) ( $summary['total_vendors'] ?? 0 ),
            'monto_bruto_total'  => round( (float) ( $summary['total_gross'] ?? 0 ), 2 ),
            'iva_trasladado'     => round( (float) ( $summary['total_iva'] ?? 0 ), 2 ),
            'iva_retenido'       => round( (float) ( $summary['total_iva_ret'] ?? 0 ), 2 ),
            'ieps_trasladado'    => round( (float) ( $summary['total_ieps'] ?? 0 ), 2 ),
            'ieps_retenido'      => round( (float) ( $summary['total_ieps_ret'] ?? 0 ), 2 ),
            'isr_retenido'       => round( (float) ( $summary['total_isr_ret'] ?? 0 ), 2 ),
        ], 200 );
    }

    // ================================================================
    // NF-3: GESTIÓN DE CREDENCIALES
    // ================================================================

    /**
     * Genera credenciales de acceso para una autoridad fiscal.
     */
    public static function generate_credentials( string $authority, int $created_by ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_fiscal_access_credentials';

        $username = sanitize_text_field( $authority . '_auditor_' . wp_generate_password( 6, false ) );
        $password = wp_generate_password( 24, true, true );
        $hash     = wp_hash_password( $password );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'authority'     => strtoupper( $authority ),
            'username'      => $username,
            'password_hash' => $hash,
            'created_by'    => $created_by,
        ] );

        $id = (int) $wpdb->insert_id;

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FISCAL_CREDENTIAL_GENERATED',
                sprintf( 'Credencial #%d generada para %s por admin #%d. Usuario: %s', $id, strtoupper( $authority ), $created_by, $username )
            );
        }

        return [
            'id'       => $id,
            'authority'=> strtoupper( $authority ),
            'username' => $username,
            'password' => $password, // Solo se muestra una vez.
            'endpoint' => rest_url( 'ltms/v1/fiscal/transactions' ),
            'note'     => __( 'Guarda esta contraseña de forma segura. No se volverá a mostrar.', 'ltms' ),
        ];
    }

    /**
     * Revoca credenciales.
     */
    public static function revoke_credentials( int $cred_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_fiscal_access_credentials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->update( $table, [
            'revoked_at' => current_time( 'mysql', true ),
        ], [ 'id' => $cred_id, 'revoked_at' => null ] );
    }

    /**
     * Lista credenciales activas.
     */
    public static function list_credentials(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_fiscal_access_credentials';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            "SELECT id, authority, username, created_at, last_access_at, access_count, revoked_at
             FROM `{$table}` ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Registra cada acceso de la autoridad fiscal.
     */
    private static function log_access( string $authority, int $cred_id, \WP_REST_Request $request ): void {
        global $wpdb;

        $endpoint = $request->get_route();
        $ip       = LTMS_Utils::get_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now     = current_time( 'mysql', true );

        // Log en lt_sat_online_access (MX) o lt_dian_online_access (CO).
        if ( strtoupper( $authority ) === 'SAT' ) {
            $table = $wpdb->prefix . 'lt_sat_online_access';
        } else {
            $table = $wpdb->prefix . 'lt_dian_online_access';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'accessed_at'  => $now,
            'endpoint'     => $endpoint,
            'ip_address'   => $ip,
            'user_agent'   => substr( $user_agent, 0, 255 ),
            'cred_id'      => $cred_id,
            'params'       => wp_json_encode( $request->get_params() ),
        ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FISCAL_ACCESS_LOGGED',
                sprintf( '%s accedió a %s desde IP %s', strtoupper( $authority ), $endpoint, $ip )
            );
        }
    }

    // ================================================================
    // NF-4: CRON T+1 — VERIFICACIÓN DE DISPONIBILIDAD
    // ================================================================

    /**
     * Cron diario T+1: verifica que TODAS las transacciones del día D
     * tengan sus datos fiscales completos para consulta en D+1.
     *
     * Art. 30-B: "la información deberá alojarse a más tardar al día siguiente"
     */
    public static function run_t1_check(): void {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';

        // Día D = ayer.
        $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        $start = $yesterday . ' 00:00:00';
        $end   = $yesterday . ' 23:59:59';

        // Contar transacciones del día D.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$c}` WHERE created_at BETWEEN %s AND %s",
            $start, $end
        ) );

        if ( $total === 0 ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'FISCAL_T1_CHECK', sprintf( 'T+1: sin transacciones en %s. OK.', $yesterday ) );
            }
            return;
        }

        // Verificar campos críticos vacíos.
        $missing = [];

        // service_type vacío.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $missing['service_type'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$c}` WHERE created_at BETWEEN %s AND %s AND (service_type IS NULL OR service_type = '')",
            $start, $end
        ) );

        // payment_method_buyer vacío.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $missing['payment_method_buyer'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$c}` WHERE created_at BETWEEN %s AND %s AND (payment_method_buyer IS NULL OR payment_method_buyer = '')",
            $start, $end
        ) );

        // vendor_rfc vacío (solo MX).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $missing['vendor_rfc_mx'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$c}` WHERE created_at BETWEEN %s AND %s AND country_code = 'MX' AND (vendor_rfc IS NULL OR vendor_rfc = '')",
            $start, $end
        ) );

        $has_gaps = false;
        foreach ( $missing as $field => $count ) {
            if ( $count > 0 ) {
                $has_gaps = true;
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'FISCAL_T1_GAP',
                        sprintf( 'T+1: %d transacciones de %s con campo %s vacío. Rellenar antes de auditoría SAT/DIAN.', $count, $yesterday, $field )
                    );
                }
            }
        }

        if ( ! $has_gaps ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'FISCAL_T1_OK',
                    sprintf( 'T+1: %d transacciones de %s con datos fiscales completos. Disponibles para SAT/DIAN.', $total, $yesterday )
                );
            }
        }

        // Auto-rellenar service_type si está vacío (derivar del tipo de producto).
        if ( $missing['service_type'] > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$c}` SET service_type = 'producto' WHERE created_at BETWEEN %s AND %s AND (service_type IS NULL OR service_type = '')",
                $start, $end
            ) );
        }

        // Auto-rellenar payment_method_buyer si está vacío (derivar del método de pago del WC order).
        if ( $missing['payment_method_buyer'] > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id FROM `{$c}` WHERE created_at BETWEEN %s AND %s AND (payment_method_buyer IS NULL OR payment_method_buyer = '')",
                $start, $end
            ), ARRAY_A );
            foreach ( $rows as $r ) {
                $order = wc_get_order( (int) $r['order_id'] );
                if ( $order ) {
                    $pm = $order->get_payment_method();
                    if ( $pm ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update( $c, [ 'payment_method_buyer' => $pm ], [ 'id' => (int) $r['id'] ] );
                    }
                }
            }
        }
    }

    // ================================================================
    // AJAX HANDLERS (admin)
    // ================================================================

    public static function ajax_generate_credentials(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $authority = sanitize_text_field( $_POST['authority'] ?? 'SAT' );
        if ( ! in_array( strtoupper( $authority ), [ 'SAT', 'DIAN' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Autoridad inválida' ] );
        }

        $cred = self::generate_credentials( $authority, get_current_user_id() );
        wp_send_json_success( $cred );
    }

    public static function ajax_revoke_credentials(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $cred_id = (int) ( $_POST['cred_id'] ?? 0 );
        $ok = self::revoke_credentials( $cred_id );
        wp_send_json_success( [ 'revoked' => $ok ] );
    }

    public static function ajax_list_credentials(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        wp_send_json_success( [ 'credentials' => self::list_credentials() ] );
    }

    // ================================================================
    // NF-5: GUARDAR DATOS FISCALES DEL VENDOR EN USER_META
    // ================================================================

    /**
     * Guarda los datos fiscales del vendedor en user_meta.
     * Se llama desde el proceso KYC y desde el guardado de settings del vendor.
     *
     * @param int   $vendor_id
     * @param array $fiscal_data {
     *   @type string $rfc        RFC (MX) o NIT (CO)
     *   @type string $curp       CURP (MX, solo PF)
     *   @type string $domicilio  Domicilio fiscal completo
     *   @type string $pais       País de residencia fiscal
     *   @type string $banco      Institución financiera
     *   @type string $clabe      CLABE (MX) o número de cuenta (CO)
     * }
     */
    public static function save_vendor_fiscal_data( int $vendor_id, array $fiscal_data ): void {
        if ( $vendor_id <= 0 ) return;

        $fields = [
            'ltms_vendor_rfc'       => 'rfc',
            'ltms_vendor_curp'      => 'curp',
            'ltms_vendor_domicilio' => 'domicilio',
            'ltms_vendor_pais'      => 'pais',
            'ltms_vendor_banco'     => 'banco',
            'ltms_vendor_clabe'     => 'clabe',
        ];

        foreach ( $fields as $meta_key => $data_key ) {
            $value = sanitize_text_field( $fiscal_data[ $data_key ] ?? '' );
            if ( $value ) {
                // Cifrar datos sensibles (CLABE, cuenta bancaria).
                if ( in_array( $data_key, [ 'clabe' ], true ) && class_exists( 'LTMS_Core_Security' ) ) {
                    $value = LTMS_Core_Security::encrypt( $value );
                }
                update_user_meta( $vendor_id, $meta_key, $value );
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'VENDOR_FISCAL_DATA_SAVED',
                sprintf( 'Datos fiscales guardados para vendor #%d (Art. 30-B frac. II)', $vendor_id ),
                [ 'vendor_id' => $vendor_id, 'fields' => array_keys( $fiscal_data ) ]
            );
        }
    }

    /**
     * Hook: cuando se aprueba el KYC, guardar los datos fiscales en user_meta.
     */
    public static function on_kyc_approved( int $vendor_id ): void {
        // Leer datos del KYC y guardarlos en los metas que espera el Fiscal Exporter.
        $doc_type = get_user_meta( $vendor_id, 'ltms_kyc_document_type', true );
        $doc_number = get_user_meta( $vendor_id, 'ltms_kyc_document_number', true );
        $bank_name = get_user_meta( $vendor_id, 'ltms_kyc_bank_name', true ) ?: get_user_meta( $vendor_id, 'ltms_bank_name', true );
        $bank_account = get_user_meta( $vendor_id, 'ltms_kyc_bank_account', true ) ?: get_user_meta( $vendor_id, 'ltms_bank_account', true );
        $bank_rep_legal = get_user_meta( $vendor_id, 'ltms_kyc_bank_rep_legal', true );

        $country = LTMS_Core_Config::get_country();

        $fiscal_data = [
            'banco'  => $bank_name,
            'clabe'   => $bank_account,
            'pais'   => $country,
        ];

        // Mapear documento fiscal según país.
        if ( $country === 'MX' ) {
            $fiscal_data['rfc'] = $doc_number; // En MX el doc fiscal es RFC.
            // CURP: si el doc type es CURP, guardarlo; sino buscar en meta.
            if ( $doc_type === 'curp' ) {
                $fiscal_data['curp'] = $doc_number;
            } else {
                $fiscal_data['curp'] = get_user_meta( $vendor_id, 'ltms_vendor_curp', true ) ?: '';
            }
        } else {
            $fiscal_data['rfc'] = $doc_number; // En CO el doc fiscal es NIT/CC.
        }

        // Domicilio: leer de billing address de WC o de user_meta.
        $user = get_userdata( $vendor_id );
        if ( $user ) {
            $billing_address = $user->billing_address ?? '';
            $billing_city = get_user_meta( $vendor_id, 'billing_city', true ) ?: '';
            $billing_state = get_user_meta( $vendor_id, 'billing_state', true ) ?: '';
            $billing_country = get_user_meta( $vendor_id, 'billing_country', true ) ?: $country;
            $fiscal_data['domicilio'] = trim( $billing_address . ', ' . $billing_city . ', ' . $billing_state . ', ' . $billing_country, ', ' );
        }

        self::save_vendor_fiscal_data( $vendor_id, $fiscal_data );
    }
}
