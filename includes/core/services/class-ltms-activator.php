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
        self::create_email_queue_table(); // INT-BUG-14: async email queue infrastructure
        self::create_foundation_wallet(); // 60-C: wallet for foundation (donation motor)

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
            'ltms_2fa_required_vendors'    => 'yes', // FT-6 (v2.9.16): 2FA obligatorio vendors con payouts (Ley Fintech art. 95 MX / Circular SFC CO).
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
            'ltms_alegra_retefuente_tax_id'       => 0,    // ID impuesto retención fuente en Alegra (CO)
            'ltms_alegra_reteiva_tax_id'          => 0,    // NC-1 (v2.9.12): ID impuesto ReteIVA en Alegra (CO)
            'ltms_alegra_reteica_tax_id'          => 0,    // NC-1 (v2.9.12): ID impuesto ReteICA en Alegra (CO)
            'ltms_alegra_inc_tax_id'              => 0,    // NC-5 (v2.9.12): ID impuesto Impoconsumo en Alegra (CO)
            'ltms_alegra_ish_tax_id'              => 0,    // ID impuesto ISH hospedaje en Alegra (MX)
            'ltms_alegra_iva_retenido_mx_tax_id'  => 0,    // NC-1 (v2.9.12): ID impuesto IVA retenido en Alegra (MX, persona moral)
            'ltms_alegra_shipping_tax_id'         => 1,    // ID impuesto para envíos (1=exento CO)
            'ltms_alegra_invoice_on_processing'   => 'no',
            'ltms_alegra_auto_payment'            => 'no',
            'ltms_alegra_send_invoice_email'      => 'no',
            'ltms_alegra_webhook_secret'          => '',
            'ltms_alegra_exchange_rate'           => 1,    // Tasa de cambio para monedas no-COP
            'ltms_alegra_fx_sync'                 => 'yes', // NC-2 (v2.9.12): sincronizar asientos FX con Alegra
            'ltms_alegra_fx_gain_account_id'      => 0,    // NC-2 (v2.9.12): ID cuenta ingreso FX (4255 PUC CO)
            'ltms_alegra_fx_loss_account_id'      => 0,    // NC-2 (v2.9.12): ID cuenta gasto FX (5255 PUC CO)

            // NC-3 (v2.9.12) — Resolución DIAN Colombia (Res. DIAN 000042/2020 art. 5).
            // Configurar con los datos de la resolución vigente otorgada por DIAN.
            'ltms_dian_resolution_number'         => '',   // Ej: '18764000004200'
            'ltms_dian_resolution_date'           => '',   // Ej: '2024-01-15'
            'ltms_dian_prefix'                    => '',   // Ej: 'SET' o 'SETP'
            'ltms_dian_range_from'                => '',   // Ej: '1'
            'ltms_dian_range_to'                  => '',   // Ej: '50000'
            'ltms_dian_technical_key'             => '',   // Clave técnica DIAN (config software)
            'ltms_google_client_secret'         => '',  // Se guarda cifrado; ver ltms_google_client_secret_raw
            'ltms_sitemap_exclude_outofstock'   => true,
            'ltms_og_site_name'                 => 'Lo Tengo Colombia',
            'ltms_og_locale'                    => 'es_CO',

            // v2.0.0 — Analytics
            'ltms_google_tag_manager_id'        => '',
            'ltms_ga4_measurement_id'           => '',
            'ltms_meta_pixel_id'                => '',
            // v2.3.0 — vendor pixel toggles
            'ltms_vendor_ga4_enabled'           => 'yes',
            'ltms_vendor_pixel_enabled'         => 'yes',

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
            // LG-6 FIX (v2.9.7): ltms_platform_commission_rate alineado con contrato v4.1
            // y Commission Strategy DEFAULT_RATE (0.15 = 15%).
            // ANTES: 0.05 (5%) — contradecía el DEFAULT_RATE del código (0.15) y
            // el contrato real (10-15% por categoría). Los vendors nuevos recibían
            // 5% de comisión en lugar del 15% correcto.
            'ltms_basic_commission_rate'      => '0.10',
            'ltms_premium_commission_rate'    => '0.08',
            'ltms_platform_commission_rate'   => '0.15',
            'ltms_category_commission_rates'  => '',
            'ltms_volume_tiers_enabled'       => false,
            'ltms_custom_commission_rate'     => '',
            // M-04 FIX: ltms_mlm_enabled debe ser 'no' (string), no false (boolean PHP).
            // La vista guarda 'yes'/'no' y la lógica compara === 'yes'.
            // Se agregan los defaults para todos los campos MLM que el activador no tenía.
            'ltms_mlm_enabled'                => 'no',
            'ltms_mlm_levels'                 => '3',
            // LG-7 FIX (v2.9.7): referral_rates alineado con DEFAULT_RATES de
            // Referral_Tree ([0.40, 0.20, 0.10] = 40%/20%/10% del commission_fee).
            // ANTES: [0.05,0.02,0.01] — contradecía DEFAULT_RATES y el contrato.
            // También había un duplicado de ltms_referral_rates (línea 241 y 244)
            // que causaba que el segundo sobrescribiera al primero con menos niveles.
            'ltms_referral_rates'             => '[0.40,0.20,0.10]',
            'ltms_mlm_min_sales_activate'     => 1,
            'ltms_mlm_referral_rate'          => '0.02',

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
            'ltms_co_impoconsumo'               => '0.08', // RT-6 (v2.9.14): canónica admin UI (html-admin-fiscal-colombia.php).
            'ltms_alcohol_allowed_hours'        => '10:00-22:00', // RT-4: horario venta alcohol (configurable por municipio).
            // FT (v2.9.16) — Fintech Compliance defaults.
            'ltms_ft_daily_payout_limit_usd'    => 5000,    // FT-3: límite diario payout por vendor (USD eq).
            'ltms_ft_monthly_payout_limit_usd'  => 20000,   // FT-3: límite mensual payout por vendor (USD eq).
            'ltms_ft_daily_tx_count_limit'      => 50,      // FT-3: número máximo de transacciones diarias.
            'ltms_ft_travel_rule_threshold_usd' => 1000,    // FT-4: umbral Travel Rule (FATF Rec. 16).
            'ltms_ft_compliance_officer_email'  => '',      // FT-1/2/5/7: oficial de cumplimiento.
            'ltms_ft_pci_dss_saq_signed_at'     => '',      // FT-5: fecha firma SAQ-A.
            'ltms_ft_pci_dss_saq_signatory'     => '',      // FT-5: firmante SAQ-A.
            'ltms_ft_pci_dss_saq_validity'      => '',      // FT-5: vigencia SAQ-A.
            'ltms_mx_uma_valor'                 => '108.57', // FT-8: UMA MX 2026 (Regla 10 LFPIDRPI).
            // LT (v2.9.17) — Logistics Compliance defaults.
            'ltms_carrier_rnt_co'               => '',      // LT-2: RNT Mintransporte (CO) formato RNT-C-XXXXX.
            'ltms_carrier_rnt_expires_co'       => '',      // LT-2: vigencia RNT.
            'ltms_carrier_sct_permit'           => '',      // LT-3: permiso SCT MX formato SCT-TP0X-XXXXX.
            'ltms_carrier_sct_expires'          => '',      // LT-3: vigencia permiso SCT.
            'ltms_carrier_rc_expires'           => '',      // LT-5: vigencia póliza RC transportista.
            'ltms_carrier_rc_amount'            => 0,       // LT-5: monto RC (CO 700 SMLMV / MX 35k UMA).
            'ltms_carrier_iso_certified'        => 'no',    // LT-6: carrier certificado ISO 17712.
            'ltms_carrier_gps_enabled'          => 'no',    // LT-7: carrier con GPS satelital.
            'ltms_carrier_rfc_mx'               => '',      // LT-1: RFC carrier MX para Carta Porte.
            'ltms_carrier_operator_name'        => '',      // LT-1: nombre operador Carta Porte.
            'ltms_carrier_operator_license'     => '',      // LT-1: licencia federal operador.
            'ltms_carrier_vehicle_config'       => 'C2',    // LT-1: config vehicular (C2, C3, T2S1, T3S2...).
            'ltms_usd_cop_rate'                 => 4200,    // LT-9: FX USD/COP para valor declarado Deprisa.
            'ltms_mxn_cop_rate'                 => 245,     // LT-9: FX MXN/COP.
            // CB (v2.9.18) — Cross-Border Compliance defaults.
            'ltms_ioss_number'                  => '',      // CB-3: número IOSS UE (Reglamento UE 2017/2455).
            'ltms_usd_cop_rate_cb'              => 4200,    // CB-9: FX USD/COP para conversión de minimis.
            'ltms_eur_cop_rate'                 => 4500,    // CB-3: FX EUR/COP para cálculo IOSS.
            'ltms_eur_mxn_rate'                 => 19,      // CB-3: FX EUR/MXN para cálculo IOSS.
            'ltms_eur_usd_rate'                 => 1.08,    // CB-3: FX EUR/USD.
            // AC (v2.9.20) — Authorities Compliance defaults (SIC + ICA + ANLA + INVIMA + DNDA + IMPI).
            'ltms_ppc_sic_endpoint'             => '',      // AC-3: API PPC SIC (https://ppc.api.gov.co/v1/quejas).
            'ltms_ppc_sic_token'                => '',      // AC-3: bearer token autenticación PPC SIC.
            'ltms_dian_api_token'               => '',      // AC-7: token API DIAN validación RUT.
            'ltms_sat_api_token'                => '',      // AC-7: token API SAT validación RFC.
            'ltms_dnda_api_token'               => '',      // AC-1: token API DNDA consulta marcas.
            'ltms_impi_api_token'               => '',      // AC-1: token API IMPI MX consulta marcas.
            // HD (v2.9.21) — Data Protection Compliance defaults.
            'ltms_csp_header'                   => '',      // HD-1: CSP override (vacío = default estricto).
            'ltms_csp_report_uri'               => '',      // HD-1: URI reporte violaciones CSP.
            'ltms_sic_registration_number'       => '',      // HD-2: registro SIC responsables (Decreto 1727/2024).
            'ltms_sic_registration_expires'      => '',      // HD-2: vigencia registro SIC.
            'ltms_dpo_name'                     => '',      // HD-6: nombre DPO/Encargado.
            'ltms_dpo_email'                    => '',      // HD-6: email contacto DPO.
            'ltms_dpo_phone'                    => '',      // HD-6: teléfono DPO.
            'ltms_last_key_rotation'            => 0,       // HD-9: timestamp última rotación de clave.
            // SE (v2.9.22) — SEO Enhanced defaults.
            'ltms_hero_image_url'               => '',      // SE-6: hero image preload en homepage (Core Web Vitals).
            // BR (v2.9.30) — Branding Engine defaults.
            'ltms_logo_white_url'               => '',      // BR-1: logo fondo blanco (Organization schema + favicon).
            'ltms_logo_dark_url'                => '',      // BR-1: logo fondo negro (dark mode).
            'ltms_brand_slogan'                 => 'Compra con confianza, vende sin límites',
            'ltms_social_facebook'              => '',
            'ltms_social_instagram'             => '',
            'ltms_social_twitter'               => '',
            'ltms_social_linkedin'              => '',
            'ltms_social_youtube'               => '',
            'ltms_social_tiktok'                => '',
            'ltms_contact_phone'                => '',
            'ltms_contact_email'                => '',
            'ltms_founder_name'                 => '',
            'ltms_founding_date'                => '2024',
            // FN (v2.9.23) — Foundation Compliance defaults (ESAL / fundaciones).
            'ltms_donation_foundation_rte_number'   => '',   // FN-1: número calificación RTE ante DIAN (Decreto 832/2019).
            'ltms_donation_foundation_rte_expires'  => '',   // FN-1: vigencia calificación RTE.
            'ltms_donation_foundation_bank_account' => '',   // FN-6: cuenta bancaria fundación (verificación SFC).
            'ltms_retefuente_compras'           => '0.025',
            'ltms_retefuente_servicios'         => '0.04',
            'ltms_retefuente_honorarios'        => '0.11',
            'ltms_retefuente_tech'              => '0.035',
            'ltms_retefuente_min_compras_uvt'   => 27,
            'ltms_retefuente_min_servicios_uvt' => 4,
            'ltms_reteiva_rate'                 => '0.15',
            'ltms_sagrilaft_uvt_threshold'      => 10000,
            'ltms_uvt_valor'                    => '52752', // UVT 2026 — Resolución DIAN 000187/2025

            // AUDIT-BOOKING-ENGINE #10 — Turismo fiscal config.
            'ltms_iva_turismo_co'               => '0.07', // IVA reducido turismo CO (Ley 1819/2016 Art. 115).
            'ltms_ish_rate_mx'                  => '0.03',  // ISH hospedaje MX (default 3%, varía por estado).
            'ltms_alegra_ish_tax_id'            => 0,       // ID del impuesto ISH en Alegra (configurar manualmente).

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
            // Fix AD-5: Deprisa defaults — was missing from activator entirely.
            'ltms_deprisa_enabled'            => 'no',
            'ltms_zapsign_api_token'                  => '',
            'ltms_zapsign_enabled'                    => 'no',
            'ltms_kyc_zapsign_enabled'                => 'no',
            'ltms_zapsign_sandbox'                    => '1',    // sandbox por defecto — apagar en producción
            // Fix M-7: template_id must NOT be hardcoded — it's account-specific.
            // The previous default '526a9570-...' only works for the LoTengo Colombia
            // account. Other deployments (Mexico, other clients) would get a 404 from
            // ZapSign. Now empty by default — admin must configure it explicitly.
            'ltms_zapsign_vendor_template_id'         => '',
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

            // v2.7.0 — Donaciones Fundación Cardio Infantil
            // El motor de donaciones (class-ltms-donation-engine.php) lee estas
            // opciones al procesar cada orden completada. Defaults conservadores:
            // disabled + percentage 0 + sin certificados activos. El admin debe
            // habilitar explícitamente en Settings → Donaciones.
            'ltms_donation_enabled'              => 'no',
            'ltms_donation_percentage'           => 0.0,
            'ltms_donation_min_amount'           => 0.0,
            'ltms_donation_max_amount'           => 0.0,
            'ltms_donation_basis'                => 'platform_fee',
            'ltms_donation_rounding'             => 'none',
            'ltms_donation_foundation_name'      => 'Fundación Cardio Infantil',
            'ltms_donation_foundation_nit'       => '',
            'ltms_donation_foundation_contact'   => '',
            'ltms_donation_foundation_email'     => '',
            'ltms_donation_alegra_account_id'    => 0,
            'ltms_donation_payout_frequency'     => 'monthly',
            'ltms_donation_payout_day'           => 15,
            'ltms_donation_vendor_transparency'  => 'yes',
            'ltms_donation_customer_opt_in'      => 'no',
            'ltms_donation_tax_deductible'       => 'yes',
            'ltms_donation_certificate_enabled'  => 'yes',

            // v3.1.0 — Cross-Border Commerce (Task 63-C)
            // El motor cross-border (LTMS_Admin_Cross_Border + LTMS_FX_Rate_Provider)
            // lee estas opciones al procesar órdenes internacionales. Defaults
            // conservadores: deshabilitado + moneda base USD + 3 monedas latam
            // + spread 1.5% + provider Frankfurter + incoterm DDU + KYC y fraud
            // screening activos. El admin debe habilitar explícitamente en
            // Settings → Cross-Border.
            'ltms_cross_border_enabled'                    => 'no',
            'ltms_base_currency'                           => 'USD',
            'ltms_enabled_currencies'                      => [ 'COP', 'MXN', 'USD' ],
            'ltms_fx_spread_percentage'                    => 1.5,
            'ltms_fx_provider'                             => 'frankfurter',
            'ltms_fx_cache_ttl_hours'                      => 6,
            'ltms_fx_manual_overrides'                     => '',
            'ltms_default_incoterm'                        => 'DDU',
            'ltms_customs_duty_rates'                      => '',
            'ltms_customs_fees'                            => '',
            'ltms_cross_border_origin_countries'           => [],
            'ltms_cross_border_destination_countries'      => [],
            'ltms_de_minimis_thresholds'                   => '',
            'ltms_cross_border_kyc_required'               => 'yes',
            'ltms_cross_border_fraud_screening'            => 'yes',
            'ltms_international_shipping_carriers'         => [],
            'ltms_customs_broker_contact'                  => '',
            'ltms_customs_broker_email'                    => '',
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
            if ( ! isset( $schedules['every_15_minutes'] ) ) {
                $schedules['every_15_minutes'] = [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 15 Minutes', 'ltms' ),
                ];
            }
            // RB-1/RB-2 FIX (v2.9.19): "monthly" y "yearly" no son schedules nativos de WordPress.
            // "monthly" a veces existe pero no es confiable; "yearly" JAMÁS existe.
            // Sin estos schedules, wp_schedule_event('monthly'/'yearly') falla silenciosamente
            // y TODOS los hooks ltms_monthly_cron + ltms_yearly_cron son dead code desde v2.9.13.
            if ( ! isset( $schedules['monthly'] ) ) {
                $schedules['monthly'] = [
                    'interval' => 30 * DAY_IN_SECONDS, // WP core usa ~30 días; proche to "monthly".
                    'display'  => __( 'Once Monthly', 'ltms' ),
                ];
            }
            if ( ! isset( $schedules['yearly'] ) ) {
                $schedules['yearly'] = [
                    'interval' => 365 * DAY_IN_SECONDS,
                    'display'  => __( 'Once Yearly', 'ltms' ),
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
            'ltms_every_15_minutes'      => [ 'recurrence' => 'every_15_minutes',  'time' => null ], // SB-1: carrito abandonado.
            'ltms_approve_payout_cron'   => [ 'recurrence' => 'daily',             'time' => '06:00:00' ],
            'ltms_daily_cron'            => [ 'recurrence' => 'daily',             'time' => '01:00:00' ], // M-46: consumer protection holds
            'ltms_alegra_retry_failed'   => [ 'recurrence' => 'hourly',            'time' => null ],       // Reintentar facturas Alegra fallidas
            // RB-1/RB-2 FIX (v2.9.19): crons mensual y anual ahora SÍ se agendan.
            // Antes de este fix, ltms_monthly_cron y ltms_yearly_cron eran
            // hook listeners registered (add_action) pero NUNCA disparados
            // (sin wp_schedule_event) → silent dead code desde v2.9.13.
            // Afectados: NC-4 cierre contable, NC-6 AR/AP reconciliation,
            // FT-1 SOS reports, FT-2 rescreen vendors, FT-7 CRS/FATCA anual,
            // FT-5 PCI DSS anual, RT-2 sanitary expiry, PP-7 batch traceability,
            // LT annual carrier docs expiry, CB annual cross-border review,
            // NT-3 FONTUR report.
            'ltms_monthly_cron'          => [ 'recurrence' => 'monthly',            'time' => '03:30:00' ], // Día 1 del mes, 03:30 UTC.
            'ltms_yearly_cron'           => [ 'recurrence' => 'yearly',             'time' => '04:30:00' ], // 1 enero + aniversario activate, 04:30 UTC.
        ];

        foreach ( $jobs as $hook => $config ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                $timestamp = $config['time']
                    ? strtotime( gmdate( 'Y-m-d' ) . ' ' . $config['time'] )
                    : time();

                wp_schedule_event( $timestamp, $config['recurrence'], $hook );
            }
        }

        // INT-BUG-4 FIX: 3 cron hooks were previously MISSING from the activator —
        // they were registered ad-hoc by individual modules on first load, which
        // means a fresh install with no cron trigger would never run them.
        // Scheduling them in activate() guarantees they exist from minute 0.
        // The offsets (+3600 / +1800) defer the first run so they don't all fire
        // simultaneously with the activate() request itself.
        if ( ! wp_next_scheduled( 'ltms_check_rnt_expiry' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'ltms_check_rnt_expiry' );
        }
        if ( ! wp_next_scheduled( 'ltms_check_aveonboarding_reminders' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'ltms_check_aveonboarding_reminders' );
        }
        if ( ! wp_next_scheduled( 'ltms_zapsign_poll_pending' ) ) {
            wp_schedule_event( time() + 1800, 'hourly', 'ltms_zapsign_poll_pending' );
        }

        // 60-C — Donation motor crons.
        //   - ltms_donation_payout_cron: batch transfer to the foundation bank
        //     account. Frequency is configurable (ltms_donation_payout_frequency):
        //       'weekly'     → weekly schedule
        //       'monthly'    → monthly schedule (default)
        //       'quarterly'  → monthly schedule (CRON-BUG-3 / Task 62-C: quarterly
        //                       is not a WP native recurrence; we schedule monthly
        //                       and rely on LTMS_Donation_Manager::process_payout_batch()
        //                       to short-circuit on non-quarter-boundary months.
        //                       TODO (Task 62-A scope): add is_quarter_end() check
        //                       at the top of process_payout_batch — return null on
        //                       non-quarter months (Jan, Apr, Jul, Oct 1st = process;
        //                       other months = skip).
        //   - ltms_donation_certificate_cron: monthly generation of tax-deductible
        //     donation certificates for donors (Colombia: certificado de donaciones
        //     Ley 1819/2016 art. 257).
        // First run is offset +86400s (1 day) so it doesn't collide with the
        // activate() request and the foundation wallet is fully initialized.
        if ( ! wp_next_scheduled( 'ltms_donation_payout_cron' ) ) {
            $frequency = LTMS_Core_Config::get( 'ltms_donation_payout_frequency', 'monthly' );
            // CRON-BUG-3 / Task 62-C: explicit schedule mapping. 'quarterly'
            // schedules monthly (WP has no quarterly recurrence); the actual
            // quarter-boundary filter must live in process_payout_batch().
            $schedule = $frequency === 'weekly' ? 'weekly' : 'monthly';
            wp_schedule_event( time() + 86400, $schedule, 'ltms_donation_payout_cron' );
        }
        if ( ! wp_next_scheduled( 'ltms_donation_certificate_cron' ) ) {
            wp_schedule_event( time() + 86400, 'monthly', 'ltms_donation_certificate_cron' );
        }

        // AUDIT-REDI-UX-GAPS GAP-9 FIX: cron hourly para SLA check de incidencias ReDi.
        if ( ! wp_next_scheduled( 'ltms_redi_incident_sla_check' ) ) {
            wp_schedule_event( time() + 3600, 'hourly', 'ltms_redi_incident_sla_check' );
        }
    }

    /**
     * 60-C — Crea la wallet de la fundación (vendor ID especial -1).
     *
     * El motor de donaciones (Task 60-B) usa una wallet con vendor_id = -1 para
     * acumular las donaciones antes de transferirlas a la cuenta bancaria de la
     * fundación. Esta wallet debe existir desde la activación para que las
     * donaciones individuales (que llaman Wallet::credit dentro del hook
     * 'ltms_order_paid_after_split') no fallen por wallet-not-found.
     *
     * INT-BUG-1 / Task 62-C: vendor_id=-1 requiere que las columnas
     * `vendor_id` de `lt_vendor_wallets`, `lt_wallet_transactions` y
     * `lt_wallet_journal` sean BIGINT (signed), no BIGINT UNSIGNED. Sin esta
     * corrección (aplicada en class-ltms-db-migrations.php v2.7.1), el INSERT
     * falla en MySQL strict mode con "Out of range value for column 'vendor_id'".
     *
     * Defensive: si el motor de donaciones no está cargado (clase
     * LTMS_Donation_Manager no existe), se omite silenciosamente — la wallet se
     * creará on-demand por Wallet::get_or_create en la primera donación.
     *
     * @return void
     */
    private static function create_foundation_wallet(): void {
        if ( ! class_exists( 'LTMS_Business_Wallet' ) ) {
            return;
        }
        if ( ! class_exists( 'LTMS_Donation_Manager' ) ) {
            // Motor de donaciones no cargado — la wallet se creará on-demand
            // cuando el motor esté disponible. No es un error.
            return;
        }

        try {
            $currency = LTMS_Core_Config::get_currency();
            LTMS_Business_Wallet::get_or_create(
                LTMS_Donation_Manager::FOUNDATION_VENDOR_ID,
                $currency
            );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'FOUNDATION_WALLET_CREATED',
                    sprintf( 'Wallet de fundación creada (vendor_id=%d, currency=%s)', LTMS_Donation_Manager::FOUNDATION_VENDOR_ID, $currency )
                );
            }
        } catch ( \Throwable $e ) {
            // No propagar — la activación no debe fallar si la wallet no se pudo
            // crear (la tabla lt_vendor_wallets podría no existir aún si las
            // migraciones no corrieron correctamente). Se loguea y se continúa.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FOUNDATION_WALLET_CREATE_FAILED',
                    sprintf( 'No se pudo crear la wallet de fundación: %s', $e->getMessage() )
                );
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
            // M-QA-PAGES-01: Páginas añadidas en v2.8 (reservas y compliance turístico).
            // La pestaña Reservas del panel vendedor no requiere página propia (vive en el SPA),
            // pero se registra aquí para que el admin la vea en el panel de páginas.
            // La de turismo apunta al endpoint /mi-cuenta/ltms-rnt/ vía shortcode redireccionador.
            'ltms-bookings'        => [
                'title'   => 'Mis Reservas',
                'content' => '[ltms_vendor_bookings]',
                'slug'    => 'mis-reservas',
            ],
            'ltms-rnt'             => [
                'title'   => 'RNT / Turismo',
                'content' => '[ltms_vendor_rnt]',
                'slug'    => 'rnt-turismo',
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
     * PERM-2 (Task 57-E): File permissions are enforced so that BOTH the
     * web server AND the admin can read/write:
     *   - Directories: 0755 (rwxr-xr-x) — admin can list + create files.
     *   - Static protection files (.htaccess, index.php): 0644 (rw-r--r--).
     *   - Generated content files (logs, contracts, invoices): 0664
     *     (rw-rw-r--) so the web server and admin can both append/write.
     *
     * wp_mkdir_p() respects the WP FS_METHOD and the configured umask, so
     * we explicitly chmod afterwards to guarantee the recommended mode
     * regardless of the host's default umask (some hosts use 0775 or 0700).
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

            // PERM-2: enforce 0755 on directories so the admin can list/create
            // files inside (admin editor requires the plugin folders to be
            // readable+executable by the web server; the vault dirs follow
            // the same convention for consistency).
            if ( function_exists( 'chmod' ) && is_dir( $dir ) ) {
                @chmod( $dir, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
            }

            // Proteger con .htaccess
            $htaccess = $dir . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n", LOCK_EX );
            }
            // PERM-2: static protection files use 0644.
            if ( function_exists( 'chmod' ) && file_exists( $htaccess ) ) {
                @chmod( $htaccess, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
            }

            // Proteger con index.php
            $index = $dir . 'index.php';
            if ( ! file_exists( $index ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $index, '<?php // Silence is golden.', LOCK_EX );
            }
            // PERM-2: index.php is a static protection file — 0644.
            if ( function_exists( 'chmod' ) && file_exists( $index ) ) {
                @chmod( $index, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
            }

            // PERM-2: for directories that hold generated runtime content
            // (logs, contracts, invoices, certificates), enforce 0664 on
            // any pre-existing files so the admin can read+edit them via
            // the WP admin editor or SFTP. We skip the .htaccess/index.php
            // already handled above.
            $is_generated_dir = (
                strpos( $dir, LTMS_LOG_DIR ) === 0
                || strpos( $dir, LTMS_VAULT_DIR . 'contracts/' ) === 0
                || strpos( $dir, LTMS_VAULT_DIR . 'invoices/' ) === 0
                || strpos( $dir, LTMS_VAULT_DIR . 'certificates/' ) === 0
                || strpos( $dir, LTMS_VAULT_DIR . 'kyc/' ) === 0
            );
            if ( $is_generated_dir && is_dir( $dir ) ) {
                $files = glob( $dir . '*' ) ?: [];
                foreach ( $files as $f ) {
                    if ( ! is_file( $f ) ) {
                        continue;
                    }
                    $base = basename( $f );
                    if ( '.htaccess' === $base || 'index.php' === $base ) {
                        continue;
                    }
                    if ( function_exists( 'chmod' ) ) {
                        @chmod( $f, 0664 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
                    }
                }
            }
        }
    }

    /**
     * INT-BUG-14 FIX: Crea la tabla de cola de emails (`lt_email_queue`).
     *
     * All emails in the plugin currently go out via direct wp_mail() calls —
     * synchronous and blocking. A failed SMTP connection (timeouts, rate limits,
     * DNS issues) blocks the calling request, which has already caused:
     *   - Registration flows where a bad SMTP deletes the vendor account (REG-BUG-2)
     *   - Payout approval pages that hang for 30s when the email server is slow
     *   - KYC approval flows that surface a 500 to the admin
     *
     * The queue table is the infrastructure for a future async email sender:
     *   - Callers enqueue via LTMS_Email_Queue::enqueue($to, $subject, $body, $headers)
     *   - A cron job (ltms_process_email_queue, to be wired up) will poll and send.
     *
     * NOTE: full migration of all wp_mail() callers is OUT OF SCOPE for this task.
     * This method only creates the table + the enqueue() entry point so that
     * new code (and gradually migrated callers) can start using it.
     *
     * @return void
     */
    private static function create_email_queue_table(): void {
        global $wpdb;

        $table      = $wpdb->prefix . 'lt_email_queue';
        $charset    = $wpdb->get_charset_collate();
        $exists     = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        );

        if ( $exists === $table ) {
            return; // Already created — idempotent.
        }

        $sql = "CREATE TABLE `{$table}` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            body LONGTEXT NOT NULL,
            headers TEXT NULL,
            priority TINYINT(1) NOT NULL DEFAULT 5,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
            last_error TEXT NULL,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_scheduled (status, scheduled_at),
            KEY priority_scheduled (priority, scheduled_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
