<?php
/**
 * LTMS Deploy Webhook v6 — writes ltms-qa.php + git pull
 */
$token = 'ltms_deploy_2026_s3cur3_t0k3n_x9z';
if (($_GET['token'] ?? '') !== $token && ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '') !== $token) {
    http_response_code(403); echo "Forbidden"; exit;
}
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

$doc_root = rtrim($_SERVER['DOCUMENT_ROOT'] ?: dirname(__FILE__), '/');
$plugin_dir = $doc_root . '/wp-content/plugins/lt-marketplace-suite';

// Write QA script
$qa_b64 = "PD9waHAKaWYgKCgkX0dFVFsndCddID8/ICcnKSAhPT0gJ2x0bXNfcWFfMjAyNicpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwMyk7IGV4aXQ7IH0KaGVhZGVyKCdDb250ZW50LVR5cGU6IHRleHQvcGxhaW47IGNoYXJzZXQ9dXRmLTgnKTsKc2V0X3RpbWVfbGltaXQoNjApOwokd3AgPSBfX0RJUl9fIC4gJy93cC1sb2FkLnBocCc7CmlmICghZmlsZV9leGlzdHMoJHdwKSkgeyBlY2hvICJFUlJPUjogd3AtbG9hZC5waHAgbm90IGZvdW5kXG4iOyBleGl0KDEpOyB9CnJlcXVpcmVfb25jZSAkd3A7CmVjaG8gIj09PSBMVE1TIEtZQyBRQSBESUFHTk9TVElDID09PVxuIjsKZWNobyAiVGltZTogIiAuIGN1cnJlbnRfdGltZSgnWS1tLWQgSDppOnMnKSAuICJcbiI7CmVjaG8gIlBIUDogIiAuIFBIUF9WRVJTSU9OIC4gIlxuXG4iOwpnbG9iYWwgJHdwZGI7CiR0YmwgPSAkd3BkYi0+cHJlZml4IC4gJ2x0X3ZlbmRvcl9reWMnOwplY2hvICI9PT0gMS4gREIgVEFCTEU6IHskdGJsfSA9PT1cbiI7CiRjb2xzID0gJHdwZGItPmdldF9yZXN1bHRzKCJERVNDUklCRSBgeyR0Ymx9YCIpOwppZiAoJGNvbHMpIHsgZm9yZWFjaCAoJGNvbHMgYXMgJGMpIGVjaG8gIiAgeyRjLT5GaWVsZH0gKHskYy0+VHlwZX0pXG4iOyB9CmVsc2UgeyBlY2hvICIgIEVSUk9SOiB0YWJsYSBubyBleGlzdGVcbiI7IH0KJGNudCA9IChpbnQpJHdwZGItPmdldF92YXIoIlNFTEVDVCBDT1VOVCgqKSBGUk9NIGB7JHRibH1gIik7CmVjaG8gIiAgVG90YWwgcmVnaXN0cm9zOiB7JGNudH1cblxuIjsKZWNobyAiPT09IDIuIEJBQ0tCTEFaRSBDT05GSUcgPT09XG4iOwpmb3JlYWNoIChbJ2x0bXNfYmFja2JsYXplX2VuZHBvaW50JywnbHRtc19iYWNrYmxhemVfa2V5X2lkJywnbHRtc19iYWNrYmxhemVfa3ljX2J1Y2tldCcsJ2x0bXNfYmFja2JsYXplX2FwcF9rZXknXSBhcyAkaykgewogICAgJHYgPSBnZXRfb3B0aW9uKCRrLCcnKTsKICAgIGlmIChlbXB0eSgkdikpIHsgZWNobyAiICB7JGt9OiAoVkFDSU8pXG4iOyBjb250aW51ZTsgfQogICAgJG1hc2tlZCA9IChzdHJwb3MoJGssJ2tleScpIT09ZmFsc2V8fHN0cnBvcygkaywnYXBwJykhPT1mYWxzZSkgPyBzdWJzdHIoJHYsMCw4KS4nLi4uJyA6ICR2OwogICAgZWNobyAiICB7JGt9OiB7JG1hc2tlZH1cbiI7Cn0KZWNobyAiXG49PT0gMy4gQjIgVVBMT0FEIFRFU1QgPT09XG4iOwppZiAoIWNsYXNzX2V4aXN0cygnTFRNU19BcGlfQmFja2JsYXplJykpIHsKICAgIGVjaG8gIiAgTFRNU19BcGlfQmFja2JsYXplIE5PVCBMT0FERURcbiI7CiAgICAkcGx1Z2lucyA9IGdsb2IoV1BfUExVR0lOX0RJUiAuICcvbHQtbWFya2V0cGxhY2Utc3VpdGUvaW5jbHVkZXMvYXBpL2NsYXNzLWx0bXMtYXBpLWJhY2tibGF6ZS5waHAnKTsKICAgIGVjaG8gIiAgUGx1Z2luIGZpbGU6ICIgLiAoJHBsdWdpbnMgPyAkcGx1Z2luc1swXSA6ICdOT1QgRk9VTkQnKSAuICJcbiI7Cn0gZWxzZSB7CiAgICB0cnkgewogICAgICAgICRiMiA9IG5ldyBMVE1TX0FwaV9CYWNrYmxhemUoKTsKICAgICAgICAkYmt0ID0gZ2V0X29wdGlvbignbHRtc19iYWNrYmxhemVfa3ljX2J1Y2tldCcsJ2xvdGVuZ28ta3ljLWRvY3MnKTsKICAgICAgICAka2V5ID0gJ2t5Yy9xYS10ZXN0LycudGltZSgpLidfcWFfMXB4LnBuZyc7CiAgICAgICAgJHBuZyA9IGJhc2U2NF9kZWNvZGUoJ2lWQk9SdzBLR2dvQUFBQU5TVWhFVWdBQUFBRUFBQUFCQ0FZQUFBQWZGY1NKQUFBQURVbEVRVlI0Mm1OaytNOVFEd0FEaGdHQVdqUjlhd0FBQUFCSlJVNUVya0pnZ2c9PScpOwogICAgICAgICRyZXMgPSAkYjItPnVwbG9hZF9maWxlKCRia3QsICRrZXksICRwbmcsICdpbWFnZS9wbmcnLCBbXSk7CiAgICAgICAgZWNobyAiICBVUExPQUQgT0sg4oCUIEVUYWc6IHskcmVzWydFVGFnJ119XG4iOwogICAgICAgICRiMi0+ZGVsZXRlX2ZpbGUoJGJrdCwgJGtleSk7CiAgICAgICAgZWNobyAiICBDbGVhbnVwIE9LXG4iOwogICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlKSB7CiAgICAgICAgZWNobyAiICBVUExPQUQgRVJSOiB7JGUtPmdldE1lc3NhZ2UoKX1cbiI7CiAgICB9Cn0KZWNobyAiXG49PT0gNC4gS1lDIFVTRVIgTUVUQSA9PT1cbiI7CiR2ZW5kb3JzID0gZ2V0X3VzZXJzKFsnbWV0YV9rZXknPT4nbHRtc19reWNfc3RhdHVzJywnbnVtYmVyJz0+NV0pOwppZiAoJHZlbmRvcnMpIHsKICAgIGZvcmVhY2ggKCR2ZW5kb3JzIGFzICR1KSB7CiAgICAgICAgZWNobyAiICB2ZW5kb3I9eyR1LT5JRH0gc3RhdHVzPSIgLiBnZXRfdXNlcl9tZXRhKCR1LT5JRCwnbHRtc19reWNfc3RhdHVzJyx0cnVlKSAuICJcbiI7CiAgICAgICAgZWNobyAiICAgIGNlZHVsYT0iIC4gKGdldF91c2VyX21ldGEoJHUtPklELCdsdG1zX2t5Y19maWxlX3BhdGgnLHRydWUpPzonKG5vbmUpJykgLiAiXG4iOwogICAgICAgIGVjaG8gIiAgICBiYW5jbz0iIC4gKGdldF91c2VyX21ldGEoJHUtPklELCdsdG1zX2t5Y19maWxlX2JhbmNvJyx0cnVlKT86Jyhub25lKScpIC4gIlxuIjsKICAgIH0KfSBlbHNlIHsgZWNobyAiICAoc2luIHZlbmRvcnMgY29uIEtZQylcbiI7IH0KZWNobyAiXG49PT0gUUEgQ09NUExFVEUgPT09XG4iOwo=";
$qa_php = base64_decode($qa_b64);
$qa_path = $doc_root . '/ltms-qa.php';
$wrote = file_put_contents($qa_path, $qa_php);
echo "QA write: " . ($wrote !== false ? "OK ({$wrote} bytes) → {$qa_path}" : "FAILED (check permissions on {$doc_root})") . "\n";

