<?php
/**
 * LTMS Commission Logic Deep Audit
 * wp eval-file bin/audit-commissions-logic.php --path=/home/customer/www/lo-tengo.com.co/public_html
 */

echo "\n========================================\n";
echo "  LTMS COMMISSION LOGIC DEEP AUDIT\n";
echo "========================================\n\n";

// ── 1. LEER OPCIONES ACTUALES ────────────────────────────────────────────────
echo "── 1. OPCIONES DE COMISIONES EN DB ─────────────────────────────────────\n";
$platform_rate  = (float) get_option('ltms_platform_commission_rate', 0.10);
$phys_raw       = get_option('ltms_commission_physical', '');
$digi_raw       = get_option('ltms_commission_digital', '');
$serv_raw       = get_option('ltms_commission_service', '');
$book_raw       = get_option('ltms_commission_booking', '');
$redi_min_raw   = (float) get_option('ltms_redi_min_rate', 5);
$redi_max_raw   = (float) get_option('ltms_redi_max_rate', 40);
$referral_rates = get_option('ltms_referral_rates', '[0.05,0.02]');
$mlm_enabled    = get_option('ltms_mlm_enabled', 'no');
$redi_enabled   = get_option('ltms_redi_enabled', 'no');
$redi_default   = (float) get_option('ltms_redi_default_rate', 0.15);
$min_payout     = get_option('ltms_min_payout_amount', 50000);

printf("  platform_rate    = %s (%.1f%%)\n", $platform_rate, $platform_rate * 100);
foreach (['physical'=>$phys_raw,'digital'=>$digi_raw,'service'=>$serv_raw,'booking'=>$book_raw] as $t=>$r) {
    $f = (float)$r; $d = $f > 1 ? $f/100 : $f;
    printf("  commission_%s = %s → %.1f%%\n", str_pad($t,8), $r, $d*100);
}
$redi_min_d = $redi_min_raw < 1 ? $redi_min_raw : $redi_min_raw/100;
$redi_max_d = $redi_max_raw < 1 ? $redi_max_raw : $redi_max_raw/100;
printf("  redi_min         = %s → %.1f%%\n", $redi_min_raw, $redi_min_d*100);
printf("  redi_max         = %s → %.1f%%\n", $redi_max_raw, $redi_max_d*100);
printf("  redi_default     = %s → %.1f%%\n", $redi_default, $redi_default < 1 ? $redi_default*100 : $redi_default);
printf("  mlm_enabled      = %s\n", $mlm_enabled);
printf("  redi_enabled     = %s\n", $redi_enabled);
printf("  referral_rates   = %s\n", $referral_rates);
printf("  min_payout       = %s COP\n\n", number_format((float)$min_payout));

// ── 2. VERIFICAR CLASES CARGADAS ────────────────────────────────────────────
echo "── 2. CLASES DE COMISIONES CARGADAS ────────────────────────────────────\n";
$classes = [
    'LTMS_Business_Commission_Strategy' => 'includes/business/class-ltms-commission-strategy.php',
    'LTMS_Business_Order_Split'         => 'includes/business/class-ltms-order-split.php',
    'LTMS_Business_Redi_Manager'        => 'includes/business/class-ltms-business-redi-manager.php',
    'LTMS_Business_Redi_Order_Split'    => 'includes/business/class-ltms-business-redi-order-split.php',
    'LTMS_Referral_Tree'                => 'includes/business/class-ltms-referral-tree.php',
    'LTMS_Core_Config'                  => 'includes/core/class-ltms-core-config.php',
];
foreach ($classes as $cls => $file) {
    $loaded = class_exists($cls);
    printf("  %-45s %s\n", $cls, $loaded ? '✅ loaded' : '❌ NOT FOUND');
}
echo "\n";

