<?php
/**
 * GlobalCatbarTest — HOME-CATBAR-2026-004/006 (2026-10-15).
 *
 * Barra global de categorías (petición del operador: "una barra de
 * categorías para móvil y escritorio, como la de la storefront con logo,
 * en todas las páginas, que no se oculte mientras se desplaza" — 006:
 * fondo negro, sin logo, deslizable en escritorio).
 *
 * Valida ESTRUCTURALMENTE:
 *   1. El módulo existe, se inicializa desde el kernel y engancha wp_body_open.
 *   2. La barra es sticky (nunca se oculta al escrolear) y usa fondo NEGRO
 *      #000000 (HOME-CATBAR-2026-006, feedback del operador) SIN logo
 *      ("cada página ya tiene su logo, es redundante").
 *   3. Las categorías: TODAS las activas vía el helper normalizado, con la
 *      exclusión del artefacto 'no-aplica' y la categoría activa resaltada
 *      (is-active + aria-current) en los archivos de categoría.
 *   4. Exclusiones documentadas: checkout (funnel), panel del vendedor
 *      (dashboard/wallet/orders). HOME-CATBAR-2026-006: la home nativa se
 *      excluye del wp_body_open porque su template la renderiza INLINE
 *      dentro del header sticky (server-side, sin JS de reubicación).
 *   5. Assets: CSS (sticky + negro + fade + affordance de arrastre) y JS
 *      (reubicación bajo el header del tema + guard de la home + rueda →
 *      horizontal + drag + fade) encolados, con .min.js sincronizado.
 *   6. El topbar del storefront usa el logo OFICIAL (BRAND-2026-002) — el
 *      theme mod custom_logo era la bandera (inconsistencia #8 del brief).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeBrandingBriefTest).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class GlobalCatbarTest
 */
final class GlobalCatbarTest extends LTMS_Unit_Test_Case {

	private string $module_path;
	private string $kernel_path;
	private string $css_path;
	private string $js_path;
	private string $min_js_path;
	private string $storefront_path;

