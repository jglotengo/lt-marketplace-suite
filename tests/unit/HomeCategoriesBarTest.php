<?php
/**
 * HomeCategoriesBarTest — tests de la barra de categorías de la home nativa
 * (HOME-REDESIGN-003, patrón Shein/Alibaba).
 *
 * Foco: C3 del rediseño de la home:
 *   - El bento grid de 6 tiles fue REEMPLAZADO por una barra de 8 accesos
 *     (ícono + nombre) + "Ver todas" — el brief: no repetir las categorías
 *     en otra grilla más abajo.
 *   - La barra va justo debajo del header y ANTES del hero (orden de compra:
 *     1 buscar, 2 elegir categoría, 3 oferta principal, 4 productos).
 *   - Fuente de datos: LTMS_Utils::get_normalized_product_categories() (dedup
 *     por fingerprint + orden por # de productos = proxy de conversión),
 *     fallback get_terms crudo top 8 si el helper no está cargado.
 *   - Móvil: fila deslizable (overflow-x auto + scroll-snap); escritorio
 *     ≥1024: barra fija sin deslizar.
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeTemplateWiringTest,
 * HomeHeaderRedesignTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeCategoriesBarTest
 */
final class HomeCategoriesBarTest extends LTMS_Unit_Test_Case {

	private string $home_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 *
	 * @param string $src Source PHP crudo.
	 * @return string Source sin comentarios slash-asterisco.
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * HOME-REDESIGN-003-A: bento grid eliminado físicamente (HTML y CSS).
	 */
	public function test_001_bento_grid_eliminado(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringNotContainsString(
			'pv-bento',
			$src,
			'HOME-REDESIGN-003-A: el bento grid (HTML + CSS) debe estar eliminado — la barra es la única navegación de categorías.'
		);
	}

