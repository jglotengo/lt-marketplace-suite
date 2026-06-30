<?php
/**
 * LTMS DIAG — Aislar causa del 403 AccessDenied en upload a Backblaze B2.
 *
 * Prueba subir un archivo de prueba a AMBOS buckets (lotengo-kyc-docs que
 * funciona, y lotengo-contratos que falla) usando exactamente la misma
 * instancia/credenciales, para determinar si el problema es:
 *   a) específico del bucket lotengo-contratos (permisos/política/encryption), o
 *   b) general de la key/código (en cuyo caso kyc-docs también fallaría).
 *
 * Ejecutar via WP-CLI:
 *   wp eval-file bin/ltms-diag-backblaze.php --allow-root
 */

if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
set_time_limit( 0 );

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    $wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        die( "Ejecutar via WP-CLI\n" );
    }
}

echo "\n🔬 DIAGNÓSTICO — 403 en upload a Backblaze B2\n";
echo 'Fecha: ' . date( 'Y-m-d H:i:s' ) . "\n\n";

$b2 = LTMS_Api_Factory::get( 'backblaze' );

$test_content = "%PDF-1.4\n% diag test " . date( 'His' ) . "\n%%EOF";
$test_key     = 'diag-test/diag-' . date( 'His' ) . '.pdf';

// Prueba 1: bucket que SÍ funciona (kyc-docs, 36 archivos reales ya subidos)
echo "── Prueba 1: lotengo-kyc-docs (bucket que ya tiene archivos reales) ──\n";
try {
    $result = $b2->upload_file( 'lotengo-kyc-docs', $test_key, $test_content, 'application/pdf', [ 'diag' => '1' ] );
    echo "✅ OK — subida exitosa. ETag: " . ( $result['ETag'] ?? 'n/a' ) . "\n";
    $b2->delete_file( 'lotengo-kyc-docs', $test_key );
    echo "   (cleanup OK)\n";
} catch ( Throwable $e ) {
    echo "❌ FALLÓ: " . $e->getMessage() . "\n";
}

echo "\n── Prueba 2: lotengo-contratos (bucket que falla) ──\n";
try {
    $result = $b2->upload_file( 'lotengo-contratos', $test_key, $test_content, 'application/pdf', [ 'diag' => '1' ] );
    echo "✅ OK — subida exitosa. ETag: " . ( $result['ETag'] ?? 'n/a' ) . "\n";
    $b2->delete_file( 'lotengo-contratos', $test_key );
    echo "   (cleanup OK)\n";
} catch ( Throwable $e ) {
    echo "❌ FALLÓ: " . $e->getMessage() . "\n";
}

echo "\n── Config actual ──\n";
echo 'Key ID guardada: ' . LTMS_Core_Config::get( 'ltms_backblaze_key_id', '(vacío)' ) . "\n";
echo 'Endpoint: ' . LTMS_Core_Config::get( 'ltms_backblaze_endpoint', '(vacío)' ) . "\n";
echo 'App Key (longitud): ' . strlen( (string) get_option( 'ltms_backblaze_app_key', '' ) ) . " caracteres (almacenada, puede estar cifrada con prefijo v1:)\n";

echo "\n";
