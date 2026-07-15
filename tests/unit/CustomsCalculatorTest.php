<?php
/**
 * CustomsCalculatorTest — Tests unitarios para LTMS_Customs_Calculator
 *
 * Cubre:
 * - calculate(): domestic (origin==dest), below de minimis, DDP vs DDU, paid_by
 * - De Minimis: threshold por país, comparación CIF (no FOB), strict <
 * - US specifics: duty_base = FOB, MPF min/max cap, MPF on FOB
 * - Duty rate: config override, country default, built-in default, global fallback
 * - VAT rate: built-in, override with clamp [0,100], unknown country fallback
 * - Excise: clamp [0,1000], prefix matching (4-digit, 2-digit)
 * - HS code sanitization (digits only)
 * - INCOTERM 2020 validation
 * - Filters: ltms_customs_calc_args, ltms_customs_de_minimis, ltms_customs_calculator_result
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_Customs_Calculator
 */
class CustomsCalculatorTest extends LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();

        // apply_filters ya está stubbeado en base (passthrough).
        // Sanitize ya está stubbeado.
    }

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_Customs_Calculator::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── SECCIÓN 1 — Domestic shipments ────────────────────────────────────

    public function test_calculate_domestic_shipment_returns_zero_result(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 1000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'CO',
            'shipping_cost'       => 50.0,
            'incoterm'            => 'DDP',
        ]);
        $this->assertSame(0.0, $result['cif_value']);
        $this->assertSame(0.0, $result['duty_amount']);
        $this->assertSame(0.0, $result['total_duties']);
        $this->assertSame('n/a', $result['paid_by']);
        $this->assertFalse($result['below_de_minimis']);
    }

    public function test_calculate_domestic_case_insensitive(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 500.0,
            'origin_country'      => 'co',
            'destination_country' => 'CO',
        ]);
        $this->assertSame(0.0, $result['total_duties']);
    }

    // ── SECCIÓN 2 — Below De Minimis ──────────────────────────────────────

    public function test_is_below_de_minimis_strict_less_than(): void {
        // US threshold = $800. CIF = $799.99 → below.
        $this->assertTrue(\LTMS_Customs_Calculator::is_below_de_minimis(700.0, 'US', 99.99, 0.0));
        // Exactly $800 → NOT below (strict <).
        $this->assertFalse(\LTMS_Customs_Calculator::is_below_de_minimis(800.0, 'US', 0.0, 0.0));
        // Above → not below.
        $this->assertFalse(\LTMS_Customs_Calculator::is_below_de_minimis(1000.0, 'US', 0.0, 0.0));
    }

    public function test_is_below_de_minimis_uses_cif_not_fob(): void {
        // CC-BUG-1 FIX: comparison on CIF (item + shipping + insurance).
        // item=$700 + shipping=$200 = $900 CIF, >$800 → NOT below.
        $this->assertFalse(\LTMS_Customs_Calculator::is_below_de_minimis(700.0, 'US', 200.0, 0.0));
        // item=$500 + shipping=$200 + insurance=$50 = $750 → below.
        $this->assertTrue(\LTMS_Customs_Calculator::is_below_de_minimis(500.0, 'US', 200.0, 50.0));
    }

    public function test_is_below_de_minimis_unknown_country_returns_false(): void {
        $this->assertFalse(\LTMS_Customs_Calculator::is_below_de_minimis(10.0, 'ZZ', 0.0, 0.0));
    }

    public function test_get_de_minimis_returns_known_thresholds(): void {
        $this->assertSame(800.0, \LTMS_Customs_Calculator::get_de_minimis('US'));
        $this->assertSame(200.0, \LTMS_Customs_Calculator::get_de_minimis('CO'));
        $this->assertSame(50.0, \LTMS_Customs_Calculator::get_de_minimis('MX'));
        $this->assertSame(150.0, \LTMS_Customs_Calculator::get_de_minimis('DE'));
    }

    public function test_get_de_minimis_unknown_country_returns_zero(): void {
        $this->assertSame(0.0, \LTMS_Customs_Calculator::get_de_minimis('ZZ'));
    }

    public function test_calculate_below_de_minimis_returns_zero_with_flag(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 100.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 50.0, // CIF = 150, < 800
        ]);
        $this->assertSame(0.0, $result['duty_amount']);
        $this->assertTrue($result['below_de_minimis']);
        $this->assertSame('n/a', $result['paid_by']);
    }

    // ── SECCIÓN 3 — INCOTERM validation ───────────────────────────────────

    public function test_calculate_invalid_incoterm_falls_back_to_ddu(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'incoterm'            => 'INVALID',
        ]);
        $this->assertSame('DDU', $result['incoterm']);
    }

    public function test_calculate_ddp_paid_by_seller(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'incoterm'            => 'DDP',
        ]);
        $this->assertSame('DDP', $result['incoterm']);
        $this->assertSame('seller', $result['paid_by']);
    }

    public function test_calculate_ddu_paid_by_buyer(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'incoterm'            => 'DDU',
        ]);
        $this->assertSame('DDU', $result['incoterm']);
        $this->assertSame('buyer', $result['paid_by']);
    }

    public function test_calculate_accepts_all_incoterms_2020(): void {
        $valid = ['EXW','FCA','FAS','FOB','CFR','CIF','CPT','CIP','DAP','DPU','DDP','DDU'];
        foreach ($valid as $inc) {
            $result = \LTMS_Customs_Calculator::calculate([
                'item_value'          => 5000.0,
                'origin_country'      => 'CO',
                'destination_country' => 'US',
                'incoterm'            => $inc,
            ]);
            $this->assertSame($inc, $result['incoterm'], "INCOTERM $inc must be accepted");
        }
    }

    // ── SECCIÓN 4 — US specifics (FOB + MPF cap) ──────────────────────────

    public function test_calculate_us_uses_fob_for_duty_base(): void {
        // CC-BUG-3 FIX: US duty base = FOB (item_value), excludes freight/insurance.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 500.0,
            'insurance_cost'      => 100.0,
        ]);
        // CIF = 5600. Duty rate (US default) = 3.4%. FOB = 5000.
        // Duty = 5000 * 3.4% = 170.
        $this->assertEqualsWithDelta(170.0, $result['duty_amount'], 0.01);
    }

    public function test_calculate_us_mpf_capped_at_min(): void {
        // Very low-value shipment → MPF would be < $31.67 min → capped up.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 900.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 0.0,
        ]);
        // MPF flat 6.50 + 0.346% of 900 = 6.50 + 3.11 = 9.61 → capped up to 31.67.
        $this->assertGreaterThanOrEqual(31.67, $result['customs_fee']);
    }

    public function test_calculate_us_mpf_capped_at_max(): void {
        // Very high-value shipment → MPF would be > $614.25 max → capped down.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 500000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 0.0,
        ]);
        $this->assertLessThanOrEqual(614.25, $result['customs_fee']);
    }

    public function test_calculate_us_mpf_on_fob_not_cif(): void {
        // CC-3 FIX: MPF percentage base = FOB (item_value) for US, not CIF.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 10000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 5000.0, // CIF = 15000, FOB = 10000.
        ]);
        // MPF = 6.50 + 0.346% of FOB (10000) = 6.50 + 34.60 = 41.10.
        // If it incorrectly used CIF, would be 6.50 + 0.346% of 15000 = 6.50 + 51.90 = 58.40.
        $this->assertEqualsWithDelta(41.10, $result['customs_fee'], 0.05);
    }

    // ── SECCIÓN 5 — VAT rate resolution ───────────────────────────────────

    public function test_vat_known_countries(): void {
        $this->assertSame(0.0, self::callPrivate('get_vat_rate', 'US'));
        $this->assertSame(19.0, self::callPrivate('get_vat_rate', 'CO'));
        $this->assertSame(16.0, self::callPrivate('get_vat_rate', 'MX'));
        $this->assertSame(20.0, self::callPrivate('get_vat_rate', 'GB'));
        $this->assertSame(19.0, self::callPrivate('get_vat_rate', 'DE'));
    }

    public function test_vat_unknown_country_returns_fallback(): void {
        // FASE5 P0 FIX: unknown country → 20% fallback (not 0%).
        $rate = self::callPrivate('get_vat_rate', 'ZZ');
        $this->assertSame(20.0, $rate);
    }

    public function test_vat_override_clamped_to_max_100(): void {
        // CC-2 FIX: VAT override >100% clamped to 100.
        $this->mock_options(['ltms_customs_vat_rates' => ['XX' => 150.0]]);
        $rate = self::callPrivate('get_vat_rate', 'XX');
        $this->assertSame(100.0, $rate);
    }

    public function test_vat_override_clamped_to_min_0(): void {
        $this->mock_options(['ltms_customs_vat_rates' => ['XX' => -10.0]]);
        $rate = self::callPrivate('get_vat_rate', 'XX');
        $this->assertSame(0.0, $rate);
    }

    // ── SECCIÓN 6 — Duty rate resolution ──────────────────────────────────

    public function test_duty_rate_default_for_known_country(): void {
        $rate = self::callPrivate('get_duty_rate', 'US', '');
        $this->assertSame(3.4, $rate);
        $rate = self::callPrivate('get_duty_rate', 'BR', '');
        $this->assertSame(11.0, $rate);
    }

    public function test_duty_rate_unknown_country_uses_global_fallback(): void {
        // Default global fallback = 5.0%.
        $rate = self::callPrivate('get_duty_rate', 'ZZ', '');
        $this->assertSame(5.0, $rate);
    }

    public function test_duty_rate_clamped_to_max_100(): void {
        // CC-1 FIX: duty rate >100% clamped.
        $this->mock_options(['ltms_customs_duty_rates' => ['YY_1234' => 200.0]]);
        $rate = self::callPrivate('get_duty_rate', 'YY', '1234');
        $this->assertSame(100.0, $rate);
    }

    public function test_duty_rate_clamped_to_min_0(): void {
        $this->mock_options(['ltms_customs_duty_rates' => ['YY_1234' => -5.0]]);
        $rate = self::callPrivate('get_duty_rate', 'YY', '1234');
        $this->assertSame(0.0, $rate);
    }

    public function test_duty_rate_from_textarea_string_format(): void {
        // CC-BUG-7 FIX: textarea string format.
        $this->mock_options(['ltms_customs_duty_rates' => "US=3.4\nUS_6101=15.0\nBR=11.0"]);
        $rate = self::callPrivate('get_duty_rate', 'US', '6101');
        $this->assertSame(15.0, $rate);
        $rate = self::callPrivate('get_duty_rate', 'BR', '');
        $this->assertSame(11.0, $rate);
    }

    public function test_duty_rate_country_default_fallback(): void {
        $this->mock_options(['ltms_customs_duty_rates' => ['XX_default' => 7.5]]);
        $rate = self::callPrivate('get_duty_rate', 'XX', '9999');
        $this->assertSame(7.5, $rate);
    }

    // ── SECCIÓN 7 — Excise taxes ──────────────────────────────────────────

    public function test_excise_tax_exact_hs_match(): void {
        $this->mock_options(['ltms_customs_excise_rates' => ['US_2401' => 50.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '2401');
        $this->assertEqualsWithDelta(500.0, $tax, 0.01);
    }

    public function test_excise_tax_4digit_prefix_match(): void {
        $this->mock_options(['ltms_customs_excise_rates' => ['US_2401' => 50.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '240110');
        $this->assertEqualsWithDelta(500.0, $tax, 0.01);
    }

    public function test_excise_tax_2digit_prefix_match(): void {
        $this->mock_options(['ltms_customs_excise_rates' => ['US_24' => 25.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '240110');
        $this->assertEqualsWithDelta(250.0, $tax, 0.01);
    }

    public function test_excise_tax_no_match_returns_zero(): void {
        $this->mock_options(['ltms_customs_excise_rates' => ['US_9999' => 50.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '2401');
        $this->assertSame(0.0, $tax);
    }

    public function test_excise_tax_rate_clamped_to_max_1000(): void {
        // CC-4 FIX: excise >1000% clamped.
        $this->mock_options(['ltms_customs_excise_rates' => ['US_2401' => 5000.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '2401');
        $this->assertEqualsWithDelta(10000.0, $tax, 0.01); // 1000% of 1000 = 10000.
    }

    public function test_excise_tax_rate_clamped_to_min_0(): void {
        $this->mock_options(['ltms_customs_excise_rates' => ['US_2401' => -20.0]]);
        $tax = self::callPrivate('calculate_other_taxes', 'US', 1000.0, '2401');
        $this->assertSame(0.0, $tax);
    }

    // ── SECCIÓN 8 — Input sanitization ────────────────────────────────────

    public function test_calculate_clamps_negative_item_value_to_zero(): void {
        // CC-BUG-5 FIX.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => -100.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
        ]);
        // item_value 0 → CIF 0 → below de minimis → zero result.
        $this->assertTrue($result['below_de_minimis']);
    }

    public function test_calculate_clamps_negative_shipping_to_zero(): void {
        // Use a high item_value so CIF is above de minimis ($800 US threshold)
        // — otherwise the result is zero_result with cif_value=0.
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => -50.0,
        ]);
        // CIF = 5000 (item) + 0 (clamped shipping) + 0 (no insurance) = 5000.
        $this->assertEqualsWithDelta(5000.0, $result['cif_value'], 0.01);
    }

    public function test_calculate_sanitizes_hs_code_to_digits(): void {
        // CC-BUG-8 FIX: HS code "61.01" → "6101".
        $this->mock_options(['ltms_customs_duty_rates' => ['US_6101' => 15.0]]);
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'hs_code'             => '61.01-A',
        ]);
        // Duty rate should be 15% (matched 6101), not 3.4% (default).
        $this->assertSame(15.0, $result['duty_rate']);
    }

    public function test_calculate_case_insensitive_countries(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 100.0,
            'origin_country'      => 'co',
            'destination_country' => 'us',
        ]);
        // Should work same as 'CO' → 'US'.
        $this->assertFalse($result['below_de_minimis'] === null);
    }

    // ── SECCIÓN 9 — CIF computation ───────────────────────────────────────

    public function test_cif_includes_item_plus_shipping_plus_insurance(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 1000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'BR', // Not US, so CIF is duty base.
            'shipping_cost'       => 200.0,
            'insurance_cost'      => 50.0,
        ]);
        // CIF = 1000 + 200 + 50 = 1250.
        $this->assertEqualsWithDelta(1250.0, $result['cif_value'], 0.01);
    }

    public function test_vat_computed_on_cif_plus_duty(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 1000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'BR',
            'shipping_cost'       => 0.0,
        ]);
        // CIF = 1000. Duty = 1000 * 11% = 110. VAT = (1000+110) * 17% = 188.70.
        $this->assertEqualsWithDelta(110.0, $result['duty_amount'], 0.01);
        $this->assertEqualsWithDelta(188.70, $result['vat_amount'], 0.01);
    }

    // ── SECCIÓN 10 — Result structure ─────────────────────────────────────

    public function test_calculate_returns_complete_result_structure(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
            'shipping_cost'       => 100.0,
            'insurance_cost'      => 50.0,
            'hs_code'             => '6101',
            'incoterm'            => 'DDP',
        ]);
        $required_keys = ['cif_value','duty_rate','duty_amount','vat_rate','vat_amount',
                          'other_taxes','customs_fee','total_duties','incoterm','paid_by',
                          'below_de_minimis','breakdown'];
        foreach ($required_keys as $key) {
            $this->assertArrayHasKey($key, $result, "Result must have key: $key");
        }
        $this->assertIsArray($result['breakdown']);
        $this->assertArrayHasKey('item_value', $result['breakdown']);
        $this->assertArrayHasKey('cif_value', $result['breakdown']);
    }

    public function test_calculate_total_equals_sum_of_components(): void {
        $result = \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'BR',
        ]);
        $sum = $result['duty_amount'] + $result['vat_amount'] + $result['other_taxes'] + $result['customs_fee'];
        $this->assertEqualsWithDelta($sum, $result['total_duties'], 0.01);
    }

    // ── SECCIÓN 11 — Filters ──────────────────────────────────────────────

    public function test_filter_ltms_customs_calc_args_is_invoked(): void {
        $invoked = false;
        $captured_args = null;
        Functions\when('apply_filters')->alias(function($tag, $value, ...$rest) use (&$invoked, &$captured_args) {
            if ($tag === 'ltms_customs_calc_args') {
                $invoked = true;
                $captured_args = $value;
            }
            return $value;
        });

        \LTMS_Customs_Calculator::calculate([
            'item_value'          => 5000.0,
            'origin_country'      => 'CO',
            'destination_country' => 'US',
        ]);
        $this->assertTrue($invoked, 'ltms_customs_calc_args filter must be invoked');
    }

    public function test_filter_ltms_customs_de_minimis_is_invoked(): void {
        $invoked = false;
        Functions\when('apply_filters')->alias(function($tag, $value, ...$rest) use (&$invoked) {
            if ($tag === 'ltms_customs_de_minimis') {
                $invoked = true;
            }
            return $value;
        });

        \LTMS_Customs_Calculator::get_de_minimis('US');
        $this->assertTrue($invoked, 'ltms_customs_de_minimis filter must be invoked');
    }
}
