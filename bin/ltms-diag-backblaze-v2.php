<?php
/**
 * LTMS DIAG v2 — Aísla si el em-dash (U+2014) en el contenido del PDF
 * sintético del QA es la causa del 403, y expone el canonical request
 * de AWS SigV4 para comparar firma byte a byte entre el caso que
 * funciona y el caso que falla.
 *
 * Ejecutar via WP-CLI:
 *   wp eval-file bin/ltms-diag-backblaze-v2.php --allow-root
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

echo "\n🔬 DIAGNÓSTICO v2 — ¿Es el em-dash la causa del 403?\n";
echo 'Fecha: ' . date( 'Y-m-d H:i:s' ) . "\n\n";

$b2     = LTMS_Api_Factory::get( 'backblaze' );
$bucket = LTMS_Core_Config::get( 'ltms_backblaze_contratos_bucket', 'lotengo-contratos' );

function run_case( $b2, $bucket, $label, $content, $key, $meta ) {
    echo "── {$label} ──\n";
    echo "  Key: {$key}\n";
    echo "  Content bytes: " . strlen( $content ) . " | hex primeros 60: " . bin2hex( substr( $content, 0, 60 ) ) . "\n";
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

$ts = date( 'His' );

// Caso A: EXACTO al QA — contenido CON em-dash, key con date() (no gmdate), meta con qa_test
$content_a = "%PDF-1.4\n% LTMS QA synthetic PDF — " . date( 'Y-m-d H:i:s' ) . "\n%%EOF";
$key_a     = sprintf( 'contratos/%s/vendedor-999999-qa-synthetic-%s-A.pdf', date( 'Y/m' ), $ts );
$meta_a    = [ 'vendor_id' => '999999', 'doc_token' => 'qa-synthetic-' . $ts, 'qa_test' => '1' ];
$ok_a = run_case( $b2, $bucket, 'Caso A — IDÉNTICO al QA (con em-dash)', $content_a, $key_a, $meta_a );

// Caso B: igual que A pero SIN em-dash (reemplazado por guion ASCII normal)
$content_b = "%PDF-1.4\n% LTMS QA synthetic PDF - " . date( 'Y-m-d H:i:s' ) . "\n%%EOF";
$key_b     = sprintf( 'contratos/%s/vendedor-999999-qa-synthetic-%s-B.pdf', date( 'Y/m' ), $ts );
$meta_b    = $meta_a;
$ok_b = run_case( $b2, $bucket, 'Caso B — igual que A pero SIN em-dash', $content_b, $key_b, $meta_b );

// Caso C: contenido CON em-dash pero SIN metadata qa_test (solo vendor_id/doc_token)
$content_c = $content_a;
$key_c     = sprintf( 'contratos/%s/vendedor-999999-qa-synthetic-%s-C.pdf', date( 'Y/m' ), $ts );
$meta_c    = [ 'vendor_id' => '999999', 'doc_token' => 'qa-synthetic-' . $ts ];
$ok_c = run_case( $b2, $bucket, 'Caso C — con em-dash, SIN meta qa_test', $content_c, $key_c, $meta_c );

// Caso D: contenido CON em-dash, key construida con gmdate() en vez de date()
$content_d = $content_a;
$key_d     = sprintf( 'contratos/%s/vendedor-999999-qa-synthetic-%s-D.pdf', gmdate( 'Y/m' ), $ts );
$meta_d    = $meta_a;
$ok_d = run_case( $b2, $bucket, 'Caso D — con em-dash, key con gmdate()', $content_d, $key_d, $meta_d );

echo "═══════════════════════════════════════════════\n";
echo "RESUMEN:\n";
echo "  Caso A (idéntico al QA):          " . ( $ok_a ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso B (sin em-dash):             " . ( $ok_b ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso C (sin meta qa_test):        " . ( $ok_c ? 'OK' : 'FALLÓ' ) . "\n";
echo "  Caso D (key con gmdate):          " . ( $ok_d ? 'OK' : 'FALLÓ' ) . "\n";
echo "═══════════════════════════════════════════════\n\n";

echo "TZ del sitio WP: " . wp_timezone_string() . " | date('Y/m')=" . date('Y/m') . " | gmdate('Y/m')=" . gmdate('Y/m') . "\n";
