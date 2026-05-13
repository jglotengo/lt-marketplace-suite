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
        add_action( 'wp_head',            [ $instance, 'inject_pwa_tags' ] );
        add_action( 'wp_footer',          [ $instance, 'inject_localized_data' ] );
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

        // Inyectar CSS de posicionamiento para forzar visibilidad del botón flotante
        wp_add_inline_style( 'ltms-header-nav', '
            #ltms-floating-access {
                position: fixed;
                top: 12px;
                right: 16px;
                z-index: 99999;
                display: flex;
                gap: 8px;
                align-items: center;
            }
            @media (max-width: 600px) {
                #ltms-floating-access { top: 8px; right: 8px; }
            }
        ' );
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
        $vendor_panel_pages = [ 'ltms-dashboard', 'ltms-orders', 'ltms-wallet', 'ltms-kyc', 'ltms-insurance' ];
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
            $dashboard_shortcodes = [ 'ltms_vendor_dashboard', 'ltms_vendor_orders', 'ltms_vendor_wallet', 'ltms_vendor_kyc', 'ltms_vendor_insurance', 'ltms_vendor_store' ];
            if ( $post ) {
                foreach ( $dashboard_shortcodes as $sc ) {
                    if ( has_shortcode( $post->post_content, $sc ) ) {
                        $is_vendor_panel = true;
                        break;
                    }
                }
            }
        }
        if ( $is_vendor_panel ) {
            $this->enqueue_dashboard_assets( $url, $ver, $suffix );
        }

        // KDS — Kitchen Display System (tab=kds dentro del dashboard)
        if ( $page_id === (int) ( $pages['ltms-dashboard'] ?? 0 ) &&
             isset( $_GET['tab'] ) && sanitize_key( $_GET['tab'] ) === 'kds' ) {
            $this->enqueue_kds_assets( $url, $ver, $suffix );
        }

        // Checkout / Pasarela de pagos
        if ( is_checkout() || $page_id === (int) ( $pages['ltms-store'] ?? 0 ) ) {
            $this->enqueue_checkout_assets( $url, $ver, $suffix );
            $this->enqueue_shipping_selector( $url, $ver, $suffix );
            $this->enqueue_stripe_assets( $url, $ver, $suffix );
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
        if ( $is_auth_page ) {
            $this->enqueue_auth_assets( $url, $ver, $suffix );
        }
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
            'https://cdn.jsdelivr.net/npm/chart.js@4.4/dist/chart.umd.min.js',
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
            wp_enqueue_script( 'openpay-js',   'https://js.openpay.co/openpay.v1.min.js',      [], '1.0', true );
            wp_enqueue_script( 'openpay-data', 'https://js.openpay.co/openpay-data.v1.min.js', [ 'openpay-js' ], '1.0', true );
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
            'poll_interval' => 15000,
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
        ]);
    }

    /**
     * Localiza los datos del checkout para JavaScript.
     *
     * @param string $country Código de país.
     * @return void
     */
    private function localize_checkout_script( string $country ): void {
        $merchant_id = LTMS_Core_Config::get( 'ltms_openpay_merchant_id', '' );
        $public_key  = LTMS_Core_Config::get( 'ltms_openpay_public_key', '' );
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
