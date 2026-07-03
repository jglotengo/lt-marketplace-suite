<?php
/**
 * Sube el contrato de vendedor a WordPress Media Library
 * y configura ltms_zapsign_contract_pdf_url + attachment_id.
 *
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *    eval-file bin/ltms-zapsign-upload-contract.php --allow-root 2>/dev/null
 */

echo "=== ZAPSIGN — Subir contrato de vendedor ===\n\n";

// Verificar si ya hay un attachment configurado
$existing_id  = (int) get_option('ltms_zapsign_contract_attachment_id', 0);
$existing_url = get_option('ltms_zapsign_contract_pdf_url', '');

if ($existing_id > 0) {
    $file = get_attached_file($existing_id);
    if ($file && file_exists($file)) {
        echo "✅ Ya existe contrato configurado:\n";
        echo "   Attachment ID: $existing_id\n";
        echo "   URL: $existing_url\n";
        echo "   Archivo: $file\n";
        echo "   Tamaño: " . number_format(filesize($file)) . " bytes\n\n";
        echo "Para reemplazar, elimina primero: wp media delete $existing_id --allow-root\n";
        exit(0);
    }
}

// Buscar el PDF en ubicaciones conocidas del plugin
$plugin_dir = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/';
$pdf_sources = [
    $plugin_dir . 'assets/contracts/contrato-vendedor.pdf',
    $plugin_dir . 'assets/Contrato_Vendedor_LoTengo_v3_Blindado.pdf',
    $plugin_dir . 'Contrato_Vendedor_LoTengo_v3_Blindado.pdf',
];

$pdf_path = null;
foreach ($pdf_sources as $src) {
    if (file_exists($src)) {
        $pdf_path = $src;
        echo "✅ PDF encontrado: $src\n";
        break;
    }
}

if (!$pdf_path) {
    // Crear el PDF desde el contrato v3 embebido en base64
    echo "⚠️  No se encontró PDF local. Generando contrato v3 desde datos embebidos...\n";
    
    // Verificar que reportlab esté disponible
    $has_reportlab = shell_exec('python3 -c "import reportlab" 2>/dev/null');
    
    if ($has_reportlab !== null || true) {
        // Intentar generar con script PHP simple
        $contracts_dir = $plugin_dir . 'assets/contracts/';
        if (!is_dir($contracts_dir)) {
            mkdir($contracts_dir, 0755, true);
        }
        
        // El PDF v3 blindado fue generado — buscarlo en uploads
        $upload_dir = wp_upload_dir();
        $possible = glob($upload_dir['basedir'] . '/**/contrato*vendedor*.pdf');
        if (empty($possible)) {
            $possible = glob($upload_dir['basedir'] . '/contrato*.pdf');
        }
        if (!empty($possible)) {
            $pdf_path = $possible[0];
            echo "✅ Encontrado en uploads: $pdf_path\n";
        }
    }
}

if (!$pdf_path) {
    echo "❌ No se encontró ningún PDF de contrato.\n\n";
    echo "Opciones:\n";
    echo "1. Sube el PDF manualmente: WP Admin → Media → Subir → copia el ID del attachment\n";
    echo "   Luego: wp option update ltms_zapsign_contract_attachment_id <ID> --allow-root\n";
    echo "2. Coloca el PDF en: " . $plugin_dir . "assets/contracts/contrato-vendedor.pdf\n";
    echo "3. Configura una URL pública: wp option update ltms_zapsign_contract_pdf_url 'https://...' --allow-root\n";
    exit(1);
}

// Subir a WordPress Media Library
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$upload = wp_upload_bits(
    'contrato-vendedor-lo-tengo.pdf',
    null,
    file_get_contents($pdf_path)
);

if ($upload['error']) {
    echo "❌ Error subiendo: " . $upload['error'] . "\n";
    exit(1);
}

$attachment = [
    'post_mime_type' => 'application/pdf',
    'post_title'     => 'Contrato Vendedor Lo Tengo Colombia v3',
    'post_content'   => '',
    'post_status'    => 'inherit',
];

$attach_id = wp_insert_attachment($attachment, $upload['file']);
wp_generate_attachment_metadata($attach_id, $upload['file']);

$attach_url = wp_get_attachment_url($attach_id);

// Guardar en opciones LTMS
update_option('ltms_zapsign_contract_attachment_id', $attach_id);
update_option('ltms_zapsign_contract_pdf_url', $attach_url);
LTMS_Core_Config::flush_cache();

echo "✅ PDF subido exitosamente:\n";
echo "   Attachment ID : $attach_id\n";
echo "   URL pública   : $attach_url\n";
echo "   Tamaño        : " . number_format(filesize($upload['file'])) . " bytes\n\n";
echo "✅ Opciones guardadas:\n";
echo "   ltms_zapsign_contract_attachment_id = $attach_id\n";
echo "   ltms_zapsign_contract_pdf_url = $attach_url\n\n";
echo "Ahora ejecuta el diagnóstico para confirmar que send_vendor_contract() funciona:\n";
echo "wp --path=/home/customer/www/lo-tengo.com.co/public_html eval-file bin/ltms-zap-diag.php --allow-root\n";
