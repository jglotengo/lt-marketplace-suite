<?php
/**
 * LTMS Full Repair Script
 *
 * Repara en un solo paso: capabilities, roles, opciones por defecto,
 * páginas requeridas y cron jobs.
 *
 * USO (WP-CLI):
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-full-repair.php --allow-root
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Ejecutar solo via WP-CLI: wp eval-file ltms-full-repair.php --allow-root' );
}

$sep  = str_repeat( '=', 65 );
$sep2 = str_repeat( '-', 65 );

echo "\n{$sep}\n";
echo " LTMS FULL REPAIR — " . date( 'Y-m-d H:i:s' ) . "\n";
echo "{$sep}\n\n";

$fixed   = [];
$errors  = [];
$skipped = [];

// ═══════════════════════════════════════════════════════════════
// 1. CAPABILITIES — rol administrator
// ═══════════════════════════════════════════════════════════════
echo "── 1. CAPABILITIES\n";

$admin_caps = [
    // Menú principal + Dashboard
    'ltms_access_dashboard',
    // Submenús admin
    'ltms_manage_all_vendors',
    'ltms_approve_payouts',
    'ltms_manage_platform_settings',
    'ltms_view_tax_reports',
    'ltms_view_wallet_ledger',
    'ltms_view_all_orders',
    'ltms_manage_kyc',
    'ltms_view_security_logs',
    'ltms_view_audit_log',
    // Caps de sistema
    'ltms_view_compliance_logs',
    'ltms_export_reports',
    'ltms_compliance',
    'ltms_manage_roles',
    'ltms_freeze_wallets',
    'ltms_generate_legal_evidence',
];

$role  = get_role( 'administrator' );
if ( ! $role ) {
    $errors[] = 'Rol administrator no encontrado';
    echo "   ERROR: rol administrator no encontrado\n";
} else {
    $added = $already = [];
    foreach ( $admin_caps as $cap ) {
        if ( $role->has_cap( $cap ) ) {
            $already[] = $cap;
        } else {
            $role->add_cap( $cap, true );
            $added[] = $cap;
            $fixed[] = "cap: $cap";
        }
    }
    echo "   Añadidas  : " . count( $added ) . "\n";
    echo "   Ya tenía  : " . count( $already ) . "\n";
    if ( $added ) {
        foreach ( $added as $c ) echo "   + $c\n";
    } else {
        echo "   ✓ Todas las caps ya estaban presentes\n";
    }
}

// Invalidar transient de auto-heal para que la próxima carga re-verifique
if ( defined( 'LTMS_VERSION' ) ) {
    delete_transient( 'ltms_admin_caps_ok_' . md5( LTMS_VERSION ) );
    echo "   ✓ Transient de verificación invalidado\n";
}

// ═══════════════════════════════════════════════════════════════
// 2. ROLES PERSONALIZADOS
// ═══════════════════════════════════════════════════════════════
echo "\n── 2. ROLES LTMS\n";

$custom_roles = [
    'ltms_vendor'            => 'Vendedor LTMS',
    'ltms_vendor_premium'    => 'Vendedor Premium LTMS',
    'ltms_support_agent'     => 'Soporte LTMS',
    'ltms_external_auditor'  => 'Auditor Externo LTMS',
    'ltms_compliance_officer'=> 'Oficial de Cumplimiento LTMS',
];

foreach ( $custom_roles as $slug => $label ) {
    $r = get_role( $slug );
    echo "   $slug: " . ( $r ? "✓ existe" : "✗ FALTA" ) . "\n";
    if ( ! $r ) {
        $errors[] = "Rol faltante: $slug — ejecutar activación del plugin";
    }
}

echo "\n   Para reinstalar todos los roles:\n";
echo "   wp eval 'LTMS_Core_Activator::activate();' --allow-root\n";

// ═══════════════════════════════════════════════════════════════
// 3. OPCIONES DE CONFIGURACIÓN POR DEFECTO
// ═══════════════════════════════════════════════════════════════
echo "\n── 3. OPCIONES DE CONFIGURACIÓN\n";

$defaults = [
    'ltms_commission_rate_default'   => '0.10',
    'ltms_payout_min_amount'         => '50000',
    'ltms_payout_frequency'          => 'weekly',
    'ltms_hold_period_days'          => '7',
    'ltms_kyc_required'              => 'yes',
    'ltms_2fa_required_vendors'      => 'no',
    'ltms_waf_enabled'               => 'yes',
    'ltms_log_retention_days'        => '90',
    'ltms_country'                   => 'CO',
    'ltms_currency'                  => 'COP',
    'ltms_tax_regime'                => 'iva',
    'ltms_invoice_provider'          => 'siigo',
    'ltms_notifications_email'       => 'yes',
    'ltms_notifications_whatsapp'    => 'no',
    'ltms_rate_limit_enabled'        => 'yes',
    'ltms_openpay_enabled'           => 'no',
    'ltms_addi_enabled'              => 'no',
    'ltms_siigo_enabled'             => 'no',
    'ltms_mlm_enabled'               => 'no',
    'ltms_tptc_enabled'              => 'no',
    'ltms_kyc_required_for_payout'   => 'yes',
    'ltms_kyc_auto_approve'          => 'no',
];

$current = get_option( 'ltms_settings', [] );
if ( ! is_array( $current ) ) {
    $current = [];
}

$merged  = array_merge( $defaults, $current ); // current tiene prioridad
$changed = 0;

foreach ( $defaults as $key => $def_val ) {
    if ( ! isset( $current[ $key ] ) ) {
        $changed++;
        echo "   + $key = $def_val (nuevo)\n";
    }
}

if ( $changed > 0 ) {
    update_option( 'ltms_settings', $merged, true );
    $fixed[] = "opciones: $changed defaults aplicados";
    echo "   ✓ $changed opciones por defecto aplicadas\n";
} else {
    echo "   ✓ Todas las opciones ya existen (" . count( $current ) . " keys)\n";
    $skipped[] = 'opciones: ya completas';
}

// Versión del plugin
$stored_version = get_option( 'ltms_version', '' );
if ( $stored_version !== ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.7.0' ) ) {
    $v = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.7.0';
    update_option( 'ltms_version', $v );
    $fixed[] = "ltms_version → $v";
    echo "   ✓ ltms_version actualizado a $v\n";
}

// ═══════════════════════════════════════════════════════════════
// 4. PÁGINAS REQUERIDAS
// ═══════════════════════════════════════════════════════════════
echo "\n── 4. PÁGINAS REQUERIDAS\n";

$required_pages = [
    'ltms-vendor-register' => [
        'title'   => 'Registro de Vendedor',
        'content' => '[ltms_vendor_register]',
        'slug'    => 'registro-vendedor',
        'option'  => 'ltms_page_register',
    ],
    'ltms-dashboard'       => [
        'title'   => 'Panel del Vendedor',
        'content' => '[ltms_vendor_dashboard]',
        'slug'    => 'panel-vendedor',
        'option'  => 'ltms_page_dashboard',
    ],
    'ltms-login'           => [
        'title'   => 'Iniciar Sesión',
        'content' => '[ltms_vendor_login]',
        'slug'    => 'login-vendedor',
        'option'  => 'ltms_page_login',
    ],
    'ltms-store'           => [
        'title'   => 'Tienda del Vendedor',
        'content' => '[ltms_vendor_store]',
        'slug'    => 'tienda',
        'option'  => 'ltms_page_store',
    ],
    'ltms-orders'          => [
        'title'   => 'Mis Pedidos',
        'content' => '[ltms_vendor_orders]',
        'slug'    => 'mis-pedidos',
        'option'  => 'ltms_page_orders',
    ],
    'ltms-wallet'          => [
        'title'   => 'Mi Billetera',
        'content' => '[ltms_vendor_wallet]',
        'slug'    => 'mi-billetera',
        'option'  => 'ltms_page_wallet',
    ],
];

$installed = get_option( 'ltms_installed_pages', [] );
if ( ! is_array( $installed ) ) {
    $installed = [];
}

foreach ( $required_pages as $key => $page ) {
    $existing_id = $installed[ $key ] ?? get_option( $page['option'], 0 );

    if ( $existing_id && get_post( $existing_id ) ) {
        echo "   ✓ '{$page['title']}' (ID:{$existing_id})\n";
        $installed[ $key ] = $existing_id;
        continue;
    }

    // Verificar si ya existe una página con ese slug
    $existing_by_slug = get_page_by_path( $page['slug'] );
    if ( $existing_by_slug ) {
        echo "   ✓ '{$page['title']}' encontrada por slug (ID:{$existing_by_slug->ID})\n";
        $installed[ $key ] = $existing_by_slug->ID;
        update_option( $page['option'], $existing_by_slug->ID );
        continue;
    }

    $page_id = wp_insert_post( [
        'post_title'     => $page['title'],
        'post_content'   => $page['content'],
        'post_status'    => 'publish',
        'post_type'      => 'page',
        'post_name'      => $page['slug'],
        'comment_status' => 'closed',
    ] );

    if ( is_wp_error( $page_id ) ) {
        $errors[]  = "Página '{$page['title']}': " . $page_id->get_error_message();
        echo "   ✗ ERROR creando '{$page['title']}': " . $page_id->get_error_message() . "\n";
    } else {
        $installed[ $key ] = $page_id;
        update_option( $page['option'], $page_id );
        $fixed[] = "página creada: {$page['title']} (ID:$page_id)";
        echo "   + Creada '{$page['title']}' (ID:$page_id, slug:/{$page['slug']}/)\n";
    }
}

update_option( 'ltms_installed_pages', $installed );

// ═══════════════════════════════════════════════════════════════
// 5. CRON JOBS
// ═══════════════════════════════════════════════════════════════
echo "\n── 5. CRON JOBS\n";

$cron_jobs = [
    'ltms_process_payouts'   => 'daily',
    'ltms_sync_siigo'        => 'hourly',
    'ltms_integrity_check'   => 'daily',
    'ltms_clean_logs'        => 'weekly',
    'ltms_send_notifications'=> 'hourly',
    'ltms_update_tracking'   => 'hourly',
];

foreach ( $cron_jobs as $hook => $recurrence ) {
    if ( wp_next_scheduled( $hook ) ) {
        echo "   ✓ $hook\n";
    } else {
        wp_schedule_event( time(), $recurrence, $hook );
        $fixed[] = "cron: $hook ($recurrence)";
        echo "   + Programado $hook ($recurrence)\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// 6. TABLAS DE BASE DE DATOS
// ═══════════════════════════════════════════════════════════════
echo "\n── 6. TABLAS DE BD\n";

global $wpdb;
$prefix = $wpdb->prefix . 'lt_';

$expected_tables = [
    'vendors', 'wallets', 'wallet_transactions', 'commissions',
    'referral_tree', 'payouts', 'kyc_documents', 'notifications',
    'audit_logs', 'waf_blocked_ips', 'waf_logs', 'api_logs',
    'coupons', 'consumer_protection', 'job_queue', 'tracking',
    'insurance_policies', 'redi_requests',
];

$missing_tables = [];
foreach ( $expected_tables as $table ) {
    $full   = $prefix . $table;
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
    if ( $exists ) {
        echo "   ✓ $full\n";
    } else {
        $missing_tables[] = $full;
        echo "   ✗ FALTA: $full\n";
    }
}

if ( ! empty( $missing_tables ) ) {
    echo "\n   Intentando ejecutar migraciones...\n";
    if ( class_exists( 'LTMS_DB_Migrations' ) ) {
        LTMS_DB_Migrations::run();
        $fixed[] = 'DB migrations ejecutadas';
        echo "   ✓ LTMS_DB_Migrations::run() ejecutado\n";
    } else {
        $errors[] = count( $missing_tables ) . ' tablas faltantes y LTMS_DB_Migrations no disponible';
        echo "   ERROR: LTMS_DB_Migrations no disponible\n";
        echo "   Ejecutar: wp plugin deactivate lt-marketplace-suite && wp plugin activate lt-marketplace-suite\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// 7. FLUSH DE CACHÉ
// ═══════════════════════════════════════════════════════════════
echo "\n── 7. FLUSH CACHÉ\n";

wp_cache_flush();
echo "   ✓ wp_cache_flush()\n";

// Transients de LTMS
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ltms_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ltms_%'" );
echo "   ✓ Transients LTMS eliminados\n";

flush_rewrite_rules( false );
echo "   ✓ Rewrite rules flushed\n";

// ═══════════════════════════════════════════════════════════════
// RESUMEN
// ═══════════════════════════════════════════════════════════════
echo "\n{$sep}\n";
echo " RESUMEN\n";
echo "{$sep}\n\n";

echo " Reparaciones : " . count( $fixed ) . "\n";
echo " Omitidos     : " . count( $skipped ) . "\n";
echo " Errores      : " . count( $errors ) . "\n\n";

if ( ! empty( $fixed ) ) {
    echo " REPARADO:\n";
    foreach ( $fixed as $f ) echo "   ✓ $f\n";
}

if ( ! empty( $errors ) ) {
    echo "\n ERRORES:\n";
    foreach ( $errors as $e ) echo "   ✗ $e\n";
    echo "\n Si hay tablas faltantes, ejecutar:\n";
    echo "   wp plugin deactivate lt-marketplace-suite --allow-root\n";
    echo "   wp plugin activate   lt-marketplace-suite --allow-root\n";
}

if ( empty( $errors ) ) {
    echo "\n ✅ SISTEMA REPARADO CORRECTAMENTE\n";
    echo "    Recarga wp-admin — el menú LT Marketplace debe aparecer.\n";
} else {
    echo "\n ⚠️  Completado con errores. Ver detalles arriba.\n";
}

echo "\n{$sep}\n\n";
