<?php
/**
 * Tests: CAT-NORM-002 + SHOP-CATS-MULTI + PROD-GALLERY-EDIT + PROD-IMG-FIT (Ciclo 9, 2026-09-29).
 *
 * Cubre el reporte del vendor:
 *  1. Categorías duplicadas/variantes: "JUEGO DE MESA" vs "JUEGOS DE MESA"
 *     (singular/plural), "Coloración" vs "Coloracion" (acento), case-variantes
 *     — normalización vía fingerprint + MAYÚSCULAS (helper + migración).
 *  2. Storefront: multi-select de categorías (shop + vendor store).
 *  3. Galería multi-imagen en el modal Editar (paridad con Nuevo).
 *  4. Preview de imagen del form: object-fit:contain (no recorta) + modal sin
 *     desborde (box-sizing).
 *
 * Diagnóstico live 2026-09-29: 227 términos product_cat, 18 grupos con
 * variantes; product_cat[]=a&product_cat[]=b fatala con HTTP 500 (verificado
 * live) — el multi-select usa CSV propio (?ltms_cats / ?cat CSV).
 *
 * @package LTMS
 */

namespace LTMS\Tests\Unit;

final class CategoryNormMultiSelectTest extends LTMS_Unit_Test_Case {

	private function src( string $file ): string {
		return (string) file_get_contents( $file );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9c-1: category_fingerprint — test FUNCIONAL (ejecuta el código real)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_fingerprint_merges_singular_plural(): void {
		$this->require_class( 'LTMS_Utils' );

		$fp_singular = \LTMS_Utils::category_fingerprint( 'JUEGO DE MESA' );
		$fp_plural   = \LTMS_Utils::category_fingerprint( 'JUEGOS DE MESA' );

		$this->assertSame( $fp_singular, $fp_plural, 'Singular/plural ("JUEGO DE MESA"/"JUEGOS DE MESA") deben colapsar al mismo fingerprint — hoy se listaban por separado.' );
	}

	public function test_fingerprint_merges_case_variants(): void {
		$this->require_class( 'LTMS_Utils' );

		$this->assertSame(
			\LTMS_Utils::category_fingerprint( 'Accesorios' ),
			\LTMS_Utils::category_fingerprint( 'ACCESORIOS' ),
			'Case-variantes deben colapsar al mismo fingerprint.'
		);
	}

	public function test_fingerprint_merges_accent_variants(): void {
		$this->require_class( 'LTMS_Utils' );

		$this->assertSame(
			\LTMS_Utils::category_fingerprint( 'Coloración' ),
			\LTMS_Utils::category_fingerprint( 'Coloracion' ),
			'Variantes de acento ("Coloración"/"Coloracion") deben colapsar al mismo fingerprint.'
		);
	}

	public function test_fingerprint_does_not_merge_different_word_order(): void {
		$this->require_class( 'LTMS_Utils' );

		// Categorías distintas del catálogo real (padres distintos en la DB):
		// "Mascarillas Capilares y Tratamientos" (parent 1219) vs "Tratamientos
		// y mascarillas" (parent 3235) — el fingerprint es word-order-sensitive
		// y NO debe mergearlas.
		$this->assertNotSame(
			\LTMS_Utils::category_fingerprint( 'Mascarillas Capilares y Tratamientos' ),
			\LTMS_Utils::category_fingerprint( 'Tratamientos y mascarillas' ),
			'Categorías con distinto orden de palabras son conceptos distintos — no deben mergearse.'
		);
	}

	public function test_fingerprint_does_not_mangle_short_words(): void {
		$this->require_class( 'LTMS_Utils' );

		// Palabras cortas (<=3 chars) no pierden la 's': "DE" queda "DE".
		$this->assertSame( 'juego de mesa', \LTMS_Utils::category_fingerprint( 'JUEGO DE MESA' ), 'El fingerprint no debe manglear palabras cortas.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9c-2: get_normalized_product_categories reemplaza a get_deduped (dead code out)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_normalized_helper_exists_and_deduped_is_removed(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/core/utils/class-ltms-utils.php' );

		$this->assertStringContainsString( 'function get_normalized_product_categories', $src, 'El helper normalizado debe existir.' );
		$this->assertStringContainsString( 'function category_fingerprint', $src, 'El fingerprint debe existir.' );
		$this->assertStringNotContainsString( 'function get_deduped_product_categories', $src, 'get_deduped_product_categories quedó sin consumidores (reemplazada por la normalizada) — dead code debe eliminarse.' );
	}

	public function test_normalized_helper_uppercases_names_and_uses_count_canonical(): void {
		$pos  = strpos( $this->src( dirname( __DIR__, 2 ) . '/includes/core/utils/class-ltms-utils.php' ), 'function get_normalized_product_categories' );
		$body = substr( $this->src( dirname( __DIR__, 2 ) . '/includes/core/utils/class-ltms-utils.php' ), $pos, 3200 );

		$this->assertNotFalse( $pos, 'El helper debe existir.' );
		$this->assertStringContainsString( 'mb_strtoupper', $body, 'El name devuelto debe ir en MAYÚSCULAS.' );
		$this->assertStringContainsString( 'category_fingerprint', $body, 'El dedup debe ser por fingerprint (no GROUP BY t.name).' );
		$this->assertStringContainsString( 'hide_empty', $body, 'Debe aceptar hide_empty (filtros solo con productos; form todas).' );
	}

	public function test_form_select_uses_normalized_helper(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/views/view-products.php' );

		$this->assertStringContainsString( 'LTMS_Utils::get_normalized_product_categories( false )', $src, 'El form de productos debe usar el helper normalizado (todas las categorías, sin dupes).' );
		$this->assertStringNotContainsString( 'get_deduped_product_categories', $src, 'El form no debe usar el helper eliminado.' );
	}

	public function test_shop_filter_uses_normalized_helper(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/templates/archive-product.php' );

		$this->assertStringContainsString( 'LTMS_Utils::get_normalized_product_categories( true )', $src, 'El filtro del shop debe usar el helper normalizado (solo con productos, MAYÚSCULAS).' );
		$this->assertStringNotContainsString( "get_terms( array( 'taxonomy' => 'product_cat'", $src, 'get_terms() plano listaba dupes/variantes por separado — no debe quedar en el filtro.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9c-3: migración CAT-NORM-002
	// ─────────────────────────────────────────────────────────────────────────

	public function test_migration_2_9_20_method_exists_and_version_bumped(): void {
		$this->assertTrue( method_exists( 'LTMS_DB_Migrations', 'migrate_2_9_20_category_normalize' ), 'La migración CAT-NORM-002 debe existir.' );

		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/core/migrations/class-ltms-db-migrations.php' );
		$this->assertStringContainsString( "CURRENT_VERSION = '2.9.20'", $src, 'ltms_db_version debe bumppear a 2.9.20 para que la migración corra.' );
		$this->assertStringContainsString( "version_compare( \$installed_version, '2.9.20', '<' )", $src, 'El runner debe despachar la migración 2.9.20.' );
	}

	public function test_migration_merges_by_fingerprint_across_parents_and_uppercases(): void {
		$src  = $this->src( dirname( __DIR__, 2 ) . '/includes/core/migrations/class-ltms-db-migrations.php' );
		$pos  = strpos( $src, 'function migrate_2_9_20_category_normalize' );
		$body = substr( $src, $pos, 6400 );

		$this->assertNotFalse( $pos, 'La migración debe existir.' );
		$this->assertStringContainsString( 'category_fingerprint', $body, 'El merge debe ser por fingerprint (case/acento/singular-plural).' );
		$this->assertStringContainsString( 'wp_update_term', $body, 'El canónico debe renombrarse a MAYÚSCULAS.' );
		$this->assertStringContainsString( "wp_delete_term( \$dup_id, 'product_cat' )", $body, 'El duplicado debe eliminarse.' );
		$this->assertStringNotContainsString( 'tt.parent = %d', $body, 'El merge de variantes NO debe exigir mismo parent ("Coloración" 1219 vs "Coloracion" 2930 quedaban sin mergear por eso).' );
	}

	public function test_migration_renames_canonical_with_explicit_slug(): void {
		$src  = $this->src( dirname( __DIR__, 2 ) . '/includes/core/migrations/class-ltms-db-migrations.php' );
		$pos  = strpos( $src, 'function migrate_2_9_20_category_normalize' );
		$body = substr( $src, $pos, 4600 );

		// El slug se pasa EXPLÍCITO en wp_update_term para que no se regenere
		// desde el nombre en MAYÚSCULAS (rompería ?cat=slug y
		// /categoria-producto/slug/).
		$this->assertStringContainsString( "'slug' => (string) \$current_slug", $body, 'El slug debe preservarse explícitamente al renombrar el canónico.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9d: multi-select de categorías — shop (/tienda/) y vendor store
	// ─────────────────────────────────────────────────────────────────────────

	public function test_shop_multiselect_checkboxes_and_ltms_cats_csv(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/templates/archive-product.php' );

		$this->assertStringContainsString( "class=\"pv-shop__cat-check\"", $src, 'El filtro del shop debe renderizar checkboxes multi-select (los links single-select no permitían combinar).' );
		$this->assertStringContainsString( "explode( ',', (string) wp_unslash( \$_GET['ltms_cats'] ) )", $src, 'El CSV ltms_cats debe parsearse por item.' );
		$this->assertStringContainsString( 'ltms_cats', $src, 'El param propio ltms_cats debe usarse (product_cat[]= fatala con HTTP 500).' );
	}

	public function test_shop_cats_applied_via_pre_get_posts_filter(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-vendor-storefront.php' );

		$this->assertStringContainsString( "add_action( 'pre_get_posts', [ __CLASS__, 'filter_shop_cats' ] )", $src, 'El filtro pre_get_posts debe estar registrado.' );
		$this->assertStringContainsString( 'function filter_shop_cats( \WP_Query $query )', $src, 'El método filter_shop_cats debe existir.' );

		$pos  = strpos( $src, 'function filter_shop_cats' );
		$body = substr( $src, $pos, 1800 );
		$this->assertStringContainsString( "'operator' => 'IN'", $body, 'El tax_query debe usar IN (multi-select).' );
		$this->assertStringContainsString( 'is_main_query', $body, 'Solo la main query (no tocar el resto).' );
	}

	public function test_vendor_store_multiselect_checkboxes_and_csv_in_both_queries(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-vendor-storefront.php' );

		$this->assertStringContainsString( "class=\"ltms-sf-cat-check\"", $src, 'La vitrina debe renderizar checkboxes multi-select (los radios single-select no permitían combinar).' );
		$this->assertStringNotContainsString( 'type="radio" name="ltms_cat"', $src, 'Los radios single-select deben haberse reemplazado.' );

		// Query inicial: CSV → IN.
		$pos  = strpos( $src, 'CAT-NORM-002 + STORE-CATS-MULTI (2026-09-29): multi-select de' );
		$body = substr( $src, $pos, 1600 );
		$this->assertNotFalse( $pos, 'El bloque de la query inicial debe existir.' );
		$this->assertStringContainsString( 'explode( \',\', $cat_raw )', $body, 'La query inicial debe parsear el CSV por item.' );
		$this->assertStringContainsString( "'operator' => 'IN'", $body, 'La query inicial debe usar IN (multi-select).' );

		// AJAX load_more: paridad CSV → IN.
		$pos2  = strpos( $src, 'CAT-NORM-002 + STORE-CATS-MULTI (2026-09-29): parse CSV del cat' );
		$body2 = substr( $src, $pos2, 1200 );
		$this->assertNotFalse( $pos2, 'El bloque del AJAX debe existir.' );
		$this->assertStringContainsString( 'explode( \',\', $cat_raw )', $body2, 'El AJAX load_more debe parsear el CSV por item (paridad con la query inicial).' );
		$this->assertStringContainsString( "'operator' => 'IN'", $body2, 'El AJAX load_more debe usar IN (multi-select).' );
	}

	public function test_vendor_store_urls_preserve_raw_csv(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-vendor-storefront.php' );

		// stock/order/pagination URLs + data-cat: con multi-select deben
		// preservar el CSV crudo ($cat_raw), no el primer slug ($cat_slug).
		$this->assertSame( 0, substr_count( $src, "'cat' => \$cat_slug ?:" ), 'Las URLs de stock/order/paginación deben preservar el CSV crudo ($cat_raw) — $cat_slug pierde las categorías 2..n.' );
		$this->assertStringContainsString( 'data-cat="<?php echo esc_attr( $cat_raw ); ?>"', $src, 'data-cat del wrapper debe llevar el CSV crudo (el load_more lo parsea).' );
	}

	public function test_storefront_multiselect_js_handles_csv(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-storefront.js' );

		$this->assertStringContainsString( "'.ltms-sf-cat-check'", $src, 'El JS de la vitrina debe manejar los checkboxes multi-select.' );
		$this->assertStringContainsString( "params.set('cat', checked.join(','))", $src, 'El JS debe construir el CSV con las categorías marcadas.' );

		$min = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-storefront.min.js' );
		$this->assertStringContainsString( 'ltms-sf-cat-check', $min, 'El min regenerado debe contener el multi-select (producción carga el min).' );
	}

	public function test_shop_multiselect_js_handles_csv(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-homepage-fixes.js' );

		$this->assertStringContainsString( '.pv-shop__cat-check', $src, 'El JS del shop debe manejar los checkboxes multi-select.' );
		$this->assertStringContainsString( "params.set('ltms_cats', checked.join(','))", $src, 'El JS debe construir el CSV ltms_cats con las marcadas.' );

		$min = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-homepage-fixes.min.js' );
		$this->assertStringContainsString( 'pv-shop__cat-check', $min, 'El min regenerado debe contener el multi-select (producción carga el min).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9a: galería multi-imagen en el modal Editar (paridad con Nuevo)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_edit_modal_has_gallery_section(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/views/view-products.php' );

		foreach ( [ 'ltms-ep-gallery-preview', 'ltms-ep-gallery-input', 'ltms-ep-gallery-ids', 'ltms-ep-gallery-btn' ] as $el ) {
			$this->assertStringContainsString( $el, $src, "El modal Editar debe tener {$el} (paridad con el modal Nuevo — antes solo permitía la imagen destacada)." );
		}
	}

	public function test_edit_modal_js_gallery_parity_with_new_modal(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-products.js' );

		$this->assertStringContainsString( "'#ltms-ep-gallery-input'", $src, 'El JS debe manejar la subida de galería en el Editar.' );
		$this->assertStringContainsString( "data-ep-gallery-remove", $src, 'El JS debe manejar la remoción de galería en el Editar.' );
		$this->assertStringContainsString( "gallery_ids:$('#ltms-ep-gallery-ids').val() || ''", $src, 'El submit del Editar debe enviar gallery_ids (paridad con create_product — update_product ya lo maneja).' );
		$this->assertStringContainsString( 'd.gallery_urls', $src, 'El modal Editar debe poblar la galería existente del producto (get_product devuelve gallery_urls).' );

		$min = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-products.min.js' );
		$this->assertStringContainsString( 'ltms-ep-gallery-input', $min, 'El min regenerado debe contener la galería del Editar (producción carga el min).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 9b: preview de imagen del form — object-fit:contain + modal sin desborde
	// ─────────────────────────────────────────────────────────────────────────

	public function test_form_previews_use_contain_not_cover(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/assets/js/ltms-products.js' );

		// Los previews PRINCIPALES (np + ep) deben usar contain (cover
		// RECORTABA la imagen — entrecorte reportado). Los thumbs de 50px de
		// la galería quedan con cover (estándar para miniaturas cuadradas).
		// Ocurrencias: np upload + ep modal load (image_url) + ep upload = 3.
		$this->assertSame( 3, substr_count( $src, 'object-fit:contain;background:#fff;' ), 'Los previews principales (np + ep) deben usar object-fit:contain.' );
	}

	public function test_modal_inner_no_overflow_box_sizing(): void {
		$src = $this->src( dirname( __DIR__, 2 ) . '/includes/frontend/views/view-products.php' );

		$this->assertSame( 2, substr_count( $src, 'max-width:560px;box-sizing:border-box;width:100%;' ), 'Ambos modals (Nuevo + Editar) deben tener box-sizing:border-box + width:100% (el padding de 28px sumaba 560+56=616px y desbordaba).' );
	}
}
