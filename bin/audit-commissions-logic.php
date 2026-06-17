<?php
/**
 * LTMS Commission Logic Deep Audit v2
 * wp eval-file bin/audit-commissions-logic.php --path=/home/customer/www/lo-tengo.com.co/public_html
 */

echo "\n========================================\n";
echo "  LTMS COMMISSION LOGIC DEEP AUDIT v2\n";
echo "========================================\n\n";

// ── 1. OPCIONES ──────────────────────────────────────────────────────────────
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

printf("  platform_rate    = %s → %.1f%%  %s\n", $platform_rate, $platform_rate*100,
    ($platform_rate>0&&$platform_rate<=1)?'✅':'❌ BAD');
foreach (['physical'=>$phys_raw,'digital'=>$digi_raw,'service'=>$serv_raw,'booking'=>$book_raw] as $t=>$r) {
    $f=(float)$r; $d=$f>1?$f/100:$f;
    printf("  commission_%-9s = %s → %.1f%%  ✅\n", $t, $r, $d*100);
}
$redi_min_d=$redi_min_raw<1?$redi_min_raw:$redi_min_raw/100;
$redi_max_d=$redi_max_raw<1?$redi_max_raw:$redi_max_raw/100;
printf("  redi_min/max     = %s/%s → %.0f%%/%.0f%%  ✅\n", $redi_min_raw, $redi_max_raw, $redi_min_d*100, $redi_max_d*100);
printf("  referral_rates   = %s  %s\n", $referral_rates, json_decode($referral_rates)?'✅':'❌');
printf("  mlm_enabled      = %s | redi_enabled = %s\n\n", $mlm_enabled, $redi_enabled);

// ── 2. CLASES CARGADAS ───────────────────────────────────────────────────────
echo "── 2. CLASES CARGADAS (context: wp eval-file no ejecuta plugins_loaded) ─\n";
$classes = [
    'LTMS_Business_Commission_Strategy' => 'business/class-ltms-commission-strategy.php',
    'LTMS_Business_Order_Split'         => 'business/class-ltms-order-split.php',
    'LTMS_Business_Redi_Manager'        => 'business/class-ltms-business-redi-manager.php',
    'LTMS_Business_Redi_Order_Split'    => 'business/class-ltms-business-redi-order-split.php',
    'LTMS_Referral_Tree'                => 'business/class-ltms-referral-tree.php',
    'LTMS_Order_Paid_Listener'          => 'business/listeners/class-ltms-order-paid-listener.php',
    'LTMS_Redi_Order_Listener'          => 'business/listeners/class-ltms-redi-order-listener.php',
    'LTMS_Core_Config'                  => 'includes/core/class-ltms-config.php',
];
$plugin_dir = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/';
foreach ($classes as $cls => $file) {
    $loaded  = class_exists($cls, false); // false = no trigger autoload
    // Verificar si el archivo existe en disco
    $filepath = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/' . $file;
    $exists  = file_exists($filepath);
    $status  = $loaded ? '✅ in memory' : ($exists ? '⚠️  file OK / not autoloaded yet' : '❌ FILE MISSING');
    printf("  %-45s %s\n", $cls, $status);
}
echo "\n";

// Intentar cargar manualmente Commission_Strategy para testear
$cs_file = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/business/class-ltms-commission-strategy.php';
if (file_exists($cs_file) && !class_exists('LTMS_Business_Commission_Strategy', false)) {
    // Necesita traits primero
    $traits_ok = true;
    foreach (['class-ltms-logger-aware.php'] as $trait_file) {
        $tf = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/core/traits/' . $trait_file;
        if (file_exists($tf)) require_once $tf;
    }
    require_once $cs_file;
}

// ── 3. SIMULACIÓN get_rate() ─────────────────────────────────────────────────
echo "── 3. SIMULACIÓN Commission_Strategy::get_rate() ───────────────────────\n";
if (class_exists('LTMS_Business_Commission_Strategy')) {
    echo "  ✅ Clase disponible. Simulando para tipos de producto:\n";
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
        printf("  type=%-10s option=%s → rate=%.4f (%.2f%%)  ✅\n", $type, $configured_pct ?: 'default', $rate, $rate*100);
    }
} else {
    echo "  ❌ No se pudo cargar. Autoloader no resuelve 'LTMS_Business_Commission_Strategy'\n";
    echo "  FIX APLICADO: se agregó 'ltms-business-commission-strategy' al mapa de excepciones.\n";
    echo "  Simulación manual:\n";
    foreach (['physical'=>0.10,'digital'=>0.15,'service'=>0.15,'booking'=>0.15] as $type=>$def) {
        $r = get_option('ltms_commission_'.$type,'');
        $rate = (is_numeric($r) && $r!=='') ? ((float)$r>1?(float)$r/100:(float)$r) : $def;
        printf("  type=%-10s → rate=%.4f (%.2f%%)  ✅\n", $type, $rate, $rate*100);
    }
}
echo "\n";

