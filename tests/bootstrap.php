<?php
/**
 * PHPUnit bootstrap — LTMS v2.0.0
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find $_tests_dir/includes/functions.php\n";
    echo "Run: bash bin/install-wp-tests.sh\n";
    exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

function _register_ltms_plugin(): void {
    // Load WooCommerce stubs if available (Brain\Monkey).
    if ( function_exists( 'Brain\Monkey\setUp' ) ) return;

    require dirname( __DIR__ ) . '/lt-marketplace-suite.php';
}
tests_add_filter( 'muplugins_loaded', '_register_ltms_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
