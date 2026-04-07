<?php
/**
 * Unit tests: Tourism Compliance — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_Business_Tourism_Compliance
 */
class TourismComplianceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_can_publish_when_rnt_not_required(): void {
        if ( ! class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            $this->markTestSkipped();
        }
        Functions\when( 'LTMS_Core_Config::get' )->justReturn( false );
        $this->assertTrue( \LTMS_Business_Tourism_Compliance::can_publish_accommodation( 1 ) );
    }

    public function test_check_rnt_expiry_does_not_throw(): void {
        if ( ! class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            $this->markTestSkipped();
        }
        try {
            \LTMS_Business_Tourism_Compliance::check_rnt_expiry();
            $this->assertTrue( true );
        } catch ( \Throwable $e ) {
            $this->fail( 'check_rnt_expiry threw: ' . $e->getMessage() );
        }
    }
}