	protected function setUp(): void {
		parent::setUp();
		$this->module_path    = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-global-catbar.php';
		$this->kernel_path    = dirname( __DIR__, 2 ) . '/includes/core/class-ltms-kernel.php';
		$this->css_path       = dirname( __DIR__, 2 ) . '/assets/css/ltms-catbar.css';
		$this->js_path        = dirname( __DIR__, 2 ) . '/assets/js/ltms-catbar.js';
		$this->min_js_path    = dirname( __DIR__, 2 ) . '/assets/js/ltms-catbar.min.js';
		$this->storefront_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-vendor-storefront.php';
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
	 * HOME-CATBAR-2026-004-A: el módulo existe, el kernel lo inicializa y
	 * engancha wp_body_open (el render corre al abrir el body de toda
	 * plantilla estándar del tema).
	 */
	public function test_001_modulo_kernel_y_hook(): void {
		$this->assertFileExists( $this->module_path, 'HOME-CATBAR-2026-004-A: el módulo LTMS_Global_Catbar debe existir.' );

		$module = $this->strip_php_comments( file_get_contents( $this->module_path ) );
		$this->assertStringContainsString(
			"add_action( 'wp_body_open', [ __CLASS__, 'render_bar' ], 10, 0 );",
			$module,
			'HOME-CATBAR-2026-006b: el hook declara accepted_args=0 — do_action antepone un string vacio (dummy) como primer argumento a los callbacks con accepted_args>=1 y el default de $context NUNCA aplicaba (la barra se duplicaba en la home).'
		);
		$this->assertStringContainsString(
			"add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );",
			$module,
			'HOME-CATBAR-2026-004-A: los assets se encolan vía wp_enqueue_scripts.'
		);

		$kernel = $this->strip_php_comments( file_get_contents( $this->kernel_path ) );
		$this->assertStringContainsString(
			'class_exists( \'LTMS_Global_Catbar\' )',
			$kernel,
			'HOME-CATBAR-2026-004-A: el kernel debe inicializar el módulo (guard class_exists, patrón del resto de módulos).'
		);
		$this->assertMatchesRegularExpression(
			'/LTMS_Global_Catbar::init\(\);/',
			$kernel,
			'HOME-CATBAR-2026-004-A: el kernel llama LTMS_Global_Catbar::init().'
		);
	}

	/**
	 * HOME-CATBAR-2026-004-B → HOME-CATBAR-2026-006: exclusiones — la home
	 * nativa se excluye del wp_body_open porque su template la renderiza
	 * INLINE dentro del header sticky (server-side). Siguen excluidos:
	 * checkout/order-received (funnel) y panel del vendedor
	 * (dashboard/wallet/orders).
	 */
	public function test_002_exclusiones_documentadas(): void {
		$module = $this->strip_php_comments( file_get_contents( $this->module_path ) );

		// HOME-CATBAR-2026-006: la home solo se excluye del wp_body_open —
		// su template la renderiza inline dentro del header sticky.
		$this->assertMatchesRegularExpression(
			"/'wp_body_open' === \\\$context\s*\n?\s*&& get_option\( 'ltms_home_template_enabled', 'yes' \) === 'yes'/",
			$module,
			'HOME-CATBAR-2026-006: la home queda excluida del wp_body_open (su template la renderiza inline).'
		);
		$home = $this->strip_php_comments( file_get_contents( dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php' ) );
		$this->assertStringContainsString(
			"LTMS_Global_Catbar::render_bar( 'home_header' );",
			$home,
			'HOME-CATBAR-2026-006: home.php renderiza la barra INLINE dentro del header sticky (server-side, sin JS de reubicación).'
		);
		// HOME-CATBAR-2026-006b: normalización del contexto — cinturón y
		// tirantes frente al '' dummy de do_action (hallazgo live: la barra
		// se duplicaba en la home porque '' no matcheaba la exclusión).
		$this->assertStringContainsString(
			"'home_header' !== \$context",
			$module,
			'HOME-CATBAR-2026-006b: render_bar normaliza el contexto — todo valor != home_header se trata como wp_body_open.'
		);
		$this->assertStringContainsString(
			"function_exists( 'is_checkout' )",
			$module,
			'HOME-CATBAR-2026-004-B: el checkout queda excluido (protección del funnel de pago).'
		);
		$this->assertStringContainsString(
			"function_exists( 'is_order_received_page' ) && is_order_received_page()",
			$module,
			'HOME-CATBAR-2026-004-B: la página de orden recibida queda excluida (funnel).'
		);
		foreach ( array( 'ltms-dashboard', 'ltms-wallet', 'ltms-orders' ) as $page_key ) {
			$this->assertStringContainsString(
				"'{$page_key}'",
				$module,
				"HOME-CATBAR-2026-004-B: la página del panel '{$page_key}' queda excluida (app, no catálogo)."
			);
		}
	}

	/**
	 * HOME-CATBAR-2026-004-C → HOME-CATBAR-2026-006: la barra renderiza
	 * TODAS las categorías activas normalizadas con el artefacto
	 * 'no-aplica' excluido, marca la categoría activa (is-active +
	 * aria-current="page") y NO lleva logo ("cada página ya tiene su logo,
	 * es redundante" — feedback del operador).
	 */
	public function test_003_render_logo_categorias_y_estado_activo(): void {
		$module = $this->strip_php_comments( file_get_contents( $this->module_path ) );

		// HOME-CATBAR-2026-006: SIN logo — el blanco sigue vivo en el
		// header/footer de la home (BRAND-2026-001), no en la barra.
		$this->assertStringNotContainsString(
			'ltms-catbar__home',
			$module,
			'HOME-CATBAR-2026-006: la barra NO lleva logo (cada página ya tiene el suyo — decisión del operador).'
		);
		$this->assertStringNotContainsString(
			'logo-lo-tengo-white.png',
			$module,
			'HOME-CATBAR-2026-006: la barra no referencia el asset del logo blanco.'
		);
		// Fuente de categorías: helper normalizado + exclusión del artefacto.
		$this->assertStringContainsString(
			'LTMS_Utils::get_normalized_product_categories( true )',
			$module,
			'HOME-CATBAR-2026-004-C: la fuente es el helper normalizado (dedup + orden por # de productos), igual que la home.'
		);
		$this->assertStringContainsString(
			"'no-aplica' !== \$c->slug",
			$module,
			'HOME-CATBAR-2026-004-C: el artefacto de syncs "NO APLICA" queda excluido (HOME-MATRIX-FIX).'
		);
		// Categoría activa resaltada en los archivos de categoría.
		$this->assertStringContainsString(
			'is_product_category( $term_id )',
			$module,
			'HOME-CATBAR-2026-004-C: la categoría activa se detecta con is_product_category().'
		);
		$this->assertStringContainsString(
			"' is-active'",
			$module,
			'HOME-CATBAR-2026-004-C: la categoría activa lleva la clase is-active.'
		);
		$this->assertStringContainsString(
			'aria-current="page"',
			$module,
			'HOME-CATBAR-2026-004-C: la categoría activa declara aria-current="page" (accesibilidad).'
		);
	}

	/**
	 * HOME-CATBAR-2026-004-D → HOME-CATBAR-2026-006: el CSS de la barra —
	 * sticky top (nunca se oculta), fondo NEGRO (#000000, feedback del
	 * operador: contrasta con el header rojo de la home y el hero claro),
	 * fila deslizable con fade, estado activo dorado del brief y
	 * affordance de arrastre en escritorio.
	 */
	public function test_004_css_sticky_rojo_de_marca_y_fade(): void {
		$this->assertFileExists( $this->css_path, 'HOME-CATBAR-2026-004-D: el CSS de la barra debe existir.' );
		$css = file_get_contents( $this->css_path );

		$this->assertStringContainsString(
			'position:sticky;',
			$css,
			'HOME-CATBAR-2026-004-D: la barra es sticky — nunca se oculta mientras se desplaza (petición del operador).'
		);
		$this->assertStringContainsString(
			'background:#000000;',
			$css,
			'HOME-CATBAR-2026-006: la barra usa fondo NEGRO #000000 — contrasta con el header rojo (home) y el fondo claro de las páginas (feedback del operador).'
		);
		$this->assertStringNotContainsString(
			'ltms-catbar__home',
			$css,
			'HOME-CATBAR-2026-006: sin reglas de logo en el CSS de la barra (el logo fue eliminado).'
		);
		$this->assertStringContainsString(
			'overflow-x:auto;',
			$css,
			'HOME-CATBAR-2026-004-D: el track de categorías es deslizable (fila única).'
		);
		$this->assertStringContainsString(
			'data-ltms-scrollable',
			$css,
			'HOME-CATBAR-2026-004-D: el fade del borde derecho se activa con data-ltms-scrollable (patrón de la home).'
		);
		$this->assertStringContainsString(
			'var(--ltms-gold,#F7B731)',
			$css,
			'HOME-CATBAR-2026-004-D: la categoría activa usa el dorado primario del brief (--ltms-gold).'
		);
		// HOME-CATBAR-2026-005: touch target 44px del brief (paridad con los
		// chips de la home que la barra reemplaza).
		$this->assertStringContainsString(
			'min-height:44px;',
			$css,
			'HOME-CATBAR-2026-005: los chips de la barra usan touch target 44px (brief HOME-MATRIX-FIX).'
		);
		// HOME-CATBAR-2026-006: affordance de arrastre en escritorio
		// ("que se pueda desplazar" — feedback del operador).
		$this->assertStringContainsString(
			'.ltms-catbar__track.is-dragging',
			$css,
			'HOME-CATBAR-2026-006: affordance de arrastre (cursor grab/grabbing + user-select none durante el drag).'
		);
		$this->assertStringContainsString(
			'cursor:grab;',
			$css,
			'HOME-CATBAR-2026-006: cursor grab en el track (desktop, media pointer:fine).'
		);
		// HOME-CATBAR-2026-006: variante home — render server-side dentro del
		// header sticky (static, negro heredado de la base).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.ltms-catbar\{[^}]*position:static;/s',
			$css,
			'HOME-CATBAR-2026-006: en la home la barra es static — hereda el sticky del header que la contiene (nunca se oculta).'
		);
	}

	/**
	 * HOME-CATBAR-2026-004-E → HOME-CATBAR-2026-006: el JS reubica la barra
	 * DEBAJO del header del tema (wp_body_open la imprime al inicio del
	 * body), RESPETA la barra inline de la home (guard closest), la hace
	 * deslizable en escritorio (rueda → horizontal + arrastre con puntero +
	 * supresión del click tras un drag real) y sincroniza el fade del
	 * track. El .min.js existe (ltms_asset_url sirve el min en producción).
	 */
	public function test_005_js_reubicacion_y_min_sincronizado(): void {
		$this->assertFileExists( $this->js_path, 'HOME-CATBAR-2026-004-E: el JS de la barra debe existir.' );
		$js = file_get_contents( $this->js_path );

		$this->assertStringContainsString(
			'.elementor-location-header, #masthead, header.site-header',
			$js,
			'HOME-CATBAR-2026-004-E: el JS reubica la barra bajo el header del tema (selección precisa, sin bare header).'
		);
		$this->assertStringContainsString(
			'insertBefore(bar, header.nextSibling)',
			$js,
			'HOME-CATBAR-2026-004-E: la barra se inserta inmediatamente después del header del tema.'
		);
		// HOME-CATBAR-2026-006: la home renderiza la barra INLINE dentro de
		// su header sticky — el JS no debe reubicarla.
		$this->assertStringContainsString(
			"bar.closest('.pv-home-header')",
			$js,
			'HOME-CATBAR-2026-006: guard closest — el JS respeta la barra inline de la home (no la reubica).'
		);
		// HOME-CATBAR-2026-006: deslizamiento en escritorio.
		$this->assertStringContainsString(
			"'wheel'",
			$js,
			'HOME-CATBAR-2026-006: rueda del mouse → scroll horizontal ("que se pueda desplazar" — operador).'
		);
		$this->assertStringContainsString(
			'is-dragging',
			$js,
			'HOME-CATBAR-2026-006: arrastre con puntero (pointer events + clase is-dragging).'
		);
		$this->assertStringContainsString(
			'stopPropagation',
			$js,
			'HOME-CATBAR-2026-006: un drag real NO navega al chip donde terminó el puntero (click suprimido >6px).'
		);
		$this->assertStringContainsString(
			'data-ltms-scrollable',
			$js,
			'HOME-CATBAR-2026-004-E: el JS sincroniza el fade del borde derecho (data-ltms-scrollable/at-end).'
		);
		// HOME-CATBAR-2026-005: navegabilidad — el chip de la categoría activa
		// se centra en el track al cargar (el usuario ve su contexto).
		$this->assertStringContainsString(
			'.ltms-catbar__chip.is-active',
			$js,
			'HOME-CATBAR-2026-005: el chip de la categoría activa se localiza para centrarlo en el track.'
		);
		$this->assertStringContainsString(
			"inline: 'center'",
			$js,
			'HOME-CATBAR-2026-005: centrado del chip activo via scrollIntoView inline:center (navegabilidad).'
		);

		// Sincronización .min.js (ltms_asset_url sirve el .min con SCRIPT_DEBUG off).
		$this->assertFileExists( $this->min_js_path, 'HOME-CATBAR-2026-004-E: el .min.js debe existir (producción lo sirve).' );
		$min = file_get_contents( $this->min_js_path );
		$this->assertStringContainsString(
			'.elementor-location-header',
			$min,
			'HOME-CATBAR-2026-004-E: el .min.js contiene la reubicación (regenerado, no desincronizado).'
		);
		$this->assertStringContainsString(
			'pv-home-header',
			$min,
			'HOME-CATBAR-2026-006: el .min.js contiene el guard de la home (regenerado, no desincronizado).'
		);
		$this->assertStringNotContainsString(
			'ltms-catbar__home',
			$min,
			'HOME-CATBAR-2026-006: el .min.js no debe contener el logo (eliminado en 006).'
		);
	}

	/**
	 * BRAND-2026-002: el topbar del storefront (la barra con logo que citó el
	 * operador como referencia) pasa del theme mod custom_logo (la bandera,
	 * inconsistencia #8 del brief) al logo OFICIAL del branding engine, con
	 * fallback al theme logo. Su comportamiento sticky !important se conserva.
	 */
	public function test_006_storefront_topbar_logo_oficial(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->storefront_path ) );

		$this->assertStringContainsString(
			"LTMS_Branding_Engine::get_logo_url( 'white' )",
			$src,
			'BRAND-2026-002: el topbar del storefront sirve el logo oficial (get_logo_url white = a color sobre fondo claro).'
		);
		$this->assertStringContainsString(
			'class="ltms-sf-topbar"',
			$src,
			'BRAND-2026-002: el topbar del storefront se conserva (es la referencia sticky de la barra global).'
		);
		$this->assertStringContainsString(
			'.ltms-sf-topbar{position:sticky!important;top:0!important;',
			$src,
			'BRAND-2026-002: el topbar del storefront sigue sticky !important (nunca se oculta — comportamiento verificado).'
		);
	}

