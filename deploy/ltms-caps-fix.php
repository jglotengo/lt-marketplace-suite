<?php
// LTMS one-shot: add WooCommerce caps to administrator role via SQL
// Self-deletes after running. Token protected.
if (($_GET['t'] ?? '') !== 'ltms_caps_fix_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

// Get current administrator role caps from wp_options
$option_name = $wpdb->prefix . 'user_roles';
$roles_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);

if (!$roles_raw) {
    echo "ERROR: Could not find user_roles option\n";
    exit;
}

$roles = maybe_unserialize($roles_raw);
if (!isset($roles['administrator'])) {
    echo "ERROR: administrator role not found\n";
    exit;
}

echo "Current caps count: " . count($roles['administrator']['capabilities']) . "\n";

$woo_caps = [
    'publish_products',
    'edit_products',
    'edit_published_products',
    'edit_others_products',
    'delete_products',
    'delete_published_products',
    'delete_others_products',
    'read_private_products',
    'edit_private_products',
    'delete_private_products',
    'manage_product_terms',
    'edit_product_terms',
    'delete_product_terms',
    'assign_product_terms',
    'manage_woocommerce',
    'view_woocommerce_reports',
];

$added = [];
$already = [];
foreach ($woo_caps as $cap) {
    if (!isset($roles['administrator']['capabilities'][$cap]) || !$roles['administrator']['capabilities'][$cap]) {
        $roles['administrator']['capabilities'][$cap] = true;
        $added[] = $cap;
    } else {
        $already[] = $cap;
    }
}

echo "Already had: " . implode(', ', $already) . "\n";
echo "Adding: " . implode(', ', $added) . "\n";

// Save back
$new_value = serialize($roles);
$result = $wpdb->update(
    $wpdb->options,
    ['option_value' => $new_value],
    ['option_name' => $option_name]
);

if ($result === false) {
    echo "ERROR saving: " . $wpdb->last_error . "\n";
} else {
    echo "Saved OK. Rows affected: $result\n";
    echo "New caps count: " . count($roles['administrator']['capabilities']) . "\n";
}

// Verify publish_products is now set
$verify_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);
$verify = maybe_unserialize($verify_raw);
$has_pub = isset($verify['administrator']['capabilities']['publish_products']) && $verify['administrator']['capabilities']['publish_products'];
echo "publish_products verified: " . ($has_pub ? "YES" : "NO") . "\n";
echo "DONE\n";

// Self-delete
@unlink(__FILE__);
echo "Script deleted.\n";
