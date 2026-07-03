<?php
require('/home/customer/www/lo-tengo.com.co/public_html/wp-load.php');
global $wpdb;

// Obtener raw desde DB directamente (sin WP cache)
$raw = $wpdb->get_var("SELECT meta_value FROM bkr_postmeta WHERE post_id = 13599 AND meta_key = '_elementor_data' LIMIT 1");

echo "RAW length: " . strlen($raw) . "\n";
echo "Is gzipped: " . (substr($raw,0,2) === "\x1f\x8b" ? 'YES' : 'NO') . "\n";

// Intentar descomprimir
$unzipped = @gzuncompress($raw);
if ($unzipped === false) {
    $unzipped = @gzinflate($raw);
}
if ($unzipped === false) {
    $unzipped = @gzdecode($raw);
}

if ($unzipped !== false) {
    echo "Unzipped length: " . strlen($unzipped) . "\n";
    $pos = strpos($unzipped, '95%');
    echo "95% pos: " . ($pos !== false ? $pos : 'NO') . "\n";
    $data = $unzipped;
} else {
    echo "Not compressed, using raw\n";
    $data = $raw;
}

// Buscar en el JSON decodificado
$arr = json_decode($data, true);
$flat = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "JSON decoded length: " . strlen($flat) . "\n";

foreach(['95%','recibes','venta','95'] as $t) {
    $p = strpos($flat, $t);
    echo "'$t': " . ($p !== false ? "pos $p => " . substr($flat, max(0,$p-80), 160) : 'NO') . "\n";
}
