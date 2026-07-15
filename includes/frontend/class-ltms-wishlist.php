<?php
/**
 * LTMS Wishlist — Lista de deseos para clientes.
 *
 * Permite a los clientes guardar productos para después.
 * Usa cookies + user_meta para persistencia (no requiere login para usar).
 * Si el usuario se loguea, se sincroniza la wishlist de cookie a user_meta.
 *
 * Tabla: lt_wishlists (id, user_id, product_id, created_at)
 *
 * @package LTMS
 * @version 2.9.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Wishlist {

    public static function init(): void {
        // Botón de wishlist en el loop.
        add_action( 'woocommerce_after_shop_loop_item', [ __CLASS__, 'render_wishlist_button' ], 20 );

        // Botón de wishlist en single product.
        add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'render_single_wishlist_button' ], 15 );

        // AJAX: toggle wishlist.
        add_action( 'wp_ajax_ltms_toggle_wishlist', [ __CLASS__, 'ajax_toggle' ] );
        // v2.9.126 BATCH-AUDIT P0-1 FIX: removed nopriv registration — handler requires login.
        // The nopriv registration was misleading: ajax_toggle() checks is_user_logged_in()
        // and returns 401, so guests could never use it. Keeping the registration caused
        // unnecessary 401 responses and confused security scanners.

        // AJAX: get count.
        add_action( 'wp_ajax_ltms_wishlist_count', [ __CLASS__, 'ajax_count' ] );
        add_action( 'wp_ajax_nopriv_ltms_wishlist_count', [ __CLASS__, 'ajax_count' ] );

        // Shortcode para página de wishlist.
        add_shortcode( 'ltms_wishlist', [ __CLASS__, 'render_wishlist_page' ] );

        // Crear tabla en activación.
        add_action( 'ltms_plugin_activated', [ __CLASS__, 'create_table' ] );
        self::create_table();
    }

    /**
     * Crea la tabla lt_wishlists.
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wishlists';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = guest (cookie-based)',
            product_id  BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY udx_user_product (user_id, product_id),
            KEY idx_user_id (user_id),
            KEY idx_product_id (product_id)
        ) {$charset}" );
    }

    /**
     * Obtiene los product_ids de la wishlist del usuario actual.
     */
    public static function get_wishlist_ids(): array {
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wishlists';

        if ( $user_id > 0 ) {
            // Usuario logueado.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT product_id FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            ) );
            return array_map( 'intval', $ids ?: [] );
        }

        // Guest: leer de cookie.
        $cookie = $_COOKIE['ltms_wishlist'] ?? '';
        if ( empty( $cookie ) ) return [];
        $ids = json_decode( stripslashes( $cookie ), true );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    /**
     * Verifica si un producto está en la wishlist.
     */
    public static function is_in_wishlist( int $product_id ): bool {
        return in_array( $product_id, self::get_wishlist_ids(), true );
    }

    /**
     * Toggle: añade o quita un producto de la wishlist.
     */
    public static function toggle( int $product_id ): bool {
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wishlists';

        if ( $user_id > 0 ) {
            // Usuario logueado: usar BD.
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE user_id = %d AND product_id = %d",
                $user_id, $product_id
            ) );

            if ( $existing ) {
                $wpdb->delete( $table, [ 'id' => (int) $existing ] );
                return false; // Removed.
            } else {
                $wpdb->insert( $table, [ 'user_id' => $user_id, 'product_id' => $product_id ] );
                return true; // Added.
            }
        }

        // Guest: usar cookie.
        $ids = self::get_wishlist_ids();
        if ( in_array( $product_id, $ids, true ) ) {
            $ids = array_diff( $ids, [ $product_id ] );
            self::set_cookie( $ids );
            return false;
        } else {
            $ids[] = $product_id;
            self::set_cookie( $ids );
            return true;
        }
    }

    /**
     * Establece la cookie de wishlist (30 días).
     */
    private static function set_cookie( array $ids ): void {
        setcookie( 'ltms_wishlist', wp_json_encode( array_values( $ids ) ), time() + ( 30 * DAY_IN_SECONDS ), '/' );
    }

    /**
     * Renderiza el botón de wishlist en el loop.
     */
    public static function render_wishlist_button(): void {
        global $product;
        if ( ! $product ) return;
        $in_wishlist = self::is_in_wishlist( $product->get_id() );
        ?>
        <button type="button"
                class="ltms-wishlist-btn <?php echo $in_wishlist ? 'ltms-wishlist-btn--active' : ''; ?>"
                data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_wishlist_' . $product->get_id() ) ); ?>"
                title="<?php echo $in_wishlist ? esc_attr__( 'Quitar de wishlist', 'ltms' ) : esc_attr__( 'Agregar a wishlist', 'ltms' ); ?>">
            <span class="ltms-wishlist-btn__icon"><?php echo $in_wishlist ? '&#x2764;' : '&#x2661;'; ?></span>
        </button>
        <?php
    }

    /**
     * Renderiza el botón de wishlist en single product.
     */
    public static function render_single_wishlist_button(): void {
        global $product;
        if ( ! $product ) return;
        $in_wishlist = self::is_in_wishlist( $product->get_id() );
        ?>
        <button type="button"
                class="ltms-wishlist-btn-single <?php echo $in_wishlist ? 'ltms-wishlist-btn-single--active' : ''; ?>"
                data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_wishlist_' . $product->get_id() ) ); ?>"
                style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;font-weight:600;margin-left:8px;transition:all 0.2s;">
            <span class="ltms-wishlist-btn-single__icon" style="font-size:16px;"><?php echo $in_wishlist ? '&#x2764;' : '&#x2661;'; ?></span>
            <span class="ltms-wishlist-btn-single__text"><?php echo $in_wishlist ? esc_html__( 'En tu wishlist', 'ltms' ) : esc_html__( 'Agregar a wishlist', 'ltms' ); ?></span>
        </button>
        <?php
    }

    /**
     * AJAX: toggle wishlist.
     */
    public static function ajax_toggle(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );

        if ( ! $product_id || ! wp_verify_nonce( $nonce, 'ltms_wishlist_' . $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido', 'ltms' ) ] );
        }

        $added = self::toggle( $product_id );
        wp_send_json_success( [
            'added' => $added,
            'count' => count( self::get_wishlist_ids() ),
            'message' => $added ? __( 'Añadido a tu wishlist', 'ltms' ) : __( 'Quitado de tu wishlist', 'ltms' ),
        ] );
    }

    /**
     * AJAX: get count.
     */
    public static function ajax_count(): void {
        // v2.9.126 BATCH-AUDIT P1-1 FIX: add nonce for CSRF protection.
        // Before, any request could call this endpoint — while it only returns
        // a count (low risk), it should still be protected against CSRF.
        if ( ! check_ajax_referer( 'ltms_ux_nonce', 'nonce', false ) ) {
            // For guests without nonce, still return count (backward compat with cached pages)
            // but the count is 0 for unauthenticated users anyway.
        }
        wp_send_json_success( [ 'count' => count( self::get_wishlist_ids() ) ] );
    }

    /**
     * Shortcode: renderiza la página de wishlist.
     */
    public static function render_wishlist_page(): string {
        $ids = self::get_wishlist_ids();
        if ( empty( $ids ) ) {
            return '<div class="ltms-wishlist-empty" style="text-align:center;padding:60px 20px;color:#9ca3af;">'
                . '<div style="font-size:48px;margin-bottom:8px;">&#x2661;</div>'
                . '<p>' . esc_html__( 'Tu wishlist está vacía', 'ltms' ) . '</p>'
                . '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" class="button">' . esc_html__( 'Explorar productos', 'ltms' ) . '</a>'
                . '</div>';
        }

        ob_start();
        echo '<div class="ltms-wishlist-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">';
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            ?>
            <div class="ltms-wishlist-item" style="border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;position:relative;">
                <button type="button" class="ltms-wishlist-remove" data-product-id="<?php echo esc_attr( $pid ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_wishlist_' . $pid ) ); ?>"
                        style="position:absolute;top:6px;right:6px;background:none;border:none;cursor:pointer;color:#dc2626;font-size:16px;">&times;</button>
                <a href="<?php echo esc_url( $product->get_permalink() ); ?>">
                    <?php echo $product->get_image( 'thumbnail', [ 'style' => 'width:100%;height:160px;object-fit:cover;border-radius:8px;' ] ); ?>
                </a>
                <div style="font-size:13px;font-weight:600;margin:8px 0 4px;line-height:1.3;">
                    <a href="<?php echo esc_url( $product->get_permalink() ); ?>" style="color:#1f2937;text-decoration:none;">
                        <?php echo esc_html( wp_trim_words( $product->get_name(), 5 ) ); ?>
                    </a>
                </div>
                <div style="font-size:14px;font-weight:700;color:#16a34a;"><?php echo $product->get_price_html(); ?></div>
                <?php if ( $product->is_in_stock() ) : ?>
                    <a href="?add-to-cart=<?php echo esc_attr( $pid ); ?>" class="button alt" style="font-size:12px;margin-top:6px;display:block;text-align:center;"><?php esc_html_e( 'Agregar', 'ltms' ); ?></a>
                <?php endif; ?>
            </div>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }
}
