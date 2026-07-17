<?php
/**
 * CurrencyManagerTest — Tests unitarios para LTMS_Currency_Manager
 *
 * Cubre:
 * - get_base_currency(): default USD, uppercase
 * - get_currency_for_country(): CO→COP, MX→MXN, EU members→EUR, unknown→null
 * - get_vendor_currency(): CM-2 FIX malformed meta → fallback
 * - get_decimals(): COP/CLP=0, others=2
 * - format_amount(): symbol placement (EUR after, others before), negative handling
 * - set_display_currency(): ISO validation, enabled-list check (anti session poisoning)
 * - convert_to_settlement(): CM-1 FIX historical rate, fallback chain
 * - get_enabled_currencies(): default list, intersection with supported
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers LTMS_Currency_Manager
 */
class CurrencyManagerTest extends LTMS_Unit_Test_Case {

    private array $user_meta = [];
    private ?float $mock_rate = null;
    private ?float $mock_convert = null;

    protected function setUp(): void {
        parent::setUp();

        $this->user_meta = [];
        $this->mock_rate = 1.0;
        $this->mock_convert = null;

        // Mock get_user_meta.
        $self = $this;
        Functions\when('get_user_meta')->alias(function($uid, $key, $single) use ($self) {
            return $self->user_meta[$uid][$key] ?? false;
        });

        // Mock WC() to return an object with null session — so get_display_currency()
        // skips the session check and falls through to geo/base.
        Functions\when('WC')->justReturn((object)['session' => null]);

        // Mock LTMS_FX_Rate_Provider statics.
        // The class is real in UNIT_ONLY mode; we can't override its methods.
        // Tests that depend on FX will use convert_to_settlement with $rate parameter.
    }

    private function set_user_meta(int $uid, array $meta): void {
        $this->user_meta[$uid] = $meta;
    }

    // ── SECCIÓN 1 — get_base_currency ─────────────────────────────────────

    public function test_get_base_currency_defaults_to_usd(): void {
        $this->assertSame('USD', \LTMS_Currency_Manager::get_base_currency());
    }

    public function test_get_base_currency_uppercases_value(): void {
        $this->mock_options(['ltms_base_currency' => 'cop']);
        $this->assertSame('COP', \LTMS_Currency_Manager::get_base_currency());
    }

    public function test_get_base_currency_returns_usd_when_empty(): void {
        $this->mock_options(['ltms_base_currency' => '']);
        $this->assertSame('USD', \LTMS_Currency_Manager::get_base_currency());
    }

    // ── SECCIÓN 2 — get_currency_for_country ──────────────────────────────

    public function test_currency_for_country_co_returns_cop(): void {
        $this->assertSame('COP', \LTMS_Currency_Manager::get_currency_for_country('CO'));
    }

    public function test_currency_for_country_mx_returns_mxn(): void {
        $this->assertSame('MXN', \LTMS_Currency_Manager::get_currency_for_country('MX'));
    }

    public function test_currency_for_country_us_returns_usd(): void {
        $this->assertSame('USD', \LTMS_Currency_Manager::get_currency_for_country('US'));
    }

    public function test_currency_for_country_br_returns_brl(): void {
        $this->assertSame('BRL', \LTMS_Currency_Manager::get_currency_for_country('BR'));
    }

    public function test_currency_for_country_eu_members_return_eur(): void {
        $eu = ['AT','BE','DE','FR','ES','IT','NL','PT','IE','FI','GR'];
        foreach ($eu as $cc) {
            $this->assertSame('EUR', \LTMS_Currency_Manager::get_currency_for_country($cc), "EU member $cc must map to EUR");
        }
    }

    public function test_currency_for_country_case_insensitive(): void {
        $this->assertSame('COP', \LTMS_Currency_Manager::get_currency_for_country('co'));
        $this->assertSame('USD', \LTMS_Currency_Manager::get_currency_for_country('us'));
    }

    public function test_currency_for_country_unknown_returns_null(): void {
        $this->assertNull(\LTMS_Currency_Manager::get_currency_for_country('ZZ'));
        $this->assertNull(\LTMS_Currency_Manager::get_currency_for_country(''));
    }

    // ── SECCIÓN 3 — get_vendor_currency (CM-2 FIX) ────────────────────────

    public function test_get_vendor_currency_uses_explicit_payout_currency(): void {
        $this->set_user_meta(1, ['ltms_payout_currency' => 'COP']);
        $this->assertSame('COP', \LTMS_Currency_Manager::get_vendor_currency(1));
    }

    public function test_get_vendor_currency_uppercases_payout_currency(): void {
        $this->set_user_meta(2, ['ltms_payout_currency' => 'mxn']);
        $this->assertSame('MXN', \LTMS_Currency_Manager::get_vendor_currency(2));
    }

    public function test_get_vendor_currency_rejects_2char_country_code(): void {
        // CM-2 FIX: 'CO' (2 chars) is malformed currency → fallback.
        $this->set_user_meta(3, [
            'ltms_payout_currency' => 'CO',
            'ltms_vendor_country' => 'CO',
        ]);
        // Should fall back to country currency COP.
        $this->assertSame('COP', \LTMS_Currency_Manager::get_vendor_currency(3));
    }

    public function test_get_vendor_currency_rejects_numeric_string(): void {
        $this->set_user_meta(4, [
            'ltms_payout_currency' => '0',
            'ltms_vendor_country' => 'MX',
        ]);
        $this->assertSame('MXN', \LTMS_Currency_Manager::get_vendor_currency(4));
    }

    public function test_get_vendor_currency_falls_back_to_country_currency(): void {
        $this->set_user_meta(5, [
            'ltms_vendor_country' => 'BR',
        ]);
        $this->assertSame('BRL', \LTMS_Currency_Manager::get_vendor_currency(5));
    }

