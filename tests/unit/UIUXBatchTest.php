<?php
/**
 * UIUXBatchTest — UIUX-BATCH-2026-10-25.
 *
 * Lote de 6 hallazgos reportados por el operador:
 *
 *   1. Lema "Tu Marketplace de Confianza en Colombia" cerca del header del
 *      home: lo inyectaba PV.injectHeroHeadline (legado v2.9.200 de la era
 *      Elementor) al inicio del <main> — ELIMINADO.
 *   2. /recuperar-contrasena/ con espacio en blanco en el header: con
 *      Elementor Pro desactivado, el Theme Builder ya no renderiza y el
 *      tema Hello imprime su header de respaldo casi vacío. El funnel
 *      auth ahora lleva el header PV estándar (elimina el espacio Y
 *      restaura la sección de cuenta).
 *   3. "Ingresar/Registrar de los compradores desapareció del header":
 *      mismo origen — el header PV se auto-excluía del funnel auth por
 *      REG-CHOICE-008; ya no. En el resto de páginas siempre estuvo.
 *   4. Botones "Añadir al carrito"/"Comprar ahora" desorganizados:
 *      .ltms-buy-now-btn (inyectado por PV.injectBuyNow) NO tenía estilo
 *      desktop (un <a> crudo junto al ATC de 48px). Sistema unificado
 *      flex desktop + grid móvil.
 *   5. Bundle "Cómpralo junto y ahorra" no llevaba al carrito: tras la
 *      cola de adds redirige directo a /carrito/ (paridad CART-UX-013b).
 *   6. Upsells del minicart llevaban a la ficha: ahora la card ES un
 *      botón data-pv-add-to-cart (add directo + refresh del drawer).
 *
 * @package LTMS\Tests\Unit
 * @group uiux-batch-1025
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class UIUXBatchTest
 *
 * @group uiux-batch-1025
 */
final class UIUXBatchTest extends LTMS_Unit_Test_Case {

