<?php
/**
 * LTMS Public Auth Handler - Autenticación Pública de Vendedores
 *
 * Maneja el registro, login y gestión de sesión de vendedores en el frontend:
 * - Formulario de registro de vendedor con validación
 * - Login personalizado sin redirigir al wp-admin
 * - Recuperación de contraseña
 * - Verificación de email
 * - Integración con KYC y ZapSign para contrato de adhesión
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Public_Auth_Handler
 */
final class LTMS_Public_Auth_Handler {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks de WordPress.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        // Shortcodes
        add_shortcode( 'ltms_vendor_login',    [ $instance, 'render_login_form' ] );
        add_shortcode( 'ltms_vendor_register', [ $instance, 'render_register_form' ] );

        // AJAX handlers (no autenticados para login/registro)
        add_action( 'wp_ajax_nopriv_ltms_vendor_login',    [ $instance, 'ajax_vendor_login' ] );
        add_action( 'wp_ajax_nopriv_ltms_vendor_register', [ $instance, 'ajax_vendor_register' ] );
        add_action( 'wp_ajax_ltms_vendor_logout',          [ $instance, 'ajax_vendor_logout' ] );

        // Redirigir vendors lejos del wp-admin
        add_action( 'admin_init', [ $instance, 'redirect_vendor_from_admin' ] );

