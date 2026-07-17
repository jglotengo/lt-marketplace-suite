<?php
/**
 * LTMS Native Templates — Plaza Viva Design System
 *
 * Reemplaza las plantillas de WooCommerce con templates nativos del plugin
 * que usan el design system "Plaza Viva", eludiendo Elementor en páginas
 * transaccionales para máximo control de UX y performance.
 *
 * @package LTMS
 * @version 2.9.183
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Native_Templates
 *
 * Intercepta template_include para servir plantillas nativas del plugin
 * en páginas de producto, tienda, carrito, checkout y páginas custom.
 */
class LTMS_Native_Templates {

    /**
     * Si el override está habilitado (configurable desde admin).
     *
     * @var bool
     */
    private static bool $enabled = true;

    /**
     * Directorio base de los templates nativos.
     *
     * @var string
     */
    private static string $template_dir = '';

    /**
     * Inicializa el sistema de templates nativos.
     */
    public static function init(): void {
        // Guard: LTMS_PATH puede no estar definida en contextos de testing
        // o si el plugin se carga parcialmente.
        if ( ! defined( 'LTMS_PATH' ) ) {
            return;
        }

        self::$template_dir = LTMS_PATH . 'includes/frontend/templates/';

        // Solo activar en frontend.
        if ( is_admin() ) {
            return;
        }

        // Config: permite desactivar desde admin (ltms_native_templates_enabled).
        self::$enabled = ( get_option( 'ltms_native_templates_enabled', 'yes' ) === 'yes' );

        if ( ! self::$enabled ) {
            return;
        }

        // Override template_include con prioridad alta (después de WC y Elementor).
        add_filter( 'template_include', [ __CLASS__, 'maybe_override' ], 99 );

        // Override de plantillas de WC content (parts).
        add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_wc_template' ], 10, 3 );
        add_filter( 'woocommerce_template_loader_files', [ __CLASS__, 'template_loader_files' ], 10, 2 );

        // Enqueue design system assets.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );

        // AJAX handlers para quick view y add-to-cart.
        add_action( 'wp_ajax_ltms_pv_quick_view', [ __CLASS__, 'ajax_quick_view' ] );
        add_action( 'wp_ajax_nopriv_ltms_pv_quick_view', [ __CLASS__, 'ajax_quick_view' ] );

        // Body class para scope CSS.
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
    }

