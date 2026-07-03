<?php
/**
 * Diagnóstico 3: Verificar estado real en servidor
 * wp eval-file bin/ltms-diag3.php --path=... --allow-root
 */

echo "=== DIAGNÓSTICO 3: ESTADO SERVIDOR ===" . PHP_EOL . PHP_EOL;

// 1. Versión del JS encolado
echo "1. ARCHIVO JS:" . PHP_EOL;
$js_path = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/assets/js/ltms-admin.js';
if ( file_exists( $js_path ) ) {
    $js = file_get_contents( $js_path );
    $has_kyc_actions = strpos( $js, 'ltms-kyc-actions' ) !== false;
    $has_btn_hide    = strpos( $js, '$btn.hide()' ) !== false;
    $has_compliance  = strpos( $js, 'ltms_compliance' ) !== false;
    echo "   ltms-kyc-actions en JS: " . ( $has_kyc_actions ? '❌ AÚN EXISTE (viejo)' : '✅ Eliminado (fix aplicado)' ) . PHP_EOL;
    echo "   \$btn.hide() en JS: " . ( $has_btn_hide ? '✅ Existe' : '❌ No encontrado' ) . PHP_EOL;
    $mtime = filemtime( $js_path );
    echo "   Modificado: " . date( 'Y-m-d H:i:s', $mtime ) . " UTC" . PHP_EOL;
} else {
    echo "   ❌ Archivo no encontrado" . PHP_EOL;
}

// 2. Verificar PHP payouts
echo PHP_EOL . "2. PHP class-ltms-admin-payouts.php:" . PHP_EOL;
$php_path = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/includes/admin/class-ltms-admin-payouts.php';
if ( file_exists( $php_path ) ) {
    $php = file_get_contents( $php_path );
    $has_old_cap = strpos( $php, "ltms_compliance" ) !== false;
    $has_new_cap = strpos( $php, "ltms_freeze_wallets" ) !== false;
    echo "   ltms_compliance (viejo): " . ( $has_old_cap ? '❌ AÚN EXISTE' : '✅ Eliminado' ) . PHP_EOL;
    echo "   ltms_freeze_wallets (nuevo): " . ( $has_new_cap ? '✅ Aplicado' : '❌ No encontrado' ) . PHP_EOL;
    $mtime = filemtime( $php_path );
    echo "   Modificado: " . date( 'Y-m-d H:i:s', $mtime ) . " UTC" . PHP_EOL;
}

// 3. Capacidades del admin actual
echo PHP_EOL . "3. CAPACIDADES ADMIN (user ID 2):" . PHP_EOL;
$admin = get_user_by( 'id', 2 );
if ( $admin ) {
    $caps = [ 'ltms_manage_kyc', 'ltms_freeze_wallets', 'ltms_compliance', 'ltms_manage_all_vendors' ];
    foreach ( $caps as $cap ) {
        $has = user_can( $admin, $cap );
        echo "   $cap: " . ( $has ? '✅' : '❌' ) . PHP_EOL;
    }
}

// 4. Hooks AJAX registrados
echo PHP_EOL . "4. HOOKS AJAX REGISTRADOS:" . PHP_EOL;
global $wp_filter;
$ajax_hooks = [
    'wp_ajax_ltms_quick_approve_kyc',
    'wp_ajax_ltms_freeze_wallet',
    'wp_ajax_ltms_unfreeze_wallet',
];
foreach ( $ajax_hooks as $hook ) {
    $registered = isset( $wp_filter[ $hook ] ) && ! empty( $wp_filter[ $hook ]->callbacks );
    echo "   $hook: " . ( $registered ? '✅' : '❌ NO REGISTRADO' ) . PHP_EOL;
}

// 5. Opcache
echo PHP_EOL . "5. OPCACHE:" . PHP_EOL;
if ( function_exists( 'opcache_get_status' ) ) {
    $status = opcache_get_status( false );
    echo "   Enabled: " . ( $status['opcache_enabled'] ? 'sí' : 'no' ) . PHP_EOL;
    // Check if payouts file is cached
    $cached = opcache_is_script_cached( $php_path );
    echo "   payouts.php en caché OPcache: " . ( $cached ? '⚠️ SÍ (puede ser versión vieja)' : 'no' ) . PHP_EOL;
} else {
    echo "   OPcache no disponible" . PHP_EOL;
}

// 6. LTMS_VERSION
echo PHP_EOL . "6. PLUGIN:" . PHP_EOL;
echo "   LTMS_VERSION: " . ( defined('LTMS_VERSION') ? LTMS_VERSION : '❌ no definida' ) . PHP_EOL;

echo PHP_EOL . "=== FIN DIAGNÓSTICO 3 ===" . PHP_EOL;
