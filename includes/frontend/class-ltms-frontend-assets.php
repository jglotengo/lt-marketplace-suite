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
        add_action( 'wp_head',            [ $instance, 'inject_pwa_tags' ] );
        add_action( 'wp_footer',          [ $instance, 'inject_localized_data' ] );
    }

    /**
     * Carga los assets según la página actual del frontend.
     *
     * @return void
     */
    public function enqueue_frontend_assets(): void {
        $ver     = LTMS_VERSION;
        $url     = LTMS_ASSETS_URL;
        $page_id = get_queried_object_id();
        $pages   = $this->get_installed_pages();

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

        // Dashboard del vendedor (SPA)
        if ( $page_id === (int) ( $pages['ltms-dashboard'] ?? 0 ) ) {
            $this->enqueue_dashboard_assets( $url, $ver );
        }

        // Checkout / Pasarela de pagos
        if ( is_checkout() || $page_id === (int) ( $pages['ltms-store'] ?? 0 ) ) {
            $this->enqueue_checkout_assets( $url, $ver );
            $this->enqueue_shipping_selector( $url, $ver );
        }

        // Login y Registro de vendedores
        if ( $page_id === (int) ( $pages['ltms-login'] ?? 0 ) ||
             $page_id === (int) ( $pages['ltms-register'] ?? 0 ) ) {
            $this->enqueue_auth_assets( $url, $ver );
        }
    }

    /**
     * Carga los assets del panel SPA del vendedor.
     *
     * @param string $url URL base de assets.
     * @param string $ver Versión para cache busting.
     * @return void
     */
    private function enqueue_dashboard_assets( string $url, string $ver ): void {
        wp_enqueue_style( 'ltms-frontend-extensions', $url . 'css/ltms-frontend-extensions.css', [ 'ltms-dashboard' ], $ver );

        wp_enqueue_script(
            'ltms-modal',
            $url . 'js/ltms-modal.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_enqueue_script(
            'ltms-notifications',
            $url . 'js/ltms-notifications.js',
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
            $url . 'js/ltms-dashboard.js',
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
    private function enqueue_checkout_assets( string $url, string $ver ): void {
        $country = LTMS_Core_Config::get_country();

        // Openpay JS SDK
        if ( $country === 'CO' ) {
            wp_enqueue_script(
                'openpay-js',
                'https://js.openpay.co/openpay.v1.min.js',
                [],
                '1.0',
                true
            );
            wp_enqueue_script(
                'openpay-data',
                'https://js.openpay.co/openpay-data.v1.min.js',
                [ 'openpay-js' ],
                '1.0',
                true
            );
        } elseif ( $country === 'MX' ) {
            wp_enqueue_script(
                'openpay-js',
                'https://js.openpay.mx/openpay.v1.min.js',
                [],
                '1.0',
                true
            );
            wp_enqueue_script(
                'openpay-data',
                'https://js.openpay.mx/openpay-data.v1.min.js',
                [ 'openpay-js' ],
                '1.0',
                true
            );

            wp_enqueue_style( 'ltms-checkout-mexico', $url . 'css/ltms-checkout-mexico.css', [], $ver );
            wp_enqueue_script( 'ltms-checkout-mexico', $url . 'js/ltms-checkout-mexico.js', [ 'jquery', 'openpay-js' ], $ver, true );
        }

        wp_enqueue_script(
            'ltms-checkout',
            $url . 'js/ltms-checkout.js',
            [ 'jquery', 'openpay-js', 'openpay-data' ],
            $ver,
            true
        );

        $this->localize_checkout_script( $country );
    }

    /**
     * Carga el selector de envío en la página de checkout (v1.6.0).
     *
     * @param string $url URL base.
     * @param string $ver Versión.
     * @return void
     */
    private function enqueue_shipping_selector( string $url, string $ver ): void {
        wp_enqueue_script(
            'ltms-shipping-selector',
            $url . 'js/ltms-shipping-selector.js',
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
     * Carga los assets de login/registro de vendedores.
     *
     * @param string $url URL base.
     * @param string $ver Versión.
     * @return void
     */
    private function enqueue_auth_assets( string $url, string $ver ): void {
        wp_enqueue_style( 'ltms-login-register', $url . 'css/ltms-login-register.css', [], $ver );
        wp_enqueue_script(
            'ltms-login-register',
            $url . 'js/ltms-login-register.js',
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
        $wallet  = $user_id ? LTMS_Wallet::get_or_create( $user_id ) : null;

        wp_localize_script( 'ltms-dashboard', 'ltmsDashboard', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'rest_url'      => rest_url( 'ltms/v1' ),
            'nonce'         => wp_create_nonce( 'ltms_dashboard_nonce' ),
            'currency'      => LTMS_Core_Config::get_currency(),
            'country'       => LTMS_Core_Config::get_country(),
            'user_id'       => $user_id,
            'wallet_balance' => $wallet ? (float) $wallet['balance'] : 0,
            'version'       => LTMS_VERSION,
            'polling_interval' => 30000, // 30 segundos para notificaciones
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

        wp_localize_script( 'ltms-checkout', 'ltmsCheckout', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'ltms_checkout_nonce' ),
            'merchant_id'  => esc_attr( $merchant_id ),
            'public_key'   => esc_attr( $public_key ),
            'is_sandbox'   => $is_sandbox,
            'country'      => $country,
            'currency'     => LTMS_Core_Config::get_currency(),
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
        return in_array( $page_id, array_map( 'intval', $pages ), true );
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
