<?php
/**
 * LTMS Admin - Controlador Principal del Backend
 *
 * Registra los menús de administración, carga assets del backend,
 * y coordina todos los sub-controladores de admin.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin
 */
final class LTMS_Admin {

    use LTMS_Logger_Aware;

    /**
     * Inicializa el controlador de admin.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'admin_menu',    [ $instance, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_assets' ] );
        add_action( 'admin_init',    [ $instance, 'handle_activation_redirect' ] );
        add_filter( 'plugin_action_links_' . LTMS_PLUGIN_BASENAME, [ $instance, 'add_plugin_links' ] );
        add_action( 'admin_notices', [ $instance, 'render_admin_notices' ] );
    }

    /**
     * Registra la estructura completa de menús del plugin.
     *
     * @return void
     */
    public function register_menus(): void {
        // Menú principal
        add_menu_page(
            __( 'LT Marketplace Suite', 'ltms' ),
            __( 'LT Marketplace', 'ltms' ),
            'ltms_access_dashboard',
            'ltms-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-store',
            30
        );

        // Submenús
        $submenus = [
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Dashboard', 'ltms' ),
                'menu_title' => __( 'Dashboard', 'ltms' ),
                'capability' => 'ltms_access_dashboard',
                'slug'       => 'ltms-dashboard',
                'callback'   => [ $this, 'render_dashboard' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Vendedores', 'ltms' ),
                'menu_title' => __( 'Vendedores', 'ltms' ),
                'capability' => 'ltms_manage_all_vendors',
                'slug'       => 'ltms-vendors',
                'callback'   => [ $this, 'render_vendors' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Pedidos', 'ltms' ),
                'menu_title' => __( 'Pedidos', 'ltms' ),
                'capability' => 'ltms_view_all_orders',
                'slug'       => 'ltms-orders',
                'callback'   => [ $this, 'render_orders' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Billeteras', 'ltms' ),
                'menu_title' => __( 'Billeteras', 'ltms' ),
                'capability' => 'ltms_view_wallet_ledger',
                'slug'       => 'ltms-wallets',
                'callback'   => [ $this, 'render_wallets' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Retiros', 'ltms' ),
                'menu_title' => __( 'Retiros', 'ltms' ),
                'capability' => 'ltms_approve_payouts',
                'slug'       => 'ltms-payouts',
                'callback'   => [ $this, 'render_payouts' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'KYC / Documentos', 'ltms' ),
                'menu_title' => __( 'KYC', 'ltms' ),
                'capability' => 'ltms_manage_kyc',
                'slug'       => 'ltms-kyc',
                'callback'   => [ $this, 'render_kyc' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Reportes Fiscales', 'ltms' ),
                'menu_title' => __( 'Reportes Fiscales', 'ltms' ),
                'capability' => 'ltms_view_tax_reports',
                'slug'       => 'ltms-tax-reports',
                'callback'   => [ $this, 'render_tax_reports' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Marketing', 'ltms' ),
                'menu_title' => __( 'Marketing', 'ltms' ),
                'capability' => 'ltms_manage_platform_settings',
                'slug'       => 'ltms-marketing',
                'callback'   => [ $this, 'render_marketing' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Seguridad / Logs', 'ltms' ),
                'menu_title' => __( 'Seguridad', 'ltms' ),
                'capability' => 'ltms_view_security_logs',
                'slug'       => 'ltms-security',
                'callback'   => [ $this, 'render_security' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Configuración', 'ltms' ),
                'menu_title' => __( 'Configuración', 'ltms' ),
                'capability' => 'ltms_manage_platform_settings',
                'slug'       => 'ltms-settings',
                'callback'   => [ $this, 'render_settings' ],
            ],
            // v1.6.0 Enterprise submenus
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Para Recogida', 'ltms' ),
                'menu_title' => __( 'Para Recogida', 'ltms' ),
                'capability' => 'ltms_view_all_orders',
                'slug'       => 'ltms-pickup-orders',
                'callback'   => [ $this, 'render_pickup_orders' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'Seguros XCover', 'ltms' ),
                'menu_title' => __( 'Seguros', 'ltms' ),
                'capability' => 'ltms_view_all_orders',
                'slug'       => 'ltms-xcover-policies',
                'callback'   => [ $this, 'render_xcover_policies' ],
            ],
            [
                'parent'     => 'ltms-dashboard',
                'page_title' => __( 'ReDi — Revendedores', 'ltms' ),
                'menu_title' => __( 'ReDi', 'ltms' ),
                'capability' => 'ltms_manage_all_vendors',
                'slug'       => 'ltms-redi',
                'callback'   => [ $this, 'render_redi' ],
            ],
        ];

        foreach ( $submenus as $submenu ) {
            add_submenu_page(
                $submenu['parent'],
                $submenu['page_title'],
                $submenu['menu_title'],
                $submenu['capability'],
                $submenu['slug'],
                $submenu['callback']
            );
        }

        // Menú especial para Auditor Externo
        if ( current_user_can( 'ltms_access_auditor_dashboard' ) ) {
            add_menu_page(
                __( 'Panel Auditor LTMS', 'ltms' ),
                __( 'Auditoría LTMS', 'ltms' ),
                'ltms_access_auditor_dashboard',
                'ltms-auditor',
                [ $this, 'render_auditor_dashboard' ],
                'dashicons-shield-alt',
                35
            );
        }
    }

    /**
     * Carga los assets del backend en páginas del plugin.
     *
     * @param string $hook_suffix Hook de la página actual.
     * @return void
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // Solo cargar en páginas del plugin
        if ( strpos( $hook_suffix, 'ltms' ) === false && strpos( $hook_suffix, 'toplevel_page_ltms' ) === false ) {
            return;
        }

        $ver = LTMS_VERSION;
        $url = LTMS_ASSETS_URL;

        wp_enqueue_style( 'ltms-admin', $url . 'css/ltms-admin.css', [], $ver );
        wp_enqueue_style( 'ltms-admin-enterprise', $url . 'css/ltms-admin-enterprise.css', [], $ver );

        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4/dist/chart.umd.min.js', [], '4.4.0', true );
        wp_enqueue_script( 'ltms-admin', $url . 'js/ltms-admin.js', [ 'jquery', 'wp-util', 'chart-js' ], $ver, true );

        wp_localize_script( 'ltms-admin', 'ltmsAdmin', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'rest_url'  => rest_url( 'ltms/v1' ),
            'nonce'     => wp_create_nonce( 'ltms_admin_nonce' ),
            'currency'  => LTMS_Core_Config::get_currency(),
            'country'   => LTMS_Core_Config::get_country(),
            'version'   => LTMS_VERSION,
            'i18n'      => [
                'confirm_delete' => __( '¿Confirmas que deseas eliminar este elemento?', 'ltms' ),
                'processing'     => __( 'Procesando...', 'ltms' ),
                'error_generic'  => __( 'Ocurrió un error. Por favor intenta de nuevo.', 'ltms' ),
                'success'        => __( '¡Operación exitosa!', 'ltms' ),
            ],
        ]);
    }

    /**
     * Redirige al wizard de configuración inicial tras la activación.
     *
     * @return void
     */
    public function handle_activation_redirect(): void {
        if ( get_option( 'ltms_activation_redirect', false ) ) {
            delete_option( 'ltms_activation_redirect' );

            if ( ! is_multisite() && ! isset( $_GET['activate-multi'] ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=ltms-settings&ltms_welcome=1' ) );
                exit;
            }
        }
    }

    /**
     * Agrega links de acción rápida en la lista de plugins.
     *
     * @param array $links Links actuales.
     * @return array
     */
    public function add_plugin_links( array $links ): array {
        $plugin_links = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-settings' ) ) . '">' .
                esc_html__( 'Configurar', 'ltms' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-dashboard' ) ) . '">' .
                esc_html__( 'Dashboard', 'ltms' ) . '</a>',
        ];

        return array_merge( $plugin_links, $links );
    }

    /**
     * Renderiza avisos de administración (errores de configuración, etc.).
     *
     * @return void
     */
    public function render_admin_notices(): void {
        // Aviso si no se ha configurado la clave de cifrado
        if ( ! defined( 'LTMS_ENCRYPTION_KEY' ) || empty( LTMS_ENCRYPTION_KEY ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                sprintf(
                    /* translators: %s: Enlace a la documentación */
                    esc_html__( 'LT Marketplace Suite: La constante LTMS_ENCRYPTION_KEY no está definida en wp-config.php. %s', 'ltms' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-settings' ) ) . '">' .
                        esc_html__( 'Configurar ahora', 'ltms' ) . '</a>'
                ) . '</p></div>';
        }
    }

    // ── Callbacks de renderizado de páginas ───────────────────────

    public function render_dashboard(): void {
        LTMS_Data_Masking::log_auditor_access( 'admin_dashboard' );
        $this->render_view( 'html-admin-dashboard' );
    }

    public function render_vendors(): void {
        $this->render_view( 'html-admin-vendors' );
    }

    public function render_orders(): void {
        $this->render_view( 'html-admin-orders' );
    }

    public function render_wallets(): void {
        $this->render_view( 'html-admin-wallets' );
    }

    public function render_payouts(): void {
        $this->render_view( 'html-admin-payouts' );
    }

    public function render_kyc(): void {
        $this->render_view( 'html-admin-kyc' );
    }

    public function render_tax_reports(): void {
        LTMS_Data_Masking::log_auditor_access( 'tax_reports' );
        $this->render_view( 'html-admin-tax-reports' );
    }

    public function render_marketing(): void {
        $this->render_view( 'html-admin-marketing' );
    }

    public function render_security(): void {
        $this->render_view( 'html-admin-security' );
    }

    public function render_settings(): void {
        $this->render_view( 'html-admin-settings' );
    }

    public function render_auditor_dashboard(): void {
        LTMS_Data_Masking::log_auditor_access( 'auditor_dashboard' );
        $this->render_view( 'view-auditor-dashboard' );
    }

    public function render_pickup_orders(): void {
        $this->render_view( 'html-admin-pickup-orders' );
    }

    public function render_xcover_policies(): void {
        $this->render_view( 'html-admin-xcover-policies' );
    }

    public function render_redi(): void {
        $this->render_view( 'html-admin-redi' );
    }

    /**
     * Carga e incluye una vista del directorio admin/views/.
     *
     * @param string $view_name Nombre del archivo de vista (sin .php).
     * @return void
     */
    private function render_view( string $view_name ): void {
        $view_path = LTMS_INCLUDES_DIR . 'admin/views/' . $view_name . '.php';

        if ( file_exists( $view_path ) ) {
            include $view_path;
        } else {
            echo '<div class="wrap"><h1>LTMS</h1><p>' .
                sprintf(
                    /* translators: %s: nombre de la vista */
                    esc_html__( 'Vista no encontrada: %s', 'ltms' ),
                    esc_html( $view_name )
                ) . '</p></div>';
        }
    }
}