// Git pull
if (!is_dir($plugin_dir . '/.git')) {
    echo "Plugin dir not found: {$plugin_dir}\n";
    exit(1);
}

// SEC-2026-07-30: GitHub PAT hardcoded eliminado. Antes el token estaba
// split en 3 strings concatenados ($gh_tok_a/b/c), commiteado en texto
// plano. Tras la rotación del PAT expuesto, el token se resuelve en
// runtime desde, en orden:
//   1. getenv('LTMS_GH_TOKEN')  -> env del runtime del servidor web
//   2. constante PHP LTMS_GH_TOKEN definida en wp-config.php
// Si ninguno está definido, abortar CON MENSAJE en vez de intentar
// fetch anónimo (rate-limit GitHub = 60/hr sin auth, insuficiente para
// deploy). Es preferible un deploy que falle ruidosamente a uno que
// silenciosamente rate-limite.
//
// SEC-2026-07-30 v6.2 RE-FIX: este webhook corre STANDALONE (no carga
// wp-load.php ni wp-config.php por sí mismo), así que defined() no ve
// constantes de wp-config.php aunque el usuario las haya definido ahí.
// Fix: si getenv() y defined() ambos fallan, hacer require_once del
// wp-config.php del doc_root (si existe) y RE-intentar defined(). El
// (@) suprime cualquier output accidental de WP que rompería el header
// Content-Type del webhook.
$gh_token = getenv('LTMS_GH_TOKEN') ?: (defined('LTMS_GH_TOKEN') ? constant('LTMS_GH_TOKEN') : '');
if ($gh_token === '' && file_exists($doc_root . '/wp-config.php')) {
    // Cargar wp-config.php solo para que defined('LTMS_GH_TOKEN') resuelva.
    // No usamos wp-load.php porque eso carga todo el framework WP; wp-config
    // es lighter y suficiente para que las constantes estén disponibles.
    @include_once $doc_root . '/wp-config.php';
    $gh_token = defined('LTMS_GH_TOKEN') ? constant('LTMS_GH_TOKEN') : '';
}
if ($gh_token === '') {
    http_response_code(500);
    echo "ERROR: LTMS_GH_TOKEN no definido. Definir via env o en wp-config.php: define('LTMS_GH_TOKEN', '<NEW_PAT>');\n";
    exit(1);
}
$gh_token = preg_replace('/[^A-Za-z0-9_]/', '', $gh_token); // sanity sanitize
if (strpos($gh_token, 'ghp_') !== 0) {
    http_response_code(500);
    echo "ERROR: LTMS_GH_TOKEN debe ser un classic PAT con prefijo 'ghp_'.\n";
    exit(1);
}
$remote = "https://{$gh_token}@github.com/jglotengo/lt-marketplace-suite.git";

putenv('GIT_TERMINAL_PROMPT=0');
putenv('HOME=/tmp');
putenv('GIT_SSH_COMMAND=ssh -o StrictHostKeyChecking=no');

chdir($plugin_dir);
exec("git remote set-url origin " . escapeshellarg($remote) . " 2>&1", $o1, $r1);
exec("git fetch origin main --depth=1 2>&1", $o2, $r2);
exec("git reset --hard origin/main 2>&1", $o3, $r3);

echo "Fetch: " . ($r2 === 0 ? "OK" : "ERR({$r2})") . "\n";
echo implode("\n", $o2) . "\n";
echo "Reset: " . ($r3 === 0 ? "OK" : "ERR({$r3})") . "\n";
echo implode("\n", $o3) . "\n";
echo "Deploy complete.\n";
