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
	 * HOME-REDESIGN-003-E: comportamiento responsivo — móvil deslizable.
	 *
	 * HOME-UX2-002 (2026-10-02): escritorio ya NO es barra fija — con las 20
	 * categorías activas no caben en el contenedor: el scroll se mantiene en
	 * TODOS los tamaños y el fade del borde derecho (mask-image activado por
	 * data-pv-scrollable / desactivado por data-pv-at-end, toggled por el JS
	 * del scope HOME) es el indicio de "hay más categorías".
	 */
	public function test_005_responsivo_scroll_todos_los_tamanos_con_fade(): void {
		$src = file_get_contents( $this->home_path );

		// Base: fila deslizable.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-cat-bar__scroll\{[^}]*overflow-x:auto;/s",
			$src,
			'HOME-REDESIGN-003-E: la barra debe ser deslizable en móvil.'
		);
		// HOME-UX2-002: sin overflow:visible en escritorio — el scroll se mantiene.
		$this->assertStringNotContainsString(
			'.pv-cat-bar__scroll{overflow:visible;}',
			$src,
			'HOME-UX2-002: el scroll de la barra se mantiene en TODOS los tamaños (20 categorías activas no caben).'
		);
		// Fade del borde: mask solo con data-pv-scrollable y sin data-pv-at-end.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-cat-bar__scroll\[data-pv-scrollable=\"1\"\]:not\(\[data-pv-at-end=\"1\"\]\)\{[^}]*mask-image:/s",
			$src,
			'HOME-UX2-002: el fade del borde derecho (mask-image) debe existir y activarse solo con desborde.'
		);
		// El JS del scope HOME togglea los data-attrs del fade.
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js' );
		$this->assertStringContainsString(
			"data-pv-scrollable",
			$js,
			'HOME-UX2-002: ltms-plaza-viva.js debe togglear data-pv-scrollable en el scroll de categorías.'
		);
		$this->assertStringContainsString(
			"data-pv-at-end",
			$js,
			'HOME-UX2-002: ltms-plaza-viva.js debe togglear data-pv-at-end al llegar al final del scroll.'
		);
	}

	/**
	 * HOME-UX4-001 (2026-10-03): VISIBILIDAD de la barra — el operador reportó
	 * que la sección no se percibe. Causas verificadas en producción (DOM
	 * 1440px): (a) barra edge-to-edge FUERA del contenedor (primera card en
	 * x=0, "Ver todas" terminando en x=1440; las demás secciones arrancan en
	 * el contenedor de 1400px); (b) cards blancas sobre --bg casi blanco
	 * (#F6F5F8) — solo un borde tenue las separaba; (c) nombres 11.5px
	 * truncados a 76px; (d) sin título visible (solo aria-label).
	 *
	 * Fix verificado por este test (forma del source — el runtime se valida
	 * en el browser check del deploy, lección #186):
	 *   - Head visible: h2 "Explora por categorías" + "Ver todas" arriba
	 *     (nav aria-labelledby, patrón de pv-section__head de trending).
	 *   - Contenedor: max-width:var(--pv-maxw) + margin:0 auto + padding
	 *     22px / 14px en ≤760px (mismo patrón que .pv-section).
	 *   - Cards tintadas --primary-50 con borde --primary-100 (visibles
	 *     sobre --bg); hover invierte a surface + shadow.
	 *   - Tipografía 12.5px/700 + ícono 28px.
	 */
	public function test_006_visibilidad_head_contenedor_y_tinte(): void {
		$src = file_get_contents( $this->home_path );
		$markup = $this->strip_php_comments( $src );

		// Head visible con título y "Ver todas" (el enlace pasó de ir junto
		// al scroll — donde comía ancho en móvil — a la fila del título).
		$this->assertMatchesRegularExpression(
			'/<nav class="pv-cat-bar" aria-labelledby="pv-home-cats-title">/',
			$markup,
			'HOME-UX4-001: el nav debe estar etiquetado por el título visible (patrón aria-labelledby de trending).'
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
		$pos_scroll = strpos( $markup, 'pv-cat-bar__scroll' );
		$this->assertNotFalse( $pos_head, 'El head de la barra debe existir.' );
		$this->assertNotFalse( $pos_more, 'El enlace "Ver todas" debe existir.' );
		$this->assertLessThan(
			$pos_scroll,
			$pos_more,
			'HOME-UX4-001: "Ver todas" va en el head, ANTES del scroll — libera el ancho completo del scroll en móvil.'
		);

		// Contenedor igual al del resto de las secciones.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar\{[^}]*max-width:var\(--pv-maxw\);[^}]*margin:0 auto;/s',
			$src,
			'HOME-UX4-001: la barra debe entrar al contenedor (--pv-maxw 1400px) — antes corría edge-to-edge (primera card en x=0).'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:760px\)\{\s*\.pv-scope\.pv-home \.pv-cat-bar\{padding-left:14px;padding-right:14px;\}/s',
			$src,
			'HOME-UX4-001: padding de contenedor móvil 14px, igual que .pv-section.'
		);

		// Cards tintadas — visibles sobre --bg casi blanco.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__item\{[^}]*background:var\(--primary-50\);[^}]*border:1px solid var\(--primary-100\);/s',
			$src,
			'HOME-UX4-001: las cards deben ser tintadas (--primary-50 + borde --primary-100) — las blancas no se distinguían del fondo.'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__item:hover\{[^}]*background:var\(--surface\);[^}]*border-color:var\(--primary\);/s',
			$src,
			'HOME-UX4-001: el hover invierte a blanco + borde primary (refuerzo de visibilidad en interacción).'
		);

		// Tipografía legible.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__name\{[^}]*font-size:12\.5px;font-weight:700;/s',
			$src,
			'HOME-UX4-001: nombres 12.5px/700 (antes 11.5px/600 — poco legibles).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-cat-bar__icon\{font-size:28px;/s',
			$src,
			'HOME-UX4-001: ícono 28px (antes 26px).'
		);
	}
}
