<?php
class LTMS_Admin_SAT_Report {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        $instance = new self();
        add_action( 'wp_ajax_ltms_generate_sat_report',  [ $instance, 'ajax_generate_report' ] );
        add_action( 'wp_ajax_ltms_generate_dian_report', [ $instance, 'ajax_generate_dian_report' ] );
    }

    /**
     * AJAX: genera el reporte SAT México (DIOT / CFDI resumen).
     *
     * @return void
     */
    public function ajax_generate_report(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $period = sanitize_text_field( $_POST['period'] ?? gmdate( 'Y-m' ) ); // YYYY-MM

        $data = $this->get_period_data( $period );

        wp_send_json_success( [
            'period'         => $period,
            'total_sales'    => $data['total_sales'],
            'total_tax'      => $data['total_tax'],
            'total_vendors'  => $data['total_vendors'],
            'records'        => count( $data['records'] ),
            'download_token' => $this->store_report_data( $data, 'sat_' . $period ),
        ] );
    }

    /**
     * AJAX: genera el reporte DIAN Colombia (Exógena) con desglose
     * completo por vendedor y transacción, conforme al art. 30-B CFF
     * y las obligaciones de acceso en línea de la DIAN Colombia.
     *
     * Retorna:
     *  - Totales agregados (ventas, retenciones, IVA, ICA).
     *  - Listado por vendedor: NIT, régimen, cuenta, monto ISR/IVA/ICA.
     *  - Token de descarga CSV/JSON.
     *
     * @return void
     */
    public function ajax_generate_dian_report(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $year       = absint( $_POST['year'] ?? gmdate( 'Y' ) );
        $month      = absint( $_POST['month'] ?? 0 ); // 0 = todo el año
        $vendor_nit = sanitize_text_field( $_POST['vendor_nit'] ?? '' );

        $data = $this->get_dian_exogena_data( $year, $month, $vendor_nit );

        // Registrar en log de acceso fiscal.
        $this->log_fiscal_access( 'query_transactions', [
            'filter_from'   => $month ? sprintf( '%04d-%02d-01', $year, $month ) : "{$year}-01-01",
            'filter_to'     => $month ? sprintf( '%04d-%02d-31', $year, $month ) : "{$year}-12-31",
            'filter_vendor' => $vendor_nit ?: null,
            'rows_returned' => count( $data['transactions'] ),
        ] );

        wp_send_json_success( [
            'year'                  => $year,
            'month'                 => $month ?: 'all',
            'total_gross'           => $data['totals']['gross'],
            'total_platform_fee'    => $data['totals']['platform_fee'],
            'total_retefuente'      => $data['totals']['retefuente'],
            'total_reteiva'         => $data['totals']['reteiva'],
            'total_reteica'         => $data['totals']['reteica'],
            'total_iva'             => $data['totals']['iva'],
            'total_net_to_vendors'  => $data['totals']['net_to_vendors'],
            'vendor_count'          => count( $data['by_vendor'] ),
            'transaction_count'     => count( $data['transactions'] ),
            'by_vendor'             => $data['by_vendor'],
            'download_token'        => $this->store_report_data( $data, 'dian_exogena_' . $year . ( $month ? "_m{$month}" : '' ) ),
        ] );
    }

    /**
     * Construye el dataset DIAN Exógena completo: por transacción y agrupado por vendedor.
     *
     * @param int    $year        Año fiscal.
     * @param int    $month       Mes (0 = todo el año).
     * @param string $vendor_nit  Filtro opcional por NIT/RUT del vendedor.
     * @return array{totals: array, by_vendor: array, transactions: array}
     */
    private function get_dian_exogena_data( int $year, int $month, string $vendor_nit ): array {
        global $wpdb;
        $c = $wpdb->prefix . 'lt_commissions';
        $k = $wpdb->prefix . 'lt_vendor_kyc';

        // Build WHERE clause.
        $where  = 'WHERE c.country_code = %s AND c.status IN (\'paid\',\'approved\')';
        $params = [ 'CO' ];

        if ( $month ) {
            $where   .= " AND DATE_FORMAT(c.created_at, '%%Y-%%m') = %s";
            $params[] = sprintf( '%04d-%02d', $year, $month );
        } else {
            $where   .= ' AND YEAR(c.created_at) = %d';
            $params[] = $year;
        }

        if ( $vendor_nit !== '' ) {
            $where   .= ' AND (c.vendor_nit = %s OR k.document_number = %s)';
            $params[] = $vendor_nit;
            $params[] = $vendor_nit;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    c.id,
                    c.order_id,
                    c.vendor_id,
                    c.gross_amount,
                    c.commission_amount    AS platform_fee,
                    c.vendor_amount,
                    c.iva_amount,
                    COALESCE(c.retefuente_amount, c.tax_withholding, 0) AS retefuente_amount,
                    COALESCE(c.retefuente_rate, 0)   AS retefuente_rate,
                    COALESCE(c.reteiva_amount, 0)    AS reteiva_amount,
                    COALESCE(c.reteiva_rate, 0)      AS reteiva_rate,
                    COALESCE(c.reteica_amount, 0)    AS reteica_amount,
                    COALESCE(c.reteica_rate, 0)      AS reteica_rate,
                    COALESCE(c.vendor_nit, k.document_number, '') AS vendor_nit,
                    COALESCE(c.vendor_regime, k.tax_regime, '')    AS vendor_regime,
                    COALESCE(c.vendor_ciiu, k.ciiu_code, '')       AS vendor_ciiu,
                    COALESCE(k.full_name, '')                      AS vendor_name,
                    COALESCE(k.bank_name, '')                      AS bank_name,
                    COALESCE(k.bank_account_number, '')            AS bank_account,
                    COALESCE(c.payment_method, '')                 AS payment_method,
                    COALESCE(c.cfdi_folio, '')                     AS invoice_folio,
                    c.created_at,
                    c.strategy_applied
                FROM `{$c}` c
                LEFT JOIN `{$k}` k ON k.vendor_id = c.vendor_id
                {$where}
                ORDER BY c.created_at ASC",
                ...$params
            ),
            ARRAY_A
        );
        // phpcs:enable

        if ( empty( $rows ) ) {
            return [
                'totals'       => array_fill_keys( [ 'gross', 'platform_fee', 'retefuente', 'reteiva', 'reteica', 'iva', 'net_to_vendors' ], 0.0 ),
                'by_vendor'    => [],
                'transactions' => [],
            ];
        }

        $totals = [
            'gross'         => 0.0,
            'platform_fee'  => 0.0,
            'retefuente'    => 0.0,
            'reteiva'       => 0.0,
            'reteica'       => 0.0,
            'iva'           => 0.0,
            'net_to_vendors'=> 0.0,
        ];
        $by_vendor = [];

        foreach ( $rows as &$row ) {
            // Cast to float.
            foreach ( [ 'gross_amount', 'platform_fee', 'vendor_amount', 'iva_amount', 'retefuente_amount', 'retefuente_rate', 'reteiva_amount', 'reteiva_rate', 'reteica_amount', 'reteica_rate' ] as $f ) {
                $row[ $f ] = (float) $row[ $f ];
            }

            $totals['gross']          += $row['gross_amount'];
            $totals['platform_fee']   += $row['platform_fee'];
            $totals['retefuente']     += $row['retefuente_amount'];
            $totals['reteiva']        += $row['reteiva_amount'];
            $totals['reteica']        += $row['reteica_amount'];
            $totals['iva']            += $row['iva_amount'];
            $totals['net_to_vendors'] += $row['vendor_amount'];

            // Agregar por vendedor.
            $vid = $row['vendor_id'];
            if ( ! isset( $by_vendor[ $vid ] ) ) {
                $by_vendor[ $vid ] = [
                    'vendor_id'     => $vid,
                    'vendor_nit'    => $row['vendor_nit'],
                    'vendor_name'   => $row['vendor_name'],
                    'vendor_regime' => $row['vendor_regime'],
                    'vendor_ciiu'   => $row['vendor_ciiu'],
                    'bank_name'     => $row['bank_name'],
                    'bank_account'  => $row['bank_account'],
                    'transactions'  => 0,
                    'gross'         => 0.0,
                    'platform_fee'  => 0.0,
                    'iva'           => 0.0,
                    'retefuente'    => 0.0,
                    'reteiva'       => 0.0,
                    'reteica'       => 0.0,
                    'net_received'  => 0.0,
                ];
            }

            $by_vendor[ $vid ]['transactions']++;
            $by_vendor[ $vid ]['gross']        += $row['gross_amount'];
            $by_vendor[ $vid ]['platform_fee'] += $row['platform_fee'];
            $by_vendor[ $vid ]['iva']          += $row['iva_amount'];
            $by_vendor[ $vid ]['retefuente']   += $row['retefuente_amount'];
            $by_vendor[ $vid ]['reteiva']      += $row['reteiva_amount'];
            $by_vendor[ $vid ]['reteica']      += $row['reteica_amount'];
            $by_vendor[ $vid ]['net_received'] += $row['vendor_amount'];
        }
        unset( $row );

        // Round totals.
        foreach ( $totals as &$t ) {
            $t = round( $t, 2 );
        }
        unset( $t );

        // Round vendor totals.
        $numeric_fields = [ 'gross', 'platform_fee', 'iva', 'retefuente', 'reteiva', 'reteica', 'net_received' ];
        foreach ( $by_vendor as &$v ) {
            foreach ( $numeric_fields as $f ) {
                $v[ $f ] = round( $v[ $f ], 2 );
            }
        }
        unset( $v );

        return [
            'totals'       => $totals,
            'by_vendor'    => array_values( $by_vendor ),
            'transactions' => $rows,
        ];
    }

    /**
     * Registra un acceso fiscal en la tabla lt_dian_online_access.
     *
     * @param string $access_type Tipo de acción.
     * @param array  $context     Contexto adicional.
     * @return void
     */
    private function log_fiscal_access( string $access_type, array $context ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_dian_online_access';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'session_token' => hash( 'sha256', ( $_COOKIE['ltms_dian_session'] ?? '' ) . wp_salt() ),
                'auditor_name'  => sanitize_text_field( $_SERVER['HTTP_X_AUDITOR_NAME'] ?? '' ),
                'auditor_nit'   => sanitize_text_field( $_SERVER['HTTP_X_AUDITOR_NIT'] ?? '' ),
                'access_type'   => $access_type,
                'filter_from'   => $context['filter_from'] ?? null,
                'filter_to'     => $context['filter_to'] ?? null,
                'filter_vendor' => $context['filter_vendor'] ?? null,
                'rows_returned' => (int) ( $context['rows_returned'] ?? 0 ),
                'ip_address'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
                'user_agent'    => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Obtiene los datos de ventas del período.
     *
     * @param string $period YYYY-MM
     * @return array
     */
    private function get_period_data( string $period ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
            $period
        ) );

        $total_sales   = 0.0;
        $total_tax     = 0.0;
        $vendor_set    = [];
        $records       = [];

        foreach ( $rows as $row ) {
            $total_sales   += (float) $row->gross_amount;
            $total_tax     += (float) ( $row->tax_withholding ?? 0 ) + (float) ( $row->iva_amount ?? 0 );
            $vendor_set[]   = $row->vendor_id;
            $records[]      = $row;
        }

        return [
            'total_sales'   => $total_sales,
            'total_tax'     => $total_tax,
            'total_vendors' => count( array_unique( $vendor_set ) ),
            'records'       => $records,
        ];
    }

    /**
     * Obtiene los datos de ventas anuales.
     *
     * @param int $year
     * @return array
     */
    private function get_annual_data( int $year ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commissions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE YEAR(created_at) = %d",
            $year
        ) );

        $total = 0.0;
        $vendors = [];
        foreach ( $rows as $row ) {
            $total      += (float) $row->gross_amount;
            $vendors[]   = $row->vendor_id;
        }

        return [
            'total_sales'   => $total,
            'total_vendors' => count( array_unique( $vendors ) ),
            'records'       => $rows,
        ];
    }

    /**
     * Almacena los datos del reporte en un transient y devuelve un token de descarga.
     *
     * @param array  $data     Datos del reporte.
     * @param string $filename Nombre base del archivo.
     * @return string Token de descarga.
     */
    private function store_report_data( array $data, string $filename ): string {
        $token = wp_generate_password( 32, false );
        set_transient( 'ltms_report_' . md5( $token ), [
            'data'     => $data,
            'filename' => sanitize_file_name( $filename ),
            'user_id'  => get_current_user_id(),
        ], 600 );
        return $token;
    }
}
