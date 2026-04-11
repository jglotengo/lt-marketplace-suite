<?php
/**
 * LTMS - Script de Migraciones de BD
 * Sube este archivo a: wp-content/plugins/lt-marketplace-suite/bin/
 * Ejecuta desde WP-CLI o accediendo via URL con el token correcto
 * 
 * USO via WP-CLI en el servidor:
 * wp eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-run-migrations.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Si se accede directamente, cargar WordPress
    $wp_load = dirname( __FILE__ ) . '/../../../../wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        die( 'WordPress no encontrado.' );
    }
}

echo "=== LTMS Migration Runner ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

// Verificar que la clase existe
if ( ! class_exists( 'LTMS_DB_Migrations' ) ) {
    echo "ERROR: Clase LTMS_DB_Migrations no encontrada.\n";
    echo "Intentando cargar manualmente...\n";
    $migrations_file = LTMS_PLUGIN_DIR . 'includes/core/migrations/class-ltms-db-migrations.php';
    if ( file_exists( $migrations_file ) ) {
        require_once $migrations_file;
        echo "OK: Clase cargada desde archivo.\n";
    } else {
        die( "FATAL: No se puede cargar la clase de migraciones.\n" );
    }
}

// Ejecutar migraciones
echo "Ejecutando LTMS_DB_Migrations::run()...\n";
try {
    LTMS_DB_Migrations::run();
    echo "OK: Migraciones ejecutadas.\n\n";
} catch ( Exception $e ) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Verificar tablas creadas
global $wpdb;
$tables_to_check = [
    'lt_vendor_wallets',
    'lt_wallet_transactions',
    'lt_commissions',
    'lt_payout_requests',
    'lt_notifications',
    'lt_audit_logs',
    'lt_webhook_logs',
    'lt_vendor_kyc',
];

echo "=== Verificacion de tablas ===\n";
$ok = 0;
$fail = 0;
foreach ( $tables_to_check as $table ) {
    $full_name = $wpdb->prefix . $table;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$full_name}'" );
    if ( $exists ) {
        echo "OK  : {$full_name}\n";
        $ok++;
    } else {
        echo "FALTA: {$full_name}\n";
        $fail++;
    }
}

echo "\n=== Resumen: {$ok} OK, {$fail} faltantes ===\n";

if ( $fail === 0 ) {
    echo "\nTodas las tablas existen. El dashboard del vendedor deberia funcionar ahora.\n";
} else {
    echo "\nAlgunas tablas no se crearon. Revisa el error_log de PHP.\n";
}
