<?php
/**
 * AuditCiclo7OrderSplitFixesTest - Tests para los fixes P1 del Ciclo 7.
 *
 * Cubre los fixes aplicados a class-ltms-order-split.php (cross-border
 * customs declaration flow):
 *   - OS-007 P1: create_customs_declaration() - el $wpdb->insert no
 *     se verificaba con distincion false (error DB) vs 0 (no rows). Si
 *     retornaba 0, $wpdb->insert_id quedaba en 0 igual y el caller
 *     persistia meta _ltms_customs_declaration_id=0 - idempotency check
 *     en re-fire del webhook entraba a SELECT en tabla (no encontraba
 *     fila si 0 rows) y proceed a crear OTRA declaracion - duplicado
 *     silencioso en lt_customs_declarations. Solo habia log warning sin
 *     distinguir false de 0.
 *     Fix: verificacion explicita false === $result || 0 === (int) $result
 *     con var_export en log critico CUSTOMS_DECLARATION_INSERT_FAILED
 *     para distinguir error DB de 0 rows. Mismo patron verificado que
 *     los fixes OS-001..006 del Ciclo 6.
 *   - OS-008 P1: process_cross_border_for_vendor() - si el INSERT de
 *     la declaracion fallaba (create_customs_declaration retorna 0),
 *     el caller procedia con declaration_id=0 silenciosamente -
 *     persistia _ltms_customs_declaration_id=0 en order meta, ejecutaba
 *     FX/DDP debit con reference a declaracion inexistente, disparaba
 *     hooks ltms_cross_border_order con declaration_id=0. Reconciliacion
 *     manual impossible.
 *     Fix: early return si $declaration_id <= 0, sin persistir metas
 *     con id=0 (falsearia idempotency check), sin disparar hooks, sin
 *     ejecutar FX/DDP. Log error CROSS_BORDER_DECLARATION_NOT_CREATED
 *     para trazabilidad.
 *   - OS-009 P1: process_cross_border_for_vendor() - $order->save()
 *     post update_meta_data no se verificaba. Si fallaba
 *     silenciosamente (WC_Data_Store exception, DB lock, post revision
 *     conflict - raro pero posible), los metas _ltms_customs_declaration*
 *     NO se persistian PERO el INSERT en lt_customs_declarations ya
 *     habia ocurrido. En re-fire del webhook, idempotency check leia
 *     meta=0 (no persistio), entraba a SELECT en tabla, encontraba la
 *     fila, retomaba el flujo desde ahi PERO YA CON OTRO INSERT
 *     ejectandose (declaracion DUPLICADA en lt_customs_declarations).
 *     Fix: capturar $saved + verificar retorno. En fallo, log critico
 *     CROSS_BORDER_META_SAVE_FAILED con SQL de reconciliacion manual
 *     explicita (UPDATE wp_postmeta SET meta_value=X WHERE post_id=Y
 *     AND meta_key='_ltms_customs_declaration_id').
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers OS-007, OS-008, OS-009
 */
class AuditCiclo7OrderSplitFixesTest extends LTMS_Unit_Test_Case {

	private const OS_PATH = __DIR__ . '/../../includes/business/class-ltms-order-split.php';

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

	// -- OS-007 P1: create_customs_declaration verifica INSERT false && 0 ----

