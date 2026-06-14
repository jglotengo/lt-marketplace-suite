<?php
/**
 * LTMS Panel Vendedor Diagnostico
 * URL: /ltms-panel-diag.php?t=panel2026
 */
if (!hash_equals('panel2026', $_GET['t'] ?? '')) { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/wp-load.php';

$plugin = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';

echo "=== PANEL VENDEDOR DIAGNOSTICO ===\n\n";

// 1. Opciones de paginas instaladas
$installed = get_option('ltms_installed_pages', []);
echo "1) ltms_installed_pages:\n";
foreach ($installed as $k => $v) echo "   $k => $v\n";

// 2. Page ID de /panel-vendedor/
$page_id = url_to_postid(home_url('/panel-vendedor/'));
echo "\n2) page_id de /panel-vendedor/: $page_id\n";
if ($page_id) {
    $post = get_post($page_id);
    echo "   post_name (slug): " . $post->post_name . "\n";
    echo "   post_status: " . $post->post_status . "\n";
    echo "   post_content snippet: " . substr($post->post_content, 0, 100) . "\n";
    echo "   has shortcode ltms_vendor_dashboard: " . (has_shortcode($post->post_content, 'ltms_vendor_dashboard') ? 'SI' : 'NO') . "\n";
}

// 3. Dashboard key en ltms_installed_pages
$dash_id = (int)($installed['ltms-dashboard'] ?? 0);
echo "\n3) ID en ltms_installed_pages[ltms-dashboard]: $dash_id\n";
echo "   Coincide con page_id: " . ($dash_id === $page_id ? 'SI' : 'NO') . "\n";

// 4. Verificar archivo frontend-assets en disco
$assets_file = $plugin . '/includes/frontend/class-ltms-frontend-assets.php';
$content = file_get_contents($assets_file);
echo "\n4) class-ltms-frontend-assets.php\n";
echo "   Tamanio: " . strlen($content) . " bytes\n";
echo "   Tiene M-56b slug fallback: " . (strpos($content, 'M-56b') !== false ? 'SI' : 'NO') . "\n";
echo "   Tiene panel-vendedor slug: " . (strpos($content, 'panel-vendedor') !== false ? 'SI' : 'NO') . "\n";

// 5. Roles del vendedor actual
$user = wp_get_current_user();
echo "\n5) Usuario actual: " . $user->user_login . " (ID: " . $user->ID . ")\n";
echo "   Roles: " . implode(', ', $user->roles) . "\n";
if ($user->ID) {
    $is_vendor = function_exists('LTMS_Utils::is_ltms_vendor') ? LTMS_Utils::is_ltms_vendor($user->ID) : 'N/A';
    echo "   is_ltms_vendor: " . (is_string($is_vendor) ? $is_vendor : ($is_vendor ? 'SI' : 'NO')) . "\n";
}

// 6. Listar vendedores
$vendors = get_users(['role__in' => ['ltms_vendor', 'seller', 'vendor', 'shop_manager'], 'number' => 10]);
echo "\n6) Usuarios con rol vendor:\n";
foreach ($vendors as $v) echo "   ID=" . $v->ID . " login=" . $v->user_login . " roles=" . implode(',', $v->roles) . "\n";

// 7. Ver si ltms_vendor_dashboard shortcode existe en alguna pagina
$pages_with_sc = get_posts(['post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish']);
echo "\n7) Paginas con shortcode ltms_vendor_dashboard:\n";
foreach ($pages_with_sc as $p) {
    if (has_shortcode($p->post_content, 'ltms_vendor_dashboard')) {
        echo "   ID=" . $p->ID . " slug=" . $p->post_name . "\n";
    }
}
echo "   (Si esta vacio, la pagina usa Elementor o no tiene shortcode)\n";

echo "\n=== FIN ===\n";
