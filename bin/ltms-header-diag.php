<?php
/**
 * Diagnóstico: estructura HTML del header de Hello Elementor
 * Ejecutar: wp eval-file bin/ltms-header-diag.php --path=...
 */
if ( ! defined('ABSPATH') ) {
    $wp_load = dirname(__DIR__, 4) . '/wp-load.php';
    if ( file_exists($wp_load) ) require_once $wp_load;
    else die("wp-load.php no encontrado\n");
}

// Obtener el contenido del header del tema
ob_start();
get_header();
$header_html = ob_get_clean();

// Extraer solo el header (primeros 8000 chars para no inundar)
echo "=== HEADER HTML (primeros 6000 chars) ===\n";
echo substr(strip_tags($header_html, '<header><nav><ul><li><a><div><span><button>'), 0, 6000);
echo "\n\n=== CLASES CSS presentes en header ===\n";
preg_match_all('/class="([^"]+)"/', $header_html, $m);
$classes = [];
foreach ($m[1] as $cls) {
    foreach (explode(' ', $cls) as $c) {
        $c = trim($c);
        if ($c && !in_array($c, $classes)) $classes[] = $c;
    }
}
sort($classes);
echo implode("\n", $classes);
echo "\n\n=== LINKS en el header ===\n";
preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>([^<]*)</', $header_html, $lm);
for ($i = 0; $i < count($lm[1]); $i++) {
    echo $lm[1][$i] . ' → ' . trim($lm[2][$i]) . "\n";
}
