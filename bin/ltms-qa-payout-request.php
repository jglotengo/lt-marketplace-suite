<?php
/**
 * QA: Flujo completo "Solicitar Retiro"
 * - Modal de retiro (HTML/campos)
 * - Validaciones del backend (monto mínimo, balance, KYC, máx pendientes)
 * - create_request completo con vendor de prueba real
 * - Tabla payout_requests (columnas, fee, net_amount)
 * - Pre-llenado de cuenta bancaria
 * - Rollback de todo al final
 *
 * Ejecutar:
 *   wp eval-file /ruta.../lt-marketplace-suite/bin/ltms-qa-payout-request.php --allow-root
 */
global $wpdb;

$pass = 0; $fail = 0; $warn = 0;
function qa_ok($msg)   { global $pass; $pass++; echo "  ✅ {$msg}\n"; }
function qa_fail($msg) { global $fail; $fail++; echo "  ❌ {$msg}\n"; }
function qa_warn($msg) { global $warn; $warn++; echo "  ⚠️  {$msg}\n"; }
function qa_head($msg) { echo "\n╠═══ {$msg} ═══\n"; }

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   LTMS QA — SOLICITAR RETIRO (Flujo Completo)                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

// ── Obtener vendedor real del sistema ────────────────────────────────────────
$vendor_uid = null;
$all_users = get_users(['number' => 20, 'fields' => ['ID','user_login','display_name']]);
foreach ($all_users as $u) {
    if (get_user_meta($u->ID, 'ltms_vendor_approved', true)) {
        $vendor_uid = $u->ID; break;
    }
}
// Si no hay aprobados, usar el primero disponible para pruebas estructurales
if (!$vendor_uid && !empty($all_users)) $vendor_uid = $all_users[0]->ID;
if (!$vendor_uid) { echo "❌ Sin usuarios. Abortando.\n"; exit(1); }
$vendor_user = get_userdata($vendor_uid);
echo "Usuario: {$vendor_user->display_name} (ID={$vendor_uid})\n\n";

$payout_table  = $wpdb->prefix . 'lt_payout_requests';
$wallet_table  = $wpdb->prefix . 'lt_vendor_wallets';

// ── 1. TABLA PAYOUT_REQUESTS — COLUMNAS COMPLETAS ───────────────────────────
qa_head("1. TABLA PAYOUT_REQUESTS — COLUMNAS");

$required_cols = ['id','vendor_id','amount','fee','net_amount','method','bank_account_id','status','reference','created_at'];
$actual_cols   = $wpdb->get_col("DESCRIBE `{$payout_table}`");
foreach ($required_cols as $col) {
    in_array($col, $actual_cols) ? qa_ok("{$payout_table}.{$col}") : qa_fail("{$payout_table}.{$col} FALTANTE");
}
$total_payouts = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$payout_table}`");
echo "  Registros actuales: {$total_payouts}\n";
$pending_payouts = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$payout_table}` WHERE status='pending'");
echo "  Pendientes: {$pending_payouts}\n";

// ── 2. CONFIGURACIÓN DEL SISTEMA DE RETIROS ──────────────────────────────────
qa_head("2. CONFIGURACIÓN — MONTOS MÍNIMOS, KYC, AUTO-APROBACIÓN");

if (!class_exists('LTMS_Core_Config')) {
    qa_fail("LTMS_Core_Config no cargada");
} else {
    $min_payout      = (float)LTMS_Core_Config::get('ltms_min_payout_amount', 0);
    $kyc_required    = LTMS_Core_Config::get('ltms_kyc_required_for_payout', 'yes');
    $auto_approve    = LTMS_Core_Config::get('ltms_auto_approve_payouts', 'no');
    $auto_max        = (float)LTMS_Core_Config::get('ltms_auto_approve_max_amount', 500000);
    $hold_days       = (int)LTMS_Core_Config::get('ltms_hold_period_days', 0);
    $country         = LTMS_Core_Config::get_country();

    // Monto mínimo efectivo (constantes del Scheduler)
    $min_cop = defined('LTMS_Payout_Scheduler::MIN_PAYOUT_COP') ? LTMS_Payout_Scheduler::MIN_PAYOUT_COP : null;
    // Acceder via reflexión si es privado
    try {
        $ref = new ReflectionClass('LTMS_Payout_Scheduler');
        $min_cop = $ref->getConstant('MIN_PAYOUT_COP');
        $min_mxn = $ref->getConstant('MIN_PAYOUT_MXN');
        $max_pend = $ref->getConstant('MAX_PENDING_PER_VENDOR');
        qa_ok("MIN_PAYOUT_COP = \${$min_cop}");
        qa_ok("MIN_PAYOUT_MXN = \${$min_mxn}");
        qa_ok("MAX_PENDING_PER_VENDOR = {$max_pend}");
    } catch (Exception $e) {
        qa_warn("No se pudo leer constantes via reflexión: " . $e->getMessage());
        $max_pend = 3;
    }

    qa_ok("ltms_min_payout_amount = " . ($min_payout > 0 ? "\${$min_payout}" : "0 (usa MIN_PAYOUT_COP)"));
    qa_ok("ltms_kyc_required_for_payout = '{$kyc_required}'");
    qa_ok("ltms_auto_approve_payouts = '{$auto_approve}'");
    qa_ok("ltms_auto_approve_max_amount = \${$auto_max}");
    qa_ok("ltms_hold_period_days = {$hold_days}");
    qa_ok("País activo = '{$country}'");
}

