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
        // install_pages() eliminada: creaba páginas con shortcodes no registrados ([ltms_login], [ltms_register], etc.)
        // y llenaba ltms_installed_pages como array indexado, rompiendo create_required_pages().
        // create_required_pages() es la función canónica y cubre todos los casos.
        self::create_required_pages();
        self::set_default_options();
        self::schedule_cron_jobs();
        self::create_secure_directories();

        update_option( 'ltms_version', LTMS_VERSION );
        update_option( 'ltms_activation_redirect', true );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'PLUGIN_ACTIVATED',
                sprintf( 'LTMS v%s activado exitosamente', LTMS_VERSION ),
                [ 'user_id' => get_current_user_id(), 'site_url' => site_url() ]
            );
        }
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
     * @deprecated Usar create_required_pages() en su lugar. Esta función ya no se llama desde activate().
     *             Se conserva sólo por compatibilidad con posibles llamadas externas.
     * @return void
     */
    private static function install_pages(): void {
        // Shortcodes corregidos a los que sí están registrados en LTMS_Public_Auth_Handler y LTMS_Dashboard_Logic
        $pages = [
            'ltms-dashboard'    => [
                'title'   => __( 'Mi Panel de Vendedor', 'ltms' ),
                'content' => '[ltms_vendor_dashboard]',
                'option'  => 'ltms_page_dashboard',
            ],
            'ltms-login'        => [
                'title'   => __( 'Acceder - Marketplace', 'ltms' ),
                'content' => '[ltms_vendor_login]',    // era [ltms_login] — shortcode no registrado
                'option'  => 'ltms_page_login',
            ],
            'ltms-sellers'      => [
                'title'   => __( 'Vende en Lo Tengo', 'ltms' ),
                'content' => '[ltms_sellers_landing]', // M-55: landing page de captación de vendedores
                'slug'    => 'sellers',
                'option'  => 'ltms_page_sellers',
            ],
            'ltms-register'     => [
                'title'   => __( 'Registrarse como Vendedor', 'ltms' ),
                'content' => '[ltms_vendor_register]', // era [ltms_register] — shortcode no registrado
                'option'  => 'ltms_page_register',
            ],
            'ltms-store'        => [
                'title'   => __( 'Tienda', 'ltms' ),
                'content' => '[ltms_vendor_store]',    // era [ltms_store] — shortcode no registrado
                'option'  => 'ltms_page_store',
            ],
            'ltms-track-order'  => [
                'title'   => __( 'Rastrear Pedido', 'ltms' ),
                'content' => '',                        // [ltms_track_order] no existe — página vacía hasta implementación
                'option'  => 'ltms_page_track_order',
            ],
        ];

        foreach ( $pages as $slug => $data ) {
            // Verificar si ya existe por la opción guardada
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
                // NO se escribe en ltms_installed_pages para no romper el array asociativo de create_required_pages()
            }
        }
        // No se actualiza ltms_installed_pages aquí — create_required_pages() es la fuente de verdad
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

            // v2.0.0 — Módulo de Envíos
            'ltms_shipping_mode'                   => 'quoted',
            'ltms_shipping_free_min_amount'        => 0,
            'ltms_shipping_free_categories'        => '',
            'ltms_shipping_commission_on_shipping' => false,
            'ltms_shipping_vendor_absorbs'         => true,
            'ltms_shipping_cache_ttl'              => 300,
            'ltms_shipping_timeout_seconds'        => 3,
            'ltms_default_product_weight_kg'       => 0.5,

            // v2.0.0 — SEO
            'ltms_google_search_console_verify' => '',
            'ltms_google_client_id'             => 'GOOGLE_CLIENT_ID_PLACEHOLDER',
            // ── Alegra Contabilidad ────────────────────────────────────────────────
            'ltms_alegra_enabled'                 => 'no',
            'ltms_alegra_email'                   => '',
            'ltms_alegra_token'                   => '',
            'ltms_alegra_default_number_template' => 0,
            'ltms_alegra_bank_account_id'         => 0,
            'ltms_alegra_commission_account_id'   => 0,    // Cuenta para comisiones de plataforma
            'ltms_alegra_retefuente_tax_id'       => 0,    // ID impuesto retención fuente en Alegra
            'ltms_alegra_shipping_tax_id'         => 1,    // ID impuesto para envíos (1=exento CO)
            'ltms_alegra_invoice_on_processing'   => 'no',
            'ltms_alegra_auto_payment'            => 'no',
            'ltms_alegra_send_invoice_email'      => 'no',
            'ltms_alegra_webhook_secret'          => '',
            'ltms_alegra_exchange_rate'           => 1,    // Tasa de cambio para monedas no-COP
            'ltms_google_client_secret'         => '',  // Se guarda cifrado; ver ltms_google_client_secret_raw
            'ltms_sitemap_exclude_outofstock'   => true,
            'ltms_og_site_name'                 => '',
            'ltms_og_locale'                    => 'es_CO',

            // v2.0.0 — Analytics
            'ltms_google_tag_manager_id'        => '',
            'ltms_ga4_measurement_id'           => '',
            'ltms_meta_pixel_id'                => '',

            // v2.0.0 — Geo
            'ltms_geo_detection_enabled'        => true,
            'ltms_geo_default_city'             => 'Bogotá',
            'ltms_geo_default_country'          => 'CO',

            // v2.0.0 — Módulo Booking
            'ltms_booking_enabled'                    => false,
            'ltms_booking_default_country'            => 'CO',
            'ltms_booking_dispute_window_days'        => 3,
            'ltms_booking_auto_cancel_unpaid_balance' => false,
            'ltms_booking_default_payment_mode'       => 'full',
            'ltms_booking_default_deposit_pct'        => 30,
            'ltms_booking_require_rnt_co'             => true,
            'ltms_booking_require_sectur_mx'          => true,
            'ltms_booking_zapsign_enabled'            => true,
            'ltms_booking_xcover_enabled'             => false,
            'ltms_booking_commission_on_deposit'      => true,
            'ltms_booking_commission_on_balance'      => true,
            'ltms_booking_pending_slot_lock_minutes'  => 30,
            'ltms_booking_checkin_reminder_hours'     => 48,
            'ltms_booking_max_advance_booking_days'   => 365,
            'ltms_zapsign_booking_template_id'        => '',

            // v2.0.0 — Comisiones avanzadas
            'ltms_basic_commission_rate'      => '0.10',
            'ltms_premium_commission_rate'    => '0.08',
            'ltms_platform_commission_rate'   => '0.05',
            'ltms_category_commission_rates'  => '',
            'ltms_volume_tiers_enabled'       => false,
            'ltms_custom_commission_rate'     => '',
            'ltms_mlm_enabled'                => false,
            'ltms_mlm_referral_rate'          => '0.02',
            'ltms_referral_rates'             => '[0.05,0.02]', // 5% nivel 1, 2% nivel 2

            // v2.0.0 — Seguridad / WAF / 2FA
            'ltms_2fa_required_auditors'         => 'no',
            'ltms_waf_block_duration_seconds'    => 3600,
            'ltms_waf_ip_cache_ttl_seconds'      => 300,
            'ltms_circuit_breaker_cooldown_minutes' => 5,
            'ltms_vault_signed_url_ttl_seconds'  => 300,

            // v2.0.0 — KYC
            'ltms_kyc_required_for_payout'  => 'yes',
            'ltms_kyc_max_file_size_mb'     => 5,
            'ltms_kyc_allowed_mime_types'   => 'image/jpeg,image/png,application/pdf',

            // v2.0.0 — Pagos / Payouts
            'ltms_min_payout_amount'                 => '50000',
            'ltms_auto_approve_payouts'              => 'no',
            'ltms_auto_approve_max_amount'           => '500000',
            'ltms_consumer_protection_days'          => 7,
            'ltms_pse_enabled'                       => false,
            'ltms_orchestration_stripe_threshold_cop' => 1000000,
            'ltms_orchestration_stripe_threshold_mxn' => 50000,

            // v2.0.0 — API timeouts / retry
            'ltms_api_timeout_seconds'              => 10,
            'ltms_api_max_retries'                  => 3,
            'ltms_api_retry_delay_seconds'          => 2,

            // v2.0.0 — Shipping
            'ltms_min_shipping_weight_kg'           => 0.1,
            'ltms_shipping_parallel_timeout'        => 5,

            // v2.0.0 — Store info
            'ltms_store_address'  => '',
            'ltms_store_city'     => '',
            'ltms_store_state'    => '',
            'ltms_store_zip'      => '',

            // v2.0.0 — Impuestos CO
            'ltms_iva_general'                  => '0.19',
            'ltms_iva_reducido'                 => '0.05',
            'ltms_impoconsumo_rate'             => '0.08',
            'ltms_retefuente_compras'           => '0.025',
            'ltms_retefuente_servicios'         => '0.04',
            'ltms_retefuente_honorarios'        => '0.11',
            'ltms_retefuente_tech'              => '0.035',
            'ltms_retefuente_min_compras_uvt'   => 27,
            'ltms_retefuente_min_servicios_uvt' => 4,
            'ltms_reteiva_rate'                 => '0.15',
            'ltms_sagrilaft_uvt_threshold'      => 10000,
            'ltms_uvt_valor'                    => '47065',

            // v2.0.0 — Impuestos MX
            'ltms_mx_iva_general'      => '0.16',
            'ltms_mx_iva_frontera'     => '0.08',
            'ltms_mx_isr_honorarios'   => '0.10',
            'ltms_mx_retencion_iva_pm' => '0.106',

            // v2.0.0 — Booking extra
            'ltms_booking_pending_timeout_minutes' => 30,
            'ltms_booking_rnt_required'            => true,

            // v2.0.0 — Integraciones (secrets vacíos por defecto, se configuran en Settings)
            'ltms_siigo_enabled'              => false,
            'ltms_siigo_username'             => '',
            'ltms_siigo_access_key'           => '',
            'ltms_siigo_seller_id'            => '',
            'ltms_siigo_invoice_document_id'  => '',
            'ltms_siigo_payment_method_id'    => '5396',
            'ltms_siigo_tax_id'               => '29',
            'ltms_siigo_sandbox'              => 'no',
            'ltms_siigo_auto_invoice'         => 'no',
            'ltms_siigo_webhook_token'        => '',
            'ltms_addi_enabled'               => false,
            'ltms_addi_client_id'             => '',
            'ltms_addi_client_secret'         => '',
            'ltms_addi_webhook_token'         => '',
            'ltms_tptc_enabled'               => false,
            'ltms_tptc_api_key'               => '',
            'ltms_tptc_program_id'            => '',
            'ltms_heka_api_key'               => '',
            'ltms_heka_account_id'            => '',
            'ltms_aveonline_api_key'          => '',
            'ltms_aveonline_account_id'       => '',
            'ltms_xcover_api_key'             => '',
            'ltms_xcover_partner_code'        => '',
            'ltms_xcover_purchase_protection' => false,
            'ltms_xcover_parcel_protection'   => false,
            'ltms_zapsign_api_token'                  => '',
            'ltms_zapsign_enabled'                    => 'no',
            'ltms_kyc_zapsign_enabled'                => 'no',
            'ltms_zapsign_sandbox'                    => '1',    // sandbox por defecto — apagar en producción
            'ltms_zapsign_vendor_template_id'         => '526a9570-0160-42f9-999b-5b624527ba5e', // plantilla Contrato_Vendedor_LoTengo_v4.1
            'ltms_zapsign_contract_pdf_url'           => '',
            'ltms_zapsign_auto_approve_kyc'           => 'yes',  // M-67: activado por defecto
            'ltms_zapsign_contract_attachment_id'     => '',
            'ltms_zapsign_webhook_secret'             => '',
            'ltms_openpay_merchant_id'        => '',
            'ltms_openpay_public_key'         => '',
            'ltms_stripe_test_mode'           => true,
            'ltms_stripe_test_publishable_key'  => '',
            'ltms_stripe_live_publishable_key'  => '',
            'ltms_stripe_webhook_secret'        => '',
            'ltms_uber_direct_client_id'        => '',
            'ltms_uber_direct_client_secret'    => '',
            'ltms_uber_direct_customer_id'      => '',
            'ltms_uber_direct_webhook_secret'   => '',
            'ltms_backblaze_key_id'             => '',
            'ltms_backblaze_app_key'            => '',
            'ltms_backblaze_endpoint'           => '',
            'ltms_backblaze_default_bucket'     => '',
            'ltms_backblaze_private_bucket'     => 'lotengo-kyc-docs',
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
        // Register custom intervals inline so wp_schedule_event() accepts them
        // even when this runs before the kernel's cron_schedules filter is hooked.
        add_filter( 'cron_schedules', static function ( array $schedules ): array {
            if ( ! isset( $schedules['every_5_minutes'] ) ) {
                $schedules['every_5_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 5 Minutes', 'ltms' ),
                ];
            }
            if ( ! isset( $schedules['every_30_minutes'] ) ) {
                $schedules['every_30_minutes'] = [
                    'interval' => 30 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 30 Minutes', 'ltms' ),
                ];
            }
            return $schedules;
        } );

        $jobs = [
            'ltms_process_payouts'       => [ 'recurrence' => 'daily',             'time' => '02:00:00' ],
            'ltms_sync_siigo'            => [ 'recurrence' => 'hourly',            'time' => null ],
            'ltms_integrity_check'       => [ 'recurrence' => 'daily',             'time' => '03:00:00' ],
            'ltms_clean_logs'            => [ 'recurrence' => 'weekly',            'time' => '04:00:00' ],
            'ltms_process_job_queue'     => [ 'recurrence' => 'every_5_minutes',   'time' => null ],
            'ltms_send_notifications'    => [ 'recurrence' => 'hourly',            'time' => null ],
            'ltms_update_tracking'       => [ 'recurrence' => 'every_30_minutes',  'time' => null ],
            'ltms_approve_payout_cron'   => [ 'recurrence' => 'daily',             'time' => '06:00:00' ],
            'ltms_daily_cron'            => [ 'recurrence' => 'daily',             'time' => '01:00:00' ], // M-46: consumer protection holds
            'ltms_alegra_retry_failed'   => [ 'recurrence' => 'hourly',            'time' => null ],       // Reintentar facturas Alegra fallidas
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
