<?php
/**
 * AuditCiclo11RediOrderListenerFixesTest - Tests para los fixes del Ciclo 11.
 *
 * Cubre los fixes aplicados a
 * includes/business/listeners/class-ltms-redi-order-listener.php
 * (listener ReDi - on_order_paid + on_order_cancelled):
 *   - RL-024 P0 CRITICO: on_order_paid() invocaba
 *     LTMS_Business_Redi_Order_Split::process() +
 *     LTMS_Business_Redi_Manager::deduct_origin_stock() DOS VECES en
 *     cada ReDi order paid exitoso - una vez fuera del try/catch
 *     (lineas 60-61 pre-fix) y otra vez dentro del try/catch
 *     (lineas 73-74 pre-fix). Esto provocaba doble stock deduction
 *     + doble comisiones + doble filas en lt_redi_commissions en
 *     CADA order ReDi pago exitoso - exactamente el
 *     doble-procesamiento que el H-5 atomic claim (linea 51)
 *     intentaba prevenir. El commit "Ciclo 1.5" añadio el try/catch
 *     para reset del flag en fallo, pero olvido eliminar las
 *     llamadas previas sueltas ABOVE del try. Bug presente desde el
 *     Ciclo 1.5 (auditoria original), no detectado en 10 ciclos
 *     posteriores porque visualmente las llamadas son adyacentes y
 *     el patron try/catch se "ve" correcto. Fix: dejar solo la
 *     invocacion dentro del try/catch.
 *   - RL-025 P1: el UPDATE de reset del flag _ltms_redi_processed
 *     en el catch (linea 78 pre-fix) no se verificaba. Si fallaba
 *     silenciosamente (false = error DB, 0 = no rows por schema
 *     drift), el flag quedaba en '1' y el retry nunca ocurria - el
 *     order quedaba sin procesar para siempre (sin stock deduct ni
 *     comisiones ni notificaciones), y el log de error
 *     REDI_PROCESS_FAILED se registraba sin alerta critica del flag
 *     lockeado. Patron recurrente documentado en Ciclos 5-10.
 *     Fix: captura $reset_result + verificacion explicita
 *     false === $reset_result (log critico
 *     REDI_PROCESSING_FLAG_RESET_FAILED con SQL de reconciliacion
 *     manual + var_export + last_error) y 0 === (int) $reset_result
 *     (mismo log critico, caso teorico).
 *   - RL-026 P1: on_order_cancelled() UPDATE status='reversed' en
 *     lt_redi_commissions (linea 244 pre-fix) no se verificaba. Si
 *     el UPDATE fallaba silenciosamente, los debits ya aplicados
 *     al wallet NO quedaban marcados como reversed - el cron o retry
 *     podia re-debitar (mismo patron P0-1 del Ciclo 1.5 que el
 *     idempotency_key previene pero el status='paid' sigue
 *     permitiendo que la logica de reversal ejecuta de nuevo).
 *     Patron recurrente documentado en Ciclos 5-10.
 *     Fix: captura $reversed + verificacion explicita
 *     false === $reversed (log critico
 *     REDI_REVERSAL_STATUS_UPDATE_FAILED con SQL de reconciliacion
 *     manual + var_export + last_error) y 0 === (int) $reversed.
 *   - RL-027 P1: create_notification() INSERT en lt_notifications
 *     (linea 309 pre-fix) no se verificaba. Si fallaba
 *     silenciosamente, el origin vendor NO recibia la notificacion
 *     in-app que le avisa que debe enviar el producto - el pedido se
 *     atrasa sin alerta. La notificacion es el canal principal de
 *     aviso al origin vendor en ReDi. Patron recurrente Ciclos 5-10.
 *     Fix: captura $notif_result + verificacion explicita
 *     false === $notif_result (log critico
 *     REDI_NOTIFICATION_INSERT_FAILED con SQL de reconciliacion
 *     manual + var_export + last_error) y 0 === (int) $notif_result.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers RL-024, RL-025, RL-026, RL-027
 */
class AuditCiclo11RediOrderListenerFixesTest extends LTMS_Unit_Test_Case {

