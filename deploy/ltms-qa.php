<?php
if (($_GET['t'] ?? '') !== 'ltms_qa_2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
error_reporting(0);
define('SHORTINIT', true);
require_once __DIR__ . '/wp-load.php';
global $wpdb;

echo "=== LTMS PAYOUT REQUESTS & WALLET QA ===\n\n";

// 1. payout_requests table
echo "=== 1. bkr_lt_payout_requests ===\n";
$ptbl = $wpdb->prefix . 'lt_payout_requests';
$cols = $wpdb->get_results("DESCRIBE `{$ptbl}`");
foreach ($cols as $c) echo "  {$c->Field} ({$c->Type})\n";
$cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ptbl}`");
echo "  Total: {$cnt}\n";
$rows = $wpdb->get_results("SELECT * FROM `{$ptbl}` ORDER BY id DESC LIMIT 5");
foreach ($rows as $r) {
    echo "  ID={$r->id} vendor={$r->vendor_id} amount={$r->amount} status={$r->status}\n";
    // Show all non-empty fields
    foreach ((array)$r as $k=>$v) {
        if ($v && $k !== 'id' && $k !== 'vendor_id' && $k !== 'amount' && $k !== 'status')
            echo "    {$k}=" . (strpos($k,'account')!==false||strpos($k,'key')!==false ? '****'.substr($v,-4) : substr($v,0,60)) . "\n";
    }
}
echo "\n";

// 2. vendor_wallets table
echo "=== 2. bkr_lt_vendor_wallets ===\n";
$wtbl = $wpdb->prefix . 'lt_vendor_wallets';
$wcols = $wpdb->get_results("DESCRIBE `{$wtbl}`");
foreach ($wcols as $c) echo "  {$c->Field} ({$c->Type})\n";
$wcnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wtbl}`");
echo "  Total wallets: {$wcnt}\n";
$wrows = $wpdb->get_results("SELECT * FROM `{$wtbl}` ORDER BY id DESC LIMIT 5");
foreach ($wrows as $r) {
    echo "  vendor={$r->vendor_id} balance={$r->balance} currency={$r->currency} status={$r->status}\n";
}
echo "\n";

// 3. wallet_transactions
echo "=== 3. bkr_lt_wallet_transactions (últimas 5) ===\n";
$ttbl = $wpdb->prefix . 'lt_wallet_transactions';
$tcnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ttbl}`");
echo "  Total: {$tcnt}\n";
$trows = $wpdb->get_results("SELECT id,vendor_id,type,amount,balance_after,description,created_at FROM `{$ttbl}` ORDER BY id DESC LIMIT 5");
foreach ($trows as $r) {
    echo "  ID={$r->id} vendor={$r->vendor_id} type={$r->type} amount={$r->amount} bal_after={$r->balance_after}\n";
    echo "    desc={$r->description} at={$r->created_at}\n";
}
echo "\n";

// 4. payout_requests structure deep dive — bank fields
echo "=== 4. CAMPOS BANCO EN payout_requests ===\n";
$all_cols = $wpdb->get_results("DESCRIBE `{$ptbl}`");
$bank_cols = array_filter($all_cols, fn($c) => strpos(strtolower($c->Field),'bank')!==false || strpos(strtolower($c->Field),'cuenta')!==false || strpos(strtolower($c->Field),'pago')!==false || strpos(strtolower($c->Field),'payment')!==false);
foreach ($bank_cols as $c) echo "  {$c->Field} ({$c->Type}) default={$c->Default}\n";
echo "\n";

// 5. frontend payout handler — what fields it saves
echo "=== 5. PAYOUT HANDLER — CAMPOS QUE GUARDA ===\n";
$plugin = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
$pf = $plugin . '/includes/frontend/class-ltms-frontend-payout-handler.php';
if (file_exists($pf)) {
    $lines = explode("\n", file_get_contents($pf));
    foreach ($lines as $i => $line) {
        if ((strpos($line,'bank')!==false || strpos($line,'cuenta')!==false || strpos($line,'account')!==false || strpos($line,'insert')!==false || strpos($line,'payout')!==false) && strpos($line,'//') === false && trim($line)) {
            if (strpos($line,'$_POST') !== false || strpos($line,'wpdb->insert') !== false || strpos($line,'update_user_meta') !== false || strpos($line,'sanitize') !== false) {
                echo "  L" . ($i+1) . ": " . trim($line) . "\n";
            }
        }
    }
}
echo "\n";

// 6. view-wallet — what fields vendor fills for payout
echo "=== 6. VIEW-WALLET — FORM FIELDS ===\n";
$vw = $plugin . '/includes/frontend/views/view-wallet.php';
if (file_exists($vw)) {
    $lines = explode("\n", file_get_contents($vw));
    foreach ($lines as $i => $line) {
        if ((strpos($line,'input')!==false || strpos($line,'select')!==false) && (strpos($line,'bank')!==false || strpos($line,'cuenta')!==false || strpos($line,'account')!==false || strpos($line,'monto')!==false || strpos($line,'amount')!==false)) {
            echo "  L" . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}

echo "\n=== DONE ===\n";
