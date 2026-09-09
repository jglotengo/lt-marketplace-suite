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
}