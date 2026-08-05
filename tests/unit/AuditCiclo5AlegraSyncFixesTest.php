<?php
/**
 * AuditCiclo5AlegraSyncFixesTest — Tests para los fixes P1+P2 del Ciclo 5.
 *
 * Cubre los fixes aplicados a class-ltms-alegra-sync.php (accounting Alegra):
 *   - ALEGRA-001 P1: on_donation_credited() — $wpdb->update de
 *     alegra_entry_id en lt_donations no se verificaba. Si fallaba
 *     silenciosamente, el asiento QUEDABA creado en Alegra PERO sin enlace
 *     en LTMS → siguiente re-fire del hook podía duplicar el asiento en
 *     Alegra si el Idempotency-Key server-side no dedupeaba. El log info
 *     DONATION_ALEGRA_SYNCED se disparaba mintiendo sobre el éxito.
 *     Fix: capturar $entry_updated + verificar === false o === 0 + log
 *     critico DONATION_ALEGRA_ENTRY_LINK_FAILED con alegra_entry_id y
 *     last_error para reconciliacion manual.
 *   - ALEGRA-002 P1: on_donation_payout_completed() — mismo patrón en
 *     $wpdb->update de alegra_entry_id en lt_donation_payouts. El batch
 *     quedaba con alegra_entry_id=0 → el admin podía re-disparar el pago
 *     manualmente creyendo que no se sincronizó → egreso duplicado en
 *     Alegra (doble salida de caja contable).
 *     Fix: capturar $batch_entry_updated + verificar === false o === 0 +
 *     log critico DONATION_PAYOUT_ALEGRA_ENTRY_LINK_FAILED.
 *   - ALEGRA-003 P2: on_payout_completed() — cuando la API Alegra
 *     respondía 200 sin id (path else), solo log_warning. No se persistía
 *     flag de fallo en user meta (a diferencia de facturas que persisten
 *     _ltms_alegra_invoice_failed → retry_failed_invoices las reintenta).
 *     El cron no reintenta payouts, así que el egreso quedaba sin
 *     mecanismo de reintento programado y el admin no lo veía.
 *     Fix: persistir _ltms_alegra_payout_failed_{md5(idem_key)} meta en
 *     user meta para un futuro cron de reintento de payouts fallidos.
 *   - ALEGRA-004 P2: on_payout_completed() catch \Throwable — solo
 *     log_warning, sin persistir flag. El egreso Alegra faltante era
 *     invisible hasta conciliación mensual del contador.
 *     Fix: persistir mismo flag que ALEGRA-003 en el catch, con el
 *     mensaje de la excepción como _error meta.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers ALEGRA-001, ALEGRA-002, ALEGRA-003, ALEGRA-004
 */
class AuditCiclo5AlegraSyncFixesTest extends LTMS_Unit_Test_Case {

	private const ALEGRA_PATH = __DIR__ . '/../../includes/business/class-ltms-alegra-sync.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'sanitize_text_field'     => static fn( string $s ): string => $s,
			'__'                      => static fn( string $s ): string => $s,
			'wp_unslash'              => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// ── ALEGRA-001 P1: on_donation_credited verifica UPDATE alegra_entry_id ───

