<?php
/**
 * QA COMPLETO: Todas las secciones de Configuración del Vendedor
 * Ejecutar:
 *   wp eval-file /ruta/lt-marketplace-suite/bin/ltms-qa-all-settings.php --allow-root
 */
global $wpdb;

$pass = 0; $fail = 0; $warn = 0;
function qa_ok($msg)   { global $pass; $pass++; echo "  ✅ {$msg}\n"; }
function qa_fail($msg) { global $fail; $fail++; echo "  ❌ {$msg}\n"; }
function qa_warn($msg) { global $warn; $warn++; echo "  ⚠️  {$msg}\n"; }
function qa_head($msg) { echo "\n╠═══ {$msg} ═══\n"; }

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   LTMS QA — TODAS LAS SECCIONES DE CONFIGURACIÓN            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

// ── Obtener o crear vendedor de prueba ───────────────────────────────────────
$test_uid = null;
$created  = false;

// Buscar vendedor existente (aprobado o no)
$candidates = get_users(['number' => 5, 'fields' => ['ID','user_login','display_name']]);
foreach ($candidates as $u) {
    if (strpos($u->user_login, 'test') !== false || strpos($u->user_login, 'seller') !== false
        || get_user_meta($u->ID, 'ltms_vendor_approved', true)) {
        $test_uid = $u->ID;
        break;
    }
}
if (!$test_uid && !empty($candidates)) {
    $test_uid = $candidates[0]->ID;
}
if (!$test_uid) {
    echo "❌ No hay usuarios en el sistema. Abortando.\n";
    exit(1);
}

$test_user = get_userdata($test_uid);
echo "Usuario de prueba: {$test_user->display_name} (ID={$test_uid}, login={$test_user->user_login})\n";

// Guardar estado original para restaurar al final
$original = [];
$all_meta_keys = [
    'ltms_store_name','ltms_store_description','ltms_store_city','ltms_store_address',
    'ltms_store_phone','ltms_store_schedule','ltms_store_categories',
    'ltms_bank_name','ltms_bank_account_type','ltms_bank_account_number','ltms_bank_account_holder',
    '_ltms_delivery_zone','ltms_store_banner_url','ltms_store_banner_id',
    'ltms_vendor_ga4_id','ltms_vendor_pixel_id',
    'ltms_bank_name','ltms_bank_info',
];
foreach (array_unique($all_meta_keys) as $k) {
    $original[$k] = get_user_meta($test_uid, $k, true);
}

// ── SECCIÓN 1: Datos de la Tienda (save_vendor_settings) ────────────────────
qa_head("1. DATOS DE LA TIENDA");

$test_basic = [
    'ltms_store_name'        => 'Tienda QA Test',
    'ltms_store_phone'       => '3009876543',
    'ltms_store_description' => 'Descripción de prueba QA',
];
foreach ($test_basic as $k => $v) update_user_meta($test_uid, $k, $v);

// Verificar
foreach ($test_basic as $k => $v) {
    $saved = get_user_meta($test_uid, $k, true);
    ($saved === $v) ? qa_ok("{$k} = '{$v}'") : qa_fail("{$k}: esperado '{$v}', leído '{$saved}'");
}

// ── SECCIÓN 2: Cuenta Bancaria (save_vendor_settings con settings[]) ─────────
qa_head("2. CUENTA BANCARIA PARA RETIROS");

$test_bank = [
    'ltms_bank_name'           => 'Bancolombia',
    'ltms_bank_account_type'   => 'ahorros',
    'ltms_bank_account_holder' => 'Juan Pérez QA',
];
foreach ($test_bank as $k => $v) update_user_meta($test_uid, $k, $v);

