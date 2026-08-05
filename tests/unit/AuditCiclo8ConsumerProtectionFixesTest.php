<?php
/**
 * AuditCiclo8ConsumerProtectionFixesTest - Tests para los fixes P1 del Ciclo 8.
 *
 * Cubre los fixes aplicados a class-ltms-business-consumer-protection.php
 * (hold/release de comisiones + disputas consumer protection):
 *   - CP-011 P1: on_shipping_delivered() - el $wpdb->update que
 *     reposiciona release_at al confirmar entrega del shipping no se
 *     verificaba. Si fallaba silenciosamente, el cron
 *     release_eligible_holds posterior usaba el release_at ANTIGUO
 *     (calculado desde fecha de pago, no desde fecha de entrega) y
 *     liberaba el hold antes de que venza la ventana legal post-entrega
 *     (Ley 1480 CO, PROFECO MX) - el vendor cobra antes del plazo de
 *     proteccion al consumidor.
 *     Fix: capturar $delivered_updated + verificar false (log critico
 *     con SQL de reconciliacion manual) y 0 (log info para trazabilidad).
 *   - CP-012 P1: freeze_hold_for_dispute() - el retorno
 *     `(bool) $wpdb->update(...)` colapsaba false (error DB) y 0 (hold
 *     ya frozen o inexistente) ambos en false. El caller (file_dispute,
 *     on_shipping_failed) no sabia si reintentar (error DB) o skip (hold
 *     ausente). Si el INSERT de la disputa procedia con hold_frozen=false
 *     cuando el freeze fallo por error DB, el hold seguia 'held' y la
 *     ventana de dispute expiraba silenciosamente - vendor cobra mientras
 *     el cliente piensa que tiene proteccion.
 *     Fix: capturar $frozen + distinguir false (log critical con SQL de
 *     reconciliacion) de 0 (log info). Mismo patron que CP-011.
 *   - CP-013 P1: unfreeze_hold_for_dispute() - mismo patron que CP-012.
 *     Si el unfreeze fallaba por error DB, el hold quedaba frozen - el
 *     cron release_eligible_holds NO lo liberaba (filtra por status='held')
 *     - el vendor quedaba sin cobrar aunque la disputa fue rechazada a su
 *     favor.
 *     Fix: capturar $unfrozen + distinguir false (log critical con SQL
 *     de reconciliacion) de 0 (log info).
 *   - CP-014 P1: review_dispute() - el check existente `if ( ! $updated )`
 *     cubria false (error DB) y 0 (fila inexistente o ya under_review)
 *     correctamente, pero colapsa ambos en un solo WP_Error
 *     'invalid_dispute' sin distinguir el caso en el log. El caller no
 *     sabe si reintentar (error DB) o skip (fila ya bajo review).
 *     Fix: capturar $updated explicitamente + distinguir false (log
 *     critical con last_error) de 0 (log info) en logs separados. El
 *     WP_Error retornado sigue siendo el mismo (backward compatible).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CP-011, CP-012, CP-013, CP-014
 */
class AuditCiclo8ConsumerProtectionFixesTest extends LTMS_Unit_Test_Case {

	private const CP_PATH = __DIR__ . '/../../includes/business/class-ltms-business-consumer-protection.php';

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

	// -- CP-011 P1: on_shipping_delivered verifica UPDATE release_at ------

