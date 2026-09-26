<?php
/**
 * CategoryDedupMigrationTest — tests del fix CAT-DEDUP-001 (migración v2.9.19).
 *
 * La sync VTEX/PosGold pre-fix (SF-CAT-DEDUP) creaba términos product_cat con
 * slug aleatorio → quedaron miles de duplicados en DB (7,480 términos para
 * 307 nombres en dkosmetic). Ese fix hizo los syncs idempotentes hacia
 * adelante y agrupó el sidebar del storefront en lectura, pero NUNCA limpió
 * los duplicados de la DB — siguen contaminando:
 *   - Panel vendedor → Productos → modal Nuevo/Editar (select con 'number' => 100).
 *   - Admin → Envíos → Override por Categoría (tabla con 'number' => 0):
 *     si el admin configura el modo sobre una fila duplicada (term sin
 *     productos) el override no aplica a los productos del término canónico.
 *
 * Este test cubre (patrón source-inspection, mismo enfoque que
 * KycAudit2FixTest — el env unit no tiene DB real):
 *   - migrate_2_9_19_category_dedup existe como método de LTMS_DB_Migrations.
 *   - Detección de duplicados: GROUP BY nombre normalizado + parent, HAVING COUNT(*) > 1.
 *   - Canonical = MIN(term_id) (el más viejo, patrón del repo).
 *   - Dedup de filas term_taxonomy corruptas (mismo term_id, keep MIN(tt_id)).
 *   - Reasignación de term_relationships con manejo de conflicto PK.
 *   - Reasignación de children al canónico.
 *   - Merge de term meta (incluye _ltms_shipping_mode del override).
 *   - wp_update_term_count + wp_delete_term del duplicado.
 *   - CURRENT_VERSION bumpada a 2.9.19 + dispatch en run().
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit --testsuite=unit --filter CategoryDedupMigrationTest
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class CategoryDedupMigrationTest
 */
final class CategoryDedupMigrationTest extends LTMS_Unit_Test_Case {

	private const MIGRATIONS_FILE = 'includes/core/migrations/class-ltms-db-migrations.php';
	private const NEEDLE         = 'function migrate_2_9_19_category_dedup';
	private const BODY_LEN       = 9000;

