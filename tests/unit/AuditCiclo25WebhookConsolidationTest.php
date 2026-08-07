<?php
/**
 * AuditCiclo25WebhookConsolidationTest - Tests para los fixes del Ciclo 25.
 *
 * Modulo: includes/api/webhooks/ — 2 fixes P2 de consolidacion (IP resolution)
 *
 * 1. AD-GAP-003 P2 (class-ltms-siigo-webhook-handler.php:97-106): client_ip()
 *    tenia implementacion manual del X-Forwarded-For sin validar el proxy contra
 *    ltms_trusted_proxies → IP spoofing posible para bypassear rate limits.
 *    Fix: delegar a LTMS_Core_Security::get_client_ip_safe() (consistencia con
 *    Uber-Direct, Openpay, Addi, ZapSign).
 *
 * 2. AD-GAP-004 P2 (class-ltms-api-webhook-router.php:123): log_incoming()
 *    usaba $_SERVER['REMOTE_ADDR'] directo, que ofrece la IP del proxy (no del
 *    cliente real) cuando hay reverse proxy — inutil para forensic/audit tras un
 *    webhook malicioso. Fix: delegar a LTMS_Core_Security::get_client_ip_safe().
 *
 * NO son fixes de codigo financiero critico (no tocan wallet/comisiones/payouts
 * — solo IP resolution para rate limiting y logs). NO requieren segunda revision
 * AGENTS.md "Revision como ultimo filtro". Pero SI requieren test de verificacion
 * (regla "no negociable": todo fix viene con test).
 *
 * Patron C25: source-based tests (file_get_contents + assertStringContains/
 * NotContainsString), mismo que C20/C21/C22/C23/C24.
 *
 * Adicionalmente, el test verifica que TODOS los webhook handlers consolidan la
 * resolucion de IP via LTMS_Core_Security::get_client_ip_safe() (consistencia
 * transversal — el hallazgo C25 estaba justamente en que 2 de 8 handlers no
 * seguian el patron de los otros 6).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AD-GAP-003, AD-GAP-004
 */
class AuditCiclo25WebhookConsolidationTest extends LTMS_Unit_Test_Case {

	private const SIIGO_HANDLER_PATH    = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';
	private const ROUTER_PATH           = __DIR__ . '/../../includes/api/webhooks/class-ltms-api-webhook-router.php';
	private const OPENPAY_HANDLER_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const ZAPSIGN_HANDLER_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';

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
	//  AD-GAP-003 P2: Siigo client_ip() debe delegar a Core_Security
	// ====================================================================

	public function test_siigo_handler_file_exists(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
	}

	public function test_siigo_handler_client_ip_delegates_to_core_security(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );

