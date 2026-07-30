<?php
/**
 * Tests estructurales del template público cart.php.
 *
 * Foco actual: AUDIT-FE Fase 1.6 — auditoría full-stack de la página pública
 * del carrito (cart.php). Cubre 4 hallazgos:
 *
 *   * AUDIT-FE-CART-001 (P1, CSP-compliance + dead script): el bloque
 *     <script> inline de líneas 962-1029 (quantity stepper, coupon sync,
 *     update cart highlight) rompía la convención CSP del design system
 *     Plaza Viva. Fix: migrado a assets/js/ltms-plaza-viva.js (scope CART
 *     al final del archivo). Paridad con vendor-store.php (100% CSP-compliant
 *     tras AUDIT-FE-VS-JT-001).
 *
 *   * AUDIT-FE-CART-006 (P1, escape inconsistente): $coupon_remove_url era
 *     asignado con esc_url() y luego echo'ed sin esc_url() wrapper (línea 501
 *     original). Funcionalmente seguro pero inconsistente con el estándar del
 *     template (todos los demás echoes usan echo esc_url(...)).
 *     Fix: esc_url() movido al echo como en el resto del template.
 *
 *   * AUDIT-FE-CART-007 (P2, dead code $is_visible): $is_visible era
 *     asignado con apply_filters pero NUNCA leído (línea 333 original).
 *     Fix: eliminada la variable asignada muerta.
 *
 *   * AUDIT-FE-CART-008 (P0 funcional + P1, breadcrumb restore missing):
 *     cart.php removía el breadcrumb hook (woocommerce_before_main_content →
 *     woocommerce_breadcrumb, priority 20) pero NO tenía add_action de
 *     restauración al final del template (patrón establecido en
 *     single-product.php:722-725). Mismo bug encontrado en checkout.php
 *     (será fixeado en Fase 1.7). Fix: añadido add_action restore balanceando
 *     el remove_action, paridad con single-product.php.
 *
 *   * AUDIT-FE-CART-009 (P1 funcional, falta botón Vaciar carrito): el
 *     comment HTML en header mencionaba "Vaciar carrito" pero NO existía
 *     el botón (WC nativo no trae endpoint built-in para vaciar el carrito
 *     entero). Fix: botón añadido con data-pv-empty-cart + handler AJAX
 *     wp_ajax_ltms_pv_empty_cart (registrado en class-ltms-frontend-checkout-handler.php)
 *     + JS delegado en ltms-plaza-viva.js con confirmación nativa del browser
 *     antes de vaciar (anti-click-accidental sin undo en WC).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source
 * PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas en
 * LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático del
 * autoloader de Composer (mismo patrón que VendorStoreCspTest,
 * WishlistPvToggleTest, OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CartAuditTest
 *
 * Verifica los 5 fixes AUDIT-FE-CART-001/006/007/008/009 sobre el template
 * includes/frontend/templates/cart.php, el handler PHP en
 * includes/frontend/class-ltms-frontend-checkout-handler.php y el handler
 * JS en assets/js/ltms-plaza-viva.js mediante invariantes estructurales del
 * source. Detecta regresiones si alguien reintroduce el <script> inline,
 * elimina el restore del breadcrumb, elimina el botón Vaciar carrito o
 * elimina el handler AJAX empty_cart.
 */
