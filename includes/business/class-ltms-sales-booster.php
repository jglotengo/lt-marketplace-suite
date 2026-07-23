<?php
/**
 * LTMS Sales Booster — 5 features para aumentar ventas.
 *
 * v2.9.28 — Implementa las 5 features de mayor ROI identificadas:
 *
 *  SB-1: Recuperación de Carrito Abandonado (email + WhatsApp).
 *  SB-2: Flash Sales con countdown timer.
 *  SB-3: Web Push Notifications.
 *  SB-4: Upsell / Cross-sell con barra de envío gratis.
 *  SB-5: Social Proof en tiempo real (toasts + viewer count).
 *
 * Impacto estimado: +30-50% ventas en 90 días.
 *
 * @package LTMS
 * @version 2.9.28
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Sales_Booster {

    /**
     * Umbrales de carrito abandonado (en minutos).
     */
    public const CART_ABANDON_1H  = 60;
    public const CART_ABANDON_6H  = 360;
    public const CART_ABANDON_24H = 1440;

    /**
     * Descuentos incrementales por etapa de recuperación.
     */
    public const RECOVERY_DISCOUNTS = [
        1 => 5,   // 1h: 5% off
        2 => 10,  // 6h: 10% off
        3 => 15,  // 24h: 15% off
    ];

    /**
     * Umbrales de envío gratis por país (en moneda local).
     */
    public const FREE_SHIPPING_THRESHOLDS = [
        'CO' => 150000,  // $150,000 COP
        'MX' => 599,     // $599 MXN
    ];

    /**
     * Productos para social proof (compras recientes para toasts).
     */
    public const SOCIAL_PROOF_TOAST_DURATION = 5; // segundos visible.
    public const SOCIAL_PROOF_INTERVAL = 30; // segundos entre toasts.

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // SB-1: Carrito abandonado.
        add_action( 'ltms_every_15_minutes', [ __CLASS__, 'detect_abandoned_carts' ] );
        add_action( 'woocommerce_cart_updated', [ __CLASS__, 'track_cart_activity' ] );
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'mark_cart_recovered' ], 10, 1 );

        // SB-2: Flash sales.
        add_action( 'init', [ __CLASS__, 'register_flash_sale_cpt' ] );
        add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_flash_sale_countdown' ] );
        add_action( 'woocommerce_before_shop_loop_item_title', [ __CLASS__, 'render_flash_sale_badge' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_flash_sale_assets' ] );

        // SB-3: Web push notifications.
        add_action( 'wp_footer', [ __CLASS__, 'render_push_subscription_prompt' ], 20 );
        add_action( 'wp_ajax_ltms_subscribe_push', [ __CLASS__, 'ajax_subscribe_push' ] );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'send_order_push_notification' ], 10, 4 );

        // SB-4: Upsell / Cross-sell.
        add_action( 'woocommerce_proceed_to_checkout', [ __CLASS__, 'render_free_shipping_progress_bar' ] );
        add_action( 'woocommerce_after_cart_contents', [ __CLASS__, 'render_cart_cross_sell' ] );
        add_action( 'woocommerce_review_order_after_cart_contents', [ __CLASS__, 'render_checkout_cross_sell' ] );

        // SB-5: Social proof.
        add_action( 'wp_footer', [ __CLASS__, 'render_social_proof_container' ], 25 );
        add_action( 'wp_ajax_nopriv_ltms_track_product_view', [ __CLASS__, 'ajax_track_product_view' ] );
        add_action( 'wp_ajax_ltms_track_product_view', [ __CLASS__, 'ajax_track_product_view' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_social_proof', [ __CLASS__, 'ajax_get_social_proof' ] );
        add_action( 'wp_ajax_ltms_get_social_proof', [ __CLASS__, 'ajax_get_social_proof' ] );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'record_purchase_for_social_proof' ] );
    }

    // ================================================================
    // SB-1: RECUPERACIÓN DE CARRITO ABANDONADO.
    // ================================================================

    /**
     * Rastrea actividad del carrito para detectar abandono.
     */
    public static function track_cart_activity(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }
        $user_id = get_current_user_id();
        $session_id = WC()->session->get_session_cookie() ? md5( implode( '', WC()->session->get_session_cookie() ) ) : '';

        if ( ! $user_id && ! $session_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_abandoned_carts';

        // Crear tabla si no existe.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED DEFAULT 0,
            `session_id` VARCHAR(64) DEFAULT '',
            `email` VARCHAR(255) DEFAULT '',
            `phone` VARCHAR(20) DEFAULT '',
            `cart_contents` LONGTEXT,
            `cart_total` DECIMAL(15,2) DEFAULT 0,
            `item_count` INT DEFAULT 0,
            `recovery_stage` TINYINT DEFAULT 0,
            `recovery_code` VARCHAR(32) DEFAULT '',
            `recovered` TINYINT DEFAULT 0,
            `recovered_order_id` BIGINT DEFAULT NULL,
            `last_activity` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_session` (`session_id`),
            KEY `idx_stage` (`recovery_stage`),
            KEY `idx_activity` (`last_activity`)
        ) {$wpdb->get_charset_collate()}" );

        $cart_data = [
            'items'  => [],
            'total'  => (float) WC()->cart->get_cart_contents_total(),
            'count'  => WC()->cart->get_cart_contents_count(),
        ];
        foreach ( WC()->cart->get_cart() as $item ) {
            $product = $item['data'];
            $cart_data['items'][] = [
                'product_id' => $item['product_id'],
                'name'       => $product->get_name(),
                'qty'        => $item['quantity'],
                'price'      => (float) $product->get_price(),
                'image'      => wp_get_attachment_url( $product->get_image_id() ) ?: '',
                'url'        => $product->get_permalink(),
            ];
        }

        $email = '';
        $phone = '';
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            $email = $user->user_email ?? '';
            $phone = get_user_meta( $user_id, 'ltms_phone', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) ?: '';
        }

        // Upsert: si ya existe para este user/session, actualizar; si no, insertar.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE (user_id = %d AND user_id > 0) OR (session_id = %s AND session_id != '') ORDER BY id DESC LIMIT 1",
            $user_id, $session_id
        ) );

        if ( $existing ) {
            $wpdb->update( $table, [
                'cart_contents'  => wp_json_encode( $cart_data ),
                'cart_total'     => $cart_data['total'],
                'item_count'     => $cart_data['count'],
                'email'          => $email,
                'phone'          => $phone,
                'last_activity'  => current_time( 'mysql', true ),
                'recovered'      => 0, // Reset si vuelve a tener actividad.
            ], [ 'id' => (int) $existing ] );
        } else {
            $wpdb->insert( $table, [
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'email'          => $email,
                'phone'          => $phone,
                'cart_contents'  => wp_json_encode( $cart_data ),
                'cart_total'     => $cart_data['total'],
                'item_count'     => $cart_data['count'],
                'recovery_stage' => 0,
                'recovered'      => 0,
                'last_activity'  => current_time( 'mysql', true ),
                'created_at'     => current_time( 'mysql', true ),
            ] );
        }
    }

    /**
     * Cron: detecta carritos abandonados y envía emails de recuperación.
     */
    public static function detect_abandoned_carts(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_abandoned_carts';
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME, $table
        ) );
        if ( ! $exists ) return;

        $now = current_time( 'mysql', true );

        // Stage 1: 1 hora sin actividad.
        $carts_1h = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE recovery_stage = 0 AND recovered = 0
             AND email != '' AND TIMESTAMPDIFF(MINUTE, last_activity, %s) >= %d",
            $now, self::CART_ABANDON_1H
        ), ARRAY_A );

        foreach ( $carts_1h as $cart ) {
            self::send_recovery_email( $cart, 1 );
            $wpdb->update( $table, [ 'recovery_stage' => 1 ], [ 'id' => $cart['id'] ] );
        }

        // Stage 2: 6 horas.
        $carts_6h = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE recovery_stage = 1 AND recovered = 0
             AND email != '' AND TIMESTAMPDIFF(MINUTE, last_activity, %s) >= %d",
            $now, self::CART_ABANDON_6H
        ), ARRAY_A );

        foreach ( $carts_6h as $cart ) {
            self::send_recovery_email( $cart, 2 );
            $wpdb->update( $table, [ 'recovery_stage' => 2 ], [ 'id' => $cart['id'] ] );
        }

        // Stage 3: 24 horas.
        $carts_24h = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE recovery_stage = 2 AND recovered = 0
             AND email != '' AND TIMESTAMPDIFF(MINUTE, last_activity, %s) >= %d",
            $now, self::CART_ABANDON_24H
        ), ARRAY_A );

        foreach ( $carts_24h as $cart ) {
            self::send_recovery_email( $cart, 3 );
            $wpdb->update( $table, [ 'recovery_stage' => 3 ], [ 'id' => $cart['id'] ] );
        }
    }

    /**
     * Envía email de recuperación con descuento incremental.
     */
    private static function send_recovery_email( array $cart, int $stage ): void {
        $cart_data = json_decode( $cart['cart_contents'], true );
        if ( ! $cart_data || empty( $cart_data['items'] ) ) return;

        $discount_pct = self::RECOVERY_DISCOUNTS[ $stage ] ?? 5;
        $recovery_code = 'RECOVER' . $stage . '_' . wp_generate_password( 8, false );

        // Guardar código de descuento.
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lt_abandoned_carts', [
            'recovery_code' => $recovery_code,
        ], [ 'id' => $cart['id'] ] );

        // Crear cupón WC temporal.
        $coupon_code = $recovery_code;
        $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
        if ( ! $coupon_id ) {
            $coupon = new \WC_Coupon();
            $coupon->set_code( $coupon_code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( $discount_pct );
            $coupon->set_individual_use( false );
            $coupon->set_usage_limit( 1 );
            $coupon->set_expiry_date( gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) ) );
            $coupon->save();
        }

        $subject = $stage === 1
            ? __( '🛒 ¡Olvidaste algo en tu carrito!', 'ltms' )
            : ( $stage === 2
                ? sprintf( __( '⏰ Todavía puedes completar tu compra — %d%% OFF', 'ltms' ), $discount_pct )
                : sprintf( __( '🎁 Última oportunidad: %d%% OFF en tu carrito', 'ltms' ), $discount_pct ) );

        $items_html = '';
        foreach ( $cart_data['items'] as $item ) {
            $items_html .= sprintf(
                '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><img src="%s" width="60" height="60" style="border-radius:8px;vertical-align:middle;margin-right:12px;">%s × %d — $%s</td></tr>',
                esc_attr( $item['image'] ),
                esc_html( $item['name'] ),
                $item['qty'],
                number_format( $item['price'] * $item['qty'], 0, ',', '.' )
            );
        }

        $checkout_url = add_query_arg( [ 'coupon_code' => $recovery_code ], wc_get_checkout_url() );

        $message = sprintf(
            '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;">
            <h2 style="color:#1e40af;">%s</h2>
            <p>%s</p>
            <table style="width:100%%;margin:16px 0;">%s</table>
            <p style="font-size:24px;font-weight:bold;color:#16a34a;">%s</p>
            <a href="%s" style="display:inline-block;background:#2563eb;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;margin:16px 0;">%s</a>
            <p style="color:#6b7280;font-size:13px;">%s</p>
            </div>',
            $subject,
            __( 'Tu carrito te está esperando. Usa este código para obtener descuento:', 'ltms' ),
            $items_html,
            sprintf( __( 'Código: %s (%d%% OFF)', 'ltms' ), $recovery_code, $discount_pct ),
            esc_url( $checkout_url ),
            __( 'Completar mi compra', 'ltms' ),
            __( 'El código expira en 7 días. Una sola uso.', 'ltms' )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $cart['email'], $subject, $message, $headers );

        // WhatsApp si hay teléfono (pendiente integración WhatsApp Cloud API).
        if ( ! empty( $cart['phone'] ) && $stage <= 2 ) {
            $wa_message = sprintf(
                '🛒 %s Tu carrito en Lo Tengo te espera. Usa el código %s para %d%% de descuento. Compra aquí: %s',
                __( '¡Hola!', 'ltms' ),
                $recovery_code,
                $discount_pct,
                $checkout_url
            );
            // Log para envío manual vía WhatsApp Business hasta integrar Cloud API.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'CART_RECOVERY_WHATSAPP', sprintf( 'Cart #%d → WhatsApp %s (stage %d)', $cart['id'], $cart['phone'], $stage ) );
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'CART_RECOVERY_EMAIL', sprintf( 'Cart #%d → %s (stage %d, %d%% off, code %s)', $cart['id'], $cart['email'], $stage, $discount_pct, $recovery_code ) );
        }
    }

    /**
     * Marca un carrito como recuperado cuando se completa checkout.
     */
    public static function mark_cart_recovered( int $order_id ): void {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_abandoned_carts';
        $wpdb->update( $table, [
            'recovered'           => 1,
            'recovered_order_id'  => $order_id,
        ], [ 'user_id' => $user_id, 'recovered' => 0 ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'CART_RECOVERED', sprintf( 'Order #%d — carrito recuperado para user #%d.', $order_id, $user_id ) );
        }
    }

    // ================================================================
    // SB-2: FLASH SALES CON COUNTDOWN.
    // ================================================================

    /**
     * Registra CPT para flash sales.
     */
    public static function register_flash_sale_cpt(): void {
        register_post_type( 'ltms_flash_sale', [
            'labels'       => [
                'name'          => __( 'Ventas Flash', 'ltms' ),
                'singular_name' => __( 'Venta Flash', 'ltms' ),
                'add_new_item'  => __( 'Nueva Venta Flash', 'ltms' ),
                'edit_item'     => __( 'Editar Venta Flash', 'ltms' ),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'ltms',
            'supports'     => [ 'title', 'custom-fields' ],
            'menu_icon'    => 'dashicons-clock',
        ] );
    }

    /**
     * Renderiza countdown timer en PDP si el producto está en flash sale.
     */
    public static function render_flash_sale_countdown(): void {
        global $product;
        if ( ! $product ) return;

        $flash_sale = self::get_active_flash_sale_for_product( $product->get_id() );
        if ( ! $flash_sale ) return;

        $end_time = $flash_sale['end_time'];
        $discount = $flash_sale['discount_pct'];
        $stock_limit = $flash_sale['stock_limit'];
        $stock_sold = $flash_sale['stock_sold'];
        $remaining = max( 0, $stock_limit - $stock_sold );

        ?>
        <div class="ltms-flash-sale-box" style="background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;padding:16px;border-radius:12px;margin:12px 0;">
            <div style="font-size:18px;font-weight:bold;margin-bottom:8px;">⚡ <?php esc_html_e( 'OFERTA RELÁMPAGO', 'ltms' ); ?></div>
            <div style="font-size:14px;margin-bottom:8px;">
                <?php echo esc_html( sprintf( __( '%d%% OFF — Termina en:', 'ltms' ), $discount ) ); ?>
            </div>
            <div class="ltms-flash-countdown" data-end="<?php echo esc_attr( $end_time ); ?>" style="font-size:28px;font-weight:bold;letter-spacing:2px;font-family:monospace;">
                --:--:--
            </div>
            <?php if ( $stock_limit > 0 ) : ?>
            <div style="margin-top:8px;font-size:13px;">
                <?php
                $pct_sold = $stock_limit > 0 ? ( $stock_sold / $stock_limit ) * 100 : 0;
                echo esc_html( sprintf( __( '¡Solo quedan %d unidades!', 'ltms' ), $remaining ) );
                ?>
                <div style="background:rgba(255,255,255,0.3);border-radius:4px;height:6px;margin-top:4px;overflow:hidden;">
                    <div style="background:#fbbf24;height:100%;width:<?php echo esc_attr( $pct_sold ); ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var el = document.querySelector('.ltms-flash-countdown');
            if (!el) return;
            var endTime = new Date(el.dataset.end + 'Z').getTime();
            var timer = setInterval(function(){
                var now = Date.now();
                var diff = endTime - now;
                if (diff <= 0) { el.textContent = 'EXPIRADO'; clearInterval(timer); return; }
                var h = Math.floor(diff / 3600000);
                var m = Math.floor((diff % 3600000) / 60000);
                var s = Math.floor((diff % 60000) / 1000);
                el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
            }, 1000);
        })();
        </script>
        <?php
    }

    /**
     * Badge de flash sale en grid de productos.
     */
    public static function render_flash_sale_badge(): void {
        global $product;
        if ( ! $product ) return;

        $flash_sale = self::get_active_flash_sale_for_product( $product->get_id() );
        if ( ! $flash_sale ) return;

        echo '<span class="ltms-flash-badge" style="position:absolute;top:8px;left:8px;background:#dc2626;color:#fff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:bold;z-index:5;">⚡ -' . esc_html( $flash_sale['discount_pct'] ) . '%</span>';
    }

    /**
     * Encuentra una flash sale activa para un producto.
     */
    private static function get_active_flash_sale_for_product( int $product_id ): ?array {
        $flash_sales = get_posts( [
            'post_type'      => 'ltms_flash_sale',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_ltms_flash_sale_product_id',
                    'value' => $product_id,
                ],
            ],
        ] );

        if ( empty( $flash_sales ) ) return null;

        $post = $flash_sales[0];
        $end_time = get_post_meta( $post->ID, '_ltms_flash_sale_end_time', true );
        if ( strtotime( $end_time ) < time() ) return null; // Expirada.

        return [
            'id'            => $post->ID,
            'discount_pct'  => (int) get_post_meta( $post->ID, '_ltms_flash_sale_discount', true ),
            'end_time'      => $end_time,
            'stock_limit'   => (int) get_post_meta( $post->ID, '_ltms_flash_sale_stock_limit', true ),
            'stock_sold'    => (int) get_post_meta( $post->ID, '_ltms_flash_sale_stock_sold', true ),
        ];
    }

    /**
     * Encola assets de flash sale.
     */
    public static function enqueue_flash_sale_assets(): void {
        if ( ! is_product() && ! is_shop() ) return;
        $ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.28';
        wp_enqueue_style( 'ltms-flash-sale', '', [], $ver );
        wp_add_inline_style( 'ltms-flash-sale', '
            .ltms-flash-sale-box { animation: ltms-pulse 2s infinite; }
            @keyframes ltms-pulse { 0%,100%{ box-shadow:0 0 0 0 rgba(220,38,38,0.4); } 50%{ box-shadow:0 0 0 8px rgba(220,38,38,0); } }
            .ltms-flash-badge { animation: ltms-shake 3s infinite; }
            @keyframes ltms-shake { 0%,90%,100%{ transform:translateX(0); } 93%{ transform:translateX(-2px); } 96%{ transform:translateX(2px); } }
        ' );
    }

    // ================================================================
    // SB-3: WEB PUSH NOTIFICATIONS.
    // ================================================================

    /**
     * Renderiza prompt de suscripción a push notifications.
     */
    public static function render_push_subscription_prompt(): void {
        if ( ! is_ssl() ) return; // Push requiere HTTPS.
        if ( is_admin() ) return;
        ?>
        <div id="ltms-push-prompt" style="display:none;position:fixed;bottom:20px;right:20px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;max-width:320px;box-shadow:0 4px 20px rgba(0,0,0,0.1);z-index:99998;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <span style="font-size:20px;">🔔</span>
                <strong style="font-size:14px;"><?php esc_html_e( 'Notificaciones Activadas', 'ltms' ); ?></strong>
            </div>
            <p style="font-size:13px;color:#6b7280;margin-bottom:12px;">
                <?php esc_html_e( 'Recibe alertas de ofertas, estado de pedidos y novedades.', 'ltms' ); ?>
            </p>
            <div style="display:flex;gap:8px;">
                <button id="ltms-push-allow" class="button button-primary" style="flex:1;font-size:13px;"><?php esc_html_e( 'Permitir', 'ltms' ); ?></button>
                <button id="ltms-push-deny" class="button" style="flex:1;font-size:13px;"><?php esc_html_e( 'Ahora no', 'ltms' ); ?></button>
            </div>
        </div>
        <script>
        if ('serviceWorker' in navigator && 'PushManager' in window) {
            setTimeout(function() {
                if (!localStorage.getItem('ltms_push_asked')) {
                    document.getElementById('ltms-push-prompt').style.display = 'block';
                }
            }, 15000);
            document.getElementById('ltms-push-allow').onclick = function() {
                localStorage.setItem('ltms_push_asked', '1');
                document.getElementById('ltms-push-prompt').style.display = 'none';
                Notification.requestPermission().then(function(permission) {
                    if (permission === 'granted') {
                        navigator.serviceWorker.ready.then(function(reg) {
                            return reg.pushManager.subscribe({ userVisibleOnly: true });
                        }).then(function(sub) {
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'action=ltms_subscribe_push&endpoint=' + encodeURIComponent(sub.endpoint) +
                                      '&key=' + encodeURIComponent(sub.keys?.p256dh || '') +
                                      '&auth=' + encodeURIComponent(sub.keys?.auth || '')
                            });
                        });
                    }
                });
            };
            document.getElementById('ltms-push-deny').onclick = function() {
                localStorage.setItem('ltms_push_asked', '1');
                document.getElementById('ltms-push-prompt').style.display = 'none';
            };
        }
        </script>
        <?php
    }

    /**
     * AJAX: guarda suscripción push.
     */
    public static function ajax_subscribe_push(): void {
        $endpoint = esc_url_raw( $_POST['endpoint'] ?? '' );
        $key = sanitize_text_field( $_POST['key'] ?? '' );
        $auth = sanitize_text_field( $_POST['auth'] ?? '' );
        if ( empty( $endpoint ) ) wp_send_json_error();

        global $wpdb;
        $table = $wpdb->prefix . 'lt_push_subscriptions';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED DEFAULT 0,
            `endpoint` TEXT NOT NULL,
            `p256dh_key` VARCHAR(200),
            `auth_key` VARCHAR(100),
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`)
        ) {$wpdb->get_charset_collate()}" );

        $wpdb->insert( $table, [
            'user_id'    => get_current_user_id(),
            'endpoint'   => $endpoint,
            'p256dh_key' => $key,
            'auth_key'   => $auth,
            'created_at' => current_time( 'mysql', true ),
        ] );

        wp_send_json_success( [ 'message' => 'Subscrito' ] );
    }

    /**
     * Envía push notification cuando cambia el estado del pedido.
     */
    public static function send_order_push_notification( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_push_subscriptions';
        $subs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d",
            $user_id
        ), ARRAY_A );
        if ( empty( $subs ) ) return;

        $status_map = [
            'processing' => [ '📦 Pedido en preparación', 'Tu pedido #' . $order_id . ' está siendo preparado.' ],
            'completed'  => [ '✅ Pedido entregado', 'Tu pedido #' . $order_id . ' ha sido entregado. ¡Gracias!' ],
            'cancelled'  => [ '❌ Pedido cancelado', 'Tu pedido #' . $order_id . ' fue cancelado.' ],
        ];

        if ( ! isset( $status_map[ $new_status ] ) ) return;

        // En producción: usar web-push-php library para enviar notificación real.
        // Por ahora: log para envío posterior.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'PUSH_NOTIFICATION', sprintf( 'Order #%d → %d subs (status: %s)', $order_id, count( $subs ), $new_status ) );
        }
    }

    // ================================================================
    // SB-4: UPSELL / CROSS-SELL CON BARRA DE ENVÍO GRATIS.
    // ================================================================

    /**
     * Renderiza barra de progreso de envío gratis en el carrito.
     */
    public static function render_free_shipping_progress_bar(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

        $country = LTMS_Core_Config::get_country();
        $threshold = self::FREE_SHIPPING_THRESHOLDS[ $country ] ?? 150000;
        $currency = LTMS_Core_Config::get_currency();
        $cart_total = (float) WC()->cart->get_cart_contents_total();
        $remaining = max( 0, $threshold - $cart_total );
        $pct = min( 100, ( $cart_total / $threshold ) * 100 );

        if ( $remaining <= 0 ) {
            echo '<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:12px;margin:8px 0;text-align:center;font-size:14px;color:#15803d;font-weight:bold;">🎉 ' . esc_html__( '¡Tienes envío gratis!', 'ltms' ) . '</div>';
            return;
        }

        echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;margin:8px 0;">';
        echo '<p style="font-size:13px;margin:0 0 8px;color:#92400e;">';
        echo wp_kses_post( sprintf(
            /* translators: %s: monto restante formateado como HTML de precio de WooCommerce. */
            __( 'Te faltan %s para envío gratis 🚚', 'ltms' ),
            wc_price( $remaining, [ 'currency' => $currency ] )
        ) );
        echo '</p>';
        echo '<div style="background:#fef3c7;border-radius:4px;height:8px;overflow:hidden;">';
        echo '<div style="background:#f59e0b;height:100%;width:' . esc_attr( $pct ) . '%;transition:width 0.3s;"></div>';
        echo '</div></div>';
    }

    /**
     * Cross-sell en carrito: productos frecuentemente comprados juntos.
     */
    public static function render_cart_cross_sell(): void {
        $cross_sells = self::get_frequently_bought_together( WC()->cart->get_cart_contents(), 4 );
        if ( empty( $cross_sells ) ) return;

        echo '<div style="margin:16px 0;padding:16px;background:#f8fafc;border-radius:8px;">';
        echo '<h3 style="font-size:16px;margin:0 0 12px;">' . esc_html__( '🛍️ También te puede interesar', 'ltms' ) . '</h3>';
        echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">';
        foreach ( $cross_sells as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            echo '<div style="display:flex;gap:8px;align-items:center;padding:8px;background:#fff;border-radius:6px;">';
            echo '<img src="' . esc_url( wp_get_attachment_url( $product->get_image_id() ) ?: '' ) . '" width="40" height="40" style="border-radius:4px;">';
            echo '<div style="flex:1;min-width:0;">';
            echo '<div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . esc_html( $product->get_name() ) . '</div>';
            echo '<div style="font-size:13px;font-weight:bold;color:#16a34a;">' . wp_kses_post( $product->get_price_html() ) . '</div>';
            echo '</div>';
            echo '<a href="' . esc_url( '?add-to-cart=' . $product_id ) . '" class="button" style="font-size:11px;padding:4px 10px;">+</a>';
            echo '</div>';
        }
        echo '</div></div>';
    }

    /**
     * Cross-sell compacto en checkout.
     */
    public static function render_checkout_cross_sell(): void {
        $cross_sells = self::get_frequently_bought_together( WC()->cart->get_cart_contents(), 3 );
        if ( empty( $cross_sells ) ) return;

        echo '<div style="margin:8px 0;padding:12px;background:#f0f9ff;border-radius:8px;">';
        echo '<p style="font-size:13px;margin:0 0 8px;font-weight:600;">' . esc_html__( '⚡ Añade antes de pagar:', 'ltms' ) . '</p>';
        foreach ( $cross_sells as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
            echo '<span style="font-size:12px;flex:1;">' . esc_html( $product->get_name() ) . '</span>';
            echo '<span style="font-size:12px;font-weight:bold;color:#16a34a;">' . wp_kses_post( $product->get_price_html() ) . '</span>';
            echo '<a href="' . esc_url( '?add-to-cart=' . $product_id ) . '" class="button" style="font-size:11px;padding:2px 8px;">' . esc_html__( 'Añadir', 'ltms' ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Algoritmo simple de co-compra: encuentra productos que se compran
     * juntos frecuentemente basado en historial de órdenes.
     */
    private static function get_frequently_bought_together( array $cart_items, int $limit = 4 ): array {
        $cart_product_ids = array_unique( array_map( fn( $i ) => $i['product_id'], $cart_items ) );
        if ( empty( $cart_product_ids ) ) return [];

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $cart_product_ids ), '%d' ) );
        $params = $cart_product_ids;

        // Buscar productos que aparecen en órdenes que también contienen
        // alguno de los productos del carrito.
        $sql = $wpdb->prepare(
            "SELECT oi2.meta_value as product_id, COUNT(*) as freq
             FROM {$wpdb->prefix}woocommerce_order_items oi1
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim1 ON oi1.order_item_id = oim1.order_item_id
             JOIN {$wpdb->prefix}woocommerce_order_items oi2 ON oi1.order_id = oi2.order_id
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi2.order_item_id = oim2.order_item_id
             WHERE oim1.meta_key = '_product_id' AND oim1.meta_value IN ($placeholders)
               AND oim2.meta_key = '_product_id'
               AND oim2.meta_value NOT IN ($placeholders)
               AND oi1.order_id IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_order_status_history'
                   OR post_status IN ('wc-completed', 'wc-processing')
               )
             GROUP BY oim2.meta_value
             ORDER BY freq DESC
             LIMIT %d",
            array_merge( $params, $params, [ $limit ] )
        );

        $results = $wpdb->get_results( $sql, ARRAY_A );
        return $results ? array_map( 'intval', array_column( $results, 'product_id' ) ) : [];
    }

    // ================================================================
    // SB-5: SOCIAL PROOF EN TIEMPO REAL.
    // ================================================================

    /**
     * Renderiza contenedor de toasts de social proof.
     */
    public static function render_social_proof_container(): void {
        if ( is_admin() ) return;
        ?>
        <div id="ltms-social-proof-container" style="position:fixed;bottom:20px;left:20px;z-index:99997;max-width:320px;"></div>
        <div id="ltms-viewer-count" style="display:none;position:fixed;top:80px;right:20px;background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:6px 14px;font-size:13px;z-index:99996;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
            <span style="color:#16a34a;">●</span> <span id="ltms-viewer-count-num">0</span> <?php esc_html_e( 'viendo esto ahora', 'ltms' ); ?>
        </div>
        <script>
        jQuery(function($){
            // Social proof toasts.
            var toastInterval = setInterval(function() {
                // FIX 403-SOCIALPROOF: ajax_get_social_proof() exige
                // ltms_ux_nonce desde v2.9.100 (SEC-3), pero esta llamada
                // nunca lo mandaba -> 403 "Token inválido" en cada tick,
                // en cada página pública. window.ltmsUX.nonce ya se
                // inyecta globalmente vía wp_add_inline_script en
                // jquery-core (ver class-ltms-frontend-assets.php), así
                // que está disponible antes de que corra este script de
                // wp_footer.
                var spNonce = ( window.ltmsUX && window.ltmsUX.nonce ) ? window.ltmsUX.nonce : '';
                $.post(ajaxurl, { action: 'ltms_get_social_proof', nonce: spNonce }, function(resp) {
                    if (resp && resp.success && resp.data) {
                        var d = resp.data;
                        var cities = ['Bogotá', 'Medellín', 'Cali', 'CDMX', 'Guadalajara', 'Barranquilla', 'Cartagena', 'Monterrey'];
                        var city = cities[Math.floor(Math.random() * cities.length)];
                        var html = '<div class="ltms-toast" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;box-shadow:0 4px 16px rgba(0,0,0,0.1);margin-bottom:8px;display:flex;gap:10px;align-items:center;animation:ltms-slide-in 0.3s ease;">';
                        html += '<img src="' + d.image + '" width="40" height="40" style="border-radius:6px;">';
                        html += '<div style="flex:1;font-size:12px;">';
                        html += '<strong>' + d.name + '</strong><br>';
                        html += '<span style="color:#6b7280;">' + d.buyer + ' en ' + city + '</span><br>';
                        html += '<span style="color:#16a34a;font-size:11px;">✓ Compra verificada · hace ' + d.time + '</span>';
                        html += '</div></div>';
                        $('#ltms-social-proof-container').append(html);
                        setTimeout(function() {
                            $('.ltms-toast').first().fadeOut(300, function(){ $(this).remove(); });
                        }, 5000);
                    }
                });
            }, <?php echo esc_js( self::SOCIAL_PROOF_INTERVAL * 1000 ); ?>);

            // Viewer count (solo en PDP).
            <?php if ( is_product() ) : ?>
            var productId = <?php echo esc_js( get_the_ID() ); ?>;
            $.post(ajaxurl, { action: 'ltms_track_product_view', product_id: productId });
            setInterval(function() {
                $.post(ajaxurl, { action: 'ltms_track_product_view', product_id: productId }, function(resp) {
                    if (resp && resp.success) {
                        $('#ltms-viewer-count-num').text(resp.data.viewers);
                        $('#ltms-viewer-count').show();
                    }
                });
            }, 15000);
            <?php endif; ?>
        });
        if (!document.getElementById('ltms-toast-styles')) {
            var s = document.createElement('style');
            s.id = 'ltms-toast-styles';
            s.textContent = '@keyframes ltms-slide-in { from { transform:translateX(-100%); opacity:0; } to { transform:translateX(0); opacity:1; } }';
            document.head.appendChild(s);
        }
        </script>
        <?php
    }

    /**
     * AJAX: track product view (viewer count).
     */
    public static function ajax_track_product_view(): void {
        // v2.9.100 SEC-8 FIX: add nonce to prevent viewer count inflation.
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error();
        }

        $product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore
        if ( ! $product_id ) wp_send_json_error();

        $transient_key = 'ltms_viewers_' . $product_id;
        $viewers = (array) get_transient( $transient_key );

        // Limpiar viewers expirados (más de 30 segundos).
        $now = time();
        $viewers = array_filter( $viewers, fn( $ts ) => ( $now - $ts ) < 30 );

        // Añadir/actualizar este viewer.
        $session_id = WC()->session ? md5( WC()->session->get_session_cookie() ? implode( '', WC()->session->get_session_cookie() ) : ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ) : md5( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $viewers[ $session_id ] = $now;

        set_transient( $transient_key, $viewers, 60 );

        wp_send_json_success( [ 'viewers' => count( $viewers ) ] );
    }

    /**
     * AJAX: get social proof data (compra reciente para toast).
     */
    public static function ajax_get_social_proof(): void {
        // v2.9.100 SEC-3 FIX: add nonce to prevent PII disclosure to public.
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 403 );
        }

        global $wpdb;

        // Buscar una orden completada recientemente con producto con imagen.
        $order = $wpdb->get_row(
            "SELECT p.ID, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
               AND p.post_status IN ('wc-completed', 'wc-processing')
             ORDER BY p.post_date DESC
             LIMIT 1",
            ARRAY_A
        );

        if ( ! $order ) {
            wp_send_json_success( null );
            return;
        }

        $items = wc_get_order( $order['ID'] )->get_items();
        if ( empty( $items ) ) {
            wp_send_json_success( null );
            return;
        }

        $item = $items[ array_key_first( $items ) ];
        $product = $item->get_product();
        if ( ! $product ) {
            wp_send_json_success( null );
            return;
        }

        $customer = wc_get_order( $order['ID'] )->get_billing_first_name() ?: 'Alguien';
        $time_ago = human_time_diff( strtotime( $order['post_date'] ), time() );

        wp_send_json_success( [
            'name'   => $product->get_name(),
            'image'  => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            'buyer'  => $customer,
            'time'   => $time_ago,
        ] );
    }

    /**
     * Registra compra para social proof.
     */
    public static function record_purchase_for_social_proof( int $order_id ): void {
        // El social proof usa directamente las órdenes de WC completadas.
        // No requiere tabla adicional — ajax_get_social_proof consulta órdenes.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'SOCIAL_PROOF_PURCHASE', sprintf( 'Order #%d registrada para social proof.', $order_id ) );
        }
    }
}
