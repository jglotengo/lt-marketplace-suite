<?php
/**
 * PvFooterGlobalTest — FOOTER-GLOBAL-2026-10-24 (operador: "lleva el footer
 * del home a todas las páginas públicas del proyecto, incluidas mi cuenta,
 * mis pedidos y las de registro vendedores y recuperación de contraseña —
 * es decir, a todas").
 *
 * El footer Plaza Viva de la home (logo · redes reales · marcas de
 * pagos/afiliaciones con filtro dark-mode · selector de moneda · ©) se
 * extrajo a UN componente compartido:
 *  - Markup:  includes/frontend/class-ltms-pv-footer.php (LTMS_PV_Footer::render)
 *  - CSS:     assets/css/ltms-pv-footer.css (+ .min.css)
 *  - Inyección: hook wp_footer (accepted_args=0 — lección #190) en TODAS
 *    las páginas públicas.
 *  - Ocultación del footer viejo del tema (Elementor/clásico) y del footer
 *    minimal de la vitrina: body class pv-pv-footer.
 *  - Exclusiones (decisión del operador 2026-10-24: "todas sin excepción"):
 *    SOLO contextos sin render HTML (admin/AJAX/REST). A diferencia del
 *    header global (REG-CHOICE-008), el panel del vendedor, el funnel auth
 *    y la vitrina /vendedor/{slug} SÍ lo llevan.
 *  - home.php ya NO imprime su propio footer (una sola fuente de verdad).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 * @group pv-footer-global
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PvFooterGlobalTest
 *
 * @group pv-footer-global
 */
final class PvFooterGlobalTest extends LTMS_Unit_Test_Case {

	private string $class_path;
	private string $css_path;
	private string $css_min_path;
	private string $home_path;
	private string $kernel_path;
	private string $main_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->class_path  = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-pv-footer.php';
		$this->css_path    = dirname( __DIR__, 2 ) . '/assets/css/ltms-pv-footer.css';
		$this->css_min_path = dirname( __DIR__, 2 ) . '/assets/css/ltms-pv-footer.min.css';
		$this->home_path   = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->kernel_path = dirname( __DIR__, 2 ) . '/includes/core/class-ltms-kernel.php';
		$this->main_path   = dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php';
		$this->webhook_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * FOOTER-GLOBAL-A: el componente existe, bootea en el kernel, está en el
	 * autoloader y en la whitelist del deploy webhook (lección #177).
	 */
	public function test_001_componente_registrado(): void {
		$this->assertFileExists( $this->class_path );
		$src = $this->strip_php_comments( (string) file_get_contents( $this->class_path ) );
		$this->assertStringContainsString( 'class LTMS_PV_Footer {', $src, 'FOOTER-GLOBAL-A: debe existir la clase LTMS_PV_Footer.' );

		// Kernel boot (patrón de los módulos frontend, después del header PV).
		$kernel = (string) file_get_contents( $this->kernel_path );
		$this->assertMatchesRegularExpression(
			"/if \( class_exists\( 'LTMS_PV_Footer' \) \) \{\s*\n\s*LTMS_PV_Footer::init\(\);/",
			$kernel,
			'FOOTER-GLOBAL-A: el kernel debe bootear LTMS_PV_Footer::init().'
		);

		// Autoloader.
		$main = (string) file_get_contents( $this->main_path );
		$this->assertStringContainsString(
			"'ltms-pv-footer'                         => 'frontend/class-ltms-pv-footer.php',",
			$main,
			'FOOTER-GLOBAL-A: la clase debe estar en el mapa del autoloader.'
		);

		// Whitelist del deploy webhook (lección #177: inventario vivo).
		$webhook = (string) file_get_contents( $this->webhook_path );
		$this->assertStringContainsString( "'includes/frontend/class-ltms-pv-footer.php',", $webhook, 'FOOTER-GLOBAL-A: la clase debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'assets/css/ltms-pv-footer.css',", $webhook, 'FOOTER-GLOBAL-A: el CSS debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'assets/css/ltms-pv-footer.min.css',", $webhook, 'FOOTER-GLOBAL-A: el .min.css debe estar en la whitelist del webhook.' );
	}

	/**
	 * FOOTER-GLOBAL-B: hooks del componente — wp_footer con accepted_args=0
	 * (lección #190: do_action antepone un dummy), body_class y enqueue global.
	 */
	public function test_002_hooks_wp_footer_body_class_enqueue(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->class_path ) );

		$this->assertMatchesRegularExpression(
			"/add_action\( 'wp_footer', \[ __CLASS__, 'maybe_render' \], 10, 0 \);/",
			$src,
			'FOOTER-GLOBAL-B: el hook wp_footer debe declarar accepted_args=0 EXPLÍCITO (lección #190).'
		);
		$this->assertMatchesRegularExpression(
			"/add_filter\( 'body_class', \[ __CLASS__, 'add_body_class' \] \);/",
			$src,
			'FOOTER-GLOBAL-B: debe registrar el filtro body_class (clase pv-pv-footer).'
		);
		$this->assertMatchesRegularExpression(
			"/add_action\( 'wp_enqueue_scripts', \[ __CLASS__, 'enqueue_assets' \] \);/",
			$src,
			'FOOTER-GLOBAL-B: debe encolar su CSS en front-end.'
		);
		$this->assertMatchesRegularExpression(
			"/wp_enqueue_style\( 'ltms-pv-footer', \\\$url \. 'css\/ltms-pv-footer\.css', \[ 'ltms-plaza-viva', 'ltms-pv-header' \]/",
			$src,
			'FOOTER-GLOBAL-B: el handle del CSS es ltms-pv-footer con dependencias del design system y del header PV.'
		);
	}

