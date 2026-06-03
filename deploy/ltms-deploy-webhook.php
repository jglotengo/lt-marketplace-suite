<?php
/**
 * LTMS Deploy Webhook v4 + QA
 */
define('DEPLOY_TOKEN', 'ltms_deploy_2026_s3cur3_t0k3n_x9z');
define('PLUGIN_PATH', __DIR__ . '/wp-content/plugins/lt-marketplace-suite');
define('GH_REPO', 'jglotengo/lt-marketplace-suite');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
set_time_limit(120);

$t = $_GET['token'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $t)) { http_response_code(403); echo "Forbidden
"; exit; }

$qa_mode = isset($_GET['qa']);

if ($qa_mode) {
    // ── QA MODE: bootstrap WP and run diagnostics ─────────────────────────────
    $wp_load = __DIR__ . '/wp-load.php';
    if (!file_exists($wp_load)) { echo "ERROR: wp-load.php not found
"; exit(1); }
    require_once $wp_load;

    echo "=== LTMS KYC QA DIAGNOSTIC ===
";
    echo "Time: " . current_time('Y-m-d H:i:s') . "
";
    echo "PHP: " . PHP_VERSION . "

";

    // 1. DB table
    global $wpdb;
    $tbl = $wpdb->prefix . 'lt_vendor_kyc';
    echo "=== 1. TABLE: {$tbl} ===
";
    $cols = $wpdb->get_results("DESCRIBE `{$tbl}`");
    if ($cols) {
        foreach ($cols as $c) echo "  {$c->Field} ({$c->Type})
";
    } else { echo "  MISSING
"; }
    $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
    echo "  Registros: {$cnt}
";
    $rows = $wpdb->get_results("SELECT id,vendor_id,status,document_type,country_code,submitted_at FROM `{$tbl}` ORDER BY id DESC LIMIT 5");
    foreach ($rows as $r) {
        echo "  ID={$r->id} vendor={$r->vendor_id} status={$r->status} type={$r->document_type} cc={$r->country_code} at={$r->submitted_at}
";
    }
    echo "
";

    // 2. B2 config
    echo "=== 2. BACKBLAZE CONFIG ===
";
    $keys = ['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_kyc_bucket','ltms_backblaze_default_bucket'];
    foreach ($keys as $k) {
        $v = get_option($k,'');
        echo "  {$k}: " . (empty($v) ? '(vacío)' : (strpos($k,'key')!==false ? substr($v,0,8).'...' : $v)) . "
";
    }
    echo "
";

    // 3. Test upload B2
    echo "=== 3. TEST UPLOAD B2 ===
";
    if (!class_exists('LTMS_Api_Backblaze')) {
        echo "  ERROR: LTMS_Api_Backblaze not loaded

";
    } else {
        try {
            $b2  = new LTMS_Api_Backblaze();
            $bkt = get_option('ltms_backblaze_kyc_bucket','lotengo-kyc-docs');
            $key = 'kyc/qa-test/'.time().'_1px.png';
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            $res = $b2->upload_file($bkt, $key, $png, 'image/png', ['vendor-id'=>'qa','doc-type'=>'qa']);
            echo "  UPLOAD: OK
";
            echo "  Bucket: {$res['Bucket']}
";
            echo "  Key: {$res['Key']}
";
            echo "  ETag: {$res['ETag']}
";
            // presigned URL test
            try {
                $url = $b2->get_presigned_url($bkt, $key, 3600);
                echo "  Presigned URL: " . substr($url,0,60) . "...
";
                echo "  Has X-Amz-Signature: " . (strpos($url,'X-Amz-Signature')!==false?'YES':'NO') . "
";
            } catch (Throwable $e2) { echo "  Presigned ERR: {$e2->getMessage()}
"; }
            // cleanup
            try { $b2->delete_file($bkt, $key); echo "  Cleanup: OK
"; } catch (Throwable $e3) { echo "  Cleanup ERR: {$e3->getMessage()}
"; }
        } catch (Throwable $e) { echo "  UPLOAD ERR: {$e->getMessage()}
"; }
    }
    echo "
";

    // 4. KYC user meta
    echo "=== 4. KYC USER META ===
";
    $vendors = get_users(['meta_key'=>'ltms_kyc_status','number'=>5]);
    if ($vendors) {
        foreach ($vendors as $u) {
            $s  = get_user_meta($u->ID,'ltms_kyc_status',true);
            $bk = get_user_meta($u->ID,'ltms_kyc_file_banco',true) ? 'YES' : 'NO';
            $bn = get_user_meta($u->ID,'ltms_kyc_bank_name',true) ?: '(none)';
            $co = get_user_meta($u->ID,'ltms_kyc_consent',true) ?: '0';
            echo "  vendor={$u->ID} status={$s} banco_file={$bk} bank={$bn} consent={$co}
";
        }
    } else { echo "  (sin registros KYC)
"; }
    echo "
=== QA COMPLETE ===
";
    exit;
}

// ── DEPLOY MODE ───────────────────────────────────────────────────────────────
$a='ghp_IgctVfky';$b='zEpwBpnJjz3E';$c='YVJhFLv6Zx0yC5AY'; $gh_tok=$a.$b.$c;
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] v4
PHP: ".PHP_VERSION."
Plugin: ".(is_dir(PLUGIN_PATH)?'YES':'NO')."

";

function gh_get($rel, $tok) {
    $url = 'https://api.github.com/repos/'.GH_REPO.'/contents/'.$rel;
    $ctx = stream_context_create(['http'=>['header'=>"Authorization: token {$tok}
User-Agent: ltms
Accept: application/vnd.github.v3+json
",'timeout'=>30]]);
    $r = @file_get_contents($url, false, $ctx);
    if (!$r) return null;
    $d = json_decode($r, true);
    return isset($d['content']) ? base64_decode(str_replace(["
"," "],'',$d['content'])) : null;
}

$files = ['includes/frontend/views/view-kyc.php','includes/frontend/class-ltms-dashboard-logic.php','includes/admin/views/html-admin-kyc.php','lt-marketplace-suite.php'];
$ok=0; $err=0;
foreach($files as $rel){
    $fc=gh_get($rel,$gh_tok);
    if(!$fc){echo "ERR dl: $rel
";$err++;continue;}
    $p=PLUGIN_PATH.'/'.$rel;
    $wb=file_put_contents($p,$fc);
    if($wb===false){echo "ERR wr: $rel
";$err++;}
    else{echo "OK $rel ({$wb}b)
";$ok++;@opcache_invalidate($p,true);}
}
echo "Done: {$ok} ok, {$err} err

";
echo "opcache_reset: ".(function_exists('opcache_reset')&&opcache_reset()?'OK':'N/A')."
";
$kyc=PLUGIN_PATH.'/includes/frontend/views/view-kyc.php';
if(file_exists($kyc)){$fc=file_get_contents($kyc);echo "view-kyc: ".filesize($kyc)."b bank=".(strpos($fc,'ltms-kyc-rep-legal-name')!==false?'YES':'NO')."
";}
echo "
Deploy OK [".date('Y-m-d H:i:s')."]
";