	/**
	 * HOME-REDESIGN-003-B: barra de accesos presente con ícono, nombre y
	 * "Ver todas".
	 */
	public function test_002_barra_de_categorias_presente(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'class="pv-cat-bar"',
			$src,
			'HOME-REDESIGN-003-B: debe existir la barra de categorías.'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__item',
			$src,
			'HOME-REDESIGN-003-B: los accesos de la barra deben usar pv-cat-bar__item.'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__icon',
			$src,
			'HOME-REDESIGN-003-B: los accesos deben tener ícono.'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__name',
			$src,
			'HOME-REDESIGN-003-B: los accesos deben tener nombre.'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__more',
			$src,
			'HOME-REDESIGN-003-B: la barra debe tener el enlace "Ver todas".'
		);
	}

	/**
	 * HOME-REDESIGN-003-C: la barra va ANTES del hero (orden de compra del
	 * brief: 1 buscar, 2 elegir categoría, 3 oferta principal, 4 productos).
	 */
	public function test_003_barra_antes_del_hero(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$pos_cats = strpos( $src, 'class="pv-cat-bar"' );
		$pos_hero = strpos( $src, 'pv-home__hero-wrap' );

		$this->assertNotFalse( $pos_cats, 'La barra de categorías debe existir.' );
		$this->assertNotFalse( $pos_hero, 'El hero debe existir.' );
		$this->assertLessThan(
			$pos_hero,
			$pos_cats,
			'HOME-REDESIGN-003-C: la barra de categorías debe renderizarse ANTES del hero (orden de compra del brief).'
		);
	}

	/**
	 * HOME-REDESIGN-003-D: fuente de datos — helper normalizado (dedup +
	 * orden por conversión), con fallback get_terms crudo.
	 *
	 * HOME-MATRIX-FIX (2026-10-01): el query cambió de array_slice directo
	 * sobre el helper a array_filter (exclusión de la categoría artefacto
	 * 'no-aplica' de los syncs) + array_slice(array_values(...), 0, 8).
	 * El intento del test se preserva: helper normalizado + top 8, y se
	 * añade la aserción de la exclusión (lección #119 — test actualizado
	 * en el mismo commit que el cambio de enfoque).
	 *
	 * HOME-UX2-002 (2026-10-02): el cap de 8 fue ELIMINADO — el operador
	 * reportó que "no se percibe que hay categorías ocultas"; hoy son 20
	 * activas y TODAS se muestran (el indicio de "hay más" lo da el fade
	 * del borde con data-pv-scrollable). Test actualizado en el mismo
	 * commit (lección #119): array_values SIN slice + fallback SIN number.
	 */
	public function test_004_fuente_datos_todas_las_categorias(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertMatchesRegularExpression(
			"/array_filter\(\s*LTMS_Utils::get_normalized_product_categories\(\s*true\s*\),\s*static function/",
			$src,
			'HOME-REDESIGN-003-D: debe usar el helper normalizado (dedup + orden por # de productos).'
		);
		$this->assertMatchesRegularExpression(
			"/\\\$pv_cat_terms\s*=\s*array_values\(\s*\\\$pv_all_cats\s*\);/",
			$src,
			'HOME-UX2-002: TODAS las categorías activas tras la exclusión — sin array_slice de top 8.'
		);
		$this->assertStringNotContainsString(
			'array_slice( array_values( $pv_all_cats ), 0, 8 )',
			$src,
			'HOME-UX2-002: el cap de 8 fue eliminado (el usuario debe percibir todas las categorías).'
		);
		$this->assertMatchesRegularExpression(
			"/'no-aplica'\s*!==\s*\\\$c->slug/",
			$src,
			'HOME-MATRIX-FIX: la categoría artefacto de syncs "NO APLICA" debe excluirse de la barra.'
		);
		$this->assertStringNotContainsString(
			"'number'     => 8,",
			$src,
			'HOME-UX2-002: el fallback get_terms NO debe pedir top 8 — todas las categorías activas.'
		);
	}

	/**
	 * HOME-UX5-002 (2026-10-04): el operador pidió "organiza las categorías
	 * en varias filas, toma imágenes de algunos productos para las
	 * categorías según aplique en vez de esos iconos" — la barra deslizable
	 * del ciclo UX2 (scroll + fade con data-pv-scrollable) fue REEMPLAZADA
	 * por un grid ENVOLVENTE multi-fila con la IMAGEN del best-seller de
	 * cada categoría; el emoji queda SOLO como fallback. Este test reemplaza
	 * al de scroll/fade (lección #119 — misma intención, enfoque nuevo).
	 */
	public function test_005_grid_multifila_con_imagenes_de_productos(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Grid envolvente en el markup (reemplaza al scroll/list horizontal).
		$this->assertStringContainsString(
			'<ul class="pv-cat-bar__grid" role="list">',
			$src,
			'HOME-UX5-002: el markup debe usar el grid multi-fila (pv-cat-bar__grid), no la fila deslizable.'
		);
		$this->assertStringNotContainsString(
			'pv-cat-bar__scroll',
			$src,
			'HOME-UX5-002: el contenedor de scroll horizontal fue eliminado — el grid envuelve en varias filas.'
		);
		$this->assertStringNotContainsString(
			'pv-cat-bar__list',
			$src,
			'HOME-UX5-002: la lista flex width:max-content fue eliminada con el scroll.'
		);

		// Columnas: móvil 4 / ≥480 5 / ≥768 6 / ≥1024 7 (21 activas ≈ 3 filas).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__grid\{[^}]*grid-template-columns:repeat\(4,1fr\);/s',
			$src,
			'HOME-UX5-002: base móvil del grid = 4 columnas.'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-cat-bar__grid\{grid-template-columns:repeat\(7,1fr\);/s',
			$src,
			'HOME-UX5-002: escritorio = 7 columnas (varias filas con las 21 activas).'
		);

		// Imagen del best-seller de la categoría con fallback al emoji.
		$this->assertStringContainsString(
			'pv-cat-bar__img-wrap',
			$src,
			'HOME-UX5-002: la card debe envolver la imagen del producto de la categoría (pv-cat-bar__img-wrap).'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__img',
			$src,
			'HOME-UX5-002: la card debe renderizar el <img> de la categoría (pv-cat-bar__img).'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__icon',
			$src,
			'HOME-UX5-002: el emoji se conserva SOLO como fallback (categorías sin thumbnail).'
		);
		// El fallback por imagen es excluyente: img-wrap si hay imagen, icon si no.
		$this->assertStringContainsString(
			'( \'\' !== $pv_img ) :',
			$src,
			'HOME-UX5-002: el markup decide entre <img> (con imagen) y emoji (sin imagen) por categoría.'
		);

		// Origen de la imagen: best-seller visible con thumbnail del término.
		$this->assertStringContainsString(
			"'key'     => '_thumbnail_id',",
			$src,
			'HOME-UX5-002: el query de imagen exige _thumbnail_id EXISTS (producto con imagen real).'
		);
		$this->assertStringContainsString(
			"get_catalog_ordering_args( 'popularity' )",
			$src,
			'HOME-UX5-002: la imagen se toma del producto MÁS VENDIDO de la categoría (popularity).'
		);
		$this->assertStringContainsString(
			'get_the_post_thumbnail_url( (int) $pv_img_ids[0], \'woocommerce_thumbnail\' )',
			$src,
			'HOME-UX5-002: la URL debe ser el tamaño woocommerce_thumbnail (no la imagen full — payload).'
		);

		// CSS de la imagen: contenida y recortada (object-fit cover).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__img\{[^}]*object-fit:cover;/s',
			$src,
			'HOME-UX5-002: la imagen debe recortarse con object-fit:cover dentro del wrap.'
		);
	}

	/**
	 * HOME-UX4-001 → HOME-UX5-002 (2026-10-04): VISIBILIDAD de la sección —
	 * el head con título + "Ver todas" y el contenedor --pv-maxw se
	 * conservan del ciclo UX4; las cards pasan de tintadas --primary-50 a
	 * blancas con borde --border (la imagen real es la que da presencia),
	 * hover azul + shadow, y los nombres usan clamp de 2 líneas (los reales
	 * son largos: "MASCARILLAS CAPILARES Y TRATAMIENTOS"). Test actualizado
	 * en el mismo commit (lección #119).
	 */
	public function test_006_visibilidad_head_contenedor_y_cards(): void {
		$src = file_get_contents( $this->home_path );
		$markup = $this->strip_php_comments( $src );

		// Head visible con título y "Ver todas" (patrón UX4-001 intacto).
		$this->assertMatchesRegularExpression(
			'/<nav class="pv-cat-bar" aria-labelledby="pv-home-cats-title">/',
			$markup,
			'HOME-UX4-001: el nav debe estar etiquetado por el título visible (patrón aria-labelledby).'
		);
		$this->assertStringContainsString(
			'<h2 class="pv-cat-bar__title" id="pv-home-cats-title">',
			$markup,
			'HOME-UX4-001: debe existir el título visible de la sección.'
		);
		$this->assertStringContainsString(
			'pv-cat-bar__head',
			$markup,
			'HOME-UX4-001: el head (título + Ver todas) debe envolver la fila superior.'
		);
		$pos_head = strpos( $markup, 'pv-cat-bar__head' );
		$pos_more = strpos( $markup, 'pv-cat-bar__more' );
		$pos_grid = strpos( $markup, 'pv-cat-bar__grid' );
		$this->assertNotFalse( $pos_head, 'El head de la barra debe existir.' );
		$this->assertNotFalse( $pos_more, 'El enlace "Ver todas" debe existir.' );
		$this->assertNotFalse( $pos_grid, 'El grid de categorías debe existir.' );
		$this->assertLessThan(
			$pos_grid,
			$pos_more,
			'HOME-UX4-001: "Ver todas" va en el head, ANTES del grid de categorías.'
		);

		// Contenedor igual al del resto de las secciones (UX4-001 intacto).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar\{[^}]*max-width:var\(--pv-maxw\);[^}]*margin:0 auto;/s',
			$src,
			'HOME-UX4-001: la barra debe entrar al contenedor (--pv-maxw 1400px) — antes corría edge-to-edge (primera card en x=0).'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:760px\)\{[^}]*\.pv-scope\.pv-home \.pv-cat-bar\{padding-left:14px;padding-right:14px;\}/s',
			$src,
			'HOME-UX4-001: padding de contenedor móvil 14px, igual que .pv-section.'
		);

		// HOME-UX5-002: cards blancas con borde — la IMAGEN del producto es
		// la que da presencia ahora; el hover refuerza con azul + shadow.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__item\{[^}]*background:var\(--surface\);[^}]*border:1px solid var\(--border\);/s',
			$src,
			'HOME-UX5-002: cards blancas con borde neutro — la imagen real destaca sobre el tinte del ciclo UX4.'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__item:hover\{[^}]*border-color:var\(--primary\);/s',
			$src,
			'HOME-UX5-002: el hover marca la card con borde azul + shadow (presencia en interacción).'
		);

		// Tipografía legible con clamp de 2 líneas (nombres reales largos).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__name\{[^}]*font-size:11\.5px;font-weight:700;/s',
			$src,
			'HOME-UX5-002: nombres 11.5px/700 con clamp de 2 líneas (los nombres reales son largos).'
		);
		$this->assertMatchesRegularExpression(
			'/-webkit-line-clamp:2;/',
			$src,
			'HOME-UX5-002: los nombres se limitan a 2 líneas con ellipsis (clamp).'
		);
		// Fallback emoji: 30px (creció de 28px — ahora es la excepción).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__icon\{font-size:30px;/s',
			$src,
			'HOME-UX5-002: el emoji de fallback crece a 30px (es la excepción sin imagen).'
		);
	}

	/**
	 * QA-TAIWAN-SYNC (2026-10-04): el catálogo de Juguetería Taiwan llegó vía
	 * sync PosGold (52=JUEGO DE MESA, 49=DIDACTICO, 189 productos nuevos).
	 * El mapa de íconos matchea por PREFIJO de slug — DIDACTICO no tenía
	 * entrada y renderizaba el fallback genérico 🛍️ (verificado en el HTML
	 * servido en producción). El test exige que las dos categorías del
	 * catálogo real del vendor tengan ícono propio en el mapa.
	 */
	public function test_007_iconos_de_categorias_reales_del_catalogo(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// DIDACTICO (slug 'didactico' raíz) — debe matchear por prefijo exacto.
		$this->assertMatchesRegularExpression(
			'/\'didactico\'\s*=>\s*\'🧩\',/',
			$src,
			'QA-TAIWAN-SYNC: el mapa de íconos debe tener entrada para el slug didactico (sin ella renderiza el fallback 🛍️ genérico).'
		);

		// JUEGO DE MESA (slug 'juego-de-mesa') — cubierto por el prefijo 'juego' => 🎲
		// (match por prefijo, no slug exacto — HOME-MATRIX-FIX).
		$this->assertMatchesRegularExpression(
			'/\'juego\'\s*=>\s*\'🎲\',/',
			$src,
			'QA-TAIWAN-SYNC: el prefijo juego => 🎲 debe seguir presente (cubre el slug real juego-de-mesa del catálogo).'
		);

		// El fallback 🛍️ se conserva para slugs fuera del mapa.
		$this->assertStringContainsString(
			"\$pv_icon = '🛍️';",
			$src,
			'QA-TAIWAN-SYNC: el fallback genérico 🛍️ debe conservarse para categorías sin entrada en el mapa.'
		);
	}
}