	/**
	 * HOME-CATBAR-2026-004-F: AUTOLOADER — LTMS_Global_Catbar deriva a un
	 * subdir 'global/' que no existe (la clase vive en frontend/); sin la
	 * entrada en el mapa de excepciones del SPL fallback del autoloader la
	 * clase NUNCA carga (bug real detectado en producción: class_exists
	 * devolvía false con el archivo presente y php -l limpio). También el
	 * whitelist del webhook de deploy debe incluir los archivos nuevos.
	 */
	public function test_007_autoloader_y_whitelist_del_webhook(): void {
		$main = $this->strip_php_comments( file_get_contents( dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php' ) );
		$this->assertStringContainsString(
			"'ltms-global-catbar'                     => 'frontend/class-ltms-global-catbar.php'",
			$main,
			'HOME-CATBAR-2026-004-F: el autoloader SPL debe mapear ltms-global-catbar a frontend/ (sin ella la clase nunca carga).'
		);

		$webhook = file_get_contents( dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php' );
		foreach ( array(
			'includes/frontend/class-ltms-global-catbar.php' => 'la clase de la barra',
			'assets/css/ltms-catbar.css'                    => 'el CSS de la barra',
			'assets/js/ltms-catbar.js'                       => 'el JS de la barra',
			'assets/js/ltms-catbar.min.js'                   => 'el min.js de la barra',
			'assets/img/logo-lo-tengo-white.png'             => 'el logo blanco (header/footer de la home — la barra lo perdió en 006)',
			'assets/img/logo-lo-tengo.png'                   => 'el logo a color (favicon/og/storefront)',
		) as $file => $why ) {
			$this->assertStringContainsString(
				"'" . $file . "'",
				$webhook,
				"HOME-CATBAR-2026-004-F: el webhook de deploy debe sincronizar {$file} ({$why})."
			);
		}
	}
}
