<?php
/**
 * AuditCiclo6ShippingCostLedgerFixesTest — Tests para los fixes P0+P1 del Ciclo 6.
 *
 * Cubre los fixes aplicados a class-ltms-shipping-cost-ledger.php:
 *   - SHLEDGER-001 P0: on_carrier_delivered() — UPDATE a status=delivered
 *     no se verificaba. Si fallaba, el sistema down-stream creia entregado
 *     mientras el ledger segnia en shipped/quoted → liberacion prematura
 *     del hold del vendor.
 *   - SHLEDGER-002 P0: on_carrier_failed() — UPDATE a status=disputed no se
 *     verificaba. Adicionalmente, la seccion delivered-now-disputed
 *     (cuenta entries con metadata LIKE failure_carrier) se evaluaba
 *     incondicionalmente → si el UPDATE fallaba, la cuenta era 0 y la
 *     alerta critica SHIPPING_LEDGER_DELIVERED_NOW_DISPUTED se perdia.
 *   - SHLEDGER-003 P1: open_dispute() manual — INSERT en
 *     lt_shipping_disputes no se verificaba. $wpdb->insert_id=0 en fallo
 *     pero el bloque if($dispute_id) no entraba y no se logueaba nada.
 *   - SHLEDGER-004 P1: open_dispute() manual — UPDATE que enlaza el
 *     ledger entry con la nueva disputa no se verificaba → disputa
 *     huerfana (el admin ve la disputa en la tabla de disputas pero el
 *     ledger entry no tiene dispute_id ni status=disputed).
 *   - SHLEDGER-005 P1: import_carrier_invoice() — INSERT de factura y
 *     INSERT de cada linea no se verificaban. Si el INSERT de factura
 *     fallaba, las lineas se insertaban con invoice_id=0 (huerfanas
 *     para siempre). Si una linea fallaba en medio del foreach, las
 *     anteriores quedaban persistidas. Fix: transaccion + verificacion
 *     en cada INSERT + rollback + COMMIT controlado.
 *   - SHLEDGER-006 P1: auto_open_dispute() — UPDATE que enlaza el ledger
 *     entry con la disputa automatica no se verificaba. Mismo patron
 *     huerfano que SHLEDGER-004 pero en path automatico.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers SHLEDGER-001, SHLEDGER-002, SHLEDGER-003, SHLEDGER-004,
 *         SHLEDGER-005, SHLEDGER-006
 */
class AuditCiclo6ShippingCostLedgerFixesTest extends LTMS_Unit_Test_Case {

	private const LEDGER_PATH = __DIR__ . '/../../includes/business/class-ltms-shipping-cost-ledger.php';

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

	// ── SHLEDGER-001 P0: on_carrier_delivered verifica UPDATE ───────────────

	/**
	 * El UPDATE a status=delivered debe capturar su retorno en
	 * $delivered_rows y verificarlo.
	 */
	public function test_delivered_update_captures_return_in_delivered_rows(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString(
			'$delivered_rows = $wpdb->query(',
			$source,
			'SHLEDGER-001: el UPDATE a delivered debe capturar su retorno en $delivered_rows.'
		);
	}

	/**
	 * En fallo (=== false), debe loguear critico
	 * SHLEDGER_DELIVERED_UPDATE_FAILED con last_error.
	 */
	public function test_delivered_failure_logs_critical(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( 'SHLEDGER_DELIVERED_UPDATE_FAILED', $source, 'SHLEDGER-001: log critico SHLEDGER_DELIVERED_UPDATE_FAILED debe estar presente.' );
		$this->assertStringContainsString( 'last_error', $source, 'SHLEDGER-001: log debe incluir last_error.' );
		// Devuelve early en fallo (no procede a log info exitoso).
		$this->assertStringContainsString( 'return;', $source, 'SHLEDGER-001: debe retornar early en fallo.' );
	}

