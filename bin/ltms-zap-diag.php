<?php
if ( ! defined( 'ABSPATH' ) ) die;

echo "=== ZAPSIGN URL DIAG ===\n\n";

// Test 1: wp_remote_get directo
$url = 'https://api.zapsign.com.br/api/v1/docs/';
$token = LTMS_Core_Config::get('ltms_zapsign_api_token', '');
$decrypted = '';
if ($token && class_exists('LTMS_Core_Security')) {
    try { $decrypted = LTMS_Core_Security::decrypt($token); } catch(Exception $e) { $decrypted = $token; }
}
echo "Token len: " . strlen($decrypted) . "\n";
echo "URL being called: $url\n";

$resp = wp_remote_get($url, [
    'headers' => ['Authorization' => 'Bearer ' . $decrypted, 'Content-Type' => 'application/json'],
    'timeout' => 15,
    'sslverify' => false,
]);

if (is_wp_error($resp)) {
    echo "wp_error: " . $resp->get_error_message() . "\n";
    echo "wp_error_code: " . $resp->get_error_code() . "\n";
} else {
    echo "HTTP status: " . wp_remote_retrieve_response_code($resp) . "\n";
    echo "Body: " . substr(wp_remote_retrieve_body($resp), 0, 300) . "\n";
}

// Test 2: verificar template_id guardado
echo "\nTemplate ID en BD: '" . get_option('ltms_zapsign_vendor_template_id','(vacío)') . "'\n";
echo "ltms_settings array key: ";
$settings = get_option('ltms_settings', []);
echo ($settings['ltms_zapsign_vendor_template_id'] ?? '(no en ltms_settings)') . "\n";
