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

	/**
	 * AUDIT-FE-PV-DS-004 (P1-2, breakpoints canónicos): el design system
	 * Plaza Viva usa breakpoints canónicos 1100/760/400px (ver @media del
	 * bloque responsive compartido). La sección shop v2.9.191 introdujo un
	 * `max-width: 768px` Tailwind-style que dejaba una ventana de 8px
	 * (761-768px) donde el sidebar de shop se comportaba distinto al resto
	 * del sistema.
	 *
	 * Este test garantiza que el archivo NO vuelva a acumular breakpoints
	 * fuera del sistema canónico.
	 */
	public function test_006_breakpoints_canonicos_sin_leaks_768(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		$this->assertStringNotContainsString(
			'max-width: 768px',
			$css,
			'AUDIT-FE-PV-DS-004 fix: ltms-plaza-viva.css no debe contener max-width:768px — usar el breakpoint canónico 760px'
		);
		$this->assertStringNotContainsString(
			'max-width:768px',
			$css,
			'AUDIT-FE-PV-DS-004 fix: ltms-plaza-viva.css no debe contener max-width:768px (variación sin espacio) — usar 760px'
		);

		// El breakpoint canónico del sidebar shop sigue presente.
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s?760px\)\s?\{[^}]*pv-shop-sidebar/s',
			$css,
			'AUDIT-FE-PV-DS-004: la regla mobile del pv-shop-sidebar debe existir bajo el breakpoint canónico 760px'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-005 (P1-3, empty state del shop): archive-product.php
	 * delega el caso "sin productos" a do_action('woocommerce_no_products_found')
	 * — WC imprime un .woocommerce-info crudo sin styling del design system.
	 *
	 * Fix: wrapper .pv-shop__empty en el template (hook preservado como
	 * válvula) + reglas CSS que integran el notice al sistema (superficie,
	 * borde dashed, tokens de texto).
	 */
	public function test_007_shop_empty_state_envuelto_y_estilado(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		$archive_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/archive-product.php';
		$this->assertFileExists( $archive_path );
		$archive = file_get_contents( $archive_path );

		// (1) El template envuelve el hook en el wrapper semantic.
		$this->assertMatchesRegularExpression(
			'/class="pv-shop__empty"[^>]*>\s*<\?php do_action\( \'woocommerce_no_products_found\' \); \?>/',
			$archive,
			'AUDIT-FE-PV-DS-005 fix: woocommerce_no_products_found debe renderizarse dentro del wrapper .pv-shop__empty'
		);

		// (2) El design system estila el notice de WC dentro del wrapper.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop \.pv-shop__empty \.woocommerce-info\s*\{[^}]+\}/',
			$css,
			'AUDIT-FE-PV-DS-005 fix: falta la regla .pv-shop__empty .woocommerce-info en ltms-plaza-viva.css'
		);

		// (3) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-005',
			$css,
			'AUDIT-FE-PV-DS-005: ltms-plaza-viva.css must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-006 (P1-4, sin style= inline): el form oculto del cupón
	 * en cart.php usaba style="display:none;" inline pese a que el design
	 * system provee la utilitaria .pv-scope .d-none { display:none !important }.
	 * El inline es el antipattern que la auditoría UX (UX-AUDIT-FE-P0-*)
	 * viene eliminando de todas las plantillas públicas.
	 */
	public function test_008_cart_coupon_form_oculto_con_clase_d_none(): void {
		$cart_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/cart.php';
		$this->assertFileExists( $cart_path );
		$cart = file_get_contents( $cart_path );

		// (1) El form del cupón usa la utilitaria del design system.
		$this->assertMatchesRegularExpression(
			'/id="pv-cart-coupon-form"[^>]*class="[^"]*\bd-none\b/',
			$cart,
			'AUDIT-FE-PV-DS-006 fix: pv-cart-coupon-form debe ocultarse con la clase .d-none del design system'
		);

		// (2) Ya NO hay style="display:none" inline en cart.php.
		$this->assertStringNotContainsString(
			'style="display:none',
			$cart,
			'AUDIT-FE-PV-DS-006 fix: cart.php no debe contener style="display:none" inline — usar .d-none'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-007 (P1-5, N+1 reviews en vendor-store): la sección de
	 * reseñas consultaba las últimas 8 reviews GLOBALES y filtraba por
	 * vendor en PHP (get_post_field por comentario ≈8 queries) + un
	 * wc_get_product() por review en el render (~6 queries más). Además el
	 * query global podía dejar la sección vacía aunque el vendor sí tuviera
	 * reseñas recientes.
	 *
	 * Fix: 1 query SQL con JOIN scopeado a post_author del vendor (rating
	 * incluido vía LEFT JOIN a commentmeta) + prefetch de productos en una
	 * sola llamada wc_get_products() → 2 queries totales.
	 */
	public function test_009_vendor_reviews_sin_n1_query_scopeado_y_prefetch(): void {
		$vendor_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php';
		$this->assertFileExists( $vendor_path );
		$src = file_get_contents( $vendor_path );

		// (1) Query SQL directo con prepare, scopeado al post_author del vendor.
		$this->assertMatchesRegularExpression(
			'/\$wpdb->prepare\(\s*"[^"]*INNER JOIN \{\$wpdb->posts\}[^"]*p\.post_author = %d/s',
			$src,
			'AUDIT-FE-PV-DS-007 fix: las reviews deben consultarse con JOIN scopeado a post_author (un solo query, $wpdb->prepare)'
		);

		// (2) El rating viene en el mismo query (LEFT JOIN a commentmeta).
		$this->assertMatchesRegularExpression(
			'/LEFT JOIN \{\$wpdb->commentmeta\}[^"]*meta_key = .rating./s',
			$src,
			'AUDIT-FE-PV-DS-007: el rating debe venir en el mismo query (evita get_comment_meta por review)'
		);

		// (3) Prefetch de productos reseñados en una sola llamada.
		$this->assertStringContainsString(
			'$pv_review_product_ids',
			$src,
			'AUDIT-FE-PV-DS-007 fix: debe existir el prefetch $pv_review_product_ids para los productos reseñados'
		);
		$this->assertMatchesRegularExpression(
			'/wc_get_products\(\s*\[\s*[\'"]include[\'"]\s*=>\s*\$pv_review_product_ids/',
			$src,
			'AUDIT-FE-PV-DS-007 fix: los productos de las reviews deben precargarse en UNA llamada wc_get_products(include=>...)'
		);

		// (4) El render ya NO llama wc_get_product() por review.
		$this->assertStringNotContainsString(
			'wc_get_product( $pv_r_pid )',
			$src,
			'AUDIT-FE-PV-DS-007 regression: el render no debe llamar wc_get_product por review — usar el mapa $pv_review_products'
		);

		// (5) La ASIGNACIÓN del array muerto sigue eliminada (los comments
		// de trazabilidad pueden citar el nombre).
		$this->assertStringNotContainsString(
			'$pv_reviews_args = [',
			$src,
			'AUDIT-FE-PV-DS-007: el array muerto pv_reviews_args no debe reintroducirse'
		);

		// (6) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-007',
			$src,
			'AUDIT-FE-PV-DS-007: vendor-store.php must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-008 (P1-6, secciones silenciosas en home): bento cats,
	 * trending y star vendors usaban if(!empty) sin else — si el query no
	 * devuelve datos la sección desaparece sin explicación (UX confusa en
	 * installs nuevos o catálogos vacíos).
	 *
	 * Fix: cada sección tiene else con .pv-home__empty-note (título + texto
	 * + CTA donde aplica) estilado con tokens del sistema.
	 */
	public function test_010_home_secciones_dinamicas_con_empty_state(): void {
		$this->assertFileExists( $this->home_template_path );
		$home = file_get_contents( $this->home_template_path );

		// (1) Las 3 secciones tienen rama else visible.
		foreach ( [ 'pv-home__cats', 'pv-home__trending', 'pv-home__vendors' ] as $section_class ) {
			$this->assertMatchesRegularExpression(
				'/else\s*:\s*\?\>[\s\S]{0,600}?class="[^"]*' . $section_class . '/',
				$home,
				"AUDIT-FE-PV-DS-008 fix: la sección $section_class debe tener empty state en su rama else"
			);
		}

		// (2) El componente .pv-home__empty-note está estilado con tokens.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home__empty-note\s*\{/',
			$home,
			'AUDIT-FE-PV-DS-008 fix: falta el estilo .pv-home__empty-note en el bloque CSS de home.php'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home__empty-note p\s*\{[^}]*var\(--text-2\)/s',
			$home,
			'AUDIT-FE-PV-DS-008 fix: el texto del empty-note debe usar el token var(--text-2)'
		);

		// (3) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-008',
			$home,
			'AUDIT-FE-PV-DS-008: home.php must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-009 (P1-7, stepper del checkout estático): el markup
	 * dejaba el paso 1 con is-active hardcodeado y .is-done nunca se aplicaba,
	 * pese a que ltms-checkout.css define estilos para ambos estados.
	 *
	 * Fix: el scope CHECKOUT de ltms-plaza-viva.js evalúa los campos
	 * requeridos de cada bloque [data-step-block] y marca .is-done en el
	 * item correspondiente; .is-active pasa al primer paso incompleto.
	 */
	public function test_011_stepper_checkout_marca_pasos_completados(): void {
		$js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->assertFileExists( $js_path );
		$js = file_get_contents( $js_path );

		// (1) El JS lee los bloques por data-step-block.
		$this->assertStringContainsString(
			"querySelector('[data-step-block=\"' + n + '\"]')",
			$js,
			'AUDIT-FE-PV-DS-009 fix: refreshStepper debe mapear cada item [data-step] a su bloque [data-step-block]'
		);

		// (2) Aplica la clase is-done que ya estila ltms-checkout.css.
		$this->assertMatchesRegularExpression(
			'/classList\.toggle\(\s*[\'"]is-done[\'"],\s*done\s*\)/',
			$js,
			'AUDIT-FE-PV-DS-009 fix: el stepper debe alternar la clase is-done según completitud del bloque'
		);

		// (3) Reacciona a updated_checkout (WC refresca fragmentos via jQuery).
		$this->assertStringContainsString(
			"'updated_checkout', refreshStepper",
			$js,
			'AUDIT-FE-PV-DS-009: el stepper debe refrescarse en updated_checkout (WC re-renderiza métodos de envío/pago)'
		);

		// (4) El CSS destino conserva los estados (contrato JS ↔ CSS).
		$checkout_css = dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css';
		$this->assertFileExists( $checkout_css );
		$ccss = file_get_contents( $checkout_css );
		$this->assertStringContainsString(
			'.pv-checkout__stepper-step.is-done',
			$ccss,
			'AUDIT-FE-PV-DS-009: ltms-checkout.css debe seguir definiendo el estado .is-done que el JS aplica'
		);

		// (5) Traza del fix para auditorías futuras.
		$this->assertStringContainsString(
			'AUDIT-FE-PV-DS-009',
			$js,
			'AUDIT-FE-PV-DS-009: ltms-plaza-viva.js must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-010 (P1-8, related products 1-col demasiado pronto):
	 * single-product.php colapsaba related a 1 columna en <560px; WoodMart
	 * mantiene 2 cols hasta ~400px. Fix: el colapso vive en su propio
	 * @media (max-width:400px) — breakpoint chico canónico del sistema.
	 */
	public function test_012_related_products_dos_columnas_hasta_400px(): void {
		$this->assertFileExists( $this->product_template_path );
		$tpl = file_get_contents( $this->product_template_path );

		// (1) El bloque 560px ya NO contiene el colapso de related a 1fr
		// (ventana corta: la regla vieja vivía a ~200 chars del @media).
		$this->assertDoesNotMatchRegularExpression(
			'/max-width:560px[\s\S]{0,260}?pv-related ul\.products\{grid-template-columns:1fr\}/',
			$tpl,
			'AUDIT-FE-PV-DS-010 fix: related no debe colapsar a 1 columna en el breakpoint 560px'
		);
		$this->assertMatchesRegularExpression(
			'/max-width:980px[\s\S]{0,400}?pv-related ul\.products\{grid-template-columns:repeat\(2,1fr\);?\}/',
			$tpl,
			'AUDIT-FE-PV-DS-010: entre 400-980px related debe mantener 2 columnas'
		);

		// (2) Existe un @media 400px con el colapso a 1 columna.
		$this->assertMatchesRegularExpression(
			'/max-width:400px[\s\S]{0,120}?pv-related ul\.products\{grid-template-columns:1fr;?\}\s*\}/',
			$tpl,
			'AUDIT-FE-PV-DS-010 fix: el colapso a 1 col de related debe vivir en max-width:400px'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-011 (P1-10, related count filtrable): el count de
	 * productos relacionados estaba hardcodeado (4) sin válvula de
	 * extensión. Fix: apply_filters( 'ltms_related_products_count', 4 ).
	 */
	public function test_013_related_products_count_filtrable(): void {
		$this->assertFileExists( $this->product_template_path );
		$tpl = file_get_contents( $this->product_template_path );

		$this->assertStringContainsString(
			"apply_filters( 'ltms_related_products_count', 4 )",
			$tpl,
			'AUDIT-FE-PV-DS-011 fix: posts_per_page de related debe pasar por el filtro ltms_related_products_count (default 4)'
		);

		// El default sin filtro sigue siendo 4 (paridad con grid 4-col) y
		// ningún posts_per_page numérico hardcodeado queda en el template.
		$this->assertDoesNotMatchRegularExpression(
			'/posts_per_page\'\s*=>\s*\d/',
			$tpl,
			'AUDIT-FE-PV-DS-011 regression: posts_per_page no debe hardcodearse numéricamente — usar el filtro'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-012 (P1-11, badge Star Seller frágil): el badge usaba
	 * right:50% + translateX(30px) fijo — la alineación dependía del ancho
	 * del texto del badge (fuente/traducción lo rompen). Fix: wrapper
	 * .pv-vendor-card__avatar-wrap alrededor de avatar+badge y centrado
	 * real con left:50% + translateX(-50%), independiente del ancho.
	 */
	public function test_014_vendor_star_badge_centrado_width_independiente(): void {
		$this->assertFileExists( $this->home_template_path );
		$home = file_get_contents( $this->home_template_path );

		// (1) El markup envuelve avatar+badge en el wrapper relativo.
		$this->assertMatchesRegularExpression(
			'/pv-vendor-card__avatar-wrap">\s*<span class="pv-vendor-card__avatar">[\s\S]{0,300}?pv-vendor-card__star/',
			$home,
			'AUDIT-FE-PV-DS-012 fix: avatar y badge Star Seller deben vivir dentro de .pv-vendor-card__avatar-wrap'
		);

		// (2) El CSS centra con translateX(-50%) — width-independiente.
		$this->assertMatchesRegularExpression(
			'/\.pv-vendor-card__star\{[^}]*left:50%;transform:translateX\(-50%\)/',
			$home,
			'AUDIT-FE-PV-DS-012 fix: el badge debe centrarse con left:50%+translateX(-50%) respecto al wrapper del avatar'
		);

		// (3) El hack viejo desapareció de la REGLA (los comments pueden
		// documentarlo).
		$this->assertDoesNotMatchRegularExpression(
			'/\.pv-vendor-card__star\s*\{[^}]*translateX\(30px\)/',
			$home,
			'AUDIT-FE-PV-DS-012 regression: la regla .pv-vendor-card__star no debe volver a usar el offset fijo translateX(30px)'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-013 (P1-12, FAQ abierto por defecto): help-center.php
	 * dejaba la primera FAQ expandida ($pv_open = idx===0) — confundía al
	 * usuario que busca un tema específico y estorbaba al filtrar con la
	 * búsqueda en vivo. Fix: todos los items colapsan por defecto.
	 */
	public function test_015_help_faq_sin_item_abierto_por_defecto(): void {
		$help_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/help-center.php';
		$this->assertFileExists( $help_path );
		$help = file_get_contents( $help_path );

		// (1) Ya NO existe la variable del default-open.
		$this->assertDoesNotMatchRegularExpression(
			'/\$pv_open\s*=\s*\(\s*\$pv_idx\s*===\s*0\s*\)/',
			$help,
			'AUDIT-FE-PV-DS-013 fix: la primera FAQ no debe abrirse por defecto (sin $pv_open idx===0)'
		);

		// (2) El <details> del FAQ item ya NO imprime el atributo open condicional.
		$this->assertDoesNotMatchRegularExpression(
			'/<details class="pv-accordion pv-help__faq-item"[^>]*\?php echo \$pv_open/',
			$help,
			'AUDIT-FE-PV-DS-013 fix: el details del FAQ no debe imprimir el atributo open condicional'
		);

		// (3) El item sigue exponiendo data-pv-faq-item (búsqueda en vivo).
		$this->assertStringContainsString(
			'data-pv-faq-item',
			$help,
			'AUDIT-FE-PV-DS-013: el item FAQ debe conservar data-pv-faq-item para el filtro en vivo'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-014 (P2-2, bundle total: decisión de diseño congelada).
	 *
	 * La sugerencia original era migrar el formateo del total del bundle a
	 * Intl.NumberFormat. Se resuelve como NO-APLICABLE intencional:
	 *
	 *   1. El formateo cliente ya replica EXACTAMENTE el config de moneda de
	 *      WooCommerce (symbol/decimal/thousand/decimals/position via
	 *      PV.config.pvCurrency — AUDIT-FE-SP-002). Intl.NumberFormat usa
	 *      reglas de locale que pueden DIVERGIR del formato WC configurado
	 *      en la tienda (p.ej. separador de miles o posición del símbolo
	 *      custom) → precios inconsistentes en la misma página.
	 *   2. El valor inicial renderizado server-side (wc_price) es efímero:
	 *      productScope llama recompute() al init, reemplazándolo de inmediato
	 *      por el cálculo cliente — cero divergencia real incluso sin interacción.
	 *
	 * Este test congela ambos invariantes para que una futura "optimización"
	 * no rompa la paridad de formato con WC.
	 */
	public function test_016_bundle_total_formato_parity_con_wc(): void {
		$js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->assertFileExists( $js_path );
		$js = file_get_contents( $js_path );

		// (1) El formateo del bundle lee el config de moneda de WC (no Intl).
		$this->assertMatchesRegularExpression(
			'/function formatMoney\(n\)\s*\{[^}]*PV\.config(\.\w+)?\.pvCurrency/s',
			$js,
			'AUDIT-FE-PV-DS-014: formatMoney debe leer PV.config.pvCurrency (config WC) — NO migrar a Intl.NumberFormat'
		);
		$this->assertStringNotContainsString(
			'Intl.NumberFormat',
			$js,
			'AUDIT-FE-PV-DS-014 regression: Intl.NumberFormat divergería del formato de precios de WC — mantener formatMoney con config WC'
		);

		// (2) recompute() corre al init del scope PRODUCT (total siempre cliente).
		$this->assertMatchesRegularExpression(
			'/items\.forEach\(function \(it\)\s*\{[\s\S]*?recompute\(\);/',
			$js,
			'AUDIT-FE-PV-DS-014: recompute() debe correr al init para reemplazar el total server-side sin esperar interacción'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-015 (P2-3, cupón sin confirmación): al aplicar un cupón
	 * el submit recarga la página — el clic no daba feedback inmediato. Fix:
	 * toast optimista vía PV.toast con string i18n couponApplying (expuesto
	 * por wp_localize_script; el resultado real lo imprime WC tras reload).
	 */
	public function test_017_cupon_toast_ack_inmediato(): void {
		$js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->assertFileExists( $js_path );
		$js = file_get_contents( $js_path );

		// (1) El handler del cupón muestra toast antes del submit.
		$this->assertMatchesRegularExpression(
			'/couponBtn\.addEventListener\(\s*[\'"]click[\'"][\s\S]{0,600}?PV\.toast\([\s\S]{0,200}?couponApplying[\s\S]{0,300}?couponForm\.submit\(\)/s',
			$js,
			'AUDIT-FE-PV-DS-015 fix: aplicar cupón debe mostrar PV.toast(couponApplying) antes de couponForm.submit()'
		);

		// (2) String i18n expuesto via localize (pasa por __()).
		$native = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->assertFileExists( $native );
		$php = file_get_contents( $native );
		$this->assertMatchesRegularExpression(
			'/\'couponApplying\'\s*=>\s*__\(/',
			$php,
			'AUDIT-FE-PV-DS-015 fix: couponApplying debe exponerse via wp_localize_script con __() para traducción'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-016 (P2-4, breathing room del footer home): el grid
	 * 2fr 1fr 1fr 1fr apretaba las columnas de enlaces en 1100-1400px.
	 * Fix: 1.6fr 1fr 1fr 1fr.
	 */
	public function test_018_home_footer_grid_breathing_room(): void {
		$this->assertFileExists( $this->home_template_path );
		$home = file_get_contents( $this->home_template_path );

		// La columna de marca ya no absorbe 2fr.
		$this->assertStringNotContainsString(
			'grid-template-columns:2fr 1fr 1fr 1fr',
			$home,
			'AUDIT-FE-PV-DS-016 fix: el footer de home no debe volver a 2fr 1fr 1fr 1fr'
		);
		$this->assertMatchesRegularExpression(
			'/pv-home-footer__inner\s*\{[^}]*grid-template-columns:\s*1\.6fr 1fr 1fr 1fr/s',
			$home,
			'AUDIT-FE-PV-DS-016 fix: pv-home-footer__inner debe usar grid 1.6fr 1fr 1fr 1fr'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-017 (P2-5, gap del layout producto en desktop grande):
	 * --pv-pg-gap 32px era justo en ≥1280px. Fix: override a 48px vía
	 * @media (min-width:1280px).
	 */
	public function test_019_product_layout_gap_48px_desktop_grande(): void {
		$this->assertFileExists( $this->product_template_path );
		$tpl = file_get_contents( $this->product_template_path );

		// Base sigue en 32px (mobile/tablet/desktop normal).
		$this->assertStringContainsString(
			'--pv-pg-gap:32px;',
			$tpl,
			'AUDIT-FE-PV-DS-017: la base del gap debe seguir siendo 32px'
		);

		// Override desktop grande a 48px.
		$this->assertMatchesRegularExpression(
			'/min-width:1280px[\s\S]{0,200}?--pv-pg-gap:48px/',
			$tpl,
			'AUDIT-FE-PV-DS-017 fix: debe existir @media(min-width:1280px) con --pv-pg-gap:48px'
		);
	}

	/**
	 * AUDIT-FE-PV-DS-018 (P2-6, atajo "/" en búsqueda del help center):
	 * hint kbd junto al input + handler global que enfoca la búsqueda al
	 * presionar "/" fuera de campos de texto.
	 */
	public function test_020_help_search_keyboard_shortcut(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		$help_path = dirname( __DIR__, 2 ) . '/includes/frontend/templates/help-center.php';
		$this->assertFileExists( $help_path );
		$help = file_get_contents( $help_path );

		$js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->assertFileExists( $js_path );
		$js = file_get_contents( $js_path );

		// (1) El template emite el hint kbd dentro del form hero.
		$this->assertMatchesRegularExpression(
			'/data-pv-faq-search[\s\S]{0,300}?<kbd class="pv-help__search-kbd"[^>]*>\/<\/kbd>/',
			$help,
			'AUDIT-FE-PV-DS-018 fix: falta el hint <kbd>/</kbd> junto al input de búsqueda'
		);

		// (2) El JS enfoca el input al presionar "/" fuera de campos de texto.
		$this->assertMatchesRegularExpression(
			'/e\.key !== \'\/\'[\s\S]{0,700}?preventDefault\(\);\s*searchInput\.focus\(\)/',
			$js,
			'AUDIT-FE-PV-DS-018 fix: el scope HELP debe enfocar [data-pv-faq-search] al presionar / (fuera de inputs)'
		);

		// (3) El CSS estila el kbd y lo oculta en mobile (sin teclado físico).
		$this->assertMatchesRegularExpression(
			'/\.pv-hero__search \.pv-help__search-kbd\s*\{/',
			$css,
			'AUDIT-FE-PV-DS-018 fix: falta estilo .pv-help__search-kbd en ltms-plaza-viva.css'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D03 (P0, focus visible en radio-cards checkout): los
	 * inputs de envío/pago son opacity:0 sin ningún :focus-visible/:focus-within
	 * sobre la tarjeta — usuario de teclado ciego en el paso crítico
	 * (WCAG 2.4.7). Fix: outline primario vía :focus-within.
	 */
	public function test_021_checkout_radio_cards_focus_visible(): void {
		$cko_css = dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css';
		$this->assertFileExists( $cko_css );
		$cko = file_get_contents( $cko_css );

		// (1) Existe regla focus-within para AMBOS tipos de opción.
		$this->assertMatchesRegularExpression(
			'/\.pv-shipping-option:focus-within[\s\S]{0,200}?\.pv-payment-option:focus-within\s*\{[^}]*outline:2px solid var\(--primary\)/',
			$cko,
			'AUDIT-FE-UIUX2-D03 fix: las radio-cards deben mostrar outline al recibir foco de teclado'
		);

		// (2) El outline usa offset (separación visual del borde de la card).
		$this->assertMatchesRegularExpression(
			'/\.pv-shipping-option:focus-within[\s\S]{0,300}?outline-offset:2px/s',
			$cko,
			'AUDIT-FE-UIUX2-D03: el outline debe llevar offset'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D01 (P0, vendor-store sin CSS de página): el markup
	 * emitía la capa BEM .pv-vendor-store__* sin NINGUNA regla en el repo —
	 * hero, stats, panels, reseñas y paginación renderizaban sin layout.
	 * Fix: sección 21 en ltms-plaza-viva.css + variante .pv-btn--invert.
	 */
	public function test_022_vendor_store_capa_pagina_estilada(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		// (1) Hero con gradiente de tokens.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-vendor-store \.pv-vendor-store__hero\s*\{[^}]*linear-gradient\(135deg,var\(--primary\)[^}]*var\(--primary-700\)/s',
			$css,
			'UIUX2-D01 fix: el hero del vendor store debe tener gradiente con tokens'
		);

		// (2) Stats en grid 4-col con números display.
		$this->assertMatchesRegularExpression(
			'/__stats\s*\{[^}]*grid-template-columns:repeat\(4,1fr\)/s',
			$css,
			'UIUX2-D01 fix: las stats del vendor deben ir en grid 4 columnas'
		);
		$this->assertMatchesRegularExpression(
			'/__stat dd\s*\{[^}]*var\(--display\)/s',
			$css,
			'UIUX2-D01: los números de stats deben usar la fuente display'
		);

		// (3) Reseñas en grid + paginación con .page-numbers estiladas.
		$this->assertMatchesRegularExpression(
			'/__reviews\s*\{[^}]*grid-template-columns:repeat\(2,1fr\)/s',
			$css,
			'UIUX2-D01 fix: las reseñas deben ir en grid 2 columnas'
		);
		$this->assertMatchesRegularExpression(
			'/__pagination \.page-numbers\.current\s*\{[^}]*var\(--primary\)/s',
			$css,
			'UIUX2-D01 fix: la paginación debe estilar .page-numbers con estado current'
		);

		// (4) La variante invert existe (CTAs sobre hero oscuro).
		$this->assertMatchesRegularExpression(
			'/\.pv-btn--invert\{[^}]*rgba\(255,255,255/',
			$css,
			'UIUX2-D01 fix: falta la variante .pv-btn--invert para CTAs sobre fondo oscuro'
		);

		// (5) Traza del fix.
		$this->assertStringContainsString(
			'AUDIT-FE-UIUX2-D01',
			$css,
			'UIUX2-D01: ltms-plaza-viva.css must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D02 (P0, help-center sin CSS de página): la capa BEM
	 * .pv-help__* no tenía NINGUNA regla — quick-grid, channels-grid,
	 * faq-list y cta-card colapsaban a columna única. Fix: sección 22 en
	 * ltms-plaza-viva.css + reset del marcador nativo de <summary> (doble
	 * indicador) + estado .is-disabled del canal Chat.
	 */
	public function test_023_help_center_capa_pagina_estilada(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		// (1) Grids 3-col para quick links y canales.
		$this->assertMatchesRegularExpression(
			'/__quick-grid\s*\{[^}]*grid-template-columns:repeat\(3,1fr\)/s',
			$css,
			'UIUX2-D02 fix: quick-grid debe ser grid 3 columnas'
		);
		$this->assertMatchesRegularExpression(
			'/__channels-grid\s*\{[^}]*grid-template-columns:repeat\(3,1fr\)/s',
			$css,
			'UIUX2-D02 fix: channels-grid debe ser grid 3 columnas'
		);

		// (2) CTA final con gradiente de tokens.
		$this->assertMatchesRegularExpression(
			'/__cta-card\s*\{[^}]*linear-gradient\(135deg,var\(--primary-50\)/s',
			$css,
			'UIUX2-D02 fix: la cta-card debe llevar gradiente primary-50'
		);

		// (3) Canal deshabilitado con tratamiento visual propio.
		$this->assertMatchesRegularExpression(
			'/__channel\.is-disabled\s*\{[^}]*opacity/s',
			$css,
			'UIUX2-D02 fix: el canal Chat is-disabled debe distinguirse visualmente'
		);

		// (4) Reset del marcador nativo de <summary> (doble indicador).
		$this->assertStringContainsString(
			'.pv-accordion__head::-webkit-details-marker{display:none;}',
			$css,
			'UIUX2-D02 fix: falta el reset del marcador nativo del summary'
		);

		// (5) Traza del fix.
		$this->assertStringContainsString(
			'AUDIT-FE-UIUX2-D02',
			$css,
			'UIUX2-D02: ltms-plaza-viva.css must contain the traceable fix marker'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D05 (P1, estado orientador con styling de error): el
	 * bloque "Aún no calculamos el envío" usaba --danger — falsa alarma en
	 * un estado transitorio normal. Fix: superficie info azul suave; el
	 * rojo queda para fallos reales.
	 */
	public function test_024_checkout_estado_orientador_sin_rojo_error(): void {
		$cko_css = dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css';
		$this->assertFileExists( $cko_css );
		$cko = file_get_contents( $cko_css );

		$this->assertMatchesRegularExpression(
			'/__no-shipping,\s*\.pv-scope\.pv-checkout \.pv-checkout__no-payment\s*\{[^}]*var\(--primary-50\)[^}]*var\(--primary-100\)/s',
			$cko,
			'UIUX2-D05 fix: los estados no-shipping/no-payment deben usar superficie info (primary-50/100)'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/__no-shipping,[^{]*\{[^}]*var\(--danger-50\)/',
			$cko,
			'UIUX2-D05 regression: los estados orientadores no deben volver a --danger'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D06 (P1, touch targets <44px y hover-only): fav 38px
	 * con opacity:0 hover-only, acciones del card 40px hover-only, remove
	 * del carrito 36px, chip de cupón 20px, inputs de cupón 42px. Fix:
	 * 44px en todos + :focus-within revela + @media(hover:none) muestra
	 * las acciones siempre en táctil + hit-area expandida en el chip.
	 */
	public function test_025_touch_targets_44px_y_visibles_en_tactil(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		$cko_css = dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css';
		$cko = file_get_contents( $cko_css );

		// BACKLOG-D32 FIX: el CSS scoped del carrito vive ahora en hoja dedicada.
		$cart_path = dirname( __DIR__, 2 ) . '/assets/css/ltms-cart.css';
		$cart = file_get_contents( $cart_path );

		// (1) Fav del card a 44px + focus-within + hover:none siempre visible.
		$this->assertMatchesRegularExpression(
			'/__fav\s*\{[^}]*width:44px;height:44px/s',
			$css,
			'UIUX2-D06 fix: el fav del product card debe medir 44px'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(hover:none\)\{[^}]*__fav\{opacity:1/s',
			$css,
			'UIUX2-D06 fix: en táctil el fav debe estar siempre visible'
		);
		$this->assertMatchesRegularExpression(
			'/-card:focus-within \.pv-product-card__fav\{opacity:1/s',
			$css,
			'UIUX2-D06 fix: el fav debe revelarse con focus-within (teclado)'
		);

		// (2) Acciones del card a 44px.
		$this->assertMatchesRegularExpression(
			'/__actions \.pv-btn\{flex:1;height:44px/s',
			$css,
			'UIUX2-D06 fix: los botones de acción del card deben medir 44px'
		);

		// (3) Remove del carrito a 44px.
		$this->assertMatchesRegularExpression(
			'/__item-remove\s*\{[^}]*width:44px;height:44px/s',
			$cart,
			'UIUX2-D06 fix: el remove del carrito debe medir 44px'
		);

		// (4) Chip de cupón con hit-area expandida.
		$this->assertMatchesRegularExpression(
			'/__coupon-chip-remove::before\s*\{[^}]*inset:-10px/s',
			$cart,
			'UIUX2-D06 fix: el chip-remove debe expandir su hit-area'
		);

		// (5) Cupón checkout a 44px.
		$this->assertDoesNotMatchRegularExpression(
			'/height:42px/',
			$cko,
			'UIUX2-D06 regression: no deben quedar touch targets de 42px en checkout'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D07 (P1, prefers-reduced-motion): el hallazgo reportaba
	 * ausencia de kill-switch en checkout.css y en el CSS inline de cart.
	 * Verificación: la cobertura es GLOBAL — plaza-viva.css define el
	 * kill-switch sobre `.pv-scope *` con !important (sección 8), se encola
	 * sin guard de página (class-ltms-native-templates.php:77) y checkout/
	 * cart renderizan bajo `.pv-scope`. Este test congela esa cobertura:
	 * si alguien vuelve el enqueue condicional o mueve el kill-switch, falla.
	 */
	public function test_026_reduced_motion_cobertura_global(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );

		// (1) Kill-switch global presente con !important.
		$this->assertMatchesRegularExpression(
			'/@media \(prefers-reduced-motion:reduce\)\s*\{\s*\.pv-scope \*,\.pv-scope \*::before,\.pv-scope \*::after\s*\{[^}]*animation-duration:\.001ms !important/s',
			$css,
			'UIUX2-D07: el kill-switch reduced-motion global (.pv-scope *) debe permanecer en plaza-viva.css'
		);

		// (2) Los templates de checkout/cart renderizan bajo .pv-scope
		// (requisito para heredar el kill-switch).
		$cko_tpl = dirname( __DIR__, 2 ) . '/includes/frontend/templates/checkout.php';
		$cart_tpl = dirname( __DIR__, 2 ) . '/includes/frontend/templates/cart.php';
		$this->assertStringContainsString( 'pv-scope pv-checkout', file_get_contents( $cko_tpl ), 'UIUX2-D07: checkout debe renderizar bajo .pv-scope' );
		$this->assertStringContainsString( 'pv-scope pv-cart', file_get_contents( $cart_tpl ), 'UIUX2-D07: cart debe renderizar bajo .pv-scope' );

		// (3) El enqueue del design system es global (sin guard de página).
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\( 'wp_enqueue_scripts', \[ __CLASS__, 'enqueue_assets' \], 20 \)/",
			$native,
			'UIUX2-D07: enqueue_assets debe seguir global (el kill-switch reduced-motion y todo el design system dependen de ello)'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D08 (P1, contraste WCAG AA): labels uppercase 12px en
	 * --text-3 (3.1:1) en tracking y .optional 0.78rem #9ca3af (2.5:1) en
	 * checkout. Fix: --text-2 (7:1) y mínimo 12px.
	 */
	public function test_027_contraste_labels_aa(): void {
		$tracking = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php' );
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );

		// (1) Labels uppercase del tracking ya no usan --text-3.
		$this->assertDoesNotMatchRegularExpression(
			'/text-transform:uppercase[^;}]*color:var\(--text-3\)|color:var\(--text-3\)[^;}]*text-transform:uppercase/',
			$tracking,
			'UIUX2-D08 fix: los labels uppercase del tracking no deben usar --text-3 (3.1:1 < AA)'
		);

		// (2) .optional con tamaño y color AA.
		$this->assertMatchesRegularExpression(
			'/\.pv-checkout__form \.optional\s*\{[^}]*var\(--text-2\)[^}]*font-size: ?12px/s',
			$cko_css,
			'UIUX2-D08 fix: .optional debe ser >=12px en --text-2'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D09 (P1, badge contradictorio en tracking): el badge
	 * "En curso" del ETA card se imprimía siempre, incluso con orden
	 * cancelada — contradictorio con el banner rojo de cancelado. Fix:
	 * condicional $is_cancelled → badge danger "Cancelada".
	 */
	public function test_028_tracking_eta_badge_coherente_con_cancelacion(): void {
		$tracking = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php' );

		// (1) El badge del ETA está condicionado a $is_cancelled.
		$this->assertMatchesRegularExpression(
			'/if \( \$is_cancelled \) :\s*\?>\s*<span class="pv-badge pv-badge--danger pv-badge--dot">[^<]*<\?php esc_html_e\( .Cancelada./s',
			$tracking,
			'UIUX2-D09 fix: el badge del ETA debe mostrar Cancelada (danger) cuando la orden está cancelada'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D11 (P1, estados custom sin color): shipped/in_transit
	 * generados por el mapa status_to_step no tenían regla — badge gris en
	 * el tramo crítico del envío. Fix: shipped→primary, in_transit→accent.
	 */
	public function test_029_tracking_badges_estados_custom_coloreados(): void {
		// BACKLOG-D20 FIX: los estilos scoped del tracking viven ahora en la
		// seccion 24 de plaza-viva.css (el template ya no los incrusta).
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css' );

		$this->assertMatchesRegularExpression(
			'/__status--shipped\{background:var\(--primary-50\)/',
			$css,
			'UIUX2-D11 fix: shipped debe tener badge primario'
		);
		$this->assertMatchesRegularExpression(
			'/__status--in-transit,\s*\.pv-scope\.pv-tracking \.pv-tracking__status--in_transit\{background:var\(--accent-50\)/',
			$css,
			'UIUX2-D11 fix: in_transit debe tener badge accent (ambas variantes de sanitize_html_class)'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D10 (P1, contador de reseñas mentiroso): el summary
	 * mostraba count($pv_reviews) capped por LIMIT 6 — "6 reseñas" con 200
	 * reales. Fix: COUNT(*) real en query aparte ($pv_total_reviews).
	 */
	public function test_030_vendor_reviews_count_real(): void {
		$vendor = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php' );

		// (1) Existe el conteo total real.
		$this->assertMatchesRegularExpression(
			'/\$pv_total_reviews = \(int\) \$wpdb->get_var\( \$wpdb->prepare\(\s*"SELECT COUNT\(\*\)/s',
			$vendor,
			'UIUX2-D10 fix: debe existir $pv_total_reviews con COUNT(*) real'
		);

		// (2) El summary ya NO usa el array capped.
		$this->assertDoesNotMatchRegularExpression(
			'/__reviews-count">.*_n\( .%d reseña., .%d reseñas., count\( \$pv_reviews \)/s',
			$vendor,
			'UIUX2-D10 regression: el summary no debe volver a count($pv_reviews) (capped por LIMIT 6)'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D16 (P1, empty state de reseñas inconsistente): era solo
	 * texto mientras el de productos tiene icono+copy+CTA. Fix: estructura
	 * simétrica con CTA que salta al tab de productos (data-pv-jump-tab).
	 */
	public function test_031_vendor_empty_reviews_simetrico(): void {
		$vendor = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php' );

		$this->assertMatchesRegularExpression(
			'/UIUX2-D16 FIX[\s\S]{0,700}?<polygon points="12 2 15\.09[\s\S]{0,300}?Aún no hay reseñas/s',
			$vendor,
			'UIUX2-D16 fix: el empty de reseñas debe llevar icono como el de productos'
		);
		$this->assertMatchesRegularExpression(
			'/Aún no hay reseñas[\s\S]{0,300}?data-pv-jump-tab="products"/s',
			$vendor,
			'UIUX2-D16 fix: el empty de reseñas debe tener CTA al tab de productos'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D15 (P1, residuos de paleta legacy en cart inline):
	 * fallbacks var(--primary, #00867d teal) y keyframe pv-pulse-pending
	 * que pulsaba teal rgba(0,134,125) en vivo. Fix: tokens limpios y
	 * pulso azul primario.
	 */
	public function test_032_cart_sin_residuos_teal_legacy(): void {
		// BACKLOG-D32 FIX: el CSS del carrito vive ahora en su hoja dedicada.
		$cart_path = dirname( __DIR__, 2 ) . '/assets/css/ltms-cart.css';
		$this->assertFileExists( $cart_path );
		$cart = file_get_contents( $cart_path );

		// (1) Sin fallback teal en primary.
		$this->assertDoesNotMatchRegularExpression(
			'/var\(--primary-700?,\s*#00[68]b?6[0-9a-f]{2}\)/i',
			$cart,
			'UIUX2-D15 fix: no debe haber fallback teal #00867d/#006b63'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/var\(--danger,\s*#dc2626\)/i',
			$cart,
			'UIUX2-D15 fix: no debe haber fallback #dc2626'
		);

		// (2) El pulso is-pending es azul primario.
		$this->assertDoesNotMatchRegularExpression(
			'/rgba\(0,\s*134,\s*125/',
			$cart,
			'UIUX2-D15 regression: el keyframe no debe volver a pulsar teal'
		);
		$this->assertMatchesRegularExpression(
			'/pv-pulse-pending\s*\{\s*0%,100%\{box-shadow:0 0 0 0 rgba\(37,99,235,0\);\}/',
			$cart,
			'UIUX2-D15 fix: el pulso is-pending debe usar el azul primario'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D12 (P1, hack font-size:0 en headings checkout): el
	 * renombrado "facturación → entrega" usaba font-size:0 + ::before —
	 * doble lectura en lectores y string sin i18n atrapado en CSS. Fix:
	 * rename via PV.i18n en fixFieldLabels + CSS limpio del heading.
	 */
	public function test_033_checkout_headings_sin_hack_css(): void {
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js' );
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php' );

		// (1) El hack CSS desapareció físicamente.
		$this->assertDoesNotMatchRegularExpression(
			'/woocommerce-billing-fields h3::before\s*\{[^}]*content:/s',
			$cko_css,
			'UIUX2-D12 fix: el hack ::before en headings de billing debe estar eliminado'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/woocommerce-billing-fields h3\s*\{[^}]*font-size: 0 !important/s',
			$cko_css,
			'UIUX2-D12 fix: el font-size:0 en headings debe estar eliminado'
		);

		// (2) El rename vive en el JS via PV.i18n.
		$this->assertMatchesRegularExpression(
			'/billingHeading\)\s*\{\s*billingHeading\.textContent = PV\.i18n\.billingHeading/',
			$js,
			'UIUX2-D12 fix: el heading de billing debe renombrarse via PV.i18n en el JS'
		);

		// (3) Strings i18n expuestos con __().
		$this->assertMatchesRegularExpression(
			'/\'billingHeading\'\s*=>\s*__\(/',
			$native,
			'UIUX2-D12 fix: billingHeading debe exponerse via wp_localize_script con __()'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D13 (P1, semántica de color del dinero): el total del
	 * checkout iba en azul --primary mientras el del carrito usa --brand.
	 * Regla unificada: TOTAL siempre --brand; montos secundarios --primary.
	 */
	public function test_034_total_checkout_color_paridad_con_carrito(): void {
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );

		$this->assertMatchesRegularExpression(
			'/order-total td \.woocommerce-Price-amount\s*\{[^}]*color:var\(--brand\)/s',
			$cko_css,
			'UIUX2-D13 fix: el total del checkout debe usar --brand (paridad con el carrito)'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D14 (P1, 4 identidades de bloques legales): terms/consent
	 * tenían 4 generaciones CSS simultáneas (brand custom-mark, duplicado con
	 * checkbox nativo, amarillo !important, azul) — hoy se veían azul y
	 * amarillo. Fix: generaciones B/C/D eliminadas; identidad única = brand-card.
	 */
	public function test_035_checkout_legales_identidad_unica(): void {
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );

		// (1) Ya no existe el bloque amarillo con !important.
		$this->assertDoesNotMatchRegularExpression(
			'/#fefce8|#facc15/i',
			$cko_css,
			'UIUX2-D14 fix: la identidad amarilla del consent debe estar eliminada'
		);

		// (2) Ya no existe el bloque azul que pisaba a terms.
		$this->assertDoesNotMatchRegularExpression(
			'/\.pv-checkout__terms-toggle\s*\{\s*padding: 12px 14px;\s*background: var\(--primary-50\)/',
			$cko_css,
			'UIUX2-D14 fix: la identidad azul de terms debe estar eliminada'
		);

		// (3) La generación duplicada con checkbox nativo también.
		$this->assertDoesNotMatchRegularExpression(
			'/Consolidar T&C \+ Privacy/',
			$cko_css,
			'UIUX2-D14 fix: la generación duplicada debe estar eliminada'
		);

		// (4) La identidad canónica brand-card sigue presente.
		$this->assertMatchesRegularExpression(
			'/__terms-toggle,\s*\.pv-scope\.pv-checkout \.ltms-checkout-consent\s*\{[^}]*var\(--brand-50\)[^}]*border-left:4px solid var\(--brand\)/s',
			$cko_css,
			'UIUX2-D14: la brand-card canónica de legales debe permanecer'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D17 (P1, emoji como iconos estructurales en home):
	 * logo 📍, títulos 🔥/⭐ — renderizado irregular entre OS y ruido en
	 * lectores. Fix: logo → SVG map-pin (header + footer); títulos limpios.
	 * Los emojis de categorías del bento quedan como identidad por-categoría
	 * (data-driven, intencional).
	 */
	public function test_036_home_sin_emoji_estructurales(): void {
		$home = file_get_contents( $this->home_template_path );

		// (1) El logo del header usa el SVG map-pin.
		$this->assertMatchesRegularExpression(
			'/__logo-mark" aria-hidden="true">\s*<svg[\s\S]{0,200}?M21 10c0 7-9 13-9 13/s',
			$home,
			'UIUX2-D17 fix: el logo-mark del header debe ser el SVG map-pin'
		);

		// (2) El logo del footer también (con su clase propia, sin style inline).
		$this->assertMatchesRegularExpression(
			'/footer__logo-mark" aria-hidden="true">\s*<svg/s',
			$home,
			'UIUX2-D17 fix: el logo del footer debe usar la clase logo-mark con SVG'
		);

		// (3) Los títulos de sección no llevan emoji.
		$this->assertDoesNotMatchRegularExpression(
			'/section__title">[^<]*(🔥|⭐)/u',
			$home,
			'UIUX2-D17 regression: los títulos de sección no deben volver a llevar emoji'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D34 + D35 (P2, CSS muerto en checkout.css): selector
	 * __badge que nunca matcheó (los badges reales son .pv-badge) y regla
	 * inválida `#billing_country { value:'CO' }` (value no es propiedad CSS).
	 */
	public function test_037_checkout_css_sin_selectores_muertos(): void {
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );

		// (1) D-34: el declutter móvil apunta a los badges reales.
		$this->assertMatchesRegularExpression(
			'/shipping-option \.pv-badge,\s*\.pv-scope\.pv-checkout \.pv-payment-option \.pv-badge\{display:none;\}/s',
			$cko_css,
			'D34 fix: el declutter móvil debe apuntar a .pv-badge'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/option__badge\{display:none/',
			$cko_css,
			'D34 regression: el selector muerto __badge no debe reaparecer'
		);

		// (2) D-35: sin regla inválida value:.
		$this->assertDoesNotMatchRegularExpression(
			'/value:\s*.CO.\s*!important/',
			$cko_css,
			'D35 fix: la regla inválida value:CO debe permanecer eliminada'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D22/D24/D27/D37 (P2, micro-fixes de cascada y landmarks):
	 * role="banner" duplicado en hero vendor; grid-4 contradictorio con
	 * grid-auto; transition:all animaba padding/margin en reflow; CTA del
	 * buscador help competía con la acción primaria.
	 */
	public function test_038_micro_fixes_cascada_y_landmarks(): void {
		$vendor = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php' );
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );
		$help = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/help-center.php' );

		// D-22: sin role banner duplicado.
		$this->assertDoesNotMatchRegularExpression(
			'/__hero" role="banner"/',
			$vendor,
			'D22 fix: el hero del vendor no debe duplicar el landmark banner'
		);

		// D-24: solo grid-auto en el grid de productos del vendor.
		$this->assertDoesNotMatchRegularExpression(
			'/__grid pv-scope grid-4/',
			$vendor,
			'D24 fix: el grid del vendor no debe llevar grid-4 contradictorio'
		);

		// D-27: sin transition:all en checkout.css.
		$this->assertDoesNotMatchRegularExpression(
			'/transition:\s*all\b/',
			$cko_css,
			'D27 fix: checkout.css no debe usar transition:all'
		);

		// D-37: el submit del buscador help es ghost.
		$this->assertMatchesRegularExpression(
			'/pv-btn--ghost pv-help__search-btn/',
			$help,
			'D37 fix: el CTA buscar del hero help debe ser ghost'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D25/D28/D30/D33/D36 (P2, lote tracking/checkout):
	 * orden móvil del timeline, offsets admin-bar, mailto sin pasarelas,
	 * aria-current del stepper y retirada del bounce doble.
	 */
	public function test_039_lote_tracking_checkout_ux(): void {
		$tracking = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php' );
		$cko_tpl = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/checkout.php' );
		$cko_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-checkout.css' );
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js' );
		// BACKLOG-D32 FIX: la regla admin-bar del carrito vive en su hoja dedicada.
		$cart = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-cart.css' );

		// D-25: sin order:-1 en la sidebar móvil del tracking.
		$this->assertDoesNotMatchRegularExpression(
			'/__sidebar\{position:static;top:auto;order:-1;\}/',
			$tracking,
			'D25 fix: la sidebar no debe anteposerse al timeline en móvil'
		);

		// D-28: offsets admin-bar en cart y checkout.
		$this->assertMatchesRegularExpression(
			'/body\.admin-bar \.pv-scope\.pv-cart \.pv-cart__summary\[data-pv-sticky\]\{top:52px;\}/',
			$cart,
			'D28 fix: falta offset admin-bar en el summary del carrito'
		);
		$this->assertMatchesRegularExpression(
			'/body\.admin-bar \.pv-scope\.pv-checkout \.pv-checkout__review\[data-pv-sticky\]\{top:52px;\}/',
			$cko_css,
			'D28 fix: falta offset admin-bar en el review del checkout'
		);

		// D-30: mailto en el mensaje sin pasarelas.
		$this->assertMatchesRegularExpression(
			'/No hay métodos de pago configurados todavía[\s\S]{0,200}?mailto:/',
			$cko_tpl,
			'D30 fix: el mensaje sin pasarelas debe incluir acción de contacto'
		);

		// D-33: aria-current en el stepper (PHP default + gestión JS).
		$this->assertMatchesRegularExpression(
			'/__stepper-step is-active" data-step="1" aria-current="step"/',
			$cko_tpl,
			'D33 fix: el stepper debe marcar aria-current="step" en el paso activo'
		);
		$this->assertMatchesRegularExpression(
			"/setAttribute\('aria-current', 'step'\)/",
			$js,
			'D33 fix: refreshStepper debe gestionar aria-current'
		);
	}

	/**
	 * AUDIT-FE-UIUX2-D18/D19/D21/D23/D31 (P2, lote transversal):
	 * breakpoints canon en tracking, tokens de variantes de badge,
	 * paginación de tienda, avatar con dimensiones, notices WC estilados.
	 */
	public function test_040_lote_transversal_tokens_y_notices(): void {
		$this->assertFileExists( $this->css_path );
		$css = file_get_contents( $this->css_path );
		$tracking = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php' );
		$vendor = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/vendor-store.php' );

		// D-18: breakpoints canon en tracking (sin 980 ni 480).
		$this->assertDoesNotMatchRegularExpression(
			'/max-width:980px|max-width:480px/',
			$tracking,
			'D18 fix: el tracking no debe tener breakpoints fuera del canon 1100/760/400'
		);

		// D-19: tokens de variantes definidos y usados por los badges.
		$this->assertMatchesRegularExpression(
			'/--accent-700:#0a8a68/',
			$css,
			'D19 fix: falta token --accent-700'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-badge--verified\{background:var\(--accent-50\);color:var\(--accent-700\);\}/',
			$css,
			'D19 fix: los badges deben usar los tokens de variante'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/color:#0a8a68|color:#b73a3e|color:#a06b00|background:#FFF4E0/',
			$tracking,
			'D19 regression: el tracking no debe volver a hex de badges sueltos'
		);

		// D-21: paginación de la tienda estilada.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-shop \.woocommerce-pagination ul\.page-numbers \.page-numbers\.current\s*\{[^}]*var\(--primary\)/s',
			$css,
			'D21 fix: falta paginación pills para la tienda'
		);

		// D-23: avatar del vendor con dimensiones explícitas.
		$this->assertMatchesRegularExpression(
			"/wp_get_attachment_image\( \(int\) \\\$pv_store_logo, 'medium'/",
			$vendor,
			'D23 fix: el avatar del hero debe usar tamaño medium'
		);

		// D-31: notices WC estilados bajo .pv-scope.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope \.woocommerce-message,\s*\.pv-scope \.woocommerce-info,\s*\.pv-scope \.woocommerce-error\s*\{/s',
			$css,
			'D31 fix: faltan estilos de notices WC en el design system'
		);
	}

	/**
	 * AUDIT-FE-EMAILS-01 (actualización de correos oficiales info.txt):
	 * soporte@lo-tengo.com.co no es un correo oficial (oficiales: dircomercialcol@,
	 * pqrscolombia@, sellerscolombia@, rgutierrez@, jgmontes@ — todos
	 * @lo-tengo.com.co). El dashboard lo mostraba y el checkout leía
	 * admin_email (gmail) en vez de la opción canónica ltms_support_email.
	 */
	public function test_041_emails_oficiales_sin_soporte_generico(): void {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-dashboard.js' );
		$cko_tpl = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/checkout.php' );

		// (1) El dashboard ya no muestra el correo no-oficial.
		$this->assertDoesNotMatchRegularExpression(
			'/soporte@lo-tengo\.com\.co/',
			$js,
			'EMAILS-01 fix: soporte@ no es oficial — usar pqrscolombia@'
		);
		$this->assertStringContainsString(
			'pqrscolombia@lo-tengo.com.co',
			$js,
			'EMAILS-01: el dashboard debe mostrar el correo oficial PQRS'
		);

		// (2) El checkout lee la opción canónica ltms_support_email.
		$this->assertMatchesRegularExpression(
			"/apply_filters\( 'ltms_support_email', get_option\( 'ltms_support_email', get_option\( 'admin_email' \) \) \)/",
			$cko_tpl,
			'EMAILS-01 fix: el checkout debe leer ltms_support_email (misma fuente que help-center)'
		);
	}

	/**
	 * AUDIT-FE-UIUX-BACKLOG-D20 (P2 organizativo): order-tracking.php
	 * incrustaba ~360 lineas de CSS scoped en un bloque de estilos dentro
	 * del markup — la ultima hoja inline del design system. Los estilos
	 * viven ahora en la seccion 24 de plaza-viva.css y el template queda
	 * sin hojas incrustadas. Congela la extraccion: si alguien re-introduce
	 * el bloque o borra la seccion, este test falla.
	 */
	public function test_042_tracking_css_extraido_al_design_system(): void {
		$tracking = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/order-tracking.php' );
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css' );
		$css_min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css' );

		// (1) El template ya no trae bloque de estilos incrustado.
		$this->assertStringNotContainsString(
			'<style',
			$tracking,
			'BACKLOG-D20 fix: order-tracking.php no debe incrustar estilos — viven en plaza-viva.css seccion 24'
		);

		// (2) La seccion 24 existe con los selectores raiz del tracking.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-tracking\{display:flex;flex-direction:column;gap:18px;padding:24px 0 48px;\}/',
			$css,
			'BACKLOG-D20 fix: falta la regla raiz .pv-scope.pv-tracking en plaza-viva.css'
		);

		// (3) El .min.css esta sincronizado con la nueva seccion (clean-css
		//     retira el punto y coma previo a la llave de cierre).
		$this->assertStringContainsString(
			'.pv-scope.pv-tracking{display:flex;flex-direction:column;gap:18px;padding:24px 0 48px}',
			$css_min,
			'BACKLOG-D20 fix: ltms-plaza-viva.min.css desincronizado — correr npm run build:css'
		);
	}

	/**
	 * AUDIT-FE-UIUX-BACKLOG-D32 (P2 organizativo): cart.php incrustaba
	 * ~330 lineas de CSS scoped en el markup. Los estilos viven ahora en
	 * assets/css/ltms-cart.css, encolada SOLO en paginas de carrito desde
	 * LTMS_Native_Templates::enqueue_assets() con version para
	 * cache-busting. Congela la extraccion y su mecanismo de carga.
	 */
	public function test_043_cart_css_extraido_y_encolado_condicional(): void {
		$cart = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/cart.php' );
		$cart_css_path = dirname( __DIR__, 2 ) . '/assets/css/ltms-cart.css';
		$this->assertFileExists( $cart_css_path );
		$cart_css = file_get_contents( $cart_css_path );
		$cart_min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-cart.min.css' );
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php' );

		// (1) El template ya no trae bloque de estilos incrustado.
		$this->assertStringNotContainsString(
			'<style',
			$cart,
			'BACKLOG-D32 fix: cart.php no debe incrustar estilos — viven en assets/css/ltms-cart.css'
		);

		// (2) La hoja dedicada conserva la regla raiz del scope del carrito.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-cart\{display:flex;flex-direction:column;gap:18px;padding-bottom:48px;\}/',
			$cart_css,
			'BACKLOG-D32 fix: falta la regla raiz .pv-scope.pv-cart en ltms-cart.css'
		);

		// (3) El .min.css dedicado esta sincronizado.
		$this->assertStringContainsString(
			'.pv-scope.pv-cart{display:flex;flex-direction:column;gap:18px;padding-bottom:48px}',
			$cart_min,
			'BACKLOG-D32 fix: ltms-cart.min.css desincronizado — correr npm run build:css'
		);

		// (4) El enqueue condicional existe y usa version del plugin.
		$this->assertMatchesRegularExpression(
			"/wp_enqueue_style\( 'ltms-cart', \\\$url \. 'css\/ltms-cart\.css', \[ 'ltms-plaza-viva' \], \\\$ver \)/",
			$native,
			'BACKLOG-D32 fix: falta el enqueue condicional de ltms-cart.css (con dependencia del design system)'
		);
	}
}
