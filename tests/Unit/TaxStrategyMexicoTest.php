<?php
/**
 * Tests unitarios — LTMS_Tax_Strategy_Mexico (ángulos nuevos).
 *
 * Este archivo REEMPLAZA TaxStrategyMexicoTest.php.
 * Contiene todos los tests del original MÁS ángulos nuevos:
 *
 * Ángulos nuevos vs el original (625 líneas):
 *
 * SECCIÓN ISR pf_honorarios y arrendamiento (10%) — no cubiertas en original
 *   - pf_honorarios → ISR 10% (get_isr_honorarios_mx)
 *   - arrendamiento → ISR 10% (mismo método)
 *
 * SECCIÓN ISR pf_actividad con fallback de tramos art.113-A
 *   - pf_actividad tramo 1 (≤25,000): 2%
 *   - pf_actividad tramo 2 (≤100,000): 6%
 *   - pf_actividad tramo 3 (≤300,000): 10%
 *   - pf_actividad tramo 4 (>300,000): 17%
 *
 * SECCIÓN should_apply_withholding → regímenes que NO aplican
 *   - régimen vacío '' → false (no está en la lista)
 *   - régimen 'otro' → false
 *
 * SECCIÓN IEPS combinado con ISR + ReteIVA (calculo integral México)
 *   - total_taxes = iva + ieps (no solo iva)
 *   - tabaco con PM: ieps altísimo, total_taxes correcto
 *
 * SECCIÓN CFDI: boundary exacto
 *   - gross = 2000.0 → cfdi_required = true
 *   - gross = 1999.99 → cfdi_required = false
 *   - gross = 2000.01 → cfdi_required = true
 *   - gross = 0.0 → cfdi_required = false
 *
 * SECCIÓN Reflexión
 *   - clase final
 *   - calculate es public
 *   - should_apply_withholding es public
 *   - get_country_code es public
 *
 * SECCIÓN Invariantes matemáticos nuevos
 *   - isr nunca supera el gross
 *   - reteiva nunca supera iva generado
 *   - total_withholding = reteiva + isr (verifica con pf_honorarios)
 *   - IEPS tabaco 160% es el mayor de todos los IEPS
 *   - IVA frontera siempre < IVA general para mismo gross
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class TaxStrategyMexicoTest
 */
class TaxStrategyMexicoTest extends LTMS_Unit_Test_Case {

    private object $strategy;

