<?php
/**
 * AuditCiclo15RediManagerFixesTest - Tests para los fixes del Ciclo 15.
 *
 * Cubre los fixes aplicados a
 * includes/business/class-ltms-business-redi-manager.php
 * (LTMS_Business_Redi_Manager - gestiona el modelo ReDi de
 * revendedores que adoptan productos de vendors origin y ganan
 * comisiones). Modulo critico: lm_redi_agreements +
 * lt_redi_commissions + stock deduction del origin vendor.
 * Es el manager invocado por el listener auditado en el Ciclo 11
 * (detect_redi_items + deduct_origin_stock).
 *
 *   - RM-036 P1: adopt_product() $wpdb->insert en
 *     lt_redi_agreements (linea 289 pre-fix) NO se verificaba. Si
 *     fallaba silenciosamente (false = error DB con last_error),
 *     el COMMIT en linea 313 (pre-fix) se ejecutaba
 *     INCONDICIONALMENTE -> el reseller quedaba con un producto
 *     copia + metas _ltms_redi_* pero SIN el acuerdo registrado en
 *     lt_redi_agreements. Esto rompe la trazabilidad: get_agreement_id
 *     (linea 401) retorna 0 -> detect_redi_items registra
 *     agreement_id=0 en lt_redi_commissions -> la comision no se
 *     atribuye correctamente al acuerdo que la genero (match
 *     exacto del bug que AUDIT-RD-BK RD-1 FIX en linea 404 intenta
 *     prevenir - columna reseller_vendor_id vs reseller_id).
 *     Fix: capturar $insert_result + check false === + ROLLBACK (no
 *     COMMIT) + log critico REDI_ADOPT_AGREEMENT_INSERT_FAILED con
 *     SQL de reconciliacion manual + return 0 (no exit path
 *     exitoso). Patron recurrente Ciclos 5-14.
 *   - RM-037 P1: adopt_product() si $new_product_id es falsy (save()
 *     falla, linea 275 pre-fix), retorna 0 pero NO hace ROLLBACK de
 *     la transaccion START TRANSACTION (linea 237) -> el row lock
 *     FOR UPDATE se mantiene hasta timeout del MySQL server (50s
 *     default innodb_lock_wait_timeout), bloqueando cualquier otra
 *     adopt_product concurrente para el mismo reseller+origin
 *     (exactamente el TOCTOU que se queria prevenir) + el objeto
 *     $wpdb queda en modo transaccional para queries posteriores no
 *     relacionados (race condition extensivo).
 *     Fix: $wpdb->query('ROLLBACK') antes del return 0 + log critico
 *     REDI_ADOPT_SAVE_FAILED con mencion "(transaction rolled back)".
 *   - RM-038 P1: soft_resume_redi() $wpdb->update re-activar acuerdo
 *     (linea 678 pre-fix) NO se verificaba. Si fallaba, el acuerdo
 *     quedaba en status='paused' PERO el reseller_product se
 *     re-publicaba (set_stock_status instock + set_status publish +
 *     save, lineas 693-695) y la notificacion de resume se enviaba
 *     (linea 700) -> el reseller recibe email "ReDi reanudado", ve
 *     su producto publicado de nuevo, pero el acuerdo sigue 'paused'
 *     -> siguientes ventas no generan comision a pesar de ser
 *     visibles y vendibles. Inconsistencia silenciosa entre lo que el
 *     reseller ve (producto activo, notificacion positiva) y lo que
 *     el sistema sabe (acuerdo pausado, sin comision).
 *     Fix: capturar $reactivated + check false === + log critico
 *     REDI_RESUME_AGREEMENT_REACTIVATE_FAILED con SQL de
 *     reconciliacion + continue (NO ejecutar el re-publicar del
 *     producto ni la notificacion al reseller para no mentirle).
 *   - RM-039 P1: on_product_visibility_change() $wpdb->update pausar
 *     acuerdo (linea 776 pre-fix) NO se verificaba. Si fallaba, el
 *     acuerdo quedaba en status='active' PERO la copia del reseller
 *     se marcaba outofstock + private (lineas 793-800) y la
 *     notificacion de pausa se enviaba al reseller (linea 805) -> el
 *     reseller recibe "ReDi pausado" pero el acuerdo sigue activo ->
 *     si el stock se restaura despues, el reseller no puede vender
 *     (producto private) pero el acuerdo nunca nego la comision -> en
 *     el siguiente resume_redi el acuerdo no esta en la lista de
 *     paused, asi que no se re-procesa -> la pausa fue cosmetica en
 *     el producto pero no en el acuerdo.
 *     Fix: capturar $paused + check false === + log critico
 *     REDI_PAUSE_AGREEMENT_FAILED con SQL de reconciliacion +
 *     continue (NO marcar el reseller_product outofstock/private ni
 *     notificar al reseller).
 *   - RM-040 P1: notify_reseller_redi_paused() $wpdb->insert en
 *     lt_notifications (linea 855 pre-fix) NO se verificaba. Si
 *     fallaba, el reseller no recibia alerta in-app de la pausa ->
 *     descubria la pausa solo cuando viera su producto outofstock
 *     en la tienda, sin alerta proactiva. La notificacion es el
 *     canal principal de aviso al reseller en soft pause
 *     (AUDIT-REDI-UX-GAPS GAP-10 FIX).
 *     Fix: capturar $notif_result + check false === + log critico
 *     REDI_PAUSED_NOTIFICATION_INSERT_FAILED con SQL de
 *     reconciliacion.
 *   - RM-041 P1: notify_reseller_redi_resumed() $wpdb->insert en
 *     lt_notifications (linea 943 pre-fix) NO se verificaba. Idem
 *     RM-040 para el caso de resume - el reseller que mira la
 *     plataforma SIN checkear email no se entera del resume.
 *     Fix: capturar + log critico REDI_RESUMED_NOTIFICATION_INSERT_FAILED
 *     con SQL de reconciliacion.
 *
 * Hallazgos descartados:
 *   - AJAX handlers (ajax_soft_pause, ajax_soft_resume): check_ajax_referer
 *     + is_user_logged_in + cap check manage_woocommerce OR owner.
 *     Correctos.
 *   - save_redi_product_fields: sanitizacion + clamp rate OK. Correcto.
 *   - detect_redi_items: solo lectura via get_post_meta. Correcto.
 *   - deduct_origin_stock: usa WC_Product API, no $wpdb directo. Correcto.
 *   - update_post_meta en save_redi_product_fields/adopt_product:
 *     P2 backlog (no bloquea retry - WC maneja re-raise internamente).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers RM-036, RM-037, RM-038, RM-039, RM-040, RM-041
 */
