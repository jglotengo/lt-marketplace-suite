<?php
/**
 * LTMS PV Footer — footer Plaza Viva compartido en TODAS las páginas públicas
 *
 * FOOTER-GLOBAL-2026-10-24 (operador: "lleva el footer del home a todas las
 * páginas públicas del proyecto, incluidas mi cuenta, mis pedidos, registro
 * vendedores y recuperación de contraseña — es decir, a todas").
 *
 * Extrae el footer de la home nativa (HOME-UX2-005 → BRAND-2026-001 →
 * FOOTER-BRANDS-2026-10-23) a un ÚNICO componente:
 *
 *  - Markup: <footer class="pv-home-footer"> con marca (logo oficial
 *    variante blanca), redes reales del operador, fila de pagos/afiliaciones
 *    (PNG transparentes con filtro dark-mode invert+hue-rotate — ver
 *    FOOTER-BRANDS-2026-10-23) + selector de moneda y línea de ©.
 *  - Inyección: hook wp_footer (accepted_args=0 — lección #190: do_action
 *    antepone un dummy como primer argumento). Cubre TODAS las páginas cuyo
 *    tema llama wp_footer() — home nativa, tienda, producto, carrito,
 *    checkout, mi cuenta, legales, blog, funnel auth del vendedor, panel
 *    del vendedor y vitrina /vendedor/{slug} (que emite su propio HTML
 *    completo pero SÍ llama wp_footer() y usa body_class()).
 *  - home.php ya NO imprime su propio footer: lo recibe por el mismo hook
 *    (una sola fuente de verdad).
 *  - Exclusiones (decisión del operador 2026-10-24: "todas sin excepción"):
 *    SOLO los contextos sin render HTML (wp-admin, AJAX, REST). El panel
 *    del vendedor, el funnel auth y la vitrina SÍ lo llevan — a diferencia
 *    del header global (REG-CHOICE-008), que conservó el chrome propio.
 *  - El footer del tema se oculta vía body class pv-pv-footer (CSS del
 *    componente, selectores precisos — NUNCA un bare `footer`, que
 *    matchearía .pv-home-footer propio). El footer minimal de la vitrina
 *    (.ltms-sf-footer) también se oculta en favor de este.
 *
 * @package LTMS
 * @since   2.9.429
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salida directa no permitida.
}

class LTMS_PV_Footer {

	/**
	 * Flag anti-duplicado: true cuando este componente ya renderizó.
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
		add_action( 'wp_footer', [ __CLASS__, 'maybe_render' ], 10, 0 );
		add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * CSS del componente (global front-end). Depende del design system
	 * (tokens :root + .pv-section de plaza-viva.css) y del CSS del header
	 * compartido (mismo boot order del kernel).
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		$ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.429';
		$url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '';
		wp_enqueue_style( 'ltms-pv-footer', $url . 'css/ltms-pv-footer.css', [ 'ltms-plaza-viva', 'ltms-pv-header' ], $ver );
	}

	/**
	 * ¿Corresponde renderizar el footer PV en el request actual?
	 *
	 * FOOTER-GLOBAL-2026-10-24: TODAS las páginas públicas lo llevan — el
	 * operador pidió "todas sin excepción". Solo se excluyen los contextos
	 * sin render HTML de página (admin, AJAX, REST). Nótese la diferencia
	 * con LTMS_PV_Header::should_render(): el header conservó el chrome
	 * propio del panel del vendedor y del funnel auth (REG-CHOICE-008),
	 * pero el footer SÍ se muestra ahí (petición explícita del operador,
	 * incluye "mis pedidos", "registro vendedores" y "recuperación de
	 * contraseña" en la lista de páginas que lo deben llevar).
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Body class del componente — activa el CSS que oculta el footer del
	 * tema (y el minimal de la vitrina) en todas las páginas públicas.
	 *
	 * @param array $classes Clases actuales del body.
	 * @return array
	 */
	public static function add_body_class( array $classes ): array {
		if ( self::should_render() ) {
			$classes[] = 'pv-pv-footer';
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
	 * Hook wp_footer: renderiza el footer si corresponde.
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
	 * Render del footer (markup canónico — una sola fuente de verdad;
	 * home.php lo recibe por el hook como cualquier otra página pública).
	 *
	 * @return void
	 */
	public static function render(): void {
		/* --- Redes reales del operador (HOME-UX2-005) + marcas de
		 * pagos/afiliaciones (HOME-UX3-002 + FOOTER-BRANDS-2026-10-23:
		 * srcs full-size retina + width/height CLS 0). Los filtros
		 * ltms_home_footer_socials / ltms_home_footer_brands se conservan
		 * con su nombre original (backward compat con la era home-only). */
		$pv_socials = apply_filters( 'ltms_home_footer_socials', array(
			array( 'label' => 'Instagram', 'url' => 'https://www.instagram.com/lotengooficial/', 'icon' => 'instagram' ),
			array( 'label' => 'TikTok',    'url' => 'https://www.tiktok.com/@lotengocolombia',  'icon' => 'tiktok' ),
			array( 'label' => 'YouTube',   'url' => 'https://www.youtube.com/@lotengocolombia',  'icon' => 'youtube' ),
		) );

		$pv_footer_brands = apply_filters( 'ltms_home_footer_brands', array(
			array( 'src' => '/wp-content/uploads/2025/10/logos-footer-4.png', 'alt' => __( 'Pagos seguros: PSE, Nequi, Daviplata, Visa, Mastercard, Amex, OpenPay', 'ltms' ), 'width' => 640, 'height' => 360 ),
			array( 'src' => '/wp-content/uploads/2026/09/logo-lacardio.png', 'alt' => __( 'Fundación Cardioinfantil LaCardio', 'ltms' ), 'width' => 480, 'height' => 178 ),
			array( 'src' => '/wp-content/uploads/2025/10/logo-foot-1.png',    'alt' => __( 'Cámara de Comercio de Cali — La CCC', 'ltms' ), 'width' => 640, 'height' => 360 ),
			array( 'src' => '/wp-content/uploads/2025/10/logo-foot-2.png',    'alt' => __( 'Cámara Colombiana de Comercio Electrónico', 'ltms' ), 'width' => 640, 'height' => 360 ),
		) );
		?>
		<footer class="pv-home-footer" role="contentinfo">
			<div class="pv-section pv-home-footer__inner">

				<?php /* --- Marca (BRAND-2026-001: logo oficial variante BLANCA —
					 * el footer es NEGRO #000000, color de marca del brief;
					 * PNG 769x324 a 36px de altura = densidad 9x). --- */ ?>
				<a class="pv-home-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<img class="pv-home-footer__logo-img"
						 src="<?php echo esc_url( ( defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '' ) . 'img/logo-lo-tengo-white.png' ); ?>"
						 alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
						 width="85" height="36" />
				</a>

				<ul class="pv-home-footer__social" aria-label="<?php esc_attr_e( 'Redes sociales', 'ltms' ); ?>">
					<?php foreach ( $pv_socials as $pv_soc ) : ?>
						<li>
							<a class="pv-home-footer__social-link" href="<?php echo esc_url( $pv_soc['url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $pv_soc['label'] ); ?>">
								<?php echo self::social_icon( $pv_soc['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php /* --- Pagos, donaciones y afiliaciones (HOME-UX3-002 +
				 * FOOTER-BRANDS-2026-10-23) + selector de moneda (solo si
				 * multi-moneda activa). --- */ ?>
			<div class="pv-section pv-home-footer__payrow">
				<ul class="pv-home-footer__brands" aria-label="<?php esc_attr_e( 'Pagos seguros, donaciones y afiliaciones', 'ltms' ); ?>">
					<?php foreach ( $pv_footer_brands as $pv_brand ) : ?>
						<li class="pv-home-footer__brand-item">
							<img src="<?php echo esc_url( home_url( $pv_brand['src'] ) ); ?>" alt="<?php echo esc_attr( $pv_brand['alt'] ); ?>" width="<?php echo absint( $pv_brand['width'] ); ?>" height="<?php echo absint( $pv_brand['height'] ); ?>" loading="lazy" decoding="async" />
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				/* HOME-REDESIGN-006: selector de moneda solo si ya existe
				 * soporte multi-moneda (brief). El widget canónico del
				 * checkout reutilizado — hace bail defensivo si el motor
				 * cross-border no está cargado o no hay monedas habilitadas
				 * (renderiza nada). */
				if ( class_exists( 'LTMS_Currency_Manager' ) && method_exists( 'LTMS_Currency_Manager', 'render_currency_selector' ) ) {
					LTMS_Currency_Manager::render_currency_selector();
				}
				?>
			</div>

			<div class="pv-home-footer__bottom">
				<div class="pv-section pv-home-footer__bottom-inner">
					<span>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. <?php esc_html_e( 'Todos los derechos reservados.', 'ltms' ); ?></span>
				</div>
			</div>
		</footer><!-- /.pv-home-footer -->
		<?php
		self::$did_render = true;
	}

	/**
	 * Icono SVG de red social (extraído de home.php con el footer — una
	 * sola fuente de verdad; la home ya no declara el helper).
	 *
	 * @param string $pv_key Clave del icono (instagram, facebook, tiktok, youtube, x).
	 * @return string Markup SVG.
	 */
	private static function social_icon( string $pv_key ): string {
		$pv_icons = array(
			'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
			'facebook'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
			'tiktok'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
			'youtube'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>',
			'x'         => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
		);
		return $pv_icons[ $pv_key ] ?? '';
	}
}
