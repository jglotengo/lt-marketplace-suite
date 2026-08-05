<?php
/**
 * AuditCiclo17OrderPaidListenerFixesTest - Tests para los fixes del Ciclo 17.
 *
 * Cubre los fixes aplicados a
 * includes/business/listeners/class-ltms-order-paid-listener.php
 * (LTMS_Order_Paid_Listener - orquestador principal de ventas:
 * comisiones + Siigo sync + notify vendor + shipping absorbed +
 * shipments Heka/Uber + carrier cost ledger). Modulo critico:
 * payment flow cartografico del marketplace (wallet debit, Siigo
 * DIAN/SAT regulatorio, atomic claim H-4 FIX).
 *
 * El Ciclo 1.5 (AuditCiclo15ListenersFixesTest) ya cubrio el
 * AUDIT-LISTENERS-001 P1-1 FIX (atomic claim + idempotency_key +
 * reset flag en catch). Este Ciclo 17 cubre 5 P1 NUEVOS que el
 * Ciclo 1.5 no detecto: error DB silencioso en atomic claim
 * paths + INSERT en lt_job_queue/lt_notifications no verificados.
 *
 *   - OP-046 P1: on_order_paid() atomic claim (linea 76 pre-fix)
 *     `if ( ! $claimed )` no distinguia entre 0 (filas afectadas
 *     = ya reclamado por otro proceso, OK) y false (error DB con
 *     last_error). Si $wpdb->query retorna false (DB timeout,
 *     deadlock, replica lag, etc.), se hacia return silencioso
 *     -> el pedido NUNCA se procesa (no comisiones, no Siigo, no
 *     notif, no shipping, no ledger) sin alerta al admin -> el
 *     pedido queda en limbo sin facturacion electronica DIAN/SAT
 *     (requisito regulatorio) ni acreditacion al vendor (dinero
 *     perdido). Patron recurrente Ciclos 5-16.
 *     Fix: distinguir false (error DB -> log critico
 *     ORDER_PAID_ATOMIC_CLAIM_DB_FAILED + return) de 0 (ya
 *     reclamado -> return silencioso, OK) con SQL de
 *     reconciliacion manual.
 *   - OP-047 P1: debit_absorbed_shipping() atomic claim (linea
 *     250 pre-fix) mismo patron que OP-046. Si false, no hay
 *     debito al vendor Y no hay log critico -> el admin no se
 *     entera del fallo transitorio. Ademas el flag NO se flipea
 *     a '1' (sigue en '0') -> en la proxima ejecucion reintentara,
 *     pero si el error DB persiste, ciclo infinito silencioso.
 *     Fix: distinguir false (error DB -> log critico
 *     SHIPPING_DEBIT_ATOMIC_CLAIM_DB_FAILED + return, no debit)
 *     de 0 (ya reclamado -> return silencioso, OK).
 *   - OP-048 P1: debit_absorbed_shipping() catch (linea 284
 *     pre-fix) $wpdb->query reset flag a '0' NO se verificaba.
 *     Si el reset falla (error DB secundario dentro del catch de
 *     un error primario), el flag queda en '1' -> no hay retry
 *     path: la proxima ejecucion del handler lee '1' y baila ->
 *     el vendor absorb nunca se debita (dinero perdido para la
 *     plataforma que absorbe el flete silenciosamente). Patron
 *     recurrente Ciclos 5-16.
 *     Fix: capturar $reset + check false === + log critico
 *     SHIPPING_DEBIT_FLAG_RESET_FAILED con SQL de reconciliacion
 *     manual.
 *   - OP-049 P1: schedule_invoice_sync() INSERT en lt_job_queue
 *     (linea 148 pre-fix, fallback path cuando ActionScheduler
 *     no esta disponible) NO se verificaba. Si fallaba
 *     silenciosamente (false = error DB), no se agendaba la
 *     factura Siigo Y no se logueaba critico -> el admin no se
 *     entera de que fallo la sync -> la factura electronica
 *     (requisito regulatorio DIAN/SAT - Art. 30-B CFF / E.T.
 *     437-2 CO, Res. DIAN 167/2023) no se emite -> sanción
 *     regulatoria + reproceso manual tardío en cierre contable.
 *     Fix: capturar $inserted_queue + check false === + log
 *     critico SIIGO_INVOICE_QUEUE_INSERT_FAILED con SQL de
 *     reconciliacion manual.
 *   - OP-050 P1: notify_vendor() INSERT en lt_notifications
 *     (linea 599 pre-fix) NO se verificaba explicitamente (solo
 *     estaba envuelto en try/catch general). Si el INSERT fallaba,
 *     el catch lo logueaba como NOTIFICATION_FAILED (warning)
 *     PERO: (a) no distinguia entre INSERT fallido vs wp_mail
 *     fallido (ambos terminaban en el mismo catch -> diagnostico
 *     impreciso); (b) no habia SQL de reconciliacion para
 *     reinsertar la notif in-app manualmente. El email (canal
 *     secundario) si se enviaba abajo, pero el registro in-app
 *     desaparecia -> el vendor que mira la plataforma sin
 *     checkear email no se entera del pedido nuevo.
 *     Fix: capturar $inserted_notif + check false === + log
 *     critico especifico VENDOR_NOTIF_INAPP_INSERT_FAILED
 *     (distinguido de NOTIFICATION_FAILED generico del catch)
 *     con SQL de reconciliacion manual. No se aborta el notify
 *     (email canal secundario sigue abajo).
 *
 * Hallazgos descartados:
 *   - AUDIT-LISTENERS-001 P1-1 FIX (Ciclo 1.5): ya cubierto en
 *     AuditCiclo15ListenersFixesTest. No duplicar.
 *   - schedule_invoice_sync happy path (as_schedule_single_action):
 *     delega a ActionScheduler, no $wpdb directo. Correcto.
 *   - save_absorbed_shipping_quote / save_shared_shipping_quote /
 *     persist_uber_quote_id: catch sin INSERT/UPDATE $wpdb
 *     directo (usan $order->update_meta_data WC API). Correctos.
 *   - auto_create_shipments: catch con log error, no $wpdb
 *     directo. Correcto (delega a Api_Heka/Api_Uber).
 *   - record_carrier_shipping_cost / _legacy: catch + Wallet
 *     execute_transaction con idempotency_key B-11 FIX. Correctos.
 *   - process_commissions: try/catch + log error. Correcto.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers OP-046, OP-047, OP-048, OP-049, OP-050
 */