		// AD-GAP-003: el handler debe llamar LTMS_Core_Security::get_client_ip_safe()
		// dentro de client_ip(), no implementar manualmente X-Forwarded-For.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'AD-GAP-003: Siigo client_ip() debe delegar a LTMS_Core_Security::get_client_ip_safe().'
		);
	}

	public function test_siigo_handler_has_ciclo25_tag_adgap003(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );

		$this->assertStringContainsString(
			'CICLO25-P2-AD-GAP-003 FIX',
			$source,
			'AD-GAP-003: tag de trazabilidad CICLO25-P2-AD-GAP-003 FIX debe estar en Siigo handler.'
		);
	}

	public function test_siigo_handler_no_longer_implements_manual_x_forwarded_for(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );

		// El patron viejo (manual X-Forwarded-For sin trusted proxy check):
		//   if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		//       $forwarded = array_filter( array_map( 'trim', explode( ',', ...
		// Ya NO debe estar en el handler.
		$this->assertStringNotContainsString(
			"! empty( \$_SERVER['HTTP_X_FORWARDED_FOR'] )",
			$source,
			'AD-GAP-003: Siigo handler NO debe implementar manualmente X-Forwarded-For — delegar a Core_Security.'
		);
	}

	public function test_siigo_handler_client_ip_has_class_exists_guard(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );

		// Defense-in-depth: si LTMS_Core_Security no esta cargado (edge case en
		// boot temprana), fallback a REMOTE_ADDR sanitizado.
		$this->assertStringContainsString(
			"class_exists( 'LTMS_Core_Security' )",
			$source,
			'AD-GAP-003: client_ip() debe tener guard class_exists antes de llamar Core_Security.'
		);
	}

	// ====================================================================
	//  AD-GAP-004 P2: API router log_incoming debe delegar IP a Core_Security
	// ====================================================================

	public function test_router_file_exists(): void {
		$this->assertFileExists( self::ROUTER_PATH );
	}

	public function test_router_log_incoming_delegates_ip_to_core_security(): void {
		$this->assertFileExists( self::ROUTER_PATH );
		$source = file_get_contents( self::ROUTER_PATH );

		// AD-GAP-004: log_incoming() debe usar LTMS_Core_Security::get_client_ip_safe()
		// para ip_address, no $_SERVER['REMOTE_ADDR'] directo.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'AD-GAP-004: router log_incoming() debe delegar IP a LTMS_Core_Security::get_client_ip_safe().'
		);
	}

	public function test_router_has_ciclo25_tag_adgap004(): void {
		$this->assertFileExists( self::ROUTER_PATH );
		$source = file_get_contents( self::ROUTER_PATH );

		$this->assertStringContainsString(
			'CICLO25-P2-AD-GAP-004 FIX',
			$source,
			'AD-GAP-004: tag de trazabilidad CICLO25-P2-AD-GAP-004 FIX debe estar en el router.'
		);
	}

	public function test_router_log_incoming_no_longer_uses_remote_addr_directly(): void {
		$this->assertFileExists( self::ROUTER_PATH );
		$source = file_get_contents( self::ROUTER_PATH );

		// El patron viejo: 'ip_address' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' )
		// en el insert. Este string ya NO debe aparecer en log_incoming.
		// Aceptamos que REMOTE_ADDR aparezca en el fallback del ternario (line inline),
		// pero NO como argumento directo del insert.
		$this->assertStringNotContainsString(
			"'ip_address' => sanitize_text_field( \$_SERVER['REMOTE_ADDR']",
			$source,
			'AD-GAP-004: router log_incoming NO debe usar $_SERVER[\'REMOTE_ADDR\'] directo en ip_address del insert.'
		);
	}

	public function test_router_log_incoming_has_class_exists_guard_for_ip(): void {
		$this->assertFileExists( self::ROUTER_PATH );
		$source = file_get_contents( self::ROUTER_PATH );

		// Defense-in-depth: si LTMS_Core_Security no esta cargado, fallback.
		$this->assertStringContainsString(
			"class_exists( 'LTMS_Core_Security' )",
			$source,
			'AD-GAP-004: router debe tener guard class_exists para fallback de IP.'
		);
	}

	// ====================================================================
	//  Guard transversal: TODOS los webhook handlers consolidan via Core_Security
	//  (6 handlers: Stripe NO usa client_ip — delega signature a SDK.
	//   Openpay, Addi, Uber-Direct, ZapSign, Siigo SI usan client_ip. + router
	//   para log_incoming).
	// ====================================================================

	public function test_all_client_ip_handlers_delegate_to_core_security(): void {
		// Handlers que tienen metodo client_ip() y deben delegar a Core_Security.
		$handlers_with_client_ip = [
			self::OPENPAY_HANDLER_PATH,
			self::UBER_HANDLER_PATH,
			self::ADDI_HANDLER_PATH,
			self::ZAPSIGN_HANDLER_PATH,
			self::SIIGO_HANDLER_PATH,
		];

		foreach ( $handlers_with_client_ip as $path ) {
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'LTMS_Core_Security::get_client_ip_safe()',
				$source,
				basename( $path ) . ': client_ip() debe delegar a LTMS_Core_Security::get_client_ip_safe() (consistencia transversal C25).'
			);
		}
	}

	public function test_router_also_delegates_to_core_security_for_logs(): void {
		// El router (que no tiene client_ip() pero si log_incoming con ip_address)
		// tambien debe delegar.
		$this->assertFileExists( self::ROUTER_PATH );
		$source = file_get_contents( self::ROUTER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Router log_incoming() debe delegar IP a Core_Security (consistencia transversal C25).'
		);
	}

	// ====================================================================
	//  Cross-check: los handlers ya auditados (C24) NO fueron tocados en C25
	//  y siguen delegando correctamente (no regresion).
	// ====================================================================

	public function test_openpay_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C24 cross-check: Openpay handler sigue delegando client_ip a Core_Security (no regresion C25).'
		);
	}

	public function test_uber_direct_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::UBER_HANDLER_PATH );
		$source = file_get_contents( self::UBER_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Uber-Direct handler delega client_ip a Core_Security (pre-existente, no tocado en C25).'
		);
	}

	public function test_addi_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C24 cross-check: Addi handler delega client_ip a Core_Security.'
		);
	}

	public function test_zapsign_handler_still_delegates_client_ip(): void {
		$this->assertFileExists( self::ZAPSIGN_HANDLER_PATH );
		$source = file_get_contents( self::ZAPSIGN_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'ZapSign handler delega client_ip a Core_Security (pre-existente, no tocado en C25).'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los handlers usan WP_REST_Request y classes WC/SQL — Brain\Monkey no
	// stubea sin configuracion extensiva. Tests source-based son deterministicos
	// y documentan el contrato del fix sin dependencias externas. Para verificar
	// runtime behavior, usar tests/integration/ con LTMS_Integration_Test_Case +
	// WC test suite (no disponible en UNIT_ONLY).
}
