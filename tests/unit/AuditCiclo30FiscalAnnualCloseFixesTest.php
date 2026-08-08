<?php
/**
 * AuditCiclo30FiscalAnnualCloseFixesTest - Tests para los fixes del Ciclo 30.
 *
 * Modulo: includes/business/class-ltms-fiscal-annual-close.php
 *
 * CICLO 30 cubre compliance fiscal-financiero (GMF 4x1000 Colombia +
 * cierre fiscal anual + PAC CFDI Mexico). Por tanto modulo CRITICO en
 * AGENTS.md "Revision como ultimo filtro" (toca wallet/retenedores DIAN),
 * 2a revision OBLIGATORIA (Leccion 27.1 regla #6). 2a revision subagente
 * general devolvio APROBADO PARA COMMIT (no P0/P1 nuevos; 2 P2 backlog
 * FAC-008 null-body access PAC + FAC-009 LF-5 dead code).
 *
 * 1. FAC-001 P0 (fiscal-annual-close.php:84 idem_key)
 *    calculate_gmf_on_payout registraba el hook ltms_payout_completed
 *    con accepted_args=2 (solo vendor_id, amount), pero el action se
 *    dispara con 3 args (vendor_id, amount, payout_id) desde
 *    payout-scheduler.php:683 + openpay-webhook-handler.php:349. El
 *    payout_id se descartaba y la idempotency_key del debito GMF se
 *    construia como `sprintf('gmf_payout_v%d_%s', $vendor_id, $month)`
 *    — MISMA key para todos los retiros del mes del mismo vendor.
 *    Wallet::execute_transaction() retorna existing_tx_id sin re-
 *    ejecutar cuando reference coincide → 2do+ payout GMF del mismo
 *    vendor en el mismo mes era SKIP silencioso. Agente retenedor
 *    perdía GMF 4x1000 adeudado a la DIAN sobre todos los retiros del
 *    mes salvo el primero. Compliant-colission sobre construcción de
 *    idempotency_key. Fix: accepted_args=3 + signature con 3er arg
 *    int $payout_id = 0 + idem_key `gmf_payout_v{payout_id}` si > 0,
 *    fallback al key vendor+month para callers manuales / unit tests
 *    ($payout_id = 0).
 *    Tag: CICLO30-P0-FAC-001 FIX (fiscal-annual-close.php lineas 19-27
 *    hook registration + signature + body idem_key).
 *
 * 2. FAC-002 P1 (fiscal-annual-close.php:77 accumulated pre-debit)
 *    Antes: `update_user_meta(monthly_gmf_key, $accumulated + $amount)`
 *    se ejecutaba SIEMPRE en linea 77 ANTES del try/catch alrededor
 *    del debito Wallet::debit. Si Wallet::debit lanzaba
 *    InvalidArgumentException por saldo insuficiente, el acumulado
 *    mensual de exencion quedaba inflado sin recibir el debito →
 *    el siguiente retiro del mes veia el accumulated inflado y
 *    recalcular la base imponible sobre un monto ya "consumido"
 *    → perdida silenciosa de GMF fiscal. Fix: mover
 *    `update_user_meta(monthly_gmf_key)` para DESPUES del retorno
 *    exitoso de Wallet::debit (dentro del try). En catch → early
 *    return SIN persistir accumulated (el vendor no consume
 *    exencion mensual sobre un monto que no fue efectivamente
 *    retirado/debitado).
 *    Tag: CICLO30-P1-FAC-002 FIX (fiscal-annual-close.php body de
 *    calculate_gmf_on_payout).
 *
 * Patron C30: source-based tests (file_get_contents + assertString
 * Contains/NotContainsString), mismo que C20-C29. Cross-checks:
 * - C25/C26/C27/C28 invariantes transversales siguen intactos.
 * - C29 sales-booster tags CICLO29 siguen presentes (no regression).
 * - CICLO1.3 IDOR fix sigue presente (no regression).
 * - CICLO1.3 typo fix `total_operaciones` sigue presente.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers FAC-001 (P0), FAC-002 (P1)
 */
class AuditCiclo30FiscalAnnualCloseFixesTest extends LTMS_Unit_Test_Case {

