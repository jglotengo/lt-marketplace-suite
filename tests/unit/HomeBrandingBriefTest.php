<?php
/**
 * HomeBrandingBriefTest — BRAND-2026-001 (2026-10-15).
 *
 * Verifica la aplicación del brief de branding de Lo Tengo
 * (BRIEF_BRANDING_LO_TENGO.md, extraído 15/10/2026) a la home nativa:
 *
 *   1. Tipografía oficial: Albert Sans (títulos) + Inter (cuerpo) — cargadas
 *      de verdad vía Google Fonts (la inconsistencia #5 del brief era que se
 *      declaraba Inter pero renderizaba el stack del sistema).
 *   2. Paleta de marca: rojo #E80001 (header, unifica los rojos —
 *      inconsistencia #2), dorado primario #F7B731 (token --ltms-gold, CTA
 *      "Vender"/badges) y negro #000000 (footer).
 *   3. Logo oficial "Lo Tengo" (LOGO_LO_TENGO.png → 4 assets PNG) en:
 *      header (variante blanca sobre rojo), footer (variante blanca sobre
 *      negro), favicon (icono cuadrado 512), og:image (canvas 1200x630 —
 *      inconsistencia #8: el logo auto-detectado era la bandera y el og
 *      declaraba 1200x630 sirviendo 769x324 crudo).
 *   4. theme-color → #E80001 (inconsistencia #3: era azul #1e40af con una
 *      marca roja/negra).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el source
 * PHP/CSS): deterministas en LTMS_UNIT_ONLY=true (mismo patrón que
 * HomeHeaderRedesignTest, HomeUxV2Test).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeBrandingBriefTest
 */
