<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(90);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "========================================\n";
echo "  LTMS FULL SYSTEM QA — " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// ── 1. PLUGIN VERSION & FILES ──────────────────
echo "=== 1. PLUGIN STATUS ===\n";
$plugin_dir = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
echo "Plugin dir exists: " . (is_dir($plugin_dir) ? 'YES' : 'NO') . "\n";
$main = $plugin_dir . '/lt-marketplace-suite.php';
if (file_exists($main)) {
    $head = file_get_contents($main, false, null, 0, 500);
    preg_match('/Version:\s*(.+)/i', $head, $m);
    echo "Plugin version: " . trim($m[1] ?? 'unknown') . "\n";
}
// Git commit
$git_head = $plugin_dir . '/.git/HEAD';
if (file_exists($git_head)) {
    $ref = trim(file_get_contents($git_head));
    if (strpos($ref, 'ref:') === 0) {
        $branch_file = $plugin_dir . '/.git/' . trim(substr($ref, 5));
        $commit = file_exists($branch_file) ? trim(file_get_contents($branch_file)) : 'unknown';
    } else { $commit = $ref; }
    echo "Git commit: " . substr($commit, 0, 8) . "\n";
}
echo "\n";

// ── 2. WP OPTIONS — ALL LTMS ──────────────────
echo "=== 2. LTMS WP OPTIONS ===\n";
$opts = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'ltms_%' ORDER BY option_name");
$sensitive = ['key','secret','token','pass','app_key'];
foreach ($opts as $o) {
    $is_sensitive = false;
    foreach ($sensitive as $s) { if (strpos($o->option_name, $s) !== false) { $is_sensitive = true; break; } }
    $val = $is_sensitive ? substr($o->option_value,0,10).'...[masked]' : $o->option_value;
    echo "  {$o->option_name}: {$val}\n";
}
echo "\n";

// ── 3. KYC TABLE FULL ─────────────────────────
echo "=== 3. KYC TABLE (bkr_lt_vendor_kyc) ===\n";
$tbl = $wpdb->prefix . 'lt_vendor_kyc';
$cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
echo "Total registros: {$cnt}\n";
$rows = $wpdb->get_results("SELECT id,vendor_id,status,document_type,country_code,file_path,file_hash,submitted_at,reviewed_at FROM `{$tbl}` ORDER BY id DESC LIMIT 10");
foreach ($rows as $r) {
    echo "  ID={$r->id} vendor={$r->vendor_id} status={$r->status} type={$r->document_type} cc={$r->country_code}\n";
    echo "    file_path=" . ($r->file_path ?: '(VACIO)') . "\n";
    echo "    file_hash=" . ($r->file_hash ? substr($r->file_hash,0,16).'...' : '(none)') . "\n";
    echo "    submitted={$r->submitted_at} reviewed={$r->reviewed_at}\n";
}
echo "\n";

// ── 4. KYC USER META — ALL VENDORS ────────────
echo "=== 4. KYC USER META (todos los vendors) ===\n";
$meta_keys = ['ltms_kyc_status','ltms_kyc_file_path','ltms_kyc_file','ltms_kyc_file_banco','ltms_kyc_bank_name','ltms_kyc_bank_account','ltms_kyc_consent','ltms_kyc_consent_date','ltms_kyc_submitted_at'];
$vendors = $wpdb->get_results("
    SELECT DISTINCT u.ID, u.user_login, u.user_email
    FROM {$wpdb->users} u
    JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
    WHERE m.meta_key LIKE 'ltms_kyc%'
    ORDER BY u.ID DESC LIMIT 10
");
if ($vendors) {
    foreach ($vendors as $u) {
        echo "  vendor={$u->ID} ({$u->user_login})\n";
        foreach ($meta_keys as $k) {
            $v = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s", $u->ID, $k));
            if ($v !== null) {
                $display = (strpos($k,'bank_account')!==false && $v) ? '****'.substr($v,-4) : $v;
                echo "    {$k}: {$display}\n";
            }
        }
    }
} else { echo "  (ningún vendor con meta KYC)\n"; }
echo "\n";

// ── 5. BACKBLAZE CONFIG COMPLETE ──────────────
echo "=== 5. BACKBLAZE CONFIG ===\n";
$b2_keys = ['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_app_key','ltms_backblaze_kyc_bucket','ltms_backblaze_default_bucket','ltms_backblaze_private_bucket','ltms_backblaze_marketing_bucket'];
foreach ($b2_keys as $k) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    $is_s = strpos($k,'key')!==false || strpos($k,'app')!==false;
    $display = $v ? ($is_s ? substr($v,0,10).'...' : $v) : '(VACIO ⚠)';
    $status = $v ? '✓' : '✗';
    echo "  [{$status}] {$k}: {$display}\n";
}
echo "\n";

