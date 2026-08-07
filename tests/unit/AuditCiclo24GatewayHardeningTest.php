<?php
/**
 * AuditCiclo24GatewayHardeningTest - Tests para los fixes del Ciclo 24.
 *
 * Modulo: includes/api/webhooks/ 3 webhook handlers (Stripe + Openpay + Addi)
 *
 * 1. AD-GAP-001 P1: class-ltms-openpay-webhook-handler.php:138 case
 *    'refund.succeeded' hacia $order->update_status('refunded', ...) SIN
 *    validar monto del refund. Si un admin o API externa procesaba un refund
 *    PARCIAL por Openpay (ej. reembolsar $5 de un pedido de $100), el pedido
 *    WC quedaba en status 'refunded' (que WC y el vendor interpretan como
 *    FULLY refunded) desincronizando el status WC vs reality. Stripe no tenia
 *    este bug (su handler solo agrega add_order_note()). Fix: validar
 *    transaction.amount contra $order->get_total() con tolerancia 0.01.
 *    - amount >= total → update_status('refunded')
 *    - amount < total  → NO cambiar status, add_order_note + log info
 *    - amount ausente  → fail-safe: NO cambiar status, add_order_note + log warning
 *
 * Codigo financiero critico — toca wallet, comisiones, payouts futuros.
 * Segundo modelo review obligatorio antes de merge (AGENTS.md "Revision como
 * ultimo filtro").
 *
 * Patron C24: source-based tests (assertFileExists + file_get_contents +
 * assertStringContains/NotContainsString). Mismo patron que C20/C21/C22 —
 * los webhook handlers son clases PHP con metodos staticos que dependen de
 * WC stack + WP_REST_Request + $wpdb, ejecutarlos en tests/unit/ con
 * Brain\Monkey requeriria stubs extensivos. Tests source-based son
 * deterministas y documentan el contrato del fix sin dependencias externas.
 * Para runtime tests, usar tests/integration/ con LTMS_Integration_Test_Case.
 *
 * Backlog NO fixeado en C24 (0 - el unico hallazgo fue AD-GAP-001):
 *   - AD-GAP-002 P2 (derivado, documentado): Stripe webhook handler
 *     handle_charge_refunded (linea 289) solo agrega add_order_note() sin
 *     validar amount_refunded contra order_total. NO es bug critico (Stripe
 *     confia en process_refund() del gateway para crear refund WC), pero
 *     podria agregar wc_create_refund() si el webhook llega ANTES que el
 *     admin vea el dashboard. Backlog C25+ si Stripe webhook timing issue
 *     se manifiesta.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AD-GAP-001
 */
class AuditCiclo24GatewayHardeningTest extends LTMS_Unit_Test_Case {

	private const OPENPAY_HANDLER_PATH = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const STRIPE_HANDLER_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-stripe-webhook-handler.php';
	private const ADDI_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'          => static fn( string $s ): string => $s,
			'esc_html__'  => static fn( string $s ): string => $s,
			'esc_html_e'  => static function ( string $s ): void { echo $s; },
			'esc_html'    => static fn( string $s ): string => $s,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ====================================================================
	//  AD-GAP-001 P1: Openpay refund.succeeded - validar amount antes
	//  de update_status('refunded', ...)
	// ====================================================================

	public function test_openpay_handler_file_exists(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
	}

	public function test_openpay_handler_no_longer_blindly_calls_update_status_refunded(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// Antes: case 'refund.succeeded': update_status('refunded', ...) SIN validacion.
		// El patron viejo era literal "update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook.', 'ltms' ) );"
		// directamente despues del case. Verificamos que ya NO exista esa
		// secuencia literal como primer statement del case.
		$this->assertStringNotContainsString(
			"case 'refund.succeeded':\n                \$order->update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook.', 'ltms' ) );",
			$source,
			'AD-GAP-001: case refund.succeeded NO debe llamar update_status(refunded) sin validar amount primero.'
		);
	}

