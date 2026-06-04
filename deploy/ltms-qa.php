<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS PAYOUT & BANK QA ===\n\n";

// 1. Payout table structure
echo "=== 1. PAYOUT TABLE ===\n";
$ptbl = $wpdb->prefix . 'lt_payouts';
$pexists = $wpdb->get_var("SHOW TABLES LIKE '{$ptbl}'");
if ($pexists) {
    $cols = $wpdb->get_results("DESCRIBE `{$ptbl}`");
    foreach ($cols as $c) echo "  {$c->Field} ({$c->Type})\n";
    $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ptbl}`");
    echo "  Total payouts: {$cnt}\n";
    $rows = $wpdb->get_results("SELECT id,vendor_id,amount,status,payment_method,bank_name,bank_account,created_at FROM `{$ptbl}` ORDER BY id DESC LIMIT 5");
    foreach ($rows as $r) {
        echo "  ID={$r->id} vendor={$r->vendor_id} amount={$r->amount} status={$r->status}\n";
        echo "    method={$r->payment_method} bank={$r->bank_name} acct=****".substr($r->bank_account,-4)."\n";
        echo "    created={$r->created_at}\n";
    }
} else {
    echo "  TABLA NO EXISTE\n";
    // Check all tables
    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}lt_%'");
    echo "  Tablas lt_ existentes:\n";
    foreach ($tables as $t) echo "    $t\n";
}
echo "\n";

// 2. Bank info stored in user_meta
echo "=== 2. BANK DATA EN USER META ===\n";
$bank_keys = ['ltms_bank_name','ltms_bank_account','ltms_bank_type','ltms_bank_account_type',
              'ltms_bank_info','ltms_payout_bank','vendor_bank_name','vendor_bank_account',
              'ltms_kyc_bank_name','ltms_kyc_bank_account','ltms_kyc_bank_rep_legal'];
foreach ($bank_keys as $k) {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s LIMIT 5", $k
    ));
    if ($rows) {
        echo "  {$k}:\n";
        foreach ($rows as $r) {
            $v = (strpos($k,'account')!==false) ? '****'.substr($r->meta_value,-4) : $r->meta_value;
            echo "    vendor={$r->user_id}: {$v}\n";
        }
    }
}
echo "\n";

// 3. What the settings page saves for bank
echo "=== 3. OPCION ltms_vendor_bank_fields ===\n";
$bf = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_vendor_bank_fields'");
echo "  ltms_vendor_bank_fields: " . ($bf ?: '(no existe)') . "\n";

// Check payout settings
echo "\n=== 4. PAYOUT CONFIG ===\n";
$pkeys = ['ltms_payout_kyc_required','ltms_payout_min_amount','ltms_min_payout_amount',
          'ltms_auto_approve_payouts','ltms_auto_approve_max_amount','ltms_payout_frequency',
          'ltms_hold_period_days','ltms_openpay_enabled','ltms_openpay_merchant_id'];
foreach ($pkeys as $k) {
    $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k));
    echo "  {$k}: " . ($v !== null ? $v : '(no existe)') . "\n";
}
echo "\n";

// 5. Find payout-related PHP files
echo "=== 5. PAYOUT PHP FILES ===\n";
$plugin = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/includes'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $name = strtolower($f->getBasename());
        if (strpos($name,'payout') !== false || strpos($name,'wallet') !== false || strpos($name,'withdrawal') !== false || strpos($name,'retiro') !== false) {
            echo "  " . str_replace($plugin.'/', '', $f->getPathname()) . "\n";
        }
    }
}
echo "\n";

// 6. Find where bank_name/bank_account is saved in settings
echo "=== 6. DONDE SE GUARDA BANK INFO (settings handler) ===\n";
$settings_files = [];
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/includes'));
foreach ($it2 as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $c = file_get_contents($f->getPathname());
        if (strpos($c, 'bank') !== false && (strpos($c, 'update_user_meta') !== false || strpos($c, 'save') !== false)) {
            $fname = str_replace($plugin.'/', '', $f->getPathname());
            $lines = explode("\n", $c);
            foreach ($lines as $i => $line) {
                if ((strpos($line,'bank')!==false || strpos($line,'banco')!==false) && 
                    (strpos($line,'update_user_meta')!==false || strpos($line,'save')!==false || strpos($line,'$_POST')!==false) &&
                    strpos($line,'//') === false) {
                    echo "  {$fname}:" . ($i+1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}

echo "\n=== DONE ===\n";
