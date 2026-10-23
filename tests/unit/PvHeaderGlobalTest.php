<?php
/**
 * PvHeaderGlobalTest — REG-CHOICE-008 (2026-10-23, operador: "el header del
 * home aplícalo para todas las demás páginas públicas del proyecto y oculta
 * el viejo").
 *
 * El header Plaza Viva de la home (logo · buscador con sugerencias live +
 * filtro categoría · Vender GRATIS + Cuenta/Favoritos/Carrito · catbar) se
 * extrajo a UN componente compartido:
 *  - Markup:  includes/frontend/class-ltms-pv-header.php (LTMS_PV_Header::render)
 *  - CSS:     assets/css/ltms-pv-header.css
 *  - Inyección: hook wp_body_open (accepted_args=0 — lección #190) en TODAS
 *    las páginas públicas.
 *  - Ocultación del header viejo del tema: body class pv-pv-header.
 *  - Exclusiones (decisiones previas del operador): panel del vendedor
 *    (HOME-CATBAR-2026-011) y funnel auth vendedor (HOME-CATBAR-2026-012 /
 *    AUTH-LOGO-001b).
 *  - La catbar se abstiene en su hook global cuando el header PV ya
 *    renderizó (did_render) — sin barra duplicada (HOME-CATBAR-2026-006b).
 *  - El live search del buscador funciona en cualquier página con el header
 *    (guard extendido en ltms-plaza-viva.js initHome()).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 * @group pv-header-global
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PvHeaderGlobalTest
 *
 * @group pv-header-global
 */
final class PvHeaderGlobalTest extends LTMS_Unit_Test_Case {

	private string $class_path;
	private string $css_path;
	private string $css_min_path;
	private string $home_path;
	private string $kernel_path;
	private string $main_path;
	private string $catbar_path;
	private string $pv_js_path;
	private string $pv_js_min_path;
	private string $header_nav_js_path;

