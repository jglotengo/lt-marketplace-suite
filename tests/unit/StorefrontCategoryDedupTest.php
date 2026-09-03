<?php
/**
 * StorefrontCategoryDedupTest — tests del fix de categorías duplicadas
 * (SF-CAT-DEDUP).
 *
 * La sync VTEX/PosGold creaba términos product_cat con slug aleatorio
 * ($slug.'-'.wp_rand(100,999)) y el lookup usaba get_term_by('slug',
 * sanitize_title($name)) → el slug limpio nunca existía → cada sync (incluido
 * el auto-sync diario de VTEX) creaba N duplicados del mismo nombre. En
 * dkosmetic llegó a 7,480 términos para 307 nombres únicos, y el sidebar del
 * storefront los mostraba todos.
 *
 * Este test cubre:
 *   - get_or_create_category() idempotente por nombre (VTEX y PosGold).
 *   - fallback a slug limpio (términos legacy sin sufijo).
 *   - fallback a slug prefijado legacy (duplicados "-NNN").
 *   - creación con slug determinista (sin wp_rand).
 *   - get_vendor_categories() agrupa por nombre y devuelve count real.
 *
 * @package LTMS\Tests\Unit
 *
 * Ejecutar con: ./vendor/bin/phpunit tests/unit/StorefrontCategoryDedupTest.php
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;

/**
 * Class StorefrontCategoryDedupTest
 */
final class StorefrontCategoryDedupTest extends LTMS_Unit_Test_Case {

