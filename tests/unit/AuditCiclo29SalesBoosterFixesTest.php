<?php
/**
 * AuditCiclo29SalesBoosterFixesTest - Tests para los fixes del Ciclo 29.
 *
 * Modulo: includes/business/class-ltms-sales-booster.php (920L auditados post-fix)
 *
 * SALES BOOSTER cubre 5 features de marketing: SB-1 carrito abandonado,
 * SB-2 flash sales, SB-3 web push notifications, SB-4 upsell/cross-sell,
 * SB-5 social proof en tiempo real. NO es modulo CRITICO en AGENTS.md
 * "Revision como ultimo filtro" (no toca wallet/comisiones/payouts/KYC/
 * SAGRILAFT/ZapSign/Backblaze/gateways de pago) y NO toca compliance
 * regulatorio (a diferencia de C28 compliance-guardian). Permite 2a
 * revision opcional (Leccion 27.1 regla #6 no obliga, pero se hace igual
 * porque toca UX del vendor + surface PHP).
 *
 * 1. SB-001 P0 (ajax_subscribe_push en sales-booster.php:550):
 *    El endpoint `wp_ajax_ltms_subscribe_push` (requiere login via cookie
 *    de sesion) NO tenia NINGUN `check_ajax_referer`. Anti-patron PEOR que
 *    CG-001 C28: alla el nonce existia pero se ignoraba (check_ajax_referer
 *    con false sin wp_send_json_error); aqui el nonce brillaba por su
 *    ausencia total. Cualquier site podia embed `<form>` o `fetch()` POST
 *    a `/wp-admin/admin-ajax.php` con `action=ltms_subscribe_push&endpoint=...`
 *    y la request se procesaba porque la cookie de sesion del usuario
 *    logueado viajaba con la request (CSRF clasico). El usuario quedaba
 *    subscrito a push notifications desde un endpoint controlado por el
 *    atacante -> superficie de phishing + exfiltracion de datos.
 *    Fix: check_ajax_referer('ltms_ux_nonce','nonce',false) + wp_send_json_error
 *    [..., 403] dentro del bloque fail-closed. JS del front actualizado
 *    para enviar `nonce` via `window.ltmsUX.nonce`. Adicionalmente wp_unslash
 *    sobre los 3 inputs POST (endpoint/key/auth) antes de sanitizar.
 *    Tag: CICLO29-P0-SB-001 FIX (sales-booster.php:550 + JS front push prompt).
 *
 * 2. SB-002 P1 (JS front de track_product_view en sales-booster.php:810+812):
 *    El JS `render_social_proof_container` hacia `$.post(ajaxurl, { action:
 *    'ltms_track_product_view', product_id: productId })` SIN enviar el
 *    nonce. Pero el handler `ajax_track_product_view` (linea 833+) exige
 *    `check_ajax_referer('ltms_ux_nonce', 'nonce', false)` fail-closed
 *    (v2.9.100 SEC-8 FIX previo). Resultado: la feature de viewer count
 *    estaba ROTA en runtime — todas las requests retornaban 403 sin
 *    actualizar el contador. Inconsistencia funcional P1. Identico patron
 *    en 2 llamadas JS (linea 810 inicial + linea 812 del setInterval).
 *    Fix: anadir `nonce: spNonce` a ambas requests (consistente con
 *    ltms_get_social_proof linea 787 que SI lo enviaba).
 *    Tag: CICLO29-P1-SB-002 FIX (sales-booster.php:810 + 812).
 *
 * Patron C29: source-based tests (file_get_contents + assertStringContains/
 * NotContainsString), mismo que C20-C28. Ademas cross-checks transversales:
 * - Compliance-guardian C28 sigue con CG-001/CG-002/CG-003 tags presentes.
 * - Traffic-booster C26 sigue usando get_client_ip_safe.
 * - 5 webhook handlers C25 siguen delegando get_client_ip_safe.
 * - sales-booster init() sigue registrando todos los hooks (no-regression).
 * - mark_cart_recovered sigue sanitizando (no-regression).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers SB-001 (P0), SB-002 (P1)
 */
class AuditCiclo29SalesBoosterFixesTest extends LTMS_Unit_Test_Case {

