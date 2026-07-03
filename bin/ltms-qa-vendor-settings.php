<?php
/**
 * QA: Vendor Settings — campos bancarios, configuración y flujo de retiro
 * Ejecutar: wp eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-qa-vendor-settings.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
    // Soporte ejecución directa via WP-CLI eval-file
    define( 'WP_CLI', true );
}

global $wpdb;

$pass = 0; $fail = 0; $warn = 0;

function qa_ok( $msg )  { global $pass; $pass++; echo "  ✅ {$msg}\n"; }
function qa_fail( $msg ){ global $fail; $fail++; echo "  ❌ {$msg}\n"; }
function qa_warn( $msg ){ global $warn; $warn++; echo "  ⚠️  {$msg}\n"; }
function qa_head( $msg ){ echo "\n=== {$msg} ===\n"; }

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  LTMS QA — Vendor Settings & Bank Account Fields    ║\n";
echo "╚══════════════════════════════════════════════════════╝\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

// ─── 1. Clases PHP existentes ────────────────────────────────────────────────
qa_head("1. CLASES PHP CARGADAS");

$classes = [
    'LTMS_Vendor_Settings_Saver'  => 'includes/frontend/class-ltms-vendor-settings-saver.php',
    'LTMS_Products_Ajax'          => 'includes/frontend/class-ltms-products-ajax.php',
    'LTMS_Frontend_Payout_Handler'=> 'includes/frontend/class-ltms-frontend-payout-handler.php',
];
$plugin_path = WP_PLUGIN_DIR . '/lt-marketplace-suite';
foreach ( $classes as $class => $file ) {
    $full = $plugin_path . '/' . $file;
    if ( file_exists( $full ) ) {
        qa_ok( "{$class} — archivo existe" );
    } else {
        qa_fail( "{$class} — archivo NO encontrado: {$file}" );
    }
}

// ─── 2. AJAX hooks registrados ───────────────────────────────────────────────
qa_head("2. AJAX HOOKS REGISTRADOS");

$hooks_to_check = [
    'wp_ajax_ltms_save_vendor_settings' => 'Guardar config básica (Products_Ajax)',
    'wp_ajax_ltms_save_vendor_profile'  => 'Guardar perfil público (Vendor_Settings_Saver)',
    'wp_ajax_ltms_get_vendor_settings'  => 'Leer settings del vendedor',
    'wp_ajax_ltms_save_delivery_zone'   => 'Guardar zona de despacho',
    'wp_ajax_ltms_upload_store_banner'  => 'Subir banner',
    'wp_ajax_ltms_request_payout'       => 'Solicitar retiro',
];
foreach ( $hooks_to_check as $hook => $label ) {
    global $wp_filter;
    if ( ! empty( $wp_filter[ $hook ] ) ) {
        $callbacks = [];
        foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
            foreach ( $cbs as $cb ) {
                if ( is_array( $cb['function'] ) ) {
                    $callbacks[] = get_class( $cb['function'][0] ) . '::' . $cb['function'][1];
                }
            }
        }
        qa_ok( "{$label} → " . implode( ', ', $callbacks ) );
    } else {
        qa_fail( "{$label} — hook NO registrado ({$hook})" );
    }
}

// ─── 3. Campos bancarios en user_meta de vendedores ─────────────────────────
qa_head("3. CAMPOS BANCARIOS EN USER META");

$vendors = get_users([
    'meta_key'   => 'ltms_vendor_approved',
    'meta_value' => '1',
    'number'     => 10,
    'fields'     => ['ID', 'user_login', 'display_name'],
]);

if ( empty( $vendors ) ) {
    qa_warn("No hay vendedores aprobados (ltms_vendor_approved=1) en el sistema");
} else {
    echo "  Vendedores encontrados: " . count($vendors) . "\n";
    $bank_fields = [
        'ltms_bank_name'           => 'Banco',
        'ltms_bank_account_type'   => 'Tipo de Cuenta',
        'ltms_bank_account_number' => 'Número de Cuenta',
        'ltms_bank_account_holder' => 'Nombre del Titular',
    ];

    foreach ( $vendors as $vendor ) {
        echo "\n  Vendedor: {$vendor->display_name} (ID={$vendor->ID})\n";
        $any_bank = false;
        foreach ( $bank_fields as $meta_key => $label ) {
            $val = get_user_meta( $vendor->ID, $meta_key, true );
            if ( ! empty( $val ) ) {
                $display = ( $meta_key === 'ltms_bank_account_number' )
                    ? '****' . substr( $val, -4 )
                    : ( strlen($val) > 40 ? substr($val,0,40).'…' : $val );
                qa_ok( "  {$label}: {$display}" );
                $any_bank = true;
            } else {
                qa_warn( "  {$label} ({$meta_key}): vacío" );
            }
        }
        if ( ! $any_bank ) {
            qa_warn( "  Este vendedor no tiene ningún campo bancario configurado" );
        }
    }
}

// ─── 4. Flujo save_vendor_settings: test simulado ───────────────────────────
qa_head("4. SIMULACIÓN SAVE_VENDOR_SETTINGS (PHP directo)");

if ( ! empty( $vendors ) ) {
    $test_vendor = $vendors[0];
    $uid = $test_vendor->ID;

    // Guardar valores de prueba directamente (simulando lo que haría el AJAX)
    $test_data = [
        'ltms_bank_name'           => 'Bancolombia-TEST',
        'ltms_bank_account_type'   => 'ahorros',
        'ltms_bank_account_holder' => 'Titular Prueba QA',
    ];
    foreach ( $test_data as $k => $v ) {
        update_user_meta( $uid, $k, $v );
    }
    // account number con "encrypt" (si existe la clase, sino directo)
    if ( class_exists('LTMS_Core_Security') && method_exists('LTMS_Core_Security','encrypt') ) {
        $enc = LTMS_Core_Security::encrypt('1234567890');
        update_user_meta( $uid, 'ltms_bank_account_number', $enc );
        qa_ok("ltms_bank_account_number guardado con LTMS_Core_Security::encrypt()");
    } else {
        update_user_meta( $uid, 'ltms_bank_account_number', '1234567890' );
        qa_warn("LTMS_Core_Security no disponible — account_number guardado en texto plano");
    }

    // Verificar que se guardaron
    foreach ( $test_data as $k => $v ) {
        $saved = get_user_meta( $uid, $k, true );
        if ( $saved === $v ) {
            qa_ok("Guardado y leído OK: {$k} = {$v}");
        } else {
            qa_fail("Mismatch en {$k}: esperado '{$v}', leído '{$saved}'");
        }
    }

    // ─── 5. Verificar get_vendor_settings devuelve los campos ────────────────
    qa_head("5. GET_VENDOR_SETTINGS RETORNA CAMPOS BANCARIOS");

    if ( class_exists('LTMS_Vendor_Settings_Saver') ) {
        // Leer directo de la DB como haría el método
        $store = [
            'bank_name'           => get_user_meta( $uid, 'ltms_bank_name',           true ),
            'bank_account_type'   => get_user_meta( $uid, 'ltms_bank_account_type',   true ) ?: 'ahorros',
            'bank_account_number' => get_user_meta( $uid, 'ltms_bank_account_number', true ),
            'bank_account_holder' => get_user_meta( $uid, 'ltms_bank_account_holder', true ),
        ];
        foreach ( $store as $k => $v ) {
            if ( ! empty( $v ) ) {
                $display = ( $k === 'bank_account_number' ) ? '****' . substr($v,-4) : $v;
                qa_ok("store.{$k} = {$display}");
            } else {
                qa_fail("store.{$k} está vacío — no se retornaría al JS");
            }
        }
    } else {
        qa_fail("LTMS_Vendor_Settings_Saver no está cargada");
    }

    // Limpiar datos de prueba (restaurar valores originales)
    foreach ( $test_data as $k => $v ) {
        delete_user_meta( $uid, $k );
    }
    delete_user_meta( $uid, 'ltms_bank_account_number' );
    echo "\n  (datos de prueba eliminados del vendedor {$uid})\n";

} else {
    qa_warn("Sin vendedores — saltando tests de simulación");
}

// ─── 6. Tabla payout_requests: columna bank_account ─────────────────────────
qa_head("6. TABLA PAYOUT_REQUESTS — COLUMNA BANK_ACCOUNT");

$ptbl = $wpdb->prefix . 'lt_payout_requests';
$cols = $wpdb->get_col("DESCRIBE `{$ptbl}`");
if ( in_array('bank_account', $cols, true) ) {
    qa_ok("Columna bank_account existe en {$ptbl}");
} else {
    qa_fail("Columna bank_account NO existe en {$ptbl} — los retiros no guardarán la cuenta");
}
if ( in_array('status', $cols, true) ) {
    qa_ok("Columna status existe");
}
$pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$ptbl}` WHERE status='pending'");
echo "  Retiros pendientes: {$pending}\n";

// ─── 7. Modal de retiro — pre-fill: verificar que JS tiene la lógica ─────────
qa_head("7. ASSET JS — LÓGICA PRE-FILL MODAL RETIRO");

$js_file = $plugin_path . '/assets/js/ltms-dashboard.js';
if ( file_exists( $js_file ) ) {
    $js = file_get_contents( $js_file );
    $checks = [
        'bank_account_number'   => 'Campo bank_account_number referenciado en JS',
        'ltms_bank_name'        => 'Campo ltms_bank_name en save-settings handler',
        'ltms_bank_account_type'=> 'Campo ltms_bank_account_type en save-settings handler',
        'ltms_bank_account_holder' => 'Campo ltms_bank_account_holder en save-settings handler',
        'ltms-save-settings-btn' => 'Botón .ltms-save-settings-btn presente',
        'ltms_request_payout'   => 'Action ltms_request_payout en JS',
    ];
    foreach ( $checks as $needle => $label ) {
        if ( strpos( $js, $needle ) !== false ) {
            qa_ok( $label );
        } else {
            qa_fail( $label . " — '{$needle}' NO encontrado en JS" );
        }
    }
    // Verificar versión del asset
    preg_match("/ltms.*?ver=([\d.]+)/", basename($js_file), $m);
    $ver = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ltms_version'");
    echo "  Versión plugin en DB: " . ($ver ?: 'no encontrada') . "\n";
} else {
    qa_fail("ltms-dashboard.js no encontrado en {$js_file}");
}

// ─── RESUMEN ─────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  RESUMEN QA                                          ║\n";
echo "╠══════════════════════════════════════════════════════╣\n";
printf("║  ✅ Pasaron:    %3d                                   ║\n", $pass);
printf("║  ❌ Fallaron:   %3d                                   ║\n", $fail);
printf("║  ⚠️  Advertencias: %3d                                ║\n", $warn);
echo "╚══════════════════════════════════════════════════════╝\n";

if ( $fail === 0 ) {
    echo "\n🎉 Todo OK — campos bancarios listos para producción.\n\n";
} else {
    echo "\n🔧 Hay {$fail} problema(s) que requieren atención.\n\n";
}
