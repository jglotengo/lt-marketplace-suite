<?php
/**
 * LTMS — Loader del módulo Deprisa v1.10.0
 *
 * Registra todas las clases del módulo Deprisa en orden de dependencia.
 *
 * NOTA: El loader activo en producción es ltms-deprisa-loader.php (procedural).
 * Este loader de clase se mantiene como reemplazo drop-in funcional. Para
 * activarlo, sustituir en lt-marketplace-suite.php:
 *   require_once LTMS_PLUGIN_DIR . 'includes/deprisa/ltms-deprisa-loader.php';
 * por:
 *   require_once LTMS_PLUGIN_DIR . 'includes/deprisa/class-ltms-deprisa-loader.php';
 *   LTMS_Deprisa_Loader::load();
 *
 * @package LTMS
 * @since   1.9.0 / 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Loader {

    public static function load(): void {
        $dir = plugin_dir_path( __FILE__ );

        // 1. API client (base — siempre primero).
        //    DP-BUG-12(a): nombre correcto del archivo (class-ltms-api-deprisa.php, no class-ltms-deprisa-api.php).
        require_once $dir . 'class-ltms-api-deprisa.php';

        // 2. Settings (la clase se llama LTMS_Settings_Deprisa).
        require_once $dir . 'class-ltms-deprisa-settings.php';

        // 3. Vendor settings
        require_once $dir . 'class-ltms-deprisa-vendor-settings.php';

        // 4. Order split / generación de guías
        require_once $dir . 'class-ltms-deprisa-order-split.php';

        // 5. Metabox en el pedido
        require_once $dir . 'class-ltms-deprisa-order-metabox.php';

        // 6. Tracking automático (cron)
        require_once $dir . 'class-ltms-deprisa-tracking-cron.php';

        // 7. Devoluciones
        require_once $dir . 'class-ltms-deprisa-devoluciones.php';

        // Nota: LTMS_Deprisa_Shipping_Method se carga vía autoloader
        // (key 'ltms-deprisa-shipping-method' → shipping/class-ltms-deprisa-shipping-method.php).
        // Nota: LTMS_Deprisa_Notificaciones no existe como clase en el código actual.

        self::init_hooks();
    }

    private static function init_hooks(): void {
        // Settings panel — DP-BUG-12: nombre de clase y métodos correctos.
        add_filter( 'ltms_settings_tabs',         [ 'LTMS_Settings_Deprisa', 'register_tab' ] );
        add_action( 'ltms_settings_tab_deprisa',  [ 'LTMS_Settings_Deprisa', 'render' ] );
        add_action( 'ltms_settings_save_deprisa', [ 'LTMS_Settings_Deprisa', 'save' ] );
        add_action( 'wp_ajax_ltms_deprisa_test_connection', [ 'LTMS_Settings_Deprisa', 'ajax_test_connection' ] );

        // Vendor settings — la clase no tiene register(); enganchar hooks directamente.
        if ( class_exists( 'LTMS_Deprisa_Vendor_Settings' ) ) {
            add_action( 'show_user_profile',        [ 'LTMS_Deprisa_Vendor_Settings', 'render_fields' ] );
            add_action( 'edit_user_profile',        [ 'LTMS_Deprisa_Vendor_Settings', 'render_fields' ] );
            add_action( 'personal_options_update',  [ 'LTMS_Deprisa_Vendor_Settings', 'save_fields' ] );
            add_action( 'edit_user_profile_update', [ 'LTMS_Deprisa_Vendor_Settings', 'save_fields' ] );
        }

        // Shipping method (la clase se carga vía autoloader cuando WC la necesita).
        add_filter( 'woocommerce_shipping_methods', function( $methods ) {
            $methods['ltms_deprisa'] = 'LTMS_Deprisa_Shipping_Method';
            return $methods;
        } );

        // Order split en checkout — DP-BUG-12(d): Order_Split no tiene register().
        add_action( 'woocommerce_order_status_processing', [ 'LTMS_Deprisa_Order_Split', 'on_order_processing' ] );

        // Metabox
        add_action( 'add_meta_boxes',        [ 'LTMS_Deprisa_Order_Metabox', 'register' ] );
        add_action( 'admin_enqueue_scripts', [ 'LTMS_Deprisa_Order_Metabox', 'enqueue_scripts' ] );
        add_action( 'wp_ajax_ltms_deprisa_download_etiqueta', [ 'LTMS_Deprisa_Order_Metabox', 'ajax_download_etiqueta' ] );

        // DP-BUG-12(e): nombre de método correcto (ajax_manual_split, no ajax_split_manual).
        add_action( 'wp_ajax_ltms_deprisa_split_manual', [ 'LTMS_Deprisa_Order_Split', 'ajax_manual_split' ] );

        // DP-BUG-8: Tracking cron — register() hookea cron + AJAX manual.
        if ( class_exists( 'LTMS_Deprisa_Tracking_Cron' ) ) {
            LTMS_Deprisa_Tracking_Cron::register();
            // Asegurar que el cron esté agendado (idempotente vía wp_next_scheduled).
            LTMS_Deprisa_Tracking_Cron::activate();
        }

        // DP-BUG-8: Devoluciones — register() hookea AJAX handlers.
        if ( class_exists( 'LTMS_Deprisa_Devoluciones' ) ) {
            LTMS_Deprisa_Devoluciones::register();
        }
    }
}
