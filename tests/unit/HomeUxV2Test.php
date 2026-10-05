<?php
/**
 * HomeUxV2Test — ciclo HOME-UX2 (2026-10-02): consistencia UI/UX de la home
 * nativa reportada por el operador.
 *
 * Hallazgos cubiertos (cada uno con su fix):
 *   1. Jerarquía de colores: header/sell/hero re-anclados a los tokens del
 *      design system (antes navy #1A1A4E + dorado #E0A526 hardcodeados,
 *      colores que NO existen en plaza-viva.css — la home leía como otro
 *      site frente al header claro de shop/producto).
 *   2. Chips del buscador: eran 4 términos hardcodeados con 0-2 resultados
 *      reales ('Tecnología', 'Regalos', 'Hogar'); ahora derivan del top 4 de
 *      categorías activas y ENLAZAN directo a la categoría (búsqueda de
 *      texto muerta eliminada).
 *   3. Carrito del header: abre el mini-cart lateral (data-ltms-open-cart,
 *      mismo patrón del topbar del storefront) en vez de navegar a /carrito/.
 *   4. Card PV sin texto redundante: rating "(0)" oculto sin reseñas, badge
 *      "✅ Verificado" suprimido (duplicaba el escudo KYC de la línea
 *      vendor), claim genérico "Envío a todo el país" eliminado.
 *   5. Corazón duplicado: .ltms-wishlist-btn (LTMS_Wishlist, hook loop) se
 *      renderizaba ENCIMA de .pv-product-card__fav — suprimido dentro del
 *      card PV con remove+re-add scoped.
 *   6. Móvil: hero (banners) y vendedores destacados ocultos en <768px,
 *      orden header → categorías → tarjetas → garantías → vende.
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeCategoriesBarTest).
 *
 * Ejecutar con: LTMS_UNIT_ONLY=true ./vendor/bin/phpunit --testsuite unit --filter HomeUxV2Test
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class HomeUxV2Test
 */
