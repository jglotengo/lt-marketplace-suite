<?php
/**
 * LTMS Dashboard Logic - Lógica del Panel de Vendedor
 *
 * Maneja toda la lógica PHP del dashboard SPA del vendedor:
 * - Renderizado del wrapper principal
 * - Handlers AJAX para las vistas del SPA
 * - Shortcode [ltms_vendor_dashboard]
 * - Handlers de formularios (producto, retiro, configuración)
 * - Endpoints REST para el panel
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Dashboard_Logic
 */
final class LTMS_Dashboard_Logic {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks de WordPress.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        // Shortcode del dashboard
        add_shortcode( 'ltms_vendor_dashboard', [ $instance, 'render_dashboard_shortcode' ] );

        // AJAX handlers autenticados
        add_action( 'wp_ajax_ltms_get_dashboard_data',    [ $instance, 'ajax_get_dashboard_data' ] );
        add_action( 'wp_ajax_ltms_get_orders_data',       [ $instance, 'ajax_get_orders_data' ] );
        add_action( 'wp_ajax_ltms_get_wallet_data',       [ $instance, 'ajax_get_wallet_data' ] );
        add_action( 'wp_ajax_ltms_request_payout',        [ $instance, 'ajax_request_payout' ] );
        add_action( 'wp_ajax_ltms_get_notifications',     [ $instance, 'ajax_get_notifications' ] );
        add_action( 'wp_ajax_ltms_mark_notification_read', [ $instance, 'ajax_mark_notification_read' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings',  [ $instance, 'ajax_save_vendor_settings' ] );
        add_action( 'wp_ajax_ltms_get_analytics_data',    [ $instance, 'ajax_get_analytics_data' ] );

        // v1.6.0 — Nuevos módulos enterprise
        add_action( 'wp_ajax_ltms_get_insurance_data',    [ $instance, 'ajax_get_insurance_data' ] );
        add_action( 'wp_ajax_ltms_get_redi_data',         [ $instance, 'ajax_get_redi_data' ] );
        add_action( 'wp_ajax_ltms_adopt_redi_product',    [ $instance, 'ajax_adopt_redi_product' ] );
        add_action( 'wp_ajax_ltms_get_shipping_quotes',   [ $instance, 'ajax_get_shipping_quotes' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_shipping_quotes', [ $instance, 'ajax_get_shipping_quotes' ] );

        // REST API endpoints del vendor dashboard
        add_action( 'rest_api_init', [ $instance, 'register_rest_routes' ] );
    }

    /**
     * Renderiza el shortcode del dashboard.
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML del dashboard.
     */
    public function render_dashboard_shortcode( array $atts = [] ): string {
        // Verificar que el usuario esté autenticado y sea vendedor
        if ( ! is_user_logged_in() ) {
            return $this->render_login_redirect();
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            return $this->render_not_vendor_notice();
        }

        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/dashboard-wrapper.php';
        if ( file_exists( $view_path ) ) {
            include $view_path;
        }
        return ob_get_clean();
    }

    /**
     * AJAX: Datos del Home del dashboard (métricas, ventas recientes).
     *
     * @return void
     */
    public function ajax_get_dashboard_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $data = $this->get_vendor_home_metrics( $user_id );
        wp_send_json_success( $data );
    }

    /**
     * AJAX: Datos de pedidos del vendedor con paginación.
     *
     * @return void
     */
    public function ajax_get_orders_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) ); // phpcs:ignore
        $per_page = min( 50, max( 10, (int) ( $_POST['per_page'] ?? 20 ) ) ); // phpcs:ignore
        $status   = sanitize_text_field( $_POST['status'] ?? '' ); // phpcs:ignore

