<?php
/**
 * Script de diagnóstico temporal — borrar después
 * Ejecutar: php debug-test-conn.php desde el directorio del plugin
 */
define('ABSPATH', '/home/customer/www/lo-tengo.com.co/public_html/');
define('WPINC', 'wp-includes');

// Simular WP env mínimo
$_SERVER['HTTP_HOST'] = 'lo-tengo.com.co';
$_SERVER['REQUEST_URI'] = '/';

// Cargar WP
require_once ABSPATH . 'wp-load.php';

echo "=== TEST CONEXIÓN ALEGRA ===\n\n";

// Limpiar caché
if (class_exists('LTMS_Core_Config')) {
    LTMS_Core_Config::flush_cache();
    echo "Config cache flushed\n";
}

if (class_exists('LTMS_Api_Factory')) {
    LTMS_Api_Factory::reset('alegra');
    echo "Factory cache reset\n";
}

echo "Email: " . get_option('ltms_alegra_email') . "\n";
$token = get_option('ltms_alegra_token');
echo "Token (primeros 10): " . substr($token, 0, 10) . "...\n";

try {
    $client = LTMS_Api_Factory::get('alegra');
    echo "Cliente instanciado OK\n";
    
    $result = $client->health_check();
    echo "health_check resultado:\n";
    print_r($result);
} catch (Throwable $e) {
    echo "EXCEPCIÓN: " . get_class($e) . "\n";
    echo "Mensaje: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
