<?php
/**
 * HomeFooterTest — tests del footer de la home nativa (HOME-REDESIGN-006,
 * patrón Amazon).
 *
 * Foco: C6 del rediseño de la home:
 *   - 4 columnas: Conócenos (marca + tagline + redes), Vende con nosotros,
 *     Ayuda y Legal.
 *   - Móvil = acordeones colapsables por columna (<details> nativo, cero JS
 *     — CSP-compliant); tablet = 2 columnas; escritorio = 4 columnas con
 *     headers estáticos (pointer-events:none).
 *   - Enlaces solo a páginas reales (/ayuda, /seguimiento, registro de
 *     vendedor, vendedores) — sin enlaces rotos (P1).
 *   - Fila de pagos + selector de moneda SOLO si ya existe soporte
 *     multi-moneda (LTMS_Currency_Manager::render_currency_selector() con
 *     bail defensivo).
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
	 * HOME-REDESIGN-006-A: 4 columnas Conócenos / Vende con nosotros /
	 * Ayuda / Legal (los títulos de columna son 3 summaries + la col brand).
	 */
	public function test_001_cuatro_columnas(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'class="pv-home-footer__acc"',
			$src,
			'HOME-REDESIGN-006-A: las columnas deben usar acordeones <details>.'
		);
		$this->assertMatchesRegularExpression(
			"/<summary class=\"pv-home-footer__col-title\">[^<]*<\?php esc_html_e\(\s*'Vende con nosotros'/",
			$src,
			'HOME-REDESIGN-006-A: debe existir la columna Vende con nosotros.'
		);
		$this->assertMatchesRegularExpression(
			"/<summary class=\"pv-home-footer__col-title\">[^<]*<\?php esc_html_e\(\s*'Ayuda'/",
			$src,
			'HOME-REDESIGN-006-A: debe existir la columna Ayuda.'
		);
		$this->assertMatchesRegularExpression(
			"/<summary class=\"pv-home-footer__col-title\">[^<]*<\?php esc_html_e\(\s*'Legal'/",
			$src,
			'HOME-REDESIGN-006-A: debe existir la columna Legal.'
		);
		// Conócenos: la col brand conserva logo + tagline + redes.
		$this->assertStringContainsString( 'pv-home-footer__col--brand', $src );
		$this->assertStringContainsString( 'pv-home-footer__tagline', $src );
		$this->assertStringContainsString( 'pv-home-footer__social', $src );
	}

	/**
	 * HOME-REDESIGN-006-B: acordeones <details open> nativos (3 columnas de
	 * enlaces), colapsables en móvil, estáticos en escritorio (cero JS).
	 */
	public function test_002_acordeones_nativos(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertSame(
			3,
			substr_count( $src, '<details class="pv-home-footer__acc" open>' ),
			'HOME-REDESIGN-006-B: exactamente 3 acordeones (Vende/Ayuda/Legal), renderizados abiertos.'
		);

		// CSS: summary con chevron giratorio + desktop estático (pointer-events:none).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-footer__acc\[open\] summary::after\{transform:rotate\(-135deg\);\}/",
			$src,
			'HOME-REDESIGN-006-B: el chevron del acordeón debe girar al abrirse.'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-footer__acc summary\{cursor:default;pointer-events:none;\}/s",
			$src,
			'HOME-REDESIGN-006-B: en escritorio los headers del footer deben ser estáticos (sin colapsar).'
		);
	}

	/**
	 * HOME-REDESIGN-006-C: enlaces solo a páginas reales — sin enlaces rotos.
	 */
	public function test_003_enlaces_paginas_reales(): void {
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
	 * HOME-REDESIGN-006-D: fila de pagos + selector de moneda solo si ya
	 * existe soporte multi-moneda.
	 */
	public function test_004_pagos_y_selector_moneda(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'pv-home-footer__payrow',
			$src,
			'HOME-REDESIGN-006-D: debe existir la fila de pagos bajo las columnas.'
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
