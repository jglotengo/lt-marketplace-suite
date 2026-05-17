<?php
/**
 * LTMS Public Auth Handler - Autenticación Pública de Vendedores
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Public_Auth_Handler {

    use LTMS_Logger_Aware;

    /** Máximo de cuentas que una IP puede crear en la ventana de tiempo. */
    private const REGISTER_MAX_PER_IP = 3;

    /** Ventana del rate limit en segundos (1h). */
    private const REGISTER_WINDOW = HOUR_IN_SECONDS;

    /** Vigencia del token de verificación de email en segundos (48h). */
    private const EMAIL_VERIFY_TTL = 48 * HOUR_IN_SECONDS;

    public static function init(): void {
        $instance = new self();

        // Shortcodes
        add_shortcode( 'ltms_vendor_login',     [ $instance, 'render_login_form' ] );
        add_shortcode( 'ltms_vendor_register',  [ $instance, 'render_register_form' ] );
        add_shortcode( 'ltms_sellers_landing',  [ $instance, 'render_sellers_landing' ] );

        // AJAX handlers. M-57: registrar también la variante priv para que admins
        // (y otros roles no-vendor) puedan probar el flujo desde wp-admin sin obtener
        // 0/HTTP-200 que el JS interpreta como "Error de conexión".
        add_action( 'wp_ajax_nopriv_ltms_vendor_login',    [ $instance, 'ajax_vendor_login' ] );
        add_action( 'wp_ajax_ltms_vendor_login',           [ $instance, 'ajax_vendor_login' ] );
        add_action( 'wp_ajax_nopriv_ltms_vendor_register', [ $instance, 'ajax_vendor_register' ] );
        add_action( 'wp_ajax_ltms_vendor_register',        [ $instance, 'ajax_vendor_register' ] );
        add_action( 'wp_ajax_ltms_vendor_logout',          [ $instance, 'ajax_vendor_logout' ] );

        // M-73: /sellers/ usa Hello Elementor — Elementor no llama the_content() y el
        // shortcode no se renderiza. Interceptamos template_include con prioridad 999
        // para servir nuestro propio template cuando la página contiene ltms_sellers_landing.
        add_filter( 'template_include', [ $instance, 'maybe_serve_sellers_template' ], 999 );

        // Listener de verificación de email (?ltms_verify_email=<token>&uid=<id>)
        add_action( 'init', [ $instance, 'handle_email_verification' ], 20 );

        // Redirigir vendors lejos del wp-admin
        add_action( 'admin_init', [ $instance, 'redirect_vendor_from_admin' ] );

        // Filtrar login de WP para vendors (redirigir a dashboard)
        add_filter( 'login_redirect', [ $instance, 'vendor_login_redirect' ], 10, 3 );
    }

    /**
     * Shortcode [ltms_sellers_landing] — Landing page para captar vendedores.
     * Se usa en la página /sellers/ para convertir visitantes en vendedores.
     *
     * @return string HTML de la landing.
     */
    public function render_sellers_landing( array $atts = [] ): string {
        ob_start();
        $view = LTMS_PLUGIN_DIR . 'includes/frontend/views/view-sellers-landing.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
        return ob_get_clean();
    }

    /**
     * M-73: Sirve un template propio para páginas LTMS cuando el tema (Hello Elementor)
     * no llama the_content() y los shortcodes no se renderizan.
     *
     * Aplica a: [ltms_sellers_landing], [ltms_vendor_register], [ltms_vendor_login]
     *
     * @param string $template Template seleccionado por WordPress.
     * @return string Template a usar.
     */
    public function maybe_serve_sellers_template( string $template ): string {
        if ( ! is_singular() ) {
            return $template;
        }
        $post = get_queried_object();
        if ( ! $post ) {
            return $template;
        }

        $ltms_shortcodes = [
            'ltms_sellers_landing',
            'ltms_vendor_register',
            'ltms_vendor_login',
            'ltms_vendor_dashboard',
        ];

        $needs_bypass = false;
        foreach ( $ltms_shortcodes as $sc ) {
            if ( has_shortcode( $post->post_content, $sc ) ) {
                $needs_bypass = true;
                break;
            }
        }

        if ( ! $needs_bypass ) {
            return $template;
        }

        $custom = LTMS_PLUGIN_DIR . 'includes/frontend/views/template-sellers-page.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
        return $template;
    }

    /**
     * Renderiza el formulario de inicio de sesión para vendedores.
     */
    public function render_login_form( array $atts = [] ): string {
        // Solo bloquear si el user actual ya es vendor (no necesita login). Admins
        // y otros roles pueden ver el form (útil para QA, soporte, demo).
        if ( is_user_logged_in() && $this->current_user_is_vendor() ) {
            return $this->render_already_logged_in();
        }

        ob_start();
        $view = LTMS_INCLUDES_DIR . 'frontend/views/vendor-parts/form-login.php';
        if ( file_exists( $view ) ) {
            include $view;
        } else {
            // Mensaje explícito si el template no se encontró — evita "página en blanco" silenciosa.
            echo '<div class="ltms-notice ltms-notice-error"><p>'
                . esc_html__( 'Error: plantilla de login no encontrada. Contacta al soporte.', 'ltms' )
                . '</p></div>';
        }
        return ob_get_clean();
    }

    public function render_register_form( array $atts = [] ): string {
        // M-56: si el user ya es vendor → notice + link al panel (no tiene sentido
        // re-registrarse). Si es admin u otro rol, mostrar el form igual.
        if ( is_user_logged_in() && $this->current_user_is_vendor() ) {
            return $this->render_already_logged_in();
        }

        ob_start();
        $view = LTMS_INCLUDES_DIR . 'frontend/views/vendor-parts/form-register.php';
        if ( file_exists( $view ) ) {
            include $view;
        } else {
            echo '<div class="ltms-notice ltms-notice-error"><p>'
                . esc_html__( 'Error: plantilla de registro no encontrada. Contacta al soporte.', 'ltms' )
                . '</p></div>';
        }
        return ob_get_clean();
    }

    /**
     * Devuelve true solo si el usuario actual tiene rol ltms_vendor o ltms_vendor_premium.
     * Admins/editores NO cuentan como vendor — pueden ver los formularios.
     */
    private function current_user_is_vendor(): bool {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }
        $roles = (array) $user->roles;
        return in_array( 'ltms_vendor', $roles, true ) || in_array( 'ltms_vendor_premium', $roles, true );
    }

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
        $password = wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( __( 'Usuario y contraseña son requeridos.', 'ltms' ) );
        }

        $user = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => ! empty( $_POST['remember'] ), // phpcs:ignore
        ]);

        if ( is_wp_error( $user ) ) {
            set_transient( $key, $tries + 1, 900 );
            wp_send_json_error( __( 'Usuario o contraseña incorrectos.', 'ltms' ) );
        }

        delete_transient( $key );

        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;

        // Determinar el redirect según el rol del usuario autenticado
        $user_roles = (array) $user->roles;
        $is_vendor  = in_array( 'ltms_vendor', $user_roles, true )
                   || in_array( 'ltms_vendor_premium', $user_roles, true );
        $is_admin   = in_array( 'administrator', $user_roles, true )
                   || in_array( 'editor', $user_roles, true );

        if ( $is_vendor ) {
            // Vendedor → su panel
            $redirect = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();
        } elseif ( $is_admin ) {
            // Admin/editor → wp-admin (no tiene sentido enviarlo al panel del vendedor)
            $redirect = admin_url();
        } else {
            // Otro usuario (cliente WC) → mi-cuenta de WooCommerce
            $redirect = wc_get_page_permalink( 'myaccount' ) ?: home_url();
        }

        wp_send_json_success([
            'redirect' => $redirect,
            'message'  => __( 'Bienvenido de vuelta.', 'ltms' ),
        ]);
    }

    public function ajax_vendor_register(): void {
        // C-5: Honeypot — campo invisible que solo los bots rellenan.
        if ( ! empty( $_POST['ltms_hp_email'] ) ) { // phpcs:ignore
            LTMS_Core_Logger::security(
                'REGISTER_HONEYPOT',
                sprintf( 'Honeypot disparado desde IP %s', LTMS_Utils::get_ip() )
            );
            // Respuesta genérica para no revelar el mecanismo al bot.
            wp_send_json_error( __( 'Error en el registro. Intenta de nuevo.', 'ltms' ) );
        }

        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        // C-4: Rate limit por IP (3 registros/hora).
        $ip          = LTMS_Utils::get_ip();
        $throttle_key = 'ltms_register_attempts_' . md5( $ip );
        $tries        = (int) get_transient( $throttle_key );

        if ( $tries >= self::REGISTER_MAX_PER_IP ) {
            LTMS_Core_Logger::security(
                'REGISTER_THROTTLE',
                sprintf( 'IP %s bloqueada por exceder %d registros en %ds', $ip, self::REGISTER_MAX_PER_IP, self::REGISTER_WINDOW )
            );
            wp_send_json_error( __( 'Demasiados registros desde tu IP. Intenta más tarde.', 'ltms' ), 429 );
        }

        $data = [
            'first_name'         => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), // phpcs:ignore
            'last_name'          => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), // phpcs:ignore
            'email'              => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), // phpcs:ignore
            'password'           => wp_unslash( $_POST['password'] ?? '' ), // phpcs:ignore (M-5)
            'confirm_pass'       => wp_unslash( $_POST['password_confirm'] ?? '' ), // phpcs:ignore
            'phone'              => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), // phpcs:ignore
            'store_name'         => sanitize_text_field( wp_unslash( $_POST['store_name'] ?? '' ) ), // phpcs:ignore
            'store_description'  => sanitize_textarea_field( wp_unslash( $_POST['store_description'] ?? '' ) ), // phpcs:ignore
            'municipality_code'  => sanitize_text_field( wp_unslash( $_POST['municipality_code'] ?? '' ) ), // M-200 phpcs:ignore
            'document_type'      => sanitize_text_field( wp_unslash( $_POST['document_type'] ?? '' ) ), // phpcs:ignore
            'document'           => sanitize_text_field( wp_unslash( $_POST['document_number'] ?? '' ) ), // phpcs:ignore
            // M-7: normalizar referral code a uppercase.
            'referral_code'      => strtoupper( sanitize_text_field( wp_unslash( $_POST['referral_code'] ?? '' ) ) ), // phpcs:ignore
            'terms_accepted'     => ! empty( $_POST['accept_terms'] ), // phpcs:ignore
            'sagrilaft_accepted' => ! empty( $_POST['accept_sagrilaft'] ), // phpcs:ignore
        ];

        // M-10: errors estructurados por campo.
        $errors = $this->validate_registration( $data );
        if ( ! empty( $errors ) ) {
            // Incrementar contador incluso en validación fallida para evitar fuzz.
            set_transient( $throttle_key, $tries + 1, self::REGISTER_WINDOW );
            wp_send_json_error([
                'message' => implode( ' ', array_column( $errors, 'message' ) ),
                'errors'  => $errors,
            ]);
        }

        if ( email_exists( $data['email'] ) ) {
            set_transient( $throttle_key, $tries + 1, self::REGISTER_WINDOW );
            wp_send_json_error([
                'message' => __( 'Este email ya está registrado.', 'ltms' ),
                'errors'  => [ [ 'field' => 'email', 'message' => __( 'Este email ya está registrado.', 'ltms' ) ] ],
            ]);
        }

        // M-9: generación de username con retry tolerante a race.
        $username = $this->generate_username( $data['first_name'], $data['last_name'] );
        $user_id  = wp_create_user( $username, $data['password'], $data['email'] );

        if ( is_wp_error( $user_id ) ) {
            // Posible colisión bajo race condition — reintento con sufijo aleatorio.
            $user_id = wp_create_user(
                $username . '_' . wp_generate_password( 4, false ),
                $data['password'],
                $data['email']
            );
        }

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error([
                'message' => $user_id->get_error_message(),
                'errors'  => [ [ 'field' => 'email', 'message' => $user_id->get_error_message() ] ],
            ]);
        }

        // M-4: bloque post-create con rollback ante cualquier fallo.
        try {
            $user = new \WP_User( $user_id );
            $user->set_role( 'ltms_vendor' );

            wp_update_user([
                'ID'           => $user_id,
                'first_name'   => $data['first_name'],
                'last_name'    => $data['last_name'],
                'display_name' => $data['first_name'] . ' ' . $data['last_name'],
            ]);

            // M-3: una sola key de teléfono (ltms_phone). ltms_store_phone se
            // mantiene como sinónimo solo si el vendedor lo configura separado
            // en el panel de Settings.
            update_user_meta( $user_id, 'ltms_store_name', $data['store_name'] );
            update_user_meta( $user_id, 'ltms_store_description', $data['store_description'] );

            // M-200: municipio DANE del vendedor (territorialidad ReteICA).
            // Si el catálogo no está cargado o el código no es válido, dejamos vacío y Order_Split
            // resuelve con fallback. Validación estricta solo si llegó algo en el POST.
            if ( $data['municipality_code'] !== '' && class_exists( 'LTMS_Business_Dane_Catalog' )
                 && LTMS_Business_Dane_Catalog::exists( $data['municipality_code'] ) ) {
                update_user_meta( $user_id, 'ltms_municipality', $data['municipality_code'] );
            }
            update_user_meta( $user_id, 'ltms_phone', LTMS_Utils::format_phone_e164( $data['phone'] ) );
            // L-1: cifrar documento con log de vault (Habeas Data — Ley 1581/2012).
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                update_user_meta( $user_id, 'ltms_document',
                    LTMS_Legal_Compliance::encrypt_and_log( $data['document'], $user_id, 'ltms_document', 'registration' )
                );
            } else {
                update_user_meta( $user_id, 'ltms_document', LTMS_Core_Security::encrypt( $data['document'] ) );
            }
            update_user_meta( $user_id, 'ltms_document_type', $data['document_type'] );
            update_user_meta( $user_id, 'ltms_kyc_status', 'pending' );
            update_user_meta( $user_id, 'ltms_terms_accepted_at', LTMS_Utils::now_utc() );
            update_user_meta( $user_id, 'ltms_email_verified', 0 );

            if ( ! empty( $data['sagrilaft_accepted'] ) ) {
                update_user_meta( $user_id, 'ltms_sagrilaft_accepted_at', LTMS_Utils::now_utc() );
            }

            // L-6: guardar consentimiento explícito de datos (Ley 1581/2012 art. 9).
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                LTMS_Legal_Compliance::save_consent( 'registration', $user_id, true );
            }

            // C-3: token de verificación de email (48h).
            $verify_token = wp_generate_password( 32, false );
            update_user_meta( $user_id, 'ltms_email_verify_token', $verify_token );
            update_user_meta( $user_id, 'ltms_email_verify_expires', time() + self::EMAIL_VERIFY_TTL );

            // Crear billetera inicial.
            LTMS_Business_Wallet::get_or_create( $user_id );

            // Disparar listeners (Affiliates genera ltms_referral_code, Alegra crea contacto).
            do_action( 'ltms_vendor_registered', $user_id, $data['referral_code'] ?? '' );

            // C-2: enviar email de bienvenida con link de verificación.
            $this->send_welcome_email( $user_id, $verify_token );

        } catch ( \Throwable $e ) {
            // Rollback: borrar el usuario huérfano si algo post-creación falla.
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $user_id );

            LTMS_Core_Logger::error(
                'VENDOR_REGISTER_ROLLBACK',
                sprintf( 'Rollback de registro para uid=%d: %s', $user_id, $e->getMessage() ),
                [ 'user_id' => $user_id, 'trace' => $e->getTraceAsString() ]
            );

            wp_send_json_error([
                'message' => __( 'No pudimos completar tu registro. Intenta de nuevo.', 'ltms' ),
                'errors'  => [],
            ]);
        }

        // Login automático.
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, false );

        // Limpiar contador en éxito para no penalizar a usuarios legítimos en la misma red.
        delete_transient( $throttle_key );

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
            'message'  => __( '¡Registro exitoso! Revisa tu email para verificar tu cuenta.', 'ltms' ),
        ]);
    }

    public function ajax_vendor_logout(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        wp_logout();

        $pages    = get_option( 'ltms_installed_pages', [] );
        $login_id = $pages['ltms-login'] ?? 0;
        $redirect = $login_id ? get_permalink( $login_id ) : home_url();

        wp_send_json_success( [ 'redirect' => $redirect ] );
    }

    /**
     * Listener del endpoint de verificación de email (C-3).
     * Activado por URL: ?ltms_verify_email=<token>&uid=<id>
     */
    public function handle_email_verification(): void {
        if ( empty( $_GET['ltms_verify_email'] ) || empty( $_GET['uid'] ) ) { // phpcs:ignore
            return;
        }

        $token   = sanitize_text_field( wp_unslash( $_GET['ltms_verify_email'] ) ); // phpcs:ignore
        $user_id = absint( wp_unslash( $_GET['uid'] ) ); // phpcs:ignore

        if ( ! $user_id || ! $token ) {
            return;
        }

        $stored  = (string) get_user_meta( $user_id, 'ltms_email_verify_token', true );
        $expires = (int) get_user_meta( $user_id, 'ltms_email_verify_expires', true );

        if ( ! $stored || ! hash_equals( $stored, $token ) ) {
            wp_die(
                esc_html__( 'Token de verificación inválido.', 'ltms' ),
                esc_html__( 'Verificación de email', 'ltms' ),
                [ 'response' => 400, 'back_link' => true ]
            );
        }

        if ( $expires > 0 && time() > $expires ) {
            wp_die(
                esc_html__( 'El link de verificación expiró. Solicita uno nuevo desde tu panel.', 'ltms' ),
                esc_html__( 'Verificación de email', 'ltms' ),
                [ 'response' => 410, 'back_link' => true ]
            );
        }

        update_user_meta( $user_id, 'ltms_email_verified', 1 );
        update_user_meta( $user_id, 'ltms_email_verified_at', LTMS_Utils::now_utc() );
        delete_user_meta( $user_id, 'ltms_email_verify_token' );
        delete_user_meta( $user_id, 'ltms_email_verify_expires' );

        LTMS_Core_Logger::info(
            'EMAIL_VERIFIED',
            sprintf( 'Email verificado para vendedor #%d', $user_id ),
            [ 'user_id' => $user_id ]
        );

        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $redirect     = $dashboard_id
            ? add_query_arg( 'email_verified', '1', get_permalink( $dashboard_id ) )
            : home_url();

        wp_safe_redirect( $redirect );
        exit;
    }

    public function redirect_vendor_from_admin(): void {
        // M-8: evitar disparar en cron/AJAX/REST.
        if ( ! is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        if ( in_array( 'ltms_vendor', (array) $user->roles, true ) ||
             in_array( 'ltms_vendor_premium', (array) $user->roles, true ) ) {

            $pages        = get_option( 'ltms_installed_pages', [] );
            $dashboard_id = $pages['ltms-dashboard'] ?? 0;
            $redirect     = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

            wp_safe_redirect( $redirect );
            exit;
        }
    }

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
     * Valida los datos del registro y devuelve errores estructurados por campo.
     *
     * @return array<int, array{field:string, message:string}>
     */
    private function validate_registration( array $data ): array {
        $errors  = [];
        $country = LTMS_Core_Config::get_country();

        if ( empty( $data['first_name'] ) ) {
            $errors[] = [ 'field' => 'first_name', 'message' => __( 'El nombre es requerido.', 'ltms' ) ];
        }
        if ( empty( $data['last_name'] ) ) {
            $errors[] = [ 'field' => 'last_name', 'message' => __( 'El apellido es requerido.', 'ltms' ) ];
        }
        if ( ! is_email( $data['email'] ) ) {
            $errors[] = [ 'field' => 'email', 'message' => __( 'Email inválido.', 'ltms' ) ];
        }

        // M-6: password composition (>= 8, al menos una mayúscula y un número).
        if ( strlen( $data['password'] ) < 8 ) {
            $errors[] = [ 'field' => 'password', 'message' => __( 'La contraseña debe tener al menos 8 caracteres.', 'ltms' ) ];
        } elseif ( ! preg_match( '/[A-Z]/', $data['password'] ) || ! preg_match( '/[0-9]/', $data['password'] ) ) {
            $errors[] = [ 'field' => 'password', 'message' => __( 'La contraseña debe incluir al menos una mayúscula y un número.', 'ltms' ) ];
        }

        if ( $data['password'] !== $data['confirm_pass'] ) {
            $errors[] = [ 'field' => 'password_confirm', 'message' => __( 'Las contraseñas no coinciden.', 'ltms' ) ];
        }
        if ( empty( $data['store_name'] ) ) {
            $errors[] = [ 'field' => 'store_name', 'message' => __( 'El nombre de tu tienda es requerido.', 'ltms' ) ];
        }
        if ( empty( $data['document'] ) ) {
            $errors[] = [ 'field' => 'document_number', 'message' => __( 'El número de documento es requerido.', 'ltms' ) ];
        }
        if ( ! $data['terms_accepted'] ) {
            $errors[] = [ 'field' => 'accept_terms', 'message' => __( 'Debes aceptar los términos y condiciones.', 'ltms' ) ];
        }

        // M-1: SAGRILAFT obligatorio server-side en Colombia.
        if ( 'CO' === $country && ! $data['sagrilaft_accepted'] ) {
            $errors[] = [ 'field' => 'accept_sagrilaft', 'message' => __( 'Debes autorizar el tratamiento de datos SAGRILAFT.', 'ltms' ) ];
        }

        return $errors;
    }

    /**
     * Genera un username único combinando nombre + apellido + contador.
     * En caso de colisión bajo race condition, el caller hace retry con sufijo random.
     */
    private function generate_username( string $first_name, string $last_name ): string {
        $base     = sanitize_user( strtolower( $first_name . $last_name ), true );
        if ( empty( $base ) ) {
            $base = 'vendor';
        }
        $username = $base;
        $counter  = 1;

        while ( username_exists( $username ) && $counter < 100 ) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Renderiza el template de email y lo envía vía wp_mail.
     */
    private function send_welcome_email( int $user_id, string $verify_token ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $dashboard_url = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();

        $verify_url = add_query_arg(
            [
                'ltms_verify_email' => $verify_token,
                'uid'               => $user_id,
            ],
            home_url( '/' )
        );

        $data = [
            'vendor_name'   => $user->first_name . ' ' . $user->last_name,
            'store_name'    => (string) get_user_meta( $user_id, 'ltms_store_name', true ),
            'referral_code' => (string) get_user_meta( $user_id, 'ltms_referral_code', true ),
            'dashboard_url' => $verify_url, // CTA principal verifica + redirige al dashboard
            'kyc_url'       => home_url( '/verificacion-identidad/' ),
            'site_name'     => get_bloginfo( 'name' ),
            'country'       => LTMS_Core_Config::get_country(),
        ];

        $template = LTMS_TEMPLATES_DIR . 'emails/email-welcome-vendor.php';
        if ( ! file_exists( $template ) ) {
            LTMS_Core_Logger::warning( 'EMAIL_TEMPLATE_MISSING', 'email-welcome-vendor.php no encontrado' );
            return;
        }

        ob_start();
        include $template;
        $body = ob_get_clean();

        $subject = sprintf(
            /* translators: %s: site name */
            __( '¡Bienvenido a %s! Verifica tu cuenta', 'ltms' ),
            get_bloginfo( 'name' )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = wp_mail( $user->user_email, $subject, $body, $headers );

        if ( ! $sent ) {
            LTMS_Core_Logger::warning(
                'WELCOME_EMAIL_FAILED',
                sprintf( 'No se pudo enviar email de bienvenida a uid=%d', $user_id ),
                [ 'user_id' => $user_id ]
            );
        }
    }

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
