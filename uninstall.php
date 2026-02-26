<?php
/**
 * LTMS Uninstall - Limpieza profunda GDPR
 *
 * Se ejecuta cuando el admin elimina el plugin desde el panel de WP.
 * ATENCIÓN: Esta acción es IRREVERSIBLE. Elimina TODOS los datos del plugin.
 *
 * @package LTMS
 */

// Verificar que la desinstalación la inició WordPress, no un acceso directo
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Cargar el autoloader para acceder a las clases del plugin
$autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

global $wpdb;

// ============================================================
// 1. ELIMINAR OPCIONES DE LA BASE DE DATOS
// ============================================================
$options_to_delete = [
    'ltms_version',
    'ltms_db_version',
    'ltms_settings',
    'ltms_api_credentials',
    'ltms_openpay_settings',
    'ltms_siigo_settings',
    'ltms_addi_settings',
    'ltms_aveonline_settings',
    'ltms_zapsign_settings',
    'ltms_tptc_settings',
    'ltms_xcover_settings',
    'ltms_backblaze_settings',
    'ltms_tax_settings',
    'ltms_commission_settings',
    'ltms_wallet_settings',
    'ltms_notification_settings',
    'ltms_waf_settings',
    'ltms_pwa_settings',
    'ltms_installed_pages',
    'ltms_activation_redirect',
    'ltms_encryption_key_hash',
    'ltms_feature_flags',
    'ltms_license_key',
    'ltms_license_status',
];

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
    // También para multisitio
    if ( is_multisite() ) {
        delete_site_option( $option );
    }
}

// Eliminar opciones por prefijo (WooCommerce payment gateways)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ltms_%'"
);
if ( is_multisite() ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'ltms_%'"
    );
}

// ============================================================
// 2. ELIMINAR TABLAS PERSONALIZADAS
// ============================================================
$custom_tables = [
    'lt_vendor_wallets',
    'lt_wallet_transactions',
    'lt_commissions',
    'lt_commission_splits',
    'lt_payout_requests',
    'lt_audit_logs',
    'lt_security_events',
    'lt_waf_blocked_ips',
    'lt_vendor_kyc',
    'lt_vendor_contracts',
    'lt_referral_network',
    'lt_coupon_attributions',
    'lt_product_categories_extended',
    'lt_tax_reports',
    'lt_invoices',
    'lt_shipping_quotes',
    'lt_notifications',
    'lt_job_queue',
    'lt_rate_limits',
    'lt_api_logs',
    'lt_webhook_logs',
    'lt_deposits',
    'lt_refunds_ledger',
    'lt_marketing_banners',
    'lt_legal_disclosures',
];

foreach ( $custom_tables as $table ) {
    $full_table = $wpdb->prefix . $table;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$full_table}`" );
}

// ============================================================
// 3. ELIMINAR ROLES DE USUARIO (RBAC)
// ============================================================
$roles_to_remove = [
    'ltms_vendor',
    'ltms_vendor_premium',
    'ltms_external_auditor',
    'ltms_compliance_officer',
    'ltms_support_agent',
];

foreach ( $roles_to_remove as $role ) {
    remove_role( $role );
}

// ============================================================
// 4. ELIMINAR METADATOS DE USUARIOS (GDPR)
// ============================================================
$user_meta_keys = [
    '_ltms_vendor_status',
    '_ltms_vendor_type',
    '_ltms_kyc_status',
    '_ltms_wallet_id',
    '_ltms_referral_code',
    '_ltms_referrer_id',
    '_ltms_commission_rate',
    '_ltms_bank_account',
    '_ltms_tax_id',
    '_ltms_rnt_number',
    '_ltms_onboarding_complete',
    '_ltms_2fa_secret',
    '_ltms_last_login_ip',
    '_ltms_login_attempts',
    '_ltms_account_locked',
    '_ltms_tptc_phone',
    '_ltms_tptc_user_id',
];

foreach ( $user_meta_keys as $meta_key ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $meta_key ] );
}

// ============================================================
// 5. ELIMINAR METADATOS DE PEDIDOS (HPOS-compatible)
// ============================================================
$order_meta_keys = [
    '_ltms_vendor_id',
    '_ltms_commission_calculated',
    '_ltms_commission_paid',
    '_ltms_split_status',
    '_ltms_openpay_charge_id',
    '_ltms_openpay_order_id',
    '_ltms_siigo_invoice_id',
    '_ltms_siigo_sync_status',
    '_ltms_addi_application_id',
    '_ltms_aveonline_guide',
    '_ltms_aveonline_tracking',
    '_ltms_zapsign_document_token',
    '_ltms_tptc_order_id',
    '_ltms_tptc_synced',
    '_ltms_xcover_quote_id',
    '_ltms_xcover_policy_id',
    '_ltms_tax_engine_data',
    '_ltms_invoice_pdf_path',
];

foreach ( $order_meta_keys as $meta_key ) {
    // Legacy post_meta (para tiendas sin HPOS)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ] );
}

// ============================================================
// 6. ELIMINAR POSTS/PÁGINAS INSTALADAS POR EL PLUGIN
// ============================================================
$pages_option = get_option( 'ltms_installed_pages', [] );
if ( is_array( $pages_option ) ) {
    foreach ( $pages_option as $page_id ) {
        if ( $page_id > 0 ) {
            wp_delete_post( absint( $page_id ), true );
        }
    }
}

// ============================================================
// 7. ELIMINAR CRON JOBS
// ============================================================
$cron_hooks = [
    'ltms_process_payouts',
    'ltms_sync_siigo',
    'ltms_integrity_check',
    'ltms_clean_logs',
    'ltms_rotate_keys',
    'ltms_monitor_wallets',
    'ltms_process_job_queue',
    'ltms_send_notifications',
    'ltms_update_tracking',
    'ltms_archive_logs',
];

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
    wp_clear_scheduled_hook( $hook );
}

// ============================================================
// 8. ELIMINAR ARCHIVOS FÍSICOS DEL VAULT (Solo si el admin confirma)
// ============================================================
// Por seguridad GDPR, los archivos de la bóveda (documentos KYC, contratos)
// solo se eliminan si la constante LTMS_UNINSTALL_DELETE_FILES es true.
// Esto previene borrado accidental de evidencia legal.
if ( defined( 'LTMS_UNINSTALL_DELETE_FILES' ) && LTMS_UNINSTALL_DELETE_FILES === true ) {
    $vault_dir = WP_CONTENT_DIR . '/uploads/ltms-secure-vault/';
    if ( is_dir( $vault_dir ) ) {
        ltms_uninstall_delete_dir( $vault_dir );
    }
    $log_dir = WP_CONTENT_DIR . '/uploads/ltms-logs/';
    if ( is_dir( $log_dir ) ) {
        ltms_uninstall_delete_dir( $log_dir );
    }
}

/**
 * Elimina un directorio de forma recursiva (solo para uninstall).
 *
 * @param string $dir Ruta absoluta del directorio.
 */
function ltms_uninstall_delete_dir( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $files = array_diff( scandir( $dir ), [ '.', '..' ] );
    foreach ( $files as $file ) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir( $path ) ? ltms_uninstall_delete_dir( $path ) : unlink( $path );
    }
    rmdir( $dir );
}

// Limpiar transients del plugin
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ltms_%' OR option_name LIKE '_transient_timeout_ltms_%'"
);
