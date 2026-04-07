<?php
/**
 * Unit tests: Geo Detector — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_Geo_Detector
 */
class GeoDetectorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_available_cities_returns_array(): void {
        if ( ! class_exists( 'LTMS_Geo_Detector' ) ) {
            $this->markTestSkipped();
        }
        $cities = \LTMS_Geo_Detector::get_available_cities();
        $this->assertIsArray( $cities );
        $this->assertNotEmpty( $cities );
    }

    public function test_get_available_cities_includes_bogota(): void {
        if ( ! class_exists( 'LTMS_Geo_Detector' ) ) {
            $this->markTestSkipped();
        }
        $cities = \LTMS_Geo_Detector::get_available_cities();
        $this->assertArrayHasKey( 'bogota', $cities );
    }

    public function test_get_current_city_returns_string(): void {
        if ( ! class_exists( 'LTMS_Geo_Detector' ) ) {
            $this->markTestSkipped();
        }
        Functions\when( 'LTMS_Core_Config::get' )->justReturn( 'bogota' );
        $city = \LTMS_Geo_Detector::get_current_city();
        $this->assertIsString( $city );
    }
}
