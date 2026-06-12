<?php
/**
 * LTMS Diagnóstico — Botones Vendedores
 * Ejecutar: wp eval-file ltms-diag-buttons.php --path=/home/customer/www/lo-tengo.com.co/public_html --allow-root
 */

$WP = '/home/customer/www/lo-tengo.com.co/public_html';
$PLUGIN = $WP . '/wp-content/plugins/lt-marketplace-suite';

echo "=== DIAGNÓSTICO BOTONES VENDEDORES ===\n\n";

// 1. Verificar que las clases existen
$classes = [
    'LTMS_Admin_Payouts',
    'LTMS_Core_Kernel',
    'LTMS_Admin',
    'LTMS_Business_Wallet',
];
echo "1. CLASES CARGADAS:\n";
foreach ($classes as $c) {
    echo "   " . ($c) . ": " . (class_exists($c) ? "✅ OK" : "❌ NO EXISTE") . "\n";
}

// 2. Verificar hooks AJAX registrados
echo "\n2. HOOKS AJAX REGISTRADOS:\n";
$hooks_to_check = [
    'wp_ajax_ltms_quick_approve_kyc',
    'wp_ajax_ltms_freeze_wallet',
    'wp_ajax_ltms_unfreeze_wallet',
    'wp_ajax_ltms_approve_kyc',
    'wp_ajax_ltms_reject_kyc',
    'wp_ajax_ltms_approve_payout',
];
global $wp_filter;
foreach ($hooks_to_check as $hook) {
    $registered = isset($wp_filter[$hook]) && !empty($wp_filter[$hook]->callbacks);
    echo "   $hook: " . ($registered ? "✅ REGISTRADO" : "❌ NO REGISTRADO") . "\n";
    if ($registered) {
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $fn = $cb['function'];
                if (is_array($fn)) {
                    echo "      → [" . get_class($fn[0]) . ", " . $fn[1] . "]\n";
                } else {
                    echo "      → $fn\n";
                }
            }
        }
    }
}

// 3. Verificar capacidades del admin
echo "\n3. CAPACIDADES DEL ADMIN (user ID 1):\n";
$admin = get_user_by('id', 1);
if ($admin) {
    $caps_to_check = ['ltms_manage_kyc', 'ltms_compliance', 'ltms_freeze_wallets', 'ltms_manage_all_vendors'];
    foreach ($caps_to_check as $cap) {
        echo "   $cap: " . ($admin->has_cap($cap) ? "✅" : "❌ FALTANTE") . "\n";
    }
}

// 4. Verificar nonce
echo "\n4. NONCE TEST:\n";
$nonce = wp_create_nonce('ltms_admin_nonce');
echo "   Nonce generado: $nonce\n";
$verified = wp_verify_nonce($nonce, 'ltms_admin_nonce');
echo "   Verificado: " . ($verified ? "✅ OK" : "❌ FALLO") . "\n";

// 5. Simular llamada AJAX ltms_quick_approve_kyc con vendor_id=168
echo "\n5. SIMULACIÓN AJAX quick_approve_kyc (vendor 168):\n";
if (class_exists('LTMS_Admin_Payouts')) {
    $instance = new ReflectionClass('LTMS_Admin_Payouts');
    echo "   Clase instanciable: ✅\n";
    $method = $instance->hasMethod('ajax_quick_approve_kyc') ? "✅" : "❌";
    echo "   Método ajax_quick_approve_kyc: $method\n";
} else {
    echo "   ❌ Clase no disponible\n";
}

// 6. Verificar que el archivo de la vista tiene las clases correctas
echo "\n6. VISTA html-admin-vendors.php:\n";
$view = $PLUGIN . '/includes/admin/views/html-admin-vendors.php';
if (file_exists($view)) {
    $v = file_get_contents($view);
    echo "   ltms-quick-approve-kyc: " . (str_contains($v, 'ltms-quick-approve-kyc') ? "✅" : "❌") . "\n";
    echo "   ltms-unfreeze-wallet: " . (str_contains($v, 'ltms-unfreeze-wallet') ? "✅" : "❌") . "\n";
    echo "   ltms-freeze-wallet: " . (str_contains($v, 'ltms-freeze-wallet') ? "✅" : "❌") . "\n";
    echo "   onclick inline: " . (str_contains($v, 'onclick') ? "⚠️ TODAVÍA TIENE onclick" : "✅ Sin onclick") . "\n";
} else {
    echo "   ❌ Archivo no encontrado\n";
}

// 7. Verificar ltms-admin.js version (cache)
echo "\n7. ASSET JS:\n";
$js = $PLUGIN . '/assets/js/ltms-admin.js';
if (file_exists($js)) {
    $js_content = file_get_contents($js);
    echo "   ltms-quick-approve-kyc handler: " . (str_contains($js_content, 'ltms-quick-approve-kyc') ? "✅" : "❌") . "\n";
    echo "   ltms-unfreeze-wallet handler: " . (str_contains($js_content, 'ltms-unfreeze-wallet') ? "✅" : "❌") . "\n";
    echo "   ltms-freeze-wallet handler: " . (str_contains($js_content, 'ltms-freeze-wallet') ? "✅" : "❌") . "\n";
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
