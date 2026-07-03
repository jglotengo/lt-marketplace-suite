<?php
/**
 * Fix sellers: forzar que the_content() renderice el shortcode
 * aunque el tema use un template custom.
 */
if ( ! defined( 'ABSPATH' ) ) die;

echo "=== SELLERS THEME TEMPLATE FIX ===\n\n";

$p = get_page_by_path('sellers');
echo "ID: {$p->ID}\n";

// Ver qué template está usando realmente WP para esta página
// Buscar en los templates del tema activo
$theme = wp_get_theme();
echo "Tema activo: " . $theme->get('Name') . "\n";
echo "Template del tema: " . $theme->get_template() . "\n";

// Listar templates disponibles del tema
$template_dir = get_template_directory();
$templates = glob($template_dir . '/page*.php');
echo "\nTemplates de página en el tema:\n";
foreach($templates as $t) {
    echo "  " . basename($t) . "\n";
}

// Ver si hay page-sellers.php o page-13599.php
$specific = [
    $template_dir . '/page-sellers.php',
    $template_dir . '/page-' . $p->ID . '.php',
    $template_dir . '/woocommerce/archive-product.php',
];
echo "\nBuscar templates específicos:\n";
foreach($specific as $t) {
    echo "  " . basename($t) . ": " . (file_exists($t) ? "EXISTE ⚠️" : "no existe") . "\n";
}

// Ver si WooCommerce está sobreescribiendo la page
$wc_shop = wc_get_page_id('shop');
echo "\nWC Shop page ID: $wc_shop\n";
echo "Sellers es shop: " . ($wc_shop == $p->ID ? 'SI ❌' : 'no') . "\n";

// Ver si el tema usa is_shop() para sellers
$wc_main_query = false;
if(function_exists('wc_get_loop_prop')) {
    echo "WooCommerce activo: SI\n";
}

// SOLUCIÓN: agregar filtro the_content para inyectar el shortcode
// si el tema no llama the_content() en esta página
// También verificar si hay un filtro bloqueando
global $wp_filter;
$content_filters = isset($wp_filter['the_content']) ? count($wp_filter['the_content']->callbacks) : 0;
echo "\nFiltros en the_content: $content_filters prioridades\n";

// FIX: usar template_redirect para forzar template correcto
// Verificar si page.php del tema llama the_content()
$page_template = $template_dir . '/page.php';
if(file_exists($page_template)) {
    $content = file_get_contents($page_template);
    echo "page.php llama the_content(): " . (strpos($content,'the_content')!==false ? 'SI':'NO ❌') . "\n";
    echo "page.php llama get_template_part: " . (strpos($content,'get_template_part')!==false ? 'SI - buscar inner':'NO') . "\n";
    // Find what template part it loads
    preg_match_all("/get_template_part\s*\(\s*['\"]([^'\"]+)['\"]/",$content,$matches);
    if(!empty($matches[1])) {
        echo "Template parts cargados: " . implode(', ',$matches[1]) . "\n";
        foreach($matches[1] as $part) {
            $part_file = $template_dir . '/' . str_replace('/',DIRECTORY_SEPARATOR,$part) . '.php';
            if(file_exists($part_file)) {
                $part_content = file_get_contents($part_file);
                echo "  $part.php llama the_content(): " . (strpos($part_content,'the_content')!==false?'SI':'NO ❌') . "\n";
            }
        }
    }
}

echo "\n[DONE]\n";