// ── 3. SIMULAR get_rate() PARA DISTINTOS VENDOR/PRODUCT TYPES ───────────────
echo "── 3. SIMULACIÓN get_rate() (commission-strategy) ──────────────────────\n";
if (class_exists('LTMS_Business_Commission_Strategy')) {
    // Necesitamos un WC_Order mock — usamos el primer pedido real si existe
    $orders = wc_get_orders(['limit' => 3, 'status' => ['wc-completed','wc-processing']]);
    if (empty($orders)) {
        echo "  ℹ️  Sin pedidos completados — simulación con datos hardcoded\n";
        // Simular la lógica manualmente
        foreach (['physical','digital','service','booking'] as $type) {
            $key = 'ltms_commission_' . $type;
            $defaults = ['physical'=>0.10,'digital'=>0.15,'service'=>0.15,'booking'=>0.15];
            $configured_pct = get_option($key, '');
            if ($configured_pct !== '' && is_numeric($configured_pct)) {
                $rate = (float)$configured_pct;
                if ($rate > 1) $rate = $rate / 100;
                $rate = max(0.0, min(1.0, $rate));
            } else {
                $rate = $defaults[$type];
            }
            printf("  type=%-10s → rate=%.4f (%.2f%%)\n", $type, $rate, $rate*100);
        }
    } else {
        foreach ($orders as $order) {
            $vendor_id = (int) $order->get_meta('_ltms_vendor_id');
            if (!$vendor_id) continue;
            $rate = LTMS_Business_Commission_Strategy::get_rate($vendor_id, $order);
            $type = $order->get_meta('_ltms_product_type') ?: 'physical';
            printf("  Order#%d type=%-10s vendor=%d → rate=%.4f (%.2f%%)\n",
                $order->get_id(), $type, $vendor_id, $rate, $rate*100);
        }
    }
} else {
    echo "  ❌ LTMS_Business_Commission_Strategy no disponible\n";
}
echo "\n";

// ── 4. SIMULACIÓN FINANCIERA COMPLETA ───────────────────────────────────────
echo "── 4. SIMULACIÓN FINANCIERA (orden $100,000 COP) ───────────────────────\n";
$order_total = 100000;
// Escenario A: producto físico, sin ReDi, sin MLM
$phys_f = (float)$phys_raw; $phys_d = $phys_f > 1 ? $phys_f/100 : $phys_f;
$plat_fee_a = round($order_total * $platform_rate);
$vendor_net_a = $order_total - $plat_fee_a;
echo "  [A] Físico, sin ReDi, sin MLM:\n";
printf("      Bruto=$%s | Plataforma(%.0f%%)=$%s | Vendedor=$%s\n",
    number_format($order_total), $platform_rate*100,
    number_format($plat_fee_a), number_format($vendor_net_a));

// Escenario B: producto físico + ReDi 20%
$redi_rate_b = 0.20;
$redi_fee_b  = round($order_total * $redi_rate_b);
$plat_fee_b  = round($order_total * $platform_rate);
$orig_net_b  = $order_total - $redi_fee_b - $plat_fee_b;
echo "  [B] Físico + ReDi 20%:\n";
printf("      Bruto=$%s | Plataforma=$%s | Revendedor=$%s | Original=$%s\n",
    number_format($order_total), number_format($plat_fee_b),
    number_format($redi_fee_b), number_format($orig_net_b));
if ($orig_net_b < 0) echo "      ❌ NEGATIVO! ReDi+Plataforma > 100%\n";
else echo "      ✅ Distribución positiva\n";

// Escenario C: MLM 2 niveles [0.05, 0.02]
$mlm_decoded = json_decode($referral_rates, true) ?: [0.05, 0.02];
$mlm_l1 = $mlm_decoded[0] ?? 0.05;
$mlm_l2 = $mlm_decoded[1] ?? 0.02;
// MLM aplica sobre platform_fee
$mlm_fee_l1 = round($plat_fee_a * $mlm_l1);
$mlm_fee_l2 = round($plat_fee_a * $mlm_l2);
echo "  [C] Físico + MLM (sobre platform_fee de $".number_format($plat_fee_a)."):\n";
printf("      Nivel1(%.0f%% of plat_fee)=$%s | Nivel2(%.0f%% of plat_fee)=$%s\n",
    $mlm_l1*100, number_format($mlm_fee_l1), $mlm_l2*100, number_format($mlm_fee_l2));
