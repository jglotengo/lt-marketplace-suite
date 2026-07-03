<?php
/**
 * LTMS Core HPOS Compat — Compatibilidad con High-Performance Order Storage
 *
 * Declara la compatibilidad del plugin con HPOS de WooCommerce (Custom Order Tables).
 * Sin esta declaración WooCommerce muestra un aviso en el panel admin y puede
 * deshabilitar la función si hay conflictos declarados.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.7.1
 * @see        https://developer.woocommerce.com/docs/high-performance-order-storage/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_HPOS_Compat
 */
final class LTMS_Core_HPOS_Compat {

    /**
     * Registra el hook de declaración de compatibilidad HPOS.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'before_woocommerce_init', [ __CLASS__, 'declare_hpos_compat' ] );
    }

    /**
     * Declara compatibilidad con HPOS y con el nuevo bloque de Checkout.
     *
     * @return void
     */
    public static function declare_hpos_compat(): void {
        if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            LTMS_PLUGIN_FILE,
            true
        );

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            LTMS_PLUGIN_FILE,
            true
        );
    }

    /** Prevenir instanciación */
    private function __construct() {}
}
