<?php
/**
 * FxRateProviderTest — Tests unitarios para LTMS_FX_Rate_Provider
 *
 * Cubre:
 * - get_rate(): same currency → 1.0, ISO validation, manual override priority
 * - get_rate(): cache poisoned defense (zero/negative entries ignored)
 * - get_rate(): reverse rate fallback (1 / opposite direction)
 * - convert(): spread application, decimal rounding per currency
 * - get_supported_currencies(): COP/CLP=0 decimals, others=2
 * - get_manual_override(): textarea string parsing, rate > 0 validation
 * - refresh_rates(): clears transient cache
 * - FX-1/FX-2/FX-3 FIXES: non-positive rate rejection
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_FX_Rate_Provider
 */
class FxRateProviderTest extends LTMS_Unit_Test_Case {

    public array $transients = [];
    public array $wp_remote_responses = [];
    private object $mock_wpdb;

    protected function setUp(): void {
        parent::setUp();

        $this->transients = [];
        $this->wp_remote_responses = [];

        // Save original wpdb to restore in tearDown (prevents mock leaking).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        // Mock get_transient / set_transient.
        $self = $this;
        Functions\when('get_transient')->alias(function($key) use ($self) {
            return $self->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function($key, $value, $ttl) use ($self) {
            $self->transients[$key] = $value;
            return true;
        });

        // Mock wp_remote_get / wp_remote_retrieve_body / is_wp_error.
        Functions\when('wp_remote_get')->alias(function($url, $args = []) use ($self) {
            return $self->wp_remote_responses[$url] ?? new \WP_Error('http_error', 'No mock');
        });
        Functions\when('wp_remote_retrieve_body')->alias(function($response) {
            if (is_array($response) && isset($response['body'])) {
                return $response['body'];
            }
            return '';
        });
        Functions\when('is_wp_error')->alias(function($thing) {
            return $thing instanceof \WP_Error;
        });

        // Mock wpdb for refresh_rates.
        $self2 = $this;
        $this->mock_wpdb = new class($self2) {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function query($sql) { return true; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_FX_Rate_Provider::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private function set_cached_rates(string $base, array $rates): void {
        $this->transients[\LTMS_FX_Rate_Provider::CACHE_KEY . '_' . $base] = $rates;
    }

    private function mock_frankfurter_response(string $base, array $rates): void {
        $url = sprintf(\LTMS_FX_Rate_Provider::PROVIDERS['frankfurter'], $base);
        $this->wp_remote_responses[$url] = ['body' => json_encode(['rates' => $rates])];
    }

    // ── SECCIÓN 1 — Constants ─────────────────────────────────────────────

    public function test_cache_key_constant_is_ltms_fx_rates(): void {
        $this->assertSame('ltms_fx_rates', \LTMS_FX_Rate_Provider::CACHE_KEY);
    }

    public function test_providers_constant_has_three_sources(): void {
        $providers = \LTMS_FX_Rate_Provider::PROVIDERS;
        $this->assertArrayHasKey('frankfurter', $providers);
        $this->assertArrayHasKey('exchangerate', $providers);
        $this->assertArrayHasKey('ecb', $providers);
    }

    // ── SECCIÓN 2 — get_rate same currency ────────────────────────────────

    public function test_get_rate_same_currency_returns_1(): void {
        $this->assertSame(1.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'USD'));
        $this->assertSame(1.0, \LTMS_FX_Rate_Provider::get_rate('COP', 'COP'));
    }

    public function test_get_rate_case_insensitive(): void {
        $this->assertSame(1.0, \LTMS_FX_Rate_Provider::get_rate('usd', 'USD'));
        $this->assertSame(1.0, \LTMS_FX_Rate_Provider::get_rate('usd', 'usd'));
    }

    // ── SECCIÓN 3 — get_rate ISO validation ───────────────────────────────

    public function test_get_rate_rejects_invalid_length(): void {
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('US', 'USD'));
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'USDD'));
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('', 'USD'));
    }

    // ── SECCIÓN 4 — get_rate manual override ──────────────────────────────

    public function test_get_rate_uses_manual_override_array_format(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 4100.0]]);
        $this->assertSame(4100.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    public function test_get_rate_uses_manual_override_textarea_format(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => "USD_COP=4100\nUSD_MXN=17.5\nINVALID=0\nBAD"]);
        $this->assertSame(4100.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
        $this->assertSame(17.5, \LTMS_FX_Rate_Provider::get_rate('USD', 'MXN'));
    }

    public function test_get_rate_ignores_zero_manual_override(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 0]]);
        // Should fall through to cache (empty) → null.
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    public function test_get_rate_ignores_negative_manual_override(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => -100]]);
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    public function test_manual_override_takes_priority_over_cache(): void {
        $this->set_cached_rates('USD', ['COP' => 4000.0]);
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 4100.0]]);
        $this->assertSame(4100.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    // ── SECCIÓN 5 — get_rate cache (FX-1 FIX) ─────────────────────────────

    public function test_get_rate_uses_cached_rates(): void {
        $this->set_cached_rates('USD', ['COP' => 4000.0]);
        $this->assertSame(4000.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    public function test_get_rate_ignores_zero_cached_rate(): void {
        // FX-1 FIX: zero cached rate must not be returned.
        $this->set_cached_rates('USD', ['COP' => 0.0]);
        // Should fall through to fetch (which fails) → null.
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    public function test_get_rate_ignores_negative_cached_rate(): void {
        $this->set_cached_rates('USD', ['COP' => -100.0]);
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    // ── SECCIÓN 6 — get_rate reverse fallback ─────────────────────────────

    public function test_get_rate_uses_reverse_rate_as_last_resort(): void {
        // Cache has COP→USD = 0.00025, but no USD→COP.
        // get_rate('USD','COP') should fall through to reverse: 1/0.00025 = 4000.
        $this->set_cached_rates('COP', ['USD' => 0.00025]);
        $this->assertEqualsWithDelta(4000.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'), 0.001);
    }

    public function test_get_rate_returns_null_when_all_sources_fail(): void {
        // No cache, no override, no live fetch (mock returns error).
        $this->assertNull(\LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
    }

    // ── SECCIÓN 7 — get_rate live fetch (Frankfurter) ─────────────────────

    public function test_get_rate_fetches_from_frankfurter_on_cache_miss(): void {
        $this->mock_frankfurter_response('USD', ['COP' => 4100.0, 'MXN' => 17.5]);
        $this->assertSame(4100.0, \LTMS_FX_Rate_Provider::get_rate('USD', 'COP'));
        $this->assertSame(17.5, \LTMS_FX_Rate_Provider::get_rate('USD', 'MXN'));
    }

    public function test_get_rate_caches_fetched_rates(): void {
        $this->mock_frankfurter_response('USD', ['COP' => 4100.0]);
        \LTMS_FX_Rate_Provider::get_rate('USD', 'COP');
        $cached = $this->transients['ltms_fx_rates_USD'] ?? null;
        $this->assertIsArray($cached, 'Rates must be cached after fetch');
        $this->assertArrayHasKey('COP', $cached);
    }

    // ── SECCIÓN 8 — FX-2 FIX: filter non-positive rates before caching ────

    public function test_fetch_from_frankfurter_filters_zero_rates(): void {
        $this->mock_frankfurter_response('USD', ['COP' => 0.0, 'MXN' => 17.5]);
        $rates = self::callPrivate('fetch_from_frankfurter', 'USD');
        $this->assertIsArray($rates);
        $this->assertArrayNotHasKey('COP', $rates, 'Zero rate must be filtered (FX-2 FIX)');
        $this->assertArrayHasKey('MXN', $rates);
    }

    public function test_fetch_from_frankfurter_filters_negative_rates(): void {
        $this->mock_frankfurter_response('USD', ['COP' => -100.0, 'MXN' => 17.5]);
        $rates = self::callPrivate('fetch_from_frankfurter', 'USD');
        $this->assertArrayNotHasKey('COP', $rates, 'Negative rate must be filtered (FX-2 FIX)');
    }

    public function test_fetch_from_frankfurter_returns_null_when_only_base(): void {
        $this->mock_frankfurter_response('USD', []);
        $rates = self::callPrivate('fetch_from_frankfurter', 'USD');
        $this->assertNull($rates, 'Must return null when no rates returned');
    }

    // ── SECCIÓN 9 — FX-3 FIX: ECB XML validation ──────────────────────────

    public function test_fetch_from_ecb_filters_dot_rates(): void {
        // ECB sometimes publishes '.' for missing rates.
        $xml = '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"><gesmes:subject>Reference rates</gesmes:subject><Cube><Cube time="2026-01-01"><Cube currency="USD" rate="1.08"/><Cube currency="COP" rate="."/><Cube currency="MXN" rate="19.50"/></Cube></Cube></gesmes:Envelope>';
        $url = \LTMS_FX_Rate_Provider::PROVIDERS['ecb'];
        $this->wp_remote_responses[$url] = ['body' => $xml];

        $rates = self::callPrivate('fetch_from_ecb');
        $this->assertIsArray($rates);
        $this->assertArrayHasKey('USD', $rates);
        $this->assertArrayNotHasKey('COP', $rates, "'.' rate must be filtered (FX-3 FIX)");
        $this->assertArrayHasKey('MXN', $rates);
    }

    public function test_fetch_from_ecb_filters_negative_rates(): void {
        $xml = '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"><Cube><Cube time="2026-01-01"><Cube currency="USD" rate="1.08"/><Cube currency="COP" rate="-100"/></Cube></Cube></gesmes:Envelope>';
        $url = \LTMS_FX_Rate_Provider::PROVIDERS['ecb'];
        $this->wp_remote_responses[$url] = ['body' => $xml];

        $rates = self::callPrivate('fetch_from_ecb');
        $this->assertArrayNotHasKey('COP', $rates, 'Negative rate must be filtered (FX-3 FIX)');
    }

    // ── SECCIÓN 10 — convert() with spread ────────────────────────────────

    public function test_convert_same_currency_no_spread(): void {
        $result = \LTMS_FX_Rate_Provider::convert(100.0, 'USD', 'USD');
        $this->assertSame(100.0, $result);
    }

    public function test_convert_applies_spread_markup(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 4000.0]]);
        // Spread 2% → rate becomes 4000 * 1.02 = 4080. Convert 100 USD = 408000 COP.
        $result = \LTMS_FX_Rate_Provider::convert(100.0, 'USD', 'COP', 2.0);
        $this->assertEqualsWithDelta(408000.0, $result, 0.01);
    }

    public function test_convert_zero_spread_uses_mid_market(): void {
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 4000.0]]);
        $result = \LTMS_FX_Rate_Provider::convert(100.0, 'USD', 'COP', 0.0);
        $this->assertEqualsWithDelta(400000.0, $result, 0.01);
    }

    public function test_convert_rounds_to_currency_decimals(): void {
        // COP has 0 decimals → result must be integer.
        $this->mock_options(['ltms_fx_manual_overrides' => ['USD_COP' => 4000.0]]);
        $result = \LTMS_FX_Rate_Provider::convert(99.99, 'USD', 'COP');
        $this->assertSame(399960.0, $result, 'COP must be rounded to 0 decimals');
    }

    public function test_convert_returns_null_when_rate_unavailable(): void {
        $this->assertNull(\LTMS_FX_Rate_Provider::convert(100.0, 'USD', 'XYZ'));
    }

    // ── SECCIÓN 11 — get_supported_currencies ──────────────────────────────

    public function test_get_supported_currencies_includes_cop_and_clp_with_zero_decimals(): void {
        $currencies = \LTMS_FX_Rate_Provider::get_supported_currencies();
        $this->assertArrayHasKey('COP', $currencies);
        $this->assertSame(0, $currencies['COP']['decimals']);
        $this->assertArrayHasKey('CLP', $currencies);
        $this->assertSame(0, $currencies['CLP']['decimals']);
    }

    public function test_get_supported_currencies_includes_usd_with_two_decimals(): void {
        $currencies = \LTMS_FX_Rate_Provider::get_supported_currencies();
        $this->assertSame(2, $currencies['USD']['decimals']);
    }

    public function test_get_supported_currencies_has_symbol_for_each(): void {
        $currencies = \LTMS_FX_Rate_Provider::get_supported_currencies();
        foreach ($currencies as $code => $info) {
            $this->assertArrayHasKey('symbol', $info, "$code must have symbol");
            $this->assertArrayHasKey('name', $info, "$code must have name");
            $this->assertArrayHasKey('country', $info, "$code must have country");
        }
    }

    // ── SECCIÓN 12 — refresh_rates ────────────────────────────────────────

    public function test_refresh_rates_executes_delete_query(): void {
        $queries = [];
        $self = $this;
        $this->mock_wpdb = new class($queries) {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            private $queries;
            public function __construct(&$queries) { $this->queries = &$queries; }
            public function query($sql) { $this->queries[] = $sql; return true; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        // Use a public array via static.
        $captured = [];
        $mock = new class {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            public $queries = [];
            public function query($sql) { $this->queries[] = $sql; return true; }
        };
        $GLOBALS['wpdb'] = $mock;

        \LTMS_FX_Rate_Provider::refresh_rates();
        $this->assertNotEmpty($mock->queries);
        $this->assertStringContainsString('DELETE', $mock->queries[0]);
        $this->assertStringContainsString('_transient_ltms_fx_rates_', $mock->queries[0]);
    }
}