    /**
     * Decide qué template nativo usar según el contexto de WC.
     *
     * @param string $template Template original.
     * @return string Template override o el original.
     */
    public static function maybe_override( string $template ): string {
        // Single product.
        if ( is_product() ) {
            $native = self::$template_dir . 'single-product.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Shop / archive / category / tag.
        if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
            $native = self::$template_dir . 'archive-product.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Cart.
        if ( is_cart() ) {
            $native = self::$template_dir . 'cart.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Checkout.
        if ( is_checkout() ) {
            $native = self::$template_dir . 'checkout.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // My Account.
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $native = self::$template_dir . 'my-account.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Order tracking (custom page).
        if ( self::is_order_tracking_page() ) {
            $native = self::$template_dir . 'order-tracking.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Vendor store (custom).
        if ( self::is_vendor_store_page() ) {
            $native = self::$template_dir . 'vendor-store.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Help center (custom).
        if ( self::is_help_page() ) {
            $native = self::$template_dir . 'help-center.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        return $template;
    }

    /**
     * Override de WC template parts (content-product.php, etc.).
     */
    public static function locate_wc_template( string $template, string $template_name, string $template_path ): string {
        $native = self::$template_dir . 'wc-parts/' . $template_name;
        if ( file_exists( $native ) ) {
            return $native;
        }
        return $template;
    }

    /**
     * Override del template loader de WC.
     */
    public static function template_loader_files( array $templates, string $default_file ): array {
        // Permitir que WC siga cargando su header/footer wrapper.
        return $templates;
    }

    /**
     * Encola el design system CSS + JS.
     */
    public static function enqueue_assets(): void {
        $ver = defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '2.9.183';
        $url = defined( 'LTMS_ASSETS_URL' ) ? LTMS_ASSETS_URL : '';

        // Google Fonts: Albert Sans + Inter + JetBrains Mono.
        wp_enqueue_style(
            'ltms-pv-fonts',
            'https://fonts.googleapis.com/css2?family=Albert+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap',
            [],
            null
        );

        // Design system CSS.
        wp_enqueue_style( 'ltms-plaza-viva', $url . 'css/ltms-plaza-viva.css', [ 'ltms-pv-fonts' ], $ver );

        // Design system JS (vanilla, no jQuery).
        wp_enqueue_script( 'ltms-plaza-viva', $url . 'js/ltms-plaza-viva.js', [], $ver, true );

        // Localize para AJAX.
        wp_localize_script( 'ltms-plaza-viva', 'ltms_data', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ltms_plaza_viva' ),
            'cart_url'  => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'i18n'      => [
                'addedToCart'     => __( 'Producto añadido al carrito', 'ltms' ),
                'cartError'       => __( 'Error al añadir al carrito', 'ltms' ),
                'quickViewError'  => __( 'No se pudo cargar el producto', 'ltms' ),
                'loading'         => __( 'Cargando...', 'ltms' ),
                'outOfStock'      => __( 'Agotado', 'ltms' ),
                'onlyLeft'        => __( '¡Solo quedan %d!', 'ltms' ),
                'peopleViewing'   => __( '%d personas viendo esto', 'ltms' ),
            ],
        ] );
    }

    /**
     * Añade clase al body para scope CSS.
     */
    public static function body_class( array $classes ): array {
        $classes[] = 'pv-scope';
        $classes[] = 'ltms-native-template';
        return $classes;
    }

    /**
     * AJAX: Quick view de producto.
     */
    public static function ajax_quick_view(): void {
        check_ajax_referer( 'ltms_plaza_viva', 'nonce' );

        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID' ] );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found' ] );
        }

        ob_start();
        self::render_quick_view( $product );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * Renderiza el contenido del quick view.
     */
    private static function render_quick_view( \WC_Product $product ): void {
        $rating = $product->get_average_rating();
        $price = $product->get_price_html();
        ?>
        <div class="pv-quick-view">
            <div class="pv-quick-view__image">
                <?php echo $product->get_image( 'medium' ); ?>
            </div>
            <div class="pv-quick-view__info">
                <h3><?php echo esc_html( $product->get_name() ); ?></h3>
                <div class="pv-stars" data-rating="<?php echo esc_attr( $rating ); ?>">
                    <?php echo wc_get_rating_html( $rating ); ?>
                    <span>(<?php echo esc_html( $product->get_review_count() ); ?>)</span>
                </div>
                <div class="pv-quick-view__price"><?php echo $price; ?></div>
                <p class="pv-quick-view__excerpt"><?php echo wp_trim_words( $product->get_short_description(), 30 ); ?></p>
                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="pv-btn pv-btn-primary">
                    <?php esc_html_e( 'Ver producto', 'ltms' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Detecta si es la página de tracking de orden.
     */
    private static function is_order_tracking_page(): bool {
        return is_page( 'seguimiento' ) || is_page( 'tracking' ) ||
               ( get_query_var( 'ltms_page' ) === 'tracking' );
    }

    /**
     * Detecta si es la página pública de vendor store.
     */
    private static function is_vendor_store_page(): bool {
        return is_singular( 'ltms_vendor_store' ) ||
               ( get_query_var( 'ltms_page' ) === 'vendor-store' );
    }

    /**
     * Detecta si es la página de ayuda/soporte.
     */
    private static function is_help_page(): bool {
        return is_page( 'ayuda' ) || is_page( 'help' ) ||
               ( get_query_var( 'ltms_page' ) === 'help' );
    }
}

// NOTA: La inicialización se realiza explícitamente en ltms_run()
// (lt-marketplace-suite.php) después del Kernel boot, para garantizar
// que WooCommerce esté cargado antes de los template overrides.
