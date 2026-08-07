<?php
/**
 * AuditCiclo26TrafficBoosterFixesTest - Tests para los fixes del Ciclo 26.
 *
 * Modulo: includes/business/class-ltms-traffic-booster.php — 3 fixes
 * (1 P0 + 2 P1) encontrados al auditar el modulo business/ en C26.
 *
 * 1. TB-001 P0 (maybe_send_weekly_newsletter linea ~573 previa):
 *    El cron usaba `new \WP_Expression( 'emails_sent + 1' )` como valor
 *    del campo a incrementar en $wpdb->update(). WP_Expression NO existe
 *    en WordPress core ni en namespaces del plugin — el cron lanzaba
 *    `Uncaught Error: Class 'WP_Expression' not found` en cuanto entraba
 *    al update, abortaba el foreach Y la llamada posterior
 *    update_option('ltms_newsletter_last_sent'). El cron se repetia
 *    diariamente reenviando spam a los primeros suscriptores.
 *    Fix: $wpdb->prepare( "UPDATE ... SET emails_sent = emails_sent + 1
 *    WHERE email = %s", $email ) + verificacion de retorno para forensic.
 *
 * 2. TB-002 P1 (ajax_subscribe_newsletter linea ~480 previa):
 *    Usaba `$_SERVER['REMOTE_ADDR']` directo como clave del rate limit
 *    transient. Anti-patron Leccion 25.1 (transversalidad del helper
 *    LTMS_Core_Security::get_client_ip_safe()): detras de reverse proxy
 *    (SiteGround) todas las requests comparten la IP del proxy → rate
 *    limit efectivo global (3/15min para TODO el trafico en lugar de
 *    por-IP). Spooﬁng de X-Forwarded-By sin trusted_proxies validation.
 *    Fix: delegar a LTMS_Core_Security::get_client_ip_safe() con
 *    class_exists guard (patron C25 — mismo fix ya aplicado a Siigo
 *    handler + API router).
 *
 * 3. TB-004 P1 (build_newsletter_html linea ~681 previa):
 *    El link de unsubscribe del newsletter era `home_url( '/unsubscribe/
 *    ?email=' )` (email VACIO). El suscriptor no podia desuscribirse con
 *    un click — tenia que ingresar el email manualmente. Violacion ley
 *    Ley 1581/2012 (Colombia) Art. 10 (derecho de revocacion del
 *    consentimiento) y GDPR Art. 7(3) (one-click unsubscribe).
 *    Fix: build_newsletter_html ahora renderiza el link con un
 *    placeholder `__TB_NEWSLETTER_UNSUB_EMAIL__`, y el foreach en
 *    maybe_send_weekly_newsletter reemplaza por `rawurlencode( $email )`
 *    del suscriptor actual. esc_url() preserva el placeholder (solo
 *    underscores y mayusculas — pasan sin escapar).
 *
 * NO son fixes de codigo financiero critico (no tocan
 * wallet/comisiones/payouts/KYC/ZapSign/Backblaze/gateways de pago).
 * NO requieren segunda revision AGENTS.md "Revision como ultimo filtro".
 * Pero SI requieren test de verificacion (regla no negociable: todo fix
 * viene con test).
 *
 * Patron C26: source-based tests (file_get_contents +
 * assertStringContains/NotContainsString), mismo que C20/C21/C22/C23/
 * C24/C25.
 *
 * Adicionalmente, el test verifica:
 *  - El modulo traffic-booster ahora ookk delega IP a Core_Security
 *    (extiende el guard transversal C25 de webhooks a business/).
 *  - Los 5 webhook handlers cubiertos por C25 siguen delegando
 *    correctamente (cross-check: no regression por refactor C26).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers TB-001, TB-002, TB-004
 */
class AuditCiclo26TrafficBoosterFixesTest extends LTMS_Unit_Test_Case {

	private const TRAFFIC_BOOSTER_PATH   = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const OPENPAY_HANDLER_PATH   = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH      = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH      = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const ZAPSIGN_HANDLER_PATH   = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
	private const SIIGO_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';

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
	//  TB-001 P0: WP_Expression debe ser eliminado del cron de newsletter
	// ====================================================================

	public function test_traffic_booster_file_exists(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
	}

	public function test_traffic_booster_no_longer_uses_wp_expression_class(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// TB-001 P0: WP_Expression NO existe en WordPress core. Su uso lanza
		// `Uncaught Error: Class 'WP_Expression' not found` en runtime.
		// Aceptamos que el tag de DOCUMENTACION del fix referencia WP_Expression
		// (porque el docstring del fix lo menciona como justificacion), pero
		// NUNCA como invocacion de clase.
		// Patron peligroso a prohibir: `new \WP_Expression` con parentesis.
		$this->assertStringNotContainsString(
			'new \WP_Expression(',
			$source,
			'TB-001: traffic-booster NO debe instanciar WP_Expression — clase inexistente en WP core, aborta el cron con fatal error.'
		);
		$this->assertStringNotContainsString(
			'new WP_Expression(',
			$source,
			'TB-001: tampoco sin el backslash de namespace root.'
		);
	}

