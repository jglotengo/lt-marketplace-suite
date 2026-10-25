<?php
/**
 * HomeHeroTest — tests del hero de la home nativa (HOME-REDESIGN-004).
 *
 * Foco: C4 del rediseño de la home (patrón Amazon):
 *   - 1 banner principal (slide 1 del Home Slider del admin) + 2 tarjetas
 *     secundarias (slides 2-3), cada una con título corto y un único botón.
 *   - Sin autoplay y sin carrusel en el hero (brief) — el hero renderiza los
 *     slides directamente (markup propio, no ltms-hs).
 *   - Desktop: banner ≈66% izq + tarjetas apiladas ≈33% der, una sola altura.
 *     Móvil/tablet: banner full-width + tarjetas en fila 2 columnas.
 *   - <picture> con recorte distinto móvil/escritorio; la imagen del banner
 *     con fetchpriority=high (sin carga diferida); el texto del banner en
 *     HTML (overlay con scrim, nunca dentro de la imagen).
 *   - Fallback: sin banners → hero gradiente del design system con un solo
 *     CTA (una sola acción principal).
 *   - Guards del Home Slider: el fallback wp_footer no corre en la home
 *     nativa (did_action ltms_before_home_plazaviva) y el enqueue del CSS/JS
 *     del slider se salta (peso muerto).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeTemplateWiringTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeHeroTest
 */
