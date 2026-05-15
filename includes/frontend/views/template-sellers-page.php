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
    while ( have_posts() ) :
        the_post();
        global $post;
        // Elementor filtra the_content() y lo reemplaza con su canvas vacío
        // cuando la página tiene _elementor_edit_mode=builder.
        // Leemos post_content directamente y aplicamos do_shortcode manualmente.
        $el_mode = get_post_meta( $post->ID, '_elementor_edit_mode', true );
        if ( $el_mode === 'builder' ) {
            echo do_shortcode( $post->post_content );
        } else {
            the_content();
        }
    endwhile;
    ?>
</main>

<?php
get_footer();
