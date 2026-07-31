<?php
/**
 * Tests estructurales de los templates públicos home.php y single-product.php.
 *
 * Foco actual: AUDIT-FE Fase 1.10 — cierre CSP-compliance de las últimas 2
 * excepciones significativas del design system Plaza Viva. Migración de los
 * bloques <script> inline de home.php (44 líneas) y single-product.php (159
 * líneas) a ltms-plaza-viva.js (scopes HOME y PRODUCT).
 *
 * Hallazgos resueltos por esta suite:
 *
 *   * AUDIT-FE-HOME-001 (P1, código muerto duplicado): el bloque <script> de
 *     home.php tenía 2 behaviours que NO se migran (ver justificación en el
 *     comment block del fix dentro del propio template):
 *       1. Chips de búsqueda — handler global del design system
 *          (ltms-plaza-viva.js líneas 588-614, AUDIT-FE-HOME-003 FIX, commit
 *          9882789b) YA rellena el input + hace form.submit() al click en
 *          [data-pv-search-chip]. El listener inline se registraba después y
 *          nunca corría visible (navegación síncrona del handler global).
 *       2. Header shadow — toggle de clase `.is-scrolled` que NO existe en
 *          ningún CSS (verificado: 0 matches en ltms-plaza-viva.css,
 *          ltms-homepage-fixes.css, ltms-frontend.css). UI muerta, mismo
 *          patrón que LECCIONES #139 / OT-002.
 *
 *   * AUDIT-FE-SP-001 (P0, script-tag inline con 3 behaviours): el bloque
 *     <script> de single-product.php tenía sticky-nav active link (Intersection
 *     Observer + smooth scroll), bundle recompute (toggle de checkboxes +
 *     descuento 2+ items + total/save update), bundle add-to-cart (fetch
 *     manual con URLSearchParams + nonce/action a mano). Los 3 migrados al
 *     scope PRODUCT de ltms-plaza-viva.js; el bundle ATC ahora usa PV.ajax
 *     (paridad con todos los add-to-cart del design system — AUDIT-FE-PV-001
 *     Fase 1.4 garantea que el handler PHP valida contra el nonce global
 *     'ltms_plaza_viva').
 *
 *   * AUDIT-FE-SP-002 (P1, config de moneda inyectada inline): la variable
 *     `window.ltms_pv_currency` era inyectada via `<?php echo wp_json_encode
 *     ($pv_currency); ?>` DENTRO del script-tag inline. AHORA se expone via
 *     wp_localize_script('ltms-plaza-viva', 'ltms_data', ...) en
 *     class-ltms-native-templates.php como `ltms_data.pv_currency`,
 *     accesible en JS via `PV.config.pvCurrency` (mapeo se hace en el init
 *     de PV.config al inicio de ltms-plaza-viva.js). Mismo patrón que
 *     AUDIT-FE-CKO-004 Fase 1.7 (country).
 *
 *   * data-pv-bundle-discount: el descuento por bundle ($bundle_discount, %
 *     entero) que antes se inyectaba via `<?php echo (int) $bundle_discount; ?>`
 *     DENTRO del JS inline ahora se lee del data-attr del <section class=
 *     "pv-bundle">. Evita meter PHP dentro del JS.
 *
 * Invariantes adicionales cubiertas:
 *
 *   * CSP-compliance estricto: home.php y single-product.php NO contienen
 *     NINGÚN `<script>` ni `</script>` (strips PHP comments antes de validar
 *     nonces negativos — el propio comment del fix menciona `window.__ltms*`
 *     y `$pv_currency` textualmente como documentación — LECCIONES #141).
 *
 *   * Sin acompañantes inline peligrosos: home.php y single-product.php NO
 *     contienen `onsubmit=`, `onload=`, `onclick=` inline event handlers.
 *
 *   * Estructura HTML preservada: home.php sigue emitiendo el scope `.pv-scope
 *     .pv-home`, el input `#pv-home-search`, los chips `data-pv-search-chip`, y
 *     `.pv-home-header`. single-product.php sigue emitiendo `.pv-scope.pv-product-page`,
 *     la sección `.pv-bundle` con `data-pv-bundle-discount`, y todos los
 *     items/total/save/add con sus data-attrs (no se rompió la migración).
 *
 *   * JS del design system: ltms-plaza-viva.js contiene los scopes HOME y
 *     PRODUCT como IIFEs (`homeScope`, `productScope`), con el sticky-nav
 *     (IntersectionObserver), bundle recompute, bundle ATC via PV.ajax.
 *
 *   * Sincronización .min.js: ltms-plaza-viva.min.js contiene los
 *     identificadores críticos del scope PRODUCT (pvCurrency, data-pv-bundle-
 *     discount). SiteGround SG Optimizer carga el .min.js en producción. No
 *     basta con que el .js source tenga el scope — el .min.js debe tenerlo.
 *     Mismo patrón AUDIT-FE-HC-007 (Fase 1.9) y CI-LINT-MIN-001.
 *
 *   * wp_localize_script: ltms_data.pv_currency está declarado en el array
 *     de wp_localize_script('ltms-plaza-viva', 'ltms_data', ...) en
 *     class-ltms-native-templates.php (NO solo en el fallback del JS).
 *
 *   * PV.config.pvCurrency: el init de PV.config en ltms-plaza-viva.js
 *     mapea `window.ltms_data.pv_currency` -> `PV.config.pvCurrency`.
 *
 * Estos tests son PURAMENTE estructurales (file_get_contents + asserts sobre
 * el source PHP/JS): NO cargan clases del plugin ni invocan WP → deterministas
 * en LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático del
 * autoloader de Composer (mismo patrón que HelpCenterAuditTest,
 * OrderTrackingAuditTest, VendorStoreCspTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeProductScopeAuditTest
 *
 * Verifica los fixes AUDIT-FE-HOME-001, AUDIT-FE-SP-001 y AUDIT-FE-SP-002
 * (Fase 1.10) sobre los templates includes/frontend/templates/home.php y
 * single-product.php y los scopes HOME + PRODUCT de assets/js/ltms-plaza-viva.js.
 * Detecta regresiones si alguien reintroduce los bloques <script> inline,
 * elimina la declaración pv_currency del wp_localize_script, o quita el
 * mapeo PV.config.pvCurrency.
 */
