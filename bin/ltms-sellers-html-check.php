<?php
/**
 * Diagnose: fetch /sellers/ and check if CSS is enqueued + content visible
 */
if ( ! defined( 'ABSPATH' ) ) die;

echo "=== SELLERS PAGE HTML DIAGNOSIS ===\n\n";

// Check what CSS files are registered for sellers page
$p = get_page_by_path('sellers');
echo "Page ID: {$p->ID}\n";
echo "Content: {$p->post_content}\n\n";

// Simulate is_ltms_page logic
$pages = get_option('ltms_installed_pages', []);
$page_id = $p->ID;
$in_pages = in_array($page_id, array_map('intval', $pages), true);
echo "ID en ltms_installed_pages: " . ($in_pages ? 'SI' : 'NO') . "\n";
echo "has_shortcode: " . (has_shortcode($p->post_content, 'ltms_sellers_landing') ? 'SI' : 'NO') . "\n";

// Check the actual HTML output from the server
$response = wp_remote_get('https://lo-tengo.com.co/sellers/', ['timeout'=>15,'sslverify'=>false]);
if(is_wp_error($response)) { echo "ERROR: ".$response->get_error_message()."\n"; exit; }

$body = wp_remote_retrieve_body($response);
echo "\nHTML length: " . strlen($body) . "\n";
echo "ltms-sellers-landing en HTML: " . (strpos($body,'ltms-sellers-landing')!==false ? 'SI':'NO') . "\n";
echo "ltms-frontend-extensions.css enqueued: " . (strpos($body,'ltms-frontend-extensions')!==false ? 'SI':'NO') . "\n";
echo "ltms-dashboard.css enqueued: " . (strpos($body,'ltms-dashboard')!==false ? 'SI':'NO') . "\n";

// Check if content is inside the_content area
$has_content = strpos($body,'class="ltms-sellers-landing"') !== false;
echo "div.ltms-sellers-landing en HTML: " . ($has_content ? 'SI':'NO') . "\n";

if($has_content) {
    // Find position of the div
    $pos = strpos($body, 'class="ltms-sellers-landing"');
    echo "Contexto: " . substr($body, max(0,$pos-200), 400) . "\n";
}

echo "\n[DONE]\n";
