<?php
/**
 * Tests unitarios — LTMS_Tax_Strategy_Colombia
 *
 * Verifica los cálculos matemáticos de impuestos colombianos:
 *   - IVA  (19% general, 5% reducido, 0% exento)
 *   - ReteFuente por tipo de actividad y umbrales UVT (art. 383 ET)
 *   - ReteIVA (15% del IVA para grandes contribuyentes)
 *   - ReteICA (0.414% - 0.966% según prefijo CIIU)
 *   - Impoconsumo / INC 8% (restaurantes, bares — Ley 2010/2019)
 *   - net_to_vendor = gross − total_withholding
 *
 * Tests PUROS de matemáticas de negocio.
 * NO requieren base de datos ni WordPress.
 *
 * Base legal: Estatuto Tributario, DIAN Circular 0087/2022, Decreto 1430/2023.
 *
 * ÁNGULOS NUEVOS añadidos en esta versión (sobre los 40 originales):
 *
 * SECCIÓN 9  — Boundary exacto de umbrales UVT
 *   - honorarios: gross == 1 UVT exacto → aplica (boundary inclusivo)
 *   - honorarios: gross == 1 UVT - 0.01 → no aplica
 *   - compras: gross == umbral exacto → aplica
 *   - compras: gross == umbral - 0.01 → no aplica
 *   - servicios tech: gross == umbral exacto → aplica
 *   - product 'product' (alias de physical) → tasa compras 2.5%
 *
 * SECCIÓN 10 — ReteICA: todos los prefijos numéricos + CIIU de 2 dígitos
 *   - CIIU de 1 caracter (prefijo 4 directamente)
 *   - CIIU de 2 caracteres (prefijo 4)
 *   - CIIU de 5 caracteres (prefijo 9 → 6.9‰)
 *   - Prefijo desconocido (letra) → fallback 4.14‰
 *   - CIIU con solo un dígito '4' → rate 4.14‰
 *   - Todos los prefijos 4–9 confirmados con rate exacto
 *
 * SECCIÓN 11 — Impoconsumo: total_taxes incluye INC pero no ReteFuente
 *   - total_taxes para restaurant = iva + inc (no cero aunque IVA=0)
 *   - food_service sin IVA especial: total_taxes = IVA_general + INC
 *   - impoconsumo es 0 para product_type desconocido
 *
 * SECCIÓN 12 — ReteIVA con IVA reducido (5%)
 *   - gran contribuyente + café (IVA 5%) → reteiva = 15% de (gross * 5%)
 *   - reteiva_rate sigue siendo 0.15 aunque el IVA base sea reducido
 *
 * SECCIÓN 13 — Régimen especial y gran_contribuyente como vendedor
 *   - special: aplica ReteFuente honorarios
 *   - gran_contribuyente como vendedor: aplica ReteFuente
 *   - should_apply_withholding con key ausente → false (default simplified)
 *
 * SECCIÓN 14 — Cálculo integral avanzado
 *   - physical + gran contribuyente + CIIU 4xxx: todos los impuestos calculados
 *   - restaurant + common + sin gran contribuyente: INC + ReteICA, sin ReteIVA
 *   - software + simplified + CIIU 6xxx: solo ReteICA, sin ReteFuente
 *   - Cálculo de 1 peso COP: todos los campos ≥ 0 y tipos correctos
 *
 * SECCIÓN 15 — Reflexión e invariantes de tipos
 *   - calculate() siempre retorna float en todos los campos numéricos
 *   - platform_fee siempre es 0.0
 *   - retefuente_rate nunca excede 0.11 (tasa máxima honorarios)
 *   - reteica_rate siempre está en el rango [0.00414, 0.00966]
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class TaxStrategyColombiaTest
 */
class TaxStrategyColombiaTest extends LTMS_Unit_Test_Case {

	/** @var \LTMS_Tax_Strategy_Colombia */
	private object $strategy;

	// ── Tolerancia monetaria (pesos colombianos) ──────────────────────────────
	private const DELTA = 0.05;