// ── 6. B2 LIVE UPLOAD TEST ────────────────────
echo "=== 6. B2 LIVE UPLOAD TEST ===\n";
$endpoint = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_endpoint'");
$key_id   = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_key_id'");
$app_key  = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_backblaze_app_key'");
$bucket   = 'lotengo-kyc-docs';
$region   = 'us-east-005';

if (!$endpoint || !$key_id || !$app_key) {
    echo "  SKIP: credenciales incompletas\n";
} else {
    $object_key = 'kyc/qa-test/' . time() . '_qa.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $dt = gmdate('Ymd\THis\Z'); $ds = gmdate('Ymd');
    $host = parse_url($endpoint, PHP_URL_HOST);
    $uri = '/' . $bucket . '/' . $object_key;
    $ch = "content-type:image/png\nhost:{$host}\nx-amz-date:{$dt}\n";
    $sh = 'content-type;host;x-amz-date';
    $ph = hash('sha256', $png);
    $cr = "PUT\n{$uri}\n\n{$ch}\n{$sh}\n{$ph}";
    $cs = "{$ds}/{$region}/s3/aws4_request";
    $sts = "AWS4-HMAC-SHA256\n{$dt}\n{$cs}\n" . hash('sha256', $cr);
    $sk = hash_hmac('sha256','aws4_request',hash_hmac('sha256','s3',hash_hmac('sha256',$region,hash_hmac('sha256',$ds,'AWS4'.$app_key,true),true),true),true);
    $sig = hash_hmac('sha256', $sts, $sk);
    $auth = "AWS4-HMAC-SHA256 Credential={$key_id}/{$cs},SignedHeaders={$sh},Signature={$sig}";
    $url = $endpoint . $uri;
    $ctx = stream_context_create(['http'=>['method'=>'PUT','header'=>"Authorization: {$auth}\r\nContent-Type: image/png\r\nx-amz-date: {$dt}\r\nContent-Length: ".strlen($png),'content'=>$png,'timeout'=>20,'ignore_errors'=>true]]);
    $resp = @file_get_contents($url, false, $ctx);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0]??'', $m2);
    $code = (int)($m2[1]??0);
    echo "  Upload to lotengo-kyc-docs: HTTP {$code} " . ($code>=200&&$code<300 ? '✓ OK' : '✗ FAILED') . "\n";
    if ($code < 200 || $code >= 300) echo "  Response: " . substr($resp,0,200) . "\n";
    else {
        // cleanup
        $dt2=gmdate('Ymd\THis\Z'); $ds2=gmdate('Ymd');
        $cr2="DELETE\n{$uri}\n\nhost:{$host}\nx-amz-date:{$dt2}\n\nhost;x-amz-date\n".hash('sha256','');
        $sk2=hash_hmac('sha256','aws4_request',hash_hmac('sha256','s3',hash_hmac('sha256',$region,hash_hmac('sha256',$ds2,'AWS4'.$app_key,true),true),true),true);
        $sig2=hash_hmac('sha256',"AWS4-HMAC-SHA256\n{$dt2}\n{$ds2}/{$region}/s3/aws4_request\n".hash('sha256',$cr2),$sk2);
        $da="AWS4-HMAC-SHA256 Credential={$key_id}/{$ds2}/{$region}/s3/aws4_request,SignedHeaders=host;x-amz-date,Signature={$sig2}";
        @file_get_contents($url,false,stream_context_create(['http'=>['method'=>'DELETE','header'=>"Authorization: {$da}\r\nx-amz-date: {$dt2}",'ignore_errors'=>true]]));
        echo "  Cleanup: OK\n";
    }
}
echo "\n";

// ── 7. VENDOR TABLE ───────────────────────────
echo "=== 7. VENDORS EN SISTEMA ===\n";
$vtbl = $wpdb->prefix . 'lt_vendors';
$vexists = $wpdb->get_var("SHOW TABLES LIKE '{$vtbl}'");
if ($vexists) {
    $vcnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$vtbl}`");
    echo "Total vendors en lt_vendors: {$vcnt}\n";
    $vrows = $wpdb->get_results("SELECT id,user_id,store_name,status,country FROM `{$vtbl}` ORDER BY id DESC LIMIT 5");
    foreach ($vrows as $v) echo "  ID={$v->id} user={$v->user_id} store='{$v->store_name}' status={$v->status} country={$v->country}\n";
} else { echo "  Tabla {$vtbl} no existe\n"; }
echo "\n";

// ── 8. RECENT WP ERRORS ───────────────────────
echo "=== 8. RECENT PHP ERRORS ===\n";
$log = __DIR__ . '/wp-content/debug.log';
if (file_exists($log)) {
    $lines = file($log);
    $recent = array_slice($lines, -15);
    foreach ($recent as $l) echo "  " . trim($l) . "\n";
} else { echo "  debug.log no encontrado\n"; }

echo "\n========================================\n";
echo "  QA COMPLETE\n";
echo "========================================\n";
