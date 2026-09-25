<?php
/**
 * LTMS Frontend Home Slider — Carrusel de banners del Home gestionado por LTMS.
 *
 * HOME-SLIDER FIX (2026-09-08): reemplaza el widget "Slides" de Elementor
 * (Swiper.js) por un carrusel propio del plugin, 100% gestionado desde
 * wp-admin (LT Marketplace → Home Slider). El admin solo carga las imágenes
 * con las dimensiones correctas (desktop panorámico + opcional mobile) y
 * estas se actualizan en el home automáticamente, sin depender de Elementor.
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Frontend_Home_Slider {

    /** Option key donde se persisten los slides. */
    const OPTION_KEY = 'ltms_home_slides';

    /** Versión de cache-busting del JS/CSS (sincronizada con LTMS_VERSION). */
    const VERSION = LTMS_VERSION;

    /** HOME-SLIDER-IMAGES-FIX: true cuando el slider ya se renderizó en este request. */
    private bool $rendered = false;

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        if ( is_admin() && ! wp_doing_ajax() ) {
            // HOME-SLIDER-ORDER-FIX (2026-09-24): prioridad 20 (después de
            // LTMS_Admin::register_menus @10). boot_frontend corre ANTES que
            // boot_admin en el Kernel, así que este admin_menu hook se registra
            // primero y corre primero: al momento de add_submenu_page() el menú
            // padre 'ltms-dashboard' AÚN NO existe en $admin_page_hooks → WP 7.x
            // calcula el hookname SIN el prefijo del padre
            // (admin_page_ltms-home-slider), pero el acceso (user_can_access_
            // admin_page) y el render del menú (menu-header.php) lo calculan CON
            // prefijo (lt-marketplace_page_ltms-home-slider) porque para entonces
            // el padre ya existe → mismatch: el menú renderiza el href CRUDO
            // (slug → /wp-admin/ltms-home-slider → 404 del frontend al click) y
            // el acceso directo a la URL correcta da "Lo siento, no tienes
            // permisos" (verified: 479 KERNEL BOOT ERROR históricos no son la
            // causa; el boot funciona). Con prioridad 20 el padre existe cuando
            // el submenu se registra → hookname consistente en los 3 call sites.
            add_action( 'admin_menu', [ $instance, 'register_admin_menu' ], 20 );
            add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_admin_assets' ] );
        }

        // AJAX (admin) — gestionar slides.
        add_action( 'wp_ajax_ltms_home_slider_save',   [ $instance, 'ajax_save_slides' ] );
        add_action( 'wp_ajax_ltms_home_slider_upload', [ $instance, 'ajax_upload_image' ] );
        add_action( 'wp_ajax_ltms_home_slider_remove', [ $instance, 'ajax_remove_image' ] );
        add_action( 'wp_ajax_ltms_home_slider_delete', [ $instance, 'ajax_delete_slide' ] );

        // Shortcode + inyección automática en el home.
        add_shortcode( 'ltms_home_slider', [ $instance, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue_frontend_assets' ] );
        // HOME-SLIDER-IMAGES-FIX (2026-09-24): el render del slider viaja en el
        // filtro the_content @10, registrado AQUÍ (en init). El código anterior
        // registraba add_action( 'wp_body_open', ... ) DESDE DENTRO del callback
        // de inject_home_slider() (que corre en wp_footer @20): wp_body_open
        // dispara al INICIO del <body> (header.php), ANTES que wp_footer, así
        // que el hook registrado tarde NUNCA corría → render_slider() nunca se
        // imprimía. Neto verificado en server (HTTP 200, home real con 3
        // banners activos): el estilo que OCULTA el widget de Elementor sí se
        // imprimía (ltms-home-slider-hide-elementor presente) pero el slider NO
        // existía en el HTML (data-ltms-hs ausente) → Elementor oculto + slider
        // ausente = home sin banners. Fix verificado empíricamente en server
        // (render simulado del front page 30 con el template real index.php):
        // the_content dispara con is_front_page()=true e in_the_loop()=true y el
        // banner aterriza dentro de <main id="content"> → .page-content, justo
        // antes del contenido Elementor (debajo del header de navegación) — la
        // posición exacta del widget Slides que reemplaza. Fallback: si
        // the_content nunca dispara (template exótico sin loop), wp_footer
        // renderiza al final (visible > invisible).
        add_filter( 'the_content', [ $instance, 'prepend_slider_to_content' ], 10 );
        add_action( 'wp_footer', [ $instance, 'inject_home_slider' ], 20 );
    }

    /**
     * Registra el submenú de administración.
     *
     * @return void
     */
    public function register_admin_menu(): void {
        add_submenu_page(
            'ltms-dashboard',
            __( 'Home Slider', 'ltms' ),
            __( 'Home Slider', 'ltms' ),
            'ltms_manage_platform_settings',
            'ltms-home-slider',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Encola los assets del admin (solo en la página del slider).
     *
     * @param string $hook_suffix Hook actual de admin.
     * @return void
     */
    public function enqueue_admin_assets( string $hook_suffix ): void {
        if ( strpos( $hook_suffix, 'ltms-home-slider' ) === false ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style(
            'ltms-home-slider-admin',
            LTMS_ASSETS_URL . 'css/ltms-home-slider.css',
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'ltms-home-slider-admin',
            ltms_asset_url( 'js/ltms-home-slider' ),
            [ 'jquery' ],
            self::VERSION,
            true
        );
        wp_localize_script( 'ltms-home-slider-admin', 'ltmsHomeSlider', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ltms_home_slider_nonce' ),
        ] );
    }

    /**
     * Encola el CSS/JS del frontend solo en la página frontal (home).
     *
     * @return void
     */
    public function enqueue_frontend_assets(): void {
        if ( ! is_front_page() && ! has_shortcode( get_post_field( 'post_content', get_the_ID() ), 'ltms_home_slider' ) ) {
            return;
        }
        $slides = $this->get_slides();
        if ( empty( $slides ) ) {
            return;
        }
        wp_enqueue_style(
            'ltms-home-slider',
            LTMS_ASSETS_URL . 'css/ltms-home-slider.css',
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'ltms-home-slider',
            ltms_asset_url( 'js/ltms-home-slider' ),
            [],
            self::VERSION,
            true
        );
        wp_localize_script( 'ltms-home-slider', 'ltmsHomeSliderData', [
            'autoplay' => (int) get_option( 'ltms_home_slider_autoplay', 5000 ),
            'loop'     => true,
        ] );
    }

    /**
     * Obtiene los slides persistidos (activos), ordenados.
     *
     * @param bool $active_only Si true, devuelve solo los activos.
     * @return array
     */
    public function get_slides( bool $active_only = true ): array {
        $slides = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $slides ) ) {
            return [];
        }
        // Ordenar por 'order' asc.
        usort( $slides, static function ( $a, $b ) {
            return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
        } );
        if ( $active_only ) {
            $slides = array_values( array_filter( $slides, static function ( $s ) {
                return ( $s['active'] ?? '1' ) !== '0';
            } ) );
        }
        return $slides;
    }

    /**
     * Renderiza el shortcode [ltms_home_slider].
     *
     * @param array $atts Atributos.
     * @return string HTML del slider.
     */
    public function render_shortcode( array $atts = [] ): string {
        return $this->render_slider();
    }

    /**
     * HOME-SLIDER-IMAGES-FIX: antepone el slider al contenido del home vía
     * filtro the_content @10 (debajo del header de navegación, donde estaba el
     * widget Slides de Elementor que reemplaza). Ver el comentario del wiring
     * en init() para la evidencia completa de la causa raíz.
     *
     * @param mixed $content Contenido del post (string esperado).
     * @return string Contenido con el slider antepuesto.
     */
    public function prepend_slider_to_content( $content ): string {
        $content = (string) $content;
        if ( $this->rendered || ! is_front_page() || ! in_the_loop() ) {
            return $content;
        }
        // Nested loops dentro del loop principal (widgets de posts que aplican
        // the_content): solo inyectar cuando el loop itera la página frontal.
        $front_id = (int) get_option( 'page_on_front' );
        if ( $front_id && (int) get_the_ID() !== $front_id ) {
            return $content;
        }
        $slides = $this->get_slides();
        if ( empty( $slides ) ) {
            return $content;
        }
        // No duplicar: si el contenido ya incluye el slider (shortcode
        // [ltms_home_slider] por expandir en do_shortcode @11, o markup propio
        // ya renderizado), no anteponer — pero marcar rendered para que el
        // fallback de wp_footer tampoco duplique.
        if ( strpos( $content, 'data-ltms-hs' ) !== false
            || strpos( $content, '[ltms_home_slider]' ) !== false ) {
            $this->rendered = true;
            return $content;
        }
        $this->rendered = true;
        return $this->hide_elementor_style() . $this->render_slider() . $content;
    }

    /**
     * Inyecta el slider en el home automáticamente y oculta el widget Slides
     * de Elementor (reemplazo automático).
     *
     * HOME-SLIDER-IMAGES-FIX (2026-09-24): queda como FALLBACK para templates
     * sin loop/the_content — si el slider ya se renderizó en the_content, este
     * método no hace nada (evita el doble banner).
     *
     * @return void
     */
    public function inject_home_slider(): void {
        if ( $this->rendered ) {
            return;
        }
        if ( ! is_front_page() ) {
            return;
        }
        $slides = $this->get_slides();
        if ( empty( $slides ) ) {
            return;
        }
        echo $this->hide_elementor_style(); // phpcs:ignore
        // Renderizar nuestro slider justo después del header.
        echo $this->render_slider(); // phpcs:ignore
        $this->rendered = true;
    }

    /**
     * HOME-SLIDER-IMAGES-FIX: estilo inline que oculta el widget Slides de
     * Elementor en el home (reemplazo automático). Extraído a helper para que
     * the_content y el fallback de wp_footer compartan el mismo markup.
     *
     * @return string Bloque <style>.
     */
    private function hide_elementor_style(): string {
        return '<style id="ltms-home-slider-hide-elementor">
            .elementor-widget-slides, .elementor-widget-image-carousel, .elementor-widget-carousel {
                display: none !important;
            }
        </style>';
    }

    /**
     * Construye el HTML del slider.
     *
     * @return string
     */
    public function render_slider(): string {
        $slides = $this->get_slides();
        if ( empty( $slides ) ) {
            return '';
        }

        $html  = '<div class="ltms-hs" data-ltms-hs aria-roledescription="carousel" aria-label="' . esc_attr__( 'Banners del home', 'ltms' ) . '">';
        $html .= '<div class="ltms-hs__track" data-ltms-hs-track>';

        foreach ( $slides as $i => $slide ) {
            $img_desktop = (string) ( $slide['image_desktop'] ?? '' );
            $img_mobile  = (string) ( $slide['image_mobile'] ?? '' );
            $cta_text    = (string) ( $slide['cta_text'] ?? '' );
            $cta_url     = (string) ( $slide['cta_url'] ?? '' );
            $alt         = (string) ( $slide['title'] ?? '' );

            $inner = '';
            if ( $img_mobile ) {
                $inner .= '<picture>';
                $inner .= '<source media="(max-width: 767px)" srcset="' . esc_url( $img_mobile ) . '">';
                $inner .= '<img src="' . esc_url( $img_desktop ) . '" alt="' . esc_attr( $alt ) . '" loading="' . ( $i === 0 ? 'eager' : 'lazy' ) . '" decoding="async">';
                $inner .= '</picture>';
            } elseif ( $img_desktop ) {
                $inner .= '<img src="' . esc_url( $img_desktop ) . '" alt="' . esc_attr( $alt ) . '" loading="' . ( $i === 0 ? 'eager' : 'lazy' ) . '" decoding="async">';
            }

            $slide_html = '<div class="ltms-hs__slide" role="group" aria-roledescription="slide" aria-label="' . esc_attr( sprintf( '%d de %d', $i + 1, count( $slides ) ) ) . '">';
            $slide_html .= '<div class="ltms-hs__media">' . $inner . '</div>';

            if ( $cta_text ) {
                $slide_html .= '<div class="ltms-hs__cta">';
                if ( $cta_url ) {
                    $slide_html .= '<a class="ltms-hs__btn" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_text ) . '</a>';
                } else {
                    $slide_html .= '<span class="ltms-hs__btn">' . esc_html( $cta_text ) . '</span>';
                }
                $slide_html .= '</div>';
            }

            $slide_html .= '</div>';
            $html .= $slide_html;
        }

        $html .= '</div>'; // track

        if ( count( $slides ) > 1 ) {
            $html .= '<button type="button" class="ltms-hs__nav ltms-hs__nav--prev" data-ltms-hs-prev aria-label="' . esc_attr__( 'Anterior', 'ltms' ) . '">‹</button>';
            $html .= '<button type="button" class="ltms-hs__nav ltms-hs__nav--next" data-ltms-hs-next aria-label="' . esc_attr__( 'Siguiente', 'ltms' ) . '">›</button>';
            $html .= '<div class="ltms-hs__dots" data-ltms-hs-dots role="tablist"></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Renderiza la página de administración.
     *
     * @return void
     */
    public function render_admin_page(): void {
        $view = LTMS_INCLUDES_DIR . 'admin/views/html-admin-home-slider.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /**
     * AJAX: Guarda la lista completa de slides (reemplaza la opción).
     *
     * @return void
     */
    public function ajax_save_slides(): void {
        check_ajax_referer( 'ltms_home_slider_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $raw_slides = isset( $_POST['slides'] ) ? wp_unslash( $_POST['slides'] ) : ''; // phpcs:ignore
        $decoded    = json_decode( $raw_slides, true );

        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( __( 'Formato inválido de slides.', 'ltms' ) );
        }

        $slides = [];
        foreach ( $decoded as $s ) {
            $slides[] = [
                'title'         => sanitize_text_field( $s['title'] ?? '' ),
                'image_desktop' => esc_url_raw( $s['image_desktop'] ?? '' ),
                'image_mobile'  => esc_url_raw( $s['image_mobile'] ?? '' ),
                'cta_text'      => sanitize_text_field( $s['cta_text'] ?? '' ),
                'cta_url'       => esc_url_raw( $s['cta_url'] ?? '' ),
                'order'         => absint( $s['order'] ?? 0 ),
                'active'        => ( isset( $s['active'] ) && (string) $s['active'] === '0' ) ? '0' : '1',
            ];
        }

        update_option( self::OPTION_KEY, $slides );

        wp_send_json_success( [
            'message' => __( 'Slider del home actualizado correctamente.', 'ltms' ),
            'count'   => count( $slides ),
        ] );
    }

    /**
     * AJAX: Sube una imagen del slider a la Media Library.
     *
     * @return void
     */
    public function ajax_upload_image(): void {
        check_ajax_referer( 'ltms_home_slider_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( __( 'No se recibió la imagen.', 'ltms' ) );
        }

        $file = $_FILES['file']; // phpcs:ignore

        // Validar tipo MIME real.
        $allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $finfo   = new finfo( FILEINFO_MIME_TYPE );
        $mime    = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( __( 'Tipo de archivo no permitido. Usa JPG, PNG, GIF o WebP.', 'ltms' ) );
        }
        if ( $file['size'] > 2 * 1024 * 1024 ) {
            wp_send_json_error( __( 'La imagen supera el límite de 2 MB.', 'ltms' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( __( 'Error al subir la imagen.', 'ltms' ) );
        }

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    /**
     * AJAX: Elimina una imagen de la Media Library (opcional).
     *
     * @return void
     */
    public function ajax_remove_image(): void {
        check_ajax_referer( 'ltms_home_slider_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        if ( $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }
        wp_send_json_success( [ 'message' => __( 'Imagen eliminada.', 'ltms' ) ] );
    }

    /**
     * AJAX: Elimina un slide completo de la lista.
     *
     * @return void
     */
    public function ajax_delete_slide(): void {
        check_ajax_referer( 'ltms_home_slider_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $index = absint( $_POST['index'] ?? -1 );
        $slides = $this->get_slides( false );
        if ( $index < 0 || ! isset( $slides[ $index ] ) ) {
            wp_send_json_error( __( 'Slide inválido.', 'ltms' ) );
        }

        array_splice( $slides, $index, 1 );
        update_option( self::OPTION_KEY, array_values( $slides ) );

        wp_send_json_success( [ 'message' => __( 'Slide eliminado.', 'ltms' ) ] );
    }
}