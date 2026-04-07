<?php
/**
 * Unit tests: SEO Manager — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_SEO_Manager
 */
class SeoManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_registers_wp_head_action(): void {
        if ( ! class_exists( 'LTMS_SEO_Manager' ) ) {
            $this->markTestSkipped();
        }
        // After init(), wp_head must have registered hooks.
        \LTMS_SEO_Manager::init();
        $this->assertGreaterThan( 0, has_action( 'wp_head', [ 'LTMS_SEO_Manager', 'inject_schema_org' ] ) );
    }

    public function test_inject_search_console_outputs_nothing_when_unconfigured(): void {
        if ( ! class_exists( 'LTMS_SEO_Manager' ) ) {
            $this->markTestSkipped();
        }
        Functions\when( 'LTMS_Core_Config::get' )->justReturn( '' );
        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();
        $this->assertEmpty( $output );
    }
}
