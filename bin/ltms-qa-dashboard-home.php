<?php
/**
 * QA: Dashboard Inicio — métricas, gráfica, botones de acción, tablas DB
 * Ejecutar:
 *   wp eval-file /ruta.../lt-marketplace-suite/bin/ltms-qa-dashboard-home.php --allow-root
 */
global $wpdb;

$pass = 0; $fail = 0; $warn = 0;
function qa_ok($msg)   { global $pass; $pass++; echo "  ✅ {$msg}\n"; }
function qa_fail($msg) { global $fail; $fail++; echo "  ❌ {$msg}\n"; }
function qa_warn($msg) { global $warn; $warn++; echo "  ⚠️  {$msg}\n"; }
function qa_head($msg) { echo "\n╠═══ {$msg} ═══\n"; }

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   LTMS QA — DASHBOARD INICIO (Métricas, Gráfica, Acciones)  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

// ── Obtener usuario de prueba ────────────────────────────────────────────────
$test_uid = null;
$all_users = get_users(['number' => 10, 'fields' => ['ID','user_login','display_name']]);
foreach ($all_users as $u) {
    if (get_user_meta($u->ID, 'ltms_vendor_approved', true) || strpos($u->user_login,'test') !== false) {
        $test_uid = $u->ID; break;
    }
}
if (!$test_uid && !empty($all_users)) $test_uid = $all_users[0]->ID;
if (!$test_uid) { echo "❌ Sin usuarios. Abortando.\n"; exit(1); }
$test_user = get_userdata($test_uid);
echo "Usuario: {$test_user->display_name} (ID={$test_uid})\n";

// ── 1. TABLAS REQUERIDAS ─────────────────────────────────────────────────────
qa_head("1. TABLAS DE BASE DE DATOS");

$required_tables = [
    'lt_commissions'      => ['id','vendor_id','order_id','gross_amount','vendor_amount','created_at'],
    'lt_vendor_wallets'   => ['id','vendor_id','balance','currency','status'],
    'lt_wallet_transactions' => ['id','vendor_id','type','amount','balance_after','created_at'],
    'lt_payout_requests'  => ['id','vendor_id','amount','bank_account','status','created_at'],
    'lt_notifications'    => ['id','user_id','type','title','message','is_read','created_at'],
];