final class CartAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la plantilla cart.php.
	 */
	private string $template_path;

	/**
	 * Ruta absoluta al handler PHP de checkout (frontend).
	 */
	private string $handler_path;

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
	 * Ubuntu (mismo patrón que VendorStoreCspTest, OrderTrackingAuditTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->template_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/cart.php';
		$this->handler_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-frontend-checkout-handler.php';
		$this->js_path      = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
	}

	/**
	 * AUDIT-FE-CART-001 (CSP-compliance): el bloque <script> inline original
	 * (líneas 962-1029 del source pre-fix) fue migrado al design system global
	 * assets/js/ltms-plaza-viva.js. La plantilla cart.php ya NO contiene
	 * NINGÚN tag <script> (paridad con vendor-store.php 100% CSP-compliant).
	 *
	 * Detecta regresión si alguien reintroduce un bloque <script> inline en
	 * cart.php (rompería la convención CSP del design system Plaza Viva).
	 */
	public function test_001_cart_template_no_contiene_script_inline(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src, 'cart.php debe existir y no estar vacío' );

		// La plantilla NO debe contener NINGÚN tag <script> (con o sin attrs).
		// Esto cierra CSP-compliance al 100% en cart.php.
		$this->assertStringNotContainsString( '<script', $src, 'AUDIT-FE-CART-001 fix: cart.php must NOT contain any <script> tag (CSP-compliance — paridad con vendor-store.php)' );
		$this->assertStringNotContainsString( '</script>', $src, 'AUDIT-FE-CART-001 fix: cart.php must NOT contain </script> closing tag (no inline JS allowed)' );

		// Trazabilidad del fix conservada en comentarios PHP.
		$this->assertStringContainsString( 'AUDIT-FE-CART-001 FIX', $src, 'AUDIT-FE-CART-001: fix marker comment must be present in cart.php for future audits' );
	}

	/**
	 * AUDIT-FE-CART-001 (continuación): los 3 behaviours migrados al design
	 * system ltms-plaza-viva.js viven ahora en el scope CART al final del
	 * archivo (paridad con el scope de order-tracking en mísmo archivo).
	 */
	public function test_002_plaza_viva_js_contiene_scope_cart_con_behaviours_migrados(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js, 'ltms-plaza-viva.js debe existir y no estar vacío' );

		// Traza del fix presente en el JS para auditoría futura.
		$this->assertStringContainsString( 'AUDIT-FE-CART-001 FIX', $js, 'AUDIT-FE-CART-001: ltms-plaza-viva.js must contain the fix marker comment for future audits' );

		// Behaviour 1: quantity stepper migrado.
		$this->assertStringContainsString( "wrap.querySelector('.pv-qty__btn--minus')", $js, 'AUDIT-FE-CART-001: quantity stepper minus handler must be in ltms-plaza-viva.js (migrated from cart.php inline)' );
		$this->assertStringContainsString( "wrap.querySelector('.pv-qty__btn--plus')", $js, 'AUDIT-FE-CART-001: quantity stepper plus handler must be in ltms-plaza-viva.js (migrated from cart.php inline)' );

		// Behaviour 2: coupon sync migrado.
		$this->assertStringContainsString( "scope.querySelector('#pv-cart-coupon-code')", $js, 'AUDIT-FE-CART-001: coupon input sync handler must be in ltms-plaza-viva.js' );
		$this->assertStringContainsString( "scope.querySelector('#pv-cart-coupon-form')", $js, 'AUDIT-FE-CART-001: coupon form sync handler must be in ltms-plaza-viva.js' );

		// Behaviour 3: update cart highlight migrado.
		$this->assertStringContainsString( "scope.querySelector('.pv-cart__update-btn')", $js, 'AUDIT-FE-CART-001: update cart highlight handler must be in ltms-plaza-viva.js' );
		$this->assertStringContainsString( "updateBtn.classList.add('is-pending')", $js, 'AUDIT-FE-CART-001: update cart highlight must add is-pending class on qty change' );
	}

	/**
	 * AUDIT-FE-CART-006 (escape inconsistente): el echo del coupon_remove_url
	 * ahora usa esc_url() en la línea de echo (paridad con el resto del
	 * template), en vez de asignar la variable pre-escaped y luego echo'earla
	 * sin wrapper. Detecta regresión del estilo inconsistente.
	 */
	public function test_006_coupon_remove_url_usa_esc_url_en_echo(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// El patrón correcto: $coupon_remove_url SIN esc_url, y el echo
		// lleva esc_url($coupon_remove_url).
		$this->assertStringContainsString( "\$coupon_remove_url = add_query_arg", $src, 'AUDIT-FE-CART-006 fix: $coupon_remove_url must be assigned WITHOUT esc_url() (escaped at echo site)' );
		$this->assertStringContainsString( "echo esc_url( \$coupon_remove_url )", $src, 'AUDIT-FE-CART-006 fix: echo de $coupon_remove_url DEBE usar esc_url() wrapper (paridad con el resto del template)' );

		// Detectar el patrón viejo incorrecto: variable pre-escaped + echo sin wrapper.
		$this->assertStringNotContainsString( "\$coupon_remove_url = esc_url(", $src, 'AUDIT-FE-CART-006 regression: $coupon_remove_url must NOT be pre-escaped (move esc_url to echo site for consistency)' );
		$this->assertStringNotContainsString( 'echo $coupon_remove_url;', $src, 'AUDIT-FE-CART-006 regression: echo de $coupon_remove_url must NOT skip esc_url() wrapper' );
	}

	/**
	 * AUDIT-FE-CART-007 (dead code $is_visible): la variable $is_visible
	 * asignada con apply_filters pero nunca leída fue eliminada del source.
	 * Detecta regresión si alguien reintroduce la variable muerta.
	 */
	public function test_007_is_visible_dead_code_eliminado(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CART-005 FIX', $src, 'AUDIT-FE-CART-007 (originally tagged CART-005 in fix marker): fix marker must be present in cart.php' );

		// La variable $is_visible NO debe volver a asignarse.
		$this->assertStringNotContainsString( "\$is_visible = apply_filters", $src, 'AUDIT-FE-CART-007: $is_visible dead code must NOT be reintroduced (variable was assigned but never read)' );
	}

	/**
	 * AUDIT-FE-CART-008 (P0 funcional — breadcrumb restore missing): el
	 * remove_action del breadcrumb hook DEBE balancearse con un add_action
	 * al final del template (paridad con single-product.php:722-725). Sin
	 * este restore, el breadcrumb queda desenganchado para cualquier caller
	 * posterior del hook en el mismo request (SEO plugins, schema.org
	 * breadcrumbs en footer, themes que esperan woocommerce_breadcrumb
	 * registrado en woocommerce_before_main_content).
	 */
	public function test_008_breadcrumb_restore_add_action_balancea_remove(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CART-001 FIX', $src, 'AUDIT-FE-CART-008: fix marker must be present in cart.php (uses same marker as CART-001 since both are in the same fix block)' );

		// El remove_action original debe seguir presente (NO se eliminó el
		// remove, se añadió el restore para balancearlo).
		$this->assertStringContainsString( "remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )", $src, 'AUDIT-FE-CART-008 (precondition): remove_action of breadcrumb must still be present (we balance it, not remove it)' );

		// El add_action restore DEBE estar presente al final del template
		// (después de do_action('woocommerce_after_main_content')).
		$this->assertStringContainsString( "add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )", $src, 'AUDIT-FE-CART-008 fix: add_action restore of breadcrumb MUST be present to balance the remove_action (paridad con single-product.php:722-725)' );

		// El restore debe estar DESPUÉS de do_action('woocommerce_after_main_content')
		// (no antes — el breadcrumb debe estar desenganchado durante el render
		// del template, restaurado solo después para no afectar al resto del sitio).
		$after_main_pos = strpos( $src, "do_action( 'woocommerce_after_main_content' )" );
		$restore_pos    = strpos( $src, "add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 )" );
		$this->assertNotFalse( $after_main_pos, 'AUDIT-FE-CART-008: precondition — do_action(woocommerce_after_main_content) must be present' );
		$this->assertNotFalse( $restore_pos, 'AUDIT-FE-CART-008: add_action restore must be present in cart.php' );
		$this->assertGreaterThan( $after_main_pos, $restore_pos, 'AUDIT-FE-CART-008: add_action restore must come AFTER do_action(woocommerce_after_main_content) — not before' );

		// El restore debe estar guardado por el condicional $pv_breadcrumb_was_hooked
		// (solo restaurar si estaba enganchado originalmente — el patrón wrong sería
		// restore incondicional que engancharía al breadcrumb en temas que no lo tenían
		// enganchado al inicio).
		$this->assertStringContainsString( 'if ( ! empty( $pv_breadcrumb_was_hooked ) )', $src, 'AUDIT-FE-CART-008: add_action restore must be guarded by $pv_breadcrumb_was_hooked (paridad con single-product.php:723)' );
	}

	/**
	 * AUDIT-FE-CART-009 (P1 funcional, falta botón Vaciar carrito): el
	 * template debe contener el botón con data-pv-empty-cart en el header.
	 * Antes el comment HTML mencionaba "Vaciar carrito" pero NO existía el
	 * botón. Detecta regresión si alguien elimina el botón del header.
	 */
	public function test_009_empty_cart_boton_presente_en_header(): void {
		$src = file_get_contents( $this->template_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CART-009 FIX', $src, 'AUDIT-FE-CART-009: fix marker must be present in cart.php for the empty-cart button fix' );

		// El botón con data-pv-empty-cart debe estar presente en el header.
		$this->assertStringContainsString( 'data-pv-empty-cart', $src, 'AUDIT-FE-CART-009 fix: button with data-pv-empty-cart MUST be present in cart.php header (was missing before fix — only mentioned in comment HTML)' );

		// El botón debe tener el aria-label accesible.
		$this->assertStringContainsString( 'aria-label=', $src, 'AUDIT-FE-CART-009: empty-cart button must have accessible aria-label' );

		// El botón debe tener la clase pv-cart__empty-btn para el scope CSS.
		$this->assertStringContainsString( 'pv-cart__empty-btn', $src, 'AUDIT-FE-CART-009: empty-cart button must have class pv-cart__empty-btn' );

		// El CSS de estados del botón (danger + is-loading + is-pending) debe estar.
		$this->assertStringContainsString( 'pv-btn--danger', $src, 'AUDIT-FE-CART-009: CSS for pv-btn--danger variant must be defined in cart.php <style> block' );
		$this->assertStringContainsString( '.is-loading', $src, 'AUDIT-FE-CART-009: CSS for is-loading disabled state must be defined' );
		$this->assertStringContainsString( '.is-pending', $src, 'AUDIT-FE-CART-003 (update highlight): CSS for is-pending state of update_cart button must be defined' );
	}

	/**
	 * AUDIT-FE-CART-009 (continuación PHP): el handler AJAX
	 * ltms_pv_empty_cart DEBE estar registrado en
	 * class-ltms-frontend-checkout-handler.php (logged-in + nopriv).
	 * Invoca WC()->cart->empty_cart() que dispara los hooks estándar de WC.
	 */
	public function test_010_handler_php_ltms_pv_empty_cart_registrado_y_canonico(): void {
		$src = file_get_contents( $this->handler_path );
		$this->assertNotEmpty( $src );

		// Trazabilidad del fix.
		$this->assertStringContainsString( 'AUDIT-FE-CART-009 FIX', $src, 'AUDIT-FE-CART-009: fix marker must be present in class-ltms-frontend-checkout-handler.php' );

		// El hook wp_ajax DEBE estar registrado (logged-in).
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_pv_empty_cart'", $src, 'AUDIT-FE-CART-009: wp_ajax_ltms_pv_empty_cart MUST be registered (logged-in)' );

		// El hook wp_ajax_nopriv DEBE estar registrado (guest — WC soporta compra guest).
		$this->assertStringContainsString( "add_action( 'wp_ajax_nopriv_ltms_pv_empty_cart'", $src, 'AUDIT-FE-CART-009: wp_ajax_nopriv_ltms_pv_empty_cart MUST be registered (guest — WC soporta compra guest)' );

		// El método handler DEBE existir.
		$this->assertStringContainsString( 'public static function ajax_pv_empty_cart(): void', $src, 'AUDIT-FE-CART-009: method ajax_pv_empty_cart() MUST exist in class-ltms-frontend-checkout-handler.php' );

		// Validación contra el nonce global ltms_plaza_viva (mismo nonce que
		// ajax_plaza_viva_add_to_cart y ajax_pv_toggle wishlist — paridad).
		$this->assertStringContainsString( "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )", $src, 'AUDIT-FE-CART-009: handler must validate against ltms_plaza_viva nonce (sent by PV.ajax helper)' );

		// Debe invocar WC()->cart->empty_cart() (NO una implementación custom
		// que se salte los hooks de WC).
		$this->assertStringContainsString( 'WC()->cart->empty_cart()', $src, 'AUDIT-FE-CART-009: handler must use WC()->cart->empty_cart() (preserves WC hooks woocommerce_before_cart_emptied, woocommerce_cart_emptied)' );

		// Debe retornar 'redirect' => wc_get_cart_url() en success (el JS
		// hace redirect a la empty-cart view tras success).
		$this->assertStringContainsString( "'redirect'   => wc_get_cart_url()", $src, 'AUDIT-FE-CART-009: success response must include redirect to wc_get_cart_url() (JS follows it to show empty-cart view)' );
	}

	/**
	 * AUDIT-FE-CART-009 (continuación JS): el handler JS para
	 * data-pv-empty-cart DEBE estar en ltms-plaza-viva.js, con confirmación
	 * nativa del browser antes de vaciar (anti-click-accidental sin undo).
	 * Detecta regresión si alguien elimina la confirmación o el handler.
	 */
	public function test_011_js_empty_cart_handler_con_confirmacion(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js );

		// Trazabilidad del fix presente en JS.
		$this->assertStringContainsString( 'AUDIT-FE-CART-009 FIX', $js, 'AUDIT-FE-CART-009: fix marker must be present in ltms-plaza-viva.js for future audits' );

		// El handler DEBE escuchar el selector data-pv-empty-cart.
		$this->assertStringContainsString( "querySelector('[data-pv-empty-cart]')", $js, 'AUDIT-FE-CART-009: JS must query [data-pv-empty-cart] selector' );

		// El handler DEBE mostrar confirmación nativa del browser (window.confirm)
		// antes de invocar el AJAX. Anti-click-accidental: WC nativo no trae undo.
		$this->assertStringContainsString( 'window.confirm(', $js, 'AUDIT-FE-CART-009: JS must call window.confirm() before emptying cart (anti-accidental-click — WC has no undo)' );

		// El mensaje de confirmación debe leerse de PV.i18n.empty_cart_confirm
		// (no hardcodeado en el JS — el fallback existe pero el preferido es i18n).
		$this->assertStringContainsString( 'PV.i18n.empty_cart_confirm', $js, 'AUDIT-FE-CART-009: JS must prefer PV.i18n.empty_cart_confirm over hardcoded string (i18n support)' );

		// La invocación AJAX DEBE usar PV.ajax('ltms_pv_empty_cart', ...)
		// (envía el nonce global automáticamente).
		$this->assertStringContainsString( "PV.ajax('ltms_pv_empty_cart'", $js, 'AUDIT-FE-CART-009: JS must invoke PV.ajax with action ltms_pv_empty_cart (sends nonce automatically)' );

		// El estado loading (disabled + is-loading class) debe aplicarse al
		// botón mientras el AJAX está en vuelo (anti-doble-submit).
		$this->assertStringContainsString( "emptyBtn.disabled = true", $js, 'AUDIT-FE-CART-009: JS must disable the button during AJAX (anti-double-submit)' );
		$this->assertStringContainsString( "is-loading", $js, 'AUDIT-FE-CART-009: JS must add is-loading class to the button during AJAX (visual feedback)' );

		// Tras success, DEBE hacer redirect a res.data.redirect (URL retornada
		// por el handler, que es wc_get_cart_url() con el carrito ya vacío).
		$this->assertStringContainsString( 'window.location.href = redirect', $js, 'AUDIT-FE-CART-009: JS must redirect to res.data.redirect after success (shows empty-cart view to user)' );

		// Toast de error en catch debe existir (consistencia con otros handlers PV).
		$this->assertStringContainsString( "PV.toast('Error de conexión'", $js, 'AUDIT-FE-CART-009: JS must show generic error toast on AJAX catch (paridad con AUDIT-FE-SF-006 follow, AUDIT-FE-AP-001 wishlist)' );

		// Tras error, el botón debe revertirse al estado original (no dejar
		// disabled + is-loading permanentemente).
		$this->assertStringContainsString( 'emptyBtn.disabled = false', $js, 'AUDIT-FE-CART-009: JS must re-enable the button after error recovery (no permanent disabled state)' );
	}

	/**
	 * AUDIT-FE-CART-009 (re-audit i18n): el objeto PV.i18n en
	 * ltms-plaza-viva.js debe incluir las nuevas strings empty_cart_confirm
	 * y empty_cart_done — si no, el fallback del JS cae al string
	 * hardcoded (pérdida de i18n support).
	 */
	public function test_012_i18n_strings_empty_cart_agregadas(): void {
		$js = file_get_contents( $this->js_path );
		$this->assertNotEmpty( $js );

		// Las 2 nuevas keys del i18n deben estar definidas en PV.i18n.
		$this->assertStringContainsString( 'empty_cart_confirm:', $js, 'AUDIT-FE-CART-009: PV.i18n must define empty_cart_confirm (no fallback-only hardcoded string)' );
		$this->assertStringContainsString( 'empty_cart_done:', $js, 'AUDIT-FE-CART-009: PV.i18n must define empty_cart_done (success toast message)' );
	}

	/**
	 * Re-audit: el handler PHP existente ajax_plaza_viva_add_to_cart
	 * (Fase 1.1 + AUDIT-FE-PV-001 Fase 1.4) DEBE seguir intacto después
	 * de añadir el nuevo handler ajax_pv_empty_cart al lado. Detecta
	 * regresiones si el cambio eliminó por error el handler add-to-cart.
	 */
	public function test_013_re_audit_add_to_cart_handler_intacto(): void {
		$src = file_get_contents( $this->handler_path );
		$this->assertNotEmpty( $src );

		// El handler add_to_cart previo debe seguir presente (Fase 1.1).
		$this->assertStringContainsString( 'public static function ajax_plaza_viva_add_to_cart(): void', $src, 'AUDIT-FE-CART re-audit: ajax_plaza_viva_add_to_cart handler (Fase 1.1) must remain intact after adding ajax_pv_empty_cart' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_plaza_viva_add_to_cart'", $src, 'AUDIT-FE-CART re-audit: wp_ajax_ltms_plaza_viva_add_to_cart hook registration must remain intact' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_nopriv_ltms_plaza_viva_add_to_cart'", $src, 'AUDIT-FE-CART re-audit: wp_ajax_nopriv_ltms_plaza_viva_add_to_cart hook registration must remain intact' );

		// AUDIT-FE-PV-001 (re-audit Fase 1.4): el nonce del handler add_to_cart
		// debe seguir siendo 'ltms_plaza_viva' (NO 'ltms_ux_nonce' — ese era
		// el bug de la Fase 1.4 que se fixeó).
		$this->assertStringContainsString( "check_ajax_referer( 'ltms_plaza_viva', 'nonce' )", $src, 'AUDIT-FE-CART re-audit + AUDIT-FE-PV-001: add_to_cart handler must still use ltms_plaza_viva nonce (not ltms_ux_nonce)' );
	}

	/**
	 * Re-audit: el handler PHP existente ajax_pv_toggle_wishlist del
	 * LTMS_Wishlist (Fase 1.5 — AUDIT-FE-AP-001) DEBE seguir intacto y
	 * registrado. Detecta regresiones si el cambio en el archivo
	 * frontend-checkout-handler.php o el refresh de Composer/autoload
	 * afectó por error el handler wishlist (vive en otro archivo).
	 */
	public function test_014_re_audit_wishlist_pv_toggle_handler_intacto(): void {
		$wishlist_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-wishlist.php';
		$src           = file_get_contents( $wishlist_path );
		$this->assertNotEmpty( $src );

		// El handler wishlist previo debe seguir presente (Fase 1.5).
		$this->assertStringContainsString( 'public static function ajax_pv_toggle(): void', $src, 'AUDIT-FE-CART re-audit + AUDIT-FE-AP-001: ajax_pv_toggle() handler (Fase 1.5 wishlist) must remain intact in class-ltms-wishlist.php' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_pv_toggle_wishlist'", $src, 'AUDIT-FE-CART re-audit: wp_ajax_ltms_pv_toggle_wishlist hook registration must remain intact' );
	}
}