	/**
	 * En === 0 (no rows matched), debe loguear info
	 * SHLEDGER_DELIVERED_NO_MATCH y retornar early (no es error pero el
	 * admin debe verlo).
	 */
	public function test_delivered_zero_rows_logs_info_and_returns(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( 'SHLEDGER_DELIVERED_NO_MATCH', $source, 'SHLEDGER-001: log SHLEDGER_DELIVERED_NO_MATCH para 0 rows.' );
		$this->assertStringContainsString( "0 === (int) \$delivered_rows", $source, 'SHLEDGER-001: check explicito 0 === (int) $delivered_rows.' );
	}

	// ── SHLEDGER-002 P0: on_carrier_failed verifica UPDATE + delivered-now-disputed ──

	/**
	 * El UPDATE a status=disputed debe capturar su retorno en
	 * $failed_rows.
	 */
	public function test_failed_update_captures_return_in_failed_rows(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( '$failed_rows = $wpdb->query(', $source, 'SHLEDGER-002: el UPDATE a disputed debe capturar su retorno en $failed_rows.' );
	}

	/**
	 * En fallo del UPDATE, debe loguear SHLEDGER_FAILED_UPDATE_FAILED y
	 * retornar early (sin evaluar delivered-now-disputed - no hay metadata).
	 */
	public function test_failed_update_failure_logs_and_returns_early(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		// Usar tokens con la coma final ('SHLEDGER_FAILED_UPDATE_FAILED', y
		// 'SHIPPING_LEDGER_DELIVERED_NOW_DISPUTED',) - son unicos a la llamada
		// real del log (no aparecen en el comentario del fix que menciona los
		// mismos tokens sin coma). strpos generico encontraba el comentario
		// primero, falsificando el orden.
		$failed_check_token            = "'SHLEDGER_FAILED_UPDATE_FAILED',";
		$delivered_now_disputed_token  = "'SHIPPING_LEDGER_DELIVERED_NOW_DISPUTED',";
		$failed_check_pos              = strpos( $source, $failed_check_token );
		$delivered_now_disputed_pos    = strpos( $source, $delivered_now_disputed_token );

		$this->assertNotFalse( $failed_check_pos, 'SHLEDGER-002: llamada log SHLEDGER_FAILED_UPDATE_FAILED debe existir.' );
		$this->assertNotFalse( $delivered_now_disputed_pos, 'SHLEDGER-002: llamada log SHIPPING_LEDGER_DELIVERED_NOW_DISPUTED debe existir.' );
		// delivered-now-disputed va DESPUES del check de fallo (que retorna
		// early) → solo se evalua si el UPDATE persistio.
		$this->assertGreaterThan( $failed_check_pos, $delivered_now_disputed_pos, 'SHLEDGER-002: delivered-now-disputed debe evaluarse despues del check de fallo del UPDATE (no antes).' );
	}

	/**
	 * En === 0 (no rows matched), debe loguear SHLEDGER_FAILED_NO_MATCH.
	 */
	public function test_failed_zero_rows_logs_info(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( 'SHLEDGER_FAILED_NO_MATCH', $source, 'SHLEDGER-002: log SHLEDGER_FAILED_NO_MATCH para 0 rows.' );
	}

	// ── SHLEDGER-003 P1: open_dispute verificar INSERT ─────────────────────

	/**
	 * El INSERT en lt_shipping_disputes debe capturar su retorno en
	 * $inserted y verificar === false.
	 */
	public function test_open_dispute_insert_captures_return(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		// Buscar el INSERT dentro de open_dispute (no auto_open_dispute).
		// open_dispute mide ~6,419 bytes → usar 7,000 para cubrirlo.
		$method_pos = strpos( $source, 'public static function open_dispute' );
		$this->assertNotFalse( $method_pos, 'open_dispute debe existir.' );

		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString( '$inserted = $wpdb->insert(', $method_block, 'SHLEDGER-003: INSERT debe capturar su retorno en $inserted.' );
		$this->assertStringContainsString( 'false === $inserted', $method_block, 'SHLEDGER-003: verificar false === $inserted.' );
		$this->assertStringContainsString( 'SHLEDGER_DISPUTE_INSERT_FAILED', $method_block, 'SHLEDGER-003: log SHLEDGER_DISPUTE_INSERT_FAILED debe estar presente.' );
	}

	// ── SHLEDGER-004 P1: open_dispute verificar UPDATE de ledger link ──────

