<?php
/**
 * LTMS Deploy Webhook
 * Coloca este archivo en: /home/customer/www/lo-tengo.com.co/public_html/ltms-deploy-webhook.php
 * NO subir a wp-content — debe estar en la raíz de public_html
 */

define( 'DEPLOY_TOKEN',   'ltms_deploy_2026_s3cur3_t0k3n_x9z' );
define( 'PLUGIN_PATH',    __DIR__ . '/wp-content/plugins/lt-marketplace-suite' );
define( 'LOG_FILE',       __DIR__ . '/ltms-deploy.log' );
define( 'MAX_LOG_LINES',  500 );

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Cache-Control: no-store, no-cache, must-revalidate' );
header( 'X-Robots-Tag: noindex' );

$token = $_GET['token']
    ?? ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '')
    ?? '';

if ( empty( $token ) || ! hash_equals( DEPLOY_TOKEN, $token ) ) {
    http_response_code( 403 );
    echo "Forbidden: invalid token\n";
    exit;
}

if ( ! in_array( $_SERVER['REQUEST_METHOD'], [ 'GET', 'POST' ], true ) ) {
    http_response_code( 405 );
    exit( "Method not allowed\n" );
}

$ts = date( 'Y-m-d H:i:s' );
echo "[{$ts}] Deploy webhook triggered\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "PLUGIN_PATH: " . PLUGIN_PATH . "\n";
echo "Path exists: " . ( is_dir( PLUGIN_PATH ) ? 'YES' : 'NO' ) . "\n";
echo "Has .git: " . ( is_dir( PLUGIN_PATH . '/.git' ) ? 'YES' : 'NO' ) . "\n\n";

// ── Diagnóstico de archivos clave ─────────────────────────────────────────────
$key_files = [
    'assets/css/ltms-auditor.css',
    'includes/admin/views/view-auditor-dashboard.php',
    'includes/admin/class-ltms-admin.php',
    'includes/roles/class-ltms-external-auditor-role.php',
];

foreach ( $key_files as $f ) {
    $full = PLUGIN_PATH . '/' . $f;
    if ( file_exists( $full ) ) {
        echo "FILE {$f}\n";
        echo "  size: " . filesize( $full ) . " bytes  |  modified: " . date( 'Y-m-d H:i:s', filemtime( $full ) ) . "\n";
    } else {
        echo "MISSING {$f}\n";
    }
}
echo "\n";

// ── git reset --hard ──────────────────────────────────────────────────────────
if ( ! is_dir( PLUGIN_PATH . '/.git' ) ) {
    // No git repo: copy files directly from this deploy package if available
    http_response_code( 500 );
    echo "ERROR: PLUGIN_PATH is not a git repository.\n";
    echo "Manual deploy needed: upload files via SFTP.\n";
    exit;
}

echo "--- Running git fetch + reset ---\n";
$cmd    = 'cd ' . escapeshellarg( PLUGIN_PATH )
    . ' && git fetch origin 2>&1'
    . ' && git reset --hard origin/main 2>&1'
    . ' && git log --oneline -3 2>&1';
$output = shell_exec( $cmd );
echo $output . "\n";

// ── Flush OPcache ─────────────────────────────────────────────────────────────
echo "--- OPcache ---\n";
if ( function_exists( 'opcache_reset' ) ) {
    $reset = opcache_reset();
    echo "opcache_reset(): " . ( $reset ? "OK" : "FAILED" ) . "\n";
} else {
    echo "opcache_reset not available (OPcache disabled or CLI mode)\n";
}

// ── Verificar archivos post-deploy ────────────────────────────────────────────
echo "\n--- Post-deploy file check ---\n";
foreach ( $key_files as $f ) {
    $full = PLUGIN_PATH . '/' . $f;
    if ( file_exists( $full ) ) {
        $content = file_get_contents( $full );
        // Check for v2.3.0 markers
        $has_v230 = ( strpos( $content, 'v2.3.0' ) !== false || strpos( $content, 'ltms-page-header' ) !== false || strpos( $content, 'ltms-kpi-grid' ) !== false );
        echo "  {$f}: " . filesize( $full ) . "b | v2.3.0=" . ( $has_v230 ? 'YES ✓' : 'NO ✗' ) . "\n";
    }
}

echo "\n--- Log ---\n";
$log_line = "[{$ts}] Deploy triggered from " . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) . "\n" . $output . "\n---\n";
file_put_contents( LOG_FILE, $log_line, FILE_APPEND | LOCK_EX );

// Rotar log
$lines = file( LOG_FILE );
if ( $lines && count( $lines ) > MAX_LOG_LINES ) {
    file_put_contents( LOG_FILE, implode( '', array_slice( $lines, -MAX_LOG_LINES ) ), LOCK_EX );
}

echo "Deploy OK [{$ts}]\n";