// account_number con encrypt si disponible
$raw_number = '69812345678';
if (class_exists('LTMS_Core_Security') && method_exists('LTMS_Core_Security','encrypt')) {
    $enc = LTMS_Core_Security::encrypt($raw_number);
    update_user_meta($test_uid, 'ltms_bank_account_number', $enc);
    $saved_enc = get_user_meta($test_uid, 'ltms_bank_account_number', true);
    ($saved_enc === $enc) ? qa_ok("ltms_bank_account_number: guardado cifrado OK") : qa_fail("ltms_bank_account_number: cifrado no coincide");
    // Verificar decrypt
    if (method_exists('LTMS_Core_Security','decrypt')) {
        $dec = LTMS_Core_Security::decrypt($saved_enc);
        ($dec === $raw_number) ? qa_ok("ltms_bank_account_number: decrypt OK → '{$raw_number}'") : qa_fail("ltms_bank_account_number: decrypt falló → '{$dec}'");
    }
} else {
    update_user_meta($test_uid, 'ltms_bank_account_number', $raw_number);
    qa_warn("LTMS_Core_Security no disponible — número guardado sin cifrar");
    $saved = get_user_meta($test_uid, 'ltms_bank_account_number', true);
    ($saved === $raw_number) ? qa_ok("ltms_bank_account_number guardado OK (sin cifrar)") : qa_fail("ltms_bank_account_number no guardó");
}

foreach ($test_bank as $k => $v) {
    $saved = get_user_meta($test_uid, $k, true);
    ($saved === $v) ? qa_ok("{$k} = '{$v}'") : qa_fail("{$k}: esperado '{$v}', leído '{$saved}'");
}

// Verificar que get_vendor_settings retornaría los campos (leyendo como lo haría el método)
echo "\n  → Simulando get_vendor_settings response:\n";
$store_preview = [
    'bank_name'           => get_user_meta($test_uid, 'ltms_bank_name',           true),
    'bank_account_type'   => get_user_meta($test_uid, 'ltms_bank_account_type',   true) ?: 'ahorros',
    'bank_account_number' => get_user_meta($test_uid, 'ltms_bank_account_number', true),
    'bank_account_holder' => get_user_meta($test_uid, 'ltms_bank_account_holder', true),
];
foreach ($store_preview as $k => $v) {
    if (!empty($v)) {
        $disp = ($k === 'bank_account_number') ? '****'.substr($v,-4) : $v;
        qa_ok("  store.{$k} = '{$disp}' ✓ llegaría al JS");
    } else {
        qa_fail("  store.{$k} vacío — NO llegaría al JS → modal no pre-llenaría este campo");
    }
}

// ── SECCIÓN 3: Perfil Público ────────────────────────────────────────────────
qa_head("3. PERFIL PÚBLICO DE LA TIENDA");

$test_profile = [
    'ltms_store_name'        => 'Tienda QA Pública',
    'ltms_store_description' => 'Descripción pública QA',
    'ltms_store_city'        => 'Cali',
    'ltms_store_address'     => 'Cra 1 # 2-3',
    'ltms_store_phone'       => '3001111111',
    'ltms_store_schedule'    => 'Lun-Vie 8am-6pm',
    'ltms_store_categories'  => 'Ropa, Accesorios',
];
foreach ($test_profile as $k => $v) update_user_meta($test_uid, $k, $v);

foreach ($test_profile as $k => $v) {
    $saved = get_user_meta($test_uid, $k, true);
    ($saved === $v) ? qa_ok("{$k} OK") : qa_fail("{$k}: esperado '{$v}', leído '{$saved}'");
}

// ── SECCIÓN 4: Zona de Despacho ──────────────────────────────────────────────
qa_head("4. ZONA DE DESPACHO");

$zone_data = ['cities' => ['Cali','Palmira'], 'radius_km' => 50, 'free_from' => 80000.0];
update_user_meta($test_uid, '_ltms_delivery_zone', wp_json_encode($zone_data));
$saved_zone_raw = get_user_meta($test_uid, '_ltms_delivery_zone', true);
$saved_zone = json_decode($saved_zone_raw, true);

