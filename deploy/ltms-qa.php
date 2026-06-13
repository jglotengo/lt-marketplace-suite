<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Full WordPress load - no SHORTINIT
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS CAPS FIX ===\n\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "WP: " . get_bloginfo('version') . "\n\n";

$option_name = $wpdb->prefix . 'user_roles';
$roles_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);

if (!$roles_raw) {
    echo "ERROR: user_roles not found (prefix=" . $wpdb->prefix . ")\n";
    exit;
}

$roles = unserialize($roles_raw);
if (!isset($roles['administrator'])) {
    echo "ERROR: administrator role not found\n";
    var_dump(array_keys($roles));
    exit;
}

$before = count($roles['administrator']['capabilities']);
$has_before = !empty($roles['administrator']['capabilities']['publish_products']);
echo "Caps before: $before\n";
echo "publish_products before: " . ($has_before ? 'YES' : 'NO') . "\n\n";

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
echo "Added: " . (empty($added) ? 'none (already set)' : implode(', ', $added)) . "\n\n";

$serialized = serialize($roles);
$result = $wpdb->query(
    $wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
        $serialized,
        $option_name
    )
);

echo "DB update result: " . ($result === false ? "ERROR: " . $wpdb->last_error : "OK (rows: $result)") . "\n\n";

// Hard verify from DB
$v_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
);
$v = unserialize($v_raw);
$ok = !empty($v['administrator']['capabilities']['publish_products']);
echo "VERIFY publish_products: " . ($ok ? "YES ✓" : "NO ✗") . "\n";
echo "Total caps after: " . count($v['administrator']['capabilities']) . "\n";

// Also flush WP object cache so it picks up new role
wp_cache_delete($option_name, 'options');
echo "Cache flushed\n";
echo "\nDONE\n";