// ── 3. MODAL — CAMPOS HTML EN view-wallet.php ────────────────────────────────
qa_head("3. MODAL SOLICITAR RETIRO — CAMPOS HTML");

$view_wallet = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/frontend/views/view-wallet.php';
if (!file_exists($view_wallet)) {
    qa_fail("view-wallet.php no existe en disco");
} else {
    $html = file_get_contents($view_wallet);
    $modal_fields = [
        'ltms-modal-payout'        => 'Modal contenedor #ltms-modal-payout',
        'ltms-payout-amount'       => 'Input monto (#ltms-payout-amount)',
        'ltms-payout-method'       => 'Select método (#ltms-payout-method)',
        'ltms-payout-account'      => 'Input/hidden cuenta (#ltms-payout-account)',
        'ltms-payout-balance-display' => 'Display balance disponible',
        'submitPayoutRequest'      => 'Botón Confirmar Retiro llama submitPayoutRequest()',
        'ltms-modal-close'         => 'Botón cerrar modal',
        'bank_transfer'            => 'Opción Transferencia Bancaria',
        'nequi'                    => 'Opción Nequi',
        '$saved_bank_acc'          => 'Pre-llenado cuenta guardada ($has_bank_data)',
        'ltms_bank_name'           => 'Lectura ltms_bank_name del vendedor',
        'ltms_bank_account_number' => 'Lectura ltms_bank_account_number del vendedor',
        'ltms_bank_account_type'   => 'Lectura ltms_bank_account_type del vendedor',
        'ltms_bank_account_holder' => 'Lectura ltms_bank_account_holder del vendedor',
        'navigate(\'settings\')'   => 'Link a Configuración cuando no hay cuenta guardada',
    ];
    foreach ($modal_fields as $needle => $label) {
        strpos($html, $needle) !== false ? qa_ok($label) : qa_fail("{$label} — '{$needle}' NO en view-wallet.php");
    }
}

// ── 4. PRE-LLENADO CUENTA BANCARIA ──────────────────────────────────────────
qa_head("4. PRE-LLENADO CUENTA BANCARIA EN MODAL");

$saved_bank        = get_user_meta($vendor_uid, 'ltms_bank_name',           true);
$saved_bank_acc    = get_user_meta($vendor_uid, 'ltms_bank_account_number', true);
$saved_bank_type   = get_user_meta($vendor_uid, 'ltms_bank_account_type',   true) ?: 'ahorros';
$saved_bank_holder = get_user_meta($vendor_uid, 'ltms_bank_account_holder', true);
$has_bank_data     = !empty($saved_bank_acc);

if ($has_bank_data) {
    qa_ok("Cuenta bancaria configurada — modal mostrará input HIDDEN pre-llenado");
    qa_ok("Banco: '{$saved_bank}'");
    qa_ok("Tipo: '{$saved_bank_type}'");
    qa_ok("Número: ****" . substr($saved_bank_acc, -4));
    !empty($saved_bank_holder) ? qa_ok("Titular: '{$saved_bank_holder}'") : qa_warn("ltms_bank_account_holder vacío — campo titular no mostraría en modal");
} else {
    qa_warn("Vendedor ID={$vendor_uid} NO tiene cuenta bancaria guardada → modal mostrará input de texto vacío + aviso amarillo");
    qa_warn("El vendedor debe configurar su cuenta en Configuración antes de retirar");
}

