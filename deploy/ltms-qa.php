<?php
/**
 * LTMS KYC QA Script — corre diagnósticos desde public_html
 * Acceso: /ltms-qa.php?t=ltms_qa_2026
 */
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') {
    http_response_code(403); echo "Forbidden
"; exit;
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

$wp = __DIR__ . '/wp-load.php';
if (!file_exists($wp)) { echo "ERROR: wp-load.php not found
"; exit(1); }
require_once $wp;

echo "=== LTMS KYC QA DIAGNOSTIC ===
";
echo "Time: " . current_time('Y-m-d H:i:s') . "
";
echo "PHP: " . PHP_VERSION . "

";

global $wpdb;
$tbl = $wpdb->prefix . 'lt_vendor_kyc';

// 1. Tabla DB
echo "=== 1. DB TABLE: {$tbl} ===
";
$cols = $wpdb->get_results("DESCRIBE `{$tbl}`");
if ($cols) { foreach ($cols as $c) echo "  {$c->Field} ({$c->Type})
"; }
else { echo "  ERROR: tabla no existe
"; }
$cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
echo "  Total registros: {$cnt}
";
$rows = $wpdb->get_results("SELECT id,vendor_id,status,document_type,country_code,submitted_at FROM `{$tbl}` ORDER BY id DESC LIMIT 5");
foreach ($rows as $r) {
    echo "  ID={$r->id} vendor={$r->vendor_id} status={$r->status} type={$r->document_type} cc={$r->country_code} at={$r->submitted_at}
";
}
echo "
";

// 2. Config B2
echo "=== 2. BACKBLAZE CONFIG ===
";
foreach (['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_kyc_bucket','ltms_backblaze_default_bucket','ltms_backblaze_private_bucket','ltms_backblaze_app_key'] as $k) {
    $v = get_option($k,'');
    if (empty($v)) { echo "  {$k}: (vacío ⚠)
"; continue; }
    $masked = (strpos($k,'key')!==false || strpos($k,'app')!==false) ? substr($v,0,8).'...[masked]' : $v;
    echo "  {$k}: {$masked}
";
}
echo "
";

// 3. Test upload B2
echo "=== 3. B2 UPLOAD TEST (lotengo-kyc-docs) ===
";
if (!class_exists('LTMS_Api_Backblaze')) {
    echo "  ✗ LTMS_Api_Backblaze not loaded

";
} else {
    try {
        $b2  = new LTMS_Api_Backblaze();
        $bkt = get_option('ltms_backblaze_kyc_bucket','lotengo-kyc-docs');
        $key = 'kyc/qa-test/'.time().'_qa_1px.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $res = $b2->upload_file($bkt, $key, $png, 'image/png', ['vendor-id'=>'qa','doc-type'=>'qa','upload-date'=>date('Y-m-d')]);
        echo "  ✓ UPLOAD OK
";
        echo "  Bucket: {$res['Bucket']}
";
        echo "  Key:    {$res['Key']}
";
        echo "  ETag:   {$res['ETag']}
";
        
        // Presigned URL
        try {
            $url = $b2->get_presigned_url($bkt, $key, 3600);
            echo "  ✓ Presigned URL generada
";
            echo "  URL: " . substr($url,0,90) . "...
";
            echo "  Tiene X-Amz-Signature: " . (strpos($url,'X-Amz-Signature')!==false?'YES ✓':'NO ✗') . "
";
        } catch (Throwable $e2) {
            echo "  ✗ Presigned ERR: {$e2->getMessage()}
";
        }
        
        // Cleanup
        try {
            $b2->delete_file($bkt, $key);
            echo "  ✓ Cleanup OK (archivo de prueba eliminado)
";
        } catch (Throwable $e3) {
            echo "  ! Cleanup ERR (no crítico): {$e3->getMessage()}
";
        }
        
    } catch (Throwable $e) {
        echo "  ✗ UPLOAD ERR: {$e->getMessage()}
";
        // Extra debug
        $ep = get_option('ltms_backblaze_endpoint','');
        echo "  Endpoint configurado: " . ($ep ?: '(VACÍO — aquí está el problema)') . "
";
    }
}
echo "
";

// 4. User meta KYC
echo "=== 4. KYC USER META ===
";
$vendors = get_users(['meta_key'=>'ltms_kyc_status','number'=>10,'fields'=>'all']);
if ($vendors) {
    foreach ($vendors as $u) {
        $status    = get_user_meta($u->ID, 'ltms_kyc_status', true);
        $banco_key = get_user_meta($u->ID, 'ltms_kyc_file_banco', true);
        $bank_name = get_user_meta($u->ID, 'ltms_kyc_bank_name', true) ?: '(none)';
        $bank_acct = get_user_meta($u->ID, 'ltms_kyc_bank_account', true) ?: '(none)';
        $consent   = get_user_meta($u->ID, 'ltms_kyc_consent', true) ?: '0';
        $consent_d = get_user_meta($u->ID, 'ltms_kyc_consent_date', true) ?: '(none)';
        $cedula_k  = get_user_meta($u->ID, 'ltms_kyc_file_path', true) ?: 
                     get_user_meta($u->ID, 'ltms_kyc_file', true) ?: '(none)';
        
        echo "  --- vendor_id={$u->ID} ({$u->user_login}) ---
";
        echo "    kyc_status:    {$status}
";
        echo "    cedula_key:    " . ($cedula_k !== '(none)' ? substr($cedula_k,0,50).'...' : '(none)') . "
";
        echo "    banco_key:     " . ($banco_key ? substr($banco_key,0,50).'...' : '(none ⚠)') . "
";
        echo "    bank_name:     {$bank_name}
";
        echo "    bank_account:  " . ($bank_acct !== '(none)' ? '****'.substr($bank_acct,-4) : '(none)') . "
";
        echo "    consent:       {$consent} ({$consent_d})
";
    }
} else {
    echo "  (sin registros KYC aún — tabla vacía o no hay vendors)
";
}
echo "
";

// 5. Verificar file_path en tabla KYC apunta a B2
echo "=== 5. VERIFY FILE PATHS EN DB ===
";
$kyc_rows = $wpdb->get_results("SELECT id, vendor_id, file_path FROM `{$tbl}` WHERE file_path != '' ORDER BY id DESC LIMIT 5");
if ($kyc_rows) {
    foreach ($kyc_rows as $r) {
        $is_b2 = (strpos($r->file_path, 'kyc/') === 0 || strpos($r->file_path, 'backblaze') !== false);
        echo "  ID={$r->id} vendor={$r->vendor_id}
";
        echo "    file_path: {$r->file_path}
";
        echo "    Es ruta B2: " . ($is_b2 ? 'YES ✓' : 'NO ✗ (revisar)') . "
";
    }
} else {
    echo "  (sin file_paths guardados aún)
";
}

echo "
=== QA COMPLETE ===
";