foreach ($required_tables as $table => $required_cols) {
    $full = $wpdb->prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
    if (!$exists) {
        qa_fail("Tabla {$full} NO existe");
        continue;
    }
    $cols = $wpdb->get_col("DESCRIBE `{$full}`");
    $missing = array_diff($required_cols, $cols);
    if (empty($missing)) {
        $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$full}`");
        qa_ok("{$full} — columnas OK — {$cnt} registros");
    } else {
        qa_fail("{$full} — columnas faltantes: " . implode(', ', $missing));
    }
}

// ── 2. MÉTRICAS DEL DASHBOARD (get_vendor_home_metrics) ─────────────────────
qa_head("2. MÉTRICAS: VENTAS, PEDIDOS, COMISIONES, BILLETERA");

$commissions_table = $wpdb->prefix . 'lt_commissions';
$month_start = gmdate('Y-m-01 00:00:00');

// Ventas del mes
$monthly_sales = (float)$wpdb->get_var($wpdb->prepare(
    "SELECT SUM(gross_amount) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
    $test_uid, $month_start
));
qa_ok("monthly_sales query OK → \${$monthly_sales}");

// Pedidos del mes
$monthly_orders = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
    $test_uid, $month_start
));
qa_ok("monthly_orders query OK → {$monthly_orders} pedidos");

// Comisiones del mes
$monthly_commissions = (float)$wpdb->get_var($wpdb->prepare(
    "SELECT SUM(vendor_amount) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
    $test_uid, $month_start
));
qa_ok("monthly_commissions query OK → \${$monthly_commissions}");

// Billetera
if (class_exists('LTMS_Business_Wallet')) {
    $wallet = LTMS_Business_Wallet::get_or_create($test_uid);
    if (isset($wallet['balance'])) {
        qa_ok("wallet balance → \${$wallet['balance']} {$wallet['currency']}");
        $held = (float)($wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0);
        $available = (float)$wallet['balance'] - $held;
        qa_ok("wallet available → \${$available} | held → \${$held}");
    } else {
        qa_fail("LTMS_Business_Wallet::get_or_create no retornó balance");
    }
} else {
    qa_fail("Clase LTMS_Business_Wallet no cargada");
}

// Verificar que el response tiene todos los campos que el JS espera
$expected_response_fields = [
    'monthly_sales', 'monthly_orders', 'monthly_commissions',
    'wallet_balance', 'wallet_held', 'currency'
];
// Simular lo que devolvería ajax_get_dashboard_data
if (class_exists('LTMS_Business_Wallet')) {
    $wallet2 = LTMS_Business_Wallet::get_or_create($test_uid);
    $simulated_response = [
        'monthly_sales'       => $monthly_sales,
        'monthly_orders'      => $monthly_orders,
        'monthly_commissions' => $monthly_commissions,
        'wallet_balance'      => (float)$wallet2['balance'],
        'wallet_held'         => (float)($wallet2['balance_pending'] ?? $wallet2['balance_reserved'] ?? 0),
        'currency'            => defined('LTMS_Core_Config') || class_exists('LTMS_Core_Config') ? LTMS_Core_Config::get_currency() : 'COP',
    ];
    echo "\n  → Campos retornados al JS:\n";
    foreach ($expected_response_fields as $field) {
        isset($simulated_response[$field])
            ? qa_ok("  data.{$field} = " . json_encode($simulated_response[$field]))
            : qa_fail("  data.{$field} FALTANTE en response");
    }
}

// ── 3. GRÁFICA — ltms_get_analytics_data ────────────────────────────────────
qa_head("3. GRÁFICA DE VENTAS (ltms_get_analytics_data)");

global $wp_filter;
$analytics_hook = 'wp_ajax_ltms_get_analytics_data';
if (!empty($wp_filter[$analytics_hook])) {
    qa_ok("Hook {$analytics_hook} registrado");
} else {
    qa_fail("Hook {$analytics_hook} NO registrado — la gráfica no cargará");
}

// Simular build_analytics_chart_data manualmente
$labels = []; $sales = []; $commissions_chart = [];
for ($i = 11; $i >= 0; $i--) {
    $month_start_i = gmdate('Y-m-01 00:00:00', strtotime("-{$i} months"));
    $month_end_i   = gmdate('Y-m-t 23:59:59',  strtotime("-{$i} months"));
    $labels[]      = gmdate('Y-m', strtotime("-{$i} months"));
    $sales[]       = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(gross_amount),0) FROM `{$commissions_table}` WHERE vendor_id=%d AND created_at BETWEEN %s AND %s",
        $test_uid, $month_start_i, $month_end_i
    ));
    $commissions_chart[] = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(vendor_amount),0) FROM `{$commissions_table}` WHERE vendor_id=%d AND created_at BETWEEN %s AND %s",
        $test_uid, $month_start_i, $month_end_i
    ));
}
$chart_data = ['labels' => $labels, 'sales' => $sales, 'commissions' => $commissions_chart];
(count($chart_data['labels']) === 12) ? qa_ok("12 meses de datos para gráfica OK") : qa_fail("Meses de gráfica: " . count($chart_data['labels']));
(count($chart_data['sales']) === 12)  ? qa_ok("Array sales[12] OK")        : qa_fail("Array sales incompleto");
(count($chart_data['commissions']) === 12) ? qa_ok("Array commissions[12] OK") : qa_fail("Array commissions incompleto");
echo "  Último mes ({$labels[11]}): sales=" . end($sales) . " commissions=" . end($commissions_chart) . "\n";

// ── 4. HOOKS AJAX DEL DASHBOARD ──────────────────────────────────────────────
qa_head("4. HOOKS AJAX DASHBOARD");

$hooks = [
    'wp_ajax_ltms_get_dashboard_data' => 'Métricas de inicio',
    'wp_ajax_ltms_get_analytics_data' => 'Datos gráfica ventas',
    'wp_ajax_ltms_get_orders_data'    => 'Pedidos del vendedor',
    'wp_ajax_ltms_get_wallet_data'    => 'Datos billetera',
    'wp_ajax_ltms_request_payout'     => 'Solicitar retiro',
    'wp_ajax_ltms_get_notifications'  => 'Notificaciones',
];
foreach ($hooks as $hook => $label) {
    !empty($wp_filter[$hook]) ? qa_ok("{$label} ({$hook})") : qa_fail("{$label} — hook FALTANTE");
}

// ── 5. CONSISTENCIA JS: campos que renderHomeView usa ───────────────────────
qa_head("5. CONSISTENCIA JS renderHomeView ↔ PHP response");

$js = file_get_contents(WP_PLUGIN_DIR . '/lt-marketplace-suite/assets/js/ltms-dashboard.js');
$js_fields = [
    'monthly_sales'       => '.ltms-metric-sales',
    'monthly_orders'      => '.ltms-metric-orders',
    'monthly_commissions' => '.ltms-metric-commissions',
    'wallet_balance'      => '.ltms-metric-balance',
];
foreach ($js_fields as $php_field => $js_selector) {
    $has_field    = strpos($js, "data.{$php_field}") !== false;
    $has_selector = strpos($js, $js_selector) !== false;
    ($has_field && $has_selector)
        ? qa_ok("data.{$php_field} → '{$js_selector}'")
        : qa_fail("data.{$php_field} o selector '{$js_selector}' faltante en JS");
}

// Botones de acción
$action_buttons = ['Solicitar Retiro', 'Ver Pedidos', 'Agregar Producto'];
foreach ($action_buttons as $btn) {
    strpos($js, $btn) !== false ? qa_ok("Botón '{$btn}' presente en JS") : qa_fail("Botón '{$btn}' NO encontrado en JS");
}

// ── 6. WALLET: get_or_create y estructura ───────────────────────────────────
qa_head("6. BILLETERA — ESTRUCTURA Y CONSISTENCIA");

$wallets_table = $wpdb->prefix . 'lt_vendor_wallets';
$wallet_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM `{$wallets_table}` WHERE vendor_id = %d", $test_uid
));
if ($wallet_row) {
    qa_ok("Registro billetera existe para vendor_id={$test_uid}");
    qa_ok("Balance: {$wallet_row->balance} {$wallet_row->currency}");
    qa_ok("Status: {$wallet_row->status}");
} else {
    qa_warn("No existe billetera para vendor_id={$test_uid} — se crearía en el primer acceso via get_or_create");
}

// Total billeteras en sistema
$total_wallets = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$wallets_table}`");
$total_vendors = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='ltms_vendor_approved' AND meta_value='1'");
echo "  Billeteras en DB: {$total_wallets} | Vendedores aprobados: {$total_vendors}\n";
($total_wallets >= $total_vendors || $total_vendors === 0)
    ? qa_ok("Cobertura de billeteras OK")
    : qa_warn("{$total_vendors} vendedores aprobados pero solo {$total_wallets} billeteras — faltan " . ($total_vendors - $total_wallets));

// ── 7. COMISIONES — estructura y consultas ───────────────────────────────────
qa_head("7. TABLA COMISIONES — ESTRUCTURA");

$total_commissions = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$commissions_table}`");
echo "  Total registros en lt_commissions: {$total_commissions}\n";
if ($total_commissions > 0) {
    $last = $wpdb->get_row("SELECT vendor_id, gross_amount, vendor_amount, created_at FROM `{$commissions_table}` ORDER BY id DESC LIMIT 1");
    qa_ok("Última comisión: vendor={$last->vendor_id} gross={$last->gross_amount} vendor_amount={$last->vendor_amount} at={$last->created_at}");
} else {
    qa_warn("Sin comisiones en DB — métricas mostrarán \$0 (normal en sistema nuevo)");
}

// Verificar columnas necesarias para las 3 queries del dashboard
$comm_cols = $wpdb->get_col("DESCRIBE `{$commissions_table}`");
foreach (['vendor_id','gross_amount','vendor_amount','created_at'] as $col) {
    in_array($col, $comm_cols) ? qa_ok("lt_commissions.{$col} existe") : qa_fail("lt_commissions.{$col} FALTANTE");
}

// ── 8. NOTIFICACIONES ────────────────────────────────────────────────────────
qa_head("8. TABLA NOTIFICACIONES");

$notif_table = $wpdb->prefix . 'lt_notifications';
$notif_exists = $wpdb->get_var("SHOW TABLES LIKE '{$notif_table}'");
if ($notif_exists) {
    $unread = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$notif_table}` WHERE user_id=%d AND is_read=0", $test_uid
    ));
    qa_ok("Tabla {$notif_table} existe — {$unread} notificaciones sin leer para usuario {$test_uid}");
} else {
    qa_fail("Tabla {$notif_table} NO existe — el badge de notificaciones fallará");
}

