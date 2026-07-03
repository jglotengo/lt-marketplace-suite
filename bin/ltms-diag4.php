<?php
/**
 * Diagnóstico 4: Verificar que ltms-admin.js se encola con ltmsAdmin localizado
 * wp eval-file bin/ltms-diag4.php --path=... --allow-root
 */
echo "=== DIAGNÓSTICO 4: ENQUEUE DEL JS ===" . PHP_EOL . PHP_EOL;

// Simular que estamos en la página ltms-vendors
$_GET['page'] = 'ltms-vendors';
set_current_screen('ltms_page_ltms-vendors');

// Disparar admin_enqueue_scripts
do_action( 'admin_enqueue_scripts', 'ltms_page_ltms-vendors' );

global $wp_scripts;

echo "1. ltms-admin encolado: ";
$enqueued = isset( $wp_scripts->queue ) && in_array( 'ltms-admin', $wp_scripts->queue );
echo ( $enqueued ? '✅' : '❌ NO ENCOLADO' ) . PHP_EOL;

echo "2. ltmsAdmin localizado: ";
$localized = isset( $wp_scripts->registered['ltms-admin']->extra['data'] );
echo ( $localized ? '✅' : '❌ NO' ) . PHP_EOL;

if ( $localized ) {
    $data = $wp_scripts->registered['ltms-admin']->extra['data'];
    echo "   Datos: " . substr( $data, 0, 300 ) . PHP_EOL;
}

echo PHP_EOL . "3. hook_suffix test:" . PHP_EOL;
$hook = 'ltms_page_ltms-vendors';
$has_ltms = strpos( $hook, 'ltms' ) !== false;
$has_toplevel = strpos( $hook, 'toplevel_page_ltms' ) !== false;
echo "   hook: $hook" . PHP_EOL;
echo "   contains 'ltms': " . ( $has_ltms ? '✅' : '❌' ) . PHP_EOL;
echo "   WP assigns hook as: " . get_plugin_page_hookname( 'ltms-vendors', 'ltms-dashboard' ) . PHP_EOL;

echo PHP_EOL . "=== FIN DIAGNÓSTICO 4 ===" . PHP_EOL;