final class HomeProductScopeAuditTest extends LTMS_Unit_Test_Case {

	/**
	 * Ruta absoluta a la plantilla home.php.
	 */
	private string $home_path;

	/**
	 * Ruta absoluta a la plantilla single-product.php.
	 */
	private string $product_path;

	/**
	 * Ruta absoluta a class-ltms-native-templates.php (wp_localize_script).
	 */
	private string $native_templates_path;

	/**
	 * Ruta absoluta al design system JS source.
	 */
	private string $pv_js_path;

	/**
	 * Ruta absoluta al design system JS minificado.
	 */
	private string $pv_min_js_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer. Esto
	 * los hace deterministas tanto en LTMS_UNIT_ONLY=true como en CI
	 * Ubuntu (mismo patrón que HelpCenterAuditTest, OrderTrackingAuditTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->home_path              = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->product_path           = dirname( __DIR__, 2 ) . '/includes/frontend/templates/single-product.php';
		$this->native_templates_path  = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-native-templates.php';
		$this->pv_js_path             = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->pv_min_js_path         = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco de un
	 * source PHP antes de validar nonces negativos. El propio comment block
	 * descriptivo del fix menciona textualmente `window.__ltms*`,
	 * `$pv_currency`, `<script>`, etc. como documentación — esos mentions
	 * NO deben contar como código vivo (LECCIONES_APRENDIDAS #141 —
	 * canarios mentirosos en comments).
	 *
	 * @param string $src Source PHP crudo.
	 * @return string Source sin comentarios slash-asterisco.
	 */
	private function strip_php_comments( string $src ): string {
		return preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	// ── AUDIT-FE-HOME-001: home.php sin <script> inline ────────────────────

	/**
	 * AUDIT-FE-HOME-001 (P1, código muerto duplicado): el bloque <script>
	 * inline de home.php (44 líneas, líneas 1009-1052 originales) con 2
	 * behaviours cosméticos fue ELIMINADO físicamente. NO se migró al
	 * design system porque ambos behaviours eran código muerto (ver
	 * justification en el comment block del fix dentro del template).
	 *
	 * Regresión: si alguien reintroduce un <script> en home.php, este test
	 * falla inmediatamente (CSP violation en producción).
	 */
	public function test_001_home_php_sin_scripts_inline(): void {
		$this->assertFileExists( $this->home_path );
		$src = file_get_contents( $this->home_path );
		$src_without_comments = $this->strip_php_comments( $src );

		// (1) home.php NO contiene <script> (strips comments primero: el
		// comment block del fix menciona `<script>` textualmente).
		$this->assertStringNotContainsString(
			'<script>',
			$src_without_comments,
			'AUDIT-FE-HOME-001: home.php no debe contener bloques <script> inline (CSP-compliance del design system Plaza Viva)'
		);

		// (2) home.php NO contiene </script>.
		$this->assertStringNotContainsString(
			'</script>',
			$src_without_comments,
			'AUDIT-FE-HOME-001: home.php no debe contener </script> (tag de cierre sin apertura)'
		);

		// (3) La traza del fix está presente.
		$this->assertStringContainsString(
			'AUDIT-FE-HOME-001 FIX',
			$src,
			'AUDIT-FE-HOME-001: la traza del fix debe estar presente en el comment block del template'
		);
	}

	/**
	 * Invariante: home.php sigue emitiendo el scope .pv-scope.pv-home y los
	 * elementos HTML críticos de la home (search input, chips, header).
	 * La migración fue del JS, no del HTML — estos elementos son los que
	 * el handler global del design system (líneas 588-614) usa para
	 * rellenar el input y dispatchar form.submit() al click en un chip.
	 */
	public function test_002_home_php_estructura_html_preservada(): void {
		$this->assertFileExists( $this->home_path );
		$src = file_get_contents( $this->home_path );

		// (1) Scope selector presente.
		$this->assertStringContainsString(
			'pv-home',
			$src,
			'AUDIT-FE-HOME-001: la clase .pv-home del scope debe seguir presente (la lee ltms-plaza-viva.js)'
		);

		// (2) Search input presente.
		$this->assertStringContainsString(
			'pv-home-search',
			$src,
			'AUDIT-FE-HOME-001: el input de búsqueda #pv-home-search debe seguir presente'
		);

		// (3) Chips de búsqueda presentes.
		$this->assertStringContainsString(
			'data-pv-search-chip',
			$src,
			'AUDIT-FE-HOME-001: los chips data-pv-search-chip deben seguir presentes (el handler global del design system los lee)'
		);

		// (4) Header del scope presente.
		$this->assertStringContainsString(
			'pv-home-header',
			$src,
			'AUDIT-FE-HOME-001: la clase .pv-home-header debe seguir presente aunque su shadow handler fue eliminado'
		);
	}

	/**
	 * Invariante: home.php NO contiene inline event handlers peligrosos
	 * (CSP estricto). Es el mismo invariant que CSP-compliance pero para
	 * atributos inline distintos a <script>.
	 */
	public function test_003_home_php_no_event_handlers_inline(): void {
		$this->assertFileExists( $this->home_path );
		$src = file_get_contents( $this->home_path );
		$src_without_comments = $this->strip_php_comments( $src );

		$this->assertStringNotContainsString(
			'onsubmit=',
			$src_without_comments,
			'AUDIT-FE-HOME-001: home.php no debe tener onsubmit= inline (CSP violation)'
		);
		$this->assertStringNotContainsString(
			'onload=',
			$src_without_comments,
			'AUDIT-FE-HOME-001: home.php no debe tener onload= inline (CSP violation)'
		);
	}

	// ── AUDIT-FE-SP-001: single-product.php sin <script> inline ─────────

	/**
	 * AUDIT-FE-SP-001 (P0, script-tag inline con 3 behaviours): el bloque
	 * <script> de single-product.php (159 líneas, líneas 890-1048 originales)
	 * con sticky-nav + bundle recompute + bundle ATC fue ELIMINADO físicamente
	 * y MIGRADO al scope PRODUCT de ltms-plaza-viva.js.
	 *
	 * Regresión: si alguien reintroduce un <script> en single-product.php,
	 * este test falla inmediatamente (CSP violation en producción).
	 */
	public function test_004_product_php_sin_scripts_inline(): void {
		$this->assertFileExists( $this->product_path );
		$src = file_get_contents( $this->product_path );
		$src_without_comments = $this->strip_php_comments( $src );

		// (1) single-product.php NO contiene <script>.
		$this->assertStringNotContainsString(
			'<script>',
			$src_without_comments,
			'AUDIT-FE-SP-001: single-product.php no debe contener bloques <script> inline (CSP-compliance Plaza Viva)'
		);

		// (2) single-product.php NO contiene </script>.
		$this->assertStringNotContainsString(
			'</script>',
			$src_without_comments,
			'AUDIT-FE-SP-001: single-product.php no debe contener </script>'
		);

		// (3) La traza del fix SP-001 está presente.
		$this->assertStringContainsString(
			'AUDIT-FE-SP-001',
			$src,
			'AUDIT-FE-SP-001: la traza del fix debe estar presente en el comment block del template'
		);
	}

	/**
	 * AUDIT-FE-SP-002 (P1, config de moneda inyectada inline): la variable
	 * `window.ltms_pv_currency` era inyectada via `<?php echo wp_json_encode
	 * ($pv_currency); ?>` DENTRO del script-tag inline. AHORA se expone
	 * via wp_localize_script (ver test_009). El source de single-product.php
	 * debe estar LIMPIO de la inyección inline.
	 *
	 * Regresión: si alguien reintroduce `window.ltms_pv_currency` o la
	 * variable `$pv_currency` en single-product.php (sin contar mentions
	 * en el comment block del fix), este test falla.
	 */
	public function test_005_product_php_sin_inyeccion_currency_inline(): void {
		$this->assertFileExists( $this->product_path );
		$src = file_get_contents( $this->product_path );
		$src_without_comments = $this->strip_php_comments( $src );

		// (1) NO debe haber `window.ltms_pv_currency` (era la asignación JS
		// global dentro del script-tag inline). El comment del fix lo
		// menciona textualmente como doc — strips comments primero.
		$this->assertStringNotContainsString(
			'window.ltms_pv_currency',
			$src_without_comments,
			'AUDIT-FE-SP-002: window.ltms_pv_currency fue eliminada del source (se expone via wp_localize_script, no inline)'
		);

		// (2) NO debe haber `$pv_currency = array(` (era la variable PHP
		// que se construye para inyectarse inline). La currency ahora se
		// construye DENTRO del array de wp_localize_script en
		// class-ltms-native-templates.php, no en el template.
		$this->assertStringNotContainsString(
			'$pv_currency = array(',
			$src_without_comments,
			'AUDIT-FE-SP-002: $pv_currency = array() fue eliminada del template (se construye en wp_localize_script)'
		);

		// (3) La traza del fix SP-002 está presente.
		$this->assertStringContainsString(
			'AUDIT-FE-SP-002',
			$src,
			'AUDIT-FE-SP-002: la traza del fix debe estar presente en el comment block del template'
		);
	}

	/**
	 * Invariante: single-product.php NO contiene el descuento bundle
	 * inyectado via PHP dentro de JS (era `var discountPct = ...`).
	 * El descuento ahora se lee del data-attr `data-pv-bundle-discount` del
	 * <section class="pv-bundle"> (ver test_008).
	 */
	public function test_006_product_php_sin_descuento_inyectado_en_js(): void {
		$this->assertFileExists( $this->product_path );
		$src = file_get_contents( $this->product_path );
		$src_without_comments = $this->strip_php_comments( $src );

		// (1) NO debe haber la inyección PHP del descuento dentro del JS
		// inline (era `var discountPct = ...` con echo (int) $bundle_discount).
		$this->assertStringNotContainsString(
			'var discountPct = <?php echo (int) $bundle_discount; ?>;',
			$src_without_comments,
			'AUDIT-FE-SP-001: el descuento bundle no debe inyectarse via PHP dentro del JS (se lee del data-attr data-pv-bundle-discount)'
		);

		// (2) El descuento SI debe USAGEarse en PHP para el HTML visible
		// (texto "Compra 2 o más y ahorra %d%%" + el attr del <section>).
		$this->assertStringContainsString(
			'$bundle_discount',
			$src,
			'AUDIT-FE-SP-001: $bundle_discount sigue en uso en el template para el texto visible y el data-attr (no se eliminó la variable PHP)'
		);
	}

	/**
	 * Invariante: single-product.php NO contiene event handlers inline
	 * peligrosos (CSP estricto).
	 */
	public function test_007_product_php_no_event_handlers_inline(): void {
		$this->assertFileExists( $this->product_path );
		$src = file_get_contents( $this->product_path );
		$src_without_comments = $this->strip_php_comments( $src );

		$this->assertStringNotContainsString( 'onsubmit=', $src_without_comments, 'AUDIT-FE-SP-001: single-product.php no debe tener onsubmit= inline' );
		$this->assertStringNotContainsString( 'onload=', $src_without_comments, 'AUDIT-FE-SP-001: single-product.php no debe tener onload= inline' );
	}

	// ── AUDIT-FE-SP-001: data-attr data-pv-bundle-discount en el HTML ───

	/**
	 * Invariante: el descuento bundle ahora se lee del data-attr del
	 * <section class="pv-bundle"> en vez de PHP inyectado en el JS
	 * inline. El scope PRODUCT (ltms-plaza-viva.js) lee este attr con
	 * `bundle.getAttribute('data-pv-bundle-discount')`.
	 */
	public function test_008_product_php_data_attr_bundle_discount(): void {
		$this->assertFileExists( $this->product_path );
		$src = file_get_contents( $this->product_path );

		// (1) El <section class="pv-bundle"> debe tener el data-attr.
		$this->assertMatchesRegularExpression(
			'/<section\s+class="pv-bundle"[^>]*data-pv-bundle-discount="[^"]+"/',
			$src,
			'AUDIT-FE-SP-001: el <section class="pv-bundle"> debe tener el atributo data-pv-bundle-discount (leído por el scope PRODUCT)'
		);

		// (2) Y debe ser el valor de $bundle_discount cast a int.
		$this->assertStringContainsString(
			'data-pv-bundle-discount="<?php echo esc_attr( (int) $bundle_discount ); ?>"',
			$src,
			'AUDIT-FE-SP-001: el data-attr debe contener <?php echo esc_attr( (int) $bundle_discount ); ?> como valor'
		);

		// (3) Los data-attrs de items/total/save/add siguen presentes.
		foreach ( [ 'data-pv-bundle-item', 'data-pv-bundle-price', 'data-pv-bundle-total', 'data-pv-bundle-save', 'data-pv-bundle-add', 'data-pv-bundle-id' ] as $attr ) {
			$this->assertStringContainsString(
				$attr,
				$src,
				"AUDIT-FE-SP-001: el data-attr $attr debe seguir presente en el HTML del bundle (lo lee el scope PRODUCT)"
			);
		}
	}

	// ── AUDIT-FE-SP-002: pv_currency en wp_localize_script ──────────────

	/**
	 * AUDIT-FE-SP-002 (P1): la currency config ahora se expone via
	 * wp_localize_script('ltms-plaza-viva', 'ltms_data', ...) en
	 * class-ltms-native-templates.php. Antes se inyectaba inline en
	 * single-product.php. Este test valida que la declaración está
	 * presente y bien formada (con las mismas keys que antes tenía el
	 * array `$pv_currency`).
	 */
	public function test_009_pv_currency_declarado_en_wp_localize_script(): void {
		$this->assertFileExists( $this->native_templates_path );
		$src = file_get_contents( $this->native_templates_path );

		// (1) La key `pv_currency` está presente en el array localize.
		$this->assertStringContainsString(
			"'pv_currency' =>",
			$src,
			'AUDIT-FE-SP-002: pv_currency debe estar declarado en el array wp_localize_script'
		);

		// (2) Las 5 keys esperadas del WC currency config están declaradas.
		foreach ( [ 'symbol', 'decimal', 'thousand', 'decimals', 'position' ] as $key ) {
			$this->assertStringContainsString(
				"'" . $key . "'",
				$src,
				"AUDIT-FE-SP-002: la key '$key' del pv_currency debe estar en el array wp_localize_script"
			);
		}

		// (3) La traza del fix SP-002 está presente.
		$this->assertStringContainsString(
			'AUDIT-FE-SP-002',
			$src,
			'AUDIT-FE-SP-002: la traza del fix debe estar presente en class-ltms-native-templates.php'
		);

		// (4) Las funciones WC usadas para hidratar pv_currency están
		// presente (sanity check de no degenerado a hardcoded).
		$this->assertStringContainsString( 'get_woocommerce_currency_symbol', $src, 'AUDIT-FE-SP-002: get_woocommerce_currency_symbol() debería usarse para hidratar pv_currency' );
		$this->assertStringContainsString( 'get_woocommerce_price_format', $src, 'AUDIT-FE-SP-002: get_woocommerce_price_format() debería usarse para hidratar pv_currency' );
	}

	// ── AUDIT-FE-SP-002: PV.config.pvCurrency en el JS ───────────────────

	/**
	 * AUDIT-FE-SP-002 (continuación): el init de PV.config en
	 * ltms-plaza-viva.js mapea `window.ltms_data.pv_currency` ->
	 * `PV.config.pvCurrency`. Sin este mapeo, el scope PRODUCT recibiría
	 * objeto pojo-default vacío y el formatMoney caería a defaults
	 * hardcodedos (símbolo '$', 2 decimales, sep ',') rompiendo moneda
	 * MXN o config distinta.
	 */
	public function test_010_pv_config_pvCurrency_mapping_en_js(): void {
		$this->assertFileExists( $this->pv_js_path );
		$js = file_get_contents( $this->pv_js_path );

		// (1) El mapeo explícito está presente en el init de PV.config.
		$this->assertStringContainsString(
			'pvCurrency: (window.ltms_data && window.ltms_data.pv_currency)',
			$js,
			'AUDIT-FE-SP-002: PV.config.pvCurrency debe mapearse desde window.ltms_data.pv_currency en el init de PV.config'
		);

		// (2) El scope PRODUCT lee PV.config.pvCurrency (no window.ltms_pv_currency).
		$this->assertStringContainsString(
			'PV.config && PV.config.pvCurrency',
			$js,
			'AUDIT-FE-SP-002: el scope PRODUCT debe leer PV.config.pvCurrency (no window.ltms_pv_currency inline)'
		);

		// (3) Ya NO debe haber referencias a window.ltms_pv_currency en el JS.
		$this->assertStringNotContainsString(
			'window.ltms_pv_currency',
			$js,
			'AUDIT-FE-SP-002: window.ltms_pv_currency debe ser eliminada del JS (reemplazada por PV.config.pvCurrency)'
		);
	}

	// ── AUDIT-FE-SP-001: scope PRODUCT en ltms-plaza-viva.js ────────────

	/**
	 * AUDIT-FE-SP-001 (P0, migración al design system): los 3 behaviours
	 * del script-tag inline (sticky-nav + bundle recompute + bundle ATC)
	 * ahora viven en el scope PRODUCT de ltms-plaza-viva.js. La migración
	 * fue completa (no parcial).
	 *
	 * Regresión: si alguien quita el scope PRODUCT del JS, este test falla
	 * (los behaviours quedan huerfanos sin handler).
	 */
	public function test_011_scope_product_en_design_system_js(): void {
		$this->assertFileExists( $this->pv_js_path );
		$js = file_get_contents( $this->pv_js_path );

		// (1) El IIFE `productScope` está presente.
		$this->assertStringContainsString(
			'productScope',
			$js,
			'AUDIT-FE-SP-001: el scope PRODUCT (function productScope() / IIFE) debe estar presente en ltms-plaza-viva.js'
		);

		// (2) Selector del scope .pv-scope.pv-product-page presente.
		$this->assertStringContainsString(
			".pv-scope.pv-product-page",
			$js,
			'AUDIT-FE-SP-001: el selector .pv-scope.pv-product-page debe estar presente (lo lee el scope PRODUCT)'
		);

		// (3) Sticky-nav con IntersectionObserver migrado.
		$this->assertStringContainsString(
			'IntersectionObserver',
			$js,
			'AUDIT-FE-SP-001: el sticky-nav con IntersectionObserver debe estar migrado al scope PRODUCT'
		);
		$this->assertStringContainsString(
			'is-active',
			$js,
			'AUDIT-FE-SP-001: la clase is-active para el sticky-nav resaltado debe estar migrada'
		);

		// (4) Sticky-nav smooth scroll: preventDefault + scrollIntoView.
		$this->assertStringContainsString( 'e.preventDefault();', $js, 'AUDIT-FE-SP-001: e.preventDefault() debe estar migrado (smooth scroll sin navegar)' );
		$this->assertStringContainsString( 'scrollIntoView', $js, 'AUDIT-FE-SP-001: scrollIntoView() debe estar migrado (smooth scroll al click de ancla)' );

		// (5) Bundle recompute migrado (formatMoney + data-pv-bundle-*).
		$this->assertStringContainsString( 'function formatMoney', $js, 'AUDIT-FE-SP-001: formatMoney() debe estar migrada al scope PRODUCT (era parte del script inline)' );
		$this->assertStringContainsString( "data-pv-bundle-discount", $js, 'AUDIT-FE-SP-001: el scope PRODUCT debe leer data-pv-bundle-discount (descuento del bundle)' );
		$this->assertStringContainsString( "data-pv-bundle-total", $js, 'AUDIT-FE-SP-001: el scope PRODUCT debe leer data-pv-bundle-total (total del bundle)' );
		$this->assertStringContainsString( "data-pv-bundle-save", $js, 'AUDIT-FE-SP-001: el scope PRODUCT debe leer data-pv-bundle-save (ahorro del bundle)' );
		$this->assertStringContainsString( "data-pv-bundle-price", $js, 'AUDIT-FE-SP-001: el scope PRODUCT debe leer data-pv-bundle-price (precio por item)' );

		// (6) Bundle ATC via PV.ajax (NO fetch manual con URLSearchParams
		// encadenando action='ltms_plaza_viva_add_to_cart'). El helper
		// PV.ajax (líneas 562+) sí usa `new URLSearchParams()` internamente
		// para todos los AJAX del design system — NO se debe asserts de
		// no contención global de URLSearchParams porque fallaría en falso
		// positivo. Lo que el inline original hacía era el patron
		// `body.append('action','ltms_plaza_viva_add_to_cart'); ... fetch(ajaxUrl)`
		// OUTSIDE de PV.ajax — detectar ese patron especifico (no el helper).
		$this->assertStringNotContainsString(
			"body.append('action','ltms_plaza_viva_add_to_cart')",
			$js,
			'AUDIT-FE-SP-001: el fetch manual con body.append action=ltms_plaza_viva_add_to_cart fue eliminado (reemplazado por PV.ajax)'
		);

		// (7) Toast de éxito via PV.toast + i18n addedToCart.
		$this->assertStringContainsString( 'PV.toast', $js, 'AUDIT-FE-SP-001: el toast de éxito del bundle ATC debe usar PV.toast (no alert())' );
		$this->assertStringContainsString( 'addedToCart', $js, 'AUDIT-FE-SP-001: el toast de éxito debe leer el string i18n addedToCart' );

		// (8) PV.Shopping.refresh() preservado (refresca el contador del carrito).
		$this->assertStringContainsString( 'PV.Shopping', $js, 'AUDIT-FE-SP-001: PV.Shopping.refresh() debe estar migrado (refresca el contador del carrito)' );

		// (9) La traza del fix SP-001 está presente en el JS.
		$this->assertStringContainsString( 'AUDIT-FE-SP-001 FIX', $js, 'AUDIT-FE-SP-001: la traza AUDIT-FE-SP-001 FIX debe estar en el comentario del scope PRODUCT' );
	}

	// ── AUDIT-FE-HOME-001: scope HOME en ltms-plaza-viva.js ──────────────

	/**
	 * Invariante: el scope HOME está presente en ltms-plaza-viva.js como
	 * válvula de extensión IIFE (no migró behaviours pero el IIFE
	 * preserva la paridad arquitectural con los otros scopes del design
	 * system: CHECKOUT, CART, HELP, PRODUCT).
	 */
	public function test_012_scope_home_en_design_system_js(): void {
		$this->assertFileExists( $this->pv_js_path );
		$js = file_get_contents( $this->pv_js_path );

		// (1) El IIFE `homeScope` está presente.
		$this->assertStringContainsString(
			'homeScope',
			$js,
			'AUDIT-FE-HOME-001: el scope HOME (function homeScope() / IIFE) debe estar presente como válvula de extensión'
		);

		// (2) Selector del scope .pv-scope.pv-home presente.
		$this->assertStringContainsString(
			".pv-scope.pv-home",
			$js,
			'AUDIT-FE-HOME-001: el selector .pv-scope.pv-home debe estar presente (lo lee el scope HOME)'
		);

		// (3) La traza del fix HOME-001 está presente en el JS.
		$this->assertStringContainsString(
			'AUDIT-FE-HOME-001 FIX',
			$js,
			'AUDIT-FE-HOME-001: la traza AUDIT-FE-HOME-001 FIX debe estar en el comentario del scope HOME'
		);

		// (4) NO debe haber fetch manual en el scope HOME (no migró behaviours
		// peligrosos que lo requirieran — los 2 behaviours eran código muerto).
		// Just verification: el scope HOME es deliberadamente minimalista.
		$this->assertStringNotContainsString(
			'window.ltms_pv_currency',
			$js,
			'AUDIT-FE-HOME-001: el scope HOME no debe usar window.ltms_pv_currency (era inyección inline eliminada)'
		);
	}

	// ── Sincronización .min.js con scopes HOME + PRODUCT ─────────────────

	/**
	 * Invariante: ltms-plaza-viva.min.js contiene los identificadores
	 * críticos del scope PRODUCT (pvCurrency + data-pv-bundle-discount).
	 * SiteGround SG Optimizer carga el .min.js en producción — no basta
	 * con que el .js source tenga el scope. Mismo patrón AUDIT-FE-HC-007
	 * (Fase 1.9) y CI-LINT-MIN-001.
	 *
	 * Nota: los nombres `productScope`/`homeScope` son IIFE-internal y
	 * terser los renombra/elimina (no sobreviven al mangle). Los asserts
	 * se hacen sobre identificadores SOBREVIVIENTES: `pvCurrency` (const
	 * de PV.config), `data-pv-bundle-discount` (string literal),
	 * `ltms_plaza_viva_add_to_cart` (action name en PV.ajax).
	 */
	public function test_013_min_js_sincronizado_con_scope_product(): void {
		$this->assertFileExists( $this->pv_min_js_path );
		$min = file_get_contents( $this->pv_min_js_path );

		// (1) pvCurrency está en el .min.js (mapeo PV.config.pvCurrency).
		$this->assertStringContainsString(
			'pvCurrency',
			$min,
			'AUDIT-FE-SP-002 + CI-LINT-MIN-001: el .min.js debe contener pvCurrency (mapeo PV.config.pvCurrency)'
		);

		// (2) data-pv-bundle-discount está en el .min.js (lo lee el scope PRODUCT).
		$this->assertStringContainsString(
			'data-pv-bundle-discount',
			$min,
			'AUDIT-FE-SP-001 + CI-LINT-MIN-001: el .min.js debe contener data-pv-bundle-discount (leído por el scope PRODUCT)'
		);

		// (3) La action ltms_plaza_viva_add_to_cart está en el .min.js (PV.ajax call).
		$this->assertStringContainsString(
			'ltms_plaza_viva_add_to_cart',
			$min,
			'AUDIT-FE-SP-001 + CI-LINT-MIN-001: el .min.js debe contener la action ltms_plaza_viva_add_to_cart (bundle ATC via PV.ajax)'
		);

		// (4) Sanity: el .min.js NO debe contener window.ltms_pv_currency
		// (era la inyección inline eliminada del script-tag).
		$this->assertStringNotContainsString(
			'window.ltms_pv_currency',
			$min,
			'AUDIT-FE-SP-002 + CI-LINT-MIN-001: el .min.js no debe contener window.ltms_pv_currency (inyección inline eliminada)'
		);
	}
}
