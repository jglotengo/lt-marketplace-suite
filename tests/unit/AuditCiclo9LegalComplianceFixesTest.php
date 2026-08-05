<?php
/**
 * AuditCiclo9LegalComplianceFixesTest - Tests para los fixes P1 del Ciclo 9.
 *
 * Cubre los fixes aplicados a class-ltms-legal-compliance.php
 * (SAGRILAFT + consentimientos KYC + vault access log + habeas data):
 *   - LC-015 P1: log_vault_access() - el $wpdb->insert en
 *     lt_vault_access_log no se verificaba. Es log de auditoria
 *     regulatorio (Ley 1581/2012 art. 8 lit. d - registro de
 *     acceso a datos sensibles del vault KYC). Si el INSERT fallaba
 *     silenciosamente, un acceso al vault ocurria sin evidencia legal
 *     - compromete auditorias UIAF/SAGRILAFT y el deber de demostrar
 *     cumplimiento (principio de responsabilidad demostrada RGPD
 *     art. 5.2). Los reguladores piden este registro en inspecciones.
 *     Fix: verificacion explicita false === $result (error DB con
 *     last_error) de 0 === (int) $result (no rows - teorico de tabla
 *     corrupta o schema drift). Log critico (no warning) con
 *     var_export($result, true) y last_error para distinguir el caso.
 *     Mismo patron verificado que los fixes de los Ciclos 5/6/7/8
 *     (alegra-sync, shipping-ledger, order-split, consumer-protection).
 *   - LC-016 P1: log_consent() - mismo patron que LC-015 en
 *     lt_consent_log. Es la evidencia legal de consentimiento (Ley
 *     1581/2012 art. 9 - consentimiento libre, previo, expreso,
 *     informado; RGPD art. 7). Si el INSERT fallaba, el consentimiento
 *     se "dio" pero no hay evidencia - el contrato jurídicamente se
 *     debilita. Se llama en el flujo critico de KYC/registro/checkout;
 *     fallo silencioso -> usuario piensa que consintio, sistema cree
 *     que consintio, pero no hay evidencia forense cuando el regulador
 *     la pide.
 *     Fix: verificacion explicita false === $result de 0 === (int)
 *     $result + log critico CONSENT_LOG_INSERT_FAILED con var_export
 *     y last_error.
 *   - LC-017 P1: save_checkout_consent() rama guest - mismo patron.
 *     Es el consentimiento legal del guest checkout (Ley 1480/2011
 *     art. 3 + Ley 1581/2012 art. 9). Si falla, no se loguea PERO el
 *     pedido procede ($order->save() ya ocurrio antes) - el guest
 *     compra sin evidencia de consentimiento. En una auditoria, el
 *     regulador pide la evidencia del consentimiento asociado al
 *     pedido - sin este INSERT, no hay defensa legal.
 *     Fix: verificacion explicita false === $result de 0 === (int)
 *     $result + log critico GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED
 *     con var_export, last_error y order_id en contexto para
 *     reconciliacion manual.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers LC-015, LC-016, LC-017
 */
class AuditCiclo9LegalComplianceFixesTest extends LTMS_Unit_Test_Case {

	private const LC_PATH = __DIR__ . '/../../includes/business/class-ltms-legal-compliance.php';

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

	// -- LC-015 P1: log_vault_access verifica INSERT false || 0 ----------

