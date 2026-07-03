<?php
/**
 * LTMS Commission Settings Diagnostic
 * Run: wp eval-file diag-commissions.php --path=/home/customer/www/lo-tengo.com.co/public_html
 */

$options = [
    'ltms_platform_commission_rate' => ['label' => 'Comisión Plataforma',     'expected_format' => 'decimal (0.15 = 15%)',  'unit' => 'decimal'],
    'ltms_commission_physical'      => ['label' => 'Comisión Físico',         'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_commission_digital'       => ['label' => 'Comisión Digital',        'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_commission_service'       => ['label' => 'Comisión Servicio',       'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_commission_booking'       => ['label' => 'Comisión Turismo',        'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_min_withdrawal'           => ['label' => 'Retiro Mínimo (COP)',     'expected_format' => 'integer (50000)',        'unit' => 'cop'],
    'ltms_payment_frequency'        => ['label' => 'Frecuencia de Pagos',     'expected_format' => 'string',                 'unit' => 'string'],
    'ltms_mlm_enabled'              => ['label' => 'MLM Activo',              'expected_format' => 'yes/no',                 'unit' => 'bool'],
    'ltms_mlm_rates'                => ['label' => 'MLM Tasas (JSON)',        'expected_format' => 'JSON array',             'unit' => 'json'],
    'ltms_redi_enabled'             => ['label' => 'ReDi Activo',             'expected_format' => 'yes/no',                 'unit' => 'bool'],
    'ltms_redi_default_rate'        => ['label' => 'ReDi Tasa Defecto',       'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_redi_min_rate'            => ['label' => 'ReDi Tasa Mínima',        'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
    'ltms_redi_max_rate'            => ['label' => 'ReDi Tasa Máxima',        'expected_format' => 'pct OR decimal',         'unit' => 'auto'],
];

echo "\n==== LTMS COMMISSION SETTINGS DIAGNOSTIC ====\n";
echo str_pad('Option Key', 38) . str_pad('Raw DB Value', 20) . str_pad('Interpreted As', 20) . "Status\n";
echo str_repeat('-', 100) . "\n";

foreach ($options as $key => $meta) {
    $raw = get_option($key, '__NOT_SET__');
    
    if ($raw === '__NOT_SET__') {
        $interpreted = 'NOT SET';
        $status = '⚠️  MISSING';
    } elseif ($meta['unit'] === 'decimal') {
        $val = (float) $raw;
        $pct = round($val * 100, 2);
        $interpreted = "{$val} → {$pct}%";
        $status = ($val > 0 && $val <= 1) ? '✅ OK' : '❌ BAD (not 0-1 decimal)';
    } elseif ($meta['unit'] === 'auto') {
        $val = (float) $raw;
        if ($val > 1) {
            $interpreted = "PCT: {$val}% (÷100 in code → " . ($val/100) . ")";
            $status = '✅ OK (pct format)';
        } else {
            $interpreted = "DECIMAL: {$val} → " . round($val*100,2) . "%";
            $status = ($val > 0) ? '✅ OK (decimal)' : '❌ ZERO';
        }
    } elseif ($meta['unit'] === 'json') {
        $decoded = json_decode($raw, true);
        $interpreted = is_array($decoded) ? 'Array[' . count($decoded) . ']' : 'INVALID JSON';
        $status = is_array($decoded) ? '✅ OK' : '❌ JSON ERROR';
    } else {
        $interpreted = $raw;
        $status = '✅ OK';
    }
    
    echo str_pad($key, 38) . str_pad(substr((string)$raw, 0, 18), 20) . str_pad(substr($interpreted, 0, 18), 20) . $status . "\n";
}

// Simulate a $100k order
echo "\n==== SIMULATION: Orden de $100,000 COP (producto físico) ====\n";
$platform_raw = (float) get_option('ltms_platform_commission_rate', 0.10);
$physical_raw = (float) get_option('ltms_commission_physical', '');
$physical_rate = ($physical_raw > 1) ? $physical_raw / 100 : $physical_raw;
$platform_rate = max(0, min(1, $platform_raw));

$order_total = 100000;
$platform_fee = round($order_total * $platform_rate, 0);
$type_fee = round($order_total * $physical_rate, 0);
$vendor_net = $order_total - $platform_fee;

echo "  ltms_platform_commission_rate raw = {$platform_raw}\n";
echo "  Platform rate used = {$platform_rate} (" . round($platform_rate*100,1) . "%)\n";
echo "  Platform fee on $100k = $" . number_format($platform_fee) . "\n";
echo "  Vendor net = $" . number_format($vendor_net) . "\n";
if ($platform_rate > 0.5) {
    echo "  ❌ ERROR: Platform rate > 50% - ALGO ESTÁ MAL!\n";
} else {
    echo "  ✅ Cálculo OK\n";
}

// Check ReDi
echo "\n==== REDI RATE CHECK ====\n";
$redi_min_raw = (float) get_option('ltms_redi_min_rate', 5);
$redi_max_raw = (float) get_option('ltms_redi_max_rate', 40);
$redi_min = ($redi_min_raw < 1) ? round($redi_min_raw * 100) : $redi_min_raw;
$redi_max = ($redi_max_raw < 1) ? round($redi_max_raw * 100) : $redi_max_raw;
echo "  redi_min raw={$redi_min_raw} → frontend shows: {$redi_min}%\n";
echo "  redi_max raw={$redi_max_raw} → frontend shows: {$redi_max}%\n";

// Check clamp_redi_rate logic
$min_pct = (float) get_option('ltms_redi_min_rate', 5);
$max_pct = (float) get_option('ltms_redi_max_rate', 40);
echo "  clamp_redi_rate(0.20) = " . max($min_pct/100, min($max_pct/100, 0.20)) . "\n";
echo "  (if min=0.05, max=0.4: clamp(0.20) should be 0.20)\n";

echo "\n==== DONE ====\n";
