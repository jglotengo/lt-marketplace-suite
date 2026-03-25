<?php
/**
 * LTMS Emergency Capabilities Fix
 *
 * Adds ALL capabilities required by LTMS admin submenus directly to the
 * administrator role, bypassing the LTMS autoloader entirely.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/emergency-fix-caps.php --allow-root
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI only.' );
}

echo "LTMS Emergency Capabilities Fix\n";
echo str_repeat( '-', 50 ) . "\n";

/**
 * EXACT capability names as checked by class-ltms-admin.php submenus.
 * These names are authoritative — changing them breaks the menu checks.
 */
$admin_caps = [
    // ── Required by admin submenus (exact names from class-ltms-admin.php) ──
    'ltms_access_dashboard',          // main menu + Dashboard submenu
    'ltms_manage_all_vendors',        // Vendedores, ReDi
    'ltms_approve_payouts',           // Retiros  (NOT ltms_manage_payouts)
    'ltms_manage_platform_settings',  // Config, Marketing, Fiscal, Salud APIs, Comisiones  (NOT ltms_manage_settings)
    'ltms_view_tax_reports',          // Reportes Fiscales
    'ltms_view_wallet_ledger',        // Billeteras
    'ltms_view_all_orders',           // Pedidos, Para Recogida, Seguros  (NOT ltms_manage_orders)
    'ltms_manage_kyc',                // KYC / Documentos
    'ltms_view_security_logs',        // Seguridad
    'ltms_view_audit_log',            // Historial Fiscal  (singular, NOT ltms_view_audit_logs)

    // ── Additional caps from LTMS_Roles::install() ──
    'ltms_view_compliance_logs',
    'ltms_export_reports',
    'ltms_compliance',
    'ltms_manage_roles',
    'ltms_freeze_wallets',
    'ltms_generate_legal_evidence',
];

$role  = get_role( 'administrator' );
$added = 0;
$had   = 0;

if ( ! $role ) {
    echo "ERROR: 'administrator' role not found.\n";
    exit( 1 );
}

foreach ( $admin_caps as $cap ) {
    if ( $role->has_cap( $cap ) ) {
        echo "  already  $cap\n";
        $had++;
    } else {
        $role->add_cap( $cap, true );
        echo "  + added  $cap\n";
        $added++;
    }
}

echo str_repeat( '-', 50 ) . "\n";
echo "Added: $added | Already present: $had\n\n";

// Verify the critical gate cap
$ok = get_role( 'administrator' ) && get_role( 'administrator' )->has_cap( 'ltms_access_dashboard' );
echo 'ltms_access_dashboard: ' . ( $ok ? 'OK — admin menu visible' : 'STILL MISSING' ) . "\n";
echo "Done.\n";
