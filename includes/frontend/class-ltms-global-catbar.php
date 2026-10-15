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
 * con el logo oficial "Lo Tengo" (variante blanca, BRAND-2026-001) y TODAS
 * las categorías activas como chips en fila única deslizable. La categoría
 * activa se resalta (is-active + aria-current) en los archivos de
 * categoría. Misma fuente de datos que la home:
 * LTMS_Utils::get_normalized_product_categories() (dedup por fingerprint +
 * orden por # de productos), con la exclusión del artefacto 'no-aplica'.
 *
	 * Dónde NO renderiza (documentado):
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
	 * HOME-CATBAR-2026-005 (2026-10-15, operador — "reemplace la barra que
	 * existe en las páginas del proyecto por esta"): la barra ahora TAMBIÉN
	 * renderiza en la home nativa, REEMPLAZANDO la fila de chips que su header
	 * llevaba desde HOME-CATBAR-2026-003 — una sola barra de categorías en
	 * todo el proyecto. En la home el JS la adopta DENTRO del header sticky
	 * (.pv-home-header, última fila): hereda el "nunca se oculta" del header,
	 * va sin logo duplicado (el CSS esconde .ltms-catbar__home en el scope
	 * home) y su rojo contrasta con el hero de fondo claro justo debajo.
	 *
	 * Ubicación: se imprime en wp_body_open (inicio del body) y el JS
	 * (assets/js/ltms-catbar.js) la reubica: (a) home nativa → dentro de su
	 * header sticky; (b) demás páginas → DEBAJO del header del tema
	 * (.elementor-location-header — verificado en producción: el header del
	 * tema NO es sticky, por lo que al escrolear la barra queda sola arriba,
	 * siempre visible).
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
		add_action( 'wp_body_open', [ __CLASS__, 'render_bar' ], 10 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
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
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( is_admin() ) {
			return false;
		}

		// HOME-CATBAR-2026-005: la home nativa YA NO se excluye — la barra
		// reemplaza la fila de chips de su header (el JS la adopta dentro
		// del header sticky como última fila).

		// Checkout + order-received: protección del funnel.
		if ( function_exists( 'is_checkout' )
			&& ( is_checkout() || ( function_exists( 'wc_get_page_id' ) && is_page( wc_get_page_id( 'checkout' ) ) ) ) ) {
			return false;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}

		// Panel del vendedor: dashboard / wallet / orders son app, no catálogo.
		$pages = get_option( 'ltms_installed_pages', [] );
		if ( is_array( $pages ) ) {
			foreach ( array( 'ltms-dashboard', 'ltms-wallet', 'ltms-orders' ) as $key ) {
				if ( ! empty( $pages[ $key ] ) && is_page( (int) $pages[ $key ] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Renderiza la barra (hook wp_body_open).
	 */
	public static function render_bar(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$terms = self::get_categories();
		if ( empty( $terms ) ) {
			return;
		}

		// Logo oficial (variante blanca — la barra es roja de marca #E80001).
		$logo_url = '';
		if ( defined( 'LTMS_ASSETS_URL' )
			&& file_exists( LTMS_PLUGIN_DIR . 'assets/img/logo-lo-tengo-white.png' ) ) {
			$logo_url = LTMS_ASSETS_URL . 'img/logo-lo-tengo-white.png';
		}

		echo '<nav class="ltms-catbar" aria-label="' . esc_attr__( 'Categorías del marketplace', 'ltms' ) . '">';
		echo '<div class="ltms-catbar__inner">';

		if ( '' !== $logo_url ) {
			echo '<a class="ltms-catbar__home" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
			echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" width="52" height="22" />';
			echo '</a>';
		}

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
