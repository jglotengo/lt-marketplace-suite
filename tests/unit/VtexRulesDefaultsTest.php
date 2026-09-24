<?php
/**
 * VtexRulesDefaultsTest — tests de las reglas VTEX tras VTEX-RULES-FIX.
 *
 * El cliente Kosmetic reportó que el recálculo de precios no respetaba los
 * cambios de reglas: el formulario colocaba valores "que ya antes se habían
 * cambiado y guardado" y no persistían. Auditoría con evidencia:
 *
 *   H1 (P0): Contaminación cruzada de selectores JS — el SPA renderiza PosGold
 *   y VTEX juntas en el mismo DOM (dashboard-wrapper.php renderiza ambas
 *   .ltms-view-section; el SPA solo las muestra/oculta) y ambas vistas usan
 *   inputs con idéntico name. ltms-vtex.js leía con selectores globales
 *   $('input[name="transport_pct"]') → jQuery .val() retorna el PRIMER match
 *   (el form PosGold) → al guardar/recalcular VTEX se enviaban los valores de
 *   PosGold y los cambios del vendor VTEX no persistían.
 *
 *   H2 (P0): Comisión Lo Tengo default 10% (heredada de PosGold) → debe ser 12.
 *   H3/H4 (P1): Transporte y gasto publicitario porcentuales → deben ser MONTO
 *   FIJO en COP/MXN según la configuración (país del vendor con fallback al
 *   país de operación del sitio).
 *
 * Este test cubre: defaults VTEX (comisión 12, montos fijos), la fórmula de
 * cálculo con montos fijos (y el modo % legacy de PosGold intacto), el
 * roundtrip de persistencia save/get de las keys nuevas, el scoping de
 * selectores JS en ambos forms, los campos de la vista y los defaults del
 * handler AJAX.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group vtex-rules
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class VtexRulesDefaultsTest
 *
 * @group vtex-rules
 */
final class VtexRulesDefaultsTest extends LTMS_Unit_Test_Case {

	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function src( string $relative ): string {
		$file = $this->plugin_path( $relative );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( "$relative no disponible." );
		}
		return (string) file_get_contents( $file );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// H2: defaults VTEX — comisión Lo Tengo 12, transporte/publicitario fijo.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_vtex_defaults_commission_lotengo_is_12(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$defaults = \LTMS_Vtex_Price_Calculator::get_defaults();