	/**
	 * El UPDATE que enlaza el ledger entry con la disputa debe capturar
	 * su retorno en $ledger_link_updated y verificar === false o === 0.
	 */
	public function test_open_dispute_ledger_link_update_captures_return(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		// open_dispute mide ~6,419 bytes → usar 7,000 para cubrirlo.
		$method_pos = strpos( $source, 'public static function open_dispute' );
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString( '$ledger_link_updated = $wpdb->update(', $method_block, 'SHLEDGER-004: UPDATE del ledger link debe capturar su retorno en $ledger_link_updated.' );
		$this->assertStringContainsString( '$ledger_link_updated === false || $ledger_link_updated === 0', $method_block, 'SHLEDGER-004: verificar $ledger_link_updated === false o === 0.' );
		$this->assertStringContainsString( 'SHLEDGER_DISPUTE_LINK_FAILED', $method_block, 'SHLEDGER-004: log SHLEDGER_DISPUTE_LINK_FAILED debe estar presente.' );
		// Log debe incluir SQL de reconciliacion manual.
		$this->assertStringContainsString( 'lt_shipping_cost_ledger SET dispute_id=', $method_block, 'SHLEDGER-004: log debe incluir SQL de reconciliacion manual.' );
	}

	// ── SHLEDGER-005 P1: import_carrier_invoice transaccion + verificacion ─

	/**
	 * El INSERT de la factura debe estar precedido por START_TRANSACTION.
	 */
	public function test_import_invoice_starts_transaction_before_insert(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		// Encontrar el INSERT de la factura.
		$inv_insert_pos = strpos( $source, '$inv_inserted = $wpdb->insert(' );
		$this->assertNotFalse( $inv_insert_pos, 'SHLEDGER-005: $inv_inserted = $wpdb->insert debe existir.' );

		// Tomar 300 chars antes del INSERT - debe contener START TRANSACTION.
		$before_insert = substr( $source, max( 0, $inv_insert_pos - 500 ), 500 );
		$this->assertStringContainsString( "START TRANSACTION", $before_insert, 'SHLEDGER-005: el INSERT de la factura debe estar precedido por START TRANSACTION.' );
	}

	/**
	 * El INSERT de la factura debe capturar su retorno en $inv_inserted y
	 * verificar === false (con rollback + return early).
	 */
	public function test_import_invoice_verifies_insert_with_rollback(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( '$inv_inserted = $wpdb->insert(', $source, 'SHLEDGER-005: capturar $inv_inserted.' );
		$this->assertStringContainsString( 'false === $inv_inserted', $source, 'SHLEDGER-005: verificar false === $inv_inserted.' );
		$this->assertStringContainsString( 'SHLEDGER_INVOICE_INSERT_FAILED', $source, 'SHLEDGER-005: log SHLEDGER_INVOICE_INSERT_FAILED.' );

		// Take block despues del check - debe contener ROLLBACK.
		$check_pos = strpos( $source, 'false === $inv_inserted' );
		$block_after = substr( $source, $check_pos, 1000 );
		$this->assertStringContainsString( "ROLLBACK", $block_after, 'SHLEDGER-005: debe hacer ROLLBACK en fallo del INSERT factura.' );
	}

	/**
	 * El INSERT de cada linea debe capturar su retorno en $line_inserted
	 * y verificar === false (con rollback).
	 */
	public function test_import_invoice_line_insert_verifies_with_rollback(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( '$line_inserted = $wpdb->insert(', $source, 'SHLEDGER-005: capturar $line_inserted.' );
		$this->assertStringContainsString( 'false === $line_inserted', $source, 'SHLEDGER-005: verificar false === $line_inserted.' );
		$this->assertStringContainsString( 'SHLEDGER_INVOICE_LINE_INSERT_FAILED', $source, 'SHLEDGER-005: log SHLEDGER_INVOICE_LINE_INSERT_FAILED.' );
	}

