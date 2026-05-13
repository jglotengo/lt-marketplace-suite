<?php
/**
 * LTMS Deploy Webhook
 * Coloca este archivo en: /home/customer/www/lo-tengo.com.co/public_html/ltms-deploy-webhook.php
 * NO subir a wp-content — debe estar en la raíz de public_html
 *
 * Token se genera con: openssl rand -hex 32
 */

// ── Configuración ────────────────────────────────────────────────────────────
define( 'DEPLOY_TOKEN',   'ltms_deploy_2026_s3cur3_t0k3n_x9z' ); // Cambiar en producción
define( 'PLUGIN_PATH',    __DIR__ . '/wp-content/plugins/lt-marketplace-suite' );
define( 'LOG_FILE',       __DIR__ . '/ltms-deploy.log' );
define( 'MAX_LOG_LINES',  200 );

// ── Seguridad ─────────────────────────────────────────────────────────────────
header( 'Content-Type: text/plain; charset=utf-8' );
// Prevent SiteGround from caching this response or redirecting to CAPTCHA
header( 'Cache-Control: no-store, no-cache, must-revalidate' );
header( 'X-Robots-Tag: noindex' );

// Accept token via GET param or X-Deploy-Token header (bypass bot detection)
$token = $_GET['token'] 
    ?? ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '')
    ?? '';

if ( empty( $token ) || ! hash_equals( DEPLOY_TOKEN, $token ) ) {
    http_response_code( 403 );
    echo "Forbidden: invalid token\n";
    exit;
}

// Solo GET o POST
if ( ! in_array( $_SERVER['REQUEST_METHOD'], [ 'GET', 'POST' ], true ) ) {
    http_response_code( 405 );
    exit( "Method not allowed\n" );
}

// ── Ejecutar git pull ─────────────────────────────────────────────────────────
if ( ! is_dir( PLUGIN_PATH . '/.git' ) ) {
    http_response_code( 500 );
    echo "Error: plugin directory not found or not a git repo\n";
    exit;
}

$cmd    = 'cd ' . escapeshellarg( PLUGIN_PATH ) . ' && git fetch origin 2>&1 && git reset --hard origin/main 2>&1 && git log --oneline -1 2>&1';
$output = shell_exec( $cmd );
$ts     = date( 'Y-m-d H:i:s' );

// ── Log ───────────────────────────────────────────────────────────────────────
$log_line = "[{$ts}] Deploy triggered from " . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) . "\n" . $output . "\n---\n";
file_put_contents( LOG_FILE, $log_line, FILE_APPEND | LOCK_EX );

// Rotar log si crece mucho
$lines = file( LOG_FILE );
if ( $lines && count( $lines ) > MAX_LOG_LINES ) {
    $trimmed = array_slice( $lines, -MAX_LOG_LINES );
    file_put_contents( LOG_FILE, implode( '', $trimmed ), LOCK_EX );
}

// ── Respuesta ─────────────────────────────────────────────────────────────────
echo "Deploy OK [{$ts}]\n";
echo $output;
