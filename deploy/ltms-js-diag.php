<?php
/**
 * LTMS JS Diagnostic & Force Patch
 * URL: /ltms-js-diag.php?t=ltms_js_diag_2026
 * DELETE after use.
 */
if (!hash_equals('ltms_js_diag_2026', $_GET['t'] ?? '')) { http_response_code(403); exit('Forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

$base   = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
$js_path = $base . '/assets/js/ltms-dashboard.js';

echo "=== JS DIAGNOSTIC ===\n";
echo "Path: $js_path\n";
echo "Exists: " . (file_exists($js_path) ? 'YES' : 'NO') . "\n";

if (file_exists($js_path)) {
    $content = file_get_contents($js_path);
    $size = strlen($content);
    echo "Size: $size bytes\n";
    
    // Check for key strings
    $checks = [
        'product_type'         => substr_count($content, 'product_type'),
        'ltms-ep-type'         => substr_count($content, 'ltms-ep-type'),
        'ltms-np-type'         => substr_count($content, 'ltms-np-type'),
        'Tipo *'               => substr_count($content, 'Tipo *'),
        'Version: 1.5.2'       => substr_count($content, 'Version: 1.5.2'),
        'Version: 1.5.1'       => substr_count($content, 'Version: 1.5.1'),
        'Producto físico'      => substr_count($content, 'Producto físico'),
    ];
    
    echo "\n=== STRING COUNTS ===\n";
    foreach ($checks as $k => $v) {
        echo "  $k: $v\n";
    }
    
    // Show first 200 chars
    echo "\n=== FILE HEADER ===\n";
    echo substr($content, 0, 200) . "\n";
}

// Check PHP file
$php_path = $base . '/includes/frontend/class-ltms-products-ajax.php';
echo "\n=== PHP DIAGNOSTIC ===\n";
echo "Path: $php_path\n";
echo "Exists: " . (file_exists($php_path) ? 'YES' : 'NO') . "\n";
if (file_exists($php_path)) {
    $php = file_get_contents($php_path);
    echo "_ltms_product_type occurrences: " . substr_count($php, '_ltms_product_type') . "\n";
}

// Git status
echo "\n=== GIT STATUS ===\n";
chdir($base);
echo shell_exec('git log --oneline -3 2>&1');
echo "\n";
echo shell_exec('git status --short 2>&1');

echo "\n=== DONE ===\n";
?>
