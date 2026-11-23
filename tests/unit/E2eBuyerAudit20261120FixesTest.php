<?php
/**
 * E2eBuyerAudit20261120FixesTest — ciclo E2E-BUYER-2026-11-20 (viaje completo
 * del comprador). Fixes cubiertos:
 *
 *   - PAYOUT-SHIPPING-CHOSEN (P1): el checkout pre-checkeaba SIEMPRE la primera
 *     tarifa de envío — el total mostrado (order review, elegido en sesión) no
 *     correspondía al cobrado (POST con la primera tarifa). El render respeta
 *     chosen_shipping_methods por paquete; el JS dispara update_checkout.
 *   - OPENPAY-PHONE-CONTRACT (P1): create_charge leía 'phone' pero los gateways
 *     CO/MX envían 'phone_number' — cada cargo de tarjeta salía con teléfono
 *     vacío (antifraude Openpay ciego).
 *   - E2E-BUYER-FAILED-GUARD + E2E-BUYER-AMOUNT-GUARD (P2): cubiertos con tests
 *     funcionales en OpenpayWebhookDocsAlignmentTest (3a-bis).
 *   - E2E-BUYER-COUNTRY-GUARD (P2): optimize_checkout_fields hardcodeaba CO +
 *     CP 110111 sin guard de país — en stores MX ocultaba el CP necesario para
 *     zonas de envío.
 *   - E2E-BUYER-SEARCH-NONCE (P1): la búsqueda viva exigía ltms_dashboard_nonce
 *     a todo usuario logueado, pero el header envía ltms_plaza_viva → 403.
 *   - E2E-BUYER-VENDOR-URL (P2): el filtro ltms_vendor_store_url no tenía
 *     implementación — las cards enlazaban a /vendor/{id}/ (404).
 *   - E2E-BUYER-ATC-VARIABLE (P2): el ATC de archive/cart hardcodeaba
 *     product_type_simple con el ID del padre variable → error de validación.
 *   - E2E-BUYER-MINICART-ESCAPE (P2): esc_html mangleaba el HTML de wc_price.
 *   - E2E-BUYER-REFUND-TZ (P2): port del fix FASE1-REAUDIT P1 de timezone a
 *     estimate_refund del frontend.
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true. No dependen de WooCommerce cargado (patrón
 * CheckoutOrderReviewWc11Test).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Class E2eBuyerAudit20261120FixesTest
 *
 * @group checkout
 * @group e2e-buyer
 */
final class E2eBuyerAudit20261120FixesTest extends LTMS_Unit_Test_Case {

	private const CHECKOUT_TPL   = __DIR__ . '/../../includes/frontend/templates/checkout.php';
	private const PV_JS          = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';
	private const API_OPENPAY    = __DIR__ . '/../../includes/api/class-ltms-api-openpay.php';
	private const CO_HANDLER     = __DIR__ . '/../../includes/frontend/class-ltms-frontend-checkout-handler.php';
	private const LIVE_SEARCH    = __DIR__ . '/../../includes/frontend/class-ltms-frontend-live-search.php';
	private const ARCHIVE_TPL    = __DIR__ . '/../../includes/frontend/templates/archive-product.php';
	private const CART_TPL       = __DIR__ . '/../../includes/frontend/templates/cart.php';
	private const CART_DRAWER    = __DIR__ . '/../../includes/frontend/class-ltms-cart-drawer.php';
	private const BOOKINGS       = __DIR__ . '/../../includes/frontend/class-ltms-frontend-customer-bookings.php';
	private const STOREFRONT     = __DIR__ . '/../../includes/frontend/class-ltms-vendor-storefront.php';
	private const WEBHOOK_HANDLER = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const GATEWAYS_CO    = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateways.php';
	private const GATEWAYS_MX    = __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateway-openpay-mx.php';
	private const MX_JS          = __DIR__ . '/../../assets/js/ltms-openpay-mx.js';

	// ── PAYOUT-SHIPPING-CHOSEN (P1) ──────────────────────────────────────────

	public function test_checkout_shipping_radio_respects_chosen_method(): void {
		$src = file_get_contents( self::CHECKOUT_TPL );

		$this->assertStringContainsString(
			'PAYOUT-SHIPPING-CHOSEN FIX',
			(string) $src,
			'El fix debe estar marcado en checkout.php.'
		);
		$this->assertStringContainsString(
			"chosen_shipping_methods",
			(string) $src,
			'El render de radios debe leer chosen_shipping_methods de la sesión.'
		);
		$this->assertStringNotContainsString(
			'$checked  = ( $ship_idx === 0 ); // Primera opción por defecto.',
			(string) $src,
			'El pre-check incondicional de la primera tarifa no debe quedar.'
		);
	}

