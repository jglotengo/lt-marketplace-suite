<?php
/**
 * LTMS OPcache invalidator — llamar una vez via browser, luego borrar.
 * URL: https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026
 */
if ( ( $_GET['token'] ?? '' ) !== 'ltms_opcache_2026' ) {
    http_response_code( 403 );
    die( 'Forbidden' );
}

$plugin_dir = dirname( __DIR__ );
$target     = $plugin_dir . '/includes/api/class-ltms-api-zapsign.php';
$results    = [];

if ( function_exists( 'opcache_invalidate' ) ) {
    $results['invalidate_zapsign'] = opcache_invalidate( $target, true );
} else {
    $results['invalidate_zapsign'] = 'not available';
}

if ( function_exists( 'opcache_reset' ) ) {
    $results['opcache_reset'] = opcache_reset();
} else {
    $results['opcache_reset'] = 'not available';
}

$content = file_get_contents( $target );
$results['has_override']   = ( strpos( $content, 'Override perform_request' ) !== false );
$results['has_public_def'] = ( strpos( $content, 'public function perform_request' ) !== false );
$results['file_size']      = filesize( $target );
$results['file_mtime']     = date( 'Y-m-d H:i:s', filemtime( $target ) );

if ( function_exists( 'opcache_get_status' ) ) {
    $status = opcache_get_status( false );
    $results['opcache_enabled']      = $status['opcache_enabled'] ?? false;
    $results['validate_timestamps']  = ini_get( 'opcache.validate_timestamps' );
    $results['num_cached_scripts']   = $status['opcache_statistics']['num_cached_scripts'] ?? 'n/a';
}

header( 'Content-Type: application/json' );
echo json_encode( $results, JSON_PRETTY_PRINT );