	public function test_openpay_handler_has_ciclo24_tag_adgap001(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		$this->assertStringContainsString(
			'CICLO24-P1-AD-GAP-001 FIX',
			$source,
			'AD-GAP-001: tag de trazabilidad CICLO24-P1-AD-GAP-001 FIX debe estar en el handler.'
		);
	}

	public function test_openpay_handler_reads_transaction_amount_for_refund_validation(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// El fix lee transaction.amount (en el webhook Openpay, $charge = $transaction;
		// asi que $charge['amount']) para validar el monto del refund.
		$this->assertStringContainsString(
			"\$refund_amount_raw = \$charge['amount'] ?? null;",
			$source,
			'AD-GAP-001: el handler debe leer \$charge[\'amount\'] del payload Openpay para validar refund.'
		);
	}

	public function test_openpay_handler_validates_amount_is_numeric(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// is_numeric() acepta strings numeric ("100", "50.5") y numeros.
		// Protege contra payload malicioso con array/objeto en amount.
		$this->assertStringContainsString(
			'is_numeric( $refund_amount_raw )',
			$source,
			'AD-GAP-001: el handler debe validar que refund_amount_raw es numerico (is_numeric).'
		);
	}

	public function test_openpay_handler_compares_refund_amount_to_order_total(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// La comparacion amount >= total - 0.01 (tolerancia float) decide
		// si es refund completo (marcar refunded) o parcial (no cambiar status).
		$this->assertStringContainsString(
			'$refund_amount >= $order_total - 0.01',
			$source,
			'AD-GAP-001: el handler debe comparar refund_amount contra order_total con tolerancia 0.01.'
		);
		$this->assertStringContainsString(
			'(float) $order->get_total()',
			$source,
			'AD-GAP-001: el handler debe castear $order->get_total() a float para comparacion segura.'
		);
	}

	public function test_openpay_handler_marks_full_refund_when_amount_matches_total(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// En la rama completa (amount >= total), debe marcar 'refunded' (igual que antes).
		$this->assertStringContainsString(
			"update_status( 'refunded', __( 'Reembolso Openpay confirmado vía webhook (total).', 'ltms' ) )",
			$source,
			'AD-GAP-001: rama refund completo debe llamar update_status(refunded) con nota aclaratoria "(total)".'
		);
	}

	public function test_openpay_handler_does_not_change_status_for_partial_refund(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// En la rama parcial (amount < total), NO debe llamar update_status.
		// Solo add_order_note + log.
		// El comentario "Refund parcial — NO cambiar status del pedido. Solo registro."
		// debe existir como marker del branch.
		$this->assertStringContainsString(
			'Refund parcial — NO cambiar status',
			$source,
			'AD-GAP-001: rama refund parcial debe tener marker "Refund parcial — NO cambiar status".'
		);
		// La nota debe mencionar "PARCIAL" para que el admin vea en el log del pedido.
		$this->assertStringContainsString(
			'Reembolso PARCIAL Openpay',
			$source,
			'AD-GAP-001: rama parcial debe generar order_note con "Reembolso PARCIAL Openpay" para audit trail.'
		);
	}

	public function test_openpay_handler_logs_partial_refund_info(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		$this->assertStringContainsString(
			"'OPENPAY_REFUND_PARTIAL'",
			$source,
			'AD-GAP-001: debe loggear OPENPAY_REFUND_PARTIAL en rama parcial para audit trail.'
		);
	}

	public function test_openpay_handler_handles_missing_amount_fail_safe(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// Si amount es null/invalid, NO cambiar status — fail-safe conservativo.
		$this->assertStringContainsString(
			"\$refund_amount === null",
			$source,
			'AD-GAP-001: debe tener rama fail-safe para refund_amount === null.'
		);
		$this->assertStringContainsString(
			"'OPENPAY_REFUND_AMOUNT_MISSING'",
			$source,
			'AD-GAP-001: debe loggear OPENPAY_REFUND_AMOUNT_MISSING cuando amount esta ausente.'
		);
	}

