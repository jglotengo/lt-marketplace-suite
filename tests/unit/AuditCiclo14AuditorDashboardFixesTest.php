<?php
/**
 * AuditCiclo14AuditorDashboardFixesTest - Tests para los fixes del Ciclo 14.
 *
 * Cubre los fixes aplicados a
 * includes/admin/views/view-auditor-dashboard.php (panel del auditor
 * externo - reportes SAGRILAFT/SOS a UIAF/SHCP, Art. 30-B CFF / E.T.
 * Art. 437-2 CO, modulo critico regulatorio):
 *
 *   - AD-033 P1: $country se sanitizaba (sanitize_text_field) pero no se
 *     validaba contra allowlist de codigos ISO-3166-1 alpha-2
 *     soportados. Un auditor podia pasar country=MX' OR 1=1 -- para
 *     intentar bypasear el filtro de pais (esc_sql escapa comillas pero
 *     NO es la validacion adecuada para un codigo de pais cuyo dominio
 *    	es { '', 'MX', 'CO' }). Ademas, el SQL interpolaba el valor con
 *    	esc_sql en vez de usar placeholder %s via $wpdb->prepare
 *    	(defense-in-depth: validacion + prepared statement).
 *    	Fix: validacion allowlist in_array($country, ['MX','CO'], true)
 *    	+ $country_sql construido via $wpdb->prepare('AND country_code
 *    	= %s', $country). Mismo principio para $event_level (allowlist
 *    	de severidades).
 *   - AD-034 P1: $date_from/$date_to se sanitizaban pero no se
 *    	validaban como fecha ISO (Y-m-d). Un auditor podia pasar
 *    	date_from=invalid-string y el BETWEEN %s AND %s se ejecutaba con
 *    	strings no-fecha -> comportamiento impredecible en MySQL (podia
 *    	retornar todas o ninguna fila, rompiendo el reporte fiscal del
 *    	Art. 30-B CFF / E.T. 437-2 que debe ser deterministico y
 *    	auditable).
 *    	Fix: validacion explicita con DateTime::createFromFormat('Y-m-d')
 *    	+ verificacion de round-trip (format('Y-m-d') === $raw). Si
 *    	invalido, fallback al default (first-of-month / today).
 *   - AD-035 P1: inconsistencia fiscal panel web vs CSV export. La
 *    	query del panel (linea 420 pre-fix) siempre usaba
 *    	SUM(COALESCE(c.ieps_amount,0)) AS ieps_retenido mostrando el
 *    	IEPS TRASLADADO en la columna f-vii) IEPS_RETENIDO del Art. 30-B
 *    	Frac. II. El CSV export (linea 154 pre-fix) ya tenia la logica
 *    	correcta: COALESCE(c.ieps_retenido, c.ieps_amount, 0) cuando la
 *    	columna opcional ieps_retenido existe. Para Mexico, ieps_amount
 *    	es el IEPS trasladado (a cargo del cliente) y ieps_retenido es
 *    	el retenido (a cargo de la plataforma) - son conceptos fiscales
 *    	diferentes con tratamientos distintos en LIEPS Art. 2. La
 *    	inconsistencia comprometia la determinismo del reporte fiscal
 *    	entre lo que el auditor ve en pantalla y lo que descarga en CSV.
 *    	Fix: deteccion de columna opcional $has_iepsr via $cols_exist
 *    	+ uso del mismo patron condicional COALESCE que el CSV:
 *    	SUM(COALESCE($has_iepsr ? c.ieps_retenido : c.ieps_amount, 0))
 *    	AS ieps_retenido.
 *
 * Hallazgos descartados:
 *   - SQL injection en SHOW COLUMNS (lineas 112, 373): es DDL contra
 *    	tabla fija {$wpdb->prefix}lt_commissions con prefijo interpolado
 *    	(proveniente de configuracion WP, no user input). No hay
 *    	placeholders user-controlled. Defensive pero no P1.
 *   - XSS en outputs: todas las salidas del view usan esc_html /
 *    	esc_attr / esc_url / ltms_na (que escapa). Na que corregir.
 *   - CSV injection: ya protecteda por AUDIT-PANEL-CSV-001 FIX
 *    	(lineas 197-209, $csv_fieldprepend "'" a celdas que empiezan
 *    	con = + - @ \t \r). Na que corregir.
 *   - Cap check: linea 22 current_user_can('ltms_access_auditor_dashboard') + wp_die esc_html. Correcto.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers AD-033, AD-034, AD-035
 */
class AuditCiclo14AuditorDashboardFixesTest extends LTMS_Unit_Test_Case {