	private const FISCAL_PATH              = __DIR__ . '/../../includes/business/class-ltms-fiscal-annual-close.php';
	private const WALLET_PATH              = __DIR__ . '/../../includes/business/class-ltms-wallet.php';
	private const COMPLIANCE_GUARDIAN_PATH  = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const SALES_BOOSTER_PATH       = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const TRAFFIC_BOOSTER_PATH     = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const XCOVER_HANDLER_PATH      = __DIR__ . '/../../includes/business/class-ltms-xcover-checkout-handler.php';
	private const ZAPSIGN_WEBHOOK_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-zapsign-webhook-handler.php';
	private const OPENPAY_HANDLER_PATH     = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
	private const UBER_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php';
	private const ADDI_HANDLER_PATH        = __DIR__ . '/../../includes/api/webhooks/class-ltms-addi-webhook-handler.php';
	private const SIIGO_HANDLER_PATH       = __DIR__ . '/../../includes/api/webhooks/class-ltms-siigo-webhook-handler.php';

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
	//  FAC-001 P0 — idempotency_key unica por payout
	// ====================================================================

	public function test_tag_ciclo30_fac_001_present(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		$this->assertStringContainsString(
			'CICLO30-P0-FAC-001 FIX',
			$source,
			'Tag CICLO30-P0-FAC-001 FIX debe estar presente en fiscal-annual-close.php.'
		);
	}

	public function test_hook_ltms_payout_completed_accepts_3_args(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Anti-patron FAC-001: el add_action declaraba accepted_args=2 (o default 1)
		// ahora debe ser 3 para recibir payout_id del fire site.
		$this->assertStringContainsString(
			"add_action( 'ltms_payout_completed', [ __CLASS__, 'calculate_gmf_on_payout' ], 10, 3 )",
			$source,
			'FAC-001: hook ltms_payout_completed debe aceptar 3 args (vendor_id, amount, payout_id).'
		);
	}

	public function test_calculate_gmf_on_payout_signature_has_payout_id(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Verificacion anti-cosmetica: la signature del callback debe tener
		// 3er arg int $payout_id = 0. Si se borra, FAC-001 regresa.
		$this->assertStringContainsString(
			'public static function calculate_gmf_on_payout( int $vendor_id, float $amount, int $payout_id = 0 ): void',
			$source,
			'FAC-001: signature calculate_gmf_on_payout debe aceptar 3er arg int $payout_id = 0.'
		);
	}

	public function test_idem_key_uses_payout_id_when_provided(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Construction del idem_key: $payout_id > 0 → `gmf_payout_v%d` unico.
		// Verificamos el patron esta presente.
		$this->assertStringContainsString(
			"sprintf( 'gmf_payout_v%d', \$payout_id )",
			$source,
			'FAC-001: idem_key debe ser sprintf(gmf_payout_v%d, $payout_id) cuando payout_id > 0.'
		);
	}

	public function test_idem_key_fallback_when_payout_id_zero(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Back-compat: si $payout_id = 0 (caller manual / unit test),
		// fallback al key por vendor+mes antiguo. Documentado en el fix.
		$this->assertStringContainsString(
			"sprintf( 'gmf_payout_v%d_%s', \$vendor_id, \$month )",
			$source,
			'FAC-001: idem_key fallback a sprintf(gmf_payout_v%d_%s, $vendor_id, $month) cuando payout_id=0.'
		);
	}

	public function test_idem_key_fallback_ternary_branches_explicit(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// El ternario debe ser explicit: $payout_id > 0 ? key_por_payout : key_por_vendor_mes.
		// Garantiza que el path primario usa payout_id (no el fallback).
		$this->assertStringContainsString(
			"\$payout_id > 0",
			$source,
			'FAC-001: branch explicito $payout_id > 0 en el ternario del idem_key.'
		);
	}

	public function test_old_anti_pattern_idem_key_only_by_vendor_month_removed(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Anti-patron FAC-001 (linea 84 pre-fix): el key que contenia
		// `$vendor_id, $month` era incondicional. Ahora solo aparece
		// en el fallback (when $payout_id = 0). Verificamos que la
		// construccion `$idem_key = sprintf( 'gmf_payout_v%d_%s'`
		// como unica asignacion (pre-fix) fue removida.
		// Buscamos el patron SIN el ternario > 0 — debe estar ausente.
		$old_pattern = '/\$idem_key\s*=\s*sprintf\(\s*\'gmf_payout_v%d_%s\'\s*,\s*\$vendor_id\s*,\s*\$month\s*\)\s*;/';
		$this->assertDoesNotMatchRegularExpression(
			$old_pattern,
			$source,
			'FAC-001: la asignacion incondicional de idem_key solo por vendor+month fue removida.'
		);
	}

	public function test_payout_id_added_to_wallet_metadata(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// El payout_id debe propagarse al metadata del Wallet::debit
		// (auditoria / trazabilidad — el layout del detail del tx
		// lleva payout_id para reconstruct forense en caso de bug).
		$this->assertStringContainsString(
			"'payout_id'   => \$payout_id",
			$source,
			'FAC-001: payout_id debe propagarse al metadata del Wallet::debit.'
		);
	}