	/**
	 * FOOTER-GLOBAL-C: exclusiones de should_render — decisión del operador
	 * 2026-10-24 "todas sin excepción": SOLO admin/AJAX/REST. El panel del
	 * vendedor, el funnel auth y la vitrina SÍ llevan el footer (a diferencia
	 * del header PV, REG-CHOICE-008).
	 */
	public function test_003_exclusiones_solo_contextos_sin_html(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->class_path ) );

		$this->assertMatchesRegularExpression(
			"/if \( is_admin\(\) \|\| wp_doing_ajax\(\) \) \{\s*\n\s*return false;/",
			$src,
			'FOOTER-GLOBAL-C: should_render debe excluir admin y AJAX.'
		);
		$this->assertMatchesRegularExpression(
			"/defined\( 'REST_REQUEST' \) && REST_REQUEST/",
			$src,
			'FOOTER-GLOBAL-C: should_render debe excluir peticiones REST.'
		);
		// "Todas sin excepción": NINGUNA exclusión de panel/auth/vitrina.
		$this->assertStringNotContainsString(
			'is_vendor_panel_page',
			$src,
			'FOOTER-GLOBAL-C: el footer PV NO excluye el panel del vendedor (decisión del operador: todas).'
		);
		$this->assertStringNotContainsString(
			'is_vendor_auth_page',
			$src,
			'FOOTER-GLOBAL-C: el footer PV NO excluye el funnel auth — registro vendedores y recuperación de contraseña lo llevan (petición explícita del operador).'
		);
		// did_render: flag anti-duplicado.
		$this->assertStringContainsString( 'private static $did_render', $src, 'FOOTER-GLOBAL-C: debe existir el flag did_render.' );
		$this->assertMatchesRegularExpression(
			"/self::\\\$did_render = true;/",
			$src,
			'FOOTER-GLOBAL-C: render() debe marcar did_render=true al terminar.'
		);
	}

	/**
	 * FOOTER-GLOBAL-D: markup canónico del footer compartido — marca, redes
	 * reales, marcas de pagos full-size (FOOTER-BRANDS-2026-10-23), selector
	 * de moneda con guard y línea de ©.
	 */
	public function test_004_markup_footer_compartido(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->class_path ) );

		$this->assertStringContainsString( '<footer class="pv-home-footer" role="contentinfo">', $src, 'FOOTER-GLOBAL-D: el footer mantiene la clase canónica pv-home-footer (precedente del header: pv-home-header).' );
		$this->assertStringContainsString( 'pv-home-footer__logo', $src, 'FOOTER-GLOBAL-D: marca — logo oficial variante blanca.' );
		$this->assertStringContainsString( 'logo-lo-tengo-white.png', $src, 'FOOTER-GLOBAL-D: el logo usa la variante blanca (footer negro).' );
		$this->assertStringContainsString( 'width="85" height="36"', $src, 'FOOTER-GLOBAL-D: el logo lleva width/height explícitos (CLS 0).' );
		$this->assertStringContainsString( "'ltms_home_footer_socials'", $src, 'FOOTER-GLOBAL-D: el filtro de redes conserva su nombre original (backward compat).' );
		$this->assertStringContainsString( 'https://www.instagram.com/lotengooficial/', $src, 'FOOTER-GLOBAL-D: redes reales del operador (Instagram).' );
		$this->assertStringContainsString( "'ltms_home_footer_brands'", $src, 'FOOTER-GLOBAL-D: el filtro de marcas conserva su nombre original (backward compat).' );
		$this->assertStringContainsString( 'logos-footer-4.png', $src, 'FOOTER-GLOBAL-D: marcas de pagos full-size (OpenPay/PSE/Nequi/Visa...).' );
		$this->assertStringContainsString( 'logo-lacardio.png', $src, 'FOOTER-GLOBAL-D: LaCardio full-size.' );
		$this->assertStringContainsString( "'width' => 640, 'height' => 360", $src, 'FOOTER-GLOBAL-D: width/height explícitos en las marcas (CLS 0, retina 2x).' );
		$this->assertStringContainsString( 'LTMS_Currency_Manager', $src, 'FOOTER-GLOBAL-D: selector de moneda con bail defensivo.' );
		$this->assertStringContainsString( 'pv-home-footer__bottom', $src, 'FOOTER-GLOBAL-D: línea de ©.' );
	}

	/**
	 * FOOTER-GLOBAL-E: home.php ya NO imprime su propio footer (una sola
	 * fuente de verdad) ni declara el helper de iconos sociales; conserva
	 * get_footer() — cuyo wp_footer dispara la inyección del componente.
	 */
	public function test_005_home_php_sin_footer_duplicado(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->home_path ) );

		$this->assertStringNotContainsString(
			'<footer class="pv-home-footer"',
			$src,
			'FOOTER-GLOBAL-E: home.php NO debe imprimir el footer (lo inyecta LTMS_PV_Footer vía wp_footer).'
		);
		$this->assertStringNotContainsString(
			'function ltms_pv_social_icon',
			$src,
			'FOOTER-GLOBAL-E: el helper de iconos sociales se movió al componente (LTMS_PV_Footer::social_icon).'
		);
		$this->assertStringNotContainsString(
			'pv-home-footer__brand-item',
			$src,
			'FOOTER-GLOBAL-E: ni el markup ni el CSS de las marcas viven ya en home.php.'
		);
		$this->assertStringContainsString( 'get_footer();', $src, 'FOOTER-GLOBAL-E: home.php conserva get_footer() — su wp_footer dispara la inyección del footer PV.' );
	}

	/**
	 * FOOTER-GLOBAL-F: CSS del componente — footer negro de marca, filtro
	 * dark-mode de las marcas (FOOTER-BRANDS-2026-10-23), sizing responsive,
	 * hide del footer del tema bajo body.pv-pv-footer + hide del footer
	 * minimal de la vitrina. NUNCA un bare `footer`. Sincronizado con el
	 * .min.css (SG Optimizer sirve el min).
	 */
	public function test_006_css_componente_y_hide_tema(): void {
		$css = (string) file_get_contents( $this->css_path );

		// Footer negro de marca (BRAND-2026-001).
		$this->assertMatchesRegularExpression(
			'/\.pv-home-footer\{[^}]*background:#000000;/s',
			$css,
			'FOOTER-GLOBAL-F: el footer compartido tiene el fondo negro de marca.'
		);
		// Filtro dark-mode + sizing responsive de las marcas.
		$this->assertMatchesRegularExpression(
			'/\.pv-home-footer \.pv-home-footer__brand-item img\{[^}]*filter:invert\(1\) hue-rotate\(180deg\);/s',
			$css,
			'FOOTER-GLOBAL-F: las marcas llevan el filtro dark-mode invert+hue-rotate (FOOTER-BRANDS-2026-10-23).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-home-footer \.pv-home-footer__brand-item\{[^}]*width:clamp\(150px,30%,320px\);/s',
			$css,
			'FOOTER-GLOBAL-F: tamaño ancho-driven responsive (clamp 150px→320px).'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:560px\)\{[^@]*\.pv-home-footer \.pv-home-footer__brand-item\{width:calc\(50% - 14px\);\}/s',
			$css,
			'FOOTER-GLOBAL-F: grid 2×2 en móvil (media fila por logo).'
		);
		// Hide del footer del tema (Elementor + clásico) — global.
		$this->assertMatchesRegularExpression( '/body\.pv-pv-footer \.elementor-location-footer,/', $css, 'FOOTER-GLOBAL-F: oculta el footer de Elementor Theme Builder.' );
		$this->assertMatchesRegularExpression( '/body\.pv-pv-footer #colophon,/', $css, 'FOOTER-GLOBAL-F: oculta el footer del tema clásico (#colophon).' );
		$this->assertMatchesRegularExpression( '/body\.pv-pv-footer \.site-footer,/', $css, 'FOOTER-GLOBAL-F: oculta el .site-footer del tema.' );
		// Vitrina: su footer minimal se reemplaza por el footer PV.
		$this->assertMatchesRegularExpression( '/body\.pv-pv-footer \.ltms-sf-footer\{display:none!important\}/', $css, 'FOOTER-GLOBAL-F: el footer minimal de la vitrina se oculta a favor del footer PV (todas sin excepción).' );
		// Prohibido el selector bare footer (matchearía .pv-home-footer propio).
		$this->assertDoesNotMatchRegularExpression(
			'/body\.pv-pv-footer footer\{/',
			$css,
			'FOOTER-GLOBAL-F: NUNCA un selector bare `footer` bajo pv-pv-footer.'
		);

		// Sincronización .min.css (regenerado).
		$min = (string) file_get_contents( $this->css_min_path );
		$this->assertStringContainsString( 'body.pv-pv-footer', $min, 'FOOTER-GLOBAL-F: el .min.css debe contener el hide del tema (regenerado).' );
		$this->assertStringContainsString( 'filter:invert(1) hue-rotate(180deg)', $min, 'FOOTER-GLOBAL-F: el .min.css debe contener el filtro dark-mode (regenerado).' );
		$this->assertStringContainsString( 'ltms-sf-footer', $min, 'FOOTER-GLOBAL-F: el .min.css debe contener el hide de la vitrina (regenerado).' );
	}

	/**
	 * FOOTER-GLOBAL-G: bump de LTMS_VERSION >= 2.9.429 (cache-busting del
	 * CSS nuevo del footer).
	 */
	public function test_007_version_bump(): void {
		$main = (string) file_get_contents( $this->main_path );
		$this->assertMatchesRegularExpression(
			"/define\(\s*'LTMS_VERSION',\s*'2\.9\.4(29|[3-9]\d|\d{3,})'\s*\)/",
			$main,
			'FOOTER-GLOBAL-G: LTMS_VERSION debe ser >= 2.9.429 (cache-busting del CSS del footer compartido).'
		);
	}
}
