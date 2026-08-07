<?php
/**
 * AuditCiclo27ZapsignManagerFixesTest - Tests para los fixes del Ciclo 27.
 *
 * Modulo: includes/business/class-ltms-zapsign-manager.php + webhook handler
 *
 * ZAPSIGN es modulo CRITICO en AGENTS.md "Revision como ultimo filtro"
 * (junto con wallet/comisiones/payouts/KYC/SAGRILAFT/Backblaze/gateways pago).
 * Estos fixes REQUIRIERON y PASARON segunda revision obligatoria del diff.
 *
 * 1. ZS-MGR-008 P1 (webhook handler class-ltms-zapsign-webhook-handler.php):
 *    El webhook de ZapSign, al recibir 'doc_signed', solo actualizaba
 *    `ltms_kyc_status='approved'` y metas privadas con underscore
 *    (`_ltms_zapsign_doc_token`, `_ltms_zapsign_signed_at`). Las metas
 *    PUBLICAS `ltms_contract_status` y `ltms_contract_signed_at` quedaban
 *    en 'pending' / vacio para siempre aunque el contracto estuviera firmado.
 *    Cualquier lector del estado publico del contracto (compliance-guardian,
 *    admin views, retencion cron) veia "pending" eternamente.
 *    Fix: el webhook ahora setea tambien:
 *      - ltms_contract_status     = 'signed'
 *      - ltms_contract_signed_at  = now UTC
 *      - ltms_contract_status_verified_at = now UTC  (inicializa el ZS-2
 *        rate-limit 24h para que el primer poll_pending_contracts no dispare
 *        otra llamada a ZapSign API el mismo dia del webhook).
 *
 * 2. ZS-MGR-007 P1 (get_contract_status en zapsign-manager.php):
 *    El match del estado remoto tenia `default => $cached_status ?: 'unknown'`
 *    — si ZapSign introducia un estado nuevo fuera del enum conocido (active,
 *    pending, refused, rejected, expired, cancelled, voided) como 'archived',
 *    'legal_hold' o 'pending_review', y el cache local era 'signed', el
 *    sistema seguia reportando 'signed' — bypass silencioso del ZS-2 FIX
 *    (que intentaba detectar contratos retractados post-firma).
 *    Fix: `default => 'unknown'` (fail-closed). Cualquier estado remoto no
 *    reconocido se persiste como 'unknown' y se marca con warning log para
 *    que el equipo lo triaje — el cache 'signed' NUNCA se mantiene sin un
 *    caso explicito del match.
 *
 * 3. ZS-MGR-008b P1 (poll_pending_contracts en zapsign-manager.php):
 *    El cron `ltms_zapsign_poll_pending` ya estaba programado por el
 *    activator (class-ltms-activator.php:613, cada hora) pero NUNCA tuvo
 *    handler registrado — el cron disparaba al vacio. get_contract_status()
 *    era codigo huérfano (sin callers). Fix: nuevo metodo
 *    `LTMS_ZapSign_Manager::poll_pending_contracts()` registrado como
 *    handler del cron. Itera vendedores con `ltms_contract_token` no-vacio
 *    y delega a get_contract_status() que re-verifica con ZapSign aplicando
 *    el rate-limit 24h interno + fail-closed default.
 *
 * Modulo CRITICO (ZAPSIGN/Backblaze/contract legal persistence): segunda
 * revision del diff obligatoria — confirmada por separado de estos tests.
 *
 * Patron C27: source-based tests (file_get_contents + assertStringContains/
 * NotContainsString), mismo que C20-C26.
 *
 * Adicionalmente, el test verifica:
 *  - Cobertura webhooks C25 sigue marcada (no regression C27).
 *  - El tag de trazabilidad CICLO27-P1-ZS-MGR-007/008 FIX presente.
 *  - El handler del cron poll_pending_contracts registrado en init().
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers ZS-MGR-007, ZS-MGR-008, ZS-MGR-008b
 */
class AuditCiclo27ZapsignManagerFixesTest extends LTMS_Unit_Test_Case {