        $orders = $this->get_vendor_orders( $user_id, $page, $per_page, $status );
        wp_send_json_success( $orders );
    }

    /**
     * AJAX: Datos de la billetera y movimientos.
     *
     * @return void
     */
    public function ajax_get_wallet_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $wallet       = LTMS_Wallet::get_or_create( $user_id );
        $transactions = $this->get_wallet_transactions( $user_id );

        wp_send_json_success([
            'balance'      => (float) $wallet['balance'],
            'held'         => (float) $wallet['held_balance'],
            'available'    => (float) $wallet['balance'] - (float) $wallet['held_balance'],
            'currency'     => LTMS_Core_Config::get_currency(),
            'formatted'    => LTMS_Utils::format_money( (float) $wallet['balance'] ),
            'transactions' => $transactions,
        ]);
    }

    /**
     * AJAX: Solicitar retiro de fondos.
     *
     * @return void
     */
    public function ajax_request_payout(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id    = get_current_user_id();
        $amount     = (float) ( $_POST['amount'] ?? 0 ); // phpcs:ignore
        $account_id = sanitize_text_field( $_POST['bank_account_id'] ?? '' ); // phpcs:ignore
        $method     = sanitize_text_field( $_POST['method'] ?? 'bank_transfer' ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) || $amount <= 0 ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::create_request( $user_id, $amount, $account_id, $method );
        wp_send_json( $result );
    }

    /**
     * AJAX: Obtener notificaciones no leídas.
     *
     * @return void
     */
    public function ajax_get_notifications(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $since   = sanitize_text_field( $_POST['since'] ?? '' ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        $where_sql = 'WHERE user_id = %d AND is_read = 0';
        $args      = [ $user_id ];

        if ( $since ) {
            $where_sql .= ' AND created_at > %s';
            $args[]     = $since;
        }

        $args[] = 20; // LIMIT placeholder

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, title, message, data, created_at FROM `{$table}` {$where_sql} ORDER BY created_at DESC LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );

        wp_send_json_success([
            'notifications' => $notifications,
            'count'         => count( $notifications ),
        ]);
    }

    /**
     * AJAX: Marcar notificación como leída.
     *
     * @return void
     */
    public function ajax_mark_notification_read(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id         = get_current_user_id();
        $notification_id = (int) ( $_POST['notification_id'] ?? 0 ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'is_read' => 1, 'read_at' => LTMS_Utils::now_utc() ],
            [ 'id' => $notification_id, 'user_id' => $user_id ],
            [ '%d', '%s' ],
            [ '%d', '%d' ]
        );

        wp_send_json_success();
    }

    /**
     * AJAX: Guardar configuración del vendedor.
     *
     * @return void
     */
    public function ajax_save_vendor_settings(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id  = get_current_user_id();
        $settings = $_POST['settings'] ?? []; // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) || ! is_array( $settings ) ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        // Campos permitidos para actualización directa
        $allowed_fields = [
            'ltms_store_name', 'ltms_store_description', 'ltms_store_phone',
            'ltms_bank_name', 'ltms_bank_account_type', 'ltms_payment_method',
            'ltms_shipping_policy', 'ltms_return_policy',
        ];

        foreach ( $allowed_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                update_user_meta( $user_id, $field, sanitize_text_field( $settings[ $field ] ) );
            }
        }

        // Campos sensibles (requieren cifrado)
        if ( ! empty( $settings['ltms_bank_account_number'] ) ) {
            update_user_meta(
                $user_id,
                'ltms_bank_account_number',
                LTMS_Core_Security::encrypt( sanitize_text_field( $settings['ltms_bank_account_number'] ) )
            );
        }

        wp_send_json_success( [ 'message' => __( 'Configuración guardada exitosamente.', 'ltms' ) ] );
    }

    /**
     * AJAX: Datos analíticos del vendedor (gráficas).
     *
     * @return void
     */
    public function ajax_get_analytics_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $period  = sanitize_text_field( $_POST['period'] ?? 'month' ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $data = $this->build_analytics_chart_data( $user_id, $period );
        wp_send_json_success( $data );
    }

    /**
     * Registra los endpoints REST del dashboard del vendedor.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/vendor/metrics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_metrics' ],
            'permission_callback' => fn() => LTMS_Utils::is_ltms_vendor( get_current_user_id() ),
        ]);
    }

    /**
     * REST: Métricas del vendedor.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function rest_get_metrics( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $data    = $this->get_vendor_home_metrics( $user_id );
        return new \WP_REST_Response( $data, 200 );
    }

    // ── Helpers privados de datos ──────────────────────────────────

    /**
     * Construye las métricas de la home del dashboard.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array
     */
    private function get_vendor_home_metrics( int $vendor_id ): array {
        global $wpdb;

        $commissions_table = $wpdb->prefix . 'lt_commissions';
        $now               = LTMS_Utils::now_utc();
        $month_start       = gmdate( 'Y-m-01 00:00:00' );

        // Ventas del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_sales = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(gross_amount) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Total de pedidos del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_orders = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Comisiones del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_commissions = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(vendor_net) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Billetera
        $wallet = LTMS_Wallet::get_or_create( $vendor_id );

        return [
            'monthly_sales'       => $monthly_sales,
            'monthly_orders'      => $monthly_orders,
            'monthly_commissions' => $monthly_commissions,
            'wallet_balance'      => (float) $wallet['balance'],
            'wallet_held'         => (float) $wallet['held_balance'],
            'currency'            => LTMS_Core_Config::get_currency(),
            'commission_rate_summary' => class_exists( 'LTMS_Commission_Strategy' )
                ? LTMS_Commission_Strategy::get_rate_summary( $vendor_id )
                : [],
        ];
    }

    /**
     * Obtiene los pedidos del vendedor paginados.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param int    $page      Página.
     * @param int    $per_page  Items por página.
     * @param string $status    Filtro de estado.
     * @return array
     */
    private function get_vendor_orders( int $vendor_id, int $page, int $per_page, string $status ): array {
        $args = [
            'meta_key'    => '_ltms_vendor_id',
            'meta_value'  => $vendor_id,
            'limit'       => $per_page,
            'paged'       => $page,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'type'        => 'shop_order',
        ];

        if ( $status ) {
            $args['status'] = sanitize_text_field( $status );
        }

        $orders_query = wc_get_orders( $args );
        $orders       = [];

        foreach ( $orders_query as $order ) {
            $orders[] = [
                'id'         => $order->get_id(),
                'number'     => $order->get_order_number(),
                'status'     => $order->get_status(),
                'total'      => (float) $order->get_total(),
                'formatted'  => LTMS_Utils::format_money( (float) $order->get_total() ),
                'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'date'       => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                'items_count' => count( $order->get_items() ),
            ];
        }

        return [
            'orders'  => $orders,
            'total'   => count( $orders_query ),
            'page'    => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Obtiene los movimientos de la billetera del vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array
     */
    private function get_wallet_transactions( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, amount, description, created_at FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
                $vendor_id
            ),
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            $row['formatted_amount'] = LTMS_Utils::format_money( (float) $row['amount'] );
        }

        return $rows;
    }

    /**
     * Construye los datos para gráficas del vendedor.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $period    Período.
     * @return array
     */
    private function build_analytics_chart_data( int $vendor_id, string $period ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commissions';

        // Últimos 12 meses o últimas 4 semanas
        $labels = [];
        $sales  = [];
        $commissions = [];

        if ( $period === 'month' ) {
            for ( $i = 11; $i >= 0; $i-- ) {
                $date    = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
                $labels[] = $date;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sales[]  = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(gross_amount) FROM `{$table}` WHERE vendor_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
                    $vendor_id, $date
                ));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $commissions[] = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(vendor_net) FROM `{$table}` WHERE vendor_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
                    $vendor_id, $date
                ));
            }
        }

        return [
            'labels'      => $labels,
            'sales'       => $sales,
            'commissions' => $commissions,
        ];
    }

    // ── v1.6.0 AJAX handlers ──────────────────────────────────────

    /**
     * AJAX: Datos de pólizas de seguro del vendedor.
     *
     * @return void
     */
    public function ajax_get_insurance_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_insurance_policies';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $policies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_id, policy_id, policy_number, certificate_url, insurance_type, premium_amount, currency, status, created_at FROM `{$table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 20",
                $user_id
            ),
            ARRAY_A
        );

        wp_send_json_success( [ 'policies' => $policies ] );
    }

    /**
     * AJAX: Datos ReDi del vendedor (acuerdos + comisiones).
     *
     * @return void
     */
    public function ajax_get_redi_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        global $wpdb;
        // My active ReDi agreements (as reseller)
        $agreements = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT a.*, p.post_title AS origin_product_name FROM `{$wpdb->prefix}lt_redi_agreements` a LEFT JOIN `{$wpdb->posts}` p ON a.origin_product_id = p.ID WHERE a.reseller_vendor_id = %d AND a.status = 'active' LIMIT 50",
                $user_id
            ),
            ARRAY_A
        );

        // Origin products available for ReDi adoption
        $available = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT p.ID, p.post_title, pm.meta_value AS redi_rate FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` pm ON p.ID = pm.post_id AND pm.meta_key = '_ltms_redi_rate' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value != '' LIMIT 50",
            ARRAY_A
        );

        wp_send_json_success( [ 'agreements' => $agreements, 'available_products' => $available ] );
    }

    /**
     * AJAX: Adoptar un producto ReDi (como revendedor).
     *
     * @return void
     */
    public function ajax_adopt_redi_product(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id    = get_current_user_id();
        $product_id = absint( $_POST['origin_product_id'] ?? 0 ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) || ! $product_id ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        if ( ! class_exists( 'LTMS_Business_Redi_Manager' ) ) {
            wp_send_json_error( __( 'Módulo ReDi no disponible.', 'ltms' ) );
        }

        try {
            $new_pid = LTMS_Business_Redi_Manager::adopt_product( $user_id, $product_id );
            wp_send_json_success( [
                'product_id' => $new_pid,
                'message'    => __( 'Producto adoptado como ReDi exitosamente.', 'ltms' ),
            ] );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'REDI_ADOPT_FAILED', $e->getMessage() );
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Cotizaciones de envío para comparación en checkout.
     *
     * @return void
     */
    public function ajax_get_shipping_quotes(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );

        $cart    = WC()->cart;
        $session = WC()->session;
        if ( ! $cart || ! $session ) {
            wp_send_json_error( __( 'Carrito no disponible.', 'ltms' ) );
        }

        $packages  = $cart->get_shipping_packages();
        $package   = reset( $packages );
        $quotes    = [];
        $providers = [
            'uber'      => [ 'class' => 'LTMS_Shipping_Method_Uber_Direct', 'label' => 'Uber Direct' ],
            'aveonline' => [ 'class' => 'LTMS_Shipping_Method_Aveonline',   'label' => 'Aveonline' ],
            'heka'      => [ 'class' => 'LTMS_Shipping_Method_Heka',        'label' => 'Heka Entrega' ],
            'pickup'    => [ 'class' => 'LTMS_Shipping_Method_Pickup',      'label' => 'Recogida en Tienda' ],
        ];

        foreach ( $providers as $slug => $info ) {
            if ( ! class_exists( $info['class'] ) ) continue;
            try {
                $method = new $info['class']();
                $method->calculate_shipping( $package ?? [] );
                $rates  = $method->get_rates_for_package( $package ?? [] );
                $rate   = reset( $rates );
                if ( $rate ) {
                    $quotes[ $slug ] = [
                        'price'         => (float) $rate->get_cost(),
                        'price_display' => LTMS_Utils::format_money( (float) $rate->get_cost() ),
                        'label'         => $rate->get_label(),
                        'rate_id'       => $rate->get_id(),
                    ];
                }
            } catch ( \Throwable $e ) {
                // Provider unavailable — skip silently
            }
        }

        // Pickup always available at $0
        if ( ! isset( $quotes['pickup'] ) ) {
            $quotes['pickup'] = [
                'price'         => 0,
                'price_display' => __( 'Gratis', 'ltms' ),
                'label'         => __( 'Recogida en Tienda', 'ltms' ),
                'rate_id'       => 'ltms_pickup',
            ];
        }

        wp_send_json_success( $quotes );
    }

    /**
     * Renderiza un mensaje de redirección al login.
     *
     * @return string
     */
    private function render_login_redirect(): string {
        $pages    = get_option( 'ltms_installed_pages', [] );
        $login_id = $pages['ltms-login'] ?? 0;
        $login_url = $login_id ? get_permalink( $login_id ) : wp_login_url( get_permalink() );

        return sprintf(
            '<div class="ltms-notice ltms-notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'Debes iniciar sesión para acceder al panel.', 'ltms' ),
            esc_url( $login_url ),
            esc_html__( 'Iniciar sesión', 'ltms' )
        );
    }

    /**
     * Renderiza un aviso para usuarios que no son vendedores.
     *
     * @return string
     */
    private function render_not_vendor_notice(): string {
        $pages       = get_option( 'ltms_installed_pages', [] );
        $register_id = $pages['ltms-register'] ?? 0;
        $register_url = $register_id ? get_permalink( $register_id ) : '';

        $msg = esc_html__( 'Esta página es exclusiva para vendedores registrados.', 'ltms' );
        if ( $register_url ) {
            $msg .= sprintf(
                ' <a href="%s">%s</a>',
                esc_url( $register_url ),
                esc_html__( 'Regístrate como vendedor', 'ltms' )
            );
        }

        return '<div class="ltms-notice ltms-notice-warning"><p>' . $msg . '</p></div>';
    }
}
