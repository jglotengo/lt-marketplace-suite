<?php
// Diagnóstico 2: probar AJAX directamente simulando la petición
// wp eval-file bin/ltms-diag2.php --path=/home/customer/www/lo-tengo.com.co/public_html --allow-root

echo "=== DIAGNÓSTICO 2: AJAX REAL TEST ===\n\n";

// 1. Info del admin
$admin = get_user_by('id', 1);
echo "Admin ID 1: " . ($admin ? $admin->user_login . " / " . $admin->user_email : "NO EXISTE") . "\n";

// Buscar admins
$admins = get_users(['role' => 'administrator', 'number' => 3]);
foreach ($admins as $u) {
    echo "Admin encontrado: ID={$u->ID} login={$u->user_login} email={$u->user_email}\n";
}

echo "\n";

// 2. Simular boot_admin manualmente y verificar
echo "Simulando boot_admin...\n";
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
// Forzar wp_doing_ajax = true
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);

// Llamar init de Admin_Payouts directamente
if (class_exists('LTMS_Admin_Payouts')) {
    LTMS_Admin_Payouts::init();
    echo "LTMS_Admin_Payouts::init() ejecutado\n";
}

// Re-verificar hooks
global $wp_filter;
$hooks = ['wp_ajax_ltms_quick_approve_kyc','wp_ajax_ltms_freeze_wallet','wp_ajax_ltms_unfreeze_wallet'];
foreach ($hooks as $h) {
    $ok = isset($wp_filter[$h]) && !empty($wp_filter[$h]->callbacks);
    echo "  $h: " . ($ok ? "✅" : "❌") . "\n";
}

echo "\n";

// 3. Hacer una llamada AJAX real con wp_remote_post
echo "Probando llamada AJAX real...\n";
$admin_user = get_users(['role'=>'administrator','number'=>1])[0] ?? null;
if ($admin_user) {
    wp_set_current_user($admin_user->ID);
    $nonce = wp_create_nonce('ltms_admin_nonce');
    echo "Nonce para user {$admin_user->ID}: $nonce\n";
    
    // Test: llamar admin-ajax.php directamente
    $response = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout' => 15,
        'cookies' => [],
        'body' => [
            'action'    => 'ltms_quick_approve_kyc',
            'nonce'     => $nonce,
            'vendor_id' => 999999, // ID que no existe → esperamos error de "vendedor no encontrado"
        ],
        'headers' => [
            'X-WP-Nonce' => $nonce,
        ],
    ]);
    
    if (is_wp_error($response)) {
        echo "WP_Error: " . $response->get_error_message() . "\n";
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        echo "HTTP $code\n";
        echo "Body: $body\n";
    }
}

echo "\n=== FIN DIAGNÓSTICO 2 ===\n";
