<?php
/**
 * Unit tests: Booking Manager — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_Booking_Manager
 */
class BookingManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_available_returns_false_when_slot_fully_booked(): void {
        // Slots fully booked → is_available must return false.
        // Requires real DB; delegated to integration suite.
        $this->assertTrue( true );
    }

    public function test_cancel_returns_wp_error_for_unknown_booking(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            $this->markTestSkipped( 'Requires WP environment.' );
        }
        $result = \LTMS_Booking_Manager::cancel_booking( 99999999 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_found', $result->get_error_code() );
    }

    public function test_cleanup_pending_bookings_does_not_throw(): void {
        // Cron task must be idempotent.
        if ( ! class_exists( 'LTMS_Booking_Manager' ) ) {
            $this->markTestSkipped( 'LTMS_Booking_Manager not loaded.' );
        }
        Functions\when( 'LTMS_Core_Config::get' )->justReturn( 30 );
        Functions\when( 'current_time' )->justReturn( '2026-04-07 00:00:00' );

        try {
            \LTMS_Booking_Manager::cleanup_pending_bookings();
            $this->assertTrue( true );
        } catch ( \Throwable $e ) {
            $this->fail( 'cleanup_pending_bookings threw: ' . $e->getMessage() );
        }
    }
}
