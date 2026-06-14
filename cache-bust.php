<?php
/**
 * LTMS Cache Bust — purga opcache, object cache y caché de página.
 * URL: /wp-content/plugins/lt-marketplace-suite/cache-bust.php?t=bust2026
 * TEMPORAL — borrar después de usar.
 */
if ( ! hash_equals( 'bust2026', $_GET['t'] ?? '' ) ) {
    http_response_code( 403 );
    exit( 'Forbidden' );
}
header( 'Content-Type: text/plain; charset=utf-8' );

$wp_load = __DIR__ . '/../../../wp-load.php';
echo "wp-load.php path: {$wp_load}\n";
echo "exists: " . ( file_exists( $wp_load ) ? 'YES' : 'NO' ) . "\n\n";

if ( file_exists( $wp_load ) ) {
    require_once $wp_load;
}

// 1. OPcache
if ( function_exists( 'opcache_reset' ) ) {
    echo "opcache_reset: " . ( opcache_reset() ? "OK" : "FAILED" ) . "\n";
} else {
    echo "opcache_reset: not available\n";
}

// 2. WP object cache
if ( function_exists( 'wp_cache_flush' ) ) {
    echo "wp_cache_flush: " . ( wp_cache_flush() ? "OK" : "FAILED/already empty" ) . "\n";
}

// 3. SiteGround SG Optimizer cache
if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
    sg_cachepress_purge_cache();
    echo "sg_cachepress_purge_cache: OK\n";
} else {
    echo "sg_cachepress_purge_cache: not available\n";
}
if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
    try {
        \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
        echo "Supercacher::purge_cache: OK\n";
    } catch ( \Throwable $e ) {
        echo "Supercacher::purge_cache ERR: " . $e->getMessage() . "\n";
    }
}

// 4. Elementor CSS cache
if ( class_exists( '\Elementor\Plugin' ) ) {
    try {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        echo "Elementor files_manager->clear_cache: OK\n";
    } catch ( \Throwable $e ) {
        echo "Elementor clear_cache ERR: " . $e->getMessage() . "\n";
    }
}

// 5. Touch dashboard-wrapper.php to bust any mtime-based cache
$wrapper = __DIR__ . '/includes/frontend/views/dashboard-wrapper.php';
if ( file_exists( $wrapper ) ) {
    touch( $wrapper );
    if ( function_exists( 'opcache_invalidate' ) ) {
        opcache_invalidate( $wrapper, true );
    }
    echo "\ndashboard-wrapper.php mtime: " . date( 'Y-m-d H:i:s', filemtime( $wrapper ) ) . "\n";
    echo "dashboard-wrapper.php has DIAG TEMPORAL marker: " . ( strpos( file_get_contents( $wrapper ), 'DIAG TEMPORAL' ) !== false ? 'YES' : 'NO' ) . "\n";
}

echo "\nDone.\n";
