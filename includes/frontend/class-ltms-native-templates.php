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
        // Resolver la ruta del plugin de forma compatible (LTMS_PATH o LTMS_PLUGIN_DIR).
        $plugin_dir = defined( 'LTMS_PATH' ) ? LTMS_PATH : ( defined( 'LTMS_PLUGIN_DIR' ) ? LTMS_PLUGIN_DIR : dirname( __DIR__, 3 ) . '/' );
        self::$template_dir = $plugin_dir . 'includes/frontend/templates/';

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
        // DISABLED: causing shop page crash. The content-product.php override
        // is triggering a fatal error when WC loads it in the loop context.
        // The single-product.php template works fine because it doesn't use
        // v2.9.211: Remove WC's default related products output to prevent
        // duplicate "Productos relacionados" sections. Our single-product.php
        // template calls woocommerce_related_products() explicitly with PV
        // design system wrapper. Elementor Theme Builder may also output its
        // own related products — those are deduped client-side via JS.
        add_action( 'init', [ __CLASS__, 'remove_default_related_products' ] );
        // wc_get_template_part in a loop.
        // add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_wc_template' ], 10, 3 );
        // add_filter( 'woocommerce_template_loader_files', [ __CLASS__, 'template_loader_files' ], 10, 2 );

        // Enqueue design system assets.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );

        // AJAX handlers para quick view y add-to-cart.
        add_action( 'wp_ajax_ltms_pv_quick_view', [ __CLASS__, 'ajax_quick_view' ] );
        add_action( 'wp_ajax_nopriv_ltms_pv_quick_view', [ __CLASS__, 'ajax_quick_view' ] );

        // Body class para scope CSS.
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );

        // v2.9.191 — Rewrite rules for custom pages (tracking, help).
        // Disabled temporarily — causing shop page crash. Needs flush_rewrite_rules.
        // add_action( 'init', [ __CLASS__, 'register_rewrites' ] );
        // add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
    }

    /**
     * Registra rewrite rules para páginas custom del plugin.
     */
    public static function register_rewrites(): void {
        add_rewrite_rule( '^seguimiento/?$', 'index.php?ltms_page=tracking', 'top' );
        add_rewrite_rule( '^ayuda/?$', 'index.php?ltms_page=help', 'top' );
    }

    /**
     * Registra query vars custom.
     */
    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ltms_page';
        return $vars;
    }

    /**
     * v2.9.211: Remove WC's default related products output hook.
     *
     * WC registers woocommerce_output_related on 'woocommerce_after_single_product_summary'
     * priority 20. Our single-product.php template calls woocommerce_related_products()
     * explicitly with PV design system wrapper. Removing the default hook prevents
     * duplicate "Productos relacionados" sections when both our template and the
     * WC default hook fire.
     *
     * Elementor Theme Builder may have its own related products widget — those are
     * deduped client-side via JS (see remove_duplicate_related_sections()).
     */
    public static function remove_default_related_products(): void {
        // WC default: add_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
    }

    /**
     * Decide qué template nativo usar según el contexto de WC.
     *
     * @param string $template Template original.
     * @return string Template override o el original.
     */
    public static function maybe_override( string $template ): string {
        // CRITICAL: If Elementor Pro Theme Builder is handling this page,
        // do NOT override. Elementor registers its own template_include
        // callback. Our priority 99 runs AFTER Elementor, but calling
        // is_product() / is_shop() etc. triggers WC query setup that
        // conflicts with Elementor's template loading, causing a fatal.
        // Only override on specific pages where we know it's safe.
        if ( did_action( 'elementor_pro/init' ) || did_action( 'elementor/loaded' ) ) {
            // Elementor is active — only override single product and cart/checkout.
            // Skip ALL archive/shop/category/tag overrides.
            if ( is_product() ) {
                $native = self::$template_dir . 'single-product.php';
                if ( file_exists( $native ) ) {
                    return $native;
                }
            }
            if ( is_cart() ) {
                $native = self::$template_dir . 'cart.php';
                if ( file_exists( $native ) ) {
                    return $native;
                }
            }
            if ( is_checkout() ) {
                $native = self::$template_dir . 'checkout.php';
                if ( file_exists( $native ) ) {
                    return $native;
                }
            }
            // For ALL other pages (shop, category, tag, account, etc.),
            // return the original template — let Elementor handle it.
            return $template;
        }

        // Non-Elementor: full override (all pages).
        if ( is_product() ) {
            $native = self::$template_dir . 'single-product.php';
            if ( file_exists( $native ) ) {
                return $native;
            }
        }

        // Shop / archive / category / tag.
        // DISABLED: even the minimal archive-product.php causes a critical error
        // on /tienda/. The issue is NOT in our template code but in how the
        // template_include override interacts with Elementor's shop page template.
        // Elementor registers its own template for the shop page at a higher
        // priority, and our override at priority 99 conflicts with it.
        // Falling through to Elementor's shop template.
        // if ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) {
        //     $native = self::$template_dir . 'archive-product.php';
        //     if ( file_exists( $native ) ) {
        //         return $native;
        //     }
        // }

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
        wp_enqueue_style( 'ltms-plaza-viva', $url . 'css/ltms-plaza-viva.css', [ 'ltms-pv-fonts' ], $ver . '-b' . time() );

        // Design system JS (vanilla, no jQuery).
        // SG Optimizer strips query params and caches by filename.
        // Solution: use wp_add_inline_script to inject critical PV functions
        // directly in the HTML, bypassing SG JS cache entirely.
        wp_enqueue_script( 'ltms-plaza-viva', $url . 'js/ltms-plaza-viva.js', [], $ver, true );

        // v2.9.202 — Inline PV patch: inject critical functions that may not
        // be in the cached version of ltms-plaza-viva.js. This runs AFTER
        // the external JS file, adding any missing functions.
        add_action( 'wp_footer', [ __CLASS__, 'inject_pv_patch' ], 999 );

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
     * v2.9.202 — Inline PV patch: injects critical PV functions directly
     * in the HTML footer, bypassing SG Optimizer JS cache.
     * This ensures new functions are always available even if the
     * external JS file is cached with an older version.
     */
    public static function inject_pv_patch(): void {
        ?>
        <script id="ltms-pv-patch">
        (function(){
            if (!window.PV) window.PV = { version: '3.0.2' };

            // ALWAYS override functions (don't check if exists — the cached
            // external JS may have an OLD version with wrong selectors).
            PV.injectHeroHeadline = function() {
                if (!document.body.classList.contains('home')) return;
                if (document.querySelector('.ltms-hero-headline')) return;
                var hero = document.querySelector('.e-con') || document.querySelector('.e-flex') || document.querySelector('.elementor-section') || document.querySelector('[data-elementor-type]') || document.querySelector('main') || document.querySelector('.site-main');
                if (!hero) return;
                var h = document.createElement('div');
                h.className = 'ltms-hero-headline';
                h.innerHTML = '<h2 style="font-family:Albert Sans,sans-serif;font-size:clamp(24px,4vw,36px);font-weight:800;color:#fff;text-align:center;padding:12px 20px;background:linear-gradient(135deg,#E80001 0%,#B80001 100%);border-radius:14px;margin:0 auto;max-width:600px;box-shadow:0 4px 14px rgba(232,0,1,0.3);line-height:1.3;letter-spacing:-0.02em">Tu Marketplace de Confianza en Colombia</h2><p style="text-align:center;color:#565C66;font-size:14px;margin-top:8px;font-weight:500">Miles de productos de vendedores verificados · PSE · Nequi · Envío a todo el país</p>';
                hero.insertBefore(h, hero.firstChild);
            };

            PV.cleanShopPage = function() {
                if (!document.body.classList.contains('archive') && !document.body.classList.contains('post-type-archive-product') && !document.body.classList.contains('tax-product_cat')) return;
                var searches = document.querySelectorAll('.widget-area .woocommerce-product-search, .sidebar .woocommerce-product-search, .widget_product_search');
                for (var i = 0; i < searches.length; i++) {
                    var w = searches[i].closest('.widget');
                    if (w) w.style.display = 'none';
                    else searches[i].style.display = 'none';
                }
            };

            PV.enhancePriceDisplay = function() {
                if (!document.body.classList.contains('single-product')) return;
                // v2.9.211: Robust dedup — check both class AND data attribute.
                if (document.querySelector('.ltms-price-shipping-info, [data-ltms-shipping-info="1"]')) return;
                var price = document.querySelector('.single-product .price, .product .price, .price:not(.ltms-price-shipping-info)');
                if (!price) return;
                var info = document.createElement('div');
                info.className = 'ltms-price-shipping-info';
                info.setAttribute('data-ltms-shipping-info', '1');
                info.style.cssText = 'font-size:13px;color:#0BA37F;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px';
                info.innerHTML = '<span>\uD83D\uDE9A</span> <span>Envío gratis incluido</span>';
                if (price.parentNode) price.parentNode.insertBefore(info, price.nextSibling);
            };

            PV.injectBuyNow = function() {
                var atc = document.querySelector('form.cart .single_add_to_cart_button, .elementor-add-to-cart .single_add_to_cart_button, button[name="add-to-cart"]');
                if (!atc) return;
                if (document.querySelector('.ltms-buy-now-btn')) return;
                var form = atc.closest('form.cart');
                var pid = '';
                if (form) { var hidden = form.querySelector('input[name="add-to-cart"]'); if (hidden) pid = hidden.value; }
                if (!pid && atc.name === 'add-to-cart') pid = atc.value;
                if (!pid) return;
                var checkoutUrl = (window.ltms_data && window.ltms_data.checkout_url) || '/checkout/';
                var bn = document.createElement('a');
                bn.href = checkoutUrl + '?buy_now=' + encodeURIComponent(pid);
                bn.className = 'ltms-buy-now-btn';
                bn.setAttribute('aria-label', 'Comprar ahora');
                bn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:6px"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke-linecap="round" stroke-linejoin="round"/></svg>Comprar ahora';
                if (atc.parentNode) atc.parentNode.insertBefore(bn, atc.nextSibling);
            };

            // v2.9.204 — Inject +/− buttons around quantity inputs on product page
            PV.injectQtySteppers = function() {
                if (!document.body.classList.contains('single-product')) return;
                var qtyInputs = document.querySelectorAll('form.cart .quantity input[type="number"], form.cart .qty, .quantity input.qty');
                for (var i = 0; i < qtyInputs.length; i++) {
                    var input = qtyInputs[i];
                    if (input.dataset.ltmsStepper) continue;
                    input.dataset.ltmsStepper = '1';

                    var wrapper = input.closest('.quantity');
                    if (!wrapper) {
                        wrapper = document.createElement('div');
                        wrapper.className = 'quantity ltms-qty-wrapper';
                        input.parentNode.insertBefore(wrapper, input);
                        wrapper.appendChild(input);
                    }

                    // Add wrapper class
                    wrapper.classList.add('ltms-qty-wrapper');
                    wrapper.style.cssText = 'display:inline-flex !important;align-items:center !important;border:2px solid #E5E7EB !important;border-radius:10px !important;overflow:hidden !important;height:48px !important';

                    // Minus button
                    var minus = document.createElement('button');
                    minus.type = 'button';
                    minus.className = 'ltms-qty-btn ltms-qty-minus';
                    minus.setAttribute('aria-label', 'Disminuir cantidad');
                    minus.style.cssText = 'width:44px;height:48px;border:none;background:#F6F5F8 !important;font-size:20px;font-weight:700;color:#565C66;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.15s';
                    minus.innerHTML = '\u2212';
                    minus.onmouseover = function() { this.style.background = '#E5E7EB'; };
                    minus.onmouseout = function() { this.style.background = '#F6F5F8'; };

                    // Plus button
                    var plus = document.createElement('button');
                    plus.type = 'button';
                    plus.className = 'ltms-qty-btn ltms-qty-plus';
                    plus.setAttribute('aria-label', 'Aumentar cantidad');
                    plus.style.cssText = 'width:44px;height:48px;border:none;background:#F6F5F8 !important;font-size:20px;font-weight:700;color:#565C66;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.15s';
                    plus.innerHTML = '+';
                    plus.onmouseover = function() { this.style.background = '#E5E7EB'; };
                    plus.onmouseout = function() { this.style.background = '#F6F5F8'; };

                    // Style input
                    input.style.cssText = 'border:none !important;text-align:center !important;width:56px !important;height:48px !important;font-size:16px !important;font-weight:600 !important;background:transparent !important;-moz-appearance:textfield !important;-webkit-appearance:none !important;box-shadow:none !important;outline:none !important;padding:0 4px !important';
                    input.setAttribute('readonly', 'readonly');

                    // Insert buttons
                    wrapper.insertBefore(minus, input);
                    if (input.nextSibling) {
                        wrapper.insertBefore(plus, input.nextSibling);
                    } else {
                        wrapper.appendChild(plus);
                    }

                    // Event handlers
                    var min = parseFloat(input.getAttribute('min')) || 1;
                    var max = parseFloat(input.getAttribute('max')) || 9999;
                    var step = parseFloat(input.getAttribute('step')) || 1;

                    function setVal(v) {
                        v = Math.max(min, Math.min(max, parseFloat(v) || min));
                        input.value = v;
                        minus.disabled = (v <= min);
                        plus.disabled = (v >= max);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    minus.onclick = function(e) { e.preventDefault(); setVal(parseFloat(input.value) - step); };
                    plus.onclick = function(e) { e.preventDefault(); setVal(parseFloat(input.value) + step); };
                    setVal(input.value);
                }
            };

            // v2.9.204 — Enhance cart page: add +/− buttons and remove buttons
            PV.enhanceCartPage = function() {
                var cartPage = document.body.classList.contains('woocommerce-cart') || document.body.classList.contains('woocommerce-checkout');
                if (!cartPage && !document.querySelector('table.cart, .cart-collaterals, .woocommerce-cart-form')) return;

                // Add +/− steppers to cart quantity inputs
                var cartQtys = document.querySelectorAll('input.qty, .product-quantity input[type="number"]');
                for (var i = 0; i < cartQtys.length; i++) {
                    var input = cartQtys[i];
                    if (input.dataset.ltmsStepper) continue;
                    input.dataset.ltmsStepper = '1';

                    var td = input.closest('td');
                    if (!td) continue;

                    var wrapper = document.createElement('div');
                    wrapper.className = 'ltms-qty-wrapper';
                    wrapper.style.cssText = 'display:inline-flex !important;align-items:center !important;border:2px solid #E5E7EB !important;border-radius:8px !important;overflow:hidden !important;height:40px !important';

                    var minus = document.createElement('button');
                    minus.type = 'button';
                    minus.style.cssText = 'width:36px;height:40px;border:none;background:#F6F5F8;font-size:18px;font-weight:700;color:#565C66;cursor:pointer';
                    minus.innerHTML = '\u2212';

                    var plus = document.createElement('button');
                    plus.type = 'button';
                    plus.style.cssText = 'width:36px;height:40px;border:none;background:#F6F5F8;font-size:18px;font-weight:700;color:#565C66;cursor:pointer';
                    plus.innerHTML = '+';

                    input.style.cssText = 'border:none !important;text-align:center !important;width:48px !important;height:40px !important;font-size:14px !important;font-weight:600 !important;background:transparent !important;-moz-appearance:textfield !important;-webkit-appearance:none !important;outline:none !important;padding:0 !important';

                    input.parentNode.insertBefore(wrapper, input);
                    wrapper.appendChild(minus);
                    wrapper.appendChild(input);
                    wrapper.appendChild(plus);

                    var min = parseFloat(input.getAttribute('min')) || 0;
                    var step = parseFloat(input.getAttribute('step')) || 1;

                    minus.onclick = function(e) {
                        e.preventDefault();
                        var v = Math.max(min, parseFloat(input.value) - step);
                        input.value = v;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        // Trigger update cart button if exists
                        var updateBtn = document.querySelector('[name="update_cart"]');
                        if (updateBtn) { updateBtn.disabled = false; updateBtn.click(); }
                    };
                    plus.onclick = function(e) {
                        e.preventDefault();
                        var v = parseFloat(input.value) + step;
                        input.value = v;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        var updateBtn = document.querySelector('[name="update_cart"]');
                        if (updateBtn) { updateBtn.disabled = false; updateBtn.click(); }
                    };
                }

                // Enhance remove buttons — make them more visible
                var removeBtns = document.querySelectorAll('.product-remove a.remove, td.product-remove a');
                for (var j = 0; j < removeBtns.length; j++) {
                    var rm = removeBtns[j];
                    rm.style.cssText = 'display:inline-flex !important;align-items:center !important;justify-content:center !important;width:32px !important;height:32px !important;border-radius:50% !important;background:#FEE2E2 !important;color:#DC2626 !important;font-size:16px !important;font-weight:700 !important;text-decoration:none !important;transition:all 0.15s !important';
                    rm.innerHTML = '\u00d7';
                    rm.title = 'Eliminar producto';
                    rm.onmouseover = function() { this.style.background = '#DC2626'; this.style.color = '#fff'; };
                    rm.onmouseout = function() { this.style.background = '#FEE2E2'; this.style.color = '#DC2626'; };
                }

                // Enhance add-to-cart buttons on homepage/shop (fly to cart)
                var shopAtcBtns = document.querySelectorAll('.product a.button, .product button.button, .product-type-simple a.ajax_add_to_cart');
                for (var k = 0; k < shopAtcBtns.length; k++) {
                    var btn = shopAtcBtns[k];
                    if (btn.dataset.ltmsEnhanced) continue;
                    btn.dataset.ltmsEnhanced = '1';
                    btn.style.cssText = 'display:block !important;width:100% !important;text-align:center !important;border-radius:8px !important;font-weight:600 !important;font-size:13px !important;padding:10px !important;margin-top:8px !important;background:#E80001 !important;color:#fff !important;border:none !important;transition:all 0.18s !important';
                    btn.onmouseover = function() { this.style.transform = 'translateY(-1px)'; this.style.boxShadow = '0 4px 12px rgba(232,0,1,0.25)'; };
                    btn.onmouseout = function() { this.style.transform = ''; this.style.boxShadow = ''; };
                }
            };

            // v2.9.211: Deduplicate "Productos relacionados" sections.
            // Both WC default hook and our explicit call may output them.
            // Also Elementor Theme Builder may add its own. Keep only the
            // FIRST one (which is our PV-styled section), hide the rest.
            PV.dedupRelatedProducts = function() {
                var sections = document.querySelectorAll('.related.products, .upsells.products, .pv-related');
                if (sections.length <= 1) return;
                // Keep the first one (our .pv-related if present, else first .related).
                var kept = null;
                // Prefer .pv-related (our PV-styled section)
                for (var i = 0; i < sections.length; i++) {
                    if (sections[i].classList.contains('pv-related')) { kept = sections[i]; break; }
                }
                if (!kept) kept = sections[0];
                // Hide all others
                for (var j = 0; j < sections.length; j++) {
                    if (sections[j] !== kept) sections[j].style.display = 'none';
                }
            };

            // v2.9.211: Enhance related product cards to match PV design system.
            // WC's default related products use ul.products > li.product markup,
            // which doesn't have PV card styling. Add CSS classes + fix image aspect.
            PV.enhanceRelatedCards = function() {
                var relatedProducts = document.querySelectorAll('.related.products ul.products > li.product, .pv-related ul.products > li.product');
                for (var i = 0; i < relatedProducts.length; i++) {
                    var card = relatedProducts[i];
                    if (card.dataset.ltmsEnhanced) continue;
                    card.dataset.ltmsEnhanced = '1';

                    // Match PV card aesthetic
                    card.style.cssText = 'position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #E7E5EC;border-radius:14px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.06);transition:transform .25s,box-shadow .25s,border-color .25s;break-inside:avoid';

                    // Image: use object-fit:contain (no crop), square aspect
                    var img = card.querySelector('img');
                    if (img) {
                        var imgWrap = img.parentNode;
                        if (imgWrap) {
                            imgWrap.style.cssText = 'position:relative;aspect-ratio:1/1;background:#F8F7FA;overflow:hidden;display:flex;align-items:center;justify-content:center;padding:12px';
                        }
                        img.style.cssText = 'width:100%;height:100%;object-fit:contain;transition:transform .4s cubic-bezier(.4,0,.2,1)';
                    }

                    // Card body (title, price)
                    var body = card.querySelector('.woocommerce-loop-product__title, h2');
                    if (body) {
                        body.style.cssText = 'font-family:Albert Sans,sans-serif;font-size:14px;font-weight:600;color:#1A1F2E;line-height:1.4;margin:10px 14px 4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px';
                    }

                    // Price
                    var price = card.querySelector('.price');
                    if (price) {
                        price.style.cssText = 'font-family:Albert Sans,sans-serif;font-size:17px;font-weight:700;color:#1A1F2E;margin:4px 14px 12px;display:flex;align-items:baseline;gap:6px;flex-wrap:wrap';
                        var oldPrice = price.querySelector('del');
                        if (oldPrice) oldPrice.style.cssText = 'font-size:13px;color:#9CA3AF;text-decoration:line-through;font-weight:500';
                        var newPrice = price.querySelector('ins');
                        if (newPrice) newPrice.style.cssText = 'text-decoration:none;color:#0BA37F;font-weight:700';
                    }

                    // Button
                    var btn = card.querySelector('.button, .added_to_cart');
                    if (btn) {
                        btn.style.cssText = 'display:block;width:calc(100% - 24px);margin:0 12px 12px;padding:10px;border-radius:8px;font-size:13px;font-weight:600;text-align:center;background:#E80001;color:#fff;border:none;transition:all .18s';
                        btn.onmouseover = function() { this.style.transform = 'translateY(-1px)'; this.style.boxShadow = '0 4px 12px rgba(232,0,1,0.25)'; };
                        btn.onmouseout = function() { this.style.transform = ''; this.style.boxShadow = ''; };
                    }

                    // Hover lift on card
                    card.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-4px)';
                        this.style.boxShadow = '0 8px 24px rgba(0,0,0,0.10)';
                        this.style.borderColor = '#E80001';
                        var imgInner = this.querySelector('img');
                        if (imgInner) imgInner.style.transform = 'scale(1.06)';
                    });
                    card.addEventListener('mouseleave', function() {
                        this.style.transform = '';
                        this.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
                        this.style.borderColor = '#E7E5EC';
                        var imgInner = this.querySelector('img');
                        if (imgInner) imgInner.style.transform = '';
                    });
                }

                // Ensure the grid uses consistent columns
                var grids = document.querySelectorAll('.related.products ul.products, .pv-related ul.products');
                for (var k = 0; k < grids.length; k++) {
                    grids[k].style.cssText = 'display:grid !important;grid-template-columns:repeat(auto-fill,minmax(180px,1fr)) !important;gap:16px !important;list-style:none !important;padding:0 !important;margin:0 !important';
                }
            };

            // Execute all functions with delay for Elementor rendering
            setTimeout(function() {
                if (PV.injectHeroHeadline) PV.injectHeroHeadline();
                if (PV.cleanShopPage) PV.cleanShopPage();
                if (PV.enhancePriceDisplay) PV.enhancePriceDisplay();
                if (PV.injectBuyNow) PV.injectBuyNow();
                if (PV.injectQtySteppers) PV.injectQtySteppers();
                if (PV.enhanceCartPage) PV.enhanceCartPage();
                if (PV.dedupRelatedProducts) PV.dedupRelatedProducts();
                if (PV.enhanceRelatedCards) PV.enhanceRelatedCards();
            }, 500);
            // Re-run dedup + enhance after 2s for late-loading Elementor widgets
            setTimeout(function() {
                if (PV.dedupRelatedProducts) PV.dedupRelatedProducts();
                if (PV.enhanceRelatedCards) PV.enhanceRelatedCards();
            }, 2000);
        })();
        </script>
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

// Inicializar.
LTMS_Native_Templates::init();