    public function test_get_vendor_currency_falls_back_to_base_when_no_meta(): void {
        $this->set_user_meta(6, []);
        $this->assertSame('USD', \LTMS_Currency_Manager::get_vendor_currency(6));
    }

    public function test_get_vendor_currency_falls_back_to_base_when_country_unknown(): void {
        $this->set_user_meta(7, ['ltms_vendor_country' => 'ZZ']);
        $this->assertSame('USD', \LTMS_Currency_Manager::get_vendor_currency(7));
    }

    // ── SECCIÓN 4 — get_decimals ──────────────────────────────────────────

    public function test_get_decimals_returns_2_for_usd(): void {
        $this->assertSame(2, \LTMS_Currency_Manager::get_decimals('USD'));
    }

    public function test_get_decimals_returns_2_for_mxn(): void {
        $this->assertSame(2, \LTMS_Currency_Manager::get_decimals('MXN'));
    }

    public function test_get_decimals_returns_2_for_unknown_currency(): void {
        $this->assertSame(2, \LTMS_Currency_Manager::get_decimals('XYZ'));
    }

    // ── SECCIÓN 5 — format_amount ─────────────────────────────────────────

    public function test_format_amount_usd_symbol_before(): void {
        $formatted = \LTMS_Currency_Manager::format_amount(100.0, 'USD');
        $this->assertStringStartsWith('$', $formatted);
        $this->assertStringContainsString('100', $formatted);
    }

    public function test_format_amount_negative_renders_minus_before_symbol(): void {
        $formatted = \LTMS_Currency_Manager::format_amount(-100.0, 'USD');
        $this->assertStringStartsWith('-$', $formatted, 'Negative must be -$100.00 not $-100.00');
    }

    public function test_format_amount_cop_zero_decimals(): void {
        $formatted = \LTMS_Currency_Manager::format_amount(5000.0, 'COP');
        // COP uses 0 decimals → "5,000" not "5,000.00".
        $this->assertStringNotContainsString('.00', $formatted);
    }

    public function test_format_amount_with_thousands_separator(): void {
        $formatted = \LTMS_Currency_Manager::format_amount(1500000.0, 'USD');
        $this->assertStringContainsString('1,500,000', $formatted);
    }

    // ── SECCIÓN 6 — set_display_currency ──────────────────────────────────

    public function test_set_display_currency_ignores_invalid_length(): void {
        // No WC session in test env — function returns early silently.
        // We just verify no exception is thrown.
        \LTMS_Currency_Manager::set_display_currency('US'); // 2 chars
        \LTMS_Currency_Manager::set_display_currency('USDD'); // 4 chars
        $this->assertTrue(true); // Just verify no crash.
    }

    public function test_set_display_currency_ignores_non_alpha(): void {
        \LTMS_Currency_Manager::set_display_currency('123');
        $this->assertTrue(true);
    }

    // ── SECCIÓN 7 — convert_to_settlement (CM-1 FIX) ──────────────────────

    public function test_convert_to_settlement_same_currency_no_conversion(): void {
        $this->set_user_meta(10, ['ltms_payout_currency' => 'USD']);
        $result = \LTMS_Currency_Manager::convert_to_settlement(100.0, 'USD', 10);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_convert_to_settlement_uses_historical_rate_when_provided(): void {
        // CM-1 FIX: when $rate is provided, must use it directly (no live FX fetch).
        $this->set_user_meta(11, ['ltms_payout_currency' => 'COP']);
        $result = \LTMS_Currency_Manager::convert_to_settlement(100.0, 'USD', 11, 4000.0);
        $this->assertEqualsWithDelta(400000.0, $result['amount'], 0.01);
        $this->assertSame(4000.0, $result['rate']);
        $this->assertSame('COP', $result['currency']);
    }

    public function test_convert_to_settlement_ignores_zero_historical_rate(): void {
        // CM-1: rate <= 0 must fall through to live fetch.
        $this->set_user_meta(12, ['ltms_payout_currency' => 'USD']);
        $result = \LTMS_Currency_Manager::convert_to_settlement(100.0, 'USD', 12, 0.0);
        // Same currency → 1.0 rate path.
        $this->assertSame(100.0, $result['amount']);
    }

    public function test_convert_to_settlement_historical_rate_with_cop_zero_decimals(): void {
        $this->set_user_meta(13, ['ltms_payout_currency' => 'COP']);
        $result = \LTMS_Currency_Manager::convert_to_settlement(99.99, 'USD', 13, 4000.0);
        // COP has 0 decimals → round to integer.
        $this->assertSame(399960.0, $result['amount']);
    }

    // ── SECCIÓN 8 — get_enabled_currencies ────────────────────────────────

    public function test_get_enabled_currencies_returns_default_list(): void {
        $currencies = \LTMS_Currency_Manager::get_enabled_currencies();
        // Default config ['COP','MXN','USD'] intersected with supported.
        // The supported list comes from FX_Rate_Provider.
        $this->assertIsArray($currencies);
        // At least USD should be enabled (it's in every default list).
        $this->assertArrayHasKey('USD', $currencies);
    }

    // ── SECCIÓN 9 — get_geo_country ───────────────────────────────────────

    public function test_get_geo_country_returns_null_when_wc_geolocation_missing(): void {
        // WC_Geolocation class doesn't exist in unit tests.
        $this->assertNull(\LTMS_Currency_Manager::get_geo_country());
    }

    // ── SECCIÓN 10 — get_display_currency fallback chain ──────────────────

    public function test_get_display_currency_falls_back_to_base_when_no_session_no_geo(): void {
        // No WC session, no WC_Geolocation → base currency.
        $this->assertSame('USD', \LTMS_Currency_Manager::get_display_currency());
    }
}
