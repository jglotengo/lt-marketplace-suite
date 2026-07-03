<?php
/**
 * LTMS Frontend Assets - Enqueue de Recursos del Dashboard de Vendedor
 *
 * Registra y carga todos los assets CSS/JS del frontend del plugin:
 * - Panel SPA del vendedor
 * - Página de checkout con Openpay
 * - Login y registro de vendedores
 * - Buscador en tiempo real
 * - Notificaciones en tiempo real (polling)
 * - Progressive Web App (manifest + service worker)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Frontend_Assets
 */
final class LTMS_Frontend_Assets {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks de WordPress.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue_frontend_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue_header_nav' ], 5 );
        add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue_homepage_fixes' ], 20 );
        // Task 67-A / UX-LOAD-1: the 25,000-line UX enhancement layer was
        // documented in UX_ENHANCEMENTS.md but never enqueued — all toasts,
        // theme toggle, keyboard shortcuts, password strength meter, command
        // palette, focus trap, dark mode, tour system, etc. were dead code.
        // Loaded on every non-admin frontend page so the data-* attributes
        // rendered by templates actually trigger their JS handlers.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_ux_enhancements' ], 25 );
        add_action( 'wp_head',            [ $instance, 'inject_pwa_tags' ] );
        add_action( 'wp_footer',          [ $instance, 'inject_localized_data' ] );
    }

    /**
     * Carga los fixes de homepage: YouTube Facade, Trust Bar, QA products, textos cortados.
     * Aplica en todas las páginas públicas (el JS detecta el contexto internamente).
     *
     * @return void
     */
    public function enqueue_homepage_fixes(): void {
        if ( is_admin() ) {
            return;
        }
        $ver = LTMS_VERSION;
        $url = LTMS_ASSETS_URL;

        wp_enqueue_style(
            'ltms-homepage-fixes',
            $url . 'css/ltms-homepage-fixes.css',
            [],
            $ver
        );

        wp_enqueue_script(
            'ltms-homepage-fixes',
            $url . 'js/ltms-homepage-fixes.js',
            [],
            $ver,
            true
        );
    }

    /**
     * Carga los assets del header nav (Seller/Cliente) en TODAS las páginas del sitio.
     * Se carga con prioridad 5 para sobreescribir estilos del tema.
     *
     * @return void
     */
    public function enqueue_header_nav(): void {
        $ver = LTMS_VERSION;
        $url = LTMS_ASSETS_URL;

        wp_enqueue_style(
            'ltms-header-nav',
            $url . 'css/ltms-header-nav.css',
            [],
            $ver
        );

        wp_enqueue_script(
            'ltms-header-nav',
            $url . 'js/ltms-header-nav.js',
            [ 'jquery' ],
            $ver,
            true
        );

        $user_id    = get_current_user_id();
        $is_vendor  = $user_id ? LTMS_Utils::is_ltms_vendor( $user_id ) : false;
        $is_logged  = is_user_logged_in();
        $pages      = get_option( 'ltms_installed_pages', [] );
        $name       = $is_logged ? wp_get_current_user()->display_name : '';

        $dashboard_url = ! empty( $pages['ltms-dashboard'] ) ? get_permalink( $pages['ltms-dashboard'] ) : home_url( '/panel-vendedor/' );
        $wallet_url    = ! empty( $pages['ltms-wallet'] )    ? get_permalink( $pages['ltms-wallet'] )    : home_url( '/mi-billetera/' );
        $orders_url    = ! empty( $pages['ltms-orders'] )    ? get_permalink( $pages['ltms-orders'] )    : home_url( '/mis-pedidos/' );
        $my_account    = wc_get_page_permalink( 'myaccount' ) ?: home_url( '/mi-cuenta/' );

        wp_localize_script( 'ltms-header-nav', 'ltmsHeaderNav', [
            'is_logged_in'   => $is_logged,
            'is_vendor'      => $is_vendor,
            'display_name'   => $name,
            'sellers_url'    => home_url( '/sellers/' ),
            'mi_cuenta_url'  => $my_account,
            'dashboard_url'  => $dashboard_url,
            'orders_url'     => $orders_url,
            'wallet_url'     => $wallet_url,
            'logout_url'     => wp_logout_url( home_url() ),
        ] );

        // CSS inline eliminado — los estilos del flotante están en ltms-header-nav.css (M-118)
    }

    /**
     * Carga los assets según la página actual del frontend.
     *
     * @return void
     */
    public function enqueue_frontend_assets(): void {
        $is_prod = defined( 'LTMS_ENVIRONMENT' ) && LTMS_ENVIRONMENT === 'production';
        $ver     = LTMS_VERSION;
        $url     = LTMS_ASSETS_URL;
        $suffix  = $is_prod ? '.min' : '';
        $page_id = get_queried_object_id();
        $pages   = $this->get_installed_pages();

        // Nonce público para live search (disponible para todos los visitantes)
        wp_add_inline_script(
            'jquery-core',
            'window.ltmsPublic = window.ltmsPublic || {}; window.ltmsPublic.searchNonce = ' .
            wp_json_encode( wp_create_nonce( 'ltms_public_search' ) ) . ';',
            'after'
        );

        // Task 67-B — UX layer public AJAX bootstrap.
        // The ltms-ux-enhancements.js layer (toasts, quick view, reorder,
        // bundle, subscription, coupon, waitlist, recommendations, etc.)
        // needs an ajax_url + nonce available on EVERY page (storefront,
        // product, cart, checkout, dashboard). Previously the JS gated every
        // AJAX call behind `typeof ltmsDashboard !== 'undefined'`, which is
        // only localized on vendor dashboard pages — so on the customer-
        // facing storefront every UX module either silently failed or
        // faked a success toast. Exposing a global `ltmsUX` object with a
        // dedicated `ltms_ux_nonce` action lets the UX modules hit the
        // new endpoints added in class-ltms-frontend-checkout-handler.php
        // (ltms_get_recommendations, ltms_quick_view, ltms_reorder,
        // ltms_validate_coupon, ltms_add_bundle_to_cart,
        // ltms_toggle_subscription, ltms_get_recent_purchases,
        // ltms_submit_review, ltms_waitlist_subscribe,
        // ltms_search_autocomplete) from any page context.
        wp_add_inline_script(
            'jquery-core',
            'window.ltmsUX = window.ltmsUX || {}; window.ltmsUX.ajax_url = ' .
            wp_json_encode( admin_url( 'admin-ajax.php' ) ) .
            '; window.ltmsUX.nonce = ' . wp_json_encode( wp_create_nonce( 'ltms_ux_nonce' ) ) .
            ';',
            'after'
        );

        // CSS base del dashboard (siempre en páginas LTMS)
        if ( $this->is_ltms_page( $page_id, $pages ) ) {
            wp_enqueue_style(
                'ltms-dashboard',
                $url . 'css/ltms-dashboard.css',
                [],
                $ver
            );
            wp_enqueue_style(
                'ltms-frontend',
                $url . 'css/ltms-frontend.css',
                [ 'ltms-dashboard' ],
                $ver
            );
        }

        // Dashboard del vendedor (SPA) + páginas de panel independientes
        // ltms_vendor_orders, ltms_vendor_wallet, ltms_vendor_kyc, ltms_vendor_insurance, ltms_vendor_store
        // necesitan los mismos assets que el SPA para hacer sus llamadas AJAX.
        // M-FIX-BOOKINGS-01: 'ltms-bookings' (slug mis-reservas) y 'ltms-rnt' (slug rnt-turismo)
        // se crearon en M-QA-PAGES-01 pero nunca se agregaron aquí — sus shortcodes
        // dependen del objeto global `ltmsDashboard` (ajax_url/nonce) que solo se
        // localiza cuando enqueue_dashboard_assets() corre. Sin esto, la tabla de
        // reservas se queda en "Cargando reservas..." indefinidamente (ltmsDashboard
        // queda undefined y el script inline muere antes de disparar el AJAX).
        $vendor_panel_pages = [ 'ltms-dashboard', 'ltms-orders', 'ltms-wallet', 'ltms-kyc', 'ltms-insurance', 'ltms-bookings', 'ltms-rnt' ];
        $is_vendor_panel    = false;
        foreach ( $vendor_panel_pages as $key ) {
            if ( $page_id === (int) ( $pages[ $key ] ?? 0 ) ) {
                $is_vendor_panel = true;
                break;
            }
        }
        // M-56: fallback por shortcode — si ltms_installed_pages está desincronizado
        // con las páginas reales (slug existe pero key no apunta), detectar por contenido.
        if ( ! $is_vendor_panel && $page_id > 0 ) {
            $post = get_post( $page_id );
            $dashboard_shortcodes = [ 'ltms_vendor_dashboard', 'ltms_vendor_orders', 'ltms_vendor_wallet', 'ltms_vendor_kyc', 'ltms_vendor_insurance', 'ltms_vendor_store', 'ltms_vendor_bookings', 'ltms_vendor_rnt' ];
            if ( $post ) {
                foreach ( $dashboard_shortcodes as $sc ) {
                    if ( has_shortcode( $post->post_content, $sc ) ) {
                        $is_vendor_panel = true;
                        break;
                    }
                }
            }
        }
        // M-56b: fallback por slug — cubre páginas Elementor donde post_content no tiene shortcode.
        if ( ! $is_vendor_panel && $page_id > 0 ) {
            $vendor_slugs = [ 'panel-vendedor', 'mis-pedidos', 'mi-billetera', 'verificacion-identidad', 'mi-seguro', 'panel-vendedor-2', 'mis-reservas', 'rnt-turismo' ];
            $current_post = get_post( $page_id );
            if ( $current_post && in_array( $current_post->post_name, $vendor_slugs, true ) ) {
                $is_vendor_panel = true;
            }
        }
        if ( $is_vendor_panel ) {
            $this->enqueue_dashboard_assets( $url, $ver, $suffix );
        }

        // KDS — Kitchen Display System (tab=kds o view=kitchen en el dashboard SPA).
        // AUDIT-RESTAURANT-ENGINE: también cargar cuando el vendor es restaurante.
        $_is_restaurant_vendor = is_user_logged_in() && get_user_meta( get_current_user_id(), 'ltms_is_restaurant', true ) === 'yes';
        if ( $page_id === (int) ( $pages['ltms-dashboard'] ?? 0 ) &&
             ( $_is_restaurant_vendor ||
               ( isset( $_GET['tab'] ) && sanitize_key( $_GET['tab'] ) === 'kds' ) ||
               ( isset( $_GET['view'] ) && sanitize_key( $_GET['view'] ) === 'kitchen' ) ) ) {
            $this->enqueue_kds_assets( $url, $ver, $suffix );
        }

        // Checkout / Pasarela de pagos
        if ( is_checkout() || $page_id === (int) ( $pages['ltms-store'] ?? 0 ) ) {
            $this->enqueue_checkout_assets( $url, $ver, $suffix );
            $this->enqueue_shipping_selector( $url, $ver, $suffix );
            $this->enqueue_stripe_assets( $url, $ver, $suffix );
            // v3.1.0 — Cross-Border motor (Task 63-D): currency switcher widget.
            $this->enqueue_currency_switcher( $url, $ver, $suffix );
        }

        // Login y Registro de vendedores — detección por ID o por shortcode (M-121 fallback)
        // M-53: activator stores register page under key 'ltms-register', not 'ltms-vendor-register'.
        $is_auth_page = (
            $page_id === (int) ( $pages['ltms-login']           ?? 0 ) ||
            $page_id === (int) ( $pages['ltms-register']        ?? 0 ) ||
            $page_id === (int) ( $pages['ltms-vendor-register'] ?? 0 )  // legacy fallback
        );
        if ( ! $is_auth_page && $page_id > 0 ) {
            $post = get_post( $page_id );
            if ( $post && (
                has_shortcode( $post->post_content, 'ltms_vendor_login' ) ||
                has_shortcode( $post->post_content, 'ltms_vendor_register' )
            ) ) {
                $is_auth_page = true;
            }
        }
        // M-REG-02: fallback por slug y por datos de Elementor. Cubre el caso en que
        // la página de registro/login fue creada con Elementor (el shortcode vive en
        // _elementor_data, no en post_content) o no está registrada en ltms_pages.
        if ( ! $is_auth_page && $page_id > 0 ) {
            $post = $post ?? get_post( $page_id );
            $auth_slugs = [
                'registro-vendedor', 'registro-de-vendedor', 'vendor-register',
                'vendor-registro', 'registro', 'login-vendedor', 'vendor-login',
            ];
            if ( $post && in_array( $post->post_name, $auth_slugs, true ) ) {
                $is_auth_page = true;
            }
            if ( ! $is_auth_page && $post ) {
                $el_data = get_post_meta( $page_id, '_elementor_data', true );
                if ( $el_data && (
                    str_contains( $el_data, 'ltms_vendor_register' ) ||
                    str_contains( $el_data, 'ltms_vendor_login' )
                ) ) {
                    $is_auth_page = true;
                }
            }
        }
        if ( $is_auth_page ) {
            $this->enqueue_auth_assets( $url, $ver, $suffix );
        }

        // M-56: Sellers landing — /sellers/ — necesita ltms-frontend-extensions.css (contiene .ltms-sellers-landing).
        // El shortcode [ltms_sellers_landing] es público (sin login); encolamos extensions standalone.
        $is_sellers_page = ( $page_id === (int) ( $pages['ltms-sellers'] ?? 0 ) );
        if ( ! $is_sellers_page && $page_id > 0 ) {
            $post = get_post( $page_id );
            if ( $post && has_shortcode( $post->post_content, 'ltms_sellers_landing' ) ) {
                $is_sellers_page = true;
            }
        }
        if ( $is_sellers_page ) {
            // Cargar ltms-dashboard.css como dependencia base de ltms-frontend-extensions.
            wp_enqueue_style( 'ltms-dashboard', $url . 'css/ltms-dashboard.css', [], $ver );
            wp_enqueue_style( 'ltms-frontend-extensions', $url . 'css/ltms-frontend-extensions.css', [ 'ltms-dashboard' ], $ver );
        }
    }

    /**
     * Enqueue UX Enhancements (toasts, theme toggle, keyboard shortcuts, etc.)
     *
     * Task 67-A / UX-LOAD-1 FIX: This method was missing — all UX features
     * (toasts, command palette, theme toggle, focus trap, password strength
     * meter, dark mode, tour system, etc.) were dead code in production. The
     * 25,000-line UX layer (ltms-ux-enhancements.js + .css) was never wired to
     * any `wp_enqueue_*` call.
     *
     * Loaded on every non-admin frontend page (the JS self-detects context and
     * bails out of irrelevant modules via element-existence checks). Style
     * dependencies are declared against the always-loaded `ltms-header-nav` and
     * the page-conditional `ltms-frontend`, `ltms-dashboard`,
     * `ltms-login-register` styles — WordPress silently skips deps that were
     * never registered on a given page, so the same call is safe everywhere.
     *
     * @return void
     */
    public static function enqueue_ux_enhancements(): void {
        // Admin is served by its own assets pipeline — never load here.
        if ( is_admin() ) {
            return;
        }

        $min = ( defined( 'LTMS_ENVIRONMENT' ) && LTMS_ENVIRONMENT === 'production' ) ? '.min' : '';
        $ver = LTMS_VERSION;
        $url = LTMS_ASSETS_URL;

        // CSS — depends on whichever LTMS stylesheets are already registered
        // for the current page type. Missing deps are silently skipped by WP.
        wp_enqueue_style(
            'ltms-ux-enhancements',
            $url . 'css/ltms-ux-enhancements' . $min . '.css',
            [ 'ltms-frontend', 'ltms-dashboard', 'ltms-login-register', 'ltms-header-nav' ],
            $ver
        );

        // JS — load in the footer so the DOM is ready when initAll() runs.
        wp_enqueue_script(
            'ltms-ux-enhancements',
            $url . 'js/ltms-ux-enhancements' . $min . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        // Localize AJAX endpoint + nonce + i18n for the JS layer.
        wp_localize_script( 'ltms-ux-enhancements', 'ltmsUX', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ltms_ux_nonce' ),
            'is_admin' => false,
            'currency' => LTMS_Core_Config::get_currency(),
            'i18n'     => [
                'loading' => __( 'Cargando...', 'ltms' ),
                'error'   => __( 'Ocurrió un error', 'ltms' ),
                'success' => __( '¡Éxito!', 'ltms' ),
                'copied'  => __( 'Copiado al portapapeles', 'ltms' ),
                'offline' => __( 'Sin conexión a internet', 'ltms' ),
                'online'  => __( 'Conexión restablecida', 'ltms' ),
            ],
        ] );
    }

    /**
     * Carga los assets del panel SPA del vendedor.
     *
     * @param string $url URL base de assets.
     * @param string $ver Versión para cache busting.
     * @return void
     */
    private function enqueue_dashboard_assets( string $url, string $ver, string $suffix = '' ): void {
        wp_enqueue_style( 'ltms-frontend-extensions', $url . 'css/ltms-frontend-extensions.css', [ 'ltms-dashboard' ], $ver );

        // Neutralizar el tema WP: el contenedor del dashboard ocupa todo el ancho sin márgenes del tema
        wp_add_inline_style( 'ltms-dashboard', '
            /* Neutralizar contenedor del tema para el dashboard del vendedor */
            .ltms-dashboard-container,
            #ltms-dashboard-container {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                float: none !important;
                box-sizing: border-box !important;
            }
            /* Neutralizar wrappers del tema que puedan tener max-width o padding */
            #ltms-dashboard-container .ltms-main-content,
            .ltms-dashboard-container .ltms-main-content {
                min-width: 0 !important;
            }
            /* Asegurar que el topbar fixed cubra todo el ancho en móvil */
            @media (max-width: 768px) {
                .ltms-topbar {
                    width: 100% !important;
                    max-width: 100% !important;
                    left: 0 !important;
                    right: 0 !important;
                }
            }
        ' );

        wp_enqueue_script(
            'ltms-modal',
            $url . 'js/ltms-modal' . $suffix . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_enqueue_script(
            'ltms-notifications',
            $url . 'js/ltms-notifications' . $suffix . '.js',
            [ 'jquery', 'ltms-modal' ],
            $ver,
            true
        );

        wp_enqueue_script(
            'chart-js',
            $url . 'js/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'ltms-dashboard',
            $url . 'js/ltms-dashboard' . $suffix . '.js',
            [ 'jquery', 'chart-js', 'ltms-modal', 'ltms-notifications' ],
            $ver,
            true
        );

        $this->localize_dashboard_script();
    }

    /**
     * Carga los assets del checkout con pasarela de pagos.
     *
     * @param string $url URL base.
     * @param string $ver Versión.
     * @return void
     */
    private function enqueue_checkout_assets( string $url, string $ver, string $suffix = '' ): void {
        $country = LTMS_Core_Config::get_country();

        // Openpay JS SDK
        if ( $country === 'CO' ) {
            wp_enqueue_script( 'openpay-js',   'https://resources.openpay.co/openpay.v1.min.js',      [], '1.0', true );
            wp_enqueue_script( 'openpay-data', 'https://resources.openpay.co/openpay-data.v1.min.js', [ 'openpay-js' ], '1.0', true );
        } elseif ( $country === 'MX' ) {
            wp_enqueue_script( 'openpay-js',   'https://js.openpay.mx/openpay.v1.min.js',      [], '1.0', true );
            wp_enqueue_script( 'openpay-data', 'https://js.openpay.mx/openpay-data.v1.min.js', [ 'openpay-js' ], '1.0', true );

            // ISSUE-009 confirmado: ltms-checkout-mexico solo carga en MX
            wp_enqueue_style( 'ltms-checkout-mexico', $url . 'css/ltms-checkout-mexico.css', [], $ver );
            wp_enqueue_script( 'ltms-checkout-mexico', $url . 'js/ltms-checkout-mexico' . $suffix . '.js', [ 'jquery', 'openpay-js' ], $ver, true );
        }

        wp_enqueue_script(
            'ltms-checkout',
            $url . 'js/ltms-checkout' . $suffix . '.js',
            [ 'jquery', 'openpay-js', 'openpay-data' ],
            $ver,
            true
        );

        $this->localize_checkout_script( $country );
    }

    /**
     * Carga Stripe Elements en el checkout cuando Stripe está habilitado (v1.7.0).
     *
     * @param string $url URL base.
     * @param string $ver Versión.
     * @return void
     */
    private function enqueue_stripe_assets( string $url, string $ver, string $suffix = '' ): void {
        // Stripe gateway stores settings in WooCommerce's option, not in standalone WP options.
        $stripe_settings = get_option( 'woocommerce_ltms_stripe_settings', [] );
        $is_testmode     = ( $stripe_settings['testmode'] ?? 'yes' ) === 'yes';
        $pub_key         = $is_testmode
            ? ( $stripe_settings['publishable_key'] ?? '' )
            : ( $stripe_settings['publishable_key_live'] ?? '' );

        if ( empty( $pub_key ) ) {
            return;
        }

        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

        wp_enqueue_script(
            'ltms-stripe',
            $url . 'js/ltms-stripe' . $suffix . '.js',
            [ 'jquery', 'stripe-js' ],
            $ver,
            true
        );

        wp_localize_script( 'ltms-stripe', 'ltmsStripe', [
            'publishable_key' => $pub_key,
            'i18n'            => [
                'card_error' => __( 'Error en los datos de la tarjeta.', 'ltms' ),
                'processing' => __( 'Procesando pago...', 'ltms' ),
            ],
        ] );
    }

    private function enqueue_shipping_selector( string $url, string $ver, string $suffix = '' ): void {
        wp_enqueue_script(
            'ltms-shipping-selector',
            $url . 'js/ltms-shipping-selector' . $suffix . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_localize_script( 'ltms-shipping-selector', 'ltmsShipping', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ltms_shipping_nonce' ),
            'i18n'     => [
                'compare_title' => __( 'Comparar Opciones de Envío', 'ltms' ),
                'loading'       => __( 'Cargando cotizaciones...', 'ltms' ),
                'unavailable'   => __( 'No disponible', 'ltms' ),
                'free'          => __( 'Gratis', 'ltms' ),
            ],
        ]);
    }

    /**
     * v3.1.0 — Cross-Border motor (Task 63-D): enqueues the currency
     * switcher widget JS + CSS on the checkout page.
     *
     * The widget lets the customer switch the display currency (COP, MXN,
     * USD, EUR, etc.) before placing the order. The JS calls the
     * `ltms_change_currency` AJAX endpoint to refresh the cart totals and
     * displays the new customs estimate based on the selected currency.
     *
     * @param string $url    Base assets URL.
     * @param string $ver    Plugin version (cache-busting).
     * @param string $suffix '.min' in production.
     * @return void
     */
    private function enqueue_currency_switcher( string $url, string $ver, string $suffix = '' ): void {
        // CSS: small styling for the switcher + customs estimate block.
        wp_enqueue_style(
            'ltms-currency-switcher',
            $url . 'css/ltms-currency-switcher.css',
            [],
            $ver
        );

        // JS: currency switch logic + customs estimate loader.
        wp_enqueue_script(
            'ltms-currency-switcher',
            $url . 'js/ltms-currency-switcher' . $suffix . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        // Localize the AJAX endpoint + nonce + i18n strings for the JS.
        wp_localize_script( 'ltms-currency-switcher', 'ltmsCurrencySwitcher', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ltms_checkout_nonce' ),
            'default_currency' => class_exists( 'LTMS_Currency_Manager' )
                ? LTMS_Currency_Manager::get_display_currency()
                : LTMS_Core_Config::get_currency(),
            'i18n' => [
                'converting'           => __( 'Convirtiendo…', 'ltms' ),
                'customs_loading'      => __( 'Calculando impuestos aduaneros…', 'ltms' ),
                'customs_title'        => __( 'Estimación de impuestos aduaneros', 'ltms' ),
                'ddp_paid_at_checkout' => __( 'Duties paid at checkout (DDP) — included in your total.', 'ltms' ),
                'ddu_payable_on_delivery' => __( 'Duties payable on delivery (DDU) — not included in your total.', 'ltms' ),
                'below_de_minimis'     => __( 'Below de minimis threshold — no duties apply.', 'ltms' ),
                'domestic_no_duties'   => __( 'Domestic shipment — no customs duties apply.', 'ltms' ),
                'duty_label'           => __( 'Import duty', 'ltms' ),
                'vat_label'            => __( 'VAT/GST', 'ltms' ),
                'fee_label'            => __( 'Customs fee', 'ltms' ),
                'total_label'          => __( 'Total duties + taxes', 'ltms' ),
                'rate_label'           => __( 'FX rate', 'ltms' ),
                'error_generic'        => __( 'Error al actualizar la moneda. Intenta de nuevo.', 'ltms' ),
            ],
        ]);
    }

    /**
     * Carga el Kitchen Display System (KDS) — solo cuando tab=kds.
     * Bug M-21: el script ltms-kds.js nunca fue enqueued ni localizado.
     *
     * @param string $url    URL base de assets.
     * @param string $ver    Versión para cache busting.
     * @param string $suffix '.min' en producción.
     * @return void
     */
    private function enqueue_kds_assets( string $url, string $ver, string $suffix = '' ): void {
        wp_enqueue_style( 'ltms-kds', $url . 'css/ltms-kds.css', [ 'ltms-dashboard' ], $ver );

        wp_enqueue_script(
            'ltms-kds',
            $url . 'js/ltms-kds' . $suffix . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        $vendor_id = 0;
        if ( is_user_logged_in() ) {
            $vendor_id = (int) get_user_meta( get_current_user_id(), '_ltms_vendor_id', true );
            if ( ! $vendor_id ) {
                $vendor_id = get_current_user_id();
            }
        }

        wp_localize_script( 'ltms-kds', 'ltmsKds', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ltms_dashboard_nonce' ),
            'vendor_id'     => $vendor_id,
            'poll_interval' => 10000, // AUDIT-RESTAURANT-ENGINE: 10s (era 15s — too slow for kitchen).
            'alert_sound'   => $url . 'sounds/new-order.mp3', // AUDIT-RESTAURANT-ENGINE: dynamic path.
            'i18n'          => [
                'loading'        => __( 'Cargando pedidos...', 'ltms' ),
                'no_orders'      => __( 'No hay pedidos activos', 'ltms' ),
                'error_loading'  => __( 'Error al cargar pedidos. Reintentando...', 'ltms' ),
                'status_updated' => __( 'Estado actualizado', 'ltms' ),
                'new_order'      => __( '¡Nuevo pedido!', 'ltms' ),
            ],
        ]);
    }

    private function enqueue_auth_assets( string $url, string $ver, string $suffix = '' ): void {
        wp_enqueue_style( 'ltms-login-register', $url . 'css/ltms-login-register.css', [], $ver );
        wp_enqueue_script(
            'ltms-login-register',
            $url . 'js/ltms-login-register' . $suffix . '.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_localize_script( 'ltms-login-register', 'ltmsAuth', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ltms_auth_nonce' ),
            'i18n'     => [
                'password_mismatch' => __( 'Las contraseñas no coinciden.', 'ltms' ),
                'required_fields'   => __( 'Por favor completa todos los campos requeridos.', 'ltms' ),
                'processing'        => __( 'Procesando...', 'ltms' ),
            ],
        ]);
    }

    /**
     * Localiza los datos del dashboard SPA para JavaScript.
     *
     * @return void
     */
    private function localize_dashboard_script(): void {
        $user_id = get_current_user_id();
        $wallet  = $user_id ? LTMS_Business_Wallet::get_or_create( $user_id ) : null;

        wp_localize_script( 'ltms-dashboard', 'ltmsDashboard', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'rest_url'      => rest_url( 'ltms/v1' ),
            'nonce'         => wp_create_nonce( 'ltms_dashboard_nonce' ),
            // M-BOOKING-UI-02: nonce dedicado para la descarga GET de exportación
            // CSV de reservas (check_admin_referer exige acción exacta, distinta
            // del nonce AJAX genérico de arriba).
            'export_nonce'  => wp_create_nonce( 'ltms_export_vendor_bookings' ),
            'currency'      => LTMS_Core_Config::get_currency(),
            'country'       => LTMS_Core_Config::get_country(),
            'user_id'       => $user_id,
            'wallet_balance' => $wallet ? (float) $wallet['balance'] : 0,
            'version'       => LTMS_VERSION,
            'polling_interval' => 30000,
            'logout_url'    => wp_logout_url( home_url( '/login-vendedor/' ) ), // 30 segundos para notificaciones
            'add_product_url' => admin_url( 'post-new.php?post_type=product' ),
            'kyc_url'         => home_url( '/verificacion-identidad/' ),
            'i18n'          => [
                'loading'      => __( 'Cargando...', 'ltms' ),
                'error'        => __( 'Error al cargar datos', 'ltms' ),
                'confirm_payout' => __( '¿Confirmas la solicitud de retiro?', 'ltms' ),
                'success'      => __( '¡Operación exitosa!', 'ltms' ),
            ],
            'redi_min_rate'  => (float) get_option( 'ltms_redi_min_rate', 5 ),
            'redi_max_rate'  => (float) get_option( 'ltms_redi_max_rate', 40 ),
        ]);
    }

    /**
     * Localiza los datos del checkout para JavaScript.
     *
     * @param string $country Código de país.
     * @return void
     */
    private function localize_checkout_script( string $country ): void {
        // M-MX-2: buscar keys por país primero, luego fallback genérico
        $merchant_id = LTMS_Core_Config::get( "ltms_openpay_{$country}_merchant_id" )
                    ?: LTMS_Core_Config::get( 'ltms_openpay_merchant_id', '' );
        $public_key  = LTMS_Core_Config::get( "ltms_openpay_{$country}_public_key" )
                    ?: LTMS_Core_Config::get( 'ltms_openpay_public_key', '' );
        $is_sandbox  = LTMS_ENVIRONMENT !== 'production';

        // M-23: ltms-checkout-mexico.js necesita order_total y order_id para OXXO/SPEI/MSI
        $cart_total = 0.0;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $cart_total = (float) WC()->cart->get_total( 'edit' );
        }
        $order_id = absint( get_query_var( 'order-pay' ) );
        if ( ! $order_id && function_exists( 'WC' ) && WC()->session ) {
            $order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
        }

        // M-MX-2: pasar los mismos datos al script de checkout MX
        if ( $country === 'MX' ) {
            wp_localize_script( 'ltms-checkout-mexico', 'ltmsCheckout', [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'ltms_checkout_nonce' ),
                'merchant_id' => esc_attr( $merchant_id ),
                'public_key'  => esc_attr( $public_key ),
                'is_sandbox'  => $is_sandbox,
                'country'     => $country,
                'currency'    => 'MXN',
                'order_total' => $cart_total,
                'order_id'    => $order_id,
            ]);
        }

        wp_localize_script( 'ltms-checkout', 'ltmsCheckout', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'ltms_checkout_nonce' ),
            'merchant_id'  => esc_attr( $merchant_id ),
            'public_key'   => esc_attr( $public_key ),
            'is_sandbox'   => $is_sandbox,
            'country'      => $country,
            'currency'     => LTMS_Core_Config::get_currency(),
            'order_total'  => $cart_total,
            'order_id'     => $order_id,
            'pse_enabled'  => $country === 'CO' && LTMS_Core_Config::get( 'ltms_pse_enabled', 'yes' ) === 'yes',
            'addi_enabled' => LTMS_Core_Config::get( 'ltms_addi_enabled', 'no' ) === 'yes',
            'i18n'         => [
                'card_error'      => __( 'Error al tokenizar la tarjeta. Verifica los datos.', 'ltms' ),
                'processing'      => __( 'Procesando pago...', 'ltms' ),
                'payment_success' => __( '¡Pago exitoso!', 'ltms' ),
                'payment_error'   => __( 'Error al procesar el pago. Intenta de nuevo.', 'ltms' ),
            ],
        ]);
    }

    /**
     * Inyecta las meta-tags para PWA en el head.
     *
     * @return void
     */
    public function inject_pwa_tags(): void {
        if ( ! $this->is_ltms_page( get_queried_object_id(), $this->get_installed_pages() ) ) {
            return;
        }

        $manifest_url = LTMS_ASSETS_URL . 'json/manifest.json';
        echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
        echo '<meta name="theme-color" content="#1a5276">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    }

    /**
     * Inyecta datos en el footer (variables globales para SW, etc.).
     *
     * @return void
     */
    public function inject_localized_data(): void {
        if ( ! $this->is_ltms_page( get_queried_object_id(), $this->get_installed_pages() ) ) {
            return;
        }

        $sw_url = LTMS_PLUGIN_URL . 'sw.js';
        echo '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("' . esc_url( $sw_url ) . '").catch(()=>{});}</script>' . "\n";
    }

    /**
     * Verifica si la página actual es una página de LTMS.
     *
     * @param int   $page_id ID de la página.
     * @param array $pages   Array de páginas instaladas.
     * @return bool
     */
    private function is_ltms_page( int $page_id, array $pages ): bool {
        // Detección primaria: por ID de página registrada en ltms_installed_pages.
        if ( in_array( $page_id, array_map( 'intval', $pages ), true ) ) {
            return true;
        }

        // M-121 FIX: Fallback por shortcode — si la página actual contiene cualquier
        // shortcode LTMS, cargamos los assets aunque el page_id no coincida con
        // ltms_installed_pages (puede pasar si se reinstalaron páginas con nuevos IDs).
        if ( $page_id > 0 ) {
            $post = get_post( $page_id );
            if ( $post && has_shortcode( $post->post_content, 'ltms_vendor_login' ) ) {
                return true;
            }
            if ( $post ) {
                $ltms_shortcodes = [
                    'ltms_vendor_dashboard', 'ltms_vendor_login', 'ltms_vendor_register',
                    'ltms_vendor_store', 'ltms_vendor_orders', 'ltms_vendor_wallet',
                    'ltms_vendor_kyc', 'ltms_vendor_insurance', 'ltms_vendor_redi',
                    'ltms_sellers_landing', // /sellers/ page
                ];
                foreach ( $ltms_shortcodes as $sc ) {
                    if ( has_shortcode( $post->post_content, $sc ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Obtiene el array de páginas instaladas por LTMS.
     *
     * @return array
     */
    private function get_installed_pages(): array {
        $pages = get_option( 'ltms_installed_pages', [] );
        return is_array( $pages ) ? $pages : [];
    }
}
