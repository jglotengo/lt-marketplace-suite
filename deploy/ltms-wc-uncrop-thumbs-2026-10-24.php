<?php
/**
 * LTMS — Miniaturas de producto SIN recorte a nivel de archivo (WC)
 *
 * PROD-IMG-FIT-2026-10-24 (fase 2, decidido por el operador): la auditoría
 * de imágenes de producto corrigió la capa CSS (contain + letterbox en los
 * 16 puntos de render), pero woocommerce_thumbnail se GENERA recortado a
 * cuadrado 300x300 (WC default '1:1' — verificado: un original 1200x1137
 * producía un archivo 300x300). Para originales no cuadrados el contenido
 * ya estaba perdido EN el archivo: un producto con foto alta (p.ej.
 * 800x1500) perdía ~70% de la imagen en la miniatura que usan TODAS las
 * cards del marketplace.
 *
 * Este script pone el recorte de miniaturas de WC en 'uncropped'
 * (archivos proporcionales de 300px de ancho — semántica verificada en
 * wc-core-functions.php::wc_get_image_size: 'uncropped' => height='',
 * crop=0). La capa CSS contain ya adapta cualquier proporción con
 * letterbox, así que el resultado visual es el producto COMPLETO.
 *
 * La REGENERACIÓN de los archivos existentes se hace con wp-cli
 * (batalla-probada) DESPUÉS de correr este script:
 *
 *   cd /home/customer/www/lo-tengo.com.co/public_html
 *   wp media regenerate --image_size=woocommerce_thumbnail --yes --allow-root
 *
 * (~3,200 adjuntos / ~1,800 productos; los archivos antiguos -300x300
 * quedan reemplazados; los HTML en cache se purgan con wp cache flush — el
 * deploy ya lo hace. Las URLs nuevas (-300xNNN) se sirven frescas.)
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-wc-uncrop-thumbs-2026-10-24.php
 *
 * Idempotente — seguro correr varias veces: el update_option es estable y
 * el reporte no toca datos.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

echo "=== LTMS — Miniaturas WC sin recorte (PROD-IMG-FIT-2026-10-24 fase 2) ===\n";

$before = get_option( 'woocommerce_thumbnail_cropping', '(unset → default 1:1)' );
update_option( 'woocommerce_thumbnail_cropping', 'uncropped' );
// Ancho se conserva (default WC 300px).
$after = get_option( 'woocommerce_thumbnail_cropping' );
echo "woocommerce_thumbnail_cropping: '{$before}' → '{$after}'\n";
echo "woocommerce_thumbnail_image_width: " . get_option( 'woocommerce_thumbnail_image_width', '300 (default)' ) . "\n";

// Limpiar el cache del tamaño que WC guarda en el object cache.
wp_cache_flush();
echo "object cache: flushed (wc_get_image_size relee la opción)\n";

// Muestra informativa: cómo quedaría el tamaño registrado.
if ( function_exists( 'wc_get_image_size' ) ) {
	$size = wc_get_image_size( 'woocommerce_thumbnail' );
	echo 'woocommerce_thumbnail registrado: ' . json_encode( $size ) . ' (crop=0 → proporcional)' . "\n";
}

echo "SIGUIENTE PASO (una sola vez): wp media regenerate --image_size=woocommerce_thumbnail --yes --allow-root\n";
echo "=== Fin ===\n";