	public function test_shipping_radio_change_triggers_wc_update_checkout(): void {
		$src = file_get_contents( self::PV_JS );

		$this->assertStringContainsString(
			"E2E-BUYER-SHIPPING-SYNC",
			(string) $src,
			'El JS debe estar marcado con el tag del fix.'
		);
		$this->assertStringContainsString(
			"trigger('update_checkout')",
			(string) $src,
			'El cambio de radio de envío debe disparar update_checkout (WC recalcula totales y persiste la elección).'
		);
	}

	// ── OPENPAY-PHONE-CONTRACT (P1) ──────────────────────────────────────────

	public function test_create_charge_reads_phone_number_key(): void {
		$src = file_get_contents( self::API_OPENPAY );

		$this->assertStringContainsString(
			'OPENPAY-PHONE-CONTRACT FIX',
			(string) $src,
			'El fix de contrato phone/phone_number debe estar marcado en api-openpay.'
		);
		$this->assertStringContainsString(
			"\$customer['phone_number'] ?? \$customer['phone']",
			(string) $src,
			'create_charge debe leer ambas keys (gateways envían phone_number).'
		);
	}

	// ── E2E-BUYER-COUNTRY-GUARD (P2) ─────────────────────────────────────────

	public function test_checkout_fields_optimization_guards_base_country(): void {
		$src = (string) file_get_contents( self::CO_HANDLER );

		$this->assertSame(
			2,
			substr_count( $src, 'E2E-BUYER-COUNTRY-GUARD FIX' ),
			'El guard de país debe estar en ambas versiones de optimize_checkout_fields (temprana y tardía).'
		);
		$this->assertStringContainsString(
			"get_base_country()",
			$src,
			'El guard debe derivar el país base de WC, no hardcodearlo.'
		);
	}

	// ── E2E-BUYER-SEARCH-NONCE (P1) ──────────────────────────────────────────

