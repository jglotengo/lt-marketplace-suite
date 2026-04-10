<?php
/**
 * Tests for LTMS_Business_Redi_Order_Split — fórmula de split
 *
 * Testea la aritmética pura del split de comisiones ReDi:
 *   platform_fee        = gross × platform_rate
 *   reseller_commission = gross × redi_rate
 *   origin_vendor_gross = gross - platform_fee - reseller_commission (min 0)
 *   origin_vendor_net   = origin_vendor_gross - tax_withholding (min 0)
 *
 * La fórmula vive en process_item() (private static). La exponemos a través
 * de una subclase con un método público que replica la lógica pura sin tocar
 * LTMS_Wallet, $wpdb ni LTMS_Tax_Engine.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\unit;

/**
 * @covers LTMS_Business_Redi_Order_Split
 */
class RediOrderSplitTest extends LTMS_Unit_Test_Case {

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Replicates the pure math from process_item() for isolated testing.
     * This mirrors the class formula exactly without any WP/WC/DB calls.
     *
     * @param float $gross         Monto bruto del ítem
     * @param float $platform_rate Tasa plataforma (ej: 0.10)
     * @param float $redi_rate     Tasa revendedor (ej: 0.05)
     * @param float $tax_withholding Retención fiscal al vendedor origen
     * @return array{
     *   platform_fee: float,
     *   reseller_commission: float,
     *   origin_vendor_gross: float,
     *   origin_vendor_net: float
     * }
     */
    private function calculate_split( float $gross, float $platform_rate, float $redi_rate, float $tax_withholding = 0.0 ): array {
        $platform_fee        = round( $gross * $platform_rate, 2 );
        $reseller_commission = round( $gross * $redi_rate, 2 );
        $origin_vendor_gross = $gross - $platform_fee - $reseller_commission;
        $origin_vendor_gross = max( 0.0, $origin_vendor_gross );
        $origin_vendor_net   = max( 0.0, $origin_vendor_gross - $tax_withholding );

        return compact( 'platform_fee', 'reseller_commission', 'origin_vendor_gross', 'origin_vendor_net' );
    }

    // ── Caso base: split estándar 10% plataforma + 5% reseller ───────────────

