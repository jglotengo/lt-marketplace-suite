<?php
/**
 * HomeFooterTest — tests del footer de la home nativa.
 *
 * Foco: C6 del rediseño de la home (HOME-REDESIGN-006) → REWORK HOME-UX2-005
 * (2026-10-02): el footer pasó a ser PURAMENTE VISUAL (logo, redes reales,
 * badges de pago, ©) y TODO el texto de enlaces se organizó en la nueva
 * sección "Ayuda y políticas" del body (Vende con nosotros · Ayuda · Legal ·
 * Contacto). El footer del tema (Elementor) se oculta en la home para
 * eliminar la redundancia de dos footers apilados (el operador reportó
 * políticas/redes/pagos duplicados).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeTemplateWiringTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeFooterTest
 */
final class HomeFooterTest extends LTMS_Unit_Test_Case {

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
	 * HOME-UX2-005-A: el footer es puramente visual — logo + redes + pagos + ©.
	 * Las columnas de texto (acordeones <details>, col brand con tagline) fueron
	 * ELIMINADAS y su contenido vive en la sección "Ayuda y políticas".
	 */
	public function test_001_footer_visual_sin_columnas_de_texto(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Presente: marca, redes, pagos, ©.
		$this->assertStringContainsString(
			'pv-home-footer__logo',
			$src,
			'HOME-UX2-005-A: el footer visual debe tener el logo.'
		);
		$this->assertStringContainsString(
			'pv-home-footer__social',
			$src,
			'HOME-UX2-005-A: el footer visual debe tener las redes.'
		);
		$this->assertStringContainsString(
			'pv-home-footer__payrow',
			$src,
			'HOME-UX2-005-A: el footer visual debe tener la fila de pagos.'
		);
		$this->assertStringContainsString(
			'pv-home-footer__bottom',
			$src,
			'HOME-UX2-005-A: el footer visual debe tener la línea de ©.'
		);

		// Eliminado: columnas de texto del footer viejo.
		$this->assertStringNotContainsString(
			'pv-home-footer__acc',
			$src,
			'HOME-UX2-005-A: los acordeones de columnas fueron eliminados (el texto vive en la sección políticas).'
		);
		$this->assertStringNotContainsString(
			'pv-home-footer__col',
			$src,
			'HOME-UX2-005-A: las columnas de enlaces del footer fueron eliminadas.'
		);
		$this->assertStringNotContainsString(
			'pv-home-footer__tagline',
			$src,
			'HOME-UX2-005-A: el tagline fue eliminado del footer (redundante con el hero).'
		);
		$this->assertStringNotContainsString(
			'pv-home-footer__built',
			$src,
			'HOME-UX2-005-A: la línea "powered by" fue eliminada del footer visual.'
		);
	}