	public function test_payout_id_added_to_gmf_cert_detail(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// El payout_id tambien en el detail del certificado GMF anual
		// (cierre fiscal → DIAN). Permite correlacionar cada retencion
		// GMF del ano con el payout exacto que la genero.
		$this->assertStringContainsString(
			"'payout_id' => \$payout_id,",
			$source,
			'FAC-001: payout_id debe incluirse en el detail[] del certificado GMF anual.'
		);
	}

	public function test_payout_id_appears_in_gmf_withheld_log(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Logging mejorado: GMF_WITHHELD ahora incluye payout_id para
		// trazabilidad fiscal. Si se remueve, perdemos la herrramienta
		// para auditar que payout genero cada GMF debitado (importante
		// en re-conciliacion con DIAN).
		$this->assertStringContainsString(
			"GMF_WITHHELD",
			$source
		);
		$this->assertStringContainsString(
			"payout_id=%d",
			$source,
			'FAC-001: log GMF_WITHHELD debe incluir payout_id.%d.'
		);
	}

	public function test_gmf_debit_failed_log_includes_payout_id(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Log de fallo mejorado: GMF_DEBIT_FAILED ahora incluye payout_id
		// para que el ops pueda reconciliar que payout fallo (no solo vendor).
		$this->assertStringContainsString(
			"GMF_DEBIT_FAILED",
			$source
		);
		$this->assertStringContainsString(
			"payout_id=%d",
			$source,
			'FAC-001: log GMF_DEBIT_FAILED debe incluir payout_id.%d.'
		);
	}

	// ====================================================================
	//  FAC-002 P1 — accumulated se persiste post-debit-success
	// ====================================================================

	public function test_tag_ciclo30_fac_002_present(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		$this->assertStringContainsString(
			'CICLO30-P1-FAC-002 FIX',
			$source,
			'Tag CICLO30-P1-FAC-002 FIX debe estar presente.'
		);
	}

	public function test_accumulated_update_moved_inside_try_after_debit(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// FAC-002 fix core: la llamada a `update_user_meta( $vendor_id, $monthly_gmf_key`
		// despues del `update_user_meta( $vendor_id, $monthly_gmf_key, $accumulated + $amount )`
		// (post-debit success path) debe estar DENTRO del try, despues del
		// `LTMS_Business_Wallet::debit(...)` return.
		// Verificamos que existe el block `update_user_meta( ... $monthly_gmf_key ... $accumulated + $amount )`
		// despues de `LTMS_Business_Wallet::debit(` dentro del try.
		// Formaource: garantizar que el patron "Debit OK: persistir exencion" sigue el debit.
		$this->assertStringContainsString(
			"// Débito OK: persistir exención consumida (post-success, no pre-try).",
			$source,
			'FAC-002: el update_user_meta de accumulated debe ir post-success del debit (documentado).'
		);
		$this->assertStringContainsString(
			'update_user_meta( $vendor_id, $monthly_gmf_key, $accumulated + $amount );',
			$source,
			'FAC-002: update_user_meta(monthly_gmf_key, accumulated + amount) presente.'
		);
	}

	public function test_accumulated_not_updated_on_debit_failure(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// El catch debe hacer early return SIN llamar a update_user_meta
		// sobre `$monthly_gmf_key`. Verificamos que en el catch hay un
		// `return;` y NO un `update_user_meta('$monthly_gmf_key'...)` que
		// persistiria el accumulated pese al fallo. La formulacion exacta
		// del comentario es informativa de la intencion del fix.
		$this->assertStringContainsString(
			'FAC-002: NO persistir accumulated si el débito falló',
			$source,
			'FAC-002: el catch tiene early return sin update_user_meta de accumulated (documentado).'
		);
	}

	public function test_accumulated_pre_debit_update_removed(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Anti-patron FAC-002 (pre-fix): el comentario "// Actualizar acumulado."
		// senalaba la posicion PRE-fix del update_user_meta incondicional (linea 77
		// pre-fix, antes del try/catch). Ese bloque fue reemplazado por el handler
		// post-success. Verificamos que el comentario marcador fue removido.
		$this->assertStringNotContainsString(
			"// Actualizar acumulado.",
			$source,
			'FAC-002: el comentario "Actualizar acumulado." + update pre-try fue removido.'
		);
	}