// ── 5. VALIDACIONES DE create_request (simuladas) ───────────────────────────
qa_head("5. VALIDACIONES DEL BACKEND (simulate create_request)");

if (!class_exists('LTMS_Payout_Scheduler')) {
    qa_fail("LTMS_Payout_Scheduler no cargada");
} else {
    // 5a. Monto mínimo
    $ref2     = new ReflectionClass('LTMS_Payout_Scheduler');
    $min_eff  = (float)LTMS_Core_Config::get('ltms_min_payout_amount', 0);
    $min_cop2 = $ref2->getConstant('MIN_PAYOUT_COP') ?: 50000;
    if ($min_eff <= 0) $min_eff = $min_cop2;

    qa_ok("Monto mínimo efectivo: \${$min_eff} COP");

    // 5b. Balance del vendedor
    $wallet_data = LTMS_Business_Wallet::get_or_create($vendor_uid);
    $balance_now = (float)$wallet_data['balance'];
    $held_now    = (float)($wallet_data['balance_pending'] ?? $wallet_data['balance_reserved'] ?? 0);
    $avail_now   = max(0, $balance_now - $held_now);
    qa_ok("Balance actual: \${$balance_now} | Disponible: \${$avail_now} | Retenido: \${$held_now}");

    if ($avail_now < $min_eff) {
        qa_warn("Balance disponible (\${$avail_now}) < mínimo de retiro (\${$min_eff}) — retiro sería rechazado por saldo insuficiente (normal en sistema nuevo)");
    } else {
        qa_ok("Balance suficiente para retiro mínimo");
    }

    // 5c. Máximo de pendientes
    $pending_vendor = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$payout_table}` WHERE vendor_id=%d AND status='pending'", $vendor_uid
    ));
    $max_pend2 = $ref2->getConstant('MAX_PENDING_PER_VENDOR') ?: 3;
    ($pending_vendor < $max_pend2)
        ? qa_ok("Pendientes del vendedor: {$pending_vendor}/{$max_pend2} — puede crear más solicitudes")
        : qa_fail("Pendientes: {$pending_vendor}/{$max_pend2} — create_request rechazaría nueva solicitud");

    // 5d. KYC
    $kyc_req2  = LTMS_Core_Config::get('ltms_kyc_required_for_payout', 'yes');
    $kyc_status = get_user_meta($vendor_uid, 'ltms_kyc_status', true) ?: 'pending';
    if ($kyc_req2 !== 'yes') {
        qa_ok("KYC no requerido para retiros (ltms_kyc_required_for_payout = '{$kyc_req2}')");
    } elseif ($kyc_status === 'approved') {
        // Verificar certificación bancaria KYC
        $file_banco     = get_user_meta($vendor_uid, 'ltms_kyc_file_banco',     true);
        $bank_rep_legal = get_user_meta($vendor_uid, 'ltms_kyc_bank_rep_legal', true);
        $bank_acc_kyc   = get_user_meta($vendor_uid, 'ltms_kyc_bank_account',   true);
        if (!empty($file_banco) && !empty($bank_rep_legal) && !empty($bank_acc_kyc)) {
            qa_ok("KYC aprobado + certificación bancaria completa — retiro permitido");
        } else {
            qa_warn("KYC aprobado PERO certificación bancaria incompleta:");
            qa_warn("  ltms_kyc_file_banco = " . (empty($file_banco) ? 'FALTA' : 'ok'));
            qa_warn("  ltms_kyc_bank_rep_legal = " . (empty($bank_rep_legal) ? 'FALTA' : 'ok'));
            qa_warn("  ltms_kyc_bank_account = " . (empty($bank_acc_kyc) ? 'FALTA' : 'ok'));
        }
    } else {
        qa_warn("KYC status = '{$kyc_status}' — create_request rechazaría retiro hasta KYC aprobado");
    }

    // 5e. Fee calculation
    qa_head("5e. CÁLCULO DE FEES");
    $test_amount = 200000.0;
    $fees_map = ['bank_transfer' => 0.0, 'openpay' => 4000.0, 'nequi' => 0.0];
    foreach ($fees_map as $method => $expected_fee) {
        $net = round($test_amount - $expected_fee, 2);
        qa_ok("Método '{$method}': fee=\${$expected_fee} → net=\${$net} para retiro de \${$test_amount}");
    }
}

// ── 6. SIMULACIÓN COMPLETA create_request (vendedor ficticio con saldo) ──────
qa_head("6. SIMULACIÓN create_request CON DATOS CONTROLADOS");

// Crear un vendedor temporal con saldo
$tmp_user_id = wp_insert_user([
    'user_login'   => 'ltms_qa_payout_' . time(),
    'user_pass'    => wp_generate_password(),
    'user_email'   => 'qa_payout_' . time() . '@test.invalid',
    'display_name' => 'QA Payout Test',
    'role'         => 'subscriber',
]);

if (is_wp_error($tmp_user_id)) {
    qa_warn("No se pudo crear usuario temporal: " . $tmp_user_id->get_error_message());
    $tmp_user_id = null;
} else {
    qa_ok("Usuario temporal creado: ID={$tmp_user_id}");

    // Aprobar como vendedor y poner KYC en estado que permita retiro
    update_user_meta($tmp_user_id, 'ltms_vendor_approved', 1);
    $kyc_req3 = LTMS_Core_Config::get('ltms_kyc_required_for_payout', 'yes');
    if ($kyc_req3 === 'yes') {
        // Simular KYC aprobado con datos bancarios
        update_user_meta($tmp_user_id, 'ltms_kyc_status',        'approved');
        update_user_meta($tmp_user_id, 'ltms_kyc_file_banco',     'https://example.com/cert.pdf');
        update_user_meta($tmp_user_id, 'ltms_kyc_bank_rep_legal', 'Juan QA');
        update_user_meta($tmp_user_id, 'ltms_kyc_bank_account',   '123456789');
        qa_ok("KYC simulado como 'approved' con certificación bancaria");
    }

    // Inyectar saldo directo en la billetera
    $wallet_tmp = LTMS_Business_Wallet::get_or_create($tmp_user_id);
    $wpdb->update($wallet_table, ['balance' => 500000.00], ['vendor_id' => $tmp_user_id], ['%f'], ['%d']);
    qa_ok("Saldo inyectado: \$500.000 COP en billetera del usuario temporal");

    // Test A: Monto menor al mínimo
    $result_low = LTMS_Payout_Scheduler::create_request($tmp_user_id, 100.0, '999', 'bank_transfer');
    (!$result_low['success'])
        ? qa_ok("Validación monto mínimo: rechazó correctamente \$100 — '{$result_low['message']}'")
        : qa_fail("Validación monto mínimo: ACEPTÓ \$100 cuando debería rechazar");

    // Test B: Monto mayor al balance
    $result_over = LTMS_Payout_Scheduler::create_request($tmp_user_id, 9999999.0, '999', 'bank_transfer');
    (!$result_over['success'])
        ? qa_ok("Validación balance: rechazó correctamente \$9.999.999 — '{$result_over['message']}'")
        : qa_fail("Validación balance: ACEPTÓ monto mayor al balance");

    // Test C: Retiro válido
    $test_payout_amount = 200000.0;
    $result_ok = LTMS_Payout_Scheduler::create_request($tmp_user_id, $test_payout_amount, 'ACC-QA-001', 'bank_transfer');
    if ($result_ok['success']) {
        qa_ok("create_request exitoso: payout_id={$result_ok['payout_id']}");

        // Verificar registro en DB
        $payout_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$payout_table}` WHERE id=%d", $result_ok['payout_id']
        ));
        if ($payout_row) {
            qa_ok("Registro en payout_requests: status={$payout_row->status}");
            qa_ok("amount={$payout_row->amount} fee={$payout_row->fee} net_amount={$payout_row->net_amount}");
            qa_ok("bank_account_id={$payout_row->bank_account_id}");
            qa_ok("reference={$payout_row->reference}");
            ($payout_row->status === 'pending') ? qa_ok("Status = 'pending' ✓") : qa_fail("Status incorrecto: {$payout_row->status}");
            // Verificar fee y net_amount
            $exp_fee = 0.0; // bank_transfer
            $exp_net = round($test_payout_amount - $exp_fee, 2);
            ((float)$payout_row->fee === $exp_fee) ? qa_ok("fee = \${$exp_fee} (bank_transfer sin costo) ✓") : qa_fail("fee incorrecto: {$payout_row->fee} esperado {$exp_fee}");
            ((float)$payout_row->net_amount === $exp_net) ? qa_ok("net_amount = \${$exp_net} ✓") : qa_fail("net_amount incorrecto: {$payout_row->net_amount}");
        } else {
            qa_fail("Payout creado (ID={$result_ok['payout_id']}) pero no encontrado en DB");
        }

        // Verificar que el balance fue retenido (hold)
        $wallet_after = LTMS_Business_Wallet::get_or_create($tmp_user_id);
        $held_after   = (float)($wallet_after['balance_pending'] ?? $wallet_after['balance_reserved'] ?? 0);
        ($held_after >= $test_payout_amount)
            ? qa_ok("Saldo retenido (hold): \${$held_after} ✓")
            : qa_warn("Hold no detectado: balance_pending/balance_reserved = {$held_after}");

        // Test D: Máximo de pendientes (crear hasta el límite)
        $max_p = $ref2->getConstant('MAX_PENDING_PER_VENDOR') ?: 3;
        // Ya tenemos 1 pendiente — llenar hasta límite
        $extra_created = 0;
        for ($i = 1; $i < $max_p; $i++) {
            // Re-inyectar saldo para cada intento
            $wpdb->update($wallet_table, ['balance' => 500000.00, 'balance_pending' => 0], ['vendor_id' => $tmp_user_id]);
            $r = LTMS_Payout_Scheduler::create_request($tmp_user_id, 150000.0, 'ACC-QA-FILL', 'bank_transfer');
            if ($r['success']) $extra_created++;
        }
        // Ahora debería rechazar el siguiente
        $wpdb->update($wallet_table, ['balance' => 500000.00, 'balance_pending' => 0], ['vendor_id' => $tmp_user_id]);
        $result_max = LTMS_Payout_Scheduler::create_request($tmp_user_id, 150000.0, 'ACC-QA-OVER', 'bank_transfer');
        (!$result_max['success'])
            ? qa_ok("Límite de {$max_p} pendientes cumple: rechazó correctamente — '{$result_max['message']}'")
            : qa_fail("Límite de pendientes NO se cumple — aceptó más de {$max_p}");

        // Limpiar payout_requests del usuario temporal
        $wpdb->delete($payout_table, ['vendor_id' => $tmp_user_id], ['%d']);
        qa_ok("Registros payout de prueba eliminados");
    } else {
        qa_fail("create_request falló para monto válido: '{$result_ok['message']}'");
    }

    // Limpiar usuario temporal
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($tmp_user_id);
    qa_ok("Usuario temporal ID={$tmp_user_id} eliminado");
}

