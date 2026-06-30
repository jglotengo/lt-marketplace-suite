<?php
/**
 * LTMS DIAG v3 — Aísla si el prefijo 'contratos/' específicamente causa el 403,
 * y consulta la API NATIVA de Backblaze B2 (b2_authorize_account) para ver
 * las capacidades y namePrefix REALES de la Application Key configurada,
 * sin depender de lo que muestra la interfaz web de Backblaze.
 *
 * Ejecutar via WP-CLI:
 *   wp eval-file bin/ltms-diag-backblaze-v3.php --allow-root
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

echo "\n🔬 DIAGNÓSTICO v3 — ¿Es el prefijo 'contratos/' la causa? + Verificación nativa B2\n";
echo 'Fecha: ' . date( 'Y-m-d H:i:s' ) . "\n\n";

$b2     = LTMS_Api_Factory::get( 'backblaze' );
$bucket = LTMS_Core_Config::get( 'ltms_backblaze_contratos_bucket', 'lotengo-contratos' );
$ts     = date( 'His' );

function run_case( $b2, $bucket, $label, $content, $key, $meta ) {
    echo "── {$label} ──\n";
    echo "  Bucket: {$bucket} | Key: {$key}\n";
    try {
        $result = $b2->upload_file( $bucket, $key, $content, 'application/pdf', $meta );
        echo "  ✅ OK — ETag: " . ( $result['ETag'] ?? 'n/a' ) . "\n";
        $b2->delete_file( $bucket, $key );
        echo "  (cleanup OK)\n\n";
        return true;
    } catch ( Throwable $e ) {
        echo "  ❌ FALLÓ: " . $e->getMessage() . "\n\n";
        return false;
    }
}

$content = "%PDF-1.4\n% diag v3 " . $ts . "\n%%EOF";
$meta    = [ 'vendor_id' => '999999', 'doc_token' => 'diagv3-' . $ts ];

// Caso E: SIN prefijo 'contratos/' — raíz del bucket
$key_e = "diag-isolate-{$ts}.pdf";
$ok_e = run_case( $b2, $bucket, "Caso E — raíz del bucket, SIN prefijo 'contratos/'", $content, $key_e, $meta );

// Caso F: prefijo distinto, 'contratos-test/' (similar pero no exacto)
$key_f = "contratos-test/diag-{$ts}.pdf";
$ok_f = run_case( $b2, $bucket, "Caso F — prefijo 'contratos-test/' (no exacto)", $content, $key_f, $meta );

// Caso G: prefijo EXACTO 'contratos/' pero sin subcarpeta de fecha
$key_g = "contratos/diag-{$ts}.pdf";
$ok_g = run_case( $b2, $bucket, "Caso G — prefijo 'contratos/' directo, sin Y/m", $content, $key_g, $meta );

// Caso H: prefijo EXACTO 'contratos/2026/06/' (el que usa el QA real)
$key_h = "contratos/2026/06/diag-{$ts}.pdf";
$ok_h = run_case( $b2, $bucket, "Caso H — prefijo 'contratos/2026/06/' exacto", $content, $key_h, $meta );

echo "═══════════════════════════════════════════════\n";
echo "RESUMEN PREFIJOS:\n";
echo "  Caso E (raíz, sin prefijo):        " . ( $ok_e ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso F (contratos-test/):          " . ( $ok_f ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso G (contratos/ directo):       " . ( $ok_g ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso H (contratos/2026/06/):       " . ( $ok_h ? 'OK' : 'FALLÓ' ) . "\n";
echo "═══════════════════════════════════════════════\n\n";

// ── Verificación nativa B2: namePrefix y capabilities REALES de la key ──
echo "═══════════════════════════════════════════════\n";
echo "VERIFICACIÓN NATIVA B2 (b2_authorize_account)\n";
echo "═══════════════════════════════════════════════\n";

$key_id  = LTMS_Core_Config::get( 'ltms_backblaze_key_id', '' );
$enc_key = LTMS_Core_Config::get( 'ltms_backblaze_app_key', '' );
$app_key = ! empty( $enc_key ) ? LTMS_Core_Security::decrypt( $enc_key ) : '';

echo "Key ID en uso: {$key_id}\n";
echo "App Key (longitud): " . strlen( $app_key ) . " caracteres\n\n";

if ( empty( $key_id ) || empty( $app_key ) ) {
    echo "❌ No se pudo leer key_id/app_key desde config — abortando verificación nativa.\n";
} else {
    $auth_url = 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account';
    $creds    = base64_encode( $key_id . ':' . $app_key );

    $resp = wp_remote_get( $auth_url, [
        'headers' => [ 'Authorization' => 'Basic ' . $creds ],
        'timeout' => 30,
    ] );

    if ( is_wp_error( $resp ) ) {
        echo "❌ Error de red: " . $resp->get_error_message() . "\n";
    } else {
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        echo "HTTP {$code}\n";
        $json = json_decode( $body, true );
        if ( $json ) {
            echo "apiInfo.storageApi.capabilities: " . wp_json_encode( $json['apiInfo']['storageApi']['capabilities'] ?? 'n/a' ) . "\n";
            echo "apiInfo.storageApi.bucketId (restricción de bucket): " . wp_json_encode( $json['apiInfo']['storageApi']['bucketId'] ?? '(sin restricción)' ) . "\n";
            echo "apiInfo.storageApi.bucketName: " . wp_json_encode( $json['apiInfo']['storageApi']['bucketName'] ?? '(sin restricción)' ) . "\n";
            echo "apiInfo.storageApi.namePrefix (RESTRICCIÓN REAL): " . wp_json_encode( $json['apiInfo']['storageApi']['namePrefix'] ?? '(ninguno)' ) . "\n";
            echo "apiInfo.storageApi.allowed (objeto completo):\n";
            echo wp_json_encode( $json['apiInfo']['storageApi'] ?? $json, JSON_PRETTY_PRINT ) . "\n";
        } else {
            echo "Respuesta cruda (no es JSON válido o estructura distinta):\n{$body}\n";
        }
    }
}

echo "\n";