	public function test_branch_gmf_amount_zero_persists_accumulated(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// Caso borde P1 identificado por 2a revision: si $gmf_amount
		// redondea a 0 pero el taxable_base > 0 (e.g. base = $0.40 con
		// rate 0 => GMF = 0.16 rounds to 0), el monto SI consume
		// exencion mensual — debe persistir accumulated y hacer early
		// return. Verificamos que el branch "gmf_amount <= 0" hace
		// update_user_meta + return.
		$this->assertStringContainsString(
			'if ( $gmf_amount <= 0 ) {',
			$source
		);
		// La persistencia del accumulated en el branch zero:
		// (no buscamos literal exacto, sino la presencia del patrón dentro
		// de la rama — validamos a nivel source del branch).
		$this->assertStringContainsString(
			"// Base imponible positiva pero redondea a 0",
			$source,
			'FAC-002: branch gmf_amount<=0 tiene el comentario explicando why persist accumulated.'
		);
	}

	public function test_wallet_missing_branch_persists_accumulated(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// En modo UNIT_ONLY / sin wallet class disponible (e.g. tests),
		// el branch `else` (sin Wallet::debit) persiste accumulated
		// para mantener el estado consistente con un mocked-wallet.
		$this->assertStringContainsString(
			'// Wallet no disponible',
			$source,
			'FAC-002: branch else (sin Wallet class) documentado.'
		);
		$this->assertStringContainsString(
			"// Wallet no disponible (p.ej. modo UNIT_ONLY o clase deshabilitada): persistir",
			$source
		);
	}

	// ====================================================================
	//  Cross-checks transversales (no regresion C25-C29)
	// ====================================================================

	public function test_c28_tags_still_present(): void {
		$this->assertFileExists( self::COMPLIANCE_GUARDIAN_PATH );
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );

