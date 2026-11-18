<?php
/**
 * AuditE2ePay20261117FixesTest — Tests de regresión del ciclo de fixes
 * derivados de la auditoría E2E de compra sandbox del 2026-11-17
 * (AUDIT-E2E-PAY-2026-11-17, reporte QA_REPORT.md §0.17).
 *
 * Cubre:
 *   E2EFIX-03 (P2): el gateway bloquea el cargo de tarjeta cuando falta
 *     device_session_id (Openpay sandbox rechaza con 400 error 1001
 *     "Attribute device_session_id is required") en vez de enviarlo y
 *     fallar con un error opaco para el comprador. Source-based (patrón
 *     PAY-15/C24): process_payment() depende de WC_Payment_Gateway y no
 *     es instanciable en modo unit.
 *   E2EFIX-04 (P2): el script muerto assets/js/ltms-checkout.js (+ min) se
 *     eliminó junto con su enqueue y su wp_localize_script; el manifest de
 *     integration tests ya no lo lista; el handler PHP queda documentado
 *     como huérfano (eliminación en ciclo posterior).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

require_once __DIR__ . '/class-ltms-unit-test-case.php';

/**
 * @covers LTMS_Api_Gateways, LTMS_Frontend_Assets, LTMS_Frontend_Checkout_Handler
 */
class AuditE2ePay20261117FixesTest extends LTMS_Unit_Test_Case {

	private const GATEWAYS_PATH       = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateways.php';
	private const FRONTEND_ASSETS_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-assets.php';
	private const CHECKOUT_HANDLER_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const BIN_TESTS_PATH      = __DIR__ . '/../../bin/ltms-integration-tests.php';
	private const CHECKOUT_TEMPLATE_PATH = __DIR__ . '/../../includes/frontend/templates/checkout.php';
	private const LEGAL_COMPLIANCE_PATH  = __DIR__ . '/../../includes/business/class-ltms-legal-compliance.php';

	// ════════════════════════════════════════════════════════════════════
	// E2EFIX-03: device_session_id ausente → bloquear ANTES del cargo
	// ════════════════════════════════════════════════════════════════════

	public function test_missing_device_session_blocks_charge_before_api_call(): void {
		$source = file_get_contents( self::GATEWAYS_PATH );

		// El bloque de device debe retornar failure con notice (antes del $client->charge).
		$this->assertStringContainsString( 'E2EFIX-03', $source, 'El fix debe estar marcado con su ID de tarea' );
		$this->assertStringContainsString(
			"wc_add_notice(",
			$source,
			'Device ausente debe producir notice para el comprador'
		);
		$this->assertStringContainsString(
			"'result' => 'failure', 'redirect' => ''",
			$source,
			'Device ausente debe retornar result=failure antes del charge'
		);

		// El retorno failure del device-check debe venir ANTES de la llamada al charge.
		$device_check_pos = strpos( $source, "'result' => 'failure', 'redirect' => ''" );
		$charge_pos       = strpos( $source, '$client->charge(' );
		$this->assertNotFalse( $device_check_pos, 'Debe existir el retorno failure del device-check' );
		$this->assertNotFalse( $charge_pos, 'Debe existir la llamada $client->charge' );
		$this->assertLessThan(
			$charge_pos,
			$device_check_pos,
			'El bloqueo por device_session_id debe ejecutarse ANTES de $client->charge()'
		);
	}

	public function test_pay15_log_is_preserved_alongside_e2efix03(): void {
		// El fix PAY-15 (log estructurado para soporte) sigue vivo junto al bloqueo.
		$source = file_get_contents( self::GATEWAYS_PATH );
		$this->assertStringContainsString( 'AUDIT-FUNNEL-PAY-15', $source );
		$this->assertStringContainsString( 'OPENPAY_DEVICE_SESSION_MISSING', $source );
	}

	// ════════════════════════════════════════════════════════════════════
	// E2EFIX-04: script muerto ltms-checkout.js eliminado
	// ════════════════════════════════════════════════════════════════════

	public function test_dead_checkout_js_files_are_deleted(): void {
		$base = dirname( __DIR__, 2 );
		$this->assertFileDoesNotExist( $base . '/assets/js/ltms-checkout.js', 'ltms-checkout.js muerto debe eliminarse' );
		$this->assertFileDoesNotExist( $base . '/assets/js/ltms-checkout.min.js', 'ltms-checkout.min.js muerto debe eliminarse' );
		// Vecinos vivos NO deben eliminarse por error.
		$this->assertFileExists( $base . '/assets/js/ltms-checkout-mexico.js' );
		$this->assertFileExists( $base . '/assets/js/ltms-checkout-fixes.js' );
	}

	public function test_frontend_assets_no_longer_enqueue_or_localize_dead_script(): void {
		$source = file_get_contents( self::FRONTEND_ASSETS_PATH );

		// Enqueue eliminado (el patrón exacto del bloque borrado).
		$this->assertStringNotContainsString(
			"js/ltms-checkout' . \$suffix . '.js",
			$source,
			'El enqueue del script muerto debe estar ausente'
		);

		// Localize CO eliminado (el MX usa handle distinto y debe seguir vivo).
		$this->assertStringNotContainsString(
			"wp_localize_script( 'ltms-checkout', 'ltmsCheckout'",
			$source,
			'El localize CO del script muerto debe estar ausente'
		);
		$this->assertStringContainsString(
			"wp_localize_script( 'ltms-checkout-mexico', 'ltmsCheckout'",
			$source,
			'El localize MX (ltms-checkout-mexico) debe seguir vivo'
		);

		// Config exclusiva del script muerto eliminada.
		$this->assertStringNotContainsString( "'pse_enabled'", $source );
		$this->assertStringNotContainsString( "'addi_enabled'", $source );
	}

