<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Tax_Engine (instance methods -- pure arithmetic)
 * + LTMS_Tax_Strategy_Colombia + LTMS_Tax_Strategy_Mexico
 *
 * Testeable sin WP/WC/DB:
 *   - calculate_retefuente()        -- base ? tarifa por tipo
 *   - calculate_reteiva()           -- base ? iva_rate ? 0.15
 *   - calculate_reteica()           -- base ? tarifa por CIIU
 *   - calculate_total_retenciones() -- suma + neto_vendor
 *   - register_strategy / flush_strategies -- state management
 *   - unsupported country ? InvalidArgumentException
 *   - Colombia strategy: IVA, retenciones, impoconsumo
 *   - Mexico strategy: IVA, ISR art.113-A, IEPS, retencion IVA PM
 *   - format_breakdown_html -- renderiza tabla con campos > 0
 *   - Reflexion: final, metodos estaticos, tipos, interface
 */
class TaxEngineTest extends TestCase
{
    private \LTMS_Tax_Engine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Monkey\Functions\stubs( [
            '__'            => static fn( $t ) => $t,
            'esc_html'      => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
            'get_option'    => static fn( $k, $d = null ) => $d,
            'update_option' => static fn() => true,
            'apply_filters' => static fn( $tag, $val ) => $val,
        ] );
        \LTMS_Tax_Engine::flush_strategies();
        \LTMS_Core_Config::flush_cache();
        $this->engine = new \LTMS_Tax_Engine();
    }

    protected function tearDown(): void
    {
        \LTMS_Tax_Engine::flush_strategies();
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  calculate_retefuente -- base ? tarifa
    // ------------------------------------------------------------------ //

    public function test_retefuente_honorarios(): void
    {
        $this->assertSame( 110000.0, $this->engine->calculate_retefuente( 1_000_000.0, 'honorarios' ) );
    }

    public function test_retefuente_compras(): void
    {
        $this->assertSame( 25000.0, $this->engine->calculate_retefuente( 1_000_000.0, 'compras' ) );
    }

    public function test_retefuente_servicios(): void
    {
        $this->assertSame( 40000.0, $this->engine->calculate_retefuente( 1_000_000.0, 'servicios' ) );
    }

    public function test_retefuente_unknown_tipo_uses_default_rate(): void
    {
        $this->assertSame( 40000.0, $this->engine->calculate_retefuente( 1_000_000.0, 'desconocido' ) );
    }

    public function test_retefuente_zero_base(): void
    {
        $this->assertSame( 0.0, $this->engine->calculate_retefuente( 0.0, 'honorarios' ) );
    }

    public function test_retefuente_rounds_to_2_decimals(): void
    {
        $this->assertSame( 13.33, $this->engine->calculate_retefuente( 333.33, 'servicios' ) );
    }

    public function test_retefuente_large_cop_amount(): void
    {
        $this->assertSame( 5_500_000.0, $this->engine->calculate_retefuente( 50_000_000.0, 'honorarios' ) );
    }

    // ------------------------------------------------------------------ //
    //  calculate_reteiva -- base ? iva_rate ? 0.15
    // ------------------------------------------------------------------ //

    public function test_reteiva_default_rate(): void
    {
        $this->assertSame( 28500.0, $this->engine->calculate_reteiva( 1_000_000.0 ) );
    }

    public function test_reteiva_custom_rate(): void
    {
        $this->assertSame( 7500.0, $this->engine->calculate_reteiva( 1_000_000.0, 0.05 ) );
    }

    public function test_reteiva_zero_base(): void
    {
        $this->assertSame( 0.0, $this->engine->calculate_reteiva( 0.0 ) );
    }

    public function test_reteiva_rounds_to_2_decimals(): void
    {
        $this->assertSame( 2.85, $this->engine->calculate_reteiva( 100.0 ) );
    }

    public function test_reteiva_zero_iva_rate(): void
    {
        $this->assertSame( 0.0, $this->engine->calculate_reteiva( 1_000_000.0, 0.0 ) );
    }

    // ------------------------------------------------------------------ //
    //  calculate_reteica -- base ? tarifa por CIIU
    // ------------------------------------------------------------------ //

    public function test_reteica_known_ciiu_4100(): void
    {
        $this->assertSame( 6900.0, $this->engine->calculate_reteica( 1_000_000.0, '4100' ) );
    }

    public function test_reteica_unknown_ciiu_uses_default(): void
    {
        $this->assertSame( 4140.0, $this->engine->calculate_reteica( 1_000_000.0, '9999' ) );
    }

    public function test_reteica_zero_base(): void
    {
        $this->assertSame( 0.0, $this->engine->calculate_reteica( 0.0, '4100' ) );
    }

    public function test_reteica_rounds_to_2_decimals(): void
    {
        $this->assertSame( 0.41, $this->engine->calculate_reteica( 100.0, '9999' ) );
    }

    // ------------------------------------------------------------------ //
    //  calculate_total_retenciones
    // ------------------------------------------------------------------ //

    public function test_total_retenciones_sum_invariant(): void
    {
        $result = $this->engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'servicios',
            'ciiu'     => '4100',
            'iva_rate' => 0.19,
        ] );
        $expected_total = round( $result['retefuente'] + $result['reteiva'] + $result['reteica'], 2 );
        $this->assertSame( $expected_total, $result['total'] );
    }

    public function test_total_retenciones_neto_vendor_invariant(): void
    {
        $base   = 1_000_000.0;
        $result = $this->engine->calculate_total_retenciones( [ 'base' => $base, 'tipo' => 'servicios', 'ciiu' => '4100' ] );
        $this->assertSame( round( $base - $result['total'], 2 ), $result['neto_vendor'] );
    }

    public function test_total_retenciones_has_all_keys(): void
    {
        $result = $this->engine->calculate_total_retenciones( [ 'base' => 500_000.0 ] );
        foreach ( [ 'retefuente', 'reteiva', 'reteica', 'total', 'neto_vendor' ] as $key ) {
            $this->assertArrayHasKey( $key, $result );
        }
    }

    public function test_total_retenciones_zero_base(): void
    {
        $result = $this->engine->calculate_total_retenciones( [ 'base' => 0.0 ] );
        $this->assertSame( 0.0, $result['retefuente'] );
        $this->assertSame( 0.0, $result['reteiva'] );
        $this->assertSame( 0.0, $result['reteica'] );
        $this->assertSame( 0.0, $result['total'] );
        $this->assertSame( 0.0, $result['neto_vendor'] );
    }

    public function test_total_retenciones_servicios_4100_concrete_values(): void
    {
        $result = $this->engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'servicios',
            'ciiu'     => '4100',
            'iva_rate' => 0.19,
        ] );
        $this->assertSame( 40000.0,  $result['retefuente'] );
        $this->assertSame( 28500.0,  $result['reteiva'] );
        $this->assertSame( 6900.0,   $result['reteica'] );
        $this->assertSame( 75400.0,  $result['total'] );
        $this->assertSame( 924600.0, $result['neto_vendor'] );
    }

    public function test_total_retenciones_honorarios_concrete_values(): void
    {
        $result = $this->engine->calculate_total_retenciones( [
            'base' => 500_000.0,
            'tipo' => 'honorarios',
            'ciiu' => '0000',
        ] );
        $this->assertSame( 55000.0,  $result['retefuente'] );
        $this->assertSame( 14250.0,  $result['reteiva'] );
        $this->assertSame( 2070.0,   $result['reteica'] );
        $this->assertSame( 71320.0,  $result['total'] );
        $this->assertSame( 428680.0, $result['neto_vendor'] );
    }

    // ------------------------------------------------------------------ //
    //  register_strategy / flush_strategies / unsupported country
    // ------------------------------------------------------------------ //

    public function test_unsupported_country_throws_invalid_argument_exception(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        \LTMS_Tax_Engine::calculate( 100.0, [], [], 'BR' );
    }

    public function test_unsupported_country_message_contains_code(): void
    {
        try {
            \LTMS_Tax_Engine::calculate( 100.0, [], [], 'AR' );
            $this->fail( 'Expected InvalidArgumentException' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'AR', $e->getMessage() );
        }
    }

    public function test_register_strategy_is_called(): void
    {
        $called = false;
        $stub   = new class( $called ) implements \LTMS_Tax_Strategy_Interface {
            public function __construct( private bool &$called ) {}
            public function calculate( float $g, array $o, array $v ): array {
                $this->called = true;
                return [ 'total' => $g ];
            }
            public function get_country_code(): string { return 'XX'; }
            public function should_apply_withholding( array $v ): bool { return false; }
        };
        \LTMS_Tax_Engine::register_strategy( 'XX', $stub );
        \LTMS_Tax_Engine::calculate( 1000.0, [], [], 'XX' );
        $this->assertTrue( $called );
    }

    public function test_flush_strategies_removes_registered_strategy(): void
    {
        $stub = new class implements \LTMS_Tax_Strategy_Interface {
            public function calculate( float $g, array $o, array $v ): array { return []; }
            public function get_country_code(): string { return 'ZZ'; }
            public function should_apply_withholding( array $v ): bool { return false; }
        };
        \LTMS_Tax_Engine::register_strategy( 'ZZ', $stub );
        \LTMS_Tax_Engine::flush_strategies();
        $this->expectException( \InvalidArgumentException::class );
        \LTMS_Tax_Engine::calculate( 100.0, [], [], 'ZZ' );
    }

    public function test_country_code_normalized_to_uppercase(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        \LTMS_Tax_Engine::calculate( 100.0, [], [], 'br' );
    }

    // ------------------------------------------------------------------ //
    //  should_apply_withholding (static facade)
    // ------------------------------------------------------------------ //

    public function test_should_apply_withholding_co_common_is_true(): void
    {
        $this->assertTrue(
            \LTMS_Tax_Engine::should_apply_withholding( [ 'tax_regime' => 'common' ], 'CO' )
        );
    }

    public function test_should_apply_withholding_co_simplified_is_false(): void
    {
        $this->assertFalse(
            \LTMS_Tax_Engine::should_apply_withholding( [ 'tax_regime' => 'simplified' ], 'CO' )
        );
    }

    public function test_should_apply_withholding_co_gran_contribuyente_is_true(): void
    {
        $this->assertTrue(
            \LTMS_Tax_Engine::should_apply_withholding( [ 'tax_regime' => 'gran_contribuyente' ], 'CO' )
        );
    }

    public function test_should_apply_withholding_mx_resico_is_true(): void
    {
        $this->assertTrue(
            \LTMS_Tax_Engine::should_apply_withholding( [ 'tax_regime' => 'resico' ], 'MX' )
        );
    }

    public function test_should_apply_withholding_mx_pm_is_true(): void
    {
        $this->assertTrue(
            \LTMS_Tax_Engine::should_apply_withholding( [ 'tax_regime' => 'pm' ], 'MX' )
        );
    }

    // ------------------------------------------------------------------ //
    //  Colombia strategy -- calculate()
    // ------------------------------------------------------------------ //

    private function co_calculate( float $gross, array $order = [], array $vendor = [] ): array
    {
        $order  = array_merge( [ 'product_type' => 'physical' ], $order );
        $vendor = array_merge( [ 'tax_regime' => 'common', 'municipality_code' => '', 'ciiu_code' => '4' ], $vendor );
        return \LTMS_Tax_Engine::calculate( $gross, $order, $vendor, 'CO' );
    }

    public function test_co_physical_product_has_iva_19(): void
    {
        $result = $this->co_calculate( 1_000_000.0 );
        $this->assertSame( 0.19, $result['iva_rate'] );
        $this->assertSame( 190_000.0, $result['iva'] );
    }

    public function test_co_basic_food_is_iva_exempt(): void
    {
        $result = $this->co_calculate( 500_000.0, [ 'product_type' => 'basic_food' ] );
        $this->assertSame( 0.0, $result['iva_rate'] );
        $this->assertSame( 0.0, $result['iva'] );
    }

    public function test_co_medicine_is_iva_exempt(): void
    {
        $result = $this->co_calculate( 200_000.0, [ 'product_type' => 'medicine' ] );
        $this->assertSame( 0.0, $result['iva'] );
    }

    public function test_co_coffee_has_iva_reducido_5pct(): void
    {
        $result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'coffee' ] );
        $this->assertSame( 0.05, $result['iva_rate'] );
        $this->assertSame( 50_000.0, $result['iva'] );
    }

    public function test_co_eggs_retail_has_iva_reducido(): void
    {
        $result = $this->co_calculate( 100_000.0, [ 'product_type' => 'eggs_retail' ] );
        $this->assertSame( 0.05, $result['iva_rate'] );
    }

    public function test_co_restaurant_has_impoconsumo_8pct(): void
    {
        $result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'restaurant' ] );
        $this->assertSame( 0.08, $result['impoconsumo_rate'] );
        $this->assertSame( 80_000.0, $result['impoconsumo'] );
    }

    public function test_co_bar_has_impoconsumo(): void
    {
        $result = $this->co_calculate( 500_000.0, [ 'product_type' => 'bar' ] );
        $this->assertSame( 40_000.0, $result['impoconsumo'] );
    }

    public function test_co_physical_product_has_no_impoconsumo(): void
    {
        $result = $this->co_calculate( 1_000_000.0 );
        $this->assertSame( 0.0, $result['impoconsumo'] );
    }

    public function test_co_simplified_regime_no_retefuente(): void
    {
        $result = $this->co_calculate( 5_000_000.0, [], [ 'tax_regime' => 'simplified' ] );
        $this->assertSame( 0.0, $result['retefuente'] );
        $this->assertSame( 0.0, $result['retefuente_rate'] );
    }

    public function test_co_common_regime_applies_retefuente_above_min(): void
    {
        // UVT default 49799, minimo compras = 10.666 UVT ? 531k COP ? 1M pasa el umbral
        $result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
        $this->assertGreaterThan( 0.0, $result['retefuente'] );
    }

    public function test_co_common_regime_no_retefuente_below_min_compras(): void
    {
        // 100 COP << minimo compras ? 0%
        $result = $this->co_calculate( 100.0, [ 'product_type' => 'physical' ], [ 'tax_regime' => 'common' ] );
        $this->assertSame( 0.0, $result['retefuente'] );
    }

    public function test_co_gran_contribuyente_buyer_activates_reteiva(): void
    {
        $result = $this->co_calculate(
            1_000_000.0,
            [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => true ],
            [ 'tax_regime' => 'common' ]
        );
        $this->assertGreaterThan( 0.0, $result['reteiva'] );
        $this->assertSame( 0.15, $result['reteiva_rate'] );
    }

    public function test_co_no_gran_contribuyente_buyer_no_reteiva(): void
    {
        $result = $this->co_calculate(
            1_000_000.0,
            [ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => false ]
        );
        $this->assertSame( 0.0, $result['reteiva'] );
    }

    public function test_co_ciiu_prefix_5_reteica_rate_is_higher(): void
    {
        // CIIU prefix '5' ? 0.00966 ? 1_000_000 ? 0.00966 = 9_660
        $result = $this->co_calculate( 1_000_000.0, [], [ 'tax_regime' => 'common', 'ciiu_code' => '5000' ] );
        $this->assertSame( 9_660.0, $result['reteica'] );
    }

    public function test_co_ciiu_prefix_9_reteica_rate(): void
    {
        // CIIU prefix '9' ? 0.00690 ? 1_000_000 ? 0.00690 = 6_900
        $result = $this->co_calculate( 1_000_000.0, [], [ 'tax_regime' => 'common', 'ciiu_code' => '9000' ] );
        $this->assertSame( 6_900.0, $result['reteica'] );
    }

    public function test_co_net_to_vendor_invariant(): void
    {
        $gross  = 2_000_000.0;
        $result = $this->co_calculate( $gross );
        $this->assertSame(
            round( $gross - $result['total_withholding'], 2 ),
            $result['net_to_vendor']
        );
    }

    public function test_co_strategy_and_currency_fields(): void
    {
        $result = $this->co_calculate( 1_000_000.0 );
        $this->assertSame( 'CO', $result['country'] );
        $this->assertSame( 'COP', $result['currency'] );
        $this->assertSame( 'LTMS_Tax_Strategy_Colombia', $result['strategy'] );
    }

    public function test_co_result_has_gross_key(): void
    {
        $result = $this->co_calculate( 750_000.0 );
        $this->assertSame( 750_000.0, $result['gross'] );
    }

    public function test_co_consulting_retefuente_honorarios_rate(): void
    {
        // consulting + importe grande ? tarifa honorarios 11%
        $result = $this->co_calculate(
            2_000_000.0,
            [ 'product_type' => 'consulting' ],
            [ 'tax_regime' => 'common' ]
        );
        $this->assertSame( 0.11, $result['retefuente_rate'] );
        $this->assertSame( 220_000.0, $result['retefuente'] );
    }

    public function test_co_isr_and_ieps_are_zero(): void
    {
        $result = $this->co_calculate( 1_000_000.0 );
        $this->assertSame( 0.0, $result['isr'] );
        $this->assertSame( 0.0, $result['ieps'] );
    }

    // ------------------------------------------------------------------ //
    //  Mexico strategy -- calculate()
    // ------------------------------------------------------------------ //

    private function mx_calculate( float $gross, array $order = [], array $vendor = [] ): array
    {
        $order  = array_merge( [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ], $order );
        $vendor = array_merge( [ 'tax_regime' => 'resico', 'monthly_income' => 20_000.0, 'is_border_north_zone' => false ], $vendor );
        return \LTMS_Tax_Engine::calculate( $gross, $order, $vendor, 'MX' );
    }

    public function test_mx_physical_product_has_iva_16(): void
    {
        $result = $this->mx_calculate( 1_000_000.0 );
        $this->assertSame( 0.16, $result['iva_rate'] );
        $this->assertSame( 160_000.0, $result['iva'] );
    }

    public function test_mx_basic_food_is_iva_exempt(): void
    {
        $result = $this->mx_calculate( 500_000.0, [ 'product_type' => 'basic_food' ] );
        $this->assertSame( 0.0, $result['iva_rate'] );
        $this->assertSame( 0.0, $result['iva'] );
    }

    public function test_mx_tortillas_are_iva_exempt(): void
    {
        $result = $this->mx_calculate( 100_000.0, [ 'product_type' => 'tortillas' ] );
        $this->assertSame( 0.0, $result['iva'] );
    }

    public function test_mx_border_zone_has_iva_8pct(): void
    {
        $result = $this->mx_calculate( 1_000_000.0, [], [ 'tax_regime' => 'resico', 'monthly_income' => 20_000.0, 'is_border_north_zone' => true ] );
        $this->assertSame( 0.08, $result['iva_rate'] );
        $this->assertSame( 80_000.0, $result['iva'] );
    }

    public function test_mx_border_exempt_product_still_zero_iva(): void
    {
        $result = $this->mx_calculate(
            200_000.0,
            [ 'product_type' => 'basic_food' ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 20_000.0, 'is_border_north_zone' => true ]
        );
        $this->assertSame( 0.0, $result['iva'] );
    }

    public function test_mx_retencion_iva_pm_is_applied_when_platform_pm(): void
    {
        // 1_000_000 ? 0.1067 = 106_700
        $result = $this->mx_calculate( 1_000_000.0, [ 'platform_is_persona_moral' => true ] );
        $this->assertSame( 0.1067, $result['reteiva_rate'] );
        $this->assertSame( 106_700.0, $result['reteiva'] );
    }

    public function test_mx_retencion_iva_not_applied_when_not_pm(): void
    {
        $result = $this->mx_calculate( 1_000_000.0, [ 'platform_is_persona_moral' => false ] );
        $this->assertSame( 0.0, $result['reteiva'] );
    }

    public function test_mx_retencion_iva_not_applied_when_iva_is_zero(): void
    {
        $result = $this->mx_calculate( 500_000.0, [ 'product_type' => 'basic_food', 'platform_is_persona_moral' => true ] );
        $this->assertSame( 0.0, $result['reteiva'] );
    }

    public function test_mx_isr_resico_below_25k_rate(): void
    {
        // monthly_income = 20_000 ? resico tramo 1 ? 1.25%
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'resico', 'monthly_income' => 20_000.0 ] );
        $this->assertSame( 0.0125, $result['isr_rate'] );
        $this->assertSame( 1_250.0, $result['isr'] );
    }

    public function test_mx_isr_resico_between_25k_and_50k(): void
    {
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'resico', 'monthly_income' => 40_000.0 ] );
        $this->assertSame( 0.015, $result['isr_rate'] );
    }

    public function test_mx_isr_resico_above_166k(): void
    {
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'resico', 'monthly_income' => 200_000.0 ] );
        $this->assertSame( 0.03, $result['isr_rate'] );
    }

    public function test_mx_isr_pf_honorarios_rate_10pct(): void
    {
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'pf_honorarios', 'monthly_income' => 50_000.0 ] );
        $this->assertSame( 0.10, $result['isr_rate'] );
        $this->assertSame( 10_000.0, $result['isr'] );
    }

    public function test_mx_isr_arrendamiento_same_as_honorarios(): void
    {
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'arrendamiento', 'monthly_income' => 50_000.0 ] );
        $this->assertSame( 0.10, $result['isr_rate'] );
    }

    public function test_mx_isr_pm_regime_no_isr(): void
    {
        $result = $this->mx_calculate( 1_000_000.0, [], [ 'tax_regime' => 'pm', 'monthly_income' => 0.0 ] );
        $this->assertSame( 0.0, $result['isr'] );
    }

    public function test_mx_ieps_sugary_drinks_8pct(): void
    {
        $result = $this->mx_calculate( 1_000_000.0, [ 'product_type' => 'sugary_drinks' ] );
        $this->assertSame( 0.08, $result['ieps_rate'] );
        $this->assertSame( 80_000.0, $result['ieps'] );
    }

    public function test_mx_ieps_tobacco_160pct(): void
    {
        $result = $this->mx_calculate( 100_000.0, [ 'product_type' => 'tobacco' ] );
        $this->assertSame( 1.60, $result['ieps_rate'] );
        $this->assertSame( 160_000.0, $result['ieps'] );
    }

    public function test_mx_ieps_beer_26pct(): void
    {
        $result = $this->mx_calculate( 1_000_000.0, [ 'product_type' => 'beer' ] );
        $this->assertSame( 0.26, $result['ieps_rate'] );
        $this->assertSame( 260_000.0, $result['ieps'] );
    }

    public function test_mx_ieps_unknown_product_is_zero(): void
    {
        $result = $this->mx_calculate( 500_000.0, [ 'product_type' => 'physical' ] );
        $this->assertSame( 0.0, $result['ieps_rate'] );
        $this->assertSame( 0.0, $result['ieps'] );
    }

    public function test_mx_cfdi_required_above_2000(): void
    {
        $result = $this->mx_calculate( 2_000.0 );
        $this->assertTrue( $result['cfdi_required'] );
    }

    public function test_mx_cfdi_not_required_below_2000(): void
    {
        $result = $this->mx_calculate( 1_999.0 );
        $this->assertFalse( $result['cfdi_required'] );
    }

    public function test_mx_net_to_vendor_invariant(): void
    {
        $gross  = 1_000_000.0;
        $result = $this->mx_calculate( $gross );
        $this->assertSame(
            round( $gross - $result['total_withholding'], 2 ),
            $result['net_to_vendor']
        );
    }

    public function test_mx_strategy_and_currency_fields(): void
    {
        $result = $this->mx_calculate( 500_000.0 );
        $this->assertSame( 'MX', $result['country'] );
        $this->assertSame( 'MXN', $result['currency'] );
        $this->assertSame( 'LTMS_Tax_Strategy_Mexico', $result['strategy'] );
    }

    public function test_mx_colombia_fields_are_zero_in_mx_result(): void
    {
        $result = $this->mx_calculate( 1_000_000.0 );
        $this->assertSame( 0.0, $result['retefuente'] );
        $this->assertSame( 0.0, $result['reteica'] );
        $this->assertSame( 0.0, $result['impoconsumo'] );
    }

    public function test_mx_pf_actividad_isr_low_income_2pct(): void
    {
        $result = $this->mx_calculate( 100_000.0, [], [ 'tax_regime' => 'pf_actividad', 'monthly_income' => 10_000.0 ] );
        $this->assertSame( 0.02, $result['isr_rate'] );
    }

    public function test_mx_pf_actividad_isr_high_income_17pct(): void
    {
        $result = $this->mx_calculate( 500_000.0, [], [ 'tax_regime' => 'pf_actividad', 'monthly_income' => 400_000.0 ] );
        $this->assertSame( 0.17, $result['isr_rate'] );
    }

    // ------------------------------------------------------------------ //
    //  format_breakdown_html
    // ------------------------------------------------------------------ //

    public function test_format_breakdown_html_contains_table_tag(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [
            'iva'   => 19_000.0,
            'total' => 19_000.0,
        ], 'COP' );
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringContainsString( '</table>', $html );
    }

    public function test_format_breakdown_html_zero_iva_not_rendered(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [
            'iva'        => 0.0,
            'retefuente' => 50_000.0,
            'total'      => 50_000.0,
        ], 'COP' );
        $this->assertStringNotContainsString( 'IVA', $html );
    }

    public function test_format_breakdown_html_returns_empty_when_all_zero(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [
            'iva'   => 0.0,
            'total' => 0.0,
        ], 'COP' );
        $this->assertSame( '', $html );
    }

    public function test_format_breakdown_html_renders_ieps_key(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [
            'ieps'  => 8_000.0,
            'total' => 8_000.0,
        ], 'MXN' );
        $this->assertStringContainsString( 'IEPS', $html );
    }

    public function test_format_breakdown_html_renders_impoconsumo_key(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [
            'impoconsumo' => 8_000.0,
            'total'       => 8_000.0,
        ], 'COP' );
        $this->assertStringContainsString( 'Impoconsumo', $html );
    }

    // ------------------------------------------------------------------ //
    //  Reflexion -- LTMS_Tax_Engine
    // ------------------------------------------------------------------ //

    public function test_reflection_class_is_final(): void
    {
        $rc = new \ReflectionClass( \LTMS_Tax_Engine::class );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_reflection_calculate_is_static_and_public(): void
    {
        $rm = new \ReflectionMethod( \LTMS_Tax_Engine::class, 'calculate' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_reflection_should_apply_withholding_is_static(): void
    {
        $rm = new \ReflectionMethod( \LTMS_Tax_Engine::class, 'should_apply_withholding' );
        $this->assertTrue( $rm->isStatic() );
    }

    public function test_reflection_register_strategy_param_count(): void
    {
        $rm = new \ReflectionMethod( \LTMS_Tax_Engine::class, 'register_strategy' );
        $this->assertCount( 2, $rm->getParameters() );
    }

    public function test_reflection_flush_strategies_return_type_is_void(): void
    {
        $rm = new \ReflectionMethod( \LTMS_Tax_Engine::class, 'flush_strategies' );
        $this->assertSame( 'void', $rm->getReturnType()->getName() );
    }

    public function test_reflection_calculate_retefuente_is_instance_method(): void
    {
        $rm = new \ReflectionMethod( \LTMS_Tax_Engine::class, 'calculate_retefuente' );
        $this->assertFalse( $rm->isStatic() );
        $this->assertTrue( $rm->isPublic() );
    }

    public function test_reflection_colombia_strategy_implements_interface(): void
    {
        $rc = new \ReflectionClass( \LTMS_Tax_Strategy_Colombia::class );
        $this->assertTrue( $rc->implementsInterface( \LTMS_Tax_Strategy_Interface::class ) );
    }

    public function test_reflection_mexico_strategy_implements_interface(): void
    {
        $rc = new \ReflectionClass( \LTMS_Tax_Strategy_Mexico::class );
        $this->assertTrue( $rc->implementsInterface( \LTMS_Tax_Strategy_Interface::class ) );
    }

    public function test_reflection_colombia_strategy_is_final(): void
    {
        $rc = new \ReflectionClass( \LTMS_Tax_Strategy_Colombia::class );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_reflection_mexico_strategy_is_final(): void
    {
        $rc = new \ReflectionClass( \LTMS_Tax_Strategy_Mexico::class );
        $this->assertTrue( $rc->isFinal() );
    }

    // ------------------------------------------------------------------ //
    //  calculate_reteica -- CIIU con tarifa especial 4100 ? 0.69%
    // ------------------------------------------------------------------ //

    public function test_reteica_ciiu_4100_usa_tarifa_especial(): void
    {
        // CIIU '4100' tiene tarifa propia 0.0069 (no default 0.00414)
        $result = $this->engine->calculate_reteica( 1_000_000.0, '4100' );
        $this->assertSame( 6_900.0, $result );
    }

    public function test_reteica_ciiu_desconocido_usa_default(): void
    {
        $result = $this->engine->calculate_reteica( 1_000_000.0, '9999' );
        $this->assertSame( 4_140.0, $result );
    }

    public function test_reteica_ciiu_vacio_usa_default(): void
    {
        $result = $this->engine->calculate_reteica( 1_000_000.0, '' );
        $this->assertSame( 4_140.0, $result );
    }

    public function test_reteica_rounds_to_2_decimals_non_round_base(): void
    {
        // 333.33 × 0.00414 = 1.3799... → 1.38
        $result = $this->engine->calculate_reteica( 333.33, '9999' );
        $this->assertSame( 1.38, $result );
    }

    // ------------------------------------------------------------------ //
    //  calculate_total_retenciones -- composicion y neto_vendor
    // ------------------------------------------------------------------ //

    public function test_total_retenciones_honorarios_con_ciiu_4100(): void
    {
        $result = $this->engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '4100',
            'iva_rate' => 0.19,
        ] );

        // retefuente = 110,000 (11%), reteiva = 28,500 (19%?15%), reteica = 6,900 (0.69%)
        $this->assertSame( 110_000.0, $result['retefuente'] );
        $this->assertSame(  28_500.0, $result['reteiva']    );
        $this->assertSame(   6_900.0, $result['reteica']    );
        $this->assertSame( 145_400.0, $result['total']      );
        $this->assertSame( 854_600.0, $result['neto_vendor'] );
    }

    public function test_total_retenciones_defaults_cuando_params_vacios(): void
    {
        // Sin params ? tipo='servicios', ciiu='4711', iva_rate=0.19, base=0
        $result = $this->engine->calculate_total_retenciones( [] );

        $this->assertSame( 0.0, $result['retefuente']  );
        $this->assertSame( 0.0, $result['reteiva']     );
        $this->assertSame( 0.0, $result['reteica']     );
        $this->assertSame( 0.0, $result['total']       );
        $this->assertSame( 0.0, $result['neto_vendor'] );
    }

    public function test_total_retenciones_neto_vendor_invariante(): void
    {
        $base   = 2_500_000.0;
        $result = $this->engine->calculate_total_retenciones( [
            'base'     => $base,
            'tipo'     => 'compras',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        // neto_vendor = base - total (siempre)
        $expected_neto = round( $base - $result['total'], 2 );
        $this->assertEqualsWithDelta( $expected_neto, $result['neto_vendor'], 0.01 );
    }

    public function test_total_retenciones_tiene_cinco_claves(): void
    {
        $result = $this->engine->calculate_total_retenciones( [ 'base' => 100.0 ] );
        $this->assertArrayHasKey( 'retefuente',  $result );
        $this->assertArrayHasKey( 'reteiva',     $result );
        $this->assertArrayHasKey( 'reteica',     $result );
        $this->assertArrayHasKey( 'total',       $result );
        $this->assertArrayHasKey( 'neto_vendor', $result );
    }

    public function test_total_retenciones_iva_reducido_5pct(): void
    {
        $result = $this->engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'servicios',
            'ciiu'     => '4711',
            'iva_rate' => 0.05,
        ] );

        // reteiva = 1,000,000 ? 0.05 ? 0.15 = 7,500
        $this->assertSame( 7_500.0, $result['reteiva'] );
    }

    // ------------------------------------------------------------------ //
    //  register_strategy -- estrategia personalizada
    // ------------------------------------------------------------------ //

    public function test_register_strategy_custom_reemplaza_builtin(): void
    {
        // Crear estrategia stub anonima
        $stub = new class implements \LTMS_Tax_Strategy_Interface {
            public function calculate( float $g, array $o, array $v ): array {
                return [ 'gross' => $g, 'country' => 'TEST', 'custom' => true ];
            }
            public function should_apply_withholding( array $v ): bool { return false; }
            public function get_country_code(): string { return 'TEST'; }
        };

        \LTMS_Tax_Engine::register_strategy( 'TEST', $stub );

        // Ahora calculate() debe usar el stub
        \LTMS_Core_Config::set( 'ltms_country', 'TEST' );
        $result = \LTMS_Tax_Engine::calculate( 500_000.0, [], [], 'TEST' );

        $this->assertSame( 'TEST', $result['country'] );
        $this->assertTrue( $result['custom'] );
    }

    public function test_register_strategy_minusculas_normalizado(): void
    {
        $stub = new class implements \LTMS_Tax_Strategy_Interface {
            public function calculate( float $g, array $o, array $v ): array {
                return [ 'gross' => $g, 'country' => 'ZZ' ];
            }
            public function should_apply_withholding( array $v ): bool { return false; }
            public function get_country_code(): string { return 'ZZ'; }
        };

        // Registrar con minusculas ? debe normalizarse a mayusculas
        \LTMS_Tax_Engine::register_strategy( 'zz', $stub );
        $result = \LTMS_Tax_Engine::calculate( 100.0, [], [], 'zz' );
        $this->assertSame( 'ZZ', $result['country'] );
    }

    // ------------------------------------------------------------------ //
    //  format_breakdown_html -- multiples campos simultaneos
    // ------------------------------------------------------------------ //

    public function test_format_breakdown_html_multiples_campos_positivos(): void
    {
        \LTMS_Core_Config::flush_cache();
        $breakdown = [
            'iva'          => 190_000.0,
            'impoconsumo'  => 80_000.0,
            'retefuente'   => 110_000.0,
            'reteiva'      => 28_500.0,
            'reteica'      => 4_140.0,
        ];

        $html = \LTMS_Tax_Engine::format_breakdown_html( $breakdown, 'COP' );

        $this->assertStringContainsString( '<table', $html );
        $this->assertStringContainsString( '</table>', $html );
        // Debe haber exactamente 5 filas <tr> para los 5 campos > 0
        $this->assertSame( 5, substr_count( $html, '<tr>' ) );
    }

    public function test_format_breakdown_html_campo_negativo_no_se_renderiza(): void
    {
        $breakdown = [ 'iva' => -1.0, 'retefuente' => 5_000.0 ];
        $html = \LTMS_Tax_Engine::format_breakdown_html( $breakdown, 'COP' );

        // iva < 0 no se renderiza; retefuente > 0 si
        $this->assertSame( 1, substr_count( $html, '<tr>' ) );
    }

    public function test_format_breakdown_html_retorna_string(): void
    {
        $html = \LTMS_Tax_Engine::format_breakdown_html( [ 'iva' => 10.0 ], 'COP' );
        $this->assertIsString( $html );
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 22 — Nuevos ángulos usando co_calculate / mx_calculate
    // ════════════════════════════════════════════════════════════════════

    public function test_co_retefuente_honorarios_pipeline(): void
    {
        // consulting = 11% retefuente
        $result = $this->co_calculate( 500_000.0,
            [ 'product_type' => 'consulting' ],
            [ 'tax_regime' => 'common', 'ciiu_code' => '7490' ]
        );
        $this->assertEqualsWithDelta( 55_000.0, $result['retefuente'], 0.01 );
    }

    public function test_co_retefuente_compras_pipeline(): void
    {
        // physical = 2.5% retefuente
        $result = $this->co_calculate( 1_000_000.0,
            [ 'product_type' => 'physical' ],
            [ 'tax_regime' => 'common', 'ciiu_code' => '4711' ]
        );
        $this->assertEqualsWithDelta( 25_000.0, $result['retefuente'], 0.01 );
    }

    public function test_co_reteica_ciiu_4100_full_pipeline(): void
    {
        $result = $this->co_calculate( 200_000.0,
            [ 'product_type' => 'physical' ],
            [ 'tax_regime' => 'common', 'ciiu_code' => '4100' ]
        );
        $this->assertGreaterThan( 0.0, $result['reteica'] );
    }

    public function test_mx_neto_vendor_invariant_pm(): void
    {
        $result = $this->mx_calculate( 800_000.0,
            [ 'product_type' => 'physical' ],
            [ 'tax_regime' => 'pm' ]
        );
        $neto = $result['net_to_vendor'];
        $this->assertGreaterThanOrEqual( 0.0,       $neto );
        $this->assertLessThanOrEqual(    800_000.0, $neto );
    }

    public function test_co_calculate_returns_float_for_all_keys(): void
    {
        $result = $this->co_calculate( 100_000.0,
            [ 'product_type' => 'physical' ],
            [ 'tax_regime' => 'gran_contribuyente', 'ciiu_code' => '4711' ]
        );
        foreach ( [ 'iva', 'retefuente', 'reteiva', 'reteica', 'net_to_vendor' ] as $key ) {
            $this->assertIsFloat( $result[ $key ],
                "La clave '{$key}' debe ser float" );
        }
    }

    public function test_mx_calculate_returns_float_for_all_keys(): void
    {
        $result = $this->mx_calculate( 100_000.0,
            [ 'product_type' => 'physical' ],
            [ 'tax_regime' => 'resico' ]
        );
        foreach ( [ 'isr', 'net_to_vendor' ] as $key ) {
            $this->assertIsFloat( $result[ $key ],
                "La clave MX '{$key}' debe ser float" );
        }
    }

    public function test_format_breakdown_html_multiples_impuestos_co(): void
    {
        $breakdown = [
            'iva'          => 19_000.0,
            'retefuente'   => 11_000.0,
            'reteiva'      => 2_660.0,
            'reteica'      => 828.0,
            'net_to_vendor'=> 66_512.0,
        ];
        $html = \LTMS_Tax_Engine::format_breakdown_html( $breakdown, 'COP' );
        $this->assertStringContainsString( '<table', $html );
        $this->assertIsString( $html );
    }

    public function test_format_breakdown_html_multiples_impuestos_mx(): void
    {
        $breakdown = [
            'iva'           => 16_000.0,
            'isr'           => 1_000.0,
            'ieps'          => 4_800.0,
            'net_to_vendor' => 78_200.0,
        ];
        $html = \LTMS_Tax_Engine::format_breakdown_html( $breakdown, 'MXN' );
        $this->assertStringContainsString( '<table', $html );
    }

    public function test_co_gross_zero_no_crash(): void
    {
        $result = $this->co_calculate( 0.0 );
        $this->assertEqualsWithDelta( 0.0, $result['iva'],        0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['retefuente'], 0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['reteica'],    0.001 );
    }

    public function test_co_gross_100m_no_overflow(): void
    {
        $result = $this->co_calculate( 100_000_000.0 );
        $this->assertIsFloat( $result['iva'] );
        $this->assertGreaterThan( 0.0,          $result['iva'] );
        $this->assertLessThan(    100_000_000.0, $result['net_to_vendor'] );
    }

    public function test_register_strategy_custom_devuelve_campos_validos(): void
    {
        $custom = new class implements \LTMS_Tax_Strategy_Interface {
            public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array {
                return [
                    'country'      => 'CO',
                    'currency'     => 'COP',
                    'gross'        => $gross_amount,
                    'platform_fee' => 0.0,
                    'iva'          => 999.0,
                    'retefuente'   => 0.0,
                    'reteiva'      => 0.0,
                    'reteica'      => 0.0,
                    'isr'          => 0.0,
                    'iva_mx'       => 0.0,
                    'ieps'         => 0.0,
                    'impoconsumo'  => 0.0,
                    'net_to_vendor'=> $gross_amount - 999.0,
                ];
            }
            public function get_country_code(): string { return 'CO'; }
            public function should_apply_withholding( array $vendor_data ): bool { return true; }
        };

        \LTMS_Tax_Engine::register_strategy( 'CO', $custom );
        $result = $this->co_calculate( 50_000.0 );
        $this->assertEqualsWithDelta( 999.0, $result['iva'], 0.01 );
        \LTMS_Tax_Engine::flush_strategies();
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 23 — DataProvider cross-country net_to_vendor invariant
    // ════════════════════════════════════════════════════════════════════

    /**
     * @dataProvider provider_co_net_vendor_invariant
     */
    public function test_co_net_to_vendor_invariant_cross_cases(
        float  $gross,
        string $product_type,
        string $regime,
        string $ciiu
    ): void {
        $result = $this->co_calculate( $gross,
            [ 'product_type' => $product_type ],
            [ 'tax_regime' => $regime, 'ciiu_code' => $ciiu ]
        );
        $neto = $result['net_to_vendor'];
        $this->assertLessThanOrEqual(    $gross + 0.01, $neto, 'neto <= gross' );
        $this->assertGreaterThanOrEqual( 0.0,           $neto, 'neto >= 0' );
    }

    public static function provider_co_net_vendor_invariant(): array
    {
        return [
            'physical_common'        => [ 100_000.0, 'physical',    'common',             '4711' ],
            'consulting_common'      => [ 500_000.0, 'consulting',  'common',             '7490' ],
            'restaurant_common'      => [ 200_000.0, 'food_service','common',             '5611' ],
            'physical_simplified'    => [ 300_000.0, 'physical',    'simplified',         '4711' ],
            'physical_gran_contrib'  => [ 400_000.0, 'physical',    'gran_contribuyente', '4711' ],
            'service_ciiu_4100'      => [ 250_000.0, 'physical',    'common',             '4100' ],
        ];
    }
}

