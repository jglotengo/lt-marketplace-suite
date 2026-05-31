<?php
/**
 * LTMS Diagnostic Tool — leer y mostrar el error fatal capturado
 * Acceso: /wp-content/plugins/lt-marketplace-suite/ltms-diag-output.php
 * BORRAR después de usar.
 */
if ( ! defined( 'ABSPATH' ) ) {
    // Acceso directo — cargar WordPress mínimo
    $wp_load = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    }
}

header( 'Content-Type: text/plain; charset=utf-8' );
$plugin_dir = dirname( __FILE__ ) . '/';

echo "=== LTMS DIAGNOSTIC ===\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Fatal debug file
$fatal_file = $plugin_dir . 'ltms-fatal-debug.txt';
echo "=== ltms-fatal-debug.txt ===\n";
if ( file_exists( $fatal_file ) ) {
    echo file_get_contents( $fatal_file );
} else {
    echo "(no existe — no hubo fatal en el ultimo request)\n";
}
echo "\n";

// 2. PHP error log
$possible_logs = [
    '/home/customer/logs/php/error.log',
    '/home/customer/www/lo-tengo.com.co/logs/error.log',
    ini_get('error_log'),
];
foreach ( $possible_logs as $log ) {
    if ( $log && file_exists( $log ) ) {
        echo "=== PHP Error Log: $log ===\n";
        $lines = file( $log );
        $ltms_lines = array_filter( $lines, fn($l) => stripos($l, 'ltms') !== false || stripos($l, 'Fatal') !== false || stripos($l, 'Parse error') !== false );
        echo implode( '', array_slice( array_values($ltms_lines), -30 ) );
        echo "\n";
        break;
    }
}

// 3. Check if critical files exist
echo "=== File checks ===\n";
$files = [
    'includes/admin/class-ltms-admin-deposits.php',
    'includes/admin/class-ltms-admin-shipping.php',
    'includes/admin/views/html-admin-bookings.php',
    'includes/admin/views/html-admin-tourism-compliance.php',
    'includes/admin/views/html-admin-settings.php',
    'includes/core/class-ltms-kernel.php',
];
foreach ( $files as $f ) {
    $path = $plugin_dir . $f;
    $exists = file_exists( $path );
    $size = $exists ? filesize( $path ) : 0;
    echo ( $exists ? "OK" : "MISSING" ) . " $f ($size bytes)\n";
}

// 4. PHP syntax check
echo "\n=== PHP Syntax Checks ===\n";
foreach ( $files as $f ) {
    $path = $plugin_dir . $f;
    if ( file_exists( $path ) ) {
        $output = shell_exec( "php -l " . escapeshellarg( $path ) . " 2>&1" );
        echo "$f: " . trim($output) . "\n";
    }
}