	private const RL_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-redi-order-listener.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'sanitize_text_field' => static fn( string $s ): string => $s,
			'sanitize_key'        => static fn( string $s ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $s ) ?? $s ),
			'__'                  => static fn( string $s ): string => $s,
			'wp_unslash'          => static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v,
		] );
	}

	protected function tearDown(): void {
		\LTMS_Core_Config::flush_cache();
		parent::tearDown();
	}

	// -- RL-024 P0: on_order_paid invoca process+deduct UNA sola vez --

	/**
	 * El metodo on_order_paid NO debe invocar
	 * Redi_Order_Split::process() mas de una vez (linea 60-61
	 * pre-fix era la invocacion duplicada fuera del try/catch).
	 *
	 * Nota: se cuenta solo la invocacion VIVA (con la firma
	 * completa `$order, $redi_items`) - no las menciones del
	 * metodo en comentarios H-5 FIX del bloque superior que
	 * describen el bug pre-fix ("both run ... ::process() -
	 * double stock deduction").
	 */
	public function test_on_order_paid_invokes_process_only_once(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		// Localizar on_order_paid (mide ~11,200 bytes post-fix).
		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		// Contar ocurrencias de Redi_Order_Split::process( $order
		// - la firma real de la invocacion viva (no menciones en
		// comentarios que no incluyen argumentos).
		$count = substr_count( $method_block, 'LTMS_Business_Redi_Order_Split::process( $order, $redi_items )' );
		$this->assertSame(
			1,
			$count,
			'RL-024: Redi_Order_Split::process() debe invocarse UNA sola vez con la firma viva (recuento de invocaciones vivas: ' . $count . ' - bug duplicado pre-fix).'
		);
	}

	/**
	 * El metodo on_order_paid NO debe invocar
	 * Redi_Manager::deduct_origin_stock() mas de una vez.
	 */
	public function test_on_order_paid_invokes_deduct_origin_stock_only_once(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		// Contar invocaciones vivas (con argumento $order).
		$count = substr_count( $method_block, 'LTMS_Business_Redi_Manager::deduct_origin_stock( $order )' );
		$this->assertSame(
			1,
			$count,
			'RL-024: deduct_origin_stock() debe invocarse UNA sola vez con la firma viva (recuento de invocaciones vivas: ' . $count . ' - bug duplicado pre-fix).'
		);
	}

	/**
	 * La invocacion debe estar DENTRO de un bloque try { ... } catch.
	 * Estrategia: tomar 200 chars antes de la invocacion viva y
	 * buscar 'try {' (no usar comentarios).
	 */
	public function test_on_order_paid_invocation_is_inside_try_catch(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		// Localizar la invocacion viva de process( $order, $redi_items ).
		$invoke = 'LTMS_Business_Redi_Order_Split::process( $order, $redi_items )';
		$invoke_pos = strpos( $method_block, $invoke );
		$this->assertNotFalse( $invoke_pos, 'La invocacion viva de process() debe existir.' );

		// Tomar 50 chars antes (suficiente para el 'try {' que esta
		// solo 1 linea antes, evitando el comentario H-5 que esta
		// mucho mas arriba).
		$before = substr( $method_block, max( 0, $invoke_pos - 50 ), 50 );
		$this->assertStringContainsString(
			'try {',
			$before,
			'RL-024: la invocacion viva de process() debe estar dentro de un try { ... } (no suelta antes del try).'
		);
	}

	/**
	 * El fix tag CICLO11-P0-RL-024 FIX debe estar presente.
	 */
	public function test_rl024_fix_tag_present(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString( 'CICLO11-P0-RL-024 FIX', $source );
	}

	// -- RL-025 P1: reset flag en catch verifica retorno false/0 -----

	/**
	 * El UPDATE de reset debe capturarse en $reset_result (no ser
	 * llamada statement suelta).
	 */
	public function test_reset_flag_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		$this->assertStringContainsString(
			'$reset_result = $wpdb->query(',
			$method_block,
			'RL-025: el UPDATE de reset debe capturar su retorno en $reset_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reset_result ||
	 * 0 === (int) $reset_result dentro de on_order_paid.
	 */
	public function test_reset_flag_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		$this->assertStringContainsString(
			'false === $reset_result || 0 === (int) $reset_result',
			$method_block,
			'RL-025: check explicito false === $reset_result || 0 === (int) $reset_result debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_PROCESSING_FLAG_RESET_FAILED.
	 */
	public function test_reset_flag_failure_logs_critical(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_PROCESSING_FLAG_RESET_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RL-025: log REDI_PROCESSING_FLAG_RESET_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RL-025: el log debe ser critico (no warning), fallo combinado de procesamiento + reset de flag.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB con
	 * last_error) de 0 (no rows sin error reported).
	 */
	public function test_reset_flag_log_uses_var_export(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 11500 );

		$this->assertStringContainsString(
			'var_export( $reset_result, true )',
			$method_block,
			'RL-025: log debe usar var_export($reset_result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE postmeta
	 * (para que el admin pueda reparar el flag manualmente).
	 *
	 * Nota: el source tiene `UPDATE %spostmeta SET meta_value=\'0\'`
	 * con la backslash-quote de PHP. El test lee el source crudo via
	 * file_get_contents, por lo que se busca el substring literal tal
	 * como aparece en el archivo.
	 */
	public function test_reset_flag_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		// El substring literal en el source (con su backslash-quote
		// de PHP single-quoted string).
		$this->assertStringContainsString(
			"UPDATE %spostmeta SET meta_value=\\'0\\' WHERE post_id=%d AND meta_key=\\'_ltms_redi_processed\\'",
			$source,
			'RL-025: log debe incluir SQL de reconciliacion manual UPDATE postmeta.'
		);

		$this->assertStringContainsString(
			'_ltms_redi_processed',
			$source,
			'RL-025: SQL de reconciliacion debe mencionar _ltms_redi_processed.'
		);
	}

	/**
	 * El fix tag CICLO11-P1-RL-025 FIX debe estar presente.
	 */
	public function test_rl025_fix_tag_present(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString( 'CICLO11-P1-RL-025 FIX', $source );
	}

	// -- RL-026 P1: on_order_cancelled UPDATE status=reversed verifica --

	/**
	 * El UPDATE debe capturarse en $reversed (no ser llamada suelta).
	 */
	public function test_on_order_cancelled_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos, 'on_order_cancelled debe existir.' );

		// on_order_cancelled mide ~8,700 bytes post-fix.
		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			'$reversed = $wpdb->update(',
			$method_block,
			'RL-026: el UPDATE de status=reversed debe capturar su retorno en $reversed (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reversed ||
	 * 0 === (int) $reversed dentro de on_order_cancelled.
	 */
	public function test_on_order_cancelled_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos, 'on_order_cancelled debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			'false === $reversed || 0 === (int) $reversed',
			$method_block,
			'RL-026: check explicito false === $reversed || 0 === (int) $reversed debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_REVERSAL_STATUS_UPDATE_FAILED.
	 */
	public function test_on_order_cancelled_failure_logs_critical(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos, 'on_order_cancelled debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_REVERSAL_STATUS_UPDATE_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RL-026: log REDI_REVERSAL_STATUS_UPDATE_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RL-026: el log debe ser critico (no warning), fallo de status de reversal.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB con
	 * last_error) de 0 (no rows sin error reported).
	 */
	public function test_on_order_cancelled_log_uses_var_export(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function on_order_cancelled' );
		$this->assertNotFalse( $method_pos, 'on_order_cancelled debe existir.' );

		$method_block = substr( $source, $method_pos, 9000 );

		$this->assertStringContainsString(
			'var_export( $reversed, true )',
			$method_block,
			'RL-026: log debe usar var_export($reversed, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar que los debits ya se aplicaron (razon por
	 * la que el fallo del status update es critico - el wallet ya
	 * debitó, no hay rollback).
	 */
	public function test_on_order_cancelled_log_mentions_debits_already_applied(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString(
			'Los debits YA se aplicaron',
			$source,
			'RL-026: log debe mencionar que los debits YA se aplicaron (razon por la que el fallo es critico - no hay rollback del wallet).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual con la tabla
	 * lt_redi_commissions y el campo status='reversed'.
	 *
	 * Nota: el source usa `SET status=\\'reversed\\'` (backslash-quote
	 * de PHP). Buscar el substring literal crudo.
	 */
	public function test_on_order_cancelled_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString(
			"UPDATE %slt_redi_commissions SET status=\\'reversed\\' WHERE id=%d",
			$source,
			'RL-026: log debe incluir SQL de reconciliacion manual UPDATE lt_redi_commissions.'
		);

		$this->assertStringContainsString(
			'lt_redi_commissions',
			$source,
			'RL-026: SQL de reconciliacion debe mencionar lt_redi_commissions.'
		);
	}

	/**
	 * El fix tag CICLO11-P1-RL-026 FIX debe estar presente.
	 */
	public function test_rl026_fix_tag_present(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString( 'CICLO11-P1-RL-026 FIX', $source );
	}

	// -- RL-027 P1: create_notification INSERT verifica false/0 ------

	/**
	 * El INSERT debe capturarse en $notif_result (no ser llamada
	 * statement suelta).
	 */
	public function test_create_notification_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function create_notification' );
		$this->assertNotFalse( $method_pos, 'create_notification debe existir.' );

		// create_notification mide ~3,000 bytes post-fix.
		$method_block = substr( $source, $method_pos, 3500 );

		$this->assertStringContainsString(
			'$notif_result = $wpdb->insert(',
			$method_block,
			'RL-027: el INSERT debe capturar su retorno en $notif_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $notif_result ||
	 * 0 === (int) $notif_result dentro de create_notification.
	 */
	public function test_create_notification_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function create_notification' );
		$this->assertNotFalse( $method_pos, 'create_notification debe existir.' );

		$method_block = substr( $source, $method_pos, 3500 );

		$this->assertStringContainsString(
			'false === $notif_result || 0 === (int) $notif_result',
			$method_block,
			'RL-027: check explicito false === $notif_result || 0 === (int) $notif_result debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_NOTIFICATION_INSERT_FAILED.
	 */
	public function test_create_notification_failure_logs_critical(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function create_notification' );
		$this->assertNotFalse( $method_pos, 'create_notification debe existir.' );

		$method_block = substr( $source, $method_pos, 3500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_NOTIFICATION_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RL-027: log REDI_NOTIFICATION_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RL-027: el log debe ser critico (no warning), fallo de notificacion in-app al origin vendor.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB
	 * con last_error) de 0 (no rows sin error reported).
	 */
	public function test_create_notification_log_uses_var_export(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$method_pos = strpos( $source, 'function create_notification' );
		$this->assertNotFalse( $method_pos, 'create_notification debe existir.' );

		$method_block = substr( $source, $method_pos, 3500 );

		$this->assertStringContainsString(
			'var_export( $notif_result, true )',
			$method_block,
			'RL-027: log debe usar var_export($notif_result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar que el vendor no recibira alerta in-app
	 * (razon por la que el fallo es critico - el origin vendor no
	 * sabra que debe enviar el producto).
	 */
	public function test_create_notification_log_mentions_no_alert(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString(
			'El vendor no recibira alerta in-app',
			$source,
			'RL-027: log debe mencionar que el vendor no recibira alerta in-app (consequencia critica del fallo del INSERT).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual con la tabla
	 * lt_notifications y los campos relevantes.
	 *
	 * Nota: el source usa `\'%s\'` (backslash-quote de PHP). Buscar
	 * el substring literal crudo.
	 */
	public function test_create_notification_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString(
			'INSERT INTO %slt_notifications',
			$source,
			'RL-027: log debe incluir SQL de reconciliacion manual (INSERT INTO %slt_notifications...).'
		);

		$this->assertStringContainsString(
			'lt_notifications',
			$source,
			'RL-027: SQL de reconciliacion debe mencionar lt_notifications.'
		);
	}

	/**
	 * El fix tag CICLO11-P1-RL-027 FIX debe estar presente.
	 */
	public function test_rl027_fix_tag_present(): void {
		$this->assertFileExists( self::RL_PATH );
		$source = file_get_contents( self::RL_PATH );

		$this->assertStringContainsString( 'CICLO11-P1-RL-027 FIX', $source );
	}
}
