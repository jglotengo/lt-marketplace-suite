<?php
/**
 * LTMS Admin Caps Fix
 * Visita esta URL una vez como administrador para restaurar las caps del menú.
 * Se autodestruye después de ejecutarse.
 */

// Cargar WordPress
$wp_load = dirname(__FILE__) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    // Intentar subir un nivel (si está en /plugins/lt-marketplace-suite/)
    $wp_load = dirname( dirname( dirname( dirname(__FILE__) ) ) ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
    die( "ERROR: No se encontró wp-load.php" );
}
require_once $wp_load;

// Solo admins
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Acceso denegado. Debes estar logueado como administrador.' );
}

// Caps que deben existir en el rol administrator
$required_caps = [
    'ltms_access_dashboard',
    'ltms_manage_all_vendors',
    'ltms_approve_payouts',
    'ltms_manage_platform_settings',
    'ltms_view_tax_reports',
    'ltms_view_wallet_ledger',
    'ltms_view_all_orders',
    'ltms_manage_kyc',
    'ltms_view_security_logs',
    'ltms_view_audit_log',
    'ltms_view_compliance_logs',
    'ltms_export_reports',
    'ltms_compliance',
    'ltms_manage_roles',
    'ltms_freeze_wallets',
    'ltms_generate_legal_evidence',
    'ltms_export_customer_db',
    'erase_others_personal_data',
    'export_others_personal_data',
];

$added   = [];
$already = [];

// 1. Añadir caps al ROL administrator
$role = get_role( 'administrator' );
if ( $role ) {
    foreach ( $required_caps as $cap ) {
        if ( ! $role->has_cap( $cap ) ) {
            $role->add_cap( $cap, true );
            $added[] = $cap;
        } else {
            $already[] = $cap;
        }
    }
}

// 2. Añadir caps al USUARIO actual (por si su entrada en usermeta no tiene las caps)
$user = wp_get_current_user();
foreach ( $required_caps as $cap ) {
    $user->add_cap( $cap, true );
}

// 3. Borrar transients de verificación de caps (para forzar revalidación)
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ltms_admin_caps_ok_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ltms_admin_caps_ok_%'" );

// 4. Limpiar object cache
wp_cache_flush();

// 5. Autodestruirse
@unlink( __FILE__ );

// Mostrar resultado
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>LTMS Caps Fix</title>
<style>body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:20px}
.ok{color:#27ae60;font-weight:bold}.info{color:#666;font-size:13px}
pre{background:#f5f5f5;padding:12px;border-radius:4px;font-size:12px;overflow:auto}
.btn{display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;margin-top:16px}</style>
</head>
<body>
<h2>✅ LTMS Admin Caps — Fix ejecutado</h2>
<p class="ok">Las capacidades del administrador han sido restauradas.</p>

<h3>Caps añadidas al rol (<?php echo count($added); ?>):</h3>
<pre><?php echo $added ? implode("
", $added) : "(todas ya existían)"; ?></pre>

<h3>Caps ya presentes (<?php echo count($already); ?>):</h3>
<pre><?php echo $already ? implode("
", $already) : "(ninguna)"; ?></pre>

<p class="info">Usuario actual: <strong><?php echo esc_html($user->user_login); ?></strong></p>
<p class="info">✅ Este archivo se autodestruyó después de ejecutarse.</p>
<p class="info">⚠️ Transients de validación borrados — las caps se revalidarán en el próximo request.</p>

<a class="btn" href="<?php echo admin_url("admin.php?page=ltms-dashboard"); ?>">
    → Ir al panel LTMS
</a>
</body>
</html>
