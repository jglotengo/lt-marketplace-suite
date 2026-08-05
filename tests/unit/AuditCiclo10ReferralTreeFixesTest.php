<?php
/**
 * AuditCiclo10ReferralTreeFixesTest - Tests para los fixes P1 del Ciclo 10.
 *
 * Cubre los fixes aplicados a class-ltms-referral-tree.php
 * (red de referidos MLM + distribute_commissions):
 *   - RT-019 P1: register_node() - el $wpdb->insert en
 *     lt_referral_network se capturaba en $inserted pero el check
 *     `if ( $inserted )` colapsaba false (error DB) y 0 (no rows,
 *     teorico de tabla corrupta o schema drift) en el mismo branch
 *     silencioso - NO habia log critico del fallo. Si el INSERT
 *     fallaba por error DB (p.ej. DB lock, deadlock tras re-fire del
 *     hook de registro), el `register_node` retorna `false`
 *     silenciosamente y el caller no sabia si fue: (a) sponsor code
 *     invalido, (b) self-reference, (c) ciclo, o (d) fallo DB. El
 *     nodo MLM no se crea -> distribute_commissions futuras para
 *     este vendor retoman el sponsor chain vacio ([] -> no paga a
 *     nadie) -> vendor pierde comisiones MLM para siempre (no hay
 *     re-intento, no hay reconciliacion manual documentada). El nodo
 *     es la base de toda la red MLM.
 *     Fix: verificacion explicita false === $inserted (log critico
 *     REFERRAL_NODE_INSERT_FAILED con SQL de reconciliacion manual
 *     + last_error + var_export) y 0 === (int) $inserted (log info
 *     REFERRAL_NODE_INSERT_NO_ROWS para distinguir el caso). Mismo
 *     patron verificado que los fixes de los Ciclos 5/6/7/8/9
 *     (alegra-sync, shipping-ledger, order-split, consumer-
 *     protection, legal-compliance).
 *
 * Hallazgos descartados documentados como backlog:
 *   - RT-020 P1 descartado: distribute_commissions tenia try/catch
 *     Throwable pero se pensaba que credit() podia retornar false/0
 *     silenciosamente. Confirmado contrato de LTMS_Business_Wallet::
 *     execute_transaction(): retorna (int) >= 1 en exito o idempotency
 *     hit (linea 394 retorna existing_tx_id), lanza Throwable en fallo
 *     (linea 691 throw $e). NUNCA retorna false ni 0. El try/catch
 *     actual en distribute_commissions ya captura los fallos. No fix.
 *   - RT-021 P2 backlog: get_vendor_by_code podria matchear users
 *     sin ltms_referral_code en algunos setups de WP. Defensive,
 *     caller ya sanitiza.
 *   - RT-022 P2 backlog: register_node idempotency return true
 *     silenciosamente si el nodo existe con sponsor distinto (re-
 *     parenting admin). Caso edge.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers RT-019
 */
class AuditCiclo10ReferralTreeFixesTest extends LTMS_Unit_Test_Case {

	private const RT_PATH = __DIR__ . '/../../includes/business/class-ltms-referral-tree.php';

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

	// -- RT-019 P1: register_node verifica INSERT false || 0 ---------

	/**
	 * Debe haber verificacion explicita false === $inserted (no solo
	 * `if ( $inserted )` que colapsa false/0).
	 */
	public function test_register_node_checks_false_explicitly(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		// Localizar dentro de register_node (mide ~8,900 bytes post-fix).
		$method_pos = strpos( $source, 'function register_node' );
		$this->assertNotFalse( $method_pos, 'register_node debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'false === $inserted',
			$method_block,
			'RT-019: check explicito false === $inserted debe estar presente (distingue error DB de 0 rows).'
		);
	}

