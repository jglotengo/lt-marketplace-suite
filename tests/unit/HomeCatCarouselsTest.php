<?php
/**
 * HomeCatCarouselsTest — ciclo HOME-UX5 (2026-10-04).
 *
 * Validación estructural del rediseño de la home pedido por el operador:
 *   - HOME-UX5-001: carruseles por categoría — top 8 categorías activas,
 *     12 productos c/u (popularity), track de 2 filas con scroll-snap +
 *     flechas prev/next (escritorio), "Ver más" → archivo de la categoría.
 *     REEMPLAZA la sección "Productos en tendencia" (decisión del operador).
 *   - HOME-UX5-003: header ROJO sticky + initHeaderScroll — los chips
 *     (palabras bajo el buscador) se ocultan al desplazarse
 *     (data-pv-scrolled) en móvil Y escritorio.
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el
 * source PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeCategoriesBarTest).
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --testsuite unit --filter HomeCatCarouselsTest
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeCatCarouselsTest
 */
final class HomeCatCarouselsTest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $pv_js_path;
	private string $pv_min_js_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path   = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->pv_js_path  = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->pv_min_js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
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
	 * HOME-UX5-001: fuente de datos de los carruseles — top 8 categorías
	 * activas (por # de productos), 12 productos visibles c/u ordenados por
	 * popularidad, con URL del término para el "Ver más".
	 */
	public function test_001_datos_top_8_categorias_12_productos(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Top 8 categorías.
		$this->assertStringContainsString(
			'array_slice( $pv_cat_terms, 0, 8 ) as $pv_cc_term',
			$src,
			'HOME-UX5-001: los carruseles derivan del top 8 de categorías activas (decisión del operador: no TODAS — la home sería infinita).'
		);
		// 12 productos por categoría (2 filas × ~6 columnas).
		$this->assertStringContainsString(
			"'posts_per_page'      => 12,",
			$src,
			'HOME-UX5-001: cada carrusel consulta 12 productos (2 filas con desborde para el snap).'
		);
		// Orden por popularidad (best sellers primero).
		$this->assertStringContainsString(
			'$pv_popularity_args',
			$src,
			'HOME-UX5-001: los productos se ordenan por popularidad (get_catalog_ordering_args).'
		);
		// Visibilidad WC (excluir hidden/exclude-from-search).
		$this->assertStringContainsString(
			'$pv_vis_meta_query',
			$src,
			'HOME-UX5-001: el meta query de visibilidad WC se aplica a los carruseles (y a las imágenes por categoría).'
		);
		// La query del trending global fue eliminada con la sección.
		$this->assertStringNotContainsString(
			'$pv_trending_ids',
			$src,
			'HOME-UX5-001: la query de trending fue eliminada — los carruseles por categoría la reemplazan.'
		);
	}

	/**
	 * HOME-UX5-001: markup de los carruseles — wrapper con sección por
	 * categoría (h2 + "Ver más"), track de cards delegadas al template part
	 * canónico, flechas prev/next con data attributes, y empty state del
	 * wrapper cuando ninguna categoría tiene productos.
	 */
	public function test_002_markup_seccion_por_categoria_y_flechas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Wrapper de la sección.
		$this->assertStringContainsString(
			'class="pv-section pv-home__cat-carousels"',
			$src,
			'HOME-UX5-001: el wrapper pv-home__cat-carousels reemplaza al pv-home__trending (orden del funnel móvil).'
		);
		// Artículo por categoría con aria-labelledby único por term_id.
		$this->assertStringContainsString(
			'aria-labelledby="pv-cat-carousel-<?php echo esc_attr( (string) $pv_cc[\'term_id\'] ); ?>"',
			$src,
			'HOME-UX5-001: cada carrusel es un <article> etiquetado por su h2 (ARIA).'
		);
		// "Ver más" enlaza al archivo de la categoría (no a una búsqueda).
		$this->assertMatchesRegularExpression(
			'/<a class="pv-section__more" href="<\?php echo esc_url\( \$pv_cc\[\'url\'\] \); \?>">/',
			$src,
			'HOME-UX5-001: el "Ver más" de cada carrusel enlaza al archivo de la categoría ($pv_cc[\'url\'] = get_term_link).'
		);
		// Flechas con data attributes (los lee initCatCarousels en el JS).
		$this->assertStringContainsString(
			'data-pv-carousel-prev',
			$src,
			'HOME-UX5-001: flecha anterior con data-pv-carousel-prev (JS).'
		);
		$this->assertStringContainsString(
			'data-pv-carousel-next',
			$src,
			'HOME-UX5-001: flecha siguiente con data-pv-carousel-next (JS).'
		);
		// Empty state del wrapper (patrón AUDIT-FE-PV-DS-008).
		$this->assertStringContainsString(
			'Aún no hay productos por categoría',
			$src,
			'HOME-UX5-001: empty state visible cuando ninguna categoría tiene productos.'
		);
	}

	/**
	 * HOME-UX5-003: JS del scope HOME — initHeaderScroll togglea
	 * data-pv-scrolled en el header al desplazarse (chips se ocultan por
	 * CSS) e initCatCarousels cable las flechas + el fade de bordes.
	 * El initCatBarFade del ciclo UX2 fue ELIMINADO con el scroll de la
	 * barra (la barra es ahora un grid estático multi-fila).
	 */
	public function test_003_js_header_scroll_y_carruseles(): void {
		$js = file_get_contents( $this->pv_js_path );

		// Header: estado de scroll (chips colapsan via CSS).
		$this->assertMatchesRegularExpression(
			"/function initHeaderScroll\(scope\)/",
			$js,
			'HOME-UX5-003: el scope HOME debe declarar initHeaderScroll.'
		);
		$this->assertMatchesRegularExpression(
			"/window\.scrollY > 8/",
			$js,
			'HOME-UX5-003: umbral de scroll 8px para marcar data-pv-scrolled.'
		);
		$this->assertStringContainsString(
			"data-pv-scrolled",
			$js,
			'HOME-UX5-003: el JS togglea data-pv-scrolled en el header (el CSS colapsa los chips).'
		);

		// Carruseles: flechas + fade por track.
		$this->assertMatchesRegularExpression(
			"/function initCatCarousels\(scope\)/",
			$js,
			'HOME-UX5-001: el scope HOME debe declarar initCatCarousels.'
		);
		$this->assertStringContainsString(
			'[data-pv-carousel-prev]',
			$js,
			'HOME-UX5-001: el JS cable la flecha previa por data-pv-carousel-prev.'
		);
		$this->assertStringContainsString(
			'[data-pv-carousel-next]',
			$js,
			'HOME-UX5-001: el JS cable la flecha siguiente por data-pv-carousel-next.'
		);
		$this->assertStringContainsString(
			'pv-cat-carousel__track',
			$js,
			'HOME-UX5-001: el fade de bordes (data-pv-scrollable) se sincroniza por track de carrusel.'
		);

		// Ambos inicializadores montan en initHome.
		$this->assertStringContainsString(
			'initHeaderScroll(scope);',
			$js,
			'HOME-UX5-003: initHome debe montar initHeaderScroll.'
		);
		$this->assertStringContainsString(
			'initCatCarousels(scope);',
			$js,
			'HOME-UX5-001: initHome debe montar initCatCarousels.'
		);

		// El fade de la barra de categorías murió con el scroll horizontal.
		$this->assertStringNotContainsString(
			'function initCatBarFade',
			$js,
			'HOME-UX5-002: initCatBarFade fue eliminado — la barra de categorías es un grid estático multi-fila.'
		);

		// Live search intacto (lecciones #186/#187 — no romperlo al tocar el scope).
		$this->assertMatchesRegularExpression(
			"/initLiveSearch\(scope\);/",
			$js,
			'HOME-UX5: el live search del header debe seguir montado en initHome.'
		);
	}

	/**
	 * HOME-UX5 infra: el .min.js debe estar REGENERADO con el código nuevo
	 * (SG Optimizer sirve el min en producción — un min viejo dejaría los
	 * carruseles sin flechas y los chips sin ocultarse al scroll).
	 */
	public function test_004_min_js_regenerado(): void {
		$min = file_get_contents( $this->pv_min_js_path );

		$this->assertStringContainsString(
			'data-pv-scrolled',
			$min,
			'HOME-UX5-003: el .min.js debe contener el toggle data-pv-scrolled (header rojo + chips ocultos al scroll).'
		);
		$this->assertStringContainsString(
			'data-pv-carousel-prev',
			$min,
			'HOME-UX5-001: el .min.js debe contener el cableado de la flecha previa.'
		);
		$this->assertStringContainsString(
			'data-pv-carousel-next',
			$min,
			'HOME-UX5-001: el .min.js debe contener el cableado de la flecha siguiente.'
		);
		$this->assertStringNotContainsString(
			'pv-cat-bar__scroll',
			$min,
			'HOME-UX5-002: el .min.js no debe contener el selector del scroll viejo de la barra (initCatBarFade eliminado).'
		);
	}
}
