<?php
/**
 * AuditCiclo13CouponAttributionListenerFixesTest - Tests para los fixes del Ciclo 13.
 *
 * Cubre los fixes aplicados a
 * includes/business/listeners/class-ltms-coupon-attribution-listener.php
 * (listener coupon attribution + referral credit):
 *   - CA-031 P1: credit_referrer() catch - el UPDATE de reset
 *     del flag _ltms_referral_credited a '0' para permitir retry
 *     tras fallo transitorio no se verificaba. Mismo patron que
 *     P1-RL-025 (Ciclo 11, ReDi listener), P1-TL-028 (Ciclo 12,
 *     TPTC listener). Si el reset fallaba silenciosamente (false =
 *     error DB con last_error, 0 = no rows por schema drift), el
 *     flag quedaba en '1' y el retry nunca ocurria -> la comision
 *     de referido nunca se acreditaba al referrer para siempre
 *     (vendor pierde comision MLM de referido silenciosamente, sin
 *     alerta al admin, sin path de reconciliacion manual aunque el
 *     referrer sigue generando ventas que teoricamente generarian
 *     comisiones para el).
 *     Fix: captura $reset_result + verificacion explicita
 *     false === $reset_result (log critico
 *     REFERRAL_CREDIT_FLAG_RESET_FAILED con SQL de reconciliacion
 *     manual UPDATE postmeta + var_export + last_error + mencion
 *     "vendor pierde comision MLM de referido silenciosamente") y
 *     0 === (int) $reset_result (mismo log critico, caso teorico
 *     de tabla corrupta). Patron recurrente Ciclos 5-12 (9 ciclos
 *     consecutivos, mismo family listeners).
 *
 * Hallazgos descartados:
 *   - P0-RL-024 cross-check (Ciclo 11): confirmado NO REPLICADO.
 *     LTMS_Business_Wallet::credit() se invoca UNA sola vez dentro
 *     del try (linea 146). No hay llamadas sueltas fuera del
 *     try/catch. Cross-check validating que el bug P0-RL-024 es
 *     especifico al ReDi listener, NO al patron H-5 FIX
 *     generalizado - tercer listener consecutivo confirmado sin
 *     el bug (Ciclo 11 = si, Ciclo 12 = no, Ciclo 13 = no).
 *   - P2-CA-032 backlog: update_post_meta( $order_id,
 *     '_ltms_referrer_id', $referrer_id ) no se verifica (linea
 *     156). Es metadato de auditoria (no bloquea retry - el atomic
 *     claim + idempotency_key del Wallet ya previenen doble
 *     credito real). Defensive. Backlog documentado.
 *
 * Hallazgo especial del Ciclo 13: el archivo estaba
 * excelentemente hardenado en los paths principales (REG-BUG-1
 * FIX meta key 'ltms_referral_code' sin underscore, AFFILIATE-
 * AUDIT P0-1 FIX validacion de codigo alfanumerico, P1-1 FIX
 * verify referrer is vendor, P0-2 FIX cap comision a
 * configurable max, P0-3 FIX idempotency key, AUDIT-LISTENERS-
 * 001 P1-2 FIX Ciclo 1.5 atomic claim + try/catch reset). Solo
 * quedaba el patron recurrente P1 de verificacion de retorno
 * UPDATE en el catch de reset, mismo que el Ciclo 11 (ReDi) y
 * el Ciclo 12 (TPTC). Esto valida la auditoria del family
 * listeners como coherente - el patron H-5 FIX tiene el mismo
 * gap P1 en los 3 listeners del modulo.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CA-031
 */
class AuditCiclo13CouponAttributionListenerFixesTest extends LTMS_Unit_Test_Case {

	private const CA_PATH = __DIR__ . '/../../includes/business/listeners/class-ltms-coupon-attribution-listener.php';

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

	// -- CA-031 P1: credit_referrer reset flag en catch verifica ----

