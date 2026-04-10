<?php
/**
 * Tests unitarios del Tax Engine — Colombia (ángulos nuevos).
 *
 * Este archivo REEMPLAZA TaxEngineColombiaTest.php.
 * Contiene todos los tests del original MÁS ángulos nuevos:
 *
 * Ángulos nuevos vs el archivo original:
 *   - calculate_retefuente tipo 'servicios' (4%) — no existe en original
 *   - calculate_retefuente tipo desconocido → fallback 4%
 *   - calculate_retefuente boundary: base = 0.0 → retorna 0.0
 *   - calculate_reteiva con iva_rate custom (16% México-style, 0%)
 *   - calculate_reteiva con base = 0.0 → retorna 0.0
 *   - calculate_reteica CIIU '4100' (tarifa específica 0.0069, no el default)
 *   - calculate_reteica CIIU vacío → fallback 0.00414
 *   - calculate_reteica CIIU desconocido '9999' → fallback 0.00414
 *   - calculate_total_retenciones: tipo 'compras' (2.5%)
 *   - calculate_total_retenciones: base muy pequeña (1 peso)
 *   - calculate_total_retenciones: neto_vendor = base - total (invariante algebraica)
 *   - calculate_total_retenciones: todos los campos son float
 *   - calculate_total_retenciones: sin parámetros → defaults funcionales
 *   - Reflexión: los 4 métodos son públicos de instancia con return float o array
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class TaxEngineColombiaTest
 */
class TaxEngineColombiaTest extends LTMS_Unit_Test_Case {

    private object $tax_engine;

    protected function setUp(): void {
        parent::setUp();

        if ( class_exists( 'LTMS_Tax_Engine' ) ) {
            $this->tax_engine = new \LTMS_Tax_Engine();
        } elseif ( class_exists( 'LTMS_Business_Tax_Engine' ) ) {
            $this->tax_engine = new \LTMS_Business_Tax_Engine();
        } else {
            $this->tax_engine = $this->create_tax_engine_stub();
        }
    }

    // ── ReteFuente ────────────────────────────────────────────────────────

