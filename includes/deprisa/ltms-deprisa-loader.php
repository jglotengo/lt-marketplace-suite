<?php
/**
 * LTMS — Deprisa Multi-Origin Loader
 *
 * Punto de entrada del módulo de split multi-origen Deprisa.
 * Incluye los archivos de clase y registra todos los hooks de WordPress.
 *
 * Incluir desde el archivo principal del plugin (ltms.php o similar):
 *
 *   require_once LTMS_PLUGIN_DIR . 'includes/deprisa/ltms-deprisa-loader.php';
 *
 * O, si ya tenías un loader anterior para la integración Deprisa estándar,
 * agrega las líneas de require_once y add_action del bloque §2 a ese loader.
 *
 * @package LTMS
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ===================================================================== */
/* §1. Includes                                                            */
/* ===================================================================== */

// Clases anteriores (integración estándar) — ajusta la ruta si difiere
$ltms_deprisa_base = __DIR__;

require_once $ltms_deprisa_base . '/class-ltms-api-deprisa.php';          // Cliente REST Alertran
require_once $ltms_deprisa_base . '/class-ltms-deprisa-settings.php';     // Settings globales (página de opciones)

// Nuevas clases del módulo multi-origen (v1.9.0)
require_once $ltms_deprisa_base . '/class-ltms-deprisa-order-split.php';   // Motor de split
require_once $ltms_deprisa_base . '/class-ltms-deprisa-vendor-settings.php'; // Bodega por vendedor
require_once $ltms_deprisa_base . '/class-ltms-deprisa-order-metabox.php'; // Metabox admin

/* ===================================================================== */
/* §2. Hooks — Order Split                                                 */
/* ===================================================================== */

/**
 * Trigger automático al pasar el pedido a "En proceso".
 * Genera una guía Deprisa por cada vendedor/origen del pedido.
 */
add_action(
    'woocommerce_order_status_processing',
    [ LTMS_Deprisa_Order_Split::class, 'on_order_processing' ]
);

/**
 * AJAX para relanzar el split desde el metabox de admin.
 */
add_action(
    'wp_ajax_ltms_deprisa_split_manual',
    [ LTMS_Deprisa_Order_Split::class, 'ajax_manual_split' ]
);

/* ===================================================================== */
/* §3. Hooks — Metabox                                                     */
/* ===================================================================== */

add_action( 'add_meta_boxes',        [ LTMS_Deprisa_Order_Metabox::class, 'register' ] );
add_action( 'admin_enqueue_scripts', [ LTMS_Deprisa_Order_Metabox::class, 'enqueue_scripts' ] );

/**
 * AJAX: descarga de etiqueta PDF desde el metabox.
 * Accesible desde el enlace "📥 PDF" de cada guía.
 */
add_action(
    'wp_ajax_ltms_deprisa_download_etiqueta',
    [ LTMS_Deprisa_Order_Metabox::class, 'ajax_download_etiqueta' ]
);

/* ===================================================================== */
/* §4. Hooks — Vendor Settings (perfil del vendedor)                       */
/* ===================================================================== */

// Mostrar campos en el perfil del usuario
add_action( 'show_user_profile',        [ LTMS_Deprisa_Vendor_Settings::class, 'render_fields' ] );
add_action( 'edit_user_profile',        [ LTMS_Deprisa_Vendor_Settings::class, 'render_fields' ] );

// Guardar al actualizar el perfil
add_action( 'personal_options_update',  [ LTMS_Deprisa_Vendor_Settings::class, 'save_fields' ] );
add_action( 'edit_user_profile_update', [ LTMS_Deprisa_Vendor_Settings::class, 'save_fields' ] );
