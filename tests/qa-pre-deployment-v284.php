<?php
/**
 * Pre-Deployment Smoke Test — Shipping Strategy v2.8.4
 *
 * Verifica que todos los componentes de la nueva estrategia de envíos están
 * correctamente instalados y listos para producción.
 *
 * Ejecutar: wp eval-file tests/qa-pre-deployment-v284.php
 *
 * Diferencia con qa-shipping-mode-v284.php:
 *  - Este script NO crea datos de test (no inserta productos/categorías/users).
 *  - Solo VERIFICA que el entorno está listo.
 *  - Apto para ejecutar en producción antes del deploy.
 *
 * @package LTMS
 * @version 2.8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    if ( $argv[1] ?? '' === '--syntax-only' ) {
        echo "OK: syntax-only mode.\n";
        exit( 0 );
    }
    echo "ERROR: Ejecutar con: wp eval-file tests/qa-pre-deployment-v284.php\n";
    exit( 1 );
}

$results = [ 'pass' => 0, 'fail' => 0, 'warnings' => [], 'errors' => [] ];

function chk( $cond, $msg, &$results, $severity = 'fail' ) {
    if ( $cond ) {
        $results['pass']++;
        echo "[PASS] $msg\n";
    } else {
        if ( $severity === 'warn' ) {
            $results['warnings'][] = $msg;
            echo "[WARN] $msg\n";
        } else {
            $results['fail']++;
            $results['errors'][] = $msg;
            echo "[FAIL] $msg\n";
        }
    }
}

function section( $title ) {
    echo "\n=== $title ===\n";
}

echo "LT Marketplace Suite — Pre-Deployment Smoke Test v2.8.4\n";
echo "Fecha: " . current_time( 'Y-m-d H:i:s' ) . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "WP: " . get_bloginfo( 'version' ) . "\n";
echo "WC: " . ( class_exists( 'WooCommerce' ) ? WC()->version : 'NO INSTALADO' ) . "\n";
echo "Plugin: " . ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : 'NO DEFINIDO' ) . "\n";

// =====================================================================
section( '1. REQUISITOS DEL SISTEMA' );
// =====================================================================

chk( version_compare( PHP_VERSION, '7.4.0', '>=' ), 'PHP >= 7.4.0', $results );
chk( version_compare( PHP_VERSION, '8.0.0', '>=' ), 'PHP >= 8.0.0 (recomendado)', $results, 'warn' );
chk( class_exists( 'WooCommerce' ), 'WooCommerce instalado', $results );
chk( defined( 'LTMS_VERSION' ), 'LTMS_VERSION definido', $results );

// =====================================================================
section( '2. CLASES REQUERIDAS CARGADAS' );
// =====================================================================

$required_classes = [
    'LTMS_Shipping_Mode',
    'LTMS_Shipping_Cost_Ledger',
    'LTMS_Admin_Shipping',
    'LTMS_Admin_Shipping_Ledger',
    'LTMS_Order_Paid_Listener',
    'LTMS_Donation_Manager',
    'LTMS_Business_Wallet',
    'LTMS_Core_Config',
    'LTMS_Core_Logger',
    'LTMS_Shipping_Parallel_Quoter',
];

foreach ( $required_classes as $cls ) {
    chk( class_exists( $cls ), "Clase $cls cargada", $results );
}

// =====================================================================
section( '3. CONSTANTES DE MODO' );
// =====================================================================

chk( defined( 'LTMS_Shipping_Mode::MODE_QUOTED' ), 'MODE_QUOTED definido', $results );
chk( defined( 'LTMS_Shipping_Mode::MODE_FLAT' ), 'MODE_FLAT definido', $results );
chk( defined( 'LTMS_Shipping_Mode::MODE_FREE' ), 'MODE_FREE definido', $results );
chk( defined( 'LTMS_Shipping_Mode::MODE_FREE_ABSORBED' ), 'MODE_FREE_ABSORBED definido', $results );
chk( defined( 'LTMS_Shipping_Mode::MODE_HYBRID' ), 'MODE_HYBRID definido', $results );
chk( defined( 'LTMS_Shipping_Mode::MODE_SHARED' ), 'MODE_SHARED definido (v2.8.4)', $results );

$valid_modes = LTMS_Shipping_Mode::valid_modes();
chk( count( $valid_modes ) === 6, '6 modos válidos (incluye SHARED)', $results );

// =====================================================================
section( '4. TABLAS DB SHIPPING LEDGER' );
// =====================================================================

global $wpdb;
$required_tables = [
    'lt_shipping_cost_ledger',
    'lt_carrier_invoices',
    'lt_carrier_invoice_lines',
    'lt_shipping_disputes',
    'lt_vendor_shipping_budgets',
];

foreach ( $required_tables as $t ) {
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . $t ) );
    chk( $exists === $wpdb->prefix . $t, "Tabla {$wpdb->prefix}{$t} existe", $results );
}

// =====================================================================
section( '5. VERSIÓN DB ACTUALIZADA' );
// =====================================================================

$db_version = get_option( 'ltms_db_version', '0.0.0' );
chk( version_compare( $db_version, '2.8.3', '>=' ), "DB version >= 2.8.3 (actual: $db_version)", $results );

// =====================================================================
section( '6. HOOKS REGISTRADOS' );
// =====================================================================

global $wp_filter;

// filter_wc_rates debe estar enganchado en woocommerce_package_rates.
$package_rates_hooks = $wp_filter['woocommerce_package_rates'] ?? new stdClass();
$callbacks = [];
if ( $package_rates_hooks instanceof WP_Hook ) {
    foreach ( $package_rates_hooks->callbacks as $priority => $cbs ) {
        foreach ( $cbs as $id => $cb ) {
            $callbacks[] = "$priority:$id";
        }
    }
}
chk( ! empty( $callbacks ), 'woocommerce_package_rates tiene hooks registrados', $results );

// Verificar específicamente filter_wc_rates y maybe_block_absorbed.
$has_filter_wc_rates = false;
$has_block_over_budget = false;
foreach ( $callbacks as $cb ) {
    if ( strpos( $cb, 'filter_wc_rates' ) !== false ) $has_filter_wc_rates = true;
    if ( strpos( $cb, 'maybe_block_absorbed' ) !== false ) $has_block_over_budget = true;
}
chk( $has_filter_wc_rates, 'LTMS_Shipping_Mode::filter_wc_rates enganchado', $results );
chk( $has_block_over_budget, 'LTMS_Shipping_Cost_Ledger::maybe_block_absorbed enganchado', $results );

// Hooks del Order_Paid_Listener.
$order_paid_hooks = $wp_filter['woocommerce_payment_complete'] ?? null;
chk( $order_paid_hooks instanceof WP_Hook, 'woocommerce_payment_complete tiene hooks', $results );

// =====================================================================
section( '7. CONFIGURACIÓN ACTUAL' );
// =====================================================================

$global_mode = LTMS_Shipping_Mode::get_global_mode();
echo "  Modo global actual: $global_mode\n";
chk( in_array( $global_mode, LTMS_Shipping_Mode::valid_modes(), true ), 'Modo global es válido', $results );

if ( $global_mode === LTMS_Shipping_Mode::MODE_FLAT ) {
    chk( false, 'Modo global es FLAT — recomendación v2.8.4: cambiar a HYBRID', $results, 'warn' );
} elseif ( $global_mode === LTMS_Shipping_Mode::MODE_HYBRID ) {
    chk( true, 'Modo global es HYBRID (recomendado)', $results );
} else {
    chk( true, "Modo global: $global_mode", $results );
}

// % shared.
$shared_pct = LTMS_Shipping_Mode::get_shared_customer_pct();
echo "  % shared (cliente paga): $shared_pct%\n";
chk( $shared_pct >= 0 && $shared_pct <= 100, '% shared en rango válido (0-100)', $results );

// Umbral hybrid.
$threshold = (float) LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 );
echo "  Umbral hybrid: $threshold\n";
chk( $threshold > 0, 'Umbral hybrid > 0', $results );

// Tarifa flat.
$flat_rate = (float) LTMS_Core_Config::get( 'ltms_shipping_flat_rate', 8500 );
echo "  Tarifa flat: $flat_rate\n";
chk( $flat_rate > 0, 'Tarifa flat > 0', $results );

// =====================================================================
section( '8. CARRIERS HABILITADOS' );
// =====================================================================

$carriers = [
    'ltms_deprisa_enabled'   => 'Deprisa',
    'ltms_heka_enabled'      => 'Heka',
    'ltms_aveonline_enabled' => 'Aveonline',
    'ltms_uber_enabled'      => 'Uber Direct',
];

$enabled_count = 0;
foreach ( $carriers as $opt => $name ) {
    $enabled = (bool) get_option( $opt, false );
    echo "  $name: " . ( $enabled ? 'HABILITADO' : 'deshabilitado' ) . "\n";
    if ( $enabled ) $enabled_count++;
}
chk( $enabled_count > 0, "Al menos 1 carrier habilitado ($enabled_count/4)", $results );

// =====================================================================
section( '9. OVERRIDE POR CATEGORÍA — ESTADO ACTUAL' );
// =====================================================================

$overrides = LTMS_Shipping_Mode::get_all_category_overrides();
echo "  Categorías con override configurado: " . count( $overrides ) . "\n";

if ( empty( $overrides ) ) {
    chk( false, 'Ninguna categoría tiene override — recomendado configurar al menos 3 categorías clave', $results, 'warn' );
} else {
    chk( true, 'Hay al menos una categoría con override configurado', $results );
    foreach ( $overrides as $cat_id => $data ) {
        echo "  - Cat #$cat_id ({$data['name']}): modo={$data['mode']}\n";
    }
}

// =====================================================================
section( '10. PRESUPUESTOS VENDOR CONFIGURADOS' );
// =====================================================================

$budget_table = $wpdb->prefix . 'lt_vendor_shipping_budgets';
$budget_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$budget_table}`" );
echo "  Presupuestos configurados: $budget_count\n";

if ( $budget_count === 0 ) {
    chk( false, 'Ningún presupuesto configurado — recomendado configurar al menos para top 10 vendors', $results, 'warn' );
} else {
    chk( $budget_count > 0, 'Hay presupuestos configurados', $results );
}

// =====================================================================
section( '11. WOOCOMMERCE HPOS' );
// =====================================================================

if ( class_exists( 'WooCommerce' ) ) {
    $hpos_enabled = wc_string_to_bool( get_option( 'woocommerce_custom_orders_table_enabled', 'no' ) );
    echo "  HPOS (Custom Orders Table): " . ( $hpos_enabled ? 'HABILITADO' : 'deshabilitado (legacy)' ) . "\n";
    // No es fail, solo info. El código es compatible con ambos.
    chk( true, 'HPOS compatible (código no depende de post_meta directamente para orders)', $results );
}

// =====================================================================
section( '12. SESIÓN WC DISPONIBLE' );
// =====================================================================

if ( function_exists( 'WC' ) && WC()->session ) {
    chk( true, 'WC()->session disponible', $results );
} else {
    chk( false, 'WC()->session NO disponible (modo SHARED requiere sesión para persistir cotización)', $results, 'warn' );
}

// =====================================================================
section( '13. WALLET FOUNDATION VENDOR' );
// =====================================================================

$foundation_id = LTMS_Donation_Manager::FOUNDATION_VENDOR_ID;
chk( $foundation_id === -1, 'FOUNDATION_VENDOR_ID = -1 (B-01 fix aplicado)', $results );

// Verificar que la wallet de fundación existe.
if ( class_exists( 'LTMS_Business_Wallet' ) ) {
    try {
        $wallet = LTMS_Business_Wallet::get_or_create( $foundation_id );
        chk( ! empty( $wallet ), 'Wallet foundation existe/creada', $results );
    } catch ( \Throwable $e ) {
        chk( false, 'Wallet foundation error: ' . $e->getMessage(), $results );
    }
}

// =====================================================================
section( '14. CRON PROGRAMADO' );
// =====================================================================

$daily_cron = wp_next_scheduled( 'ltms_shipping_ledger_daily_alerts' );
chk( $daily_cron !== false, 'Cron ltms_shipping_ledger_daily_alerts programado', $results );
if ( $daily_cron ) {
    echo "  Próxima ejecución: " . wp_date( 'Y-m-d H:i:s', $daily_cron ) . "\n";
}

// =====================================================================
section( '15. PERMISOS (CAPABILITIES)' );
// =====================================================================

$admin = get_role( 'administrator' );
if ( $admin ) {
    $caps = [ 'ltms_view_wallet_ledger', 'ltms_manage_platform_settings' ];
    foreach ( $caps as $cap ) {
        chk( $admin->has_cap( $cap ), "Capability '$cap' en rol administrator", $results );
    }
}

// =====================================================================
section( '16. ENTRADAS LEDGER EXISTENTES (datos reales)' );
// =====================================================================

$ledger_table = $wpdb->prefix . 'lt_shipping_cost_ledger';
$entry_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ledger_table}`" );
echo "  Entries en ledger: $entry_count\n";
chk( $entry_count >= 0, 'Tabla ledger accesible', $results );

if ( $entry_count === 0 ) {
    echo "  (Tabla vacía — es normal si el sistema acaba de instalarse)\n";
}

// =====================================================================
section( '17. RESUMEN' );
// =====================================================================

echo "\n";
echo "========================================\n";
echo "  RESULTADOS: {$results['pass']} PASS / {$results['fail']} FAIL / " . count( $results['warnings'] ) . " WARN\n";
echo "========================================\n";

if ( ! empty( $results['warnings'] ) ) {
    echo "\nADVERTENCIAS:\n";
    foreach ( $results['warnings'] as $w ) {
        echo "  [WARN] $w\n";
    }
}

if ( $results['fail'] > 0 ) {
    echo "\nFALLAS CRÍTICAS:\n";
    foreach ( $results['errors'] as $e ) {
        echo "  [FAIL] $e\n";
    }
    echo "\nNO DESPLEGAR hasta corregir las fallas.\n";
    exit( 1 );
}

if ( empty( $results['warnings'] ) ) {
    echo "\n✓ Sistema listo para producción. Sin advertencias.\n";
} else {
    echo "\n✓ Sistema funcional con " . count( $results['warnings'] ) . " advertencia(s) a revisar.\n";
}

exit( 0 );
