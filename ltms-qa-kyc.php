<?php
/**
 * LTMS KYC QA Diagnostic
 * QA: verifica almacenamiento correcto de documentos KYC
 */

$token = $_GET['t'] ?? '';
if ($token !== 'ltms_qa_kyc_2026') {
    http_response_code(403); echo "Forbidden
"; exit;
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

// Bootstrap WordPress
$wp_load = dirname(__FILE__) . '/wp-load.php';
if (!file_exists($wp_load)) {
    echo "ERROR: wp-load.php not found at " . $wp_load . "
";
    exit(1);
}
require_once $wp_load;

echo "=== LTMS KYC QA DIAGNOSTIC ===
";
echo "Time: " . current_time('Y-m-d H:i:s') . "
";
echo "PHP: " . PHP_VERSION . "

";

// ── 1. Verificar tabla lt_vendor_kyc ─────────────────────────────────────────
echo "=== 1. DB TABLE: lt_vendor_kyc ===
";
global $wpdb;
$table = $wpdb->prefix . 'lt_vendor_kyc';
$cols = $wpdb->get_results("DESCRIBE `{$table}`");
if ($cols) {
    foreach ($cols as $col) {
        echo "  {$col->Field} ({$col->Type})
";
    }
} else {
    echo "  ERROR: tabla no existe o no accesible
";
}

$count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
echo "  Total registros: {$count}
";

// Show recent records (masked)
$rows = $wpdb->get_results("SELECT id, vendor_id, status, document_type, submitted_at, country_code FROM `{$table}` ORDER BY id DESC LIMIT 5");
if ($rows) {
    echo "  Últimos registros:
";
    foreach ($rows as $r) {
        echo "    ID={$r->id} vendor={$r->vendor_id} status={$r->status} type={$r->document_type} country={$r->country_code} at={$r->submitted_at}
";
    }
}
echo "
";

// ── 2. Verificar configuración Backblaze ─────────────────────────────────────
echo "=== 2. BACKBLAZE CONFIG ===
";
$keys = ['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_app_key','ltms_backblaze_default_bucket','ltms_backblaze_kyc_bucket','ltms_backblaze_private_bucket','ltms_backblaze_bucket_name'];
foreach ($keys as $k) {
    $v = get_option($k, '');
    if (empty($v)) { echo "  {$k}: (vacío)
"; continue; }
    // Mask sensitive values
    if (strpos($k, 'key') !== false || strpos($k, 'app') !== false) {
        echo "  {$k}: " . substr($v, 0, 8) . "...[masked]
";
    } else {
        echo "  {$k}: {$v}
";
    }
}
echo "
";

// ── 3. Test upload a Backblaze B2 (archivo de prueba pequeño) ─────────────────
echo "=== 3. TEST UPLOAD B2 (lotengo-kyc-docs) ===
";
if (!class_exists('LTMS_Api_Backblaze')) {
    echo "  ERROR: class LTMS_Api_Backblaze no cargada
";
} else {
    try {
        $b2 = new LTMS_Api_Backblaze();
        $bucket = get_option('ltms_backblaze_kyc_bucket', 'lotengo-kyc-docs');
        $key    = 'kyc/qa-test/' . time() . '_qa_1px.png';
        // 1px PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $result = $b2->upload_file($bucket, $key, $png, 'image/png', ['vendor-id'=>'qa-test','doc-type'=>'qa']);
        echo "  UPLOAD OK
";
        echo "  Bucket: {$result['Bucket']}
";
        echo "  Key: {$result['Key']}
";
        echo "  ETag: {$result['ETag']}
";
        echo "  Location: {$result['Location']}

";
        
        // ── 4. Test presigned URL ────────────────────────────────────────────
        echo "=== 4. TEST PRESIGNED URL ===
";
        try {
            $url = $b2->get_presigned_url($bucket, $key, 3600);
            echo "  URL generada: " . substr($url, 0, 80) . "...
";
            echo "  Contiene X-Amz-Signature: " . (strpos($url,'X-Amz-Signature')!==false?'YES':'NO') . "

";
        } catch (Throwable $e2) {
            echo "  ERROR presigned: " . $e2->getMessage() . "

";
        }
        
        // ── 5. Cleanup - delete test file ────────────────────────────────────
        echo "=== 5. CLEANUP ===
";
        try {
            $b2->delete_file($bucket, $key);
            echo "  Test file deleted OK
";
        } catch (Throwable $e3) {
            echo "  Delete error (non-critical): " . $e3->getMessage() . "
";
        }
        
    } catch (Throwable $e) {
        echo "  UPLOAD ERROR: " . $e->getMessage() . "
";
    }
}
echo "
";

// ── 6. Verificar user_meta KYC para vendors existentes ────────────────────────
echo "=== 6. KYC USER META (vendors con KYC) ===
";
$vendors_with_kyc = get_users(['meta_key'=>'ltms_kyc_status','number'=>5]);
if ($vendors_with_kyc) {
    foreach ($vendors_with_kyc as $u) {
        $status   = get_user_meta($u->ID, 'ltms_kyc_status', true);
        $has_file = get_user_meta($u->ID, 'ltms_kyc_file_banco', true) ? 'YES' : 'NO';
        $bank     = get_user_meta($u->ID, 'ltms_kyc_bank_name', true);
        $consent  = get_user_meta($u->ID, 'ltms_kyc_consent', true);
        echo "  vendor_id={$u->ID} kyc_status={$status} has_banco={$has_file} bank={$bank} consent={$consent}
";
    }
} else {
    echo "  (no vendors con KYC aún)
";
}
echo "
";

echo "=== QA COMPLETE ===
";
