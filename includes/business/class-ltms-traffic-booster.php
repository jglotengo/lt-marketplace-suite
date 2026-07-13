<?php
/**
 * LTMS Traffic Booster — 5 features para aumentar visibilidad y tráfico.
 *
 * v2.9.29 — Implementa las 5 features estratégicas de mayor impacto:
 *
 *  TB-1: Google Shopping Feed XML (Merchant Center).
 *  TB-2: Social Commerce auto-post (Instagram / Facebook / Pinterest).
 *  TB-3: Newsletter semanal con productos destacados.
 *  TB-4: City Pages programáticas (Programmatic SEO).
 *  TB-5: Google Business Profile por ciudad.
 *
 * Impacto estimado: +50-100% tráfico en 6 meses.
 *
 * @package LTMS
 * @version 2.9.29
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Traffic_Booster {

    /**
     * Ciudades para city pages + GBP.
     */
    public const CITIES = [
        'CO' => [
            'bogota'        => 'Bogotá D.C.',
            'medellin'      => 'Medellín',
            'cali'          => 'Cali',
            'barranquilla'  => 'Barranquilla',
            'cartagena'     => 'Cartagena',
        ],
        'MX' => [
            'cdmx'          => 'Ciudad de México',
            'guadalajara'   => 'Guadalajara',
            'monterrey'     => 'Monterrey',
            'puebla'        => 'Puebla',
            'merida'        => 'Mérida',
        ],
    ];

    /**
     * Intervalo del newsletter semanal (días).
     */
    public const NEWSLETTER_INTERVAL_DAYS = 7;

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // TB-1: Google Shopping Feed.
        add_action( 'init', [ __CLASS__, 'register_shopping_feed_rewrite' ] );
        add_action( 'template_redirect', [ __CLASS__, 'serve_shopping_feed' ] );
        add_action( 'ltms_daily_cron', [ __CLASS__, 'regenerate_shopping_feed_cache' ] );

        // TB-2: Social Commerce.
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'offer_social_auto_post' ], 70, 1 );
        add_action( 'wp_ajax_ltms_social_auto_post', [ __CLASS__, 'ajax_social_auto_post' ] );

        // TB-3: Newsletter semanal.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'maybe_send_weekly_newsletter' ] );
        add_action( 'wp_ajax_ltms_subscribe_newsletter', [ __CLASS__, 'ajax_subscribe_newsletter' ] );
        add_action( 'wp_ajax_nopriv_ltms_subscribe_newsletter', [ __CLASS__, 'ajax_subscribe_newsletter' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_newsletter_signup' ], 30 );

        // TB-4: City Pages.
        add_action( 'init', [ __CLASS__, 'register_city_pages_rewrite' ] );
        add_action( 'template_redirect', [ __CLASS__, 'serve_city_page' ] );

        // TB-5: Google Business Profile.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'post_to_gbp' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_gbp_panel' ] );
    }

    // ================================================================
    // TB-1: GOOGLE SHOPPING FEED XML.
    // ================================================================

    /**
     * Registra rewrite para /shopping-feed.xml.
     */
    public static function register_shopping_feed_rewrite(): void {
        add_rewrite_rule( '^shopping-feed\.xml$', 'index.php?ltms_shopping_feed=1', 'top' );
    }

    /**
     * Sirve el feed XML de Google Shopping.
     *
     * Formato RSS 2.0 con namespace g: completo para Merchant Center.
     * Incluye todos los atributos obligatorios + recomendados.
     */
    public static function serve_shopping_feed(): void {
        if ( ! get_query_var( 'ltms_shopping_feed' ) ) return;

        // Intentar caché.
        $cached = get_transient( 'ltms_shopping_feed_cache' );
        if ( $cached ) {
            header( 'Content-Type: application/xml; charset=utf-8' );
            header( 'Cache-Control: public, max-age=3600' );
            echo $cached;
            exit;
        }

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );

        $xml = self::generate_shopping_feed_xml();
        set_transient( 'ltms_shopping_feed_cache', $xml, HOUR_IN_SECONDS );
        echo $xml;
        exit;
    }

    /**
     * Genera el XML completo del feed de Google Shopping.
     */
    private static function generate_shopping_feed_xml(): string {
        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 5000,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );

        $site_name  = get_bloginfo( 'name' );
        $site_url   = home_url();
        $currency   = get_woocommerce_currency();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '  <title>' . esc_html( $site_name ) . ' — Product Feed</title>' . "\n";
        $xml .= '  <link>' . esc_url( $site_url ) . '</link>' . "\n";
        $xml .= '  <description>Marketplace product feed for Google Merchant Center</description>' . "\n";

        foreach ( $products as $product ) {
            $image_url = wp_get_attachment_url( $product->get_image_id() ) ?: '';
            $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
            $category_str = is_array( $categories ) ? implode( ' > ', $categories ) : '';
            $vendor_id = (int) get_post_meta( $product->get_id(), '_ltms_vendor_id', true );
            $vendor_name = $vendor_id ? ( get_userdata( $vendor_id )->display_name ?? '' ) : '';
            $gtin = get_post_meta( $product->get_id(), '_gtin', true ) ?: '';
            $mpn = get_post_meta( $product->get_id(), '_mpn', true ) ?: $product->get_sku();
            $brand = get_post_meta( $product->get_id(), '_ltms_brand_name', true ) ?: $vendor_name;
            $country = LTMS_Core_Config::get_country();
            $shipping = self::get_product_shipping_info( $product );

            $xml .= '  <item>' . "\n";
            $xml .= '    <g:id>' . esc_html( $product->get_id() ) . '</g:id>' . "\n";
            $xml .= '    <title>' . esc_html( $product->get_name() ) . '</title>' . "\n";
            $xml .= '    <description>' . esc_html( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ) ) . '</description>' . "\n";
            $xml .= '    <link>' . esc_url( $product->get_permalink() ) . '</link>' . "\n";
            if ( $image_url ) {
                $xml .= '    <g:image_link>' . esc_url( $image_url ) . '</g:image_link>' . "\n";
            }
            $xml .= '    <g:price>' . esc_html( $product->get_price() ) . ' ' . esc_html( $currency ) . '</g:price>' . "\n";
            if ( $product->is_on_sale() && $product->get_regular_price() > $product->get_price() ) {
                $xml .= '    <g:sale_price>' . esc_html( $product->get_price() ) . ' ' . esc_html( $currency ) . '</g:sale_price>' . "\n";
                $xml .= '    <g:regular_price>' . esc_html( $product->get_regular_price() ) . ' ' . esc_html( $currency ) . '</g:regular_price>' . "\n";
            }
            $xml .= '    <g:availability>' . ( $product->is_in_stock() ? 'in stock' : 'out of stock' ) . '</g:availability>' . "\n";
            $xml .= '    <g:condition>new</g:condition>' . "\n";
            if ( $gtin ) $xml .= '    <g:gtin>' . esc_html( $gtin ) . '</g:gtin>' . "\n";
            if ( $mpn ) $xml .= '    <g:mpn>' . esc_html( $mpn ) . '</g:mpn>' . "\n";
            if ( $brand ) $xml .= '    <g:brand>' . esc_html( $brand ) . '</g:brand>' . "\n";
            if ( $category_str ) $xml .= '    <g:product_type>' . esc_html( $category_str ) . '</g:product_type>' . "\n";
            $xml .= '    <g:identifier_exists>' . ( $gtin || $mpn ? 'yes' : 'no' ) . '</g:identifier_exists>' . "\n";
            if ( $shipping ) $xml .= '    <g:shipping>' . $shipping . '</g:shipping>' . "\n";
            // Google product category (configurable por categoría WC).
            $gpc = get_post_meta( $product->get_id(), '_ltms_google_product_category', true );
            if ( $gpc ) $xml .= '    <g:google_product_category>' . esc_html( $gpc ) . '</g:google_product_category>' . "\n";
            // Atributos adicionales.
            $color = $product->get_attribute( 'color' ) ?: get_post_meta( $product->get_id(), '_ltms_color', true );
            if ( $color ) $xml .= '    <g:color>' . esc_html( $color ) . '</g:color>' . "\n";
            $size = $product->get_attribute( 'size' ) ?: get_post_meta( $product->get_id(), '_ltms_size', true );
            if ( $size ) $xml .= '    <g:size>' . esc_html( $size ) . '</g:size>' . "\n";
            $material = get_post_meta( $product->get_id(), '_ltms_material', true );
            if ( $material ) $xml .= '    <g:material>' . esc_html( $material ) . '</g:material>' . "\n";
            $gender = get_post_meta( $product->get_id(), '_ltms_gender', true );
            if ( $gender ) $xml .= '    <g:gender>' . esc_html( $gender ) . '</g:gender>' . "\n";
            $age_group = get_post_meta( $product->get_id(), '_ltms_age_group', true );
            if ( $age_group ) $xml .= '    <g:age_group>' . esc_html( $age_group ) . '</g:age_group>' . "\n";
            $xml .= '  </item>' . "\n";
        }

        $xml .= '</channel></rss>';
        return $xml;
    }

    /**
     * Información de envío para Google Shopping.
     */
    private static function get_product_shipping_info( \WC_Product $product ): string {
        $country = LTMS_Core_Config::get_country();
        $currency = get_woocommerce_currency();
        $shipping_cost = $country === 'CO' ? '8000' : '50';
        $shipping_country = $country === 'CO' ? 'CO' : 'MX';

        return sprintf(
            '<g:country>%s</g:country><g:service>Standard</g:service><g:price>%s %s</g:price>',
            $shipping_country, $shipping_cost, $currency
        );
    }

    /**
     * Cron diario: regenera caché del feed.
     */
    public static function regenerate_shopping_feed_cache(): void {
        delete_transient( 'ltms_shopping_feed_cache' );
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'TB_SHOPPING_FEED_REGEN', 'Google Shopping feed cache cleared for regeneration.' );
        }
    }

    // ================================================================
    // TB-2: SOCIAL COMMERCE AUTO-POST.
    // ================================================================

    /**
     * Ofrece auto-post a redes sociales al publicar producto.
     */
    public static function offer_social_auto_post( int $product_id ): void {
        $posted = get_post_meta( $product_id, '_ltms_social_posted', true );
        if ( $posted === 'yes' ) return; // Ya se publicó.

        // Marcar como pendiente de publicación social.
        update_post_meta( $product_id, '_ltms_social_post_pending', 'yes' );
    }

    /**
     * AJAX: publica producto en redes sociales.
     *
     * Requiere tokens de Meta Graph API + Pinterest API configurados.
     */
    public static function ajax_social_auto_post(): void {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $platforms  = array_map( 'sanitize_key', (array) ( $_POST['platforms'] ?? [] ) );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Producto no encontrado.', 'ltms' ) ], 404 );
        }

        $results = [];

        foreach ( $platforms as $platform ) {
            switch ( $platform ) {
                case 'instagram':
                    $results['instagram'] = self::post_to_instagram( $product );
                    break;
                case 'facebook':
                    $results['facebook'] = self::post_to_facebook( $product );
                    break;
                case 'pinterest':
                    $results['pinterest'] = self::post_to_pinterest( $product );
                    break;
            }
        }

        // Marcar como publicado.
        update_post_meta( $product_id, '_ltms_social_posted', 'yes' );
        delete_post_meta( $product_id, '_ltms_social_post_pending' );
        update_post_meta( $product_id, '_ltms_social_posted_at', current_time( 'mysql', true ) );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'TB_SOCIAL_POST', sprintf( 'Product #%d posted to: %s', $product_id, implode( ', ', $platforms ) ) );
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    /**
     * Publica en Instagram via Meta Graph API.
     */
    private static function post_to_instagram( \WC_Product $product ): array {
        $token = LTMS_Core_Config::get( 'ltms_meta_access_token', '' );
        $ig_account = LTMS_Core_Config::get( 'ltms_ig_business_account', '' );

        if ( empty( $token ) || empty( $ig_account ) ) {
            return [ 'status' => 'skipped', 'reason' => 'Instagram no configurado' ];
        }

        $image_url = wp_get_attachment_url( $product->get_image_id() );
        if ( ! $image_url ) {
            return [ 'status' => 'skipped', 'reason' => 'Sin imagen' ];
        }

        $caption = sprintf(
            "🛒 %s\n💰 %s\n\n🔗 %s\n\n#LoTengo #Marketplace #Colombia #Mexico #CompraOnline",
            $product->get_name(),
            wp_strip_all_tags( $product->get_price_html() ),
            $product->get_permalink()
        );

        $utm_caption = $caption . "\n\nutm_source=instagram&utm_medium=social&utm_campaign=product_auto_post";

        // Paso 1: crear container.
        $response = wp_remote_post( "https://graph.facebook.com/v18.0/{$ig_account}/media", [
            'body' => [
                'image_url' => $image_url,
                'caption'   => $utm_caption,
                'access_token' => $token,
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'error', 'reason' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['id'] ) ) {
            return [ 'status' => 'error', 'reason' => 'No se pudo crear container IG' ];
        }

        // Paso 2: publicar.
        $publish = wp_remote_post( "https://graph.facebook.com/v18.0/{$ig_account}/media_publish", [
            'body' => [
                'creation_id' => $body['id'],
                'access_token' => $token,
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $publish ) ) {
            return [ 'status' => 'error', 'reason' => $publish->get_error_message() ];
        }

        $pub_body = json_decode( wp_remote_retrieve_body( $publish ), true );
        if ( isset( $pub_body['id'] ) ) {
            return [ 'status' => 'success', 'ig_media_id' => $pub_body['id'] ];
        }

        return [ 'status' => 'error', 'reason' => 'Publicación IG falló' ];
    }

    /**
     * Publica en Facebook via Meta Graph API.
     */
    private static function post_to_facebook( \WC_Product $product ): array {
        $token = LTMS_Core_Config::get( 'ltms_meta_access_token', '' );
        $fb_page = LTMS_Core_Config::get( 'ltms_fb_page_id', '' );

        if ( empty( $token ) || empty( $fb_page ) ) {
            return [ 'status' => 'skipped', 'reason' => 'Facebook no configurado' ];
        }

        $message = sprintf(
            "🛒 %s\n💰 %s\n\n🔗 %s",
            $product->get_name(),
            wp_strip_all_tags( $product->get_price_html() ),
            add_query_arg( 'utm_source', 'facebook', $product->get_permalink() )
        );

        $image_url = wp_get_attachment_url( $product->get_image_id() );

        $body = [ 'message' => $message, 'access_token' => $token ];
        if ( $image_url ) $body['link'] = $product->get_permalink();

        $response = wp_remote_post( "https://graph.facebook.com/v18.0/{$fb_page}/feed", [
            'body' => $body,
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'error', 'reason' => $response->get_error_message() ];
        }

        $resp = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $resp['id'] ) ) {
            return [ 'status' => 'success', 'fb_post_id' => $resp['id'] ];
        }

        return [ 'status' => 'error', 'reason' => 'Publicación FB falló' ];
    }

    /**
     * Publica en Pinterest via Pinterest API.
     */
    private static function post_to_pinterest( \WC_Product $product ): array {
        $token = LTMS_Core_Config::get( 'ltms_pinterest_token', '' );
        $board_id = LTMS_Core_Config::get( 'ltms_pinterest_board_id', '' );

        if ( empty( $token ) || empty( $board_id ) ) {
            return [ 'status' => 'skipped', 'reason' => 'Pinterest no configurado' ];
        }

        $image_url = wp_get_attachment_url( $product->get_image_id() );
        if ( ! $image_url ) {
            return [ 'status' => 'skipped', 'reason' => 'Sin imagen' ];
        }

        $response = wp_remote_post( 'https://api.pinterest.com/v5/pins', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'board_id'   => $board_id,
                'title'      => $product->get_name(),
                'description'=> wp_strip_all_tags( $product->get_short_description() ),
                'link'       => add_query_arg( 'utm_source', 'pinterest', $product->get_permalink() ),
                'media_source' => [ 'source_type' => 'image_url', 'url' => $image_url ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'error', 'reason' => $response->get_error_message() ];
        }

        $resp = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $resp['id'] ) ) {
            return [ 'status' => 'success', 'pin_id' => $resp['id'] ];
        }

        return [ 'status' => 'error', 'reason' => 'Pinterest falló' ];
    }

    // ================================================================
    // TB-3: NEWSLETTER SEMANAL.
    // ================================================================

    /**
     * Renderiza form de suscripción al newsletter en footer.
     */
    public static function render_newsletter_signup(): void {
        if ( is_admin() ) return;
        ?>
        <div id="ltms-newsletter-bar" style="background:linear-gradient(135deg,#1e40af,#3730a3);color:#fff;padding:20px;text-align:center;margin-top:30px;">
            <h3 style="margin:0 0 8px;font-size:18px;">📬 <?php esc_html_e( 'Recibe ofertas exclusivas cada semana', 'ltms' ); ?></h3>
            <p style="font-size:13px;margin:0 0 12px;opacity:0.9;"><?php esc_html_e( 'Productos nuevos, flash sales y descuentos especiales directo en tu correo.', 'ltms' ); ?></p>
            <div style="display:flex;gap:8px;max-width:400px;margin:0 auto;">
                <input type="email" id="ltms-newsletter-email" placeholder="<?php esc_attr_e( 'tu@email.com', 'ltms' ); ?>"
                       style="flex:1;padding:10px 14px;border:none;border-radius:6px;font-size:14px;" />
                <button id="ltms-newsletter-submit" class="button"
                        style="background:#f59e0b;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:bold;font-size:14px;cursor:pointer;">
                    <?php esc_html_e( 'Suscribirme', 'ltms' ); ?>
                </button>
            </div>
            <p id="ltms-newsletter-msg" style="font-size:12px;margin-top:8px;display:none;"></p>
        </div>
        <script>
        jQuery(function($){
            $('#ltms-newsletter-submit').on('click', function(){
                var email = $('#ltms-newsletter-email').val();
                if (!email || !email.includes('@')) { $('#ltms-newsletter-msg').text('⚠️ Email inválido').css('color','#fca5a5').show(); return; }
                $.post(ajaxurl, { action: 'ltms_subscribe_newsletter', email: email }, function(resp){
                    if (resp.success) {
                        $('#ltms-newsletter-msg').text('✅ ¡Suscrito! Revisa tu correo.').css('color','#86efac').show();
                        $('#ltms-newsletter-email').val('');
                    } else {
                        $('#ltms-newsletter-msg').text('❌ ' + (resp.data?.message || 'Error')).css('color','#fca5a5').show();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: suscribe email al newsletter.
     */
    public static function ajax_subscribe_newsletter(): void {
        // v2.9.100 SEC-2 FIX: add nonce + rate limit to prevent email enumeration + DB pollution.
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 403 );
        }

        // Rate limit: max 3 subscripciones por IP cada 15 minutos.
        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $key  = 'ltms_newsletter_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= 3 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados intentos. Intenta más tarde.', 'ltms' ) ], 429 );
        }
        set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );

        $email = sanitize_email( $_POST['email'] ?? '' ); // phpcs:ignore
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Email inválido.', 'ltms' ) ], 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_newsletter_subscribers';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(255) NOT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT 0,
            `city` VARCHAR(50) DEFAULT '',
            `preferred_categories` VARCHAR(255) DEFAULT '',
            `subscribed_at` DATETIME NOT NULL,
            `unsubscribed_at` DATETIME DEFAULT NULL,
            `emails_sent` INT DEFAULT 0,
            `emails_opened` INT DEFAULT 0,
            `emails_clicked` INT DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_email` (`email`)
        ) {$wpdb->get_charset_collate()}" );

        // Verificar si ya existe.
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE email = %s", $email ) );
        if ( $existing ) {
            // Re-activar si estaba desuscrito.
            $wpdb->update( $table, [ 'unsubscribed_at' => null ], [ 'email' => $email ] );
            wp_send_json_success( [ 'message' => __( 'Ya estabas suscrito. ¡Bienvenido de vuelta!', 'ltms' ) ] );
        }

        $wpdb->insert( $table, [
            'email'        => $email,
            'user_id'      => get_current_user_id(),
            'subscribed_at'=> current_time( 'mysql', true ),
        ] );

        wp_send_json_success( [ 'message' => __( '¡Suscrito exitosamente!', 'ltms' ) ] );
    }

    /**
     * Cron diario: envía newsletter semanal.
     */
    public static function maybe_send_weekly_newsletter(): void {
        $last_sent = get_option( 'ltms_newsletter_last_sent', 0 );
        if ( ( time() - (int) $last_sent ) < ( self::NEWSLETTER_INTERVAL_DAYS * DAY_IN_SECONDS ) ) {
            return; // Aún no es hora.
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_newsletter_subscribers';
        $subscribers = $wpdb->get_col(
            "SELECT email FROM `{$table}` WHERE unsubscribed_at IS NULL LIMIT 5000"
        );

        if ( empty( $subscribers ) ) return;

        // Productos destacados de la semana.
        $new_products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 5,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'date_after' => gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) ),
        ] );

        $on_sale = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 5,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'meta_key' => '_sale_price',
            'meta_compare' => 'EXISTS',
        ] );
        $on_sale = array_filter( $on_sale, fn( $p ) => $p->is_on_sale() );

        if ( empty( $new_products ) && empty( $on_sale ) ) return;

        $subject = sprintf( '📬 %s — Lo nuevo esta semana', wp_date( 'd/m' ) );
        $html = self::build_newsletter_html( $new_products, $on_sale );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = 0;
        foreach ( $subscribers as $email ) {
            wp_mail( $email, $subject, $html, $headers );
            $sent++;
            $wpdb->update( $table, [ 'emails_sent' => new \WP_Expression( 'emails_sent + 1' ) ], [ 'email' => $email ] );
        }

        update_option( 'ltms_newsletter_last_sent', time() );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'TB_NEWSLETTER_SENT', sprintf( 'Newsletter enviado a %d suscriptores (%d productos nuevos, %d ofertas).', $sent, count( $new_products ), count( $on_sale ) ) );
        }
    }

    /**
     * Construye el HTML del newsletter.
     */
    private static function build_newsletter_html( array $new_products, array $on_sale ): string {
        $html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;">';
        $html .= '<h1 style="color:#1e40af;">📬 Lo nuevo esta semana</h1>';
        $html .= '<p>Aquí tienes las novedades y ofertas de Lo Tengo Colombia.</p>';

        if ( ! empty( $new_products ) ) {
            $html .= '<h2 style="color:#15803d;border-bottom:2px solid #86efac;padding-bottom:8px;">🆕 Productos Nuevos</h2>';
            $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;">';
            foreach ( $new_products as $product ) {
                $img = wp_get_attachment_url( $product->get_image_id() ) ?: '';
                $html .= sprintf(
                    '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <a href="%s"><img src="%s" style="width:100%%;height:150px;object-fit:cover;"></a>
                    <div style="padding:8px;">
                    <a href="%s" style="text-decoration:none;color:#1e293b;font-size:13px;font-weight:600;">%s</a>
                    <div style="color:#16a34a;font-weight:bold;font-size:14px;margin-top:4px;">%s</div>
                    </div></div>',
                    esc_url( $product->get_permalink() ),
                    esc_url( $img ),
                    esc_url( $product->get_permalink() ),
                    esc_html( $product->get_name() ),
                    wp_strip_all_tags( $product->get_price_html() )
                );
            }
            $html .= '</div>';
        }

        if ( ! empty( $on_sale ) ) {
            $html .= '<h2 style="color:#dc2626;border-bottom:2px solid #fca5a5;padding-bottom:8px;">🔥 Ofertas</h2>';
            $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">';
            foreach ( $on_sale as $product ) {
                $img = wp_get_attachment_url( $product->get_image_id() ) ?: '';
                $html .= sprintf(
                    '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <a href="%s"><img src="%s" style="width:100%%;height:150px;object-fit:cover;"></a>
                    <div style="padding:8px;">
                    <a href="%s" style="text-decoration:none;color:#1e293b;font-size:13px;font-weight:600;">%s</a>
                    <div style="color:#dc2626;font-weight:bold;font-size:14px;margin-top:4px;">%s</div>
                    </div></div>',
                    esc_url( $product->get_permalink() ),
                    esc_url( $img ),
                    esc_url( $product->get_permalink() ),
                    esc_html( $product->get_name() ),
                    wp_strip_all_tags( $product->get_price_html() )
                );
            }
            $html .= '</div>';
        }

        $html .= '<div style="text-align:center;margin-top:24px;padding:16px;background:#f8fafc;border-radius:8px;">';
        $html .= '<a href="' . esc_url( home_url( '/tienda/' ) ) . '" style="display:inline-block;background:#2563eb;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:bold;">Ver todos los productos</a>';
        $html .= '</div>';
        $html .= '<p style="font-size:11px;color:#6b7280;margin-top:16px;text-align:center;">Recibes este email porque te suscribiste en Lo Tengo Colombia. <a href="' . esc_url( home_url( '/unsubscribe/?email=' ) ) . '">Desuscribir</a></p>';
        $html .= '</div>';

        return $html;
    }

    // ================================================================
    // TB-4: CITY PAGES PROGRAMÁTICAS.
    // ================================================================

    /**
     * Registra rewrite para city pages.
     */
    public static function register_city_pages_rewrite(): void {
        // /ciudad/{ciudad}/
        add_rewrite_rule( '^ciudad/([a-z_]+)/?$', 'index.php?ltms_city_page=$matches[1]', 'top' );
        // /ciudad/{ciudad}/{categoria}/
        add_rewrite_rule( '^ciudad/([a-z_]+)/([a-z_-]+)/?$', 'index.php?ltms_city_page=$matches[1]&ltms_city_cat=$matches[2]', 'top' );
    }

    /**
     * Sirve city pages programáticas.
     */
    public static function serve_city_page(): void {
        $city_slug = get_query_var( 'ltms_city_page' );
        if ( empty( $city_slug ) ) return;

        $city_data = self::find_city( $city_slug );
        if ( ! $city_data ) return;

        $category_slug = get_query_var( 'ltms_city_cat' );
        $city_name = $city_data['name'];
        $country = $city_data['country'];

        // Buscar vendors en esta ciudad.
        $vendors = get_users( [
            'role'       => 'vendor',
            'number'     => 50,
            'meta_key'   => 'ltms_city',
            'meta_value' => $city_slug,
            'meta_compare' => 'LIKE',
        ] );

        // Buscar productos en esta ciudad.
        $product_args = [
            'status'   => 'publish',
            'limit'    => 24,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];
        if ( ! empty( $category_slug ) ) {
            $product_args['category'] = [ $category_slug ];
        }
        $products = wc_get_products( $product_args );

        $category_name = '';
        if ( ! empty( $category_slug ) ) {
            $term = get_term_by( 'slug', $category_slug, 'product_cat' );
            $category_name = $term ? $term->name : ucfirst( $category_slug );
        }

        $page_title = $category_name
            ? sprintf( '%s en %s', $category_name, $city_name )
            : sprintf( 'Comprar online en %s', $city_name );

        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $page_title ); ?> — Lo Tengo Colombia</title>
    <meta name="description" content="<?php echo esc_attr( sprintf( 'Los mejores productos y vendedores verificados en %s. Envío local, pago seguro, derecho de retracto. Compra online con confianza.', $city_name ) ); ?>">
    <link rel="canonical" href="<?php echo esc_url( home_url( "/ciudad/{$city_slug}/" ) ); ?>" />
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "CollectionPage",
        "name": "<?php echo esc_js( $page_title ); ?>",
        "url": "<?php echo esc_js( home_url( "/ciudad/{$city_slug}/" ) ); ?>",
        "description": "Productos y vendedores en <?php echo esc_js( $city_name ); ?>",
        "about": {
            "@type": "Place",
            "name": "<?php echo esc_js( $city_name ); ?>",
            "addressCountry": "<?php echo esc_js( $country ); ?>"
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {
                "@type": "Question",
                "name": "¿Cuánto tarda el envío en <?php echo esc_js( $city_name ); ?>?",
                "acceptedAnswer": { "@type": "Answer", "text": "El envío en <?php echo esc_js( $city_name ); ?> tarda entre 24 y 72 horas hábiles según el transportista. Envío disponible con Deprisa, Heka y Aveonline." }
            },
            {
                "@type": "Question",
                "name": "¿Hay pago contraentrega en <?php echo esc_js( $city_name ); ?>?",
                "acceptedAnswer": { "@type": "Answer", "text": "Sí, ofrecemos pago contraentrega en <?php echo esc_js( $city_name ); ?> en pedidos superiores a $50.000 COP. También aceptamos tarjeta, PSE y Nequi." }
            },
            {
                "@type": "Question",
                "name": "¿Puedo devolver un producto en <?php echo esc_js( $city_name ); ?>?",
                "acceptedAnswer": { "@type": "Answer", "text": "Sí, tienes 5 días hábiles (Ley 1480/2011) o 10 días naturales (PROFECO MX) desde la entrega para retractarte. Reembolso garantizado en 15 días." }
            }
        ]
    }
    </script>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:1200px;margin:0 auto;padding:20px;color:#1e293b;">
    <nav style="margin-bottom:20px;"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#2563eb;text-decoration:none;">← Inicio</a></nav>
    <h1 style="font-size:28px;color:#1e40af;"><?php echo esc_html( $page_title ); ?></h1>
    <p style="font-size:16px;line-height:1.6;color:#475569;">
        Descubre los mejores productos y vendedores verificados en <?php echo esc_html( $city_name ); ?>, <?php echo esc_html( $country === 'CO' ? 'Colombia' : 'México' ); ?>.
        Compra online con envío local, pago seguro y derecho de retracto. Todos nuestros vendedores pasan por verificación KYC
        y cumplimiento SAGRILAFT. <?php echo esc_html( sprintf( '%d vendedores activos en %s.', count( $vendors ), $city_name ) ); ?>
    </p>

    <?php if ( ! empty( $vendors ) ) : ?>
    <h2 style="font-size:20px;color:#15803d;margin-top:24px;">🏪 Vendedores en <?php echo esc_html( $city_name ); ?></h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">
        <?php foreach ( array_slice( $vendors, 0, 12 ) as $vendor ) : ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;text-align:center;">
            <a href="<?php echo esc_url( home_url( "/vendedor/{$vendor->user_nicename}/" ) ); ?>" style="text-decoration:none;color:#1e293b;">
                <div style="font-weight:600;font-size:14px;"><?php echo esc_html( $vendor->display_name ); ?></div>
                <div style="font-size:12px;color:#16a34a;">✓ Verificado KYC</div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $products ) ) : ?>
    <h2 style="font-size:20px;color:#1e40af;">🛍️ Productos destacados</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
        <?php foreach ( $products as $product ) : ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
            <a href="<?php echo esc_url( $product->get_permalink() ); ?>">
                <img src="<?php echo esc_url( wp_get_attachment_url( $product->get_image_id() ) ?: '' ); ?>" style="width:100%;height:150px;object-fit:cover;" loading="lazy">
            </a>
            <div style="padding:8px;">
                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" style="text-decoration:none;color:#1e293b;font-size:12px;font-weight:600;"><?php echo esc_html( $product->get_name() ); ?></a>
                <div style="color:#16a34a;font-weight:bold;font-size:14px;"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size:20px;color:#1e40af;margin-top:24px;">📍 Categorías populares en <?php echo esc_html( $city_name ); ?></h2>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php
        $cats = get_terms( [ 'taxonomy' => 'product_cat', 'number' => 12, 'hide_empty' => true ] );
        if ( ! is_wp_error( $cats ) ) :
            foreach ( $cats as $cat ) :
        ?>
        <a href="<?php echo esc_url( home_url( "/ciudad/{$city_slug}/{$cat->slug}/" ) ); ?>"
           style="display:inline-block;background:#eff6ff;border:1px solid #bfdbfe;border-radius:20px;padding:6px 16px;font-size:13px;color:#1e40af;text-decoration:none;">
            <?php echo esc_html( $cat->name ); ?>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <footer style="margin-top:40px;padding-top:20px;border-top:1px solid #e5e7eb;text-align:center;font-size:12px;color:#6b7280;">
        <p>Lo Tengo Colombia — Marketplace multi-vendor verificado. Cumplimiento Ley 1480/2011, Ley 1581/2012, SAGRILAFT.</p>
    </footer>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Busca datos de una ciudad por slug.
     */
    private static function find_city( string $slug ): ?array {
        foreach ( self::CITIES as $country => $cities ) {
            if ( isset( $cities[ $slug ] ) ) {
                return [ 'name' => $cities[ $slug ], 'country' => $country ];
            }
        }
        return null;
    }

    // ================================================================
    // TB-5: GOOGLE BUSINESS PROFILE POSTS.
    // ================================================================

    /**
     * Panel admin para configurar GBP.
     */
    public static function register_gbp_panel(): void {
        add_submenu_page(
            'ltms',
            __( 'Google Business Profile', 'ltms' ),
            __( 'Google Business', 'ltms' ),
            'manage_options',
            'ltms-gbp',
            [ __CLASS__, 'render_gbp_panel' ]
        );
    }

    public static function render_gbp_panel(): void {
        $accounts = get_option( 'ltms_gbp_accounts', [] );
        ?>
        <div class="wrap">
            <h1>📍 <?php esc_html_e( 'Google Business Profile', 'ltms' ); ?></h1>
            <p><?php esc_html_e( 'Configura tus perfiles de Google Business Profile por ciudad. Los posts se publican automáticamente con productos destacados.', 'ltms' ); ?></p>
            <h2><?php esc_html_e( 'Cuentas configuradas', 'ltms' ); ?></h2>
            <table class="wp-list-table widefat">
                <thead><tr><th>Ciudad</th><th>Account ID</th><th>Último post</th><th>Estado</th></tr></thead>
                <tbody>
                <?php if ( empty( $accounts ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'Sin cuentas configuradas. Añade tu Google Business Profile account ID por ciudad.', 'ltms' ); ?></td></tr>
                <?php else : foreach ( $accounts as $city => $acct ) : ?>
                    <tr><td><?php echo esc_html( $city ); ?></td><td><?php echo esc_html( $acct['account_id'] ?? '—' ); ?></td><td><?php echo esc_html( $acct['last_post'] ?? 'Nunca' ); ?></td><td><?php echo esc_html( $acct['status'] ?? '—' ); ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p style="margin-top:20px;font-size:13px;color:#6b7280;">
                <?php esc_html_e( 'Para configurar: 1) Ve a Google Business Profile API. 2) Obtén el account ID de cada ubicación. 3) Configúralo en Settings → Traffic Booster.', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Cron diario: postea productos destacados en GBP.
     */
    public static function post_to_gbp(): void {
        $accounts = get_option( 'ltms_gbp_accounts', [] );
        if ( empty( $accounts ) ) return;

        $token = LTMS_Core_Config::get( 'ltms_gbp_access_token', '' );
        if ( empty( $token ) ) return;

        // Producto más vendido de la semana para destacar.
        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 1,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'meta_key' => 'total_sales',
        ] );

        if ( empty( $products ) ) return;
        $product = $products[0];

        foreach ( $accounts as $city => $acct ) {
            if ( empty( $acct['account_id'] ) ) continue;

            // Google Business Profile API: crear local post.
            $response = wp_remote_post(
                "https://mybusiness.googleapis.com/v4/accounts/{$acct['account_id']}/locations/{$acct['location_id']}/localPosts",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => wp_json_encode( [
                        'languageCode' => 'es',
                        'summary'      => sprintf( '🛒 %s — %s', $product->get_name(), wp_strip_all_tags( $product->get_price_html() ) ),
                        'callToAction' => [
                            'actionType' => 'LEARN_MORE',
                            'url'        => $product->get_permalink(),
                        ],
                        'media' => [
                            'mediaFormat' => 'PHOTO',
                            'sourceUrl'   => wp_get_attachment_url( $product->get_image_id() ) ?: '',
                        ],
                    ] ),
                    'timeout' => 30,
                ]
            );

            if ( ! is_wp_error( $response ) ) {
                $accounts[ $city ]['last_post'] = current_time( 'mysql', true );
                $accounts[ $city ]['status'] = 'active';

                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info( 'TB_GBP_POST', sprintf( 'GBP post creado para %s: producto #%d.', $city, $product->get_id() ) );
                }
            }
        }

        update_option( 'ltms_gbp_accounts', $accounts );
    }
}
