<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS KYC UPLOAD TRACE + FIXES ===\n\n";

// Fix: marketing bucket
$mb = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_marketing_bucket'");
if (empty($mb)) {
    $wpdb->replace($wpdb->options, ['option_name'=>'ltms_backblaze_marketing_bucket','option_value'=>'lotengo-marketing','autoload'=>'yes']);
    echo "[FIX] ltms_backblaze_marketing_bucket -> 'lotengo-marketing' ✓\n";
}

// Fix: ensure default_bucket is contratos not kyc
$db = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_default_bucket'");
echo "[CHECK] ltms_backblaze_default_bucket: {$db}\n";
if ($db === 'lotengo-kyc-docs') {
    $wpdb->update($wpdb->options, ['option_value'=>'lotengo-contratos'], ['option_name'=>'ltms_backblaze_default_bucket']);
    echo "  -> FIXED to 'lotengo-contratos'\n";
}

echo "\n=== KYC UPLOAD HANDLER TRACE ===\n";

// Find the KYC handler file
$plugin = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
$kyc_files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $name = strtolower($f->getBasename());
        if (strpos($name,'kyc') !== false) $kyc_files[] = $f->getPathname();
    }
}
echo "KYC PHP files found:\n";
foreach ($kyc_files as $f) echo "  " . str_replace($plugin.'/', '', $f) . "\n";

// Find where file_path is set in kyc files
echo "\n=== WHERE file_path IS SET ===\n";
foreach ($kyc_files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'file_path') !== false) {
        $lines = explode("\n", $content);
        $fname = str_replace($plugin.'/', '', $f);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'file_path') !== false && strpos($line, '//') === false) {
                echo "  {$fname}:" . ($i+1) . ": " . trim($line) . "\n";
            }
        }
    }
}

// Find where upload_file or b2 upload is called in kyc context
echo "\n=== WHERE B2 UPLOAD IS CALLED ===\n";
foreach ($kyc_files as $f) {
    $content = file_get_contents($f);
    $fname = str_replace($plugin.'/', '', $f);
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if ((strpos($line, 'upload_file') !== false || strpos($line, 'upload_to_b2') !== false || strpos($line, 'backblaze') !== false || strpos($line, 'b2->') !== false) && strpos($line, '//') === false) {
            echo "  {$fname}:" . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}

// Check the REST API handler for KYC submit
echo "\n=== KYC REST API ENDPOINTS ===\n";
foreach ($kyc_files as $f) {
    $content = file_get_contents($f);
    $fname = str_replace($plugin.'/', '', $f);
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'register_rest_route') !== false && strpos($line, 'kyc') !== false) {
            echo "  {$fname}:" . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}

// Check class-ltms-api-backblaze.php exists and has upload_file method
echo "\n=== BACKBLAZE CLASS CHECK ===\n";
$b2_file = $plugin . '/includes/api/class-ltms-api-backblaze.php';
if (file_exists($b2_file)) {
    echo "  class-ltms-api-backblaze.php: EXISTS ✓\n";
    $b2c = file_get_contents($b2_file);
    echo "  upload_file method: " . (strpos($b2c, 'function upload_file') !== false ? 'EXISTS ✓' : 'MISSING ✗') . "\n";
    echo "  get_presigned_url: " . (strpos($b2c, 'get_presigned_url') !== false ? 'EXISTS ✓' : 'MISSING ✗') . "\n";
    echo "  delete_file: " . (strpos($b2c, 'function delete_file') !== false ? 'EXISTS ✓' : 'MISSING ✗') . "\n";
} else {
    echo "  class-ltms-api-backblaze.php: MISSING ✗\n";
}

echo "\n=== KYC FORM HANDLER (POST handler) ===\n";
// Find the actual handler that processes KYC form POST
$all_files = [];
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/includes'));
foreach ($it2 as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') $all_files[] = $f->getPathname();
}
foreach ($all_files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'ltms_kyc_submit') !== false || strpos($content, 'kyc_submit') !== false || strpos($content, 'submit_kyc') !== false) {
        $fname = str_replace($plugin.'/', '', $f);
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'submit') !== false && strpos($line, 'kyc') !== false && strpos($line, '//') === false) {
                echo "  {$fname}:" . ($i+1) . ": " . trim($line) . "\n";
            }
        }
    }
}

echo "\n=== DONE ===\n";