final class HomeUxV2Test extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $card_path;
	private string $plugin_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path   = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->card_path   = dirname( __DIR__, 2 ) . '/includes/frontend/templates/wc-parts/content-product.php';
		$this->plugin_path = dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php';
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
	 * HOME-UX2-001: colores re-anclados a los tokens del design system.
	 * Los literales off-system (navy/dorado/hovers fuera de plaza-viva.css)
	 * deben haber desaparecido del CSS de la home.
	 */
	public function test_001_colores_re_anclados_a_tokens(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$off_system_hexes = array(
			'#1A1A4E' => 'navy hardcodeado (no existe en ningún token PV)',
			'#E0A526' => 'dorado off-system (el token es --gold #D4A857)',
			'#16309B' => 'hover off-system (los tokens son --primary-600/700)',
			'#EBB44A' => 'hover dorado off-system',
		);
		foreach ( $off_system_hexes as $hex => $why ) {
			$this->assertStringNotContainsString(
				strtoupper( $hex ),
				strtoupper( $src ),
				sprintf( 'HOME-UX2-001: %s (%s) no debe quedar hardcodeado — todo a var(--*).', $hex, $why )
			);
		}

		// HOME-UX5-003 (2026-10-04): el botón Buscar pasó a var(--danger-700)
		// — el header es ROJO a pedido del operador; el botón viaja DENTRO del
		// campo blanco y mantiene el rojo institucional con texto blanco AA.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__search-btn\{[^}]*background:var\(--danger-700\);/s",
			$src,
			'HOME-UX5-003: el botón Buscar usa var(--danger-700) — rojo del header, blanco 5.7:1 AA.'
		);
		// HOME-UX5-003: el header es ROJO (var(--danger-700)) — decisión del
		// operador que reemplaza el fondo claro del ciclo UX2.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header\{[^}]*background:var\(--danger-700\);/s",
			$src,
			'HOME-UX5-003: el header de la home es rojo var(--danger-700) (operador 2026-10-04).'
		);
		// La franja Vende usa el gradiente del design system (mismo del hero fallback).
		$this->assertMatchesRegularExpression(
			"/\.pv-home-sell\{[^}]*radial-gradient\(120% 100% at 0% 0%,#3b82f6 0%,var\(--primary\) 45%,var\(--primary-700\) 100%\)/s",
			$src,
			'HOME-UX2-001: la franja Vende con nosotros debe usar el gradiente azul del design system.'
		);
		// El badge del header es dorado token.
		$this->assertMatchesRegularExpression(
			"/\.pv-scope\.pv-home \.pv-home-header__badge\{[^}]*background:var\(--gold\);/s",
			$src,
			'HOME-UX2-001: el badge del contador usa var(--gold) (token) con texto var(--text).'
		);
	}

	/**
	 * HOME-UX2-003 → HOME-UX5-004 (2026-10-04): chips = top 8 categorías
	 * activas como ENLACES directos. Los términos muertos ('Tecnología',
	 * 'Regalos', 'Hogar' → 0-2 resultados reales verificados en producción)
	 * quedaron eliminados; el top 4 pasó a 8 para cubrir también JUEGO DE
	 * MESA y DIDACTICO (decisión del operador — con 4 solo entraban las
	 * categorías de belleza). Test actualizado en el mismo commit (#119).
	 */
	public function test_002_chips_categorias_reales_como_enlaces(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Términos muertos eliminados del default de chips.
		$this->assertStringNotContainsString( "'Tecnología'", $src, "HOME-UX2-003: 'Tecnología' no existe en el catálogo (0 categorías, 113 matches de descripción — no es chip)." );
		$this->assertStringNotContainsString( "'Regalos'", $src, "HOME-UX2-003: búsqueda de 'Regalos' = 0 resultados verificados." );
		$this->assertStringNotContainsString( "'Hogar'", $src, "HOME-UX2-003: búsqueda de 'Hogar' = 2 resultados verificados — no es chip." );

		// Los chips derivan del mismo query de categorías (top 8, enlaces).
		$this->assertStringContainsString(
			'array_slice( $pv_cat_terms, 0, 8 )',
			$src,
			'HOME-UX5-004: los chips derivan del top 8 de categorías activas (incluye JUEGO DE MESA y DIDACTICO).'
		);
		$this->assertStringNotContainsString(
			'array_slice( $pv_cat_terms, 0, 4 )',
			$src,
			'HOME-UX5-004: el top 4 de chips fue ampliado a 8 — con 4 solo entraban las categorías de belleza.'
		);
		$this->assertMatchesRegularExpression(
			"/<a class=\"pv-home-header__chip\" href=\"<\?php echo esc_url\( \\\$pv_chip\['url'\] \); \?>/",
			$src,
			'HOME-UX2-003: los chips son enlaces <a> a la página de la categoría (no botones de búsqueda).'
		);
		$this->assertStringNotContainsString(
			'data-pv-search-chip',
			$src,
			'HOME-UX2-003: sin data-pv-search-chip — el handler JS lanzaría una búsqueda de texto que puede llegar vacía.'
		);
		// El filtro de personalización se conserva.
		$this->assertStringContainsString(
			"apply_filters( 'ltms_home_popular_chips',",
			$src,
			'HOME-UX2-003: el filtro ltms_home_popular_chips se conserva (backward compat).'
		);
	}

	/**
	 * HOME-UX2-004: la acción de carrito del header abre el mini-cart lateral
	 * (data-ltms-open-cart, el drawer de LTMS_Cart_Drawer), con href de fallback.
	 */
	public function test_003_carrito_abre_minicart(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// El <a> del carrito lleva data-ltms-open-cart (el JS del drawer hace preventDefault y abre).
		$this->assertMatchesRegularExpression(
			"/<a class=\"pv-home-header__action\" href=\"<\?php echo esc_url\( \\\$pv_cart_url \); \?>\" data-ltms-open-cart/",
			$src,
			'HOME-UX2-004: el carrito del header debe abrir el mini-cart (data-ltms-open-cart).'
		);
		// El href de fallback a /carrito se conserva para no-JS.
		$this->assertStringContainsString(
			'$pv_cart_url    = wc_get_cart_url();',
			$src,
			'HOME-UX2-004: el href de fallback a la página de carrito se conserva.'
		);
		// El selector JS del drawer reconoce data-ltms-open-cart como opt-in.
		$drawer_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-cart-drawer.js' );
		$this->assertStringContainsString(
			"[data-ltms-open-cart]",
			$drawer_js,
			'HOME-UX2-004: ltms-cart-drawer.js debe seguir reconociendo el opt-in data-ltms-open-cart.'
		);
	}

	/**
	 * HOME-UX2-008: móvil <768px — hero (banners) y vendedores destacados
	 * ocultos; orden del funnel: categorías → tarjetas → garantías → vende →
	 * políticas. Desktop (≥768) conserva el orden original.
	 */
	public function test_004_movil_oculta_hero_y_vendors_y_reordena(): void {
		$src = file_get_contents( $this->home_path );

		// Bloque móvil con las reglas de ocultado + orden flex.
		$this->assertMatchesRegularExpression(
			"/@media \(max-width:767px\)\{[^@]*\.pv-scope\.pv-home \.pv-home__hero-wrap\{display:none;\}/s",
			$src,
			'HOME-UX2-008: el hero de banners debe ocultarse en móvil (<768px).'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(max-width:767px\)\{[^@]*\.pv-scope\.pv-home \.pv-home__vendors\{display:none;\}/s",
			$src,
			'HOME-UX2-008: los vendedores destacados deben ocultarse en móvil (<768px).'
		);
		$this->assertMatchesRegularExpression(
			"/@media \(max-width:767px\)\{[^@]*\.pv-scope\.pv-home \#pv-main\{display:flex;flex-direction:column;\}/s",
			$src,
			'HOME-UX2-008: #pv-main debe ser flex-column en móvil para el orden del funnel.'
		);
		foreach ( array(
			'.pv-home__cat-carousels{order:1;}' => 'carruseles por categoría inmediatamente tras las categorías (HOME-UX5-001 reemplaza a trending)',
			'.pv-home__trust{order:2;}'     => 'garantías después de los carruseles',
			'.pv-home__sell{order:3;}'       => 'franja Vende con nosotros después de garantías',
			'.pv-home__policies{order:4;}'   => 'políticas al final, antes del footer',
		) as $rule => $why ) {
			$this->assertStringContainsString(
				$rule,
				$src,
				sprintf( 'HOME-UX2-008: regla de orden móvil %s (%s).', $rule, $why )
			);
		}
	}

	/**
	 * HOME-UX2-007: el corazón legacy del wishlist (.ltms-wishlist-btn) se
	 * suprime DENTRO del card PV (remove+re-add scoped) — antes se montaba
	 * encima de .pv-product-card__fav (dos corazones por card).
	 */
	public function test_005_card_pv_sin_corazon_duplicado(): void {
		$this->assertFileExists( $this->card_path );
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringContainsString(
			"remove_action( 'woocommerce_after_shop_loop_item', array( 'LTMS_Wishlist', 'render_wishlist_button' ), 20 )",
			$src,
			'HOME-UX2-007: el botón wishlist legacy debe suprimirse dentro del card PV.'
		);
		$this->assertStringContainsString(
			"add_action( 'woocommerce_after_shop_loop_item', array( 'LTMS_Wishlist', 'render_wishlist_button' ), 20 )",
			$src,
			'HOME-UX2-007: el hook legacy debe re-añadirse tras do_action (loops de terceros sin card PV lo conservan).'
		);
		// El fav del design system sigue presente y con AJAX propio.
		$this->assertStringContainsString( 'pv-product-card__fav', $src, 'HOME-UX2-007: el fav del card PV (.pv-product-card__fav) se conserva.' );
		$this->assertStringContainsString( 'data-pv-wishlist-toggle', $src, 'HOME-UX2-007: el fav del card conserva su AJAX propio (data-pv-wishlist-toggle).' );
		// Orden: remove ANTES del do_action, add DESPUÉS (si no, el badge igual se renderiza).
		$pos_remove = strpos( $src, "remove_action( 'woocommerce_after_shop_loop_item', array( 'LTMS_Wishlist', 'render_wishlist_button' ), 20 )" );
		$pos_do     = strpos( $src, "do_action( 'woocommerce_after_shop_loop_item' )" );
		$pos_add    = strpos( $src, "add_action( 'woocommerce_after_shop_loop_item', array( 'LTMS_Wishlist', 'render_wishlist_button' ), 20 )" );
		$this->assertNotFalse( $pos_remove );
		$this->assertNotFalse( $pos_do );
		$this->assertNotFalse( $pos_add );
		$this->assertLessThan( $pos_do, $pos_remove, 'HOME-UX2-007: el remove_action debe ejecutarse ANTES del do_action.' );
		$this->assertGreaterThan( $pos_do, $pos_add, 'HOME-UX2-007: el add_action de restauración debe ir DESPUÉS del do_action.' );
	}

	/**
	 * HOME-UX2-006a: badge "✅ Verificado" de LTMS_Trust_Badges suprimido
	 * dentro del card PV (duplicaba el escudo KYC de la línea de vendor).
	 */
	public function test_006_card_pv_sin_badge_verificado_duplicado(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringContainsString(
			"remove_action( 'woocommerce_after_shop_loop_item_title', array( 'LTMS_Trust_Badges', 'render_loop_vendor_badge' ), 5 )",
			$src,
			'HOME-UX2-006a: render_loop_vendor_badge debe suprimirse dentro del card PV.'
		);
		$this->assertStringContainsString(
			"add_action( 'woocommerce_after_shop_loop_item_title', array( 'LTMS_Trust_Badges', 'render_loop_vendor_badge' ), 5 )",
			$src,
			'HOME-UX2-006a: el hook debe re-añadirse tras do_action (loops de terceros lo conservan).'
		);
		// El escudo KYC propio del card sigue presente (la señal de verificación NO se pierde).
		$this->assertStringContainsString(
			'pv-product-card__vendor-check',
			$src,
			'HOME-UX2-006a: el escudo KYC de la línea de vendor se conserva — la señal sigue, sin duplicar.'
		);
		// Orden correcto del guard.
		$pos_remove = strpos( $src, "remove_action( 'woocommerce_after_shop_loop_item_title', array( 'LTMS_Trust_Badges', 'render_loop_vendor_badge' ), 5 )" );
		$pos_do     = strpos( $src, "do_action( 'woocommerce_after_shop_loop_item_title' )" );
		$pos_add    = strpos( $src, "add_action( 'woocommerce_after_shop_loop_item_title', array( 'LTMS_Trust_Badges', 'render_loop_vendor_badge' ), 5 )" );
		$this->assertNotFalse( $pos_remove );
		$this->assertNotFalse( $pos_do );
		$this->assertNotFalse( $pos_add );
		$this->assertLessThan( $pos_do, $pos_remove, 'HOME-UX2-006a: el remove_action debe ejecutarse ANTES del do_action.' );
		$this->assertGreaterThan( $pos_do, $pos_add, 'HOME-UX2-006a: el add_action de restauración debe ir DESPUÉS del do_action.' );
	}

	/**
	 * HOME-UX2-006b: rating solo con reseñas — antes cada card sin reseñas
	 * renderaba la fila con "(0)" (ruido sin señal).
	 */
	public function test_007_card_pv_rating_solo_con_resenas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringContainsString(
			'if ( $pv_review_count > 0 ) :',
			$src,
			'HOME-UX2-006b: la fila de rating solo debe renderizarse con reseñas.'
		);
		// El wrapper del rating queda dentro del condicional (verificar que el
		// rating-count vive dentro del bloque condicional, no fuera).
		$pos_cond  = strpos( $src, 'if ( $pv_review_count > 0 ) :' );
		$pos_count = strpos( $src, 'pv-product-card__rating-count' );
		$this->assertNotFalse( $pos_cond );
		$this->assertNotFalse( $pos_count );
		$this->assertLessThan(
			$pos_count,
			$pos_cond,
			'HOME-UX2-006b: el rating-count debe quedar DENTRO del bloque condicional de reseñas.'
		);
	}

	/**
	 * HOME-UX2-006c: el claim genérico "Envío a todo el país" eliminado de la
	 * card (duplicaba la franja de garantías de la home; el badge de envío
	 * gratis real — free_absorbed — vive sobre la imagen).
	 */
	public function test_008_card_pv_sin_claim_envio_generico(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->card_path ) );

		$this->assertStringNotContainsString(
			'Envío a todo el país',
			$src,
			'HOME-UX2-006c: el claim genérico de envío fue eliminado del card.'
		);
		$this->assertStringNotContainsString(
			'pv-product-card__shipping',
			$src,
			'HOME-UX2-006c: el elemento pv-product-card__shipping fue eliminado del card.'
		);
		// La señal REAL por producto se conserva: badge envío gratis sobre la imagen.
		$this->assertStringContainsString(
			'pv-product-card__free-shipping',
			$src,
			'HOME-UX2-006c: el badge de envío gratis real (free_absorbed) se conserva.'
		);
		// La urgencia de stock bajo se conserva.
		$this->assertStringContainsString(
			'pv-product-card__stock-low',
			$src,
			'HOME-UX2-006c: la urgencia de stock bajo se conserva.'
		);
	}

	/**
	 * HOME-UX2 infra: versión bumpeada (cache-bust del JS del fade de la
	 * barra de categorías) + el .min.js regenerado con el código nuevo.
	 */
	public function test_009_version_y_min_regenerado(): void {
		$this->assertFileExists( $this->plugin_path );
		$plugin = file_get_contents( $this->plugin_path );
		$this->assertMatchesRegularExpression(
			"/define\( 'LTMS_VERSION', '2\.9\.4(0[89]|[1-9][0-9])' \);/",
			$plugin,
			'HOME-UX2: LTMS_VERSION debe ser >= 2.9.408 (cache-busting del JS del ciclo UX2; 410 tras UX3).'
		);

		$min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js' );
		$this->assertStringContainsString( 'data-pv-scrollable', $min, 'HOME-UX2: el .min.js debe estar regenerado con el toggle del fade.' );
		$this->assertStringContainsString( 'data-pv-at-end', $min, 'HOME-UX2: el .min.js debe contener el toggle data-pv-at-end.' );
	}

	/**
	 * HOME-UX2-008a: FIX ESTRUCTURAL del scope HOME. El homeScope corre
	 * FUERA del IIFE principal del design system (cierra ~línea 1075), donde
	 * los helpers qs/qsa/on NO existen — el live search de HOME-REDESIGN-002
	 * moría en silencio con "ReferenceError: on is not defined" (verificado
	 * con window.onerror en producción: las sugerencias del buscador NUNCA
	 * funcionaron en runtime; lección #186 forma-vs-runtime de nuevo). El
	 * scope HOME debe declarar helpers locales ANTES de cualquier uso bare.
	 */
	public function test_010_scope_home_declara_helpers_locales(): void {
		$js_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.js';
		$this->assertFileExists( $js_path );
		$js = file_get_contents( $js_path );

		// 1. El IIFE principal del design system cierra ANTES del scope HOME.
		$pos_main_close = strpos( $js, '})(window, document);' );
		$pos_home_scope  = strpos( $js, '(function homeScope() {' );
		$this->assertNotFalse( $pos_main_close, 'HOME-UX2-008a: debe existir el cierre del IIFE principal.' );
		$this->assertNotFalse( $pos_home_scope, 'HOME-UX2-008a: debe existir el scope HOME.' );
		$this->assertLessThan(
			$pos_home_scope,
			$pos_main_close,
			'HOME-UX2-008a: el scope HOME corre FUERA del IIFE principal — no puede usar sus helpers internos.'
		);

		// 2. El scope HOME declara sus propios helpers al inicio.
		$home_scope_src = substr( $js, $pos_home_scope );
		$this->assertMatchesRegularExpression(
			"/\(function homeScope\(\) \{\s*\/\* HOME-UX2-008a/",
			$home_scope_src,
			'HOME-UX2-008a: el fix estructural debe documentarse al inicio del scope HOME.'
		);
		foreach ( array(
			'function on(el, ev, fn, opt)' => 'bind de eventos (live search + fade de categorías)',
			'function qs(sel, ctx)'        => 'querySelector del fade de categorías',
			'function qsa(sel, ctx)'       => 'querySelectorAll del combobox de sugerencias',
		) as $decl => $why ) {
			$this->assertStringContainsString(
				$decl,
				$home_scope_src,
				sprintf( 'HOME-UX2-008a: el scope HOME debe declarar helper local "%s" (%s).', $decl, $why )
			);
		}

		// 3. Las declaraciones van ANTES del primer uso bare de on( dentro del scope.
		$pos_decl_on = strpos( $home_scope_src, 'function on(el, ev, fn, opt)' );
		$pos_first_use = strpos( $home_scope_src, "on(input, 'input', function () {" );
		$this->assertNotFalse( $pos_first_use, 'HOME-UX2-008a: referencia de anclaje — el live search debe seguir usando on().' );
		$this->assertLessThan(
			$pos_first_use,
			$pos_decl_on,
			'HOME-UX2-008a: la declaración local de on() debe preceder a su primer uso en el scope HOME.'
		);

		// 4. El .min.js regenerado contiene las declaraciones locales (no quedó con el on bare muerto).
		$min = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ltms-plaza-viva.min.js' );
		$this->assertStringNotContainsString(
			'window.on=',
			$min,
			'HOME-UX2-008a: el min no debe inventar un on global — helpers LOCALES del scope HOME.'
		);
	}
}