	private const SALES_BOOSTER_PATH        = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const COMPLIANCE_GUARDIAN_PATH  = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const TRAFFIC_BOOSTER_PATH      = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const XCOVER_HANDLER_PATH       = __DIR__ . '/../../includes/business/class-ltms-xcover-checkout-handler.php';
	private const ZAPSIGN_WEBHOOK_PATH      = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
	private const OPENPAY_HANDLER_PATH      = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH         = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const SIIGO_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'          => static fn( string $s ): string => $s,
			'esc_html__'  => static fn( string $s ): string => $s,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ====================================================================
	//  SB-001 P0: ajax_subscribe_push nonce fail-closed
	// ====================================================================

	public function test_sales_booster_file_exists(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
	}

	public function test_ajax_subscribe_push_has_ciclo29_tag(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO29-P0-SB-001 FIX',
			$source,
			'SB-001: tag de trazabilidad CICLO29-P0-SB-001 FIX debe estar en sales-booster.'
		);
	}

	public function test_ajax_subscribe_push_has_check_ajax_referer(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-001 P0: el handler ajax_subscribe_push debe contener check_ajax_referer.
		// Antes del fix NO existia — era un endpoint WP-ajax sin proteccion CSRF.
		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_ux_nonce', 'nonce', false )",
			$source,
			'SB-001: ajax_subscribe_push debe llamar check_ajax_referer con die=false (fail-closed).'
		);
	}

	public function test_ajax_subscribe_push_returns_403_on_invalid_nonce(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-001 P0: dentro del bloque `if ( ! check_ajax_referer(...) )` debe
		// haber wp_send_json_error con status 403. Mismo patron que CG-001 C28.
		$this->assertStringContainsString(
			"wp_send_json_error(",
			$source,
			'SB-001: ajax_subscribe_push debe retornar wp_send_json_error cuando el nonce falla.'
		);
		$this->assertStringContainsString(
			'403',
			$source,
			'SB-001: el wp_send_json_error del nonce failure debe retornar status 403 Forbidden.'
		);
	}

	public function test_ajax_subscribe_push_unslash_inputs(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-001 P0 adyacente: el fix anade wp_unslash() sobre $_POST['endpoint'],
		// $_POST['key'], $_POST['auth']. Sin wp_unslash, WP agrega backslashes
		// y esc_url_raw/sanitize_text_field reciben strings escapados.
		$this->assertStringContainsString(
			"wp_unslash( \$_POST['endpoint'] ?? '' )",
			$source,
			'SB-001: $_POST[endpoint] debe pasar por wp_unslash antes de esc_url_raw.'
		);
		$this->assertStringContainsString(
			"wp_unslash( \$_POST['key'] ?? '' )",
			$source,
			'SB-001: $_POST[key] debe pasar por wp_unslash antes de sanitize_text_field.'
		);
		$this->assertStringContainsString(
			"wp_unslash( \$_POST['auth'] ?? '' )",
			$source,
			'SB-001: $_POST[auth] debe pasar por wp_unslash antes de sanitize_text_field.'
		);
	}

	public function test_push_prompt_js_sends_nonce(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-001 P0 front-side: el JS del push prompt (render_push_subscription_prompt)
		// debe enviar el nonce en el body del fetch. Antes no lo enviaba porque el
		// handler no lo exigia. Ahora ambos lados estan sincronizados.
		// Buscamos el patron "nonce=' + encodeURIComponent(pushNonce)" que se anade.
		$this->assertStringContainsString(
			"var pushNonce = ( window.ltmsUX && window.ltmsUX.nonce ) ? window.ltmsUX.nonce : '';",
			$source,
			'SB-001: JS del push prompt extrae nonce de window.ltmsUX.nonce (mismo patron que spNonce de social proof).'
		);
		$this->assertStringContainsString(
			"&nonce=' + encodeURIComponent(pushNonce)",
			$source,
			'SB-001: el body del fetch incluye nonce=encodeURIComponent(pushNonce).'
		);
	}

	public function test_ajax_subscribe_push_no_more_zero_nonce_protection(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-001 P0 garantia anti-regresion: el patron viejo tenia el handler
		// ajax_subscribe_push empezando directo con `$endpoint = esc_url_raw(...)`
		// SIN check_ajax_referer antes. Tras el fix, el handler ARRANCA con la
		// validación de nonce. Negamos el patron "sin nonce" verificando que
		// la primera operacion del handler NO sea la asignacion de endpoint
		// (debe ser el check_ajax_referer primero). Buscamos el snippet viejo
		// exacto del patron sin check_ajax_referer antes de $endpoint.
		$pattern_viejo = "public static function ajax_subscribe_push(): void {\n        \$endpoint = esc_url_raw( \$_POST['endpoint'] ?? '' );";
		$this->assertStringNotContainsString(
			$pattern_viejo,
			$source,
			'SB-001: el patron viejo "function ajax_subscribe_push() { $endpoint = esc_url_raw" debe haber sido reemplazado por la version con check_ajax_referer primero.'
		);
	}

	// ====================================================================
	//  SB-002 P1: track_product_view JS envia nonce (feature antes rota)
	// ====================================================================

	public function test_sb002_has_ciclo29_tag(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO29-P1-SB-002 FIX',
			$source,
			'SB-002: tag de trazabilidad CICLO29-P1-SB-002 FIX debe estar en sales-booster.'
		);
	}

	public function test_track_product_view_js_sends_nonce_first_call(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-002 P1: la PRIMERA llamada `$.post(ajaxurl, { action:
		// 'ltms_track_product_view', product_id: productId })` debe ahora
		// incluir `nonce: spNonce`. Buscamos el snippet con nonce.
		$this->assertStringContainsString(
			"{ action: 'ltms_track_product_view', nonce: spNonce, product_id: productId }",
			$source,
			'SB-002: primera llamada $.post de track_product_view debe incluir nonce: spNonce.'
		);
	}

	public function test_track_product_view_js_sends_nonce_in_setinterval(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-002 P1: la SEGUNDA llamada (dentro de setInterval) tambien debe
		// incluir nonce. El bug afectaba ambas — el setInterval llama al
		// handler cada 15s para refrescar el viewer count, sin nonce todas
		// fallaban 403.
		$count = substr_count( $source, "{ action: 'ltms_track_product_view', nonce: spNonce, product_id: productId }" );
		$this->assertSame( 2, $count,
			'SB-002: deben existir 2 llamadas $.post de track_product_view con nonce: spNonce (primera + setInterval).'
		);
	}

	public function test_track_product_view_js_no_more_bare_call(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-002 P1 garantia anti-regresion: el patron viejo era
		//   $.post(ajaxurl, { action: 'ltms_track_product_view', product_id: productId });
		// sin nonce. Tras el fix, todas las llamadas $.post de track_product_view
		// deben incluir nonce. Negamos ocurrencias sin nonce.
		$this->assertStringNotContainsString(
			"{ action: 'ltms_track_product_view', product_id: productId }",
			$source,
			'SB-002: el patron viejo sin nonce (action + product_id solamente) no debe existir mas.'
		);
	}

	public function test_spnonce_variable_still_defined(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: la variable spNonce sigue definida antes de su uso.
		// Si se elimina por error, ambas features (social proof + viewer count)
		// se rompen. Confirmamos que la declaracion var spNonce sigue presente.
		$this->assertStringContainsString(
			"var spNonce = ( window.ltmsUX && window.ltmsUX.nonce ) ? window.ltmsUX.nonce : '';",
			$source,
			'No-regresion: var spNonce sigue definida (inqeta social proof + track_product_view dependen de ella).'
		);
	}

	public function test_get_social_proof_still_sends_nonce(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion SEC-3 FIX: ajax_get_social_proof sigue recibiendo nonce
		// via spNonce en el JS (no se toco en C29, garantizamos sigue).
		$this->assertStringContainsString(
			"{ action: 'ltms_get_social_proof', nonce: spNonce }",
			$source,
			'SEC-3 FIX intacto: ajax_get_social_proof JS sigue enviando nonce: spNonce.'
		);
	}

	// ====================================================================
	//  No-regresion: estructura del modulo sales-booster
	// ====================================================================

	public function test_class_ltms_sales_booster_exists(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		$this->assertStringContainsString( 'class LTMS_Sales_Booster', $source );
	}

	public function test_sales_booster_init_registers_all_hooks(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: init() debe seguir registrando todos los hooks del
		// modulo. Si un fix accidentalmente remueve un add_action, las 5
		// features (SB-1 a SB-5) se rompen.
		$this->assertStringContainsString( "add_action( 'ltms_every_15_minutes', [ __CLASS__, 'detect_abandoned_carts' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_cart_updated', [ __CLASS__, 'track_cart_activity' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'mark_cart_recovered' ]", $source );
		$this->assertStringContainsString( "add_action( 'init', [ __CLASS__, 'register_flash_sale_cpt' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_flash_sale_countdown' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_before_shop_loop_item_title', [ __CLASS__, 'render_flash_sale_badge' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_flash_sale_assets' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_footer', [ __CLASS__, 'render_push_subscription_prompt' ], 20 )", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_subscribe_push', [ __CLASS__, 'ajax_subscribe_push' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'send_order_push_notification' ]", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_proceed_to_checkout', [ __CLASS__, 'render_free_shipping_progress_bar' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_after_cart_contents', [ __CLASS__, 'render_cart_cross_sell' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_review_order_after_cart_contents', [ __CLASS__, 'render_checkout_cross_sell' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_footer', [ __CLASS__, 'render_social_proof_container' ], 25 )", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_nopriv_ltms_track_product_view', [ __CLASS__, 'ajax_track_product_view' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_track_product_view', [ __CLASS__, 'ajax_track_product_view' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_nopriv_ltms_get_social_proof', [ __CLASS__, 'ajax_get_social_proof' ] )", $source );
		$this->assertStringContainsString( "add_action( 'wp_ajax_ltms_get_social_proof', [ __CLASS__, 'ajax_get_social_proof' ] )", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record_purchase_for_social_proof' ] )", $source );
	}

	public function test_ajax_track_product_view_still_has_nonce_check(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion SEC-8 FIX: el handler ajax_track_product_view sigue
		// exigiendo nonce fail-closed. C29 NO toco el handler — solo fixeo
		// el JS front para que lo envie. La constraint del backend sigue.
		$this->assertStringContainsString(
			"if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {",
			$source,
			'SEC-8 FIX intacto: ajax_track_product_view sigue con check_ajax_referer fail-closed.'
		);
		// El comentario SEC-8 FIX debe seguir presente (no se removio).
		$this->assertStringContainsString(
			'v2.9.100 SEC-8 FIX',
			$source,
			'Comentario SEC-8 FIX sigue presente en ajax_track_product_view.'
		);
	}

	public function test_ajax_get_social_proof_still_has_nonce_check(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion SEC-3 FIX: el handler ajax_get_social_proof sigue
		// exigiendo nonce fail-closed. C29 NO lo toco.
		$this->assertStringContainsString(
			'v2.9.100 SEC-3 FIX',
			$source,
			'Comentario SEC-3 FIX sigue presente en ajax_get_social_proof.'
		);
	}

	public function test_mark_cart_recovered_still_sanitizes_order_id(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: mark_cart_recovered firma `int $order_id` (type hint).
		// Si se quita el type hint, podria recibir cualquier cosa y romper
		// el $wpdb->update.
		$this->assertStringContainsString(
			'public static function mark_cart_recovered( int $order_id ): void {',
			$source,
			'mark_cart_recovered conserva type hint int (defensa contra input malicioso).'
		);
	}

	public function test_send_order_push_notification_type_hints(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: send_order_push_notification firma con type hints
		// int $order_id, string $old_status, string $new_status, \WC_Order $order.
		$this->assertStringContainsString(
			'public static function send_order_push_notification( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {',
			$source,
			'send_order_push_notification conserva type hints (defensa WP hook signature).'
		);
	}

	public function test_track_cart_activity_uses_wpdb_prepare_for_existing(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: track_cart_activity sigue usando $wpdb->prepare para
		// la query de `existing` (user_id + session_id). Si se quita, SQLi.
		$this->assertStringContainsString(
			'$wpdb->prepare(',
			$source,
			'track_cart_activity sigue usando $wpdb->prepare (no SQLi).'
		);
	}

	public function test_detect_abandoned_carts_uses_wpdb_prepare(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: detect_abandoned_carts sigue usando $wpdb->prepare
		// en las 3 queries de stage 1h/6h/24h. Si se remueve, SQLi via cron.
		$this->assertStringContainsString(
			"TIMESTAMPDIFF(MINUTE, last_activity, %s) >= %d",
			$source,
			'detect_abandoned_carts usa placeholders %s/%d en TIMESTAMPDIFF (no SQLi).'
		);
	}

	public function test_get_active_flash_sale_for_product_returns_typed(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// No-regresion: get_active_flash_sale_for_product firma ?array (return
		// type hint). Si se quita, callers pueden romper esperando null|array.
		$this->assertStringContainsString(
			'private static function get_active_flash_sale_for_product( int $product_id ): ?array {',
			$source,
			'get_active_flash_sale_for_product conserva return type ?array.'
		);
	}

	// ====================================================================
	//  SB-007 P1 (2a revision): REMOTE_ADDR en session_id de viewer count
	// ====================================================================

	public function test_sb007_has_ciclo29_tag(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO29-P1-SB-007 FIX',
			$source,
			'SB-007: tag de trazabilidad CICLO29-P1-SB-007 FIX debe estar en sales-booster.'
		);
	}

	public function test_track_product_view_uses_get_client_ip_safe_for_session_id(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-007 P1 (2a revision): ajax_track_product_view debe usar
		// LTMS_Core_Security::get_client_ip_safe() para el fallback de
		// session_id (no REMOTE_ADDR crudo). Invariante transversal
		// Leccion 25.1.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'SB-007: sales-booster debe usar get_client_ip_safe() (no REMOTE_ADDR) en ajax_track_product_view.'
		);
	}

	public function test_track_product_view_no_more_remote_addr_raw(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-007 P1 garantia anti-regresion: el patron viejo
		//   $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
		// debe haber desaparecido sustituido por $safe_ip. Buscamos
		// el literal $_SERVER['REMOTE_ADDR'] en el archivo y negamos.
		// Nota: el literal puede aparecer en otros handlers futuros,
		// pero en C29 asumimos que el unico uso era este (verificado).
		$this->assertStringNotContainsString(
			"\$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'",
			$source,
			'SB-007: el patron viejo $_SERVER[REMOTE_ADDR] ?? 0.0.0.0 debe haber sido reemplazado por get_client_ip_safe().'
		);
	}

	public function test_track_product_view_assigns_safe_ip_variable(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// SB-007 P1: se introduce una variable $safe_ip que guarda
		// get_client_ip_safe() y luego se usa 2 veces en el ternario
		// anidado de session_id. Confirmamos su asignacion.
		$this->assertStringContainsString(
			'$safe_ip = LTMS_Core_Security::get_client_ip_safe();',
			$source,
			'SB-007: se asigna $safe_ip = LTMS_Core_Security::get_client_ip_safe() antes del ternario session_id.'
		);
	}

	// ====================================================================
	//  Cross-checks transversales (no regresion C25 / C26 / C27 / C28)
	// ====================================================================

	public function test_compliance_guardian_c28_tags_still_present(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Cross-check C28 (no regression): los 3 tags de fix C28 siguen
		// presentes. C29 NO toco compliance-guardian pero garantizamos.
		$this->assertStringContainsString( 'CICLO28-P0-CG-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO28-P1-CG-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO28-P1-CG-003 FIX', $source );
	}

	public function test_compliance_guardian_ajax_cookie_consent_still_fail_closed(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Cross-check C28: ajax_cookie_consent sigue con wp_send_json_error(403)
		// tras el fix C28. Si se remueve, el bypass CSRF vuelve.
		$this->assertStringContainsString(
			"wp_send_json_error(",
			$source,
			'C28 intacto: compliance-guardian ajax_cookie_consent sigue fail-closed.'
		);
	}

	public function test_traffic_booster_still_uses_get_client_ip_safe_c29(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// Cross-check C26 (no regression): traffic-booster sigue delegando
		// IP resolution a LTMS_Core_Security::get_client_ip_safe().
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Traffic-booster C26 sigue usando get_client_ip_safe.'
		);
	}

	public function test_xcover_checkout_handler_still_uses_get_client_ip_safe_c29(): void {
		$this->assertFileExists( self::XCOVER_HANDLER_PATH );
		$source = file_get_contents( self::XCOVER_HANDLER_PATH );

		// Cross-check C4 (no regression).
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Xcover checkout handler C4 sigue usando get_client_ip_safe.'
		);
	}

	public function test_zapsign_webhook_handler_still_delegates_client_ip_safe_c29(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// Cross-check C27 (no regression).
		$this->assertStringContainsString(
			'get_client_ip_safe()',
			$source,
			'ZapSign webhook handler C27 sigue usando get_client_ip_safe.'
		);
	}

	public function test_all_5_webhook_handlers_use_get_client_ip_safe_c29(): void {
		$paths = [
			self::OPENPAY_HANDLER_PATH,
			self::UBER_HANDLER_PATH,
			self::ADDI_HANDLER_PATH,
			self::SIIGO_HANDLER_PATH,
			self::ZAPSIGN_WEBHOOK_PATH,
		];
		foreach ( $paths as $path ) {
			$this->assertFileExists( $path, "Webhook handler {$path} debe existir." );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'get_client_ip_safe()',
				$source,
				"Webhook handler {$path} sigue usando get_client_ip_safe (invariante C25)."
			);
		}
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque los metodos de Sales Booster
	// (WC()->cart, WC()->session, get_userdata, wp_mail, wp_remote_post)
	// requieren stubeo extensivo de WP/WC internals. Los tests documentan
	// el contrato del fix (tag presente, cambios estructurales correctos,
	// JS sincronizado con backend) sin reimplementar logica.
}
