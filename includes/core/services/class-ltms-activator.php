<?php
/**
 * LTMS Core Activator - Proceso de Instalación del Plugin
 *
 * Ejecuta todas las tareas necesarias al activar el plugin:
 * migraciones de BD, roles, páginas, opciones por defecto y cron jobs.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/services
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Activator
 */
final class LTMS_Core_Activator {

    /**
     * Ejecuta el proceso completo de activación.
     *
     * @return void
     */
    public static function activate(): void {
        self::run_migrations();
        self::install_roles();
        self::install_pages();
        self::create_required_pages();
        self::set_default_options();
        self::schedule_cron_jobs();
        self::create_secure_directories();

        update_option( 'ltms_version', LTMS_VERSION );
        update_option( 'ltms_activation_redirect', true );

        LTMS_Core_Logger::info(
            'PLUGIN_ACTIVATED',
            sprintf( 'LTMS v%s activado exitosamente', LTMS_VERSION ),
            [ 'user_id' => get_current_user_id(), 'site_url' => site_url() ]
        );
    }

    /**
     * Ejecuta las migraciones de BD.
     *
     * @return void
     */
    private static function run_migrations(): void {
        if ( class_exists( 'LTMS_DB_Migrations' ) ) {
            LTMS_DB_Migrations::run();
        }
    }

    /**
     * Instala los roles y capacidades de usuario.
     *
     * @return void
     */
    private static function install_roles(): void {
        if ( class_exists( 'LTMS_Roles' ) ) {
            LTMS_Roles::install();
        }
        if ( class_exists( 'LTMS_External_Auditor_Role' ) ) {
            LTMS_External_Auditor_Role::install();
        }
    }

    /**
     * Crea las páginas necesarias del plugin si no existen.
     *
     * @return void
     */
    private static function install_pages(): void {
        $pages = [
            'ltms-dashboard'    => [
                'title'     => __( 'Mi Panel de Vendedor', 'ltms' ),
                'content'   => '[ltms_vendor_dashboard]',
                'option'    => 'ltms_page_dashboard',
            ],
            'ltms-login'        => [
                'title'     => __( 'Acceder - Marketplace', 'ltms' ),
                'content'   => '[ltms_login]',
                'option'    => 'ltms_page_login',
            ],
            'ltms-register'     => [
                'title'     => __( 'Registrarse como Vendedor', 'ltms' ),
                'content'   => '[ltms_register]',
                'option'    => 'ltms_page_register',
            ],
            'ltms-store'        => [
                'title'     => __( 'Tienda', 'ltms' ),
                'content'   => '[ltms_store]',
                'option'    => 'ltms_page_store',
            ],
            'ltms-track-order'  => [
                'title'     => __( 'Rastrear Pedido', 'ltms' ),
                'content'   => '[ltms_track_order]',
                'option'    => 'ltms_page_track_order',
            ],
        ];

        $installed_pages = get_option( 'ltms_installed_pages', [] );

        foreach ( $pages as $slug => $data ) {
            // Verificar si ya existe
            $existing = get_option( $data['option'], 0 );
            if ( $existing && get_post( $existing ) ) {
                continue;
            }

            $page_id = wp_insert_post( [
                'post_title'     => wp_strip_all_tags( $data['title'] ),
                'post_content'   => $data['content'],
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_name'      => $slug,
                'comment_status' => 'closed',
            ] );

            if ( ! is_wp_error( $page_id ) ) {
                update_option( $data['option'], $page_id );
                $installed_pages[] = $page_id;
            }
        }

        update_option( 'ltms_installed_pages', $installed_pages );
    }

    /**
     * Establece las opciones por defecto del plugin.
     *
     * @return void
     */
    private static function set_default_options(): void {
        $defaults = [
            'ltms_commission_rate_default' => '0.10', // 10%
            'ltms_payout_min_amount'       => '50000', // COP
            'ltms_payout_frequency'        => 'weekly',
            'ltms_hold_period_days'        => '7',
            'ltms_kyc_required'            => 'yes',
            'ltms_2fa_required_vendors'    => 'no',
            'ltms_waf_enabled'             => 'yes',
            'ltms_log_retention_days'      => '90',
            'ltms_country'                 => 'CO',
            'ltms_currency'                => 'COP',
            'ltms_tax_regime'              => 'iva',
            'ltms_invoice_provider'        => 'siigo',
            'ltms_notifications_email'     => 'yes',
            'ltms_notifications_whatsapp'  => 'no',
        ];

        $current_settings = get_option( 'ltms_settings', [] );
        $merged           = array_merge( $defaults, $current_settings );

        update_option( 'ltms_settings', $merged, true );
    }

