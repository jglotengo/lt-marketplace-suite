<?php
/**
 * AuditCiclo12TptcListenerFixesTest - Tests para los fixes del Ciclo 12.
 *
 * Cubre los fixes aplicados a
 * includes/business/listeners/class-ltms-tptc-listener.php
 * (listener TPTC - Te Paga Tus Compras - on_order_paid +
 * on_order_refunded). Cross-check del patron H-5 FIX del Ciclo 11
 * (ReDi listener), mismo modulo listeners.
 *
 *   - TL-028 P1: on_order_paid() catch - el UPDATE de reset del
 *     flag _ltms_tptc_synced a '0' para permitir retry tras fallo
 *     transitorio no se verificaba. Mismo patron que P1-RL-025
 *     del Ciclo 11 (ReDi listener). Si el reset fallaba
 *     silenciosamente (false = error DB con last_error, 0 = no
 *     rows por schema drift), el flag quedaba en '1' y el retry
 *     nunca ocurria -> el pedido quedaba sin sincronizar con TPTC
 *     para siempre (puntos/comisiones TPTC nunca se acreditan
 *     al vendor, compliance contable TPTC pendiente
 *     permanentemente, remaindere de la traza regulatoria del
 *     operador TPTC S.A.S.).
 *     Fix: captura $reset_result + verificacion explicita
 *     false === $reset_result (log critico
 *     TPTC_SYNC_FLAG_RESET_FAILED con SQL de reconciliacion
 *     manual UPDATE postmeta + var_export + last_error) y
 *     0 === (int) $reset_result (mismo log critico, caso
 *     teorico de tabla corrupta). Patron recurrente Ciclos 5-11.
 *   - TL-029 P1: on_order_refunded() catch - mismo patron que
 *     TL-028 en el reset del claim _ltms_tptc_reversed. Si el
 *     reset fallaba silenciosamente, el claim quedaba en '1' y
 *     el retry de la reversión nunca ocurria -> la reversión
 *     TPTC quedaba pendiente para siempre (puntos/comisiones NO
 *     reversados al vendor en TPTC, inconsistencia contable
 *     permanente: el reembolso se proceso en WooCommerce pero
 *     TPTC sigue mostrando la venta como activa).
 *     Fix: captura $reset_reversal + verificacion explicita
 *     false === $reset_reversal (log critico
 *     TPTC_REVERSAL_FLAG_RESET_FAILED con SQL de reconciliacion
 *     manual + var_export + last_error + mencion "TPTC sigue
 *     mostrando la venta como activa") y
 *     0 === (int) $reset_reversal. Mismo patron.
 *   - TL-030 P1 (reclasificado desde P2): reset del claim
 *     _ltms_tptc_reversed en el path defense-in-depth de
 *     on_order_refunded cuando el pedido NO estaba synced. Si el
 *     reset fallaba silenciosamente, el claim quedaba en '1'
 *     aunque la reversión NO ocurrio. Si el pedido se sincronizaba
 *     con TPTC mas tarde y entonces se reembolsaba, una nueva
 *     ejecucion de on_order_refunded salia por el early-return
 *     "Already reversed by another process" sin aplicar
 *     reverse_sale -> la venta TPTC quedaba activa tras
 *     reembolso hechos posteriores, inconsistencia contable.
 *     Reclasificado P2 -> P1 porque la consequence real SI es
 *     P1 (bloquea retroactivamente la reversión futura).
 *     Fix: captura $reset_not_synced + verificacion explicita
 *     false === $reset_not_synced (log critico
 *     TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED con SQL de
 *     reconciliacion manual + var_export + last_error + mencion
 *     del escenario de race retroactivo).
 *
 * Hallazgos descartados:
 *   - Doble invocacion check (P0-RL-024 del Ciclo 11): confirmado
 *     que NO se replica en TPTC listener. sync_sale() se invoca
 *     una sola vez dentro del try (linea 95), no hay llamadas
 *     sueltas fuera del try/catch. El bug P0-RL-024 era especifico
 *     al commit "Ciclo 1.5" del ReDi listener, no al patron H-5
 *     FIX generalizado.
 *   - API client nulo (linea 89): si LTMS_Api_Factory::get('tptc')
 *     retorna null, $client->sync_sale() lanza TypeError caught
 *     por el catch. No P1 - el catch ya lo maneja y loguea el
 *     fallo. Caso edge de configuracion incompleta.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers TL-028, TL-029, TL-030
 */
class AuditCiclo12TptcListenerFixesTest extends LTMS_Unit_Test_Case {

	private const TL_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-tptc-listener.php';

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

	// -- TL-028 P1: on_order_paid reset flag en catch verifica -------

