<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "========================================\n";
echo "  LTMS PAYOUT / BANKING SYSTEM QA\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 1. Tablas de pagos/retiros
echo "=== 1. TABLAS DE PAGOS ===\n";
$tables = ['lt_payouts','lt_transactions','lt_wallet','lt_balances','lt_earnings','lt_vendor_payouts','lt_payout_requests'];
foreach ($tables as $t) {
    $full = $wpdb->prefix . $t;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
    if ($exists) {
        $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$full}`");
        $cols = $wpdb->get_results("DESCRIBE `{$full}`");
        echo "  [✓] {$full} ({$cnt} registros)\n";
        foreach ($cols as $c) echo "      {$c->Field} ({$c->Type})\n";
    } else {
        echo "  [✗] {$full}: NO EXISTE\n";
    }
}
echo "\n";

// 2. Campos bancarios en user_meta
echo "=== 2. CAMPOS BANCARIOS EN USER_META ===\n";
$bank_keys = ['ltms_bank_name','ltms_bank_account','ltms_bank_account_type','ltms_bank_account_holder','ltms_bank_account_number','ltms_bank_routing','ltms_payout_method','ltms_bank_info','ltms_kyc_bank_name','ltms_kyc_bank_account','ltms_kyc_bank_rep_legal'];
foreach ($bank_keys as $k) {
    $cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value!=''", $k));
    if ($cnt > 0) echo "  [✓] {$k}: {$cnt} vendors tienen este campo\n";
}
// Show all unique bank-related meta keys in use
$used = $wpdb->get_results("SELECT DISTINCT meta_key, COUNT(*) as cnt FROM {$wpdb->usermeta} WHERE meta_key LIKE '%bank%' OR meta_key LIKE '%payout%' OR meta_key LIKE '%retiro%' GROUP BY meta_key ORDER BY meta_key");
echo "\n  Todos los meta_keys bancarios en uso:\n";
foreach ($used as $u) echo "    {$u->meta_key}: {$u->cnt} vendors\n";
echo "\n";

// 3. Config de procesadores de pago
echo "=== 3. PROCESADORES DE PAGO CONFIGURADOS ===\n";
$pay_options = [
    'ltms_openpay_enabled'    => 'OpenPay',
    'ltms_openpay_merchant_id'=> 'OpenPay Merchant ID',
    'ltms_stripe_enabled'     => 'Stripe',
    'ltms_addi_enabled'       => 'ADDI',
    'ltms_aveonline_enabled'  => 'AveOnline',
    'ltms_pse_enabled'        => 'PSE',
    'ltms_deprisa_enabled'    => 'Deprisa (envíos)',
];
foreach ($pay_options as $k => $label) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    $active = ($v === 'yes' || $v === '1') ? '✓ ACTIVO' : '✗ inactivo';
    echo "  [{$active}] {$label}: {$v}\n";
}
echo "\n";

// 4. Revisar estructura del campo "banco_retiros" en settings panel
echo "=== 4. CAMPO BANCO PARA RETIROS (settings) ===\n";
// Check what's stored for vendors who filled in bank info
$bank_info = $wpdb->get_results("
    SELECT u.ID, u.user_login,
        MAX(CASE WHEN m.meta_key='ltms_bank_info' THEN m.meta_value END) as bank_info,
        MAX(CASE WHEN m.meta_key='ltms_payout_bank' THEN m.meta_value END) as payout_bank,
        MAX(CASE WHEN m.meta_key='ltms_kyc_bank_name' THEN m.meta_value END) as kyc_bank,
        MAX(CASE WHEN m.meta_key='ltms_kyc_bank_account' THEN m.meta_value END) as kyc_acct,
        MAX(CASE WHEN m.meta_key='ltms_kyc_bank_rep_legal' THEN m.meta_value END) as kyc_rep
    FROM {$wpdb->users} u
    JOIN {$wpdb->usermeta} m ON m.user_id=u.ID
    WHERE m.meta_key IN ('ltms_bank_info','ltms_payout_bank','ltms_kyc_bank_name','ltms_kyc_bank_account','ltms_kyc_bank_rep_legal')
    GROUP BY u.ID ORDER BY u.ID DESC LIMIT 10
");
if ($bank_info) {
    foreach ($bank_info as $r) {
        echo "  vendor={$r->ID} ({$r->user_login})\n";
        if ($r->bank_info)  echo "    ltms_bank_info: " . substr($r->bank_info,0,60) . "\n";
        if ($r->payout_bank) echo "    ltms_payout_bank: " . substr($r->payout_bank,0,60) . "\n";
        if ($r->kyc_bank)   echo "    kyc_bank_name: {$r->kyc_bank}\n";
        if ($r->kyc_acct)   echo "    kyc_bank_account: ****" . substr($r->kyc_acct,-4) . "\n";
        if ($r->kyc_rep)    echo "    kyc_rep_legal: {$r->kyc_rep}\n";
    }
} else { echo "  (ningún vendor con datos bancarios guardados)\n"; }
echo "\n";

// 5. Revisar código del settings panel para "banco_retiros"
echo "=== 5. PAYOUT FLOW OPTIONS ===\n";
$payout_opts = ['ltms_payout_kyc_required','ltms_min_payout_amount','ltms_payout_frequency','ltms_hold_period_days','ltms_auto_approve_payouts','ltms_auto_approve_max_amount'];
foreach ($payout_opts as $k) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    echo "  {$k}: " . ($v ?: '(no configurado)') . "\n";
}
echo "\n";

// 6. OpenPay config completa
echo "=== 6. OPENPAY CONFIG ===\n";
$op_keys = ['ltms_openpay_enabled','ltms_openpay_merchant_id','ltms_openpay_public_key','ltms_openpay_MX_merchant_id'];
foreach ($op_keys as $k) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    $masked = (strpos($k,'key')!==false || strpos($k,'secret')!==false) ? substr($v??'',0,10).'...' : ($v?:'(vacío)');
    echo "  {$k}: {$masked}\n";
}

echo "\n=== DONE ===\n";