	/**
	 * Debe haber verificacion explicita 0 === (int) $inserted (no rows).
	 */
	public function test_register_node_checks_zero_explicitly(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$method_pos = strpos( $source, 'function register_node' );
		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'0 === (int) $inserted',
			$method_block,
			'RT-019: check explicito 0 === (int) $inserted debe estar presente (no rows - tabla corrupta o schema drift).'
		);
	}

	/**
	 * En fallo (false), debe loguear critico REFERRAL_NODE_INSERT_FAILED.
	 */
	public function test_register_node_failure_false_logs_critical(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$method_pos = strpos( $source, 'function register_node' );
		$method_block = substr( $source, $method_pos, 9500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REFERRAL_NODE_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RT-019: log REFERRAL_NODE_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RT-019: el log de false debe ser critico (no warning), fallo de nodo MLM base.'
		);
	}

	/**
	 * En 0 rows, debe loguear info REFERRAL_NODE_INSERT_NO_ROWS (no
	 * critico - caso teorico de tabla corrupta, no fallo DB).
	 */
	public function test_register_node_zero_rows_logs_info(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$this->assertStringContainsString(
			'REFERRAL_NODE_INSERT_NO_ROWS',
			$source,
			'RT-019: log info REFERRAL_NODE_INSERT_NO_ROWS para 0 rows (teorico tabla corrupta).'
		);

		// Verificar que el log es info (no critical ni warning).
		$method_pos = strpos( $source, 'function register_node' );
		$method_block = substr( $source, $method_pos, 9500 );

		$token_pos = strpos( $method_block, "'REFERRAL_NODE_INSERT_NO_ROWS'," );
		$this->assertNotFalse( $token_pos, 'RT-019: token REFERRAL_NODE_INSERT_NO_ROWS debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::info(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::info(',
			$before_token,
			'RT-019: el log de 0 rows debe ser info (no critico), caso teorico no fallo DB.'
		);
	}

	/**
	 * El log critico debe usar var_export para distinguir false (error
	 * DB con last_error) de 0 (no rows sin error reported).
	 */
	public function test_register_node_log_uses_var_export(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$method_pos = strpos( $source, 'function register_node' );
		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'var_export( $inserted, true )',
			$method_block,
			'RT-019: log debe usar var_export($inserted, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log critico debe mencionar la consecuencia: el vendor pierde
	 * comisiones MLM futuras hasta reconciliacion manual.
	 */
	public function test_register_node_log_mentions_consequence(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$this->assertStringContainsString(
			'pierde todas las comisiones MLM',
			$source,
			'RT-019: log critico debe mencionar consecuencia (vendor pierde comisiones MLM futuras) - alerta al admin.'
		);
	}

	/**
	 * El log critico debe incluir SQL de reconciliacion manual
	 * (INSERT INTO... para que el admin pueda reparar el nodo).
	 */
	public function test_register_node_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$this->assertStringContainsString(
			'INSERT INTO',
			$source,
			'RT-019: log debe incluir SQL de reconciliacion manual (INSERT INTO...).'
		);

		$this->assertStringContainsString(
			'lt_referral_network',
			$source,
			'RT-019: SQL de reconciliacion debe mencionar lt_referral_network.'
		);

		// Debe incluir los campos del INSERT para que el admin pueda
		// reconstruir el nodo manualmente.
		$this->assertStringContainsString(
			'ancestor_path',
			$source,
			'RT-019: SQL debe mencionar ancestor_path (campo critico para el nodo MLM).'
		);
	}

	/**
	 * En fallo (false o 0), debe retornar false explicito (no
	 * `(bool) $inserted` que colapsa ambos casos).
	 */
	public function test_register_node_returns_false_explicitly_on_failure(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$method_pos = strpos( $source, 'function register_node' );
		$method_block = substr( $source, $method_pos, 9500 );

		// NO debe usar el patron viejo `return (bool) $inserted`.
		$this->assertStringNotContainsString(
			'return (bool) $inserted;',
			$method_block,
			'RT-019: NO debe usar `return (bool) $inserted;` (patron viejo que colapsa false/0).'
		);
	}

	// -- Fix tags de trazabilidad ------------------------------------------

	/**
	 * El fix del Ciclo 10 debe estar marcado con su ID en el codigo.
	 */
	public function test_fix_tag_present_in_referral_tree(): void {
		$this->assertFileExists( self::RT_PATH );
		$source = file_get_contents( self::RT_PATH );

		$this->assertStringContainsString( 'CICLO10-P1-RT-019 FIX', $source );
	}
}
