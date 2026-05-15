<?php
/**
 * Agrega logging al template_include para ver qué template se sirve para cada página
 */
if ( ! defined( 'ABSPATH' ) ) die;

// Forzar manualmente el check para registro-vendedor
$p = get_page_by_path('registro-vendedor');
echo "Página registro-vendedor ID: {$p->ID}\n";
echo "post_content: {$p->post_content}\n";
echo "has_shortcode ltms_vendor_register: " . (has_shortcode($p->post_content,'ltms_vendor_register')?'SI':'NO') . "\n";

$custom = LTMS_PLUGIN_DIR . 'includes/frontend/views/template-sellers-page.php';
echo "Template custom existe: " . (file_exists($custom)?'SI':'NO') . "\n";
echo "Template path: $custom\n\n";

// Simular lo que hace maybe_serve_sellers_template
$ltms_shortcodes = ['ltms_sellers_landing','ltms_vendor_register','ltms_vendor_login','ltms_vendor_dashboard'];
$needs_bypass = false;
foreach($ltms_shortcodes as $sc) {
    if(has_shortcode($p->post_content, $sc)) { $needs_bypass = true; break; }
}
echo "needs_bypass: " . ($needs_bypass?'SI':'NO') . "\n";

// Ver si el hook template_include tiene nuestro método
global $wp_filter;
$has_our_filter = false;
if(isset($wp_filter['template_include'])) {
    foreach($wp_filter['template_include']->callbacks as $priority => $callbacks) {
        foreach($callbacks as $cb) {
            if(is_array($cb['function']) && $cb['function'][1]==='maybe_serve_sellers_template') {
                $has_our_filter = true;
                echo "maybe_serve_sellers_template registrado en prioridad: $priority\n";
            }
        }
    }
}
if(!$has_our_filter) echo "⚠️  maybe_serve_sellers_template NO está en template_include\n";