	public function test_integration_tests_manifest_no_longer_lists_dead_script(): void {
		$source = file_get_contents( self::BIN_TESTS_PATH );
		$this->assertStringNotContainsString(
			"\n    'assets/js/ltms-checkout.js',",
			$source,
			'El manifest de assets no debe listar el script eliminado'
		);
	}

	public function test_orphaned_endpoint_removed_in_cleanup_cycle(): void {
		// CHECKOUT-LEGACY-REMOVAL-2026-11-18: owner confirmó que nadie consume
		// el endpoint legacy; único consumer era ltms-checkout.js (eliminado en
		// E2EFIX-04). Se eliminan registro admin-ajax, método y maquinaria.
		$source = file_get_contents( self::CHECKOUT_HANDLER_PATH );
		$this->assertStringContainsString( 'CHECKOUT-LEGACY-REMOVAL-2026-11-18', $source, 'El handler debe documentar la eliminación del endpoint legacy' );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_ltms_process_checkout'", $source, 'El registro admin-ajax del endpoint legacy no debe existir' );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_nopriv_ltms_process_checkout'", $source, 'El registro nopriv del endpoint legacy no debe existir' );
		$this->assertStringNotContainsString( 'function ajax_process_checkout', $source, 'El método ajax_process_checkout no debe existir' );

		// Endpoints vivos intactos.
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_get_pse_banks'", $source, 'ltms_get_pse_banks debe seguir registrado' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_get_cart'", $source, 'ltms_get_cart (cart drawer) debe seguir registrado' );
	}

	// ════════════════════════════════════════════════════════════════════
	// GUEST-CONSENT-FIX (P0): el template checkout debe disparar los hooks
	// form-checkout wrappers (paridad con form-checkout.php de WooCommerce).
	// Tras la consolidación 2026-11-18, el checkbox único es
	// ltms_privacy_consent (handler), visible en cualquier template.
	// ════════════════════════════════════════════════════════════════════

	public function test_checkout_template_fires_before_and_after_order_review_hooks(): void {
		$source = file_get_contents( self::CHECKOUT_TEMPLATE_PATH );

		$this->assertStringContainsString( 'GUEST-CONSENT-FIX', $source, 'El fix debe estar marcado con su ID de tarea' );
		$this->assertStringContainsString(
			"do_action( 'woocommerce_checkout_before_order_review' )",
			$source,
			'El template debe disparar woocommerce_checkout_before_order_review (render_customs_estimate)'
		);
		$this->assertStringContainsString(
			"do_action( 'woocommerce_checkout_after_order_review' )",
			$source,
			'El template debe disparar woocommerce_checkout_after_order_review (paridad con form-checkout.php)'
		);

		// El hook after_order_review debe envolver la llamada del order review
		// (así el consent checkbox aparece junto al resumen, como en form-checkout.php).
		// strrpos: la PRIMERA mención de woocommerce_order_review() está en el
		// docblock histórico (CHECKOUT-CTA-VISIBLE FIX); la llamada real va después.
		$before_pos = strpos( $source, "do_action( 'woocommerce_checkout_before_order_review' )" );
		$review_pos = strrpos( $source, 'woocommerce_order_review()' );
		$after_pos  = strpos( $source, "do_action( 'woocommerce_checkout_after_order_review' )" );
		$this->assertNotFalse( $before_pos );
		$this->assertNotFalse( $review_pos );
		$this->assertNotFalse( $after_pos );
		$this->assertLessThan( $review_pos, $before_pos, 'before_order_review debe ir antes del order review' );
		$this->assertLessThan( $after_pos, $review_pos, 'after_order_review debe ir después del order review' );
	}

	public function test_legal_compliance_consent_consolidated_into_single_field(): void {
		$source = file_get_contents( self::LEGAL_COMPLIANCE_PATH );

		// GUEST-CONSENT-FIX-2026-11-18: checkbox único — legal ya no renderiza
		// ni valida un segundo campo (el duplicado ltms_checkout_consent fue
		// eliminado junto con su hook); el rastro legal se guarda desde el mismo
		// checkbox del template (ltms_privacy_consent).
		$this->assertStringNotContainsString( 'render_checkout_consent_field', $source );
		$this->assertStringNotContainsString( 'validate_checkout_consent', $source );
		$this->assertStringContainsString(
			"add_action( 'woocommerce_checkout_order_created',    [ __CLASS__, 'save_checkout_consent' ], 10, 1 )",
			$source,
			'El registro del rastro legal debe seguir enganchado a checkout_order_created'
		);
		// El save acepta el campo único (privacy) y el legado.
		$this->assertStringContainsString( "\$_POST['ltms_checkout_consent']", $source );
		$this->assertStringContainsString( "\$_POST['ltms_privacy_consent']", $source );

		// El único checkbox lo renderiza y valida el handler.
		$handler = file_get_contents( self::CHECKOUT_HANDLER_PATH );
		$this->assertStringContainsString( 'name="ltms_privacy_consent"', $handler );
		$this->assertStringContainsString( "\$_POST['ltms_privacy_consent']", $handler );
	}
}
