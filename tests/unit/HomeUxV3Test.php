<?php
/**
 * HomeUxV3Test — ronda HOME-UX3 (2026-10-02): hallazgos post-UX2 del operador.
 *
 * Hallazgos cubiertos:
 *   1. Wishlist end-to-end (HOME-UX3-001): el corazón persistía (AJAX + cookie)
 *      pero NADA restauraba el estado — el fav renderizaba aria-pressed="false"
 *      hardcodeado (el corazón legacy suprimido en UX2-007 era el ÚNICO que
 *      consultaba is_in_wishlist), el badge del header nunca se movía (el
 *      filtro ltms_wishlist_count no tenía implementación y el evento
 *      pv:wishlist-toggle se disparaba "que nadie escucha"), y la página
 *      /favoritos enlazada era un 404 (creada con el shortcode).
 *   2. Footer con las IMÁGENES reales de marca (HOME-UX3-002): pagos (incl.
 *      OpenPay), Fundación Cardioinfantil LaCardio y afiliaciones CCC/CCCE —
 *      los assets del footer del tema que se perdieron al ocultarlo.
 *   3. Card: título DUPLICADO (HOME-UX3-003): el h2 core de WC
 *      (woocommerce_template_loop_product_title, hook p10) se colaba dentro
 *      del card porque el template suprimía rating/price/ATC pero olvidaba
 *      este callback — la "redundancia en las letras" del operador. Además
 *      el kit de Elementor pisaba el h3 del título a 20px/700 (especificidad
 *      0-1-1 vs 0-1-0) — bump a .pv-product-card .pv-product-card__title.
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeUxV2Test).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeUxV3Test
 */