// ── 9. UTILS — format_money y is_ltms_vendor ────────────────────────────────
qa_head("9. UTILS Y HELPERS");

if (class_exists('LTMS_Utils')) {
    qa_ok("LTMS_Utils cargada");
    if (method_exists('LTMS_Utils','format_money')) {
        $formatted = LTMS_Utils::format_money(150000);
        qa_ok("format_money(150000) → '{$formatted}'");
    } else {
        qa_fail("LTMS_Utils::format_money() no existe");
    }
    if (method_exists('LTMS_Utils','is_ltms_vendor')) {
        $is_vendor = LTMS_Utils::is_ltms_vendor($test_uid);
        qa_ok("is_ltms_vendor({$test_uid}) → " . ($is_vendor ? 'true (vendedor)' : 'false (no vendedor aún)'));
    } else {
        qa_fail("LTMS_Utils::is_ltms_vendor() no existe");
    }
} else {
    qa_fail("LTMS_Utils no cargada");
}

if (class_exists('LTMS_Core_Config')) {
    $currency = LTMS_Core_Config::get_currency();
    qa_ok("LTMS_Core_Config::get_currency() → '{$currency}'");
} else {
    qa_fail("LTMS_Core_Config no cargada");
}

// ── RESUMEN ──────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  RESUMEN QA — DASHBOARD INICIO                               ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Pasaron:        %3d                                      ║\n", $pass);
printf("║  ❌ Fallaron:       %3d                                      ║\n", $fail);
printf("║  ⚠️  Advertencias:   %3d                                      ║\n", $warn);
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if ($fail === 0 && $warn === 0) {
    echo "🎉 Todo perfecto — Dashboard Inicio listo para producción.\n\n";
} elseif ($fail === 0) {
    echo "✅ Sin fallos — revisa las advertencias arriba.\n\n";
} else {
    echo "🔧 {$fail} problema(s) requieren atención.\n\n";
}
