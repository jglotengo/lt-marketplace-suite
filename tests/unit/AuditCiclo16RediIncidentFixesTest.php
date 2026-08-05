<?php
/**
 * AuditCiclo16RediIncidentFixesTest - Tests para los fixes del Ciclo 16.
 *
 * Cubre los fixes aplicados a
 * includes/business/class-ltms-business-redi-incident.php
 * (LTMS_Business_Redi_Incident - gestiona el ciclo de vida de
 * incidencias/novedades reportadas sobre pedidos ReDi). Modulo
 * critico: lt_redi_incidents + lt_redi_incident_comments + SLA
 * (48h primera respuesta, 15d resolucion) + auto-acciones
 * (soft_pause freeze comisiones). Afecta experiencia proveedor
 * (SAGRILAFT UX-GAPS GAP-9) y SLA regulatorio.
 *
 *   - RI-042 P1: add_comment() $wpdb->update bump updated_at en
 *     lt_redi_incidents (linea 346 pre-fix) NO se verificaba. Si
 *     fallaba, el comentario se insertaba OK (INSERT linea 323 ya
 *     verificado) pero la cabecera no reflejaba el bump -> el cron
 *     sla_check_cron() (lineas 570, 603) filtraba WHERE status IN
 *     ('open','investigating') AND sla_due_at < now y escalaba
 *     basado en updated_at obsoleto -> el cron podia escalar
 *     erróneamente una incidencia que tuvo actividad reciente
 *     solo porque la cabecera no registro el bump. SLA de primera
 *     respuesta (48h) y SLA de resolucion (15d) son criticos
 *     regulatorios de experiencia proveedor.
 *     Fix: capturar $bumped + check false === + log critico
 *     REDI_INCIDENT_BUMP_UPDATED_AT_FAILED con SQL de
 *     reconciliacion manual. No se aborta el add_comment (el
 *     comentario YA esta guardado) - solo se loguea critico para
 *     reproceso manual.
 *   - RI-043 P1: notify_incident_created() 2x $wpdb->insert en
 *     lt_notifications (lineas 691 y 749 pre-fix - vendors
 *     recipients + admin) NO se verificaban. Si fallaban, neither
 *     vendedores nor admin recibian aviso in-app de la incidencia
 *     nueva -> el SLA de primera respuesta (48h) corria sin que
 *     los vendedores seenteraran por la plataforma, enterandose
 *     solo via email (canal secundario, a menudo ignorado en
 *     mobile). El admin necesita la notificacion temprana (no la
 *     de overdue del cron) para gestionar el riesgo proactivamente.
 *     Fix: capturar $inserted_notif (vendor) + $inserted_admin_notif
 *     (admin) + check false === + log critico
 *     REDI_INCIDENT_NOTIFY_CREATED_INSERT_FAILED con SQL de
 *     reconciliacion manual.
 *   - RI-044 P1: notify_incident_comment() $wpdb->insert en
 *     lt_notifications (linea 818 pre-fix) NO se verificaba. Si
 *     fallaba, la parte contraria no recibia alerta in-app del
 *     comentario nuevo -> el hilo de la incidencia se fragmenta:
 *     el autor piensa que la otra parte leyo el mensaje y la otra
 *     parte no se entera hasta proximo login a la plataforma
 *     (canal email es secundario). Esto extiende el SLA de
 *     primera respuesta y resolucion (SLA 48h/15d) sin que el
 *     cron detecte overdue (el comentario SI esta insertado en
 *     BD - solo la notif in-app fallaba).
 *     Fix: capturar $inserted_comment_notif + check false === +
 *     log critico REDI_INCIDENT_NOTIFY_COMMENT_INSERT_FAILED con
 *     SQL de reconciliacion manual.
 *   - RI-045 P1: notify_incident_status_change() $wpdb->insert
 *     en lt_notifications (linea 891 pre-fix) NO se verificaba.
 *     Si fallaba, los vendedores no recibian alerta in-app del
 *     cambio de estado (escalado/resolucion) -> el admin cambia
 *     el estado a "resolved" pero el vendor no se entera en
 *     plataforma -> piensa que la incidencia sigue abierta, le da
 *     follow-up innecesario al admin, o peor, abre un nuevo
 *     incident duplicado.
 *     Fix: capturar $inserted_status_notif + check false === +
 *     log critico REDI_INCIDENT_NOTIFY_STATUS_INSERT_FAILED con
 *     SQL de reconciliacion manual.
 *
 * Hallazgos descartados:
 *   - INSERT en create() (linea 149): ya verificado con
 *     if ( ! $inserted ).
 *   - INSERT en add_comment() (linea 323): ya verificado.
 *   - UPDATE en change_status() (linea 431): ya verificado con
 *     false === $updated.
 *   - $wpdb->query en run_auto_actions() (linea 257 freeze
 *     comisiones): ya verificado con false !== $frozen.
 *   - Cron sla_check_cron(): queries parametrizadas, usa
 *     change_status(0) que pasa el check user_can (defense-in-
 *     depth AUDIT-RD-BK RD-4 FIX ya contempla cron).
 *   - AJAX handlers (5): todos con check_ajax_referer + cap check
 *     manage_woocommerce + sanitizacion. Correctos.
 *   - AUDIT-RD-BK RD-4 FIX (defense-in-depth authz en
 *     change_status): ya presente - no toca.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers RI-042, RI-043, RI-044, RI-045
 */