	public function test_live_search_does_not_die_on_missing_dashboard_nonce(): void {
		$src = (string) file_get_contents( self::LIVE_SEARCH );

		$this->assertStringContainsString(
			'E2E-BUYER-SEARCH-NONCE FIX',
			$src,
			'El fix de nonce de búsqueda debe estar marcado.'
		);
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_dashboard_nonce', 'nonce', false )",
			$src,
			'check_ajax_referer debe correr con $die=false para no matar la búsqueda pública del comprador logueado.'
		);
	}

	// ── E2E-BUYER-VENDOR-URL (P2) ────────────────────────────────────────────

	public function test_vendor_store_url_filter_is_registered_with_default_implementation(): void {
		$src = (string) file_get_contents( self::STOREFRONT );

		$this->assertStringContainsString(
			'E2E-BUYER-VENDOR-URL FIX',
			$src,
			'El registro del filtro default debe estar marcado.'
		);
		$this->assertStringContainsString(
			"add_filter( 'ltms_vendor_store_url', [ __CLASS__, 'vendor_store_url' ], 10, 2 );",
			$src,
			'El filtro ltms_vendor_store_url debe registrarse en el dueño de la ruta.'
		);
		$this->assertStringContainsString(
			'function vendor_store_url( $url, $vendor_id ): string',
			$src,
			'La implementación default debe existir.'
		);
		$this->assertStringContainsString(
			"get_user_by( 'login'",
			$src,
			'La ruta resuelve por user_login — la URL default debe usar user_login.'
		);
		$this->assertStringContainsString(
			"'ltms_store_slug'",
			$src,
			'Revisión del fix: el slug debe preferir la meta ltms_store_slug (fuente de verdad post-backfill).'
		);
		$this->assertStringContainsString(
			'sanitize_title( $vendor->user_login )',
			$src,
			'El fallback del slug debe ser sanitize_title del login.'
		);
	}

	// ── E2E-BUYER-ATC-VARIABLE (P2) ──────────────────────────────────────────

	public function test_archive_and_cart_templates_handle_variable_products(): void {
		foreach ( [ self::ARCHIVE_TPL, self::CART_TPL ] as $tpl ) {
			$src = (string) file_get_contents( $tpl );

			$this->assertStringContainsString(
				"E2E-BUYER-ATC-VARIABLE FIX",
				$src,
				basename( $tpl ) . ': el fix de ATC variable debe estar marcado.'
			);
			$this->assertStringContainsString(
				"is_type( 'variable' )",
				$src,
				basename( $tpl ) . ': el ATC debe bifurcar para productos variables.'
			);
		}
	}

	// ── E2E-BUYER-MINICART-ESCAPE (P2) ───────────────────────────────────────

	public function test_mini_cart_shipping_bar_does_not_double_escape_wc_price(): void {
		$src = (string) file_get_contents( self::CART_DRAWER );

		$this->assertStringContainsString(
			'E2E-BUYER-MINICART-ESCAPE FIX',
			$src,
			'El fix de escape del mini-cart debe estar marcado.'
		);
		$this->assertStringNotContainsString(
			"esc_html( sprintf( __( 'Te faltan %s",
			$src,
			'esc_html alrededor de wc_price no debe quedar (mangleaba el span del precio).'
		);
		$this->assertStringNotContainsString(
			"esc_html__( '&#x1F389;",
			$src,
			'La entidad HTML del emoji no debe quedar dentro de esc_html__ (se imprimía como texto).'
		);
	}

	// ── E2E-BUYER-REFUND-TZ (P2) ─────────────────────────────────────────────

	public function test_estimate_refund_uses_gmt_parsing(): void {
		$src = (string) file_get_contents( self::BOOKINGS );

		$this->assertStringContainsString(
			'E2E-BUYER-REFUND-TZ FIX',
			$src,
			'El port del fix de timezone debe estar marcado en customer-bookings.'
		);
		$this->assertStringContainsString(
			"mysql2date( 'U', \$booking['checkin_date'], true )",
			$src,
			'estimate_refund debe parsear el check-in en GMT (patrón FASE1-REAUDIT P1).'
		);
	}

	// ── E2E-BUYER-MX-CARD + E2E-BUYER-REFUND-RECORD (P2) ─────────────────────

	public function test_mx_gateway_has_tokenization_script_and_localizes_i18n(): void {
		$this->assertFileExists(
			__DIR__ . '/../../assets/js/ltms-openpay-mx.js',
			'E2E-BUYER-MX-CARD: ltms-openpay-mx.js debe existir (antes el checkout MX card estaba roto: token vacío, validate_fields bloqueaba siempre).'
		);

		$js = (string) file_get_contents( __DIR__ . '/../../assets/js/ltms-openpay-mx.js' );
		$this->assertStringContainsString( "checkout_place_order_ltms_openpay_mx", $js, 'El JS MX debe engancharse al evento nativo del gateway.' );
		$this->assertStringContainsString( 'ltms_openpay_mx_token', $js, 'El JS MX debe setear el token que valida el gateway.' );
		$this->assertStringContainsString( 'OpenPay.deviceData.setup', $js, 'El JS MX debe capturar device session id (antifraude).' );

		$gateway = (string) file_get_contents( __DIR__ . '/../../includes/api/gateways/class-ltms-api-gateway-openpay-mx.php' );
		$this->assertStringContainsString( "'i18n'", $gateway, 'El gateway MX debe localizar i18n para el JS de tokenización.' );
	}

	public function test_refund_webhook_creates_wc_refund_record(): void {
		$src = (string) file_get_contents( self::WEBHOOK_HANDLER );

		$this->assertStringContainsString(
			'E2E-BUYER-REFUND-RECORD FIX',
			$src,
			'El fix de registro de refund WC debe estar marcado en el webhook.'
		);
		$this->assertStringContainsString(
			'wc_create_refund',
			$src,
			'La rama refund debe crear el registro WC (reportes/comisiones/stock ven el dinero devuelto).'
		);
		$this->assertStringContainsString(
			"_ltms_openpay_refund_events",
			$src,
			'La idempotencia de refunds por evento debe vivir en meta del pedido.'
		);

		// Anti-loop admin→webhook (revisión 2026-11-20): ambos gateways registran
		// el id del refund en la MISMA meta que consulta el webhook.
		$co  = (string) file_get_contents( self::GATEWAYS_CO );
		$mx  = (string) file_get_contents( self::GATEWAYS_MX );
		$this->assertStringContainsString( '_ltms_openpay_refund_events', $co, 'El gateway CO debe registrar el id del refund en la meta anti-duplicado.' );
		$this->assertStringContainsString( '_ltms_openpay_refund_events', $mx, 'El gateway MX debe registrar el id del refund en la meta anti-duplicado.' );

		// PAN/CVV nunca al servidor (revisión 2026-11-20): inputs de tarjeta sin name.
		$js_mx = (string) file_get_contents( self::MX_JS );
		$this->assertStringNotContainsString( 'name="ltms_mx_card', $js_mx, 'Los inputs de PAN/CVV no deben tener atributo name (no deben postearse al servidor).' );
	}
}