	/**
	 * El UPDATE de reset debe capturarse en $reset_result (no ser
	 * llamada statement suelta).
	 */
	public function test_on_order_paid_catch_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		// Localizar dentro de on_order_paid (mide ~8,000 bytes post-fix).
		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 8500 );

		$this->assertStringContainsString(
			'$reset_result = $wpdb->query(',
			$method_block,
			'TL-028: el UPDATE de reset debe capturar su retorno en $reset_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reset_result ||
	 * 0 === (int) $reset_result dentro de on_order_paid.
	 */
	public function test_on_order_paid_catch_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 8500 );

		$this->assertStringContainsString(
			'false === $reset_result || 0 === (int) $reset_result',
			$method_block,
			'TL-028: check explicito false === $reset_result || 0 === (int) $reset_result debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico TPTC_SYNC_FLAG_RESET_FAILED.
	 */
	public function test_on_order_paid_catch_failure_logs_critical(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 8500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'TPTC_SYNC_FLAG_RESET_FAILED'," );
		$this->assertNotFalse( $token_pos, 'TL-028: log TPTC_SYNC_FLAG_RESET_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'TL-028: el log debe ser critico (no warning), fallo combinado de sync + reset de flag.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB con
	 * last_error) de 0 (no rows sin error reported).
	 */
	public function test_on_order_paid_catch_log_uses_var_export(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 8500 );

		$this->assertStringContainsString(
			'var_export( $reset_result, true )',
			$method_block,
			'TL-028: log debe usar var_export($reset_result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar la consequence: el pedido queda sin
	 * sincronizar con TPTC para siempre (puntos/comisiones no se
	 * acreditan).
	 */
	public function test_on_order_paid_catch_log_mentions_consequence(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString(
			'sin sincronizar con TPTC para siempre',
			$source,
			'TL-028: log debe mencionar consequence (pedido sin sincronizar para siempre).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE
	 * postmeta con _ltms_tptc_synced.
	 *
	 * Nota: el source usa `\'0\'` (backslash-quote de PHP).
	 */
	public function test_on_order_paid_catch_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString(
			"UPDATE %spostmeta SET meta_value=\\'0\\' WHERE post_id=%d AND meta_key=\\'_ltms_tptc_synced\\'",
			$source,
			'TL-028: log debe incluir SQL de reconciliacion manual UPDATE postmeta con _ltms_tptc_synced.'
		);
	}

	/**
	 * El fix tag CICLO12-P1-TL-028 FIX debe estar presente.
	 */
	public function test_tl028_fix_tag_present(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString( 'CICLO12-P1-TL-028 FIX', $source );
	}

	// -- TL-029 P1: on_order_refunded reset claim en catch verifica --

	/**
	 * El UPDATE de reset debe capturarse en $reset_reversal.
	 */
	public function test_on_order_refunded_catch_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		// on_order_refunded mide ~9,300 bytes post-fix.
		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'$reset_reversal = $wpdb->query(',
			$method_block,
			'TL-029: el UPDATE de reset debe capturar su retorno en $reset_reversal (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reset_reversal
	 * || 0 === (int) $reset_reversal.
	 */
	public function test_on_order_refunded_catch_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'false === $reset_reversal || 0 === (int) $reset_reversal',
			$method_block,
			'TL-029: check explicito false === $reset_reversal || 0 === (int) $reset_reversal debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * TPTC_REVERSAL_FLAG_RESET_FAILED.
	 */
	public function test_on_order_refunded_catch_failure_logs_critical(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'TPTC_REVERSAL_FLAG_RESET_FAILED'," );
		$this->assertNotFalse( $token_pos, 'TL-029: log TPTC_REVERSAL_FLAG_RESET_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'TL-029: el log debe ser critico (no warning), fallo combinado de reversal + reset de claim.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false de 0.
	 */
	public function test_on_order_refunded_catch_log_uses_var_export(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'var_export( $reset_reversal, true )',
			$method_block,
			'TL-029: log debe usar var_export($reset_reversal, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar que TPTC sigue mostrando la venta como
	 * activa (consequence critica del fallo combinado de reversal +
	 * reset).
	 */
	public function test_on_order_refunded_catch_log_mentions_consequence(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString(
			'TPTC sigue mostrando la venta como activa',
			$source,
			'TL-029: log debe mencionar consequence (TPTC sigue mostrando la venta como activa).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual con
	 * _ltms_tptc_reversed.
	 */
	public function test_on_order_refunded_catch_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString(
			"UPDATE %spostmeta SET meta_value=\\'0\\' WHERE post_id=%d AND meta_key=\\'_ltms_tptc_reversed\\'",
			$source,
			'TL-029: log debe incluir SQL de reconciliacion manual UPDATE postmeta con _ltms_tptc_reversed.'
		);
	}

	/**
	 * El fix tag CICLO12-P1-TL-029 FIX debe estar presente.
	 */
	public function test_tl029_fix_tag_present(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString( 'CICLO12-P1-TL-029 FIX', $source );
	}

	// -- TL-030 P1: reset claim en path no-synced verifica ----------

	/**
	 * El UPDATE de reset debe capturarse en $reset_not_synced.
	 */
	public function test_on_order_refunded_not_synced_path_captures_update(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'$reset_not_synced = $wpdb->query(',
			$method_block,
			'TL-030: el UPDATE de reset en path no-synced debe capturar su retorno en $reset_not_synced.'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reset_not_synced
	 * || 0 === (int) $reset_not_synced.
	 */
	public function test_on_order_refunded_not_synced_path_checks_false_and_zero(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'false === $reset_not_synced || 0 === (int) $reset_not_synced',
			$method_block,
			'TL-030: check explicito false === $reset_not_synced || 0 === (int) $reset_not_synced debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED.
	 */
	public function test_on_order_refunded_not_synced_path_failure_logs_critical(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$token_pattern = "'TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED',";
		$this->assertStringContainsString(
			$token_pattern,
			$source,
			'TL-030: log TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED debe existir.'
		);

		// Verificar que es ::critical (no warning).
		$method_pos = strpos( $source, 'function on_order_refunded' );
		$method_block = substr( $source, $method_pos, 9500 );

		$token_pos = strpos( $method_block, $token_pattern );
		$this->assertNotFalse( $token_pos, 'TL-030: token TPTC_REVERSED_CLAIM_RESET_FAILED_NOT_SYNCED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'TL-030: el log debe ser critico, fallo combinado de reset de claim en path defense-in-depth.'
		);
	}

	/**
	 * El log debe mencionar el escenario de race retroactivo (pedido
	 * se sincroniza mas tarde y entonces se reembolsa -> early
	 * return sin reverse_sale).
	 */
	public function test_on_order_refunded_not_synced_path_mentions_race_scenario(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString(
			'saldra por early-return sin aplicar reverse_sale',
			$source,
			'TL-030: log debe mencionar el escenario de race retroactivo (early-return sin reverse_sale).'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false de 0.
	 */
	public function test_on_order_refunded_not_synced_path_log_uses_var_export(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_refunded' );
		$this->assertNotFalse( $method_pos, 'on_order_refunded debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'var_export( $reset_not_synced, true )',
			$method_block,
			'TL-030: log debe usar var_export($reset_not_synced, true) para distinguir false de 0.'
		);
	}

	/**
	 * El fix tag CICLO12-P1-TL-030 FIX debe estar presente.
	 */
	public function test_tl030_fix_tag_present(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$this->assertStringContainsString( 'CICLO12-P1-TL-030 FIX', $source );
	}

	// -- Cross-check: bug P0-RL-024 del Ciclo 11 NO se replica ------

	/**
	 * Cross-check con el bug P0-RL-024 del Ciclo 11 (ReDi listener):
	 * sync_sale() debe invocarse UNA sola vez (no llamadas sueltas
	 * fuera del try/catch). Confirmacion de que el patron H-5 FIX
	 * esta correctamente aplicado en TPTC listener.
	 */
	public function test_cross_check_no_double_invocation_like_ciclo11_p0(): void {
		$this->assertFileExists( self::TL_PATH );
		$source = file_get_contents( self::TL_PATH );

		$method_pos = strpos( $source, 'function on_order_paid' );
		$this->assertNotFalse( $method_pos, 'on_order_paid debe existir.' );

		$method_block = substr( $source, $method_pos, 8500 );

		// Contar invocaciones VIVAS de sync_sale( con firma
		// de array (no menciones en comentarios).
		$count = substr_count( $method_block, '$client->sync_sale( [' );
		$this->assertSame(
			1,
			$count,
			'Cross-check: sync_sale() debe invocarse UNA sola vez (recuento de invocaciones vivas: ' . $count . '. El bug P0-RL-024 del Ciclo 11 (doble invocacion) NO se replica en TPTC listener.'
		);

		// reverse_sale tambien una sola vez.
		$method_refund_pos = strpos( $source, 'function on_order_refunded' );
		$method_refund_block = substr( $source, $method_refund_pos, 9500 );
		$count_reversal = substr_count( $method_refund_block, '$client->reverse_sale( [' );
		$this->assertSame(
			1,
			$count_reversal,
			'Cross-check: reverse_sale() debe invocarse UNA sola vez (recuento de invocaciones vivas: ' . $count_reversal . '.'
		);
	}
}
