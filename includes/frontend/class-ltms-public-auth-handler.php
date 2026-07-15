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

    /** Máximo de intentos de login fallidos por IP en la ventana. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** Ventana del rate limit de login (15 minutos). */
    private const LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;

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
        // v2.9.60 MISSING-08: Endpoint para reenviar email de verificación.
        add_action( 'wp_ajax_ltms_resend_verification',    [ $instance, 'ajax_resend_verification' ] );
        // v2.9.61 DEEP-AUDIT-002 UX-06: Endpoint para completar perfil (Google OAuth path).
        add_action( 'wp_ajax_ltms_complete_profile',       [ $instance, 'ajax_complete_profile' ] );

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

        // Throttle: máximo 5 intentos por IP en 15 minutos.
        // INTEGRATIONS-AUDIT P0 FIX: migrated from non-atomic get_transient/set_transient
        // to atomic INSERT...ON DUPLICATE KEY UPDATE (same pattern as register throttle
        // at line 287). The previous implementation had a TOCTOU race: N concurrent
        // requests all read $tries=0, all increment to 1, the counter never advanced.
        // A botnet with 50 parallel connections could brute-force passwords with no
        // effective throttle.
        $ip           = LTMS_Utils::get_ip();
        $throttle_key = 'ltms_login_attempts_' . md5( $ip );
        global $wpdb;
        $option_name  = '_transient_' . $throttle_key;
        $timeout_name = '_transient_timeout_' . $throttle_key;
        $now          = time();
        $expires      = $now + self::LOGIN_WINDOW;

        // Check if the transient already expired → reset to 0.
        $timeout_val = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $timeout_name
        ) );

        if ( $timeout_val && $timeout_val < $now ) {
            // Expired — reset counter to 0 before incrementing.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = '0' WHERE option_name = %s",
                $option_name
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s",
                $expires, $timeout_name
            ) );
        }

        // Atomic increment — race-safe under concurrent requests.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $option_name
        ) );
        // Ensure timeout is set (only on first attempt; don't extend on subsequent).
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = IF(option_value < %d, %d, option_value)",
            $timeout_name, $expires, $now, $expires
        ) );
        $tries = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ) );

        if ( $tries > self::LOGIN_MAX_ATTEMPTS ) {
            LTMS_Core_Logger::security(
                'LOGIN_THROTTLE',
                sprintf( 'IP %s bloqueada por demasiados intentos de login (%d intentos)', $ip, $tries )
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
            // Counter was already incremented at the top — no need to increment again.
            wp_send_json_error( __( 'Usuario o contraseña incorrectos.', 'ltms' ) );
        }

        // Successful login — clear the throttle counter.
        delete_transient( $throttle_key );

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
        if ( ! empty( $_POST['ltms_hp_website'] ) ) { // phpcs:ignore — M-AUDIT-REG-05: campo renombrado para evitar autocompletado de gestores de contraseñas
            LTMS_Core_Logger::security(
                'REGISTER_HONEYPOT',
                sprintf( 'Honeypot disparado desde IP %s', LTMS_Utils::get_ip() )
            );
            // Respuesta genérica para no revelar el mecanismo al bot.
            wp_send_json_error( __( 'Error en el registro. Intenta de nuevo.', 'ltms' ) );
        }

        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        // C-4: Rate limit por IP (3 registros/hora).
        // v2.9.60 REG-02 FIX: Usar increment atómico con $wpdb para evitar TOCTOU.
        // Antes se hacía get_transient() + set_transient($tries+1) lo que permitía
        // race conditions bajo requests concurrentes. Ahora usamos INSERT ON DUPLICATE
        // KEY UPDATE que es atómico a nivel de MySQL.
        $ip          = LTMS_Utils::get_ip();
        $throttle_key = 'ltms_register_attempts_' . md5( $ip );

        // Increment atómico via direct DB query (transients no son atómicos).
        global $wpdb;
        $option_name = '_transient_' . $throttle_key;
        $timeout_name = '_transient_timeout_' . $throttle_key;
        $now = time();
        $expires = $now + self::REGISTER_WINDOW;

        // Verificar si el transient ya expiró
        $timeout_val = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $timeout_name
        ) );

        if ( $timeout_val && $timeout_val < $now ) {
            // Expiró — resetear a 1
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                '1', $option_name
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s",
                $expires, $timeout_name
            ) );
            $tries = 1;
        } else {
            // Increment atómico con INSERT ... ON DUPLICATE KEY UPDATE
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
                 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
                $option_name
            ) );
            // Asegurar que el timeout esté seteado
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
                 ON DUPLICATE KEY UPDATE option_value = IF(option_value < %d, %d, option_value)",
                $timeout_name, $expires, $now, $expires
            ) );
            $tries = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            ) );
        }

        // El contador ya se incrementó. Si excede el límite, bloquear.
        if ( $tries > self::REGISTER_MAX_PER_IP ) {
            LTMS_Core_Logger::security(
                'REGISTER_THROTTLE',
                sprintf( 'IP %s bloqueada por exceder %d registros en %ds', $ip, self::REGISTER_MAX_PER_IP, self::REGISTER_WINDOW )
            );
            wp_send_json_error( __( 'Demasiados registros desde tu IP. Intenta más tarde.', 'ltms' ), 429 );
        }

        // v2.9.60 MISSING-03: Verificar Cloudflare Turnstile si está configurado.
        $turnstile_secret = LTMS_Core_Config::get( 'ltms_turnstile_secret_key', '' );
        if ( ! empty( $turnstile_secret ) ) {
            $turnstile_token = sanitize_text_field( wp_unslash( $_POST['ltms_turnstile_token'] ?? '' ) ); // phpcs:ignore
            if ( empty( $turnstile_token ) ) {
                wp_send_json_error([
                    'message' => __( 'Por favor completa el captcha de verificación.', 'ltms' ),
                    'errors'  => [ [ 'field' => 'turnstile', 'message' => __( 'Captcha requerido.', 'ltms' ) ] ],
                ]);
            }

            // Verificar token con la API de Cloudflare.
            $verify_response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'timeout' => 10,
                'body'    => [
                    'secret'   => $turnstile_secret,
                    'response' => $turnstile_token,
                    'remoteip' => $ip,
                ],
            ] );

            if ( is_wp_error( $verify_response ) ) {
                LTMS_Core_Logger::warning( 'TURNSTILE_VERIFY_ERROR', $verify_response->get_error_message() );
                wp_send_json_error( __( 'Error al verificar captcha. Intenta de nuevo.', 'ltms' ), 500 );
            }

            $verify_body = json_decode( wp_remote_retrieve_body( $verify_response ), true );
            if ( empty( $verify_body['success'] ) ) {
                LTMS_Core_Logger::security( 'TURNSTILE_FAILED', sprintf( 'IP %s falló verificación Turnstile', $ip ) );
                wp_send_json_error([
                    'message' => __( 'La verificación captcha falló. Intenta de nuevo.', 'ltms' ),
                    'errors'  => [ [ 'field' => 'turnstile', 'message' => __( 'Captcha inválido.', 'ltms' ) ] ],
                ]);
            }
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
            'store_address'      => sanitize_text_field( wp_unslash( $_POST['store_address'] ?? '' ) ), // phpcs:ignore — dirección fiscal para contrato
            'tax_regime'         => sanitize_key( wp_unslash( $_POST['tax_regime'] ?? '' ) ), // phpcs:ignore — régimen tributario para contrato
            'terms_accepted'     => ! empty( $_POST['accept_terms'] ), // phpcs:ignore
            'sagrilaft_accepted' => ! empty( $_POST['accept_sagrilaft'] ), // phpcs:ignore
            // M-MX-1: país declarado por el vendedor (CO o MX).
            'vendor_country'     => strtoupper( sanitize_text_field( wp_unslash( $_POST['vendor_country'] ?? '' ) ) ), // phpcs:ignore

            // M-TURISMO-01: tipo de negocio declarado en el registro.
            'business_type'      => sanitize_key( wp_unslash( $_POST['business_type'] ?? 'physical' ) ), // phpcs:ignore
        ];

        // M-10: errors estructurados por campo.
        $errors = $this->validate_registration( $data );
        if ( ! empty( $errors ) ) {
            // v2.9.60 REG-02: El contador ya se incrementó atómicamente arriba.
            wp_send_json_error([
                'message' => implode( ' ', array_column( $errors, 'message' ) ),
                'errors'  => $errors,
            ]);
        }

        if ( email_exists( $data['email'] ) ) {
            // v2.9.60 REG-02: El contador ya se incrementó atómicamente arriba.
            // INTEGRATIONS-AUDIT P1 FIX (user enumeration): previously returned
            // "Este email ya está registrado" — allowed attackers to enumerate
            // which emails have vendor accounts. Now returns the same generic
            // success message as a new registration, and sends an "already
            // registered" email to the existing address with a login link.
            $existing_user = get_user_by( 'email', $data['email'] );
            if ( $existing_user ) {
                $login_url = wp_login_url();
                $subject   = sprintf( __( '[%s] Ya tienes una cuenta', 'ltms' ), get_bloginfo( 'name' ) );
                $message   = sprintf(
                    __( "Hola,\n\nAlguien intentó registrar una nueva cuenta con tu email en %s.\n\nSi fuiste tú, ya tienes una cuenta. Puedes iniciar sesión aquí: %s\n\nSi no fuiste tú, ignora este correo — tu cuenta está segura.\n\nSaludos,\nEquipo %s", 'ltms' ),
                    get_bloginfo( 'name' ),
                    $login_url,
                    get_bloginfo( 'name' )
                );
                wp_mail( $data['email'], $subject, $message );
                LTMS_Core_Logger::info(
                    'REGISTER_DUPLICATE_EMAIL',
                    sprintf( 'Existing email used on registration form — sent login link to user #%d', $existing_user->ID )
                );
            }
            // Return the same success shape as a real registration so the
            // attacker can't distinguish "created" from "already existed".
            wp_send_json_success([
                'message'  => __( 'Revisa tu email para completar el registro.', 'ltms' ),
                'redirect' => '',
            ]);
        }

        // v2.9.113 P2 FIX: Validate referral code if provided.
        if ( ! empty( $data['referral_code'] ) ) {
            $referrer = get_users( [
                'meta_key'   => 'ltms_referral_code',
                'meta_value' => $data['referral_code'],
                'number'     => 1,
                'fields'     => 'ID',
                'number'     => 1,
            ] );
            if ( empty( $referrer ) ) {
                // Invalid code — don't fail registration, just clear it.
                LTMS_Core_Logger::info( 'INVALID_REFERRAL_CODE', sprintf( 'Code %s not found', $data['referral_code'] ) );
                $data['referral_code'] = '';
            }
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
            // v2.9.60 REG-11 FIX: Usar add_role en vez de set_role para no
            // sobrescribir el rol de customer si el usuario ya existía como tal.
            $user->add_role( 'ltms_vendor' );

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

            // v2.8.1: slug estable para la vitrina pública (/vendedor/{slug}/).
            // No usar el login directamente — puede tener "@", espacios, etc.
            if ( class_exists( 'LTMS_Vendor_Storefront' ) ) {
                $store_slug = LTMS_Vendor_Storefront::generate_unique_slug( $data['store_name'], $user_id );
                update_user_meta( $user_id, 'ltms_store_slug', $store_slug );
            }

            // M-200: municipio DANE del vendedor (territorialidad ReteICA).
            // Si el catálogo no está cargado o el código no es válido, dejamos vacío y Order_Split
            // resuelve con fallback. Validación estricta solo si llegó algo en el POST.
            if ( $data['municipality_code'] !== '' && class_exists( 'LTMS_Business_Dane_Catalog' )
                 && LTMS_Business_Dane_Catalog::exists( $data['municipality_code'] ) ) {
                update_user_meta( $user_id, 'ltms_municipality', $data['municipality_code'] );
            }
            update_user_meta( $user_id, 'ltms_phone', LTMS_Utils::format_phone_e164( $data['phone'] ) );
            if ( ! empty( $data['store_address'] ) ) {
                update_user_meta( $user_id, 'billing_address_1', $data['store_address'] );
            }
            if ( ! empty( $data['tax_regime'] ) ) {
                update_user_meta( $user_id, 'ltms_tax_regime', $data['tax_regime'] );
            }
            // L-1: cifrar documento (Habeas Data — Ley 1581/2012).
            // M-FIX-REG-01: LTMS_Legal_Compliance::encrypt_and_log() no existe — usar
            // LTMS_Core_Security::encrypt() directamente y log_vault_access() por separado.
            $encrypted_doc = LTMS_Core_Security::encrypt( $data['document'] );
            update_user_meta( $user_id, 'ltms_document', $encrypted_doc );
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                // M-FIX-REG-04: log_vault_access() requiere $accessor_id (int) como segundo
                // parámetro — pasar el tipo de documento ahí causaba un TypeError fatal
                // (capturado por el catch de abajo, produciendo el MISMO rollback que el
                // bug original). El propio vendedor es quien declara su documento al
                // registrarse, así que accessor_id = user_id.
                LTMS_Legal_Compliance::log_vault_access( $user_id, $user_id, 'ltms_document', 'write', 'registration' );
            }
            update_user_meta( $user_id, 'ltms_document_type', $data['document_type'] );
            // M-MX-1: guardar país del vendedor para routing fiscal y wallet.
            $vendor_country = in_array( $data['vendor_country'], [ 'CO', 'MX' ], true )
                ? $data['vendor_country']
                : LTMS_Core_Config::get_country();
            update_user_meta( $user_id, 'ltms_country', $vendor_country );
            // M-TURISMO-01: tipo de negocio principal del vendedor.
            $allowed_btypes = [ 'physical', 'digital', 'services', 'tourism', 'restaurant' ];
            $btype = in_array( $data['business_type'] ?? '', $allowed_btypes, true )
                ? $data['business_type']
                : 'physical';
            update_user_meta( $user_id, 'ltms_business_type', $btype );
            // v2.9.113 P0-4 FIX: marca restaurantes para routing de compliance.
            if ( $btype === 'restaurant' ) {
                update_user_meta( $user_id, 'ltms_is_restaurant', 'yes' );
            }
            update_user_meta( $user_id, 'ltms_kyc_status', 'pending' );
            // v2.9.113 P2-14 FIX: flag inicial de configuración de tienda (se setea en 1 al configurar).
            update_user_meta( $user_id, 'ltms_store_configured', 0 );
            update_user_meta( $user_id, 'ltms_terms_accepted_at', LTMS_Utils::now_utc() );
            update_user_meta( $user_id, 'ltms_email_verified', 0 );

            if ( ! empty( $data['sagrilaft_accepted'] ) ) {
                update_user_meta( $user_id, 'ltms_sagrilaft_accepted_at', LTMS_Utils::now_utc() );
            }

            // L-6: guardar consentimiento explícito de datos (Ley 1581/2012 art. 9).
            // M-FIX-REG-02: save_consent() no existe — usar log_consent() con los parámetros reales.
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                LTMS_Legal_Compliance::log_consent( $user_id, 'terms_and_conditions', true, '1.0', 'web' );
            }

            // C-3: token de verificación de email (48h).
            $verify_token = wp_generate_password( 32, false );
            update_user_meta( $user_id, 'ltms_email_verify_token', $verify_token );
            update_user_meta( $user_id, 'ltms_email_verify_expires', time() + self::EMAIL_VERIFY_TTL );

            // Crear billetera inicial con la moneda del país del vendedor.
            $wallet_currency = ( $vendor_country === 'MX' ) ? 'MXN' : 'COP';
            // v2.9.113 P2-16 FIX: wrap en try-catch para que un fallo de wallet no rompa el registro.
            try {
                LTMS_Business_Wallet::get_or_create( $user_id, $wallet_currency );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::error( 'WALLET_CREATE_FAILED', sprintf( 'uid=%d: %s', $user_id, $e->getMessage() ) );
                // Continue — wallet can be created later.
            }

            // Disparar listeners (Affiliates genera ltms_referral_code, Alegra crea contacto).
            do_action( 'ltms_vendor_registered', $user_id, $data['referral_code'] ?? '' );

            // L-2 FIX: Registrar consentimiento de tratamiento de datos (Ley 1581/2012, art. 9).
            // M-FIX-REG-03: PURPOSE_REGISTRATION constante no existe — usar string literal.
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                LTMS_Legal_Compliance::log_consent( $user_id, 'data_treatment', true, '1.0', 'web' );
                if ( ! empty( $data['sagrilaft_accepted'] ) ) {
                    LTMS_Legal_Compliance::log_consent( $user_id, 'sagrilaft', true, '1.0', 'web' );
                }
            }

            // C-2: enviar email de bienvenida con link de verificación.
            $this->send_welcome_email( $user_id, $verify_token );

            // v2.9.60 MISSING-04: Notificar al admin del sitio sobre el nuevo registro.
            $this->notify_admin_new_vendor( $user_id, $data );

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

        // ME-5 FIX: do NOT auto-login after registration by default. The user
        // must verify their email before they can access the dashboard. This
        // prevents account creation with fake/typo emails from being used to
        // browse vendor data immediately.
        //
        // If the site admin has explicitly set the option
        // `ltms_require_email_verification` to 'no', auto-login is preserved
        // for backward compatibility (e.g. dev / staging sites that skip
        // email delivery).
        $require_email_verification = get_option( 'ltms_require_email_verification', 'yes' ) !== 'no';

        $pages        = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $login_id     = $pages['ltms-login'] ?? 0;

        if ( $require_email_verification ) {
            // No auth cookie — the user must click the verification link in the
            // welcome email. Redirect them to the login page with a clear
            // message so they know to check their inbox.
            $redirect = $login_id ? get_permalink( $login_id ) : home_url();
            $message  = __( 'Registration successful. Please check your email to verify your account.', 'ltms' );
        } else {
            // Email verification is optional — auto-login (legacy behavior).
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, false );
            // L-5 FIX: Registrar acceso de autenticación para trazabilidad.
            // Ley 1581/2012 — el titular puede solicitar historial de accesos a sus datos.
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                LTMS_Legal_Compliance::log_oauth_access( $user_id, 'native_login' );
            }
            $redirect = $dashboard_id ? get_permalink( $dashboard_id ) : home_url();
            $message  = __( '¡Registro exitoso! Revisa tu email para verificar tu cuenta.', 'ltms' );
        }

        // v2.9.60 REG-01 FIX: NO borrar el contador en éxito.
        // Antes se hacía delete_transient() lo que permitía a un atacante
        // resetear su presupuesto alternando intentos válidos e inválidos.
        // El transient expira naturalmente tras REGISTER_WINDOW segundos.
        // delete_transient( $throttle_key );

        LTMS_Core_Logger::info(
            'VENDOR_REGISTERED',
            sprintf( 'Nuevo vendedor registrado: #%d (%s)', $user_id, $data['email'] ),
            [ 'user_id' => $user_id, 'auto_login' => ! $require_email_verification ]
        );

        wp_send_json_success([
            'redirect'            => $redirect,
            'message'             => $message,
            'email_verification_required' => $require_email_verification,
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
        // M-MX-1: usar el país declarado por el vendor (si es válido), no el global del sitio.
        $country = in_array( $data['vendor_country'] ?? '', [ 'CO', 'MX' ], true )
            ? $data['vendor_country']
            : LTMS_Core_Config::get_country();

        if ( empty( $data['first_name'] ) ) {
            $errors[] = [ 'field' => 'first_name', 'message' => __( 'El nombre es requerido.', 'ltms' ) ];
        }
        if ( empty( $data['last_name'] ) ) {
            $errors[] = [ 'field' => 'last_name', 'message' => __( 'El apellido es requerido.', 'ltms' ) ];
        }
        if ( ! is_email( $data['email'] ) ) {
            $errors[] = [ 'field' => 'email', 'message' => __( 'Email inválido.', 'ltms' ) ];
        }

        // v2.9.60 REG-07: Whitelist vendor_country (CO o MX únicamente).
        if ( ! empty( $data['vendor_country'] ) && ! in_array( $data['vendor_country'], [ 'CO', 'MX' ], true ) ) {
            $errors[] = [ 'field' => 'vendor_country', 'message' => __( 'País inválido. Solo se permite Colombia o México.', 'ltms' ) ];
        }

        // v2.9.60 REG-06: Whitelist document_type.
        $valid_doc_types = ( $country === 'MX' )
            ? [ 'RFC', 'CURP', 'PAS' ]
            : [ 'CC', 'CE', 'NIT', 'PAS' ];
        if ( ! empty( $data['document_type'] ) && ! in_array( $data['document_type'], $valid_doc_types, true ) ) {
            $errors[] = [ 'field' => 'document_type', 'message' => __( 'Tipo de documento inválido.', 'ltms' ) ];
        }

        // v2.9.60 REG-05: Whitelist business_type.
        if ( ! empty( $data['business_type'] ) && ! in_array( $data['business_type'], [ 'physical', 'digital', 'services', 'tourism', 'restaurant' ], true ) ) {
            $errors[] = [ 'field' => 'business_type', 'message' => __( 'Tipo de negocio inválido.', 'ltms' ) ];
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
        if ( empty( $data['phone'] ) ) {
            $errors[] = [ 'field' => 'phone', 'message' => __( 'El teléfono es requerido.', 'ltms' ) ];
        } elseif ( ! preg_match( '/^\+[1-9][0-9]{6,19}$/', preg_replace( '/[\s\-\(\)]/', '', $data['phone'] ) ) ) {
            // v2.9.113 P2-12 FIX: Validar formato E.164 estricto (+CC seguido de 7-19 dígitos, sin espacios/guiones/paréntesis).
            $errors[] = [ 'field' => 'phone', 'message' => __( 'El teléfono debe estar en formato internacional E.164 (ej: +573001112233). Incluye el código de país con + al inicio.', 'ltms' ) ];
        }
        if ( empty( $data['store_address'] ) ) {
            $errors[] = [ 'field' => 'store_address', 'message' => __( 'La dirección es requerida para el contrato de vinculación.', 'ltms' ) ];
        }
        if ( 'CO' === ( $data['vendor_country'] ?? 'CO' ) && empty( $data['tax_regime'] ) ) {
            $errors[] = [ 'field' => 'tax_regime', 'message' => __( 'El régimen tributario es requerido.', 'ltms' ) ];
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

        // v2.9.60 REG-09 FIX: La URL de verificación debe apuntar a la página de
        // login, no a home_url('/'). Antes, el usuario hacía click, se verificaba
        // el email, pero luego el redirect al dashboard fallaba porque no estaba
        // logueado. Ahora apunta al login con un parámetro de "verificación exitosa".
        $login_url = wp_login_url();
        $verify_url = add_query_arg(
            [
                'ltms_verify_email' => $verify_token,
                'uid'               => $user_id,
            ],
            $login_url
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

    /**
     * v2.9.60 MISSING-04: Envía email al admin del sitio cuando un nuevo vendedor se registra.
     * Permite al admin conocer los registros sin tener que revisar el panel.
     */
    private function notify_admin_new_vendor( int $user_id, array $data ): void {
        $admin_email = get_option( 'admin_email' );
        if ( empty( $admin_email ) ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $site_name = get_bloginfo( 'name' );
        $subject = sprintf( '[%s] Nuevo vendedor registrado: %s', $site_name, $data['store_name'] );

        $body = sprintf(
            "Nuevo vendedor registrado en %s\n\n" .
            "Nombre: %s %s\n" .
            "Email: %s\n" .
            "Tienda: %s\n" .
            "País: %s\n" .
            "Teléfono: %s\n" .
            "Tipo de negocio: %s\n" .
            "Régimen tributario: %s\n\n" .
            "Revisa el panel de admin para aprobar el KYC:\n%s\n",
            $site_name,
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['store_name'],
            $data['vendor_country'] ?? 'N/A',
            $data['phone'] ?? 'N/A',
            $data['business_type'] ?? 'N/A',
            $data['tax_regime'] ?? 'N/A',
            admin_url( 'admin.php?page=ltms-kyc' )
        );

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        wp_mail( $admin_email, $subject, $body, $headers );

        LTMS_Core_Logger::info(
            'ADMIN_NOTIFIED_NEW_VENDOR',
            sprintf( 'Admin %s notificado del nuevo vendedor #%d', $admin_email, $user_id )
        );
    }

    /**
     * v2.9.60 MISSING-08: Reenvía el email de verificación.
     * Endpoint: wp_ajax_ltms_resend_verification
     * Rate limited: 3 reenvíos por hora por usuario.
     */
    public function ajax_resend_verification(): void {
        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debes iniciar sesión.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();

        // Verificar que el email no esté ya verificado.
        if ( get_user_meta( $user_id, 'ltms_email_verified', true ) ) {
            wp_send_json_error( [ 'message' => __( 'Tu email ya está verificado.', 'ltms' ) ] );
        }

        // Rate limit: 3 reenvíos por hora.
        $throttle_key = 'ltms_resend_attempts_' . $user_id;
        $attempts = (int) get_transient( $throttle_key );
        if ( $attempts >= 3 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados reenvíos. Intenta más tarde.', 'ltms' ) ], 429 );
        }
        set_transient( $throttle_key, $attempts + 1, HOUR_IN_SECONDS );

        // Generar nuevo token.
        $verify_token = wp_generate_password( 32, false );
        update_user_meta( $user_id, 'ltms_email_verify_token', $verify_token );
        update_user_meta( $user_id, 'ltms_email_verify_expires', time() + ( 48 * HOUR_IN_SECONDS ) );

        // Enviar email.
        $this->send_welcome_email( $user_id, $verify_token );

        LTMS_Core_Logger::info(
            'VERIFICATION_RESENT',
            sprintf( 'Email de verificación reenviado a uid=%d', $user_id )
        );

        wp_send_json_success( [ 'message' => __( 'Email de verificación reenviado. Revisa tu bandeja de entrada.', 'ltms' ) ] );
    }

    /**
     * v2.9.61 DEEP-AUDIT-002 UX-06: Completa el perfil de un vendor registrado via Google OAuth.
     * Captura los campos faltantes: teléfono, documento, régimen tributario, SAGRILAFT, etc.
     * Endpoint: wp_ajax_ltms_complete_profile
     */
    public function ajax_complete_profile(): void {
        check_ajax_referer( 'ltms_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debes iniciar sesión.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        // Verificar que el perfil esté marcado como incompleto.
        if ( ! get_user_meta( $user_id, 'ltms_profile_incomplete', true ) ) {
            wp_send_json_error( [ 'message' => __( 'Tu perfil ya está completo.', 'ltms' ) ] );
        }

        // Sanitizar y validar datos.
        $phone           = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ); // phpcs:ignore
        $document_type   = sanitize_text_field( wp_unslash( $_POST['document_type'] ?? '' ) ); // phpcs:ignore
        $document_number = sanitize_text_field( wp_unslash( $_POST['document_number'] ?? '' ) ); // phpcs:ignore
        $store_name      = sanitize_text_field( wp_unslash( $_POST['store_name'] ?? '' ) ); // phpcs:ignore
        $store_address   = sanitize_text_field( wp_unslash( $_POST['store_address'] ?? '' ) ); // phpcs:ignore
        $vendor_country  = strtoupper( sanitize_text_field( wp_unslash( $_POST['vendor_country'] ?? '' ) ) ); // phpcs:ignore
        $tax_regime      = sanitize_key( wp_unslash( $_POST['tax_regime'] ?? '' ) ); // phpcs:ignore
        $business_type   = sanitize_key( wp_unslash( $_POST['business_type'] ?? 'physical' ) ); // phpcs:ignore
        $sagrilaft       = ! empty( $_POST['accept_sagrilaft'] ); // phpcs:ignore

        // Validaciones.
        $errors = [];
        // v2.9.113 FIX: Usar el mismo regex E.164 que validate_registration.
        if ( empty( $phone ) || ! preg_match( '/^\+[1-9][0-9]{6,19}$/', preg_replace( '/[\s\-\(\)]/', '', $phone ) ) ) {
            $errors[] = [ 'field' => 'phone', 'message' => __( 'Teléfono inválido. Usa formato internacional E.164 (ej: +573001112233).', 'ltms' ) ];
        }
        if ( empty( $document_type ) ) {
            $errors[] = [ 'field' => 'document_type', 'message' => __( 'Tipo de documento requerido.', 'ltms' ) ];
        }
        if ( empty( $document_number ) ) {
            $errors[] = [ 'field' => 'document_number', 'message' => __( 'Número de documento requerido.', 'ltms' ) ];
        }
        if ( empty( $store_name ) ) {
            $errors[] = [ 'field' => 'store_name', 'message' => __( 'Nombre de tienda requerido.', 'ltms' ) ];
        }
        if ( empty( $store_address ) ) {
            $errors[] = [ 'field' => 'store_address', 'message' => __( 'Dirección requerida.', 'ltms' ) ];
        }
        if ( ! in_array( $vendor_country, [ 'CO', 'MX' ], true ) ) {
            $errors[] = [ 'field' => 'vendor_country', 'message' => __( 'País inválido.', 'ltms' ) ];
        }
        if ( $vendor_country === 'CO' && empty( $tax_regime ) ) {
            $errors[] = [ 'field' => 'tax_regime', 'message' => __( 'Régimen tributario requerido.', 'ltms' ) ];
        }
        if ( $vendor_country === 'CO' && ! $sagrilaft ) {
            $errors[] = [ 'field' => 'accept_sagrilaft', 'message' => __( 'Debes autorizar SAGRILAFT.', 'ltms' ) ];
        }

        // v2.9.113 P1 FIX: validate document_type and business_type against whitelists.
        $valid_doc_types = ( $vendor_country === 'MX' ) ? [ 'RFC', 'CURP', 'PAS' ] : [ 'CC', 'CE', 'NIT', 'PAS' ];
        if ( ! in_array( $document_type, $valid_doc_types, true ) ) {
            $errors[] = [ 'field' => 'document_type', 'message' => __( 'Tipo de documento inválido para tu país.', 'ltms' ) ];
        }
        if ( ! in_array( $business_type, [ 'physical', 'digital', 'services', 'tourism', 'restaurant' ], true ) ) {
            $errors[] = [ 'field' => 'business_type', 'message' => __( 'Tipo de negocio inválido.', 'ltms' ) ];
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'message' => implode( ' ', array_column( $errors, 'message' ) ),
                'errors'  => $errors,
            ] );
        }

        // Guardar metas.
        update_user_meta( $user_id, 'ltms_phone', LTMS_Utils::format_phone_e164( $phone ) );
        update_user_meta( $user_id, 'ltms_document_type', $document_type );
        // v2.9.113 P0-2 FIX: Use same meta key as normal registration (ltms_document) and encrypt.
        // Before: stored plain in ltms_document_number + encrypted in ltms_document_number_encrypted.
        // Now: single encrypted ltms_document key, consistent with normal registration.
        if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'encrypt' ) ) {
            $encrypted = LTMS_Core_Security::encrypt( $document_number );
            update_user_meta( $user_id, 'ltms_document', $encrypted );
        } else {
            update_user_meta( $user_id, 'ltms_document', $document_number );
        }
        // Also store type (normal registration stores in ltms_document_type).
        update_user_meta( $user_id, 'ltms_document_type', $document_type );
        update_user_meta( $user_id, 'ltms_store_name', $store_name );
        update_user_meta( $user_id, 'ltms_store_address', $store_address );
        // v2.9.113 P0-3 FIX: use ltms_country (consistent with normal registration), not ltms_vendor_country.
        update_user_meta( $user_id, 'ltms_country', $vendor_country );
        update_user_meta( $user_id, 'ltms_tax_regime', $tax_regime );
        update_user_meta( $user_id, 'ltms_business_type', $business_type );

        // Generar store slug.
        if ( class_exists( 'LTMS_Vendor_Storefront' ) ) {
            $store_slug = LTMS_Vendor_Storefront::generate_unique_slug( $store_name, $user_id );
            update_user_meta( $user_id, 'ltms_store_slug', $store_slug );
        }

        // SAGRILAFT consent.
        if ( $sagrilaft && class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'sagrilaft', true, '1.0', 'web' );
        }

        // v2.9.113 P2-15 FIX: persist SAGRILAFT acceptance timestamp for audit trail.
        if ( $sagrilaft ) {
            update_user_meta( $user_id, 'ltms_sagrilaft_accepted_at', LTMS_Utils::now_utc() );
        }

        // v2.9.113 P3-17 FIX: mark email as verified (Google path already verified it).
        update_user_meta( $user_id, 'ltms_email_verified', 1 );
        update_user_meta( $user_id, 'ltms_email_verified_at', LTMS_Utils::now_utc() );

        // Marcar perfil como completo.
        delete_user_meta( $user_id, 'ltms_profile_incomplete' );

        // v2.9.113 P3-18 FIX: Fire action so listeners (Alegra, Affiliates) can run.
        do_action( 'ltms_vendor_registered', $user_id, '' );

        // Log.
        LTMS_Core_Logger::info( 'PROFILE_COMPLETED', sprintf( 'Vendor #%d completó su perfil (Google OAuth path)', $user_id ) );

        // Redirigir al dashboard.
        $pages = get_option( 'ltms_installed_pages', [] );
        $dashboard_id = $pages['ltms-dashboard'] ?? 0;
        $redirect = $dashboard_id ? get_permalink( $dashboard_id ) : home_url( '/panel-vendedor/' );

        wp_send_json_success( [
            'redirect' => $redirect,
            'message'  => __( '¡Perfil completado! Ya puedes publicar productos.', 'ltms' ),
        ] );
    }
}
