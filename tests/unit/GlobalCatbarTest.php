<?php
/**
 * GlobalCatbarTest — HOME-CATBAR-2026-004 (2026-10-15).
 *
 * Barra global de categorías (petición del operador: "una barra de
 * categorías para móvil y escritorio, como la de la storefront con logo,
 * en todas las páginas, que no se oculte mientras se desplaza").
 *
 * Valida ESTRUCTURALMENTE:
 *   1. El módulo existe, se inicializa desde el kernel y engancha wp_body_open.
 *   2. La barra es sticky (nunca se oculta al escrolear) y usa el rojo de
 *      marca #E80001 + el logo oficial (variante blanca, BRAND-2026-001).
 *   3. Las categorías: TODAS las activas vía el helper normalizado, con la
 *      exclusión del artefacto 'no-aplica' y la categoría activa resaltada
 *      (is-active + aria-current) en los archivos de categoría.
 *   4. Exclusiones documentadas: home nativa (barra propia), checkout
 *      (funnel), panel del vendedor (dashboard/wallet/orders).
 *   5. Assets: CSS (sticky + fade del track) y JS (reubicación bajo el
 *      header del tema + fade) encolados, con .min.js sincronizado.
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
			"add_action( 'wp_body_open', [ __CLASS__, 'render_bar' ], 10 );",
			$module,
			'HOME-CATBAR-2026-004-A: el render debe engancharse a wp_body_open (toda página estándar del tema).'
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
	 * HOME-CATBAR-2026-004-B: exclusiones documentadas — home nativa (tiene
	 * su propia barra en el header sticky), checkout/order-received (funnel)
	 * y panel del vendedor (dashboard/wallet/orders).
	 */
	public function test_002_exclusiones_documentadas(): void {
		$module = $this->strip_php_comments( file_get_contents( $this->module_path ) );

		$this->assertMatchesRegularExpression(
			"/get_option\( 'ltms_home_template_enabled', 'yes' \) === 'yes'\s*\n?\s*&& is_front_page\(\) && is_page\(\)/",
			$module,
			'HOME-CATBAR-2026-004-B: la home nativa queda excluida (su header sticky ya tiene la barra de chips).'
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
	 * HOME-CATBAR-2026-004-C: la barra renderiza el logo oficial (variante
	 * blanca) + TODAS las categorías activas normalizadas con el artefacto
	 * 'no-aplica' excluido, y marca la categoría activa (is-active +
	 * aria-current="page").
	 */
	public function test_003_render_logo_categorias_y_estado_activo(): void {
		$module = $this->strip_php_comments( file_get_contents( $this->module_path ) );

		// Logo oficial (variante blanca — la barra es roja de marca).
		$this->assertStringContainsString(
			"'img/logo-lo-tengo-white.png'",
			$module,
			'HOME-CATBAR-2026-004-C: la barra usa el logo oficial en variante blanca (BRAND-2026-001).'
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
	 * HOME-CATBAR-2026-004-D: el CSS de la barra — sticky top (nunca se
	 * oculta al escrolear), rojo de marca, fila deslizable con fade y
	 * estado activo dorado del brief.
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
			'background:#E80001;',
			$css,
			'HOME-CATBAR-2026-004-D: la barra usa el rojo de marca del brief #E80001.'
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
	}

	/**
	 * HOME-CATBAR-2026-004-E: el JS reubica la barra DEBAJO del header del
	 * tema (wp_body_open la imprime al inicio del body) y sincroniza el fade
	 * del track. El .min.js existe (ltms_asset_url sirve el min en producción).
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
		$this->assertStringContainsString(
			'data-ltms-scrollable',
			$js,
			'HOME-CATBAR-2026-004-E: el JS sincroniza el fade del borde derecho (data-ltms-scrollable/at-end).'
		);

		// Sincronización .min.js (ltms_asset_url sirve el .min con SCRIPT_DEBUG off).
		$this->assertFileExists( $this->min_js_path, 'HOME-CATBAR-2026-004-E: el .min.js debe existir (producción lo sirve).' );
		$min = file_get_contents( $this->min_js_path );
		$this->assertStringContainsString(
			'.elementor-location-header',
			$min,
			'HOME-CATBAR-2026-004-E: el .min.js contiene la reubicación (regenerado, no desincronizado).'
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
			'assets/img/logo-lo-tengo-white.png'             => 'el logo blanco (header/footer/barra)',
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
