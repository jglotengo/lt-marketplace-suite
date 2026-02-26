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
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::log(
                    'KERNEL_BOOT_ERROR',
                    $e->getMessage(),
                    [ 'trace' => $e->getTraceAsString() ],
                    'CRITICAL'
                );
            }
            // En producción, no mostrar el error. En debug, re-lanzar.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                throw $e;
            }
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

        // Motor de comisiones
        if ( class_exists( 'LTMS_Business_Commission_Strategy' ) ) {
            LTMS_Business_Commission_Strategy::init();
        }

        // Split de pagos
        if ( class_exists( 'LTMS_Business_Order_Split' ) ) {
            LTMS_Business_Order_Split::init();
        }

        // Motor fiscal
        if ( class_exists( 'LTMS_Business_Tax_Engine' ) ) {
            LTMS_Business_Tax_Engine::init();
        }

        // Árbol de referidos MLM
        if ( class_exists( 'LTMS_Business_Referral_Tree' ) ) {
            LTMS_Business_Referral_Tree::init();
        }

        // Afiliados / Cookies
        if ( class_exists( 'LTMS_Business_Affiliates' ) ) {
            LTMS_Business_Affiliates::init();
        }

        // Scheduler de pagos
        if ( class_exists( 'LTMS_Business_Payout_Scheduler' ) ) {
            LTMS_Business_Payout_Scheduler::init();
        }

        // Protección al consumidor / vesting
        if ( class_exists( 'LTMS_Business_Consumer_Protection' ) ) {
            LTMS_Business_Consumer_Protection::init();
        }

        // Cumplimiento turístico (Ley 2068 Colombia)
        if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            LTMS_Business_Tourism_Compliance::init();
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
        if ( class_exists( 'LTMS_Business_Pickup_Handler' ) ) {
            LTMS_Business_Pickup_Handler::init();
        }
    }

    /**
     * Arranca los conectores de API externas y pasarelas de pago.
     *
     * @return void
     */
    private function boot_api_integrations(): void {
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
        ];

        foreach ( $webhook_handlers as $handler ) {
            if ( class_exists( $handler ) ) {
                $handler::init();
            }
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
        if ( class_exists( 'LTMS_Secure_Downloads' ) ) {
            LTMS_Secure_Downloads::init();
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

        if ( class_exists( 'LTMS_Admin' ) ) {
            LTMS_Admin::init();
        }
        if ( class_exists( 'LTMS_Admin_Settings' ) ) {
            LTMS_Admin_Settings::init();
        }
        if ( class_exists( 'LTMS_Admin_Payouts' ) ) {
            LTMS_Admin_Payouts::init();
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
    }

    /**
     * Registra los cron jobs del sistema.
     *
     * @return void
     */
    private function boot_cron(): void {
        if ( class_exists( 'LTMS_Core_Cron_Manager' ) ) {
            LTMS_Core_Cron_Manager::init();
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