	protected function setUp(): void {
		parent::setUp();
		$this->class_path      = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-pv-header.php';
		$this->css_path        = dirname( __DIR__, 2 ) . '/assets/css/ltms-pv-header.css';
		$this->css_min_path    = dirname( __DIR__, 2 ) . '/assets/css/ltms-pv-header.min.css';
		$this->home_path       = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->kernel_path     = dirname( __DIR__, 2 ) . '/includes/core/class-ltms-kernel.php';
		$this->main_path       = dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php';
		$this->catbar_path     = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-global-catbar.php';
		$this->pv_js_path      = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->pv_js_min_path  = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
		$this->header_nav_js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-header-nav.js';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * REG-CHOICE-008-A: el componente existe, bootea en el kernel y está en el
	 * autoloader + whitelist del deploy webhook (lección #177).
	 */
	public function test_001_componente_registrado(): void {
		$this->assertFileExists( $this->class_path );
		$src = $this->strip_php_comments( file_get_contents( $this->class_path ) );
		$this->assertStringContainsString( 'class LTMS_PV_Header {', $src, 'REG-CHOICE-008-A: debe existir la clase LTMS_PV_Header.' );

		// Kernel boot (patrón de los módulos frontend).
		$kernel = file_get_contents( $this->kernel_path );
		$this->assertMatchesRegularExpression(
			"/if \( class_exists\( 'LTMS_PV_Header' \) \) \{\s*\n\s*LTMS_PV_Header::init\(\);/",
			$kernel,
			'REG-CHOICE-008-A: el kernel debe bootear LTMS_PV_Header::init().'
		);

		// Autoloader.
		$main = (string) file_get_contents( $this->main_path );
		$this->assertStringContainsString(
			"'ltms-pv-header'                         => 'frontend/class-ltms-pv-header.php',",
			$main,
			'REG-CHOICE-008-A: la clase debe estar en el mapa del autoloader.'
		);

		// Whitelist del deploy webhook (lección #177: inventario vivo).
		$webhook = (string) file_get_contents( dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php' );
		$this->assertStringContainsString( "'includes/frontend/class-ltms-pv-header.php',", $webhook, 'REG-CHOICE-008-A: la clase debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'assets/css/ltms-pv-header.css',", $webhook, 'REG-CHOICE-008-A: el CSS debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'assets/css/ltms-pv-header.min.css',", $webhook, 'REG-CHOICE-008-A: el .min.css debe estar en la whitelist del webhook.' );
	}

	/**
	 * REG-CHOICE-008-B: hooks del componente — wp_body_open con accepted_args=0
	 * (lección #190: do_action antepone un dummy), body_class y enqueue global.
	 */
	public function test_002_hooks_wl_body_open_body_class_enqueue(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->class_path ) );

		$this->assertMatchesRegularExpression(
			"/add_action\( 'wp_body_open', \[ __CLASS__, 'maybe_render' \], 5, 0 \);/",
			$src,
			'REG-CHOICE-008-B: el hook wp_body_open debe declarar accepted_args=0 EXPLÍCITO (lección #190).'
		);
		$this->assertMatchesRegularExpression(
			"/add_filter\( 'body_class', \[ __CLASS__, 'add_body_class' \] \);/",
			$src,
			'REG-CHOICE-008-B: debe registrar el filtro body_class (clase pv-pv-header).'
		);
		$this->assertMatchesRegularExpression(
			"/add_action\( 'wp_enqueue_scripts', \[ __CLASS__, 'enqueue_assets' \] \);/",
			$src,
			'REG-CHOICE-008-B: debe encolar su CSS en front-end.'
		);
		$this->assertMatchesRegularExpression(
			"/wp_enqueue_style\( 'ltms-pv-header'/",
			$src,
			'REG-CHOICE-008-B: el handle del CSS debe ser ltms-pv-header (con dependencia del design system).'
		);
	}

	/**
	 * REG-CHOICE-008-C: exclusiones de should_render — admin/AJAX, panel del
	 * vendedor y funnel auth vendedor (decisiones HOME-CATBAR-2026-011/012).
	 */
	public function test_003_exclusiones_panel_y_auth(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->class_path ) );

		$this->assertMatchesRegularExpression(
			"/if \( is_admin\(\) \|\| wp_doing_ajax\(\) \) \{\s*\n\s*return false;/",
			$src,
			'REG-CHOICE-008-C: should_render debe excluir admin y AJAX.'
		);
		$this->assertMatchesRegularExpression(
			"/LTMS_Global_Catbar::is_vendor_auth_page\(\) \|\| LTMS_Global_Catbar::is_vendor_panel_page\(\)/",
			$src,
			'REG-CHOICE-008-C: el header PV NO renderiza en el panel ni en el funnel auth (decisiones del operador).'
		);
		// did_render: flag anti-duplicado consultable por la catbar.
		$this->assertStringContainsString( 'private static $did_render', $src, 'REG-CHOICE-008-C: debe existir el flag did_render.' );
		$this->assertMatchesRegularExpression(
			"/self::\\\$did_render = true;/",
			$src,
			'REG-CHOICE-008-C: render() debe marcar did_render=true al terminar.'
		);
	}