// ── 4. VERIFICAR HOOKS VÍA ARCHIVOS (no en memoria — eval-file context) ─────
echo "── 4. VERIFICACIÓN DE LISTENERS VÍA CÓDIGO FUENTE ──────────────────────\n";
$listeners = [
    'class-ltms-order-paid-listener.php'  => WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/business/listeners/class-ltms-order-paid-listener.php',
    'class-ltms-redi-order-listener.php'  => WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/business/listeners/class-ltms-redi-order-listener.php',
];
foreach ($listeners as $name => $path) {
    if (!file_exists($path)) { echo "  ❌ $name MISSING\n"; continue; }
    $content = file_get_contents($path);
    preg_match_all("/add_action\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $matches);
    $hooks = $matches[1] ?? [];
    printf("  ✅ %-45s hooks: %s\n", $name, implode(', ', $hooks));
}

echo "\n── 5. FLUJO DE REGISTRO (Kernel::boot → Listener::init → add_action) ────\n";
$kernel_file = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/core/class-ltms-kernel.php';
if (file_exists($kernel_file)) {
    $kc = file_get_contents($kernel_file);
    $has_paid  = strpos($kc, 'LTMS_Order_Paid_Listener') !== false;
    $has_redi  = strpos($kc, 'LTMS_Redi_Order_Listener') !== false;
    $has_ref   = strpos($kc, 'LTMS_Referral_Tree') !== false;
    printf("  Order_Paid_Listener registered in Kernel: %s\n", $has_paid ? '✅' : '❌');
    printf("  Redi_Order_Listener registered in Kernel: %s\n", $has_redi ? '✅' : '❌');
    printf("  Referral_Tree registered in Kernel:       %s\n", $has_ref  ? '✅' : '❌');
    // Verificar que plugins_loaded@15 llama a ltms_run
    $main_file = WP_PLUGIN_DIR . '/lt-marketplace-suite/lt-marketplace-suite.php';
    $mc = file_get_contents($main_file);
    $has_run = preg_match("/add_action.*plugins_loaded.*ltms_run.*15/s", $mc);
    printf("  plugins_loaded@15 → ltms_run:             %s\n", $has_run ? '✅' : '⚠️  check manually');
}

// ── 5. SIMULACIÓN FINANCIERA ─────────────────────────────────────────────────
echo "\n── 6. SIMULACIÓN FINANCIERA (orden $100,000 COP) ───────────────────────\n";
$order_total = 100000;
$phys_f = (float)$phys_raw; $phys_d = $phys_f>1?$phys_f/100:$phys_f;

echo "  [A] Físico | sin ReDi | sin MLM:\n";
$plat = round($order_total * $platform_rate);
printf("      Plataforma(%.0f%%)=$%s | Vendedor=$%s\n", $platform_rate*100, number_format($plat), number_format($order_total-$plat));

echo "  [B] Físico | ReDi 20% | sin MLM:\n";
$redi_fee = round($order_total * 0.20);
$plat_b   = round($order_total * $platform_rate);
$orig     = $order_total - $redi_fee - $plat_b;
printf("      Plataforma=$%s | Revendedor=$%s | Original=$%s  %s\n",
    number_format($plat_b), number_format($redi_fee), number_format($orig), $orig>0?'✅':'❌ NEGATIVO');

echo "  [C] Físico | sin ReDi | MLM 2 niveles (sobre platform_fee):\n";
$mlm = json_decode($referral_rates, true) ?: [0.05, 0.02];
printf("      Nivel1(%.0f%% de $%s)=$%s | Nivel2(%.0f%%)=$%s\n",
    $mlm[0]*100, number_format($plat), number_format(round($plat*$mlm[0])),
    $mlm[1]*100, number_format(round($plat*$mlm[1])));

// ── 6. DB ESTADO ─────────────────────────────────────────────────────────────
echo "\n── 7. ESTADO DB ─────────────────────────────────────────────────────────\n";
global $wpdb;
foreach (['lt_commissions','lt_wallet_transactions','lt_redi_agreements'] as $t) {
    $full = $wpdb->prefix . $t;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
    $count  = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$full}") : 0;
    printf("  %-35s %s  (%s rows)\n", $full, $exists?'✅':'❌ MISSING', number_format((int)$count));
}

echo "\n==== AUDIT COMPLETE ====\n";
