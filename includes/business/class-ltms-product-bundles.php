<?php
/**
 * LTMS Product Bundles — Bundles de productos del mismo vendor.
 *
 * Permite a un vendor crear bundles: "Compra producto A + B + C y ahorra X%".
 * El bundle se muestra en la página del producto padre. Cuando todos los
 * productos del bundle están en el carrito, se aplica el descuento.
 *
 * Tabla: lt_product_bundles (id, vendor_id, parent_product_id, child_products JSON,
 *         discount_pct, title, status, created_at)
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Product_Bundles {

    public static function init(): void {
        // Frontend: render bundle offer en product page.
        add_action( 'woocommerce_after_single_product_summary', [ __CLASS__, 'render_bundle_offer' ], 5 );

        // Cart: aplicar descuento cuando todos los productos del bundle están.
        add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'apply_bundle_discount' ] );

        // Admin: meta box para configurar bundle.
        add_action( 'woocommerce_product_options_related', [ __CLASS__, 'render_bundle_meta_box' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_bundle_meta' ] );

        // AJAX: add all bundle products to cart.
        add_action( 'wp_ajax_ltms_add_bundle_to_cart', [ __CLASS__, 'ajax_add_bundle_to_cart' ] );
        add_action( 'wp_ajax_nopriv_ltms_add_bundle_to_cart', [ __CLASS__, 'ajax_add_bundle_to_cart' ] );
    }

    /**
     * Obtiene los bundles activos para un producto padre.
     */
    public static function get_bundles_for_product( int $product_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_product_bundles';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE parent_product_id = %d AND status = 'active'",
            $product_id
        ), ARRAY_A );
        return $results ?: [];
    }

    /**
     * Renderiza la oferta de bundle en la página de producto.
     */
    public static function render_bundle_offer(): void {
        global $product;
        if ( ! $product ) return;

        $bundles = self::get_bundles_for_product( $product->get_id() );
        if ( empty( $bundles ) ) return;

        foreach ( $bundles as $bundle ) {
            $child_ids = json_decode( $bundle['child_products'], true );
            if ( ! is_array( $child_ids ) || empty( $child_ids ) ) continue;

            $children = array_filter( array_map( 'wc_get_product', $child_ids ) );
            if ( empty( $children ) ) continue;

            $all_products = array_merge( [ $product ], $children );
            $total_regular = 0;
            foreach ( $all_products as $p ) {
                $total_regular += (float) $p->get_price();
            }
            $discount_pct = (float) $bundle['discount_pct'];
            $discount_amount = round( $total_regular * ( $discount_pct / 100 ), 2 );
            $bundle_price = $total_regular - $discount_amount;

            wp_nonce_field( 'ltms_bundle_' . $bundle['id'], 'ltms_bundle_nonce_' . $bundle['id'] );
            ?>
            <div class="ltms-bundle-offer" style="margin:24px 0;padding:20px;border:2px solid #16a34a;border-radius:12px;background:#f0fdf4;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                    <span style="font-size:24px;">&#x1F381;</span>
                    <h3 style="margin:0;font-size:18px;color:#16a34a;">
                        <?php echo esc_html( $bundle['title'] ?: __( 'Bundle especial — Ahorra comprando junto', 'ltms' ) ); ?>
                    </h3>
                </div>

                <div class="ltms-bundle-products" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px;">
                    <?php foreach ( $all_products as $idx => $p ) : ?>
                        <div class="ltms-bundle-product" style="text-align:center;flex:1;min-width:100px;max-width:140px;">
                            <a href="<?php echo esc_url( $p->get_permalink() ); ?>">
                                <?php echo $p->get_image( 'thumbnail' ); // phpcs:ignore ?>
                            </a>
                            <div style="font-size:11px;margin-top:4px;line-height:1.3;">
                                <a href="<?php echo esc_url( $p->get_permalink() ); ?>" style="color:#374151;text-decoration:none;">
                                    <?php echo esc_html( wp_trim_words( $p->get_name(), 4 ) ); ?>
                                </a>
                            </div>
                            <div style="font-size:12px;font-weight:600;color:#16a34a;margin-top:2px;">
                                <?php echo wc_price( $p->get_price() ); // phpcs:ignore ?>
                            </div>
                        </div>
                        <?php if ( $idx < count( $all_products ) - 1 ) : ?>
                            <span style="font-size:20px;color:#9ca3af;">+</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="ltms-bundle-summary" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding-top:16px;border-top:1px solid #d1fae5;">
                    <div>
                        <div style="font-size:13px;color:#6b7280;text-decoration:line-through;">
                            <?php echo wc_price( $total_regular ); // phpcs:ignore ?>
                        </div>
                        <div style="font-size:20px;font-weight:700;color:#16a34a;">
                            <?php echo wc_price( $bundle_price ); // phpcs:ignore ?>
                        </div>
                        <div style="font-size:12px;color:#16a34a;font-weight:600;">
                            <?php echo esc_html( sprintf( __( 'Ahorras %s (%.0f%%)', 'ltms' ), wc_price( $discount_amount ), $discount_pct ) ); // phpcs:ignore ?>
                        </div>
                    </div>
                    <button type="button"
                            class="button alt ltms-add-bundle-btn"
                            data-bundle-id="<?php echo esc_attr( $bundle['id'] ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'ltms_bundle_' . $bundle['id'] ) ); ?>"
                            style="background:#16a34a;color:#fff;border:none;padding:10px 24px;font-size:14px;border-radius:8px;cursor:pointer;">
                        <?php esc_html_e( 'Agregar bundle al carrito', 'ltms' ); ?>
                    </button>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Aplica el descuento del bundle en el carrito si todos los productos están.
     */
    public static function apply_bundle_discount( \WC_Cart $cart ): void {
        // v2.9.49 PERF: Antes esto era O(N²) con consultas SQL por cada item.
        // Ahora: una sola pasada para obtener IDs del carrito, una sola query
        // para buscar bundles, y cache de precios de productos.

        $applied_bundles = [];
        $cart_product_ids = [];
        $cart_items = $cart->get_cart();

        // 1. Una sola pasada: recolectar IDs únicos de productos en el carrito.
        foreach ( $cart_items as $cart_item ) {
            $pid = (int) ( $cart_item['product_id'] ?? 0 );
            if ( $pid && ! in_array( $pid, $cart_product_ids, true ) ) {
                $cart_product_ids[] = $pid;
            }
        }

        if ( empty( $cart_product_ids ) ) return;

        // 2. Una sola query para obtener TODOS los bundles relevantes.
        global $wpdb;
        $table = $wpdb->prefix . 'lt_product_bundles';
        $placeholders = implode( ',', array_fill( 0, count( $cart_product_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $bundles = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE parent_product_id IN ($placeholders) AND status = 'active'",
            $cart_product_ids
        ), ARRAY_A );

        if ( empty( $bundles ) ) return;

        // 3. Cache de precios: solo cargar cada producto una vez.
        $price_cache = [];

        foreach ( $bundles as $bundle ) {
            if ( isset( $applied_bundles[ $bundle['id'] ] ) ) continue;

            $child_ids = json_decode( $bundle['child_products'], true );
            if ( ! is_array( $child_ids ) ) continue;

            $required_ids = array_merge( [ (int) $bundle['parent_product_id'] ], array_map( 'intval', $child_ids ) );

            // Verificar que TODOS los productos del bundle están en el carrito.
            $all_present = true;
            foreach ( $required_ids as $rid ) {
                if ( ! in_array( $rid, $cart_product_ids, true ) ) {
                    $all_present = false;
                    break;
                }
            }

            if ( ! $all_present ) continue;

            // Sumar precios (con cache).
            $total = 0;
            foreach ( $required_ids as $rid ) {
                if ( ! isset( $price_cache[ $rid ] ) ) {
                    $p = wc_get_product( $rid );
                    $price_cache[ $rid ] = $p ? (float) $p->get_price() : 0;
                }
                $total += $price_cache[ $rid ];
            }

            $discount = round( $total * ( (float) $bundle['discount_pct'] / 100 ), 2 );
            if ( $discount > 0 ) {
                $cart->add_fee( sprintf( __( 'Descuento bundle: %s', 'ltms' ), $bundle['title'] ), -$discount, true );
                $applied_bundles[ $bundle['id'] ] = true;
            }
        }
    }

    /**
     * AJAX: añade todos los productos del bundle al carrito.
     */
    public static function ajax_add_bundle_to_cart(): void {
        $bundle_id = (int) ( $_POST['bundle_id'] ?? 0 );
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );

        if ( ! $bundle_id || ! wp_verify_nonce( $nonce, 'ltms_bundle_' . $bundle_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido', 'ltms' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_product_bundles';
        $bundle = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND status = 'active'",
            $bundle_id
        ), ARRAY_A );

        if ( ! $bundle ) {
            wp_send_json_error( [ 'message' => __( 'Bundle no encontrado', 'ltms' ) ] );
        }

        $child_ids = json_decode( $bundle['child_products'], true );
        $all_ids = array_merge( [ (int) $bundle['parent_product_id'] ], array_map( 'intval', $child_ids ) );

        $added = 0;
        foreach ( $all_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            if ( $product->is_type( 'variable' ) ) continue; // Skip variable products in bundle.
            $result = WC()->cart->add_to_cart( $pid, 1 );
            if ( $result ) $added++;
        }

        if ( $added > 0 ) {
            wp_send_json_success( [
                'message' => sprintf( __( '%d productos agregados al carrito', 'ltms' ), $added ),
                'added' => $added,
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No se pudieron agregar los productos', 'ltms' ) ] );
        }
    }

    /**
     * Admin: meta box para configurar bundles.
     */
    public static function render_bundle_meta_box(): void {
        global $post;
        $bundles = self::get_bundles_for_product( $post->ID );
        ?>
        <div class="options_group">
            <p class="form-field" style="border-bottom:1px solid #e5e7eb;padding-bottom:12px;">
                <strong><?php esc_html_e( 'Bundles de productos (Lo Tengo)', 'ltms' ); ?></strong>
                <?php echo wc_help_tip( __( 'Crea bundles: el cliente compra este producto + otros del mismo vendor y obtiene un descuento.', 'ltms' ) ); ?>
            </p>

            <div id="ltms-bundles-list">
                <?php foreach ( $bundles as $bundle ) : $child_ids = json_decode( $bundle['child_products'], true ); ?>
                    <div class="ltms-bundle-row" style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;">
                        <input type="hidden" name="ltms_bundle_ids[]" value="<?php echo esc_attr( $bundle['id'] ); ?>" />
                        <p>
                            <label><?php esc_html_e( 'Título del bundle', 'ltms' ); ?></label>
                            <input type="text" name="ltms_bundle_titles[]" value="<?php echo esc_attr( $bundle['title'] ); ?>" style="width:100%;" />
                        </p>
                        <p>
                            <label><?php esc_html_e( 'IDs de productos (separados por coma)', 'ltms' ); ?></label>
                            <input type="text" name="ltms_bundle_children[]" value="<?php echo esc_attr( implode( ',', $child_ids ) ); ?>" style="width:100%;" placeholder="ej: 123, 456, 789" />
                        </p>
                        <p>
                            <label><?php esc_html_e( 'Descuento (%)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_bundle_discounts[]" value="<?php echo esc_attr( $bundle['discount_pct'] ); ?>" min="1" max="100" step="0.5" style="width:80px;" />
                            <button type="button" class="button ltms-remove-bundle" style="color:#a00;margin-left:12px;"><?php esc_html_e( 'Eliminar', 'ltms' ); ?></button>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="ltms-add-bundle"><?php esc_html_e( '+ Añadir bundle', 'ltms' ); ?></button>
            <?php wp_nonce_field( 'ltms_save_bundles', 'ltms_bundles_nonce' ); ?>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var rowHtml = '<div class="ltms-bundle-row" style="padding:12px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;">' +
                '<input type="hidden" name="ltms_bundle_ids[]" value="0" />' +
                '<p><label><?php esc_html_e( "Título del bundle", "ltms" ); ?></label><input type="text" name="ltms_bundle_titles[]" value="" style="width:100%;" /></p>' +
                '<p><label><?php esc_html_e( "IDs de productos (separados por coma)", "ltms" ); ?></label><input type="text" name="ltms_bundle_children[]" value="" style="width:100%;" placeholder="ej: 123, 456, 789" /></p>' +
                '<p><label><?php esc_html_e( "Descuento (%)", "ltms" ); ?></label><input type="number" name="ltms_bundle_discounts[]" value="10" min="1" max="100" step="0.5" style="width:80px;" />' +
                '<button type="button" class="button ltms-remove-bundle" style="color:#a00;margin-left:12px;"><?php esc_html_e( "Eliminar", "ltms" ); ?></button></p>' +
                '</div>';
            $('#ltms-add-bundle').on('click', function() { $('#ltms-bundles-list').append(rowHtml); });
            $(document).on('click', '.ltms-remove-bundle', function() { $(this).closest('.ltms-bundle-row').remove(); });
        });
        </script>
        <?php
    }

    /**
     * Guarda los bundles desde el admin.
     */
    public static function save_bundle_meta( int $post_id ): void {
        if ( ! isset( $_POST['ltms_bundles_nonce'] ) || ! wp_verify_nonce( $_POST['ltms_bundles_nonce'], 'ltms_save_bundles' ) ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_product_bundles';
        $vendor_id = (int) get_post_field( 'post_author', $post_id );

        $ids = $_POST['ltms_bundle_ids'] ?? [];
        $titles = $_POST['ltms_bundle_titles'] ?? [];
        $children = $_POST['ltms_bundle_children'] ?? [];
        $discounts = $_POST['ltms_bundle_discounts'] ?? [];

        $seen_ids = [];
        for ( $i = 0; $i < count( $titles ); $i++ ) {
            $bid = (int) ( $ids[ $i ] ?? 0 );
            $title = sanitize_text_field( $titles[ $i ] ?? '' );
            $child_str = sanitize_text_field( $children[ $i ] ?? '' );
            $discount = (float) ( $discounts[ $i ] ?? 0 );
            $child_arr = array_filter( array_map( 'intval', explode( ',', $child_str ) ) );

            if ( empty( $child_arr ) || $discount <= 0 || $discount > 100 ) continue;
            $child_json = wp_json_encode( $child_arr );
            $seen_ids[] = $bid;

            if ( $bid > 0 ) {
                $wpdb->update( $table, [
                    'title' => $title,
                    'child_products' => $child_json,
                    'discount_pct' => $discount,
                ], [ 'id' => $bid ] );
            } else {
                $wpdb->insert( $table, [
                    'vendor_id' => $vendor_id,
                    'parent_product_id' => $post_id,
                    'child_products' => $child_json,
                    'discount_pct' => $discount,
                    'title' => $title,
                    'status' => 'active',
                    'created_at' => current_time( 'mysql', true ),
                ] );
            }
        }

        // Eliminar bundles que ya no están.
        if ( ! empty( $seen_ids ) ) {
            $seen_ids_str = implode( ',', array_map( 'intval', $seen_ids ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE parent_product_id = %d AND id NOT IN ($seen_ids_str)",
                $post_id
            ) );
        } else {
            $wpdb->delete( $table, [ 'parent_product_id' => $post_id ] );
        }
    }

    /**
     * Crea la tabla lt_product_bundles (called from migration).
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_product_bundles';
        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id           BIGINT UNSIGNED NOT NULL,
            parent_product_id   BIGINT UNSIGNED NOT NULL,
            child_products      LONGTEXT NOT NULL COMMENT 'JSON array of product IDs',
            discount_pct        DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            title               VARCHAR(200) DEFAULT '',
            status              VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY idx_parent (parent_product_id),
            KEY idx_vendor (vendor_id),
            KEY idx_status (status)
        ) {$charset}" );
    }
}
