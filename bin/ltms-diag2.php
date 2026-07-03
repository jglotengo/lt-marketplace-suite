<?php
/**
 * LTMS Diagnóstico 2 — Simula una petición REAL a admin-ajax.php
 * (la única forma válida de probar wp_ajax_* hooks, ya que wp eval-file
 * NO es is_admin() ni wp_doing_ajax(), por lo que boot_admin() nunca corre
 * en ese contexto).
 *
 * Ejecutar: wp eval-file bin/ltms-diag2.php --path=/home/customer/www/lo-tengo.com.co/public_html --allow-root
 */

echo "=== DIAGNÓSTICO 2: PETICIÓN REAL A admin-ajax.php ===\n\n";

// Buscar el primer usuario administrador (no asumir ID 1)
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
if ( empty( $admins ) ) {
    echo "❌ No existe ningún usuario con rol administrator\n";
    exit;
}
$user    = $admins[0];
$user_id = $user->ID;
echo "Usuario: {$user->user_login} (ID {$user_id})\n";

// Generar cookie de autenticación válida CON un token de sesión persistido,
// y usar ESE MISMO token para generar el nonce (wp_create_nonce usa el
// session token del usuario actual via wp_get_session_token()).
$expiration  = time() + 3600;
$manager     = WP_Session_Tokens::get_instance( $user_id );
$token       = $manager->create( $expiration );
$auth_cookie = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
$cookie_name = LOGGED_IN_COOKIE;

echo "Cookie name: $cookie_name\n";
echo "Session token: $token\n";

// Para que wp_create_nonce use el mismo token de sesión, simulamos la cookie
// en $_COOKIE y fijamos el usuario actual ANTES de generar el nonce.
$_COOKIE[ $cookie_name ] = $auth_cookie;
wp_set_current_user( $user_id );

// Generar nonce igual que lo haría el JS (ltmsAdmin.nonce)
$nonce = wp_create_nonce( 'ltms_admin_nonce' );
echo "Nonce: $nonce\n";
echo "current_user_can(ltms_manage_kyc): " . ( current_user_can( 'ltms_manage_kyc' ) ? 'true' : 'false' ) . "\n";
echo "current_user_can(ltms_freeze_wallets): " . ( current_user_can( 'ltms_freeze_wallets' ) ? 'true' : 'false' ) . "\n\n";

// Buscar un vendor real para probar (PRAGA DESIGN, pending)
global $wpdb;
$vendor = $wpdb->get_row( "SELECT ID, user_login FROM {$wpdb->users} ORDER BY ID DESC LIMIT 1" );
$vendor_id = $vendor ? $vendor->ID : 168;
echo "Vendor de prueba: ID $vendor_id ($vendor->user_login)\n\n";

$ajax_url = admin_url( 'admin-ajax.php' );
echo "URL: $ajax_url\n\n";

$tests = [
    'ltms_quick_approve_kyc' => [ 'vendor_id' => $vendor_id ],
    'ltms_freeze_wallet'     => [ 'vendor_id' => $vendor_id ],
];

foreach ( $tests as $action => $extra_args ) {
    echo "--- Probando action=$action ---\n";

    $body = array_merge(
        [
            'action' => $action,
            'nonce'  => $nonce,
        ],
        $extra_args
    );

    $response = wp_remote_post( $ajax_url, [
        'timeout'   => 15,
        'sslverify' => false,
        'headers'   => [
            'Cookie' => "$cookie_name=$auth_cookie",
        ],
        'body'      => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        echo "❌ ERROR DE PETICIÓN: " . $response->get_error_message() . "\n\n";
        continue;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );

    echo "HTTP CODE: $code\n";
    echo "BODY: " . substr( $resp_body, 0, 1000 ) . "\n\n";
}

// También verificar: ¿wp_doing_ajax() y is_admin() en este contexto?
echo "--- Contexto actual (wp eval-file) ---\n";
echo "is_admin(): " . ( is_admin() ? 'true' : 'false' ) . "\n";
echo "wp_doing_ajax(): " . ( wp_doing_ajax() ? 'true' : 'false' ) . "\n";

echo "\n=== FIN DIAGNÓSTICO 2 ===\n";
