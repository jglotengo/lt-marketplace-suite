<?php
/**
 * QA Tests — Webhook Handlers v2.8.9.
 *
 * Ejecutar: wp eval-file tests/qa-webhook-handlers-v289.php
 *
 * 30 tests que verifican:
 *  - Clases cargadas (5 tests)
 *  - WH1 Openpay fail-closed sin secret (3 tests)
 *  - WH2 Addi fail-closed sin token (3 tests)
 *  - WH3 get_client_ip_safe trusted proxies (4 tests)
 *  - WH3 X-Forwarded-For spoofing blocked (2 tests)
 *  - Rate limiting presente en todos (5 tests)
 *  - Idempotency transients en todos (5 tests)
 *  - Rutas REST registradas (5 tests)
 *  - Tabla webhook_logs (2 tests)
 *  - Limpieza (1 test)
 *
 * @package LTMS
 * @version 2.8.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Ejecutar con: wp eval-file tests/qa-webhook-handlers-v289.php\n";
    exit( 1 );
}

$results = [ 'pass' => 0, 'fail' => 0, 'errors' => [] ];

function qa_assert( $cond, $msg, &$results ) {
    if ( $cond ) {
        $results['pass']++;
        echo "[PASS] $msg\n";
    } else {
        $results['fail']++;
        $results['errors'][] = $msg;
        echo "[FAIL] $msg\n";
    }
}

function qa_section( $title ) {
    echo "\n=== $title ===\n";
}

// =====================================================================
qa_section( '1. CLASES CARGADAS' );
// =====================================================================

qa_assert( class_exists( 'LTMS_Stripe_Webhook_Handler' ), 'LTMS_Stripe_Webhook_Handler cargada', $results );
qa_assert( class_exists( 'LTMS_Openpay_Webhook_Handler' ), 'LTMS_Openpay_Webhook_Handler cargada', $results );
qa_assert( class_exists( 'LTMS_Addi_Webhook_Handler' ), 'LTMS_Addi_Webhook_Handler cargada', $results );
qa_assert( class_exists( 'LTMS_Aveonline_Webhook_Handler' ), 'LTMS_Aveonline_Webhook_Handler cargada', $results );
qa_assert( class_exists( 'LTMS_Uber_Direct_Webhook_Handler' ), 'LTMS_Uber_Direct_Webhook_Handler cargada', $results );

// =====================================================================
qa_section( '2. WH1 — OPENPAY FAIL-CLOSED SIN SECRET' );
// =====================================================================

// 2.1 Sin private_key configurado → 401 (no 200 con firma omitida).
LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', '' );
LTMS_Core_Config::set( 'ltms_country', 'CO' );

$request = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/openpay' );
$request->set_body( wp_json_encode( [ 'type' => 'charge.succeeded', 'transaction' => [ 'id' => 'test1' ] ] ) );
$request->set_header( 'Content-Type', 'application/json' );

$response = LTMS_Openpay_Webhook_Handler::handle( $request );
qa_assert( $response->get_status() === 401, 'WH1: Openpay sin secret → 401 (fail-closed)', $results );

// 2.2 Con private_key pero firma inválida → 401.
LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', LTMS_Core_Security::encrypt( 'sk_test_fake_key_12345' ) );

$request2 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/openpay' );
$request2->set_body( wp_json_encode( [ 'type' => 'charge.succeeded', 'transaction' => [ 'id' => 'test2' ] ] ) );
$request2->set_header( 'Content-Type', 'application/json' );
$request2->set_header( 'x-openpay-signature', 'sha256=invalid_signature' );

$response2 = LTMS_Openpay_Webhook_Handler::handle( $request2 );
qa_assert( $response2->get_status() === 401, 'WH1: Openpay con firma inválida → 401', $results );

// 2.3 Con private_key y firma correcta → pasa validación (puede 400 por payload).
$valid_payload = wp_json_encode( [ 'type' => 'charge.succeeded', 'transaction' => [ 'id' => 'test3' ] ] );
$valid_hmac = 'sha256=' . hash_hmac( 'sha256', $valid_payload, 'sk_test_fake_key_12345' );

$request3 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/openpay' );
$request3->set_body( $valid_payload );
$request3->set_header( 'Content-Type', 'application/json' );
$request3->set_header( 'x-openpay-signature', $valid_hmac );

$response3 = LTMS_Openpay_Webhook_Handler::handle( $request3 );
// Debe pasar la firma (no 401). Puede ser 200 o 404 dependiendo del order lookup.
qa_assert( $response3->get_status() !== 401, 'WH1: Openpay con firma válida → pasa (no 401)', $results );

// =====================================================================
qa_section( '3. WH2 — ADDI FAIL-CLOSED SIN TOKEN' );
// =====================================================================

// 3.1 Sin token configurado → 401.
LTMS_Core_Config::set( 'ltms_addi_webhook_token', '' );

$request = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/addi' );
$request->set_body( wp_json_encode( [ 'orderId' => 123, 'status' => 'APPROVED' ] ) );
$request->set_header( 'Content-Type', 'application/json' );

$response = LTMS_Addi_Webhook_Handler::handle( $request );
qa_assert( $response->get_status() === 401, 'WH2: Addi sin token → 401 (fail-closed)', $results );

// 3.2 Con token pero header vacío → 401.
LTMS_Core_Config::set( 'ltms_addi_webhook_token', 'valid_token_12345' );

$request2 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/addi' );
$request2->set_body( wp_json_encode( [ 'orderId' => 123, 'status' => 'APPROVED' ] ) );
$request2->set_header( 'Content-Type', 'application/json' );
$request2->set_header( 'x-addi-signature', '' );

$response2 = LTMS_Addi_Webhook_Handler::handle( $request2 );
qa_assert( $response2->get_status() === 401, 'WH2: Addi con token pero header vacío → 401', $results );

// 3.3 Con token y header correcto → pasa (puede 404 por order).
$request3 = new WP_REST_Request( 'POST', '/ltms/v1/webhooks/addi' );
$request3->set_body( wp_json_encode( [ 'orderId' => 99999999, 'status' => 'APPROVED', 'transactionId' => 'txn_test' ] ) );
$request3->set_header( 'Content-Type', 'application/json' );
$request3->set_header( 'x-addi-signature', 'valid_token_12345' );

$response3 = LTMS_Addi_Webhook_Handler::handle( $request3 );
qa_assert( $response3->get_status() !== 401, 'WH2: Addi con token válido → pasa (no 401)', $results );

// =====================================================================
qa_section( '4. WH3 — GET_CLIENT_IP_SAFE TRUSTED PROXIES' );
// =====================================================================

qa_assert( class_exists( 'LTMS_Core_Security' ), 'LTMS_Core_Security cargada', $results );
qa_assert( method_exists( 'LTMS_Core_Security', 'get_client_ip_safe' ), 'Método get_client_ip_safe existe', $results );

// 4.1 Sin trusted proxies configurados → usa REMOTE_ADDR.
delete_option( 'ltms_trusted_proxies' );
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1'; // Spoofed.
$ip = LTMS_Core_Security::get_client_ip_safe();
qa_assert( $ip === '192.168.1.100', 'WH3: sin trusted proxies → REMOTE_ADDR (no spoofable)', $results );

// 4.2 Con trusted proxies y REMOTE_ADDR en lista → usa X-Forwarded-For.
update_option( 'ltms_trusted_proxies', '192.168.1.100,10.0.0.1' );
$_SERVER['REMOTE_ADDR'] = '192.168.1.100'; // En la lista.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
$ip = LTMS_Core_Security::get_client_ip_safe();
qa_assert( $ip === '203.0.113.50', 'WH3: con trusted proxy → usa X-Forwarded-For', $results );

// 4.3 REMOTE_ADDR no en trusted proxies → ignora X-Forwarded-For.
$_SERVER['REMOTE_ADDR'] = '198.51.100.99'; // No en la lista.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
$ip = LTMS_Core_Security::get_client_ip_safe();
qa_assert( $ip === '198.51.100.99', 'WH3: REMOTE_ADDR no en lista → ignora X-Forwarded-For', $results );

// =====================================================================
qa_section( '5. WH3 — X-FORWARDED-FOR SPOOFING BLOCKED' );
// =====================================================================

// 5.1 Sin trusted proxies → X-Forwarded-For ignorado (no bypass rate limit).
delete_option( 'ltms_trusted_proxies' );
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'spoofed_ip_1,spoofed_ip_2';
$ip = LTMS_Core_Security::get_client_ip_safe();
qa_assert( $ip === '192.168.1.100', 'WH3: spoofing blocked — usa REMOTE_ADDR', $results );

// 5.2 Múltiples IPs en X-Forwarded-For con proxy confiable → última IP.
update_option( 'ltms_trusted_proxies', '192.168.1.100' );
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1,203.0.113.2,203.0.113.3';
$ip = LTMS_Core_Security::get_client_ip_safe();
qa_assert( $ip === '203.0.113.3', 'WH3: múltiple X-Forwarded-For → última IP (más cercana al cliente)', $results );

// Limpieza.
delete_option( 'ltms_trusted_proxies' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

// =====================================================================
qa_section( '6. RATE LIMITING PRESENTE' );
// =====================================================================

// Verificar que todos los handlers usan client_ip() para rate limiting.
$handlers = [
    'LTMS_Stripe_Webhook_Handler',
    'LTMS_Openpay_Webhook_Handler',
    'LTMS_Addi_Webhook_Handler',
    'LTMS_Aveonline_Webhook_Handler',
    'LTMS_Uber_Direct_Webhook_Handler',
];

foreach ( $handlers as $handler ) {
    $reflection = new ReflectionClass( $handler );
    $has_client_ip = $reflection->hasMethod( 'client_ip' );
    qa_assert( $has_client_ip, "$handler tiene método client_ip()", $results );
}

// =====================================================================
qa_section( '7. IDEMPOTENCY TRANSIENTS' );
// =====================================================================

// Verificar que cada handler usa transient con prefijo ltms_wh_seen_.
$handler_files = [
    '/home/z/my-project/lt-marketplace-suite/includes/api/webhooks/class-ltms-stripe-webhook-handler.php',
    '/home/z/my-project/lt-marketplace-suite/includes/api/webhooks/class-ltms-openpay-webhook-handler.php',
    '/home/z/my-project/lt-marketplace-suite/includes/api/webhooks/class-ltms-addi-webhook-handler.php',
    '/home/z/my-project/lt-marketplace-suite/includes/api/webhooks/class-ltms-aveonline-webhook-handler.php',
    '/home/z/my-project/lt-marketplace-suite/includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php',
];

foreach ( $handler_files as $file ) {
    $content = file_get_contents( $file );
    $has_idem = strpos( $content, 'ltms_wh_seen_' ) !== false || strpos( $content, 'ltms_ave_wh_seen_' ) !== false;
    $handler_name = basename( $file, '.php' );
    qa_assert( $has_idem, "$handler_name usa idempotency transient", $results );
}

// =====================================================================
qa_section( '8. RUTAS REST REGISTRADAS' );
// =====================================================================

// Forzar registro de rutas.
do_action( 'rest_api_init' );

$rest_server = rest_get_server();
$routes = $rest_server->get_routes();

$expected_routes = [
    '/ltms/v1/webhooks/stripe',
    '/ltms/v1/webhooks/openpay',
    '/ltms/v1/webhooks/addi',
    '/ltms/v1/webhooks/aveonline',
    '/ltms/v1/webhooks/uber-direct',
];

foreach ( $expected_routes as $route ) {
    qa_assert( isset( $routes[ $route ] ), "Ruta REST $route registrada", $results );
}

// =====================================================================
qa_section( '9. TABLA WEBHOOK_LOGS' );
// =====================================================================

global $wpdb;
$log_table = $wpdb->prefix . 'lt_webhook_logs';
$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $log_table ) );
qa_assert( $exists === $log_table, "Tabla $log_table existe", $results );

// Verificar columnas mínimas.
$columns = $wpdb->get_col( "DESCRIBE `{$log_table}`" );
$required = [ 'id', 'provider', 'event_type', 'status', 'created_at' ];
$missing = array_diff( $required, $columns );
qa_assert( empty( $missing ), 'Tabla webhook_logs tiene columnas requeridas', $results );

// =====================================================================
qa_section( '10. LIMPIEZA' );
// =====================================================================

// Restaurar config.
LTMS_Core_Config::set( 'ltms_openpay_CO_private_key', '' );
LTMS_Core_Config::set( 'ltms_addi_webhook_token', '' );
delete_option( 'ltms_trusted_proxies' );

// Limpiar transients de test.
for ( $i = 1; $i <= 3; $i++ ) {
    delete_transient( 'ltms_wh_seen_openpay_' . md5( 'charge.succeeded|test' . $i ) );
}
delete_transient( 'ltms_wh_seen_addi_' . md5( 'addi_99999999_APPROVED_txn_test' ) );

qa_assert( true, 'Limpieza completada', $results );

// =====================================================================
qa_section( 'RESUMEN' );
// =====================================================================

echo "\n";
echo "========================================\n";
echo "  RESULTADOS: {$results['pass']} PASS / {$results['fail']} FAIL\n";
echo "========================================\n";

if ( $results['fail'] > 0 ) {
    echo "\nFALLAS:\n";
    foreach ( $results['errors'] as $err ) {
        echo "  - $err\n";
    }
    exit( 1 );
}

echo "\nTodos los tests PASARON.\n";
exit( 0 );