		$this->assertSame( 12.0, (float) $defaults['lotengo_commission_pct'],
			'La comisión Lo Tengo default en las reglas VTEX debe ser 12 (antes 10 heredado de PosGold).' );
	}

	public function test_vtex_defaults_use_fixed_transport_and_advertising_amounts(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$defaults = \LTMS_Vtex_Price_Calculator::get_defaults();

		$this->assertArrayHasKey( 'transport_amount', $defaults,
			'Los defaults VTEX deben incluir transport_amount (monto fijo COP/MXN).' );
		$this->assertArrayHasKey( 'advertising_amount', $defaults,
			'Los defaults VTEX deben incluir advertising_amount (monto fijo COP/MXN).' );
		$this->assertArrayNotHasKey( 'transport_pct', $defaults,
			'Los defaults VTEX NO deben incluir transport_pct (el transporte ya no es porcentual).' );
		$this->assertArrayNotHasKey( 'advertising_pct', $defaults,
			'Los defaults VTEX NO deben incluir advertising_pct (la publicidad ya no es porcentual).' );
	}

	public function test_posgold_defaults_keep_pct_mode_untouched(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$defaults = \LTMS_PosGold_Price_Calculator::get_defaults();

		$this->assertSame( 10.0, (float) $defaults['lotengo_commission_pct'],
			'PosGold queda fuera de alcance: su comisión default debe seguir siendo 10.' );
		$this->assertArrayHasKey( 'transport_pct', $defaults,
			'PosGold mantiene su modo % (transport_pct).' );
		$this->assertArrayNotHasKey( 'transport_amount', $defaults,
			'PosGold no debe heredar las keys de monto fijo de VTEX.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// H3/H4: fórmula — montos fijos VTEX y modo % legacy PosGold.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_calculate_uses_fixed_transport_and_advertising(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$rules = \LTMS_Vtex_Price_Calculator::get_defaults();
		$rules['transport_amount']   = 5000.0;
		$rules['advertising_amount'] = 3000.0;

		$calc = \LTMS_Vtex_Price_Calculator::calculate( 50000, $rules );

		// Fijo: NO depende del costo (5000 y 3000, no % de 50000).
		$this->assertSame( 5000.0, (float) $calc['breakdown']['transport'],
			'El transporte debe aplicarse como MONTO FIJO (5000), no como % del costo.' );
		$this->assertSame( 3000.0, (float) $calc['breakdown']['advertising'],
			'La publicidad debe aplicarse como MONTO FIJO (3000), no como % del costo.' );
		$this->assertSame( 58000.0, (float) $calc['breakdown']['subtotal_gastos'],
			'Subtotal gastos = 50000 + 5000 + 3000.' );
	}

	public function test_calculate_pct_mode_still_works_for_posgold(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		// Reglas estilo PosGold (solo %, sin keys de monto fijo): backward compat.
		$rules = \LTMS_PosGold_Price_Calculator::get_defaults();
		$rules['transport_pct']   = 10.0;
		$rules['advertising_pct'] = 5.0;

		$calc = \LTMS_PosGold_Price_Calculator::calculate( 50000, $rules );

		$this->assertSame( 5000.0, (float) $calc['breakdown']['transport'],
			'PosGold: transporte 10% de 50000 = 5000 (modo % intacto).' );
		$this->assertSame( 2500.0, (float) $calc['breakdown']['advertising'],
			'PosGold: publicidad 5% de 50000 = 2500 (modo % intacto).' );
	}

	public function test_calculate_fixed_amounts_full_formula_commission_12(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$rules = \LTMS_Vtex_Price_Calculator::get_defaults();
		$rules['transport_amount']   = 5000.0;
		$rules['advertising_amount'] = 3000.0;

		$calc = \LTMS_Vtex_Price_Calculator::calculate( 50000, $rules );

		// 58000 * 1.30 = 75400 → gross-up 12%: 75400/0.88 = 85681.82 →
		// * 1.19 (IVA) = 101961.36 → redondeado por encima a 102000.
		$this->assertSame( 102000.0, (float) $calc['price'],
			'Fórmula completa con comisión 12% y montos fijos: 50000 → 102000.' );
		$this->assertSame( $calc['price'], \LTMS_PosGold_Price_Calculator::calculate( 50000, $rules )['price'],
			'La delegación VTEX→PosGold con montos fijos debe dar el mismo precio.' );
	}

	public function test_calculate_without_pct_keys_does_not_crash(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		// Los defaults VTEX no tienen transport_pct/advertising_pct — el
		// calculate() compartido no debe lanzar undefined index.
		$calc = \LTMS_Vtex_Price_Calculator::calculate( 84000, \LTMS_Vtex_Price_Calculator::get_defaults() );

		$this->assertSame( 0.0, (float) $calc['breakdown']['transport'],
			'Sin monto configurado, el transporte es 0.' );
		$this->assertSame( 148000.0, (float) $calc['price'],
			'Con defaults VTEX (comisión 12%): 84000 → 148000.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Persistencia: roundtrip save/get de las keys nuevas (H1 — queja Kosmetic).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_rules_roundtrip_persists_new_keys(): void {
		$this->require_class( 'LTMS_Vtex_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		$store = [];
		Monkey\Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key = '', $value = '' ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key = '', $single = false ) use ( &$store ) {
				return $store[ $key ] ?? '';
			}
		);

		$rules = [
			'is_redi'                => true,
			'transport_amount'       => 7500.0,
			'advertising_amount'     => 3200.0,
			'returns_pct'            => 2.5,
			'margin_pct'             => 25.0,
			'lotengo_commission_pct' => 12.0,
			'iva_pct'                => 16.0,
			'redi_cost_pct'          => 5.0,
			'round_multiple'         => 500,
		];

		\LTMS_Vtex_Price_Calculator::save_vendor_rules( 223, $rules );
		$saved = \LTMS_Vtex_Price_Calculator::get_vendor_rules( 223 );

		$this->assertSame( 7500.0, (float) $saved['transport_amount'],
			'El transporte fijo guardado debe persistir (roundtrip save/get).' );
		$this->assertSame( 3200.0, (float) $saved['advertising_amount'],
			'La publicidad fija guardada debe persistir (roundtrip save/get).' );
		$this->assertSame( 12.0, (float) $saved['lotengo_commission_pct'],
			'La comisión guardada debe persistir (roundtrip save/get).' );
		$this->assertTrue( $saved['is_redi'], 'El flag ReDi debe persistir como true.' );
		$this->assertSame( 500.0, (float) $saved['round_multiple'], 'El redondeo debe persistir.' );

		// Los metas % legacy VTEX no deben escribirse en el save.
		$this->assertArrayNotHasKey( 'ltms_vtex_price_transport_pct', $store,
			'El save VTEX no debe persistir la key % legacy transport_pct.' );
		$this->assertArrayNotHasKey( 'ltms_vtex_price_advertising_pct', $store,
			'El save VTEX no debe persistir la key % legacy advertising_pct.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// H1: scoping de selectores JS (contaminación cruzada PosGold/VTEX).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_vtex_js_scopes_rules_selectors_to_own_form(): void {
		$js = $this->src( 'assets/js/ltms-vtex.js' );

		$this->assertStringContainsString( "$('#ltms-vtex-rules-form')", $js,
			'El JS VTEX debe scopear la lectura de reglas al form #ltms-vtex-rules-form.' );
		$this->assertStringContainsString( '$' . 'form.find(\'input[name="transport_amount"]\')', $js,
			'El submit debe leer transport_amount SCOPED al form.' );
		$this->assertStringContainsString( '$' . 'form.find(\'input[name="advertising_amount"]\')', $js,
			'El submit debe leer advertising_amount SCOPED al form.' );
		$this->assertStringNotContainsString( '$' . '(\'input[name="transport_pct"]\')', $js,
			'NO debe existir el selector global de transport_pct (leía el form PosGold — H1).' );
		$this->assertStringNotContainsString( '$' . '(\'input[name="advertising_pct"]\')', $js,
			'NO debe existir el selector global de advertising_pct (leía el form PosGold — H1).' );
		$this->assertStringNotContainsString( 'transport_pct:', $js,
			'El POST de reglas VTEX no debe enviar transport_pct (key % legacy removida).' );
	}

	public function test_posgold_js_scopes_rules_selectors_to_own_form(): void {
		$js = $this->src( 'assets/js/ltms-posgold.js' );

		$this->assertStringContainsString( "$('#ltms-posgold-rules-form')", $js,
			'El JS PosGold debe scopear la lectura de reglas al form #ltms-posgold-rules-form.' );
		$this->assertStringContainsString( '$' . 'form.find(\'input[name="transport_pct"]\')', $js,
			'PosGold mantiene %: el submit debe leer transport_pct SCOPED a su form.' );
		$this->assertStringNotContainsString( '$' . '(\'input[name="transport_pct"]\')', $js,
			'NO debe existir el selector global (depende del orden del DOM — H1).' );
	}

	public function test_min_js_in_sync_with_dev_fixes(): void {
		$vtex_min = $this->src( 'assets/js/ltms-vtex.min.js' );
		$posgold_min = $this->src( 'assets/js/ltms-posgold.min.js' );

		// Producción (SCRIPT_DEBUG off) sirve el .min — debe reflejar los mismos
		// fixes que el dev (AGENTS.md: hay que sincronizar ambos).
		$this->assertStringContainsString( 'transport_amount', $vtex_min,
			'El min VTEX debe contener transport_amount (monto fijo).' );
		$this->assertStringContainsString( 'advertising_amount', $vtex_min,
			'El min VTEX debe contener advertising_amount (monto fijo).' );
		$this->assertStringContainsString( 'attr("data-currency")', $vtex_min,
			'El min VTEX debe leer data-currency del form con .attr (lectura fresca, moneda COP/MXN).' );
		$this->assertStringNotContainsString( 'transport_pct', $vtex_min,
			'El min VTEX no debe contener la key % legacy transport_pct.' );
		$this->assertStringContainsString( 'transport_pct', $posgold_min,
			'El min PosGold debe mantener transport_pct (modo % de PosGold).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Vista y handler — campos monto fijo con moneda según configuración.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_view_vtex_renders_fixed_amount_fields_with_currency(): void {
		$view = $this->src( 'includes/frontend/views/view-vtex.php' );

		$this->assertStringContainsString( 'name="transport_amount"', $view,
			'La vista debe renderizar transport_amount (monto fijo).' );
		$this->assertStringContainsString( 'name="advertising_amount"', $view,
			'La vista debe renderizar advertising_amount (monto fijo).' );
		$this->assertStringNotContainsString( 'name="transport_pct"', $view,
			'La vista VTEX no debe renderizar transport_pct (% legacy).' );
		$this->assertStringNotContainsString( 'name="advertising_pct"', $view,
			'La vista VTEX no debe renderizar advertising_pct (% legacy).' );
		$this->assertStringContainsString( 'data-currency=', $view,
			'El form de reglas debe exponer data-currency para el ejemplo del JS.' );
		$this->assertStringContainsString( "'ltms_country'", $view,
			'La moneda debe resolverse con el país del vendor (ltms_country, fallback LTMS_Core_Config::get_country).' );
		$this->assertStringContainsString( 'LTMS_Core_Config::get_country()', $view,
			'La moneda debe caer al país de operación del sitio si el vendor no tiene país.' );
		$this->assertStringContainsString( "'MXN'", $view,
			'Debe existir la etiqueta MXN para México.' );
		$this->assertStringContainsString( "'COP'", $view,
			'Debe existir la etiqueta COP para Colombia.' );
	}

	public function test_save_vtex_rules_handler_reads_new_keys_and_defaults(): void {
		$logic = $this->src( 'includes/frontend/class-ltms-dashboard-logic.php' );

		$this->assertStringContainsString( '\'lotengo_commission_pct\' => (float) ( $_POST[\'lotengo_commission_pct\'] ?? 12 )', $logic,
			'El fallback de la comisión Lo Tengo en VTEX debe ser 12.' );
		$this->assertStringContainsString( '\'transport_amount\'       => (float) ( $_POST[\'transport_amount\'] ?? 0 )', $logic,
			'El handler debe leer transport_amount (monto fijo).' );
		$this->assertStringContainsString( '\'advertising_amount\'     => (float) ( $_POST[\'advertising_amount\'] ?? 0 )', $logic,
			'El handler debe leer advertising_amount (monto fijo).' );
		$this->assertStringNotContainsString( '$POST[\'transport_pct\']', $logic,
			'El handler VTEX no debe leer transport_pct (% legacy removida de VTEX).' );
		$this->assertStringNotContainsString( '$POST[\'advertising_pct\']', $logic,
			'El handler VTEX no debe leer advertising_pct (% legacy removida de VTEX).' );
		$this->assertStringContainsString( 'min( 10000000, $rules[\'transport_amount\'] )', $logic,
			'Los montos fijos NO se limitan a 0-100: tope 10.000.000.' );
	}
}
