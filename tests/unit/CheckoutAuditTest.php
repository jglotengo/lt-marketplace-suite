<?php
/**
 * Tests estructurales del template público checkout.php.
 *
 * Foco actual: AUDIT-FE Fase 1.7 — auditoría full-stack de la página pública
 * de checkout (checkout.php). Cubre 4 hallazgos:
 *
 *   * AUDIT-FE-CKO-001 (P0 funcional, breadcrumb restore missing): checkout.php
 *     removía el breadcrumb hook (woocommerce_before_main_content →
 *     woocommerce_breadcrumb, priority 20) pero NO tenía add_action de
 *     restauración al final del template (patrón establecido en
 *     single-product.php:722-725). Mismo bug encontrado en cart.php
 *     (AUDIT-FE-CART-008 Fase 1.6). Fix: añadido add_action restore
 *     balanceando el remove_action, paridad con single-product.php.
 *
 *   * AUDIT-FE-CKO-003 (P1, CSP-compliance + script-tag inline más grande
 *     del design system): el bloque script-tag inline de líneas 622-916
 *     (~295 líneas) era la excepción inline más significativa del design
 *     system Plaza Viva. Contenía 7 behaviours: stepper sync, shipping
 *     radio cards, payment radio cards/toggle, ship_to_different_address
 *     toggle, submit loading, WOOCCM label override, sync_state_from_city.
 *     Fix: migrado por completo a assets/js/ltms-plaza-viva.js (scope
 *     CHECKOUT al final del archivo). Paridad con cart.php (Fase 1.6) y
 *     vendor-store.php.
 *
 *   * AUDIT-FE-CKO-004 (P0 CSP, JS usaba PHP esc_js dentro del script-tag
 *     inline): el checkout.php:729 original hacía
 *     `var ltmsCountry = '<?php echo esc_js( LTMS_Core_Config::get_country() ); ?>';`
 *     DENTRO del script-tag inline. Esto hacia imposible migrar el JS a
 *     un archivo externo (un valor dinámico PHP inyectado dentro del JS).
 *     Fix: el país ahora se expone via wp_localize_script como ltms_data.country
 *     (class-ltms-native-templates.php:329) y el JS lee PV.config.country.
 *
 *   * AUDIT-FE-CKO-005 (P2, style attribute inline en bloques legal y
 *     no-shipping): varias líneas con style="..." inline en lugar de
 *     clases — technical debt que NO respeta el design system. NO fixeado
 *     en este commit — fuera de alcance (cosmético, no rompe nada).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source
 * PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas en
 * LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático del
 * autoloader de Composer (mismo patrón que CartAuditTest, VendorStoreCspTest,
 * OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CheckoutAuditTest
 *
 * Verifica los 3 fixes AUDIT-FE-CKO-001/003/004 sobre el template
 * includes/frontend/templates/checkout.php, el método wp_localize_script
 * en includes/frontend/class-ltms-native-templates.php y el scope CHECKOUT
 * en assets/js/ltms-plaza-viva.js. Detecta regresiones si alguien reintroduce
 * el script-tag inline, elimina el restore del breadcrumb, elimina la
 * exposición del country via wp_localize_script, o reintroduce la inyección
 * PHP en el JS.
 */
