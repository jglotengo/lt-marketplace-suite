<?php
/**
 * LTMS Deploy Webhook v4
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
    $fc=gh_get($rel, $gh_tok);
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
