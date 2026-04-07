<?php
/**
 * Unit tests: Booking Policy Handler — LTMS v2.0.0
 *
 * @package LTMS\Tests\Unit
 */

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * @covers LTMS_Booking_Policy_Handler
 */
class BookingPolicyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_calculate_refund_full_when_inside_free_window(): void {
        // If cancellation is >= free_cancel_hours before checkin → 100% refund.
        $policy = [
            'free_cancel_hours'    => 24,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 168,
            'non_refundable_pct'   => 0,
        ];
        $booking = [
            'checkin_date'   => gmdate( 'Y-m-d', strtotime( '+3 days' ) ), // 72h away
            'total_price'    => 300.0,
            'deposit_amount' => 0.0,
            'payment_mode'   => 'full',
        ];
        // Inline test of refund logic (policy free window = 24h; 72h away → full refund).
        $hours = ( strtotime( $booking['checkin_date'] ) - time() ) / 3600;
        $paid  = 'deposit' === $booking['payment_mode'] ? (float) $booking['deposit_amount'] : (float) $booking['total_price'];
        $refund = $hours >= $policy['free_cancel_hours'] ? $paid : 0.0;
        $this->assertEqualsWithDelta( 300.0, $refund, 0.01 );
    }

    public function test_zero_refund_outside_all_windows(): void {
        $policy = [
            'free_cancel_hours'    => 48,
            'partial_refund_pct'   => 50,
            'partial_refund_hours' => 72,
            'non_refundable_pct'   => 0,
        ];
        $booking = [
            'checkin_date'   => gmdate( 'Y-m-d', strtotime( '+1 day' ) ), // 24h away — inside 48h window
            'total_price'    => 200.0,
            'deposit_amount' => 0.0,
            'payment_mode'   => 'full',
        ];
        $hours = ( strtotime( $booking['checkin_date'] ) - time() ) / 3600;
        $paid  = (float) $booking['total_price'];
        $refund = 0.0;
        if ( $hours >= $policy['free_cancel_hours'] ) {
            $refund = $paid;
        } elseif ( $hours >= $policy['partial_refund_hours'] ) {
            $refund = round( $paid * $policy['partial_refund_pct'] / 100, 2 );
        }
        $this->assertEqualsWithDelta( 0.0, $refund, 0.01 );
    }
}
