<?php
/**
 * HomeHeaderRedesignTest — tests del header de la home nativa (HOME-REDESIGN-002).
 *
 * Foco: C2 del rediseño de la home (patrón Amazon simplificado):
 *   - Header azul marino #1A1A4E con texto blanco, sticky, safe-area.
 *   - Buscador protagonista con sugerencias live (máx 6, endpoint
 *     ltms_live_search) y filtro opcional de categoría dentro del campo
 *     (select product_cat — apply_shop_filters() ya lo soporta en /tienda/).
 *   - Enlace "Saltar al contenido" + wrapper semántico <main id="pv-main">.
 *   - CSS defensivo body.pv-home-native: oculta el header del tema y el
 *     floating access de ltms-header-nav.js (excluidos por el brief).
 *   - CSP-compliance: home.php sigue sin <script> inline (el live search
 *     vive en el scope HOME de ltms-plaza-viva.js).
 *   - Touch targets 44px (search-btn, sugerencias) y font-size 16px en el
 *     input (evita el zoom automático de iOS en campos).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source
 * PHP/JS/CSS): NO cargan clases del plugin ni invocan WP → deterministas en
 * LTMS_UNIT_ONLY=true (mismo patrón que HomeTemplateWiringTest,
 * HomeProductScopeAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeHeaderRedesignTest
 */