	// ── Tasas default hardcodeadas en la clase ────────────────────────────────
	private const UVT                   = 49799.0;
	private const IVA_GENERAL           = 0.19;
	private const IVA_REDUCIDO          = 0.05;
	private const RETEFUENTE_HONORARIOS = 0.11;
	private const RETEFUENTE_SERVICIOS  = 0.04;
	private const RETEFUENTE_COMPRAS    = 0.025;
	private const RETEFUENTE_TECH       = 0.06;
	private const RETEIVA_RATE          = 0.15;
	private const IMPOCONSUMO_RATE      = 0.08;
	private const MIN_COMPRAS           = self::UVT * 10.666;   // ≈ 531,156 COP
	private const MIN_SERVICIOS         = self::UVT * 2.666;    // ≈ 132,764 COP
	private const MIN_HONORARIOS        = self::UVT * 1.0;      // ≈  49,799 COP

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'LTMS_Tax_Strategy_Colombia' ) ) {
			$this->markTestSkipped( 'LTMS_Tax_Strategy_Colombia no disponible.' );
		}

		\LTMS_Core_Config::flush_cache();
		$this->strategy = new \LTMS_Tax_Strategy_Colombia();
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 1 — Contrato de la interfaz
	// ══════════════════════════════════════════════════════════════════════════

	public function test_implementa_interfaz_tax_strategy(): void {
		$this->assertInstanceOf(
			\LTMS_Tax_Strategy_Interface::class,
			$this->strategy,
			'Debe implementar LTMS_Tax_Strategy_Interface'
		);
	}

	public function test_get_country_code_retorna_CO(): void {
		$this->assertSame( 'CO', $this->strategy->get_country_code() );
	}

	public function test_calculate_retorna_array_con_claves_requeridas(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [] );

		$claves = [
			'gross', 'iva', 'iva_rate',
			'retefuente', 'retefuente_rate',
			'reteiva', 'reteiva_rate',
			'reteica', 'reteica_rate',
			'impoconsumo', 'impoconsumo_rate',
			'isr', 'isr_rate',
			'ieps', 'ieps_rate',
			'total_taxes', 'total_withholding', 'net_to_vendor',
			'strategy', 'country', 'currency', 'uvt_value',
		];

		foreach ( $claves as $clave ) {
			$this->assertArrayHasKey( $clave, $result, "Falta la clave '{$clave}' en el resultado" );
		}
	}

	public function test_calculate_country_es_CO(): void {
		$result = $this->strategy->calculate( 500_000.0, [], [] );
		$this->assertSame( 'CO', $result['country'] );
	}

	public function test_calculate_currency_es_COP(): void {
		$result = $this->strategy->calculate( 500_000.0, [], [] );
		$this->assertSame( 'COP', $result['currency'] );
	}

	public function test_gross_se_preserva_en_resultado(): void {
		$result = $this->strategy->calculate( 987654.32, [], [] );
		$this->assertEqualsWithDelta( 987654.32, $result['gross'], self::DELTA );
	}

	public function test_isr_y_ieps_son_cero_en_colombia(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [] );
		$this->assertEqualsWithDelta( 0.0, $result['isr'],       self::DELTA, 'ISR debe ser 0 en CO' );
		$this->assertEqualsWithDelta( 0.0, $result['isr_rate'],  0.0001 );
		$this->assertEqualsWithDelta( 0.0, $result['ieps'],      self::DELTA, 'IEPS debe ser 0 en CO' );
		$this->assertEqualsWithDelta( 0.0, $result['ieps_rate'], 0.0001 );
	}

	public function test_uvt_value_en_resultado(): void {
		$result = $this->strategy->calculate( 100_000.0, [], [] );
		$this->assertEqualsWithDelta( self::UVT, $result['uvt_value'], 0.01 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 2 — IVA (Impuesto al Valor Agregado)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * IVA general 19% para productos físicos estándar.
	 *
	 * @dataProvider provider_iva_general
	 */
	public function test_iva_general_19_porciento( float $gross, float $iva_esperado ): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'physical' ],
			[]
		);

		$this->assertEqualsWithDelta(
			$iva_esperado,
			$result['iva'],
			self::DELTA,
			"IVA 19% incorrecto para gross={$gross}"
		);
		$this->assertEqualsWithDelta( self::IVA_GENERAL, $result['iva_rate'], 0.001 );
	}

	/** @return array<string, array{float, float}> */
	public static function provider_iva_general(): array {
		return [
			'$500K COP'   => [   500_000.0,   95_000.0 ],
			'$1M COP'     => [ 1_000_000.0,  190_000.0 ],
			'$5M COP'     => [ 5_000_000.0,  950_000.0 ],
			'$100K COP'   => [   100_000.0,   19_000.0 ],
			'$1 COP'      => [         1.0,       0.19 ],
		];
	}

	/**
	 * IVA reducido 5% para productos como café, cacao, huevos al por menor.
	 *
	 * @dataProvider provider_iva_reducido
	 */
	public function test_iva_reducido_5_porciento( string $product_type, float $gross, float $iva_esperado ): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => $product_type ],
			[]
		);

		$this->assertEqualsWithDelta(
			$iva_esperado,
			$result['iva'],
			self::DELTA,
			"IVA 5% incorrecto para '{$product_type}', gross={$gross}"
		);
		$this->assertEqualsWithDelta( self::IVA_REDUCIDO, $result['iva_rate'], 0.001 );
	}

	/** @return array<string, array{string, float, float}> */
	public static function provider_iva_reducido(): array {
		return [
			'café 1M'          => [ 'coffee',                1_000_000.0,  50_000.0 ],
			'cacao 2M'         => [ 'cacao',                 2_000_000.0, 100_000.0 ],
			'huevos 500K'      => [ 'eggs_retail',             500_000.0,  25_000.0 ],
			'útiles sanit 1M'  => [ 'sanitary_supplies',     1_000_000.0,  50_000.0 ],
			'maquinaria agr'   => [ 'agricultural_machinery', 3_000_000.0, 150_000.0 ],
		];
	}

	/**
	 * IVA 0% para bienes y servicios exentos (art. 424 ET).
	 *
	 * @dataProvider provider_iva_cero
	 */
	public function test_iva_cero_bienes_exentos( string $product_type ): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[ 'product_type' => $product_type ],
			[]
		);

		$this->assertEqualsWithDelta(
			0.0,
			$result['iva'],
			self::DELTA,
			"Producto '{$product_type}' debe tener IVA = 0"
		);
		$this->assertEqualsWithDelta( 0.0, $result['iva_rate'], 0.001 );
	}

	/** @return array<string, array{string}> */
	public static function provider_iva_cero(): array {
		return [
			'alimentos básicos' => [ 'basic_food'         ],
			'medicamentos'      => [ 'medicine'            ],
			'servicios salud'   => [ 'health_service'      ],
			'educación'         => [ 'education'           ],
			'agropecuario básico'=> [ 'agricultural_basic' ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 3 — ReteFuente (Retención en la Fuente)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Régimen simplificado NO aplica ReteFuente.
	 */
	public function test_retefuente_cero_para_regimen_simplificado(): void {
		$result = $this->strategy->calculate(
			5_000_000.0,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime' => 'simplified' ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'],      self::DELTA );
		$this->assertEqualsWithDelta( 0.0, $result['retefuente_rate'], 0.001 );
	}

	/**
	 * Honorarios y servicios profesionales — 11% cuando gross ≥ 1 UVT.
	 *
	 * @dataProvider provider_retefuente_honorarios
	 */
	public function test_retefuente_honorarios_11_porciento(
		float $gross,
		float $retefuente_esperado
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta(
			$retefuente_esperado,
			$result['retefuente'],
			self::DELTA,
			"ReteFuente honorarios 11% incorrecta para gross={$gross}"
		);
		$this->assertEqualsWithDelta( self::RETEFUENTE_HONORARIOS, $result['retefuente_rate'], 0.001 );
	}

	/** @return array<string, array{float, float}> */
	public static function provider_retefuente_honorarios(): array {
		// Monto justo encima del umbral 1 UVT (≈49,799 COP)
		$sobre_umbral = (int) ceil( self::MIN_HONORARIOS ) + 1;
		return [
			'justo sobre umbral'    => [ (float) $sobre_umbral, round( $sobre_umbral * 0.11, 2 ) ],
			'$1M COP honorarios'    => [ 1_000_000.0,  110_000.0 ],
			'$5M COP honorarios'    => [ 5_000_000.0,  550_000.0 ],
			'freelance 2M'          => [ 2_000_000.0,  220_000.0 ],
		];
	}

	/**
	 * Honorarios bajo el umbral de 1 UVT → ReteFuente = 0.
	 */
	public function test_retefuente_honorarios_cero_bajo_umbral(): void {
		$bajo_umbral = self::MIN_HONORARIOS - 1.0;

		$result = $this->strategy->calculate(
			$bajo_umbral,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA );
	}

	/**
	 * Servicios tech / SaaS — 6% cuando gross ≥ umbral servicios (~132,764 COP).
	 *
	 * @dataProvider provider_retefuente_tech
	 */
	public function test_retefuente_tech_6_porciento(
		string $product_type,
		float $gross,
		float $retefuente_esperado
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => $product_type ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta(
			$retefuente_esperado,
			$result['retefuente'],
			self::DELTA,
			"ReteFuente tech 6% incorrecta para '{$product_type}', gross={$gross}"
		);
		$this->assertEqualsWithDelta( self::RETEFUENTE_TECH, $result['retefuente_rate'], 0.001 );
	}

	/** @return array<string, array{string, float, float}> */
	public static function provider_retefuente_tech(): array {
		$sobre = (int) ceil( self::MIN_SERVICIOS ) + 1;
		return [
			'software 1M'         => [ 'software',        1_000_000.0,  60_000.0 ],
			'digital_service 2M'  => [ 'digital_service', 2_000_000.0, 120_000.0 ],
			'saas justo umbral'   => [ 'saas',             (float) $sobre, round( $sobre * 0.06, 2 ) ],
			'tech_service 500K'   => [ 'tech_service',      500_000.0,  30_000.0 ],
		];
	}

	/**
	 * Servicios tech bajo umbral → ReteFuente = 0.
	 */
	public function test_retefuente_tech_cero_bajo_umbral(): void {
		$bajo_umbral = self::MIN_SERVICIOS - 1.0;

		$result = $this->strategy->calculate(
			$bajo_umbral,
			[ 'product_type' => 'saas' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA );
	}

	/**
	 * Compras de productos físicos — 2.5% cuando gross ≥ umbral compras (~531,156 COP).
	 *
	 * @dataProvider provider_retefuente_compras
	 */
	public function test_retefuente_compras_2_5_porciento(
		float $gross,
		float $retefuente_esperado
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'physical' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta(
			$retefuente_esperado,
			$result['retefuente'],
			self::DELTA,
			"ReteFuente compras 2.5% incorrecta para gross={$gross}"
		);
		$this->assertEqualsWithDelta( self::RETEFUENTE_COMPRAS, $result['retefuente_rate'], 0.001 );
	}

	/** @return array<string, array{float, float}> */
	public static function provider_retefuente_compras(): array {
		$sobre = (int) ceil( self::MIN_COMPRAS ) + 1;
		return [
			'justo sobre umbral'     => [ (float) $sobre, round( $sobre * 0.025, 2 ) ],
			'$1M COP producto'       => [ 1_000_000.0, 25_000.0 ],
			'$10M COP producto'      => [10_000_000.0, 250_000.0 ],
		];
	}

	/**
	 * Compras bajo umbral → ReteFuente = 0.
	 */
	public function test_retefuente_compras_cero_bajo_umbral(): void {
		$bajo_umbral = self::MIN_COMPRAS - 1.0;

		$result = $this->strategy->calculate(
			$bajo_umbral,
			[ 'product_type' => 'physical' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA );
	}

	/**
	 * Servicios generales — 4% cuando gross ≥ umbral servicios.
	 */
	public function test_retefuente_servicios_generales_4_porciento(): void {
		$gross  = 500_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'general' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta(
			round( $gross * self::RETEFUENTE_SERVICIOS, 2 ),
			$result['retefuente'],
			self::DELTA
		);
		$this->assertEqualsWithDelta( self::RETEFUENTE_SERVICIOS, $result['retefuente_rate'], 0.001 );
	}

	/**
	 * Regímenes que aplican ReteFuente.
	 *
	 * @dataProvider provider_regimenes_con_retefuente
	 */
	public function test_should_apply_withholding_true( string $regime ): void {
		$this->assertTrue(
			$this->strategy->should_apply_withholding( [ 'tax_regime' => $regime ] ),
			"Régimen '{$regime}' debe aplicar ReteFuente"
		);
	}

	/** @return array<string, array{string}> */
	public static function provider_regimenes_con_retefuente(): array {
		return [
			'common'              => [ 'common'              ],
			'special'             => [ 'special'             ],
			'gran_contribuyente'  => [ 'gran_contribuyente'  ],
		];
	}

	/**
	 * Regímenes que NO aplican ReteFuente.
	 *
	 * @dataProvider provider_regimenes_sin_retefuente
	 */
	public function test_should_apply_withholding_false( string $regime ): void {
		$this->assertFalse(
			$this->strategy->should_apply_withholding( [ 'tax_regime' => $regime ] ),
			"Régimen '{$regime}' NO debe aplicar ReteFuente"
		);
	}

	/** @return array<string, array{string}> */
	public static function provider_regimenes_sin_retefuente(): array {
		return [
			'simplified' => [ 'simplified' ],
			'vacío'      => [ ''            ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 4 — ReteIVA (solo gran contribuyente)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * ReteIVA = 15% del IVA generado (solo buyer_is_gran_contribuyente=true).
	 *
	 * @dataProvider provider_reteiva
	 */
	public function test_reteiva_gran_contribuyente( float $gross, float $reteiva_esperado ): void {
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'physical',
				'buyer_is_gran_contribuyente' => true,
			],
			[]
		);

		$iva_generado = round( $gross * self::IVA_GENERAL, 2 );
		$this->assertEqualsWithDelta(
			$reteiva_esperado,
			$result['reteiva'],
			self::DELTA,
			"ReteIVA incorrecta para gross={$gross} (IVA generado={$iva_generado})"
		);
		$this->assertEqualsWithDelta( self::RETEIVA_RATE, $result['reteiva_rate'], 0.001 );
	}

	/** @return array<string, array{float, float}> */
	public static function provider_reteiva(): array {
		// reteiva = gross × 0.19 × 0.15
		return [
			'$1M COP'   => [ 1_000_000.0,  28_500.0 ],  // 190_000 × 0.15
			'$500K COP' => [   500_000.0,  14_250.0 ],
			'$5M COP'   => [ 5_000_000.0, 142_500.0 ],
		];
	}

	/**
	 * Sin buyer gran contribuyente → ReteIVA = 0.
	 */
	public function test_reteiva_cero_sin_gran_contribuyente(): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => false ],
			[]
		);

		$this->assertEqualsWithDelta( 0.0, $result['reteiva'],      self::DELTA );
		$this->assertEqualsWithDelta( 0.0, $result['reteiva_rate'], 0.001 );
	}

	/**
	 * Producto exento de IVA → ReteIVA = 0 aunque sea gran contribuyente.
	 */
	public function test_reteiva_cero_cuando_no_hay_iva(): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[
				'product_type'              => 'basic_food',
				'buyer_is_gran_contribuyente' => true,
			],
			[]
		);

		$this->assertEqualsWithDelta( 0.0, $result['iva'],    self::DELTA );
		$this->assertEqualsWithDelta( 0.0, $result['reteiva'], self::DELTA );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 5 — ReteICA (por prefijo CIIU)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * ReteICA según tabla de prefijos CIIU (tarifas Bogotá 2024).
	 *
	 * @dataProvider provider_reteica_ciiu
	 */
	public function test_reteica_por_prefijo_ciiu(
		string $ciiu,
		float $gross,
		float $rate_esperado,
		float $monto_esperado
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[],
			[ 'ciiu_code' => $ciiu ]
		);

		$this->assertEqualsWithDelta(
			$rate_esperado,
			$result['reteica_rate'],
			0.00001,
			"ReteICA rate incorrecto para CIIU {$ciiu}"
		);
		$this->assertEqualsWithDelta(
			$monto_esperado,
			$result['reteica'],
			self::DELTA,
			"ReteICA monto incorrecto para CIIU {$ciiu}, gross={$gross}"
		);
	}

	/** @return array<string, array{string, float, float, float}> */
	public static function provider_reteica_ciiu(): array {
		// [ciiu, gross, rate, monto]
		return [
			// Prefijo 4 → comercio al detal (4.14‰)
			'4711 comercio 1M'       => [ '4711', 1_000_000.0, 0.00414,  4_140.0 ],
			'4711 comercio 500K'     => [ '4711',   500_000.0, 0.00414,  2_070.0 ],
			// Prefijo 5 → transporte / alojamiento (9.66‰)
			'5111 transporte 1M'     => [ '5111', 1_000_000.0, 0.00966,  9_660.0 ],
			// Prefijo 6 → software / actividades financieras (9.66‰)
			'6201 software 1M'       => [ '6201', 1_000_000.0, 0.00966,  9_660.0 ],
			'6499 financiero 2M'     => [ '6499', 2_000_000.0, 0.00966, 19_320.0 ],
			// Prefijo 7 → actividades inmobiliarias (9.66‰)
			'7110 inmobiliario 1M'   => [ '7110', 1_000_000.0, 0.00966,  9_660.0 ],
			// Prefijo 8 → actividades profesionales (9.66‰)
			'8299 profesional 1M'    => [ '8299', 1_000_000.0, 0.00966,  9_660.0 ],
			// Prefijo 9 → arte / entretenimiento (6.9‰)
			'9001 entretenimiento 1M'=> [ '9001', 1_000_000.0, 0.00690,  6_900.0 ],
			// Sin CIIU → default 4.14‰
			'sin CIIU'               => [ '',     1_000_000.0, 0.00414,  4_140.0 ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 6 — Impoconsumo / INC (restaurantes, bares, discotecas)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Impoconsumo 8% para food_service, restaurant y bar (Ley 2010/2019).
	 *
	 * @dataProvider provider_impoconsumo
	 */
	public function test_impoconsumo_8_porciento( string $product_type, float $gross, float $inc_esperado ): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => $product_type ],
			[]
		);

		$this->assertEqualsWithDelta(
			$inc_esperado,
			$result['impoconsumo'],
			self::DELTA,
			"Impoconsumo 8% incorrecto para '{$product_type}'"
		);
		$this->assertEqualsWithDelta( self::IMPOCONSUMO_RATE, $result['impoconsumo_rate'], 0.001 );
	}

	/** @return array<string, array{string, float, float}> */
	public static function provider_impoconsumo(): array {
		return [
			'food_service 500K'  => [ 'food_service', 500_000.0,  40_000.0 ],
			'restaurant 1M'      => [ 'restaurant',  1_000_000.0, 80_000.0 ],
			'bar 200K'           => [ 'bar',          200_000.0,  16_000.0 ],
		];
	}

	/**
	 * Productos NO gastronómicos no deben tener Impoconsumo.
	 *
	 * @dataProvider provider_sin_impoconsumo
	 */
	public function test_sin_impoconsumo_para_productos_normales( string $product_type ): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => $product_type ],
			[]
		);

		$this->assertEqualsWithDelta( 0.0, $result['impoconsumo'],      self::DELTA );
		$this->assertEqualsWithDelta( 0.0, $result['impoconsumo_rate'], 0.001 );
	}

	/** @return array<string, array{string}> */
	public static function provider_sin_impoconsumo(): array {
		return [
			'physical'     => [ 'physical'  ],
			'software'     => [ 'software'  ],
			'consulting'   => [ 'consulting' ],
			'basic_food'   => [ 'basic_food' ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 7 — total_taxes, total_withholding y net_to_vendor
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * total_taxes = iva + impoconsumo
	 */
	public function test_total_taxes_es_iva_mas_impoconsumo(): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'physical' ],
			[]
		);

		$esperado = round( $result['iva'] + $result['impoconsumo'], 2 );
		$this->assertEqualsWithDelta( $esperado, $result['total_taxes'], self::DELTA );
	}

	/**
	 * total_withholding = retefuente + reteiva + reteica
	 */
	public function test_total_withholding_es_suma_de_retenciones(): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[
				'product_type'              => 'consulting',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '6201',
			]
		);

		$esperado = round(
			$result['retefuente'] + $result['reteiva'] + $result['reteica'],
			2
		);
		$this->assertEqualsWithDelta( $esperado, $result['total_withholding'], self::DELTA );
	}

	/**
	 * net_to_vendor = gross - total_withholding
	 */
	public function test_net_to_vendor_es_gross_menos_total_withholding(): void {
		$gross  = 3_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'physical',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '4711',
			]
		);

		$esperado = round( $gross - $result['total_withholding'], 2 );
		$this->assertEqualsWithDelta( $esperado, $result['net_to_vendor'], self::DELTA );
	}

	/**
	 * Vendor simplificado sin gran contribuyente: ReteFuente=0, ReteIVA=0.
	 * ReteICA sigue aplicando (no depende del régimen del vendedor, sino del CIIU).
	 * net_to_vendor = gross - reteica.
	 */
	public function test_net_to_vendor_regimen_simplificado_sin_retefuente(): void {
		$gross  = 2_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'buyer_is_gran_contribuyente' => false ],
			[ 'tax_regime' => 'simplified' ]
		);

		// Régimen simplificado → ReteFuente = 0
		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA, 'ReteFuente debe ser 0 para simplified' );
		// Sin gran contribuyente → ReteIVA = 0
		$this->assertEqualsWithDelta( 0.0, $result['reteiva'],    self::DELTA, 'ReteIVA debe ser 0 sin gran contribuyente' );
		// ReteICA sigue aplicando (default 4.14‰ cuando no hay CIIU)
		$this->assertGreaterThan( 0.0, $result['reteica'], 'ReteICA aplica independientemente del régimen' );
		// Invariante: net = gross - total_withholding
		$esperado_net = round( $gross - $result['total_withholding'], 2 );
		$this->assertEqualsWithDelta( $esperado_net, $result['net_to_vendor'], self::DELTA );
	}

	/**
	 * Cálculo integral: consulting 2M, régimen common, CIIU 6201, gran contribuyente.
	 *
	 * Valores esperados (defaults hardcodeados):
	 *   IVA (19%)              = 380,000
	 *   ReteFuente hon. (11%)  = 220,000
	 *   ReteIVA (15% de IVA)   =  57,000
	 *   ReteICA (9.66‰)        =  19,320
	 *   total_withholding      = 296,320
	 *   net_to_vendor          = 1,703,680
	 */
	public function test_calculo_integral_consulting_gran_contribuyente(): void {
		$gross  = 2_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'consulting',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '6201',
			]
		);

		$this->assertEqualsWithDelta( 380_000.0,   $result['iva'],               self::DELTA );
		$this->assertEqualsWithDelta( 220_000.0,   $result['retefuente'],         self::DELTA );
		$this->assertEqualsWithDelta(  57_000.0,   $result['reteiva'],            self::DELTA );
		$this->assertEqualsWithDelta(  19_320.0,   $result['reteica'],            self::DELTA );
		$this->assertEqualsWithDelta( 296_320.0,   $result['total_withholding'],  self::DELTA );
		$this->assertEqualsWithDelta(1_703_680.0,  $result['net_to_vendor'],      self::DELTA );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 8 — Invariantes matemáticos
	// ══════════════════════════════════════════════════════════════════════════

	public function test_iva_no_negativo(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [ 'product_type' => 'physical' ], [] );
		$this->assertGreaterThanOrEqual( 0.0, $result['iva'] );
	}

	public function test_retefuente_no_negativo(): void {
		$result = $this->strategy->calculate( 5_000_000.0, [], [ 'tax_regime' => 'common' ] );
		$this->assertGreaterThanOrEqual( 0.0, $result['retefuente'] );
	}

	public function test_reteiva_no_negativo(): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'physical', 'buyer_is_gran_contribuyente' => true ],
			[]
		);
		$this->assertGreaterThanOrEqual( 0.0, $result['reteiva'] );
	}

	public function test_reteica_no_negativo(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [ 'ciiu_code' => '4711' ] );
		$this->assertGreaterThanOrEqual( 0.0, $result['reteica'] );
	}

	public function test_net_to_vendor_no_negativo(): void {
		$result = $this->strategy->calculate(
			500_000.0,
			[
				'product_type'              => 'consulting',
				'buyer_is_gran_contribuyente' => true,
			],
			[ 'tax_regime' => 'gran_contribuyente', 'ciiu_code' => '6201' ]
		);
		$this->assertGreaterThanOrEqual( 0.0, $result['net_to_vendor'], 'net_to_vendor no puede ser negativo' );
	}

	public function test_iva_rate_entre_cero_y_uno(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [ 'product_type' => 'physical' ], [] );
		$this->assertGreaterThanOrEqual( 0.0, $result['iva_rate'] );
		$this->assertLessThanOrEqual( 1.0, $result['iva_rate'] );
	}

	public function test_retefuente_rate_entre_cero_y_uno(): void {
		$result = $this->strategy->calculate( 5_000_000.0, [], [ 'tax_regime' => 'common' ] );
		$this->assertGreaterThanOrEqual( 0.0, $result['retefuente_rate'] );
		$this->assertLessThanOrEqual( 1.0, $result['retefuente_rate'] );
	}

	public function test_strategy_identifica_clase_colombia(): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [] );
		$this->assertStringContainsString( 'Colombia', $result['strategy'] );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ▼▼▼  ÁNGULOS NUEVOS  ▼▼▼
	// ══════════════════════════════════════════════════════════════════════════

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 9 — Boundary exacto de umbrales UVT
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Honorarios: gross == 1 UVT exacto → aplica (umbral inclusivo >=).
	 */
	public function test_retefuente_honorarios_en_umbral_exacto_aplica(): void {
		// 1 UVT exacto → la condición es >= → debe aplicar 11%
		$gross  = self::MIN_HONORARIOS; // 49,799.0
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime'   => 'common'     ]
		);

		$esperado = round( $gross * self::RETEFUENTE_HONORARIOS, 2 );
		$this->assertEqualsWithDelta( $esperado, $result['retefuente'], self::DELTA,
			'gross == 1 UVT debe aplicar ReteFuente honorarios (boundary inclusivo)' );
		$this->assertEqualsWithDelta( self::RETEFUENTE_HONORARIOS, $result['retefuente_rate'], 0.001 );
	}

	/**
	 * Honorarios: gross == 1 UVT - 0.01 → NO aplica (justo bajo el umbral).
	 */
	public function test_retefuente_honorarios_justo_bajo_umbral_no_aplica(): void {
		$gross  = self::MIN_HONORARIOS - 0.01;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime'   => 'common'     ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA,
			'gross justo bajo 1 UVT no debe aplicar ReteFuente honorarios' );
	}

	/**
	 * Compras: gross == umbral_compras exacto → aplica 2.5%.
	 */
	public function test_retefuente_compras_en_umbral_exacto_aplica(): void {
		$gross  = self::MIN_COMPRAS; // ≈ 531,156 COP
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'physical' ],
			[ 'tax_regime'   => 'common'   ]
		);

		$esperado = round( $gross * self::RETEFUENTE_COMPRAS, 2 );
		$this->assertEqualsWithDelta( $esperado, $result['retefuente'], self::DELTA,
			'gross == umbral_compras debe aplicar ReteFuente compras (boundary inclusivo)' );
	}

	/**
	 * Compras: gross == umbral_compras - 0.01 → NO aplica.
	 */
	public function test_retefuente_compras_justo_bajo_umbral_no_aplica(): void {
		$gross  = self::MIN_COMPRAS - 0.01;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'physical' ],
			[ 'tax_regime'   => 'common'   ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA,
			'gross justo bajo umbral_compras no debe aplicar ReteFuente' );
	}

	/**
	 * Tech: gross == umbral_servicios exacto → aplica 6%.
	 */
	public function test_retefuente_tech_en_umbral_exacto_aplica(): void {
		$gross  = self::MIN_SERVICIOS; // ≈ 132,764 COP
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'software' ],
			[ 'tax_regime'   => 'common'   ]
		);

		$esperado = round( $gross * self::RETEFUENTE_TECH, 2 );
		$this->assertEqualsWithDelta( $esperado, $result['retefuente'], self::DELTA,
			'gross == umbral_servicios debe aplicar ReteFuente tech (boundary inclusivo)' );
	}

	/**
	 * product_type 'product' (alias de 'physical') usa la rama de compras (2.5%).
	 */
	public function test_product_type_product_usa_rama_compras(): void {
		$gross  = 1_000_000.0; // Sobre umbral compras
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'product' ],
			[ 'tax_regime'   => 'common'  ]
		);

		$this->assertEqualsWithDelta( self::RETEFUENTE_COMPRAS, $result['retefuente_rate'], 0.001,
			"product_type 'product' debe usar tasa de compras 2.5%" );
		$this->assertEqualsWithDelta(
			round( $gross * self::RETEFUENTE_COMPRAS, 2 ),
			$result['retefuente'],
			self::DELTA
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 10 — ReteICA: CIIU de longitud inusual y prefijo desconocido
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * CIIU de 1 caracter '4' → prefijo 4, tasa 4.14‰.
	 */
	public function test_reteica_ciiu_de_un_solo_digito(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate( $gross, [], [ 'ciiu_code' => '4' ] );

		$this->assertEqualsWithDelta( 0.00414, $result['reteica_rate'], 0.00001 );
		$this->assertEqualsWithDelta( 4_140.0, $result['reteica'],      self::DELTA );
	}

	/**
	 * CIIU de 5 caracteres con prefijo 9 → tasa 6.9‰.
	 */
	public function test_reteica_ciiu_de_cinco_digitos_prefijo_9(): void {
		$gross  = 2_000_000.0;
		$result = $this->strategy->calculate( $gross, [], [ 'ciiu_code' => '90011' ] );

		$this->assertEqualsWithDelta( 0.00690, $result['reteica_rate'], 0.00001 );
		$this->assertEqualsWithDelta( 13_800.0, $result['reteica'],     self::DELTA );
	}

	/**
	 * CIIU con prefijo letra (desconocido) → fallback 4.14‰.
	 */
	public function test_reteica_prefijo_desconocido_letra_usa_fallback(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate( $gross, [], [ 'ciiu_code' => 'A100' ] );

		$this->assertEqualsWithDelta( 0.00414, $result['reteica_rate'], 0.00001,
			'Prefijo no numérico debe usar la tarifa fallback 4.14‰' );
	}

	/**
	 * CIIU con prefijo '3' (no está en la tabla) → fallback 4.14‰.
	 */
	public function test_reteica_prefijo_3_no_en_tabla_usa_fallback(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate( $gross, [], [ 'ciiu_code' => '3100' ] );

		$this->assertEqualsWithDelta( 0.00414, $result['reteica_rate'], 0.00001,
			'Prefijo 3 (manufactura) no está en tabla — debe usar fallback 4.14‰' );
	}

	/**
	 * Confirmar todos los prefijos 4–9 en una sola pasada.
	 *
	 * @dataProvider provider_todos_los_prefijos_reteica
	 */
	public function test_todos_los_prefijos_ciiu_retornan_rate_correcto(
		string $ciiu,
		float  $rate_esperado
	): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [ 'ciiu_code' => $ciiu ] );

		$this->assertEqualsWithDelta( $rate_esperado, $result['reteica_rate'], 0.00001,
			"Prefijo CIIU {$ciiu[0]} rate incorrecto" );
	}

	/** @return array<string, array{string, float}> */
	public static function provider_todos_los_prefijos_reteica(): array {
		return [
			'prefijo 4' => [ '4000', 0.00414 ],
			'prefijo 5' => [ '5000', 0.00966 ],
			'prefijo 6' => [ '6000', 0.00966 ],
			'prefijo 7' => [ '7000', 0.00966 ],
			'prefijo 8' => [ '8000', 0.00966 ],
			'prefijo 9' => [ '9000', 0.00690 ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 11 — Impoconsumo: interacción con total_taxes
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * restaurant: total_taxes = IVA_general + impoconsumo (ambos aplican).
	 */
	public function test_total_taxes_restaurant_incluye_iva_e_impoconsumo(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'restaurant' ],
			[]
		);

		// IVA 19% (restaurant no está en exentos ni reducidos) + INC 8%
		$iva_esperado = round( $gross * self::IVA_GENERAL,     2 ); // 190,000
		$inc_esperado = round( $gross * self::IMPOCONSUMO_RATE, 2 ); //  80,000

		$this->assertEqualsWithDelta( $iva_esperado, $result['iva'],         self::DELTA );
		$this->assertEqualsWithDelta( $inc_esperado, $result['impoconsumo'], self::DELTA );
		$this->assertEqualsWithDelta(
			round( $iva_esperado + $inc_esperado, 2 ),
			$result['total_taxes'],
			self::DELTA,
			'total_taxes debe ser IVA + INC para restaurant'
		);
	}

	/**
	 * product_type desconocido → impoconsumo = 0, impoconsumo_rate = 0.
	 */
	public function test_impoconsumo_cero_para_product_type_desconocido(): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'unknown_type' ],
			[]
		);

		$this->assertEqualsWithDelta( 0.0, $result['impoconsumo'],      self::DELTA );
		$this->assertEqualsWithDelta( 0.0, $result['impoconsumo_rate'], 0.001 );
	}

	/**
	 * bar: impoconsumo NO entra en total_withholding (es un impuesto al consumo,
	 * no una retención al vendedor).
	 */
	public function test_impoconsumo_no_forma_parte_de_total_withholding(): void {
		$result = $this->strategy->calculate(
			500_000.0,
			[ 'product_type' => 'bar' ],
			[ 'tax_regime' => 'simplified' ]
		);

		// simplified → retefuente=0, no gran contribuyente → reteiva=0
		// total_withholding = solo reteica
		$this->assertEqualsWithDelta(
			$result['reteica'],
			$result['total_withholding'],
			self::DELTA,
			'Para bar con régimen simplificado, total_withholding = solo ReteICA'
		);

		// Pero impoconsumo sí es > 0
		$this->assertGreaterThan( 0.0, $result['impoconsumo'], 'bar debe tener impoconsumo > 0' );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 12 — ReteIVA con IVA base reducido (5%)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Gran contribuyente + café (IVA 5%) → reteiva = 15% del IVA reducido.
	 */
	public function test_reteiva_con_iva_reducido_5_porciento(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'coffee',
				'buyer_is_gran_contribuyente' => true,
			],
			[]
		);

		$iva_esperado    = round( $gross * self::IVA_REDUCIDO, 2 );          // 50,000
		$reteiva_esperado = round( $iva_esperado * self::RETEIVA_RATE, 2 );  //  7,500

		$this->assertEqualsWithDelta( $iva_esperado,     $result['iva'],         self::DELTA );
		$this->assertEqualsWithDelta( $reteiva_esperado, $result['reteiva'],     self::DELTA,
			'ReteIVA debe calcularse sobre IVA reducido (5%), no sobre tasa general' );
		// La tasa de ReteIVA sigue siendo 15%
		$this->assertEqualsWithDelta( self::RETEIVA_RATE, $result['reteiva_rate'], 0.001 );
	}

	/**
	 * Gran contribuyente + eggs_retail (IVA 5%): reteiva_rate sigue en 0.15.
	 */
	public function test_reteiva_rate_no_cambia_con_iva_reducido(): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[
				'product_type'              => 'eggs_retail',
				'buyer_is_gran_contribuyente' => true,
			],
			[]
		);

		$this->assertEqualsWithDelta( self::RETEIVA_RATE, $result['reteiva_rate'], 0.001,
			'reteiva_rate debe ser siempre 15% independientemente del IVA base' );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 13 — Regímenes especial y gran_contribuyente como vendedor
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Régimen 'special' aplica ReteFuente.
	 */
	public function test_retefuente_aplica_para_regimen_especial(): void {
		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime'   => 'special'    ]
		);

		$this->assertGreaterThan( 0.0, $result['retefuente'],
			"Régimen 'special' debe aplicar ReteFuente" );
		$this->assertEqualsWithDelta( self::RETEFUENTE_HONORARIOS, $result['retefuente_rate'], 0.001 );
	}

	/**
	 * Régimen 'gran_contribuyente' (como vendedor) aplica ReteFuente.
	 */
	public function test_retefuente_aplica_para_vendedor_gran_contribuyente(): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[ 'product_type' => 'physical' ],
			[ 'tax_regime'   => 'gran_contribuyente' ]
		);

		$this->assertGreaterThan( 0.0, $result['retefuente'],
			"Vendedor 'gran_contribuyente' debe aplicar ReteFuente" );
	}

	/**
	 * should_apply_withholding sin clave tax_regime → default 'simplified' → false.
	 */
	public function test_should_apply_withholding_sin_clave_tax_regime_es_false(): void {
		// Vendor data vacío → no existe 'tax_regime' → default 'simplified' → false
		$this->assertFalse(
			$this->strategy->should_apply_withholding( [] ),
			'Sin clave tax_regime, should_apply_withholding debe retornar false (default simplified)'
		);
	}

	/**
	 * Régimen 'natural_person' (inventado) → no está en whitelist → false.
	 */
	public function test_should_apply_withholding_regimen_desconocido_es_false(): void {
		$this->assertFalse(
			$this->strategy->should_apply_withholding( [ 'tax_regime' => 'natural_person' ] )
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 14 — Cálculos integrales adicionales
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * physical + common + CIIU 4711 + gran contribuyente:
	 *   IVA = 19%, ReteFuente = 2.5% (sobre umbral), ReteIVA = 15% de IVA, ReteICA = 4.14‰.
	 *   gross = 1,000,000
	 *   iva          = 190,000
	 *   retefuente   =  25,000   (2.5% × 1,000,000)
	 *   reteiva      =  28,500   (15% × 190,000)
	 *   reteica      =   4,140   (4.14‰ × 1,000,000)
	 *   total_withholding = 57,640
	 *   net_to_vendor     = 942,360
	 */
	public function test_calculo_integral_physical_gran_contribuyente_ciiu_4(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'physical',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '4711',
			]
		);

		$this->assertEqualsWithDelta( 190_000.0, $result['iva'],              self::DELTA );
		$this->assertEqualsWithDelta(  25_000.0, $result['retefuente'],        self::DELTA );
		$this->assertEqualsWithDelta(  28_500.0, $result['reteiva'],           self::DELTA );
		$this->assertEqualsWithDelta(   4_140.0, $result['reteica'],           self::DELTA );
		$this->assertEqualsWithDelta(  57_640.0, $result['total_withholding'], self::DELTA );
		$this->assertEqualsWithDelta( 942_360.0, $result['net_to_vendor'],     self::DELTA );
	}

	/**
	 * restaurant + simplified + sin gran contribuyente:
	 *   IVA = 19%, INC = 8%, ReteFuente = 0, ReteIVA = 0, ReteICA = 4.14‰ (default).
	 *   gross = 500,000
	 *   iva          =  95,000
	 *   impoconsumo  =  40,000
	 *   total_taxes  = 135,000
	 *   reteica      =   2,070   (4.14‰)
	 *   total_withholding =  2,070
	 *   net_to_vendor     = 497,930
	 */
	public function test_calculo_integral_restaurant_simplificado(): void {
		$gross  = 500_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'restaurant',
				'buyer_is_gran_contribuyente' => false,
			],
			[ 'tax_regime' => 'simplified' ]
		);

		$this->assertEqualsWithDelta(  95_000.0, $result['iva'],              self::DELTA );
		$this->assertEqualsWithDelta(  40_000.0, $result['impoconsumo'],      self::DELTA );
		$this->assertEqualsWithDelta( 135_000.0, $result['total_taxes'],      self::DELTA );
		$this->assertEqualsWithDelta(       0.0, $result['retefuente'],       self::DELTA );
		$this->assertEqualsWithDelta(       0.0, $result['reteiva'],          self::DELTA );
		$this->assertEqualsWithDelta(   2_070.0, $result['reteica'],          self::DELTA );
		$this->assertEqualsWithDelta(   2_070.0, $result['total_withholding'],self::DELTA );
		$this->assertEqualsWithDelta( 497_930.0, $result['net_to_vendor'],    self::DELTA );
	}

	/**
	 * software + simplified + CIIU 6201:
	 *   ReteFuente = 0 (simplified), solo ReteICA aplica.
	 */
	public function test_calculo_integral_software_simplified_solo_reteica(): void {
		$gross  = 2_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'software' ],
			[
				'tax_regime' => 'simplified',
				'ciiu_code'  => '6201',
			]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA,
			'Simplified no aplica ReteFuente' );
		$this->assertEqualsWithDelta( 0.0, $result['reteiva'],    self::DELTA,
			'Sin gran contribuyente no aplica ReteIVA' );
		$this->assertEqualsWithDelta( 0.00966, $result['reteica_rate'], 0.00001 );
		// total_withholding = solo reteica
		$reteica_esperada = round( $gross * 0.00966, 2 ); // 19,320
		$this->assertEqualsWithDelta( $reteica_esperada, $result['reteica'],           self::DELTA );
		$this->assertEqualsWithDelta( $reteica_esperada, $result['total_withholding'], self::DELTA );
	}

	/**
	 * Cálculo de 1 peso COP: todos los campos numéricos ≥ 0 y son float.
	 */
	public function test_calculo_con_1_peso_cop_todos_los_campos_son_float_no_negativos(): void {
		$result = $this->strategy->calculate( 1.0, [ 'product_type' => 'physical' ], [] );

		$campos_numericos = [
			'gross', 'iva', 'iva_rate', 'retefuente', 'retefuente_rate',
			'reteiva', 'reteiva_rate', 'reteica', 'reteica_rate',
			'impoconsumo', 'impoconsumo_rate', 'isr', 'isr_rate', 'ieps', 'ieps_rate',
			'total_taxes', 'total_withholding', 'net_to_vendor', 'platform_fee', 'uvt_value',
		];

		foreach ( $campos_numericos as $campo ) {
			$this->assertIsFloat( $result[ $campo ],                           "Campo '{$campo}' debe ser float" );
			$this->assertGreaterThanOrEqual( 0.0, $result[ $campo ],          "Campo '{$campo}' no debe ser negativo" );
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 15 — Invariantes de tipos y límites de tasas
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * platform_fee siempre es 0.0 (Colombia no tiene comisión de plataforma en la strategy).
	 */
	public function test_platform_fee_siempre_es_cero(): void {
		$result = $this->strategy->calculate(
			5_000_000.0,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime' => 'common' ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['platform_fee'], 0.001,
			'platform_fee debe ser 0.0 en la strategy Colombia' );
	}

	/**
	 * retefuente_rate nunca excede 0.11 (tasa máxima: honorarios 11%).
	 *
	 * @dataProvider provider_todos_los_product_types
	 */
	public function test_retefuente_rate_no_excede_maximo_11_porciento( string $product_type ): void {
		$result = $this->strategy->calculate(
			10_000_000.0, // Monto grande: siempre supera umbrales
			[ 'product_type' => $product_type ],
			[ 'tax_regime'   => 'common' ]
		);

		$this->assertLessThanOrEqual( 0.11, $result['retefuente_rate'],
			"retefuente_rate ({$result['retefuente_rate']}) no debe exceder 11% para '{$product_type}'" );
	}

	/** @return array<string, array{string}> */
	public static function provider_todos_los_product_types(): array {
		return [
			'physical'       => [ 'physical'        ],
			'consulting'     => [ 'consulting'       ],
			'software'       => [ 'software'         ],
			'digital_service'=> [ 'digital_service'  ],
			'saas'           => [ 'saas'             ],
			'tech_service'   => [ 'tech_service'     ],
			'general'        => [ 'general'          ],
			'freelance'      => [ 'freelance'        ],
			'restaurant'     => [ 'restaurant'       ],
			'basic_food'     => [ 'basic_food'       ],
		];
	}

	/**
	 * reteica_rate siempre está en [0.00414, 0.00966] para cualquier CIIU.
	 *
	 * @dataProvider provider_ciiu_variados
	 */
	public function test_reteica_rate_siempre_en_rango_valido( string $ciiu ): void {
		$result = $this->strategy->calculate( 1_000_000.0, [], [ 'ciiu_code' => $ciiu ] );

		$this->assertGreaterThanOrEqual( 0.00414, $result['reteica_rate'],
			"reteica_rate debe ser >= 0.00414 para CIIU '{$ciiu}'" );
		$this->assertLessThanOrEqual( 0.00966, $result['reteica_rate'],
			"reteica_rate debe ser <= 0.00966 para CIIU '{$ciiu}'" );
	}

	/** @return array<string, array{string}> */
	public static function provider_ciiu_variados(): array {
		return [
			'sin ciiu'    => [ ''      ],
			'prefijo 4'   => [ '4711'  ],
			'prefijo 5'   => [ '5120'  ],
			'prefijo 6'   => [ '6201'  ],
			'prefijo 7'   => [ '7110'  ],
			'prefijo 8'   => [ '8299'  ],
			'prefijo 9'   => [ '9001'  ],
			'fallback'    => [ 'Z999'  ],
			'1 dígito'    => [ '6'     ],
		];
	}

	/**
	 * Invariante: iva + impoconsumo == total_taxes (siempre, para cualquier combinación).
	 *
	 * @dataProvider provider_combinaciones_para_invariante_total_taxes
	 */
	public function test_invariante_total_taxes_iva_mas_impoconsumo(
		float  $gross,
		string $product_type
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => $product_type ],
			[]
		);

		$suma = round( $result['iva'] + $result['impoconsumo'], 2 );
		$this->assertEqualsWithDelta( $suma, $result['total_taxes'], self::DELTA,
			"total_taxes debe ser iva + impoconsumo para '{$product_type}'" );
	}

	/** @return array<string, array{float, string}> */
	public static function provider_combinaciones_para_invariante_total_taxes(): array {
		return [
			'physical 1M'     => [ 1_000_000.0, 'physical'     ],
			'restaurant 500K' => [   500_000.0, 'restaurant'   ],
			'medicine 2M'     => [ 2_000_000.0, 'medicine'     ],
			'coffee 1M'       => [ 1_000_000.0, 'coffee'       ],
			'bar 750K'        => [   750_000.0, 'bar'          ],
			'software 3M'     => [ 3_000_000.0, 'software'     ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 16 — gross = 0 y gross muy grande (robustez numérica)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * gross = 0.0 → todos los campos monetarios deben ser 0.0 sin errores.
	 */
	public function test_gross_cero_retorna_todo_cero(): void {
		$result = $this->strategy->calculate(
			0.0,
			[
				'product_type'              => 'physical',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '4711',
			]
		);

		$campos_monetarios = [
			'gross', 'iva', 'retefuente', 'reteiva', 'reteica',
			'impoconsumo', 'total_taxes', 'total_withholding', 'net_to_vendor',
		];

		foreach ( $campos_monetarios as $campo ) {
			$this->assertEqualsWithDelta( 0.0, $result[ $campo ], self::DELTA,
				"Con gross=0, el campo '{$campo}' debe ser 0.0" );
		}
	}

	/**
	 * gross muy grande (100M COP) — sin desbordamiento ni pérdida de precisión.
	 * Verifica que net_to_vendor = gross - total_withholding sigue siendo exacto.
	 */
	public function test_gross_muy_grande_100M_mantiene_precision(): void {
		$gross  = 100_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'consulting',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '6201',
			]
		);

		// IVA 19%
		$iva_esperado = round( $gross * self::IVA_GENERAL, 2 );
		// ReteFuente 11% (honorarios, gross >> 1 UVT)
		$retefuente_esperado = round( $gross * self::RETEFUENTE_HONORARIOS, 2 );
		// ReteIVA 15% del IVA
		$reteiva_esperado = round( $iva_esperado * self::RETEIVA_RATE, 2 );
		// ReteICA prefijo 6 → 9.66‰
		$reteica_esperado = round( $gross * 0.00966, 2 );

		$this->assertEqualsWithDelta( $iva_esperado,        $result['iva'],        self::DELTA );
		$this->assertEqualsWithDelta( $retefuente_esperado, $result['retefuente'], self::DELTA );
		$this->assertEqualsWithDelta( $reteiva_esperado,    $result['reteiva'],    self::DELTA );
		$this->assertEqualsWithDelta( $reteica_esperado,    $result['reteica'],    self::DELTA );

		// Invariante: net = gross - total_withholding
		$net_esperado = round( $gross - $result['total_withholding'], 2 );
		$this->assertEqualsWithDelta( $net_esperado, $result['net_to_vendor'], self::DELTA );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 17 — Aliases de product_type no documentados explícitamente
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * 'professional_service' cae en el grupo de honorarios (11%) como consulting.
	 * Está en el array de la implementación pero no tenía test propio.
	 */
	public function test_professional_service_aplica_retefuente_honorarios(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'professional_service' ],
			[ 'tax_regime'   => 'common' ]
		);

		$this->assertEqualsWithDelta(
			round( $gross * self::RETEFUENTE_HONORARIOS, 2 ),
			$result['retefuente'],
			self::DELTA,
			'professional_service debe aplicar ReteFuente 11%'
		);
		$this->assertEqualsWithDelta( self::RETEFUENTE_HONORARIOS, $result['retefuente_rate'], 0.001 );
	}

	/**
	 * 'saas' y 'digital_service' son tech → 6% ReteFuente sobre umbral servicios.
	 * Verifica que IVA general (19%) también aplica (no exento).
	 */
	public function test_saas_tiene_iva_general_y_retefuente_tech(): void {
		$gross  = 2_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[ 'product_type' => 'saas' ],
			[ 'tax_regime'   => 'common' ]
		);

		$this->assertEqualsWithDelta( self::IVA_GENERAL, $result['iva_rate'], 0.001,
			'SaaS debe tener IVA general 19%' );
		$this->assertEqualsWithDelta( self::RETEFUENTE_TECH, $result['retefuente_rate'], 0.001,
			'SaaS debe tener ReteFuente tech 6%' );
		$this->assertEqualsWithDelta( round( $gross * self::IVA_GENERAL,    2 ), $result['iva'],        self::DELTA );
		$this->assertEqualsWithDelta( round( $gross * self::RETEFUENTE_TECH, 2 ), $result['retefuente'], self::DELTA );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 18 — food_service / bar con todos los impuestos activos
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * food_service + common + gran contribuyente:
	 *   IVA 19% + INC 8% + ReteFuente 4% (servicios generales) + ReteIVA 15% + ReteICA.
	 *
	 * gross = 1,000,000 COP, CIIU 5630 (prefijo 5 → 9.66‰)
	 *   iva          = 190,000  (19%)
	 *   impoconsumo  =  80,000  (8%)
	 *   total_taxes  = 270,000
	 *   retefuente   =  40,000  (4% — food_service cae en servicios generales)
	 *   reteiva      =  28,500  (15% × 190,000)
	 *   reteica      =   9,660  (9.66‰)
	 *   total_withholding = 78,160
	 *   net_to_vendor     = 921,840
	 */
	public function test_food_service_common_gran_contribuyente_todos_los_impuestos(): void {
		$gross  = 1_000_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'food_service',
				'buyer_is_gran_contribuyente' => true,
			],
			[
				'tax_regime' => 'common',
				'ciiu_code'  => '5630',
			]
		);

		$this->assertEqualsWithDelta( 190_000.0, $result['iva'],              self::DELTA, 'IVA 19%' );
		$this->assertEqualsWithDelta(  80_000.0, $result['impoconsumo'],      self::DELTA, 'INC 8%' );
		$this->assertEqualsWithDelta( 270_000.0, $result['total_taxes'],      self::DELTA, 'total_taxes = IVA + INC' );
		$this->assertEqualsWithDelta(  40_000.0, $result['retefuente'],       self::DELTA, 'ReteFuente 4% servicios' );
		$this->assertEqualsWithDelta(  28_500.0, $result['reteiva'],          self::DELTA, 'ReteIVA 15% × IVA' );
		$this->assertEqualsWithDelta(   9_660.0, $result['reteica'],          self::DELTA, 'ReteICA 9.66‰' );
		$this->assertEqualsWithDelta(  78_160.0, $result['total_withholding'],self::DELTA, 'total_withholding' );
		$this->assertEqualsWithDelta( 921_840.0, $result['net_to_vendor'],    self::DELTA, 'net_to_vendor' );
	}

	/**
	 * bar + gran contribuyente + common:
	 *   IVA 19% + INC 8% + ReteIVA 15% + ReteFuente 4% (bar → servicios generales).
	 *   Confirma que 'bar' es tratado igual que 'food_service'.
	 */
	public function test_bar_gran_contribuyente_tiene_inc_y_reteiva(): void {
		$gross  = 500_000.0;
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => 'bar',
				'buyer_is_gran_contribuyente' => true,
			],
			[ 'tax_regime' => 'common' ]
		);

		$iva_esperado = round( $gross * self::IVA_GENERAL, 2 );
		$inc_esperado = round( $gross * self::IMPOCONSUMO_RATE, 2 );

		$this->assertEqualsWithDelta( $iva_esperado, $result['iva'],         self::DELTA );
		$this->assertEqualsWithDelta( $inc_esperado, $result['impoconsumo'], self::DELTA );
		// ReteIVA = 15% del IVA
		$this->assertEqualsWithDelta(
			round( $iva_esperado * self::RETEIVA_RATE, 2 ),
			$result['reteiva'],
			self::DELTA,
			'bar con gran contribuyente debe tener ReteIVA'
		);
		// total_taxes = IVA + INC
		$this->assertEqualsWithDelta(
			round( $iva_esperado + $inc_esperado, 2 ),
			$result['total_taxes'],
			self::DELTA
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 19 — Exentos de IVA + gran contribuyente (ReteIVA siempre = 0)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Productos exentos de IVA: aunque buyer sea gran contribuyente, ReteIVA = 0
	 * porque no hay base IVA sobre la cual aplicar el 15%.
	 *
	 * @dataProvider provider_exentos_con_gran_contribuyente
	 */
	public function test_reteiva_cero_en_exentos_aunque_sea_gran_contribuyente(
		string $product_type
	): void {
		$result = $this->strategy->calculate(
			2_000_000.0,
			[
				'product_type'              => $product_type,
				'buyer_is_gran_contribuyente' => true,
			],
			[]
		);

		$this->assertEqualsWithDelta( 0.0, $result['iva'],    self::DELTA,
			"'{$product_type}' debe tener IVA = 0" );
		$this->assertEqualsWithDelta( 0.0, $result['reteiva'], self::DELTA,
			"Sin IVA no puede haber ReteIVA aunque sea gran contribuyente" );
	}

	/** @return array<string, array{string}> */
	public static function provider_exentos_con_gran_contribuyente(): array {
		return [
			'basic_food'        => [ 'basic_food'        ],
			'medicine'          => [ 'medicine'           ],
			'health_service'    => [ 'health_service'     ],
			'education'         => [ 'education'          ],
			'agricultural_basic'=> [ 'agricultural_basic' ],
		];
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 20 — Configuración dinámica de tasas vía LTMS_Core_Config
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * UVT configurable: cambiar UVT a 60,000 sube el umbral de honorarios.
	 * Un gross de 55,000 (entre 49,799 y 60,000) deja de aplicar ReteFuente.
	 */
	public function test_uvt_configurable_sube_umbral_honorarios(): void {
		\LTMS_Core_Config::set( 'ltms_uvt_valor', 60_000.0 );

		// 55,000 COP < 60,000 UVT → NO debe aplicar ReteFuente
		$result = $this->strategy->calculate(
			55_000.0,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime'   => 'common'     ]
		);

		$this->assertEqualsWithDelta( 0.0, $result['retefuente'], self::DELTA,
			'Con UVT=60,000, gross=55,000 está bajo el umbral y NO aplica ReteFuente' );
		// uvt_value en el resultado refleja el valor configurado
		$this->assertEqualsWithDelta( 60_000.0, $result['uvt_value'], 0.01 );
	}

	/**
	 * IVA general configurable: cambiar a 21% (simulación futura).
	 * gross = 1,000,000 → IVA debe ser 210,000.
	 */
	public function test_iva_general_configurable(): void {
		\LTMS_Core_Config::set( 'ltms_iva_general', 0.21 );

		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'physical' ],
			[]
		);

		$this->assertEqualsWithDelta( 0.21,    $result['iva_rate'], 0.001 );
		$this->assertEqualsWithDelta( 210_000.0, $result['iva'],    self::DELTA );
	}

	/**
	 * Impoconsumo configurable: cambiar a 10% para simular cambio legislativo.
	 */
	public function test_impoconsumo_rate_configurable(): void {
		\LTMS_Core_Config::set( 'ltms_impoconsumo_rate', 0.10 );

		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'restaurant' ],
			[]
		);

		$this->assertEqualsWithDelta( 0.10,    $result['impoconsumo_rate'], 0.001 );
		$this->assertEqualsWithDelta( 100_000.0, $result['impoconsumo'],   self::DELTA );
	}

	/**
	 * ReteFuente honorarios configurable: cambiar a 10% baja la retención.
	 */
	public function test_retefuente_honorarios_configurable(): void {
		\LTMS_Core_Config::set( 'ltms_retefuente_honorarios', 0.10 );

		$result = $this->strategy->calculate(
			1_000_000.0,
			[ 'product_type' => 'consulting' ],
			[ 'tax_regime'   => 'common'     ]
		);

		$this->assertEqualsWithDelta( 0.10,    $result['retefuente_rate'], 0.001 );
		$this->assertEqualsWithDelta( 100_000.0, $result['retefuente'],   self::DELTA );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECCIÓN 21 — Invariante net_to_vendor multi-escenario
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Invariante: net_to_vendor == gross - total_withholding en cualquier combinación.
	 * (Ampliado sobre la sección 15 con más variedad de regímenes y tipos.)
	 *
	 * @dataProvider provider_invariante_net_to_vendor
	 */
	public function test_invariante_net_to_vendor_gross_menos_retenciones(
		float  $gross,
		string $product_type,
		string $regime,
		string $ciiu,
		bool   $gran_contribuyente
	): void {
		$result = $this->strategy->calculate(
			$gross,
			[
				'product_type'              => $product_type,
				'buyer_is_gran_contribuyente' => $gran_contribuyente,
			],
			[
				'tax_regime' => $regime,
				'ciiu_code'  => $ciiu,
			]
		);

		$net_calculado = round( $gross - $result['total_withholding'], 2 );
		$this->assertEqualsWithDelta( $net_calculado, $result['net_to_vendor'], self::DELTA,
			"Invariante net_to_vendor fallida para {$product_type}/{$regime}/{$ciiu}" );
	}

	/** @return array<string, array{float, string, string, string, bool}> */
	public static function provider_invariante_net_to_vendor(): array {
		return [
			// [gross, product_type, regime, ciiu, gran_contribuyente]
			'physical/common/4711/GC'         => [ 1_000_000.0, 'physical',         'common',           '4711', true  ],
			'consulting/gran/6201/GC'          => [ 2_000_000.0, 'consulting',       'gran_contribuyente','6201', true  ],
			'software/simplified/6201/no-GC'  => [ 3_000_000.0, 'software',         'simplified',       '6201', false ],
			'restaurant/common/5630/no-GC'    => [   500_000.0, 'restaurant',       'common',           '5630', false ],
			'medicine/special/4711/GC'        => [ 1_500_000.0, 'medicine',         'special',          '4711', true  ],
			'bar/common/9001/GC'              => [   800_000.0, 'bar',              'common',           '9001', true  ],
			'saas/common/7110/no-GC'          => [ 5_000_000.0, 'saas',             'common',           '7110', false ],
			'basic_food/simplified/sin-GC'    => [   250_000.0, 'basic_food',       'simplified',       '',     false ],
			'freelance/gran/8299/GC'          => [ 4_000_000.0, 'freelance',        'gran_contribuyente','8299', true  ],
			'digital_service/common/6499/GC'  => [ 1_200_000.0, 'digital_service',  'common',           '6499', true  ],
		];
	}
}