	private const ZAPSIGN_MANAGER_PATH  = __DIR__ . '/../../includes/business/class-ltms-zapsign-manager.php';
	private const ZAPSIGN_WEBHOOK_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
	private const ZAPSIGN_API_PATH      = __DIR__ . '/../../includes/api/class-ltms-api-zapsign.php';
	private const OPENPAY_HANDLER_PATH  = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const SIIGO_HANDLER_PATH    = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';

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
	//  ZS-MGR-008 P1: webhook handler actualiza meta publica del contracto
	// ====================================================================

	public function test_zapsign_webhook_file_exists(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
	}

	public function test_zapsign_webhook_updates_public_contract_status_to_signed(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// ZS-MGR-008 P1: ademas de las metas privadas _ltms_zapsign_* con
		// guion bajo, el webhook DEBE actualizar la meta publica
		// `ltms_contract_status` con valor 'signed'. Antes solo movia
		// ltms_kyc_status='approved' — el estado publico del contracto
		// quedaba eternamente 'pending' aunque firmado.
		$this->assertStringContainsString(
			"'ltms_contract_status', 'signed'",
			$source,
			'ZS-MGR-008: webhook de ZapSign debe actualizar ltms_contract_status=signed cuando llega doc_signed (no solo ltms_kyc_status).'
		);
	}

	public function test_zapsign_webhook_updates_public_contract_signed_at(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// ZS-MGR-008 P1: `ltms_contract_signed_at` (sin guion bajo, publica)
		// debe setearse cuando llega el webhook. Antes solo `_ltms_zapsign_signed_at`
		// (privada) se actualizaba — cualquier lector externo leia vacio.
		$this->assertStringContainsString(
			"'ltms_contract_signed_at'",
			$source,
			'ZS-MGR-008: webhook debe actualizar ltms_contract_signed_at (meta publica sin underscore).'
		);
	}

	public function test_zapsign_webhook_initializes_status_verified_at(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// ZS-MGR-008 P1: el webhook inicializa `ltms_contract_status_verified_at`
		// con la fecha del webhook. Esto evita que el primer poll_pending_contracts
		// dispare otra llamada a ZapSign API el mismo dia — el rate-limit 24h
		// del ZS-2 FIX (lineas 498-511 de zapsign-manager) se respeta desde el
		// momento del webhook.
		$this->assertStringContainsString(
			"'ltms_contract_status_verified_at'",
			$source,
			'ZS-MGR-008: webhook debe inicializar ltms_contract_status_verified_at (rate-limit 24h de ZS-2 FIX).'
		);
	}

	public function test_zapsign_webhook_still_updates_private_metas(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// El fix NO debe romper el comportamiento existente de las metas
		// privadas con underscore. Son historicas/para uso interno de B2.
		$this->assertStringContainsString(
			"'_ltms_zapsign_doc_token'",
			$source,
			'ZS-MGR-008: webhook mantiene la meta privada _ltms_zapsign_doc_token (legacy).'
		);
		$this->assertStringContainsString(
			"'_ltms_zapsign_signed_at'",
			$source,
			'ZS-MGR-008: webhook mantiene la meta privada _ltms_zapsign_signed_at (legacy).'
		);
	}

	public function test_zapsign_webhook_has_ciclo27_tag_zs008(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		$this->assertStringContainsString(
			'CICLO27-P1-ZS-MGR-008 FIX',
			$source,
			'ZS-MGR-008: tag de trazabilidad CICLO27-P1-ZS-MGR-008 FIX debe estar en webhook handler.'
		);
	}

	// ====================================================================
	//  ZS-MGR-007 P1: get_contract_status default fail-closed a 'unknown'
	// ====================================================================

	public function test_zapsign_manager_file_exists(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
	}

	public function test_zapsign_manager_match_default_is_unknown_fail_closed(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-MGR-007 P1: el default del match NO debe ser `$cached_status`
		// (truthy cuando es 'signed' = bypass silencioso del ZS-2 FIX) ni
		// `$cached_status ?: 'unknown'` (sigue siendo 'signed' si cache es
		// signed). Debe ser literal 'unknown' (fail-closed).
		$this->assertStringContainsString(
			"default                     => 'unknown',",
			$source,
			"ZS-MGR-007: el default del match debe ser 'unknown' (fail-closed) — no mantener cache 'signed' ante estado remoto no reconocido."
		);
	}

