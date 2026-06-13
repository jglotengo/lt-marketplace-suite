<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Read DB credentials directly from wp-config.php
$cfg = file_get_contents(__DIR__ . '/wp-config.php');
preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $cfg, $m); $db_name = $m[1];
preg_match("/define\(\s*'DB_USER'\s*,\s*'([^']+)'/", $cfg, $m); $db_user = $m[1];
preg_match("/define\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $cfg, $m); $db_pass = $m[1];
preg_match("/define\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $cfg, $m); $db_host = $m[1];
preg_match("/\\\$table_prefix\s*=\s*'([^']+)'/", $cfg, $m); $prefix = $m[1] ?? 'bkr_';

echo "=== LTMS CAPS FIX (raw SQL) ===\n";
echo "DB: $db_name @ $db_host | prefix: $prefix\n\n";

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$option_name = $prefix . 'user_roles';
$row = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name = '$option_name' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo "ERROR: $option_name not found\n"; exit; }

$roles = unserialize($row['option_value']);
if (!isset($roles['administrator'])) { echo "ERROR: administrator role missing\n"; exit; }

$before = count($roles['administrator']['capabilities']);
$has_pub = !empty($roles['administrator']['capabilities']['publish_products']);
echo "Caps before: $before\n";
echo "publish_products before: " . ($has_pub ? 'YES' : 'NO') . "\n\n";

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
echo "Adding: " . (empty($added) ? 'none (all already set)' : implode(', ', $added)) . "\n\n";

$new_val = serialize($roles);
$stmt = $pdo->prepare("UPDATE {$prefix}options SET option_value = ? WHERE option_name = ?");
$ok = $stmt->execute([$new_val, $option_name]);
echo "Save: " . ($ok ? "OK (rows: " . $stmt->rowCount() . ")" : "ERROR") . "\n\n";

// Verify
$v = unserialize($pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name = '$option_name' LIMIT 1")->fetchColumn());
$verified = !empty($v['administrator']['capabilities']['publish_products']);
echo "VERIFY publish_products: " . ($verified ? "YES ✓" : "NO ✗") . "\n";
echo "Total caps after: " . count($v['administrator']['capabilities']) . "\n";
echo "\nDONE\n";