	/**
	 * REG-CHOICE-008-D: la catbar se abstiene en su hook global cuando el
	 * header PV ya renderizó — sin barra duplicada (HOME-CATBAR-2026-006b).
	 */
	public function test_004_catbar_sin_duplicado(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->catbar_path ) );

		$this->assertMatchesRegularExpression(
			"/'wp_body_open' === \\\$context && class_exists\( 'LTMS_PV_Header' \) && LTMS_PV_Header::did_render\(\)/",
			$src,
			'REG-CHOICE-008-D: should_render de la catbar debe abstenerse si el header PV ya renderizó.'
		);
		// El header PV solo imprime la barra si la propia catbar lo permite.
		$hdr = $this->strip_php_comments( file_get_contents( $this->class_path ) );
		$this->assertMatchesRegularExpression(
			"/LTMS_Global_Catbar::should_render\( 'home_header' \)/",
			$hdr,
			'REG-CHOICE-008-D: el header PV respeta las exclusiones de la catbar (checkout, panel, auth) antes de renderizarla.'
		);
	}

	/**
	 * REG-CHOICE-008-E: markup canónico del header compartido — las 3 zonas
	 * + botón Vender estático + buscador con sugerencias + catbar.
	 */
	public function test_005_markup_header_compartido(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->class_path ) );

		$this->assertStringContainsString( '<header class="pv-home-header" role="banner">', $src, 'REG-CHOICE-008-E: el header debe mantener la clase canónica pv-home-header.' );
		$this->assertStringContainsString( 'pv-home-header__logo', $src, 'REG-CHOICE-008-E: zona 1 — logo oficial.' );
		$this->assertStringContainsString( 'logo-lo-tengo-white.png', $src, 'REG-CHOICE-008-E: el logo usa la variante blanca (header negro).' );
		$this->assertStringContainsString( 'id="pv-home-search"', $src, 'REG-CHOICE-008-E: zona 2 — buscador.' );
		$this->assertStringContainsString( 'id="pv-home-suggestions"', $src, 'REG-CHOICE-008-E: panel de sugerencias live.' );
		$this->assertStringContainsString( 'pv-home-header__sell', $src, 'REG-CHOICE-008-E: botón Vender estático (REG-CHOICE-005).' );
		$this->assertStringContainsString( 'data-ltms-open-cart', $src, 'REG-CHOICE-008-E: el carrito abre el minicart.' );
		$this->assertStringContainsString( 'data-pv-wishlist-count', $src, 'REG-CHOICE-008-E: badge de favoritos actualizable en vivo.' );
		$this->assertStringContainsString( 'Ingresar / Registrarse', $src, 'REG-CHOICE-008-E: label guest (REG-CHOICE-002).' );
	}

	/**
	 * REG-CHOICE-008-F: home.php ya NO imprime su propio header (una sola
	 * fuente de verdad) pero conserva skip link, main semántico, get_header
	 * (que dispara wp_body_open) y su bloque defensivo del tema.
	 */
	public function test_006_home_php_sin_header_duplicado(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// El markup del header ya no vive en home.php.
		$this->assertStringNotContainsString(
			'<header class="pv-home-header"',
			$src,
			'REG-CHOICE-008-F: home.php NO debe imprimir el header (lo inyecta LTMS_PV_Header vía wp_body_open).'
		);
		$this->assertStringNotContainsString(
			"LTMS_Global_Catbar::render_bar( 'home_header' );",
			$src,
			'REG-CHOICE-008-F: la llamada inline de la catbar se movió al componente compartido.'
		);
		// Conserva: skip link, main, get_header (dispara el hook) y defensiva.
		$this->assertStringContainsString( 'class="pv-home-skip"', $src, 'REG-CHOICE-008-F: el skip link permanece en home.php.' );
		$this->assertStringContainsString( '<main id="pv-main">', $src, 'REG-CHOICE-008-F: el main semántico permanece.' );
		$this->assertStringContainsString( 'get_header();', $src, 'REG-CHOICE-008-F: home.php conserva get_header() — su wp_body_open dispara la inyección del header PV.' );
		$this->assertStringContainsString( 'body.pv-home-native .elementor-location-header', $src, 'REG-CHOICE-008-F: el bloque defensivo de la home (header+footer del tema) se conserva.' );
	}

	/**
	 * REG-CHOICE-008-G: CSS del componente — hide del tema bajo body.pv-pv-header,
	 * catbar estática dentro del header, compensación sticky de checkout.
	 * Sincronizado con el .min.css (SG Optimizer sirve el min).
	 */
	public function test_007_css_componente_y_hide_tema(): void {
		$css = file_get_contents( $this->css_path );

		// Header negro sticky (tokens BRAND-2026-001 / HOME-UX-014).
		$this->assertMatchesRegularExpression(
			'/\.pv-home-header\{[^}]*position:sticky;top:0;[^}]*background:#000000;/s',
			$css,
			'REG-CHOICE-008-G: el header compartido es sticky con fondo negro de marca.'
		);
		// Hide del header viejo del tema (header-only: el footer del tema se
		// conserva fuera de la home).
		$this->assertMatchesRegularExpression( '/body\.pv-pv-header \.site-header,/', $css, 'REG-CHOICE-008-G: oculta .site-header del tema.' );
		$this->assertMatchesRegularExpression( '/body\.pv-pv-header \.elementor-location-header,/', $css, 'REG-CHOICE-008-G: oculta el header de Elementor Theme Builder.' );
		$this->assertMatchesRegularExpression( '/body\.pv-pv-header #ltms-floating-access,/', $css, 'REG-CHOICE-008-G: neutraliza el floating access del header-nav JS.' );
		$this->assertDoesNotMatchRegularExpression(
			'/body\.pv-pv-header footer/',
			$css,
			'REG-CHOICE-008-G: NUNCA un selector bare footer bajo pv-pv-header (el footer del tema se conserva fuera de la home).'
		);
		// Catbar estática dentro del header (banda final del sticky).
		$this->assertMatchesRegularExpression(
			'/\.pv-home-header \.ltms-catbar\{position:static;z-index:auto;\}/',
			$css,
			'REG-CHOICE-008-G: la catbar dentro del header compartido es estática (sin sticky propio).'
		);
		// Compensación del sticky de checkout (asumía el header del tema).
		$this->assertMatchesRegularExpression(
			'/body\.pv-pv-header \.pv-checkout__review\[data-pv-sticky\]\{top:84px;\}/',
			$css,
			'REG-CHOICE-008-G: el resumen sticky de checkout se compensa con la altura del header PV.'
		);

		// Sincronización .min.css.
		$min = file_get_contents( $this->css_min_path );
		$this->assertStringContainsString( 'body.pv-pv-header', $min, 'REG-CHOICE-008-G: el .min.css debe contener el hide del tema (regenerado).' );
		$this->assertStringContainsString( 'pv-checkout__review', $min, 'REG-CHOICE-008-G: el .min.css debe contener la compensación de checkout (regenerado).' );
	}

	/**
	 * REG-CHOICE-008-H: el live search del buscador funciona en cualquier
	 * página con el header (guard extendido en initHome de plaza-viva.js).
	 * Sincronizado con el .min.js.
	 */
	public function test_008_live_search_global_en_header(): void {
		$js = file_get_contents( $this->pv_js_path );

		$this->assertMatchesRegularExpression(
			"/var header = document\.querySelector\('\.pv-home-header'\);\s*\n\s*if \(header\) initLiveSearch\(header\);/",
			$js,
			'REG-CHOICE-008-H: initHome debe inicializar el live search donde exista el header PV (no solo la home).'
		);

		$min = file_get_contents( $this->pv_js_min_path );
		$this->assertStringContainsString(
			'pv-home-header',
			$min,
			'REG-CHOICE-008-H: el .min.js debe contener el guard extendido (regenerado).'
		);
	}

	/**
	 * REG-CHOICE-008-I: el header-nav JS extiende su guard a cualquier página
	 * con el header PV (no solo la home nativa).
	 */
	public function test_009_header_nav_guard_extendido(): void {
		$src = file_get_contents( $this->header_nav_js_path );
		$this->assertMatchesRegularExpression(
			"/document\.querySelector\('\.pv-scope\.pv-home, \.pv-home-header'\)/",
			$src,
			'REG-CHOICE-008-I: el guard del header-nav cubre todas las páginas con header PV.'
		);
	}

	/**
	 * REG-CHOICE-008-J: bump de LTMS_VERSION >= 2.9.428 (cache-busting).
	 */
	public function test_010_version_bump(): void {
		$main = (string) file_get_contents( $this->main_path );
		$this->assertMatchesRegularExpression(
			"/define\(\s*'LTMS_VERSION',\s*'2\.9\.4(2[8-9]|[3-9]\d|\d{3,})'\s*\)/",
			$main,
			'REG-CHOICE-008-J: LTMS_VERSION debe ser >= 2.9.428 (cache-busting del CSS/JS del header).'
		);
	}
}
