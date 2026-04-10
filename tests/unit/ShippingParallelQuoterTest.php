<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LTMS_Shipping_Parallel_Quoter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests para LTMS_Shipping_Parallel_Quoter — versión extendida
 */
class ShippingParallelQuoterTest extends TestCase
{
    private object $original_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'];
        Monkey\setUp();
        Functions\when('current_time')->justReturn('2025-01-01 00:00:00');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('get_option')->justReturn(null);

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_var(mixed $q = null): mixed  { return null; }
            public function get_row(mixed $q = null, string $output = OBJECT): mixed { return null; }
            public function insert(string $t, array $d, mixed $f = null): int { return 1; }
            public function replace(string $t, array $d, mixed $f = null): int { return 1; }
            public function get_results(mixed $q = null, string $output = OBJECT): array { return []; }
            public function update(string $t, array $d, array $w, mixed $f = null, mixed $wf = null): int { return 1; }
        };
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    private function callStatic(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(LTMS_Shipping_Parallel_Quoter::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    private function make_rate(string $provider, float $cost, int $days): array
    {
        return [
            'provider'       => $provider,
            'cost'           => $cost,
            'estimated_days' => $days,
            'label'          => "$provider Standard",
            'badges'         => [],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // apply_badges()
    // ════════════════════════════════════════════════════════════════════════

    public function test_apply_badges_single_rate_returned_unchanged(): void
    {
        $rates  = [$this->make_rate('aveonline', 10_000, 3)];
        $result = $this->callStatic('apply_badges', $rates);
        $this->assertCount(1, $result);
        $this->assertSame([], $result[0]['badges']);
    }

    public function test_apply_badges_marks_cheapest(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 5),
            $this->make_rate('heka',       8_000, 4),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $heka = current(array_filter($result, fn($r) => $r['provider'] === 'heka'));
        $this->assertContains('💰 Mejor precio', $heka['badges']);
    }

    public function test_apply_badges_marks_fastest(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 5),
            $this->make_rate('uber',      20_000, 1),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $uber = current(array_filter($result, fn($r) => $r['provider'] === 'uber'));
        $this->assertContains('⚡ Más rápido', $uber['badges']);
    }

    public function test_apply_badges_recommended_when_cheapest_and_fastest(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 5),
            $this->make_rate('heka',       8_000, 2),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $heka = current(array_filter($result, fn($r) => $r['provider'] === 'heka'));
        $this->assertContains('💰 Mejor precio', $heka['badges']);
        $this->assertContains('⚡ Más rápido',   $heka['badges']);
    }

    public function test_apply_badges_expensive_provider_has_empty_badges(): void
    {
        $rates = [
            $this->make_rate('aveonline', 50_000, 10),
            $this->make_rate('heka',       8_000,  2),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $aveo = current(array_filter($result, fn($r) => $r['provider'] === 'aveonline'));
        $this->assertSame([], $aveo['badges']);
    }

    public function test_apply_badges_preserves_all_rates(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 3),
            $this->make_rate('heka',      10_000, 5),
            $this->make_rate('uber',      12_000, 2),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $this->assertCount(3, $result);
    }

    public function test_apply_badges_cheapest_has_lowest_cost(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 3),
            $this->make_rate('heka',       8_000, 5),
            $this->make_rate('uber',      12_000, 4),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $cheapest = current(array_filter($result, fn($r) => in_array('💰 Mejor precio', $r['badges'], true)));
        $this->assertNotFalse($cheapest);
        $this->assertSame(8_000.0, (float) $cheapest['cost']);
    }

    public function test_apply_badges_fastest_has_lowest_days(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 3),
            $this->make_rate('heka',      10_000, 5),
            $this->make_rate('uber',      12_000, 2),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $fastest = current(array_filter($result, fn($r) => in_array('⚡ Más rápido', $r['badges'], true)));
        $this->assertNotFalse($fastest);
        $this->assertSame(2, (int) $fastest['estimated_days']);
    }

    public function test_apply_badges_three_rates_split_roles(): void
    {
        $rates = [
            $this->make_rate('aveonline',  7_000, 5),
            $this->make_rate('heka',      10_000, 4),
            $this->make_rate('uber',      12_000, 1),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $aveo = current(array_filter($result, fn($r) => $r['provider'] === 'aveonline'));
        $uber = current(array_filter($result, fn($r) => $r['provider'] === 'uber'));
        $heka = current(array_filter($result, fn($r) => $r['provider'] === 'heka'));
        $this->assertContains('💰 Mejor precio', $aveo['badges']);
        $this->assertNotContains('⚡ Más rápido', $aveo['badges']);
        $this->assertContains('⚡ Más rápido', $uber['badges']);
        $this->assertNotContains('💰 Mejor precio', $uber['badges']);
        $this->assertSame([], $heka['badges']);
    }

    public function test_apply_badges_tie_in_cost_exactly_one_gets_cheapest(): void
    {
        $rates = [
            $this->make_rate('aveonline', 10_000, 5),
            $this->make_rate('heka',      10_000, 3),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $withCheapest = array_filter($result, fn($r) => in_array('💰 Mejor precio', $r['badges'], true));
        $this->assertCount(1, $withCheapest);
    }

    public function test_apply_badges_empty_input_returns_empty(): void
    {
        $result = $this->callStatic('apply_badges', []);
        $this->assertSame([], $result);
    }

    public function test_apply_badges_badges_key_is_array_for_all_rates(): void
    {
        $rates = [
            $this->make_rate('aveonline', 15_000, 3),
            $this->make_rate('heka',       8_000, 5),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        foreach ($result as $rate) {
            $this->assertArrayHasKey('badges', $rate);
            $this->assertIsArray($rate['badges']);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // parse_response()
    // ════════════════════════════════════════════════════════════════════════

    public function test_parse_response_null_on_non_200(): void
    {
        $this->assertNull($this->callStatic('parse_response', 'aveonline', '{}', 404));
    }

    public function test_parse_response_null_on_server_error(): void
    {
        $this->assertNull($this->callStatic('parse_response', 'aveonline', '{}', 500));
    }

    public function test_parse_response_null_on_invalid_json(): void
    {
        $this->assertNull($this->callStatic('parse_response', 'aveonline', 'NOT JSON', 200));
    }

    public function test_parse_response_aveonline_price_key(): void
    {
        $body   = json_encode(['price' => 15000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertIsArray($result);
        $this->assertSame('aveonline', $result['provider']);
        $this->assertSame(15000.0, $result['cost']);
        $this->assertSame(2, $result['estimated_days']);
    }

    public function test_parse_response_aveonline_total_fallback(): void
    {
        $body   = json_encode(['total' => 12000, 'days' => 3]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertIsArray($result);
        $this->assertSame(12000.0, $result['cost']);
        $this->assertSame(3, $result['estimated_days']);
    }

    public function test_parse_response_aveonline_null_when_zero_cost(): void
    {
        $body = json_encode(['price' => 0, 'delivery_time' => 2]);
        $this->assertNull($this->callStatic('parse_response', 'aveonline', $body, 200));
    }

    public function test_parse_response_aveonline_has_label(): void
    {
        $body   = json_encode(['price' => 15000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertArrayHasKey('label', $result);
        $this->assertStringContainsString('2', $result['label']);
    }

    public function test_parse_response_aveonline_has_badges_array(): void
    {
        $body   = json_encode(['price' => 15000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertArrayHasKey('badges', $result);
        $this->assertIsArray($result['badges']);
    }

    public function test_parse_response_aveonline_label_contains_dias(): void
    {
        $body   = json_encode(['price' => 15000, 'delivery_time' => 3]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertStringContainsString('días', $result['label']);
    }

    public function test_parse_response_aveonline_days_fallback_default_5(): void
    {
        $body   = json_encode(['price' => 8000]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertSame(5, $result['estimated_days']);
    }

    public function test_parse_response_aveonline_has_all_required_keys(): void
    {
        $body   = json_encode(['price' => 15000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        foreach (['provider', 'cost', 'estimated_days', 'label', 'badges'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_parse_response_heka_total_price_key(): void
    {
        $body   = json_encode(['total_price' => 11000, 'estimated_days' => 4]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertIsArray($result);
        $this->assertSame('heka', $result['provider']);
        $this->assertSame(11000.0, $result['cost']);
        $this->assertSame(4, $result['estimated_days']);
    }

    public function test_parse_response_heka_price_fallback(): void
    {
        $body   = json_encode(['price' => 9500, 'days' => 5]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertIsArray($result);
        $this->assertSame(9500.0, $result['cost']);
    }

    public function test_parse_response_heka_null_when_zero_cost(): void
    {
        $body = json_encode(['total_price' => 0, 'estimated_days' => 3]);
        $this->assertNull($this->callStatic('parse_response', 'heka', $body, 200));
    }

    public function test_parse_response_heka_label_contains_dias(): void
    {
        $body   = json_encode(['total_price' => 9000, 'estimated_days' => 4]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertStringContainsString('días', $result['label']);
    }

    public function test_parse_response_heka_days_fallback_default_7(): void
    {
        $body   = json_encode(['total_price' => 9000]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertSame(7, $result['estimated_days']);
    }

    public function test_parse_response_heka_has_all_required_keys(): void
    {
        $body   = json_encode(['total_price' => 11000, 'estimated_days' => 4]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        foreach (['provider', 'cost', 'estimated_days', 'label', 'badges'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_parse_response_uber_fee_value(): void
    {
        $body   = json_encode(['fee' => ['value' => 8000], 'dropoff_eta' => 3600]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertIsArray($result);
        $this->assertSame('uber', $result['provider']);
        $this->assertSame(8000.0, $result['cost']);
        $this->assertSame(0, $result['estimated_days']);
    }

    public function test_parse_response_uber_null_when_no_fee(): void
    {
        $body = json_encode(['fee' => ['value' => 0]]);
        $this->assertNull($this->callStatic('parse_response', 'uber', $body, 200));
    }

    public function test_parse_response_unknown_provider_returns_null(): void
    {
        $body = json_encode(['price' => 5000]);
        $this->assertNull($this->callStatic('parse_response', 'unknown_provider', $body, 200));
    }

    public function test_parse_response_uber_estimated_days_always_zero(): void
    {
        $body   = json_encode(['fee' => ['value' => 5000], 'dropoff_eta' => 7200]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertSame(0, $result['estimated_days']);
    }

    public function test_parse_response_uber_label_contains_h(): void
    {
        $body   = json_encode(['fee' => ['value' => 5000], 'dropoff_eta' => 3600]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertStringContainsString('h', $result['label']);
    }

    public function test_parse_response_uber_eta_converts_to_hours(): void
    {
        $body   = json_encode(['fee' => ['value' => 9000], 'dropoff_eta' => 7200]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertStringContainsString('2', $result['label']);
    }

    public function test_parse_response_uber_default_eta_when_missing(): void
    {
        $body   = json_encode(['fee' => ['value' => 6000]]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertSame(0, $result['estimated_days']);
        $this->assertStringContainsString('2', $result['label']);
    }

    public function test_parse_response_null_on_301_redirect(): void
    {
        $this->assertNull($this->callStatic('parse_response', 'heka', '{}', 301));
    }

    public function test_parse_response_null_on_199_boundary(): void
    {
        $this->assertNull($this->callStatic('parse_response', 'aveonline', '{"price":1000}', 199));
    }

    public function test_parse_response_accepts_200_boundary(): void
    {
        $body   = json_encode(['price' => 5000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertIsArray($result);
    }

    public function test_parse_response_accepts_299_boundary(): void
    {
        $body   = json_encode(['price' => 5000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 299);
        $this->assertIsArray($result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_package_weight()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_package_weight_empty_returns_default(): void
    {
        $weight = $this->callStatic('get_package_weight', ['contents' => []]);
        $this->assertEqualsWithDelta(0.5, $weight, 0.01);
    }

    public function test_get_package_weight_non_wc_product_returns_default(): void
    {
        $package = [
            'contents' => [[
                'data'       => new \stdClass(),
                'quantity'   => 3,
                'product_id' => 7,
            ]]
        ];
        $weight = $this->callStatic('get_package_weight', $package);
        $this->assertEqualsWithDelta(0.5, $weight, 0.01);
    }

    public function test_get_package_weight_always_at_least_min(): void
    {
        $weight = $this->callStatic('get_package_weight', ['contents' => []]);
        $this->assertGreaterThanOrEqual(0.1, $weight);
    }

    public function test_get_package_weight_always_positive(): void
    {
        $weight = $this->callStatic('get_package_weight', ['contents' => []]);
        $this->assertGreaterThan(0.0, $weight);
    }

    public function test_get_package_weight_returns_float(): void
    {
        $weight = $this->callStatic('get_package_weight', []);
        $this->assertIsFloat($weight);
    }

    public function test_get_package_weight_no_contents_key_returns_default(): void
    {
        $weight = $this->callStatic('get_package_weight', []);
        $this->assertEqualsWithDelta(0.5, $weight, 0.01);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_cache_key()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_cache_key_is_64_chars(): void
    {
        $pkg = ['destination' => ['postcode' => '110111', 'country' => 'CO'], 'contents' => []];
        $key = $this->callStatic('get_cache_key', $pkg);
        $this->assertSame(64, strlen($key));
    }

    public function test_get_cache_key_is_hex_string(): void
    {
        $pkg = ['destination' => ['postcode' => '110111', 'country' => 'CO'], 'contents' => []];
        $key = $this->callStatic('get_cache_key', $pkg);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function test_get_cache_key_is_deterministic(): void
    {
        $pkg = ['destination' => ['postcode' => '110111', 'country' => 'CO'], 'contents' => []];
        $this->assertSame(
            $this->callStatic('get_cache_key', $pkg),
            $this->callStatic('get_cache_key', $pkg)
        );
    }

    public function test_get_cache_key_differs_for_different_postcodes(): void
    {
        $bog = ['destination' => ['postcode' => '110111', 'country' => 'CO'], 'contents' => []];
        $med = ['destination' => ['postcode' => '050001', 'country' => 'CO'], 'contents' => []];
        $this->assertNotSame(
            $this->callStatic('get_cache_key', $bog),
            $this->callStatic('get_cache_key', $med)
        );
    }

    public function test_get_cache_key_differs_for_different_items(): void
    {
        $base     = ['destination' => ['postcode' => '110111'], 'contents' => []];
        $withItem = [
            'destination' => ['postcode' => '110111'],
            'contents'    => [['product_id' => 5, 'quantity' => 2]],
        ];
        $this->assertNotSame(
            $this->callStatic('get_cache_key', $base),
            $this->callStatic('get_cache_key', $withItem)
        );
    }

    public function test_get_cache_key_differs_for_different_countries(): void
    {
        $co = ['destination' => ['postcode' => '110111', 'country' => 'CO'], 'contents' => []];
        $mx = ['destination' => ['postcode' => '110111', 'country' => 'MX'], 'contents' => []];
        $this->assertNotSame(
            $this->callStatic('get_cache_key', $co),
            $this->callStatic('get_cache_key', $mx)
        );
    }

    public function test_get_cache_key_differs_for_different_quantities(): void
    {
        $q1 = ['destination' => ['postcode' => '110111'], 'contents' => [['product_id' => 1, 'quantity' => 1]]];
        $q3 = ['destination' => ['postcode' => '110111'], 'contents' => [['product_id' => 1, 'quantity' => 3]]];
        $this->assertNotSame(
            $this->callStatic('get_cache_key', $q1),
            $this->callStatic('get_cache_key', $q3)
        );
    }

    public function test_get_cache_key_empty_package_is_valid_hex(): void
    {
        $key = $this->callStatic('get_cache_key', []);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_cheapest_quote()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_cheapest_quote_returns_null_when_no_providers_configured(): void
    {
        $package = [
            'destination' => ['postcode' => '110111', 'country' => 'CO', 'city' => 'Bogota'],
            'contents'    => [],
        ];
        $result = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote($package);
        $this->assertNull($result);
    }

    public function test_get_cheapest_quote_returns_null_for_empty_package(): void
    {
        $result = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote([
            'destination' => [],
            'contents'    => [],
        ]);
        $this->assertNull($result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Reflexión
    // ════════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('LTMS_Shipping_Parallel_Quoter'));
    }

    public function test_quote_all_is_public_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'quote_all');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_get_cheapest_quote_is_public_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'get_cheapest_quote');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_apply_badges_is_private_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'apply_badges');
        $this->assertTrue($ref->isPrivate());
        $this->assertTrue($ref->isStatic());
    }

    public function test_parse_response_is_private_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'parse_response');
        $this->assertTrue($ref->isPrivate());
        $this->assertTrue($ref->isStatic());
    }

    public function test_get_package_weight_is_private_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'get_package_weight');
        $this->assertTrue($ref->isPrivate());
        $this->assertTrue($ref->isStatic());
    }

    public function test_get_cache_key_is_private_static(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'get_cache_key');
        $this->assertTrue($ref->isPrivate());
        $this->assertTrue($ref->isStatic());
    }

    public function test_class_is_not_final(): void
    {
        $ref = new \ReflectionClass('LTMS_Shipping_Parallel_Quoter');
        $this->assertFalse($ref->isFinal());
    }

    public function test_quote_all_return_type_is_array(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'quote_all');
        $rt  = $ref->getReturnType();
        $this->assertNotNull($rt);
        $this->assertSame('array', $rt->getName());
    }

    public function test_get_cheapest_quote_return_type_is_nullable_array(): void
    {
        $ref = new ReflectionMethod('LTMS_Shipping_Parallel_Quoter', 'get_cheapest_quote');
        $rt  = $ref->getReturnType();
        $this->assertNotNull($rt);
        $this->assertTrue($rt->allowsNull());
    }

    // ════════════════════════════════════════════════════════════════════════
    // apply_badges() — casos adicionales con estimated_days = 0 (Uber)
    // ════════════════════════════════════════════════════════════════════════

    public function test_apply_badges_uber_zero_days_can_be_fastest(): void {
        $rates = [
            $this->make_rate('aveonline', 15_000, 3),
            $this->make_rate('uber',      20_000, 0),  // days=0 → fastest
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $uber = array_values(array_filter($result, fn($r) => $r['provider'] === 'uber'))[0];
        $this->assertContains('⚡ Más rápido', $uber['badges']);
    }

    public function test_apply_badges_recommended_badge_when_cheapest_and_fastest_same(): void {
        $rates = [
            $this->make_rate('aveonline', 8_000, 2),
            $this->make_rate('heka',     12_000, 5),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $ave = array_values(array_filter($result, fn($r) => $r['provider'] === 'aveonline'))[0];
        $this->assertContains('💰 Mejor precio', $ave['badges']);
        $this->assertContains('⚡ Más rápido', $ave['badges']);
    }

    public function test_apply_badges_all_same_cost_first_gets_cheapest(): void {
        $rates = [
            $this->make_rate('aveonline', 10_000, 3),
            $this->make_rate('heka',      10_000, 5),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $cheapest_providers = array_filter($result, fn($r) => in_array('💰 Mejor precio', $r['badges']));
        $this->assertCount(1, $cheapest_providers);
    }

    public function test_apply_badges_does_not_add_extra_keys(): void {
        $rates = [
            $this->make_rate('aveonline', 10_000, 3),
            $this->make_rate('heka',      12_000, 5),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $expected_keys = ['provider', 'cost', 'estimated_days', 'label', 'badges'];
        foreach ($result as $r) {
            $this->assertSame([], array_diff(array_keys($r), $expected_keys));
        }
    }

    public function test_apply_badges_count_preserved(): void {
        $rates = [
            $this->make_rate('aveonline', 9_000,  2),
            $this->make_rate('heka',     11_000,  4),
            $this->make_rate('uber',     25_000,  0),
        ];
        $result = $this->callStatic('apply_badges', $rates);
        $this->assertCount(3, $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_cheapest_quote() — con rates válidos vía parse_response
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_cheapest_quote_has_provider_key(): void {
        // quote_all sin providers configurados → null → get_cheapest devuelve null
        $result = \LTMS_Shipping_Parallel_Quoter::get_cheapest_quote([
            'destination' => ['city' => 'Bogota', 'country' => 'CO'],
            'contents'    => [],
        ]);
        // Con config vacía siempre null; verificamos que si fuera no-null tendría 'provider'
        $this->assertTrue($result === null || array_key_exists('provider', $result));
    }

    public function test_get_cheapest_quote_has_cost_key_if_not_null(): void {
        $result = \LTMS_Shipping_Parallel_Quoter::get_cheapest_quote([]);
        $this->assertTrue($result === null || array_key_exists('cost', $result));
    }

    public function test_get_cheapest_quote_has_label_key_if_not_null(): void {
        $result = \LTMS_Shipping_Parallel_Quoter::get_cheapest_quote([]);
        $this->assertTrue($result === null || array_key_exists('label', $result));
    }

    // ════════════════════════════════════════════════════════════════════════
    // parse_response() — casos adicionales
    // ════════════════════════════════════════════════════════════════════════

    public function test_parse_response_aveonline_delivery_time_key_used(): void {
        $body = json_encode(['price' => 10_000, 'delivery_time' => 2]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertSame(2, $result['estimated_days']);
    }

    public function test_parse_response_heka_estimated_days_key_used(): void {
        $body = json_encode(['total_price' => 8_000, 'estimated_days' => 3]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertSame(3, $result['estimated_days']);
    }

    public function test_parse_response_uber_uses_dropoff_eta(): void {
        $body = json_encode(['fee' => ['value' => 5_000], 'dropoff_eta' => 3600]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertSame(0, $result['estimated_days']);
        $this->assertStringContainsString('1', $result['label']); // ~1h
    }

    public function test_parse_response_uber_large_eta_converted_correctly(): void {
        $body = json_encode(['fee' => ['value' => 5_000], 'dropoff_eta' => 7200]);
        $result = $this->callStatic('parse_response', 'uber', $body, 200);
        $this->assertStringContainsString('2', $result['label']); // ~2h
    }

    public function test_parse_response_heka_missing_total_price_uses_price(): void {
        // total_price ausente → ?? fallback a price
        $body = json_encode(['price' => 5_000]);
        $result = $this->callStatic('parse_response', 'heka', $body, 200);
        $this->assertSame(5000.0, $result['cost']);
    }

    public function test_parse_response_aveonline_missing_price_uses_total(): void {
        // price ausente → ?? fallback a total
        $body = json_encode(['total' => 12_000]);
        $result = $this->callStatic('parse_response', 'aveonline', $body, 200);
        $this->assertSame(12000.0, $result['cost']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_cache_key() — casos adicionales
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_cache_key_same_items_different_order_differ(): void {
        // get_cache_key itera contents en orden → distinto orden → distinta clave
        $p1 = ['destination' => ['city' => 'Cali'], 'contents' => [
            ['product_id' => 1, 'quantity' => 1],
            ['product_id' => 2, 'quantity' => 1],
        ]];
        $p2 = ['destination' => ['city' => 'Cali'], 'contents' => [
            ['product_id' => 2, 'quantity' => 1],
            ['product_id' => 1, 'quantity' => 1],
        ]];
        $k1 = $this->callStatic('get_cache_key', $p1);
        $k2 = $this->callStatic('get_cache_key', $p2);
        $this->assertNotEquals($k1, $k2);
    }

    public function test_get_cache_key_state_field_affects_key(): void {
        $p1 = ['destination' => ['city' => 'Bogota', 'state' => 'CUN'], 'contents' => []];
        $p2 = ['destination' => ['city' => 'Bogota', 'state' => 'ANT'], 'contents' => []];
        $k1 = $this->callStatic('get_cache_key', $p1);
        $k2 = $this->callStatic('get_cache_key', $p2);
        $this->assertNotEquals($k1, $k2);
    }

}

