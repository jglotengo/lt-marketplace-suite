<?php
/**
 * LTMS Product Tabs — Tabs adicionales en product page.
 *
 * Añade:
 *  - Tab "Sobre el vendedor" (About Brand) con info del vendor.
 *  - Tab "Envío y Entrega" con política de envío específica.
 *  - Size Guide modal (botón + modal con tabla de tallas/dimensiones).
 *
 * @package LTMS
 * @version 2.9.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Product_Tabs {

    public static function init(): void {
        // Tabs adicionales en product page.
        add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'add_vendor_tab' ], 15 );
        add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'add_shipping_tab' ], 16 );

        // Size guide button + modal.
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_size_guide_button' ], 25 );

        // Admin: meta box para size guide.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_size_guide_meta' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_size_guide_meta' ] );
    }

    // ================================================================
    // TAB: Sobre el vendedor
    // ================================================================

    public static function add_vendor_tab( array $tabs ): array {
        global $product;
        if ( ! $product ) return $tabs;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        if ( ! $vendor_id ) return $tabs;

        $tabs['ltms_vendor'] = [
            'title' => __( 'Sobre el vendedor', 'ltms' ),
            'priority' => 15,
            'callback' => [ __CLASS__, 'render_vendor_tab' ],
        ];
        return $tabs;
    }

    public static function render_vendor_tab(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor ) return;

        $kyc_approved = get_user_meta( $vendor_id, 'ltms_kyc_status', true ) === 'approved';
        $sales_count = class_exists( 'LTMS_Trust_Badges' )
            ? LTMS_Trust_Badges::get_vendor_sales_count( $vendor_id )
            : 0;
        $vendor_since = date_i18n( get_option( 'date_format' ), strtotime( $vendor->user_registered ) );
        $store_url = class_exists( 'LTMS_Vendor_Storefront' )
            ? home_url( '/vendedor/' . $vendor->user_nicename . '/' )
            : '';
        $rating = 0; // TODO: calcular rating promedio del vendor.
        $description = get_user_meta( $vendor_id, 'description', true ) ?: '';
        $store_logo = get_user_meta( $vendor_id, 'ltms_store_logo', true ) ?: '';
        ?>
        <div class="ltms-vendor-tab" style="display:flex;gap:20px;flex-wrap:wrap;">
            <!-- Columna izquierda: info del vendor -->
            <div style="flex:1;min-width:200px;">
                <?php if ( $store_logo ) : ?>
                    <img src="<?php echo esc_url( $store_logo ); ?>" alt="<?php echo esc_attr( $vendor->display_name ); ?>"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:12px;border:2px solid #e5e7eb;">
                <?php else : ?>
                    <div style="width:80px;height:80px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:32px;margin-bottom:12px;">
                        &#x1F3EA;
                    </div>
                <?php endif; ?>

                <h3 style="margin:0 0 4px;font-size:18px;">
                    <?php echo esc_html( $vendor->display_name ); ?>
                    <?php if ( $kyc_approved ) : ?>
                        <span style="color:#16a34a;font-size:14px;" title="<?php esc_attr_e( 'Vendedor verificado', 'ltms' ); ?>">&#x2705;</span>
                    <?php endif; ?>
                </h3>

                <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">
                    <?php echo esc_html( sprintf( __( 'Vendedor desde %s', 'ltms' ), $vendor_since ) ); ?>
                </div>

                <div style="display:flex;gap:12px;margin-bottom:12px;font-size:13px;">
                    <div>
                        <strong><?php echo esc_html( number_format( $sales_count ) ); ?></strong>
                        <span style="color:#6b7280;"><?php esc_html_e( 'ventas', 'ltms' ); ?></span>
                    </div>
                    <div>
                        <strong>&#x2B50; <?php echo esc_html( number_format( $rating, 1 ) ); ?></strong>
                        <span style="color:#6b7280;"><?php esc_html_e( 'rating', 'ltms' ); ?></span>
                    </div>
                </div>

                <?php if ( $store_url ) : ?>
                    <a href="<?php echo esc_url( $store_url ); ?>" class="button" style="font-size:12px;">
                        <?php esc_html_e( 'Ver tienda del vendedor →', 'ltms' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Columna derecha: descripción -->
            <div style="flex:2;min-width:250px;">
                <?php if ( $description ) : ?>
                    <p style="font-size:13px;color:#4b5563;line-height:1.6;"><?php echo esc_html( $description ); ?></p>
                <?php else : ?>
                    <p style="font-size:13px;color:#9ca3af;font-style:italic;">
                        <?php esc_html_e( 'El vendedor aún no ha agregado una descripción.', 'ltms' ); ?>
                    </p>
                <?php endif; ?>

                <!-- Trust badges -->
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    <?php if ( $kyc_approved ) : ?>
                        <span class="ltms-trust-badge ltms-trust-badge--verified" style="padding:4px 10px;border-radius:20px;font-size:11px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;">
                            &#x2705; <?php esc_html_e( 'Identidad verificada', 'ltms' ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="ltms-trust-badge ltms-trust-badge--protected" style="padding:4px 10px;border-radius:20px;font-size:11px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">
                        &#x1F6E1; <?php esc_html_e( 'Compra protegida', 'ltms' ); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    // ================================================================
    // TAB: Envío y Entrega
    // ================================================================

    public static function add_shipping_tab( array $tabs ): array {
        $tabs['ltms_shipping'] = [
            'title' => __( 'Envío y Entrega', 'ltms' ),
            'priority' => 20,
            'callback' => [ __CLASS__, 'render_shipping_tab' ],
        ];
        return $tabs;
    }

    public static function render_shipping_tab(): void {
        global $product;
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
        $shipping_mode = class_exists( 'LTMS_Shipping_Mode' )
            ? LTMS_Shipping_Mode::get_vendor_mode( $vendor_id )
            : 'quoted';
        $country = LTMS_Core_Config::get_country();

        // Determinar texto según modo de envío.
        $shipping_info = '';
        $estimated_delivery = '';
        $shipping_cost_text = '';

        switch ( $shipping_mode ) {
            case 'free':
            case 'free_absorbed':
                $shipping_cost_text = __( 'Envío gratis incluido en el precio.', 'ltms' );
                $estimated_delivery = $country === 'MX' ? '3-7 días hábiles' : '2-5 días hábiles';
                break;
            case 'hybrid':
                $threshold = (float) LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 );
                $shipping_cost_text = sprintf(
                    __( 'Envío gratis en pedidos superiores a %s. Debajo de ese monto, se cotiza al finalizar la compra.', 'ltms' ),
                    wc_price( $threshold )
                );
                $estimated_delivery = $country === 'MX' ? '3-7 días hábiles' : '2-5 días hábiles';
                break;
            case 'shared':
                $pct = class_exists( 'LTMS_Shipping_Mode' ) ? LTMS_Shipping_Mode::get_shared_customer_pct() : 60;
                $shipping_cost_text = sprintf(
                    __( 'El cliente paga el %d%% del flete y el vendedor absorbe el restante %d%%.', 'ltms' ),
                    $pct, 100 - $pct
                );
                $estimated_delivery = $country === 'MX' ? '3-7 días hábiles' : '2-5 días hábiles';
                break;
            case 'flat':
                $flat = (float) LTMS_Core_Config::get( 'ltms_shipping_flat_rate', 8500 );
                $shipping_cost_text = sprintf( __( 'Tarifa de envío fija: %s', 'ltms' ), wc_price( $flat ) );
                $estimated_delivery = $country === 'MX' ? '3-7 días hábiles' : '2-5 días hábiles';
                break;
            case 'quoted':
            default:
                $shipping_cost_text = __( 'El costo de envío se calcula al finalizar la compra según tu ubicación.', 'ltms' );
                $estimated_delivery = $country === 'MX' ? '3-10 días hábiles' : '2-7 días hábiles';
                break;
        }
        ?>
        <div class="ltms-shipping-tab">
            <table class="ltms-shipping-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-weight:600;width:40%;">
                        &#x1F69A; <?php esc_html_e( 'Costo de envío', 'ltms' ); ?>
                    </td>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;">
                        <?php echo esc_html( $shipping_cost_text ); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-weight:600;">
                        &#x1F4C5; <?php esc_html_e( 'Tiempo estimado de entrega', 'ltms' ); ?>
                    </td>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;">
                        <?php echo esc_html( $estimated_delivery ); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-weight:600;">
                        &#x1F4E6; <?php esc_html_e( 'Carriers disponibles', 'ltms' ); ?>
                    </td>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;">
                        <?php
                        $carriers = [];
                        if ( get_option( 'ltms_deprisa_enabled', false ) ) $carriers[] = 'Deprisa';
                        if ( get_option( 'ltms_aveonline_enabled', false ) ) $carriers[] = 'Aveonline';
                        if ( get_option( 'ltms_heka_enabled', false ) ) $carriers[] = 'Heka';
                        if ( get_option( 'ltms_uber_enabled', false ) ) $carriers[] = 'Uber Direct';
                        echo esc_html( ! empty( $carriers ) ? implode( ', ', $carriers ) : __( 'Múltiples opciones', 'ltms' ) );
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-weight:600;">
                        &#x21A9; <?php esc_html_e( 'Devoluciones', 'ltms' ); ?>
                    </td>
                    <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;">
                        <?php
                        $hold_days = $country === 'MX' ? 10 : 5;
                        echo esc_html( sprintf(
                            __( 'Tienes %d días para solicitar devolución tras recibir el producto.', 'ltms' ),
                            $hold_days
                        ) );
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px 0;font-weight:600;">
                        &#x1F6E1; <?php esc_html_e( 'Protección', 'ltms' ); ?>
                    </td>
                    <td style="padding:10px 0;">
                        <?php echo esc_html( $country === 'MX'
                            ? __( 'Compra protegida por PROFECO (derecho de retracto).', 'ltms' )
                            : __( 'Compra protegida por Ley 1480 (Estatuto del Consumidor).', 'ltms' ) );
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    // ================================================================
    // SIZE GUIDE MODAL
    // ================================================================

    public static function render_size_guide_button(): void {
        global $product;
        if ( ! $product ) return;

        $size_guide = get_post_meta( $product->get_id(), '_ltms_size_guide', true );
        if ( ! $size_guide ) return;
        ?>
        <div style="margin:8px 0;">
            <button type="button" class="ltms-size-guide-btn" id="ltms-size-guide-open"
                    style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;color:#2563eb;">
                <span>&#x1F4CF;</span> <?php esc_html_e( 'Guía de tallas / dimensiones', 'ltms' ); ?>
            </button>
        </div>

        <!-- Modal -->
        <div id="ltms-size-guide-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:12px;max-width:600px;width:100%;max-height:80vh;overflow-y:auto;padding:24px;position:relative;">
                <button type="button" id="ltms-size-guide-close"
                        style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;color:#6b7280;">&times;</button>
                <h3 style="margin:0 0 16px;font-size:18px;">&#x1F4CF; <?php esc_html_e( 'Guía de tallas / dimensiones', 'ltms' ); ?></h3>
                <div class="ltms-size-guide-content" style="overflow-x:auto;">
                    <?php echo wp_kses_post( wpautop( $size_guide ) ); ?>
                </div>
            </div>
        </div>

        <?php
        // INTEGRATIONS-AUDIT P0 FIX (CSP): moved inline <script> to external
        // assets/js/ltms-product-tabs.js (enqueued below).
        wp_enqueue_script(
            'ltms-product-tabs',
            LTMS_ASSETS_URL . 'js/ltms-product-tabs.js',
            [ 'jquery' ],
            LTMS_VERSION,
            true
        );
    }

    public static function render_size_guide_meta(): void {
        global $post;
        $size_guide = get_post_meta( $post->ID, '_ltms_size_guide', true );
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="_ltms_size_guide"><?php esc_html_e( 'Guía de tallas / dimensiones', 'ltms' ); ?></label>
                <textarea name="_ltms_size_guide" id="_ltms_size_guide" rows="4"
                          style="width:100%;" placeholder="<?php esc_attr_e( 'Ej: Talla | Pecho | Cintura | Cadera\nS | 86-90 | 70-74 | 90-94\nM | 90-94 | 74-78 | 94-98', 'ltms' ); ?>"><?php echo esc_textarea( $size_guide ); ?></textarea>
                <?php echo wc_help_tip( __( 'Tabla de tallas o dimensiones del producto. Se muestra en un modal.', 'ltms' ) ); ?>
            </p>
        </div>
        <?php
    }

    public static function save_size_guide_meta( int $post_id ): void {
        // INTEGRATIONS-AUDIT P1 FIX: add explicit nonce verification (was
        // relying on WC's inherited nonce — best practice is explicit).
        if ( ! isset( $_POST['woocommerce_meta_nonce'] )
            || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' )
        ) {
            return;
        }
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;
        $guide = wp_kses_post( wp_unslash( $_POST['_ltms_size_guide'] ?? '' ) );
        if ( $guide ) {
            update_post_meta( $post_id, '_ltms_size_guide', $guide );
        } else {
            delete_post_meta( $post_id, '_ltms_size_guide' );
        }
    }
}
