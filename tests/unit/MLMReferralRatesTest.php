<?php
/**
 * MLMReferralRatesTest — QA Ronda 4: módulo Marketing / MLM
 *
 * Cubre:
 *  M-01: get_referral_rates() lee ltms_referral_rates (JSON), no ltms_mlm_l1/l2/l3_rate
 *  M-02: get_referral_rates() respeta ltms_mlm_levels (trunca el array)
 *
 * @package LTMS\Tests\Unit
 */

use Brain\Monkey\Functions;

class MLMReferralRatesTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    protected function setUp(): void {
        parent::setUp();
        LTMS_Core_Config::flush_cache();
    }

    // ── M-01: la función lee ltms_referral_rates (JSON), no ltms_mlm_l1/l2/l3_rate ──

    /** @test */
    public function test_get_referral_rates_reads_json_key(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.08, 0.04, 0.02 ] ) );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '3' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertEqualsWithDelta( 0.08, $rates[0], 0.001 );
        $this->assertEqualsWithDelta( 0.04, $rates[1], 0.001 );
        $this->assertEqualsWithDelta( 0.02, $rates[2], 0.001 );
    }

    /** @test */
    public function test_get_referral_rates_ignores_mlm_l1_l2_l3_keys(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.06 ] ) );
        LTMS_Core_Config::set( 'ltms_mlm_l1_rate', 0.99 ); // clave incorrecta — debe ignorarse
        LTMS_Core_Config::set( 'ltms_mlm_levels', '1' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 1, $rates );
        $this->assertEqualsWithDelta( 0.06, $rates[0], 0.001, 'Debe leer ltms_referral_rates, no ltms_mlm_l1_rate' );
    }

    /** @test */
    public function test_get_referral_rates_falls_back_to_default_when_empty(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', '' );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '3' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertEqualsWithDelta( LTMS_Referral_Tree::DEFAULT_RATES[0], $rates[0], 0.001 );
    }

    /** @test */
    public function test_get_referral_rates_invalid_json_falls_back_to_default(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', 'not-json' );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '3' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 3, $rates );
        $this->assertEqualsWithDelta( LTMS_Referral_Tree::DEFAULT_RATES[0], $rates[0], 0.001 );
    }

    // ── M-02: ltms_mlm_levels trunca el array de tasas ──

    /** @test */
    public function test_get_referral_rates_truncated_to_1_level(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.05, 0.02, 0.01 ] ) );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '1' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 1, $rates, 'ltms_mlm_levels=1 debe dar solo 1 tasa' );
    }

    /** @test */
    public function test_get_referral_rates_truncated_to_2_levels(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.05, 0.02, 0.01 ] ) );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '2' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 2, $rates );
    }

    /** @test */
    public function test_get_referral_rates_all_3_levels_when_configured(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', json_encode( [ 0.05, 0.02, 0.01 ] ) );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '3' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 3, $rates );
    }

    /** @test */
    public function test_default_rates_truncated_by_mlm_levels(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', '' );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '1' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 1, $rates );
        $this->assertEqualsWithDelta( LTMS_Referral_Tree::DEFAULT_RATES[0], $rates[0], 0.001 );
    }

    /** @test */
    public function test_default_rates_all_3_levels_when_configured(): void {
        LTMS_Core_Config::set( 'ltms_referral_rates', '' );
        LTMS_Core_Config::set( 'ltms_mlm_levels', '3' );

        $rates = $this->invoke_get_referral_rates();
        $this->assertCount( 3, $rates );
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function invoke_get_referral_rates(): array {
        $ref    = new ReflectionClass( LTMS_Referral_Tree::class );
        $method = $ref->getMethod( 'get_referral_rates' );
        $method->setAccessible( true );
        return $method->invoke( null );
    }
}
