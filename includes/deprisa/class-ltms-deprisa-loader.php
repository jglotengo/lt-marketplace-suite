<?php
/**
 * LTMS — Loader del módulo Deprisa v1.10.0
 *
 * Registra todas las clases del módulo Deprisa en orden de dependencia.
 *
 * @package LTMS
 * @since   1.9.0 / 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Loader {

    public static function load(): void {
        $dir = plugin_dir_path( __FILE__ );

        // 1. API client (base — siempre primero)
        require_once $dir . 'class-ltms-deprisa-api.php';

        // 2. Settings
        require_once $dir . 'class-ltms-deprisa-settings.php';

        // 3. Vendor settings
        require_once $dir . 'class-ltms-deprisa-vendor-settings.php';

        // 4. Order split / generación de guías
        require_once $dir . 'class-ltms-deprisa-order-split.php';

        // 5. Shipping method (checkout)
        require_once $dir . 'class-ltms-deprisa-shipping-method.php';

        // 6. Metabox en el pedido
        require_once $dir . 'class-ltms-deprisa-order-metabox.php';

        // 7. Tracking automático (cron)
        require_once $dir . 'class-ltms-deprisa-tracking-cron.php';

        // 8. Devoluciones
        require_once $dir . 'class-ltms-deprisa-devoluciones.php';

        // 9. Notificaciones al cliente
        require_once $dir . 'class-ltms-deprisa-notificaciones.php';

        self::init_hooks();
    }

    private static function init_hooks(): void {
        // Settings panel
        LTMS_Deprisa_Settings::register();

        // Vendor settings
        if ( class_exists( 'LTMS_Deprisa_Vendor_Settings' ) ) {
            LTMS_Deprisa_Vendor_Settings::register();
        }

        // Shipping method
        add_filter( 'woocommerce_shipping_methods', function( $methods ) {
            $methods['ltms_deprisa'] = 'LTMS_Deprisa_Shipping_Method';
            return $methods;
        } );

        // Order split en checkout
        LTMS_Deprisa_Order_Split::register();

        // Metabox
        add_action( 'add_meta_boxes',          [ 'LTMS_Deprisa_Order_Metabox', 'register' ] );
        add_action( 'admin_enqueue_scripts',   [ 'LTMS_Deprisa_Order_Metabox', 'enqueue_scripts' ] );
        add_action( 'wp_ajax_ltms_deprisa_download_etiqueta', [ 'LTMS_Deprisa_Order_Metabox', 'ajax_download_etiqueta' ] );
        add_action( 'wp_ajax_ltms_deprisa_split_manual',      [ 'LTMS_Deprisa_Order_Split',   'ajax_split_manual' ] );

        // Tracking cron
        LTMS_Deprisa_Tracking_Cron::register();

        // Devoluciones
        LTMS_Deprisa_Devoluciones::register();

        // Notificaciones
        LTMS_Deprisa_Notificaciones::register();
    }
}