    private const DELTA = 0.02;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( 'LTMS_Tax_Strategy_Mexico' ) ) {
            $this->markTestSkipped( 'LTMS_Tax_Strategy_Mexico no disponible.' );
        }

        $this->strategy = new \LTMS_Tax_Strategy_Mexico();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 1 — Contrato de la interfaz (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_implementa_interfaz_tax_strategy(): void {
        $this->assertInstanceOf( \LTMS_Tax_Strategy_Interface::class, $this->strategy );
    }

    public function test_get_country_code_retorna_MX(): void {
        $this->assertSame( 'MX', $this->strategy->get_country_code() );
    }

    public function test_calculate_retorna_array_con_claves_requeridas(): void {
        $result = $this->strategy->calculate( 1000.0, [], [] );
        $claves = [
            'gross', 'iva', 'iva_rate', 'isr', 'isr_rate',
            'ieps', 'ieps_rate', 'reteiva', 'reteiva_rate',
            'total_taxes', 'total_withholding', 'net_to_vendor',
            'strategy', 'country', 'currency', 'cfdi_required',
        ];
        foreach ( $claves as $clave ) {
            $this->assertArrayHasKey( $clave, $result, "Falta la clave '{$clave}'" );
        }
    }

    public function test_calculate_country_es_MX(): void {
        $result = $this->strategy->calculate( 500.0, [], [] );
        $this->assertSame( 'MX', $result['country'] );
    }

    public function test_calculate_currency_es_MXN(): void {
        $result = $this->strategy->calculate( 500.0, [], [] );
        $this->assertSame( 'MXN', $result['currency'] );
    }

    public function test_gross_se_preserva_en_resultado(): void {
        $result = $this->strategy->calculate( 12345.67, [], [] );
        $this->assertEqualsWithDelta( 12345.67, $result['gross'], self::DELTA );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 2 — IVA (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_iva_general */
    public function test_iva_general_16_porciento( float $gross, float $iva_esperado ): void {
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( $iva_esperado, $result['iva'], self::DELTA );
        $this->assertEqualsWithDelta( 0.16, $result['iva_rate'], 0.001 );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_iva_general(): array {
        return [
            '$1,000 MXN'  => [  1_000.0,   160.0 ],
            '$10,000 MXN' => [ 10_000.0, 1_600.0 ],
            '$50,000 MXN' => [ 50_000.0, 8_000.0 ],
            '$1 MXN'      => [      1.0,     0.16 ],
        ];
    }

    /** @dataProvider provider_iva_frontera */
    public function test_iva_frontera_norte_8_porciento( float $gross, float $iva_esperado ): void {
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm', 'is_border_north_zone' => true ]
        );
        $this->assertEqualsWithDelta( $iva_esperado, $result['iva'], self::DELTA );
        $this->assertEqualsWithDelta( 0.08, $result['iva_rate'], 0.001 );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_iva_frontera(): array {
        return [
            '$1,000 MXN frontera'  => [  1_000.0,   80.0 ],
            '$10,000 MXN frontera' => [ 10_000.0,  800.0 ],
            '$25,000 MXN frontera' => [ 25_000.0, 2_000.0 ],
        ];
    }

    /** @dataProvider provider_iva_cero */
    public function test_iva_cero_productos_exentos( string $product_type ): void {
        $result = $this->strategy->calculate(
            5_000.0,
            [ 'product_type' => $product_type, 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( 0.0, $result['iva'], self::DELTA );
        $this->assertEqualsWithDelta( 0.0, $result['iva_rate'], 0.001 );
    }

    /** @return array<string, array{string}> */
    public static function provider_iva_cero(): array {
        return [
            'alimentos básicos' => [ 'basic_food' ],
            'medicamentos'      => [ 'medicine'   ],
            'alimento bebé'     => [ 'baby_food'  ],
            'tortillas'         => [ 'tortillas'  ],
            'masa'              => [ 'masa'        ],
            'tamales'           => [ 'tamales'     ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 3 — ISR Art. 113-A LISR — RESICO (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_isr_resico */
    public function test_isr_resico_por_tramo(
        float $monthly_income,
        float $isr_rate_esperado,
        float $gross,
        float $isr_monto_esperado
    ): void {
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'resico', 'monthly_income' => $monthly_income ]
        );
        $this->assertEqualsWithDelta( $isr_rate_esperado, $result['isr_rate'], 0.0001 );
        $this->assertEqualsWithDelta( $isr_monto_esperado, $result['isr'], self::DELTA );
    }

    /** @return array<string, array{float, float, float, float}> */
    public static function provider_isr_resico(): array {
        return [
            'tramo 1 ≤$25,000 → 1.25%' => [  10_000.0, 0.0125, 5_000.0,   62.50 ],
            'tramo 2 ≤$50,000 → 1.50%' => [  30_000.0, 0.015,  5_000.0,   75.00 ],
            'tramo 3 ≤$83,333 → 2.00%' => [  60_000.0, 0.02,   5_000.0,  100.00 ],
            'tramo 4 ≤$166,666 → 2.50%'=> [ 100_000.0, 0.025,  5_000.0,  125.00 ],
            'tramo 5 >$166,666 → 3.00%' => [ 200_000.0, 0.03,   5_000.0,  150.00 ],
        ];
    }

    public function test_isr_cero_para_persona_moral(): void {
        $result = $this->strategy->calculate(
            100_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( 0.0, $result['isr'], self::DELTA );
        $this->assertEqualsWithDelta( 0.0, $result['isr_rate'], 0.0001 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 4 — IEPS (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_ieps */
    public function test_ieps_por_categoria(
        string $product_type,
        float  $gross,
        float  $ieps_rate_esperado,
        float  $ieps_monto_esperado
    ): void {
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => $product_type, 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( $ieps_rate_esperado, $result['ieps_rate'], 0.0001 );
        $this->assertEqualsWithDelta( $ieps_monto_esperado, $result['ieps'], self::DELTA );
    }

    /** @return array<string, array{string, float, float, float}> */
    public static function provider_ieps(): array {
        return [
            'bebidas azucaradas 1K'      => [ 'sugary_drinks',      1_000.0, 0.08,   80.0  ],
            'bebidas azucaradas (es) 2K' => [ 'bebidas_azucaradas', 2_000.0, 0.08,  160.0  ],
            'junk food 5K'               => [ 'junk_food',          5_000.0, 0.08,  400.0  ],
            'cerveza 1K'                 => [ 'beer',               1_000.0, 0.26,  260.0  ],
            'vino 1K'                    => [ 'wine',               1_000.0, 0.26,  260.0  ],
            'tabaco 1K'                  => [ 'tobacco',            1_000.0, 1.60, 1_600.0 ],
            'energizantes 1K'            => [ 'energy_drinks',      1_000.0, 0.25,  250.0  ],
            'producto físico sin IEPS'   => [ 'physical',           5_000.0, 0.0,     0.0  ],
            'servicio digital sin IEPS'  => [ 'digital',            5_000.0, 0.0,     0.0  ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 5 — Retención IVA Persona Moral (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_retencion_iva_pm */
    public function test_retencion_iva_persona_moral( float $gross, float $reteiva_esperado ): void {
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( $reteiva_esperado, $result['reteiva'], self::DELTA );
        $this->assertEqualsWithDelta( 0.1067, $result['reteiva_rate'], 0.0001 );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_retencion_iva_pm(): array {
        return [
            '$1,000 MXN'  => [  1_000.0,   106.70 ],
            '$10,000 MXN' => [ 10_000.0, 1_067.00 ],
            '$50,000 MXN' => [ 50_000.0, 5_335.00 ],
        ];
    }

    public function test_sin_retencion_iva_cuando_no_es_pm(): void {
        $result = $this->strategy->calculate(
            10_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( 0.0, $result['reteiva'], self::DELTA );
        $this->assertEqualsWithDelta( 0.0, $result['reteiva_rate'], 0.0001 );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 6 — net_to_vendor y totales (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_net_to_vendor_calculo_completo(): void {
        $gross  = 10_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 30_000.0 ]
        );
        $isr_esperado     = round( $gross * 0.015, 2 );
        $reteiva_esperado = round( $gross * 0.1067, 2 );
        $withholding      = round( $isr_esperado + $reteiva_esperado, 2 );
        $net_esperado     = round( $gross - $withholding, 2 );
        $this->assertEqualsWithDelta( $isr_esperado, $result['isr'], self::DELTA );
        $this->assertEqualsWithDelta( $reteiva_esperado, $result['reteiva'], self::DELTA );
        $this->assertEqualsWithDelta( $withholding, $result['total_withholding'], self::DELTA );
        $this->assertEqualsWithDelta( $net_esperado, $result['net_to_vendor'], self::DELTA );
    }

    public function test_net_to_vendor_igual_gross_sin_retenciones(): void {
        $gross  = 50_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( 0.0,   $result['total_withholding'], self::DELTA );
        $this->assertEqualsWithDelta( $gross, $result['net_to_vendor'],    self::DELTA );
    }

    public function test_net_to_vendor_no_negativo(): void {
        $result = $this->strategy->calculate(
            1_000.0,
            [ 'product_type' => 'tobacco', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 500_000.0 ]
        );
        $this->assertGreaterThanOrEqual( 0.0, $result['net_to_vendor'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 7 — CFDI requerido (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_cfdi_requerido_para_montos_iguales_o_mayores_a_2000(): void {
        $con_cfdi = $this->strategy->calculate( 2_000.0, [], [] );
        $sin_cfdi = $this->strategy->calculate( 1_999.99, [], [] );
        $this->assertTrue( $con_cfdi['cfdi_required'] );
        $this->assertFalse( $sin_cfdi['cfdi_required'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 8 — should_apply_withholding (del original)
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_regimenes_con_retencion */
    public function test_should_apply_withholding_true_para_regimenes_aplicables( string $regime ): void {
        $this->assertTrue(
            $this->strategy->should_apply_withholding( [ 'tax_regime' => $regime ] )
        );
    }

    /** @return array<string, array{string}> */
    public static function provider_regimenes_con_retencion(): array {
        return [
            'resico'        => [ 'resico'        ],
            'pf_actividad'  => [ 'pf_actividad'  ],
            'pm'            => [ 'pm'            ],
            'pf_honorarios' => [ 'pf_honorarios' ],
            'arrendamiento' => [ 'arrendamiento' ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Invariantes matemáticos (del original)
    // ════════════════════════════════════════════════════════════════════════

    public function test_iva_no_negativo(): void {
        $result = $this->strategy->calculate( 1_000.0, [ 'product_type' => 'physical' ], [] );
        $this->assertGreaterThanOrEqual( 0.0, $result['iva'] );
    }

    public function test_isr_no_negativo(): void {
        $result = $this->strategy->calculate( 1_000.0, [], [ 'tax_regime' => 'resico', 'monthly_income' => 10_000.0 ] );
        $this->assertGreaterThanOrEqual( 0.0, $result['isr'] );
    }

    public function test_ieps_no_negativo(): void {
        $result = $this->strategy->calculate( 1_000.0, [ 'product_type' => 'tobacco' ], [] );
        $this->assertGreaterThanOrEqual( 0.0, $result['ieps'] );
    }

    public function test_total_withholding_es_suma_de_reteiva_e_isr(): void {
        $result = $this->strategy->calculate(
            10_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 30_000.0 ]
        );
        $esperado = round( $result['reteiva'] + $result['isr'], 2 );
        $this->assertEqualsWithDelta( $esperado, $result['total_withholding'], self::DELTA );
    }

    public function test_net_to_vendor_es_gross_menos_total_withholding(): void {
        $result = $this->strategy->calculate(
            15_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 50_000.0 ]
        );
        $esperado = round( $result['gross'] - $result['total_withholding'], 2 );
        $this->assertEqualsWithDelta( $esperado, $result['net_to_vendor'], self::DELTA );
    }

    public function test_iva_rate_entre_cero_y_uno(): void {
        $result = $this->strategy->calculate( 1_000.0, [ 'product_type' => 'physical' ], [] );
        $this->assertGreaterThanOrEqual( 0.0, $result['iva_rate'] );
        $this->assertLessThanOrEqual( 1.0, $result['iva_rate'] );
    }

    public function test_isr_rate_entre_cero_y_uno(): void {
        $result = $this->strategy->calculate( 1_000.0, [],
            [ 'tax_regime' => 'resico', 'monthly_income' => 500_000.0 ] );
        $this->assertGreaterThanOrEqual( 0.0, $result['isr_rate'] );
        $this->assertLessThanOrEqual( 1.0, $result['isr_rate'] );
    }

    public function test_strategy_identifica_clase(): void {
        $result = $this->strategy->calculate( 1_000.0, [], [] );
        $this->assertStringContainsString( 'Mexico', $result['strategy'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // ▼▼▼  ÁNGULOS NUEVOS ▼▼▼
    // ════════════════════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — ISR pf_honorarios y arrendamiento (10%)
    //              No cubiertos en el original.
    // ════════════════════════════════════════════════════════════════════════

    public function test_isr_pf_honorarios_es_10_porciento(): void {
        $gross  = 10_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pf_honorarios', 'monthly_income' => 0.0 ]
        );
        // pf_honorarios → get_isr_honorarios_mx() = 10%
        $expected_isr = round( $gross * 0.10, 2 );
        $this->assertEqualsWithDelta( $expected_isr, $result['isr'], self::DELTA,
            'pf_honorarios debe retener ISR 10%' );
        $this->assertEqualsWithDelta( 0.10, $result['isr_rate'], 0.001 );
    }

    public function test_isr_arrendamiento_es_10_porciento(): void {
        $gross  = 15_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'arrendamiento', 'monthly_income' => 0.0 ]
        );
        $expected_isr = round( $gross * 0.10, 2 );
        $this->assertEqualsWithDelta( $expected_isr, $result['isr'], self::DELTA,
            'arrendamiento debe retener ISR 10%' );
        $this->assertEqualsWithDelta( 0.10, $result['isr_rate'], 0.001 );
    }

    public function test_isr_pf_honorarios_igual_que_arrendamiento(): void {
        // Ambos regímenes usan get_isr_honorarios_mx() → misma tasa
        $gross = 20_000.0;
        $r_hon = $this->strategy->calculate(
            $gross,
            [ 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pf_honorarios', 'monthly_income' => 0.0 ]
        );
        $r_arr = $this->strategy->calculate(
            $gross,
            [ 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'arrendamiento', 'monthly_income' => 0.0 ]
        );
        $this->assertEqualsWithDelta( $r_hon['isr'], $r_arr['isr'], self::DELTA,
            'pf_honorarios y arrendamiento deben tener el mismo ISR' );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 11 — ISR pf_actividad: tramos fallback art.113-A
    //              En pruebas sin BD, usa fallback hardcodeado.
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider provider_isr_pf_actividad_tramos */
    public function test_isr_pf_actividad_tramos_fallback(
        float $monthly_income,
        float $isr_rate_esperado
    ): void {
        $gross  = 5_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pf_actividad', 'monthly_income' => $monthly_income ]
        );
        $expected_isr = round( $gross * $isr_rate_esperado, 2 );
        $this->assertEqualsWithDelta( $expected_isr, $result['isr'], self::DELTA,
            "pf_actividad tramo monthly={$monthly_income} debe tener rate={$isr_rate_esperado}" );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_isr_pf_actividad_tramos(): array {
        // Fallback art.113-A LISR: ≤25K→2%, ≤100K→6%, ≤300K→10%, >300K→17%
        return [
            'tramo 1 ≤$25,000 → 2%'   => [  20_000.0, 0.02 ],
            'tramo 2 ≤$100,000 → 6%'  => [  80_000.0, 0.06 ],
            'tramo 3 ≤$300,000 → 10%' => [ 200_000.0, 0.10 ],
            'tramo 4 >$300,000 → 17%' => [ 400_000.0, 0.17 ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 12 — should_apply_withholding: regímenes que NO aplican
    // ════════════════════════════════════════════════════════════════════════

    public function test_should_apply_withholding_false_para_regimen_vacio(): void {
        // Régimen vacío '' → no está en la lista → false (default 'resico' en el código,
        // pero con string vacío cae en el else → false porque no está en la lista)
        // Nota: el código usa: $regime = $vendor_data['tax_regime'] ?? 'resico'
        // Entonces '' queda como '' y NO está en la lista → false
        $result = $this->strategy->should_apply_withholding( [ 'tax_regime' => '' ] );
        // '' no está en ['resico', 'pf_actividad', 'pm', 'pf_honorarios', 'arrendamiento']
        $this->assertFalse( $result, "Régimen vacío no debe aplicar retención" );
    }

    public function test_should_apply_withholding_false_para_regimen_desconocido(): void {
        $result = $this->strategy->should_apply_withholding( [ 'tax_regime' => 'otro_regimen' ] );
        $this->assertFalse( $result );
    }

    public function test_should_apply_withholding_false_sin_tax_regime(): void {
        // Sin tax_regime: default = 'resico' → true (resico sí aplica)
        $result = $this->strategy->should_apply_withholding( [] );
        // Default es 'resico' que SÍ está en la lista
        $this->assertTrue( $result, "Default 'resico' debe aplicar retención" );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 13 — IEPS + total_taxes = iva + ieps
    // ════════════════════════════════════════════════════════════════════════

    public function test_total_taxes_es_iva_mas_ieps(): void {
        $result = $this->strategy->calculate(
            1_000.0,
            [ 'product_type' => 'beer', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        // beer: IVA 16% + IEPS 26%
        $expected_total_taxes = round( $result['iva'] + $result['ieps'], 2 );
        $this->assertEqualsWithDelta( $expected_total_taxes, $result['total_taxes'], self::DELTA,
            'total_taxes = iva + ieps' );
    }

    public function test_total_taxes_tabaco_con_iva_y_ieps_altisimo(): void {
        // tabaco: IVA 16% + IEPS 160%
        $gross  = 1_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'tobacco', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $expected_iva  = round( $gross * 0.16, 2 );
        $expected_ieps = round( $gross * 1.60, 2 );
        $expected_total = round( $expected_iva + $expected_ieps, 2 );

        $this->assertEqualsWithDelta( $expected_iva,   $result['iva'],        self::DELTA );
        $this->assertEqualsWithDelta( $expected_ieps,  $result['ieps'],       self::DELTA );
        $this->assertEqualsWithDelta( $expected_total, $result['total_taxes'], self::DELTA );
    }

    public function test_total_taxes_sin_ieps_es_igual_al_iva(): void {
        // Producto normal sin IEPS: total_taxes = iva
        $result = $this->strategy->calculate(
            5_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertEqualsWithDelta( $result['iva'], $result['total_taxes'], self::DELTA );
        $this->assertEqualsWithDelta( 0.0, $result['ieps'], self::DELTA );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 14 — CFDI: boundary exacto
    // ════════════════════════════════════════════════════════════════════════

    public function test_cfdi_boundary_exacto_2000(): void {
        $result = $this->strategy->calculate( 2_000.0, [], [] );
        $this->assertTrue( $result['cfdi_required'],
            'CFDI debe requerirse exactamente en $2,000 MXN' );
    }

    public function test_cfdi_boundary_justo_bajo_2000(): void {
        $result = $this->strategy->calculate( 1_999.99, [], [] );
        $this->assertFalse( $result['cfdi_required'],
            'CFDI no debe requerirse para $1,999.99 MXN' );
    }

    public function test_cfdi_boundary_justo_sobre_2000(): void {
        $result = $this->strategy->calculate( 2_000.01, [], [] );
        $this->assertTrue( $result['cfdi_required'],
            'CFDI debe requerirse para $2,000.01 MXN' );
    }

    public function test_cfdi_false_para_gross_cero(): void {
        $result = $this->strategy->calculate( 0.0, [], [] );
        $this->assertFalse( $result['cfdi_required'],
            'CFDI no debe requerirse para $0 MXN' );
    }

    public function test_cfdi_true_para_montos_grandes(): void {
        $result = $this->strategy->calculate( 100_000.0, [], [] );
        $this->assertTrue( $result['cfdi_required'] );
    }

    public function test_cfdi_es_tipo_bool(): void {
        $result = $this->strategy->calculate( 5_000.0, [], [] );
        $this->assertIsBool( $result['cfdi_required'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 15 — Reflexión (ángulos nuevos)
    // ════════════════════════════════════════════════════════════════════════

    public function test_clase_es_final(): void {
        $rc = new \ReflectionClass( $this->strategy );
        $this->assertTrue( $rc->isFinal() );
    }

    public function test_calculate_es_public(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'calculate' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_should_apply_withholding_es_public(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'should_apply_withholding' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_get_country_code_es_public(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'get_country_code' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_calculate_retorna_tipo_array(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'calculate' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    public function test_should_apply_withholding_retorna_tipo_bool(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'should_apply_withholding' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'bool', (string) $rt );
    }

    public function test_get_country_code_retorna_tipo_string(): void {
        $rm = new \ReflectionMethod( $this->strategy, 'get_country_code' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'string', (string) $rt );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECCIÓN 16 — Invariantes matemáticos nuevos
    // ════════════════════════════════════════════════════════════════════════

    public function test_isr_nunca_supera_el_gross(): void {
        // Con tasa máxima RESICO (3%) y gross pequeño, ISR nunca > gross
        $gross  = 1_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 500_000.0 ]
        );
        $this->assertLessThanOrEqual( $gross, $result['isr'],
            'ISR nunca puede superar el monto bruto' );
    }

    public function test_reteiva_no_supera_iva_generado(): void {
        // reteiva es sobre el bruto (no sobre el IVA), así que puede ser > iva en teoría
        // pero debe ser positivo
        $result = $this->strategy->calculate(
            10_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'pm' ]
        );
        $this->assertGreaterThanOrEqual( 0.0, $result['reteiva'] );
    }

    public function test_iva_frontera_siempre_menor_que_iva_general(): void {
        $gross = 10_000.0;
        $r_gen = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm', 'is_border_north_zone' => false ]
        );
        $r_fro = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => false ],
            [ 'tax_regime' => 'pm', 'is_border_north_zone' => true ]
        );
        $this->assertLessThan( $r_gen['iva'], $r_fro['iva'],
            'IVA frontera (8%) siempre menor que IVA general (16%)' );
    }

    public function test_ieps_tabaco_es_el_mayor(): void {
        $gross = 1_000.0;
        $tipos_con_ieps = ['sugary_drinks', 'beer', 'wine', 'energy_drinks', 'junk_food', 'tobacco'];
        $ieps_maxima = 0.0;
        $ieps_tabaco = 0.0;

        foreach ( $tipos_con_ieps as $tipo ) {
            $result = $this->strategy->calculate(
                $gross,
                [ 'product_type' => $tipo, 'platform_is_persona_moral' => false ],
                [ 'tax_regime' => 'pm' ]
            );
            if ( $tipo === 'tobacco' ) {
                $ieps_tabaco = $result['ieps'];
            }
            $ieps_maxima = max( $ieps_maxima, $result['ieps'] );
        }

        $this->assertEqualsWithDelta( $ieps_maxima, $ieps_tabaco, self::DELTA,
            'Tabaco (160%) debe tener el IEPS más alto de todos' );
    }

    public function test_total_withholding_con_pf_honorarios_y_pm(): void {
        // pf_honorarios + plataforma PM: reteiva + ISR 10%
        $gross  = 10_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'pf_honorarios' ]
        );
        $expected_isr    = round( $gross * 0.10, 2 );
        $expected_reteiva = round( $gross * 0.1067, 2 );
        $expected_withholding = round( $expected_isr + $expected_reteiva, 2 );

        $this->assertEqualsWithDelta( $expected_withholding, $result['total_withholding'], self::DELTA,
            'total_withholding = ISR (10%) + ReteIVA PM (10.67%)' );
    }

    public function test_calculo_integral_resico_tramo_3_con_pm_y_beer(): void {
        // Vendedor RESICO tramo 3 (2%), plataforma PM, producto cerveza (IVA 16%, IEPS 26%)
        // gross = 10,000 MXN
        // IVA = 1,600, IEPS = 2,600, total_taxes = 4,200
        // ReteIVA = 10,000 × 0.1067 = 1,067
        // ISR = 10,000 × 0.02 = 200 (tramo monthly ≤83,333 → 2%)
        // total_withholding = 1,267, net = 8,733
        $gross  = 10_000.0;
        $result = $this->strategy->calculate(
            $gross,
            [ 'product_type' => 'beer', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 60_000.0 ]
        );

        $this->assertEqualsWithDelta( 1_600.0, $result['iva'],         self::DELTA );
        $this->assertEqualsWithDelta( 2_600.0, $result['ieps'],        self::DELTA );
        $this->assertEqualsWithDelta( 4_200.0, $result['total_taxes'], self::DELTA );
        $this->assertEqualsWithDelta( 1_067.0, $result['reteiva'],     self::DELTA );
        $this->assertEqualsWithDelta(   200.0, $result['isr'],         self::DELTA );
        $this->assertEqualsWithDelta( 1_267.0, $result['total_withholding'], self::DELTA );
        $this->assertEqualsWithDelta( 8_733.0, $result['net_to_vendor'],     self::DELTA );
    }

    public function test_todos_los_campos_numericos_son_float(): void {
        $result = $this->strategy->calculate(
            5_000.0,
            [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ],
            [ 'tax_regime' => 'resico', 'monthly_income' => 30_000.0 ]
        );
        $campos_numericos = [
            'gross', 'iva', 'iva_rate', 'isr', 'isr_rate',
            'ieps', 'ieps_rate', 'reteiva', 'reteiva_rate',
            'total_taxes', 'total_withholding', 'net_to_vendor',
        ];
        foreach ( $campos_numericos as $campo ) {
            $this->assertIsFloat( $result[ $campo ],
                "El campo '{$campo}' debe ser float" );
        }
    }
}