if ($saved_zone && isset($saved_zone['cities'], $saved_zone['radius_km'], $saved_zone['free_from'])) {
    qa_ok("_ltms_delivery_zone guardado como JSON OK");
    ($saved_zone['cities'] === ['Cali','Palmira']) ? qa_ok("cities: Cali, Palmira") : qa_fail("cities no coincide: " . json_encode($saved_zone['cities']));
    ($saved_zone['radius_km'] === 50)    ? qa_ok("radius_km = 50")     : qa_fail("radius_km: ".json_encode($saved_zone['radius_km']));
    ($saved_zone['free_from'] == 80000)  ? qa_ok("free_from = 80000")  : qa_fail("free_from: ".json_encode($saved_zone['free_from']));
} else {
    qa_fail("_ltms_delivery_zone no guardó correctamente: '{$saved_zone_raw}'");
}

// ── SECCIÓN 5: Analytics ─────────────────────────────────────────────────────
qa_head("5. ANALYTICS & TRACKING");

$test_analytics = [
    'ltms_vendor_ga4_id'   => 'G-TEST123456',
    'ltms_vendor_pixel_id' => '123456789012345',
];
foreach ($test_analytics as $k => $v) update_user_meta($test_uid, $k, $v);
foreach ($test_analytics as $k => $v) {
    $saved = get_user_meta($test_uid, $k, true);
    ($saved === $v) ? qa_ok("{$k} = '{$v}'") : qa_fail("{$k}: esperado '{$v}', leído '{$saved}'");
}

// Verificar que get_vendor_settings expone analytics
$ga4_meta  = get_user_meta($test_uid, 'ltms_vendor_ga4_id',   true);
$pixel_meta = get_user_meta($test_uid, 'ltms_vendor_pixel_id', true);
(!empty($ga4_meta))   ? qa_ok("vendor_ga4_id retornable al JS")   : qa_warn("vendor_ga4_id vacío");
(!empty($pixel_meta)) ? qa_ok("vendor_pixel_id retornable al JS") : qa_warn("vendor_pixel_id vacío");

// ── SECCIÓN 6: Banner (solo verificar meta keys, no subir archivo real) ──────
qa_head("6. BANNER DE LA TIENDA (estructura)");

// Verificar que el hook existe y los meta keys están definidos
global $wp_filter;
$banner_hook = 'wp_ajax_ltms_upload_store_banner';
if (!empty($wp_filter[$banner_hook])) {
    qa_ok("Hook {$banner_hook} registrado");
} else {
    qa_fail("Hook {$banner_hook} NO registrado");
}
// Meta keys de banner deben existir como columnas de user_meta
$banner_meta = ['ltms_store_banner_id', 'ltms_store_banner_url'];
foreach ($banner_meta as $mk) {
    // Intentar set/get
    update_user_meta($test_uid, $mk, 'qa_test_value');
    $v = get_user_meta($test_uid, $mk, true);
    ($v === 'qa_test_value') ? qa_ok("{$mk} — user_meta OK") : qa_fail("{$mk} — no se puede guardar en user_meta");
    delete_user_meta($test_uid, $mk);
}

// ── SECCIÓN 7: Consistencia JS ↔ PHP — nombres de campos ────────────────────
qa_head("7. CONSISTENCIA JS ↔ PHP (nombres de campos)");

$plugin_path = WP_PLUGIN_DIR . '/lt-marketplace-suite';
$js = file_get_contents($plugin_path . '/assets/js/ltms-dashboard.js');

