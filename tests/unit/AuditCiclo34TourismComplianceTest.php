<?php
/**
 * AuditCiclo34TourismComplianceTest - Tests para los fixes del Ciclo 34.
 *
 * Modulo auditado C34 (compliance legal turistica - FONTUR Colombia Ley 2068/2020):
 *   - includes/business/class-ltms-tourism-compliance-ext.php (458L) - extension NT-3 a NT-6
 *   - includes/business/class-ltms-business-tourism-compliance.php (469L) - base RNT/SECTUR
 *
 * C34 arranco desde checkpoint C33 (STOP CHECK cumplido) con trabajo previo de otra
 * sesion ya en working tree SIN commit (TC-001..TC-004). Esta sesion completo:
 *   - Auditoria de los 4 fixes previos (TC-001..TC-004) - correctos en intencion.
 *   - Auditoria EXTENDIDA: detecto H7 (P1 nuevo) y H8 (P2 backlog).
 *   - FIX H7 (TC-005): save_rnt retorna int|false + hook fire on !== false (no solo truthy).
 *
 * 5 fixes verificados por este test (TC-001..TC-005):
 *
 *  TC-001 P1 (H1+H3+H4) en class-ltms-tourism-compliance-ext.php:
 *    query_fontur_rnt() hacia wp_remote_post al portal FONTUR con:
 *      (H1) sin wp_remote_retrieve_response_code check - 404/500 con HTML casual
 *           conteniendo el RNT podia dar falso "verificado". Fix: code 200-299 obligatorio.
 *      (H3) strpos crudo fragil para token RNT - aparecia en URLs/headers/HTML adyacente
 *           dando falso positivo. Fix: regex con word boundary (?:^|[^\d])..(?:[^\d]|$).
 *      (H4) timeout 10s muy corto - canonico del proyecto es 30s (fintech-compliance:537,
 *           authorities-compliance:623). Portal FONTUR puede ser lento. Fix: timeout 30.
 *
 *  TC-002 P1 (H5) en class-ltms-tourism-compliance-ext.php:
 *    request_rc_insurance() llamaba wp_mail() sin verificar retorno. Si el mail falla
 *    (SMTP caido, bounce) el vendor nunca sabia que necesita subir póliza RC (NT-5 Res.
 *    FONTUR 0220/2020) y se quedaba atascado sin productos publicables sin explicacion.
 *    Fix: capturar $sent, loguear LTMS_Core_Logger::error RC_INSURANCE_REQUEST_MAIL_FAILED.
 *
 *  TC-003 P2 (H6) en class-ltms-tourism-compliance-ext.php:
 *    generate_fontur_report() docblock no aclaraba el flujo operacional (FONTUR no
 *    publica API oficial - se genera el reporte y se entrega por canal externo).
 *    Fix: docblock extendido aclarando flujo + cron `ltms_monthly_cron` agenda dia 1
 *    a las 03:30 UTC.
 *
 *  TC-004 P1 (H2) en class-ltms-business-tourism-compliance.php:
 *    ajax_save_rnt() NO llamaba do_action('ltms_save_rnt') JAMAS en todo el codebase.
 *    El listener auto_verify_rnt_fontur() en class-ltms-tourism-compliance-ext.php:20
 *    era dead-code-by-design (análogo a AVC-DEADHOOKS C32). Fix: disparar
 *    do_action('ltms_save_rnt', $vendor_id, $data) tras save_rnt exitoso.
 *
 *  TC-005 P1 (H7) en class-ltms-business-tourism-compliance.php (NUEVO esta sesion):
 *    save_rnt() retornaba (bool)$wpdb->update/insert, lo que convertia 0 filas
 *    idempotente (re-guardado con datos identicos) en false -> el dispatch del hook
 *    ltms_save_rnt en ajax_save_rnt (TC-004) NO se disparaba en re-guardado idempotente.
 *    La verificacion automatica RNT/FONTUR era no determinista (dependia de si los
 *    datos eran diferentes a los existentes). Fix: save_rnt retorna int|false
 *    (1/2 = filas reales, 0 = idempotente, false = error BD); ajax_save_rnt dispara
 *    hook cuando $saved !== false (incluye 0 idempotente = exito valido). false real
 *    reporta wp_send_json_error.
 *
 * H8 P2 (BACKLOG - NO fixeado por decision de usuario): query_fontur_rnt() usa
 *    wp_remote_POST a https://www.fontur.com.co/consultas/registro-nacional-de-turismo
 *    con body ['rnt'=>$rnt_number]. El portal FONTUR es una pagina de consulta
 *    publica con formulario GET, NO API REST que acepte POST. Puede retornar 405
 *    Method Not Allowed (o HTML completo que nunca contiene el RNT) -> fallback a
 *    manual perpetuo. Verificacion funcional en SG requerida. Documentado como
 *    backlog P2 #39 en LECCIONES_APRENDIDAS.md Leccion 34.1.
 *
 * Patron C34: source-based tests (file_get_contents + assertStringContains/
 * NotContainsString), mismo que C20-C33. Cross-checks acumulados (patron C33):
 *   - C25 invariantes webhooks (get_client_ip_safe sigue presente en webhooks).
 *   - C28 compliance-guardian tag CICLO28 sigue presente.
 *   - C29 sales-booster tags CICLO29 siguen presentes.
 *   - C30 fiscal-annual-close tags CICLO30 siguen presentes + hook accepted_args=3.
 *   - C31 tags CICLO31 siguen presentes en los 6 archivos migrados.
 *   - C32 aveonline-cities tags CICLO32 siguen presentes.
 *   - C33 invariante INTEGRATIONS-AUDIT P1 sslverify en api-aveonline.php (>=10 sitios)
 *     + CICLO33-P1-SSL-TOURISM-FONTUR sigue presente (TC-001 no toca sslverify, solo
 *     agrega response_code/timeout/word_boundary encima del fix C33).
 *   - Anti-regresion: geo-detector.php sigue con sslverify=>false + tag C33 ausente.
 *
 * @package LTMS\Tests\Unit
 * @covers CICLO34-P1-TC-001 FIX
 * @covers CICLO34-P1-TC-002 FIX
 * @covers CICLO34-P2-TC-003 FIX
 * @covers CICLO34-P1-TC-004 FIX
 * @covers CICLO34-P1-TC-005 FIX
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test suite source-based para los 5 fixes del Ciclo 34 en el modulo de
 * compliance turistica (NT-3 a NT-6 FONTUR Colombia Ley 2068/2020).
 */
