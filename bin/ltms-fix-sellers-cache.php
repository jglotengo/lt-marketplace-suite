<?php
/**
 * Fix sellers page: purge all caches aggressively + verify shortcode rendering
 */
if ( ! defined( 'ABSPATH' ) ) die;

echo "=== FIX SELLERS PAGE - CACHE PURGE TOTAL ===\n\n";

// 1. Verify page content
$p = get_page_by_path('sellers');
echo "ID: {$p->ID}\n";
echo "Contenido BD: {$p->post_content}\n";
echo "Tiene shortcode: " . (strpos($p->post_content,'ltms_sellers_landing')!==false ? 'SI':'NO') . "\n\n";

// 2. Force re-save to bust any object cache
wp_update_post(['ID'=>$p->ID,'post_content'=>'[ltms_sellers_landing]','post_status'=>'publish']);
echo "Post re-guardado\n";

// 3. Purge everything
wp_cache_flush();
if(function_exists('opcache_reset')) { opcache_reset(); echo "OPcache reseteado\n"; }
if(function_exists('sg_cachepress_purge_cache')) { sg_cachepress_purge_cache(); echo "SG Cache purgada\n"; }
if(function_exists('w3tc_flush_all')) { w3tc_flush_all(); echo "W3TC flush\n"; }
if(function_exists('rocket_clean_domain')) { rocket_clean_domain(); echo "WP Rocket flush\n"; }

// Purge SG specifically for this URL
$url = get_permalink($p->ID);
if(function_exists('sg_cachepress_purge_single_url')) { 
    sg_cachepress_purge_single_url($url); 
    echo "SG URL purgada: $url\n"; 
}

// 4. Verify shortcode renders
if(class_exists('LTMS_Public_Auth_Handler') && !shortcode_exists('ltms_sellers_landing')) {
    LTMS_Public_Auth_Handler::init();
}
$output = do_shortcode('[ltms_sellers_landing]');
echo "\nShortcode output length: " . strlen($output) . " chars\n";
if(strlen($output) > 100) {
    echo "RENDER OK ✅\n";
    echo "Primeros 200 chars: " . substr($output,0,200) . "\n";
} else {
    echo "RENDER VACIO ❌ — output: " . $output . "\n";
    // Check if LTMS_Core_Config exists
    echo "LTMS_Core_Config existe: " . (class_exists('LTMS_Core_Config')?'SI':'NO') . "\n";
    echo "LTMS_PLUGIN_DIR: " . (defined('LTMS_PLUGIN_DIR')?LTMS_PLUGIN_DIR:'NO DEFINIDA') . "\n";
    $view = (defined('LTMS_PLUGIN_DIR')?LTMS_PLUGIN_DIR:'') . 'includes/frontend/views/view-sellers-landing.php';
    echo "View existe: " . (file_exists($view)?'SI':'NO') . "\n";
    echo "View path: $view\n";
}

echo "\n[DONE]\n";
