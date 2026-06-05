<?php
/**
 * LTMS HF-08 — Diagnóstico completo del hero slider y la imagen "Con el apoyo de"
 */
echo "=== HF-08 Diagnóstico ===" . PHP_EOL . PHP_EOL;

// 1. ¿El asset JS/CSS está encolado?
$js_handle  = 'ltms-homepage-fixes';
$css_handle = 'ltms-homepage-fixes';
echo "1. Verificando encolado de assets..." . PHP_EOL;

// Simulate frontend context
global $wp_scripts, $wp_styles;
// Check option/transient for frontend script registration
$plugin_url = plugins_url('', dirname(__DIR__) . '/lt-marketplace-suite.php');
$js_url  = $plugin_url . '/assets/js/ltms-homepage-fixes.js';
$css_url = $plugin_url . '/assets/css/ltms-homepage-fixes.css';
echo "  JS  URL: $js_url" . PHP_EOL;
echo "  CSS URL: $css_url" . PHP_EOL;

// Check file exists on disk
$plugin_dir = WP_PLUGIN_DIR . '/lt-marketplace-suite';
$js_file  = $plugin_dir . '/assets/js/ltms-homepage-fixes.js';
$css_file = $plugin_dir . '/assets/css/ltms-homepage-fixes.css';
$img_file = $plugin_dir . '/assets/img/con-el-apoyo.png';
echo "  JS  exists: " . (file_exists($js_file) ? '✅ YES (' . filesize($js_file) . ' bytes)' : '❌ NO') . PHP_EOL;
echo "  CSS exists: " . (file_exists($css_file) ? '✅ YES (' . filesize($css_file) . ' bytes)' : '❌ NO') . PHP_EOL;
echo "  IMG exists: " . (file_exists($img_file) ? '✅ YES (' . filesize($img_file) . ' bytes)' : '❌ NO') . PHP_EOL;
echo PHP_EOL;

// 2. Check what class enqueues ltms-homepage-fixes
echo "2. Buscando quien encola ltms-homepage-fixes..." . PHP_EOL;
$assets_file = $plugin_dir . '/includes/frontend/class-ltms-frontend-assets.php';
if (file_exists($assets_file)) {
    $content = file_get_contents($assets_file);
    $idx = strpos($content, 'homepage-fixes');
    if ($idx !== false) {
        echo "  ✅ Encontrado en class-ltms-frontend-assets.php:" . PHP_EOL;
        echo "  " . substr($content, max(0,$idx-100), 400) . PHP_EOL;
    } else {
        echo "  ❌ 'homepage-fixes' NO está en frontend-assets.php" . PHP_EOL;
        // Search in all PHP files
        $grep_cmd = "grep -r 'homepage-fixes' " . escapeshellarg($plugin_dir) . " --include='*.php' -l 2>/dev/null";
        $result = shell_exec($grep_cmd);
        echo "  Grep result: " . ($result ?: 'NADA encontrado') . PHP_EOL;
    }
} else {
    echo "  ❌ frontend-assets.php no existe" . PHP_EOL;
}
echo PHP_EOL;

// 3. Check ltms_assets_url in wp_localize_script
echo "3. Verificando ltms_assets_url en wp_localize_script..." . PHP_EOL;
$ltmsData_hook = "grep -r 'ltmsData\|ltms_assets_url\|con-el-apoyo' " . escapeshellarg($plugin_dir) . " --include='*.php' -l 2>/dev/null";
$r3 = shell_exec($ltmsData_hook);
echo "  Files with ltmsData/con-el-apoyo: " . ($r3 ?: 'NINGUNO') . PHP_EOL;
echo PHP_EOL;

// 4. Check the Elementor page data for homepage (ID:30)
echo "4. Elementor data del homepage (ID:30)..." . PHP_EOL;
$el_data = get_post_meta(30, '_elementor_data', true);
if ($el_data) {
    $decoded = json_decode($el_data, true);
    // Find slider widgets
    $find_widgets = function($elements, $depth=0) use (&$find_widgets) {
        foreach ((array)$elements as $el) {
            $type = $el['elType'] ?? '';
            $wtype = $el['widgetType'] ?? '';
            $id = $el['id'] ?? '';
            if (in_array($wtype, ['slides', 'carousel', 'image-carousel', 'media-carousel'])) {
                echo str_repeat('  ', $depth) . "✅ SLIDER WIDGET: widgetType=$wtype, id=$id" . PHP_EOL;
                // Print CSS classes
                $css = $el['settings']['css_classes'] ?? '';
                echo str_repeat('  ', $depth) . "   css_classes='$css'" . PHP_EOL;
            }
            if ($id) {
                echo str_repeat('  ', $depth) . "[$type/$wtype] id=$id" . PHP_EOL;
            }
            $find_widgets($el['elements'] ?? [], $depth+1);
        }
    };
    $find_widgets($decoded);
} else {
    echo "  No Elementor data for ID:30" . PHP_EOL;
    // Check if page exists
    $page = get_post(30);
    echo "  Page: " . ($page ? $page->post_title . ' (' . $page->post_status . ')' : 'NOT FOUND') . PHP_EOL;
}
echo PHP_EOL;

// 5. Conclusión
echo "5. Conclusión / Fix recomendado..." . PHP_EOL;
if (!file_exists($js_file)) {
    echo "  ❌ CRITICAL: JS no está en disco — hacer git pull" . PHP_EOL;
} elseif (strpos(file_get_contents($js_file), 'injectSupportInHero') === false) {
    echo "  ❌ CRITICAL: JS en disco no tiene injectSupportInHero" . PHP_EOL;
} else {
    echo "  ✅ JS tiene injectSupportInHero" . PHP_EOL;
}
if (!file_exists($img_file)) {
    echo "  ❌ CRITICAL: Imagen con-el-apoyo.png no está en disco" . PHP_EOL;
} else {
    echo "  ✅ Imagen existe en disco" . PHP_EOL;
}

echo PHP_EOL . "=== END ===" . PHP_EOL;