	/**
	 * El UPDATE de alegra_entry_id en lt_donations debe capturar su retorno
	 * en $entry_updated y verificarlo antes de disparar el log info.
	 */
	public function test_donation_credited_update_captures_return_in_entry_updated(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'$entry_updated = $wpdb->update(',
			$source,
			'ALEGRA-001: el UPDATE de alegra_entry_id en lt_donations debe capturar su retorno en $entry_updated.'
		);
	}

	/**
	 * La verificacion debe cubrir tanto === false (error DB) como === 0
	 * (fila desaparecida entre SELECT short-circuit y UPDATE).
	 */
	public function test_donation_credited_update_checks_false_and_zero(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'$entry_updated === false || $entry_updated === 0',
			$source,
			'ALEGRA-001: verificar $entry_updated === false (error DB) o === 0 (fila desaparecida).'
		);
	}

	/**
	 * En fallo, debe disparar log critico DONATION_ALEGRA_ENTRY_LINK_FAILED
	 * con alegra_entry_id, last_error y sentencia SQL de reconciliacion.
	 */
	public function test_donation_credited_failure_logs_critical_with_reconciliation_hint(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'DONATION_ALEGRA_ENTRY_LINK_FAILED',
			$source,
			'ALEGRA-001: log critico DONATION_ALEGRA_ENTRY_LINK_FAILED debe estar presente.'
		);

		// Debe mencionar el alegra_entry_id para reconciliacion manual.
		$this->assertStringContainsString(
			'alegra_entry_id',
			$source,
			'ALEGRA-001: log debe mencionar alegra_entry_id para reconciliacion manual.'
		);

		// Debe incluir la sentencia SQL de reconciliacion manual.
		$this->assertStringContainsString(
			'lt_donations.alegra_entry_id',
			$source,
			'ALEGRA-001: log debe incluir la sentencia SQL de reconciliacion manual.'
		);
	}

	/**
	 * El bloque de verificacion debe ir ANTES del log info
	 * DONATION_ALEGRA_SYNCED (el log info previo mintia sobre el exito de
	 * la persistencia local).
	 */
	public function test_donation_credited_check_before_log_info(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$update_pos  = strpos( $source, '$entry_updated = $wpdb->update(' );
		$check_pos   = strpos( $source, '$entry_updated === false || $entry_updated === 0' );
		$log_info    = strpos( $source, "'DONATION_ALEGRA_SYNCED'" );

		$this->assertNotFalse( $update_pos, 'ALEGRA-001: UPDATE debe existir.' );
		$this->assertNotFalse( $check_pos, 'ALEGRA-001: check debe existir.' );
		$this->assertNotFalse( $log_info, 'ALEGRA-001: log info DONATION_ALEGRA_SYNCED debe seguir presente.' );

		$this->assertGreaterThan( $update_pos, $check_pos, 'ALEGRA-001: check debe ir despues del UPDATE.' );
		// El log info va despues del bloque de verificacion (no antes como antes).
		$this->assertGreaterThan( $check_pos, $log_info, 'ALEGRA-001: log info DONATION_ALEGRA_SYNCED debe ir DESPUES del check (antes mintia sobre persistencia fallida).' );
	}

	// ── ALEGRA-002 P1: on_donation_payout_completed verifica UPDATE ─────────

	/**
	 * El UPDATE de alegra_entry_id en lt_donation_payouts debe capturar su
	 * retorno en $batch_entry_updated y verificarlo.
	 */
	public function test_donation_payout_update_captures_return_in_batch_entry_updated(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'$batch_entry_updated = $wpdb->update(',
			$source,
			'ALEGRA-002: el UPDATE de alegra_entry_id en lt_donation_payouts debe capturar su retorno en $batch_entry_updated.'
		);
	}

	/**
	 * La verificacion debe cubrir === false y === 0.
	 */
	public function test_donation_payout_update_checks_false_and_zero(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'$batch_entry_updated === false || $batch_entry_updated === 0',
			$source,
			'ALEGRA-002: verificar $batch_entry_updated === false (error DB) o === 0 (fila desaparecida).'
		);
	}

	/**
	 * En fallo, debe disparar log critico
	 * DONATION_PAYOUT_ALEGRA_ENTRY_LINK_FAILED.
	 */
	public function test_donation_payout_failure_logs_critical(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			'DONATION_PAYOUT_ALEGRA_ENTRY_LINK_FAILED',
			$source,
			'ALEGRA-002: log critico DONATION_PAYOUT_ALEGRA_ENTRY_LINK_FAILED debe estar presente.'
		);

		// Debe mencionar el batch_id para reconciliacion manual.
		$this->assertStringContainsString(
			'batch_id',
			$source,
			'ALEGRA-002: log debe mencionar batch_id para reconciliacion manual.'
		);

		// Debe mencionar la sentencia SQL de reconciliacion manual.
		$this->assertStringContainsString(
			'lt_donation_payouts.alegra_entry_id',
			$source,
			'ALEGRA-002: log debe incluir la sentencia SQL de reconciliacion.'
		);
	}

	// ── ALEGRA-003 P2: on_payout_completed persiste flag cuando 200 sin id ────

	/**
	 * En el path else (200 OK sin id de Alegra), debe persistir un flag de
	 * fallo en user meta para futura deteccion por cron/admin.
	 */
	public function test_payout_no_id_path_persists_failure_flag(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		// El meta key debe seguir el patron _ltms_alegra_payout_failed_{md5}.
		$this->assertStringContainsString(
			"'_ltms_alegra_payout_failed_' . md5( \$idem_key )",
			$source,
			'ALEGRA-003: path 200-sin-id debe persistir _ltms_alegra_payout_failed_{md5($idem_key)} en user meta.'
		);

		// Debe llamar update_user_meta para persistir el flag.
		$this->assertStringContainsString(
			'update_user_meta( $vendor_id, $fail_meta',
			$source,
			'ALEGRA-003: debe llamarse update_user_meta con $fail_meta para persistir el flag de fallo.'
		);
	}

	/**
	 * El flag debe persistirse con timestamp (current_time) para que el
	 * cron/admin sepa cuando fallo.
	 */
	public function test_payout_no_id_flag_persists_timestamp(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		// current_time( 'mysql' ) debe usarse como valor del flag.
		$this->assertStringContainsString(
			'update_user_meta( $vendor_id, $fail_meta, current_time',
			$source,
			'ALEGRA-003: flag debe persistir timestamp (current_time) para que el cron sepa cuando fallo.'
		);
	}

	/**
	 * Adicionalmente, debe persistir el mensaje de error en
	 * {fail_meta}_error para diagnostico.
	 */
	public function test_payout_no_id_flag_persists_error_message(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			"\$fail_meta . '_error'",
			$source,
			'ALEGRA-003: debe persistirse {fail_meta}_error con mensaje de diagnostico.'
		);
	}

	/**
	 * El log_warning debe mencionar el flag persistido para que el admin
	 * sepa donde buscar el fallo.
	 */
	public function test_payout_no_id_log_mentions_fail_meta(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString(
			"'alegra_payout_no_id'",
			$source,
			'ALEGRA-003: log alegra_payout_no_id debe seguir presente.'
		);

		// El log debe mencionar 'fail_meta' o 'flag persistido'.
		$this->assertStringContainsString(
			'flag persistido para reintento',
			$source,
			'ALEGRA-003: log debe mencionar que el flag fue persistido para reintento.'
		);
	}

	// ── ALEGRA-004 P2: on_payout_completed catch persiste flag ────────────────

	/**
	 * En el catch \Throwable, debe persistir el flag de fallo (mismo
	 * patron que ALEGRA-003).
	 */
	public function test_payout_catch_persists_failure_flag(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		// Encontrar el catch \Throwable dentro de on_payout_completed.
		$method_pos  = strpos( $source, 'function on_payout_completed' );
		$this->assertNotFalse( $method_pos, 'on_payout_completed debe existir.' );

		// Tomar el metodo completo (7000 chars: el metodo con los fixes
		// P1 anteriores + los P2 nuevos ocupa ~6600 chars desde 'function'
		// hasta el cierre del catch \Throwable).
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'catch ( \Throwable $e )',
			$method_block,
			'ALEGRA-004: catch \Throwable debe existir dentro de on_payout_completed.'
		);

		$this->assertStringContainsString(
			"'_ltms_alegra_payout_failed_' . md5( \$idem_key )",
			$method_block,
			'ALEGRA-004: catch debe persistir _ltms_alegra_payout_failed_{md5($idem_key)} en user meta.'
		);

		$this->assertStringContainsString(
			'update_user_meta( $vendor_id, $fail_meta',
			$method_block,
			'ALEGRA-004: catch debe llamarse update_user_meta con $fail_meta.'
		);
	}

	/**
	 * El flag del catch debe persistir el mensaje de la excepcion como
	 * {fail_meta}_error.
	 */
	public function test_payout_catch_persists_exception_message(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$method_pos  = strpos( $source, 'function on_payout_completed' );
		$method_block = substr( $source, $method_pos, 7000 );

		// {fail_meta}_error debe recibir $e->getMessage().
		$this->assertStringContainsString(
			"\$fail_meta . '_error', \$e->getMessage()",
			$method_block,
			'ALEGRA-004: catch debe persistir $e->getMessage() en {fail_meta}_error.'
		);
	}

	/**
	 * El log_warning del catch debe mencionar el flag persistido.
	 */
	public function test_payout_catch_log_mentions_fail_meta(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$method_pos   = strpos( $source, 'function on_payout_completed' );
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			"'alegra_payout_failed'",
			$method_block,
			'ALEGRA-004: log alegra_payout_failed debe seguir presente en el catch.'
		);

		$this->assertStringContainsString(
			'flag persistido para reintento',
			$method_block,
			'ALEGRA-004: log del catch debe mencionar flag persistido para reintento.'
		);
	}

	// ── Fix tags de trazabilidad ─────────────────────────────────────────────

	/**
	 * Todos los fixes del Ciclo 5 deben estar marcados con sus IDs en el
	 * codigo fuente.
	 */
	public function test_fix_tags_present_in_alegra_sync(): void {
		$this->assertFileExists( self::ALEGRA_PATH );
		$source = file_get_contents( self::ALEGRA_PATH );

		$this->assertStringContainsString( 'CICLO5-P1-ALEGRA-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO5-P1-ALEGRA-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO5-P2-ALEGRA-003 FIX', $source );
		$this->assertStringContainsString( 'CICLO5-P2-ALEGRA-004 FIX', $source );
	}
}
