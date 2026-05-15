<?php
/**
 * Diagnóstico v2: capturar body HTTP completo de Alegra
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html eval-file bin/ltms-diag-contact2.php --allow-root 2>/dev/null
 */

LTMS_Core_Config::flush_cache();
LTMS_Api_Factory::reset('alegra');

// Obtener token real (descifrado)
$token_raw = get_option('ltms_alegra_token', '');
$email_raw  = get_option('ltms_alegra_email', '');

// Descifrar token si está cifrado
if ( str_starts_with($token_raw, 'v1:') ) {
    try {
        $token_dec = LTMS_Core_Security::decrypt( $token_raw );
    } catch(Throwable $e) {
        $token_dec = $token_raw;
    }
} else {
    $token_dec = $token_raw;
}

echo "Email: $email_raw\n";
echo "Token (primeros 15): " . substr($token_dec, 0, 15) . "...\n";
echo "Auth string: " . base64_encode("$email_raw:$token_dec") . "\n\n";

// Hacer GET /contacts para ver límites
$auth = base64_encode("$email_raw:$token_dec");
$response = wp_remote_get('https://api.alegra.com/api/v1/contacts?limit=1', [
    'headers' => [
        'Authorization' => 'Basic ' . $auth,
        'Accept'        => 'application/json',
    ],
    'timeout' => 15,
]);

echo "=== GET /contacts ===\n";
echo "HTTP: " . wp_remote_retrieve_response_code($response) . "\n";
$body = wp_remote_retrieve_body($response);
$decoded = json_decode($body, true);
echo "Body: " . json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Intentar POST /contacts con el mínimo absoluto y ver el body completo
$post_resp = wp_remote_post('https://api.alegra.com/api/v1/contacts', [
    'headers' => [
        'Authorization' => 'Basic ' . $auth,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ],
    'body'    => json_encode(['name' => 'QA Test ' . date('His')]),
    'timeout' => 15,
]);

echo "=== POST /contacts (body mínimo) ===\n";
echo "HTTP: " . wp_remote_retrieve_response_code($post_resp) . "\n";
$post_body = wp_remote_retrieve_body($post_resp);
echo "Body completo: $post_body\n";

// Ver headers de respuesta
$headers = wp_remote_retrieve_headers($post_resp);
echo "Headers: " . json_encode((array)$headers, JSON_PRETTY_PRINT) . "\n";

// Probar GET /users para ver info de la cuenta
$users_resp = wp_remote_get('https://api.alegra.com/api/v1/users?limit=1', [
    'headers' => ['Authorization' => 'Basic ' . $auth, 'Accept' => 'application/json'],
    'timeout' => 10,
]);
echo "\n=== GET /users (info cuenta) ===\n";
echo "HTTP: " . wp_remote_retrieve_response_code($users_resp) . "\n";
echo json_encode(json_decode(wp_remote_retrieve_body($users_resp), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