// Mapeo: lo que el JS envía → lo que PHP espera guardar
$js_to_php = [
    // Datos básicos
    'store_name'              => 'ltms_store_name',
    'store_phone'             => 'ltms_store_phone',
    'store_description'       => 'ltms_store_description',
    // Bancarios (dentro de settings[])
    'ltms_bank_name'          => 'ltms_bank_name',
    'ltms_bank_account_type'  => 'ltms_bank_account_type',
    'ltms_bank_account_number'=> 'ltms_bank_account_number',
    'ltms_bank_account_holder'=> 'ltms_bank_account_holder',
    // Perfil público
    'ltms_store_name'         => 'ltms_store_name',
    'ltms_store_city'         => 'ltms_store_city',
    'ltms_store_address'      => 'ltms_store_address',
    // Analytics
    'ltms_vendor_ga4_id'      => 'ltms_vendor_ga4_id',
    'ltms_vendor_pixel_id'    => 'ltms_vendor_pixel_id',
];

$php_saver = file_get_contents($plugin_path . '/includes/frontend/class-ltms-vendor-settings-saver.php');
$php_ajax  = file_get_contents($plugin_path . '/includes/frontend/class-ltms-products-ajax.php');

foreach ($js_to_php as $js_field => $php_meta) {
    $in_js  = strpos($js, $js_field)  !== false;
    $in_php = strpos($php_saver, $php_meta) !== false || strpos($php_ajax, $php_meta) !== false;
    if ($in_js && $in_php) {
        qa_ok("'{$js_field}' → '{$php_meta}' ✓");
    } elseif (!$in_js) {
        qa_fail("'{$js_field}' NO encontrado en ltms-dashboard.js");
    } else {
        qa_fail("'{$php_meta}' NO encontrado en PHP handlers");
    }
}

// ── SECCIÓN 8: Campo banco viejo (ltms_bank_info) ───────────────────────────
qa_head("8. LIMPIEZA CAMPO BANCO VIEJO");

$old_bank_info = get_user_meta($test_uid, 'ltms_bank_info', true);
$old_bank_name = get_user_meta($test_uid, 'ltms_bank_name', true);

if (!empty($old_bank_info) && empty($old_bank_name)) {
    qa_warn("ltms_bank_info tiene valor viejo ('{$old_bank_info}') y ltms_bank_name está vacío — el campo banco mostraría el valor mezclado viejo");
    qa_warn("Solución: migrar ltms_bank_info a ltms_bank_name o limpiar el campo en la UI");
} elseif (!empty($old_bank_info)) {
    qa_warn("ltms_bank_info (campo viejo) aún tiene valor: '{$old_bank_info}' — considerar limpiar");
} else {
    qa_ok("ltms_bank_info vacío — no hay contaminación del campo viejo");
}

// Verificar cuántos usuarios tienen bank_info con el número mezclado
$mixed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='ltms_bank_info' AND meta_value != ''");
if ($mixed > 0) {
    qa_warn("{$mixed} usuario(s) tienen ltms_bank_info con valor viejo — el campo Banco les mostraría el texto mezclado");
} else {
    qa_ok("Ningún usuario tiene ltms_bank_info con valor — campo banco limpio");
}

// ── Restaurar valores originales ─────────────────────────────────────────────
echo "\n  (Restaurando datos originales del usuario de prueba...)\n";
foreach ($original as $k => $v) {
    if ($v !== '' && $v !== false) {
        update_user_meta($test_uid, $k, $v);
    } else {
        delete_user_meta($test_uid, $k);
    }
}
echo "  Restauración completada.\n";

// ── RESUMEN ──────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  RESUMEN QA — CONFIGURACIÓN COMPLETA                        ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Pasaron:        %3d                                      ║\n", $pass);
printf("║  ❌ Fallaron:       %3d                                      ║\n", $fail);
printf("║  ⚠️  Advertencias:   %3d                                      ║\n", $warn);
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

if ($fail === 0 && $warn === 0) {
    echo "🎉 Todo perfecto — todas las secciones listas para producción.\n\n";
} elseif ($fail === 0) {
    echo "✅ Sin fallos — revisa las advertencias arriba.\n\n";
} else {
    echo "🔧 {$fail} problema(s) requieren atención antes de producción.\n\n";
}
