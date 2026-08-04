<?php
/**
 * Tests estructurales del archivo de migraciones class-ltms-db-migrations.php.
 *
 * Foco actual: AUDIT-DB-AVE-001 — backslash escape espurio en литерales SQL
 * `DEFAULT \'\'` dentro de strings PHP con comillas dobles. Las comillas
 * simples internas NO necesitan escape con backslash cuando el string PHP
 * está delimitado por comillas dobles. El escape espurio introducía el
 * texto `DEFAULT \'\'` (con backslash literal) en la SQL final enviada a
 * MySQL → dbDelta recibía SQL inválida → "WordPress database error You
 * have an error in your SQL syntax; ... near '' at line 4" en cada activate
 * del plugin (descubierto en debug.log del server, 30-Jul-2026 21:53:04 UTC).
 *
 * Cobertura del test:
 *   - AUDIT-DB-AVE-001 FIX: las 3 columnas `codigodane`, `departamento`,
 *     `nombremun` de `lt_aveonline_cities` usan `DEFAULT ''` (sin backslash).
 *   - Regla preventiva: NINGÚN literal SQL en todo el archivo usa
 *     `DEFAULT \'` (backslash escapando comilla simple dentro de string PHP
 *     con comillas dobles — siempre es error). Re-auditoría con
 *     `Select-String -SimpleMatch "\\'"` confirma confinado.
 *   - Trazabilidad: el archivo contiene la traza `AUDIT-DB-AVE-001 FIX`.
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts sobre
 * el source PHP): NO cargan clases del plugin ni invocan WP ni dbDelta →
 * deterministas en LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap
 * estático del autoloader de Composer (mismo patrón que HelpCenterAuditTest,
 * VendorStoreCspTest, OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class DbMigrationsAuditTest
 *
 * Verifica el fix AUDIT-DB-AVE-001 (Fase de cierre de auditoría SSH post-deploy
 * Fase 1.9) sobre el archivo includes/core/migrations/class-ltms-db-migrations.php.
 * Detecta regresiones si alguien reintroduce `DEFAULT \'\'` (backslash escape
 * espurio) en literales SQL dentro de strings PHP con comillas dobles.
 */
