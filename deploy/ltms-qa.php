<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS PAYOUT REQUESTS & WALLET QA ===\n\n";

// 1. payout_requests table
$ptbl = $wpdb->prefix . 'lt_payout_requests';
echo "=== 1. {$ptbl} ===\n";
$cols = $wpdb->get_results("DESCRIBE `{$ptbl}`");
if ($cols) {
    foreach ($cols as $c) {
        echo "  {$c->Field} ({$c->Type})\n";
    }
    $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ptbl}`");
    echo "  Total: {$cnt}\n";
    if ($cnt > 0) {
        $rows = $wpdb->get_results("SELECT * FROM `{$ptbl}` ORDER BY id DESC LIMIT 3");
        foreach ($rows as $r) {
            $arr = (array)$r;
            foreach ($arr as $k => $v) {
                if ($v !== null && $v !== '') {
                    $display = (strpos($k, 'account') !== false || strpos($k, 'key') !== false) ? '****' . substr($v, -4) : substr($v, 0, 80);
                    echo "    {$k}: {$display}\n";
                }
            }
            echo "  ---\n";
        }
    }
} else {
    echo "  ERROR: " . $wpdb->last_error . "\n";
}
echo "\n";

// 2. vendor_wallets
$wtbl = $wpdb->prefix . 'lt_vendor_wallets';
echo "=== 2. {$wtbl} ===\n";
$wcols = $wpdb->get_results("DESCRIBE `{$wtbl}`");
if ($wcols) {
    foreach ($wcols as $c) {
        echo "  {$c->Field} ({$c->Type})\n";
    }
    $wcnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wtbl}`");
    echo "  Total: {$wcnt}\n";
    $wrows = $wpdb->get_results("SELECT vendor_id, balance, currency, status FROM `{$wtbl}` ORDER BY balance DESC LIMIT 5");
    foreach ($wrows as $r) {
        echo "  vendor={$r->vendor_id} balance={$r->balance} {$r->currency} status={$r->status}\n";
    }
}
echo "\n";

// 3. wallet_transactions
$ttbl = $wpdb->prefix . 'lt_wallet_transactions';
echo "=== 3. {$ttbl} (ultimas 5) ===\n";
$tcnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ttbl}`");
echo "  Total: {$tcnt}\n";
$trows = $wpdb->get_results("SELECT id, vendor_id, type, amount, balance_after, description, created_at FROM `{$ttbl}` ORDER BY id DESC LIMIT 5");
foreach ($trows as $r) {
    echo "  ID={$r->id} vendor={$r->vendor_id} type={$r->type} amount={$r->amount} bal={$r->balance_after}\n";
    echo "    desc=" . substr($r->description, 0, 60) . " at={$r->created_at}\n";
}
echo "\n";

// 4. payout config from ltms_settings
echo "=== 4. PAYOUT CONFIG (desde ltms_settings) ===\n";
$settings_raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_settings'");
if ($settings_raw) {
    $settings = maybe_unserialize($settings_raw);
    if (is_array($settings)) {
        $payout_keys = array('ltms_payout_min_amount', 'ltms_min_payout_amount', 'ltms_auto_approve_payouts',
                            'ltms_auto_approve_max_amount', 'ltms_payout_frequency', 'ltms_hold_period_days',
                            'ltms_payout_kyc_required', 'ltms_kyc_required_for_payout', 'ltms_consumer_protection_days');
        foreach ($payout_keys as $k) {
            $short_k = str_replace('ltms_', '', $k);
            if (isset($settings[$k])) {
                echo "  {$k}: {$settings[$k]}\n";
            } elseif (isset($settings[$short_k])) {
                echo "  {$short_k}: {$settings[$short_k]}\n";
            }
        }
        // Show all payout-related
        foreach ($settings as $k => $v) {
            if (strpos($k, 'payout') !== false || strpos($k, 'hold') !== false || strpos($k, 'withdraw') !== false) {
                echo "  {$k}: {$v}\n";
            }
        }
    }
}
echo "\n";

// 5. bank fields in payout_requests columns
echo "=== 5. BANK FIELDS EN payout_requests ===\n";
$all_cols = $wpdb->get_results("DESCRIBE `{$ptbl}`");
foreach ($all_cols as $c) {
    $name = strtolower($c->Field);
    if (strpos($name, 'bank') !== false || strpos($name, 'account') !== false || strpos($name, 'payment') !== false || strpos($name, 'method') !== false) {
        echo "  {$c->Field} ({$c->Type}) null={$c->Null} default={$c->Default}\n";
    }
}
echo "\n";

// 6. view-wallet form fields
echo "=== 6. VIEW-WALLET FORM FIELDS ===\n";
$plugin = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
$vw = $plugin . '/includes/frontend/views/view-wallet.php';
if (file_exists($vw)) {
    $lines = explode("\n", file_get_contents($vw));
    foreach ($lines as $i => $line) {
        $low = strtolower($line);
        if ((strpos($low, 'input') !== false || strpos($low, 'select') !== false || strpos($low, 'name=') !== false) &&
            (strpos($low, 'bank') !== false || strpos($low, 'cuenta') !== false || strpos($low, 'account') !== false || strpos($low, 'amount') !== false || strpos($low, 'monto') !== false)) {
            echo "  L" . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
} else {
    echo "  view-wallet.php not found\n";
}

echo "\n=== DONE ===\n";
