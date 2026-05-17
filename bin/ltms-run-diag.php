<?php
if ( ! isset( $_GET['key'] ) || $_GET['key'] !== 'ltms-diag-2026' ) { http_response_code(403); die('Forbidden'); }
define( 'ABSPATH', dirname(__DIR__, 4) . '/' );
require dirname(__DIR__, 3) . '/wp-load.php';
$c = new LTMS_Api_Zapsign();
echo 'api_url: ' . (new ReflectionProperty($c, 'api_url'))->getValue($c) . "\n";
echo 'sandbox: ' . ($c->is_sandbox() ? 'yes' : 'no') . "\n";
$pdf = get_option('ltms_zapsign_contract_pdf_url', 'https://www.w3.org/WAI/WCAG21/Techniques/pdf/pdfs/table.pdf');
echo 'pdf_url: ' . $pdf . "\n";
$vid = (int) email_exists('test-seller@prueba.com');
echo 'vendor_id: ' . $vid . "\n";
if ($vid) {
    try { $r = $c->send_vendor_contract($vid, $pdf); print_r($r); }
    catch(Throwable $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }
}
