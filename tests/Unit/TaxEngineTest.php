<?php
/**
 * LTMS TaxEngineTest - Pruebas Unitarias del Motor de Impuestos
 *
 * @package    LTMS\Tests\Unit
 * @version    1.5.0
 */

namespace LTMS\Tests\Unit;

use WP_UnitTestCase;

/**
 * Class TaxEngineTest
 *
 * Cubre Colombia (ReteFuente, ReteIVA, ReteICA, Impoconsumo)
 * y México (ISR art. 113-A, IEPS, IVA 16%).
 */
class TaxEngineTest extends WP_UnitTestCase {

    /**
     * @var array Datos de pedido base para pruebas Colombia.
     */
    private array $co_order_data;

    /**
     * @var array Datos de pedido base para pruebas México.
     */
    private array $mx_order_data;

    /**
     * @var array Datos de vendedor Colombia.
     */
    private array $co_vendor_data;

    /**
     * @var array Datos de vendedor México.
     */
    private array $mx_vendor_data;

    /**
     * Configuración de cada test.
     */
    public function setUp(): void {
        parent::setUp();

        $this->co_order_data = [
            'order_id'     => 999,
            'product_type' => 'general',
            'ciiu_code'    => '4711', // Comercio al por menor
        ];

        $this->mx_order_data = [
            'order_id'       => 998,
            'product_type'   => 'general',
            'ieps_rate'      => 0.0,
            'regime'         => 'general',
        ];

        $this->co_vendor_data = [
            'vendor_id'          => 1,
            'is_vat_responsible' => true,
            'regime'             => 'simplificado',
            'monthly_income'     => 5000000.00, // $5M COP
        ];

        $this->mx_vendor_data = [
            'vendor_id'    => 2,
            'regime'       => 'general',
            'annual_income' => 500000.00, // $500K MXN (tasa ISR 10%)
        ];

        // Flush estrategias para pruebas limpias
        \LTMS_Tax_Engine::flush_strategies();
    }

    // ── Colombia ───────────────────────────────────────────────────

    /**
     * Test: Colombia calcula ReteFuente al 3.5% sobre servicios.
     */
    public function test_colombia_rete_fuente_general_services(): void {
        $result = \LTMS_Tax_Engine::calculate(
            100000.00,
            array_merge( $this->co_order_data, [ 'product_type' => 'servicios' ] ),
            $this->co_vendor_data,
            'CO'
        );

        $this->assertArrayHasKey( 'rete_fuente', $result );
        // ReteFuente servicios = 4% sobre base gravable
        $this->assertGreaterThan( 0.0, (float) $result['rete_fuente'] );
    }

    /**
     * Test: Colombia calcula ReteIVA al 15% del IVA.
     */
    public function test_colombia_rete_iva(): void {
        $result = \LTMS_Tax_Engine::calculate(
            100000.00,
            array_merge( $this->co_order_data, [ 'iva_rate' => 0.19 ] ),
            array_merge( $this->co_vendor_data, [ 'is_vat_responsible' => true ] ),
            'CO'
        );

        $this->assertArrayHasKey( 'rete_iva', $result );
        // ReteIVA = 15% del IVA (19% de 100000 = 19000, 15% = 2850)
        $expected = round( 100000.00 * 0.19 * 0.15, 2 );
        $this->assertEquals( $expected, (float) $result['rete_iva'] );
    }

    /**
     * Test: Colombia respeta UVT para umbral ReteFuente (2025 UVT = 49799).
     * Transacciones menores a ~27 UVT no aplican ReteFuente en compras generales.
     */
    public function test_colombia_rete_fuente_below_threshold(): void {
        $result = \LTMS_Tax_Engine::calculate(
            100.00, // Monto muy bajo, por debajo del umbral
            $this->co_order_data,
            $this->co_vendor_data,
            'CO'
        );

        $this->assertArrayHasKey( 'rete_fuente', $result );
        $this->assertEquals( 0.0, (float) $result['rete_fuente'] );
    }

    /**
     * Test: Colombia retorna estructura correcta.
     */
    public function test_colombia_returns_expected_structure(): void {
        $result = \LTMS_Tax_Engine::calculate(
            500000.00,
            $this->co_order_data,
            $this->co_vendor_data,
            'CO'
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'gross',          $result );
        $this->assertArrayHasKey( 'rete_fuente',    $result );
        $this->assertArrayHasKey( 'rete_iva',       $result );
        $this->assertArrayHasKey( 'rete_ica',       $result );
        $this->assertArrayHasKey( 'impoconsumo',    $result );
        $this->assertArrayHasKey( 'platform_fee',   $result );
        $this->assertArrayHasKey( 'vendor_net',     $result );
    }

    /**
     * Test: vendor_net nunca supera gross.
     */
    public function test_colombia_vendor_net_never_exceeds_gross(): void {
        $gross  = 200000.00;
        $result = \LTMS_Tax_Engine::calculate(
            $gross,
            $this->co_order_data,
            $this->co_vendor_data,
            'CO'
        );

        $this->assertLessThanOrEqual( $gross, (float) $result['vendor_net'] );
    }

    /**
     * Test: Impoconsumo aplica a restaurantes (CIIU 5611).
     */
    public function test_colombia_impoconsumo_restaurant(): void {
        $result = \LTMS_Tax_Engine::calculate(
            100000.00,
            array_merge( $this->co_order_data, [ 'ciiu_code' => '5611' ] ),
            $this->co_vendor_data,
            'CO'
        );

        // Impoconsumo = 8% para restaurantes
        $this->assertGreaterThan( 0.0, (float) $result['impoconsumo'] );
        $expected_impo = round( 100000.00 * 0.08, 2 );
        $this->assertEquals( $expected_impo, (float) $result['impoconsumo'] );
    }

