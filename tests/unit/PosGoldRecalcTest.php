<?php
/**
 * PosGoldRecalcTest — tests del recálculo masivo de precios PosGold (POSGOLD-RECALC).
 *
 * Paridad con RecalcPricesTest (VTEX/PRICE-RECALC): la sync PosGold pre-fix
 * NO persistía el costo original del producto → no había forma de re-preciar
 * el catálogo al cambiar reglas sin re-sincronizar desde la API. Además, el
 * vendor Jugueteria Taiwan (UID 168) no tiene reglas persistidas (metas
 * ltms_posgold_price_* vacías en producción — el guardado moría con "Módulo
 * PosGold no disponible" mientras la calculadora estuvo ausente del server
 * hasta 2026-09-23, lección #177).
 *
 * Este test cubre:
 *   - la sync persiste el costo original en el meta _ltms_posgold_cost
 *     (create_product + update_product_fields).
 *   - el handler ajax_recalculate_posgold_prices existe y el hook AJAX está
 *     registrado (paridad ajax_recalculate_vtex_prices).
 *   - el JS guarda las reglas del form ANTES de recalcular y encadena lotes
 *     con reintento multi-URL (paridad PRICE-RECALC-SAVE + PRICE-RECALC-NET).
 *   - la fórmula con defaults PosGold: 50.000 → 86.000 (margen 30%, comisión
 *     10% gross-up, IVA 19%, redondeo 1.000).
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --group posgold-recalc
 *
 * @group posgold-recalc
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class PosGoldRecalcTest
 *
 * @group posgold-recalc
 */
final class PosGoldRecalcTest extends LTMS_Unit_Test_Case {

	private function require_classes(): void {
		$this->require_class( 'LTMS_Dashboard_Logic' );
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );
		$this->require_class( 'LTMS_PosGold_Sync' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Sync persiste el costo original (paridad VTEX COST_META_KEY)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_posgold_sync_persists_cost_meta_before_rules(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );
		$src = file_get_contents( __DIR__ . '/../../includes/business/class-ltms-posgold-sync.php' );

		$this->assertStringContainsString(
			'$product[\'_ltms_cost\'] = $price_calc[\'cost\'];',
			$src,
			'La sync debe persistir el costo (price_calc.cost) ANTES de sobreescribir regular_price.'
		);
		$this->assertStringContainsString(
			"COST_META_KEY = '_ltms_posgold_cost'",
			$src,
			'Debe existir la constante COST_META_KEY con el meta _ltms_posgold_cost.'
		);
	}

	public function test_posgold_sync_writes_cost_meta_on_create_and_update(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );
		$src = file_get_contents( __DIR__ . '/../../includes/business/class-ltms-posgold-sync.php' );