	/**
	 * El UPDATE de reset debe capturarse en $reset_result (no ser
	 * llamada statement suelta).
	 */
	public function test_credit_referrer_catch_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		// Localizar dentro de credit_referrer (mide ~7,700 bytes post-fix).
		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'$reset_result = $wpdb->query(',
			$method_block,
			'CA-031: el UPDATE de reset debe capturar su retorno en $reset_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $reset_result ||
	 * 0 === (int) $reset_result dentro de credit_referrer catch.
	 */
	public function test_credit_referrer_catch_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'false === $reset_result || 0 === (int) $reset_result',
			$method_block,
			'CA-031: check explicito false === $reset_result || 0 === (int) $reset_result debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REFERRAL_CREDIT_FLAG_RESET_FAILED.
	 */
	public function test_credit_referrer_catch_failure_logs_critical(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REFERRAL_CREDIT_FLAG_RESET_FAILED'," );
		$this->assertNotFalse( $token_pos, 'CA-031: log REFERRAL_CREDIT_FLAG_RESET_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'CA-031: el log debe ser critico (no warning), fallo combinado de credito + reset de flag.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB con
	 * last_error) de 0 (no rows sin error reported).
	 */
	public function test_credit_referrer_catch_log_uses_var_export(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'var_export( $reset_result, true )',
			$method_block,
			'CA-031: log debe usar var_export($reset_result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar la consequence: el vendor pierde
	 * comision MLM de referido silenciosamente (alerta al admin
	 * que el referrer sigue generando ventas pero no recibe
	 * comision).
	 */
	public function test_credit_referrer_catch_log_mentions_consequence(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$this->assertStringContainsString(
			'pierde comision MLM de referido silenciosamente',
			$source,
			'CA-031: log debe mencionar consequence (vendor pierde comision MLM de referido silenciosamente).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE postmeta
	 * con _ltms_referral_credited.
	 *
	 * Nota: el source usa `\'0\'` (backslash-quote de PHP).
	 */
	public function test_credit_referrer_catch_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$this->assertStringContainsString(
			"UPDATE %spostmeta SET meta_value=\\'0\\' WHERE post_id=%d AND meta_key=\\'_ltms_referral_credited\\'",
			$source,
			'CA-031: log debe incluir SQL de reconciliacion manual UPDATE postmeta con _ltms_referral_credited.'
		);
	}

	/**
	 * El fix tag CICLO13-P1-CA-031 FIX debe estar presente.
	 */
	public function test_ca031_fix_tag_present(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$this->assertStringContainsString( 'CICLO13-P1-CA-031 FIX', $source );
	}

	// -- Cross-check: bug P0-RL-024 del Ciclo 11 NO se replica ------

	/**
	 * Cross-check con el bug P0-RL-024 del Ciclo 11 (ReDi listener):
	 * LTMS_Business_Wallet::credit() debe invocarse UNA sola vez
	 * (no llamadas sueltas fuera del try/catch). Tercer listener
	 * consecutivo del family confirmado SIN el bug P0-RL-024,
	 * validando que el bug era especifico al commit "Ciclo 1.5" del
	 * ReDi listener.
	 */
	public function test_cross_check_no_double_invocation_like_ciclo11_p0(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		// Contar invocaciones VIVAS de Wallet::credit( con firma
		// completa (no menciones en comentarios).
		$count = substr_count( $method_block, 'LTMS_Business_Wallet::credit(' );
		$this->assertSame(
			1,
			$count,
			'Cross-check: Wallet::credit() debe invocarse UNA sola vez (recuento de invocaciones vivas: ' . $count . '. Bug P0-RL-024 del Ciclo 11 (doble invocacion) NO se replica en coupon attribution listener.'
		);
	}

	/**
	 * La invocacion de Wallet::credit() debe estar dentro de un
	 * bloque try { ... } catch.
	 *
	 * Estrategia: localizar la invocacion viva, luego localizar
	 * 'try {' en el method_block. Verificar que try { aparece
	 * BEFORE la invocacion (offset menor) Y que 'catch' aparece
	 * AFTER la invocacion (offset mayor) - confirmando que la
	 * invocacion esta dentro del try/catch.
	 */
	public function test_cross_check_wallet_credit_inside_try(): void {
		$this->assertFileExists( self::CA_PATH );
		$source = file_get_contents( self::CA_PATH );

		$method_pos = strpos( $source, 'function credit_referrer' );
		$this->assertNotFalse( $method_pos, 'credit_referrer debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		// Localizar la invocacion viva de Wallet::credit(.
		$invoke_pos = strpos( $method_block, 'LTMS_Business_Wallet::credit(' );
		$this->assertNotFalse( $invoke_pos, 'La invocacion viva de Wallet::credit() debe existir.' );

		// Localizar 'try {' en el method_block.
		$try_pos = strpos( $method_block, 'try {' );
		$this->assertNotFalse( $try_pos, 'Debe existir un try { en credit_referrer.' );

		// Localizar el catch relativo al try (primer 'catch' despues del try).
		$catch_pos = strpos( $method_block, 'catch ( \Throwable', $try_pos );
		$this->assertNotFalse( $catch_pos, 'Debe existir un catch (Throwable despues del try.' );

		// Validar orden: try < invoke < catch.
		$this->assertLessThan(
			$invoke_pos,
			$try_pos,
			'Cross-check: la invocacion viva de Wallet::credit() debe estar DESPUES del try { (dentro del try/catch).'
		);
		$this->assertGreaterThan(
			$invoke_pos,
			$catch_pos,
			'Cross-check: la invocacion viva de Wallet::credit() debe estar ANTES del catch (dentro del try/catch).'
		);
	}
}