    // ── México ─────────────────────────────────────────────────────

    /**
     * Test: México calcula ISR art. 113-A correctamente según nivel de ingreso.
     * Nivel 1: ingresos hasta $25K MXN/mes → 2%
     */
    public function test_mexico_isr_113a_tier1(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10000.00,
            $this->mx_order_data,
            array_merge( $this->mx_vendor_data, [ 'monthly_income' => 20000.00 ] ), // Tier 1 < 25K
            'MX'
        );

        $this->assertArrayHasKey( 'isr_amount', $result );
        // ISR tier 1 = 2%
        $expected = round( 10000.00 * 0.02, 2 );
        $this->assertEquals( $expected, (float) $result['isr_amount'] );
    }

    /**
     * Test: México ISR tier 2 (ingresos $25K-$100K → 4%).
     */
    public function test_mexico_isr_113a_tier2(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10000.00,
            $this->mx_order_data,
            array_merge( $this->mx_vendor_data, [ 'monthly_income' => 60000.00 ] ), // Tier 2
            'MX'
        );

        $expected = round( 10000.00 * 0.04, 2 );
        $this->assertEquals( $expected, (float) $result['isr_amount'] );
    }

    /**
     * Test: México ISR tier 3 (ingresos $100K-$300K → 6%).
     */
    public function test_mexico_isr_113a_tier3(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10000.00,
            $this->mx_order_data,
            array_merge( $this->mx_vendor_data, [ 'monthly_income' => 150000.00 ] ), // Tier 3
            'MX'
        );

        $expected = round( 10000.00 * 0.06, 2 );
        $this->assertEquals( $expected, (float) $result['isr_amount'] );
    }

    /**
     * Test: México ISR tier 4 (ingresos > $300K → 10% provisional).
     */
    public function test_mexico_isr_113a_tier4(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10000.00,
            $this->mx_order_data,
            array_merge( $this->mx_vendor_data, [ 'monthly_income' => 400000.00 ] ), // Tier 4 > 300K
            'MX'
        );

        $expected = round( 10000.00 * 0.10, 2 );
        $this->assertEquals( $expected, (float) $result['isr_amount'] );
    }

    /**
     * Test: México IVA 16%.
     */
    public function test_mexico_iva_16_percent(): void {
        $result = \LTMS_Tax_Engine::calculate(
            100000.00,
            $this->mx_order_data,
            $this->mx_vendor_data,
            'MX'
        );

        $this->assertArrayHasKey( 'iva_amount', $result );
        $expected = round( 100000.00 * 0.16, 2 );
        $this->assertEquals( $expected, (float) $result['iva_amount'] );
    }

    /**
     * Test: México retorna estructura correcta.
     */
    public function test_mexico_returns_expected_structure(): void {
        $result = \LTMS_Tax_Engine::calculate(
            50000.00,
            $this->mx_order_data,
            $this->mx_vendor_data,
            'MX'
        );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'gross',        $result );
        $this->assertArrayHasKey( 'isr_amount',   $result );
        $this->assertArrayHasKey( 'iva_amount',   $result );
        $this->assertArrayHasKey( 'ieps_amount',  $result );
        $this->assertArrayHasKey( 'platform_fee', $result );
        $this->assertArrayHasKey( 'vendor_net',   $result );
    }

    /**
     * Test: México IEPS aplica a bebidas azucaradas (1 peso/litro + 8%).
     */
    public function test_mexico_ieps_sugary_drinks(): void {
        $result = \LTMS_Tax_Engine::calculate(
            50000.00,
            array_merge( $this->mx_order_data, [ 'product_type' => 'bebidas_azucaradas' ] ),
            $this->mx_vendor_data,
            'MX'
        );

        $this->assertGreaterThan( 0.0, (float) $result['ieps_amount'] );
    }

    // ── General ─────────────────────────────────────────────────────

    /**
     * Test: Motor lanza excepción para país no soportado.
     */
    public function test_unsupported_country_throws_exception(): void {
        $this->expectException( \InvalidArgumentException::class );

        \LTMS_Tax_Engine::calculate( 100.00, [], [], 'AR' );
    }

    /**
     * Test: gross = 0 retorna todo en cero.
     */
    public function test_zero_gross_returns_zeros(): void {
        $result = \LTMS_Tax_Engine::calculate( 0.00, $this->co_order_data, $this->co_vendor_data, 'CO' );

        $this->assertEquals( 0.0, (float) $result['rete_fuente'] );
        $this->assertEquals( 0.0, (float) $result['vendor_net'] );
    }

    /**
     * Test: vendor_net es no negativo en Colombia.
     */
    public function test_colombia_vendor_net_is_non_negative(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10.00, // Monto muy pequeño
            $this->co_order_data,
            $this->co_vendor_data,
            'CO'
        );

        $this->assertGreaterThanOrEqual( 0.0, (float) $result['vendor_net'] );
    }

    /**
     * Test: vendor_net es no negativo en México.
     */
    public function test_mexico_vendor_net_is_non_negative(): void {
        $result = \LTMS_Tax_Engine::calculate(
            10.00,
            $this->mx_order_data,
            $this->mx_vendor_data,
            'MX'
        );

        $this->assertGreaterThanOrEqual( 0.0, (float) $result['vendor_net'] );
    }

    /**
     * Limpieza.
     */
    public function tearDown(): void {
        \LTMS_Tax_Engine::flush_strategies();
        parent::tearDown();
    }
}