	/**
	 * Los tests corran desde tests/unit → el path canónico es dirname(__DIR__, 2).
	 */
	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function migrations_src(): string {
		$file = $this->plugin_path( self::MIGRATIONS_FILE );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Migrations file no disponible.' );
		}
		return (string) file_get_contents( $file );
	}

	private function migration_body(): string {
		$src = $this->migrations_src();
		// Localizar la DEFINICIÓN del método (no el dispatch).
		$start = strpos( $src, self::NEEDLE );
		$this->assertNotFalse( $start, "Método " . self::NEEDLE . " debe existir en el archivo." );
		return substr( $src, $start, self::BODY_LEN );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CAT-DEDUP-001 — la migración existe y su cuerpo implementa el merge.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_01_migration_2_9_19_method_exists(): void {
		$file = $this->plugin_path( self::MIGRATIONS_FILE );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Migrations file no disponible.' );
		}
		require_once $file;
		$this->assertTrue( method_exists( 'LTMS_DB_Migrations', 'migrate_2_9_19_category_dedup' ),
			'La migración v2.9.19 debe existir como método de LTMS_DB_Migrations.' );
	}

	public function test_02_detects_duplicates_by_normalized_name_and_parent(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( "LOWER(TRIM(t.name))", $body,
			'La detección debe normalizar el nombre (trim + case-insensitive) para capturar variantes legacy.' );
		$this->assertStringContainsString( 'tt.parent', $body,
			'La detección debe agrupar por parent — categorías homónimas bajo padres distintos NO se mergean.' );
		$this->assertStringContainsString( 'HAVING COUNT(*) > 1', $body,
			'La detección debe filtrar solo grupos con duplicados.' );
		$this->assertStringContainsString( "taxonomy = 'product_cat'", $body,
			'La detección debe limitarse a la taxonomía product_cat.' );
	}

	public function test_03_canonical_is_oldest_term_id(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( 'MIN(t.term_id) AS canonical_id', $body,
			'El término canónico debe ser MIN(term_id) (el más viejo, patrón del repo "keep the oldest").' );
		$this->assertStringContainsString( 't.term_id != %d', $body,
			'Los duplicados deben excluir al canónico (t.term_id != %d).' );
	}

	public function test_04_dedupes_corrupt_term_taxonomy_rows(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( 'MIN(term_taxonomy_id) AS keep_tt', $body,
			'El paso A debe deduplicar filas term_taxonomy corruptas (mismo term_id, keep MIN(tt_id)).' );
		$this->assertStringContainsString( 'term_taxonomy_id != %d', $body,
			'El paso A debe eliminar las filas term_taxonomy distintas a la que se conserva.' );
	}

	public function test_05_reassigns_relationships_with_pk_conflict_handling(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( 'DELETE tr FROM', $body,
			'Debe manejar conflictos de PK: borrar la relación del duplicado cuando el objeto ya tiene la del canónico.' );
		$this->assertStringContainsString( 'keep_tr.object_id = tr.object_id', $body,
			'El conflicto PK debe detectarse por object_id asignado a ambos términos.' );
		$this->assertStringContainsString( 'UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d', $body,
			'Debe reasignar las relaciones restantes del duplicado al término canónico.' );
	}

	public function test_06_reassigns_children_to_canonical(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( 'SET parent = %d', $body,
			'Los children (subcategorías) del duplicado deben reasignarse al canónico.' );
	}

	public function test_07_merges_term_meta_including_shipping_override(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( 'get_term_meta( $dup_id )', $body,
			'Debe leer el term meta del duplicado antes de eliminarlo.' );
		$this->assertStringContainsString( 'add_term_meta( (int) $group->canonical_id, $meta_key, $value )', $body,
			'Debe copiar el term meta faltante al canónico (incluye _ltms_shipping_mode del override y thumbnail_id).' );
	}

	public function test_08_recounts_and_deletes_duplicate(): void {
		$body = $this->migration_body();

		$this->assertStringContainsString( "wp_update_term_count( [ \$canon_tt ], 'product_cat' )", $body,
			'Debe recuentar el término canónico tras reasignar relaciones.' );
		$this->assertStringContainsString( "wp_delete_term( \$dup_id, 'product_cat' )", $body,
			'Debe eliminar el término duplicado vía wp_delete_term (manera canónica WP).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// CAT-DEDUP-001 — CURRENT_VERSION bump + dispatch en run().
	// ─────────────────────────────────────────────────────────────────────────

	public function test_09_current_version_bumped_to_2_9_19(): void {
		$src = $this->migrations_src();

		$this->assertStringContainsString( "CURRENT_VERSION = '2.9.19'", $src,
			'CURRENT_VERSION debe bumparse a 2.9.19 para que la migración v2.9.19 corra en sites ya activados.' );
	}

	public function test_10_migration_dispatched_in_run(): void {
		$src = $this->migrations_src();

		$needle1 = "version_compare( \$installed_version, '2.9.19', '<' )";
		$needle2 = 'self::migrate_2_9_19_category_dedup();';
		$this->assertStringContainsString( $needle1, $src,
			'run() debe gatear la migración v2.9.19 con version_compare.' );
		$this->assertStringContainsString( $needle2, $src,
			'run() debe invocar self::migrate_2_9_19_category_dedup().' );

		$pos1 = strpos( $src, $needle1 );
		$pos2 = strpos( $src, $needle2 );
		$this->assertNotFalse( $pos1 );
		$this->assertNotFalse( $pos2 );
		$this->assertGreaterThan( $pos1, $pos2,
			'La invocación self::migrate_2_9_19... debe ir después del version_compare gate.' );
		$this->assertLessThan( 250, $pos2 - $pos1,
			'La invocación self::migrate_2_9_19... debe estar en el bloque if del version_compare.' );
	}
}
