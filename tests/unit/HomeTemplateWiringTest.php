<?php
/**
 * HomeTemplateWiringTest — tests del wiring del template nativo home.php.
 *
 * Foco: HOME-WIRING-001 (2026-10-01) — la home nativa Plaza Viva
 * (includes/frontend/templates/home.php) estaba completa pero NO conectada:
 * LTMS_Native_Templates::maybe_override() no tenía rama para is_front_page()
 * (el docblock del template mencionaba la opción ltms_home_template_enabled
 * pero la rama no existía — docblock mentiroso). El home vivo era la página
 * de Elementor (ID 30).
 *
 * Hallazgos resueltos por esta suite:
 *
 *   * HOME-WIRING-001-A (rama is_front_page): maybe_override() debe servir
 *     home.php cuando is_front_page() + is_page() + opción
 *     ltms_home_template_enabled='yes' (default). La rama debe correr ANTES
 *     del check de Elementor (did_action elementor_pro/init) porque con
 *     Elementor activo el home cae al final de la rama Elementor sin override
 *     — una rama colocada después nunca se ejecutaría con Elementor activo.
 *     El guard is_page() evita reemplazar el índice del blog cuando
 *     show_on_front='posts' (is_front_page() también es true ahí).
 *
 *   * HOME-WIRING-001-B (flag anti-duplicado del slider):
 *     LTMS_Frontend_Home_Slider::render_shortcode() debe marcar
 *     $this->rendered = true tras renderizar. Antes el flag solo se seteaba
 *     en prepend_slider_to_content (the_content) e inject_home_slider
 *     (wp_footer): un render por do_shortcode() directo (como lo hace el hero
 *     de home.php nativo) dejaba $rendered=false y el fallback de wp_footer
 *     imprimía el banner una SEGUNDA vez (doble slider).
 *
 *   * HOME-WIRING-001-C (cache-busting): el enqueue de ltms-plaza-viva.css
 *     usaba $ver . '-b' . time() — la URL cambiaba en CADA request,
 *     invalidando el cache del CSS en SiteGround Optimizer en cada visita.
 *     La versión debe depender solo de LTMS_VERSION (bump controlado).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source
 * PHP): NO cargan clases del plugin ni invocan WP → deterministas en
 * LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático de
 * Composer (mismo patrón que HomeProductScopeAuditTest, HelpCenterAuditTest).
 * NOTA: no hay test de reflexión sobre LTMS_Frontend_Home_Slider porque la
 * clase declara `const VERSION = LTMS_VERSION` (requiere la constante de WP
 * al cargarla) — el smoke se salta en UNIT_ONLY (ver HomeSliderTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeTemplateWiringTest
 *
 * Verifica el wiring del template nativo home.php en
 * class-ltms-native-templates.php, el flag anti-duplicado del slider en
 * class-ltms-frontend-home-slider.php, y el cache-busting del enqueue.
 */