final class HomeHeaderRedesignTest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $native_templates_path;
	private string $pv_js_path;
	private string $pv_min_js_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path              = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->native_templates_path  = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->pv_js_path             = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->pv_min_js_path         = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
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
	 * HOME-REDESIGN-002-A: skip link + wrapper <main> semántico.
	 */
	public function test_001_skip_link_y_main_semantico(): void {
		$this->assertFileExists( $this->home_path );
		$src = file_get_contents( $this->home_path );

		$this->assertStringContainsString(
			'class="pv-home-skip"',
			$src,
			'HOME-REDESIGN-002-A: home.php debe emitir el enlace "Saltar al contenido".'
		);
		$this->assertMatchesRegularExpression(
			'/pv-home-skip"[^>]*href="#pv-main"/',
			$src,
			'HOME-REDESIGN-002-A: el skip link debe apuntar a #pv-main.'
		);
		$this->assertStringContainsString(
			'<main id="pv-main">',
			$src,
			'HOME-REDESIGN-002-A: home.php debe envolver el contenido en <main id="pv-main"> (HTML semántico).'
		);
		$this->assertStringContainsString(
			'</main><!-- /#pv-main -->',
			$src,
			'HOME-REDESIGN-002-A: el <main> debe cerrarse antes del footer.'
		);
	}

	/**
	 * HOME-REDESIGN-002-B: header azul marino con texto blanco (patrón Amazon).
	 */
	public function test_002_header_azul_marino(): void {
		$src = file_get_contents( $this->home_path );

		// Fondo azul marino del header (paleta del brief).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header\{[^}]*background:#1A1A4E;/s",
			$src,
			'HOME-REDESIGN-002-B: el header debe tener fondo azul marino #1A1A4E.'
		);
		// Logo con texto blanco.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__logo\{[^}]*color:#fff;/s",
			$src,
			'HOME-REDESIGN-002-B: el logo del header debe ser texto blanco sobre azul marino.'
		);
		// Safe-area en el header sticky.
		$this->assertStringContainsString(
			'env(safe-area-inset-top',
			$src,
			'HOME-REDESIGN-002-B: el header sticky debe respetar env(safe-area-inset-*).'
		);
	}

	/**
	 * HOME-REDESIGN-002-C: buscador con filtro de categoría + combobox ARIA +
	 * panel de sugerencias.
	 */
	public function test_003_buscador_filtro_categoria_y_sugerencias(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Filtro de categoría dentro del campo (select product_cat).
		$this->assertStringContainsString(
			'class="pv-home-header__search-cat"',
			$src,
			'HOME-REDESIGN-002-C: el buscador debe incluir el filtro de categoría (select).'
		);
		$this->assertMatchesRegularExpression(
			'/<select[^>]*name="product_cat"/',
			$src,
			'HOME-REDESIGN-002-C: el select de categoría debe viajar como product_cat (apply_shop_filters lo soporta).'
		);

		// Combobox ARIA en el input.
		$this->assertStringContainsString( 'aria-controls="pv-home-suggestions"', $src );
		$this->assertMatchesRegularExpression(
			'/pv-home-search"\s*\n?\s*class="pv-home-header__search-input"\s*\n?\s*name="s"\s*\n?\s*placeholder="[^"]*"\s*\n?\s*value="[^"]*"\s*\n?\s*autocomplete="off"\s*\n?\s*role="combobox"/s',
			$src,
			'HOME-REDESIGN-002-C: el input del buscador debe declarar role="combobox" (ARIA).'
		);

		// Panel de sugerencias (máx 6).
		$this->assertStringContainsString(
			'id="pv-home-suggestions"',
			$src,
			'HOME-REDESIGN-002-C: debe existir el panel de sugerencias.'
		);
		$this->assertMatchesRegularExpression(
			'/pv-home-header__suggestions"[^>]*role="listbox"/',
			$src,
			'HOME-REDESIGN-002-C: el panel de sugerencias debe tener role="listbox".'
		);
	}

	/**
	 * HOME-REDESIGN-002-D: el live search vive en el scope HOME de
	 * ltms-plaza-viva.js (CSP-compliance) — endpoint, límite 6, debounce,
	 * teclado accesible.
	 */
	public function test_004_live_search_en_scope_home_del_design_system(): void {
		$src = file_get_contents( $this->pv_js_path );

		$this->assertStringContainsString(
			'ltms_live_search',
			$src,
			'HOME-REDESIGN-002-D: el live search debe llamar al endpoint ltms_live_search.'
		);
		$this->assertMatchesRegularExpression(
			"/initLiveSearch\(scope\)/",
			$src,
			'HOME-REDESIGN-002-D: el scope HOME debe inicializar el live search.'
		);
		$this->assertMatchesRegularExpression(
			"/type:\s*'products',\s*limit:\s*6/",
			$src,
			'HOME-REDESIGN-002-D: el live search debe pedir máx 6 sugerencias (brief).'
		);
		$this->assertMatchesRegularExpression(
			"/q\.length < 2/",
			$src,
			'HOME-REDESIGN-002-D: el live search debe exigir mínimo 2 caracteres (paridad con el endpoint).'
		);
		$this->assertMatchesRegularExpression(
			"/e\.key === 'Escape'/",
			$src,
			'HOME-REDESIGN-002-D: el live search debe cerrar con Escape (teclado accesible).'
		);

		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$min = file_get_contents( $this->pv_min_js_path );
		$this->assertStringContainsString(
			'ltms_live_search',
			$min,
			'HOME-REDESIGN-002-D: el .min.js debe contener el live search (regenerado).'
		);
	}

	/**
	 * HOME-REDESIGN-002-E: CSS defensivo body.pv-home-native — oculta el
	 * header del tema y el floating access (selectores precisos, sin bare
	 * header que matchearía .pv-home-header).
	 */
	public function test_005_defensiva_header_tema_y_floating_oculto(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertStringContainsString(
			'body.pv-home-native .site-header',
			$src,
			'HOME-REDESIGN-002-E: debe ocultar el .site-header del tema en la home nativa.'
		);
		$this->assertStringContainsString(
			'body.pv-home-native .elementor-location-header',
			$src,
			'HOME-REDESIGN-002-E: debe ocultar el header del Theme Builder de Elementor.'
		);
		$this->assertStringContainsString(
			'body.pv-home-native #ltms-floating-access',
			$src,
			'HOME-REDESIGN-002-E: debe ocultar el floating access (elemento fijo extra, excluido por el brief).'
		);
		$this->assertStringContainsString(
			'body.pv-home-native #ltms-hello-access',
			$src,
			'HOME-REDESIGN-002-E: debe ocultar el acceso inyectado en el header de Hello Elementor.'
		);

		// La clase body se emite desde native-templates.
		$native = $this->strip_php_comments( file_get_contents( $this->native_templates_path ) );
		$this->assertMatchesRegularExpression(
			"/is_front_page\(\)\s*&&\s*is_page\(\)\s*\)\s*\{\s*\n?\s*\\\$classes\[\]\s*=\s*'pv-home-native';/",
			$native,
			'HOME-REDESIGN-002-E: body_class() debe añadir pv-home-native en la página frontal.'
		);
	}

	/**
	 * HOME-REDESIGN-002-F: touch targets 44px y font-size 16px en el input
	 * (evita el zoom automático de iOS en campos).
	 */
	public function test_006_touch_targets_y_font_size_ios(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-btn\{[^}]*height:44px;/s",
			$src,
			'HOME-REDESIGN-002-F: el botón Buscar debe tener 44px de altura (touch target).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__suggestion\{[^}]*min-height:44px;/s",
			$src,
			'HOME-REDESIGN-002-F: las sugerencias deben tener min-height 44px (touch target).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-input\{[^}]*font-size:16px;/s",
			$src,
			'HOME-REDESIGN-002-F: el input del buscador debe ser 16px (evita el zoom automático de iOS).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-cat\{[^}]*font-size:16px;/s",
			$src,
			'HOME-REDESIGN-002-F: el select de categoría debe ser 16px (evita el zoom automático de iOS).'
		);
	}
}
