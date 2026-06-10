<?php
require_once('/home/customer/www/lo-tengo.com.co/public_html/wp-load.php');
global $wpdb;
$post_id = 13599;
$raw = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
    $post_id
));

// Decodificar, modificar, re-encodear
$decoded = json_decode($raw, true);
$json_str = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Buscar y reemplazar el texto completo del widget
$patterns = [
    'Tú recibes el <strong>95%<\/strong> de cada venta.',
    'Tú recibes el <strong>95%</strong> de cada venta.',
    'T\u00fa recibes el <strong>95%<\/strong> de cada venta.',
    'Tú recibes el 95% de cada venta.',
];

$replaced = false;
foreach ($patterns as $p) {
    if (strpos($json_str, $p) !== false) {
        $json_str = str_replace($p, '', $json_str);
        echo "REPLACED: $p\n";
        $replaced = true;
        break;
    }
}

if (!$replaced) {
    // Buscar cualquier cosa con 95
    preg_match_all('/[^"]{0,50}95[^"]{0,50}/', $json_str, $m);
    echo "Contextos con 95:\n";
    foreach($m[0] as $match) echo "  >> $match\n";
}

if ($replaced) {
    $result = $wpdb->update(
        $wpdb->postmeta,
        ['meta_value' => $json_str],
        ['post_id' => $post_id, 'meta_key' => '_elementor_data']
    );
    // Borrar CSS cacheado
    $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => '_elementor_css']);
    echo "Updated: $result rows\n";
}