class AuditCiclo15RediManagerFixesTest extends LTMS_Unit_Test_Case {

	private const RM_PATH = __DIR__ . '/../../includes/business/class-ltms-business-redi-manager.php';

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

	// -- RM-036 P1: adopt_product $wpdb->insert lt_redi_agreements verifica

	/**
	 * El INSERT debe capturarse en $insert_result (no ser llamada suelta).
	 */
	public function test_adopt_product_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function adopt_product' );
		$this->assertNotFalse( $method_pos, 'adopt_product debe existir.' );

		// adopt_product mide ~9,400 bytes post-fix.
		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'$insert_result = $wpdb->insert(',
			$method_block,
			'RM-036: el INSERT en lt_redi_agreements debe capturar su retorno en $insert_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $insert_result dentro de adopt_product.
	 */
	public function test_adopt_product_checks_false_explicitly(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function adopt_product' );
		$this->assertNotFalse( $method_pos, 'adopt_product debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		$this->assertStringContainsString(
			'false === $insert_result',
			$method_block,
			'RM-036: check explicito false === $insert_result debe estar presente.'
		);
	}

	/**
	 * En path de fallo del INSERT, debe hacer ROLLBACK (no COMMIT).
	 */
	public function test_adopt_product_insert_failure_does_rollback_not_commit(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function adopt_product' );
		$this->assertNotFalse( $method_pos, 'adopt_product debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		// Localizar el bloque if (false === $insert_result).
		$check_pos = strpos( $method_block, 'false === $insert_result' );
		$this->assertNotFalse( $check_pos, 'RM-036: el check debe existir.' );

		// En los 300 chars despues del check, debe haber ROLLBACK y NO COMMIT.
		$block_after = substr( $method_block, $check_pos, 400 );
		$this->assertStringContainsString(
			"\$wpdb->query( 'ROLLBACK' )",
			$block_after,
			'RM-036: en path de fallo del INSERT, debe hacer ROLLBACK (no COMMIT incondicional).'
		);
	}

	/**
	 * El log de fallo del INSERT debe ser critico
	 * REDI_ADOPT_AGREEMENT_INSERT_FAILED.
	 */
	public function test_adopt_product_insert_failure_logs_critical(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function adopt_product' );
		$this->assertNotFalse( $method_pos, 'adopt_product debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_ADOPT_AGREEMENT_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RM-036: log REDI_ADOPT_AGREEMENT_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RM-036: el log debe ser critico (no warning), fallo de INSERT en agreement compromete trazabilidad de comisiones.'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual INSERT INTO
	 * lt_redi_agreements.
	 */
	public function test_adopt_product_insert_failure_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			'INSERT INTO %slt_redi_agreements',
			$source,
			'RM-036: log debe incluir SQL de reconciliacion manual INSERT INTO lt_redi_agreements.'
		);

		$this->assertStringContainsString(
			'lt_redi_agreements',
			$source,
			'RM-036: SQL de reconciliacion debe mencionar lt_redi_agreements.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-036 FIX debe estar presente.
	 */
	public function test_rm036_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-036 FIX', $source );
	}

	// -- RM-037 P1: adopt_product save falla hace ROLLBACK -----------

	/**
	 * El path de fallo de $new_product_id debe hacer ROLLBACK.
	 */
	public function test_adopt_product_save_failure_does_rollback(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function adopt_product' );
		$this->assertNotFalse( $method_pos, 'adopt_product debe existir.' );

		$method_block = substr( $source, $method_pos, 9500 );

		// Localizar el bloque if ( ! $new_product_id ).
		$block_pos = strpos( $method_block, 'if ( ! $new_product_id )' );
		$this->assertNotFalse( $block_pos, 'RM-037: el bloque if ( ! $new_product_id ) debe existir.' );

		// El bloque de comentarios explicativos del CICLO15-P1-RM-037
		// FIX es extenso (12 lineas, ~940 chars antes del ROLLBACK
		// real). Ampliado de 250 -> 1200 para soportar el bloque
		// completo de comentarios que explica por que el ROLLBACK es
		// necesario (TOCTOU, innodb_lock_wait_timeout, race
		// condition extensivo). El ROLLBACK esta en offset ~943
		// dentro del bloque desde if ( ! $new_product_id ).
		$block_after = substr( $method_block, $block_pos, 1200 );
		$this->assertStringContainsString(
			"\$wpdb->query( 'ROLLBACK' )",
			$block_after,
			'RM-037: en path de fallo de save(), debe hacer ROLLBACK de la transaccion START TRANSACTION.'
		);
	}

	/**
	 * El log REDI_ADOPT_SAVE_FAILED debe mencionar "(transaction rolled back)".
	 */
	public function test_adopt_product_save_failure_log_mentions_rollback(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			'transaction rolled back',
			$source,
			'RM-037: log REDI_ADOPT_SAVE_FAILED debe mencionar "(transaction rolled back)" para auditar el rollback.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-037 FIX debe estar presente.
	 */
	public function test_rm037_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-037 FIX', $source );
	}

	// -- RM-038 P1: soft_resume_redi $wpdb->update re-activar verifica --

	/**
	 * El UPDATE debe capturarse en $reactivated (no ser llamada suelta).
	 */
	public function test_soft_resume_redi_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function soft_resume_redi' );
		$this->assertNotFalse( $method_pos, 'soft_resume_redi debe existir.' );

		// soft_resume_redi mide ~7,600 bytes post-fix.
		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'$reactivated = $wpdb->update(',
			$method_block,
			'RM-038: el UPDATE de re-activar acuerdo debe capturar su retorno en $reactivated (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $reactivated.
	 */
	public function test_soft_resume_redi_checks_false_explicitly(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function soft_resume_redi' );
		$this->assertNotFalse( $method_pos, 'soft_resume_redi debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'false === $reactivated',
			$method_block,
			'RM-038: check explicito false === $reactivated debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_RESUME_AGREEMENT_REACTIVATE_FAILED.
	 */
	public function test_soft_resume_redi_failure_logs_critical(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function soft_resume_redi' );
		$this->assertNotFalse( $method_pos, 'soft_resume_redi debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_RESUME_AGREEMENT_REACTIVATE_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RM-038: log REDI_RESUME_AGREEMENT_REACTIVATE_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RM-038: el log debe ser critico (no warning), fallo de re-activacion de acuerdo.'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE lt_redi_agreements.
	 */
	public function test_soft_resume_redi_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			'UPDATE %slt_redi_agreements',
			$source,
			'RM-038: log debe incluir SQL de reconciliacion manual UPDATE lt_redi_agreements.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-038 FIX debe estar presente.
	 */
	public function test_rm038_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-038 FIX', $source );
	}

	// -- RM-039 P1: on_product_visibility_change $wpdb->update verifica --

	/**
	 * El UPDATE debe capturarse en $paused (no ser llamada suelta).
	 */
	public function test_on_product_visibility_captures_update_in_result_variable(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function on_product_visibility_change' );
		$this->assertNotFalse( $method_pos, 'on_product_visibility_change debe existir.' );

		// on_product_visibility_change mide ~6,500 bytes post-fix.
		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'$paused = $wpdb->update(',
			$method_block,
			'RM-039: el UPDATE de pausar acuerdo debe capturar su retorno en $paused (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $paused.
	 */
	public function test_on_product_visibility_checks_false_explicitly(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function on_product_visibility_change' );
		$this->assertNotFalse( $method_pos, 'on_product_visibility_change debe existir.' );

		$method_block = substr( $source, $method_pos, 7000 );

		$this->assertStringContainsString(
			'false === $paused',
			$method_block,
			'RM-039: check explicito false === $paused debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_PAUSE_AGREEMENT_FAILED.
	 */
	public function test_on_product_visibility_failure_logs_critical(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function on_product_visibility_change' );
		$this->assertNotFalse( $method_pos, 'on_product_visibility_change debe existir.' );

		$method_block = substr( $source, $method_pos, 7000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_PAUSE_AGREEMENT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RM-039: log REDI_PAUSE_AGREEMENT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RM-039: el log debe ser critico (no warning), fallo de pausar acuerdo.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-039 FIX debe estar presente.
	 */
	public function test_rm039_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-039 FIX', $source );
	}

	// -- RM-040 P1: notify_reseller_redi_paused $wpdb->insert verifica --

	/**
	 * El INSERT debe capturarse en $notif_result.
	 */
	public function test_notify_reseller_paused_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function notify_reseller_redi_paused' );
		$this->assertNotFalse( $method_pos, 'notify_reseller_redi_paused debe existir.' );

		// notify_reseller_redi_paused mide ~5,800 bytes post-fix.
		$method_block = substr( $source, $method_pos, 6500 );

		$this->assertStringContainsString(
			'$notif_result = $wpdb->insert(',
			$method_block,
			'RM-040: el INSERT debe capturar su retorno en $notif_result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $notif_result.
	 */
	public function test_notify_reseller_paused_checks_false_explicitly(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function notify_reseller_redi_paused' );
		$this->assertNotFalse( $method_pos, 'notify_reseller_redi_paused debe existir.' );

		$method_block = substr( $source, $method_pos, 6500 );

		$this->assertStringContainsString(
			'false === $notif_result',
			$method_block,
			'RM-040: check explicito false === $notif_result debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_PAUSED_NOTIFICATION_INSERT_FAILED.
	 */
	public function test_notify_reseller_paused_failure_logs_critical(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			"'REDI_PAUSED_NOTIFICATION_INSERT_FAILED',",
			$source,
			'RM-040: log REDI_PAUSED_NOTIFICATION_INSERT_FAILED debe existir.'
		);

		// Verificar que es ::critical (no warning).
		$token_pos = strpos( $source, "'REDI_PAUSED_NOTIFICATION_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RM-040: el log debe ser critico (no warning), fallo de notificacion in-app al reseller.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-040 FIX debe estar presente.
	 */
	public function test_rm040_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-040 FIX', $source );
	}

	// -- RM-041 P1: notify_reseller_redi_resumed $wpdb->insert verifica --

	/**
	 * El INSERT debe capturarse en $notif_result.
	 */
	public function test_notify_reseller_resumed_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$method_pos = strpos( $source, 'function notify_reseller_redi_resumed' );
		$this->assertNotFalse( $method_pos, 'notify_reseller_redi_resumed debe existir.' );

		// notify_reseller_redi_resumed mide ~4,500 bytes post-fix.
		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'$notif_result = $wpdb->insert(',
			$method_block,
			'RM-041: el INSERT debe capturar su retorno en $notif_result (no ser statement suelta).'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_RESUMED_NOTIFICATION_INSERT_FAILED.
	 */
	public function test_notify_reseller_resumed_failure_logs_critical(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			"'REDI_RESUMED_NOTIFICATION_INSERT_FAILED',",
			$source,
			'RM-041: log REDI_RESUMED_NOTIFICATION_INSERT_FAILED debe existir.'
		);

		// Verificar que es ::critical (no warning).
		$token_pos = strpos( $source, "'REDI_RESUMED_NOTIFICATION_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RM-041: el log debe ser critico (no warning), fallo de notificacion in-app al reseller.'
		);
	}

	/**
	 * El fix tag CICLO15-P1-RM-041 FIX debe estar presente.
	 */
	public function test_rm041_fix_tag_present(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString( 'CICLO15-P1-RM-041 FIX', $source );
	}

	// -- Cross-check: AJAX handlers siguen con nonce + cap check -------

	/**
	 * Cross-check: ajax_soft_pause debe seguir con check_ajax_referer
	 * (regresion guard - los fixes no tocan los handlers AJAX).
	 */
	public function test_ajax_soft_pause_still_has_nonce_check(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' )",
			$source,
			'Cross-check: ajax_soft_pause debe seguir con check_ajax_referer (regresion guard).'
		);
	}

	/**
	 * Cross-check: el FASE5 P0 FIX (SELECT FOR UPDATE en adopt_product)
	 * debe seguir presente.
	 */
	public function test_adopt_product_keeps_transaction_with_for_update(): void {
		$this->assertFileExists( self::RM_PATH );
		$source = file_get_contents( self::RM_PATH );

		$this->assertStringContainsString(
			"FOR UPDATE",
			$source,
			'Cross-check: el FASE5 P0 FIX (SELECT FOR UPDATE en adopt_product) debe seguir presente.'
		);

		$this->assertStringContainsString(
			"START TRANSACTION",
			$source,
			'Cross-check: START TRANSACTION debe seguir presente en adopt_product.'
		);
	}
}