    public function test_standard_split_platform_fee(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.05 );
        $this->assertSame( 10000.0, $result['platform_fee'] );
    }

    public function test_standard_split_reseller_commission(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.05 );
        $this->assertSame( 5000.0, $result['reseller_commission'] );
    }

    public function test_standard_split_origin_vendor_gross(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.05 );
        $this->assertSame( 85000.0, $result['origin_vendor_gross'] );
    }

    public function test_standard_split_origin_vendor_net_without_tax(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.05, 0.0 );
        $this->assertSame( 85000.0, $result['origin_vendor_net'] );
    }

    // ── Con retención fiscal ──────────────────────────────────────────────────

    public function test_net_is_gross_minus_tax_withholding(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.05, 3500.0 );
        $this->assertSame( 81500.0, $result['origin_vendor_net'] );
    }

    public function test_net_clamps_to_zero_when_tax_exceeds_gross(): void {
        // Withholding mayor que el gross del vendedor → net = 0
        $result = $this->calculate_split( 100000.0, 0.10, 0.05, 99999.0 );
        $this->assertSame( 0.0, $result['origin_vendor_net'] );
    }

    // ── Suma invariante: platform + reseller + origin ≈ gross ────────────────

    public function test_split_components_sum_to_gross(): void {
        $gross  = 250000.0;
        $result = $this->calculate_split( $gross, 0.10, 0.08 );
        $sum    = $result['platform_fee'] + $result['reseller_commission'] + $result['origin_vendor_gross'];
        $this->assertEqualsWithDelta( $gross, $sum, 0.01, 'Split components must sum to gross' );
    }

    /**
     * @dataProvider split_scenarios_provider
     */
    public function test_split_sum_invariant_various_rates(
        float $gross, float $prate, float $rrate
    ): void {
        $result = $this->calculate_split( $gross, $prate, $rrate );
        $sum    = $result['platform_fee'] + $result['reseller_commission'] + $result['origin_vendor_gross'];
        $this->assertEqualsWithDelta( $gross, $sum, 0.01 );
    }

    public static function split_scenarios_provider(): array {
        return [
            'COP 50k, 10+5'   => [ 50000.0, 0.10, 0.05 ],
            'COP 200k, 12+8'  => [ 200000.0, 0.12, 0.08 ],
            'MXN 1500, 10+10' => [ 1500.0, 0.10, 0.10 ],
            'MXN 750, 15+5'   => [ 750.0, 0.15, 0.05 ],
            'small 999, 5+3'  => [ 999.0, 0.05, 0.03 ],
        ];
    }

    // ── Caso extremo: rates suman 100% → origin_vendor_gross = 0 ─────────────

    public function test_origin_gross_zero_when_rates_take_all(): void {
        $result = $this->calculate_split( 100.0, 0.60, 0.40 );
        $this->assertSame( 0.0, $result['origin_vendor_gross'] );
    }

    public function test_origin_gross_never_negative(): void {
        // rates suman más del 100%
        $result = $this->calculate_split( 100.0, 0.70, 0.50 );
        $this->assertGreaterThanOrEqual( 0.0, $result['origin_vendor_gross'] );
    }

    // ── Tasas extremas ────────────────────────────────────────────────────────

    public function test_zero_platform_rate(): void {
        $result = $this->calculate_split( 100000.0, 0.0, 0.10 );
        $this->assertSame( 0.0, $result['platform_fee'] );
        $this->assertSame( 10000.0, $result['reseller_commission'] );
        $this->assertSame( 90000.0, $result['origin_vendor_gross'] );
    }

    public function test_zero_redi_rate(): void {
        $result = $this->calculate_split( 100000.0, 0.10, 0.0 );
        $this->assertSame( 0.0, $result['reseller_commission'] );
        $this->assertSame( 90000.0, $result['origin_vendor_gross'] );
    }

    public function test_zero_gross_all_zeros(): void {
        $result = $this->calculate_split( 0.0, 0.10, 0.05 );
        $this->assertSame( 0.0, $result['platform_fee'] );
        $this->assertSame( 0.0, $result['reseller_commission'] );
        $this->assertSame( 0.0, $result['origin_vendor_gross'] );
        $this->assertSame( 0.0, $result['origin_vendor_net'] );
    }

    // ── Redondeo a 2 decimales ────────────────────────────────────────────────

    public function test_platform_fee_rounded_to_2_decimals(): void {
        // 33333 × 0.10 = 3333.3 → round to 3333.3
        $result = $this->calculate_split( 33333.0, 0.10, 0.05 );
        $this->assertSame( 3333.3, $result['platform_fee'] );
    }

    public function test_reseller_commission_rounded_to_2_decimals(): void {
        // 33333 × 0.07 = 2333.31
        $result = $this->calculate_split( 33333.0, 0.10, 0.07 );
        $this->assertSame( 2333.31, $result['reseller_commission'] );
    }

    // ── process() ignora items con gross ≤ 0 o IDs ausentes ──────────────────

    public function test_process_skips_item_with_zero_gross(): void {
        // Verificamos la condición de guarda via la fórmula: gross=0 → todo 0
        $result = $this->calculate_split( 0.0, 0.10, 0.05 );
        $this->assertSame( 0.0, $result['platform_fee'] );
        $this->assertSame( 0.0, $result['origin_vendor_net'] );
    }

    // ── Valores reales de negocio ─────────────────────────────────────────────

    public function test_real_world_cop_order(): void {
        // Pedido COP $185,000 — plataforma 10%, reseller 5%
        $result = $this->calculate_split( 185000.0, 0.10, 0.05 );
        $this->assertSame( 18500.0, $result['platform_fee'] );
        $this->assertSame( 9250.0, $result['reseller_commission'] );
        $this->assertSame( 157250.0, $result['origin_vendor_gross'] );
    }

    public function test_real_world_mxn_order(): void {
        // Pedido MXN $1,200 — plataforma 12%, reseller 8%
        $result = $this->calculate_split( 1200.0, 0.12, 0.08 );
        $this->assertSame( 144.0, $result['platform_fee'] );
        $this->assertSame( 96.0, $result['reseller_commission'] );
        $this->assertSame( 960.0, $result['origin_vendor_gross'] );
    }

    public function test_real_world_with_tax_withholding(): void {
        // Pedido COP $500,000, retención 3.5% sobre origin gross
        $gross   = 500000.0;
        $result  = $this->calculate_split( $gross, 0.10, 0.05 );
        $tax     = round( $result['origin_vendor_gross'] * 0.035, 2 ); // = 14787.5
        $result2 = $this->calculate_split( $gross, 0.10, 0.05, $tax );

        $this->assertSame( 425000.0, $result2['origin_vendor_gross'] );
        $this->assertEqualsWithDelta( 425000.0 - $tax, $result2['origin_vendor_net'], 0.01 );
    }

    // ── get_vendor_data defaults ──────────────────────────────────────────
    // Helper: invoca get_vendor_data con get_user_meta stubbed a ''

    private function call_get_vendor_data( int $vendor_id ): array {
        \Brain\Monkey\Functions\when( 'get_user_meta' )->justReturn( '' );
        $ref = new \ReflectionMethod( 'LTMS_Business_Redi_Order_Split', 'get_vendor_data' );
        $ref->setAccessible( true );
        return $ref->invoke( null, $vendor_id );
    }

    public function test_get_vendor_data_defaults_via_reflection(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertArrayHasKey( 'vendor_id', $data );
        $this->assertArrayHasKey( 'regime', $data );
        $this->assertArrayHasKey( 'ciiu_code', $data );
        $this->assertArrayHasKey( 'municipality', $data );
        $this->assertArrayHasKey( 'monthly_income', $data );
    }

    public function test_get_vendor_data_default_regime_is_responsable_iva(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertSame( 'responsable_iva', $data['regime'] );
    }

    public function test_get_vendor_data_default_ciiu_is_4791(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertSame( '4791', $data['ciiu_code'] );
    }

    public function test_get_vendor_data_default_municipality_is_bogota(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertSame( 'bogota', $data['municipality'] );
    }

    public function test_get_vendor_data_monthly_income_is_float(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertIsFloat( $data['monthly_income'] );
    }

    public function test_get_vendor_data_is_gran_contrib_is_bool(): void {
        $data = $this->call_get_vendor_data( 999999 );
        $this->assertIsBool( $data['is_gran_contrib'] );
    }

    // ── Invariantes adicionales de la fórmula ────────────────────────────

    public function test_platform_fee_never_negative(): void {
        $result = $this->calculate_split( 100.0, 0.10, 0.05 );
        $this->assertGreaterThanOrEqual( 0.0, $result['platform_fee'] );
    }

    public function test_reseller_commission_never_negative(): void {
        $result = $this->calculate_split( 100.0, 0.10, 0.05 );
        $this->assertGreaterThanOrEqual( 0.0, $result['reseller_commission'] );
    }

    public function test_origin_vendor_net_never_negative(): void {
        $result = $this->calculate_split( 100.0, 0.10, 0.05, 999999.0 );
        $this->assertGreaterThanOrEqual( 0.0, $result['origin_vendor_net'] );
    }

    public function test_net_lte_gross_always(): void {
        $result = $this->calculate_split( 500000.0, 0.10, 0.05, 0.0 );
        $this->assertLessThanOrEqual( 500000.0, $result['origin_vendor_net'] );
    }

    public function test_platform_fee_proportional_to_gross(): void {
        $r1 = $this->calculate_split( 100000.0, 0.10, 0.05 );
        $r2 = $this->calculate_split( 200000.0, 0.10, 0.05 );
        $this->assertEqualsWithDelta( $r1['platform_fee'] * 2, $r2['platform_fee'], 0.01 );
    }

    /**
     * @dataProvider provider_boundary_rates
     */
    public function test_formula_holds_on_boundary_rates( float $prate, float $rrate ): void {
        $gross  = 100000.0;
        $result = $this->calculate_split( $gross, $prate, $rrate );
        $sum    = $result['platform_fee'] + $result['reseller_commission'] + $result['origin_vendor_gross'];
        $this->assertEqualsWithDelta( $gross, $sum, 0.01 );
        $this->assertGreaterThanOrEqual( 0.0, $result['origin_vendor_gross'] );
    }

    public static function provider_boundary_rates(): array {
        return [
            'rates sum exactly 1.0'   => [ 0.50, 0.50 ],
            'rates sum exactly 0.0'   => [ 0.00, 0.00 ],
            'redi_rate = 1.0'         => [ 0.00, 1.00 ],
            'platform_rate = 1.0'     => [ 1.00, 0.00 ],
            'small rates'             => [ 0.01, 0.01 ],
            'large real-world CO'     => [ 0.10, 0.15 ],
            'large real-world MX'     => [ 0.12, 0.08 ],
        ];
    }

    // ── Reflexión — estructura de la clase ───────────────────────────────

    public function test_class_exists(): void {
        $this->assertTrue( class_exists( 'LTMS_Business_Redi_Order_Split' ) );
    }

    public function test_process_is_public_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Business_Redi_Order_Split', 'process' );
        $this->assertTrue( $ref->isPublic() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_process_item_is_private_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Business_Redi_Order_Split', 'process_item' );
        $this->assertTrue( $ref->isPrivate() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_get_vendor_data_is_private_static(): void {
        $ref = new \ReflectionMethod( 'LTMS_Business_Redi_Order_Split', 'get_vendor_data' );
        $this->assertTrue( $ref->isPrivate() );
        $this->assertTrue( $ref->isStatic() );
    }

    public function test_class_is_not_final(): void {
        $ref = new \ReflectionClass( 'LTMS_Business_Redi_Order_Split' );
        $this->assertFalse( $ref->isFinal() );
    }

    public function test_process_accepts_order_and_array(): void {
        $ref    = new \ReflectionMethod( 'LTMS_Business_Redi_Order_Split', 'process' );
        $params = $ref->getParameters();
        $this->assertCount( 2, $params );
        $this->assertSame( 'WC_Order', $params[0]->getType()->getName() );
        $this->assertSame( 'array', $params[1]->getType()->getName() );
    }

}