class AuditCiclo34TourismComplianceTest extends LTMS_Unit_Test_Case {

	/**
	 * Rutas absolutas a los archivos auditados en C34.
	 */
	private const TOURISM_EXT_PATH       = __DIR__ . '/../../includes/business/class-ltms-tourism-compliance-ext.php';
	private const TOURISM_BASE_PATH      = __DIR__ . '/../../includes/business/class-ltms-business-tourism-compliance.php';

	/** Cross-check paths (ciclos previos - anti-regresion). */
	private const AVEONLINE_CITIES_PATH   = __DIR__ . '/../../includes/business/class-ltms-business-aveonline-cities.php';
	private const COMPLIANCE_GUARDIAN_PATH = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const SALES_BOOSTER_PATH      = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const FISCAL_ANNUAL_PATH      = __DIR__ . '/../../includes/business/class-ltms-fiscal-annual-close.php';
	private const API_AVEONLINE_PATH      = __DIR__ . '/../../includes/api/class-ltms-api-aveonline.php';
	private const GEO_DETECTOR_PATH       = __DIR__ . '/../../includes/frontend/class-ltms-geo-detector.php';
	private const WEBHOOKS_DIR            = __DIR__ . '/../../includes/api/webhooks';

	/**
	 * Tags CICLO34 esperados en los 2 archivos tocados.
	 */
	private const EXPECTED_C34_TAGS = [
		'CICLO34-P1-TC-001 FIX',
		'CICLO34-P1-TC-002 FIX',
		'CICLO34-P2-TC-003 FIX',
		'CICLO34-P1-TC-004 FIX',
		'CICLO34-P1-TC-005 FIX',
	];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'         => static fn( string $s ): string => $s,
			'esc_html__' => static fn( string $s ): string => $s,
		] );
	}

	// ====================================================================
	// Helpers de lectura de archivos fuente.
	// ====================================================================

	private static function read_source( string $path ): string {
		self::assertFileExists( $path, "Archivo fuente no encontrado: $path" );
		$contents = file_get_contents( $path );
		self::assertIsString( $contents, "No se pudo leer el archivo: $path" );
		return $contents;
	}

	// ====================================================================
	// TC-001 P1 (H1): wp_remote_retrieve_response_code check en query_fontur_rnt.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc001_h1_response_code_check_after_wp_error( string $source ): void {
		$this->assertStringContainsString(
			'$code = (int) wp_remote_retrieve_response_code( $response );',
			$source,
			'TC-001 H1: query_fontur_rnt debe verificar response code antes de leer body'
		);
		$this->assertStringContainsString(
			'if ( $code < 200 || $code >= 300 )',
			$source,
			'TC-001 H1: range 2xx obligatorio - 404/500/redirect con HTML del RNT no debe dar falso verificado'
		);
		$this->assertStringContainsString(
			"return null;",
			$source,
			'TC-001 H1: response code fuera de 2xx debe retornar null (fallback a manual)'
		);
	}

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc001_h1_response_code_check_antes_de_body( string $source ): void {
		// Orden: response code check debe aparecer antes de wp_remote_retrieve_body.
		$pos_code = strpos( $source, 'wp_remote_retrieve_response_code' );
		$pos_body = strpos( $source, 'wp_remote_retrieve_body' );
		$this->assertNotFalse( $pos_code, 'TC-001 H1: response_code call debe existir' );
		$this->assertNotFalse( $pos_body, 'wp_remote_retrieve_body call debe existir' );
		$this->assertLessThan(
			$pos_body,
			$pos_code,
			'TC-001 H1: response_code check debe aparecer ANTES de leer body (ordem correcto)'
		);
	}

	// ====================================================================
	// TC-001 P1 (H3): word boundary regex en lugar de strpos crudo.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc001_h3_word_boundary_regex_replaces_strpos( string $source ): void {
		// La regex con word boundary debe estar presente en la rama verified=true.
		$this->assertStringContainsString(
			"preg_match( '/(?:^|[^\\d])' . preg_quote( \$rnt_number, '/' ) . '(?:[^\\d]|$)/', \$body )",
			$source,
			'TC-001 H3: regex con word boundary (?:^|[^\\d])..(?:[^\\d]|$) debe reemplazar strpos crudo'
		);
		// El strpos crudo antiguo NO debe seguir presente (anti-regresion).
		$this->assertStringNotContainsString(
			"strpos( \$body, \$rnt_number )",
			$source,
			'TC-001 H3: strpos crudo fragil NO debe seguir presente (regresion)'
		);
	}

	// ====================================================================
	// TC-001 P1 (H4): timeout 30s (canonico del proyecto).
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc001_h4_timeout_30_canonico( string $source ): void {
		$this->assertStringContainsString(
			"'timeout' => 30",
			$source,
			'TC-001 H4: timeout debe ser 30 (canonico del proyecto, antes era 10s demasiado corto)'
		);
		$this->assertStringNotContainsString(
			"'timeout' => 10",
			$source,
			'TC-001 H4: timeout 10s (anterior fragil) NO debe seguir presente'
		);
	}

	// ====================================================================
	// TC-001 P1: sslverify C33 sigue presente (TC-001 NO lo toca).
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc001_preserva_sslverify_c33_canonico( string $source ): void {
		$this->assertStringContainsString(
			"'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),",
			$source,
			'TC-001 preserva sslverify canonico C33 (CICLO33-P1-SSL-TOURISM-FONTUR)'
		);
		$this->assertStringContainsString(
			'CICLO33-P1-SSL-TOURISM-FONTUR FIX',
			$source,
			'TC-001 NO debe borrar el tag C33 (regresion)'
		);
	}

	// ====================================================================
	// TC-002 P1 (H5): wp_mail retorno verificado + log error.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc002_h5_wp_mail_return_captured( string $source ): void {
		$this->assertStringContainsString(
			'$sent = wp_mail( $user->user_email, $subject, $message );',
			$source,
			'TC-002 H5: retorno de wp_mail debe capturarse en $sent'
		);
		$this->assertStringContainsString(
			'if ( ! $sent )',
			$source,
			'TC-002 H5: branch if (! $sent) debe existir para loguear error'
		);
	}

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc002_h5_logs_critical_error_on_failure( string $source ): void {
		$this->assertStringContainsString(
			'LTMS_Core_Logger::error(',
			$source,
			'TC-002 H5: fallo de wp_mail debe loguear con LTMS_Core_Logger::error'
		);
		$this->assertStringContainsString(
			"'RC_INSURANCE_REQUEST_MAIL_FAILED'",
			$source,
			'TC-002 H5: codigo de log RC_INSURANCE_REQUEST_MAIL_FAILED debe estar presente'
		);
	}

	// ====================================================================
	// TC-003 P2 (H6): docblock aclarando flujo operacional generate_fontur_report.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tc003_h6_docblock_aclara_fujo_operacional( string $source ): void {
		$this->assertStringContainsString(
			'CICLO34-P2-TC-003 FIX',
			$source,
			'TC-003 H6: tag CICLO34-P2-TC-003 FIX debe presente en docblock'
		);
		$this->assertStringContainsString(
			'FONTUR no publica API oficial',
			$source,
			'TC-003 H6: docblock debe explicar que FONTUR no publica API oficial'
		);
		$this->assertStringContainsString(
			'entrega humana',
			$source,
			'TC-003 H6: docblock debe mencionar entrega humana por canal externo'
		);
	}

	// ====================================================================
	// TC-004 P1 (H2): do_action('ltms_save_rnt', $vendor_id, $data) en ajax_save_rnt.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tc004_h2_do_action_ltms_save_rnt_present( string $source ): void {
		$this->assertStringContainsString(
			"do_action( 'ltms_save_rnt', \$vendor_id, \$data );",
			$source,
			'TC-004 H2: do_action(ltms_save_rnt, vendor_id, data) debe dispararse en ajax_save_rnt'
		);
		$this->assertStringContainsString(
			'CICLO34-P1-TC-004 FIX',
			$source,
			'TC-004 H2: tag CICLO34-P1-TC-004 FIX debe presente'
		);
	}

	// ====================================================================
	// TC-005 P1 (H7 - NUEVO esta sesion): save_rnt retorna int|false, fire on !== false.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tc005_h7_save_rnt_returns_int_or_false( string $source ): void {
		// La firma del metodo debe ser int|false (PHP 8.0+ union type).
		$this->assertStringContainsString(
			'public static function save_rnt( int $vendor_id, array $data ): int|false {',
			$source,
			'TC-005 H7: save_rnt debe retornar int|false (no bool) para distinguir 0-idempotente de false-error'
		);
		$this->assertStringContainsString(
			'CICLO34-P1-TC-005 FIX',
			$source,
			'TC-005 H7: tag CICLO34-P1-TC-005 FIX debe presente'
		);
	}

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tc005_h7_no_bool_cast_on_update_or_insert( string $source ): void {
		// Los cast (bool) deben eliminarse para preservar 0 idempotente como int.
		$this->assertStringNotContainsString(
			'return (bool) $wpdb->update(',
			$source,
			'TC-005 H7: (bool) cast en update derrota el 0-idempotente (regresion)'
		);
		$this->assertStringNotContainsString(
			'return (bool) $wpdb->insert(',
			$source,
			'TC-005 H7: (bool) cast en insert derrota el 0-idempotente (regresion)'
		);
	}

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tc005_h7_dispatch_on_strict_not_false( string $source ): void {
		// El check debe ser $saved !== false (no truthy) - 0 idempotente dispara el hook.
		$this->assertStringContainsString(
			'if ( $saved !== false )',
			$source,
			'TC-005 H7: dispatch debe ser $saved !== false (no solo truthy) - 0 idempotente dispara'
		);
		$this->assertStringNotContainsString(
			'if ( $saved ) {',
			$source,
			'TC-005 H7: check truthy if ($saved) NO debe seguir presente (regresion de TC-004)'
		);
	}

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tc005_h7_error_branch_reports_wp_send_json_error( string $source ): void {
		// $saved === false (error real BD) debe reportar wp_send_json_error al usuario.
		$this->assertStringContainsString(
			"wp_send_json_error( __( 'Error al guardar la informaci",
			$source,
			'TC-005 H7: error real (false) debe reportar wp_send_json_error, no marcar success'
		);
	}

	// ====================================================================
	// HALLAZGO H8 P2 (BACKLOG): wp_remote_post a endpoint GET-form FONTUR.
	// ====================================================================

	/**
	 * H8 P2: NO hay fix aplicado (decision de usuario: documentar como backlog).
	 * Este test DOCUMENTA la situacion actual como proteccion anti-regresion -
	 * si alguien "arregla" el metodo HTTP sin pasar por decision de negocio,
	 * este test falla y fuerza la pausa.
	 *
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_h8_backlog_documented_post_method_preserved( string $source ): void {
		// H8 documentado en Leccion 34.1 - NO fixeado por decision de usuario.
		// Verificacion funcional en SG requerida. Este test snapshot el estado.
		$this->assertStringContainsString(
			"wp_remote_post( \$url, [",
			$source,
			'H8 P2 backlog: wp_remote_post sigue presente (POST a endpoint GET-form). '
			. 'Verificar en SG antes de cambiar metodo - decision de negocio pendiente.'
		);
		$this->assertStringContainsString(
			"'body'    => [ 'rnt' => \$rnt_number ]",
			$source,
			'H8 P2 backlog: body rnt en wp_remote_post sigue presente. '
			. 'Snapshot anti-regresion - forzar decision antes de cambios.'
		);
	}

	// ====================================================================
	// CARDINALIDAD: todos los tags CICLO34 presentes (5 tags en 2 archivos).
	// ====================================================================

	public function test_total_c34_tags_count_is_5(): void {
		$ext  = self::read_source( self::TOURISM_EXT_PATH );
		$base = self::read_source( self::TOURISM_BASE_PATH );
		$combined = $ext . $base;

		$total = 0;
		foreach ( self::EXPECTED_C34_TAGS as $tag ) {
			$count = substr_count( $combined, $tag );
			$this->assertGreaterThanOrEqual( 1, $count, "Tag C34 debe presente al menos 1 vez: $tag (found $count)" );
			$total += $count;
		}
		$this->assertGreaterThanOrEqual( 5, $total, 'Total ocurrencias de tags CICLO34-* debe ser >= 5 (1 por fix, puede haber mas por docblock/comments)' );
	}

	public function test_each_c34_tag_present_at_least_once(): void {
		$ext  = self::read_source( self::TOURISM_EXT_PATH );
		$base = self::read_source( self::TOURISM_BASE_PATH );
		$combined = $ext . $base;

		foreach ( self::EXPECTED_C34_TAGS as $tag ) {
			$this->assertStringContainsString(
				$tag,
				$combined,
				"Tag C34 debe presente: $tag"
			);
		}
	}

	// ====================================================================
	// ANTI-REGRESION: error_log / php -l / estructura basica.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_tourism_ext_opens_with_abspath_guard( string $source ): void {
		$this->assertStringContainsString(
			"if ( ! defined( 'ABSPATH' ) ) { exit; }",
			$source,
			'Guard ABSPATH debe presente al inicio de class-ltms-tourism-compliance-ext.php'
		);
	}

	/**
	 * @dataProvider provide_tourism_base_source
	 */
	public function test_tourism_base_opens_with_abspath_guard( string $source ): void {
		$this->assertStringContainsString(
			"if ( ! defined( 'ABSPATH' ) ) {",
			$source,
			'Guard ABSPATH debe presente al inicio de class-ltms-business-tourism-compliance.php'
		);
	}

	// ====================================================================
	// CROSS-CHECKS ANTI-REGRESION (ciclos previos siguen intactos).
	// ====================================================================

	public function test_cross_check_c28_compliance_guardian_tag_preserved(): void {
		$source = self::read_source( self::COMPLIANCE_GUARDIAN_PATH );
		$this->assertStringContainsString( 'CICLO28', $source, 'Tag(s) CICLO28 sigue presente en compliance-guardian.php' );
	}

	public function test_cross_check_c29_sales_booster_tags_preserved(): void {
		$source = self::read_source( self::SALES_BOOSTER_PATH );
		$this->assertStringContainsString( 'CICLO29', $source, 'Tag(s) CICLO29 sigue presente en sales-booster.php' );
	}

	public function test_cross_check_c30_fiscal_annual_close_tags_preserved(): void {
		$source = self::read_source( self::FISCAL_ANNUAL_PATH );
		$this->assertStringContainsString( 'CICLO30', $source, 'Tag(s) CICLO30 sigue presente en fiscal-annual-close.php' );
	}

	public function test_cross_check_c32_aveonline_cities_tags_preserved(): void {
		$source = self::read_source( self::AVEONLINE_CITIES_PATH );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-001 FIX', $source, 'Tag CICLO32-P1-AVC-001 sigue presente' );
		$this->assertStringContainsString( 'CICLO32-P1-AVC-002 FIX', $source, 'Tag CICLO32-P1-AVC-002 sigue presente' );
	}

	public function test_cross_check_c33_invariante_sslverify_api_aveonline(): void {
		$source = self::read_source( self::API_AVEONLINE_PATH );
		$matches = substr_count( $source, "'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY )," );
		$this->assertGreaterThanOrEqual(
			10,
			$matches,
			'Invariante INTEGRATIONS-AUDIT P1: api-aveonline.php debe tener >= 10 sitios con patron sslverify canonico'
		);
	}

	public function test_cross_check_c33_tourism_fontur_sslverify_preserved(): void {
		$source = self::read_source( self::TOURISM_EXT_PATH );
		$this->assertStringContainsString(
			"'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),",
			$source,
			'TC-001 NO debe tocar sslverify canonico C33 (preservar fix CICLO33-P1-SSL-TOURISM-FONTUR)'
		);
	}

	public function test_cross_check_c33_geo_detector_exception_preserved(): void {
		$source = self::read_source( self::GEO_DETECTOR_PATH );
		$this->assertStringContainsString(
			"'sslverify' => false",
			$source,
			'Excepcion documentada geo-detector http://ip-api.com sigue con sslverify=>false (C33 regla #5)'
		);
		$this->assertStringNotContainsString(
			'CICLO33-P1-SSL-',
			$source,
			'Tag C33 NO debe presente en geo-detector.php (excepcion no-aplica, anti-regresion)'
		);
	}

	public function test_cross_check_c25_get_client_ip_safe_in_webhooks(): void {
		$webhooks_dir = self::WEBHOOKS_DIR;
		$this->assertDirectoryExists( $webhooks_dir, 'Directorio webhooks debe existir' );

		$any_with_safe_ip = false;
		foreach ( glob( $webhooks_dir . '/*.php' ) as $file ) {
			$contents = file_get_contents( $file );
			if ( strpos( $contents, 'get_client_ip_safe' ) !== false
				|| strpos( $contents, 'LTMS_Core_Security::get_client_ip_safe' ) !== false ) {
				$any_with_safe_ip = true;
				break;
			}
		}
		$this->assertTrue(
			$any_with_safe_ip,
			'Invariante C25: al menos un handler en webhooks/ debe usar get_client_ip_safe para IP resolution'
		);
	}

	// ====================================================================
	// HOOK WIRING ANTI-REGRESION.
	// ====================================================================

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_hook_ltms_save_rnt_listener_registered( string $source ): void {
		// Listener debe seguir registrado - TC-004 lo hace util ahora.
		$this->assertStringContainsString(
			"add_action( 'ltms_save_rnt', [ __CLASS__, 'auto_verify_rnt_fontur' ], 10, 2 );",
			$source,
			'Listener ltms_save_rnt -> auto_verify_rnt_fontur debe seguir registrado con accepted_args=2'
		);
	}

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_hook_ltms_monthly_cron_listener_registered( string $source ): void {
		// generate_fontur_report debe seguir registrado en ltms_monthly_cron (RB-1 fix v2.9.19).
		$this->assertStringContainsString(
			"add_action( 'ltms_monthly_cron', [ __CLASS__, 'generate_fontur_report' ] );",
			$source,
			'Listener ltms_monthly_cron -> generate_fontur_report debe seguir registrado (cron ya agendado por activator RB-1 fix v2.9.19)'
		);
	}

	/**
	 * @dataProvider provide_tourism_ext_source
	 */
	public function test_hook_ltms_rnt_approved_fire_site_is_auto_verify( string $source ): void {
		// do_action('ltms_rnt_approved', $vendor_id) debe estar en auto_verify_rnt_fontur
		// (es el fire-site que dispara request_rc_insurance -> TC-002 aplica al mail de ese listener).
		$this->assertStringContainsString(
			"do_action( 'ltms_rnt_approved', \$vendor_id );",
			$source,
			'Fire-site ltms_rnt_approved debe presente en auto_verify_rnt_fontur (trigger request_rc_insurance)'
		);
	}

	// ====================================================================
	// DataProviders.
	// ====================================================================

	public function provide_tourism_ext_source(): array {
		$source = self::read_source( self::TOURISM_EXT_PATH );
		return [ 'class-ltms-tourism-compliance-ext.php' => [ $source ] ];
	}

	public function provide_tourism_base_source(): array {
		$source = self::read_source( self::TOURISM_BASE_PATH );
		return [ 'class-ltms-business-tourism-compliance.php' => [ $source ] ];
	}
}
