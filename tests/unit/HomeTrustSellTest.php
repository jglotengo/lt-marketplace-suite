<?php
/**
 * HomeTrustSellTest — tests de la franja de confianza, grid de productos y
 * franja "Vende con nosotros" de la home nativa (HOME-REDESIGN-005).
 *
 * Foco: C5 del rediseño de la home:
 *   - Franja de confianza (Temu/AliExpress): compacta, sin animación, móvil
 *     2×2, tablet/escritorio una línea con los 4 elementos. Íconos unificados
 *     a azul (la variante danger del 4º item leía como error — el brief
 *     reserva rojo para estados de error/éxito).
 *   - La trust bar genérica HF-02 (que prometía "Devoluciones garantizadas",
 *     excluida por el brief) NO se inyecta en la home nativa.
 *   - Grid de productos: 2 col móvil / 3 tablet / 4 escritorio / 5 solo
 *     ≥1440px. Radio 8px.
 *   - Radios uniformes: 8px tarjetas, 12px botones (scoped a la home, sin
 *     tocar tokens globales).
 *   - Franja "Vende con nosotros" (AliExpress): fondo azul marino con detalle
 *     dorado, sin cifras inventadas; desktop texto izq + botón der, móvil
 *     apilado con botón full-width.
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeTemplateWiringTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeTrustSellTest
 */