final class HomeBrandingBriefTest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $pv_css_path;
	private string $pv_min_css_path;
	private string $native_templates_path;
	private string $branding_engine_path;
	private string $img_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path             = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->pv_css_path          = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->pv_min_css_path      = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css';
		$this->native_templates_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->branding_engine_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-branding-engine.php';
		$this->img_dir              = dirname( __DIR__, 2 ) . '/assets/img';
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
	 * BRAND-2026-001-A: los 4 assets del logo oficial existen con las
	 * dimensiones correctas (PNG transparente 769x324 a color, variante
	 * blanca 769x324, og:image 1200x630 con fondo blanco, icono cuadrado
	 * 512x512 para favicon/apple-touch).
	 */
	public function test_001_assets_del_logo_oficial(): void {
		$expected = array(
			'logo-lo-tengo.png'        => array( 769, 324 ),
			'logo-lo-tengo-white.png' => array( 769, 324 ),
			'logo-lo-tengo-og.png'     => array( 1200, 630 ),
			'logo-lo-tengo-icon.png'   => array( 512, 512 ),
		);
		foreach ( $expected as $file => $dims ) {
			$path = $this->img_dir . '/' . $file;
			$this->assertFileExists( $path, "BRAND-2026-001-A: el asset del logo {$file} debe existir." );
			$info = getimagesize( $path );
			$this->assertIsArray( $info, "BRAND-2026-001-A: {$file} debe ser una imagen válida." );
			$this->assertSame( $dims, array( $info[0], $info[1] ), "BRAND-2026-001-A: {$file} debe medir {$dims[0]}x{$dims[1]} px." );
		}
	}

	/**
	 * BRAND-2026-001-B: tipografía oficial del brief — Albert Sans (títulos,
	 * 700-800) + Inter (cuerpo) cargadas vía Google Fonts (inconsistencia #5:
	 * Inter se declaraba pero no se cargaba) y declaradas como tokens
	 * --display/--body del design system.
	 */
	public function test_002_tipografia_albert_sans_e_inter_cargadas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->native_templates_path ) );

		$this->assertMatchesRegularExpression(
			"/wp_enqueue_style\(\s*'ltms-pv-fonts',\s*'https:\/\/fonts\.googleapis\.com\/css2\?family=Albert\+Sans[^']*family=Inter/s",
			$src,
			'BRAND-2026-001-B: Google Fonts debe cargar Albert Sans + Inter de verdad (handle ltms-pv-fonts).'
		);

		$css = file_get_contents( $this->pv_css_path );
		$this->assertMatchesRegularExpression(
			"/--display:'Albert Sans',system-ui,sans-serif;/",
			$css,
			'BRAND-2026-001-B: el token --display debe ser Albert Sans (títulos del brief).'
		);
		$this->assertMatchesRegularExpression(
			"/--body:'Inter',system-ui,sans-serif;/",
			$css,
			'BRAND-2026-001-B: el token --body debe ser Inter (cuerpo del brief).'
		);
	}

	/**
	 * BRAND-2026-001-C: token del dorado primario del brief (--ltms-gold
	 * #F7B731) en el design system y sincronizado en el .min.css. El dorado
	 * premium secundario --gold #D4A857 se conserva (ambos viven en el brief).
	 */
	public function test_003_token_ltms_gold(): void {
		$css = file_get_contents( $this->pv_css_path );
		$this->assertStringContainsString(
			'--ltms-gold:#F7B731;',
			$css,
			'BRAND-2026-001-C: plaza-viva.css debe declarar el dorado primario del brief --ltms-gold:#F7B731.'
		);
		$this->assertStringContainsString(
			'--gold:#D4A857;',
			$css,
			'BRAND-2026-001-C: el dorado premium secundario --gold #D4A857 se conserva (brief: detalles premium).'
		);

		$min = file_get_contents( $this->pv_min_css_path );
		$this->assertStringContainsString(
			'--ltms-gold:#F7B731',
			$min,
			'BRAND-2026-001-C: el .min.css debe estar regenerado con --ltms-gold (desincronizado = no llega a producción).'
		);
	}

	/**
	 * BRAND-2026-001-D → HOME-UX-014: header de la home NEGRO #000000
	 * (operador 17/10/2026: "cambia el fondo de ese header a negro" — mismo
	 * chrome que la franja de Elementor del resto del sitio; la catbar roja
	 * queda como banda bajo el header). Botón Buscar var(--brand) con hover
	 * --brand-600 (viaja DENTRO del campo blanco), badge de contador dorado
	 * primario --ltms-gold con borde NEGRO (recorte contra el header).
	 */
	public function test_004_header_rojo_de_marca(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header\{[^}]*background:#000000;[^}]*border-bottom:1px solid rgba\(255,255,255,\.10\);/s",
			$src,
			'HOME-UX-014: el header debe ser NEGRO #000000 con hairline rgba(255,255,255,.10) (operador: "cambia el fondo de ese header a negro").'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-btn\{[^}]*background:var\(--brand\);/s",
			$src,
			'BRAND-2026-001-D: el botón Buscar debe ser var(--brand) (rojo de marca).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-btn:hover\{background:var\(--brand-600\);\}/s",
			$src,
			'BRAND-2026-001-D: hover del botón Buscar = --brand-600 (hover oficial del brief).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__badge\{[^}]*background:var\(--ltms-gold\);[^}]*border:2px solid #000000;/s",
			$src,
			'HOME-UX-014: el badge del contador debe ser --ltms-gold con borde NEGRO (recorte contra el header negro).'
		);
	}

	/**
	 * BRAND-2026-001-E: logo OFICIAL en header y footer — variante blanca
	 * (header rojo / footer negro; el logo a color lleva "TENGO" en rojo e
	 * sería invisible), <img> con width/height explícitos (sin CLS).
	 */
	public function test_005_logo_oficial_en_header_y_footer(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertMatchesRegularExpression(
			'/class="pv-home-header__logo-img"\s*src="[^"]*logo-lo-tengo-white\.png/s',
			$src,
			'BRAND-2026-001-E: el header debe usar el logo oficial en variante blanca.'
		);
		$this->assertMatchesRegularExpression(
			'/class="pv-home-footer__logo-img"\s*src="[^"]*logo-lo-tengo-white\.png/s',
			$src,
			'BRAND-2026-001-E: el footer debe usar el logo oficial en variante blanca.'
		);
		// Dimensiones explícitas en ambos (evita layout shift).
		$this->assertStringContainsString( 'width="95" height="40"', $src, 'BRAND-2026-001-E: el logo del header lleva width/height (CLS 0).' );
		$this->assertStringContainsString( 'width="85" height="36"', $src, 'BRAND-2026-001-E: el logo del footer lleva width/height (CLS 0).' );
		// El placeholder SVG map-pin fue retirado del header y del footer.
		$this->assertStringNotContainsString( 'pv-home-header__logo-mark', $src, 'BRAND-2026-001-E: el placeholder logo-mark del header fue reemplazado por el logo oficial.' );
		$this->assertStringNotContainsString( 'pv-home-footer__logo-mark', $src, 'BRAND-2026-001-E: el placeholder logo-mark del footer fue reemplazado por el logo oficial.' );
	}

	/**
	 * BRAND-2026-001-F: franja "Vende con nosotros" con la paleta de marca —
	 * gradiente rojo --brand → --brand-600, línea dorada --ltms-gold, CTA
	 * dorado #F7B731 con texto NEGRO (patrón "VENDER GRATIS" del brief) y
	 * texto blanco #fff (4.74:1 AA sobre #E80001).
	 */
	public function test_006_franja_vende_rojo_y_dorado(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-sell\{[^}]*radial-gradient\(120% 100% at 0% 0%,var\(--brand\) 0%,var\(--brand-600\) 100%\);[^}]*border-top:3px solid var\(--ltms-gold\);/s",
			$src,
			'BRAND-2026-001-F: el slab Vende debe ser el gradiente rojo de marca con línea dorada --ltms-gold.'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-sell__cta\{[^}]*background:var\(--ltms-gold\);color:#000;/s",
			$src,
			'BRAND-2026-001-F: el CTA "Registrarme como vendedor" debe ser dorado --ltms-gold con texto negro (patrón VENDER GRATIS del brief).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-sell__sub\{[^}]*color:#fff;/s",
			$src,
			'BRAND-2026-001-F: el sub del slab debe ser blanco pleno (rgba .8 daba <4.5:1 sobre el rojo de marca #E80001).'
		);
	}

	/**
	 * BRAND-2026-001-G: footer NEGRO de marca (#000000, brief: "footer
	 * negro") — texto blanco, hover dorado --ltms-gold, imágenes de
	 * pagos/afiliaciones sobre chips blancos y © en blanco atenuado.
	 */
	public function test_007_footer_negro_de_marca(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-footer\{[^}]*background:#000000;/s",
			$src,
			'BRAND-2026-001-G: el footer de la home debe ser NEGRO #000000 (color de marca del brief).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-footer__social-link:hover\{[^}]*background:var\(--ltms-gold\);color:#000;/s",
			$src,
			'BRAND-2026-001-G: el hover de las redes debe ser dorado --ltms-gold con icono negro (acento de marca).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-footer__brand-item img\{[^}]*background:#fff;[^}]*border-radius:8px;/s",
			$src,
			'BRAND-2026-001-G: las imágenes de pagos/afiliaciones viajan sobre chips blancos redondeados (assets de fondo claro sobre footer negro).'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-footer__bottom-inner\{[^}]*color:rgba\(255,255,255,\.66\);/s",
			$src,
			'BRAND-2026-001-G: la línea de © debe ser blanca atenuada legible sobre negro.'
		);
	}

	/**
	 * BRAND-2026-001-H: branding engine con la marca del brief —
	 * theme-color #E80001 (inconsistencia #3: era azul #1e40af), favicon PNG
	 * cuadrado oficial, get_logo_url() mapea las variantes nuevas y el og:image
	 * usa el canvas 1200x630 (antes declaraba 1200x630 sirviendo 769x324).
	 */
	public function test_008_branding_engine_marca_del_brief(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->branding_engine_path ) );

		// Paleta con el rojo de marca del brief.
		$this->assertStringContainsString(
			"'brand'          => '#E80001'",
			$src,
			'BRAND-2026-001-H: COLOR_PALETTE debe incluir el rojo de marca #E80001 del brief.'
		);
		// theme-color emite el rojo de marca (no el azul #1e40af del viejo primary).
		$this->assertMatchesRegularExpression(
			'/name="theme-color" content="\' \. esc_attr\( self::COLOR_PALETTE\[\'brand\'\] \)/',
			$src,
			'BRAND-2026-001-H: theme-color debe emitir el rojo de marca #E80001.'
		);
		$this->assertMatchesRegularExpression(
			'/name="msapplication-TileColor" content="\' \. esc_attr\( self::COLOR_PALETTE\[\'brand\'\] \)/',
			$src,
			'BRAND-2026-001-H: MS TileColor debe emitir el rojo de marca #E80001.'
		);
		// Favicon → icono cuadrado PNG oficial (antes JPG 951x960 del viejo logo).
		$this->assertMatchesRegularExpression(
			"/img\/logo-lo-tengo-icon\.png/",
			$src,
			'BRAND-2026-001-H: el favicon debe apuntar al icono cuadrado oficial logo-lo-tengo-icon.png.'
		);
		$this->assertStringContainsString(
			'rel="icon" type="image/png"',
			$src,
			'BRAND-2026-001-H: los favicons deben declararse como image/png (antes image/jpeg).'
		);
		// Mapeo de variantes del logo oficial.
		$this->assertStringContainsString(
			"'white' => 'img/logo-lo-tengo.png',",
			$src,
			'BRAND-2026-001-H: get_logo_url(white) debe servir el logo oficial a color (superficies claras / schema).'
		);
		$this->assertStringContainsString(
			"'dark'  => 'img/logo-lo-tengo-white.png',",
			$src,
			'BRAND-2026-001-H: get_logo_url(dark) debe servir la variante blanca (superficies oscuras).'
		);
		$this->assertStringContainsString(
			"'og'    => 'img/logo-lo-tengo-og.png',",
			$src,
			'BRAND-2026-001-H: get_logo_url(og) debe servir el canvas 1200x630 para og:image.'
		);
		$this->assertStringContainsString(
			"self::get_logo_url( 'og' )",
			$src,
			'BRAND-2026-001-H: ensure_logo_in_og_image debe usar la variante og (1200x630 real).'
		);
		// Los JPG del logo viejo ya no son la fuente canónica de get_logo_url.
		$this->assertStringNotContainsString(
			'logo-white-bg.jpg',
			$src,
			'BRAND-2026-001-H: el JPG viejo logo-white-bg.jpg fue reemplazado por el PNG oficial.'
		);
		$this->assertStringNotContainsString(
			'logo-dark-bg.jpg',
			$src,
			'BRAND-2026-001-H: el JPG viejo logo-dark-bg.jpg fue reemplazado por la variante blanca oficial.'
		);
	}

	/**
	 * BRAND-2026-001-I: cache-busting — LTMS_VERSION bumpeada por el cambio
	 * de CSS inline de la home (los estilos viven en el template; sin bump el
	 * cache de SG Optimizer sirve la versión vieja).
	 */
	public function test_009_version_bumpeada(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php' );
		$this->assertMatchesRegularExpression(
			"/define\( 'LTMS_VERSION', '2\.9\.4(1[3-9]|[2-9][0-9])' \);/",
			$plugin,
			'BRAND-2026-001-I: LTMS_VERSION debe ser >= 2.9.413 (cache-busting del CSS inline de la home).'
		);
	}
}