	private function require_classes(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );
		$this->require_class( 'LTMS_PosGold_Sync' );
		$this->require_class( 'LTMS_Vendor_Storefront' );
	}

	/**
	 * Invoca el método privado get_or_create_category por Reflection.
	 */
	private function invoke_create( string $class, string $name, int $parent_id = 0 ): mixed {
		$this->require_class( $class );
		$refl = new \ReflectionMethod( $class, 'get_or_create_category' );
		$refl->setAccessible( true );
		return $refl->invoke( null, $name, $parent_id );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// get_or_create_category() — VTEX (SF-CAT-DEDUP)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_vtex_get_or_create_is_idempotent_by_name(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );

		$inserts = 0;
		// get_terms por nombre devuelve [42] la PRIMERA vez que se llama (ya existe).
		// Simula: en la 1ª llamada no existe (null) → cae a wp_insert_term → 42;
		// en la 2ª llamada el término por nombre YA existe → devuelve 42 sin insertar.
		$by_name_calls = 0;
		Monkey\Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( &$by_name_calls ) {
				$by_name_calls++;
				// El fallback de "slug prefijado legacy" también usa get_terms; en la
				// primera llamada (búsqueda por nombre) no existe → []. En la segunda
				// (slug prefijado) tampoco → [].
				return [];
			}
		);
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->alias(
			static function ( $name, $tax, $args ) use ( &$inserts ) {
				$inserts++;
				return [ 'term_id' => 42 ];
			}
		);
		Monkey\Functions\when( 'sanitize_title' )->alias(
			static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) )
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

		$first = $this->invoke_create( 'LTMS_Vtex_Sync', 'Coloración' );
		$this->assertSame( 42, $first, 'Debe crear el término la primera vez.' );

		// Ahora el término existe por nombre: get_terms devuelve [42].
		Monkey\Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				// Búsqueda por nombre (primer get_terms del método) → existe.
				return [ 42 ];
			}
		);
		$second = $this->invoke_create( 'LTMS_Vtex_Sync', 'Coloración' );
		$this->assertSame( 42, $second, 'Debe reutilizar el término por nombre (idempotente).' );
		$this->assertSame( 1, $inserts, 'No debe insertar de nuevo cuando ya existe por nombre.' );
	}

	public function test_vtex_get_or_create_reuses_legacy_prefixed_slug(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );

		$inserts = 0;
		// No existe por nombre ni por slug limpio, pero existe el legacy "coloracion-918".
		Monkey\Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				$slug = $args['slug'] ?? '';
				// La 1ª llamada (por nombre) → []; la 2ª (slug prefijado) → [918].
				if ( '' !== $slug ) {
					return [ 918 ];
				}
				return [];
			}
		);
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->alias(
			static function () use ( &$inserts ) {
				$inserts++;
				return [ 'term_id' => 999 ];
			}
		);
		Monkey\Functions\when( 'sanitize_title' )->alias(
			static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) )
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->invoke_create( 'LTMS_Vtex_Sync', 'Coloración' );
		$this->assertSame( 918, $result, 'Debe reutilizar el término legacy con slug prefijado en vez de crear otro duplicado.' );
		$this->assertSame( 0, $inserts, 'No debe insertar cuando existe un legacy con slug prefijado.' );
	}

	public function test_vtex_get_or_create_uses_deterministic_slug(): void {
		$this->require_class( 'LTMS_Vtex_Sync' );

		$captured_slug = null;
		Monkey\Functions\when( 'get_terms' )->justReturn( [] );
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->alias(
			static function ( $name, $tax, $args ) use ( &$captured_slug ) {
				$captured_slug = $args['slug'] ?? '';
				return [ 'term_id' => 55 ];
			}
		);
		Monkey\Functions\when( 'sanitize_title' )->alias(
			static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) )
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

		$this->invoke_create( 'LTMS_Vtex_Sync', 'Cuidado Facial' );
		$this->assertSame( 'cuidado-facial', $captured_slug,
			'El slug debe ser determinista (sin sufijo aleatorio) para que el lookup por slug limpio funcione en la próxima sync.' );
	}

	public function test_posgold_get_or_create_is_idempotent_by_name(): void {
		$this->require_class( 'LTMS_PosGold_Sync' );

		$inserts = 0;
		Monkey\Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				$slug = $args['slug'] ?? '';
				if ( '' !== $slug ) {
					return [];
				}
				return [ 7 ];
			}
		);
		Monkey\Functions\when( 'get_term_by' )->justReturn( null );
		Monkey\Functions\when( 'wp_insert_term' )->alias(
			static function () use ( &$inserts ) {
				$inserts++;
				return [ 'term_id' => 99 ];
			}
		);
		Monkey\Functions\when( 'sanitize_title' )->alias(
			static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) )
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->invoke_create( 'LTMS_PosGold_Sync', 'Maquillaje' );
		$this->assertSame( 7, $result, 'PosGold debe reutilizar la categoría existente por nombre.' );
		$this->assertSame( 0, $inserts );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Estructural: el código fuente ya no usa slug aleatorio
	// ─────────────────────────────────────────────────────────────────────────

	public function test_vtex_source_does_not_use_random_slug(): void {
		$src = file_get_contents( __DIR__ . '/../../includes/business/class-ltms-vtex-sync.php' );
		$pos = strpos( $src, 'function get_or_create_category' );
		$body = substr( $src, $pos, 2200 );

		$this->assertStringNotContainsString(
			"wp_rand( 100, 999 )",
			$body,
			'El slug no debe usar wp_rand (causa de duplicados en cada sync).'
		);
		$this->assertStringContainsString(
			"'slug'   => \$slug,",
			$body,
			'Debe usar el slug determinista (sanitize_title) al insertar.'
		);
	}

	public function test_posgold_source_does_not_use_random_slug(): void {
		$src = file_get_contents( __DIR__ . '/../../includes/business/class-ltms-posgold-sync.php' );
		$pos = strpos( $src, 'function get_or_create_category' );
		$body = substr( $src, $pos, 2200 );

		$this->assertStringNotContainsString(
			"wp_rand( 100, 999 )",
			$body,
			'PosGold no debe usar wp_rand en el slug.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// get_vendor_categories() — agrupa por nombre (defensa en el storefront)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_get_vendor_categories_groups_by_name_with_count(): void {
		$this->require_class( 'LTMS_Vendor_Storefront' );

		global $wpdb;
		$wpdb = $this->mock_wpdb_with_categories();

		$refl = new \ReflectionMethod( \LTMS_Vendor_Storefront::class, 'get_vendor_categories' );
		$refl->setAccessible( true );
		$cats = $refl->invoke( null, 223 );

		$this->assertIsArray( $cats );
		$this->assertNotEmpty( $cats );
		// Solo debe devolver las 3 categorías por nombre (no las 6 filas duplicadas).
		$names = array_map( static fn( $c ) => $c->name, $cats );
		$this->assertSame( [ 'Cuidado Facial', 'Maquillaje', 'Rostro' ], $names );
		// El count debe ser el número real de productos del vendor por categoría.
		foreach ( $cats as $c ) {
			$this->assertGreaterThan( 0, $c->count, 'Cada categoría debe tener su count real.' );
		}
	}

	/**
	 * Crea un stub mínimo de $wpdb cuyo get_results devuelve filas duplicadas
	 * que la query agrupada debería colapsar (aunque aquí ya llega agrupada,
	 * verificamos que la normalización a objetos con count funciona).
	 */
	private function mock_wpdb_with_categories(): object {
		$rows = [
			(object) [ 'term_id' => '3', 'name' => 'Cuidado Facial', 'slug' => 'cuidado-facial-257', 'product_count' => '20' ],
			(object) [ 'term_id' => '1', 'name' => 'Maquillaje', 'slug' => 'maquillaje-918', 'product_count' => '12' ],
			(object) [ 'term_id' => '2', 'name' => 'Rostro',     'slug' => 'rostro-482',     'product_count' => '8' ],
		];
		$stub = new class( $rows ) {
			public array $rows;
			public string $terms = 'wp_terms';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $posts = 'wp_posts';
			public string $prefix = 'wp_';
			public function __construct( array $rows ) { $this->rows = $rows; }
			public function get_results( $query ): array { return $this->rows; }
			public function prepare( $query, ...$args ): string { return $query; }
		};
		return $stub;
	}
}