echo "\n";

// ── 5. VERIFICAR HOOKS REGISTRADOS ──────────────────────────────────────────
echo "── 5. HOOKS DE COMISIONES REGISTRADOS ──────────────────────────────────\n";
$hooks_to_check = [
    'woocommerce_order_status_completed',
    'woocommerce_order_status_processing',
    'ltms_process_order_commission',
    'ltms_process_redi_split',
    'ltms_referral_commission',
];
global $wp_filter;
foreach ($hooks_to_check as $hook) {
    if (isset($wp_filter[$hook]) && !empty($wp_filter[$hook]->callbacks)) {
        $count = 0;
        $callbacks = [];
        foreach ($wp_filter[$hook]->callbacks as $priority => $cbs) {
            foreach ($cbs as $cb) {
                $count++;
                if (is_array($cb['function'])) {
                    $cls = is_object($cb['function'][0]) ? get_class($cb['function'][0]) : $cb['function'][0];
                    $callbacks[] = "{$cls}::{$cb['function'][1]}@{$priority}";
                } elseif (is_string($cb['function'])) {
                    $callbacks[] = "{$cb['function']}@{$priority}";
                }
            }
        }
        printf("  ✅ %-45s [%d] %s\n", $hook, $count, implode(', ', array_slice($callbacks,0,3)));
    } else {
        printf("  ⚠️  %-45s NOT REGISTERED\n", $hook);
    }
}
echo "\n";

// ── 6. REVISAR ÚLTIMAS COMISIONES EN DB ─────────────────────────────────────
echo "── 6. ÚLTIMAS COMISIONES REGISTRADAS EN DB ─────────────────────────────\n";
global $wpdb;
$table = $wpdb->prefix . 'lt_commissions';
$exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
if ($exists) {
    $rows = $wpdb->get_results("SELECT order_id, vendor_id, gross_amount, commission_amount, vendor_net, status, created_at FROM {$table} ORDER BY id DESC LIMIT 5");
    if ($rows) {
        printf("  %-8s %-8s %-12s %-12s %-12s %-10s %-12s\n",
            'order_id','vendor','gross','platform_fee','vendor_net','status','date');
        printf("  %s\n", str_repeat('-', 80));
        foreach ($rows as $r) {
            $pct = $r->gross_amount > 0 ? round($r->commission_amount / $r->gross_amount * 100, 1) : 0;
            printf("  %-8s %-8s $%-11s $%-11s $%-11s %-10s %s\n",
                $r->order_id, $r->vendor_id,
                number_format($r->gross_amount), number_format($r->commission_amount)."({$pct}%)",
                number_format($r->vendor_net), $r->status,
                substr($r->created_at, 0, 10));
        }
    } else {
        echo "  ℹ️  Tabla existe pero sin registros aún\n";
    }
} else {
    echo "  ❌ Tabla {$table} NO EXISTE\n";
}
echo "\n";

// ── 7. VERIFICAR TABLA WALLET TRANSACTIONS ──────────────────────────────────
echo "── 7. ÚLTIMAS TRANSACCIONES DE WALLET ──────────────────────────────────\n";
$wtable = $wpdb->prefix . 'lt_wallet_transactions';
$wexists = $wpdb->get_var("SHOW TABLES LIKE '{$wtable}'");
if ($wexists) {
    $wrows = $wpdb->get_results("SELECT user_id, type, amount, balance_after, reference_id, created_at FROM {$wtable} ORDER BY id DESC LIMIT 5");
    if ($wrows) {
        foreach ($wrows as $r) {
            printf("  user=%-5s type=%-15s amount=$%-10s balance=$%s ref=%s\n",
                $r->user_id, $r->type, number_format($r->amount), number_format($r->balance_after), $r->reference_id);
        }
    } else {
        echo "  ℹ️  Sin transacciones de wallet\n";
    }
} else {
    echo "  ❌ Tabla {$wtable} NO EXISTE\n";
}

echo "\n==== AUDIT COMPLETE ====\n";