	/**
	 * El INSERT debe capturarse en $result (no ser llamada statement suelta).
	 */
	public function test_log_vault_access_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		// Localizar dentro de log_vault_access (mide ~4,500 bytes).
		$method_pos = strpos( $source, 'function log_vault_access' );
		$this->assertNotFalse( $method_pos, 'log_vault_access debe existir.' );

		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'$result = $wpdb->insert(',
			$method_block,
			'LC-015: el INSERT debe capturar su retorno en $result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $result || 0 === (int) $result.
	 */
	public function test_log_vault_access_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'false === $result || 0 === (int) $result',
			$source,
			'LC-015: check explicito false === $result || 0 === (int) $result debe estar presente (distingue false de 0).'
		);
	}

	/**
	 * El log de fallo debe ser critico (no warning) -es un fallo de
	 * auditoria regulatoria.
	 */
	public function test_log_vault_access_failure_logs_critical(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function log_vault_access' );
		$method_block = substr( $source, $method_pos, 5000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'VAULT_ACCESS_LOG_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'LC-015: log VAULT_ACCESS_LOG_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'LC-015: el log debe ser critico (no warning), fallo de auditoria regulatoria.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB con
	 * last_error) de 0 (no rows sin error reported).
	 */
	public function test_log_vault_access_log_uses_var_export(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function log_vault_access' );
		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'var_export( $result, true )',
			$method_block,
			'LC-015: log debe usar var_export($result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar la base legal (Ley 1581/2012 art. 8 lit. d).
	 */
	public function test_log_vault_access_mentions_legal_basis(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'art. 8 lit. d',
			$source,
			'LC-015: log debe mencionar Ley 1581/2012 art. 8 lit. d (base legal del registro de acceso).'
		);
	}

	/**
	 * El log debe incluir sentencia SQL de reconciliacion manual
	 * (INSERT INTO... para que el admin pueda reparar el log).
	 */
	public function test_log_vault_access_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'INSERT INTO',
			$source,
			'LC-015: log debe incluir SQL de reconciliacion manual (INSERT INTO...).'
		);

		$this->assertStringContainsString(
			'lt_vault_access_log',
			$source,
			'LC-015: SQL de reconciliacion debe mencionar lt_vault_access_log.'
		);
	}

	// -- LC-016 P1: log_consent verifica INSERT false || 0 ----------------

	/**
	 * El INSERT debe capturarse en $result (no ser llamada statement suelta).
	 */
	public function test_log_consent_captures_insert_in_result_variable(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		// Localizar dentro de log_consent (mide ~4,200 bytes).
		$method_pos = strpos( $source, 'function log_consent' );
		$this->assertNotFalse( $method_pos, 'log_consent debe existir.' );

		// Tomar el metodo completo - usar 5000 para cubrirlo.
		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'$result = $wpdb->insert(',
			$method_block,
			'LC-016: el INSERT debe capturar su retorno en $result (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion explicita false === $result || 0 === (int) $result
	 * en log_consent (puede haber multiple ocurrencias en el archivo - al menos 1).
	 */
	public function test_log_consent_checks_false_and_zero_explicitly(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function log_consent' );
		$method_block = substr( $source, $method_pos, 5000 );

		$this->assertStringContainsString(
			'false === $result || 0 === (int) $result',
			$method_block,
			'LC-016: check explicito false === $result || 0 === (int) $result debe estar presente en log_consent.'
		);
	}

	/**
	 * El log de fallo debe ser critico CONSENT_LOG_INSERT_FAILED.
	 */
	public function test_log_consent_failure_logs_critical(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function log_consent' );
		$method_block = substr( $source, $method_pos, 5000 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'CONSENT_LOG_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'LC-016: log CONSENT_LOG_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'LC-016: el log debe ser critico (no warning), fallo de evidencia legal.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB) de 0.
	 */
	public function test_log_consent_log_uses_var_export(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function log_consent' );
		$method_block = substr( $source, $method_pos, 5000 );

		// var_export puede aparecer multiple veces (en sprintf + context array).
		$this->assertStringContainsString(
			'var_export( $result, true )',
			$method_block,
			'LC-016: log debe usar var_export($result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar la base legal (Ley 1581/2012 art. 9 + RGPD art. 7).
	 */
	public function test_log_consent_mentions_legal_basis(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		// LC-016 mentions both art. 9 and RGPD art. 7.
		$this->assertStringContainsString( 'art. 9', $source, 'LC-016: log debe mencionar Ley 1581/2012 art. 9.' );
		$this->assertStringContainsString( 'RGPD art. 7', $source, 'LC-016: log debe mencionar RGPD art. 7.' );
	}

	// -- LC-017 P1: save_checkout_consent (guest) verifica INSERT ----------
	// Nota: save_checkout_consent mide ~5,200 bytes; el bloque guest esta
	// al final del metodo. Usamos substr amplio (6000) para cubrirlo.

	/**
	 * El INSERT en la rama guest debe capturarse en $result.
	 */
	public function test_save_checkout_consent_guest_captures_insert_in_result(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function save_checkout_consent' );
		$this->assertNotFalse( $method_pos, 'save_checkout_consent debe existir.' );

		$method_block = substr( $source, $method_pos, 6000 );

		$this->assertStringContainsString(
			'$result = $wpdb->insert(',
			$method_block,
			'LC-017: el INSERT debe capturar su retorno en $result (no ser statement suelta).'
		);
	}

	/**
	 * El log de fallo debe ser critico GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED.
	 */
	public function test_save_checkout_consent_guest_failure_logs_critical(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED',
			$source,
			'LC-017: log critico GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED debe estar presente.'
		);

		// Localizar y verificar que es ::critical (no warning).
		$method_pos = strpos( $source, 'function save_checkout_consent' );
		$method_block = substr( $source, $method_pos, 6000 );

		$token_pos = strpos( $method_block, "'GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'LC-017: token GUEST_CHECKOUT_CONSENT_LOG_INSERT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'LC-017: el log debe ser critico, fallo de evidencia legal en guest checkout.'
		);
	}

	/**
	 * El log debe usar var_export para distinguir false (error DB) de 0.
	 */
	public function test_save_checkout_consent_guest_log_uses_var_export(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$method_pos = strpos( $source, 'function save_checkout_consent' );
		$method_block = substr( $source, $method_pos, 6000 );

		// Verificar que existe var_export( $result, true ) en el bloque.
		$this->assertStringContainsString(
			'var_export( $result, true )',
			$method_block,
			'LC-017: log debe usar var_export($result, true) para distinguir false de 0.'
		);
	}

	/**
	 * El log debe mencionar que el pedido ya fue creado (order->save ya ocurrio)
	 * -es la razon por la que el fallo es critico (no se puede rollback).
	 */
	public function test_save_checkout_consent_guest_mentions_order_already_created(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'order->save() ya ocurrio',
			$source,
			'LC-017: log debe mencionar que order->save() ya ocurrio (por eso el fallo es critico - no se puede rollback).'
		);
	}

	/**
	 * El log debe incluir la base legal (Ley 1480/2011 + Ley 1581/2012 art. 9).
	 */
	public function test_save_checkout_consent_guest_mentions_legal_basis(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'Ley 1480/2011',
			$source,
			'LC-017: log debe mencionar Ley 1480/2011 (estatuto del consumidor).'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual con order_id.
	 */
	public function test_save_checkout_consent_guest_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString(
			'INSERT INTO',
			$source,
			'LC-017: log debe incluir SQL de reconciliacion manual (INSERT INTO...).'
		);

		$this->assertStringContainsString(
			'checkout_guest',
			$source,
			'LC-017: SQL de reconciliacion debe mencionar el consent_type checkout_guest.'
		);

		$this->assertStringContainsString(
			'web_guest_o',
			$source,
			'LC-017: SQL de reconciliacion debe mencionar el channel web_guest_o (con order_id).'
		);
	}

	// -- Fix tags de trazabilidad ------------------------------------------

	/**
	 * Todos los fixes del Ciclo 9 deben estar marcados con sus IDs en
	 * el codigo fuente.
	 */
	public function test_fix_tags_present_in_legal_compliance(): void {
		$this->assertFileExists( self::LC_PATH );
		$source = file_get_contents( self::LC_PATH );

		$this->assertStringContainsString( 'CICLO9-P1-LC-015 FIX', $source );
		$this->assertStringContainsString( 'CICLO9-P1-LC-016 FIX', $source );
		$this->assertStringContainsString( 'CICLO9-P1-LC-017 FIX', $source );
	}
}
