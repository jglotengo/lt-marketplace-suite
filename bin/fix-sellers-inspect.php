<?php
require_once('/home/customer/www/lo-tengo.com.co/public_html/wp-load.php');
global $wpdb;
$post_id = 13599;
$raw = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
    $post_id
));
$pos = strpos($raw, '95%');
if ($pos !== false) {
    echo "FOUND:" . substr($raw, max(0,$pos-300), 600) . ":END\n";
} else {
    echo "95% not found in _elementor_data\n";
    // Intentar con la tabla de revisiones
    $rev = $wpdb->get_var("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = 13599 AND meta_key = '_elementor_data' LIMIT 1");
    echo "Length: " . strlen($rev) . "\n";
    foreach(['recibes','95','venta','Reg'] as $t) {
        echo $t . ": " . (strpos($rev,$t) !== false ? strpos($rev,$t) : 'NO') . "\n";
    }
}
