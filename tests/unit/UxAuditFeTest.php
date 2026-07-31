<?php
/**
 * Tests estructurales del ciclo UX-AUDIT-FE v2.9.306 (design tokens PV).
 *
 * Foco: eliminación de `style="..."` inline en plantillas públicas del
 * design system Plaza Viva, migrando a clases reusables definidas en
 * ltms-plaza-viva.css (tokens PV) y ltms-frontend-extensions.css.
 *
 * Hallazgos cubiertos (todos P0 — incumplimiento de design system):
 *   - UX-AUDIT-FE-P0-01: class-ltms-trust-badges.php + single-product.php
 *     con `style="..."` inline en mini-badges, info cards (4 variantes).
 *   - UX-AUDIT-FE-P0-02: checkout.php con `style="..."` inline en legal
 *     block + checkbox + empty-state (8 ocurrencias).
 *   - UX-AUDIT-FE-P0-03: cart.php + checkout.php con `.pv-btn--brand`
 *     duplicado scoped usando `#E80001` hardcodeado (2 hojas CSS).
 *   - UX-AUDIT-FE-P0-04: 6 bloques de fallback en 5 plantillas con
 *     `style="padding:60px 22px;text-align:center"` inline.
 *   - UX-AUDIT-FE-P0-05: criterio Star Seller divergente entre 3
 *     plantillas (single-product usaba umbral sales>=50 vs home + vendor-store
 *     que usan flag ltms_star_seller=1). Unificado en LTMS_Trust_Badges::is_star_seller().
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts sobre el
 * source PHP/CSS): NO cargan clases del plugin ni invocan WP → deterministas
 * en LTMS_UNIT_ONLY=true y CI Ubuntu sin depender del classmap estático de
 * Composer (mismo patrón que HelpCenterAuditTest, DbMigrationsAuditTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class UxAuditFeTest
 *
 * Verifica los 5 hallazgos UX-AUDIT-FE v2.9.306 sobre el design system
 * Plaza Viva. Detecta regresiones si alguien reintroduce `style="..."` inline
 * o hardcodea `#E80001` / colores Tailwind en plantillas públicas o diverge
 * el criterio Star Seller entre vistas.
 */
final class UxAuditFeTest extends LTMS_Unit_Test_Case {

	/**
	 * Rutas absolutas a los archivos auditados.
	 */
	private string $trust_badges_path;
	private string $single_product_path;
	private string $cart_path;
	private string $checkout_path;
	private string $home_path;
	private string $order_tracking_path;
	private string $vendor_store_path;
	private string $pv_css_path;
	private string $fe_ext_css_path;
	private string $cart_css_path;
	private string $checkout_css_path;

	/**
	 * @inheritDoc
	 *
	 * NOTA INTENCIONAL: este test NO llama $this->require_class(). Los
	 * tests son puramente de filesystem (file_get_contents + asserts),
	 * por lo que NO dependen del classmap estático de Composer (mismo
	 * patrón que HelpCenterAuditTest, DbMigrationsAuditTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		$root                         = dirname( __DIR__, 2 );
		$this->trust_badges_path      = $root . '/includes/frontend/class-ltms-trust-badges.php';
		$this->single_product_path    = $root . '/includes/frontend/templates/single-product.php';
		$this->cart_path              = $root . '/includes/frontend/templates/cart.php';
		$this->checkout_path          = $root . '/includes/frontend/templates/checkout.php';
		$this->home_path              = $root . '/includes/frontend/templates/home.php';
		$this->order_tracking_path    = $root . '/includes/frontend/templates/order-tracking.php';
		$this->vendor_store_path     = $root . '/includes/frontend/templates/vendor-store.php';
		$this->pv_css_path            = $root . '/assets/css/ltms-plaza-viva.css';
		$this->fe_ext_css_path        = $root . '/assets/css/ltms-frontend-extensions.css';
		$this->cart_css_path          = $root . '/assets/css/ltms-checkout.css';
		$this->checkout_css_path      = $root . '/assets/css/ltms-checkout.css';
	}

	/**
	 * Strip PHP comments (`/* * /` y `//`) del source. Mismo truco que
	 * DbMigrationsAuditTest — el propio comment del fix puede mencionar
	 * textualmente el substring que el test busca como evidencia del bug,
	 * NO debe contar como código vivo (LECCIONES_APRENDIDAS #141). Para CSS
	 * se usan los delimitadores `/* * /` exclusivamente (CSS no tiene `//`).
	 */
	private function strip_php_comments( string $src ): string {
		$no_block = preg_replace( '/\/\*.*?\*\//s', '', $src );
		return preg_replace( '#(^|[^\:])//(?!\:).*?$#m', '$1', $no_block );
	}

