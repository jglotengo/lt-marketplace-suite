<?php
/**
 * E2eBuyerQuickViewHardeningTest — Tests del ciclo E2E-BUYER-QV-HARDEN
 * (2026-11-24, continúa el backlog P3 del ciclo E2E-BUYER-2026-11-20).
 *
 * Cubre:
 *   E2E-BUYER-QV-VISGATE (P2): los 2 handlers vivos de quick view
 *     (ltms_quick_view en LTMS_Frontend_Checkout_Handler y ltms_pv_quick_view
 *     en LTMS_Native_Templates) aplican el gate de vendibilidad
 *     LTMS_Business_Sync_Visibility_Gate::is_unsellable — antes el gate solo
 *     vivía en el handler muerto LTMS_Quick_View::ajax_load_product.
 *   E2E-BUYER-QV-XSS-EXCERPT (P2): el excerpt del modal PV escapa la short
 *     description (vendor-controlled HTML → innerHTML del modal).
 *   E2E-BUYER-QV-BROKEN-BTN (P2): el botón legacy "Vista rápida" emite el
 *     trigger canonical data-pv-quickview (antes: nonce por producto contra
 *     handler que exige ltms_ux_nonce → 403 en cada click) y el modal JS
 *     legacy que esperaba response.data.html fue eliminado.
 *   E2E-BUYER-CART-NONCE (P3): ajax_get_cart exige el nonce fatal-if-invalid
 *     (antes check_ajax_referer(..., false) no-fatal = nonce-teatro).
 *   E2E-BUYER-PSE-REMOVAL (P3): el endpoint muerto ltms_get_pse_banks fue
 *     eliminado (sin consumers desde E2EFIX-04); el gateway conserva su propio
 *     get_pse_banks server-side (AUDIT-FUNNEL-PAY-12).
 *
 * Source-based (patrón SyncVisibilityGateTest / AuditE2ePay20261117FixesTest):
 * los handlers dependen de WP/WC runtime no disponible en modo unit.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Frontend_Checkout_Handler, LTMS_Native_Templates, LTMS_Quick_View
 */
class E2eBuyerQuickViewHardeningTest extends LTMS_Unit_Test_Case {

	private const CHECKOUT_HANDLER_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const NATIVE_TEMPLATES_PATH = __DIR__ . '/../../includes/frontend/class-ltms-native-templates.php';
	private const QUICK_VIEW_PATH       = __DIR__ . '/../../includes/frontend/class-ltms-quick-view.php';
	private const PE_JS_PATH            = __DIR__ . '/../../assets/js/ltms-product-enhancements.js';
	private const PE_JS_MIN_PATH        = __DIR__ . '/../../assets/js/ltms-product-enhancements.min.js';
	private const PV_JS_PATH            = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';
	private const PV_JS_MIN_PATH        = __DIR__ . '/../../assets/js/ltms-plaza-viva.min.js';
	private const API_GATEWAYS_PATH     = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateways.php';

	/**
	 * Extrae el cuerpo de un método de una clase PHP (aproximación strpos,
	 * patrón WishlistPvToggleTest::extract_method).
	 */
	private function extract_method( string $src, string $method_name, string $signature = 'public static function %s(): void' ): string {
		$needle = sprintf( $signature, $method_name );
		$pos    = strpos( $src, $needle );
		if ( false === $pos ) {
			return '';
		}
		return substr( $src, $pos, 2500 );
	}

	// =====================================================================
	// E2E-BUYER-QV-VISGATE: gate de vendibilidad en los handlers vivos
	// =====================================================================

