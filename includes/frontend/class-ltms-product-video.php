<?php
/**
 * LTMS Product Video — Video MP4 directo en galería de producto.
 *
 * Permite a los vendors subir videos MP4 directamente (sin YouTube/Vimeo).
 * El video se muestra como primer elemento de la galería, autoplay muted + loop.
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Product_Video {

    public static function init(): void {
        // Meta box en admin para subir video.
        add_action( 'woocommerce_product_options_sidebar', [ __CLASS__, 'render_video_meta_box' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_video_meta' ] );

        // Prepend video a la galería del producto.
        add_action( 'woocommerce_product_thumbnails', [ __CLASS__, 'render_video_in_gallery' ], 5 );

        // Prepend video grande en la imagen principal (solo si hay video).
        add_filter( 'woocommerce_single_product_image_thumbnail_html', [ __CLASS__, 'maybe_replace_main_image' ], 10, 2 );

        // Allow MP4 upload in WC media uploader.
        add_filter( 'upload_mimes', [ __CLASS__, 'allow_mp4_upload' ] );
    }

    /**
     * Meta box para seleccionar el video del producto.
     */
    public static function render_video_meta_box(): void {
        global $post;
        $video_id = (int) get_post_meta( $post->ID, '_ltms_product_video_id', true );
        $video_url = $video_id ? wp_get_attachment_url( $video_id ) : '';
        ?>
        <div class="options_group show_if_simple show_if_variable">
            <p class="form-field">
                <label for="_ltms_product_video_id"><?php esc_html_e( 'Video del producto (MP4)', 'ltms' ); ?></label>
                <input type="hidden" name="_ltms_product_video_id" id="_ltms_product_video_id" value="<?php echo esc_attr( $video_id ); ?>" />
                <button type="button" class="button" id="ltms_upload_video_btn">
                    <?php $video_id ? esc_html_e( 'Cambiar video', 'ltms' ) : esc_html_e( 'Subir video MP4', 'ltms' ); ?>
                </button>
                <span id="ltms_video_filename" style="margin-left:8px;font-size:12px;">
                    <?php if ( $video_url ) echo esc_html( basename( $video_url ) ); ?>
                </span>
                <?php if ( $video_id ) : ?>
                    <button type="button" class="button" id="ltms_remove_video_btn" style="margin-left:4px;color:#a00;">
                        <?php esc_html_e( 'Quitar', 'ltms' ); ?>
                    </button>
                <?php endif; ?>
            </p>
            <?php wp_nonce_field( 'ltms_save_product_video', 'ltms_product_video_nonce' ); ?>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            $('#ltms_upload_video_btn').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '<?php esc_html_e( "Seleccionar video MP4", "ltms" ); ?>',
                    library: { type: 'video' },
                    button: { text: '<?php esc_html_e( "Usar este video", "ltms" ); ?>' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#_ltms_product_video_id').val(attachment.id);
                    $('#ltms_video_filename').text(attachment.filename);
                    $('#ltms_upload_video_btn').text('<?php esc_html_e( "Cambiar video", "ltms" ); ?>');
                    $('<button type="button" class="button" id="ltms_remove_video_btn" style="margin-left:4px;color:#a00;"><?php esc_html_e( "Quitar", "ltms" ); ?></button>').insertAfter('#ltms_upload_video_btn');
                });
                frame.open();
            });
            $(document).on('click', '#ltms_remove_video_btn', function(e) {
                e.preventDefault();
                $('#_ltms_product_video_id').val('');
                $('#ltms_video_filename').text('');
                $('#ltms_upload_video_btn').text('<?php esc_html_e( "Subir video MP4", "ltms" ); ?>');
                $(this).remove();
            });
        });
        </script>
        <?php
    }

    /**
     * Guarda el meta del video.
     */
    public static function save_video_meta( int $post_id ): void {
        if ( ! isset( $_POST['ltms_product_video_nonce'] ) || ! wp_verify_nonce( $_POST['ltms_product_video_nonce'], 'ltms_save_product_video' ) ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;
        $video_id = (int) ( $_POST['_ltms_product_video_id'] ?? 0 );
        if ( $video_id > 0 ) {
            update_post_meta( $post_id, '_ltms_product_video_id', $video_id );
        } else {
            delete_post_meta( $post_id, '_ltms_product_video_id' );
        }
    }

    /**
     * Renderiza el video en la galería de thumbnails.
     */
    public static function render_video_in_gallery(): void {
        global $product;
        if ( ! $product ) return;
        $video_id = (int) get_post_meta( $product->get_id(), '_ltms_product_video_id', true );
        if ( ! $video_id ) return;
        $video_url = wp_get_attachment_url( $video_id );
        if ( ! $video_url ) return;
        ?>
        <div class="ltms-product-video-wrap" onclick="ltmsPlayMainVideo('<?php echo esc_url( $video_url ); ?>')"
             style="cursor:pointer;position:relative;width:100%;margin-bottom:10px;border-radius:8px;overflow:hidden;">
            <video muted loop playsinline preload="metadata"
                   style="width:100%;display:block;border-radius:8px;">
                <source src="<?php echo esc_url( $video_url ); ?>" type="video/mp4">
            </video>
            <div class="ltms-product-video-overlay" style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.2);transition:opacity 0.3s;">
                <div style="width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,0.9);display:flex;align-items:center;justify-content:center;">
                    <span style="font-size:24px;margin-left:4px;">&#x25B6;</span>
                </div>
            </div>
        </div>
        <script>
        function ltmsPlayMainVideo(url) {
            var overlay = event.currentTarget.querySelector('.ltms-product-video-overlay');
            var video = event.currentTarget.querySelector('video');
            if (video.paused) {
                video.play();
                overlay.style.opacity = '0';
            } else {
                video.pause();
                overlay.style.opacity = '1';
            }
        }
        </script>
        <?php
    }

    /**
     * Permite subir MP4 en el media uploader de WordPress.
     */
    public static function allow_mp4_upload( array $mimes ): array {
        $mimes['mp4'] = 'video/mp4';
        $mimes['mov'] = 'video/quicktime';
        $mimes['webm'] = 'video/webm';
        return $mimes;
    }

    /**
     * Si el producto tiene video, lo prepend a la imagen principal.
     */
    public static function maybe_replace_main_image( string $html, int $post_thumbnail_id ): string {
        global $product;
        if ( ! $product ) return $html;
        $video_id = (int) get_post_meta( $product->get_id(), '_ltms_product_video_id', true );
        if ( ! $video_id ) return $html;
        $video_url = wp_get_attachment_url( $video_id );
        if ( ! $video_url ) return $html;

        // Prepend video antes de la imagen.
        $video_html = sprintf(
            '<div class="ltms-main-product-video" style="margin-bottom:10px;border-radius:8px;overflow:hidden;">
                <video controls muted loop playsinline preload="metadata" style="width:100%%;display:block;border-radius:8px;">
                    <source src="%s" type="video/mp4">
                </video>
            </div>',
            esc_url( $video_url )
        );
        return $video_html . $html;
    }
}