class AuditCiclo17OrderPaidListenerFixesTest extends LTMS_Unit_Test_Case {

	private const OP_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-order-paid-listener.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'sanitize_text_field' => static fn( string $s ): string => $s,
			'__'                  => static fn( string $s ): string => $s,
			'wp_unslash'          => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// -- OP-046 P1: on_order_paid atomic claim distinguishes false vs 0 --

	/**
	 * Debe haber verificacion false === $claimed explicita.
	 */
	public function test_on_order_paid_checks_false_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		// on_order_paid mide ~9,000 bytes post-fix.
		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'false === $claimed',
			$method_block,
			'OP-046: check explicito false === $claimed debe estar presente (distinguir error DB de "ya reclamado").'
		);
	}

	/**
	 * Debe haber verificacion 0 === (int) $claimed explicita.
	 */
	public function test_on_order_paid_checks_zero_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'0 === (int) $claimed',
			$method_block,
			'OP-046: check explicito 0 === (int) $claimed debe estar presente (distinguir "ya reclamado OK" de error DB).'
		);
	}

	/**
	 * El log de fallo debe ser critico ORDER_PAID_ATOMIC_CLAIM_DB_FAILED.
	 */
	public function test_on_order_paid_failure_logs_critical(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'ORDER_PAID_ATOMIC_CLAIM_DB_FAILED'," );
		$this->assertNotFalse( $token_pos, 'OP-046: log ORDER_PAID_ATOMIC_CLAIM_DB_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OP-046: el log debe ser critico (no warning), error DB mata procesamiento completo del pedido.'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE postmeta.
	 */
	public function test_on_order_paid_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		// El source usa backslash-escaped single quotes dentro de
		// un string delimitado por single quotes PHP: \'0\' y
		// \'_ltms_commissions_processed\'. Buscar el patron literal.
		$this->assertStringContainsString(
			"UPDATE %spostmeta SET meta_value=\\'0\\' WHERE post_id=%d AND meta_key=\\'_ltms_commissions_processed\\'",
			$source,
			'OP-046: log debe incluir SQL de reconciliacion manual UPDATE postmeta SET meta_value=0 (reset flag).'
		);
	}

	/**
	 * El fix tag CICLO17-P1-OP-046 FIX debe estar presente.
	 */
	public function test_op046_fix_tag_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString( 'CICLO17-P1-OP-046 FIX', $source );
	}

	// -- OP-047 P1: debit_absorbed_shipping atomic claim false vs 0 ------

	/**
	 * Debe haber verificacion false === $shipping_claimed explicita.
	 */
	public function test_debit_absorbed_shipping_checks_false_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function debit_absorbed_shipping' );
		$this->assertNotFalse( $method_pos, 'debit_absorbed_shipping debe existir.' );

		// debit_absorbed_shipping mide ~13,600 bytes post-fix
		// (incluye atomic claim + Wallet::debit + catch con reset
		// verificado).
		$method_block = substr( $source, $method_pos, 14000 );

		$this->assertStringContainsString(
			'false === $shipping_claimed',
			$method_block,
			'OP-047: check explicito false === $shipping_claimed debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * SHIPPING_DEBIT_ATOMIC_CLAIM_DB_FAILED.
	 */
	public function test_debit_absorbed_shipping_failure_logs_critical(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function debit_absorbed_shipping' );
		$this->assertNotFalse( $method_pos, 'debit_absorbed_shipping debe existir.' );

		$method_block = substr( $source, $method_pos, 14000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'SHIPPING_DEBIT_ATOMIC_CLAIM_DB_FAILED'," );
		$this->assertNotFalse( $token_pos, 'OP-047: log SHIPPING_DEBIT_ATOMIC_CLAIM_DB_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OP-047: el log debe ser critico (no warning), error DB mata debito al vendor.'
		);
	}

	/**
	 * El fix tag CICLO17-P1-OP-047 FIX debe estar presente.
	 */
	public function test_op047_fix_tag_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString( 'CICLO17-P1-OP-047 FIX', $source );
	}

	// -- OP-048 P1: debit_absorbed_shipping catch reset flag verifica -----

	/**
	 * El reset debe capturarse en $reset (no ser llamada suelta).
	 */
	public function test_debit_absorbed_shipping_captures_reset_in_result_var(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function debit_absorbed_shipping' );
		$this->assertNotFalse( $method_pos, 'debit_absorbed_shipping debe existir.' );

		$method_block = substr( $source, $method_pos, 14000 );

		$this->assertStringContainsString(
			'$reset = $wpdb->query(',
			$method_block,
			'OP-048: el reset del flag debe capturar su retorno en $reset (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $reset en el catch.
	 */
	public function test_debit_absorbed_shipping_reset_checks_false_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function debit_absorbed_shipping' );
		$this->assertNotFalse( $method_pos, 'debit_absorbed_shipping debe existir.' );

		$method_block = substr( $source, $method_pos, 14000 );

		$this->assertStringContainsString(
			'false === $reset',
			$method_block,
			'OP-048: check explicito false === $reset en catch debe estar presente (regresion guard del reset del flag).'
		);
	}

	/**
	 * El log de fallo del reset debe ser critico
	 * SHIPPING_DEBIT_FLAG_RESET_FAILED.
	 */
	public function test_debit_absorbed_shipping_reset_failure_logs_critical(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$token_pos = strpos( $source, "'SHIPPING_DEBIT_FLAG_RESET_FAILED'," );
		$this->assertNotFalse( $token_pos, 'OP-048: log SHIPPING_DEBIT_FLAG_RESET_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OP-048: el log debe ser critico (no warning), fallo de reset bloquea retry path.'
		);
	}

	/**
	 * El fix tag CICLO17-P1-OP-048 FIX debe estar presente.
	 */
	public function test_op048_fix_tag_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString( 'CICLO17-P1-OP-048 FIX', $source );
	}

	// -- OP-049 P1: schedule_invoice_sync $wpdb->insert lt_job_queue ------

	/**
	 * El INSERT debe capturarse en $inserted_queue.
	 */
	public function test_schedule_invoice_sync_captures_insert_in_result_var(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function schedule_invoice_sync' );
		$this->assertNotFalse( $method_pos, 'schedule_invoice_sync debe existir.' );

		// schedule_invoice_sync mide ~3,600 bytes post-fix.
		$method_block = substr( $source, $method_pos, 4000 );

		$this->assertStringContainsString(
			'$inserted_queue = $wpdb->insert(',
			$method_block,
			'OP-049: el INSERT en lt_job_queue debe capturar su retorno en $inserted_queue (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_queue.
	 */
	public function test_schedule_invoice_sync_checks_false_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function schedule_invoice_sync' );
		$this->assertNotFalse( $method_pos, 'schedule_invoice_sync debe existir.' );

		$method_block = substr( $source, $method_pos, 4000 );

		$this->assertStringContainsString(
			'false === $inserted_queue',
			$method_block,
			'OP-049: check explicito false === $inserted_queue debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico SIIGO_INVOICE_QUEUE_INSERT_FAILED.
	 */
	public function test_schedule_invoice_sync_failure_logs_critical(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$token_pos = strpos( $source, "'SIIGO_INVOICE_QUEUE_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'OP-049: log SIIGO_INVOICE_QUEUE_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OP-049: el log debe ser critico (no warning), factura DIAN/SAT regulatoria perdida.'
		);
	}

	/**
	 * El log debe mencionar INSERT INTO %slt_job_queue con hook
	 * 'ltms_sync_siigo_invoice'.
	 */
	public function test_schedule_invoice_sync_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString(
			"INSERT INTO %slt_job_queue",
			$source,
			'OP-049: log debe incluir SQL de reconciliacion INSERT INTO lt_job_queue.'
		);
	}

	/**
	 * El fix tag CICLO17-P1-OP-049 FIX debe estar presente.
	 */
	public function test_op049_fix_tag_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString( 'CICLO17-P1-OP-049 FIX', $source );
	}

	// -- OP-050 P1: notify_vendor $wpdb->insert lt_notifications ----------

	/**
	 * El INSERT debe capturarse en $inserted_notif.
	 */
	public function test_notify_vendor_captures_insert_in_result_var(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function notify_vendor' );
		$this->assertNotFalse( $method_pos, 'notify_vendor debe existir.' );

		// notify_vendor mide ~5,600 bytes post-fix.
		$method_block = substr( $source, $method_pos, 6000 );

		$this->assertStringContainsString(
			'$inserted_notif = $wpdb->insert(',
			$method_block,
			'OP-050: el INSERT debe capturar su retorno en $inserted_notif (no statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_notif.
	 */
	public function test_notify_vendor_checks_false_explicitly(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$method_pos = strpos( $source, 'function notify_vendor' );
		$this->assertNotFalse( $method_pos, 'notify_vendor debe existir.' );

		$method_block = substr( $source, $method_pos, 6000 );

		$this->assertStringContainsString(
			'false === $inserted_notif',
			$method_block,
			'OP-050: check explicito false === $inserted_notif debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * VENDOR_NOTIF_INAPP_INSERT_FAILED (distinguido del generico
	 * NOTIFICATION_FAILED del catch).
	 */
	public function test_notify_vendor_failure_logs_critical(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString(
			"'VENDOR_NOTIF_INAPP_INSERT_FAILED',",
			$source,
			'OP-050: log VENDOR_NOTIF_INAPP_INSERT_FAILED debe existir (distinguido del NOTIFICATION_FAILED generico del catch).'
		);

		// Verificar que es ::critical (no warning).
		$token_pos = strpos( $source, "'VENDOR_NOTIF_INAPP_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OP-050: el log debe ser critico (no warning), notif in-app perdida sin reconciliacion.'
		);
	}

	/**
	 * El log debe mencionar INSERT INTO %slt_notifications con
	 * type='order_new' channel='inapp'.
	 */
	public function test_notify_vendor_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		// El source usa backslash-escaped single quotes en sprintf:
		// \'order_new\', \'inapp\'. Buscar el patron literal.
		$this->assertStringContainsString(
			"\\'order_new\\', \\'inapp\\'",
			$source,
			'OP-050: SQL de reconciliacion debe mencionar type=order_new channel=inapp.'
		);
	}

	/**
	 * El fix tag CICLO17-P1-OP-050 FIX debe estar presente.
	 */
	public function test_op050_fix_tag_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString( 'CICLO17-P1-OP-050 FIX', $source );
	}

	// -- Cross-check: AUDIT-LISTENERS-001 P1-1 FIX (Ciclo 1.5) intacto --

	/**
	 * Cross-check: el AUDIT-LISTENERS-001 P1-1 FIX del Ciclo 1.5
	 * (atomic claim + idempotency_key en Wallet::debit) debe seguir
	 * presente en debit_absorbed_shipping (regresion guard).
	 */
	public function test_ciclo15_p1_1_fix_still_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString(
			'AUDIT-LISTENERS-001 P1-1 FIX',
			$source,
			'Cross-check: el AUDIT-LISTENERS-001 P1-1 FIX del Ciclo 1.5 debe seguir presente (regresion guard).'
		);

		// Verificar que el idempotency_key sprintf('shipping_absorbed_o%d', $order->get_id()) sigue.
		$this->assertStringContainsString(
			" sprintf( 'shipping_absorbed_o%d',",
			$source,
			'Cross-check: el idempotency_key B-11 FIX sprintf(shipping_absorbed_o%d) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el H-4 FIX (atomic claim original en on_order_paid
	 * con add_post_meta unique=true) debe seguir presente (regresion
	 * guard - los fixes del Ciclo 17 solo anaden log critico en path
	 * de error DB, no eliminan el atomic claim que esta bien).
	 */
	public function test_h4_atomic_claim_still_present(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString(
			'H-4 FIX',
			$source,
			'Cross-check: el H-4 FIX (atomic claim original) debe seguir presente (regresion guard).'
		);

		$this->assertStringContainsString(
			"add_post_meta( \$order_id, '_ltms_commissions_processed', '0', true )",
			$source,
			'Cross-check: el add_post_meta unique=true del H-4 FIX debe seguir presente.'
		);
	}

	/**
	 * Cross-check: los hooks init deben seguir registrados (regresion
	 * guard - los fixes del Ciclo 17 no tocan init).
	 */
	public function test_init_hooks_still_registered(): void {
		$this->assertFileExists( self::OP_PATH );
		$source = file_get_contents( self::OP_PATH );

		$this->assertStringContainsString(
			"add_action( 'woocommerce_payment_complete'",
			$source,
			'Cross-check: el hook woocommerce_payment_complete debe seguir registrado.'
		);

		$this->assertStringContainsString(
			"add_action( 'woocommerce_order_status_completed'",
			$source,
			'Cross-check: el hook woocommerce_order_status_completed debe seguir registrado.'
		);
	}
}