	public function test_qv_visgate_en_checkout_handler(): void {
		$src = (string) file_get_contents( self::CHECKOUT_HANDLER_PATH );
		$qv  = $this->extract_method( $src, 'ajax_quick_view' );

		$this->assertStringContainsString( 'E2E-BUYER-QV-VISGATE', $qv, 'El gate de quick view debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString( 'LTMS_Business_Sync_Visibility_Gate::is_unsellable( $product )', $qv, 'ajax_quick_view debe aplicar el gate de vendibilidad.' );
		$this->assertStringContainsString( 'Producto no disponible', $qv, 'El gate debe rechazar con error, no servir el producto.' );
	}

	public function test_qv_visgate_en_native_templates(): void {
		$src = (string) file_get_contents( self::NATIVE_TEMPLATES_PATH );
		$qv  = $this->extract_method( $src, 'ajax_quick_view' );

		$this->assertStringContainsString( 'E2E-BUYER-QV-VISGATE', $qv, 'El gate del quick view PV debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString( 'LTMS_Business_Sync_Visibility_Gate::is_unsellable( $product )', $qv, 'ltms_pv_quick_view debe aplicar el gate de vendibilidad.' );
		$this->assertStringContainsString( 'Product not available', $qv, 'El gate debe rechazar con error, no servir el producto.' );
	}

	// =====================================================================
	// E2E-BUYER-QV-XSS-EXCERPT: escape del excerpt vendor-controlled
	// =====================================================================

	public function test_qv_excerpt_escapado_en_modal_pv(): void {
		$src = (string) file_get_contents( self::NATIVE_TEMPLATES_PATH );

		$this->assertStringContainsString( 'E2E-BUYER-QV-XSS-EXCERPT', $src, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString(
			'esc_html( wp_trim_words( wp_strip_all_tags( (string) $product->get_short_description() ), 30 ) )',
			$src,
			'El excerpt del modal PV debe escaparse (strip + esc_html).'
		);
		$this->assertStringNotContainsString(
			'echo wp_trim_words( $product->get_short_description(), 30 )',
			$src,
			'El excerpt crudo (HTML de vendor a innerHTML) no debe existir.'
		);
	}

	// =====================================================================
	// E2E-BUYER-QV-BROKEN-BTN: trigger canonical + modal JS legacy fuera
	// =====================================================================

	public function test_qv_boton_legacy_emite_trigger_canonical(): void {
		$src = (string) file_get_contents( self::QUICK_VIEW_PATH );
		$btn = $this->extract_method( $src, 'render_quick_view_button' );

		$this->assertStringContainsString( 'E2E-BUYER-QV-BROKEN-BTN', $src, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString( 'data-pv-quickview=', $btn, 'El botón debe emitir el trigger canonical del design system.' );
		$this->assertStringNotContainsString( 'data-nonce=', $btn, 'El nonce por producto (rechazado por el handler vivo) no debe emitirse.' );
		$this->assertStringNotContainsString( 'wp_create_nonce', $src, 'La generación del nonce muerto ltms_quick_view_{id} no debe existir.' );
		$this->assertStringNotContainsString( 'function ajax_load_product', $src, 'El handler muerto ajax_load_product debe estar eliminado.' );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_ltms_quick_view'", $src, 'El registro del handler muerto no debe existir (ni siquiera comentado como código).' );
	}

	public function test_qv_modal_legacy_js_eliminado(): void {
		$src = (string) file_get_contents( self::PE_JS_PATH );
		$min = (string) file_get_contents( self::PE_JS_MIN_PATH );

		$this->assertStringContainsString( 'E2E-BUYER-QV-BROKEN-BTN', $src, 'El JS debe documentar la eliminación del modal legacy.' );
		$this->assertStringNotContainsString( 'LTMS_QuickView', $src, 'El objeto JS legacy del modal debe estar eliminado.' );
		$this->assertStringNotContainsString( "action: 'ltms_quick_view'", $src, 'El POST al handler estructurado esperando data.html no debe existir.' );
		$this->assertStringNotContainsString( 'ltms-quick-view-modal--open', $src, 'La clase del modal legacy no debe persistir en el JS fuente.' );
		// El .min (el que sirve SG) debe estar sincronizado — sin el modal legacy.
		$this->assertStringNotContainsString( 'ltms_quick_view', $min, 'El .min debe reflejar la eliminación (ci-lint Min files sync).' );
	}

	// =====================================================================
	// E2E-BUYER-QV-ACTION-MISMATCH: PV.quickView llama al handler real
	// =====================================================================

	public function test_pv_quickview_llama_accion_registrada(): void {
		$src = (string) file_get_contents( self::PV_JS_PATH );
		$min = (string) file_get_contents( self::PV_JS_MIN_PATH );

		$this->assertStringContainsString( 'E2E-BUYER-QV-ACTION-MISMATCH', $src, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString( "PV.ajax('ltms_pv_quick_view'", $src, 'PV.quickView debe llamar la acción registrada (ltms_pv_quick_view en LTMS_Native_Templates).' );
		$this->assertStringNotContainsString( "PV.ajax('ltms_plaza_viva_quick_view'", $src, 'La llamada a la acción fantasma (sin handler desde v2.9.183) no debe persistir en el fuente.' );
		// El .min (el que sirve SG) debe reflejar la acción correcta.
		$this->assertStringNotContainsString( "PV.ajax('ltms_plaza_viva_quick_view'", $min, 'El .min debe estar sincronizado con la acción correcta (ci-lint Min files sync).' );
	}

	// =====================================================================
	// E2E-BUYER-CART-NONCE: nonce fatal-if-invalid en ajax_get_cart
	// =====================================================================

	public function test_cart_nonce_fatal_en_get_cart(): void {
		$src   = (string) file_get_contents( self::CHECKOUT_HANDLER_PATH );
		$cart  = $this->extract_method( $src, 'ajax_get_cart', 'public function %s(): void' );

		$this->assertStringContainsString( 'E2E-BUYER-CART-NONCE', $cart, 'El fix debe estar marcado con su ID de tarea.' );
		$this->assertStringContainsString(
			"if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) )",
			$cart,
			'El nonce debe ser fatal-if-invalid (patrón de los hermanos ltms_drawer_*).'
		);
		$this->assertStringContainsString( '403', $cart, 'El nonce inválido debe rechazarse con 403.' );
	}

	// =====================================================================
	// E2E-BUYER-PSE-REMOVAL: endpoint muerto fuera, gateway intacto
	// =====================================================================

	public function test_pse_endpoint_eliminado_del_checkout_handler(): void {
		$src = (string) file_get_contents( self::CHECKOUT_HANDLER_PATH );

		$this->assertStringContainsString( 'E2E-BUYER-PSE-REMOVAL', $src, 'La eliminación debe documentarse con su ID de tarea.' );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_ltms_get_pse_banks'", $src, 'El registro admin-ajax del endpoint muerto no debe existir.' );
		$this->assertStringNotContainsString( 'function ajax_get_pse_banks', $src, 'El método ajax_get_pse_banks no debe existir.' );
		$this->assertStringNotContainsString( 'PSE_BANKS_CO', $src, 'La const de bancos duplicada (la viva es del gateway/API client) no debe existir.' );
	}

	public function test_pse_banks_del_gateway_quedan_intactos(): void {
		$src = (string) file_get_contents( self::API_GATEWAYS_PATH );

		$this->assertStringContainsString( 'private function get_pse_banks', $src, 'El gateway conserva su get_pse_banks server-side (AUDIT-FUNNEL-PAY-12) — la eliminación del endpoint admin-ajax no debe tocarlo.' );
	}
}
