<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS CAPS FIX ===\n\n";

$option_name = $wpdb->prefix . 'user_roles';
$roles_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);

if (!$roles_raw) {
    echo "ERROR: user_roles not found\n";
    exit;
}

$roles = maybe_unserialize($roles_raw);
if (!isset($roles['administrator'])) {
    echo "ERROR: administrator role not found\n";
    exit;
}

echo "Caps before: " . count($roles['administrator']['capabilities']) . "\n";
echo "Has publish_products before: " . (empty($roles['administrator']['capabilities']['publish_products']) ? 'NO' : 'YES') . "\n\n";

$woo_caps = [
    'publish_products', 'edit_products', 'edit_published_products',
    'edit_others_products', 'delete_products', 'delete_published_products',
    'delete_others_products', 'read_private_products', 'edit_private_products',
    'delete_private_products', 'manage_product_terms', 'edit_product_terms',
    'delete_product_terms', 'assign_product_terms',
    'manage_woocommerce', 'view_woocommerce_reports',
];

$added = [];
foreach ($woo_caps as $cap) {
    if (empty($roles['administrator']['capabilities'][$cap])) {
        $roles['administrator']['capabilities'][$cap] = true;
        $added[] = $cap;
    }
}

echo "Added caps: " . (empty($added) ? 'none (all already set)' : implode(', ', $added)) . "\n\n";

$result = $wpdb->update(
    $wpdb->options,
    ['option_value' => serialize($roles)],
    ['option_name' => $option_name]
);

if ($result === false) {
    echo "ERROR saving: " . $wpdb->last_error . "\n";
    exit;
}
echo "Save result: OK (rows affected: $result)\n\n";

// Verify from DB
$v_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);
$v = maybe_unserialize($v_raw);
$has = !empty($v['administrator']['capabilities']['publish_products']);
echo "VERIFY publish_products in DB: " . ($has ? "YES ✓" : "NO ✗") . "\n";
echo "Total caps after: " . count($v['administrator']['capabilities']) . "\n";
echo "\nDONE\n";