final class CheckoutAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la plantilla checkout.php.
	 */
	private string $template_path;

	/**
	 * Ruta absoluta a class-ltms-native-templates.php (done se exponen
	 * los datos al JS via wp_localize_script).
	 */
	private string $native_templates_path;

	/**
	 * Ruta absoluta al design system JS.
	 */
	private string $js_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que CartAuditTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->template_path        = dirname( __DIR__, 2 ) . '/includes/frontend/templates/checkout.php';
		$this->native_templates_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->js_path             = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
	}

	/**
	 * AUDIT-FE-CKO-001 (P0 funcional, breadcrumb restore missing): el
	 * remove_action del breadcrumb hook DEBE balancearse con un add_action
	 * al final del template (paridad con single-product.php:722-725 y
	 * cart.php AUDIT-FE-CART-008 Fase 1.6). Sin este restore, el breadcrumb
	 * queda desenganchado para cualquier caller posterior del hook en el
	 * mismo request (SEO plugins, schema.org breadcrumbs en footer, themes
	 * que esperan woocommerce_breadcrumb registrado en
	 * woocommerce_before_main_content).
	 */
	public function test_001_breadcrumb_restore_add_action_balancea_remove(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CKO-001 FIX', $src, 'AUDIT-FE-CKO-001: fix marker must be present in checkout.php for the breadcrumb restore fix' );

		// El remove_action original debe seguir presente (NO se eliminó el
		// remove, se añadió el restore para balancearlo).
		$this->assertStringContainsString( "remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )", $src, 'AUDIT-FE-CKO-001 (precondition): remove_action of breadcrumb must still be present in checkout.php (we balance it, not remove it)' );

		// El add_action restore DEBE estar presente al final del template
		// (después de do_action('woocommerce_after_main_content')).
		$this->assertStringContainsString( "add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )", $src, 'AUDIT-FE-CKO-001 fix: add_action restore of breadcrumb MUST be present to balance the remove_action (paridad con single-product.php:722-725)' );

		// El restore debe estar DESPUÉS de do_action('woocommerce_after_main_content').
		$after_main_pos = strpos( $src, "do_action( 'woocommerce_after_main_content' )" );
		$restore_pos    = strpos( $src, "add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )" );
		$this->assertNotFalse( $after_main_pos, 'AUDIT-FE-CKO-001: precondition — do_action(woocommerce_after_main_content) must be present' );
		$this->assertNotFalse( $restore_pos, 'AUDIT-FE-CKO-001: add_action restore must be present in checkout.php' );
		$this->assertGreaterThan( $after_main_pos, $restore_pos, 'AUDIT-FE-CKO-001: add_action restore must come AFTER do_action(woocommerce_after_main_content) — not before' );

		// El restore debe estar guardado por el condicional $pv_breadcrumb_was_hooked
		// (paridad con single-product.php:723 y cart.php).
		$this->assertStringContainsString( 'if ( ! empty( $pv_breadcrumb_was_hooked ) )', $src, 'AUDIT-FE-CKO-001: add_action restore must be guarded by $pv_breadcrumb_was_hooked (paridad con single-product.php:723)' );
	}

	/**
	 * AUDIT-FE-CKO-003 (CSP-compliance): el bloque script-tag inline original
	 * (líneas 622-916 del source pre-fix, ~295 líneas) fue migrado al design
	 * system global assets/js/ltms-plaza-viva.js (scope CHECKOUT al final
	 * del archivo). La plantilla checkout.php ya NO contiene NINGÚN tag
	 * script (paridad con cart.php y vendor-store.php).
	 *
	 * Detecta regresión si alguien reintroduce un bloque script-tag inline
	 * en checkout.php (rompería la convención CSP del design system).
	 */
	public function test_003_checkout_template_no_contiene_script_inline(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// La plantilla NO debe contener NINGÚN tag script (con o sin attrs).
		// Esto cierra CSP-compliance al 100% en checkout.php.
		$this->assertStringNotContainsString( '<script', $src, 'AUDIT-FE-CKO-003 fix: checkout.php must NOT contain any script tag (CSP-compliance — paridad con cart.php Fase 1.6 y vendor-store.php)' );
		$this->assertStringNotContainsString( '</script>', $src, 'AUDIT-FE-CKO-003 fix: checkout.php must NOT contain </script> closing tag (no inline JS allowed — script-tag migrated to ltms-plaza-viva.js)' );

		// Trazabilidad del fix conservada en comentarios PHP (block comment
		// /* ... */ que SI admite texto con caracteres literales).
		$this->assertStringContainsString( 'AUDIT-FE-CKO-003 FIX', $src, 'AUDIT-FE-CKO-003: fix marker comment must be present in checkout.php for future audits' );
	}

	/**
	 * AUDIT-FE-CKO-003 (continuación): los 7 behaviours migrados al design
	 * system ltms-plaza-viva.js viven ahora en el scope CHECKOUT al final
	 * del archivo (paridad con el scope CART ya migrado en Fase 1.6).
	 */
	public function test_004_plaza_viva_js_contiene_scope_checkout_con_behaviours_migrados(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js );

		// Traza del fix presente en el JS para auditoría futura.
		$this->assertStringContainsString( 'AUDIT-FE-CKO-003 FIX', $js, 'AUDIT-FE-CKO-003: ltms-plaza-viva.js must contain the fix marker comment for future audits' );

		// Behaviour 1: stepper sync via IntersectionObserver + data-step-block.
		$this->assertStringContainsString( "scope.querySelectorAll('[data-step-block]')", $js, 'AUDIT-FE-CKO-003: stepper sync handler must query [data-step-block] selectors (migrated from checkout.php inline)' );
		$this->assertStringContainsString( 'IntersectionObserver', $js, 'AUDIT-FE-CKO-003: stepper sync must use IntersectionObserver (1 of 7 migrated behaviours)' );

		// Behaviour 2: shipping radio cards.
		$this->assertStringContainsString( "querySelectorAll('[data-pv-shipping-radio]')", $js, 'AUDIT-FE-CKO-003: shipping radio cards handler must query [data-pv-shipping-radio] (2 of 7)' );

		// Behaviour 3: payment radio cards.
		$this->assertStringContainsString( "querySelectorAll('[data-pv-payment-radio]')", $js, 'AUDIT-FE-CKO-003: payment radio cards handler must query [data-pv-payment-radio] (3 of 7)' );
		$this->assertStringContainsString( "querySelectorAll('[data-pv-payment-fields]')", $js, 'AUDIT-FE-CKO-003: payment fields show/hide handler must query [data-pv-payment-fields]' );

		// Behaviour 4: ship_to_different_address toggle.
		$this->assertStringContainsString( "ship_to_different_address", $js, 'AUDIT-FE-CKO-003: ship_to_different_address toggle handler must remain (4 of 7)' );

		// Behaviour 5: submit loading state.
		$this->assertStringContainsString( "pv-checkout__submit", $js, 'AUDIT-FE-CKO-003: submit loading state handler must query .pv-checkout__submit (5 of 7)' );

		// Behaviour 6: WOOCCM label override (Departamento/Municipio/Dirección).
		$this->assertStringContainsString( "fixFieldLabels", $js, 'AUDIT-FE-CKO-003: WOOCCM label override function must remain (6 of 7)' );
		$this->assertStringContainsString( "'billing_state': 'Departamento'", $js, 'AUDIT-FE-CKO-003: CO label map must include billing_state → Departamento (WOOCCM override)' );

		// Behaviour 7: sync billing_state from billing_city.
		$this->assertStringContainsString( "syncStateFromCity", $js, 'AUDIT-FE-CKO-003: syncStateFromCity function must remain (7 of 7)' );
		$this->assertStringContainsString( "#billing_city, #ltms-municipality-select", $js, 'AUDIT-FE-CKO-003: syncStateFromCity must query billing_city + ltms-municipality-select (DANE municipio)' );

		// MutationObserver para WOOCCM JS que corre tarde (debe seguir presente).
		$this->assertStringContainsString( 'MutationObserver', $js, 'AUDIT-FE-CKO-003: MutationObserver for WOOCCM late-rendering must remain' );
	}

	/**
	 * AUDIT-FE-CKO-004 (P0 CSP): el valor country se expone via
	 * wp_localize_script (ltms_data.country) en class-ltms-native-templates.php
	 * para que el JS externo no necesite un valor PHP inyectado inline.
	 * Antes el checkout.php:729 hacía
	 * `var ltmsCountry = '<?php echo esc_js( LTMS_Core_Config::get_country() ); ?>';`
	 * DENTRO del script-tag inline — rompía la posibilidad de migrar el JS
	 * a un archivo externo (valor dinámico PHP inyectado en el JS).
	 */
	public function test_005_country_expuesto_via_wp_localize_script(): void {
		$src = file_get_contents( $this->native_templates_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CKO-004 FIX', $src, 'AUDIT-FE-CKO-004: fix marker must be present in class-ltms-native-templates.php' );

		// El campo 'country' DEBE estar en el array wp_localize_script.
		// Buscamos el patrón "'country' =>" (con el class_exists defensivo).
		$this->assertStringContainsString( "'country'   => class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_country() : 'CO'", $src, 'AUDIT-FE-CKO-004 fix: ltms_data must include country field via wp_localize_script (read from LTMS_Core_Config::get_country() with fallback to CO)' );

		// La línea completa del wp_localize_script debe seguir presente.
		$this->assertStringContainsString( "wp_localize_script( 'ltms-plaza-viva', 'ltms_data',", $src, 'AUDIT-FE-CKO-004: wp_localize_script call for ltms-plaza-viva → ltms_data must still be present (precondition)' );
	}

	/**
	 * AUDIT-FE-CKO-004 (continuación JS): el scope CHECKOUT en
	 * ltms-plaza-viva.js DEBE leer PV.config.country en vez de un valor
	 * PHP inyectado. Antes el checkout.php inline hacía
	 * `var ltmsCountry = '<?php echo esc_js(LTMS_Core_Config::get_country()); ?>';`.
	 */
	public function test_006_js_scope_checkout_usa_pv_config_country(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js );

		// PV.config DEBE tener el campo country.
		$this->assertStringContainsString( 'country:', $js, 'AUDIT-FE-CKO-004: PV.config must include country field (read from window.ltms_data.country)' );

		// PV.config.country debe leer de window.ltms_data.country o fallback 'CO'.
		$this->assertStringContainsString( "window.ltms_data && window.ltms_data.country", $js, 'AUDIT-FE-CKO-004: PV.config.country must read from window.ltms_data.country (exposed by wp_localize_script)' );

		// El scope CHECKOUT (function checkoutScope) DEBE usar PV.config.country
		// en vez de un valor PHP inyectado.
		$this->assertStringContainsString( 'var ltmsCountry = PV.config.country', $js, 'AUDIT-FE-CKO-004 fix: scope CHECKOUT must read ltmsCountry from PV.config.country (NOT <?php echo esc_js()?> in script-tag inline)' );

		// Detectar el patrón viejo incorrecto: valor PHP inyectado DENTRO del
		// JS como VARIABLE asignación (no en comentarios block /* ... */).
		// El patrón exacto a no aceptar: `var ltmsCountry = '<?php echo esc_js(`
		$this->assertStringNotContainsString( "var ltmsCountry = '<?php echo esc_js", $js, 'AUDIT-FE-CKO-004 regression: scope CHECKOUT must NOT assign ltmsCountry from <?php echo esc_js()?> inline (comments in ltms-plaza-viva.js referencing the old buggy pattern are OK, but no actual code-level variable assignment allowed)' );
	}

	/**
	 * AUDIT-FE-CKO-004 (regression check): el template checkout.php NO debe
	 * tener el patrón `<?php echo esc_js(...); ?>` DENTRO del JS — la
	 * migración a ltms-plaza-viva.js y la exposición via PV.config.country
	 * eliminaron la necesidad de eso.
	 */
	public function test_007_checkout_template_no_php_injects_en_js(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// Como checkout.php ya no contiene NINGÚN script-tag inline, no debería
		// haber inyecciones PHP dentro de JS. Pero validamos el esc_js pattern
		// en general como sanity check (puede haberlo en HTML attributes,
		// pero dentro de un JS context NO es aceptable).
		$this->assertStringNotContainsString( "var ltmsCountry = '<?php echo esc_js", $src, 'AUDIT-FE-CKO-004 regression: checkout.php must NOT inject ltmsCountry via <?php echo esc_js()?> in inline JS (now read from PV.config.country)' );

		// Confirmamos que la traza del fix está presente (en el bloque
		// de comentarios PI "/* ... */" al final del template).
		$this->assertStringContainsString( 'AUDIT-FE-CKO-004 FIX', $src, 'AUDIT-FE-CKO-004: fix marker must be present in checkout.php for future audits' );
	}

	/**
	 * Re-audit: el scope CART migrado en Fase 1.6 (AUDIT-FE-CART-001) DEBE
	 * seguir intacto después de añadir el scope CHECKOUT al lado (mismo
	 * archivo ltms-plaza-viva.js). Detecta regresiones si el cambio introdujo
	 * errores en el scope anterior.
	 */
	public function test_008_re_audit_scope_cart_migrado_fase_1_6_intacto(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js );

		// Trazas del scope CART migrado en Fase 1.6 deben seguir presentes.
		$this->assertStringContainsString( 'AUDIT-FE-CART-001 FIX', $js, 'AUDIT-FE-CKO re-audit + AUDIT-FE-CART-001: scope CART migrado en Fase 1.6 must remain intact after adding scope CHECKOUT' );

		// El handler de empty_cart (Fase 1.6, AUDIT-FE-CART-009) debe seguir presente.
		$this->assertStringContainsString( "PV.ajax('ltms_pv_empty_cart'", $js, 'AUDIT-FE-CKO re-audit + AUDIT-FE-CART-009: empty cart handler (Fase 1.6) must remain intact' );
		$this->assertStringContainsString( 'window.confirm(', $js, 'AUDIT-FE-CKO re-audit: window.confirm() for empty-cart confirmation must remain intact (shared logic don\'t duplicate)' );
	}

	/**
	 * Re-audit: el handler PHP existing ajax_plaza_viva_add_to_cart (Fase 1.1
	 * + AUDIT-FE-PV-001 Fase 1.4) y ajax_pv_empty_cart (Fase 1.6) DEBEN
	 * seguir intactos y registrados después de añadir la exposición del
	 * country via wp_localize_script. Detecta regresiones.
	 */
	public function test_009_re_audit_handlers_pv_intactos(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-frontend-checkout-handler.php' );
		$this->assertNotEmpty( $src );

		// El handler add_to_cart previo (Fase 1.1 + AUDIT-FE-PV-001 Fase 1.4 fix).
		$this->assertStringContainsString( 'public static function ajax_plaza_viva_add_to_cart(): void', $src, 'AUDIT-FE-CKO re-audit: ajax_plaza_viva_add_to_cart handler must remain intact after wp_localize_script changes' );

		// El handler empty_cart (Fase 1.6 — AUDIT-FE-CART-009).
		$this->assertStringContainsString( 'public static function ajax_pv_empty_cart(): void', $src, 'AUDIT-FE-CKO re-audit: ajax_pv_empty_cart handler (Fase 1.6) must remain intact' );

		// Los nonces del handler add_to_cart deben seguir siendo ltms_plaza_viva.
		$this->assertStringContainsString( "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )", $src, 'AUDIT-FE-CKO re-audit + AUDIT-FE-PV-001: add_to_cart handler must still use ltms_plaza_viva nonce (not ltms_ux_nonce)' );
	}
}
