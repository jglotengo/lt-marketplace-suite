<?php
/**
 * LTMS - Diagnose slider plugin and upload sponsor banner
 * Run: wp eval-file /full/path/to/this.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname(__DIR__, 4) . '/' );
}

echo "=== LTMS Slider Diagnostics ===" . PHP_EOL;
echo PHP_EOL;

// 1. Active theme
$theme = wp_get_theme();
echo "Theme: " . $theme->get('Name') . " v" . $theme->get('Version') . PHP_EOL;
echo "Template: " . $theme->get_template() . PHP_EOL;
echo "Theme dir: " . get_template_directory() . PHP_EOL;
echo PHP_EOL;

// 2. Active plugins — find slider plugins
$active = get_option('active_plugins', []);
$slider_plugins = [];
foreach ($active as $p) {
    foreach (['slider', 'carousel', 'revslider', 'revolution', 'smart-slider', 'LayerSlider', 'masterslider', 'swiper', 'owl', 'splide', 'metaslider'] as $kw) {
        if (stripos($p, $kw) !== false) {
            $slider_plugins[] = $p;
            break;
        }
    }
}
echo "Slider plugins found: " . (count($slider_plugins) ? implode(', ', $slider_plugins) : 'NONE') . PHP_EOL;
echo PHP_EOL;

// 3. Check for Revolution Slider
if (class_exists('RevSliderFront') || class_exists('RevSlider')) {
    echo "✅ Revolution Slider ACTIVE" . PHP_EOL;
    // List sliders
    if (class_exists('RevSliderSlider')) {
        $revSlider = new RevSliderSlider();
        $sliders = $revSlider->getAllSlidersPosts();
        foreach ($sliders as $s) {
            echo "  Rev Slider: " . $s->post_title . " (ID:" . $s->ID . ")" . PHP_EOL;
        }
    }
}

// 4. Check for MetaSlider
if (class_exists('MetaSliderPlugin') || function_exists('ms_plugin')) {
    echo "✅ MetaSlider ACTIVE" . PHP_EOL;
    $slideshow_ids = get_posts(['post_type' => 'ml-slider', 'numberposts' => 20, 'fields' => 'ids']);
    foreach ($slideshow_ids as $sid) {
        echo "  MetaSlider ID:" . $sid . " — " . get_the_title($sid) . PHP_EOL;
    }
}

// 5. Check for Smart Slider 3
if (class_exists('Nextend\SmartSlider3\Application\ApplicationSmartSlider3')) {
    echo "✅ Smart Slider 3 ACTIVE" . PHP_EOL;
}

// 6. Check WP options for slider data
$opt_keys = ['smart-slider3', 'rev_slider', 'metaslider', 'ml_slider', 'soliloquy', 'nivo'];
foreach ($opt_keys as $k) {
    $v = get_option($k);
    if ($v !== false) {
        echo "WP Option '$k' exists" . PHP_EOL;
    }
}

// 7. Check theme for slider shortcodes in homepage
$home_id = get_option('page_on_front');
if ($home_id) {
    $home = get_post($home_id);
    echo "Homepage: " . $home->post_title . " (ID:" . $home_id . ")" . PHP_EOL;
    echo "Content snippet: " . substr($home->post_content, 0, 500) . PHP_EOL;
} else {
    echo "No static homepage set (using blog/latest posts)" . PHP_EOL;
}

// 8. Check theme_mods for slider
$mods = get_theme_mods();
$slider_mods = [];
foreach ($mods as $k => $v) {
    if (stripos($k, 'slider') !== false || stripos($k, 'carousel') !== false || stripos($k, 'slide') !== false) {
        $slider_mods[$k] = is_array($v) ? json_encode($v) : (string)$v;
    }
}
if ($slider_mods) {
    echo PHP_EOL . "Theme mods with slider:" . PHP_EOL;
    foreach ($slider_mods as $k => $v) {
        echo "  $k = " . substr($v, 0, 200) . PHP_EOL;
    }
}

// 9. Check WooCommerce theme customizer options
$wc_slider = get_option('woocommerce_home_slider');
if ($wc_slider !== false) {
    echo "WC Home Slider option: " . json_encode($wc_slider) . PHP_EOL;
}

echo PHP_EOL . "=== END ===" . PHP_EOL;