		// Debe escribir el meta en create_product y update_product_fields.
		$count = substr_count( $src, 'update_meta_data( self::COST_META_KEY' );
		$this->assertGreaterThanOrEqual(
			2,
			$count,
			'El meta de costo debe escribirse en create_product y update_product_fields.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fórmula con defaults PosGold (50.000 → 86.000)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_posgold_defaults_calculate_price(): void {
		$this->require_class( 'LTMS_PosGold_Price_Calculator' );

		// UNIT_ONLY: get_user_meta no existe sin WP — stub con metas vacías
		// (reglas default, mismo patrón de RecalcPricesTest).
		Monkey\Functions\when( 'get_user_meta' )->alias(
			static fn( $uid, $key = '', $single = false ) => ''
		);

		$rules = \LTMS_PosGold_Price_Calculator::get_vendor_rules( 999999 );
		$calc  = \LTMS_PosGold_Price_Calculator::calculate( 50000.0, $rules );

		$this->assertSame( 86000.0, (float) $calc['price'],
			'Con defaults PosGold (margen 30%, comisión 10% gross-up, IVA 19%, redondeo 1.000), 50.000 de costo debe dar 86.000.' );
		$this->assertSame( 50000.0, (float) $calc['cost'],
			'El breakdown debe conservar el costo original (insumo del recálculo).' );

		// Caso ReDi activo: costo ReDi 5% entra al subtotal de gastos.
		$rules_redi                = $rules;
		$rules_redi['is_redi']     = true;
		$rules_redi['redi_cost_pct'] = 5.0;
		$calc_redi                 = \LTMS_PosGold_Price_Calculator::calculate( 50000.0, $rules_redi );
		$this->assertSame( 91000.0, (float) $calc_redi['price'],
			'Con ReDi activo (costo 5%), 50.000 de costo debe dar 91.000 (ReDi 2.500 entra al subtotal de gastos).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Handler + hook + botón (paridad VTEX PRICE-RECALC)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_recalculate_handler_exists(): void {
		$this->require_classes();

		$rc = new \ReflectionClass( 'LTMS_Dashboard_Logic' );
		$this->assertTrue( $rc->hasMethod( 'ajax_recalculate_posgold_prices' ),
			'Debe existir el handler ajax_recalculate_posgold_prices.' );
	}

	public function test_hook_registered_and_js_button_present(): void {
		$logic_src = file_get_contents( __DIR__ . '/../../includes/frontend/class-ltms-dashboard-logic.php' );
		$js_src    = file_get_contents( __DIR__ . '/../../assets/js/ltms-posgold.js' );
		$min_src   = file_get_contents( __DIR__ . '/../../assets/js/ltms-posgold.min.js' );
		$view_src  = file_get_contents( __DIR__ . '/../../includes/frontend/views/view-posgold.php' );

		$this->assertStringContainsString(
			'ltms_recalculate_posgold_prices',
			$logic_src,
			'El hook AJAX del recálculo PosGold debe estar registrado.'
		);
		$this->assertStringContainsString(
			'ltms-posgold-recalc-btn',
			$js_src,
			'El JS debe manejar el botón de recalcular PosGold.'
		);
		$this->assertStringContainsString(
			'ltms-posgold-recalc-btn',
			$view_src,
			'La vista debe contener el botón de recalcular PosGold.'
		);
		// Producción carga el .min — el recálculo debe estar minificado también.
		$this->assertStringContainsString(
			'ltms_recalculate_posgold_prices',
			$min_src,
			'El min.js (el que carga producción) debe contener el action del recálculo.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PRICE-RECALC-SAVE: el recálculo guarda primero las reglas del form
	// ─────────────────────────────────────────────────────────────────────────

	public function test_recalc_js_saves_rules_before_recalculate(): void {
		$js_src = file_get_contents( __DIR__ . '/../../assets/js/ltms-posgold.js' );

		$this->assertStringContainsString(
			'POSGOLD-RECALC (2026-09-25)',
			$js_src,
			'El JS debe documentar el fix POSGOLD-RECALC.'
		);
		$this->assertStringContainsString(
			"action: 'ltms_save_posgold_rules'",
			$js_src,
			'El recálculo debe guardar las reglas del form ANTES de recalcular.'
		);
		$this->assertStringContainsString(
			"action: 'ltms_recalculate_posgold_prices'",
			$js_src,
			'El recálculo debe ejecutar ltms_recalculate_posgold_prices en cadena.'
		);
		$this->assertStringContainsString(
			'function collectRules()',
			$js_src,
			'El JS debe recolectar los valores actuales del form de reglas.'
		);
		$this->assertStringContainsString(
			"\$form.find('input[name=\"margin_pct\"]').val()",
			$js_src,
			'collectRules debe leer margin_pct SCOPED al form de reglas PosGold (lección #176: la vista VTEX convive en el mismo DOM con names idénticos).'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PRICE-RECALC-NET: reintento multi-URL que propaga el resultado
	// ─────────────────────────────────────────────────────────────────────────

	public function test_recalc_js_has_network_fallback(): void {
		$js_src = file_get_contents( __DIR__ . '/../../assets/js/ltms-posgold.js' );

		$this->assertStringContainsString(
			'function posgoldAjax(urls, data)',
			$js_src,
			'Debe existir el helper posgoldAjax con reintento multi-URL.'
		);
		$this->assertStringContainsString(
			"urls.push('/wp-admin/admin-ajax.php')",
			$js_src,
			'El recálculo debe reintentar contra admin-ajax.php si el primario falla (WAF SiteGround).'
		);
		$this->assertStringContainsString(
			'dfd.resolve(resp)',
			$js_src,
			'El helper debe resolver el Deferred con la respuesta real de la retry (no descartarla).'
		);
		$this->assertStringContainsString(
			'dfd.reject()',
			$js_src,
			'El helper debe rechazar solo cuando se agotan todos los endpoints.'
		);
		// El patrón roto de VTEX (contador 'tries' + retry descartada) no debe replicarse.
		$this->assertStringNotContainsString(
			'tries++',
			$js_src,
			'No debe replicarse el patrón roto que descartaba el resultado de la retry.'
		);
	}
}