	private string $pv_js;
	private string $pv_js_min;
	private string $drawer_js;
	private string $header_path;
	private string $catbar_path;
	private string $catbar_css;
	private string $single_path;
	private string $drawer_css;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$base = dirname( __DIR__, 2 );
		$this->pv_js       = $base . '/assets/js/ltms-plaza-viva.js';
		$this->pv_js_min   = $base . '/assets/js/ltms-plaza-viva.min.js';
		$this->drawer_js   = $base . '/assets/js/ltms-cart-drawer.js';
		$this->header_path = $base . '/includes/frontend/class-ltms-pv-header.php';
		$this->catbar_path = $base . '/includes/frontend/class-ltms-global-catbar.php';
		$this->catbar_css  = $base . '/assets/css/ltms-catbar.css';
		$this->single_path = $base . '/includes/frontend/templates/single-product.php';
		$this->drawer_css  = $base . '/assets/css/ltms-cart-drawer.css';
		$this->webhook_path = $base . '/deploy/ltms-deploy-webhook.php';
	}

	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * BATCH-1: el lema "Tu Marketplace de Confianza en Colombia" ya no se
	 * inyecta en el home (ni la función ni su llamada).
	 */
	public function test_001_lema_home_eliminado(): void {
		$js = (string) file_get_contents( $this->pv_js );

		$this->assertStringNotContainsString( 'injectHeroHeadline =', $js, 'UIUX-BATCH-1: la función que inyectaba el lema debe estar ELIMINADA.' );
		$this->assertStringNotContainsString( 'Tu Marketplace de Confianza', $js, 'UIUX-BATCH-1: el texto del lema no debe existir en el JS.' );
		$this->assertStringNotContainsString( "PV.injectHeroHeadline()", $js, 'UIUX-BATCH-1: la llamada al inyector debe estar eliminada.' );
		// Trazabilidad del retiro.
		$this->assertStringContainsString( 'UIUX-BATCH-2026-10-25', $js, 'UIUX-BATCH-1: el retiro lleva su tag de trazabilidad.' );

		$min = (string) file_get_contents( $this->pv_js_min );
		$this->assertStringNotContainsString( 'Tu Marketplace de Confianza', $min, 'UIUX-BATCH-1: el .min.js regenerado tampoco contiene el lema.' );
	}

	/**
	 * BATCH-2/3: header PV en el funnel auth (el espacio blanco del header
	 * de respaldo del tema desaparece y la sección cuenta/ingresar vuelve).
	 */
	public function test_002_header_pv_en_funnel_auth(): void {
		$src = $this->strip_php_comments( (string) file_get_contents( $this->header_path ) );

		$this->assertDoesNotMatchRegularExpression(
			'/is_vendor_auth_page\(\)/',
			$src,
			'UIUX-BATCH-2: should_render del header YA NO consulta is_vendor_auth_page — el funnel auth (recuperar contraseña, registro, login) lleva el header PV estándar.'
		);
		$this->assertMatchesRegularExpression(
			'/is_vendor_panel_page\(\)/',
			$src,
			'UIUX-BATCH-2: el panel del vendedor SIGUE excluido (app con chrome propio).'
		);

		// El catbar tampoco excluye el auth (barra de categorías estándar).
		$catbar = $this->strip_php_comments( (string) file_get_contents( $this->catbar_path ) );
		$should_render = '';
		if ( preg_match( '/public static function should_render\( string \$context = \'wp_body_open\' \): bool \{(.*?)\n\t\}/s', $catbar, $m ) ) {
			$should_render = $m[1];
		}
		$this->assertNotEmpty( $should_render, 'UIUX-BATCH-2: no se encontró should_render del catbar para verificar.' );
		$this->assertDoesNotMatchRegularExpression( '/is_vendor_auth_page\(\)/', $should_render, 'UIUX-BATCH-2: el catbar YA NO excluye el funnel auth de la barra (página pública estándar).' );
		$this->assertMatchesRegularExpression( '/is_vendor_panel_page\(\)/', $should_render, 'UIUX-BATCH-2: el catbar sigue excluyendo el panel del vendedor.' );

		// Panel: el header de RESPALDO del tema Hello se oculta (espacio blanco).
		$css = (string) file_get_contents( $this->catbar_css );
		$this->assertMatchesRegularExpression(
			'/body\.ltms-panel-view #site-header,/',
			$css,
			'UIUX-BATCH-2: el header de respaldo del tema (#site-header, casi vacío con Elementor Pro off) se oculta en la vista de panel.'
		);
	}

	/**
	 * BATCH-4: botones del producto organizados — buy-now estilizado +
	 * grid móvil determinista (reemplaza la pila parcial ≤560).
	 */
	public function test_003_botones_producto_organizados(): void {
		$css = (string) file_get_contents( $this->single_path );

		// Desktop: buy-now estilizado como botón secundario de 48px (antes crudo).
		$this->assertMatchesRegularExpression(
			'/form\.cart:not\(\.variations_form\) \.ltms-buy-now-btn\{[^}]*height:48px;/s',
			$css,
			'UIUX-BATCH-4: "Comprar ahora" estilizado a 48px de alto (antes era un <a> sin estilo pegado al ATC).'
		);
		$this->assertMatchesRegularExpression(
			'/form\.cart:not\(\.variations_form\) \.ltms-buy-now-btn\{[^}]*border:2px solid var\(--brand\);/s',
			$css,
			'UIUX-BATCH-4: "Comprar ahora" usa el outline de marca (secundario frente al ATC rojo).'
		);
		// Móvil: grid determinista hasta 768 (antes solo había pila ≤560).
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:768px\)\{[^@]*form\.cart:not\(\.variations_form\)\{[^}]*display:grid !important;/s',
			$css,
			'UIUX-BATCH-4: móvil ≤768px organiza la compra en grid (cantidad + ATC en fila 1; buy-now y favoritos a ancho completo).'
		);
		// La pila vieja parcial fue reemplazada (no debe quedar el bloque PDP-MOBILE-SPACING).
		$this->assertStringNotContainsString(
			'PDP-MOBILE-SPACING FIX (2026-09-12)',
			$css,
			'UIUX-BATCH-4: el sistema viejo parcial fue reemplazado por el unificado (lección #119).'
		);
	}

	/**
	 * BATCH-5: el bundle "Cómpralo junto y ahorra" redirige al carrito
	 * tras añadir el paquete (mismo comportamiento que el sticky ATC).
	 */
	public function test_004_bundle_lleva_a_carrito(): void {
		$js = (string) file_get_contents( $this->pv_js );

		$this->assertMatchesRegularExpression(
			"/window\.location\.href = \(window\.ltms_data && window\.ltms_data\.cart_url\) \|\| '\/carrito\/';/",
			$js,
			'UIUX-BATCH-5: tras añadir el paquete completo redirige al carrito (CART-UX-013b).'
		);
		$this->assertStringContainsString( 'no me lleva a carrito', $js, 'UIUX-BATCH-5: trazabilidad del reporte del operador.' );
	}

	/**
	 * BATCH-6: las cards "También te puede interesar" del minicart agregan
	 * directo al carrito (botón data-pv-add-to-cart), no navegan a la ficha.
	 */
	public function test_005_upsells_minicart_agregan_directo(): void {
		$js = (string) file_get_contents( $this->drawer_js );

		$this->assertStringContainsString(
			'data-pv-add-to-cart="' . "' + esc(u.id || '')",
			$js,
			'UIUX-BATCH-6: la card de upsell es un botón data-pv-add-to-cart con el id del producto.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<a class="ltms-minicart__upsell" href=/',
			$js,
			'UIUX-BATCH-6: la card YA NO es un link a la ficha del producto.'
		);

		$css = (string) file_get_contents( $this->drawer_css );
		$this->assertMatchesRegularExpression(
			'/\.ltms-minicart__upsell\{[^}]*cursor:pointer;/s',
			$css,
			'UIUX-BATCH-6: la card tiene affordance de botón (cursor pointer).'
		);
		$this->assertMatchesRegularExpression(
			'/\.ltms-minicart__upsell-add\{/',
			$css,
			'UIUX-BATCH-6: el badge "+" de agregar existe en la card.'
		);
	}

	/**
	 * BATCH-7: whitelist del deploy webhook (lección #177).
	 */
	public function test_006_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		foreach ( array(
			"'assets/js/ltms-cart-drawer.js',",
			"'assets/js/ltms-cart-drawer.min.js',",
			"'assets/css/ltms-catbar.min.css',",
			"'assets/css/ltms-cart-drawer.css',",
			"'assets/css/ltms-cart-drawer.min.css',",
			"'tests/unit/UIUXBatchTest.php',",
		) as $entry ) {
			$this->assertStringContainsString( $entry, $webhook, "UIUX-BATCH-7: {$entry} debe estar en la whitelist del webhook." );
		}
	}
}
