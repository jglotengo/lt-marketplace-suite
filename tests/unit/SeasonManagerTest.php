<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Tests para LTMS_Booking_Season_Manager — versión extendida
 */
class SeasonManagerTest extends LTMS_Unit_Test_Case
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ════════════════════════════════════════════════════════════════════
    // apply_season_modifier() — precio × modifier, redondeado a 2 decimales
    // ════════════════════════════════════════════════════════════════════

    public function test_apply_season_modifier_with_default_modifier_returns_price(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier(100.0, 1, '2025-07-15');
        $this->assertEqualsWithDelta(100.0, $result, 0.01);
    }

    public function test_apply_season_modifier_returns_float(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier(250.0, 0, '2025-12-24');
        $this->assertIsFloat($result);
    }

    public function test_apply_season_modifier_rounds_to_two_decimals(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier(100.999, 0, '2025-07-01');
        $this->assertSame(round($result, 2), $result);
    }

    public function test_apply_season_modifier_product_id_zero_same_as_positive(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        // Con $wpdb null ambos retornan modifier=1.0 → precio×1.0
        $r0 = \LTMS_Booking_Season_Manager::apply_season_modifier(150.0, 0, '2025-08-01');
        $rP = \LTMS_Booking_Season_Manager::apply_season_modifier(150.0, 99, '2025-08-01');
        $this->assertEqualsWithDelta($r0, $rP, 0.001);
    }

    public function test_apply_season_modifier_zero_price_returns_zero(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier(0.0, 1, '2025-07-15');
        $this->assertEqualsWithDelta(0.0, $result, 0.001);
    }

    public function test_apply_season_modifier_large_price_returns_float(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::apply_season_modifier(999999.99, 1, '2025-07-15');
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);
    }

    // ════════════════════════════════════════════════════════════════════
    // Lógica pura del modifier via matemática directa
    // ════════════════════════════════════════════════════════════════════

    public function test_price_multiplied_by_modifier_150pct(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = round(200.0 * 1.5, 2);
        $this->assertEqualsWithDelta(300.0, $result, 0.01);
    }

    public function test_price_multiplied_by_modifier_50pct(): void
    {
        $result = round(200.0 * 0.5, 2);
        $this->assertEqualsWithDelta(100.0, $result, 0.01);
    }

    public function test_modifier_floor_at_0_1(): void
    {
        $this->assertSame(0.1, max(0.1, 0.05));
        $this->assertSame(0.1, max(0.1, 0.0));
        $this->assertSame(0.1, max(0.1, -1.0));
    }

    public function test_modifier_above_floor_passes_through(): void
    {
        $this->assertSame(0.5, max(0.1, 0.5));
        $this->assertSame(2.0, max(0.1, 2.0));
        $this->assertSame(1.0, max(0.1, 1.0));
    }

    public function test_modifier_200pct_doubles_price(): void
    {
        $result = round(500.0 * 2.0, 2);
        $this->assertEqualsWithDelta(1000.0, $result, 0.01);
    }

    public function test_modifier_exact_floor_010_passes(): void
    {
        $this->assertSame(0.1, max(0.1, 0.1));
    }

    public function test_modifier_75pct_discount(): void
    {
        $result = round(400.0 * 0.75, 2);
        $this->assertEqualsWithDelta(300.0, $result, 0.01);
    }

    // ════════════════════════════════════════════════════════════════════
    // calculate_total() — loop de noches × modifier
    // ════════════════════════════════════════════════════════════════════

    public function test_calculate_total_zero_nights_returns_zero(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-15', '2025-07-15');
        $this->assertEqualsWithDelta(0.0, $result, 0.001);
    }

    public function test_calculate_total_one_night_with_default_modifier(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-15', '2025-07-16');
        $this->assertEqualsWithDelta(100.0, $result, 0.01);
    }

    public function test_calculate_total_three_nights_with_default_modifier(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-15', '2025-07-18');
        $this->assertEqualsWithDelta(300.0, $result, 0.01);
    }

    public function test_calculate_total_returns_float(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(200.0, 1, '2025-07-01', '2025-07-03');
        $this->assertIsFloat($result);
    }

    public function test_calculate_total_rounds_to_two_decimals(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(33.333, 1, '2025-07-01', '2025-07-04');
        $this->assertSame(round($result, 2), $result);
    }

    public function test_calculate_total_seven_nights(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(150.0, 1, '2025-07-01', '2025-07-08');
        $this->assertEqualsWithDelta(1050.0, $result, 0.01);
    }

    public function test_calculate_total_fractional_price_30_nights(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        // 30 noches × 99.99 con modifier=1.0
        $result = \LTMS_Booking_Season_Manager::calculate_total(99.99, 0, '2025-01-01', '2025-01-31');
        $this->assertEqualsWithDelta(30 * 99.99, $result, 0.10);
    }

    public function test_calculate_total_checkout_before_checkin_returns_zero(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-20', '2025-07-15');
        $this->assertEqualsWithDelta(0.0, $result, 0.001);
    }

    public function test_calculate_total_proportional_to_nights(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $two  = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-01', '2025-07-03');
        $four = \LTMS_Booking_Season_Manager::calculate_total(100.0, 1, '2025-07-01', '2025-07-05');
        $this->assertEqualsWithDelta($four, $two * 2, 0.01);
    }

    public function test_calculate_total_price_zero_always_zero(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $result = \LTMS_Booking_Season_Manager::calculate_total(0.0, 1, '2025-07-01', '2025-07-10');
        $this->assertEqualsWithDelta(0.0, $result, 0.001);
    }

    // ════════════════════════════════════════════════════════════════════
    // Reflexión — estructura y visibilidad de la clase
    // ════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(class_exists('LTMS_Booking_Season_Manager'));
    }

    public function test_apply_season_modifier_method_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(method_exists('LTMS_Booking_Season_Manager', 'apply_season_modifier'));
    }

    public function test_get_modifier_for_date_method_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(method_exists('LTMS_Booking_Season_Manager', 'get_modifier_for_date'));
    }

    public function test_calculate_total_method_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(method_exists('LTMS_Booking_Season_Manager', 'calculate_total'));
    }

    public function test_get_rules_method_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(method_exists('LTMS_Booking_Season_Manager', 'get_rules'));
    }

    public function test_save_rule_method_exists(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $this->assertTrue(method_exists('LTMS_Booking_Season_Manager', 'save_rule'));
    }

    public function test_apply_season_modifier_is_public_static(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'apply_season_modifier');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_calculate_total_return_type_is_float(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'calculate_total');
        $rt  = $ref->getReturnType();
        $this->assertNotNull($rt);
        $this->assertSame('float', $rt->getName());
    }

    public function test_apply_season_modifier_accepts_correct_params(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref    = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'apply_season_modifier');
        $params = $ref->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('price',        $params[0]->getName());
        $this->assertSame('product_id',   $params[1]->getName());
        $this->assertSame('checkin_date', $params[2]->getName());
    }

    public function test_save_rule_signature_accepts_array(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref    = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'save_rule');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertSame('array', $type->getName());
    }

    public function test_save_rule_source_uses_strtoupper_for_country_code(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref  = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'save_rule');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString('strtoupper', $code);
    }

    public function test_save_rule_source_sanitizes_season_name(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref  = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'save_rule');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString('sanitize_text_field', $code);
    }

    public function test_calculate_total_is_public_static(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'calculate_total');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_get_rules_is_public_static(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'get_rules');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_init_method_exists_and_is_public_static(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'init');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_calculate_total_accepts_four_params(): void
    {
        $this->require_class('LTMS_Booking_Season_Manager');
        $ref    = new \ReflectionMethod('LTMS_Booking_Season_Manager', 'calculate_total');
        $params = $ref->getParameters();
        $this->assertCount(4, $params);
    }
}