final class HomeTemplateWiringTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a class-ltms-native-templates.php.
	 */
	private string $native_templates_path;

	/**
	 * Ruta absoluta a class-ltms-frontend-home-slider.php.
	 */
	private string $home_slider_path;

	/**
	 * Ruta absoluta a la plantilla home.php.
	 */
	private string $home_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->native_templates_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->home_slider_path      = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-frontend-home-slider.php';
		$this->home_path             = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco de un
	 * source PHP antes de validar nonces negativos. Los comment blocks
	 * descriptivos de los fixes mencionan texto legalmente partes del código
	 * buscado como documentación — esos mentions NO cuentan como código vivo
	 * (LECCIONES_APRENDIDAS #141 — canarios mentirosos en comments).
	 *
	 * @param string $src Source PHP crudo.
	 * @return string Source sin comentarios slash-asterisco.
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	// ── HOME-WIRING-001-A: rama is_front_page en maybe_override ────────────

	/**
	 * La rama del home nativo debe existir en maybe_override() con la opción,
	 * los condicionales y el fallback file_exists.
	 */
	public function test_001_maybe_override_tiene_rama_home_nativa(): void {
		$this->assertFileExists( $this->native_templates_path );
		$src = $this->strip_php_comments( (string) file_get_contents( $this->native_templates_path ) );

		// Opción con default 'yes' (el home cambia a nativo sin setup manual).
		$this->assertMatchesRegularExpression(
			"/get_option\(\s*'ltms_home_template_enabled',\s*'yes'\s*\)\s*===\s*'yes'/",
			$src,
			'HOME-WIRING-001-A: la rama debe activarse con get_option ltms_home_template_enabled default yes.'
		);

		// Condicionales is_front_page() + is_page() (guard anti blog-index).
		$this->assertMatchesRegularExpression(
			"/is_front_page\(\)\s*&&\s*is_page\(\)/",
			$src,
			'HOME-WIRING-001-A: la rama debe condicionar is_front_page() && is_page() (guard show_on_front=posts).'
		);

		// Resolución del template nativo home.php con fallback file_exists.
		$this->assertMatchesRegularExpression(
			"/self::\\\$template_dir\s*\.\s*'home\.php'/",
			$src,
			'HOME-WIRING-001-A: la rama debe resolver home.php desde self::$template_dir.'
		);
	}

	/**
	 * La rama del home debe correr ANTES del check de Elementor: con Elementor
	 * activo, el home cae al final de la rama Elementor (return $template)
	 * sin override — una rama colocada después nunca se ejecutaría.
	 */
	public function test_002_rama_home_antes_del_check_elementor(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->native_templates_path ) );

		$pos_home        = strpos( $src, 'ltms_home_template_enabled' );
		$pos_elementor   = strpos( $src, "did_action( 'elementor_pro/init' )" );

		$this->assertNotFalse( $pos_home, 'HOME-WIRING-001-A: la rama home debe existir.' );
		$this->assertNotFalse( $pos_elementor, 'El check de Elementor debe existir (rama is_product/is_shop).' );
		$this->assertLessThan(
			$pos_elementor,
			$pos_home,
			'HOME-WIRING-001-A: la rama del home nativo debe aparecer ANTES del check de Elementor en maybe_override().'
		);
	}

	// ── HOME-WIRING-001-B: flag anti-duplicado del slider ──────────────────

	/**
	 * render_shortcode() debe marcar $rendered = true tras renderizar HTML
	 * (el hero del home nativo renderiza el shortcode vía do_shortcode y el
	 * fallback wp_footer no debe duplicar el banner).
	 */
	public function test_003_render_shortcode_marca_rendered(): void {
		$this->assertFileExists( $this->home_slider_path );
		$src = $this->strip_php_comments( (string) file_get_contents( $this->home_slider_path ) );

		// El método render_shortcode debe contener el seteo del flag.
		$this->assertMatchesRegularExpression(
			"/public function render_shortcode\([^)]*\):\s*string\s*\{[^}]*\\\$this->rendered\s*=\s*true;/s",
			$src,
			'HOME-WIRING-001-B: render_shortcode() debe marcar $this->rendered = true tras renderizar.'
		);

		// El seteo debe condicionarse a que el HTML no quede vacío
		// (sin slides activos el fallback wp_footer sigue siendo válido).
		$this->assertMatchesRegularExpression(
			"/''\s*!==\s*\\\$html\s*\)?\s*\{\s*\n?\s*\\\$this->rendered\s*=\s*true;/",
			$src,
			'HOME-WIRING-001-B: el flag debe setearse solo cuando el slider produce HTML.'
		);
	}

	// ── HOME-WIRING-001-C: cache-busting del design system CSS ─────────────

	/**
	 * El enqueue de ltms-plaza-viva.css NO debe usar time() en la versión
	 * (la URL cambiaba en cada request y invalidaba el cache de SG Optimizer).
	 */
	public function test_004_enqueue_plaza_viva_sin_time(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->native_templates_path ) );

		$this->assertDoesNotMatchRegularExpression(
			"/wp_enqueue_style\(\s*'ltms-plaza-viva',[^;]*time\(\)/",
			$src,
			'HOME-WIRING-001-C: el enqueue de ltms-plaza-viva.css no debe depender de time() (invalida el cache de SG en cada request).'
		);

		// La versión debe seguir basada en LTMS_VERSION (bump controlado).
		$this->assertMatchesRegularExpression(
			"/wp_enqueue_style\(\s*'ltms-plaza-viva',[^;]*\\\$ver\s*\)/s",
			$src,
			'HOME-WIRING-001-C: la versión del CSS debe seguir basada en $ver (LTMS_VERSION).'
		);
	}

	// ── Invariante: la plantilla home.php sigue existiendo intacta ─────────

	/**
	 * El template home.php debe seguir emitiendo el scope y el header (la
	 * estructura que el wiring sirve). Sin esto, la rama serviría un template
	 * roto.
	 */
	public function test_005_home_php_estructura_base_preservada(): void {
		$this->assertFileExists( $this->home_path );
		$src = file_get_contents( $this->home_path );

		$this->assertStringContainsString( '.pv-scope.pv-home', $src, 'home.php debe emitir el scope .pv-scope.pv-home.' );
		$this->assertStringContainsString( 'pv-home-header', $src, 'home.php debe emitir el header propio.' );
		$this->assertStringContainsString( "get_header();", $src, 'home.php debe envolver con el header del tema.' );
		$this->assertStringContainsString( "get_footer();", $src, 'home.php debe envolver con el footer del tema.' );
	}
}