	public function test_traffic_booster_has_atomic_emails_sent_increment(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// El fix utiliza SQL atomic `emails_sent = emails_sent + 1` con
		// $wpdb->prepare() para el incremento del counter. Esto evita race
		// conditions y reemplaza la falte WP_Expression.
		$this->assertStringContainsString(
			'emails_sent = emails_sent + 1',
			$source,
			'TB-001: el incremento del counter debe hacerse con SQL atomic emails_sent = emails_sent + 1.'
		);
	}

	public function test_traffic_booster_verify_update_return_for_forensic(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// Verificacion de retorno del UPDATE para forensic logging — si la
		// query falla, $failed_updates++ y se reporta en el log info. Antes
		// no se verificaba nada (porque el fatal error terminaba el foreach).
		$this->assertStringContainsString(
			'false === $updated',
			$source,
			'TB-001: el retorno del UPDATE debe verificarse (false = error DB) para forensic logging.'
		);
		$this->assertStringContainsString(
			'$failed_updates',
			$source,
			'TB-001: debe existir un counter $failed_updates para reportar fallos del UPDATE en el log.'
		);
	}

	public function test_traffic_booster_has_ciclo26_tag_tb001(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO26-P0-TB-001 FIX',
			$source,
			'TB-001: tag de trazabilidad CICLO26-P0-TB-001 FIX debe estar en traffic-booster.'
		);
	}

	public function test_traffic_booster_uses_wpdb_prepare_for_email_in_update(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// El WHERE email = %s DEBE pasar por $wpdb->prepare para evitar
		// SQL injection via el campo email (los subscribers vienen de la DB
		// pero el persistence layer pudo aceptar valores no sanitizados en
		// el AJAX subscribe — defense in depth).
		$this->assertStringContainsString(
			'$wpdb->prepare(',
			$source,
			'TB-001: el UPDATE del counter debe usar $wpdb->prepare() para la condicion WHERE email = %s.'
		);
	}

	// ====================================================================
	//  TB-002 P1: ajax_subscribe_newsletter debe delegar IP a Core_Security
	// ====================================================================

	public function test_traffic_booster_subscribe_newsletter_delegates_ip_to_core_security(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// TB-002 P1: el rate limit de newsletter DEBE usar la IP resuelta
		// por LTMS_Core_Security::get_client_ip_safe() para respetar
		// ltms_trusted_proxies y no tragar el X-Forwarded-For a ciegas.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'TB-002: ajax_subscribe_newsletter debe delegar IP a LTMS_Core_Security::get_client_ip_safe() (consistencia transversal C25+C26 — Leccion 25.1).'
		);
	}

	public function test_traffic_booster_has_class_exists_guard_for_ip(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// Defense-in-depth: si LTMS_Core_Security no esta cargado (edge
		// case en boot temprana), fallback a REMOTE_ADDR sanitizado.
		$this->assertStringContainsString(
			"class_exists( 'LTMS_Core_Security' )",
			$source,
			'TB-002: el handler debe tener guard class_exists antes de llamar Core_Security (patron C25).'
		);
	}

	public function test_traffic_booster_has_ciclo26_tag_tb002(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO26-P1-TB-002 FIX',
			$source,
			'TB-002: tag de trazabilidad CICLO26-P1-TB-002 FIX debe estar en traffic-booster.'
		);
	}

	public function test_traffic_booster_no_longer_uses_remote_addr_as_primary_ip_source(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// El patron viejo de TB-002 era:
		//   $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
		// justo antes de $key = 'ltms_newsletter_rl_' . md5( $ip );
		// Verificamos que el rate limit ya NO dependa primariamente de
		// REMOTE_ADDR. Necesitamos verificar que la asignacion a $ip no
		// sea el ternario directo, sino que pase por Core_Security. Como
		// el fallback defensivo todavía mantiene REMOTE_ADDR, validamos el
		// patron especifico del bug: `md5( $ip )` debe estar precedido por
		// el bloque if (class_exists + get_client_ip_safe.
		$ip_block_pos = strpos( $source, "'ltms_newsletter_rl_' . md5( \$ip )" );
		$this->assertNotFalse( $ip_block_pos, 'TB-002: el rate limit debe seguir usando md5($ip) como key del transient.' );

		$block_before = substr( $source, max( 0, $ip_block_pos - 600 ), 600 );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$block_before,
			'TB-002: la asignacion a $ip antes de md5($ip) debe delegar a Core_Security, no usar REMOTE_ADDR directo.'
		);
	}

	// ====================================================================
	//  TB-004 P1: link unsubscribe debe incluir el email del suscriptor
	// ====================================================================

	public function test_traffic_booster_build_newsletter_html_uses_unsubscribe_placeholder(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// TB-004 P1: build_newsletter_html debe renderizar el link
		// unsubscribe con un placeholder `__TB_NEWSLETTER_UNSUB_EMAIL__`
		// que sera reemplazado por subscriber en el foreach.
		$this->assertStringContainsString(
			'__TB_NEWSLETTER_UNSUB_EMAIL__',
			$source,
			'TB-004: build_newsletter_html debe usar el placeholder __TB_NEWSLETTER_UNSUB_EMAIL__ en el link unsubscribe.'
		);
	}

	public function test_traffic_booster_foreach_replaces_placeholder_per_subscriber(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// El foreach en maybe_send_weekly_newsletter debe reemplazar el
		// placeholder por rawurlencode( $email ) del suscriptor actual.
		// Verificamos los componentes clave del bloque str_replace sin
		// asumir indentacion exacta (espacios vs tabs puede variar tras
		// formatter).
		$this->assertStringContainsString(
			"'__TB_NEWSLETTER_UNSUB_EMAIL__'",
			$source,
			'TB-004: el foreach debe invocar str_replace con el placeholder __TB_NEWSLETTER_UNSUB_EMAIL__.'
		);
		$this->assertStringContainsString(
			'rawurlencode( $email )',
			$source,
			'TB-004: el reemplazo del placeholder debe usar rawurlencode( $email ) para URL-safety del email.'
		);
		$this->assertStringContainsString(
			'$subscriber_html = str_replace(',
			$source,
			'TB-004: el foreach debe asignar el resultado a $subscriber_html (no mutar $html del loop).'
		);
	}

	public function test_traffic_booster_no_longer_emits_empty_email_in_unsubscribe_link(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// El patron viejo de TB-004 era: `home_url( '/unsubscribe/?email=' )`
		// (email vacio en el link). Verificamos que ya no este.
		$this->assertStringNotContainsString(
			"home_url( '/unsubscribe/?email=' )",
			$source,
			'TB-004: el link unsubscribe NO debe emitirse con email vacio — viola Ley 1581 Art. 10 + GDPR Art. 7(3).'
		);
	}

	public function test_traffic_booster_has_ciclo26_tag_tb004(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		$this->assertStringContainsString(
			'CICLO26-P1-TB-004 FIX',
			$source,
			'TB-004: tag de trazabilidad CICLO26-P1-TB-004 FIX debe estar en traffic-booster.'
		);
	}

	// ====================================================================
	//  Guard transversal C26: modulo business/ tambien delega IP a
	//  Core_Security (extiende C25 — ahora cubre webhook + business)
	// ====================================================================

	public function test_traffic_booster_ip_resolution_consistent_with_webhook_handlers(): void {
		$this->assertFileExists( self::TRAFFIC_BOOSTER_PATH );
		$tb_source = file_get_contents( self::TRAFFIC_BOOSTER_PATH );

		// traffic-booster DEBE usar el mismo helper que usan los webhook
		// handlers (consistencia transversal C25 extendida en C26 a
		// business/). Si en el futuro algun handler o business-class
		// introduce un caller manual de X-Forwarded-For, el guard falla.
		$handlers_to_check = [
			'traffic-booster'                => $tb_source,
			'Siigo webhook handler'          => file_get_contents( self::SIIGO_HANDLER_PATH ),
			'API webhook router'              => file_get_contents( __DIR__ . '/../../includes/api/webhooks/class-ltms-api-webhook-router.php' ),
		];

		foreach ( $handlers_to_check as $label => $src ) {
			$this->assertStringContainsString(
				'LTMS_Core_Security::get_client_ip_safe()',
				$src,
				$label . ': debe delegar IP a LTMS_Core_Security::get_client_ip_safe() (guard transversal C25+C26).'
			);
		}
	}

	// ====================================================================
	//  Cross-check: los 5 webhook handlers C25 siguen delegando IP
	//  (no regression por C26 — traffic-booster refactor no toco webhooks)
	// ====================================================================

	public function test_openpay_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check: Openpay handler sigue delegando client_ip a Core_Security (no regression C26).'
		);
	}

	public function test_uber_direct_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::UBER_HANDLER_PATH );
		$source = file_get_contents( self::UBER_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check: Uber-Direct handler sigue delegando client_ip a Core_Security (no regression C26).'
		);
	}

	public function test_addi_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check: Addi handler sigue delegando client_ip a Core_Security (no regression C26).'
		);
	}

	public function test_zapsign_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::ZAPSIGN_HANDLER_PATH );
		$source = file_get_contents( self::ZAPSIGN_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check: ZapSign handler sigue delegando client_ip a Core_Security (no regression C26).'
		);
	}

	public function test_siigo_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check: Siigo handler sigue delegando client_ip a Core_Security (no regression C26).'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// maybe_send_weekly_newsletter y ajax_subscribe_newsletter usan wp_mail,
	// wc_get_products, $wpdb, get_option, set_transient — todos external
	// deps que Brain\Monkey no stubea sin configuracion extensiva. Tests
	// source-based son deterministicos y documentan el contrato del fix.
	// Para verificar runtime behavior, usar tests/integration/ con
	// LTMS_Integration_Test_Case + WC test suite (no disponible en
	// UNIT_ONLY).
}