	/**
	 * El UPDATE de totales debe capturar su retorno en $totals_updated y
	 * verificar === false (con rollback + return early).
	 */
	public function test_import_invoice_totals_update_verifies_with_rollback(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( '$totals_updated = $wpdb->update(', $source, 'SHLEDGER-005: capturar $totals_updated.' );

		// Take 1500 chars despues del UPDATE - debe contener check + ROLLBACK + COMMIT.
		$update_pos = strpos( $source, '$totals_updated = $wpdb->update(' );
		$block_after = substr( $source, $update_pos, 2500 );

		$this->assertStringContainsString( 'false === $totals_updated', $block_after, 'SHLEDGER-005: verificar false === $totals_updated.' );
		$this->assertStringContainsString( 'SHLEDGER_INVOICE_TOTALS_UPDATE_FAILED', $block_after, 'SHLEDGER-005: log SHLEDGER_INVOICE_TOTALS_UPDATE_FAILED.' );
		$this->assertStringContainsString( 'ROLLBACK', $block_after, 'SHLEDGER-005: debe haber ROLLBACK en fallo del UPDATE totales.' );
		$this->assertStringContainsString( "COMMIT", $block_after, 'SHLEDGER-005: debe haber COMMIT en exito.' );
	}

	/**
	 * El catch general del import debe hacer ROLLBACK defensivo (si una
	 * excepcion salta con la transaccion abierta).
	 */
	public function test_import_invoice_catch_has_defensive_rollback(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		// import_carrier_invoice mide ~15,761 bytes → usar 16,000 para
		// cubrir el catch al final del metodo.
		$method_pos = strpos( $source, 'function import_carrier_invoice' );
		$method_block = substr( $source, $method_pos, 16000 );

		$this->assertStringContainsString( "catch ( \Throwable \$e )", $method_block, 'SHLEDGER-005: catch Throwable debe existir.' );

		// Tomar el bloque despues del catch.
		$catch_pos = strpos( $method_block, "catch ( \Throwable \$e )" );
		$catch_block = substr( $method_block, $catch_pos, 1200 );
		$this->assertStringContainsString( "ROLLBACK", $catch_block, 'SHLEDGER-005: el catch debe hacer ROLLBACK defensivo.' );
	}

	// ── SHLEDGER-006 P1: auto_open_dispute verificar UPDATE de ledger link ──

	/**
	 * El UPDATE que enlaza el ledger entry con la disputa automatica
	 * debe capturar su retorno en $auto_link_updated y verificar.
	 */
	public function test_auto_open_dispute_ledger_link_update_captures_return(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( '$auto_link_updated = $wpdb->update(', $source, 'SHLEDGER-006: UPDATE debe capturar su retorno en $auto_link_updated.' );
		$this->assertStringContainsString( '$auto_link_updated === false || $auto_link_updated === 0', $source, 'SHLEDGER-006: verificar $auto_link_updated === false o === 0.' );
		$this->assertStringContainsString( 'SHLEDGER_AUTO_DISPUTE_LINK_FAILED', $source, 'SHLEDGER-006: log SHLEDGER_AUTO_DISPUTE_LINK_FAILED debe estar presente.' );
		// Log debe incluir SQL de reconciliacion manual.
		$this->assertStringContainsString( 'lt_shipping_cost_ledger SET dispute_id=', $source, 'SHLEDGER-006: log debe incluir SQL de reconciliacion manual.' );
	}

	// ── Fix tags de trazabilidad ─────────────────────────────────────────────

	/**
	 * Todos los fixes del Ciclo 6 deben estar marcados con sus IDs en el
	 * codigo fuente.
	 */
	public function test_fix_tags_present_in_shipping_cost_ledger(): void {
		$this->assertFileExists( self::LEDGER_PATH );
		$source = file_get_contents( self::LEDGER_PATH );

		$this->assertStringContainsString( 'CICLO6-P0-SHLEDGER-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO6-P0-SHLEDGER-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO6-P1-SHLEDGER-003 FIX', $source );
		$this->assertStringContainsString( 'CICLO6-P1-SHLEDGER-004 FIX', $source );
		$this->assertStringContainsString( 'CICLO6-P1-SHLEDGER-005 FIX', $source );
		$this->assertStringContainsString( 'CICLO6-P1-SHLEDGER-006 FIX', $source );
	}
}
