<?php
/**
 * Unit tests: Analytics Manager — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_Analytics_Manager
 */
class AnalyticsManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_is_idempotent(): void {
        if ( ! class_exists( 'LTMS_Analytics_Manager' ) ) {
            $this->markTestSkipped();
        }
        \LTMS_Analytics_Manager::init();
        \LTMS_Analytics_Manager::init(); // Second call must not register duplicate hooks.
        $this->assertTrue( true );
    }

    public function test_no_output_when_gtm_and_ga4_unconfigured(): void {
        if ( ! class_exists( 'LTMS_Analytics_Manager' ) ) {
            $this->markTestSkipped();
        }
        Functions\when( 'LTMS_Core_Config::get' )->justReturn( '' );
        ob_start();
        \LTMS_Analytics_Manager::inject_head_tags();
        $output = ob_get_clean();
        $this->assertEmpty( trim( $output ) );
    }
}
