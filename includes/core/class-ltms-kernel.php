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
                . ' in ' . $e->getFile() . ':' . $e->getLine() );

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
        if ( class_exists( 'LTMS_Payout_Scheduler' ) ) {
            LTMS_Payout_Scheduler::init();
        if ( class_exists( 'LTMS_Accounting' ) ) { LTMS_Accounting::init(); }
        if ( class_exists( 'LTMS_Shipping_Mode' ) ) { LTMS_Shipping_Mode::init(); }
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
        // v1.7.5 — Openpay MX gateway (registrado directamente; autoloader resuelve la clase)
        $gateways[] = 'LTMS_Api_Gateway_Openpay_MX';
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
        if ( class_exists( 'LTMS_Admin_Settings' ) ) {
            LTMS_Admin_Settings::init();
        }
        if ( class_exists( 'LTMS_Admin_Payouts' ) ) {
            LTMS_Admin_Payouts::init();
        }
        if ( class_exists( 'LTMS_Admin_Accounting' ) ) { LTMS_Admin_Accounting::init(); }
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

        // M-32: ltms_update_tracking was scheduled in the activator but had no handler.
        // Poll all shipping APIs (Heka, Aveonline, Uber) for orders still in transit.
        add_action( 'ltms_update_tracking', static function(): void {
            global $wpdb;

            // Find orders in transit with a tracking number assigned by LTMS.
            $order_ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type   = 'shop_order'
                   AND p.post_status IN ('wc-processing','wc-shipped')
                   AND pm.meta_key   = '_ltms_tracking_number'
                   AND pm.meta_value != ''"
            );

            if ( empty( $order_ids ) ) {
                return;
            }

            foreach ( $order_ids as $order_id ) {
                try {
                    $order           = wc_get_order( (int) $order_id );
                    $tracking_number = $order ? $order->get_meta( '_ltms_tracking_number' ) : '';
                    $carrier         = $order ? $order->get_meta( '_ltms_shipping_carrier' ) : '';

                    if ( ! $order || ! $tracking_number ) {
                        continue;
                    }

                    $status = '';

                    if ( class_exists( 'LTMS_Api_Factory' ) ) {
                        if ( in_array( $carrier, [ 'heka', '' ], true ) && class_exists( 'LTMS_Api_Heka' ) ) {
                            $api    = LTMS_Api_Factory::get( 'heka' );
                            $result = $api->track_shipment( $tracking_number );
                            $status = $result['status'] ?? '';
                        } elseif ( $carrier === 'aveonline' && class_exists( 'LTMS_Api_Aveonline' ) ) {
                            $api    = LTMS_Api_Factory::get( 'aveonline' );
                            $result = $api->track_shipment( $tracking_number );
                            $status = $result['status'] ?? '';
                        }
                    }

                    if ( $status ) {
                        $order->update_meta_data( '_ltms_tracking_status', sanitize_text_field( $status ) );
                        $order->update_meta_data( '_ltms_tracking_updated_at', gmdate( 'c' ) );
                        $order->save();

                        // Mark delivered orders as completed.
                        if ( in_array( strtolower( $status ), [ 'delivered', 'entregado', 'delivered_final' ], true ) ) {
                            $order->update_status( 'completed', __( 'Entregado — actualizado automáticamente por tracking.', 'ltms' ) );
                        }
                    }
                } catch ( \Throwable $e ) {
                    LTMS_Core_Logger::warning(
                        'TRACKING_UPDATE_FAILED',
                        sprintf( 'Order #%d: %s', $order_id, $e->getMessage() )
                    );
                }
            }
        } );
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

