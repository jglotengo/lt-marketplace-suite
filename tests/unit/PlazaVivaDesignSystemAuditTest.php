<?php
/**
 * Tests estructurales del design system Plaza Viva (Ciclo 1 de auditoría).
 *
 * Cubre hallazgos P0 de la auditoría del sistema de diseño compartido
 * (assets/css/ltms-plaza-viva.css + assets/js/ltms-plaza-viva.js + los 8
 * templates públicos + content-product.php). Los fixes son CSS-level, por
 * lo que las invariantes son estructurales sobre el source:
 *
 *   * AUDIT-FE-PV-DS-001 (P0, badges sin reglas CSS): content-product.php
 *     referencia .pv-product-card__discount--soft ("Oferta", :190) y
 *     --muted ("Agotado", :197) pero ltms-plaza-viva.css no definía reglas
 *     para esos modificadores → ambos badges heredaban el rojo --danger
 *     del badge de descuento (-X%), mostrando "Agotado" como si fuera una
 *     alerta de oferta agresiva. Fix: reglas --soft (dorado var(--gold))
 *     y --muted (neutral var(--bg-2)/var(--text-2)) después de la base.
 *
 *   * AUDIT-FE-PV-DS-002 (P0, .pv-empty sin regla en design system):
 *     single-product.php:583 emite <p class="pv-empty"> pero la única
 *     regla era inline en el <style> del propio template (ex :850) — se
 *     pierde al centralizar y cualquier otro emisor quedaba sin estilo.
 *     Fix: regla genérica .pv-scope .pv-empty en ltms-plaza-viva.css
 *     (sección 3.1.3 EMPTY STATE) + eliminación del duplicado inline.
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts
 * sobre el source CSS/PHP): NO cargan clases del plugin ni invocan WP →
 * deterministas en LTMS_UNIT_ONLY=true y CI Ubuntu (mismo patrón que
 * OrderTrackingAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PlazaVivaDesignSystemAuditTest
 *
 * Verifica los fixes AUDIT-FE-PV-DS-001+ sobre el design system
 * ltms-plaza-viva.css mediante invariantes estructurales del source.
 * Detecta regresiones si alguien elimina los modificadores de badge o
 * desincroniza el .min.css.
 */
final class PlazaVivaDesignSystemAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta al design system CSS.
	 */
	private string $css_path;

	/**
	 * Ruta absoluta al design system CSS minificado.
	 */
	private string $css_min_path;

	/**
	 * Ruta absoluta al template part content-product.php.
	 */
	private string $card_part_path;

	/**
	 * Ruta absoluta al template single-product.php.
	 */
	private string $product_template_path;

	/**
	 * Ruta absoluta al template home.php.
	 */
	private string $home_template_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que OrderTrackingAuditTest — ver su setUp).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->css_path       = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->css_min_path   = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css';
		$this->card_part_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/wc-parts/content-product.php';
		$this->product_template_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/single-product.php';
		$this->home_template_path    = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
	}

	/**
	 * AUDIT-FE-PV-DS-001 (P0, badges sin reglas CSS): el HTML de
	 * content-product.php emite dos modificadores del badge de descuento
	 * que no tenían reglas en el design system — caían al look del badge
	 * base (-X% rojo --danger):
	 *
	 *   * --soft  → "Oferta" (on_sale sin % calculable) debía verse como
	 *               aviso suave dorado, no rojo agresivo.
	 *   * --muted → "Agotado" (out of stock) debía verse neutral/apagado,
	 *               no como alarma roja.
	 */
	public function test_001_badges_soft_y_muted_definidos_en_design_system(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );
		$this->assertFileExists( $this->card_part_path );
		$part = file_get_contents( $this->card_part_path );

		// (1) El HTML referencia ambos modificadores (contrato render ↔ CSS).
		$this->assertStringContainsString(
			'pv-product-card__discount--soft',
			$part,
			'AUDIT-FE-PV-DS-001: content-product.php debe seguir emitiendo el badge --soft (Oferta)'
		);
		$this->assertStringContainsString(
			'pv-product-card__discount--muted',
			$part,
			'AUDIT-FE-PV-DS-001: content-product.php debe seguir emitiendo el badge --muted (Agotado)'
		);

		// (2) El CSS define ambas reglas (el fix).
		$this->assertStringContainsString(
			'.pv-product-card__discount--soft{',
			$css,
			'AUDIT-FE-PV-DS-001 fix: falta la regla .pv-product-card__discount--soft en ltms-plaza-viva.css'
		);
		$this->assertStringContainsString(
			'.pv-product-card__discount--muted{',
			$css,
			'AUDIT-FE-PV-DS-001 fix: falta la regla .pv-product-card__discount--muted en ltms-plaza-viva.css'
		);

		// (3) El fix usa los tokens del sistema (no hex sueltos): soft con
		// fondo dorado var(--gold), muted con neutrals var(--bg-2) y borde.
		$this->assertMatchesRegularExpression(
			'/\.pv-product-card__discount--soft\{[^}]*var\(--gold\)[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-001: --soft debe usar el token dorado var(--gold) del sistema'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-product-card__discount--muted\{[^}]*var\(--bg-2\)[^}]*var\(--border\)[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-001: --muted debe usar tokens neutrales var(--bg-2) + borde var(--border)'
		);

		// (4) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-001',
			$css,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.css must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-001 (sincronización .min.css): ltms-plaza-viva.min.css
	 * se genera con clean-css (`npm run build:css`). Si alguien edita el
	 * source y no regenera el min, producción pierde las reglas nuevas.
	 */
	public function test_002_min_css_sincronizado_con_badges(): void {
		$this->assertFileExists( $this->css_min_path );
		$min = file_get_contents( $this->css_min_path );

		$this->assertStringContainsString(
			'.pv-product-card__discount--soft',
			$min,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.min.css desactualizado — regenerar con npm run build:css (falta --soft)'
		);
		$this->assertStringContainsString(
			'.pv-product-card__discount--muted',
			$min,
			'AUDIT-FE-PV-DS-001: ltms-plaza-viva.min.css desactualizado — regenerar con npm run build:css (falta --muted)'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-002 (P0, .pv-empty sin regla en design system): el
	 * mensaje "Este producto no tiene especificaciones técnicas
	 * adicionales." (single-product.php:583) usaba una regla definida SOLO
	 * en el <style> inline del propio template (ex :850) — un duplicado que
	 * se pierde al centralizar estilos y dejaba sin estilo a cualquier otro
	 * emisor de la clase.
	 *
	 * Fix: regla genérica .pv-scope .pv-empty en ltms-plaza-viva.css y el
	 * duplicado inline eliminado del template. Paridad visual exacta con la
	 * regla eliminada: color var(--text-3) + font-style italic.
	 */
	public function test_003_pv_empty_definido_en_design_system_y_duplicado_inline_eliminado(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );
		$this->assertFileExists( $this->product_template_path );
		$tpl = file_get_contents( $this->product_template_path );

		// (1) El template sigue emitiendo la clase (el mensaje no desaparece).
		$this->assertStringContainsString(
			'class="pv-empty"',
			$tpl,
			'AUDIT-FE-PV-DS-002: single-product.php debe seguir emitiendo <p class="pv-empty"> para specs vacías'
		);

		// (2) El design system define la regla genérica con paridad de tokens.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope \.pv-empty\{[^}]*var\(--text-3\)[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-002 fix: falta .pv-scope .pv-empty{color:var(--text-3);...} en ltms-plaza-viva.css'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope \.pv-empty\{[^}]*italic[^}]*\}/',
			$css,
			'AUDIT-FE-PV-DS-002: la regla .pv-empty debe preservar font-style italic (paridad con la regla inline eliminada)'
		);

		// (3) El duplicado inline fue eliminado FÍSICAMENTE del template
		// (LECCIONES #141: migración física, no un comment que lo declare).
		$this->assertStringNotContainsString(
			'.pv-empty{',
			$tpl,
			'AUDIT-FE-PV-DS-002 fix: el duplicado inline .pv-scope.pv-product-page .pv-empty{...} debe eliminarse del <style> de single-product.php'
		);

		// (4) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-002',
			$css,
			'AUDIT-FE-PV-DS-002: ltms-plaza-viva.css must contain the traceable fix marker comment for future audits'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-002 (sincronización .min.css): el min debe contener la
	 * regla .pv-empty migrada al design system.
	 */
	public function test_004_min_css_sincronizado_con_pv_empty(): void {
		$this->assertFileExists( $this->css_min_path );
		$min = file_get_contents( $this->css_min_path );

		$this->assertStringContainsString(
			'.pv-scope .pv-empty',
			$min,
			'AUDIT-FE-PV-DS-002: ltms-plaza-viva.min.css desactualizado — regenerar con npm run build:css (falta .pv-empty)'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-003 (P1-1, DRY): la sección trending de home.php delega
	 * al template part canónico wc-parts/content-product.php vía
	 * wc_get_template_part( 'content', 'product' ). Antes el helper
	 * ltms_pv_render_trending_card() reimplementaba .pv-product-card con un
	 * subconjunto de features (sin KYC, sin SF-04, sin swatches, sin stock
	 * urgency, sin badges --soft/--muted) — dos fuentes de verdad para el
	 * mismo UI que divergían con cada fix del card. Helper eliminado
	 * físicamente (Lecciones #119/#141).
	 *
	 * Beneficio colateral: las cards trending ahora heredan automáticamente
	 * cualquier fix futuro de content-product.php (incluidos PV-DS-001).
	 */
	public function test_005_home_trending_delega_a_content_product(): void {
		$this->assertFileExists( $this->home_template_path );
		$home = file_get_contents( $this->home_template_path );

		// (1) Delegación presente en el loop trending.
		$this->assertStringContainsString(
			"wc_get_template_part( 'content', 'product' )",
			$home,
			'AUDIT-FE-PV-DS-003 fix: el loop trending de home.php debe delegar a wc_get_template_part(content,product)'
		);

		// (2) La DEFINICIÓN del helper duplicado fue eliminada físicamente
		// (los comments de trazabilidad pueden citar el nombre — lo que no
		// puede volver es la función).
		$this->assertStringNotContainsString(
			'function ltms_pv_render_trending_card',
			$home,
			'AUDIT-FE-PV-DS-003 fix: ltms_pv_render_trending_card() debe estar eliminada físicamente de home.php'
		);

		// (3) El setup de globals para el template part ($product/$post).
		$this->assertStringContainsString(
			'$product = $pv_trending_product;',
			$home,
			'AUDIT-FE-PV-DS-003: el loop debe setear el global $product antes de wc_get_template_part (content-product.php lo consume)'
		);
	}
}
