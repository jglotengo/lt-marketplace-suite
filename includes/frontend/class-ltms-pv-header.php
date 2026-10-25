<?php
/**
 * LTMS PV Header — header Plaza Viva compartido en todas las páginas públicas
 *
 * REG-CHOICE-008 (2026-10-23, operador: "el header del home aplícalo para
 * todas las demás páginas públicas del proyecto y oculta el viejo").
 *
 * Extrae el header de la home nativa (HOME-REDESIGN-002 → BRAND-2026-001 →
 * HOME-UX-014) a un ÚNICO componente:
 *
 *  - Markup: <header class="pv-home-header"> con 3 zonas (logo oficial,
 *    buscador con sugerencias live + filtro categoría, acciones: Vender
 *    GRATIS + Cuenta/Favoritos/Carrito) + la barra global de categorías
 *    (LTMS_Global_Catbar) como última fila.
 *  - Inyección: hook wp_body_open (accepted_args=0 — lección #190: do_action
 *    antepone un dummy como primer argumento). Cubre TODAS las páginas cuyo
 *    tema emite wp_body_open (Hello Elementor lo hace), incluidas las páginas
 *    no-nativas (legales, blog...).
 *  - home.php ya NO imprime su propio header: lo recibe por el mismo hook
 *    (una sola fuente de verdad). El skip link y el resto del template quedan.
 *  - Exclusiones: panel del vendedor (HOME-CATBAR-2026-011, chrome de app).
 *    El funnel auth del vendedor SÍ lo lleva desde UIUX-BATCH-2026-10-25
 *    (operador: con Elementor Pro desactivado quedaba el header de respaldo
 *    del tema casi vacío = espacio en blanco y sin sección de cuenta).
 *  - La vitrina del vendedor (/vendedor/{slug}) imprime su HTML completo
 *    sin wp_body_open — no aplica.
 *  - El header del tema se oculta vía body class pv-pv-header (CSS del
 *    componente, selectores precisos — NUNCA un bare `header`).
 *  - La catbar se renderiza DENTRO del header (contexto 'home_header') y el
 *    hook global de la catbar (wp_body_open prio 10) se abstiene cuando este
 *    componente ya renderizó (did_render) — sin duplicados (lección barra
 *    duplicada, HOME-CATBAR-2026-006b).
 *
 * @package LTMS
 * @since   2.9.428
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salida directa no permitida.
}

class LTMS_PV_Header {

	/**
	 * Flag anti-duplicado de la catbar: true cuando este componente ya
	 * renderizó (y con él la barra). Lo consulta LTMS_Global_Catbar.
	 *
	 * @var bool
	 */
	private static $did_render = false;

	/**
	 * Registro de hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// accepted_args=0 EXPLÍCITO (lección #190): do_action antepone un ''
		// dummy como primer argumento a callbacks con accepted_args>=1.
		add_action( 'wp_body_open', [ __CLASS__, 'maybe_render' ], 5, 0 );
		add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * CSS del componente (global front-end). Depende del design system
	 * (tokens :root de plaza-viva.css).
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		$ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.428';
		$url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '';
		wp_enqueue_style( 'ltms-pv-header', $url . 'css/ltms-pv-header.css', [ 'ltms-plaza-viva' ], $ver );
	}

	/**
	 * ¿Corresponde renderizar el header PV en el request actual?
	 * Front-end público — excluye admin, AJAX y el panel del vendedor
	 * (app con chrome propio por decisión del operador, HOME-CATBAR-2026-011).
	 *
	 * UIUX-BATCH-2026-10-25 (operador): el funnel auth del vendedor
	 * (registro/login/recuperar contraseña) YA NO se excluye. Con Elementor
	 * Pro desactivado, esas páginas quedaban con el header de respaldo
	 * (casi vacío) del tema Hello = espacio en blanco, y sin la sección
	 * "Ingresar / Registrarse". Ahora llevan el header PV estándar — su
	 * propio CSS (body.pv-pv-header) oculta el header del tema y el funnel
	 * mantiene acceso visual a cuenta/carrito/favoritos.
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( class_exists( 'LTMS_Global_Catbar' ) ) {
			if ( LTMS_Global_Catbar::is_vendor_panel_page() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Body class del componente — activa el CSS que oculta el header del tema.
	 *
	 * @param array $classes Clases actuales del body.
	 * @return array
	 */
	public static function add_body_class( array $classes ): array {
		if ( self::should_render() ) {
			$classes[] = 'pv-pv-header';
		}
		return $classes;
	}

	/**
	 * ¿Ya renderizó este componente en el request actual?
	 *
	 * @return bool
	 */
	public static function did_render(): bool {
		return self::$did_render;
	}

	/**
	 * Hook wp_body_open: renderiza el header si corresponde.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! self::should_render() ) {
			return;
		}
		self::render();
	}

	/**
	 * Render del header (markup canónico — una sola fuente de verdad; home.php
	 * lo recibe por el hook como cualquier otra página pública).
	 *
	 * @return void
	 */
	public static function render(): void {
		$shop_url     = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );
		$account_url  = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'myaccount' ) ) : home_url( '/mi-cuenta/' );
		$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/carrito/' );
		$cart_count   = ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) ? (int) WC()->cart->get_cart_contents_count() : 0;
		$wishlist_url = apply_filters( 'ltms_wishlist_url', home_url( '/favoritos' ) );
		$wishlist_count = apply_filters( 'ltms_wishlist_count', 0 );

		// REG-CHOICE-001: landing de vendedores canónica por ID (mapa del activator).
		$installed_pages    = get_option( 'ltms_installed_pages', array() );
		$sellers_page_id    = (int) ( $installed_pages['ltms-sellers'] ?? 0 );
		$sellers_landing_url = apply_filters(
			'ltms_sellers_page_url',
			$sellers_page_id ? get_permalink( $sellers_page_id ) : home_url( '/sellers/' )
		);

		// Filtro de categoría del buscador (patrón Amazon — apply_shop_filters()).
		$cat_terms = array();
		if ( class_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'get_normalized_product_categories' ) ) {
			$all_cats = array_filter( LTMS_Utils::get_normalized_product_categories( true ), static function ( $c ) {
				return isset( $c->slug ) && 'no-aplica' !== $c->slug;
			} );
			$cat_terms = array_values( $all_cats );
		} else {
			$cat_terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			) );
		}
		?>
		<header class="pv-home-header" role="banner">
			<div class="pv-section pv-home-header__inner">

				<?php /* --- Zona 1: Logo "Lo Tengo" (BRAND-2026-001, variante blanca) --- */ ?>
				<a class="pv-home-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<img class="pv-home-header__logo-img"
						 src="<?php echo esc_url( ( defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '' ) . 'img/logo-lo-tengo-white.png' ); ?>"
						 alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
						 width="95" height="40" />
				</a>

				<?php /* --- Zona 2: Buscador (sugerencias live + filtro categoría) --- */ ?>
				<div class="pv-home-header__search" role="search">
					<form class="pv-home-header__search-form" method="get" action="<?php echo esc_url( $shop_url ); ?>">
						<label class="pv-visually-hidden" for="pv-home-search"><?php esc_html_e( 'Buscar productos', 'ltms' ); ?></label>
						<span class="pv-home-header__search-icon" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						</span>
						<?php if ( ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ) : ?>
							<label class="pv-visually-hidden" for="pv-home-search-cat"><?php esc_html_e( 'Todas las categorías', 'ltms' ); ?></label>
							<select id="pv-home-search-cat" class="pv-home-header__search-cat" name="product_cat">
								<option value=""><?php esc_html_e( 'Todas las categorías', 'ltms' ); ?></option>
								<?php foreach ( $cat_terms as $cat_term_opt ) : ?>
									<option value="<?php echo esc_attr( $cat_term_opt->slug ); ?>"><?php echo esc_html( $cat_term_opt->name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<input type="search"
							   id="pv-home-search"
							   class="pv-home-header__search-input"
							   name="s"
							   placeholder="<?php esc_attr_e( 'Buscar productos, marcas, vendedores...', 'ltms' ); ?>"
							   value="<?php echo esc_attr( get_search_query() ); ?>"
							   autocomplete="off"
							   role="combobox"
							   aria-autocomplete="list"
							   aria-expanded="false"
							   aria-controls="pv-home-suggestions" />
						<input type="hidden" name="post_type" value="product" />
						<button type="submit" class="pv-btn pv-btn--sm pv-home-header__search-btn">
							<?php esc_html_e( 'Buscar', 'ltms' ); ?>
						</button>
					</form>
					<div id="pv-home-suggestions" class="pv-home-header__suggestions" role="listbox" aria-label="<?php esc_attr_e( 'Sugerencias de búsqueda', 'ltms' ); ?>" hidden></div>
				</div>

				<?php /* --- Zona 3: Acciones (REG-CHOICE-005 Vender estático + REG-CHOICE-002 label guest) --- */ ?>
				<nav class="pv-home-header__actions" aria-label="<?php esc_attr_e( 'Acciones de cuenta y venta', 'ltms' ); ?>">
					<a class="pv-home-header__sell" href="<?php echo esc_url( $sellers_landing_url ); ?>" aria-label="<?php esc_attr_e( 'Vender en el marketplace — registro gratis', 'ltms' ); ?>">
						<?php esc_html_e( 'Vender', 'ltms' ); ?>
						<span class="pv-home-header__sell-free"><?php esc_html_e( 'GRATIS', 'ltms' ); ?></span>
					</a>
					<a class="pv-home-header__action" href="<?php echo esc_url( $account_url ); ?>" aria-label="<?php echo is_user_logged_in() ? esc_attr__( 'Mi cuenta', 'ltms' ) : esc_attr__( 'Ingresar o registrarse', 'ltms' ); ?>">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
						<span class="pv-home-header__action-label"><?php echo is_user_logged_in() ? esc_html__( 'Cuenta', 'ltms' ) : esc_html__( 'Ingresar / Registrarse', 'ltms' ); ?></span>
					</a>
					<a class="pv-home-header__action" href="<?php echo esc_url( $wishlist_url ); ?>" aria-label="<?php esc_attr_e( 'Favoritos', 'ltms' ); ?>">
						<span class="pv-home-header__action-icon">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
							<span class="pv-home-header__badge" data-pv-wishlist-count><?php echo esc_html( $wishlist_count > 0 ? number_format_i18n( $wishlist_count ) : '' ); ?></span>
						</span>
						<span class="pv-home-header__action-label"><?php esc_html_e( 'Favoritos', 'ltms' ); ?></span>
					</a>
					<a class="pv-home-header__action" href="<?php echo esc_url( $cart_url ); ?>" data-ltms-open-cart aria-label="<?php esc_attr_e( 'Carrito', 'ltms' ); ?>">
						<span class="pv-home-header__action-icon">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
							<?php if ( $cart_count > 0 ) : ?>
								<span class="pv-home-header__badge pv-home-header__badge--accent"><?php echo esc_html( number_format_i18n( $cart_count ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="pv-home-header__action-label"><?php esc_html_e( 'Carrito', 'ltms' ); ?></span>
					</a>
				</nav>

			</div>

			<?php /* Barra global de categorías — server-side dentro del header
				 * (última fila del header sticky). Solo si la propia catbar
				 * permite renderizar en este contexto (checkout/order-received
				 * excluidos por su lógica). El hook global de la catbar se
				 * abstiene cuando este componente renderizó (did_render). */ ?>
			<?php if ( class_exists( 'LTMS_Global_Catbar' ) && LTMS_Global_Catbar::should_render( 'home_header' ) ) : ?>
				<?php LTMS_Global_Catbar::render_bar( 'home_header' ); ?>
			<?php endif; ?>
		</header>
		<?php
		self::$did_render = true;
	}
}
