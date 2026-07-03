<?php
/**
 * LTMS Amazon Enhancements — 5 features inspiradas en Amazon.
 *
 * 1. Delivery Promise: fecha estimada de entrega + countdown para pedir hoy.
 * 2. Verified Purchase: badge en reviews de compradores verificados.
 * 3. Add-on Items: cross-selling automático en product page.
 * 4. Gift Options: checkbox "This is a gift" + mensaje + envoltorio.
 * 5. Browsing History: productos vistos recientemente.
 *
 * @package LTMS
 * @version 2.9.5
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Amazon_Enhancements {

    public static function init(): void {
        // 1. Delivery Promise en single product (debajo del precio).
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_delivery_promise' ], 12 );

        // 2. Verified Purchase badge en reviews.
        add_filter( 'woocommerce_review_before_comment_text', [ __CLASS__, 'add_verified_purchase_badge' ], 10, 1 );

        // 3. Add-on Items (debajo del add-to-cart).
        add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'render_addon_items' ], 20 );

        // 4. Gift checkbox en single product.
        add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_gift_checkbox' ], 5 );

        // 4b. Guardar gift data en cart item.
        add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'save_gift_data' ], 10, 3 );

        // 4c. Mostrar gift info en cart.
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_gift_info_cart' ], 10, 2 );

        // 5. Browsing History en home (después del contenido principal).
        add_action( 'woocommerce_after_main_content', [ __CLASS__, 'render_browsing_history' ], 15 );

        // 5b. Tracking: registrar producto visto.
        add_action( 'woocommerce_before_single_product', [ __CLASS__, 'track_product_view' ], 5 );

        // AJAX: eliminar item del browsing history.
        add_action( 'wp_ajax_ltms_remove_browsing_item', [ __CLASS__, 'ajax_remove_browsing_item' ] );
        add_action( 'wp_ajax_nopriv_ltms_remove_browsing_item', [ __CLASS__, 'ajax_remove_browsing_item' ] );
    }

    // ================================================================
    // 1. DELIVERY PROMISE
    // ================================================================

    public static function render_delivery_promise(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $country = LTMS_Core_Config::get_country();

        // Calcular fechas estimadas según carriers disponibles.
        $estimates = self::calculate_delivery_estimates( $vendor_id, $country );

        // Countdown: hora límite para pedir hoy y recibir en el plazo más rápido.
        $cutoff_hour = (int) LTMS_Core_Config::get( 'ltms_delivery_cutoff_hour', 14 ); // 2pm default.
        $now = current_time( 'timestamp' );
        $cutoff_today = strtotime( 'today ' . $cutoff_hour . ':00:00', $now );

        // Si ya pasó el cutoff, el countdown es para mañana.
        if ( $now > $cutoff_today ) {
            $cutoff_today = strtotime( 'tomorrow ' . $cutoff_hour . ':00:00', $now );
            $countdown_label = __( 'Pide mañana antes de las', 'ltms' );
        } else {
            $countdown_label = __( 'Pide en los próximos', 'ltms' );
        }

        $remaining = $cutoff_today - $now;
        $hours = floor( $remaining / 3600 );
        $minutes = floor( ( $remaining % 3600 ) / 60 );
        $countdown_text = sprintf( '%dh %dm', $hours, $minutes );
        ?>
        <div class="ltms-delivery-promise" style="margin:12px 0;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
            <div class="ltms-delivery-promise__dates" style="display:flex;gap:16px;flex-wrap:wrap;">
                <div class="ltms-delivery-promise__option">
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">
                        &#x1F69A; <?php esc_html_e( 'Envío estándar', 'ltms' ); ?>
                    </div>
                    <div style="font-size:15px;font-weight:700;color:#16a34a;">
                        <?php echo esc_html( $estimates['standard_date'] ); ?>
                    </div>
                </div>
                <?php if ( $estimates['express_date'] ) : ?>
                    <div class="ltms-delivery-promise__option">
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">
                            &#x26A1; <?php esc_html_e( 'Envío exprés', 'ltms' ); ?>
                        </div>
                        <div style="font-size:15px;font-weight:700;color:#2563eb;">
                            <?php echo esc_html( $estimates['express_date'] ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ltms-delivery-promise__countdown" id="ltms-delivery-countdown"
                 data-cutoff="<?php echo esc_attr( $cutoff_today ); ?>"
                 data-now="<?php echo esc_attr( $now ); ?>"
                 style="margin-top:8px;font-size:12px;color:#92400e;">
                <span>&#x23F1;</span>
                <span id="ltms-delivery-countdown-text">
                    <?php echo esc_html( $countdown_label . ' ' . $countdown_text ); ?>
                </span>
                <span style="color:#6b7280;"> — <?php esc_html_e( 'recíbelo más rápido', 'ltms' ); ?></span>
            </div>
            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
                <?php esc_html_e( 'Entrega estimada según tu ubicación. Confirma al finalizar la compra.', 'ltms' ); ?>
            </div>
        </div>
        <script>
        // Countdown timer en vivo.
        (function() {
            var cutoff = parseInt(document.getElementById('ltms-delivery-countdown').dataset.cutoff);
            function updateCountdown() {
                var now = Math.floor(Date.now() / 1000);
                var remaining = cutoff - now;
                if (remaining <= 0) {
                    document.getElementById('ltms-delivery-countdown-text').textContent = '<?php esc_html_e( 'Tiempo agotado para hoy', 'ltms' ); ?>';
                    return;
                }
                var h = Math.floor(remaining / 3600);
                var m = Math.floor((remaining % 3600) / 60);
                var label = h > 0 ? h + 'h ' + m + 'm' : m + 'm';
                document.getElementById('ltms-delivery-countdown-text').textContent =
                    '<?php echo esc_js( $countdown_label ); ?> ' + label;
            }
            updateCountdown();
            setInterval(updateCountdown, 60000);
        })();
        </script>
        <?php
    }

    /**
     * Calcula fechas estimadas de entrega.
     */
    private static function calculate_delivery_estimates( int $vendor_id, string $country ): array {
        $standard_days = $country === 'MX' ? 4 : 3;
        $express_days = $country === 'MX' ? 2 : 1;

        // Verificar si Uber Direct está disponible (same-day / next-day).
        $has_uber = (bool) get_option( 'ltms_uber_enabled', false );
        if ( ! $has_uber ) {
            $express_days = $standard_days - 1;
        }

        // Calcular fechas (saltando fines de semana para estándar).
        $standard_date = self::add_business_days( current_time( 'timestamp' ), $standard_days );
        $express_date = self::add_business_days( current_time( 'timestamp' ), $express_days );

        $format = $country === 'MX' ? 'D j M' : 'D j M';

        return [
            'standard_date' => date_i18n( $format, $standard_date ),
            'express_date' => $has_uber ? date_i18n( $format, $express_date ) : null,
        ];
    }

    /**
     * Suma días hábiles (saltando sábados y domingos).
     */
    private static function add_business_days( int $timestamp, int $days ): int {
        $result = $timestamp;
        $added = 0;
        while ( $added < $days ) {
            $result += DAY_IN_SECONDS;
            $dow = (int) date( 'w', $result );
            if ( $dow !== 0 && $dow !== 6 ) { // 0=domingo, 6=sábado.
                $added++;
            }
        }
        return $result;
    }

    // ================================================================
    // 2. VERIFIED PURCHASE BADGE
    // ================================================================

    public static function add_verified_purchase_badge( \WP_Comment $comment ): void {
        $comment_id = $comment->comment_ID;
        $post_id = $comment->comment_post_ID;
        $user_id = (int) $comment->user_id;
        $is_verified = (bool) get_comment_meta( $comment_id, 'verified', true );

        // Si no está marcado como verificado pero el user compró el producto, marcarlo.
        if ( ! $is_verified && $user_id > 0 ) {
            $is_verified = self::user_purchased_product( $user_id, $post_id );
            if ( $is_verified ) {
                update_comment_meta( $comment_id, 'verified', 1 );
            }
        }

        if ( $is_verified ) :
            ?>
            <div class="ltms-verified-purchase" style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;font-size:11px;color:#16a34a;font-weight:600;margin-bottom:6px;">
                <span>&#x2714;</span> <?php esc_html_e( 'Compra verificada', 'ltms' ); ?>
            </div>
            <?php
        endif;
    }

    /**
     * Verifica si un usuario compró un producto específico.
     */
    private static function user_purchased_product( int $user_id, int $product_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
             INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
             WHERE p.post_author = %d
             AND oim.meta_key = '_product_id' AND oim.meta_value = %d
             AND p.post_status IN ('wc-completed', 'wc-processing')",
            $user_id, $product_id
        ) );
        return $count > 0;
    }

    // ================================================================
    // 3. ADD-ON ITEMS (cross-selling automático)
    // ================================================================

    public static function render_addon_items(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );

        // Buscar productos del mismo vendor + misma categoría, excluyendo el actual.
        $query = new \WP_Query( [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 4,
            'author' => $vendor_id,
            'post__not_in' => [ $product->get_id() ],
            'tax_query' => ! empty( $cats ) ? [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats ] ] : [],
            'meta_query' => [ [ 'key' => '_price', 'value' => 0, 'compare' => '>' ] ],
            'orderby' => 'rand',
        ] );

        if ( ! $query->have_posts() ) return;
        ?>
        <div class="ltms-addon-items" style="margin:20px 0;padding:16px;border:1px solid #e5e7eb;border-radius:10px;">
            <h4 style="margin:0 0 12px;font-size:14px;font-weight:700;">
                &#x1F4E6; <?php esc_html_e( 'Añade estos productos complementarios', 'ltms' ); ?>
            </h4>
            <div class="ltms-addon-items__grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;">
                <?php while ( $query->have_posts() ) : $query->the_post();
                    $addon = wc_get_product( get_the_ID() );
                    if ( ! $addon || $addon->get_price() <= 0 ) continue;
                ?>
                    <div class="ltms-addon-item" style="text-align:center;padding:10px;border:1px solid #f3f4f6;border-radius:8px;transition:all 0.2s;">
                        <a href="<?php echo esc_url( $addon->get_permalink() ); ?>" style="text-decoration:none;">
                            <?php echo $addon->get_image( 'thumbnail', [ 'style' => 'width:100%;height:80px;object-fit:cover;border-radius:6px;' ] ); ?>
                        </a>
                        <div style="font-size:11px;margin:6px 0 2px;line-height:1.3;">
                            <a href="<?php echo esc_url( $addon->get_permalink() ); ?>" style="color:#374151;text-decoration:none;">
                                <?php echo esc_html( wp_trim_words( $addon->get_name(), 5 ) ); ?>
                            </a>
                        </div>
                        <div style="font-size:13px;font-weight:700;color:#16a34a;"><?php echo $addon->get_price_html(); ?></div>
                        <?php if ( $addon->is_type( 'simple' ) && $addon->is_in_stock() ) : ?>
                            <a href="?add-to-cart=<?php echo esc_attr( $addon->get_id() ); ?>" class="ltms-addon-add-btn"
                               style="display:inline-block;margin-top:4px;padding:4px 10px;background:#2563eb;color:#fff;font-size:11px;font-weight:600;border-radius:6px;text-decoration:none;">
                                + <?php esc_html_e( 'Añadir', 'ltms' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php
        wp_reset_postdata();
    }

    // ================================================================
    // 4. GIFT OPTIONS
    // ================================================================

    public static function render_gift_checkbox(): void {
        global $product;
        if ( ! $product ) return;
        ?>
        <div class="ltms-gift-options" style="margin:10px 0;padding:10px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                <input type="checkbox" name="ltms_is_gift" id="ltms_is_gift" value="yes" />
                <span>&#x1F381; <?php esc_html_e( 'Este producto es un regalo', 'ltms' ); ?></span>
            </label>
            <div id="ltms_gift_fields" style="display:none;margin-top:10px;">
                <textarea name="ltms_gift_message" id="ltms_gift_message" rows="2"
                          placeholder="<?php esc_attr_e( 'Mensaje para la persona que recibe el regalo (opcional)', 'ltms' ); ?>"
                          style="width:100%;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:6px;"></textarea>
                <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;color:#6b7280;">
                    <input type="checkbox" name="ltms_gift_wrap" id="ltms_gift_wrap" value="yes" />
                    <?php esc_html_e( 'Envoltorio de regalo (+$5.000)', 'ltms' ); ?>
                </label>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#ltms_is_gift').on('change', function() {
                $('#ltms_gift_fields').slideToggle(200);
            });
        });
        </script>
        <?php
    }

    public static function save_gift_data( array $cart_item_data, int $product_id, int $variation_id ): array {
        if ( isset( $_POST['ltms_is_gift'] ) && $_POST['ltms_is_gift'] === 'yes' ) {
            $cart_item_data['ltms_gift'] = true;
            $cart_item_data['ltms_gift_message'] = sanitize_textarea_field( $_POST['ltms_gift_message'] ?? '' );
            $cart_item_data['ltms_gift_wrap'] = isset( $_POST['ltms_gift_wrap'] ) && $_POST['ltms_gift_wrap'] === 'yes';
        }
        return $cart_item_data;
    }

    public static function display_gift_info_cart( array $item_data, array $cart_item ): array {
        if ( ! empty( $cart_item['ltms_gift'] ) ) {
            $item_data[] = [
                'key' => __( 'Regalo', 'ltms' ),
                'value' => '&#x1F381; ' . __( 'Sí', 'ltms' ),
            ];
            if ( ! empty( $cart_item['ltms_gift_message'] ) ) {
                $item_data[] = [
                    'key' => __( 'Mensaje', 'ltms' ),
                    'value' => wp_trim_words( $cart_item['ltms_gift_message'], 8 ),
                ];
            }
            if ( ! empty( $cart_item['ltms_gift_wrap'] ) ) {
                $item_data[] = [
                    'key' => __( 'Envoltorio', 'ltms' ),
                    'value' => __( 'Sí (+$5.000)', 'ltms' ),
                ];
            }
        }
        return $item_data;
    }

    // ================================================================
    // 5. BROWSING HISTORY
    // ================================================================

    public static function track_product_view(): void {
        global $product;
        if ( ! $product ) return;

        $product_id = $product->get_id();
        $user_id = get_current_user_id();
        $cookie_key = 'ltms_browsing_history';

        // Obtener historial actual.
        if ( $user_id > 0 ) {
            $history = get_user_meta( $user_id, '_ltms_browsing_history', true ) ?: [];
        } else {
            $cookie = $_COOKIE[ $cookie_key ] ?? '';
            $history = $cookie ? json_decode( stripslashes( $cookie ), true ) : [];
            if ( ! is_array( $history ) ) $history = [];
        }

        // Remover si ya existe (para moverlo al frente).
        $history = array_diff( $history, [ $product_id ] );

        // Añadir al frente.
        array_unshift( $history, $product_id );

        // Limitar a 20 productos.
        $history = array_slice( $history, 0, 20 );

        // Guardar.
        if ( $user_id > 0 ) {
            update_user_meta( $user_id, '_ltms_browsing_history', $history );
        } else {
            setcookie( $cookie_key, wp_json_encode( array_values( $history ) ), time() + ( 30 * DAY_IN_SECONDS ), '/' );
        }
    }

    public static function get_browsing_history(): array {
        $user_id = get_current_user_id();
        $cookie_key = 'ltms_browsing_history';

        if ( $user_id > 0 ) {
            $history = get_user_meta( $user_id, '_ltms_browsing_history', true ) ?: [];
        } else {
            $cookie = $_COOKIE[ $cookie_key ] ?? '';
            $history = $cookie ? json_decode( stripslashes( $cookie ), true ) : [];
            if ( ! is_array( $history ) ) $history = [];
        }

        return array_map( 'intval', $history );
    }

    public static function render_browsing_history(): void {
        $history = self::get_browsing_history();
        if ( empty( $history ) ) return;

        // Obtener productos válidos.
        $products = array_filter( array_map( 'wc_get_product', $history ) );
        if ( empty( $products ) ) return;

        // Limitar a 10.
        $products = array_slice( $products, 0, 10 );
        ?>
        <div class="ltms-browsing-history" style="margin:24px 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:16px;font-weight:700;">
                    &#x1F4C4; <?php esc_html_e( 'Productos vistos recientemente', 'ltms' ); ?>
                </h3>
                <button type="button" id="ltms-clear-history" style="font-size:11px;color:#6b7280;background:none;border:none;cursor:pointer;text-decoration:underline;">
                    <?php esc_html_e( 'Limpiar historial', 'ltms' ); ?>
                </button>
            </div>
            <div class="ltms-browsing-grid" style="display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;">
                <?php foreach ( $products as $p ) :
                    $vendor_id = (int) get_post_field( 'post_author', $p->get_id() );
                ?>
                    <div class="ltms-browsing-item" style="flex:0 0 140px;text-align:center;position:relative;">
                        <button type="button" class="ltms-browsing-remove" data-product-id="<?php echo esc_attr( $p->get_id() ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_remove_browsing' ) ); ?>"
                                style="position:absolute;top:0;right:0;background:rgba(255,255,255,0.9);border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:14px;color:#6b7280;z-index:2;">&times;</button>
                        <a href="<?php echo esc_url( $p->get_permalink() ); ?>" style="text-decoration:none;">
                            <?php echo $p->get_image( 'thumbnail', [ 'style' => 'width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;' ] ); ?>
                            <div style="font-size:11px;margin:6px 0 2px;line-height:1.3;color:#374151;">
                                <?php echo esc_html( wp_trim_words( $p->get_name(), 5 ) ); ?>
                            </div>
                            <div style="font-size:12px;font-weight:700;color:#16a34a;"><?php echo $p->get_price_html(); ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.ltms-browsing-remove').on('click', function(e) {
                e.preventDefault();
                var $item = $(this).closest('.ltms-browsing-item');
                $.post(ltmsDrawerData.ajaxUrl, {
                    action: 'ltms_remove_browsing_item',
                    nonce: $(this).data('nonce'),
                    product_id: $(this).data('product-id')
                }, function(response) {
                    if (response.success) {
                        $item.fadeOut(300, function() { $(this).remove(); });
                    }
                });
            });
            $('#ltms-clear-history').on('click', function() {
                $('.ltms-browsing-item').fadeOut(300, function() {
                    $('.ltms-browsing-history').slideUp();
                });
                $.post(ltmsDrawerData.ajaxUrl, {
                    action: 'ltms_remove_browsing_item',
                    nonce: '<?php echo esc_attr( wp_create_nonce( 'ltms_remove_browsing' ) ); ?>',
                    product_id: 0,
                    clear_all: true
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_remove_browsing_item(): void {
        check_ajax_referer( 'ltms_remove_browsing', 'nonce' );
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $clear_all = (bool) ( $_POST['clear_all'] ?? false );

        $user_id = get_current_user_id();

        if ( $clear_all ) {
            if ( $user_id > 0 ) {
                delete_user_meta( $user_id, '_ltms_browsing_history' );
            } else {
                setcookie( 'ltms_browsing_history', '', time() - 3600, '/' );
            }
            wp_send_json_success();
        }

        $history = self::get_browsing_history();
        $history = array_diff( $history, [ $product_id ] );

        if ( $user_id > 0 ) {
            update_user_meta( $user_id, '_ltms_browsing_history', array_values( $history ) );
        } else {
            setcookie( 'ltms_browsing_history', wp_json_encode( array_values( $history ) ), time() + ( 30 * DAY_IN_SECONDS ), '/' );
        }

        wp_send_json_success();
    }
}