	public function test_zapsign_manager_no_longer_uses_cached_status_as_default(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// Patron peligroso a prohibir: `default => $cached_status` o
		// `default => $cached_status ?: 'unknown'` — ambos son bypass del
		// ZS-2 FIX cuando cache='signed' y remote trae estado nuevo.
		$this->assertStringNotContainsString(
			"default                     => \$cached_status",
			$source,
			'ZS-MGR-007: el default del match no debe referenciar $cached_status — bypass del ZS-2 FIX si cache era signed.'
		);
	}

	public function test_zapsign_manager_has_ciclo27_tag_zs007(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		$this->assertStringContainsString(
			'CICLO27-P1-ZS-MGR-007 FIX',
			$source,
			'ZS-MGR-007: tag CICLO27-P1-ZS-MGR-007 FIX debe estar en zapsign-manager.'
		);
	}

	// ====================================================================
	//  ZS-MGR-008b P1: poll_pending_contracts handler + cron registration
	// ====================================================================

	public function test_zapsign_manager_init_registers_poll_pending_cron_handler(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// El init() debe registrar el handler `poll_pending_contracts` en el
		// cron `ltms_zapsign_poll_pending` ya programado por el activator
		// (class-ltms-activator.php:613). Antes el cron disparaba al vacio.
		$this->assertStringContainsString(
			"add_action( 'ltms_zapsign_poll_pending'",
			$source,
			'ZS-MGR-008b: init() debe registrar add_action en ltms_zapsign_poll_pending (cron huérfano antes).'
		);
		$this->assertStringContainsString(
			"'poll_pending_contracts'",
			$source,
			'ZS-MGR-008b: el handler registrado debe ser poll_pending_contracts.'
		);
	}

	public function test_zapsign_manager_has_poll_pending_contracts_method(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// El metodo poll_pending_contracts debe existir como public static
		// para que add_action de init() pueda invocarlo sin instancia.
		$this->assertStringContainsString(
			'public static function poll_pending_contracts(): void',
			$source,
			'ZS-MGR-008b: poll_pending_contracts() debe ser public static (invocado por add_action).'
		);
	}

	public function test_zapsign_manager_poll_pending_uses_get_contract_status(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-MGR-008b: el handler delega a get_contract_status($vendor_id)
		// para cada vendedor con token. El rate-limit 24h + fail-closed
		// default aplican dentro de get_contract_status.
		$this->assertStringContainsString(
			'self::get_contract_status( $vendor_id )',
			$source,
			'ZS-MGR-008b: poll_pending_contracts debe delegar a get_contract_status (no reimplementar verificacion).'
		);
	}

	public function test_zapsign_manager_poll_pending_iterates_users_with_contract_token(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-MGR-008b: la query DEBE filtrar por `ltms_contract_token != ''`
		// (usuarios sin contrato enviado no tienen caso de re-verificacion).
		$this->assertStringContainsString(
			"ltms_contract_token",
			$source,
			'ZS-MGR-008b: poll_pending_contracts debe filtrar usuarios por ltms_contract_token (usermeta query).'
		);
	}

	public function test_zapsign_manager_poll_pending_catches_exceptions_per_user(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-MGR-008b: el poll no debe morir si un vendor lanza excepcion —
		// catch por cada uno para no bloquear la iteracion de los demas.
		$this->assertStringContainsString(
			'catch ( \Throwable $e )',
			$source,
			'ZS-MGR-008b: poll_pending_contracts debe catch \Throwable por vendor (no abortar el foreach).'
		);
	}

	public function test_zapsign_manager_poll_pending_uses_absint_on_user_id(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// Defense-in-depth: el user_id de DB se pasa por absint() antes de
		// invocar get_contract_status. user_id de usermeta es integer pero
		// la validacion previene integer-overflow / cast raro.
		$this->assertStringContainsString(
			'absint( $row[\'user_id\'] )',
			$source,
			'ZS-MGR-008b: poll_pending_contracts debe pasar user_id por absint() antes de usarlo.'
		);
	}

	public function test_zapsign_manager_poll_pending_has_limit_clause(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-MGR-008b: LIMIT 200 previene memory/slow query si hay miles de
		// vendors con contrato. El rate-limit 24h del ZS-2 FINX dentro de
		// get_contract_status ya filtra los que no necesitan re-verify.
		$this->assertStringContainsString(
			'LIMIT 200',
			$source,
			'ZS-MGR-008b: query de poll_pending_contracts debe tener LIMIT (default 200) para no memory-bloat.'
		);
	}