final class HomeUxV3Test extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $card_path;
	private string $wishlist_path;
	private string $js_path;
	private string $css_path;
	private string $plugin_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path     = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->card_path     = dirname( __DIR__, 2 ) . '/includes/frontend/templates/wc-parts/content-product.php';
		$this->wishlist_path = dirname( __DIR__, 2 ) . '/includes/frontend/class-ltms-wishlist.php';
		$this->js_path       = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->css_path      = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->plugin_path   = dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php';
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
	 * HOME-UX3-001a: el fav del card PV restaura su estado inicial desde la
	 * wishlist real — antes aria-pressed="false" hardcodeado: al recargar el
	 * corazón volvía vacío aunque el producto estaba guardado (cookie/DB).
	 */
	public function test_001_fav_estado_inicial_desde_wishlist(): void {
		$this->assertFileExists( $this->card_path );
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringContainsString(
			"LTMS_Wishlist::is_in_wishlist( \$pv_pid )",
			$src,
			'HOME-UX3-001a: el card debe consultar el estado real en la wishlist (cookie guest / DB logged-in).'
		);
		$this->assertStringContainsString(
			"pv-product-card__fav<?php echo \$pv_in_wishlist ? ' is-active' : ''; ?>",
			$src,
			'HOME-UX3-001a: la clase is-active debe aplicarse condicionalmente según el estado real.'
		);
		$this->assertStringContainsString(
			"aria-pressed=\"<?php echo \$pv_in_wishlist ? 'true' : 'false'; ?>\"",
			$src,
			'HOME-UX3-001a: aria-pressed debe reflejar el estado real (accesibilidad).'
		);
		$this->assertStringNotContainsString(
			'aria-pressed="false"' . "\n",
			$src,
			'HOME-UX3-001a: el aria-pressed="false" hardcodeado fue eliminado.'
		);
	}

	/**
	 * HOME-UX3-001b: el filtro ltms_wishlist_count tiene implementación — el
	 * badge del header home consume apply_filters('ltms_wishlist_count', 0)
	 * y siempre computaba 0.
	 */
	public function test_002_filtro_wishlist_count_implementado(): void {
		$this->assertFileExists( $this->wishlist_path );
		$src = $this->strip_php_comments( file_get_contents( $this->wishlist_path ) );

		$this->assertStringContainsString(
			"add_filter( 'ltms_wishlist_count', [ __CLASS__, 'filter_wishlist_count' ] )",
			$src,
			'HOME-UX3-001b: LTMS_Wishlist debe registrar el filtro ltms_wishlist_count.'
		);
		$this->assertStringContainsString(
			'public static function filter_wishlist_count(): int {',
			$src,
			'HOME-UX3-001b: el método filter_wishlist_count debe existir.'
		);
		$this->assertStringContainsString(
			'return count( self::get_wishlist_ids() );',
			$src,
			'HOME-UX3-001b: el count viene de la fuente canónica get_wishlist_ids().'
		);
	}

	/**
	 * HOME-UX3-001c: badge del header SIEMPRE renderizado con
	 * data-pv-wishlist-count (el JS lo actualiza en vivo; vacío se oculta
	 * por CSS :empty).
	 */
	public function test_003_badge_header_actualizable(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString(
			'data-pv-wishlist-count',
			$src,
			'HOME-UX3-001c: el badge de favoritos debe llevar data-pv-wishlist-count para el update en vivo.'
		);
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__badge:empty\{display:none;\}/",
			$src,
			'HOME-UX3-001c: el badge vacío (count 0) se oculta por CSS :empty.'
		);
		// El render condicional viejo (badge solo con count>0) fue eliminado.
		$this->assertStringNotContainsString(
			"if ( \$pv_wishlist_count > 0 ) : ?>\n                            <span class=\"pv-home-header__badge\">",
			$src,
			'HOME-UX3-001c: el badge ya no se renderiza condicionalmente — existe SIEMPRE.'
		);
	}

	/**
	 * HOME-UX3-001d: el evento pv:wishlist-toggle ahora tiene listener — el
	 * count del backend viaja en el evento y actualiza los badges.
	 */
	public function test_004_evento_wishlist_toggle_escuchado(): void {
		$js = file_get_contents( $this->js_path );

		$this->assertStringContainsString(
			"on(window, 'pv:wishlist-toggle'",
			$js,
			'HOME-UX3-001d: plaza-viva.js debe escuchar pv:wishlist-toggle (antes "que nadie escucha").'
		);
		$this->assertStringContainsString(
			"qsa('[data-pv-wishlist-count]')",
			$js,
			'HOME-UX3-001d: el listener debe actualizar todos los [data-pv-wishlist-count].'
		);
		$this->assertStringContainsString(
			"count: (res.data && typeof res.data.count === 'number') ? res.data.count : undefined",
			$js,
			'HOME-UX3-001d: el dispatch del evento debe llevar el count autoritativo del backend.'
		);
		// El .min.js regenerado.
		$min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js' );
		$this->assertStringContainsString( 'pv:wishlist-toggle', $min, 'HOME-UX3-001d: el .min.js debe estar regenerado con el listener.' );
	}

	/**
	 * HOME-UX3-002: footer con las IMÁGENES reales de marca del operador
	 * (pagos incl. OpenPay, LaCardio, CCC, CCCE) — los assets del footer del
	 * tema que se perdieron al ocultarlo en HOME-UX2-005.
	 */
	public function test_005_footer_imagenes_de_marca_restauradas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringContainsString( 'pv-home-footer__brands', $src, 'HOME-UX3-002: debe existir la fila de marcas del footer.' );
		foreach ( array(
			'logos-footer-4-300x169.png' => 'logos de métodos de pago (PSE/Nequi/Daviplata/Visa/Mastercard/Amex/OpenPay)',
			'logo-lacardio-300x111.png'  => 'Fundación Cardioinfantil LaCardio (donaciones)',
			'logo-foot-1-300x169.png'    => 'Cámara de Comercio de Cali — La CCC',
			'logo-foot-2-300x169.png'    => 'Cámara Colombiana de Comercio Electrónico',
		) as $file => $why ) {
			$this->assertStringContainsString(
				$file,
				$src,
				sprintf( 'HOME-UX3-002: la imagen %s (%s) debe estar en el footer.', $file, $why )
			);
		}
		$this->assertStringContainsString(
			"apply_filters( 'ltms_home_footer_brands',",
			$src,
			'HOME-UX3-002: la fila de marcas debe ser filterable (ltms_home_footer_brands).'
		);
		// Los badges de texto inventados en UX2-005 fueron reemplazados por las imágenes reales.
		$this->assertStringNotContainsString(
			'pv-home-footer__pay-badge',
			$src,
			'HOME-UX3-002: los badges de texto fueron reemplazados por las imágenes de marca reales.'
		);
	}

	/**
	 * HOME-UX3-003a: el título DUPLICADO del card (h2 core de WC) suprimido —
	 * el template ya suprimía rating/price/ATC pero olvidaba
	 * woocommerce_template_loop_product_title (hook p10 del mismo action).
	 */
	public function test_006_card_sin_titulo_core_duplicado(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringContainsString(
			"remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 )",
			$src,
			'HOME-UX3-003a: el h2 core de WC debe suprimirse dentro del card PV.'
		);
		$this->assertStringContainsString(
			"add_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 )",
			$src,
			'HOME-UX3-003a: el hook core debe re-añadirse tras do_action (loops de terceros lo conservan).'
		);
		// Orden: remove ANTES del do_action, add DESPUÉS.
		$pos_remove = strpos( $src, "remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 )" );
		$pos_do     = strpos( $src, "do_action( 'woocommerce_shop_loop_item_title' )" );
		$pos_add    = strpos( $src, "add_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 )" );
		$this->assertNotFalse( $pos_remove );
		$this->assertNotFalse( $pos_do );
		$this->assertNotFalse( $pos_add );
		$this->assertLessThan( $pos_do, $pos_remove, 'HOME-UX3-003a: el remove debe ir ANTES del do_action.' );
		$this->assertGreaterThan( $pos_do, $pos_add, 'HOME-UX3-003a: el add de restauración debe ir DESPUÉS del do_action.' );
	}

	/**
	 * HOME-UX3-003b: especificidad del título del card bumpada — el kit
	 * global de Elementor (.elementor-kit-* h3, 0-1-1) ganaba a la clase
	 * sola (0-1-0) y el título computaba 20px/700 en producción.
	 */
	public function test_007_especificidad_titulo_card_bumpeada(): void {
		$css = file_get_contents( $this->css_path );

		$this->assertMatchesRegularExpression(
			"/\.pv-product-card \.pv-product-card__title\{\s*font-family:var\(--display\);font-weight:600;font-size:14\.5px;/s",
			$css,
			'HOME-UX3-003b: la regla del título debe usar .pv-product-card .pv-product-card__title (0-2-0) para ganar al kit de Elementor.'
		);
		$this->assertStringNotContainsString(
			"\n.pv-product-card__title{\n",
			$css,
			'HOME-UX3-003b: la regla base con clase sola (0-1-0) fue reemplazada.'
		);
		$this->assertStringContainsString(
			'.pv-product-card .pv-product-card__title{font-size:13.5px;',
			$css,
			'HOME-UX3-003b: el override móvil (max-width:400px) también bumpeado.'
		);
		// El .min.css regenerado.
		$min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css' );
		$this->assertStringContainsString( '.pv-product-card .pv-product-card__title', $min, 'HOME-UX3-003b: el .min.css debe estar regenerado con el bump.' );
	}

	/**
	 * HOME-UX3 infra: versión 2.9.410 (cache-busting JS+CSS).
	 * HOME-UX4-001 (2026-10-03): pin actualizado a 2.9.411 en el mismo commit
	 * del bump (lección #119 — test que aserta el enfoque viejo se actualiza
	 * junto al cambio, no queda huérfano rompiendo suites futuras).
	 * HOME-UX5 (2026-10-04): pin EXACTO → RANGO ≥2.9.412 (lección #188 — el
	 * pin exacto rompía la suite en cada bump; los rangos son el patrón de
	 * HomeUxV2Test). 2.9.412 = cache-busting del JS/CSS del ciclo HOME-UX5.
	 */
	public function test_008_version_2_9_410(): void {
		$this->assertFileExists( $this->plugin_path );
		$plugin = file_get_contents( $this->plugin_path );
		$this->assertMatchesRegularExpression(
			"/define\( 'LTMS_VERSION', '2\.9\.4(1[2-9]|[2-9][0-9])' \);/",
			$plugin,
			'HOME-UX5: LTMS_VERSION debe ser >= 2.9.412 (cache-busting del JS/CSS del ciclo HOME-UX5).'
		);
	}
}