    /**
     * Programa los cron jobs del sistema.
     *
     * @return void
     */
    private static function schedule_cron_jobs(): void {
        $jobs = [
            'ltms_process_payouts'    => [ 'recurrence' => 'daily',   'time' => '02:00:00' ],
            'ltms_sync_siigo'         => [ 'recurrence' => 'hourly',  'time' => null ],
            'ltms_integrity_check'    => [ 'recurrence' => 'daily',   'time' => '03:00:00' ],
            'ltms_clean_logs'         => [ 'recurrence' => 'weekly',  'time' => '04:00:00' ],
            'ltms_process_job_queue'  => [ 'recurrence' => 'every_5_minutes', 'time' => null ],
            'ltms_send_notifications' => [ 'recurrence' => 'hourly',  'time' => null ],
            'ltms_update_tracking'    => [ 'recurrence' => 'every_30_minutes', 'time' => null ],
        ];

        foreach ( $jobs as $hook => $config ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $timestamp = $config['time']
                    ? strtotime( gmdate( 'Y-m-d' ) . ' ' . $config['time'] )
                    : time();

                wp_schedule_event( $timestamp, $config['recurrence'], $hook );
            }
        }
    }

    /**
     * Registra los hooks de WordPress necesarios para el activador
     * (p.ej., el handler de admin-post para recrear páginas).
     * Llamar desde el bootstrap del plugin (ltms_run).
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_post_ltms_recreate_pages', [ __CLASS__, 'handle_recreate_pages' ] );
    }

    /**
     * Handler de admin-post: recrea las páginas faltantes del plugin.
     * Requiere nonce válido y capacidad manage_options.
     *
     * @return void
     */
    public static function handle_recreate_pages(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'ltms' ), 403 );
        }

        check_admin_referer( 'ltms_recreate_pages' );

        self::create_required_pages();

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => 'ltms-pages', 'ltms_pages_recreated' => '1' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Crea (o recrea) las páginas requeridas del plugin si no existen o fueron eliminadas.
     * Almacena un mapa associativo page_key => page_id en la opción ltms_installed_pages.
     *
     * @return void
     */
    public static function create_required_pages(): void {
        $pages = [
            'ltms-vendor-register' => [
                'title'   => 'Registro de Vendedor',
                'content' => '[ltms_vendor_register]',
                'slug'    => 'registro-vendedor',
            ],
            'ltms-dashboard'       => [
                'title'   => 'Panel del Vendedor',
                'content' => '[ltms_vendor_dashboard]',
                'slug'    => 'panel-vendedor',
            ],
            'ltms-login'           => [
                'title'   => 'Iniciar Sesión',
                'content' => '[ltms_vendor_login]',
                'slug'    => 'login-vendedor',
            ],
            'ltms-store'           => [
                'title'   => 'Tienda del Vendedor',
                'content' => '[ltms_vendor_store]',
                'slug'    => 'tienda',
            ],
            'ltms-orders'          => [
                'title'   => 'Mis Pedidos',
                'content' => '[ltms_vendor_orders]',
                'slug'    => 'mis-pedidos',
            ],
            'ltms-wallet'          => [
                'title'   => 'Mi Billetera',
                'content' => '[ltms_vendor_wallet]',
                'slug'    => 'mi-billetera',
            ],
            'ltms-kyc'             => [
                'title'   => 'Verificación de Identidad',
                'content' => '[ltms_vendor_kyc]',
                'slug'    => 'verificacion-identidad',
            ],
            'ltms-insurance'       => [
                'title'   => 'Mis Seguros',
                'content' => '[ltms_vendor_insurance]',
                'slug'    => 'mis-seguros',
            ],
        ];

        // ltms_installed_pages puede ser un array indexado (legado) o asociativo (nuevo).
        // Normalizar a asociativo por clave de página.
        $installed = get_option( 'ltms_installed_pages', [] );
        if ( ! is_array( $installed ) ) {
            $installed = [];
        }

        foreach ( $pages as $key => $page_data ) {
            // Si ya existe una entrada válida para esta clave, omitir.
            if ( ! empty( $installed[ $key ] ) && get_post( $installed[ $key ] ) ) {
                continue;
            }

            $page_id = wp_insert_post(
                [
                    'post_title'     => $page_data['title'],
                    'post_content'   => $page_data['content'],
                    'post_status'    => 'publish',
                    'post_type'      => 'page',
                    'post_name'      => $page_data['slug'],
                    'comment_status' => 'closed',
                ]
            );

            if ( ! is_wp_error( $page_id ) ) {
                $installed[ $key ] = $page_id;
            }
        }

        update_option( 'ltms_installed_pages', $installed );
    }

    /**
     * Crea los directorios seguros necesarios en wp-content/uploads.
     *
     * @return void
     */
    private static function create_secure_directories(): void {
        $dirs = [
            LTMS_VAULT_DIR,
            LTMS_VAULT_DIR . 'kyc/',
            LTMS_VAULT_DIR . 'contracts/',
            LTMS_VAULT_DIR . 'invoices/',
            LTMS_VAULT_DIR . 'certificates/',
            LTMS_LOG_DIR,
        ];

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            // Proteger con .htaccess
            $htaccess = $dir . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n", LOCK_EX );
            }

            // Proteger con index.php
            $index = $dir . 'index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, '<?php // Silence is golden.', LOCK_EX );
            }
        }
    }
}
