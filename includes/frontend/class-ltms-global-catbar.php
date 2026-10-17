<?php
/**
 * LTMS Global Catbar — barra global de categorías del marketplace.
 *
 * HOME-CATBAR-2026-004 (2026-10-15). Petición del operador:
 * "considera en el diseño una barra tanto para móvil como para escritorio,
 * una barra de categorías… he visto en la página de la storefront que
 * existe una con logo, mejórala en todas las páginas, que esa barra no se
 * oculte mientras se desplaza con los productos, que el usuario pueda
 * utilizarla para seleccionar las categorías que desee".
 *
	 * Barra sticky (position:sticky top:0 — nunca se oculta al escrolear)
	 * con TODAS las categorías activas como chips en fila única deslizable.
	 * Fondo ROJO DE MARCA #E80001 con chips BLANCOS y texto #222 desde
	 * HOME-CATBAR-2026-009 (feedback del operador: "cambia solo la parte
	 * del fondo a color rojo y mejora el contraste de las formas que
	 * contienen el texto — que sean de otro color y amigable a la vista").
	 * SIN logo: "cada página ya tiene su logo, es redundante" (operador).
	 * La categoría activa se resalta (is-active + aria-current) en los
	 * archivos de categoría. Misma fuente de datos que la home:
 * LTMS_Utils::get_normalized_product_categories() (dedup por fingerprint +
 * orden por # de productos), con la exclusión del artefacto 'no-aplica'.
 *
	 * Dónde NO renderiza (documentado):
	 *   - Home nativa: NO renderiza via wp_body_open — su template la
	 *     renderiza INLINE dentro del header sticky (llamado
	 *     LTMS_Global_Catbar::render_bar( 'home_header' ) desde home.php,
	 *     HOME-CATBAR-2026-006): server-side, sin dependencia de JS de
	 *     reubicación ni flash de reubicamiento.
	 *   - Panel del vendedor (HOME-CATBAR-2026-011): TODAS sus páginas son
	 *     app, no catálogo — dashboard, pedidos, billetera, KYC, seguros,
	 *     reservas y RNT. Se detecta por ID (opción ltms_installed_pages)
	 *     Y por slug (cubre páginas duplicadas heredadas: la opción apunta
	 *     a /panel-vendedor/ pero el operador usa /ltms-dashboard/). En esa
	 *     vista el body class ltms-panel-view oculta además el header del
	 *     tema (el "hero" con el buscador) — petición del operador.
	 *   - Checkout / order-received: protección del funnel de pago (misma
 *     filosofía de HOME-REDESIGN-007 — nada distrae del checkout).
 *   - Panel del vendedor (dashboard/wallet/orders): páginas de app, no de
 *     catálogo.
 *   - Vitrina del vendedor (/vendedor/{slug}): NO porque el módulo se
 *     engancha a wp_body_open y la vitrina imprime su propio <body> (print_head
 *     de LTMS_Vendor_Storefront) sin disparar el hook — además su topbar
 *     sticky con logo es la referencia de este diseño (verificado:
 *     position:sticky !important, nunca se oculta) y sus categorías son
 *     filtros multi-select propios del sidebar.
 *
	 * HOME-CATBAR-2026-006/009 (feedback del operador):
	 * (1) Fondo ROJO DE MARCA #E80001 (009: "cambia solo la parte del fondo
	 * a color rojo") + chips BLANCOS con texto #222 (009: "mejora el
	 * contraste de las formas... amigable a la vista" — texto 15.9:1 AAA,
	 * chip sobre rojo 4.7:1); chip activo dorado #F7B731 con texto negro
	 * 11.8:1 (AAA). (2) SIN logo — "cada página ya tiene su logo, es
	 * redundante": eliminado de la barra en todas las páginas. (3) La fila
	 * se desliza también en ESCRITORIO: rueda del mouse → scroll horizontal
	 * + arrastre con puntero (assets/js/ltms-catbar.js). (4) En la home la
	 * barra se renderiza SERVER-SIDE dentro de su header sticky (ver
	 * arriba) — una sola barra de categorías en todo el proyecto.
	 *
	 * Ubicación: (a) home nativa → home.php la imprime DENTRO de su header
	 * sticky (última fila, hereda el "nunca se oculta"); (b) demás páginas →
	 * wp_body_open y el JS (assets/js/ltms-catbar.js) la reubica DEBAJO del
	 * header del tema (.elementor-location-header — verificado en
	 * producción: el header del tema NO es sticky, por lo que al escrolear
	 * la barra queda sola arriba, siempre visible).
 *
 * @package LTMS
 * @since   2.9.414
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LTMS_Global_Catbar {

	/**
	 * Init: hooks de render + assets.
	 */
	public static function init(): void {
		/* HOME-CATBAR-2026-006b: accepted_args EXPLÍCITO en 0. do_action()
		 * antepone un '' dummy como primer argumento a todo callback con
		 * accepted_args>=1 (WP_Hook::apply_filters hace array_merge con el
		 * $value vacío de las actions) — verificado live: render_bar llegaba
		 * con $context='' desde el hook, el default 'wp_body_open' NUNCA
		 * aplicaba, la exclusión de la home no matcheaba y la barra se
		 * duplicaba sobre la inline del header. */
		add_action( 'wp_body_open', [ __CLASS__, 'render_bar' ], 10, 0 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		/* HOME-CATBAR-2026-011: body class de la vista del panel del
		 * vendedor — el CSS oculta el header del tema ahí (el "hero" con
		 * el buscador; petición del operador: "ocúltalos para esa vista"). */
		add_filter( 'body_class', [ __CLASS__, 'panel_body_class' ] );
	}

	/**
	 * Encola el CSS + JS de la barra (global — el render decide por página).
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		$ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.414';
		$url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '';
		if ( '' === $url ) {
			return;
		}

		wp_enqueue_style( 'ltms-catbar', $url . 'css/ltms-catbar.css', [], $ver );

		// Defer: la reubicación bajo el header del tema corre tras el parse.
		wp_enqueue_script( 'ltms-catbar', ltms_asset_url( 'js/ltms-catbar' ), [], $ver, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	/**
	 * ¿Debe renderizar la barra en esta request?
	 *
	 * Exclusiones documentadas en el docblock de la clase.
	 *
	 * @param string $context 'wp_body_open' (hook global) o 'home_header'
	 *                        (render INLINE dentro del header sticky de la
	 *                        home nativa, HOME-CATBAR-2026-006).
	 * @return bool
	 */
	public static function should_render( string $context = 'wp_body_open' ): bool {
		if ( is_admin() ) {
			return false;
		}

		// Home nativa: excluida del wp_body_open porque su template la
		// renderiza INLINE dentro del header sticky (llamado desde home.php
		// con $context = 'home_header' — HOME-CATBAR-2026-006).
		if ( 'wp_body_open' === $context
			&& get_option( 'ltms_home_template_enabled', 'yes' ) === 'yes'
			&& is_front_page() && is_page() ) {
			return false;
		}

		// Checkout + order-received: protección del funnel.
		if ( function_exists( 'is_checkout' )
			&& ( is_checkout() || ( function_exists( 'wc_get_page_id' ) && is_page( wc_get_page_id( 'checkout' ) ) ) ) ) {
			return false;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}

		// Panel del vendedor: TODAS sus páginas son app, no catálogo
		// (HOME-CATBAR-2026-011 — por ID y por slug, ver helper).
		if ( self::is_vendor_panel_page() ) {
			return false;
		}

		return true;
	}

	/**
	 * ¿Es una página del panel del vendedor (app, no de catálogo)?
	 *
	 * HOME-CATBAR-2026-011: el operador veía la barra en /ltms-dashboard/
	 * porque la opción ltms_installed_pages apunta a la página DUPLICADA
	 * /panel-vendedor/ (14343) mientras la usada es /ltms-dashboard/
	 * (14337). Se cubre por ID (opción) Y por slug (duplicadas/futuras):
	 * dashboard, pedidos, billetera, KYC, seguros, reservas y RNT.
	 *
	 * @return bool
	 */
	private static function is_vendor_panel_page(): bool {
		$pages = get_option( 'ltms_installed_pages', [] );
		if ( is_array( $pages ) ) {
			foreach ( array(
				'ltms-dashboard', 'ltms-orders', 'ltms-wallet',
				'ltms-kyc', 'ltms-insurance', 'ltms-bookings', 'ltms-rnt',
			) as $key ) {
				if ( ! empty( $pages[ $key ] ) && is_page( (int) $pages[ $key ] ) ) {
					return true;
				}
			}
		}
		foreach ( array(
			'ltms-dashboard', 'panel-vendedor', 'mis-pedidos', 'mi-billetera',
			'verificacion-identidad', 'mis-seguros', 'mis-reservas', 'rnt-turismo',
		) as $panel_slug ) {
			if ( is_page( $panel_slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HOME-CATBAR-2026-011: clase body para la vista del panel del
	 * vendedor. El CSS de la barra (ltms-catbar.css) oculta el header del
	 * tema (el "hero" con el buscador FiboSearch, logo y carrito) en esa
	 * vista — petición del operador: en el panel, desde PC o móvil, se
	 * veían la barra de categorías y el hero del navegador; la vista
	 * queda como app limpia con su propia navegación interna.
	 *
	 * @param array $classes Clases body.
	 * @return array
	 */
	public static function panel_body_class( array $classes ): array {
		if ( self::is_vendor_panel_page() ) {
			$classes[] = 'ltms-panel-view';
		}
		return $classes;
	}

	/**
	 * Renderiza la barra.
	 *
	 * @param string $context 'wp_body_open' (hook global) o 'home_header'
	 *                        (render INLINE desde home.php).
	 */
	public static function render_bar( string $context = 'wp_body_open' ): void {
		/* HOME-CATBAR-2026-006b (hallazgo live): normalizar el contexto —
		 * todo valor que no sea 'home_header' se trata como wp_body_open.
		 * Cinturón y tirantes frente al '' dummy que do_action pasa como
		 * primer argumento (ver accepted_args=0 en init()). */
		if ( 'home_header' !== $context ) {
			$context = 'wp_body_open';
		}

		if ( ! self::should_render( $context ) ) {
			return;
		}

		$terms = self::get_categories();
		if ( empty( $terms ) ) {
			return;
		}

		/* HOME-CATBAR-2026-006: SIN logo — "cada página ya tiene su logo,
		 * es redundante" (feedback del operador; el blanco sigue vivo en
		 * header/footer de la home, BRAND-2026-001). */
		echo '<nav class="ltms-catbar" aria-label="' . esc_attr__( 'Categorías del marketplace', 'ltms' ) . '">';
		echo '<div class="ltms-catbar__inner">';

		echo '<div class="ltms-catbar__track" role="list">';
		foreach ( $terms as $term ) {
			$term_id = (int) ( $term->term_id ?? 0 );
			$url      = $term_id ? get_term_link( $term_id ) : get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$is_active = $term_id
				&& function_exists( 'is_product_category' )
				&& is_product_category( $term_id );
			?>
			<a role="listitem"
			   class="ltms-catbar__chip<?php echo $is_active ? ' is-active' : ''; ?>"
			   href="<?php echo esc_url( $url ); ?>"
				<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $term->name ); ?>
			</a>
			<?php
		}

		/* HOME-CATBAR-2026-010: affordance profesional de cierre —
		 * "Ver todas →" al final de la fila (patrón Amazon "Ver todo").
		 * La URL de la tienda usa la MISMA fuente que el resto del plugin
		 * (wc_get_page_id('shop') con guard, ver home.php) y es filtrable
		 * via ltms_catbar_view_all_url. */
		$shop_url = function_exists( 'wc_get_page_id' )
			? (string) get_permalink( wc_get_page_id( 'shop' ) )
			: '';
		if ( '' === $shop_url ) {
			$shop_url = (string) home_url( '/' );
		}
		$view_all_url = apply_filters( 'ltms_catbar_view_all_url', $shop_url );
		?>
		<a role="listitem"
		   class="ltms-catbar__chip ltms-catbar__chip--all"
		   href="<?php echo esc_url( $view_all_url ); ?>">
			<?php esc_html_e( 'Ver todas →', 'ltms' ); ?>
		</a>
		<?php
		echo '</div>';

		echo '</div>';
		echo '</nav>';
	}

	/**
	 * Categorías activas normalizadas (misma fuente que la home).
	 *
	 * HOME-MATRIX-FIX: excluye el artefacto de syncs 'no-aplica'.
	 *
	 * @return array
	 */
	private static function get_categories(): array {
		if ( class_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'get_normalized_product_categories' ) ) {
			$all = array_filter( LTMS_Utils::get_normalized_product_categories( true ), static function ( $c ) {
				return isset( $c->slug ) && 'no-aplica' !== $c->slug;
			} );
			return array_values( $all );
		}

		// Fallback: get_terms crudo (todas las activas).
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}
}
