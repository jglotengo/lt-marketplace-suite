<?php
/**
 * LTMS Commission Settings FIX
 * Run: wp eval-file bin/fix-commissions.php --path=/home/customer/www/lo-tengo.com.co/public_html
 */

echo "\n==== LTMS COMMISSION SETTINGS FIX ====\n\n";

// 1. Fijar ltms_referral_rates - valor corrupto (float con 60 decimales)
$current_referral = get_option('ltms_referral_rates', '');
$decoded = json_decode($current_referral, true);
$is_corrupt = false;
if (is_array($decoded)) {
    foreach ($decoded as $v) {
        // Si algún valor tiene más de 10 decimales → corrupto (floating point artifact)
        if (strlen((string)(float)$v) > 10) { $is_corrupt = true; break; }
        // Si el valor interpretado es exactamente 0.05 o 0.02 → OK
    }
}
$clean_rates = '[0.05,0.02]';
if ($is_corrupt || !is_array($decoded)) {
    update_option('ltms_referral_rates', $clean_rates);
    echo "✅ ltms_referral_rates corregido → {$clean_rates}\n";
} else {
    // Limpiar precisión flotante
    $clean = array_map(fn($v) => round((float)$v, 6), $decoded);
    $clean_json = json_encode($clean);
    update_option('ltms_referral_rates', $clean_json);
    echo "✅ ltms_referral_rates limpiado → {$clean_json}\n";
}

// 2. Setear ltms_min_payout_amount si no existe
if (get_option('ltms_min_payout_amount', '__NOT_SET__') === '__NOT_SET__') {
    update_option('ltms_min_payout_amount', 50000);
    echo "✅ ltms_min_payout_amount creado → 50000\n";
} else {
    echo "ℹ️  ltms_min_payout_amount ya existe → " . get_option('ltms_min_payout_amount') . "\n";
}

// 3. Setear ltms_payout_schedule si no existe
if (get_option('ltms_payout_schedule', '__NOT_SET__') === '__NOT_SET__') {
    update_option('ltms_payout_schedule', 'biweekly');
    echo "✅ ltms_payout_schedule creado → biweekly\n";
} else {
    echo "ℹ️  ltms_payout_schedule ya existe → " . get_option('ltms_payout_schedule') . "\n";
}

// 4. Verificar y reportar todos los valores de comisiones
echo "\n==== ESTADO FINAL ====\n";
$checks = [
    'ltms_platform_commission_rate' => ['decimal', 0.15],
    'ltms_commission_physical'      => ['pct_or_decimal', 15],
    'ltms_commission_digital'       => ['pct_or_decimal', 15],
    'ltms_commission_service'       => ['pct_or_decimal', 10],
    'ltms_commission_booking'       => ['pct_or_decimal', 10],
    'ltms_min_payout_amount'        => ['int', 50000],
    'ltms_payout_schedule'          => ['string', 'biweekly'],
    'ltms_mlm_enabled'              => ['bool', 'no'],
    'ltms_referral_rates'           => ['json', '[0.05,0.02]'],
    'ltms_redi_enabled'             => ['bool', 'yes'],
    'ltms_redi_default_rate'        => ['decimal', 0.15],
    'ltms_redi_min_rate'            => ['decimal', 0.05],
    'ltms_redi_max_rate'            => ['decimal', 0.40],
];

foreach ($checks as $key => [$type, $default]) {
    $val = get_option($key, '__NOT_SET__');
    if ($val === '__NOT_SET__') {
        echo "  ⚠️  {$key} = NOT SET\n";
        continue;
    }
    if ($type === 'decimal') {
        $f = (float)$val;
        $pct = round($f * 100, 2);
        $ok = $f > 0 && $f <= 1;
        echo "  " . ($ok ? '✅' : '❌') . " {$key} = {$val} → {$pct}%\n";
    } elseif ($type === 'pct_or_decimal') {
        $f = (float)$val;
        $display = $f > 1 ? "{$f}%" : round($f*100,2)."%";
        echo "  ✅ {$key} = {$val} → {$display}\n";
    } elseif ($type === 'json') {
        $d = json_decode($val, true);
        $ok = is_array($d);
        echo "  " . ($ok ? '✅' : '❌') . " {$key} = " . substr($val, 0, 40) . "\n";
    } else {
        echo "  ✅ {$key} = {$val}\n";
    }
}

// 5. Test clamp_redi_rate corregido
echo "\n==== TEST clamp_redi_rate ====\n";
$min_raw = (float) get_option('ltms_redi_min_rate', 5);
$max_raw = (float) get_option('ltms_redi_max_rate', 40);
$min_d = ($min_raw < 1) ? $min_raw : $min_raw / 100;
$max_d = ($max_raw < 1) ? $max_raw : $max_raw / 100;
$test_rates = [0.20, 0.03, 0.50, 0.05, 0.40];
foreach ($test_rates as $r) {
    $clamped = max($min_d, min($max_d, $r));
    $pct = round($r * 100);
    $cpct = round($clamped * 100, 1);
    $ok = $clamped === $r ? '✅ unchanged' : "→ clamped to {$cpct}%";
    echo "  clamp({$pct}%) = {$cpct}% {$ok}\n";
}

echo "\n==== DONE ====\n";