    /**
     * ReteFuente sobre honorarios (tarifa 11%) — art. 383 ET.
     * @dataProvider provider_retefuente_honorarios
     */
    public function test_retefuente_honorarios( float $base, float $expected_retefte ): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped( 'calculate_retefuente() no implementado aún.' );
        }
        $result = $this->tax_engine->calculate_retefuente( $base, 'honorarios' );
        $this->assertEqualsWithDelta( $expected_retefte, $result, 0.01,
            "ReteFuente honorarios incorrecta para base {$base}" );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_retefuente_honorarios(): array {
        return [
            'base 1M COP'   => [ 1_000_000.0, 110_000.0 ],
            'base 500K COP' => [   500_000.0,  55_000.0 ],
            'base 5M COP'   => [ 5_000_000.0, 550_000.0 ],
            'base 100K COP' => [   100_000.0,  11_000.0 ],
        ];
    }

    /**
     * ReteFuente sobre compras (tarifa 2.5%) — art. 383 ET.
     * @dataProvider provider_retefuente_compras
     */
    public function test_retefuente_compras( float $base, float $expected ): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped( 'calculate_retefuente() no implementado aún.' );
        }
        $result = $this->tax_engine->calculate_retefuente( $base, 'compras' );
        $this->assertEqualsWithDelta( $expected, $result, 0.01 );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_retefuente_compras(): array {
        return [
            'base 1M' => [ 1_000_000.0, 25_000.0 ],
            'base 2M' => [ 2_000_000.0, 50_000.0 ],
        ];
    }

    // ── ReteIVA ────────────────────────────────────────────────────────────

    /**
     * ReteIVA = 15% del IVA generado (19% del valor gravado).
     * @dataProvider provider_reteiva
     */
    public function test_reteiva_calculation( float $base_gravable, float $expected_reteiva ): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped( 'calculate_reteiva() no implementado aún.' );
        }
        $result = $this->tax_engine->calculate_reteiva( $base_gravable );
        $this->assertEqualsWithDelta( $expected_reteiva, $result, 0.01,
            "ReteIVA incorrecta para base {$base_gravable}" );
    }

    /** @return array<string, array{float, float}> */
    public static function provider_reteiva(): array {
        return [
            'base 1M'   => [ 1_000_000.0,  28_500.0 ],
            'base 500K' => [   500_000.0,  14_250.0 ],
            'base 10M'  => [10_000_000.0, 285_000.0 ],
        ];
    }

    // ── ReteICA ────────────────────────────────────────────────────────────

    /**
     * ReteICA varía según actividad CIIU.
     * @dataProvider provider_reteica
     */
    public function test_reteica_by_ciiu( string $ciiu, float $base, float $expected ): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped( 'calculate_reteica() no implementado aún.' );
        }
        $result = $this->tax_engine->calculate_reteica( $base, $ciiu );
        $this->assertEqualsWithDelta( $expected, $result, 1.0,
            "ReteICA incorrecta para CIIU {$ciiu}, base {$base}" );
    }

    /** @return array<string, array{string, float, float}> */
    public static function provider_reteica(): array {
        return [
            'comercio 1M'     => [ '4711', 1_000_000.0, 4_140.0 ],
            'software 1M'     => [ '6201', 1_000_000.0, 4_140.0 ],
            'construccion 1M' => [ '4100', 1_000_000.0, 6_900.0 ],
        ];
    }

    // ── Cálculo Total ─────────────────────────────────────────────────────

    public function test_total_retenciones_vendor_servicios(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped( 'calculate_total_retenciones() no implementado aún.' );
        }
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '6201',
            'pais'     => 'CO',
            'iva_rate' => 0.19,
        ] );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'retefuente',  $result );
        $this->assertArrayHasKey( 'reteiva',     $result );
        $this->assertArrayHasKey( 'reteica',     $result );
        $this->assertArrayHasKey( 'total',       $result );
        $this->assertArrayHasKey( 'neto_vendor', $result );

        $expected_neto = 1_000_000.0 - $result['total'];
        $this->assertEqualsWithDelta( $expected_neto, $result['neto_vendor'], 0.01 );
        $this->assertGreaterThan( 0, $result['retefuente'] );
        $this->assertGreaterThan( 0, $result['reteiva'] );
        $this->assertGreaterThan( 0, $result['reteica'] );
        $this->assertGreaterThan( 0, $result['neto_vendor'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // ▼▼▼  ÁNGULOS NUEVOS ▼▼▼
    // ════════════════════════════════════════════════════════════════════════

    // ── calculate_retefuente: tipo 'servicios' (4%) ───────────────────────

    public function test_retefuente_tipo_servicios_4_porciento(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        // servicios → 4% según código fuente
        $base   = 1_000_000.0;
        $result = $this->tax_engine->calculate_retefuente( $base, 'servicios' );
        $this->assertEqualsWithDelta( 40_000.0, $result, 0.01,
            'ReteFuente servicios debe ser 4%' );
    }

    public function test_retefuente_tipo_desconocido_usa_fallback_4_porciento(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        // El código fuente: $tarifa = $tarifas[$tipo] ?? 0.04
        $base   = 2_000_000.0;
        $result = $this->tax_engine->calculate_retefuente( $base, 'tipo_inexistente' );
        $this->assertEqualsWithDelta( 80_000.0, $result, 0.01,
            'Tipo desconocido debe usar fallback 4%' );
    }

    public function test_retefuente_con_base_cero_retorna_cero(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_retefuente( 0.0, 'honorarios' );
        $this->assertEqualsWithDelta( 0.0, $result, 0.001 );
    }

    public function test_retefuente_retorna_float(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_retefuente( 500_000.0, 'compras' );
        $this->assertIsFloat( $result );
    }

    /** @dataProvider provider_retefuente_todas_las_tarifas */
    public function test_retefuente_todas_las_tarifas_son_positivas(
        string $tipo,
        float  $base,
        float  $rate_esperado
    ): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_retefuente( $base, $tipo );
        $expected = round( $base * $rate_esperado, 2 );
        $this->assertEqualsWithDelta( $expected, $result, 0.01,
            "ReteFuente '{$tipo}' incorrecta para base={$base}" );
    }

    /** @return array<string, array{string, float, float}> */
    public static function provider_retefuente_todas_las_tarifas(): array {
        return [
            'honorarios 11%' => [ 'honorarios', 1_000_000.0, 0.11 ],
            'compras 2.5%'   => [ 'compras',    1_000_000.0, 0.025 ],
            'servicios 4%'   => [ 'servicios',  1_000_000.0, 0.04 ],
            'fallback 4%'    => [ 'otro_tipo',  1_000_000.0, 0.04 ],
        ];
    }

    // ── calculate_reteiva: iva_rate custom ───────────────────────────────

    public function test_reteiva_con_iva_rate_custom_16_porciento(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        // base=1M, IVA=16% → reteiva = 1M * 0.16 * 0.15 = 24,000
        $result = $this->tax_engine->calculate_reteiva( 1_000_000.0, 0.16 );
        $this->assertEqualsWithDelta( 24_000.0, $result, 0.01,
            'ReteIVA con tasa 16% debe ser base * 0.16 * 0.15' );
    }

    public function test_reteiva_con_iva_rate_cero_retorna_cero(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        // Producto exento: IVA=0% → ReteIVA = 0
        $result = $this->tax_engine->calculate_reteiva( 1_000_000.0, 0.0 );
        $this->assertEqualsWithDelta( 0.0, $result, 0.001 );
    }

    public function test_reteiva_con_base_cero_retorna_cero(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_reteiva( 0.0 );
        $this->assertEqualsWithDelta( 0.0, $result, 0.001 );
    }

    public function test_reteiva_retorna_float(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_reteiva( 500_000.0 );
        $this->assertIsFloat( $result );
    }

    public function test_reteiva_formula_es_base_por_iva_rate_por_015(): void {
        // Verifica la fórmula: round(base * iva_rate * 0.15, 2)
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        $base     = 3_000_000.0;
        $iva_rate = 0.19;
        $expected = round( $base * $iva_rate * 0.15, 2 ); // 85,500
        $result   = $this->tax_engine->calculate_reteiva( $base, $iva_rate );
        $this->assertEqualsWithDelta( $expected, $result, 0.01 );
    }

    // ── calculate_reteica: tarifas especiales ────────────────────────────

    public function test_reteica_ciiu_4100_tarifa_especifica_0069(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        // El código fuente tiene: '4100' => 0.0069 (tarifa especial)
        $base   = 1_000_000.0;
        $result = $this->tax_engine->calculate_reteica( $base, '4100' );
        $this->assertEqualsWithDelta( 6_900.0, $result, 1.0,
            'CIIU 4100 tiene tarifa especial 0.0069 (6.9‰)' );
    }

    public function test_reteica_ciiu_vacio_usa_fallback_00414(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        $base   = 1_000_000.0;
        $result = $this->tax_engine->calculate_reteica( $base, '' );
        $this->assertEqualsWithDelta( 4_140.0, $result, 1.0,
            'CIIU vacío debe usar fallback 0.00414' );
    }

    public function test_reteica_ciiu_desconocido_usa_fallback_00414(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        $base   = 2_000_000.0;
        $result = $this->tax_engine->calculate_reteica( $base, '9999' );
        $this->assertEqualsWithDelta( 8_280.0, $result, 1.0,
            'CIIU desconocido debe usar fallback 0.00414' );
    }

    public function test_reteica_con_base_cero_retorna_cero(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_reteica( 0.0, '4711' );
        $this->assertEqualsWithDelta( 0.0, $result, 0.001 );
    }

    public function test_reteica_retorna_float(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_reteica( 500_000.0, '4711' );
        $this->assertIsFloat( $result );
    }

    // ── calculate_total_retenciones: ángulos nuevos ──────────────────────

    public function test_total_retenciones_tipo_compras(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $base   = 1_000_000.0;
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => $base,
            'tipo'     => 'compras',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        // compras → 2.5%
        $expected_retefte = round( $base * 0.025, 2 ); // 25,000
        $this->assertEqualsWithDelta( $expected_retefte, $result['retefuente'], 0.01,
            'Tipo compras → 2.5% ReteFuente' );
    }

    public function test_total_retenciones_tipo_servicios(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $base   = 500_000.0;
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => $base,
            'tipo'     => 'servicios',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        $expected_retefte = round( $base * 0.04, 2 ); // 20,000
        $this->assertEqualsWithDelta( $expected_retefte, $result['retefuente'], 0.01,
            'Tipo servicios → 4% ReteFuente' );
    }

    public function test_total_retenciones_base_un_peso(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => 1.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        $this->assertIsArray( $result );
        // Con base 1 COP, neto_vendor debe ser <= 1.0
        $this->assertLessThanOrEqual( 1.0, $result['neto_vendor'] );
        // Total de retenciones no puede superar la base
        $this->assertLessThanOrEqual( 1.0 + 0.01, $result['total'] );
    }

    public function test_total_retenciones_todos_los_campos_son_float(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => 1_000_000.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        foreach ( ['retefuente', 'reteiva', 'reteica', 'total', 'neto_vendor'] as $key ) {
            $this->assertIsFloat( $result[ $key ],
                "El campo '{$key}' debe ser float" );
        }
    }

    public function test_total_retenciones_invariante_neto_es_base_menos_total(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $base   = 3_000_000.0;
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => $base,
            'tipo'     => 'honorarios',
            'ciiu'     => '6201',
            'iva_rate' => 0.19,
        ] );

        $expected_neto = round( $base - $result['total'], 2 );
        $this->assertEqualsWithDelta( $expected_neto, $result['neto_vendor'], 0.01,
            'neto_vendor debe ser exactamente base - total' );
    }

    public function test_total_retenciones_total_es_suma_de_tres_retenciones(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => 2_000_000.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '4711',
            'iva_rate' => 0.19,
        ] );

        $expected_total = round(
            $result['retefuente'] + $result['reteiva'] + $result['reteica'],
            2
        );
        $this->assertEqualsWithDelta( $expected_total, $result['total'], 0.01,
            'total = retefuente + reteiva + reteica' );
    }

    public function test_total_retenciones_sin_params_usa_defaults(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        // Sin parámetros: base=0, tipo='servicios', ciiu='4711', iva_rate=0.19
        $result = $this->tax_engine->calculate_total_retenciones( [] );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'neto_vendor', $result );
        // Con base 0, todas las retenciones deben ser 0
        $this->assertEqualsWithDelta( 0.0, $result['retefuente'], 0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['reteiva'],    0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['reteica'],    0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['total'],      0.001 );
        $this->assertEqualsWithDelta( 0.0, $result['neto_vendor'],0.001 );
    }

    public function test_total_retenciones_neto_vendor_no_negativo(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $result = $this->tax_engine->calculate_total_retenciones( [
            'base'     => 100_000.0,
            'tipo'     => 'honorarios',
            'ciiu'     => '6201',
            'iva_rate' => 0.19,
        ] );
        // Las retenciones nunca deben superar la base en un sistema legal
        $this->assertGreaterThanOrEqual( 0.0, $result['neto_vendor'],
            'neto_vendor no puede ser negativo' );
    }

    // ── Reflexión: métodos de instancia ──────────────────────────────────

    public function test_calculate_retefuente_es_metodo_publico_de_instancia(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_retefuente' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_calculate_reteiva_es_metodo_publico_de_instancia(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_reteiva' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_calculate_reteica_es_metodo_publico_de_instancia(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_reteica' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_calculate_total_retenciones_es_metodo_publico_de_instancia(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_total_retenciones' );
        $this->assertTrue( $rm->isPublic() );
        $this->assertFalse( $rm->isStatic() );
    }

    public function test_calculate_retefuente_retorna_tipo_float(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_retefuente' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'float', (string) $rt );
    }

    public function test_calculate_total_retenciones_retorna_tipo_array(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_total_retenciones' ) ) {
            $this->markTestSkipped();
        }
        $rm = new \ReflectionMethod( $this->tax_engine, 'calculate_total_retenciones' );
        $rt = $rm->getReturnType();
        $this->assertNotNull( $rt );
        $this->assertStringContainsString( 'array', (string) $rt );
    }

    // ── Invariantes matemáticos ────────────────────────────────────────────

    public function test_retefuente_honorarios_mayor_que_compras_misma_base(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_retefuente' ) ) {
            $this->markTestSkipped();
        }
        $base = 1_000_000.0;
        $hon  = $this->tax_engine->calculate_retefuente( $base, 'honorarios' ); // 11%
        $comp = $this->tax_engine->calculate_retefuente( $base, 'compras' );    // 2.5%
        $this->assertGreaterThan( $comp, $hon,
            'Honorarios (11%) debe superar compras (2.5%)' );
    }

    public function test_reteiva_crece_proporcionalmente_con_la_base(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteiva' ) ) {
            $this->markTestSkipped();
        }
        $base1 = 1_000_000.0;
        $base2 = 2_000_000.0;
        $rv1   = $this->tax_engine->calculate_reteiva( $base1 );
        $rv2   = $this->tax_engine->calculate_reteiva( $base2 );
        // Debe ser aproximadamente el doble
        $this->assertEqualsWithDelta( $rv1 * 2, $rv2, 0.05 );
    }

    public function test_reteica_no_negativo_para_cualquier_ciiu(): void {
        if ( ! method_exists( $this->tax_engine, 'calculate_reteica' ) ) {
            $this->markTestSkipped();
        }
        foreach ( ['4711', '4100', '6201', '', '9999', 'AAAA'] as $ciiu ) {
            $result = $this->tax_engine->calculate_reteica( 1_000_000.0, $ciiu );
            $this->assertGreaterThanOrEqual( 0.0, $result,
                "ReteICA no puede ser negativa para CIIU '{$ciiu}'" );
        }
    }

    // ── Stub ─────────────────────────────────────────────────────────────

    private function create_tax_engine_stub(): object {
        return new class {
            // Sin métodos → tests se saltean con markTestSkipped
        };
    }
}
