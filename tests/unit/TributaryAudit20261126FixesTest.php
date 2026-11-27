<?php
/**
 * TributaryAudit20261126FixesTest — Ciclo TRIB-2026-11-26 (auditoría tributaria MX/CO)
 *
 * Módulos: includes/business/class-ltms-order-split.php,
 *          includes/business/strategies/class-ltms-tax-strategy-colombia.php,
 *          includes/business/strategies/class-ltms-tax-strategy-mexico.php
 *
 * Hallazgos verificados:
 *  - TRIB-001 (P0): el Order Split leía el order meta `_ltms_product_type`, que
 *    NADIE escribe → todo pedido tributaba como 'physical'. Fix: resolución desde
 *    el post meta del producto por grupo de vendor (mismo patrón que
 *    LTMS_Commission_Strategy::get_product_type_rate()).
 *  - TRIB-002 (P1): vocabulario LTMS (physical/digital/service/booking/restaurant)
 *    no matcheaba el de las estrategias → IVA turismo 7% CO, ReteFuente tech 6% CO
 *    e ISH MX eran dead code aunque el tipo llegara.
 *  - TRIB-006 (P2): `is_booking` nunca se pasaba al engine (gates NT-1/NT-2).
 *
 * Nota de diseño: los asserts NotContains NO deben citar el anti-patrón texto
 * completo (regla del ciclo QV-HARDEN: 7 tests rotos por citar el anti-patrón).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class TributaryAudit20261126FixesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'            => static fn( $t ) => $t,
			'esc_html'      => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
			'get_option'    => static fn( $k, $d = null ) => $d,
			'update_option' => static fn() => true,
			'apply_filters' => static fn( $tag, $val ) => $val,
			'sanitize_key'  => static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ),
			'sanitize_text_field' => static fn( $v ) => is_scalar( $v ) ? (string) $v : '',
			'absint'        => static fn( $v ) => abs( (int) $v ),
		] );
		\LTMS_Tax_Engine::flush_strategies();
		\LTMS_Core_Config::flush_cache();
	}

	protected function tearDown(): void {
		\LTMS_Tax_Engine::flush_strategies();
		\LTMS_Core_Config::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	//  Helpers de cálculo (espejo de TaxEngineTest)
	// ------------------------------------------------------------------ //

	private function co_calculate( float $gross, array $order = [], array $vendor = [] ): array {
		$order  = array_merge( [ 'product_type' => 'physical' ], $order );
		$vendor = array_merge( [ 'tax_regime' => 'common', 'municipality_code' => '', 'ciiu_code' => '4' ], $vendor );
		return \LTMS_Tax_Engine::calculate( $gross, $order, $vendor, 'CO' );
	}

	private function mx_calculate( float $gross, array $order = [], array $vendor = [] ): array {
		$order  = array_merge( [ 'product_type' => 'physical', 'platform_is_persona_moral' => true ], $order );
		$vendor = array_merge( [ 'tax_regime' => 'resico', 'monthly_income' => 20_000.0, 'is_border_north_zone' => false ], $vendor );
		return \LTMS_Tax_Engine::calculate( $gross, $order, $vendor, 'MX' );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-002 — vocabulario LTMS en estrategia CO
	// ------------------------------------------------------------------ //

	public function test_co_booking_ltms_type_uses_tourism_iva_7(): void {
		// 'booking' es el tipo LTMS real para hospedaje (products-ajax.php:1003).
		// Sin TRIB-002, 'booking' caía al 19% general.
		$result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'booking' ] );
		$this->assertSame( 0.07, $result['iva_rate'], 'TRIB-002: booking LTMS debe aplicar IVA turismo 7% CO (E.T. art. 468-1)' );
		$this->assertSame( 70_000.0, $result['iva'] );
	}

	public function test_co_legacy_accommodation_type_keeps_tourism_iva_7(): void {
		// No-regresión: los tipos legacy de la estrategia siguen funcionando.
		$result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'accommodation' ] );
		$this->assertSame( 0.07, $result['iva_rate'] );
	}

	public function test_co_is_booking_flag_uses_tourism_iva_7(): void {
		// TRIB-006: el flag is_booking del order_data debe abrir el gate NT-1.
		$result = $this->co_calculate( 1_000_000.0, [ 'is_booking' => true ] );
		$this->assertSame( 0.07, $result['iva_rate'] );
	}

	public function test_co_digital_ltms_type_uses_tech_retefuente_6(): void {
		// 'digital' es el tipo LTMS real para productos digitales — debe caer en la
		// rama tech (6% con mínimo 2.666 UVT) y no en servicios generales (4%).
		// Gross 1M COP > min servicios (2.666 × 52752 = 140,640) → 6%.
		$result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'digital' ], [ 'tax_regime' => 'common' ] );
		$this->assertSame( 0.06, $result['retefuente_rate'], 'TRIB-002: digital LTMS debe aplicar ReteFuente tech 6% CO' );
		$this->assertSame( 60_000.0, $result['retefuente'] );
	}

	public function test_co_restaurant_ltms_type_applies_impoconsumo_8(): void {
		// 'restaurant' ya matcheaba la rama INC — no-regresión con tipo real LTMS.
		$result = $this->co_calculate( 1_000_000.0, [ 'product_type' => 'restaurant' ] );
		$this->assertSame( 0.08, $result['impoconsumo_rate'] );
		$this->assertSame( 80_000.0, $result['impoconsumo'] );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-002 — vocabulario LTMS en estrategia MX
	// ------------------------------------------------------------------ //

	public function test_mx_booking_ltms_type_applies_ish(): void {
		// ISH (LIEPS art. 2-B): hospedaje → 3% default por estado (configurable).
		// Sin TRIB-002, 'booking' no activaba el ISH.
		$result = $this->mx_calculate(
			1_000_000.0,
			[ 'product_type' => 'booking', 'shipping_state' => ' quintana_roo ' ]
		);
		$this->assertSame( 0.03, $result['ish_rate'], 'TRIB-002: booking LTMS debe aplicar ISH MX (3% default)' );
		$this->assertSame( 30_000.0, $result['ish'] );
	}

	public function test_mx_is_booking_flag_applies_ish(): void {
		// TRIB-006: el flag is_booking debe abrir el gate NT-2 (ISH).
		$result = $this->mx_calculate( 1_000_000.0, [ 'is_booking' => true ] );
		$this->assertSame( 0.03, $result['ish_rate'] );
		$this->assertSame( 30_000.0, $result['ish'] );
	}

	public function test_mx_physical_keeps_no_ish(): void {
		// No-regresión: physical NO paga ISH.
		$result = $this->mx_calculate( 1_000_000.0 );
		$this->assertSame( 0.0, $result['ish_rate'] );
		$this->assertSame( 0.0, $result['ish'] );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-001 — resolución de product_type en el Order Split (source-based)
	// ------------------------------------------------------------------ //

	private function split_source(): string {
		$path = dirname( __DIR__, 2 ) . '/includes/business/class-ltms-order-split.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_split_grouping_captures_product_type_from_product_meta(): void {
		// TRIB-001: group_items_by_vendor debe capturar el tipo desde el post meta
		// del producto (el order meta `_ltms_product_type` no es escrito por nadie).
		$src = $this->split_source();
		$this->assertStringContainsString(
			"get_post_meta( \$product_id, '_ltms_product_type', true )",
			$src,
			'TRIB-001: el agrupador debe leer el tipo desde el post meta del producto.'
		);
		$this->assertStringContainsString(
			'TRIB-001 FIX',
			$src,
			'TRIB-001: tag de trazabilidad requerido en order-split.'
		);
	}

	public function test_split_normalizes_legacy_product_types(): void {
		// Normalización '' / 'product' → 'physical' (mismo mapeo que Commission_Strategy).
		$src = $this->split_source();
		$this->assertStringContainsString(
			'normalize_ltms_product_type',
			$src,
			'TRIB-001: debe existir normalizador de tipos LTMS en el split.'
		);
		$this->assertStringContainsString(
			"'product' === \$raw",
			$src,
			'TRIB-001: el tipo legacy product debe normalizarse a physical.'
		);
	}

	public function test_split_passes_product_type_to_tax_breakdown(): void {
		// El quinto parámetro (product_type del grupo) debe fluir de process() al breakdown.
		$src = $this->split_source();
		$this->assertStringContainsString(
			"(string) ( \$data['product_type'] ?? '' )",
			$src,
			'TRIB-001: process() debe pasar el product_type del grupo al breakdown fiscal.'
		);
	}

	public function test_split_exposes_is_booking_flag_to_engine(): void {
		// TRIB-006: is_booking debe construirse desde el tipo resuelto.
		$src = $this->split_source();
		$this->assertStringContainsString(
			"'is_booking'   => ( 'booking' === \$resolved_type )",
			$src,
			'TRIB-006: order_data debe exponer is_booking según el tipo resuelto.'
		);
	}

	public function test_strategies_carry_trib002_traceability_tags(): void {
		$co_path = dirname( __DIR__, 2 ) . '/includes/business/strategies/class-ltms-tax-strategy-colombia.php';
		$mx_path = dirname( __DIR__, 2 ) . '/includes/business/strategies/class-ltms-tax-strategy-mexico.php';
		$this->assertStringContainsString( 'TRIB-002 FIX', (string) file_get_contents( $co_path ) );
		$this->assertStringContainsString( 'TRIB-002 FIX', (string) file_get_contents( $mx_path ) );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-003 — writer fiscal deja de fabricar impuestos (source-based)
	// ------------------------------------------------------------------ //

	private function writer_source(): string {
		$path = dirname( __DIR__, 2 ) . '/includes/admin/class-ltms-commission-writer.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	public function test_writer_no_longer_fabricates_co_iva_19_fixed(): void {
		$src = $this->writer_source();
		$this->assertStringNotContainsString(
			"round( \$gross * self::IVA_CO, 2 )",
			$src,
			'TRIB-003: el writer no debe calcular IVA CO fijo 19% — el engine es la única fuente.'
		);
		$this->assertStringNotContainsString(
			'const IVA_CO',
			$src,
			'TRIB-003: la constante muerta IVA_CO debe estar eliminada.'
		);
	}

	public function test_writer_no_longer_fabricates_unconditional_reteiva(): void {
		$src = $this->writer_source();
		$this->assertStringNotContainsString(
			"\$iva * self::RETEIVA_CO_RATE",
			$src,
			'TRIB-003: ReteIVA CO incondicional del writer debe desaparecer (contradecía T3 FIX de la estrategia).'
		);
		$this->assertStringNotContainsString(
			'const RETEIVA_CO_RATE',
			$src,
			'TRIB-003: la constante muerta RETEIVA_CO_RATE debe estar eliminada.'
		);
	}

	public function test_writer_no_longer_fabricates_mx_isr_1_percent(): void {
		$src = $this->writer_source();
		$this->assertStringNotContainsString(
			"round( \$gross * self::ISR_MX_RATE, 2 )",
			$src,
			'TRIB-003: ISR MX 1% fijo del writer debe desaparecer (vs tramos RESICO del engine).'
		);
		$this->assertStringNotContainsString(
			'const ISR_MX_RATE',
			$src,
			'TRIB-003: la constante muerta ISR_MX_RATE debe estar eliminada.'
		);
	}

	public function test_writer_update_branch_has_no_money_columns(): void {
		$src = $this->writer_source();
		// El UPDATE de la fila existente solo lleva campos de reporte Frac. I/II —
		// las columnas de dinero (reteiva_amount/isr_amount) quedan para el engine.
		$pos_update = strpos( $src, '$data = [' );
		$this->assertNotFalse( $pos_update, 'Estructura del writer: bloque $data debe existir.' );
		$block = substr( $src, $pos_update, 1200 );
		$this->assertStringNotContainsString( "'isr_amount'", $block, 'TRIB-003: el UPDATE no debe tocar isr_amount.' );
		$this->assertStringNotContainsString( "'reteiva_amount'", $block, 'TRIB-003: el UPDATE no debe tocar reteiva_amount.' );
		$this->assertStringContainsString( 'TRIB-003 FIX', $src, 'TRIB-003: tag de trazabilidad requerido en el writer.' );
	}

	public function test_split_persists_per_tax_columns_on_insert(): void {
		$src = $this->split_source();
		foreach ( [ 'retefuente_amount', 'reteiva_amount', 'reteica_amount', 'isr_amount', 'ieps_amount', 'impoconsumo_amount' ] as $col ) {
			$this->assertStringContainsString(
				"'{$col}'",
				$src,
				"TRIB-003: record_commission debe persistir {$col} desde el breakdown del engine."
			);
		}
	}

	// ------------------------------------------------------------------ //
	//  TRIB-004 — Siigo: IVA por item según tipo LTMS (e-invoice DIAN)
	// ------------------------------------------------------------------ //

	private function siigo_resolve( string $product_type ): array {
		$path = dirname( __DIR__, 2 ) . '/includes/api/class-ltms-api-siigo.php';
		if ( ! class_exists( 'LTMS_Api_Siigo', false ) ) {
			require_once $path;
		}
		$this->assertTrue(
			class_exists( 'LTMS_Api_Siigo' ),
			'TRIB-004: LTMS_Api_Siigo debe poder cargarse en modo unit (abstract stubbed).'
		);
		return \LTMS_Api_Siigo::resolve_item_tax_ids( $product_type );
	}

	public function test_siigo_exempt_product_types_get_no_tax(): void {
		// Medicamentos/alimentos básicos: IVA 0% — el doc DIAN no debe llevar
		// el tax_id 19% por defecto.
		foreach ( [ 'basic_food', 'medicine', 'health_service', 'education', 'agricultural_basic' ] as $type ) {
			$this->assertSame( [], $this->siigo_resolve( $type ), "TRIB-004: {$type} es exento de IVA — sin taxes en Siigo." );
		}
	}

	public function test_siigo_default_physical_gets_configurable_19_tax_id(): void {
		// No-regresión: physical conserva el comportamiento legacy (default 29).
		$this->assertSame( [ [ 'id' => 29 ] ], $this->siigo_resolve( 'physical' ) );
		$this->assertSame( [ [ 'id' => 29 ] ], $this->siigo_resolve( '' ) );
	}

	public function test_siigo_booking_uses_configured_7_tax_id(): void {
		\LTMS_Core_Config::set( 'ltms_siigo_tax_id_7', 77 );
		$this->assertSame( [ [ 'id' => 77 ] ], $this->siigo_resolve( 'booking' ), 'TRIB-004: booking con tax ID 7% configurado debe usarlo.' );
	}

	public function test_siigo_booking_without_7_tax_id_omits_tax(): void {
		// Sin ltms_siigo_tax_id_7 configurado: OMITIR el impuesto (no aplicar 19%
		// a un item 7% — sobrefacturación DIAN). El warning va por log.
		\LTMS_Core_Config::set( 'ltms_siigo_tax_id_7', 0 );
		$this->assertSame( [], $this->siigo_resolve( 'booking' ) );
	}

	public function test_siigo_reduced_type_uses_configured_5_tax_id(): void {
		\LTMS_Core_Config::set( 'ltms_siigo_tax_id_5', 55 );
		$this->assertSame( [ [ 'id' => 55 ] ], $this->siigo_resolve( 'coffee' ), 'TRIB-004: tipo reducido 5% con tax ID configurado debe usarlo.' );
		\LTMS_Core_Config::set( 'ltms_siigo_tax_id_5', 0 );
		$this->assertSame( [], $this->siigo_resolve( 'coffee' ), 'TRIB-004: 5% sin configurar se omite (no 19%).' );
	}

	public function test_siigo_build_invoice_uses_per_item_resolver(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/api/class-ltms-api-siigo.php' );
		$this->assertStringContainsString(
			'self::resolve_item_tax_ids(',
			$src,
			'TRIB-004: build_invoice_payload debe resolver taxes por item.'
		);
		$this->assertStringContainsString( 'TRIB-004 FIX', $src, 'TRIB-004: tag de trazabilidad requerido.' );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-005 — label IVA del PDF de factura usa la tasa real
	// ------------------------------------------------------------------ //

	/**
	 * Renderiza template-invoice.php con $data dado y devuelve el HTML.
	 */
	private function render_invoice_template( array $data ): string {
		Monkey\Functions\stubs( [
			'__'            => static fn( $t ) => $t,
			'esc_html_e'    => static fn( $t ) => print $t,
			'esc_html'      => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
			'esc_attr'      => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
		] );
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/pdf/template-invoice.php';
		return (string) ob_get_clean();
	}

	public function test_invoice_label_shows_real_tourism_rate_7(): void {
		$html = $this->render_invoice_template( [
			'country'    => 'CO',
			'currency'   => 'COP',
			'items'      => [ [ 'name' => 'Hospedaje', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 7, 'tax_amount' => 7000, 'subtotal' => 100000 ] ],
			'subtotal'   => 100000,
			'tax_total'  => 7000,
		] );
		$this->assertStringContainsString( 'IVA (7%)', $html, 'TRIB-005: hospedaje 7% debe etiquetarse como IVA (7%), no (19%).' );
		$this->assertStringNotContainsString( 'IVA (19%)', $html );
	}

	public function test_invoice_label_falls_back_to_country_general_rate(): void {
		// Items sin tasa > 0 y sin iva_rate en el desglose → fallback por país.
		$html = $this->render_invoice_template( [
			'country'   => 'CO',
			'currency'  => 'COP',
			'items'     => [ [ 'name' => 'Medicamento', 'quantity' => 1, 'unit_price' => 50000, 'tax_rate' => 0, 'tax_amount' => 0, 'subtotal' => 50000 ] ],
			'subtotal'  => 50000,
			'tax_total' => 0,
		] );
		$this->assertStringContainsString( 'IVA (19%)', $html, 'TRIB-005: sin tasa detectable, fallback CO = 19%.' );
	}

	public function test_invoice_label_uses_breakdown_iva_rate(): void {
		// Sin items con tasa pero con iva_rate en el desglose del engine (0.07 → 7%).
		$html = $this->render_invoice_template( [
			'country'   => 'CO',
			'currency'  => 'COP',
			'items'     => [ [ 'name' => 'Tour', 'quantity' => 2, 'unit_price' => 50000, 'tax_rate' => 0, 'tax_amount' => 0, 'subtotal' => 100000 ] ],
			'iva_rate'  => 0.07,
			'subtotal'  => 100000,
			'tax_total' => 7000,
		] );
		$this->assertStringContainsString( 'IVA (7%)', $html, 'TRIB-005: el iva_rate del breakdown debe usarse como label.' );
	}

	public function test_invoice_label_mx_shows_16_general(): void {
		$html = $this->render_invoice_template( [
			'country'   => 'MX',
			'currency'  => 'MXN',
			'items'     => [ [ 'name' => 'Producto', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 16, 'tax_amount' => 160, 'subtotal' => 1000 ] ],
			'subtotal'  => 1000,
			'tax_total' => 160,
		] );
		$this->assertStringContainsString( 'IVA (16%)', $html, 'TRIB-005: MX general 16% se preserva.' );
	}

	public function test_invoice_template_carries_trib005_tag(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/pdf/template-invoice.php' );
		$this->assertStringContainsString( 'TRIB-005 FIX', $src, 'TRIB-005: tag de trazabilidad requerido.' );
	}

	// ------------------------------------------------------------------ //
	//  TRIB-BACKFILL-2026-11-26 — backfill de columnas fiscales históricas
	// ------------------------------------------------------------------ */

	private function load_backfill_class(): string {
		$path = dirname( __DIR__, 2 ) . '/deploy/ltms-backfill-fiscal-tax-columns-2026-11-26.php';
		if ( ! class_exists( 'LTMS_Backfill_Fiscal_Tax_20261126', false ) ) {
			require_once $path;
		}
		$this->assertTrue(
			class_exists( 'LTMS_Backfill_Fiscal_Tax_20261126' ),
			'TRIB-BACKFILL: la clase testeable debe cargarse sin ejecutar el runner (WP_CLI no definido).'
		);
		return (string) file_get_contents( $path );
	}

	public function test_backfill_compute_matches_engine_for_booking_co(): void {
		// Caso ancla del P0: pedido booking CO de 100k — el writer viejo habría
		// dejado iva 19% (19000); el backfill debe dejar 7% (7000) + reteica 414.
		$this->load_backfill_class();
		$computed = \LTMS_Backfill_Fiscal_Tax_20261126::compute_per_tax_update(
			100000.0,
			'booking',
			[ 'tax_regime' => 'common', 'ciiu_code' => '4791', 'municipality_code' => '' ],
			[],
			'CO'
		);
		$this->assertEqualsWithDelta( 7000.0, $computed['iva_amount'], 0.01, 'TRIB-BACKFILL: booking CO = IVA 7% (no el 19% fabricado por el writer).' );
		$this->assertEqualsWithDelta( 0.0, $computed['reteiva_amount'], 0.01 );
		$this->assertEqualsWithDelta( 414.0, $computed['reteica_amount'], 0.01 );
		$this->assertEqualsWithDelta( 0.0, $computed['impoconsumo_amount'], 0.01 );
	}

	public function test_backfill_compute_matches_engine_for_physical_co(): void {
		// No-regresión: physical CO 100k → IVA 19%, mismas cifras que el split vivo.
		$this->load_backfill_class();
		$computed = \LTMS_Backfill_Fiscal_Tax_20261126::compute_per_tax_update(
			100000.0,
			'physical',
			[ 'tax_regime' => 'common', 'ciiu_code' => '4791', 'municipality_code' => '' ],
			[],
			'CO'
		);
		$this->assertEqualsWithDelta( 19000.0, $computed['iva_amount'], 0.01 );
		$this->assertEqualsWithDelta( 0.0, $computed['retefuente_amount'], 0.01, 'physical 100k < min compras (10.666 UVT) → sin ReteFuente.' );
	}

	public function test_backfill_row_is_aligned_is_idempotent(): void {
		$this->load_backfill_class();
		$row = (object) [
			'iva_amount' => '7000.00', 'retefuente_amount' => '0.00', 'retefuente_rate' => '0.000000',
			'reteiva_amount' => '0.00', 'reteiva_rate' => '0.000000',
			'reteica_amount' => '414.00', 'reteica_rate' => '0.004140',
			'isr_amount' => '0.00', 'isr_rate' => '0.000000',
			'ieps_amount' => '0.00', 'impoconsumo_amount' => '0.00',
		];
		$computed = \LTMS_Backfill_Fiscal_Tax_20261126::compute_per_tax_update(
			100000.0, 'booking',
			[ 'tax_regime' => 'common', 'ciiu_code' => '4791', 'municipality_code' => '' ],
			[], 'CO'
		);
		$this->assertTrue( \LTMS_Backfill_Fiscal_Tax_20261126::row_is_aligned( $row, $computed ), 'Fila ya alineada debe saltarse (idempotencia).' );

		$row->reteiva_amount = '2850.00'; // valor fabricado del writer viejo
		$this->assertFalse( \LTMS_Backfill_Fiscal_Tax_20261126::row_is_aligned( $row, $computed ), 'Fila con ReteIVA fabricada debe detectarse como desalineada.' );
	}

	public function test_backfill_runner_does_not_touch_money_settlement_columns(): void {
		$src = $this->load_backfill_class();
		// El UPDATE del backfill solo lleva columnas del desglose fiscal — las
		// columnas de liquidación de wallet (vendor_amount/tax_withholding/
		// commission) reflejan lo REALMENTE pagado y no se reescriben.
		$pos = strpos( $src, '$wpdb->update(' );
		$this->assertNotFalse( $pos, 'El runner debe usar $wpdb->update.' );
		$update_block = substr( $src, $pos, 500 );
		$this->assertStringNotContainsString( 'vendor_amount', $update_block, 'TRIB-BACKFILL: vendor_amount NO se reescribe (dinero ya liquidado).' );
		$this->assertStringNotContainsString( 'tax_withholding', $update_block, 'TRIB-BACKFILL: tax_withholding NO se reescribe.' );
		$this->assertStringNotContainsString( 'commission_amount', $update_block, 'TRIB-BACKFILL: commission_amount NO se reescribe.' );
	}

	public function test_backfill_requires_db_backup_and_has_dry_run(): void {
		$src = $this->load_backfill_class();
		$this->assertStringContainsString( 'wp db export', $src, 'TRIB-BACKFILL: pre-requisito obligatorio de backup de BD documentado.' );
		$this->assertStringContainsString( 'LTMS_BACKFILL_DRY_RUN', $src, 'TRIB-BACKFILL: soporte dry-run obligatorio para preview.' );
		$this->assertStringContainsString( 'TRIB-BACKFILL-2026-11-26', $src, 'TRIB-BACKFILL: tag de trazabilidad requerido.' );
	}
}
