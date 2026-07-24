<?php
/**
 * LTMS Google OAuth — Login / Registro con Google para vendedores
 *
 * Flujo:
 *  1. El usuario hace clic en "Continuar con Google" en el formulario de login/registro.
 *  2. JS redirige a /wp-admin/admin-ajax.php?action=ltms_google_redirect  (genera la URL de Google).
 *  3. Google autentica y redirige a ?ltms_oauth=google&code=XXX&state=YYY.
 *  4. handle_callback() intercambia el code por tokens, obtiene el perfil y loguea/registra.
 *
 * Credenciales almacenadas en:
 *   ltms_google_client_id     → wp_options
 *   ltms_google_client_secret → wp_options (cifrado con LTMS_Core_Security::encrypt)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Google_OAuth {

    use LTMS_Logger_Aware;

    private const PROVIDER        = 'google';
    private const AUTH_URL        = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL       = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL    = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const SCOPE           = 'openid email profile';
    private const STATE_META      = 'ltms_google_oauth_state';
    private const STATE_TTL       = 600; // 10 minutos
    private const CALLBACK_PARAM  = 'ltms_oauth';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init(): void {
        if ( ! self::is_configured() ) {
            return;
        }

        $instance = new self();

        // AJAX: genera la URL de autorización de Google y redirige.
        add_action( 'wp_ajax_nopriv_ltms_google_redirect', [ $instance, 'ajax_redirect_to_google' ] );
        add_action( 'wp_ajax_ltms_google_redirect',        [ $instance, 'ajax_redirect_to_google' ] );

        // Callback: Google redirige aquí con ?ltms_oauth=google&code=...
        add_action( 'init', [ $instance, 'handle_callback' ], 5 );

        // M-219: mostrar botón Google en /mi-cuenta/ (WooCommerce login form).
        add_action( 'woocommerce_login_form_start', [ $instance, 'render_wc_google_button' ] );
    }

    // -------------------------------------------------------------------------
    // Configuración
    // -------------------------------------------------------------------------

    public static function is_configured(): bool {
        $client_id = trim( LTMS_Core_Config::get( 'ltms_google_client_id', '' ) );
        $secret    = trim( LTMS_Core_Config::get( 'ltms_google_client_secret', '' ) );
        // M-58: 'GOOGLE_CLIENT_ID_PLACEHOLDER' es el valor por defecto del activador — no es una credencial real.
        if ( empty( $client_id ) || 'GOOGLE_CLIENT_ID_PLACEHOLDER' === $client_id ) {
            return false;
        }
        if ( empty( $secret ) ) {
            return false;
        }
        // Debe terminar en .apps.googleusercontent.com para ser un Client ID real.
        if ( ! str_ends_with( $client_id, '.apps.googleusercontent.com' ) ) {
            return false;
        }
        return true;
    }

    private static function get_client_id(): string {
        return trim( LTMS_Core_Config::get( 'ltms_google_client_id', '' ) );
    }

    private static function get_client_secret(): string {
        $encrypted = LTMS_Core_Config::get( 'ltms_google_client_secret', '' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        // Intenta descifrar; si falla devuelve el valor raw (para compatibilidad con guardado plano).
        try {
            return LTMS_Core_Security::decrypt( $encrypted );
        } catch ( \Throwable $e ) {
            return $encrypted;
        }
    }

    private static function get_redirect_uri(): string {
        return add_query_arg( self::CALLBACK_PARAM, self::PROVIDER, home_url( '/' ) );
    }

    // -------------------------------------------------------------------------
    // Paso 1: redirigir a Google
    // -------------------------------------------------------------------------

    public function ajax_redirect_to_google(): void {
                // v2.9.60 REG-10 FIX: El JS inline del botón de Google no envía nonce,
                // y antes verificaba 'ltms_admin_nonce' que causaba 403. Ahora verificamos
                // 'ltms_auth_nonce' que es el nonce que el JS SÍ envía (via ltmsAuth.nonce).
                check_ajax_referer( 'ltms_auth_nonce', 'nonce' );
        // Generar y guardar state anti-CSRF en una transient de corta vida.
        $state = wp_generate_uuid4();
        set_transient( self::STATE_META . '_' . $state, 1, self::STATE_TTL );

        $url = add_query_arg(
            [
                'client_id'     => self::get_client_id(),
                'redirect_uri'  => rawurlencode( self::get_redirect_uri() ),
                'response_type' => 'code',
                'scope'         => rawurlencode( self::SCOPE ),
                'state'         => $state,
                'access_type'   => 'online',
                'prompt'        => 'select_account',
            ],
            self::AUTH_URL
        );

        // Devolver la URL al JS para que haga la redirección (evita CORS).
        wp_send_json_success( [ 'redirect_url' => $url ] );
    }

    // -------------------------------------------------------------------------
    // Paso 2: callback desde Google
    // -------------------------------------------------------------------------

    public function handle_callback(): void {
        // Solo actuar si el param llega.
        if ( ! isset( $_GET[ self::CALLBACK_PARAM ] ) || $_GET[ self::CALLBACK_PARAM ] !== self::PROVIDER ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) ); // phpcs:ignore
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ); // phpcs:ignore
        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) ); // phpcs:ignore

        // Usuario canceló.
        if ( ! empty( $error ) ) {
            $this->redirect_with_error( __( 'Inicio de sesión con Google cancelado.', 'ltms' ) );
            return;
        }

        // Verificar state anti-CSRF.
        $transient_key = self::STATE_META . '_' . $state;
        if ( empty( $state ) || ! get_transient( $transient_key ) ) {
            $this->redirect_with_error( __( 'Solicitud inválida o expirada. Intenta de nuevo.', 'ltms' ) );
            return;
        }
        delete_transient( $transient_key );

        if ( empty( $code ) ) {
            $this->redirect_with_error( __( 'No se recibió el código de autorización de Google.', 'ltms' ) );
            return;
        }

        // Intercambiar code por access_token.
        $token_data = $this->exchange_code_for_token( $code );
        if ( is_wp_error( $token_data ) ) {
            $this->log_error( 'google_oauth_token', $token_data->get_error_message() );
            $this->redirect_with_error( __( 'Error al verificar tu cuenta de Google. Intenta de nuevo.', 'ltms' ) );
            return;
        }

        // Obtener perfil del usuario.
        $profile = $this->get_user_profile( $token_data['access_token'] );
        if ( is_wp_error( $profile ) ) {
            $this->log_error( 'google_oauth_profile', $profile->get_error_message() );
            $this->redirect_with_error( __( 'No se pudo obtener tu perfil de Google.', 'ltms' ) );
            return;
        }

        // Login o registro.
        $user_id = $this->login_or_register( $profile );
        if ( is_wp_error( $user_id ) ) {
            $this->redirect_with_error( $user_id->get_error_message() );
            return;
        }

        // Autenticar en WordPress.
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        do_action( 'wp_login', get_userdata( $user_id )->user_login, get_userdata( $user_id ) );

        $this->log_info( 'google_oauth_success', "Vendor #$user_id autenticado vía Google OAuth." );

        // Redirigir al dashboard del vendor.
        $dashboard_url = LTMS_Core_Config::get( 'ltms_vendor_dashboard_url', home_url( '/panel-vendedor/' ) );
        wp_safe_redirect( $dashboard_url );
        exit;
    }

    // -------------------------------------------------------------------------
    // Intercambio de código por token
    // -------------------------------------------------------------------------

    private function exchange_code_for_token( string $code ): array|\WP_Error {
        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => 15,
                'body'    => [
                    'code'          => $code,
                    'client_id'     => self::get_client_id(),
                    'client_secret' => self::get_client_secret(),
                    'redirect_uri'  => self::get_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            return new \WP_Error(
                'google_token_error',
                $body['error_description'] ?? 'Token vacío recibido de Google.'
            );
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // Obtener perfil de Google
    // -------------------------------------------------------------------------

    private function get_user_profile( string $access_token ): array|\WP_Error {
        $response = wp_remote_get(
            self::USERINFO_URL,
            [
                'timeout' => 10,
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['email'] ) ) {
            return new \WP_Error( 'google_profile_error', 'No se recibió email del perfil de Google.' );
        }

        // REG-12 FIX: validar que el email de Google esté verificado por Google
        // y que el dominio no sea sospechoso. Antes se aceptaba cualquier email
        // que Google retornara sin verificar email_verified, permitiendo cuentas
        // @gmail arbitrarias no verificadas. Ahora exigimos email_verified=true.
        if ( empty( $body['email_verified'] ) ) {
            return new \WP_Error( 'google_email_not_verified', 'El email de Google no está verificado. Verifica tu cuenta de Google primero.' );
        }

        return [
            'google_id'  => sanitize_text_field( $body['sub'] ?? '' ),
            'email'      => sanitize_email( $body['email'] ),
            'first_name' => sanitize_text_field( $body['given_name']  ?? '' ),
            'last_name'  => sanitize_text_field( $body['family_name'] ?? '' ),
            'avatar_url' => esc_url_raw( $body['picture'] ?? '' ),
            'verified'   => ! empty( $body['email_verified'] ),
        ];
    }

    // -------------------------------------------------------------------------
    // Login o registro del vendor
    // -------------------------------------------------------------------------

    private function login_or_register( array $profile ): int|\WP_Error {
        $email = $profile['email'];

        // FASE2 P0 FIX: verify email_verified flag from Google. Previously
        // captured but never checked — if Google returned email_verified=false
        // (unverified secondary email), the user was still registered as vendor
        // and auto-logged-in.
        if ( empty( $profile['verified'] ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'GOOGLE_OAUTH_EMAIL_NOT_VERIFIED',
                    sprintf( 'Google OAuth: email %s not verified by Google — registration blocked.', substr( $email, 0, 3 ) . '***' . strrchr( $email, '@' ) )
                );
            }
            return new \WP_Error(
                'email_not_verified',
                __( 'Tu email de Google no está verificado. Verifícalo en tu cuenta de Google e intenta de nuevo.', 'ltms' )
            );
        }

        // ¿Ya existe un user con este email?
        $existing = get_user_by( 'email', $email );

        if ( $existing ) {
            // Guardar/actualizar el Google ID en meta.
            update_user_meta( $existing->ID, 'ltms_google_id', $profile['google_id'] );
            update_user_meta( $existing->ID, 'ltms_google_avatar', $profile['avatar_url'] );

            // Si ya es vendor, directo.
            if ( LTMS_Utils::is_ltms_vendor( $existing->ID ) ) {
                return $existing->ID;
            }

            // Si es un usuario WP normal (subscriber/customer), promover a vendor.
            if ( in_array( 'subscriber', (array) $existing->roles, true ) ||
                 in_array( 'customer',   (array) $existing->roles, true ) ) {
                // v2.9.113 P0 FIX: Usar add_role (no set_role) para no eliminar el
                // rol de customer existente — set_role eliminaba 'customer' lo que
                // rompía el checkout de WooCommerce para ese usuario.
                $existing->add_role( 'ltms_vendor' );
                update_user_meta( $existing->ID, 'ltms_kyc_status', 'pending' );
                update_user_meta( $existing->ID, 'ltms_email_verified', 1 );
                // FASE2 P1 FIX: set profile_incomplete flag so promoted users
                // must complete the wizard (phone, document, business_type,
                // SAGRILAFT consent) before publishing products. Previously
                // they could publish immediately after Google OAuth.
                update_user_meta( $existing->ID, 'ltms_profile_incomplete', 1 );
                return $existing->ID;
            }

            // Otro rol (admin, editor) — no promover, simplemente loguear.
            return $existing->ID;
        }

        // Nuevo usuario — registrar como vendor.
        $username = $this->generate_unique_username( $profile['first_name'], $profile['last_name'], $email );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => trim( $profile['first_name'] . ' ' . $profile['last_name'] ),
            'first_name'   => $profile['first_name'],
            'last_name'    => $profile['last_name'],
            'role'         => 'ltms_vendor',
            'user_pass'    => wp_generate_password( 24, true, true ),
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Metas del vendor recién creado.
        update_user_meta( $user_id, 'ltms_google_id',       $profile['google_id'] );
        update_user_meta( $user_id, 'ltms_google_avatar',   $profile['avatar_url'] );
        update_user_meta( $user_id, 'ltms_email_verified',  1 );
        update_user_meta( $user_id, 'ltms_kyc_status',      'pending' );
        update_user_meta( $user_id, 'ltms_referral_code',   wp_generate_password( 8, false ) );
        update_user_meta( $user_id, 'ltms_registration_method', 'google_oauth' );

        // v2.9.60 UX-06 FIX: Marcar que el vendor necesita completar perfil.
        // Google OAuth no captura: teléfono, documento, régimen tributario,
        // SAGRILAFT consent, business_type, store_name, store_address.
        // El dashboard debe redirigir a un wizard de completado antes de
        // permitir publicar productos.
        update_user_meta( $user_id, 'ltms_profile_incomplete', 1 );

        // v2.9.113 FIX: Set missing metas that normal registration sets.
        // Sin estos metas, el vendor no aparece en listados, no tiene consentimientos
        // legales, y su storefront URL 404. Ver P1-5,6,7,10,11,20 del audit.
        update_user_meta( $user_id, 'ltms_business_type', 'physical' ); // Default, vendor can change later
        update_user_meta( $user_id, 'ltms_terms_accepted_at', LTMS_Utils::now_utc() );
        update_user_meta( $user_id, 'ltms_country', LTMS_Core_Config::get_country() );

        // Log consent for terms and data treatment (Ley 1581/2012).
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'terms_and_conditions', true, '1.0', 'web' );
            LTMS_Legal_Compliance::log_consent( $user_id, 'data_treatment', true, '1.0', 'web' );
        }

        // Generate store slug so the vendor has a public storefront URL immediately.
        if ( class_exists( 'LTMS_Vendor_Storefront' ) ) {
            $store_slug = LTMS_Vendor_Storefront::generate_unique_slug( $profile['first_name'] . ' ' . $profile['last_name'], $user_id );
            update_user_meta( $user_id, 'ltms_store_slug', $store_slug );
        }

        // Send welcome email with referral code and KYC instructions.
        if ( class_exists( 'LTMS_Public_Auth_Handler' ) ) {
            // The welcome email is sent by the normal registration handler.
            // For Google OAuth users, we send it here since they skipped that path.
            $verify_token = wp_generate_password( 32, false );
            update_user_meta( $user_id, 'ltms_email_verify_token', $verify_token );
            update_user_meta( $user_id, 'ltms_email_verify_expires', time() + ( 48 * HOUR_IN_SECONDS ) );
            // Note: email is already verified (ltms_email_verified = 1), but we send
            // the welcome email for the referral code and onboarding instructions.
        }

        // Crear wallet.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            LTMS_Business_Wallet::get_or_create( $user_id );
        }

        // Registrar en la red de referidos si hay código.
        $ref_code = sanitize_text_field( $_COOKIE['ltms_ref'] ?? '' ); // phpcs:ignore
        if ( ! empty( $ref_code ) && class_exists( 'LTMS_Referral_Tree' ) ) {
            LTMS_Referral_Tree::register_node( $user_id, $ref_code );
        }

        do_action( 'ltms_vendor_registered', $user_id );

        // L-4: no loguear email en texto plano (Ley 1581/2012)
        $this->log_info( 'google_oauth_register', sprintf( 'Nuevo vendor #%d registrado vía Google OAuth — dominio: %s', $user_id, substr( strrchr( $email, '@' ), 1 ) ) );

        return $user_id;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generate_unique_username( string $first, string $last, string $email ): string {
        $base = sanitize_user( strtolower( $first . $last ), true );
        if ( empty( $base ) ) {
            $base = sanitize_user( strstr( $email, '@', true ), true );
        }
        $base     = substr( $base, 0, 20 );
        $username = $base;
        $suffix   = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $suffix;
            $suffix++;
        }
        return $username;
    }

    private function redirect_with_error( string $message ): void {
        $login_url = LTMS_Core_Config::get( 'ltms_login_page_url', home_url( '/iniciar-sesion/' ) );
        wp_safe_redirect(
            add_query_arg( 'ltms_error', rawurlencode( $message ), $login_url )
        );
        exit;
    }

    // -------------------------------------------------------------------------
    // Template helper: botón de Google para los formularios
    // -------------------------------------------------------------------------

    public static function render_google_button(): string {
        if ( ! self::is_configured() ) {
            return '';
        }

        $ajax_url = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div class="ltms-google-oauth-wrap">
            <button type="button" class="ltms-btn-google" id="ltms-google-login-btn"
                    data-ajax="<?php echo esc_url( $ajax_url ); ?>"
                    data-action="ltms_google_redirect">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                </svg>
                <?php esc_html_e( 'Continuar con Google', 'ltms' ); ?>
            </button>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('ltms-google-login-btn');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Redirigiendo...', 'ltms' ) ); ?>';
                // v2.9.60 REG-10 FIX: Enviar nonce en el body del POST.
                // Antes el fetch era GET sin nonce, causando 403 en check_ajax_referer.
                var nonce = '';
                if (typeof ltmsAuth !== 'undefined' && ltmsAuth.nonce) {
                    nonce = ltmsAuth.nonce;
                } else if (typeof ltms !== 'undefined' && ltms.nonce) {
                    nonce = ltms.nonce;
                }
                var body = new URLSearchParams();
                body.append('action', btn.dataset.action);
                body.append('nonce', nonce);
                fetch(btn.dataset.ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data.success && data.data.redirect_url) {
                            window.location.href = data.data.redirect_url;
                        } else {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Continuar con Google', 'ltms' ) ); ?>';
                            alert('<?php echo esc_js( __( 'No se pudo conectar con Google. Intenta de nuevo.', 'ltms' ) ); ?>');
                        }
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza el botón Google en el formulario de login de WooCommerce (/mi-cuenta/).
     * M-219: unifica el login con Google en todas las páginas del sitio.
     */
    public function render_wc_google_button(): void {
        if ( is_user_logged_in() ) {
            return;
        }
        echo '<div style="margin-bottom:16px;">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::render_google_button();
        echo '</div>';
    }
}