	public function test_zapsign_manager_poll_pending_has_ciclo27_tag(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		$this->assertStringContainsString(
			'CICLO27-P1-ZS-MGR-008 FIX',
			$source,
			'ZS-MGR-008b: tag CICLO27-P1-ZS-MGR-008 FIX debe estar junto al cron handler.'
		);
	}

	// ====================================================================
	//  No-regression: el webhook handler sigue validando token / IP
	//  (defense-in-depth intacto — fix C27 solo anade metas, no quita validacion)
	// ====================================================================

	public function test_zapsign_webhook_still_validates_via_hash_equals(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// La validacion del token via hash_equals (timing-safe) no debe romperse.
		$this->assertStringContainsString(
			'hash_equals( $expected_token, $req_token )',
			$source,
			'ZS-MGR-008: webhook sigue usando hash_equals (timing-safe) para validar token.' );
	}

	public function test_zapsign_webhook_still_fail_closed_on_empty_token(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// FU1 FIX v2.9.1: si no hay token configurado, RECHAZAR el webhook
		// (fail-closed). Antes era fail-open y auto-aprobaba KYC cualquier vendor.
		$this->assertStringContainsString(
			"'Webhook endpoint not configured'",
			$source,
			'ZS-MGR-008: webhook sigue fail-closed cuando no hay token configurado (defensa FU1 intacta).'
		);
	}

	public function test_zapsign_webhook_still_has_idempotency_transient(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// Idempotency transient (ZapSign reintenta 3x) — no debe romperse.
		$this->assertStringContainsString(
			'ltms_wh_seen_zapsign_',
			$source,
			'ZS-MGR-008: webhook sigue usando idempotency transient (ZapSign reintenta 3x).'
		);
	}

