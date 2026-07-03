<?php
/**
 * Tests unitarios — LTMS_Commission_Strategy
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey\Functions;

class CommissionStrategyTest extends LTMS_Unit_Test_Case {

    private const DELTA = 0.00001;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Commission_Strategy' ) ) {
            $this->markTestSkipped( 'LTMS_Commission_Strategy no disponible.' );
        }

        // M-QA-10: get_rate_summary() now calls get_custom_contract_rate() internally,
        // which invokes get_user_meta(). Default safe stub ('' = no contract) for all
        // Section-7 tests that don't stub it explicitly.
        Functions\when( 'get_user_meta' )->justReturn( '' );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — Constantes y estructura de clase
    // ════════════════════════════════════════════════════════════════════════

    public function test_default_rate_es_0_15(): void {
        $this->assertEqualsWithDelta( 0.15, \LTMS_Commission_Strategy::DEFAULT_RATE, self::DELTA, 'DEFAULT_RATE debe ser 0.15 (15%)' );
    }

    public function test_default_rate_es_float(): void {
        $this->assertIsFloat( \LTMS_Commission_Strategy::DEFAULT_RATE );
    }

    public function test_default_rate_como_porcentaje_es_15(): void {
        $this->assertEqualsWithDelta( 15.0, \LTMS_Commission_Strategy::DEFAULT_RATE * 100, self::DELTA );
    }

    public function test_default_rate_en_rango_valido(): void {
        $this->assertGreaterThanOrEqual( 0.0, \LTMS_Commission_Strategy::DEFAULT_RATE );
        $this->assertLessThanOrEqual( 1.0, \LTMS_Commission_Strategy::DEFAULT_RATE );
    }

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Commission_Strategy' ) );
    }

    public function test_get_rate_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Commission_Strategy', 'get_rate' ) );
    }

    public function test_get_rate_summary_method_exists(): void {
        $this->assertTrue( method_exists( 'LTMS_Commission_Strategy', 'get_rate_summary' ) );
    }

    public function test_get_rate_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_get_rate_summary_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate_summary' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — Reflexión avanzada
    // ════════════════════════════════════════════════════════════════════════

    public function test_class_is_final(): void {
        $rc = new \ReflectionClass( 'LTMS_Commission_Strategy' );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_get_rate_returns_float_type(): void {
        $rm = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'float', (string) $rt );
    }

    public function test_get_rate_summary_returns_array_type(): void {
        $rm = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate_summary' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    public function test_get_rate_has_two_parameters(): void {
        $rm = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate' );
        $this->assertCount( 2, $rm->getParameters() );
    }

    public function test_get_rate_summary_has_one_parameter(): void {
        $rm = new \ReflectionMethod( 'LTMS_Commission_Strategy', 'get_rate_summary' );
        $this->assertCount( 1, $rm->getParameters() );
    }

    public function test_class_has_default_rate_constant(): void {
        $rc = new \ReflectionClass( 'LTMS_Commission_Strategy' );
        $this->assertTrue( $rc->hasConstant( 'DEFAULT_RATE' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — Prioridad 1: Tasa personalizada por contrato
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_custom_rates */
    public function test_custom_rate_tiene_maxima_prioridad( float $custom_rate ): void {
        Functions\when( 'get_user_meta' )->justReturn( (string) $custom_rate );
        $order = $this->make_order_mock();
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( $custom_rate, $rate, self::DELTA, "La tasa custom {$custom_rate} debe tener prioridad absoluta" );
    }

    /** @return array<string, array{float}> */
    public static function provider_custom_rates(): array {
        return [
            'tasa cero (vendor estratégico)' => [ 0.0   ],
            'tasa 5%'                        => [ 0.05  ],
            'tasa 7.5%'                      => [ 0.075 ],
            'tasa 15%'                       => [ 0.15  ],
            'tasa máxima 100%'               => [ 1.0   ],
        ];
    }

    public function test_custom_rate_invalida_se_ignora(): void {
        Functions\when( 'get_user_meta' )->justReturn( '2.5' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.10 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertLessThanOrEqual( 1.0, $rate );
        $this->assertGreaterThanOrEqual( 0.0, $rate );
    }

    public function test_custom_rate_negativa_se_ignora(): void {
        Functions\when( 'get_user_meta' )->justReturn( '-0.5' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.10 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertGreaterThanOrEqual( 0.0, $rate );
        $this->assertLessThanOrEqual( 1.0, $rate );
    }

    public function test_custom_rate_vacia_cae_a_siguiente_prioridad(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.07 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.07, $rate, self::DELTA );
    }

    public function test_custom_rate_exactamente_uno_es_valido(): void {
        Functions\when( 'get_user_meta' )->justReturn( '1.0' );
        $order = $this->make_order_mock();
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 1.0, $rate, self::DELTA );
    }

    public function test_custom_rate_exactamente_cero_es_valido(): void {
        Functions\when( 'get_user_meta' )->justReturn( '0' );
        $order = $this->make_order_mock();
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.0, $rate, self::DELTA );
    }

    public function test_custom_rate_mayor_que_uno_punto_uno_se_ignora(): void {
        Functions\when( 'get_user_meta' )->justReturn( '1.1' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.10 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.10, $rate, self::DELTA );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — Prioridad: Tasa por plan del vendedor
    // ════════════════════════════════════════════════════════════════════════

    public function test_vendor_premium_usa_tasa_premium(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'ltms_vendor_premium' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_premium_commission_rate', 0.08 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.08, $rate, self::DELTA, 'Vendor premium debe pagar 8%' );
    }

    public function test_vendor_basico_usa_tasa_basica(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'ltms_vendor' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_basic_commission_rate', 0.10 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.10, $rate, self::DELTA, 'Vendor básico debe pagar 10%' );
    }

    public function test_vendor_sin_rol_ltms_cae_a_global(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'subscriber' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.09 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.09, $rate, self::DELTA );
    }

    public function test_vendor_not_found_cae_a_global(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.11 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 99, $order );
        $this->assertEqualsWithDelta( 0.11, $rate, self::DELTA );
    }

    public function test_premium_rate_configurable(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'ltms_vendor_premium' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_premium_commission_rate', 0.06 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.06, $rate, self::DELTA );
    }

    public function test_basic_rate_configurable(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'ltms_vendor' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_basic_commission_rate', 0.12 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.12, $rate, self::DELTA );
    }

    public function test_premium_menor_que_basic_es_posible(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $user = new \stdClass(); $user->roles = [ 'ltms_vendor_premium' ];
        Functions\when( 'get_userdata' )->justReturn( $user );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_premium_commission_rate', 0.05 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.05, $rate, self::DELTA );
        $this->assertLessThan( 0.10, $rate );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — Prioridad 5: Tasa global configurable (fallback final)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_global_rates */
    public function test_tasa_global_como_fallback_final( float $global_rate ): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', $global_rate );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 99, $order );
        $this->assertEqualsWithDelta( $global_rate, $rate, self::DELTA );
    }

    /** @return array<string, array{float}> */
    public static function provider_global_rates(): array {
        return [
            '5% global'  => [ 0.05 ],
            '10% global' => [ 0.10 ],
            '12% global' => [ 0.12 ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — Lógica de tiers (inline, sin DB)
    // ════════════════════════════════════════════════════════════════════════

    private function classify_tier( float $monthly_sales, string $country ): string {
        $tier = 'base';
        if ( $country === 'CO' ) {
            if ( $monthly_sales >= 50_000_000 )     $tier = 'platinum';
            elseif ( $monthly_sales >= 20_000_000 ) $tier = 'gold';
            elseif ( $monthly_sales >=  5_000_000 ) $tier = 'silver';
        } elseif ( $country === 'MX' ) {
            if ( $monthly_sales >= 300_000 )        $tier = 'platinum';
            elseif ( $monthly_sales >= 100_000 )    $tier = 'gold';
            elseif ( $monthly_sales >=  25_000 )    $tier = 'silver';
        }
        return $tier;
    }

    /** @dataProvider provider_tiers_colombia */
    public function test_tiers_colombia_umbrales( float $sales, string $expected_tier ): void {
        $this->assertSame( $expected_tier, $this->classify_tier( $sales, 'CO' ) );
    }

    /** @return array<string, array{float, string}> */
    public static function provider_tiers_colombia(): array {
        return [
            'COP 0 → base'            => [          0.0, 'base'     ],
            'COP 4.999.999 → base'    => [  4_999_999.0, 'base'     ],
            'COP 5.000.000 → silver'  => [  5_000_000.0, 'silver'   ],
            'COP 10M → silver'        => [ 10_000_000.0, 'silver'   ],
            'COP 19.999.999 → silver' => [ 19_999_999.0, 'silver'   ],
            'COP 20M → gold'          => [ 20_000_000.0, 'gold'     ],
            'COP 30M → gold'          => [ 30_000_000.0, 'gold'     ],
            'COP 49.999.999 → gold'   => [ 49_999_999.0, 'gold'     ],
            'COP 50M → platinum'      => [ 50_000_000.0, 'platinum' ],
            'COP 100M → platinum'     => [100_000_000.0, 'platinum' ],
        ];
    }

    /** @dataProvider provider_tiers_mexico */
    public function test_tiers_mexico_umbrales( float $sales, string $expected_tier ): void {
        $this->assertSame( $expected_tier, $this->classify_tier( $sales, 'MX' ) );
    }

    /** @return array<string, array{float, string}> */
    public static function provider_tiers_mexico(): array {
        return [
            'MXN 0 → base'        => [       0.0, 'base'     ],
            'MXN 24.999 → base'   => [  24_999.0, 'base'     ],
            'MXN 25.000 → silver' => [  25_000.0, 'silver'   ],
            'MXN 50K → silver'    => [  50_000.0, 'silver'   ],
            'MXN 99.999 → silver' => [  99_999.0, 'silver'   ],
            'MXN 100K → gold'     => [ 100_000.0, 'gold'     ],
            'MXN 200K → gold'     => [ 200_000.0, 'gold'     ],
            'MXN 299.999 → gold'  => [ 299_999.0, 'gold'     ],
            'MXN 300K → platinum' => [ 300_000.0, 'platinum' ],
            'MXN 500K → platinum' => [ 500_000.0, 'platinum' ],
        ];
    }

    public function test_pais_desconocido_siempre_retorna_base(): void {
        $this->assertSame( 'base', $this->classify_tier( 999_999_999.0, 'AR' ) );
        $this->assertSame( 'base', $this->classify_tier( 0.0, 'US' ) );
    }

    public function test_tier_platinum_es_el_mas_alto(): void {
        $this->assertSame( 'platinum', $this->classify_tier( 1_000_000_000.0, 'CO' ) );
        $this->assertSame( 'platinum', $this->classify_tier( 1_000_000_000.0, 'MX' ) );
    }

    public function test_tiers_co_mx_tienen_distintos_umbrales(): void {
        $this->assertSame( 'base', $this->classify_tier( 100_000.0, 'CO' ) );
        $this->assertSame( 'gold', $this->classify_tier( 100_000.0, 'MX' ) );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 7 — get_rate_summary() — estructura y tipos
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_rate_summary_retorna_estructura_correcta(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertIsArray( $summary );
        $this->assertArrayHasKey( 'current_rate',  $summary );
        $this->assertArrayHasKey( 'rate_source',   $summary );
        $this->assertArrayHasKey( 'tier',          $summary );
        $this->assertArrayHasKey( 'monthly_sales', $summary );
    }

    public function test_current_rate_en_summary_es_float(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertIsFloat( $summary['current_rate'] );
    }

    public function test_monthly_sales_en_summary_es_float(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertIsFloat( $summary['monthly_sales'] );
    }

    public function test_tier_en_summary_es_string(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertIsString( $summary['tier'] );
    }

    public function test_tier_en_summary_es_valor_valido(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertContains( $summary['tier'], [ 'base', 'silver', 'gold', 'platinum' ] );
    }

    public function test_summary_monthly_sales_desde_wpdb_null_es_cero(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertEqualsWithDelta( 0.0, $summary['monthly_sales'], self::DELTA );
    }

    public function test_summary_tier_es_base_cuando_ventas_son_cero(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_country', 'CO' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertSame( 'base', $summary['tier'] );
    }

    public function test_summary_tier_es_base_para_mx_con_ventas_cero(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_country', 'MX' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertSame( 'base', $summary['tier'] );
    }

    public function test_summary_retorna_exactamente_cuatro_claves(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertCount( 4, $summary );
    }

    /**
     * M-QA-10: get_rate_summary() must reflect CS-00 contract rate.
     * Regression: Juguetería Taiwán at 0.12 showed 0.15 in admin panel.
     */
    public function test_summary_current_rate_usa_contrato_individual_cuando_existe(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        Functions\when( 'get_user_meta' )->alias( function( $user_id, $key, $single ) {
            return ( $key === 'ltms_custom_commission_rate' ) ? '0.12' : '';
        } );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 168 );
        $this->assertEqualsWithDelta( 0.12, $summary['current_rate'], self::DELTA );
        $this->assertSame( 'custom_contract', $summary['rate_source'] );
    }

    public function test_summary_rate_source_es_default_sin_contrato_ni_tier(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertSame( 'default', $summary['rate_source'] );
    }

    public function test_summary_current_rate_en_rango_valido(): void {
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        $summary = \LTMS_Commission_Strategy::get_rate_summary( 1 );
        $this->assertGreaterThanOrEqual( 0.0, $summary['current_rate'] );
        $this->assertLessThanOrEqual( 1.0, $summary['current_rate'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — Invariantes matemáticos
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_vendor_scenarios */
    public function test_tasa_siempre_en_rango_valido( array $config, string $scenario ): void {
        Functions\when( 'get_user_meta' )->justReturn( $config['custom_rate'] ?? '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        if ( isset( $config['global_rate'] ) ) {
            \LTMS_Core_Config::set( 'ltms_platform_commission_rate', $config['global_rate'] );
        }
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertGreaterThanOrEqual( 0.0, $rate, "{$scenario}: tasa no puede ser negativa" );
        $this->assertLessThanOrEqual( 1.0, $rate, "{$scenario}: tasa no puede superar 1.0" );
    }

    /** @return array<string, array{array, string}> */
    public static function provider_vendor_scenarios(): array {
        return [
            'custom rate 0.05' => [ [ 'custom_rate' => '0.05' ], 'custom 5%'   ],
            'custom rate 0.0'  => [ [ 'custom_rate' => '0.0'  ], 'custom 0%'   ],
            'custom rate 1.0'  => [ [ 'custom_rate' => '1.0'  ], 'custom 100%' ],
            'global rate 0.08' => [ [ 'global_rate' =>  0.08  ], 'global 8%'   ],
            'global rate 0.12' => [ [ 'global_rate' =>  0.12  ], 'global 12%'  ],
            'sin config'       => [ [                          ], 'default'     ],
        ];
    }

    public function test_tasa_es_float(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertIsFloat( $rate );
    }

    public function test_global_rate_clamped_a_cero_si_negativa(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', -0.5 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 99, $order );
        $this->assertEqualsWithDelta( 0.0, $rate, self::DELTA, 'Rate negativa debe ser clamped a 0' );
    }

    public function test_global_rate_clamped_a_uno_si_mayor(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        \LTMS_Core_Config::set( 'ltms_volume_tiers_enabled', 'no' );
        \LTMS_Core_Config::set( 'ltms_category_commission_rates', [] );
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 5.0 );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 99, $order );
        $this->assertEqualsWithDelta( 1.0, $rate, self::DELTA, 'Rate > 1 debe ser clamped a 1.0' );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Aritmética aplicada
    // ════════════════════════════════════════════════════════════════════════

    public function test_comision_calculada_sobre_monto_cop(): void {
        $this->assertEqualsWithDelta( 50_000.0, round( 0.10 * 500_000.0, 2 ), 0.01 );
    }

    public function test_comision_tasa_cero_siempre_es_cero(): void {
        $this->assertEqualsWithDelta( 0.0, round( 0.0 * 999_999.0, 2 ), self::DELTA );
    }

    public function test_comision_tasa_100_igual_al_monto(): void {
        $amount = 250_000.0;
        $this->assertEqualsWithDelta( $amount, round( 1.0 * $amount, 2 ), 0.01 );
    }

    public function test_comision_premium_menor_que_basica_en_mismo_monto(): void {
        $amount = 1_000_000.0;
        $this->assertLessThan( round( 0.10 * $amount, 2 ), round( 0.08 * $amount, 2 ) );
        $this->assertEqualsWithDelta( 80_000.0,  round( 0.08 * $amount, 2 ), 0.01 );
        $this->assertEqualsWithDelta( 100_000.0, round( 0.10 * $amount, 2 ), 0.01 );
    }

    public function test_comision_default_rate_sobre_millon(): void {
        $this->assertEqualsWithDelta( 150_000.0, round( \LTMS_Commission_Strategy::DEFAULT_RATE * 1_000_000.0, 2 ), 0.01 );
    }

    public function test_vendor_neto_es_monto_menos_comision(): void {
        $rate = 0.10; $amount = 500_000.0;
        $this->assertEqualsWithDelta( 450_000.0, $amount - round( $rate * $amount, 2 ), 0.01 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — init() no lanza excepciones
    // ════════════════════════════════════════════════════════════════════════

    public function test_init_no_lanza_excepcion(): void {
        $this->expectNotToPerformAssertions();
        \LTMS_Commission_Strategy::init();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Helpers privados
    // ════════════════════════════════════════════════════════════════════════

    private function make_order_mock( ?array $items = null ): object {
        $order = \Mockery::mock( 'WC_Order' );
        if ( $items === null ) {
            $order->shouldReceive( 'get_items' )->zeroOrMoreTimes()->andReturn( [] );
        } else {
            $wc_items = [];
            foreach ( $items as $product_id ) {
                $item = \Mockery::mock( 'WC_Order_Item_Product' );
                $item->shouldReceive( 'get_product_id' )->andReturn( $product_id );
                $wc_items[] = $item;
            }
            $order->shouldReceive( 'get_items' )->andReturn( $wc_items );
        }
        return $order;
    }

    public function test_get_rate_reads_platform_commission_rate_key(): void {
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.12 );
        \LTMS_Core_Config::set( 'ltms_commission_rate', 0.99 );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertEqualsWithDelta( 0.12, $rate, 0.001 );
    }

    public function test_get_rate_wrong_key_does_not_override_platform_rate(): void {
        \LTMS_Core_Config::set( 'ltms_platform_commission_rate', 0.10 );
        \LTMS_Core_Config::set( 'ltms_commission_rate', 0.50 );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_userdata' )->justReturn( false );
        $order = $this->make_order_mock( [] );
        $rate  = \LTMS_Commission_Strategy::get_rate( 1, $order );
        $this->assertNotEqualsWithDelta( 0.50, $rate, 0.001 );
    }
}