	private function strip_css_comments( string $src ): string {
		return preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/* =====================================================================
	 * UX-AUDIT-FE-P0-01 — mini-badges + info cards (trust-badges + single-product)
	 * ===================================================================== */

	/**
	 * P0-01: class-ltms-trust-badges.php NO contiene `style="..."` inline.
	 * Antes: 3 bloques `<div/style="margin:16px 0;..."|style="margin-top:12px;...|font-size:12px;color:#6b7280;"|style="font-size:11px;color:#2563eb;margin:2px 0;">`.
	 * Fix: migrado a classes .ltms-trust-badges / .ltms-mini-badges / .ltms-mini-badge / .ltms-loop-vendor-badge (ltms-frontend-extensions.css líneas 1194+).
	 */
	public function test_01a_trust_badges_no_inline_styles(): void {
		$this->assertFileExists( $this->trust_badges_path );
		$src = $this->strip_php_comments( file_get_contents( $this->trust_badges_path ) );
		$this->assertStringNotContainsString( 'style="', $src, 'UX-AUDIT-FE-P0-01: trust-badges.php no debe contener style="..." inline. Usar clases del design system.' );
		$this->assertStringNotContainsString( "style='", $src, 'UX-AUDIT-FE-P0-01: trust-badges.php no debe contener style=\'...\' inline.' );
	}

	/**
	 * P0-01: las nuevas clases estructurales existen en ltms-frontend-extensions.css.
	 */
	public function test_01b_trust_badges_css_classes_defined(): void {
		$this->assertFileExists( $this->fe_ext_css_path );
		$css = file_get_contents( $this->fe_ext_css_path );
		$this->assertStringContainsString( '.ltms-mini-badges', $css );
		$this->assertStringContainsString( '.ltms-mini-badge', $css );
		$this->assertStringContainsString( '.ltms-mini-badge--accent', $css );
		$this->assertStringContainsString( '.ltms-mini-badge--primary', $css );
		$this->assertStringContainsString( '.ltms-mini-badge__dot', $css );
		$this->assertStringContainsString( '.ltms-loop-vendor-badge', $css );
		// Trazas del fix.
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-01', $css );
	}

	/**
	 * P0-01: single-product.php NO contiene los 4 bloques de info card
	 * con style Tailwind (#f3f4f6, #f0fdf4, #fff7ed, #f9fafb) hardcodeado.
	 * Antes: `<div class="pv-product-type-badge" style="background:#f3f4f6;...">`, etc.
	 * Fix: migrado a 4 variantes de `.pv-info-card` (--neutral, --success, --warning, --info).
	 */
	public function test_01c_single_product_no_tailwind_inline_colors(): void {
		$this->assertFileExists( $this->single_product_path );
		$src = $this->strip_php_comments( file_get_contents( $this->single_product_path ) );
		$this->assertStringNotContainsString( 'style="background:#f3f4f6', $src, 'UX-AUDIT-FE-P0-01: single-product.php no debe tener background:#f3f4f6 inline.' );
		$this->assertStringNotContainsString( 'style="background:#f0fdf4', $src );
		$this->assertStringNotContainsString( 'style="background:#fff7ed', $src );
		$this->assertStringNotContainsString( 'style="background:#f9fafb', $src );
	}

	/**
	 * P0-01: single-product.php usa las nuevas clases .pv-info-card con sus variantes.
	 */
	public function test_01d_single_product_uses_pv_info_card_classes(): void {
		$src = file_get_contents( $this->single_product_path );
		$this->assertStringContainsString( 'pv-info-card', $src );
		$this->assertStringContainsString( 'pv-info-card--success', $src );
		$this->assertStringContainsString( 'pv-info-card--warning', $src );
		$this->assertStringContainsString( 'pv-info-card--neutral', $src );
		$this->assertStringContainsString( 'pv-info-card__title', $src );
		$this->assertStringContainsString( 'pv-info-card__body', $src );
		$this->assertStringContainsString( 'pv-info-card__list', $src );
	}

	/**
	 * P0-01: ltms-plaza-viva.css define las 4 variantes de .pv-info-card.
	 */
	public function test_01e_plaza_viva_css_defines_pv_info_card_variants(): void {
		$css = file_get_contents( $this->pv_css_path );
		$this->assertStringContainsString( '.pv-info-card{', $css );
		$this->assertStringContainsString( '.pv-info-card__title', $css );
		$this->assertStringContainsString( '.pv-info-card__body', $css );
		$this->assertStringContainsString( '.pv-info-card--neutral', $css );
		$this->assertStringContainsString( '.pv-info-card--success', $css );
		$this->assertStringContainsString( '.pv-info-card--warning', $css );
		$this->assertStringContainsString( '.pv-info-card--info', $css );
		$this->assertStringContainsString( '.pv-info-card--inline', $css );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-01', $css );
	}

	/* =====================================================================
	 * UX-AUDIT-FE-P0-02 — legal block + checkbox + empty state (checkout)
	 * ===================================================================== */

	/**
	 * P0-02: checkout.php NO contiene los 8 `style="..."` inline del legal block
	 * (#f9fafb, #e5e7eb, #374151, #E80001) y del empty state (#1A1F2E, #565C66).
	 * Fix: migrado a .pv-legal-block / .pv-checkbox / .pv-empty-state__*.
	 */
	public function test_02a_checkout_no_legal_inline_styles(): void {
		$this->assertFileExists( $this->checkout_path );
		$src = $this->strip_php_comments( file_get_contents( $this->checkout_path ) );
		$this->assertStringNotContainsString( 'style="padding:16px;background:#f9fafb', $src, 'UX-AUDIT-FE-P0-02: checkout.php no debe tener el estilo inline del legal block.' );
		$this->assertStringNotContainsString( 'style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;', $src, 'UX-AUDIT-FE-P0-02: checkout.php no debe tener los estilos inline de los checkbox labels.' );
		$this->assertStringNotContainsString( 'style="width:18px;height:18px;accent-color:#E80001', $src );
		$this->assertStringNotContainsString( 'style="font-size:14px;color:#374151', $src );
		// Empty state: anteriormente inline con #1A1F2E / #565C66 hardcodeado.
		$this->assertStringNotContainsString( 'style="margin:0 0 6px;font-weight:600;color:#1A1F2E', $src );
		$this->assertStringNotContainsString( 'style="margin:0;color:#565C66', $src );
	}

	/**
	 * P0-02: checkout.php usa las nuevas clases reusables del legal + empty state.
	 */
	public function test_02b_checkout_uses_pv_classes(): void {
		$src = file_get_contents( $this->checkout_path );
		$this->assertStringContainsString( 'pv-legal-block', $src );
		$this->assertStringContainsString( 'pv-checkbox', $src );
		$this->assertStringContainsString( 'pv-checkbox__label', $src );
		$this->assertStringContainsString( 'pv-empty-state__title', $src );
		$this->assertStringContainsString( 'pv-empty-state__sub', $src );
	}

	/**
	 * P0-02: ltms-plaza-viva.css define .pv-legal-block / .pv-checkbox / .pv-empty-state__*.
	 */
	public function test_02c_plaza_viva_css_defines_pv_legal_and_empty_state(): void {
		$css = file_get_contents( $this->pv_css_path );
		$this->assertStringContainsString( '.pv-legal-block{', $css );
		$this->assertStringContainsString( '.pv-checkbox{', $css );
		$this->assertStringContainsString( '.pv-checkbox input[type="checkbox"]', $css );
		$this->assertStringContainsString( '.pv-checkbox__label', $css );
		$this->assertStringContainsString( '.pv-empty-state__title', $css );
		$this->assertStringContainsString( '.pv-empty-state__sub', $css );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-02', $css );
	}

	/* =====================================================================
	 * UX-AUDIT-FE-P0-03 — .pv-btn--brand consolidado en el design system
	 * ===================================================================== */

	/**
	 * P0-03: ltms-checkout.css NO debe tener la definición scoped de .pv-btn--brand.
	 * Antes: `.pv-scope.pv-cart .pv-btn--brand{background:#E80001;...}` y
	 * `.pv-scope.pv-checkout .pv-btn--brand{background:#E80001;...}` duplicados.
	 * Fix: eliminadas; el selector global `.pv-btn--brand` en plaza-viva.css aplica.
	 *
	 * Nota: NO se valida que el archivo no contenga `#E80001` en absoluto — hay
	 * usos legítimos del color de marca fuera de scope (accent-color de checkboxes
	 * de validación, border-left de mensajes de error WC). El alcance P0-03 es
	 * exclusivamente la definición duplicada del botón brand.
	 */
	public function test_03a_checkout_css_no_scoped_brand_button(): void {
		$this->assertFileExists( $this->checkout_css_path );
		$css = $this->strip_css_comments( file_get_contents( $this->checkout_css_path ) );
		$this->assertStringNotContainsString( '.pv-cart .pv-btn--brand{background:#E80001', $css, 'UX-AUDIT-FE-P0-03: ltms-checkout.css no debe contener la definición scoped .pv-cart .pv-btn--brand con #E80001 hardcodeado.' );
		$this->assertStringNotContainsString( '.pv-checkout .pv-btn--brand{background:#E80001', $css, 'UX-AUDIT-FE-P0-03: ltms-checkout.css no debe contener la definición scoped .pv-checkout .pv-btn--brand con #E80001 hardcodeado.' );
		$this->assertStringNotContainsString( '.pv-scope.pv-cart .pv-btn--brand{', $css, 'UX-AUDIT-FE-P0-03: ltms-checkout.css no debe contener el selector scoped .pv-scope.pv-cart .pv-btn--brand{...} — debe usar el global.' );
		$this->assertStringNotContainsString( '.pv-scope.pv-checkout .pv-btn--brand{', $css );
	}

	/**
	 * P0-03: cart.php NO debe tener CSS scoped de .pv-btn--brand.
	 * (cart.php tiene su propio <style> embedded con la definición duplicada.)
	 */
	public function test_03b_cart_template_no_scoped_brand_button(): void {
		$this->assertFileExists( $this->cart_path );
		$src = $this->strip_php_comments( file_get_contents( $this->cart_path ) );
		$this->assertStringNotContainsString( '.pv-cart .pv-btn--brand{', $src, 'UX-AUDIT-FE-P0-03: cart.php no debe contener la definición scoped .pv-cart .pv-btn--brand{...}.' );
		$this->assertStringNotContainsString( 'background:#E80001', $src );
	}

	/**
	 * P0-03: ltms-plaza-viva.css define .pv-btn--brand global usando var(--brand).
	 */
	public function test_03c_plaza_viva_css_defines_global_brand_button(): void {
		$css = file_get_contents( $this->pv_css_path );
		$this->assertStringContainsString( '.pv-btn--brand{background:var(--brand)', $css );
		$this->assertStringContainsString( '.pv-btn--brand:hover', $css );
		$this->assertStringContainsString( '.pv-btn--brand:active', $css );
		$this->assertStringContainsString( '--brand:#E80001', $css, 'UX-AUDIT-FE-P0-03: el design token --brand debe estar definido con el color de marca #E80001.' );
		$this->assertStringContainsString( '--brand-600', $css );
	}

	/**
	 * P0-03: ltms-checkout.css contiene la traza del fix (eliminación del scoped dup).
	 */
	public function test_03d_checkout_css_has_fix_trace(): void {
		$css = file_get_contents( $this->checkout_css_path );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-03', $css );
	}

	/* =====================================================================
	 * UX-AUDIT-FE-P0-04 — fallback states en 5 plantillas públicas
	 * ===================================================================== */

	/**
	 * P0-04: ninguna de las 5 plantillas públicas con fallback debe tener
	 * `style="padding:60px 22px;text-align:center"` inline.
	 * Fix: migrado a `.pv-fallback__section` + subclases (plaza-viva.css).
	 */
	public function test_04a_no_inline_padding_fallback_styles(): void {
		$paths = array(
			$this->cart_path,
			$this->checkout_path,
			$this->home_path,
			$this->order_tracking_path,
			$this->vendor_store_path,
		);
		foreach ( $paths as $path ) {
			$this->assertFileExists( $path );
			$src = $this->strip_php_comments( file_get_contents( $path ) );
			$this->assertStringNotContainsString(
				'style="padding:60px 22px;text-align:center"',
				$src,
				'UX-AUDIT-FE-P0-04: ' . basename( $path ) . ' no debe tener style="padding:60px 22px;text-align:center" inline. Usar .pv-fallback__section.'
			);
		}
	}

	/**
	 * P0-04: las 5 plantillas usan la clase .pv-fallback__section en su fallback.
	 */
	public function test_04b_templates_use_pv_fallback_section(): void {
		$paths = array(
			$this->cart_path           => 'pv-cart',
			$this->checkout_path       => 'pv-checkout',
			$this->home_path           => 'pv-home',
			$this->order_tracking_path => 'pv-tracking',
			$this->vendor_store_path   => 'pv-vendor-store',
		);
		foreach ( $paths as $path => $scope ) {
			$src = file_get_contents( $path );
			$this->assertStringContainsString(
				'pv-fallback__section',
				$src,
				'UX-AUDIT-FE-P0-04: ' . basename( $path ) . ' debe usar .pv-fallback__section en el bloque de fallback.'
			);
		}
	}

	/**
	 * P0-04: vendor-store.php usa pv-fallback__title, pv-fallback__sub y
	 * pv-fallback__action en el fallback "Vendedor no encontrado" (no solo
	 * pv-fallback__msg como las páginas WC-disabled).
	 */
	public function test_04c_vendor_store_uses_fallback_title_sub_action(): void {
		$src = file_get_contents( $this->vendor_store_path );
		$this->assertStringContainsString( 'pv-fallback__title', $src, 'UX-AUDIT-FE-P0-04: vendor-store.php debe usar pv-fallback__title en el fallback "Vendedor no encontrado".' );
		$this->assertStringContainsString( 'pv-fallback__sub', $src );
		$this->assertStringContainsString( 'pv-fallback__action', $src );
		$this->assertStringContainsString( 'pv-fallback__msg', $src );
	}

	/**
	 * P0-04: ltms-plaza-viva.css define .pv-fallback__section + subclases.
	 */
	public function test_04d_plaza_viva_css_defines_pv_fallback(): void {
		$css = file_get_contents( $this->pv_css_path );
		$this->assertStringContainsString( '.pv-fallback__section{', $css );
		$this->assertStringContainsString( '.pv-fallback__title', $css );
		$this->assertStringContainsString( '.pv-fallback__sub', $css );
		$this->assertStringContainsString( '.pv-fallback__msg', $css );
		$this->assertStringContainsString( '.pv-fallback__action', $css );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-04', $css );
	}

	/* =====================================================================
	 * UX-AUDIT-FE-P0-05 — criterio Star Seller unificado
	 * ===================================================================== */

	/**
	 * P0-05: LTMS_Trust_Badges::is_star_seller() existe y usa el criterio
	 * canónico (ltms_kyc_status=approved AND ltms_star_seller=1).
	 */
	public function test_05a_is_star_seller_helper_exists_with_canonical_criteria(): void {
		$this->assertFileExists( $this->trust_badges_path );
		$src = file_get_contents( $this->trust_badges_path );
		// Método definido con signature typed int→bool.
		$this->assertStringContainsString( 'public static function is_star_seller( int $vendor_id ): bool', $src, 'UX-AUDIT-FE-P0-05: LTMS_Trust_Badges::is_star_seller() debe declararse con signature typed (int):bool.' );
		// Traits del criterio canónico: lee kyc_status y el flag ltms_star_seller.
		// NO debe usar umbral sales>=50 (que era el bug en single-product.php).
		// Validamos que el cuerpo consulte las 2 metas correctas.
		$body = substr( $src, strpos( $src, 'function is_star_seller' ) );
		$body = substr( $body, 0, strpos( $body, "\n}" ) + 2 );
		$this->assertStringContainsString( "'ltms_kyc_status'", $body );
		$this->assertStringContainsString( "'ltms_star_seller'", $body );
		$this->assertStringNotContainsString( '>= 50', $body, 'UX-AUDIT-FE-P0-05: is_star_seller() NO debe comparar contra umbral sales>=50 — ese es criterio de upgrade, no de display runtime.' );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-05', $src, 'UX-AUDIT-FE-P0-05: traza del fix debe estar presente en trust-badges.php.' );
	}

	/**
	 * P0-05: single-product.php NO recalcula el criterio con sales>=50.
	 * Antes: `$star_seller = ( $kyc_approved && $vendor_sales >= 50 );`
	 * Fix: delega a LTMS_Trust_Badges::is_star_seller() con fallback al criterio antiguo solo si la clase no existe (defensivo).
	 */
	public function test_05b_single_product_delegates_to_helper(): void {
		$src = file_get_contents( $this->single_product_path );
		$this->assertStringContainsString( 'LTMS_Trust_Badges::is_star_seller', $src, 'UX-AUDIT-FE-P0-05: single-product.php debe delegar a LTMS_Trust_Badges::is_star_seller().' );
		$this->assertStringNotContainsString( '$star_seller = ( $kyc_approved && $vendor_sales >= 50', $src, 'UX-AUDIT-FE-P0-05: single-product.php NO debe usar el criterio antiguo sales>=50 como única fuente.' );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-05', $src );
	}

	/**
	 * P0-05: vendor-store.php delega a is_star_seller() con fallback si la clase no está cargada.
	 */
	public function test_05c_vendor_store_delegates_to_helper(): void {
		$src = file_get_contents( $this->vendor_store_path );
		$this->assertStringContainsString( 'LTMS_Trust_Badges::is_star_seller', $src, 'UX-AUDIT-FE-P0-05: vendor-store.php debe delegar a LTMS_Trust_Badges::is_star_seller().' );
		// Fallback defensivo (no se quita el criterio manual en caso edge donde la clase no esté cargada).
		$this->assertStringContainsString( "ltms_star_seller', true ) === '1'", $src );
	}

	/**
	 * P0-05: home.php documenta paridad con el criterio canónico en el comment
	 * del query de Star Sellers (vitrina de vendedores destacados).
	 */
	public function test_05d_home_documents_canonical_criteria(): void {
		$src = file_get_contents( $this->home_path );
		$this->assertStringContainsString( 'UX-AUDIT-FE-P0-05', $src );
		$this->assertStringContainsString( 'is_star_seller', $src );
	}
}
