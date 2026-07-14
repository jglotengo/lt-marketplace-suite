<?php
/**
 * LTMS Core Kernel - Sistema de Arranque (Bootloader)
 *
 * Orquesta la inicialización de todos los módulos del plugin
 * siguiendo el patrón de Arquitectura Hexagonal.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Kernel
 *
 * Singleton que controla el ciclo de vida y la secuencia de arranque.
 */
final class LTMS_Core_Kernel {

    /**
     * Instancia singleton.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Indica si el kernel ya fue arrancado.
     *
     * @var bool
     */
    private bool $booted = false;

    /**
     * Módulos registrados para arrancar en orden.
     *
     * @var array<int, array{class: string, priority: int}>
     */
    private array $modules = [];

    /**
     * Constructor privado (Singleton).
     */
    private function __construct() {}

    /**
     * Obtiene la instancia singleton.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Arranca el sistema completo en secuencia.
     * Orden: Infraestructura → Lógica de Negocio → API → Frontend → Admin
     *
     * @return void
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        try {
            $this->boot_infrastructure();
            $this->boot_roles();
            $this->boot_business_logic();
            $this->boot_api_integrations();
            $this->boot_frontend();
            $this->boot_admin();
            $this->boot_cron();
            $this->boot_rest_api();

            $this->booted = true;

            /**
             * Acción disparada cuando el Kernel termina de arrancar.
             * Los addons pueden engancharse aquí para extender el plugin.
             */
            do_action( 'ltms_kernel_booted' );

        } catch ( \Throwable $e ) {
            // Siempre escribir en error_log de PHP (visible en cPanel/hosting).
            // Esto permite diagnosticar el error sin re-lanzar la excepción.
            error_log( 'LTMS KERNEL BOOT ERROR: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine()
                . "\nTrace: " . $e->getTraceAsString() );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::log(
                    'KERNEL_BOOT_ERROR',
                    $e->getMessage(),
                    [ 'trace' => $e->getTraceAsString() ],
                    'CRITICAL'
                );
            }

            // NUNCA re-lanzar: si la excepción escapa de plugins_loaded,
            // WordPress entra en recovery mode y tumba el sitio.
            // El error ya queda en error_log y la página de emergencia lo mostrará.
        }
    }

    /**
     * Carga la infraestructura base (Config, Logger, Security, WAF, Cache).
     *
     * @return void
     */
    private function boot_infrastructure(): void {
        // 1. Configuración del entorno (primera en cargar)
        if ( class_exists( 'LTMS_Core_Config' ) ) {
            LTMS_Core_Config::init();
        }

        // 2. Logger forense
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::init();
        }

        // 3. Seguridad (AES, Sanitización)
        if ( class_exists( 'LTMS_Core_Security' ) ) {
            LTMS_Core_Security::init();
        }

        // 4. Firewall (WAF)
        if ( class_exists( 'LTMS_Core_Firewall' ) ) {
            LTMS_Core_Firewall::init();
        }

        // 5. Caché
        if ( class_exists( 'LTMS_Core_Cache_Manager' ) ) {
            LTMS_Core_Cache_Manager::init();
        }

        // 6. HPOS Compatibility
        if ( class_exists( 'LTMS_Core_HPOS_Compat' ) ) {
            LTMS_Core_HPOS_Compat::init();
        }
    }

    /**
     * Instala y configura los roles de usuario (RBAC).
     *
     * @return void
     */
    private function boot_roles(): void {
        if ( class_exists( 'LTMS_Roles' ) ) {
            LTMS_Roles::init();
        }
        if ( class_exists( 'LTMS_External_Auditor_Role' ) ) {
            LTMS_External_Auditor_Role::init();
        }
    }

    /**
     * Arranca la capa de Lógica de Negocio.
     *
     * @return void
     */
    private function boot_business_logic(): void {
        // Billetera
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            LTMS_Business_Wallet::init();
        }

        // F-05: Depósitos manuales de recarga de wallet
        if ( class_exists( 'LTMS_Deposit' ) ) {
            LTMS_Deposit::init();
        }
        if ( class_exists( 'LTMS_Frontend_Deposit_Handler' ) ) {
            LTMS_Frontend_Deposit_Handler::init();
        }

        // Motor de comisiones
        if ( class_exists( 'LTMS_Commission_Strategy' ) ) {
            LTMS_Commission_Strategy::init();
        }

        // Motor fiscal
        if ( class_exists( 'LTMS_Tax_Engine' ) ) {
            LTMS_Tax_Engine::init();
        }

        // Árbol de referidos MLM
        if ( class_exists( 'LTMS_Referral_Tree' ) ) {
            LTMS_Referral_Tree::init();
        }

        // Afiliados / Cookies
        if ( class_exists( 'LTMS_Affiliates' ) ) {
            LTMS_Affiliates::init();
        }

        // Scheduler de pagos
        // CR-1 FIX (Task 57-E): De-nested class_exists blocks. Previously
        // LTMS_Shipping_Mode was nested INSIDE the LTMS_Payout_Scheduler
        // if-block, so if Payout_Scheduler was missing it wouldn't initialize.
        // Each component must boot independently.
        // M-6 FIX: removed dead `if ( class_exists( 'LTMS_Accounting' ) )`
        // block — LTMS_Accounting never existed (only LTMS_Accounting_Compliance,
        // which is booted separately below at v2.9.12).
        if ( class_exists( 'LTMS_Payout_Scheduler' ) ) {
            LTMS_Payout_Scheduler::init();
        }
        if ( class_exists( 'LTMS_Shipping_Mode' ) ) {
            LTMS_Shipping_Mode::init();
        }

        // Protección al consumidor / vesting
        if ( class_exists( 'LTMS_Business_Consumer_Protection' ) ) {
            LTMS_Business_Consumer_Protection::init();
        }

        // Cumplimiento turístico (Ley 2068 Colombia)
        if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            LTMS_Business_Tourism_Compliance::init();
        }

        // v2.0.0 — Motor de reservas ACID
        if ( class_exists( 'LTMS_Booking_Manager' ) ) {
            LTMS_Booking_Manager::init();
        }
        // M-52: Booking Season Manager tiene init() con add_filter — faltaba boot en kernel.
        if ( class_exists( 'LTMS_Booking_Season_Manager' ) ) {
            LTMS_Booking_Season_Manager::init();
        }
        // M-BOOKING-PLAN-03: Booking Policy Handler tiene AJAX en init() — faltaba boot en kernel.
        if ( class_exists( 'LTMS_Booking_Policy_Handler' ) ) {
            LTMS_Booking_Policy_Handler::init();
        }
        // M-BOOKING-UI-03: notificaciones por email del ciclo de vida de una
        // reserva (nueva al vendedor, confirmada/cancelada al comprador).
        // Los templates en templates/emails/email-booking-*.php existían
        // desde antes pero nunca se cargaban en ningún punto del código.
        if ( class_exists( 'LTMS_Booking_Notifications' ) ) {
            LTMS_Booking_Notifications::init();
        }

        // Listeners de eventos de dominio
        if ( class_exists( 'LTMS_Order_Paid_Listener' ) ) {
            LTMS_Order_Paid_Listener::init();
        }
        if ( class_exists( 'LTMS_TPTC_Listener' ) ) {
            LTMS_TPTC_Listener::init();
        }
        if ( class_exists( 'LTMS_Coupon_Attribution_Listener' ) ) {
            LTMS_Coupon_Attribution_Listener::init();
        }

        // v2.8.3 — Shipping Cost Ledger & Reconciliation Engine.
        if ( class_exists( 'LTMS_Shipping_Cost_Ledger' ) ) {
            LTMS_Shipping_Cost_Ledger::init();
        }

        // v2.8.3 — Admin UI para el ledger (solo en backend).
        if ( class_exists( 'LTMS_Admin_Shipping_Ledger' ) && is_admin() ) {
            LTMS_Admin_Shipping_Ledger::init();
        }

        // v2.9.2 — Product Page Enhancements (Trust Badges, Cart Drawer, Bundles, Comparison, Video).
        if ( class_exists( 'LTMS_Trust_Badges' ) ) {
            LTMS_Trust_Badges::init();
        }
        if ( class_exists( 'LTMS_Cart_Drawer' ) ) {
            LTMS_Cart_Drawer::init();
        }
        if ( class_exists( 'LTMS_Product_Bundles' ) ) {
            LTMS_Product_Bundles::init();
            // Crear tabla si no existe (idempotente).
            if ( is_admin() && ! wp_next_scheduled( 'ltms_create_bundles_table' ) ) {
                LTMS_Product_Bundles::create_table();
            }
        }
        if ( class_exists( 'LTMS_Comparison_Table' ) ) {
            LTMS_Comparison_Table::init();
        }
        if ( class_exists( 'LTMS_Product_Video' ) ) {
            LTMS_Product_Video::init();
        }

        // v2.9.4 — WoodMart-inspired features (Quick View, Wishlist, Product Tabs, Rating Summary).
        if ( class_exists( 'LTMS_Quick_View' ) ) {
            LTMS_Quick_View::init();
        }
        if ( class_exists( 'LTMS_Wishlist' ) ) {
            LTMS_Wishlist::init();
        }
        if ( class_exists( 'LTMS_Product_Tabs' ) ) {
            LTMS_Product_Tabs::init();
        }
        if ( class_exists( 'LTMS_Rating_Summary' ) ) {
            LTMS_Rating_Summary::init();
        }

        // v2.9.5 — Amazon-inspired features (Delivery Promise, Verified Purchase, Add-on Items, Gift Options, Browsing History).
        if ( class_exists( 'LTMS_Amazon_Enhancements' ) ) {
            LTMS_Amazon_Enhancements::init();
        }

        // v2.9.6 — Compliance Guardian (Meta policies + Ley 1581 + LFPDPPP + PLD).
        if ( class_exists( 'LTMS_Compliance_Guardian' ) ) {
            LTMS_Compliance_Guardian::init();
        }

        // v2.9.8 — Fiscal Online Access (Art. 30-B CFF SAT + E.T. 437-2 DIAN).
        if ( class_exists( 'LTMS_Fiscal_Online_Access' ) ) {
            LTMS_Fiscal_Online_Access::init();
            // Hook: guardar datos fiscales del vendor al aprobar KYC.
            add_action( 'ltms_vendor_approved', [ 'LTMS_Fiscal_Online_Access', 'on_kyc_approved' ], 5, 1 );
        }

        // v2.9.9 — Tourism Compliance Extension (NT-3 a NT-6).
        if ( class_exists( 'LTMS_Tourism_Compliance_Ext' ) ) {
            LTMS_Tourism_Compliance_Ext::init();
        }

        // v2.9.10 — MLM Compliance Guardian (NA-1 a NA-5: disclaimer, anti-pirámide, consent, no-compra, reporte anual).
        if ( class_exists( 'LTMS_MLM_Compliance_Guardian' ) ) {
            LTMS_MLM_Compliance_Guardian::init();
        }

        // v2.9.11 — Fiscal Annual Close (LF-3: GMF 4x1000 + LF-4: certificado retenciones + LF-5: PAC CFDI).
        if ( class_exists( 'LTMS_Fiscal_Annual_Close' ) ) {
            LTMS_Fiscal_Annual_Close::init();
        }

        // v2.9.12 — Accounting Compliance (NC-1: ReteIVA/ReteICA en factura + NC-2: FX gain/loss + NC-3: DIAN resolución + NC-4: cierre mensual + NC-6: conciliación AR/AP).
        if ( class_exists( 'LTMS_Accounting_Compliance' ) ) {
            LTMS_Accounting_Compliance::init();
        }

        // v2.9.13 — Privacy Toolkit (PR-2: WordPress data exporter + PR-3: extended eraser + PR-5: retention cron).
        if ( class_exists( 'LTMS_Privacy_Toolkit' ) ) {
            LTMS_Privacy_Toolkit::init();
        }

        // v2.9.14 — Restaurant Compliance (RT-1: alcohol age + RT-2: sanitary reg + RT-3: alérgenos + RT-4: horarios + RT-5: propina + RT-6: impoconsumo bug + RT-7: cold chain).
        if ( class_exists( 'LTMS_Restaurant_Compliance' ) ) {
            LTMS_Restaurant_Compliance::init();
        }

        // v2.9.15 — Physical Products Compliance (PP-1: warranty + PP-2: country of origin + PP-3: hazmat + PP-4: certifications + PP-5: textile labeling + PP-6: ICE/IEPS + PP-7: batch + PP-8: FTA customs).
        if ( class_exists( 'LTMS_Physical_Products_Compliance' ) ) {
            LTMS_Physical_Products_Compliance::init();
        }

        // v2.9.16 — Fintech Compliance (FT-1: SOS reports + FT-2: sanctions screening + FT-3: operational limits + FT-4: Travel Rule + FT-5: PCI DSS + FT-6: 2FA vendors + FT-7: CRS/FATCA + FT-8: PLD MX UMA).
        if ( class_exists( 'LTMS_Fintech_Compliance' ) ) {
            LTMS_Fintech_Compliance::init();
        }

        // v2.9.17 — Logistics Compliance (LT-1: Carta Porte CFDI + LT-2: RNT CO + LT-3: SCT MX + LT-4: pesos/dimensiones + LT-5: RC transportista + LT-6: ISO 17712 + LT-7: GPS + LT-8: DVA + LT-9: Deprisa bug).
        if ( class_exists( 'LTMS_Logistics_Compliance' ) ) {
            LTMS_Logistics_Compliance::init();
        }

        // v2.9.18 — Cross-Border Compliance (CB-1: cert origin + CB-2: incoterms 2020 + CB-3: IOSS UE + CB-4: AES US + CB-5: FX declaration + CB-6: non-resident IVA + CB-7: VUCE + CB-8: EUR.1/ATR.1/Form A + CB-9: de minimis currency bug).
        if ( class_exists( 'LTMS_Cross_Border_Compliance' ) ) {
            LTMS_Cross_Border_Compliance::init();
        }

        // v2.9.20 — Authorities Compliance (AC-1: counterfeit IP + AC-2: PQR formal + AC-3: PPC SIC + AC-4: ICA fitosanitario + AC-5: RESPEL/RAEE + AC-6: conciliación SIC + AC-7: RUT/CC DIAN + AC-8: INVIMA anual + AC-9: competencia desleal).
        if ( class_exists( 'LTMS_Authorities_Compliance' ) ) {
            LTMS_Authorities_Compliance::init();
        }

        // v2.9.21 — Data Protection Compliance (HD-1: CSP + HD-2: registro SIC + HD-3: transfer int + HD-4: aviso simplif vs integral + HD-5: DPIA + HD-6: DPO + HD-7: bitácora + HD-8: cifrado PII + HD-9: rotación claves + HD-10: brechas + HD-11: capacitación + HD-12: menores).
        if ( class_exists( 'LTMS_Data_Protection_Compliance' ) ) {
            LTMS_Data_Protection_Compliance::init();
        }

        // v2.9.22 — SEO Enhanced (Sprint 1: 7 RSS feeds + Schema.org ampliado + llms.txt AEO + sitemap index + robots.txt + Core Web Vitals hints).
        if ( class_exists( 'LTMS_SEO_Enhanced' ) ) {
            LTMS_SEO_Enhanced::init();
        }

        // v2.9.23 — Foundation Compliance (FN-1: RTE + FN-2: límite deducibilidad + FN-3: reporte DIAN 1737 + FN-4: screening AML donantes + FN-5: consentimiento donante + FN-6: verificación cuenta + FN-7: transparencia ESAL + FN-8: donaciones cross-border).
        if ( class_exists( 'LTMS_Foundation_Compliance' ) ) {
            LTMS_Foundation_Compliance::init();
        }

        // v2.9.24 — Jurisprudence Compliance (JU-1: takedown 48h + JU-2: retracto irrenunciable + JU-3: PQR vendor + JU-4: defensa filtros + JU-5: vigilancia PI + JU-6: publicidad comparativa + JU-7: Nutri-Score + JU-8: cooperación judicial).
        if ( class_exists( 'LTMS_Jurisprudence_Compliance' ) ) {
            LTMS_Jurisprudence_Compliance::init();
        }

        // v2.9.27 — TOTP 2FA (SEC-15: implementación real RFC 6238).
        if ( class_exists( 'LTMS_TOTP_2FA' ) ) {
            LTMS_TOTP_2FA::init();
        }

        // v2.9.28 — Sales Booster (SB-1: carrito abandonado + SB-2: flash sales + SB-3: push notifications + SB-4: upsell/cross-sell + SB-5: social proof).
        if ( class_exists( 'LTMS_Sales_Booster' ) ) {
            LTMS_Sales_Booster::init();
        }

        // v2.9.29 — Traffic Booster (TB-1: Google Shopping Feed + TB-2: Social Commerce + TB-3: Newsletter + TB-4: City Pages + TB-5: GBP).
        if ( class_exists( 'LTMS_Traffic_Booster' ) ) {
            LTMS_Traffic_Booster::init();
        }

        // v2.9.30 — Branding Engine (BR-1: logo Google Knowledge Panel + BR-2: favicon meta + BR-3: psicología color CSS + BR-4: OG logo + BR-5: trust signals + BR-6: loss aversion + BR-7: reciprocidad + BR-8: anclaje).
        if ( class_exists( 'LTMS_Branding_Engine' ) ) {
            LTMS_Branding_Engine::init();
        }

        // Encolar CSS + JS de product enhancements en frontend.
        add_action( 'wp_enqueue_scripts', function() {
            $ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.2';
            $url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '';
            wp_enqueue_style( 'ltms-product-enhancements', $url . 'css/ltms-product-enhancements.css', [], $ver );
            wp_enqueue_script( 'ltms-product-enhancements', $url . 'js/ltms-product-enhancements.js', [ 'jquery' ], $ver, true );
            wp_localize_script( 'ltms-product-enhancements', 'ltmsDrawerData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'ajaxurl' => admin_url( 'admin-ajax.php' ), // v2.9.40: some JS code uses ajaxurl directly
                'nonce'   => wp_create_nonce( 'ltms_drawer_nonce' ),
                'i18n'    => [
                    'remove'           => __( 'Quitar', 'ltms' ),
                    'empty'            => __( 'Tu carrito está vacío', 'ltms' ),
                    'subtotal'         => __( 'Subtotal', 'ltms' ),
                    'checkout'         => __( 'Finalizar compra', 'ltms' ),
                    'viewCart'         => __( 'Ver carrito completo', 'ltms' ),
                    'upsells'          => __( 'También te puede interesar', 'ltms' ),
                    'add'              => __( 'Agregar', 'ltms' ),
                    'adding'           => __( 'Agregando...', 'ltms' ),
                    'error'            => __( 'Error', 'ltms' ),
                    'reserved'         => __( 'Tu carrito está reservado por', 'ltms' ),
                    'reservedExpired'  => __( 'Tiempo de reserva agotado. Los precios pueden haber cambiado.', 'ltms' ),
                    'tncText'          => __( 'Acepto los', 'ltms' ),
                    'tncLink'          => __( 'Términos y Condiciones', 'ltms' ),
                    'tncWarning'       => __( 'Debes aceptar los Términos y Condiciones para continuar.', 'ltms' ),
                    'inWishlist'       => __( 'En tu wishlist', 'ltms' ),
                    'addToWishlist'    => __( 'Agregar a wishlist', 'ltms' ),
                ],
            ] );
        } );

        // v1.6.0 — Módulos Enterprise
        if ( class_exists( 'LTMS_Media_Guard' ) ) {
            LTMS_Media_Guard::init();
        }
        if ( class_exists( 'LTMS_XCover_Checkout_Handler' ) ) {
            LTMS_XCover_Checkout_Handler::init();
        }
        if ( class_exists( 'LTMS_XCover_Policy_Listener' ) ) {
            LTMS_XCover_Policy_Listener::init();
        }
        if ( class_exists( 'LTMS_Business_Redi_Manager' ) ) {
            LTMS_Business_Redi_Manager::init();
        }
        if ( class_exists( 'LTMS_Redi_Order_Listener' ) ) {
            LTMS_Redi_Order_Listener::init();
        }
        // v2.9.132 AUDIT-AUDIT: Listeners for actions added during audit cycle.
        if ( class_exists( 'LTMS_Audit_Listeners' ) ) {
            LTMS_Audit_Listeners::init();
        }
        // AUDIT-REDI-UX-GAPS GAP-9 FIX: Incident Manager para el modelo ReDi.
        if ( class_exists( 'LTMS_Business_Redi_Incident' ) ) {
            LTMS_Business_Redi_Incident::init();
        }
        // v2.9.2 — Ave-Hub: reporta a Aveonline el estado de envíos propios (domiciliario, pickup, etc.)
        if ( class_exists( 'LTMS_Aveonline_Hub_Listener' ) ) {
            LTMS_Aveonline_Hub_Listener::init();
        }
        if ( class_exists( 'LTMS_Business_Pickup_Handler' ) ) {
            LTMS_Business_Pickup_Handler::init();
        }

        // v2.1.0 — Sincronización contable con Alegra
        if ( class_exists( 'LTMS_Alegra_Sync' ) ) {
            LTMS_Alegra_Sync::init();
        }

        // v3.0.0 — Motor de Donaciones (Task 60-B): donaciones automáticas a
        // fundación desde comisión de plataforma. Loose-coupling: si la clase
        // no existe (motor deshabilitado o no cargado), el hook
        // 'ltms_order_paid_after_split' disparado por Order_Split simplemente
        // no tiene listeners y es un no-op.
        if ( class_exists( 'LTMS_Donation_Manager' ) ) {
            LTMS_Donation_Manager::init();
        }

        // v2.2.0 — Agentes Aveonline por vendedor
        if ( class_exists( 'LTMS_Business_Aveonline_Agents' ) ) {
            LTMS_Business_Aveonline_Agents::init();
        }

        // v2.2.0 — Catálogo de ciudades Aveonline (sincronización desde JSON oficial)
        if ( class_exists( 'LTMS_Business_Aveonline_Carriers' ) ) {
            LTMS_Business_Aveonline_Carriers::init();
        }
        if ( class_exists( 'LTMS_Business_Aveonline_Cities' ) ) {
            LTMS_Business_Aveonline_Cities::init();
        }

        if ( class_exists( 'LTMS_Business_Aveonline_ShipmentRelations' ) ) {
            LTMS_Business_Aveonline_ShipmentRelations::init();
        }

        // v2.7.0 — Guías de envío del vendedor (tabla local + 6 handlers AJAX)
        if ( class_exists( 'LTMS_Business_Aveonline_Guias' ) ) {
            LTMS_Business_Aveonline_Guias::init();
        }

        // v2.8.0 — Órdenes de Compra Aveonline
        if ( class_exists( 'LTMS_Business_Aveonline_OrdenCompra' ) ) {
            LTMS_Business_Aveonline_OrdenCompra::init();
        }

        // v2.9.1 — Sandbox Aveonline (QA: obtenerEstadoAuth + avanzarEstado)
        if ( class_exists( 'LTMS_Business_Aveonline_Sandbox' ) ) {
            LTMS_Business_Aveonline_Sandbox::init();
        }

        // v2.9.2 — Ave-Hub: log local de eventos de estado reportados (envíos propios)
        if ( class_exists( 'LTMS_Business_Aveonline_Hub_Log' ) ) {
            LTMS_Business_Aveonline_Hub_Log::init();
        }

        // L-1..L-8: Cumplimiento legal — Habeas Data, consentimientos, vault log
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::init();
        }

        // ZapSign — Gestión completa de contratos de vendedores
        if ( class_exists( 'LTMS_ZapSign_Manager' ) ) {
            LTMS_ZapSign_Manager::init();
        }

        // v3.1.0 — Cross-Border motor (Task 63-A/B): Currency Manager,
        // FX Rate Provider and Customs Calculator. These are stateless
        // utility classes (no init() method required) — we just verify
        // their presence so the autoloader loads them eagerly on boot
        // and downstream callers (Order_Split, Checkout_Handler, Alegra)
        // can rely on them being available. The Tax_Engine::init() call
        // above already registers US/EU/BR strategies defensively.
        if ( class_exists( 'LTMS_FX_Rate_Provider' ) ) {
            // Stateless — no init() required. Class existence check
            // forces autoload so the class is available for the rest
            // of the request lifecycle.
            LTMS_FX_Rate_Provider::get_supported_currencies();
        }
        if ( class_exists( 'LTMS_Currency_Manager' ) ) {
            // Stateless — verify presence + warm autoloader.
            LTMS_Currency_Manager::get_base_currency();
        }
        // LTMS_Customs_Calculator is invoked on-demand by Order_Split
        // and Checkout_Handler — no boot-time initialisation needed.
    }

    /**
     * Arranca los conectores de API externas y pasarelas de pago.
     *
     * @return void
     */
    private function boot_api_integrations(): void {
        // v2.0.0 — Tipo de producto ltms_bookable
        add_filter( 'product_type_selector', static function( array $types ): array {
            return array_merge( $types, [ 'ltms_bookable' => __( 'Producto Reservable (LTMS)', 'ltms' ) ] );
        } );
        add_filter( 'woocommerce_product_class', static function( string $classname, string $type ): string {
            return 'ltms_bookable' === $type ? 'LTMS_Product_Bookable' : $classname;
        }, 10, 2 );

        // v2.0.0 — Setup políticas por defecto para vendedores nuevos
        add_action( 'ltms_vendor_approved', static function( int $vendor_id ): void {
            if ( class_exists( 'LTMS_Booking_Policy_Handler' ) ) {
                LTMS_Booking_Policy_Handler::setup_default_policies( $vendor_id );
            }
        } );

        // Registrar pasarelas de pago en WooCommerce
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_payment_gateways' ] );

        // Registrar métodos de envío (v1.6.0)
        add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_methods' ] );

        // Registrar proveedor Heka en la API Factory (v1.6.0)
        if ( class_exists( 'LTMS_Api_Factory' ) && class_exists( 'LTMS_Api_Heka' ) ) {
            LTMS_Api_Factory::register( 'heka', 'LTMS_Api_Heka' );
        }

        // Webhook router
        if ( class_exists( 'LTMS_Api_Webhook_Router' ) ) {
            LTMS_Api_Webhook_Router::init();
        }

        // Handlers de webhooks individuales
        $webhook_handlers = [
            'LTMS_Addi_Webhook_Handler',
            'LTMS_Openpay_Webhook_Handler',
            'LTMS_Siigo_Webhook_Handler',
            'LTMS_Aveonline_Webhook_Handler',
            'LTMS_Zapsign_Webhook_Handler',
            'LTMS_Uber_Direct_Webhook_Handler', // v1.6.0
            'LTMS_Stripe_Webhook_Handler',       // v1.7.0
        ];

        foreach ( $webhook_handlers as $handler ) {
            if ( class_exists( $handler ) ) {
                $handler::init();
            }
        }

        // v2.1.0 — Webhook handler de Alegra
        if ( class_exists( 'LTMS_Alegra_Webhook_Handler' ) ) {
            LTMS_Alegra_Webhook_Handler::init();
        }
    }

    /**
     * Registra los métodos de envío LTMS en WooCommerce.
     *
     * @param array $methods Lista actual de métodos de envío.
     * @return array
     */
    public function register_shipping_methods( array $methods ): array {
        $shipping_classes = [
            'LTMS_Shipping_Method_Uber_Direct',
            'LTMS_Shipping_Method_Aveonline',
            'LTMS_Shipping_Method_Heka',
            'LTMS_Shipping_Method_Pickup',
            'LTMS_Shipping_Method_Own_Delivery',  // v1.7.0
            'LTMS_Shipping_Method_Free_Absorbed', // v2.0.0
        ];

        foreach ( $shipping_classes as $class ) {
            if ( class_exists( $class ) ) {
                $methods[] = $class;
            }
        }

        return $methods;
    }

    /**
     * Registra las pasarelas de pago en el filtro de WooCommerce.
     *
     * @param array $gateways Lista actual de pasarelas.
     * @return array Lista ampliada.
     */
    public function register_payment_gateways( array $gateways ): array {
        if ( class_exists( 'LTMS_Api_Gateway_Openpay' ) ) {
            $gateways[] = 'LTMS_Api_Gateway_Openpay';
        }
        if ( class_exists( 'LTMS_Api_Gateway_Addi' ) ) {
            $gateways[] = 'LTMS_Api_Gateway_Addi';
        }
        // v1.7.0 — Stripe gateway
        if ( class_exists( 'LTMS_Gateway_Stripe' ) ) {
            $gateways[] = 'LTMS_Gateway_Stripe';
        }
        // v1.7.4 — PSE gateway (Openpay CO débito bancario)
        if ( class_exists( 'LTMS_Api_Gateway_PSE' ) ) {
            $gateways[] = 'LTMS_Api_Gateway_PSE';
        }
        // v1.7.5 — Openpay MX gateway — solo se registra en instalaciones de México.
        // En lo-tengo.com.co (CO) este gateway está incompleto (falta ltms-openpay-mx.js)
        // y no debe aparecer en el checkout colombiano.
        if ( class_exists( 'LTMS_Api_Gateway_Openpay_MX' ) && 'MX' === LTMS_Core_Config::get_country() ) {
            $gateways[] = 'LTMS_Api_Gateway_Openpay_MX';
        }
        return $gateways;
    }

    /**
     * Arranca los controladores del frontend / dashboard del vendedor.
     *
     * @return void
     */
    private function boot_frontend(): void {
        if ( class_exists( 'LTMS_Frontend_Assets' ) ) {
            LTMS_Frontend_Assets::init();
        }
        if ( class_exists( 'LTMS_Dashboard_Logic' ) ) {
            LTMS_Dashboard_Logic::init();
        }
        if ( class_exists( 'LTMS_Public_Auth_Handler' ) ) {
            LTMS_Public_Auth_Handler::init();

            // Google OAuth — login / registro con Google.
            if ( class_exists( 'LTMS_Google_OAuth' ) ) {
                LTMS_Google_OAuth::init();
            }
        }
        if ( class_exists( 'LTMS_Frontend_Live_Search' ) ) {
            LTMS_Frontend_Live_Search::init();
        }
        if ( class_exists( 'LTMS_Kitchen_Ajax' ) ) {
            LTMS_Kitchen_Ajax::init();
        }
        if ( class_exists( 'LTMS_Frontend_Payout_Handler' ) ) {
            LTMS_Frontend_Payout_Handler::init();
        }
        if ( class_exists( 'LTMS_Frontend_Booking_Handler' ) ) {
            LTMS_Frontend_Booking_Handler::init();
        }
        if ( class_exists( 'LTMS_Frontend_Customer_Bookings' ) ) {
            LTMS_Frontend_Customer_Bookings::init();
        }
        // v2.4.0 — Notificaciones in-app del panel de vendedor.
        if ( class_exists( 'LTMS_Frontend_Notifications' ) ) {
            LTMS_Frontend_Notifications::init();
        }
        if ( class_exists( 'LTMS_Vendor_Settings_Saver' ) ) {
            LTMS_Vendor_Settings_Saver::init();
        }
        if ( class_exists( 'LTMS_Driver_Ajax' ) ) {
            LTMS_Driver_Ajax::init();
        }
        if ( class_exists( 'LTMS_Products_Ajax' ) ) {
            new LTMS_Products_Ajax();
        }

        // M-14 FIX: handler de checkout AJAX (ltms_process_checkout + ltms_get_pse_banks)
        // El JS ltms-checkout.js llamaba estas acciones pero no existía ningún handler PHP.
        if ( class_exists( 'LTMS_Frontend_Checkout_Handler' ) ) {
            LTMS_Frontend_Checkout_Handler::init();
        }
        if ( class_exists( 'LTMS_Frontend_Checkout_Mexico_Handler' ) ) {
            LTMS_Frontend_Checkout_Mexico_Handler::init();
        }
        // M-200: dropdown DANE de municipios en checkout CO (territorialidad ReteICA).
        if ( class_exists( 'LTMS_Frontend_Checkout_Municipality_Field' ) ) {
            LTMS_Frontend_Checkout_Municipality_Field::init();
        }
        // v2.1.0: selección de oficina / punto de entrega Aveonline en checkout.
        if ( class_exists( 'LTMS_Frontend_Checkout_Aveonline_Office' ) ) {
            LTMS_Frontend_Checkout_Aveonline_Office::init();
        }
        if ( class_exists( 'LTMS_Secure_Downloads' ) ) {
            LTMS_Secure_Downloads::init();
        }

        // v2.0.0 — Calendario de reservas
        if ( class_exists( 'LTMS_Booking_Calendar' ) ) {
            LTMS_Booking_Calendar::init();
        }

        // v2.0.0 — SEO técnico (Schema.org, Open Graph, Sitemap)
        if ( class_exists( 'LTMS_SEO_Manager' ) ) {
            LTMS_SEO_Manager::init();
        }
        if ( class_exists( 'LTMS_Sitemap' ) ) {
            LTMS_Sitemap::init();
        }

        // v2.0.0 — Analytics (GTM, GA4, Meta Pixel)
        if ( class_exists( 'LTMS_Analytics_Manager' ) ) {
            LTMS_Analytics_Manager::init();
        }

        // v2.0.0 — Geolocalización
        if ( class_exists( 'LTMS_Geo_Detector' ) ) {
            LTMS_Geo_Detector::init();
        }

        // v2.8.0 — Vitrina pública del vendedor (/vendedores/{slug})
        if ( class_exists( 'LTMS_Vendor_Storefront' ) ) {
            LTMS_Vendor_Storefront::init();
        }
    }

    /**
     * Arranca los controladores del backend (admin WordPress).
     *
     * @return void
     */
    private function boot_admin(): void {
        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        // GOB-002: Aviso si WP_CRON no está desactivado (recomendación para pagos y reportes DIAN/SAT)
        add_action( 'admin_notices', function() {
            if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
                echo '<div class="notice notice-warning"><p>';
                esc_html_e( 'LT Marketplace Suite: Para garantizar pagos puntuales, configure un cron real del servidor. Ver documentación de instalación.', 'ltms' );
                echo '</p></div>';
            }
        } );

        if ( class_exists( 'LTMS_Admin' ) ) {
            LTMS_Admin::init();
        }
        // M-QA-PAGES-01: registrar el handler de admin-post aquí (no en la vista)
        // para que esté disponible cuando WordPress procesa admin_post_ltms_recreate_pages,
        // que ocurre ANTES de que se cargue html-admin-pages.php.
        if ( class_exists( 'LTMS_Core_Activator' ) ) {
            LTMS_Core_Activator::register_hooks();
        }
        if ( class_exists( 'LTMS_Admin_Settings' ) ) {
            LTMS_Admin_Settings::init();
        }
        if ( class_exists( 'LTMS_Admin_Payouts' ) ) {
            LTMS_Admin_Payouts::init();
        }
        // M-6 FIX: removed dead `if ( class_exists( 'LTMS_Admin_Accounting' ) )`
        // line — LTMS_Admin_Accounting never existed. No accounting-specific
        // admin class is booted here; accounting concerns are handled by
        // LTMS_Accounting_Compliance (booted in boot_business()) and the
        // generic LTMS_Admin_Settings/LTMS_Admin_Payouts panels above.
        if ( class_exists( 'LTMS_Admin_Shipping' ) ) { LTMS_Admin_Shipping::init(); }
        // F-05: Panel admin depósitos manuales
        if ( class_exists( 'LTMS_Admin_Deposits' ) ) {
            LTMS_Admin_Deposits::init();
        }
        if ( class_exists( 'LTMS_Admin_Marketing_Manager' ) ) {
            LTMS_Admin_Marketing_Manager::init();
        }
        if ( class_exists( 'LTMS_Bank_Reconciler' ) ) {
            LTMS_Bank_Reconciler::init();
        }
        if ( class_exists( 'LTMS_Legal_Evidence_Handler' ) ) {
            LTMS_Legal_Evidence_Handler::init();
        }
        if ( class_exists( 'LTMS_Admin_SAT_Report' ) ) {
            LTMS_Admin_SAT_Report::init();
        }

        // v1.6.0 — Admin de módulos enterprise
        if ( class_exists( 'LTMS_Admin_Redi' ) ) {
            LTMS_Admin_Redi::init();
        }

        // v2.0.0 — Admin de reservas y compliance turístico
        if ( class_exists( 'LTMS_Admin_Bookings' ) ) {
            LTMS_Admin_Bookings::init();
        }

        // M-QA-12: Metabox de tipo y comisión individual en el editor de producto WC
        if ( class_exists( 'LTMS_Admin_Product_Meta' ) ) {
            LTMS_Admin_Product_Meta::init();
        }

        // QA-booking-01: Metabox de configuración de reserva (turismo/booking) en el editor de producto WC
        if ( class_exists( 'LTMS_Admin_Bookable_Meta' ) ) {
            LTMS_Admin_Bookable_Meta::init();
        }
    }

    /**
     * Registra los cron jobs del sistema.
     *
     * @return void
     */
    private function boot_cron(): void {
        // Register custom recurrence intervals so that WP accepts them.
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

        if ( class_exists( 'LTMS_Core_Cron_Manager' ) ) {
            LTMS_Core_Cron_Manager::init();
        }

        // CR-2 FIX (Task 57-E): Removed duplicate `ltms_update_tracking` closure.
        // Previously the kernel registered an anonymous closure for the
        // `ltms_update_tracking` action while the Cron Manager ALSO registered
        // `LTMS_Core_Cron_Manager::update_tracking` for the SAME hook. Both
        // handlers fired on every cron tick → duplicated Heka/Aveonline API
        // calls and duplicated order status updates. The Cron Manager handler
        // (class-ltms-core-cron-manager.php:34, 370-436) is canonical and more
        // complete (handles Uber Direct, Aveonline + Heka via WC_Order_Query).
        // The kernel closure has been removed.

        // M-QA-RNT-01: Cron diario de vencimiento RNT/SECTUR.
        // check_rnt_expiry() existía pero nunca se programaba — los registros
        // verificados con rnt_expiry_date pasada permanecían como 'verified' indefinidamente.
        add_action( 'ltms_check_rnt_expiry', static function(): void {
            if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
                LTMS_Business_Tourism_Compliance::check_rnt_expiry();
            }
        } );
        if ( ! wp_next_scheduled( 'ltms_check_rnt_expiry' ) ) {
            wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'ltms_check_rnt_expiry' );
        }

        // v3.1.0 — Cross-Border motor (Task 63-D): daily FX rate refresh.
        // The FX_Rate_Provider caches rates for 6h (transient). This daily
        // cron forces a cache flush once a day so rates never get stale by
        // more than 24h even on low-traffic stores (the cache would otherwise
        // be refreshed organically by customer traffic). Stateless — if the
        // cross-border motor is not loaded, the action is a no-op.
        add_action( 'ltms_refresh_fx_rates', static function(): void {
            if ( class_exists( 'LTMS_FX_Rate_Provider' ) ) {
                LTMS_FX_Rate_Provider::refresh_rates();
            }
        } );
        if ( ! wp_next_scheduled( 'ltms_refresh_fx_rates' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 02:00' ), 'daily', 'ltms_refresh_fx_rates' );
        }
    }


    /**
     * Registra los endpoints de la API REST.
     *
     * @return void
     */
    private function boot_rest_api(): void {
        add_action( 'rest_api_init', function() {
            if ( class_exists( 'LTMS_Core_REST_Controller' ) ) {
                $controller = new LTMS_Core_REST_Controller();
                $controller->register_routes();
            }
        });
    }

    /**
     * Prevenir clonación del Singleton.
     */
    private function __clone() {}

    /**
     * Prevenir deserialización del Singleton.
     *
     * @throws \RuntimeException
     */
    public function __wakeup(): void {
        throw new \RuntimeException( 'No se puede deserializar un Singleton.' );
    }
}