final class HomeHeroTest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $home_slider_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path        = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->home_slider_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-frontend-home-slider.php';
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
	 * HOME-REDESIGN-004-A: datos del hero — slides del admin (slide 1 principal,
	 * slides 2-3 tarjetas).
	 */
	public function test_001_datos_hero_desde_home_slider(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertMatchesRegularExpression(
			"/\( new LTMS_Frontend_Home_Slider\(\) \)->get_slides\(\s*true\s*\)/",
			$src,
			'HOME-REDESIGN-004-A: el hero debe leer los slides del Home Slider (contenido del admin).'
		);
		$this->assertMatchesRegularExpression(
			"/array_slice\(\s*\\\$pv_hero_slides,\s*0,\s*2\s*\)/",
			$src,
			'HOME-REDESIGN-004-A: las tarjetas secundarias deben ser los slides 2-3.'
		);
	}

	/**
	 * HOME-REDESIGN-004-B: markup del banner principal — <picture>, prioridad
	 * de carga, overlay con título + CTA en HTML (nunca dentro de la imagen).
	 */
	public function test_002_banner_principal_picture_y_overlay_html(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'class="pv-home-hero__banner"',
			$src,
			'HOME-REDESIGN-004-B: debe existir el banner principal.'
		);
		$this->assertMatchesRegularExpression(
			'/pv-home-hero__banner"\s*\n?\s*href="[^"]*">\s*(?:<\?php\s*\?>\s*)?<picture>/',
			$src,
			'HOME-REDESIGN-004-B: el banner principal debe usar <picture> (recortes distintos móvil/escritorio).'
		);
		$this->assertStringContainsString(
			'fetchpriority="high"',
			$src,
			'HOME-REDESIGN-004-B: la imagen del banner debe tener prioridad de carga (sin carga diferida).'
		);
		$this->assertStringContainsString(
			'pv-home-hero__banner-title',
			$src,
			'HOME-REDESIGN-004-B: el título del banner debe ir en HTML (overlay).'
		);
		$this->assertStringContainsString(
			'pv-home-hero__banner-cta',
			$src,
			'HOME-REDESIGN-004-B: el banner debe tener su único botón CTA.'
		);
		// Scrim del overlay (contraste del texto sobre la imagen).
		$this->assertMatchesRegularExpression(
			'/pv-home-hero__overlay\{[^}]*linear-gradient\(/s',
			$src,
			'HOME-REDESIGN-004-B: el overlay debe tener scrim de gradiente (contraste AA del texto).'
		);
	}

	/**
	 * HOME-REDESIGN-004-C: tarjetas secundarias (slides 2-3) con lazy load.
	 */
	public function test_003_tarjetas_secundarias(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'class="pv-home-hero__side"',
			$src,
			'HOME-REDESIGN-004-C: debe existir el contenedor de tarjetas secundarias.'
		);
		$this->assertStringContainsString(
			'class="pv-home-hero__card"',
			$src,
			'HOME-REDESIGN-004-C: deben existir las tarjetas secundarias.'
		);
		$this->assertStringContainsString(
			'pv-home-hero__card-cta',
			$src,
			'HOME-REDESIGN-004-C: cada tarjeta debe tener su único botón CTA.'
		);
	}

	/**
	 * HOME-REDESIGN-004-D: h1 único (estructural: una rama if/else, un h1 por
	 * rama — en runtime solo uno se renderiza).
	 */
	public function test_004_h1_unico(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		/* UIUX-BATCH-2026-10-25b (lección #119): el operador pidió eliminar el
		 * bloque del hero con el titular del marketplace ("cerca del header").
		 * El head (eyebrow + h1 + sub) fue eliminado de AMBAS ramas — con
		 * banners el hero muestra solo banner + tarjetas; sin banners la
		 * home pasa directa al trust bar. */
		$count = substr_count( $src, 'id="pv-home-hero-title"' );
		$this->assertSame(
			0,
			$count,
			'UIUX-BATCH-2026-10-25b: el h1 del hero fue eliminado de ambas ramas (pedido del operador).'
		);
		$this->assertStringNotContainsString(
			'Compra con confianza, vende con libertad',
			$src,
			'UIUX-BATCH-2026-10-25b: el titular del hero no existe en ninguna rama de la home.'
		);
	}

	/**
	 * HOME-REDESIGN-004-E: sin carrusel/slider en el hero nativo — el hero
	 * renderiza los slides directamente (markup propio), sin autoplay.
	 */
	public function test_005_sin_carrusel_ni_autoplay_en_hero(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringNotContainsString(
			'data-ltms-hs',
			$src,
			'HOME-REDESIGN-004-E: el hero nativo no debe renderizar el carrusel ltms-hs (sin autoplay, banner estático).'
		);

		// Guard del fallback wp_footer: no corre en la home nativa.
		$slider = $this->strip_php_comments( file_get_contents( $this->home_slider_path ) );
		$this->assertMatchesRegularExpression(
			"/did_action\(\s*'ltms_before_home_plazaviva'\s*\)/",
			$slider,
			'HOME-REDESIGN-004-E: el fallback wp_footer debe saltarse en la home nativa (did_action ltms_before_home_plazaviva).'
		);

		// Guard del enqueue: el CSS/JS del slider no se encola en la home nativa.
		$this->assertMatchesRegularExpression(
			"/public function enqueue_frontend_assets\(\): void \{[^}]*ltms_home_template_enabled/s",
			$slider,
			'HOME-REDESIGN-004-E: el enqueue del slider debe saltarse en la home nativa (peso muerto).'
		);
	}

	/**
	 * HOME-REDESIGN-004-F: CSS responsivo — móvil banner full + tarjetas 2 col;
	 * escritorio ≥1024 banner 66% + tarjetas apiladas 33%.
	 */
	public function test_006_css_responsivo_hero(): void {
		$src = file_get_contents( $this->home_path );

		// Base móvil: tarjetas en fila de 2 columnas.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-hero__side\{[^}]*grid-template-columns:repeat\(2,1fr\);/s",
			$src,
			'HOME-REDESIGN-004-F: en móvil las tarjetas van en fila de 2 columnas.'
		);
		// Escritorio ≥1024: banner 66% + tarjetas apiladas 33%.
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-hero__grid\{grid-template-columns:2fr 1fr;/s",
			$src,
			'HOME-REDESIGN-004-F: en escritorio el banner va a la izquierda (≈66%) y las tarjetas a la derecha (≈33%).'
		);
		// HOME-CATBAR-2026-002 (2026-10-15, operador: "las imágenes de la
		// sección de banner no se ven completas en vista escritorio"): el
		// recorte 16/6 + img absolute + object-fit:cover de ≥768 fue
		// ELIMINADO — el banner fluye con su ALTURA NATURAL en todos los
		// breakpoints (la imagen del admin se ve completa). Test actualizado
		// en el mismo commit (lección #119).
		$this->assertStringNotContainsString(
			'aspect-ratio:16/6',
			$src,
			'HOME-CATBAR-2026-002: el banner NO debe forzar aspect-ratio 16/6 (recortaba la imagen en escritorio).'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.pv-home-hero__banner img\{[^}]*object-fit:cover;/s',
			$src,
			'HOME-CATBAR-2026-002: la img del banner NO debe usar object-fit:cover (la imagen se ve COMPLETA, altura natural).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home-hero__banner img\{display:block;width:100%;height:auto;\}/',
			$src,
			'HOME-CATBAR-2026-002: la img del banner fluye con altura natural (width:100%;height:auto) en todos los breakpoints.'
		);
	}
}
