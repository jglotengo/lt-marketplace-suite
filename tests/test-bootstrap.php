<?php
/**
 * LTMS PHPUnit Bootstrap
 *
 * Carga el entorno de WordPress y el plugin para las pruebas unitarias.
 *
 * Uso:
 *   vendor/bin/phpunit --bootstrap tests/test-bootstrap.php tests/Unit/
 *
 * @package    LTMS
 * @subpackage Tests
 * @version    1.5.0
 */

// Directorio raíz del plugin
define( 'LTMS_TEST_DIR', dirname( __DIR__ ) . '/' );

// Cargar autoloader de Composer
if ( file_exists( LTMS_TEST_DIR . 'vendor/autoload.php' ) ) {
    require_once LTMS_TEST_DIR . 'vendor/autoload.php';
}

// Cargar WP Test Suite si está disponible
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! is_dir( $wp_tests_dir ) ) {
    $wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( is_dir( $wp_tests_dir ) ) {
    require_once $wp_tests_dir . '/includes/functions.php';

    /**
     * Carga el plugin en el entorno de WP tests.
     */
    function _manually_load_plugin(): void {
        require_once LTMS_TEST_DIR . 'lt-marketplace-suite.php';
    }

    tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
    require $wp_tests_dir . '/includes/bootstrap.php';
} else {
    // Entorno mock para pruebas unitarias sin WP
    require_once __DIR__ . '/mocks/wp-mock-bootstrap.php';
}