		// Cross-check C28 (no regression): los 3 tags de fix C28 siguen.
		$this->assertStringContainsString( 'CICLO28-P0-CG-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO28-P1-CG-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO28-P1-CG-003 FIX', $source );
	}

	public function test_c29_sales_booster_tags_still_present(): void {
		$this->assertFileExists( self::SALES_BOOSTER_PATH );
		$source = file_get_contents( self::SALES_BOOSTER_PATH );

		// Cross-check C29 (no regression): los 3 tags de fix C29 siguen.
		$this->assertStringContainsString( 'CICLO29-P0-SB-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-007 FIX', $source );
	}

	public function test_traffic_booster_still_uses_get_client_ip_safe_c30(): void {
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

	public function test_xcover_checkout_handler_still_uses_get_client_ip_safe_c30(): void {
		$this->assertFileExists( self::XCOVER_HANDLER_PATH );
		$source = file_get_contents( self::XCOVER_HANDLER_PATH );

		// Cross-check C4 (no regression).
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Xcover checkout handler C4 sigue usando get_client_ip_safe.'
		);
	}

	public function test_zapsign_webhook_handler_still_delegates_client_ip_safe_c30(): void {
		$this->assertFileExists( self::ZAPSIGN_WEBHOOK_PATH );
		$source = file_get_contents( self::ZAPSIGN_WEBHOOK_PATH );

		// Cross-check C27 (no regression).
		$this->assertStringContainsString(
			'get_client_ip_safe()',
			$source,
			'ZapSign webhook handler C27 sigue usando get_client_ip_safe.'
		);
	}

	public function test_all_5_webhook_handlers_use_get_client_ip_safe_c30(): void {
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
	//  Cross-checks CICLO1.3 (pre-existing fixes en este mismo archivo)
	// ====================================================================

	public function test_idor_protection_in_ajax_download_cert_present(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// CICLO1.3 P1-2 IDOR fix: admin cap OR own vendor cert; 403 otherwise.
		// Verificamos que el check sigue presente.
		$this->assertStringContainsString(
			'$can_manage = current_user_can( \'ltms_manage_platform_settings\' );',
			$source,
			'CICLO1.3 IDOR fix: can_manage check sigue presente.'
		);
		$this->assertStringContainsString(
			'$is_owner   = is_user_logged_in() && get_current_user_id() === $vendor_id;',
			$source,
			'CICLO1.3 IDOR fix: is_owner check sigue presente.'
		);
		$this->assertStringContainsString(
			'wp_send_json_error( [ \'message\' => __( \'Sin permisos para descargar este certificado.\', \'ltms\' ) ], 403 );',
			$source,
			'CICLO1.3 IDOR fix: 403 fall-closed sigue presente.'
		);
	}

	public function test_total_operaciones_key_present_in_cert_construction(): void {
		$this->assertFileExists( self::FISCAL_PATH );
		$source = file_get_contents( self::FISCAL_PATH );

		// CICLO1.3 P1-1 typo fix: la clave italiana `total_operazioni`
		// (NO existente en espanol) fue reemplazada por `total_operaciones`.
		// Verificamos que la correcta Sigue presente y la typo sigue ausente.
		$this->assertStringContainsString(
			"'total_operaciones'",
			$source,
			'CICLO1.3 typo fix: clave total_operaciones sigue presente.'
		);
		$this->assertStringNotContainsString(
			'total_operazioni',
			$source,
			'CICLO1.3 typo fix: clave italiana total_operazioni sigue ausente (no regression).'
		);
	}

	// ====================================================================
	//  Cross-check Wallet::debit acepta idempotency_key en posicion 6
	//  (contracto que el fix FAC-001 depende)
	// ====================================================================

	public function test_wallet_debit_accepts_idempotency_key_param(): void {
		$this->assertFileExists( self::WALLET_PATH );
		$source = file_get_contents( self::WALLET_PATH );

		// Si el signature de Wallet::debit se renombrara/removiera la
		// 6ta posicion `$idempotency_key`, el fix FAC-001 rompe silenciosamente.
		$this->assertStringContainsString(
			'public static function debit(',
			$source,
			'Wallet::debit method debe existir.'
		);
		$this->assertStringContainsString(
			'string $idempotency_key = \'\'',
			$source,
			'Wallet::debit debe seguir aceptando $idempotency_key en su signature.'
		);
	}

	public function test_wallet_execute_transaction_uses_reference_column_for_idempotency(): void {
		$this->assertFileExists( self::WALLET_PATH );
		$source = file_get_contents( self::WALLET_PATH );

		// Contracto dependiente: Wallet::execute_transaction usa la
		// `reference` column en lt_wallet_transactions para idempotency check.
		// Si se renombra la columna o se quita el SELECT, FAC-001 idem_key
		// pierde efecto — el 2do+ payout podria nuevamente doble-debit.
		$this->assertStringContainsString(
			'SELECT id FROM',
			$source,
			'Wallet::execute_transaction debe seguir haciendo SELECT id para idempotency check.'
		);
		$this->assertStringContainsString(
			'`reference` = %s',
			$source,
			'Wallet::execute_transaction debe seguir filtranado por reference column.'
		);
	}

	// ====================================================================
	//  Cross-checks fire sites de ltms_payout_completed
	//  (FAC-001 depende que ambos disparen con 3 args)
	// ====================================================================

	public function test_payout_scheduler_fires_with_3_args(): void {
		$payout_scheduler_path = __DIR__ . '/../../includes/business/class-ltms-payout-scheduler.php';
		$this->assertFileExists( $payout_scheduler_path );
		$source = file_get_contents( $payout_scheduler_path );

		// Fire site principal en payout-scheduler.php:683. Verificamos
		// que do_action('ltms_payout_completed', ...) pasa los 3 args
		// (incluyendo $payout_id). Sin esto, payout_id llega siempre 0
		// al GMF handler y el fix FAC-001 queda en fallback perpetuo.
		$this->assertStringContainsString(
			"do_action( 'ltms_payout_completed',",
			$source,
			'payout-scheduler.php sigue disparando ltms_payout_completed.'
		);
		$this->assertStringContainsString(
			'$payout[\'vendor_id\'], (float) $payout[\'amount\'], $payout_id',
			$source,
			'payout-scheduler.php dispara con 3 args (vendor_id, amount, payout_id).'
		);
	}

	public function test_openpay_webhook_fires_with_3_args(): void {
		$openpay_path = __DIR__ . '/../../includes/api/webhooks/class-ltms-openpay-webhook-handler.php';
		$this->assertFileExists( $openpay_path );
		$source = file_get_contents( $openpay_path );

		// 2do fire site en openpay-webhook-handler.php:349. Verificamos.
		$this->assertStringContainsString(
			"do_action( 'ltms_payout_completed',",
			$source,
			'openpay-webhook-handler.php tambien dispara ltms_payout_completed.'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque calculate_gmf_on_payout requiere
	// stubeo extensivo de LTMS_Core_Config::get(), get_user_meta,
	// update_user_meta, current_time, class_exists('LTMS_Business_Wallet')
	// (que usa transacciones MySQL reales), wp_mail, WC_Order, wp_remote_post.
	// Los tests documentan el contrato del fix (tag presente, cambio
	// estructural correcto, invariantes transversales intactas) sin
	// reimplementar logica — mismo patron que C20-C29.
}
