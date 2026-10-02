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
	 * orden por conversión) top 8, con fallback get_terms crudo top 8.
	 *
	 * HOME-MATRIX-FIX (2026-10-01): el query cambió de array_slice directo
	 * sobre el helper a array_filter (exclusión de la categoría artefacto
	 * 'no-aplica' de los syncs) + array_slice(array_values(...), 0, 8).
	 * El intento del test se preserva: helper normalizado + top 8, y se
	 * añade la aserción de la exclusión (lección #119 — test actualizado
	 * en el mismo commit que el cambio de enfoque).
	 */
	public function test_004_fuente_datos_top_8_normalizada(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertMatchesRegularExpression(
			"/array_filter\(\s*LTMS_Utils::get_normalized_product_categories\(\s*true\s*\),\s*static function/",
			$src,
			'HOME-REDESIGN-003-D: debe usar el helper normalizado (dedup + orden por # de productos).'
		);
		$this->assertMatchesRegularExpression(
			"/array_slice\(\s*array_values\(\s*\\\$pv_all_cats\s*\),\s*0,\s*8\s*\)/",
			$src,
			'HOME-REDESIGN-003-D: debe tomar el top 8 tras la exclusión (brief: 8-10 accesos).'
		);
		$this->assertMatchesRegularExpression(
			"/'no-aplica'\s*!==\s*\\\$c->slug/",
			$src,
			'HOME-MATRIX-FIX: la categoría artefacto de syncs "NO APLICA" debe excluirse de la barra.'
		);
		$this->assertMatchesRegularExpression(
			"/'number'\s*=>\s*8,/",
			$src,
			'HOME-REDESIGN-003-D: el fallback get_terms debe pedir top 8 (brief: 8-10 accesos).'
		);
	}

	/**
	 * HOME-REDESIGN-003-E: comportamiento responsivo — móvil deslizable,
	 * escritorio ≥1024 barra fija sin deslizar.
	 */
	public function test_005_responsivo_deslizable_movil_fija_escritorio(): void {
		$src = file_get_contents( $this->home_path );

		// Base: fila deslizable.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-cat-bar__scroll\{[^}]*overflow-x:auto;/s",
			$src,
			'HOME-REDESIGN-003-E: la barra debe ser deslizable en móvil.'
		);
		// Escritorio ≥1024: sin deslizar.
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-cat-bar__scroll\{overflow:visible;\}/s",
			$src,
			'HOME-REDESIGN-003-E: en escritorio la barra debe ser fija (sin deslizar).'
		);
	}
}