final class DbMigrationsAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta al archivo de migraciones.
	 */
	private string $migrations_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer (mismo
	 * patrón que HelpCenterAuditTest, VendorStoreCspTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrations_path = dirname( __DIR__, 2 ) . '/includes/core/migrations/class-ltms-db-migrations.php';
	}

	/**
	 * AUDIT-DB-AVE-001 FIX (P1, backslash escape espurio en DEFAULT):
	 * las 3 columnas `codigodane`, `departamento`, `nombremun` de la tabla
	 * `lt_aveonline_cities` usaban `DEFAULT \'\'` (con backslash). El string
	 * PHP está delimitado por comillas dobles, por lo que las comillas
	 * simples internas NO requieren escape — el backslash pasaba literalmente
	 * a la SQL final → dbDelta recibía SQL inválida → "near '' at line 4"
	 * en cada activate del plugin (ruido en debug.log desde 23-Jul-2026).
	 *
	 * Fix: cambiar `DEFAULT \'\'` a `DEFAULT ''` en las 3 columnas. Verificado
	 * por test_001 y test_002 (assertStringNotContainsString sobre el
	 * substring `DEFAULT \'`) + test_003 (assertStringContainsString sobre
	 * `DEFAULT ''`).
	 *
	 * Regresión: si alguien reintroduce el backslash escape (por copy-paste
	 * desde una versión anterior, o por confusión con escaping PHP), este
	 * test falla inmediatamente. Previerno +50 ocurrencias silenciosas del
	 * error de MySQL en cada activate del plugin.
	 */
	public function test_001_audir_db_ave_001_no_backslash_escape_en_default(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// Strip /* */ block comments y // single-line comments (mismo truco
		// que test_006) — el comment del fix menciona `DEFAULT \'` textualmente
		// 5 veces para explicar el bug, NO debe contar como código vivo.
		$src_no_block    = preg_replace( '/\/\*.*?\*\//s', '', $src );
		$src_no_comments = preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $src_no_block );

		// (1) NO existe el substring `DEFAULT \'` en Código (sin comments).
		$this->assertStringNotContainsString(
			"DEFAULT \\'",
			$src_no_comments,
			'AUDIT-DB-AVE-001 fix: ninguna literal SQL en class-ltms-db-migrations.php debe usar `DEFAULT \\\\\'` (backslash escape espurio). Las comillas simples internas no se escapan dentro de strings PHP con comillas dobles.'
		);
	}

	/**
	 * AUDIT-DB-AVE-001 FIX (continuación): valida negativamente patrón
	 * alternativo — `NOT NULL DEFAULT \` seguido de `\'\'`. Útil como red
	 * de seguridad adicional por si el fix parcial deja un patrón residual.
	 *
	 * Nota técnica: en PHP el string `"foo \'bar\'"` evalúa a `foo \'bar\'`
	 * (con backslash literal) — NO a `foo 'bar'`. Por eso el bug era real
	 * en runtime, no un simple warning.
	 */
	public function test_002_audir_db_ave_001_no_notnull_default_backslash(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// Strip comments PHP como en test_001 y test_006 (el comment del
		// fix menciona `NOT NULL DEFAULT \'\'` textualmente para explicar
		// el bug — NO debe contar como patrón de código real).
		$src_no_block    = preg_replace( '/\/\*.*?\*\//s', '', $src );
		$src_no_comments = preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $src_no_block );

		// Validación negativa robusta: split por líneas y buscar patrón
		// `DEFAULT` seguido de algo que contenga `\'`.
		$lines = preg_split( "/\r?\n/", $src_no_comments );
		$bad_lines = [];
		foreach ( $lines as $i => $line ) {
			// Buscar patrón DEFAULT ... \' en una misma línea.
			if ( preg_match( '/DEFAULT\s+[^\n]*\\\\\'/', $line ) ) {
				$bad_lines[] = 'L' . ( $i + 1 ) . ': ' . trim( $line );
			}
		}
		$this->assertSame(
			[],
			$bad_lines,
			'AUDIT-DB-AVE-001 fix: ninguna línea debe contener `DEFAULT ... \\\\\'` (backslash escape espurio). Líneas malas: ' . implode( '; ', $bad_lines )
		);
	}

	/**
	 * AUDIT-DB-AVE-001 FIX (continuación): las 3 columnas `codigodane`,
	 * `departamento`, `nombremun` de `lt_aveonline_cities` ahora usan
	 * `DEFAULT ''` (comillas simples, sin backslash). Assert positivo.
	 *
	 * Regresión: si alguien corrompe el fix (reintroduce backslash, o
	 * elimina el DEFAULT), este test falla.
	 */
	public function test_003_audir_db_ave_001_default_correcto_en_aveonline_cities(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// (a) La columna codigodane usa DEFAULT '' (sin backslash).
		// Match: `codigodane` ... VARCHAR(12) NOT NULL DEFAULT '',
		$this->assertMatchesRegularExpression(
			'/`codigodane`\s+VARCHAR\(12\)\s+NOT NULL DEFAULT \x27\x27/',
			$src,
			'AUDIT-DB-AVE-001 fix: la columna codigodane debe usar DEFAULT \'\' (comillas simples, sin backslash escapado en string PHP con comillas dobles)'
		);

		// (b) La columna departamento usa DEFAULT '' (sin backslash).
		$this->assertMatchesRegularExpression(
			'/`departamento`\s+VARCHAR\(80\)\s+NOT NULL DEFAULT \x27\x27/',
			$src,
			'AUDIT-DB-AVE-001 fix: la columna departamento debe usar DEFAULT \'\''
		);

		// (c) La columna nombremun usa DEFAULT '' (sin backslash).
		$this->assertMatchesRegularExpression(
			'/`nombremun`\s+VARCHAR\(120\)\s+NOT NULL DEFAULT \x27\x27/',
			$src,
			'AUDIT-DB-AVE-001 fix: la columna nombremun debe usar DEFAULT \'\''
		);
	}

	/**
	 * Trazabilidad: el archivo contiene la traza `AUDIT-DB-AVE-001 FIX`
	 * para auditoría futura. Regresión: si alguien elimina el comment de
	 * traza, este test falla (pérdida de trazabilidad del fix).
	 */
	public function test_004_audir_db_ave_001_traza_presente(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		$this->assertStringContainsString(
			'AUDIT-DB-AVE-001 FIX',
			$src,
			'AUDIT-DB-AVE-001 fix: el comment de traza AUDIT-DB-AVE-001 FIX debe estar presente en el archivo para trazabilidad futura'
		);
	}

	/**
	 * La tabla `lt_aveonline_cities` sigue presente en el source (no se
	 * eliminó físicamente — solo se corrigió el DEFAULT). Regresión: si
	 * alguien elimina la definición completa de la tabla rompiendo el fix,
	 * este test falla.
	 */
	public function test_005_tabla_lt_aveonline_cities_preservada(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// (a) La declaración CREATE TABLE sigue presente.
		$this->assertStringContainsString(
			'CREATE TABLE IF NOT EXISTS `{$p}lt_aveonline_cities`',
			$src,
			'AUDIT-DB-AVE-001 fix: la declaración CREATE TABLE lt_aveonline_cities debe seguir presente (el fix corrige el DEFAULT, no elimina la tabla)'
		);

		// (b) Las 4 columnas + UNIQUE KEY + 2 KEYs siguen presentes (schema
		// completo, no se acortó la definición). Usamos regex para tolerar
		// el padding de columnas tabulares (e.g. `nombre`       VARCHAR...).
		$this->assertMatchesRegularExpression( '/`nombre`\s+VARCHAR\(160\)\s+NOT NULL/', $src );
		$this->assertMatchesRegularExpression( '/`synced_at`\s+DATETIME/', $src );
		$this->assertMatchesRegularExpression( '/UNIQUE KEY `uk_nombre`/', $src );
		$this->assertMatchesRegularExpression( '/KEY `idx_codigodane`/', $src );
		$this->assertMatchesRegularExpression( '/KEY `idx_departamento`/', $src );
	}

	/**
	 * Regla preventiva general: NINGÚN literal SQL en el archivo (excluyendo
	 * comments PHP) usa `\'` (backslash escapando comilla simple dentro de
	 * string PHP con comillas dobles). Esta higiene de código previene
	 * reaparecimiento del mismo bug en otras tablas futuras.
	 *
	 * NOTA: el propio comment del fix (líneas 758-770) menciona textualmente
	 * `DEFAULT \'\'` 5 veces para explicar el bug — esos son comments PHP,
	 * no código. Stripamos comments antes de validar para evitar falso-positivo
	 * (mismo truco de HelpCenterAuditTest::test_001 / test_002 / test_003
	 * — ver LECCIONES #141: canarios sobre comments siempre pasan falsos).
	 *
	 * Re-auditoría inicial: `Select-String -SimpleMatch "\\'"` sobre el
	 * archivo completo (3477 líneas) confirma que solo las 3 ocurrencias
	 * de `lt_aveonline_cities` (líneas 760-762 pre-fix) usaban el patrón en
	 * CÓDIGO. Tras el fix, las 5 ocurrencias residuales están CONFINADAS a
	 * comments PHP (líneas 758, 769, 770 — mi propio comment explicativo).
	 */
	public function test_006_regla_preventiva_higiene_no_backslash_quote(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// Strip /* */ block comments (multilínea).
		$src_no_block    = preg_replace( '/\/\*.*?\*\//s', '', $src );
		// Strip // single-line comments (preservando URLs con //:).
		$src_no_comments = preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $src_no_block );

		// Contar ocurrencias del patrón `\'` en código (sin comments).
		// Esperado: 0 — ninguna sentencia SQL o PHP usa el backslash escapando
		// comilla simple dentro de strings con comillas dobles.
		$count = substr_count( $src_no_comments, "\\'" );
		$this->assertSame(
			0,
			$count,
			'AUDIT-DB-AVE-001 regla preventiva: el código (sin comments) de class-ltms-db-migrations.php NO debe contener NINGÚN patrón \\\\\' (backslash escapando comilla simple dentro de string PHP con comillas dobles). Encontradas ' . $count . ' ocurrencias.'
		);
	}

	/**
	 * AUDIT-DB-COMMISSIONS-001 FIX (P1, ; dentro de COMMENT rompe dbDelta):
	 * la columna `notes` de lt_commissions usaba un COMMENT con `;`:
	 *   `notes` TEXT DEFAULT NULL COMMENT 'Notas internas; ej: vendor_id:123 para referidos',
	 * dbDelta de WordPress NO es un parser SQL completo — tokenizea por líneas
	 * y el `;` dentro del COMMENT lo confunde como fin de statement. Síntoma
	 * observado en debug.log del server (04-Aug-2026 12:16:49 UTC):
	 *   - "ALTER TABLE bkr_lt_commissions CHANGE COLUMN `strategy_applied`
	 *      `strategy_applied` VARCHAR(100"  ← truncado en el `(100`, falta `)`
	 *   - "'ej: vendor_id:123 para referidos', `metadata` LONGTEXT DEFAULT"  ←
	 *     el COMMENT se parseó como DDL y se mezcló con la línea siguiente.
	 *
	 * Fix: reemplazar `;` por `,` dentro del COMMENT. Semántica preservada,
	 * dbDelta recibe SQL válido, no hay truncamiento.
	 *
	 * Regresión: si alguien reintroduce un `;` dentro de un COMMENT en
	 * cualquier tabla del archivo, este test falla inmediatamente.
	 */
	public function test_007_audir_db_commissions_001_no_semicolon_in_comment(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// Strip comments PHP como en test_001/test_006.
		$src_no_block    = preg_replace( '/\/\*.*?\*\//s', '', $src );
		$src_no_comments = preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $src_no_block );

		// Buscar patrón COMMENT '...;...' (punto y coma dentro del comentario).
		// El regex captura COMMENT seguido de '...;...' (con `;` dentro).
		preg_match_all( "/COMMENT\s+'[^']*;[^']*'/", $src_no_comments, $matches );
		$this->assertSame(
			[],
			$matches[0] ?? [],
			'AUDIT-DB-COMMISSIONS-001 regla preventiva: ninguna columna debe usar `COMMENT \'<texto con ;>\'` — el `;` interno rompe el tokenizer de dbDelta que WP usa para ALTER TABLE. Ocurrencias malas: ' . implode( '; ', $matches[0] ?? [] )
		);
	}

	/**
	 * AUDIT-DB-COMMISSIONS-001 FIX (continuación): la columna `notes` de
	 * lt_commissions ahora usa `,` en vez de `;` en el COMMENT. Assert positivo.
	 *
	 * Regresión: si alguien revierte el fix, este test falla.
	 */
	public function test_008_audir_db_commissions_001_comma_used_in_notes_comment(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// La línea debe contener el COMMENT con `,` (no `;`).
		$this->assertStringContainsString(
			"COMMENT 'Notas internas, ej: vendor_id:123 para referidos'",
			$src,
			'AUDIT-DB-COMMISSIONS-001 fix: la columna `notes` de lt_commissions debe usar `,` en el COMMENT (no `;` — el `;` rompe dbDelta).'
		);

		// Y NO debe contener el COMMENT viejo con `;`.
		$this->assertStringNotContainsString(
			"COMMENT 'Notas internas; ej: vendor_id:123 para referidos'",
			$src,
			'AUDIT-DB-COMMISSIONS-001 fix: el COMMENT viejo con `;` no debe existir.'
		);
	}

	/**
	 * AUDIT-DB-COMMISSIONS-001 FIX (continuación): la columna `strategy_applied`
	 * de lt_commissions preserva su VARCHAR(100) completo y cerrado con `)`.
	 * El bug del server log mostraba `VARCHAR(100` SIN `)` — dbDelta truncaba
	 * la query ALTER porque el `;` del COMMENT de la línea siguiente leía
	 * como fin de statement. Tras el fix, dbDelta recibe SQL válida y la
	 * columna se crea/modifica con su tipo completo.
	 *
	 * Regresión: si alguien corrompe el schema de `strategy_applied` (lo
	 * truncara, le quitara el `)`, o moviera el COMMENT a una posición que
	 * dbDelta no pueda parsear), este test falla.
	 */
	public function test_009_audir_db_commissions_001_strategy_applied_varchar_intact(): void {
		$this->assertFileExists( $this->migrations_path );
		$src = file_get_contents( $this->migrations_path );

		// La columna strategy_applied debe tener VARCHAR(100) CERRADO con `)`.
		$this->assertMatchesRegularExpression(
			'/`strategy_applied`\s+VARCHAR\(100\)\s+DEFAULT NULL/',
			$src,
			'AUDIT-DB-COMMISSIONS-001 fix: la columna strategy_applied debe ser VARCHAR(100) completa con `)` — dbDelta truncaba en VARCHAR(100 cuando el `;` del COMMENT de la línea siguiente rompía el tokenizer.'
		);
	}
}
