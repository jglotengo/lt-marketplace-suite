<?php
/**
 * LTMS Trust Badges — Badges de confianza en página de producto.
 *
 * Muestra:
 *  - Compra Protegida (Ley 1480 / PROFECO) — link a política de protección.
 *  - Vendedor Verificado (KYC aprobado) — badge con check verde.
 *  - X ventas realizadas por este vendor — social proof.
 *  - Envío gratis / Devoluciones gratis — según configuración del vendor.
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Trust_Badges {

    public static function init(): void {
        // Badges debajo del precio (single product).
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_trust_badges' ], 15 );

        // Badges debajo del botón add-to-cart.
        add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'render_mini_badges' ], 5 );

        // Badge de vendor en el loop de productos (shop page).
        add_action( 'woocommerce_after_shop_loop_item_title', [ __CLASS__, 'render_loop_vendor_badge' ], 5 );
    }

    /**
     * Renderiza los badges principales de confianza en la página de producto.
     */
    public static function render_trust_badges(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $badges = self::get_badges_for_product( $product, $vendor_id );

        if ( empty( $badges ) ) return;
        ?>
        <div class="ltms-trust-badges" style="margin:16px 0;display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ( $badges as $badge ) : ?>
                <div class="ltms-trust-badge ltms-trust-badge--<?php echo esc_attr( $badge['type'] ); ?>"
                     title="<?php echo esc_attr( $badge['tooltip'] ); ?>">
                    <span class="ltms-trust-badge__icon"><?php echo $badge['icon']; // phpcs:ignore ?></span>
                    <span class="ltms-trust-badge__text"><?php echo esc_html( $badge['text'] ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Mini badges debajo del botón add-to-cart (sin texto, solo iconos).
     */
    public static function render_mini_badges(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $is_protected = LTMS_Core_Config::get( 'ltms_consumer_protection_enabled', 'yes' ) === 'yes';
        $kyc_approved = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) === 'approved';
        $sales_count = self::get_vendor_sales_count( $vendor_id );
        ?>
        <div class="ltms-mini-badges" style="margin-top:12px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#6b7280;">
            <?php if ( $is_protected ) : ?>
                <span class="ltms-mini-badge" title="<?php esc_attr_e( 'Compra protegida por Lo Tengo — Ley 1480 / PROFECO', 'ltms' ); ?>">
                    <span style="color:#16a34a;">&#x1F6E1;</span> <?php esc_html_e( 'Compra protegida', 'ltms' ); ?>
                </span>
            <?php endif; ?>
            <?php if ( $kyc_approved ) : ?>
                <span class="ltms-mini-badge" title="<?php esc_attr_e( 'Vendedor verificado (KYC aprobado)', 'ltms' ); ?>">
                    <span style="color:#2563eb;">&#x2705;</span> <?php esc_html_e( 'Vendedor verificado', 'ltms' ); ?>
                </span>
            <?php endif; ?>
            <?php if ( $sales_count > 0 ) : ?>
                <span class="ltms-mini-badge" title="<?php esc_attr_e( 'Ventas realizadas por este vendedor', 'ltms' ); ?>">
                    <span>&#x1F4C8;</span> <?php echo esc_html( sprintf( _n( '%d venta', '%d ventas', $sales_count, 'ltms' ), $sales_count ) ); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Badge compacto de vendor verificado en el loop de tienda.
     */
    public static function render_loop_vendor_badge(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $kyc_approved = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) === 'approved';
        if ( ! $kyc_approved ) return;
        ?>
        <div class="ltms-loop-vendor-badge" style="font-size:11px;color:#2563eb;margin:2px 0;">
            &#x2705; <?php esc_html_e( 'Verificado', 'ltms' ); ?>
        </div>
        <?php
    }

    /**
     * Construye el array de badges para un producto.
     */
    private static function get_badges_for_product( $product, int $vendor_id ): array {
        $badges = [];

        // 1. Compra Protegida.
        if ( LTMS_Core_Config::get( 'ltms_consumer_protection_enabled', 'yes' ) === 'yes' ) {
            $country = LTMS_Core_Config::get_country();
            $protection_text = $country === 'MX'
                ? __( 'Compra Protegida (PROFECO)', 'ltms' )
                : __( 'Compra Protegida (Ley 1480)', 'ltms' );
            $badges[] = [
                'type' => 'protected',
                'icon' => '&#x1F6E1;',
                'text' => $protection_text,
                'tooltip' => __( 'Tu compra está protegida. Tienes 5 días hábiles para reclamar tras la entrega.', 'ltms' ),
            ];
        }

        // 2. Vendedor Verificado.
        $kyc_status = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
        if ( $kyc_status === 'approved' ) {
            $vendor_user = get_userdata( $vendor_id );
            $vendor_name = $vendor_user ? $vendor_user->display_name : '';
            $badges[] = [
                'type' => 'verified',
                'icon' => '&#x2705;',
                'text' => __( 'Vendedor Verificado', 'ltms' ),
                'tooltip' => sprintf( __( '%s ha completado la verificación KYC (identidad y cuenta bancaria certificada).', 'ltms' ), $vendor_name ),
            ];
        }

        // 3. Ventas realizadas.
        $sales = self::get_vendor_sales_count( $vendor_id );
        if ( $sales > 0 ) {
            $badges[] = [
                'type' => 'sales',
                'icon' => '&#x1F4C8;',
                'text' => sprintf( _n( '%d venta realizada', '%d ventas realizadas', $sales, 'ltms' ), $sales ),
                'tooltip' => __( 'Número de pedidos completados por este vendedor.', 'ltms' ),
            ];
        }

        // 4. Envío gratis si aplica.
        $shipping_mode = class_exists( 'LTMS_Shipping_Mode' )
            ? LTMS_Shipping_Mode::get_vendor_mode( $vendor_id )
            : '';
        if ( in_array( $shipping_mode, [ 'free', 'free_absorbed', 'hybrid' ], true ) ) {
            $badges[] = [
                'type' => 'shipping',
                'icon' => '&#x1F69A;',
                'text' => __( 'Envío disponible', 'ltms' ),
                'tooltip' => __( 'Este vendedor ofrece envío. Consulta opciones y costos al finalizar la compra.', 'ltms' ),
            ];
        }

        // 5. Devoluciones.
        $returns_url = LTMS_Core_Config::get( 'ltms_devoluciones_url', '' );
        if ( $returns_url ) {
            $badges[] = [
                'type' => 'returns',
                'icon' => '&#x21A9;',
                'text' => __( 'Devoluciones aceptadas', 'ltms' ),
                'tooltip' => __( 'Puedes solicitar devolución dentro del período de protección.', 'ltms' ),
            ];
        }

        return $badges;
    }

    /**
     * Cuenta las ventas completadas de un vendor (WC orders con status completed).
     */
    public static function get_vendor_sales_count( int $vendor_id ): int {
        if ( $vendor_id <= 0 ) return 0;

        // Usar cache transient (1h) para evitar queries pesados en cada page load.
        $cache_key = 'ltms_vendor_sales_' . $vendor_id;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return (int) $cached;

        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ltms_vendor_id' AND pm.meta_value = %d
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')",
            $vendor_id
        ) );

        set_transient( $cache_key, $count, HOUR_IN_SECONDS );
        return $count;
    }
}