	/**
	 * El UPDATE de release_at debe capturarse en $delivered_updated.
	 */
	public function test_on_shipping_delivered_captures_update_in_delivered_updated(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'$delivered_updated = $wpdb->update(',
			$source,
			'CP-011: el UPDATE de release_at debe capturar su retorno en $delivered_updated.'
		);
	}

	/**
	 * En fallo (=== false), debe loguear critico
	 * HOLD_DELIVERY_RELEASE_AT_UPDATE_FAILED con SQL de reconciliacion.
	 */
	public function test_on_shipping_delivered_failure_logs_critical_with_sql(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_DELIVERY_RELEASE_AT_UPDATE_FAILED',
			$source,
			'CP-011: log critico HOLD_DELIVERY_RELEASE_AT_UPDATE_FAILED debe estar presente.'
		);

		// Debe incluir la sentencia SQL de reconciliacion manual.
		$this->assertStringContainsString(
			"UPDATE %s SET release_at=\'%s\' WHERE order_id=%d AND status=\'held\'",
			$source,
			'CP-011: log debe incluir la sentencia SQL de reconciliacion manual.'
		);

		// Debe mencionar que el cron usara el release_at ANTIGUO.
		$this->assertStringContainsString(
			'release_at ANTIGUO',
			$source,
			'CP-011: log debe mencionar que el cron usara el release_at ANTIGUO.'
		);
	}

	/**
	 * En 0 rows, debe loguear info HOLD_DELIVERY_RELEASE_AT_NO_MATCH.
	 */
	public function test_on_shipping_delivered_zero_rows_logs_info(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_DELIVERY_RELEASE_AT_NO_MATCH',
			$source,
			'CP-011: log info HOLD_DELIVERY_RELEASE_AT_NO_MATCH para 0 rows.'
		);

		// Debe distinguir explicitamente el caso: hold ya released o frozen.
		$this->assertStringContainsString(
			'hold en status distinto a held',
			$source,
			'CP-011: log debe mencionar que hold esta en status distinto a held.'
		);
	}

	// -- CP-012 P1: freeze_hold_for_dispute distinguir false de 0 --------

	/**
	 * freeze_hold_for_dispute NO debe usar `(bool) $wpdb->update(...)`.
	 * Debe capturar el retorno en $frozen y distinguir false de 0.
	 */
	public function test_freeze_hold_captures_return_in_frozen_variable(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		// Localizar dentro de freeze_hold_for_dispute.
		$method_pos = strpos( $source, 'function freeze_hold_for_dispute' );
		$this->assertNotFalse( $method_pos, 'freeze_hold_for_dispute debe existir.' );

		$method_block = substr( $source, $method_pos, 3000 );

		$this->assertStringContainsString(
			'$frozen = $wpdb->update(',
			$method_block,
			'CP-012: UPDATE debe capturar su retorno en $frozen (no usar (bool) directo).'
		);

		// NO debe usar el patron viejo `(bool) $wpdb->update(`.
		$this->assertStringNotContainsString(
			'return (bool) $wpdb->update(',
			$method_block,
			'CP-012: NO debe usar `return (bool) $wpdb->update(...)` (patron viejo sin distincion false/0).'
		);
	}

	/**
	 * En fallo (false), debe loguear critico HOLD_FREEZE_UPDATE_FAILED
	 * con SQL de reconciliacion.
	 */
	public function test_freeze_hold_failure_logs_critical_with_sql(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_FREEZE_UPDATE_FAILED',
			$source,
			'CP-012: log critico HOLD_FREEZE_UPDATE_FAILED debe estar presente.'
		);

		// Debe incluir la sentencia SQL de reconciliacion manual.
		$this->assertStringContainsString(
			"UPDATE %s SET status=\'frozen\', freeze_reason=\'%s\' WHERE order_id=%d AND status=\'held\'",
			$source,
			'CP-012: log debe incluir la sentencia SQL de reconciliacion manual.'
		);
	}

	/**
	 * En 0 rows, debe loguear info HOLD_FREEZE_NO_MATCH.
	 */
	public function test_freeze_hold_zero_rows_logs_info(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_FREEZE_NO_MATCH',
			$source,
			'CP-012: log info HOLD_FREEZE_NO_MATCH para 0 rows.'
		);
	}

	// -- CP-013 P1: unfreeze_hold_for_dispute distinguir false de 0 ------

	/**
	 * unfreeze_hold_for_dispute NO debe usar `(bool) $wpdb->update(...)`.
	 * Debe capturar el retorno en $unfrozen y distinguir false de 0.
	 */
	public function test_unfreeze_hold_captures_return_in_unfrozen_variable(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$method_pos = strpos( $source, 'function unfreeze_hold_for_dispute' );
		$this->assertNotFalse( $method_pos, 'unfreeze_hold_for_dispute debe existir.' );

		$method_block = substr( $source, $method_pos, 3000 );

		$this->assertStringContainsString(
			'$unfrozen = $wpdb->update(',
			$method_block,
			'CP-013: UPDATE debe capturar su retorno en $unfrozen (no usar (bool) directo).'
		);

		// NO debe usar el patron viejo `(bool) $wpdb->update(`.
		$this->assertStringNotContainsString(
			'return (bool) $wpdb->update(',
			$method_block,
			'CP-013: NO debe usar `return (bool) $wpdb->update(...)` (patron viejo sin distincion false/0).'
		);
	}

	/**
	 * En fallo (false), debe loguear critico HOLD_UNFREEZE_UPDATE_FAILED.
	 */
	public function test_unfreeze_hold_failure_logs_critical(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_UNFREEZE_UPDATE_FAILED',
			$source,
			'CP-013: log critico HOLD_UNFREEZE_UPDATE_FAILED debe estar presente.'
		);

		// Debe mencionar que el cron NO liberara el hold (filtra por status=held).
		$this->assertStringContainsString(
			'cron no lo liberara',
			$source,
			'CP-013: log debe mencionar que el cron no lo liberara (filtra por status=held).'
		);
	}

	/**
	 * En 0 rows, debe loguear info HOLD_UNFREEZE_NO_MATCH.
	 */
	public function test_unfreeze_hold_zero_rows_logs_info(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'HOLD_UNFREEZE_NO_MATCH',
			$source,
			'CP-013: log info HOLD_UNFREEZE_NO_MATCH para 0 rows.'
		);
	}

	// -- CP-014 P1: review_dispute distinguir false de 0 -----------------

	/**
	 * review_dispute debe distinguir false (error DB) de 0 (no rows) en
	 * logs separados. NO debe usar el check `if ( ! $updated )` solo.
	 */
	public function test_review_dispute_distinguishes_false_and_zero(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		// Localizar dentro de review_dispute.
		$method_pos = strpos( $source, 'function review_dispute' );
		$this->assertNotFalse( $method_pos, 'review_dispute debe existir.' );

		// Tomar el metodo completo (~3,000 bytes).
		$method_block = substr( $source, $method_pos, 3000 );

		$this->assertStringContainsString(
			'false === $updated',
			$method_block,
			'CP-014: check explicito false === $updated debe estar presente.'
		);

		$this->assertStringContainsString(
			'0 === (int) $updated',
			$method_block,
			'CP-014: check explicito 0 === (int) $updated debe estar presente.'
		);
	}

	/**
	 * En fallo (false), debe loguear critico DISPUTE_REVIEW_UPDATE_FAILED.
	 */
	public function test_review_dispute_failure_logs_critical(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'DISPUTE_REVIEW_UPDATE_FAILED',
			$source,
			'CP-014: log critico DISPUTE_REVIEW_UPDATE_FAILED debe estar presente.'
		);

		// Debe mencionar que reintento es posible.
		$this->assertStringContainsString(
			'reintento posible',
			$source,
			'CP-014: log debe mencionar que reintento es posible.'
		);
	}

	/**
	 * En 0 rows, debe loguear info DISPUTE_REVIEW_NO_MATCH.
	 */
	public function test_review_dispute_zero_rows_logs_info(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString(
			'DISPUTE_REVIEW_NO_MATCH',
			$source,
			'CP-014: log info DISPUTE_REVIEW_NO_MATCH para 0 rows.'
		);

		// Debe mencionar resolucion concurrente como posible causa.
		$this->assertStringContainsString(
			'resolucion concurrente',
			$source,
			'CP-014: log debe mencionar resolucion concurrente como posible causa.'
		);
	}

	// -- Fix tags de trazabilidad ------------------------------------------

	/**
	 * Todos los fixes del Ciclo 8 deben estar marcados con sus IDs en
	 * el codigo fuente.
	 */
	public function test_fix_tags_present_in_consumer_protection(): void {
		$this->assertFileExists( self::CP_PATH );
		$source = file_get_contents( self::CP_PATH );

		$this->assertStringContainsString( 'CICLO8-P1-CP-011 FIX', $source );
		$this->assertStringContainsString( 'CICLO8-P1-CP-012 FIX', $source );
		$this->assertStringContainsString( 'CICLO8-P1-CP-013 FIX', $source );
		$this->assertStringContainsString( 'CICLO8-P1-CP-014 FIX', $source );
	}
}