	private const AD_PATH = __DIR__ . '/../../includes/admin/views/view-auditor-dashboard.php';

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

	// -- AD-033 P1: validacion allowlist de country + $wpdb->prepare ----

	/**
	 * El test verifica que $country se valida contra allowlist de codigos
	 * ISO-3166-1 alpha-2 soportados (in_array con ['MX', 'CO']).
	 */
	public function test_country_validated_against_allowlist(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Buscar la validacion allowlist de country.
		$this->assertStringContainsString(
			"in_array( \$country, [ 'MX', 'CO' ], true )",
			$source,
			'AD-033: $country debe validarse contra allowlist [MX, CO] con strict comparison.'
		);
	}

	/**
	 * El test verifica que $event_level se valida contra allowlist de
	 * severidades (defense-in-depth igual que country).
	 */
	public function test_event_level_validated_against_allowlist(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			"in_array( \$raw_level, [ 'critical', 'high', 'medium', 'low', 'info' ], true )",
			$source,
			'AD-033: $event_level debe validarse contra allowlist de severidades.'
		);
	}

	/**
	 * El test verifica que $country_sql se construye via $wpdb->prepare
	 * con placeholder %s (no interpolacion directa con esc_sql).
	 */
	public function test_country_sql_uses_prepare_with_placeholder(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Buscar el uso de $wpdb->prepare para country_sql.
		$this->assertStringContainsString(
			"\$country_sql = \$country ? \$wpdb->prepare( 'AND country_code = %s', \$country ) : '';",
			$source,
			'AD-033: $country_sql debe construirse via $wpdb->prepare con placeholder %s (no interpolacion con esc_sql).'
		);
	}

	/**
	 * El test verifica que NO queda la interpolacion directa con esc_sql
	 * para country_sql (regresion guard).
	 */
	public function test_no_esc_sql_direct_interpolation_for_country(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// El patron viejo (pre-fix) era:
		// $country_sql = $country ? "AND country_code = '" . esc_sql( $country ) . "'" : '';
		// Verificar que ya no existe.
		$this->assertStringNotContainsString(
			"\"AND country_code = '\" . esc_sql( \$country ) . \"'\"",
			$source,
			'AD-033: la interpolacion directa con esc_sql para country_sql debe estar eliminada (regresion guard).'
		);
	}

	/**
	 * El fix tag CICLO14-P1-AD-033 FIX debe estar presente.
	 */
	public function test_ad033_fix_tag_present(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString( 'CICLO14-P1-AD-033 FIX', $source );
	}

	// -- AD-034 P1: validacion DateTime de date_from/date_to ----------

	/**
	 * El test verifica que $date_from se valida con
	 * DateTime::createFromFormat('Y-m-d', ...).
	 */
	public function test_date_from_validated_with_datetime_createfromformat(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			"DateTime::createFromFormat( 'Y-m-d', \$raw_from )",
			$source,
			'AD-034: $date_from debe validarse con DateTime::createFromFormat( Y-m-d ).'
		);
	}

	/**
	 * El test verifica que $date_to se valida con
	 * DateTime::createFromFormat('Y-m-d', ...).
	 */
	public function test_date_to_validated_with_datetime_createfromformat(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			"DateTime::createFromFormat( 'Y-m-d', \$raw_to )",
			$source,
			'AD-034: $date_to debe validarse con DateTime::createFromFormat( Y-m-d ).'
		);
	}

	/**
	 * El test verifica que hay verificacion de round-trip (format('Y-m-d')
	 * === $raw) para rechazar fechas invalidas como 2024-13-45.
	 */
	public function test_date_validation_uses_roundtrip_check(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Verificar el round-trip: $dt_from_obj->format('Y-m-d') === $raw_from
		$this->assertStringContainsString(
			"\$dt_from_obj->format( 'Y-m-d' ) === \$raw_from",
			$source,
			'AD-034: validacion de fecha debe usar round-trip check (format Y-m-d === raw) para date_from.'
		);

		// Para date_to, el source usa alineacion visual con espacios extra
		// (3 espacios antes del && y antes del === para alinear con
		// $dt_from_obj). Buscar con regex flexible para los espacios.
		// En single-quote PHP string: \$ queda como \$ (escape regex),
		// \s queda como \s (escape regex). No usar \\$ (doble escape).
		$this->assertMatchesRegularExpression(
			'/\$dt_to_obj->format\( \'Y-m-d\' \)\s+===\s+\$raw_to/',
			$source,
			'AD-034: validacion de fecha debe usar round-trip check (format Y-m-d === raw) para date_to (con espaciado flexible por alineacion visual).'
		);
	}

	/**
	 * El test verifica que hay fallback al default cuando la fecha es
	 * invalida (date('Y-m-01') para from, date('Y-m-d') para to).
	 */
	public function test_date_validation_falls_back_to_default_on_invalid(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Fallback para date_from.
		$this->assertStringContainsString(
			"? \$raw_from : date( 'Y-m-01' )",
			$source,
			'AD-034: $date_from debe hacer fallback a date(Y-m-01) cuando la fecha es invalida.'
		);

		// Fallback para date_to.
		$this->assertStringContainsString(
			"? \$raw_to   : date( 'Y-m-d' )",
			$source,
			'AD-034: $date_to debe hacer fallback a date(Y-m-d) cuando la fecha es invalida.'
		);
	}

	/**
	 * El fix tag CICLO14-P1-AD-034 FIX debe estar presente.
	 */
	public function test_ad034_fix_tag_present(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString( 'CICLO14-P1-AD-034 FIX', $source );
	}

	// -- AD-035 P1: ieps_retenido inconsistencia panel vs CSV ---------

	/**
	 * El test verifica que se detecta la columna opcional ieps_retenido
	 * en $cols_exist (como ya se hace para is_hospedaje, is_import).
	 */
	public function test_ieps_retenido_column_detection(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			"\$has_iepsr      = isset( \$cols_exist['ieps_retenido'] )",
			$source,
			'AD-035: debe detectar la columna opcional ieps_retenido via $cols_exist (mismo patron que is_hospedaje / is_import).'
		);
	}

	/**
	 * El test verifica que la query del panel usa el patron condicional
	 * COALESCE(c.ieps_retenido, ...) cuando la columna existe, fallback
	 * a c.ieps_amount (alineado con CSV export linea 154).
	 */
	public function test_panel_query_uses_conditional_ieps_retenido(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Buscar el patron condicional en el bloque de la query del panel.
		// El source debe contener ( $has_iepsr ? "c.ieps_retenido" : "c.ieps_amount" )
		$this->assertStringContainsString(
			'( $has_iepsr ? "c.ieps_retenido" : "c.ieps_amount" )',
			$source,
			'AD-035: la query del panel debe usar el patron condicional ( $has_iepsr ? c.ieps_retenido : c.ieps_amount ) alineado con CSV export.'
		);
	}

	/**
	 * El test verifica que el alias de la columna sigue siendo
	 * ieps_retenido (no se renombro).
	 */
	public function test_panel_query_keeps_ieps_retenido_alias(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		// Verificar que el alias AS ieps_retenido sigue presente (para
		// no romper el frontend que referencia $v['ieps_retenido']).
		$this->assertStringContainsString(
			'AS ieps_retenido,',
			$source,
			'AD-035: el alias AS ieps_retenido debe mantenerse para no romper el frontend (linea 1445: ltms_money( $v[\'ieps_retenido\'] )).'
		);
	}

	/**
	 * El fix tag CICLO14-P1-AD-035 FIX debe estar presente.
	 */
	public function test_ad035_fix_tag_present(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString( 'CICLO14-P1-AD-035 FIX', $source );
	}

	// -- Cross-check: cap check + CSV injection protection siguen OK ----

	/**
	 * Cross-check: el cap check current_user_can debe seguir presente
	 * (linea 22 pre-fix, no debe romperse).
	 */
	public function test_cap_check_still_present(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			"current_user_can( 'ltms_access_auditor_dashboard' )",
			$source,
			'Cross-check: el cap check current_user_can(ltms_access_auditor_dashboard) debe seguir presente (regresion guard).'
		);
	}

	/**
	 * Cross-check: la proteccion CSV formula injection ($csv_field)
	 * debe seguir presente (AUDIT-PANEL-CSV-001).
	 */
	public function test_csv_injection_protection_still_present(): void {
		$this->assertFileExists( self::AD_PATH );
		$source = file_get_contents( self::AD_PATH );

		$this->assertStringContainsString(
			'AUDIT-PANEL-CSV-001',
			$source,
			'Cross-check: la proteccion CSV formula injection AUDIT-PANEL-CSV-001 debe seguir presente.'
		);

		// El source tiene los literales "\t" y "\r" como texto crudo
		// (backslash + t / backslash + r en PHP source). Usamos strings
		// con escape doble para que el assertion busque el substring
		// literal que aparece en el archivo (no el caracter interpretado).
		$this->assertStringContainsString(
			"in_array( \$v[0], [ '=', '+', '-', '@', \"\\t\", \"\\r\" ], true )",
			$source,
			'Cross-check: el array de caracteres peligrosos para CSV injection debe seguir presente.'
		);
	}
}