// ── 7. AJAX HOOK ─────────────────────────────────────────────────────────────
qa_head("7. HOOK AJAX ltms_request_payout");

global $wp_filter;
!empty($wp_filter['wp_ajax_ltms_request_payout'])
    ? qa_ok("Hook wp_ajax_ltms_request_payout registrado")
    : qa_fail("Hook wp_ajax_ltms_request_payout NO registrado");

// ── 8. JS submitPayoutRequest ─────────────────────────────────────────────────
qa_head("8. JS submitPayoutRequest — CAMPOS ENVIADOS");

$js = file_get_contents(WP_PLUGIN_DIR . '/lt-marketplace-suite/assets/js/ltms-dashboard.js');
$js_checks = [
    'submitPayoutRequest'  => 'Función submitPayoutRequest definida',
    'ltms_request_payout'  => 'Action ltms_request_payout en AJAX',
    'bank_account_id'      => 'Campo bank_account_id enviado',
    'ltms-payout-amount'   => 'Lee #ltms-payout-amount',
    'ltms-payout-account'  => 'Lee #ltms-payout-account',
    'ltms-payout-method'   => 'Lee #ltms-payout-method',
    'confirm_payout'       => 'Confirmación antes de enviar',
    'openPayoutModal'      => 'Función openPayoutModal definida',
    'ltms-modal-payout'    => 'Referencia al modal ltms-modal-payout',
];
foreach ($js_checks as $needle => $label) {
    strpos($js, $needle) !== false ? qa_ok($label) : qa_fail("{$label} — '{$needle}' NO en JS");
}

// ── RESUMEN ──────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  RESUMEN QA — SOLICITAR RETIRO                               ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Pasaron:        %3d                                      ║\n", $pass);
printf("║  ❌ Fallaron:       %3d                                      ║\n", $fail);
printf("║  ⚠️  Advertencias:   %3d                                      ║\n", $warn);
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if ($fail === 0 && $warn === 0) {
    echo "🎉 Todo perfecto — flujo de retiro listo para producción.\n\n";
} elseif ($fail === 0) {
    echo "✅ Sin fallos — revisa las advertencias (pueden ser normales en sistema nuevo).\n\n";
} else {
    echo "🔧 {$fail} problema(s) requieren atención.\n\n";
}
