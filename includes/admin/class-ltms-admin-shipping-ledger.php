<?php
/**
 * LTMS Admin Shipping Ledger & Reconciliation Engine.
 *
 * Panel de administración para gestionar costos logísticos:
 *  - Dashboard con KPIs (P&L por carrier, varianza, disputas)
 *  - Ledger detallado filtrable (1 fila por servicio logístico)
 *  - Import de facturas de carriers (CSV)
 *  - Workflow de disputas
 *  - Presupuestos por vendedor
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    2.8.3
 * @since      2.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Shipping_Ledger
 */
class LTMS_Admin_Shipping_Ledger {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_submenu' ], 99 );
        add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_post_ltms_import_carrier_invoice', [ __CLASS__, 'handle_csv_upload' ] );
        add_action( 'admin_post_ltms_save_vendor_budget', [ __CLASS__, 'handle_budget_save' ] );
        add_action( 'admin_post_ltms_resolve_dispute', [ __CLASS__, 'handle_dispute_resolution' ] );
        add_action( 'admin_post_ltms_open_dispute', [ __CLASS__, 'handle_open_dispute' ] );

        // AJAX inline actions.
        add_action( 'wp_ajax_ltms_ledger_open_dispute', [ __CLASS__, 'ajax_open_dispute' ] );
        add_action( 'wp_ajax_ltms_ledger_resolve_dispute', [ __CLASS__, 'ajax_resolve_dispute' ] );
        add_action( 'wp_ajax_ltms_ledger_save_budget', [ __CLASS__, 'ajax_save_budget' ] );
    }

    public static function add_submenu(): void {
        add_submenu_page(
            'ltms-dashboard',
            __( 'Logística / Costos', 'ltms' ),
            __( 'Logística / Costos', 'ltms' ),
            'ltms_view_wallet_ledger',
            'ltms-shipping-ledger',
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Router principal: muestra una de 5 pestañas.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'ltms_view_wallet_ledger' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ltms' ) );
        }

        $tab   = sanitize_key( $_GET['tab'] ?? 'dashboard' );
        $valid = [ 'dashboard', 'ledger', 'invoices', 'disputes', 'budgets' ];
        if ( ! in_array( $tab, $valid, true ) ) {
            $tab = 'dashboard';
        }

        // Cargar la vista.
        // v2.9.31: usar LTMS_PLUGIN_DIR (constante definida en lt-marketplace-suite.php linea 67)
        // ANTES usaba LTMS_PATH que NO existe → fatal error "Undefined constant".
        $view_path = LTMS_PLUGIN_DIR . 'includes/admin/views/html-admin-shipping-ledger.php';
        if ( file_exists( $view_path ) ) {
            include $view_path;
        } else {
            echo '<div class="wrap"><p>' . esc_html__( 'Vista no encontrada.', 'ltms' ) . '</p></div>';
        }
    }

    // =====================================================================
    // HANDLERS — POST actions
    // =====================================================================

    /**
     * Handler unificado para acciones GET (resolver disputa, etc.).
     */
    public static function handle_actions(): void {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ltms-shipping-ledger' ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] );
        $nonce  = sanitize_text_field( $_GET['_wpnonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'ltms_ledger_' . $action ) ) {
            return;
        }

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            return;
        }

        switch ( $action ) {
            case 'resolve_dispute':
                self::do_resolve_dispute();
                break;
            case 'open_dispute':
                self::do_open_dispute();
                break;
        }
    }

    /**
     * Maneja la subida de CSV de factura de carrier.
     */
    public static function handle_csv_upload(): void {
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ltms' ) );
        }

        check_admin_referer( 'ltms_import_carrier_invoice' );

        $carrier = sanitize_key( $_POST['carrier'] ?? '' );
        if ( ! $carrier ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'invoices', 'error' => 'no_carrier' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( empty( $_FILES['invoice_csv'] ) || $_FILES['invoice_csv']['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'invoices', 'error' => 'no_file' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Validar tipo MIME.
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $_FILES['invoice_csv']['tmp_name'] );
        finfo_close( $finfo );
        $allowed = [ 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ];
        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'invoices', 'error' => 'invalid_mime' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Mover a ubicación temporal.
        $tmp_path = $_FILES['invoice_csv']['tmp_name'];
        $filename = sanitize_file_name( $_FILES['invoice_csv']['name'] );

        // Parsear.
        $parsed = LTMS_Shipping_Cost_Ledger::parse_carrier_invoice_csv( $tmp_path );
        if ( ! empty( $parsed['errors'] ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'invoices', 'error' => 'parse', 'msg' => urlencode( implode( '|', $parsed['errors'] ) ) ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Importar.
        $result = LTMS_Shipping_Cost_Ledger::import_carrier_invoice(
            $carrier,
            $parsed['invoice_data'],
            $parsed['lines'],
            get_current_user_id(),
            $filename
        );

        $params = [
            'page'           => 'ltms-shipping-ledger',
            'tab'            => 'invoices',
            'imported'       => 1,
            'invoice_id'     => $result['invoice_id'],
            'lines_matched'  => $result['lines_matched'],
            'lines_unmatched'=> $result['lines_unmatched'],
            'variance_total' => $result['variance_total'],
        ];
        wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Guarda el presupuesto mensual de un vendor.
     */
    public static function handle_budget_save(): void {
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ltms' ) );
        }

        check_admin_referer( 'ltms_save_vendor_budget' );

        $vendor_id        = (int) $_POST['vendor_id'];
        $period_year      = (int) $_POST['period_year'];
        $period_month     = (int) $_POST['period_month'];
        $budget_limit     = (float) $_POST['budget_limit'];
        $soft_threshold   = (float) $_POST['soft_threshold'];
        $hard_threshold   = (float) $_POST['hard_threshold'];
        $notes            = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $vendor_id || ! $period_year || ! $period_month ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'budgets', 'error' => 'missing_fields' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_shipping_budgets';

        // Upsert.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE vendor_id = %d AND period_year = %d AND period_month = %d",
            $vendor_id, $period_year, $period_month
        ) );

        if ( $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, [
                'budget_limit'    => $budget_limit,
                'soft_threshold'  => $soft_threshold,
                'hard_threshold'  => $hard_threshold,
                'notes'           => $notes,
                'updated_at'      => current_time( 'mysql', true ),
            ], [ 'id' => (int) $existing ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, [
                'vendor_id'       => $vendor_id,
                'period_year'     => $period_year,
                'period_month'    => $period_month,
                'budget_limit'    => $budget_limit,
                'soft_threshold'  => $soft_threshold,
                'hard_threshold'  => $hard_threshold,
                'notes'           => $notes,
            ] );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'budgets', 'saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Resuelve una disputa vía POST (form submit).
     */
    public static function handle_dispute_resolution(): void {
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ltms' ) );
        }

        check_admin_referer( 'ltms_resolve_dispute' );

        $dispute_id     = (int) $_POST['dispute_id'];
        $status         = sanitize_key( $_POST['status'] );
        $credit_amount  = (float) $_POST['credit_amount'];
        $notes          = sanitize_textarea_field( $_POST['resolution_notes'] ?? '' );

        LTMS_Shipping_Cost_Ledger::resolve_dispute(
            $dispute_id,
            $status,
            $credit_amount,
            $notes,
            get_current_user_id()
        );

        wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'disputes', 'resolved' => $dispute_id ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Abre una disputa vía POST (form submit).
     */
    public static function handle_open_dispute(): void {
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'ltms' ) );
        }

        check_admin_referer( 'ltms_open_dispute' );

        $dispute_id = LTMS_Shipping_Cost_Ledger::open_dispute( [
            'ledger_id'        => (int) $_POST['ledger_id'],
            'invoice_id'       => (int) ( $_POST['invoice_id'] ?? 0 ),
            'invoice_line_id'  => (int) ( $_POST['invoice_line_id'] ?? 0 ),
            'dispute_type'     => sanitize_key( $_POST['dispute_type'] ),
            'dispute_reason'   => sanitize_text_field( $_POST['dispute_reason'] ),
            'evidence_url'     => esc_url_raw( $_POST['evidence_url'] ?? '' ),
            'expected_amount'  => (float) $_POST['expected_amount'],
            'disputed_amount'  => (float) $_POST['disputed_amount'],
            'opened_by'        => get_current_user_id(),
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => 'ltms-shipping-ledger', 'tab' => 'disputes', 'opened' => $dispute_id ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // =====================================================================
    // AJAX handlers (inline actions)
    // =====================================================================

    public static function ajax_open_dispute(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $dispute_id = LTMS_Shipping_Cost_Ledger::open_dispute( [
            'ledger_id'       => (int) $_POST['ledger_id'],
            'dispute_type'    => sanitize_key( $_POST['dispute_type'] ?? 'other' ),
            'dispute_reason'  => sanitize_text_field( $_POST['dispute_reason'] ?? '' ),
            'expected_amount' => (float) $_POST['expected_amount'],
            'disputed_amount' => (float) $_POST['disputed_amount'],
            'opened_by'       => get_current_user_id(),
        ] );

        if ( $dispute_id ) {
            wp_send_json_success( [ 'dispute_id' => $dispute_id ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudo abrir la disputa.', 'ltms' ) ] );
        }
    }

    public static function ajax_resolve_dispute(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $ok = LTMS_Shipping_Cost_Ledger::resolve_dispute(
            (int) $_POST['dispute_id'],
            sanitize_key( $_POST['status'] ),
            (float) $_POST['credit_amount'],
            sanitize_text_field( $_POST['notes'] ?? '' ),
            get_current_user_id()
        );

        if ( $ok ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => __( 'Disputa no encontrada.', 'ltms' ) ] );
        }
    }

    public static function ajax_save_budget(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        // Reutilizar handler POST.
        $_POST = wp_unslash( $_POST );
        self::handle_budget_save();
    }

    // =====================================================================
    // HELPERS para la vista
    // =====================================================================

    /**
     * Obtiene los datos para la pestaña dashboard.
     */
    public static function get_dashboard_data( string $period = 'month' ): array {
        return LTMS_Shipping_Cost_Ledger::get_kpis( $period );
    }

    /**
     * Obtiene los datos para la pestaña ledger.
     */
    public static function get_ledger_data( array $filters = [] ): array {
        return LTMS_Shipping_Cost_Ledger::get_entries( $filters );
    }

    /**
     * Obtiene las facturas importadas.
     */
    public static function get_invoices( int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_carrier_invoices';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY imported_at DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Obtiene las disputas.
     */
    public static function get_disputes( string $status_filter = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_disputes';
        $where = '';
        $args  = [];
        if ( $status_filter && in_array( $status_filter, [ 'open', 'in_review', 'approved', 'rejected', 'credited', 'expired' ], true ) ) {
            $where = 'WHERE status = %s';
            $args[] = $status_filter;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` {$where} ORDER BY opened_at DESC LIMIT 200", $args ),
            ARRAY_A
        );
    }

    /**
     * Obtiene los presupuestos.
     */
    public static function get_budgets( int $year = 0, int $month = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_shipping_budgets';
        if ( ! $year )  $year  = (int) current_time( 'Y' );
        if ( ! $month ) $month = (int) current_time( 'n' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE period_year = %d AND period_month = %d ORDER BY spent_pct DESC",
            $year, $month
        ), ARRAY_A );
    }

    /**
     * Obtiene el nombre visible de un vendor.
     */
    public static function get_vendor_display_name( int $vendor_id ): string {
        if ( $vendor_id <= 0 ) {
            return __( 'Plataforma', 'ltms' );
        }
        $user = get_user_by( 'ID', $vendor_id );
        return $user ? $user->display_name : sprintf( __( 'Vendor #%d', 'ltms' ), $vendor_id );
    }

    /**
     * Formatea un monto como moneda COP/MXN.
     */
    public static function format_currency( float $amount, string $currency = '' ): string {
        if ( ! $currency ) {
            $currency = LTMS_Core_Config::get_currency();
        }
        $symbol = ( $currency === 'MXN' ) ? '$' : '$';
        return $symbol . ' ' . number_format( $amount, 0, ',', '.' );
    }

    /**
     * Badge HTML para un status del ledger.
     */
    public static function status_badge( string $status ): string {
        $colors = [
            'quoted'     => '#6b7280', // gray
            'shipped'    => '#3b82f6', // blue
            'delivered'  => '#16a34a', // green
            'invoiced'   => '#0d9488', // teal
            'disputed'   => '#f97316', // orange
            'reconciled' => '#166534', // darkgreen
            'writeoff'   => '#dc2626', // red
        ];
        $color = $colors[ $status ] ?? '#6b7280';
        $labels = [
            'quoted'     => __( 'Cotizado', 'ltms' ),
            'shipped'    => __( 'Enviado', 'ltms' ),
            'delivered'  => __( 'Entregado', 'ltms' ),
            'invoiced'   => __( 'Facturado', 'ltms' ),
            'disputed'   => __( 'En disputa', 'ltms' ),
            'reconciled' => __( 'Conciliado', 'ltms' ),
            'writeoff'   => __( 'Pérdida', 'ltms' ),
        ];
        $label = $labels[ $status ] ?? $status;
        return sprintf(
            '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    /**
     * URL a una pestaña específica.
     */
    public static function tab_url( string $tab, array $extra = [] ): string {
        $params = array_merge( [ 'page' => 'ltms-shipping-ledger', 'tab' => $tab ], $extra );
        return add_query_arg( $params, admin_url( 'admin.php' ) );
    }
}