	public function test_zapsign_webhook_still_delegates_client_ip(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// Leccion 25.1: el webhook delega client_ip a LTMS_Core_Security.
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'ZS-MGR-008: webhook sigue delegando client_ip a Core_Security (Leccion 25.1).'
		);
	}

	public function test_zapsign_webhook_still_calls_backup_signed_contract(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// BC-01: el backup del PDF firmado en Backblaze B2 sigue invocandose.
		$this->assertStringContainsString(
			'LTMS_ZapSign_Manager::backup_signed_contract( $vendor_id, $doc_token )',
			$source,
			'ZS-MGR-008: webhook sigue llamando a backup_signed_contract (BC-01 intacto).'
		);
	}

	public function test_zapsign_webhook_still_triggers_vendor_approved_action(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// El do_action('ltms_vendor_approved',$vendor_id) dispara la cadena
		// de aprobacion (payout scheduler, redi manager, etc). No debe romperse.
		$this->assertStringContainsString(
			"do_action( 'ltms_vendor_approved', \$vendor_id )",
			$source,
			'ZS-MGR-008: webhook sigue disparando ltms_vendor_approved (cadena aprobacion KYC intacta).'
		);
	}

	// ====================================================================
	//  Cross-check C26: traffic-booster sigue delegando IP
	//  (no regression de ciclos previos)
	// ====================================================================

	public function test_traffic_booster_still_delegates_client_ip_c27(): void {
		$tb_path = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
		$this->assertFileExists( $tb_path );
		$source = file_get_contents( $tb_path );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C26 cross-check: traffic-booster sigue delegando client_ip a Core_Security (no regression C27).'
		);
	}

	// ====================================================================
	//  Cross-check C25: los 5 webhook handlers siguen delegando IP
	//  (no regression de ciclos previos)
	// ====================================================================

	public function test_openpay_handler_still_delegates_client_ip_c27(): void {
		$this->assertFileExists( self::OPENPAY_HANDLER_PATH );
		$source = file_get_contents( self::OPENPAY_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check C27: Openpay handler sigue delegando client_ip a Core_Security.'
		);
	}

	public function test_uber_direct_handler_still_delegates_client_ip_c27(): void {
		$this->assertFileExists( self::UBER_HANDLER_PATH );
		$source = file_get_contents( self::UBER_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check C27: Uber-Direct handler sigue delegando client_ip a Core_Security.'
		);
	}

	public function test_addi_handler_still_delegates_client_ip_c27(): void {
		$this->assertFileExists( self::ADDI_HANDLER_PATH );
		$source = file_get_contents( self::ADDI_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check C27: Addi handler sigue delegando client_ip a Core_Security.'
		);
	}

	public function test_siigo_handler_still_delegates_client_ip_c27(): void {
		$this->assertFileExists( self::SIIGO_HANDLER_PATH );
		$source = file_get_contents( self::SIIGO_HANDLER_PATH );
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'C25 cross-check C27: Siigo handler sigue delegando client_ip a Core_Security.'
		);
	}

	// ====================================================================
	//  No-regression: ZS-1, ZS-2, BC-01, SEC-4 fixes previos intactos
	// ====================================================================

	public function test_zapsign_manager_still_has_zs1_sha256_integrity_check(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-1 FIX: compute SHA-256 del PDF firmado ANTES del upload a B2.
		$this->assertStringContainsString(
			"hash( 'sha256', \$pdf_binary )",
			$source,
			'ZS-1 FIX intacto: backup_signed_contract sigue computando SHA-256 del PDF antes del upload.'
		);
		$this->assertStringContainsString(
			'hash_equals( $stored_hash, $current_hash )',
			$source,
			'ZS-1 FIX intacto: verify_contract_integrity sigue usando hash_equals timing-safe.'
		);
	}

	public function test_zapsign_manager_still_has_zs2_reverify_24h_guard(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// ZS-2 FIX: rate-limit 24h (DAY_IN_SECONDS) en re-verificacion de
		// contractos signed.
		$this->assertStringContainsString(
			'DAY_IN_SECONDS',
			$source,
			'ZS-2 FIX intacto: get_contract_status sigue re-verificando signed como max una vez cada 24h.'
		);
	}

	public function test_zapsign_manager_still_has_bc01_backup_to_b2(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// BC-01: backup del PDF firmado en Backblaze B2.
		$this->assertStringContainsString(
			'LTMS_Api_Factory::get( \'backblaze\' )',
			$source,
			'BC-01 intacto: backup_signed_contract sigue usando LTMS_Api_Factory::get(backblaze).'
		);
	}

	public function test_zapsign_manager_still_has_sec4_auth_check(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// SEC-4 FIX: ajax_resend_contract valida is_user_logged_in al inicio.
		$this->assertStringContainsString(
			'is_user_logged_in()',
			$source,
			'SEC-4 FIX intacto: ajax_resend_contract sigue validando auth.'
		);
	}

	public function test_zapsign_webhook_still_has_integration_audit_idempotency(): void {
		$this->assertFileExists( self::ZAPSIGN_API_PATH );
		$source = file_get_contents( self::ZAPSIGN_API_PATH );

		// INTEGRATIONS-AUDIT P0: create_document sigue enviando Idempotency-Key.
		$this->assertStringContainsString(
			"'Idempotency-Key'",
			$source,
			'INTEGRATIONS-AUDIT P0 intacto: create_document sigue usando Idempotency-Key header.'
		);
	}

	public function test_zapsign_manager_still_has_excmsg_fix(): void {
		$this->assertFileExists( self::ZAPSIGN_MANAGER_PATH );
		$source = file_get_contents( self::ZAPSIGN_MANAGER_PATH );

		// EXCMSG-FIX (AUDIT-EXCMSG-BIZ-001): mensajes de excepcion pasan
		// por esc_html() para que wp_send_json_error no los devuelva raw.
		$this->assertStringContainsString(
			'esc_html( $e->getMessage() )',
			$source,
			'EXCMSG-FIX intacto: zapsign-manager sigue haciendo esc_html($e->getMessage()).'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque los metodos del webhook handler
	// (WP_REST_Request, get_json_params, get_header) y del manager
	// (LTMS_Api_Factory, get_user_meta, update_user_meta, $wpdb) requieren
	// stubeo extensivo de WP internals. Los tests documentan el contrato
	// del fix (que este presente el tag, que la meta publica se actualice)
	// sin reimplementar la logica de negocio.
}