final class HomeTrustSellTest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $homepage_fixes_js_path;
	private string $homepage_fixes_min_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path              = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->homepage_fixes_js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-homepage-fixes.js';
		$this->homepage_fixes_min_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-homepage-fixes.min.js';
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
	 * HOME-REDESIGN-005-A: franja de confianza — móvil 2×2, tablet/escritorio
	 * una línea, compacta sin sombra, íconos unificados a azul.
	 */
	public function test_001_franja_confianza_responsiva_y_compacta(): void {
		$src = file_get_contents( $this->home_path );

		// Tablet/escritorio: una línea (4 columnas).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-trust-bar\{[^}]*grid-template-columns:repeat\(4,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-A: en tablet/escritorio la franja debe ser una línea con los 4 elementos.'
		);
		// Móvil: 2×2.
		$this->assertMatchesRegularExpression(
			"/@media \(max-width:767px\)\{[^@]*\.pv-scope\.pv-home \.pv-trust-bar\{[^}]*grid-template-columns:repeat\(2,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-A: en móvil la franja debe ser 2×2.'
		);
		// Compacta, sin sombra (una sola elevación por tarjeta — ninguna aquí).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-trust-bar\{[^}]*box-shadow:none;/s",
			$src,
			'HOME-REDESIGN-005-A: la franja debe ser compacta sin sombra (sin animación ni elevación extra).'
		);
		// Íconos unificados a azul (la variante danger leía como error).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-trust-item:nth-child\(n\) \.pv-trust-item__icon\{[^}]*background:var\(--primary-50\);color:var\(--primary\);/s",
			$src,
			'HOME-REDESIGN-005-A: los íconos de la franja deben unificarse a azul (danger reservado a error/éxito).'
		);
	}

	/**
	 * HOME-REDESIGN-005-B: la trust bar genérica HF-02 NO se inyecta en la
	 * home nativa (prometía "Devoluciones garantizadas", excluida por el brief).
	 */
	public function test_002_trust_bar_hf02_skip_en_home_nativa(): void {
		$this->assertFileExists( $this->homepage_fixes_js_path );
		$src = file_get_contents( $this->homepage_fixes_js_path );

		$this->assertMatchesRegularExpression(
			"/injectTrustBar\(\)\s*\{[^}]*document\.querySelector\('\.pv-scope\.pv-home'\)\)\s*return;/s",
			$src,
			'HOME-REDESIGN-005-B: injectTrustBar() debe saltarse cuando existe la home nativa (doble franja).'
		);

		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$min = file_get_contents( $this->homepage_fixes_min_path );
		$this->assertStringContainsString(
			'.pv-scope.pv-home',
			$min,
			'HOME-REDESIGN-005-B: el .min.js debe contener el skip de la home nativa (regenerado).'
		);
	}

	/**
	 * HOME-REDESIGN-005-C: grid de productos 2/3/4/5 columnas (móvil-primero).
	 */
	public function test_003_grid_productos_2_3_4_5_columnas(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home__product-grid\{[^}]*grid-template-columns:repeat\(2,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-C: base móvil 2 columnas.'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:768px\)\{[^@]*\.pv-scope\.pv-home \.pv-home__product-grid\{[^}]*repeat\(3,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-C: tablet 3 columnas.'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-home__product-grid\{[^}]*repeat\(4,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-C: escritorio 4 columnas.'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1440px\)\{[^@]*\.pv-scope\.pv-home \.pv-home__product-grid\{[^}]*repeat\(5,1fr\);/s",
			$src,
			'HOME-REDESIGN-005-C: 5 columnas solo en pantallas ≥1440px.'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home__product-grid \.pv-product-card\{margin:0;border-radius:8px;\}/",
			$src,
			'HOME-REDESIGN-005-C: las cards de producto deben tener radio 8px (radios uniformes).'
		);
	}

	/**
	 * HOME-REDESIGN-005-D: radios uniformes — tarjetas 8px ganan al final del
	 * <style>, botones 12px.
	 */
	public function test_004_radios_uniformes(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Bloque de radios de tarjetas al final (gana sobre las reglas específicas).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-product-card,\s*\n?\.pv-scope\.pv-home \.pv-vendor-card,\s*\n?\.pv-scope\.pv-home \.pv-cat-bar__item,\s*\n?\.pv-scope\.pv-home \.pv-home-hero__banner,\s*\n?\.pv-scope\.pv-home \.pv-home-hero__card\{border-radius:8px;\}/",
			$src,
			'HOME-REDESIGN-005-D: bloque de radios de tarjetas 8px (product/vendor/cat/hero) agrupado.'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-btn\{border-radius:12px;\}/",
			$src,
			'HOME-REDESIGN-005-D: los botones de la home deben tener radio 12px (brief: 8-12px).'
		);
	}

	/**
	 * HOME-REDESIGN-005-E: franja "Vende con nosotros" — fondo azul marino con
	 * detalle dorado, CTA dorado con texto navy (nunca dorado sobre blanco),
	 * desktop fila, móvil botón full-width.
	 */
	public function test_005_franja_vende_con_nosotros(): void {
		$src = file_get_contents( $this->home_path );

		$this->assertStringContainsString(
			'class="pv-home-sell"',
			$src,
			'HOME-REDESIGN-005-E: debe existir la franja "Vende con nosotros".'
		);
		$this->assertStringContainsString(
			'id="pv-home-sell-title"',
			$src,
			'HOME-REDESIGN-005-E: la franja debe tener su h2 con id.'
		);
		$this->assertStringContainsString(
			'ltms_become_seller_url',
			$src,
			'HOME-REDESIGN-005-E: el CTA debe apuntar al registro de vendedor (URL filterable).'
		);
		// Fondo: gradiente azul del design system (HOME-UX2-001 reemplazó el
		// navy hardcodeado #1A1A4E — fuera de los tokens PV — por el mismo
		// gradiente del hero fallback) con detalle dorado token (línea superior).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-sell\{[^}]*radial-gradient\(120% 100% at 0% 0%,#3b82f6 0%,var\(--primary\) 45%,var\(--primary-700\) 100%\);[^}]*border-top:3px solid var\(--gold\);/s",
			$src,
			'HOME-UX2-001: la franja debe usar el gradiente del design system con detalle dorado var(--gold).'
		);
		// CTA dorado token con texto del sistema (el dorado nunca como texto sobre blanco).
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-sell__cta\{[^}]*background:var\(--gold\);color:var\(--text\);/s",
			$src,
			'HOME-UX2-001: el CTA debe ser var(--gold) con texto var(--text) (AA ~8:1).'
		);
		// Desktop: texto izq + botón der en una sola fila.
		$this->assertMatchesRegularExpression(
			"/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-sell\{[^}]*flex-direction:row;/s",
			$src,
			'HOME-REDESIGN-005-E: en escritorio la franja debe ser texto izq + botón der en una fila.'
		);
		// Móvil: botón a todo el ancho.
		$this->assertMatchesRegularExpression(
			"/@media \(max-width:560px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-sell__cta\{width:100%;/s",
			$src,
			'HOME-REDESIGN-005-E: en móvil el botón debe ir a todo el ancho.'
		);
	}

	/**
	 * HOME-REDESIGN-007: popups excluidos de la home nativa (decisión del
	 * operador 2026-10-01; el brief excluye ventanas emergentes y avisos
	 * flotantes). (a) El popup de newsletter del módulo ux-enhancements
	 * (módulo 117, 45s) hace skip si existe .pv-scope.pv-home. (b) El prompt
	 * de push del Sales Booster hace skip en is_front_page()+is_page().
	 * Ambos siguen activos en las demás páginas.
	 */
	public function test_006_popups_excluidos_de_la_home(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/assets/js/ltms-ux-enhancements.js' );
		$ux_src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-ux-enhancements.js' );

		// (a) Newsletter: guard dentro de initNewsletterSignup, antes del setTimeout.
		$this->assertMatchesRegularExpression(
			"/function initNewsletterSignup\(\)\s*\{[\s\S]*?pv-scope\.pv-home[\s\S]*?setTimeout/",
			$ux_src,
			'HOME-REDESIGN-007: initNewsletterSignup debe hacer return si existe .pv-scope.pv-home (antes del setTimeout del popup).'
		);
		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$ux_min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-ux-enhancements.min.js' );
		$this->assertStringContainsString(
			'pv-scope.pv-home',
			$ux_min,
			'HOME-REDESIGN-007: el .min.js de ux-enhancements debe contener el guard (regenerado).'
		);

		// (a-2) Newsletter — la copia que SÍ corre en la home: ltms-ux-storefront.js
		// (módulo 117 duplicado; ux-enhancements no se encola en la home nativa —
		// verificado en producción: el overlay provenía de ux-storefront).
		$storefront_src = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-ux-storefront.js' );
		$this->assertMatchesRegularExpression(
			"/function initNewsletterSignup\(\)\s*\{[\s\S]*?pv-scope\.pv-home[\s\S]*?setTimeout/",
			$storefront_src,
			'HOME-REDESIGN-007: la copia de initNewsletterSignup en ltms-ux-storefront.js debe hacer return si existe .pv-scope.pv-home (es la que corre en la home).'
		);
		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$storefront_min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-ux-storefront.min.js' );
		$this->assertStringContainsString(
			'pv-scope.pv-home',
			$storefront_min,
			'HOME-REDESIGN-007: el .min.js de ux-storefront debe contener el guard (regenerado).'
		);
		// El guard no debe romper el delay del setTimeout (la constante delay
		// sigue declarada antes del setTimeout — verificado: su ausencia habría
		// sido ReferenceError en runtime que node --check no detecta).
		$this->assertMatchesRegularExpression(
			"/pv-scope\.pv-home'\)\) return;[\s\S]{0,200}const delay[\s\S]{0,120}setTimeout/",
			$storefront_src,
			'HOME-REDESIGN-007: la declaración const delay debe seguir presente entre el guard y el setTimeout.'
		);

		// (b) Sales Booster: guard en render_push_subscription_prompt.
		$booster_src = (string) preg_replace( '/\/\*.*?\*\//s', '', (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/business/class-ltms-sales-booster.php' ) );
		$this->assertMatchesRegularExpression(
			"/function render_push_subscription_prompt\(\): void\s*\{[\s\S]*?is_front_page\(\)\s*&&\s*is_page\(\)\s*\)\s*return;/",
			$booster_src,
			'HOME-REDESIGN-007: render_push_subscription_prompt debe hacer return en la home nativa (is_front_page + is_page).'
		);

		// (c) Traffic Booster: guard en render_newsletter_signup (el bar quedaba
		// colgando después del footer propio de la home).
		$traffic_src = (string) preg_replace( '/\/\*.*?\*\//s', '', (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/business/class-ltms-traffic-booster.php' ) );
		$this->assertMatchesRegularExpression(
			"/function render_newsletter_signup\(\): void\s*\{[\s\S]*?is_front_page\(\)\s*&&\s*is_page\(\)\s*\)\s*return;/",
			$traffic_src,
			'HOME-REDESIGN-007: render_newsletter_signup debe hacer return en la home nativa (is_front_page + is_page).'
		);
	}
}