	/**
	 * El check explícito debe distinguir false (error DB con last_error)
	 * de 0 (no rows inserted sin error reported).
	 */
	public function test_create_customs_declaration_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$this->assertStringContainsString(
			'false === $result || 0 === (int) $result',
			$source,
			'OS-007: check explicito false === $result || 0 === (int) $result debe estar presente (distingue false de 0).'
		);
	}

	/**
	 * El check debe usar var_export en el log para distinguir false (error
	 * DB con last_error) de 0 (no rows sin error reported) en el mensaje.
	 */
	public function test_create_customs_declaration_log_uses_var_export(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		// Localizar el bloque del INSERT en create_customs_declaration.
		// El metodo mide ~5,494 bytes - usar 6,000 para cubrirlo completo.
		$ccd_pos = strpos( $source, 'function create_customs_declaration' );
		$this->assertNotFalse( $ccd_pos, 'create_customs_declaration debe existir.' );

		$ccd_block = substr( $source, $ccd_pos, 6000 );

		$this->assertStringContainsString(
			'CUSTOMS_DECLARATION_INSERT_FAILED',
			$ccd_block,
			'OS-007: log CUSTOMS_DECLARATION_INSERT_FAILED debe seguir presente.'
		);

		$this->assertStringContainsString(
			"var_export( \$result, true )",
			$ccd_block,
			'OS-007: log debe usar var_export($result, true) para distinguir false de 0.'
		);

		// Adicionalmente, el log debe mencionar last_error (error DB).
		$this->assertStringContainsString(
			'last_error',
			$ccd_block,
			'OS-007: log debe mencionar last_error (error DB).'
		);

		// Y debe mencionar reconciliacion manual + el meta key afectado.
		$this->assertStringContainsString(
			'_ltms_customs_declaration_id',
			$ccd_block,
			'OS-007: log debe mencionar _ltms_customs_declaration_id para reconciliacion manual.'
		);
	}

	/**
	 * El log debe ser critico (no warning como antes) -es un fallo de
	 * persistencia que duplica declaraciones en reintentos.
	 */
	public function test_create_customs_declaration_log_is_critical(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$ccd_pos = strpos( $source, 'function create_customs_declaration' );
		$ccd_block = substr( $source, $ccd_pos, 6000 );

		// Buscar LTMS_Core_Logger::critical( justo antes del token
		// CUSTOMS_DECLARATION_INSERT_FAILED (con coma final = llamada real).
		$critical_call_pos = strpos( $ccd_block, "'CUSTOMS_DECLARATION_INSERT_FAILED'," );
		$this->assertNotFalse( $critical_call_pos, 'OS-007: llamada log CUSTOMS_DECLARATION_INSERT_FAILED debe existir.' );

		// Tomar 300 chars antes del token - debe contener ::critical(.
		$before_token = substr( $ccd_block, max( 0, $critical_call_pos - 300 ), 300 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'OS-007: el log debe ser critico (no warning), fallo de persistencia DB.'
		);
	}

	// -- OS-008 P1: process_cross_border_for_vendor early return --------------

	/**
	 * Si $declaration_id <= 0, debe haber early return (no persistir
	 * metas con id=0, no ejecutar FX/DDP, no disparar hooks).
	 */
	public function test_cross_border_returns_early_when_declaration_id_is_zero(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		// Localizar dentro del metodo process_cross_border_for_vendor
		// (mide ~25,551 bytes - usar substr amplio).
		$method_pos = strpos( $source, 'function process_cross_border_for_vendor' );
		$this->assertNotFalse( $method_pos, 'process_cross_border_for_vendor debe existir.' );

		$method_block = substr( $source, $method_pos, 26000 );

		$this->assertStringContainsString(
			'$declaration_id <= 0',
			$method_block,
			'OS-008: check $declaration_id <= 0 debe estar presente.'
		);

		// Dentro del bloque if, debe haber return;.
		$check_pos = strpos( $method_block, '$declaration_id <= 0' );
		$this->assertNotFalse( $check_pos, 'OS-008: check debe existir.' );
		$block_after_check = substr( $method_block, $check_pos, 800 );

		$this->assertStringContainsString(
			'return;',
			$block_after_check,
			'OS-008: debe haber return; dentro del bloque if ($declaration_id <= 0).'
		);
	}

	/**
	 * El log de fallo debe ser CROSS_BORDER_DECLARATION_NOT_CREATED.
	 */
	public function test_cross_border_logs_not_created_on_zero_declaration(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$this->assertStringContainsString(
			'CROSS_BORDER_DECLARATION_NOT_CREATED',
			$source,
			'OS-008: log CROSS_BORDER_DECLARATION_NOT_CREATED debe estar presente.'
		);
	}

	/**
	 * El log debe mencionar que NO se persistira meta _ltms_customs_declaration_id=0
	 * (es la razon del early return -falsea idempotency check en re-fire).
	 */
	public function test_cross_border_log_mentions_no_meta_persist(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$method_pos = strpos( $source, 'function process_cross_border_for_vendor' );
		$method_block = substr( $source, $method_pos, 26000 );

		$nc_pos = strpos( $method_block, 'CROSS_BORDER_DECLARATION_NOT_CREATED' );
		$this->assertNotFalse( $nc_pos, 'OS-008: log CROSS_BORDER_DECLARATION_NOT_CREATED debe existir.' );

		$block_after = substr( $method_block, $nc_pos, 1200 );

		// El log debe mencionar que no se persistira meta=0.
		$this->assertStringContainsString(
			'_ltms_customs_declaration_id=0',
			$block_after,
			'OS-008: log debe mencionar que no se persistira _ltms_customs_declaration_id=0.'
		);
	}

	/**
	 * El log debe explicar que FX/DDP/hooks NO se ejecutaran.
	 */
	public function test_cross_border_log_mentions_no_fx_ddp_hooks(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$method_pos = strpos( $source, 'function process_cross_border_for_vendor' );
		$method_block = substr( $source, $method_pos, 26000 );

		$nc_pos = strpos( $method_block, 'CROSS_BORDER_DECLARATION_NOT_CREATED' );
		$block_after = substr( $method_block, $nc_pos, 1200 );

		// El log debe mencionar FX y/o DDP y/o hooks no se ejecutaran.
		$this->assertStringContainsString(
			'FX/DDP/hooks',
			$block_after,
			'OS-008: log debe mencionar que FX/DDP/hooks no se ejecutaran.'
		);
	}

	// -- OS-009 P1: order->save verificado post customs meta ------------------

	/**
	 * $order->save() debe capturarse en $saved y verificarse el retorno.
	 */
	public function test_order_save_captured_in_saved_variable(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$this->assertStringContainsString(
			'$saved = $order->save();',
			$source,
			'OS-009: $order->save() debe capturarse en $saved para verificacion.'
		);
	}

	/**
	 * En fallo de $saved, debe loguear critico
	 * CROSS_BORDER_META_SAVE_FAILED con SQL de reconciliacion manual.
	 */
	public function test_save_failure_logs_critical_with_sql(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$this->assertStringContainsString(
			'CROSS_BORDER_META_SAVE_FAILED',
			$source,
			'OS-009: log critico CROSS_BORDER_META_SAVE_FAILED debe estar presente.'
		);

		// Debe incluir la sentencia SQL de reconciliacion manual
		// (UPDATE wp_postmeta SET meta_value=X WHERE post_id=Y AND meta_key='_ltms_customs_declaration_id').
		$this->assertStringContainsString(
			"UPDATE wp_postmeta SET meta_value=",
			$source,
			'OS-009: log debe incluir SQL de reconciliacion manual.'
		);

		// La sentencia debe mencionar el meta key afectado.
		$this->assertStringContainsString(
			"_ltms_customs_declaration_id",
			$source,
			'OS-009: SQL de reconciliacion debe mencionar _ltms_customs_declaration_id.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (exception) de
	 * otros valores de retorno no-numericos.
	 */
	public function test_save_failure_log_uses_var_export(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		// Localizar el bloque del log critico.
		// var_export($saved, true) esta ~578 chars despues del token
		// CROSS_BORDER_META_SAVE_FAILED - usar block_after de 1500.
		$sm_pos = strpos( $source, 'CROSS_BORDER_META_SAVE_FAILED' );
		$this->assertNotFalse( $sm_pos, 'OS-009: log CROSS_BORDER_META_SAVE_FAILED debe existir.' );

		$block_after = substr( $source, $sm_pos, 1500 );

		$this->assertStringContainsString(
			"var_export( \$saved, true )",
			$block_after,
			'OS-009: log debe usar var_export($saved, true) para distinguir false de otros valores.'
		);
	}

	// -- Fix tags de trazabilidad ---------------------------------------------

	/**
	 * Todos los fixes del Ciclo 7 deben estar marcados con sus IDs en
	 * el codigo fuente.
	 */
	public function test_fix_tags_present_in_order_split(): void {
		$this->assertFileExists( self::OS_PATH );
		$source = file_get_contents( self::OS_PATH );

		$this->assertStringContainsString( 'CICLO7-P1-OS-007 FIX', $source );
		$this->assertStringContainsString( 'CICLO7-P1-OS-008 FIX', $source );
		$this->assertStringContainsString( 'CICLO7-P1-OS-009 FIX', $source );
	}
}
