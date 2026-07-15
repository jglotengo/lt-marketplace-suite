<?php
/**
 * LTMS Backfill Scripts — One-shot maintenance
 *
 * v2.9.132 BACKFILL: 
 * 1. KYC expires_at: setear expires_at = approved_at + 1 año para KYCs aprobados antes de v2.9.114
 * 2. Payouts rejection_reason: migrar notes → rejection_reason para payouts rechazados antes de v2.9.115
 *
 * Uso: wp eval-file bin/ltms-backfill-audit-fixes.php --allow-root
 * O:  php bin/ltms-backfill-audit-fixes.php (con wp-load.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Cargar WordPress si se ejecuta directamente
    $wp_load = dirname( __FILE__, 3 ) . '/wp-load.php';
    if ( file_exists( $wp_load ) ) {
        require_once $wp_load;
    } else {
        echo "ERROR: No se pudo cargar wp-load.php\n";
        exit( 1 );
    }
}

global $wpdb;

echo "=== LTMS Backfill v2.9.132 ===\n\n";

// ── 1. KYC expires_at backfill ──────────────────────────────────────────
$kyc_table = $wpdb->prefix . 'lt_vendor_kyc';

// Buscar KYCs aprobados sin expires_at
$approved_without_expiry = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$kyc_table}` 
     WHERE status = 'approved' 
       AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
);

echo "1. KYC expires_at backfill:\n";
echo "   KYCs aprobados sin expires_at: {$approved_without_expiry}\n";

if ( $approved_without_expiry > 0 ) {
    // Setear expires_at = reviewed_at + 1 año (o submitted_at + 1 año si reviewed_at es NULL)
    $updated = $wpdb->query(
        "UPDATE `{$kyc_table}` 
         SET expires_at = DATE_ADD(
             COALESCE(NULLIF(reviewed_at, '0000-00-00 00:00:00'), NULLIF(submitted_at, '0000-00-00 00:00:00'), NOW()),
             INTERVAL 1 YEAR
         )
         WHERE status = 'approved'
           AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
    );
    echo "   Actualizados: {$updated}\n";
} else {
    echo "   No hay KYCs que actualizar.\n";
}

// ── 2. Payouts rejection_reason backfill ────────────────────────────────
$payouts_table = $wpdb->prefix . 'lt_payout_requests';

// Buscar payouts rechazados con notes pero sin rejection_reason
$rejected_without_reason = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$payouts_table}` 
     WHERE status = 'rejected' 
       AND notes IS NOT NULL AND notes != ''
       AND (rejection_reason IS NULL OR rejection_reason = '')"
);

echo "\n2. Payouts rejection_reason backfill:\n";
echo "   Payouts rechazados con notes pero sin rejection_reason: {$rejected_without_reason}\n";

if ( $rejected_without_reason > 0 ) {
    // Migrar notes → rejection_reason (solo si rejection_reason está vacío)
    $updated = $wpdb->query(
        "UPDATE `{$payouts_table}` 
         SET rejection_reason = notes
         WHERE status = 'rejected'
           AND notes IS NOT NULL AND notes != ''
           AND (rejection_reason IS NULL OR rejection_reason = '')"
    );
    echo "   Actualizados: {$updated}\n";
} else {
    echo "   No hay payouts que migrar.\n";
}

// ── 3. Verificación final ───────────────────────────────────────────────
echo "\n=== Verificación ===\n";
$remaining_kyc = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$kyc_table}` WHERE status = 'approved' AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
);
$remaining_payouts = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$payouts_table}` WHERE status = 'rejected' AND notes != '' AND (rejection_reason IS NULL OR rejection_reason = '')"
);
echo "KYCs aprobados sin expires_at restantes: {$remaining_kyc}\n";
echo "Payouts rechazados sin rejection_reason restantes: {$remaining_payouts}\n";
echo "\n=== Backfill completado ===\n";