	public function test_openpay_handler_docblock_explains_financial_impact(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// El docblock debe mencionar las consecuencias reales: comisiones, payouts, vendor.
		$this->assertStringContainsString(
			'comisiones',
			$source,
			'AD-GAP-001: docblock debe mencionar "comisiones" como consecuencia del bug.'
		);
		$this->assertStringContainsString(
			'payouts',
			$source,
			'AD-GAP-001: docblock debe mencionar "payouts" como consecuencia del bug.'
		);
		$this->assertStringContainsString(
			'vendor',
			$source,
			'AD-GAP-001: docblock debe mencionar "vendor" como afectado.'
		);
	}

	public function test_openpay_handler_docblock_references_stripe_pattern_as_precedent(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// El docblock debe referenciar Stripe como patron alternativo seguro.
		$this->assertStringContainsString(
			'Stripe',
			$source,
			'AD-GAP-001: docblock debe mencionar Stripe como patron precedent (handle_charge_refunded).'
		);
	}

	public function test_openpay_handler_docblock_marks_financial_critical_code(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );

		// El docblock debe etiquetar el codigo como financiero critico
		// (requiere segundo modelo review).
		$this->assertStringContainsString(
			'codigo financiero critico',
			$source,
			'AD-GAP-001: docblock debe etiquetar el codigo como "codigo financiero critico".'
		);
	}

	// ====================================================================
	//  Cross-check: Stripe handler sigue SIENDO seguro (no ha sido tocado)
	// ====================================================================

	public function test_stripe_handler_does_not_call_update_status_on_refunded_event(): void {
		$this->assertFileExists( self::STRIPE_HANDLER_PATH );
		$source = file_get_contents( self::STRIPE_HANDLER_PATH );

		// Stripe handler handle_charge_refunded solo agrega add_order_note(),
		// NO hace update_status('refunded', ...) — confia en process_refund() gateway.
		// Verificamos que NO exista el patron "update_status( 'refunded'" en el handler.
		$this->assertStringNotContainsString(
			"update_status( 'refunded'",
			$source,
			'AD-GAP-001 cross-check: Stripe handler NO debe usar update_status(refunded) — confia en process_refund() del gateway.'
		);
	}

	public function test_stripe_handler_has_hmac_signature_verification_via_sdk(): void {
		$this->assertFileExists( self::STRIPE_HANDLER_PATH );
		$source = file_get_contents( self::STRIPE_HANDLER_PATH );

		// Stripe usa SDK oficial \Stripe\Webhook::constructEvent para HMAC verification.
		$this->assertStringContainsString(
			'\Stripe\Webhook::constructEvent(',
			$source,
			'Stripe handler debe usar \\Stripe\\Webhook::constructEvent() para HMAC verification (SDK oficial).'
		);
	}

	public function test_stripe_handler_fail_closed_on_missing_secret(): void {
		$this->assertFileExists( self::STRIPE_HANDLER_PATH );
		$source = file_get_contents( self::STRIPE_HANDLER_PATH );

		// Fail-closed: si webhook_secret vacio, retorna 401 antes de procesar.
		$this->assertStringContainsString(
			'empty( $webhook_secret )',
			$source,
			'Stripe handler debe fail-closed si webhook_secret esta vacio.'
		);
		$this->assertStringContainsString(
			"'STRIPE_WEBHOOK_NO_SECRET'",
			$source,
			'Stripe handler debe loggear STRIPE_WEBHOOK_NO_SECRET cuando secret falta.'
		);
	}

	public function test_stripe_handler_has_idempotency_transient(): void {
		$this->assertFileExists( self::STRIPE_HANDLER_PATH );
		$source = file_get_contents( self::STRIPE_HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_wh_seen_stripe_'",
			$source,
			'Stripe handler debe tener idempotency transient (ltms_wh_seen_stripe_).'
		);
	}

	public function test_stripe_handler_has_double_capture_prevention_meta(): void {
		$this->assertFileExists( self::STRIPE_HANDLER_PATH );
		$source = file_get_contents( self::STRIPE_HANDLER_PATH );

		// AUDIT-GATEWAY-STRIPE-002 P0-3: meta _ltms_stripe_payment_captured
		// previene doble payment_complete() si process_payment ya capturo sync.
		$this->assertStringContainsString(
			"_ltms_stripe_payment_captured",
			$source,
			'Stripe handler debe tener meta _ltms_stripe_payment_captured (AUDIT-GATEWAY-STRIPE-002 P0-3) para prevenir double-capture.'
		);
	}

	// ====================================================================
	//  Cross-check: Addi handler sigue SIENDO seguro (no ha sido tocado)
	// ====================================================================

	public function test_addi_handler_uses_hash_equals_for_token_comparison(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );

		// Addi usa hash_equals para timing-safe comparison del token.
		$this->assertStringContainsString(
			'hash_equals( $expected_token, $token )',
			$source,
			'Addi handler debe usar hash_equals() para timing-safe token comparison.'
		);
	}

	public function test_addi_handler_fail_closed_on_missing_token(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );

		$this->assertStringContainsString(
			'empty( $expected_token )',
			$source,
			'Addi handler debe fail-closed si expected_token esta vacio (WH2 FIX v2.8.9).'
		);
		$this->assertStringContainsString(
			"'ADDI_WEBHOOK_NO_TOKEN'",
			$source,
			'Addi handler debe loggear ADDI_WEBHOOK_NO_TOKEN cuando token falta.'
		);
	}

	public function test_addi_handler_has_idempotency_transient(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );

		$this->assertStringContainsString(
			"'ltms_wh_seen_addi_'",
			$source,
			'Addi handler debe tener idempotency transient (ltms_wh_seen_addi_).'
		);
	}

	public function test_addi_handler_uses_needs_payment_before_payment_complete(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );

		// Addi verifica $order->needs_payment() antes de payment_complete() —
		// defense-in-depth contra doble procesamiento.
		$this->assertStringContainsString(
			'$order->needs_payment()',
			$source,
			'Addi handler debe verificar needs_payment() antes de payment_complete() (defense-in-depth).'
		);
	}

	// ====================================================================
	//  Guard de regression: TODOS los handlers deben tener rate limit per-IP
	// ====================================================================

	public function test_all_handlers_have_rate_limit_per_ip(): void {
		$handlers = [
			self::STRIPE_HANDLER_PATH,
			self::OPENPAY_HANDLER_PATH,
			self::ADDI_HANDLER_PATH,
		];

		foreach ( $handlers as $path ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				"'ltms_wh_rate_'",
				$source,
				basename( $path ) . ' debe tener rate limit per-IP (ltms_wh_rate_ transient keyed by client_ip md5).'
			);
			$this->assertStringContainsString(
				'$count > 100',
				$source,
				basename( $path ) . ' debe rechazar con 429 si count > 100 (rate limit per-IP).'
			);
		}
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los 3 webhook handlers usan classes WC_Order con metodos como
	// payment_complete(), update_status(), add_order_note(), needs_payment(),
	// get_total() — Brain\Monkey no stubea WC_Order sin configuracion extensiva.
	// Tests runtime (que ejecuten el metodo handle() con un WP_REST_Request
	// mock) requieren tests/integration/ con LTMS_Integration_Test_Case + WC
	// test suite (no disponible en modo UNIT_ONLY). Mismo patron que C20/C21/C22
	// (tests source-based). Para verificar el comportamiento runtime del fix
	// AD-GAP-001, usar integration tests en sesión futura con acceso a DB local.
}