	/**
	 * HOME-UX2-005-B: redes sociales REALES del operador (extraídas del footer
	 * Elementor) — antes eran placeholders genéricos a instagram.com etc.
	 */
	public function test_002_redes_sociales_reales(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'https://www.instagram.com/lotengooficial/',
			$src,
			'HOME-UX2-005-B: Instagram debe apuntar al perfil real del operador.'
		);
		$this->assertStringContainsString(
			'https://www.tiktok.com/@lotengocolombia',
			$src,
			'HOME-UX2-005-B: TikTok debe apuntar al perfil real del operador.'
		);
		$this->assertStringContainsString(
			'https://www.youtube.com/@lotengocolombia',
			$src,
			'HOME-UX2-005-B: YouTube debe apuntar al canal real del operador.'
		);
		$this->assertStringNotContainsString(
			"'url' => 'https://instagram.com'",
			$src,
			'HOME-UX2-005-B: el placeholder genérico de Instagram debe estar eliminado.'
		);
		$this->assertStringNotContainsString(
			"'url' => 'https://facebook.com'",
			$src,
			'HOME-UX2-005-B: el placeholder de Facebook (el operador no tiene página) debe estar eliminado.'
		);
	}

	/**
	 * HOME-UX2-005-C: sección "Ayuda y políticas" — organiza el texto
	 * eliminado del footer (Vende · Ayuda · Legal · Contacto).
	 */
	public function test_003_seccion_ayuda_y_politicas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'pv-home__policies',
			$src,
			'HOME-UX2-005-C: debe existir la sección Ayuda y políticas.'
		);
		foreach ( array( 'Vende con nosotros', 'Ayuda', 'Legal', 'Contacto' ) as $title ) {
			$this->assertStringContainsString(
				sprintf( "esc_html_e( '%s', 'ltms' )", $title ),
				$src,
				sprintf( "HOME-UX2-005-C: el grupo '%s' debe existir en la sección de políticas.", $title )
			);
		}
		// Contacto real del operador (antes vivía solo en el footer Elementor, hoy oculto en la home).
		$this->assertStringContainsString(
			'dircomercialcol@lo-tengo.com.co',
			$src,
			'HOME-UX2-005-C: el email comercial del operador debe existir en Contacto.'
		);
		$this->assertStringContainsString(
			'sellerscolombia@lo-tengo.com.co',
			$src,
			'HOME-UX2-005-C: el email de sellers del operador debe existir en Contacto.'
		);
		// El filtro de enlaces legales se conserva (backward compat).
		$this->assertStringContainsString(
			'ltms_home_footer_legal_links',
			$src,
			'HOME-UX2-005-C: el filtro ltms_home_footer_legal_links se conserva para los enlaces legales.'
		);
	}

	/**
	 * HOME-UX2-005-D: el footer del tema (Elementor) se oculta SOLO en la home
	 * — mismo patrón defensivo del header. NUNCA un bare `footer`.
	 */
	public function test_004_footer_del_tema_oculto(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertStringContainsString(
			'body.pv-home-native .elementor-location-footer',
			$src,
			'HOME-UX2-005-D: el footer Elementor debe ocultarse en la home.'
		);
		$this->assertStringContainsString(
			'body.pv-home-native #colophon',
			$src,
			'HOME-UX2-005-D: el footer del tema clásico (#colophon) debe ocultarse en la home.'
		);
		$this->assertStringContainsString(
			'body.pv-home-native .site-footer',
			$src,
			'HOME-UX2-005-D: el .site-footer del tema debe ocultarse en la home.'
		);
		// El selector NUNCA debe ser un bare footer (matchearía .pv-home-footer).
		$this->assertStringNotContainsString(
			'body.pv-home-native footer{',
			$src,
			'HOME-UX2-005-D: prohibido el selector bare `footer` — ocultaría el footer PV propio.'
		);
	}

	/**
	 * HOME-REDESIGN-006-C (se mantiene): enlaces solo a páginas reales.
	 */
	public function test_005_enlaces_paginas_reales(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Centro de ayuda (/ayuda — page slug verificado en is_help_page()).
		$this->assertStringContainsString(
			"home_url( '/ayuda' )",
			$src,
			'HOME-REDESIGN-006-C: el enlace Centro de ayuda debe apuntar a /ayuda (página real).'
		);
		// Rastrear pedido (/seguimiento — page slug verificado en is_order_tracking_page()).
		$this->assertStringContainsString(
			"home_url( '/seguimiento' )",
			$src,
			'HOME-REDESIGN-006-C: el enlace Rastrear pedido debe apuntar a /seguimiento (página real).'
		);
		// Registro de vendedor (filter) + vendedores (filter).
		$this->assertStringContainsString( 'ltms_become_seller_url', $src );
		$this->assertStringContainsString( 'ltms_sellers_page_url', $src );
	}

	/**
	 * HOME-REDESIGN-006-D (se mantiene): fila de pagos + selector de moneda
	 * solo si ya existe soporte multi-moneda.
	 */
	public function test_006_pagos_y_selector_moneda(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'pv-home-footer__payrow',
			$src,
			'HOME-REDESIGN-006-D: debe existir la fila de pagos bajo la marca.'
		);
		// Selector de moneda: el widget canónico del checkout, con guard.
		$this->assertMatchesRegularExpression(
			"/class_exists\(\s*'LTMS_Currency_Manager'\s*\)\s*&&\s*method_exists\(\s*'LTMS_Currency_Manager',\s*'render_currency_selector'\s*\)/",
			$src,
			'HOME-REDESIGN-006-D: el selector de moneda debe renderizarse solo si existe LTMS_Currency_Manager (bail defensivo).'
		);
		// Sin selector de país inventado (no existe soporte — brief).
		$this->assertDoesNotMatchRegularExpression(
			'/name="ltms_country_switch"/',
			$src,
			'HOME-REDESIGN-006-D: no debe inventarse un selector de país (sin soporte en el plugin).'
		);
	}
}
