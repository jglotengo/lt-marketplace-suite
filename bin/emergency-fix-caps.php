<?php
/**
 * LTMS Emergency Capabilities Fix
 *
 * Run via WP-CLI when LTMS_Roles class is unavailable (e.g., autoloader broken):
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/emergency-fix-caps.php --allow-root
 *
 * This script directly writes capabilities to the administrator role in the database,
 * bypassing the LTMS class autoloader entirely.
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI only.' );
}

echo "LTMS Emergency Capabilities Fix\n";
echo str_repeat( '-', 50 ) . "\n";

// All capabilities the administrator needs for LTMS
$admin_caps = [
    'ltms_access_dashboard'     => true,
    'ltms_manage_all_vendors'   => true,
    'ltms_view_wallet_ledger'   => true,
    'ltms_manage_payouts'       => true,
    'ltms_manage_commissions'   => true,
    'ltms_view_audit_logs'      => true,
    'ltms_manage_settings'      => true,
    'ltms_export_reports'       => true,
    'ltms_manage_api_keys'      => true,
    'ltms_manage_kyc'           => true,
    'ltms_approve_kyc'          => true,
    'ltms_manage_waf'           => true,
    'ltms_view_waf_logs'        => true,
    'ltms_impersonate_vendor'   => true,
    'ltms_manage_referrals'     => true,
    'ltms_manage_insurance'     => true,
    'ltms_manage_redi'          => true,
    'ltms_view_tax_reports'     => true,
    'ltms_manage_coupons'       => true,
    'ltms_manage_marketing'     => true,
];

// Vendor caps
$vendor_caps = [
    'ltms_vendor_access'         => true,
    'ltms_view_own_wallet'       => true,
    'ltms_request_payout'        => true,
    'ltms_manage_own_products'   => true,
    'ltms_view_own_orders'       => true,
    'ltms_manage_own_store'      => true,
    'ltms_upload_kyc_docs'       => true,
    'ltms_view_own_commissions'  => true,
    'ltms_view_own_referrals'    => true,
    'ltms_manage_own_settings'   => true,
];

// 1. Administrator role
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
    $added = 0;
    $already = 0;
    foreach ( $admin_caps as $cap => $grant ) {
        if ( ! $admin_role->has_cap( $cap ) ) {
            $admin_role->add_cap( $cap, $grant );
            $added++;
            echo "+ Added: {$cap}\n";
        } else {
            $already++;
        }
    }
    // Admin also gets vendor caps
    foreach ( $vendor_caps as $cap => $grant ) {
        if ( ! $admin_role->has_cap( $cap ) ) {
            $admin_role->add_cap( $cap, $grant );
        }
    }
    echo "\nAdministrator: {$added} caps added, {$already} already present.\n";
} else {
    echo "ERROR: 'administrator' role not found!\n";
}

// 2. Create ltms_vendor role if missing
$vendor_role = get_role( 'ltms_vendor' );
if ( ! $vendor_role ) {
    add_role( 'ltms_vendor', 'Vendor LTMS', array_merge( $vendor_caps, [
        'read' => true,
    ] ) );
    echo "Created role: ltms_vendor\n";
} else {
    foreach ( $vendor_caps as $cap => $grant ) {
        $vendor_role->add_cap( $cap, $grant );
    }
    echo "Updated role: ltms_vendor\n";
}

// 3. Create ltms_vendor_premium role if missing
$premium_role = get_role( 'ltms_vendor_premium' );
if ( ! $premium_role ) {
    $premium_caps = array_merge( $vendor_caps, [
        'read'                      => true,
        'ltms_vendor_premium_access'=> true,
        'ltms_access_advanced_stats'=> true,
        'ltms_create_coupons'       => true,
    ] );
    add_role( 'ltms_vendor_premium', 'Vendor Premium LTMS', $premium_caps );
    echo "Created role: ltms_vendor_premium\n";
} else {
    echo "Role ltms_vendor_premium: already exists\n";
}

// 4. Create ltms_compliance_officer role if missing
if ( ! get_role('ltms_compliance_officer') ) {
    add_role( 'ltms_compliance_officer', 'Compliance Officer LTMS', [
        'read'                   => true,
        'ltms_view_audit_logs'   => true,
        'ltms_manage_kyc'        => true,
        'ltms_approve_kyc'       => true,
        'ltms_view_tax_reports'  => true,
        'ltms_export_reports'    => true,
        'ltms_view_waf_logs'     => true,
    ] );
    echo "Created role: ltms_compliance_officer\n";
}

// 5. Create ltms_external_auditor role if missing
if ( ! get_role('ltms_external_auditor') ) {
    add_role( 'ltms_external_auditor', 'External Auditor LTMS', [
        'read'                   => true,
        'ltms_view_audit_logs'   => true,
        'ltms_view_tax_reports'  => true,
        'ltms_export_reports'    => true,
    ] );
    echo "Created role: ltms_external_auditor\n";
}

// 6. Verify the critical capability
$admin_role_check = get_role( 'administrator' );
$has_key_cap = $admin_role_check && $admin_role_check->has_cap( 'ltms_access_dashboard' );
echo "\n" . str_repeat( '-', 50 ) . "\n";
echo "ltms_access_dashboard on administrator: " . ( $has_key_cap ? "YES ✓ — Admin menu should now appear" : "NO ✗ — Something went wrong" ) . "\n";
echo "\nDone. Clear any object cache and reload wp-admin.\n";
