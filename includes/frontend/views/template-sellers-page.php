<?php
/**
 * Template para /sellers/ — Bypass de Elementor (M-73)
 *
 * Hello Elementor no llama the_content() en páginas con template Elementor,
 * por lo que el shortcode [ltms_sellers_landing] nunca se renderizaba.
 * Este template sirve el contenido directamente usando el header/footer del tema.
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Encolar CSS necesario si no está encolado aún
add_action( 'wp_head', function() {
    $url = LTMS_ASSETS_URL;
    $ver = LTMS_VERSION;
    if ( ! wp_style_is( 'ltms-dashboard', 'enqueued' ) ) {
        wp_enqueue_style( 'ltms-dashboard', $url . 'css/ltms-dashboard.css', [], $ver );
    }
    if ( ! wp_style_is( 'ltms-frontend-extensions', 'enqueued' ) ) {
        wp_enqueue_style( 'ltms-frontend-extensions', $url . 'css/ltms-frontend-extensions.css', [ 'ltms-dashboard' ], $ver );
    }
    if ( ! wp_style_is( 'ltms-login-register', 'enqueued' ) ) {
        wp_enqueue_style( 'ltms-login-register', $url . 'css/ltms-login-register.css', [ 'ltms-dashboard' ], $ver );
    }
}, 5 );

get_header();
?>

<main id="ltms-sellers-main" style="min-height:60vh;">
    <?php
    if ( have_posts() ) :
        the_post();
        global $post;
        $raw = get_post_field( 'post_content', $post->ID, 'raw' );

        // Instanciamos los handlers directamente y llamamos el método correcto,
        // bypasando do_shortcode() que Elementor puede interferir.
        if ( strpos( $raw, 'ltms_sellers_landing' ) !== false && class_exists( 'LTMS_Public_Auth_Handler' ) ) {
            $h = new LTMS_Public_Auth_Handler();
            echo $h->render_sellers_landing(); // phpcs:ignore
        } elseif ( strpos( $raw, 'ltms_vendor_register' ) !== false && class_exists( 'LTMS_Public_Auth_Handler' ) ) {
            $h = new LTMS_Public_Auth_Handler();
            echo $h->render_register_form(); // phpcs:ignore
        } elseif ( strpos( $raw, 'ltms_vendor_dashboard' ) !== false && class_exists( 'LTMS_Dashboard_Logic' ) ) {
            $h = new LTMS_Dashboard_Logic();
            echo $h->render_dashboard_shortcode(); // phpcs:ignore
        } else {
            // Para ltms_vendor_login y otros: usar el sistema de WP normal
            // ya que el shortcode fue registrado pero el método puede estar en otra clase
            echo do_shortcode( $raw ); // phpcs:ignore
        }
    endif;
    ?>
</main>

<?php
get_footer();
