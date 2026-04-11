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
     * AJAX: genera el reporte DIAN Colombia (Exógena).
     *
     * @return void
     */
    public function ajax_generate_dian_report(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $year = absint( $_POST['year'] ?? gmdate( 'Y' ) );
        $data = $this->get_annual_data( $year );

        wp_send_json_success( [
            'year'           => $year,
            'total_sales'    => $data['total_sales'],
            'total_vendors'  => $data['total_vendors'],
            'download_token' => $this->store_report_data( $data, 'dian_' . $year ),
        ] );
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
            $total_tax     += (float) ( $row->tax_amount ?? 0 );
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