        // Filtrar login de WP para vendors (redirigir a dashboard)
        add_filter( 'login_redirect', [ $instance, 'vendor_login_redirect' ], 10, 3 );
    }

    /**
     * Renderiza el formulario de login del vendedor.
     *
     * @param array $atts Atributos del shortcode.
     * @return string
     */
    public function render_login_form( array $atts = [] ): string {
        if ( is_user_logged_in() ) {
            return $this->render_already_logged_in();
        }

        ob_start();
        $view = LTMS_INCLUDES_DIR . 'frontend/views/vendor-parts/form-login.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
        return ob_get_clean();
    }

    /**
     * Renderiza el formulario de registro del vendedor.
     *
     * @param array $atts Atributos del shortcode.
     * @return string
     */
    public function render_register_form( array $atts = [] ): string {
        add_action( 'wp_footer', function() {
            $nonce    = wp_create_nonce( 'ltms_auth_nonce' );
            $ajax_url = admin_url( 'admin-ajax.php' );
            echo '<link rel="stylesheet" href="' . esc_url( LTMS_ASSETS_URL . 'css/ltms-login-register.css?ver=' . LTMS_VERSION ) . '">';
            echo '<script>var ltmsAuth = ' . wp_json_encode([
                'ajax_url' => $ajax_url,
                'nonce'    => $nonce,
                'i18n'     => [
                    'password_mismatch' => 'Las contraseñas no coinciden.',
                    'required_fields'   => 'Por favor completa todos los campos requeridos.',
                    'processing'        => 'Procesando...',
                ],
            ]) . ';</script>';
            echo '<script src="' . esc_url( LTMS_ASSETS_URL . 'js/ltms-login-register.js?ver=' . LTMS_VERSION ) . '"></script>';
        }, 99 );
        if ( is_user_logged_in() ) {
            return $this->render_already_logged_in();
        }

        ob_start();
        $view = LTMS_INCLUDES_DIR . 'frontend/views/vendor-parts/form-register.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
        return ob_get_clean();
    }

    /**
     * AJAX: Procesa el login del vendedor.
     *
     * @return void
     */
    public function ajax_vendor_login(): void {
        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        // Throttle: máximo 5 intentos por IP en 15 minutos
        $ip     = LTMS_Utils::get_ip();
        $key    = 'ltms_login_attempts_' . md5( $ip );
        $tries  = (int) get_transient( $key );

        if ( $tries >= 5 ) {
            LTMS_Core_Logger::security(
                'LOGIN_THROTTLE',
                sprintf( 'IP %s bloqueada por demasiados intentos de login', $ip )
            );
            wp_send_json_error( __( 'Demasiados intentos. Espera 15 minutos.', 'ltms' ), 429 );
        }

        $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) ); // phpcs:ignore
        $password = $_POST['password'] ?? ''; // phpcs:ignore (password sin sanitizar)

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( __( 'Usuario y contraseña son requeridos.', 'ltms' ) );
        }

        $user = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => ! empty( $_POST['remember'] ), // phpcs:ignore
        ]);

        if ( is_wp_error( $user ) ) {
            set_transient( $key, $tries + 1, 900 ); // 15 minutos
            wp_send_json_error( __( 'Usuario o contraseña incorrectos.', 'ltms' ) );
        }

        delete_transient( $key );

        $pages       = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $redirect    = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

        wp_send_json_success([
            'redirect' => $redirect,
            'message'  => __( 'Bienvenido de vuelta.', 'ltms' ),
        ]);
    }

    /**
     * AJAX: Procesa el registro de un nuevo vendedor.
     *
     * @return void
     */
    public function ajax_vendor_register(): void {
        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        $data = [
            'first_name'     => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), // phpcs:ignore
            'last_name'      => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), // phpcs:ignore
            'email'          => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), // phpcs:ignore
            'password'       => $_POST['password'] ?? '', // phpcs:ignore
            'confirm_pass'   => $_POST['confirm_password'] ?? '', // phpcs:ignore
            'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), // phpcs:ignore
            'store_name'     => sanitize_text_field( wp_unslash( $_POST['store_name'] ?? '' ) ), // phpcs:ignore
            'document'       => sanitize_text_field( wp_unslash( $_POST['document'] ?? '' ) ), // phpcs:ignore
            'referral_code'  => sanitize_text_field( wp_unslash( $_POST['referral_code'] ?? '' ) ), // phpcs:ignore
            'terms_accepted' => ! empty( $_POST['terms_accepted'] ), // phpcs:ignore
        ];

        // Validaciones
        $errors = $this->validate_registration( $data );
        if ( ! empty( $errors ) ) {
            wp_send_json_error( implode( ' ', $errors ) );
        }

        // Verificar que el email no esté registrado
        if ( email_exists( $data['email'] ) ) {
            wp_send_json_error( __( 'Este email ya está registrado.', 'ltms' ) );
        }

        // Crear usuario
        $username = $this->generate_username( $data['first_name'], $data['last_name'] );
        $user_id  = wp_create_user( $username, $data['password'], $data['email'] );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }

        // Asignar rol de vendedor
        $user = new \WP_User( $user_id );
        $user->set_role( 'ltms_vendor' );

        // Guardar meta del vendedor
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $data['first_name'],
            'last_name'    => $data['last_name'],
            'display_name' => $data['first_name'] . ' ' . $data['last_name'],
        ]);

        update_user_meta( $user_id, 'ltms_store_name', $data['store_name'] );
        update_user_meta( $user_id, 'ltms_phone', LTMS_Utils::format_phone_e164( $data['phone'] ) );
        update_user_meta( $user_id, 'ltms_document', LTMS_Core_Security::encrypt( $data['document'] ) );
        update_user_meta( $user_id, 'ltms_kyc_status', 'pending' );
        update_user_meta( $user_id, 'ltms_terms_accepted_at', LTMS_Utils::now_utc() );
        update_user_meta( $user_id, 'ltms_referral_code', LTMS_Core_Security::generate_referral_code() );

        // Registrar en la red de referidos si hay código patrocinador
        if ( $data['referral_code'] && class_exists( 'LTMS_Referral_Tree' ) ) {
            LTMS_Referral_Tree::register_node( $user_id, $data['referral_code'] );
        }

        // Crear billetera inicial
        LTMS_Business_Wallet::get_or_create( $user_id );

        // Registrar en TPTC si está habilitado
        if ( LTMS_Core_Config::get( 'ltms_tptc_enabled', 'no' ) === 'yes' && class_exists( 'LTMS_Api_Factory' ) ) {
            try {
                $tptc = LTMS_Api_Factory::get( 'tptc' );
                $tptc->register_affiliate([
                    'vendor_id'   => $user_id,
                    'first_name'  => $data['first_name'],
                    'last_name'   => $data['last_name'],
                    'email'       => $data['email'],
                    'phone'       => $data['phone'],
                    'document'    => $data['document'],
                    'sponsor_code' => $data['referral_code'],
                ]);
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'TPTC_REGISTER_FAILED', $e->getMessage() );
            }
        }

        // Login automático
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, false );

        LTMS_Core_Logger::info(
            'VENDOR_REGISTERED',
            sprintf( 'Nuevo vendedor registrado: #%d (%s)', $user_id, $data['email'] ),
            [ 'user_id' => $user_id ]
        );

        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $redirect     = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

        wp_send_json_success([
            'redirect' => $redirect,
            'message'  => __( '¡Registro exitoso! Bienvenido a la plataforma.', 'ltms' ),
        ]);
    }

    /**
     * AJAX: Cierra la sesión del vendedor.
     *
     * @return void
     */
    public function ajax_vendor_logout(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        wp_logout();

        $pages    = get_option( 'ltms_installed_pages', [] );
        $login_id = $pages['ltms-login'] ?? 0;
        $redirect = $login_id ? get_permalink( $login_id ) : home_url();

        wp_send_json_success( [ 'redirect' => $redirect ] );
    }

    /**
     * Redirige a los vendors lejos del wp-admin.
     *
     * @return void
     */
    public function redirect_vendor_from_admin(): void {
        if ( ! is_user_logged_in() || wp_doing_ajax() ) {
            return;
        }

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        if ( in_array( 'ltms_vendor', (array) $user->roles, true ) ||
             in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {

            $pages        = get_option( 'ltms_installed_pages', [] );
            $dashboard_id = $pages['ltms-dashboard'] ?? 0;
            $redirect     = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

            wp_safe_redirect( $redirect );
            exit;
        }
    }

    /**
     * Redirige a los vendors al dashboard tras login en wp-login.php.
     *
     * @param string           $redirect_to URL de redirección.
     * @param string           $requested   URL solicitada.
     * @param \WP_User|\WP_Error $user      Usuario o error.
     * @return string
     */
    public function vendor_login_redirect( string $redirect_to, string $requested, $user ): string {
        if ( ! ( $user instanceof \WP_User ) ) {
            return $redirect_to;
        }

        if ( in_array( 'ltms_vendor', (array) $user->roles, true ) ||
             in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {

            $pages        = get_option( 'ltms_installed_pages', [] );
            $dashboard_id = $pages['ltms-dashboard'] ?? 0;
            return $dashboard_id ? get_permalink( $dashboard_id ) : home_url();
        }

        return $redirect_to;
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Valida los datos de registro del vendedor.
     *
     * @param array $data Datos del formulario.
     * @return string[] Array de mensajes de error.
     */
    private function validate_registration( array $data ): array {
        $errors = [];

        if ( empty( $data['first_name'] ) ) {
            $errors[] = __( 'El nombre es requerido.', 'ltms' );
        }
        if ( empty( $data['last_name'] ) ) {
            $errors[] = __( 'El apellido es requerido.', 'ltms' );
        }
        if ( ! is_email( $data['email'] ) ) {
            $errors[] = __( 'Email inválido.', 'ltms' );
        }
        if ( strlen( $data['password'] ) < 8 ) {
            $errors[] = __( 'La contraseña debe tener al menos 8 caracteres.', 'ltms' );
        }
        if ( $data['password'] !== $data['confirm_pass'] ) {
            $errors[] = __( 'Las contraseñas no coinciden.', 'ltms' );
        }
        if ( empty( $data['store_name'] ) ) {
            $errors[] = __( 'El nombre de tu tienda es requerido.', 'ltms' );
        }
        if ( empty( $data['document'] ) ) {
            $errors[] = __( 'El número de documento es requerido.', 'ltms' );
        }
        if ( ! $data['terms_accepted'] ) {
            $errors[] = __( 'Debes aceptar los términos y condiciones.', 'ltms' );
        }

        return $errors;
    }

    /**
     * Genera un nombre de usuario único a partir del nombre.
     *
     * @param string $first_name Nombre.
     * @param string $last_name  Apellido.
     * @return string
     */
    private function generate_username( string $first_name, string $last_name ): string {
        $base     = sanitize_user( strtolower( $first_name . $last_name ), true );
        $username = $base;
        $counter  = 1;

        while ( username_exists( $username ) ) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Renderiza mensaje para usuario ya autenticado.
     *
     * @return string
     */
    private function render_already_logged_in(): string {
        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $url          = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

        return sprintf(
            '<div class="ltms-notice ltms-notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'Ya tienes sesión iniciada.', 'ltms' ),
            esc_url( $url ),
            esc_html__( 'Ir al panel', 'ltms' )
        );
    }
}