class AuditCiclo16RediIncidentFixesTest extends LTMS_Unit_Test_Case {

	private const RI_PATH = __DIR__ . '/../../includes/business/class-ltms-business-redi-incident.php';

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

	// -- RI-042 P1: add_comment $wpdb->update bump updated_at verifica ----

	/**
	 * El UPDATE debe capturarse en $bumped (no ser llamada suelta).
	 */
	public function test_add_comment_captures_update_in_bumped_variable(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function add_comment' );
		$this->assertNotFalse( $method_pos, 'add_comment debe existir.' );

		// add_comment mide ~5,000 bytes post-fix.
		$method_block = substr( $source, $method_pos, 5500 );

		$this->assertStringContainsString(
			'$bumped = $wpdb->update(',
			$method_block,
			'RI-042: el UPDATE de bump updated_at debe capturar su retorno en $bumped (no ser statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $bumped dentro de add_comment.
	 */
	public function test_add_comment_checks_false_explicitly(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function add_comment' );
		$this->assertNotFalse( $method_pos, 'add_comment debe existir.' );

		$method_block = substr( $source, $method_pos, 5500 );

		$this->assertStringContainsString(
			'false === $bumped',
			$method_block,
			'RI-042: check explicito false === $bumped debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico REDI_INCIDENT_BUMP_UPDATED_AT_FAILED.
	 */
	public function test_add_comment_failure_logs_critical(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function add_comment' );
		$this->assertNotFalse( $method_pos, 'add_comment debe existir.' );

		$method_block = substr( $source, $method_pos, 5500 );

		// Buscar el token critico.
		$token_pos = strpos( $method_block, "'REDI_INCIDENT_BUMP_UPDATED_AT_FAILED'," );
		$this->assertNotFalse( $token_pos, 'RI-042: log REDI_INCIDENT_BUMP_UPDATED_AT_FAILED debe existir.' );

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $method_block, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RI-042: el log debe ser critico (no warning), fallo de bump updated_at afecta SLA check del cron.'
		);
	}

	/**
	 * El log debe incluir SQL de reconciliacion manual UPDATE
	 * lt_redi_incidents.
	 */
	public function test_add_comment_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString(
			'UPDATE %slt_redi_incidents SET updated_at=UTC_TIMESTAMP() WHERE id=%d',
			$source,
			'RI-042: log debe incluir SQL de reconciliacion manual UPDATE lt_redi_incidents.'
		);
	}

	/**
	 * El fix tag CICLO16-P1-RI-042 FIX debe estar presente.
	 */
	public function test_ri042_fix_tag_present(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString( 'CICLO16-P1-RI-042 FIX', $source );
	}

	// -- RI-043 P1: notify_incident_created $wpdb->insert (vendors + admin) --

	/**
	 * El INSERT para vendors debe capturarse en $inserted_notif.
	 */
	public function test_notify_incident_created_captures_vendor_insert(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_created' );
		$this->assertNotFalse( $method_pos, 'notify_incident_created debe existir.' );

		// notify_incident_created mide ~22,000 bytes post-fix (largo
		// porque incluye template email + 2 INSERTs verificados + log
		// critico extendido para vendors + admin).
		$method_block = substr( $source, $method_pos, 23000 );

		$this->assertStringContainsString(
			'$inserted_notif = $wpdb->insert(',
			$method_block,
			'RI-043: el INSERT de vendor notif debe capturar su retorno en $inserted_notif (no statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_notif.
	 */
	public function test_notify_incident_created_checks_vendor_false_explicitly(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_created' );
		$this->assertNotFalse( $method_pos, 'notify_incident_created debe existir.' );

		$method_block = substr( $source, $method_pos, 23000 );

		$this->assertStringContainsString(
			'false === $inserted_notif',
			$method_block,
			'RI-043: check explicito false === $inserted_notif debe estar presente.'
		);
	}

	/**
	 * El INSERT para admin debe capturarse en $inserted_admin_notif.
	 */
	public function test_notify_incident_created_captures_admin_insert(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_created' );
		$this->assertNotFalse( $method_pos, 'notify_incident_created debe existir.' );

		$method_block = substr( $source, $method_pos, 23000 );

		$this->assertStringContainsString(
			'$inserted_admin_notif = $wpdb->insert(',
			$method_block,
			'RI-043: el INSERT de admin notif debe capturar su retorno en $inserted_admin_notif (no statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_admin_notif.
	 */
	public function test_notify_incident_created_checks_admin_false_explicitly(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_created' );
		$this->assertNotFalse( $method_pos, 'notify_incident_created debe existir.' );

		$method_block = substr( $source, $method_pos, 23000 );

		$this->assertStringContainsString(
			'false === $inserted_admin_notif',
			$method_block,
			'RI-043: check explicito false === $inserted_admin_notif debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * REDI_INCIDENT_NOTIFY_CREATED_INSERT_FAILED.
	 */
	public function test_notify_incident_created_failure_logs_critical(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$token_pos = strpos( $source, "'REDI_INCIDENT_NOTIFY_CREATED_INSERT_FAILED'," );
		$this->assertNotFalse(
			$token_pos,
			'RI-043: log REDI_INCIDENT_NOTIFY_CREATED_INSERT_FAILED debe existir.'
		);

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RI-043: el log debe ser critico (no warning), fallo de INSERT en notif compromete SLA.'
		);
	}

	/**
	 * El log debe mencionar INSERT INTO %slt_notifications con
	 * 'redi_incident_created' como type.
	 */
	public function test_notify_incident_created_log_includes_reconciliation_sql(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		// El source usa backslash-escaped single quotes dentro de un
		// string delimitado por single quotes PHP: \'redi_incident_created\'
		// Buscar el patron literal con \\ para que coincida.
		$this->assertStringContainsString(
			"\\'redi_incident_created\\', \\'inapp\\'",
			$source,
			'RI-043: SQL de reconciliacion debe mencionar type=redi_incident_created channel=inapp.'
		);
	}

	/**
	 * El fix tag CICLO16-P1-RI-043 FIX debe estar presente (en 2
	 * sitios: vendors + admin).
	 */
	public function test_ri043_fix_tag_present(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		// Verificar que hay al menos 1 ocurrencia del fix tag.
		$this->assertStringContainsString( 'CICLO16-P1-RI-043 FIX', $source );
	}

	// -- RI-044 P1: notify_incident_comment $wpdb->insert (vendors recipients)

	/**
	 * El INSERT debe capturarse en $inserted_comment_notif.
	 */
	public function test_notify_incident_comment_captures_insert(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_comment' );
		$this->assertNotFalse( $method_pos, 'notify_incident_comment debe existir.' );

		// notify_incident_comment mide ~13,000 bytes post-fix.
		$method_block = substr( $source, $method_pos, 14000 );

		$this->assertStringContainsString(
			'$inserted_comment_notif = $wpdb->insert(',
			$method_block,
			'RI-044: el INSERT debe capturar su retorno en $inserted_comment_notif (no statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_comment_notif.
	 */
	public function test_notify_incident_comment_checks_false_explicitly(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_comment' );
		$this->assertNotFalse( $method_pos, 'notify_incident_comment debe existir.' );

		$method_block = substr( $source, $method_pos, 14000 );

		$this->assertStringContainsString(
			'false === $inserted_comment_notif',
			$method_block,
			'RI-044: check explicito false === $inserted_comment_notif debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * REDI_INCIDENT_NOTIFY_COMMENT_INSERT_FAILED.
	 */
	public function test_notify_incident_comment_failure_logs_critical(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$token_pos = strpos( $source, "'REDI_INCIDENT_NOTIFY_COMMENT_INSERT_FAILED'," );
		$this->assertNotFalse(
			$token_pos,
			'RI-044: log REDI_INCIDENT_NOTIFY_COMMENT_INSERT_FAILED debe existir.'
		);

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RI-044: el log debe ser critico (no warning).'
		);
	}

	/**
	 * El log debe mencionar 'redi_incident_comment' como type.
	 */
	public function test_notify_incident_comment_log_includes_type(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString(
			"\\'redi_incident_comment\\', \\'inapp\\'",
			$source,
			'RI-044: SQL de reconciliacion debe mencionar type=redi_incident_comment channel=inapp.'
		);
	}

	/**
	 * El fix tag CICLO16-P1-RI-044 FIX debe estar presente.
	 */
	public function test_ri044_fix_tag_present(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString( 'CICLO16-P1-RI-044 FIX', $source );
	}

	// -- RI-045 P1: notify_incident_status_change $wpdb->insert verifica ----

	/**
	 * El INSERT debe capturarse en $inserted_status_notif.
	 */
	public function test_notify_incident_status_change_captures_insert(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_status_change' );
		$this->assertNotFalse( $method_pos, 'notify_incident_status_change debe existir.' );

		// notify_incident_status_change mide ~7,500 bytes post-fix.
		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'$inserted_status_notif = $wpdb->insert(',
			$method_block,
			'RI-045: el INSERT debe capturar su retorno en $inserted_status_notif (no statement suelta).'
		);
	}

	/**
	 * Debe haber verificacion false === $inserted_status_notif.
	 */
	public function test_notify_incident_status_change_checks_false_explicitly(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function notify_incident_status_change' );
		$this->assertNotFalse( $method_pos, 'notify_incident_status_change debe existir.' );

		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'false === $inserted_status_notif',
			$method_block,
			'RI-045: check explicito false === $inserted_status_notif debe estar presente.'
		);
	}

	/**
	 * El log de fallo debe ser critico
	 * REDI_INCIDENT_NOTIFY_STATUS_INSERT_FAILED.
	 */
	public function test_notify_incident_status_change_failure_logs_critical(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$token_pos = strpos( $source, "'REDI_INCIDENT_NOTIFY_STATUS_INSERT_FAILED'," );
		$this->assertNotFalse(
			$token_pos,
			'RI-045: log REDI_INCIDENT_NOTIFY_STATUS_INSERT_FAILED debe existir.'
		);

		// Tomar 250 chars antes del token - debe contener ::critical(.
		$before_token = substr( $source, max( 0, $token_pos - 250 ), 250 );
		$this->assertStringContainsString(
			'::critical(',
			$before_token,
			'RI-045: el log debe ser critico (no warning).'
		);
	}

	/**
	 * El log debe mencionar 'redi_incident_status' como type.
	 */
	public function test_notify_incident_status_change_log_includes_type(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString(
			"\\'redi_incident_status\\', \\'inapp\\'",
			$source,
			'RI-045: SQL de reconciliacion debe mencionar type=redi_incident_status channel=inapp.'
		);
	}

	/**
	 * El fix tag CICLO16-P1-RI-045 FIX debe estar presente.
	 */
	public function test_ri045_fix_tag_present(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString( 'CICLO16-P1-RI-045 FIX', $source );
	}

	// -- Cross-check: INSERTs ya verificados siguen OK ----

	/**
	 * Cross-check: el INSERT en create() (linea 149 pre-fix) debe
	 * seguir verificado con if ( ! $inserted ) (regresion guard -
	 * no debe romper lo ya hardenado).
	 */
	public function test_create_insert_still_verified(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function create' );
		$this->assertNotFalse( $method_pos, 'create debe existir.' );

		// create() mide ~5,000 bytes.
		$method_block = substr( $source, $method_pos, 5500 );

		$this->assertStringContainsString(
			'$inserted = $wpdb->insert(',
			$method_block,
			'Cross-check: el INSERT en create() debe seguir capturando su retorno en $inserted (regresion guard).'
		);

		$this->assertStringContainsString(
			'if ( ! $inserted )',
			$method_block,
			'Cross-check: el check if ( ! $inserted ) en create() debe seguir presente (regresion guard).'
		);
	}

	/**
	 * Cross-check: el UPDATE en change_status() (linea 431
	 * pre-fix) debe seguir verificado con false === $updated.
	 */
	public function test_change_status_update_still_verified(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$method_pos = strpos( $source, 'function change_status' );
		$this->assertNotFalse( $method_pos, 'change_status debe existir.' );

		// change_status() mide ~7,500 bytes.
		$method_block = substr( $source, $method_pos, 8000 );

		$this->assertStringContainsString(
			'$updated = $wpdb->update(',
			$method_block,
			'Cross-check: el UPDATE en change_status() debe seguir capturando su retorno.'
		);

		$this->assertStringContainsString(
			'false === $updated',
			$method_block,
			'Cross-check: el check false === $updated en change_status() debe seguir presente.'
		);
	}

	/**
	 * Cross-check: el AUDIT-RD-BK RD-4 FIX (defense-in-depth authz
	 * en change_status con user_can check) debe seguir presente.
	 */
	public function test_rd4_defense_in_depth_authz_still_present(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString(
			"AUDIT-RD-BK RD-4 FIX",
			$source,
			'Cross-check: el AUDIT-RD-BK RD-4 FIX (defense-in-depth authz) debe seguir presente en change_status.'
		);

		$this->assertStringContainsString(
			"user_can( \$user, 'manage_woocommerce' )",
			$source,
			'Cross-check: el check user_can(manage_woocommerce) debe seguir presente.'
		);
	}

	/**
	 * Cross-check: todos los AJAX handlers siguen con nonce check
	 * (regresion guard - los fixes no tocan los handlers).
	 */
	public function test_ajax_handlers_keep_nonce_check(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		// Verificar que hay al menos 5 ocurrencias de check_ajax_referer
		// (uno por handler: create, add_comment, get_incidents,
		// get_incident_detail, admin_change_status).
		$count = substr_count( $source, "check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' )" );
		$this->assertGreaterThanOrEqual(
			5,
			$count,
			'Cross-check: los 5 AJAX handlers deben seguir con check_ajax_referer (regresion guard).'
		);
	}

	/**
	 * Cross-check: las constantes STATUSES y TYPES siguen definidas
	 * con allowlist (regresion guard).
	 */
	public function test_allowlists_still_defined(): void {
		$this->assertFileExists( self::RI_PATH );
		$source = file_get_contents( self::RI_PATH );

		$this->assertStringContainsString(
			"public const STATUSES = [ 'open', 'investigating', 'escalated', 'resolved', 'closed' ];",
			$source,
			'Cross-check: STATUSES allowlist debe seguir definida.'
		);

		$this->assertStringContainsString(
			"public const TYPES = [ 'stockout', 'complaint', 'quality', 'shipping', 'payment', 'other' ];",
			$source,
			'Cross-check: TYPES allowlist debe seguir definida.'
		);
	}
}
