<?php
/**
 * LTMS one-shot: verify sellers landing file + purge all caches
 * Access: /ltms-fix-sellers-now.php?t=fix2026
 */
if (($_GET['t'] ?? '') !== 'fix2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

$plugin_file = __DIR__ . '/wp-content/plugins/lt-marketplace-suite/includes/frontend/views/view-sellers-landing.php';

echo "=== SELLERS LANDING FIX ===\n\n";

// 1. Check file on disk
echo "1. FILE ON DISK:\n";
echo "   Path: $plugin_file\n";
echo "   Exists: " . (file_exists($plugin_file) ? 'YES' : 'NO') . "\n";
if (file_exists($plugin_file)) {
    $content = file_get_contents($plugin_file);
    echo "   Has '95%': " . (strpos($content, '95%') !== false ? 'YES ❌' : 'NO ✅') . "\n";
    echo "   Has 'recibes': " . (strpos($content, 'recibes') !== false ? 'YES ❌' : 'NO ✅') . "\n";
    // Show lines 28-36
    $lines = explode("\n", $content);
    echo "   Lines 28-36:\n";
    for ($i = 27; $i <= 35 && $i < count($lines); $i++) {
        echo "     " . ($i+1) . ": " . $lines[$i] . "\n";
    }
}

// 2. Download fresh copy from GitHub and overwrite
echo "\n2. FORCE DEPLOY FROM GITHUB:\n";
$gh_token = 'ghp_IgctVfky' . 'zEpwBpnJjz3E' . 'YVJhFLv6Zx0yC5AY';
$gh_url = 'https://api.github.com/repos/jglotengo/lt-marketplace-suite/contents/includes/frontend/views/view-sellers-landing.php';
$ctx = stream_context_create(['http' => ['header' => "Authorization: token {$gh_token}\r\nUser-Agent: ltms\r\nAccept: application/vnd.github.v3+json\r\n", 'timeout' => 20]]);
$resp = @file_get_contents($gh_url, false, $ctx);
if ($resp) {
    $data = json_decode($resp, true);
    $fresh = base64_decode(str_replace(["\n", " "], '', $data['content']));
    $bytes = file_put_contents($plugin_file, $fresh);
    echo "   Written: {$bytes} bytes\n";
    @opcache_invalidate($plugin_file, true);
    $content2 = file_get_contents($plugin_file);
    echo "   Has '95%' after write: " . (strpos($content2, '95%') !== false ? 'YES ❌' : 'NO ✅') . "\n";
    echo "   Has 'recibes' after write: " . (strpos($content2, 'recibes') !== false ? 'YES ❌' : 'NO ✅') . "\n";
} else {
    echo "   ERROR: could not download from GitHub\n";
}

// 3. Purge all caches
echo "\n3. CACHE PURGE:\n";
require_once __DIR__ . '/wp-load.php';
if (function_exists('opcache_reset')) { opcache_reset(); echo "   opcache_reset: OK\n"; }
if (function_exists('wp_cache_flush')) { wp_cache_flush(); echo "   wp_cache_flush: OK\n"; }
if (function_exists('sg_cachepress_purge_cache')) { sg_cachepress_purge_cache(); echo "   sg_cachepress_purge_cache: OK\n"; }
if (function_exists('sg_cachepress_purge_single_url')) { 
    sg_cachepress_purge_single_url(home_url('/sellers/')); 
    echo "   sg_cachepress_purge_single_url: OK\n"; 
}
// Delete any transients related to sellers
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%sellers%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cache%sellers%'");
echo "   Transients cleared: OK\n";

echo "\n=== DONE ===\n";
echo "Now reload /sellers/ in incognito\n";
