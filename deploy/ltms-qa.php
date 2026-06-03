<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;
$tbl = $wpdb->prefix . 'lt_vendor_kyc';
echo "=== LTMS KYC QA DIAGNOSTIC ===\n";
echo "PHP: " . PHP_VERSION . "\n\n";
echo "=== 1. DB TABLE: {$tbl} ===\n";
$cols = $wpdb->get_results("DESCRIBE `{$tbl}`");
if ($cols) { foreach ($cols as $c) echo "  {$c->Field} ({$c->Type})\n"; }
else { echo "  ERROR: tabla no existe — " . $wpdb->last_error . "\n"; }
$cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
echo "  Total: {$cnt}\n\n";
echo "=== 2. WP OPTIONS (Backblaze) ===\n";
foreach (['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_kyc_bucket','ltms_backblaze_app_key'] as $k) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    $masked = ($v && (strpos($k,'key')!==false||strpos($k,'app')!==false)) ? substr($v,0,8).'...' : ($v ?: '(VACIO)');
    echo "  {$k}: {$masked}\n";
}
echo "\n=== 3. KYC USER META ===\n";
$rows = $wpdb->get_results("SELECT u.ID, u.user_login, MAX(CASE WHEN m.meta_key='ltms_kyc_status' THEN m.meta_value END) as kyc_status, MAX(CASE WHEN m.meta_key='ltms_kyc_file_path' THEN m.meta_value END) as file_path, MAX(CASE WHEN m.meta_key='ltms_kyc_file_banco' THEN m.meta_value END) as file_banco, MAX(CASE WHEN m.meta_key='ltms_kyc_bank_name' THEN m.meta_value END) as bank_name FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID WHERE m.meta_key IN ('ltms_kyc_status','ltms_kyc_file_path','ltms_kyc_file_banco','ltms_kyc_bank_name') GROUP BY u.ID ORDER BY u.ID DESC LIMIT 5");
if ($rows) { foreach ($rows as $r) { echo "  vendor={$r->ID} ({$r->user_login}) status={$r->kyc_status}\n    cedula=" . ($r->file_path?:'none') . "\n    banco=" . ($r->file_banco?:'none') . "\n"; } }
else { echo "  (sin vendors KYC)\n"; }
echo "\n=== 4. KYC TABLE ROWS ===\n";
$kyc = $wpdb->get_results("SELECT id,vendor_id,status,document_type,file_path,submitted_at FROM `{$tbl}` ORDER BY id DESC LIMIT 5");
if ($kyc) { foreach ($kyc as $r) { echo "  ID={$r->id} vendor={$r->vendor_id} status={$r->status}\n    file={$r->file_path}\n    at={$r->submitted_at}\n"; } }
else { echo "  (tabla vacia)\n"; }
echo "\n=== QA COMPLETE ===\n";
