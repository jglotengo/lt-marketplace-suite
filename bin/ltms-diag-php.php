#!/usr/bin/env php
<?php
/**
 * bin/ltms-diag-php.php — Diagnóstico PHP puro (no necesita WP-CLI)
 * Uso desde el plugin dir:
 *   php bin/ltms-diag-php.php > /tmp/ltms-diag.log 2>&1 &
 *   cat /tmp/ltms-diag.log
 */

$wp_path    = '/home/customer/www/lo-tengo.com.co/public_html';
$plugin_dir = $wp_path . '/wp-content/plugins/lt-marketplace-suite';

echo "=== LTMS PHP Diagnostic === " . date('Y-m-d H:i:s') . "\n";
echo "PHP: " . PHP_VERSION . " | SAPI: " . php_sapi_name() . "\n";
echo "CWD: " . getcwd() . "\n\n";

// 1. Cargar WordPress sin WP-CLI
echo "--- Cargando wp-load.php ---\n";
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
    die("ERROR: wp-load.php no encontrado en $wp_path\n");
}

define( 'ABSPATH', $wp_path . '/' );
$_SERVER['HTTP_HOST']   = 'lo-tengo.com.co';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
try {
    require_once $wp_path . '/wp-load.php';
    ob_end_clean();
    echo "✅ WordPress cargado — versión: " . get_bloginfo('version') . "\n";
} catch ( Throwable $e ) {
    ob_end_clean();
    die("❌ Error cargando WP: " . $e->getMessage() . "\n");
}

// 2. Verificar que LTMS está activo
echo "\n--- Plugin LTMS ---\n";
if ( ! class_exists('LTMS_Core_Config') ) {
    echo "❌ LTMS_Core_Config no existe — plugin no activo o autoloader fallando\n";
    $plugins = get_option('active_plugins', []);
    echo "Plugins activos:\n";
    foreach ( $plugins as $p ) echo "  - $p\n";
    exit(1);
}
echo "✅ LTMS_Core_Config existe\n";

// 3. Verificar Alegra
echo "\n--- Conexión Alegra ---\n";
try {
    $alegra = LTMS_Api_Factory::get('alegra');
    $hc = $alegra->health_check();
    echo "✅ Alegra: " . ($hc['status'] === 'ok' ? 'OK' : 'ERROR') . " — " . $hc['message'] . "\n";
} catch (Throwable $e) {
    echo "❌ Alegra: " . $e->getMessage() . "\n";
}

// 4. Tabla de BD
echo "\n--- Tablas BD (prefijo: {$prefix}) ---\n";
global $wpdb;
$tables = ['wallet_transactions','wallet_holds','payout_requests','bookings','vendor_kyc'];
$missing = 0;
foreach ( $tables as $t ) {
    $full = "{$prefix}ltms_{$t}";
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full'");
    echo ( $exists ? "✅" : "❌" ) . " $full\n";
    if ( ! $exists ) $missing++;
}

if ( $missing > 0 ) {
    echo "\n⚠️  $missing tabla(s) faltante(s). Ejecutando migraciones...\n";
    if ( class_exists('LTMS_DB_Migrations') ) {
        try {
            LTMS_DB_Migrations::run_all();
            echo "✅ Migraciones ejecutadas. Verificando...\n";
            foreach ( $tables as $t ) {
                $full   = "{$prefix}ltms_{$t}";
                $exists = $wpdb->get_var("SHOW TABLES LIKE '$full'");
                echo ( $exists ? "  ✅" : "  ❌" ) . " $full\n";
            }
        } catch ( Throwable $e ) {
            echo "❌ Error en migraciones: " . $e->getMessage() . "\n";
        }
    } else {
        echo "❌ LTMS_DB_Migrations no existe — correr: php bin/ltms-run-migrations.php\n";
    }
}

echo "\n=== DONE ===\n";
