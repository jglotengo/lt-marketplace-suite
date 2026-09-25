<?php
/**
 * HomeSliderTest — tests del módulo LTMS_Frontend_Home_Slider (HOME-SLIDER FIX 2026-09-08).
 *
 * El slider de banners del home era el widget "Slides" de Elementor (Swiper.js).
 * Este módulo lo reemplaza por un carrusel propio del plugin, gestionado desde
 * LT Marketplace → Home Slider: el admin carga imágenes con las dimensiones
 * correctas (desktop panorámico + mobile opcional) y se actualizan solas.
 *
 * Tests source-based (patrón del proyecto) + smoke de la clase.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class HomeSliderTest extends LTMS_Unit_Test_Case {

	private const CLASS_PATH = __DIR__ . '/../../includes/frontend/class-ltms-frontend-home-slider.php';
	private const VIEW_PATH  = __DIR__ . '/../../includes/admin/views/html-admin-home-slider.php';
	private const JS_PATH    = __DIR__ . '/../../assets/js/ltms-home-slider.js';
	private const CSS_PATH   = __DIR__ . '/../../assets/css/ltms-home-slider.css';

	public function test_class_file_exists(): void {
		$this->assertFileExists( self::CLASS_PATH );
	}

	public function test_module_registered_in_kernel(): void {
		$kernel = file_get_contents( __DIR__ . '/../../includes/core/class-ltms-kernel.php' );
		$this->assertStringContainsString(
			"LTMS_Frontend_Home_Slider::init();",
			$kernel,
			'HOME-SLIDER: el módulo debe registrarse en boot_frontend del kernel.'
		);
	}

	public function test_option_key_and_shortcode_registered(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			"const OPTION_KEY = 'ltms_home_slides';",
			$src,
			'HOME-SLIDER: debe persistir los slides en la opción ltms_home_slides.'
		);
		$this->assertStringContainsString(
			"add_shortcode( 'ltms_home_slider',",
			$src,
			'HOME-SLIDER: debe registrarse el shortcode ltms_home_slider.'
		);
	}

	public function test_injects_on_front_page_and_hides_elementor(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			'public function inject_home_slider(): void',
			$src,
			'HOME-SLIDER: debe existir el método de inyección automática en el home.'
		);
		$this->assertStringContainsString(
			"if ( ! is_front_page() ) {",
			$src,
			'HOME-SLIDER: la inyección debe limitarse a la página frontal.'
		);
		$this->assertStringContainsString(
			'.elementor-widget-slides',
			$src,
			'HOME-SLIDER: debe ocultar el widget Slides de Elementor (reemplazo automático).'
		);
	}

	public function test_render_uses_picture_for_desktop_and_mobile(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			"<source media=\"(max-width: 767px)\"",
			$src,
			'HOME-SLIDER: el render debe usar <picture> para imagen mobile distinta.'
		);
		$this->assertStringContainsString(
			"ltms-hs__slide",
			$src,
			'HOME-SLIDER: el render debe generar slides con la clase ltms-hs__slide.'
		);
	}

	public function test_ajax_handlers_sanitize_and_check_permissions(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			"check_ajax_referer( 'ltms_home_slider_nonce', 'nonce' );",
			$src,
			'HOME-SLIDER: todos los AJAX deben verificar nonce ltms_home_slider_nonce.'
		);
		$this->assertStringContainsString(
			"current_user_can( 'ltms_manage_platform_settings' )",
			$src,
			'HOME-SLIDER: los AJAX deben requerir ltms_manage_platform_settings.'
		);
		$this->assertStringContainsString(
			"'image_desktop' => esc_url_raw( \$s['image_desktop'] ?? '' )",
			$src,
			'HOME-SLIDER: image_desktop debe sanitizarse con esc_url_raw.'
		);
		$this->assertStringContainsString(
			"sanitize_text_field( \$s['cta_text'] ?? '' )",
			$src,
			'HOME-SLIDER: cta_text debe sanitizarse con sanitize_text_field.'
		);
	}

	public function test_admin_view_has_dimensions_guide_and_upload(): void {
		$src = file_get_contents( self::VIEW_PATH );

		$this->assertStringContainsString(
			'1920 × 600–800 px',
			$src,
			'HOME-SLIDER: la vista admin debe mostrar las dimensiones recomendadas de desktop.'
		);
		$this->assertStringContainsString(
			'ltms-hs-upload',
			$src,
			'HOME-SLIDER: la vista admin debe tener botones de subir imagen.'
		);
		$this->assertStringContainsString(
			'ltms-hs-save',
			$src,
			'HOME-SLIDER: la vista admin debe tener botón de guardar.'
		);
	}

	public function test_js_has_carousel_and_admin_handlers(): void {
		$js = file_get_contents( self::JS_PATH );

		$this->assertStringContainsString(
			'function initCarousel()',
			$js,
			'HOME-SLIDER: el JS debe tener el carrusel del frontend.'
		);
		$this->assertStringContainsString(
			'function initAdmin()',
			$js,
			'HOME-SLIDER: el JS debe tener el gestor del admin.'
		);
		$this->assertStringContainsString(
			"'ltms_home_slider_save'",
			$js,
			'HOME-SLIDER: el JS admin debe llamar ltms_home_slider_save.'
		);
		$this->assertStringContainsString(
			'touchstart',
			$js,
			'HOME-SLIDER: el carrusel debe soportar swipe táctil.'
		);
	}

	public function test_css_has_responsive_breakpoint(): void {
		$css = file_get_contents( self::CSS_PATH );

		$this->assertStringContainsString(
			'@media (max-width: 767px)',
			$css,
			'HOME-SLIDER: el CSS debe tener breakpoint mobile.'
		);
		$this->assertStringContainsString(
			'aspect-ratio: 16 / 5',
			$css,
			'HOME-SLIDER: el CSS debe usar aspect-ratio panorámico en desktop.'
		);
	}

	public function test_min_js_generated_and_contains_handler(): void {
		$min = __DIR__ . '/../../assets/js/ltms-home-slider.min.js';
		$this->assertFileExists( $min, 'El .min del JS debe existir (trackeado).' );
		$content = file_get_contents( $min );
		// Terser minifica con mangle (renombra initCarousel → t()); verificamos
		// el DOM real del carrusel y la acción admin en lugar del nombre.
		$this->assertStringContainsString(
			'data-ltms-hs-track',
			$content,
			'HOME-SLIDER: el .min debe incluir el track del carrusel.'
		);
		$this->assertStringContainsString(
			'ltms_home_slider_save',
			$content,
			'HOME-SLIDER: el .min debe incluir la acción admin de guardado.'
		);
		$this->assertStringContainsString(
			'wp.media',
			$content,
			'HOME-SLIDER: el .min debe usar la Media Library para subir imágenes.'
		);
	}

	public function test_class_loads_and_exposes_shortcode(): void {
		$this->require_class( 'LTMS_Frontend_Home_Slider' );
		$this->assertTrue( class_exists( 'LTMS_Frontend_Home_Slider' ), 'La clase debe cargar.' );
		$rc = new \ReflectionClass( 'LTMS_Frontend_Home_Slider' );
		$this->assertTrue( $rc->hasMethod( 'render_shortcode' ), 'Debe existir render_shortcode.' );
		$this->assertTrue( $rc->hasMethod( 'ajax_save_slides' ), 'Debe existir ajax_save_slides.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HOME-SLIDER-ORDER-FIX (2026-09-24): el submenu debe registrarse con
	// prioridad 20 en admin_menu — DESPUÉS de LTMS_Admin::register_menus @10.
	//
	// Causa raíz verificada en server (WP 7.1.2): boot_frontend corre ANTES que
	// boot_admin en el Kernel → el admin_menu hook de HS se registra primero y
	// corre primero → al momento de add_submenu_page() el padre 'ltms-dashboard'
	// AÚN no existe en $admin_page_hooks → WP calcula el hookname SIN el prefijo
	// del padre (admin_page_ltms-home-slider), pero el acceso
	// (user_can_access_admin_page) y el render del menú (menu-header.php) lo
	// calculan CON prefijo (lt-marketplace_page_ltms-home-slider) porque para
	// entonces el padre ya existe → mismatch: el menú renderiza el href CRUDO
	// (slug → /wp-admin/ltms-home-slider → 404 del frontend al click) y el
	// acceso directo a la URL correcta da "Lo siento, no tienes permisos".
	// Reproducido con wp eval-file en server: access=false, registered(prefijo)=false,
	// registered(admin_page_*)=true.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_admin_menu_hook_uses_late_priority(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			"add_action( 'admin_menu', [ \$instance, 'register_admin_menu' ], 20 )",
			$src,
			'El submenu debe registrarse en admin_menu con prioridad 20 (después del menú padre @10) — HOME-SLIDER-ORDER-FIX.'
		);
		// El registro SIN prioridad (el bug) no debe persistir.
		$this->assertStringNotContainsString(
			"add_action( 'admin_menu', [ \$instance, 'register_admin_menu' ] )",
			$src,
			'NO debe persistir el registro de admin_menu sin prioridad (causaba el mismatch de hookname y el 404 del menú).'
		);
	}

	public function test_order_fix_documented_with_evidence(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			'HOME-SLIDER-ORDER-FIX (2026-09-24)',
			$src,
			'El fix debe documentarse con el ID HOME-SLIDER-ORDER-FIX y la fecha.'
		);
		$this->assertStringContainsString(
			'admin_page_ltms-home-slider',
			$src,
			'El comentario debe documentar el hookname sin prefijo (la causa raíz del mismatch).'
		);
		$this->assertStringContainsString(
			'lt-marketplace_page_ltms-home-slider',
			$src,
			'El comentario debe documentar el hookname con prefijo que buscan acceso/render.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HOME-SLIDER-IMAGES-FIX (2026-09-24): los banners cargados en el admin no
	// se veían en el home. Causa raíz verificada de punta a punta en server
	// (HTTP 200, home real con 3 banners activos): inject_home_slider() corría
	// en wp_footer @20 y DENTRO de ese callback registraba
	// add_action( 'wp_body_open', ... ) — pero wp_body_open dispara al INICIO
	// del <body> (header.php), ANTES que wp_footer, así que el hook registrado
	// tarde NUNCA corría → render_slider() nunca se imprimía. Neto: el estilo
	// que OCULTA el widget de Elementor SÍ se imprimía
	// (ltms-home-slider-hide-elementor presente en el HTML servido) pero el
	// slider NO existía (data-ltms-hs ausente) → Elementor oculto + slider
	// ausente = home sin banners. Fix: el render viaja en el filtro
	// the_content @10 registrado en init(), con guards (is_front_page,
	// in_the_loop, page_on_front para nested loops), flag $this->rendered
	// anti-duplicado y guard de shortcode. Fallback: wp_footer con guard
	// rendered. Verificado empíricamente en server (render simulado del front
	// page 30 con el template real index.php): the_content dispara con
	// is_front_page()=true e in_the_loop()=true y el banner aterriza dentro de
	// <main id="content"> → .page-content, justo antes del contenido Elementor
	// (debajo del header de navegación) — la posición exacta del widget Slides.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_render_wired_via_the_content_filter_not_body_open_from_footer(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			"add_filter( 'the_content', [ \$instance, 'prepend_slider_to_content' ], 10 )",
			$src,
			'El render debe viajar en el filtro the_content @10 registrado en init() — HOME-SLIDER-IMAGES-FIX.'
		);
		// El patrón del bug (registrar wp_body_open con closure DESDE el callback
		// de wp_footer) no debe persistir: el hook registrado en wp_footer nunca
		// corre porque wp_body_open ya disparó al inicio del <body>.
		$this->assertStringNotContainsString(
			"add_action( 'wp_body_open', function",
			$src,
			'NO debe persistir el registro de wp_body_open con closure desde wp_footer (el hook registrado tarde nunca corre → banners invisibles).'
		);
		$this->assertStringContainsString(
			'public function prepend_slider_to_content(',
			$src,
			'Debe existir el método prepend_slider_to_content (render vía the_content).'
		);
	}

	public function test_content_filter_guards_front_page_loop_and_nested_loops(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			'! is_front_page() || ! in_the_loop()',
			$src,
			'El filtro the_content debe limitarse al loop de la página frontal (evita inyectar el banner en widgets/contenido ajeno).'
		);
		$this->assertStringContainsString(
			'get_the_ID() !== $front_id',
			$src,
			'El filtro debe ignorar nested loops (solo inyectar cuando el loop itera page_on_front).'
		);
	}

	public function test_content_filter_avoids_double_render(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			'if ( $this->rendered || ! is_front_page() || ! in_the_loop() )',
			$src,
			'El filtro debe respetar el flag rendered (evita el doble banner).'
		);
		$this->assertStringContainsString(
			"strpos( \$content, '[ltms_home_slider]' )",
			$src,
			'El filtro debe detectar el shortcode [ltms_home_slider] en el contenido para no anteponer el slider dos veces.'
		);
		$this->assertStringContainsString(
			"strpos( \$content, 'data-ltms-hs' )",
			$src,
			'El filtro debe detectar markup del slider ya renderizado (shortcode expandido por Elementor) para no duplicar.'
		);
	}

	public function test_footer_fallback_guards_rendered_flag(): void {
		$src = file_get_contents( self::CLASS_PATH );

		$this->assertStringContainsString(
			'public function inject_home_slider(): void',
			$src,
			'Debe existir el fallback inject_home_slider (templates sin loop/the_content).'
		);
		$this->assertStringContainsString(
			'if ( $this->rendered ) {' . "\n" . '            return;' . "\n" . '        }' . "\n" . '        if ( ! is_front_page() ) {',
			$src,
			'El fallback de wp_footer debe verificar el flag rendered ANTES de renderizar (evita el doble banner).'
		);
		$this->assertStringContainsString(
			'HOME-SLIDER-IMAGES-FIX (2026-09-24)',
			$src,
			'El fix debe documentarse con el ID HOME-SLIDER-IMAGES-FIX y la fecha.'
		);
	}
}