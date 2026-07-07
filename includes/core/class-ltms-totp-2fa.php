<?php
/**
 * LTMS TOTP 2FA — Implementación real de Time-based One-Time Password.
 *
 * v2.9.27 — Implementa TOTP (RFC 6238) para autenticación de dos factores,
 * reemplazando el marcador `_ltms_2fa_session_verified` que era solo un flag
 * sin verificación criptográfica real.
 *
 * Funciona con Google Authenticator, Microsoft Authenticator, Authy, FreeOTP
 * y cualquier app TOTP compatible RFC 6238.
 *
 * SEC-15 FIX (v2.9.27): 2FA era solo un flag booleano sin TOTP real.
 * Ahora implementa:
 *   - Generación de secret TOTP (Base32, 160 bits)
 *   - QR code URI para enrolamiento (otpauth://)
 *   - Verificación de código TOTP de 6 dígitos (ventana ±1)
 *   - Códigos de backup (10 códigos de un solo uso)
 *   - Rate limiting en verificación (5 intentos/5 min)
 *
 * @package LTMS
 * @version 2.9.27
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_TOTP_2FA {

    /**
     * Tamaño del secret en bits (RFC 6238 recomienda 160).
     */
    private const SECRET_BITS = 160;

    /**
     * Período TOTP en segundos (RFC 6238 default: 30s).
     */
    private const PERIOD = 30;

    /**
     * Longitud del código TOTP (RFC 6238 default: 6 dígitos).
     */
    private const DIGITS = 6;

    /**
     * Ventana de tolerancia (±1 período = ±30 segundos).
     */
    private const WINDOW = 1;

    /**
     * Número de códigos de backup generados.
     */
    const BACKUP_CODES_COUNT = 10;

    /**
     * Máximo intentos antes de bloqueo temporal.
     */
    const MAX_ATTEMPTS = 5;

    /**
     * Ventana de bloqueo temporal (5 minutos).
     */
    const LOCKOUT_WINDOW = 300;

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // Hook en el login de WordPress para verificar 2FA.
        add_action( 'wp_login', [ __CLASS__, 'intercept_login_for_2fa' ], 30, 2 );

        // AJAX para verificar código TOTP tras login.
        add_action( 'wp_ajax_ltms_verify_2fa', [ __CLASS__, 'ajax_verify_2fa' ] );
        add_action( 'wp_ajax_nopriv_ltms_verify_2fa', [ __CLASS__, 'ajax_verify_2fa' ] );

        // AJAX para configurar 2FA (enrolamiento).
        add_action( 'wp_ajax_ltms_setup_2fa', [ __CLASS__, 'ajax_setup_2fa' ] );
        add_action( 'wp_ajax_ltms_confirm_2fa', [ __CLASS__, 'ajax_confirm_2fa' ] );
        add_action( 'wp_ajax_ltms_disable_2fa', [ __CLASS__, 'ajax_disable_2fa' ] );
        // v2.9.68 P2-18: Admin force-disable 2FA (lost phone recovery).
        add_action( 'wp_ajax_ltms_admin_reset_2fa', [ __CLASS__, 'ajax_admin_reset_2fa' ] );

        // Página intermedia de verificación 2FA.
        add_action( 'login_form_ltms_2fa', [ __CLASS__, 'render_2fa_challenge_page' ] );
    }

    // ================================================================
    // GENERACIÓN Y VERIFICACIÓN TOTP.
    // ================================================================

    /**
     * Genera un nuevo secret TOTP en Base32.
     *
     * @return string Secret Base32 (32 caracteres).
     */
    public static function generate_secret(): string {
        $bytes = random_bytes( self::SECRET_BITS / 8 ); // 20 bytes = 160 bits.
        return self::base32_encode( $bytes );
    }

    /**
     * Genera la URI otpauth:// para QR code.
     *
     * @param string $secret   Secret Base32.
     * @param string $username Nombre del usuario (label).
     * @return string URI otpauth://
     */
    public static function generate_otpauth_uri( string $secret, string $username ): string {
        $issuer = rawurlencode( get_bloginfo( 'name' ) );
        $label  = rawurlencode( $issuer . ':' . $username );
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&period=" . self::PERIOD . '&digits=' . self::DIGITS;
    }

    /**
     * Verifica un código TOTP de 6 dígitos.
     *
     * @param string $secret Secret Base32 del usuario.
     * @param string $code   Código de 6 dígitos a verificar.
     * @return bool True si el código es válido.
     */
    public static function verify_code( string $secret, string $code ): bool {
        if ( strlen( $code ) !== self::DIGITS || ! ctype_digit( $code ) ) {
            return false;
        }

        $secret_bytes = self::base32_decode( $secret );
        if ( $secret_bytes === false ) {
            return false;
        }

        $timestamp = time();
        $counter = intdiv( $timestamp, self::PERIOD );

        // Verificar ventana ±1 (current, previous, next).
        for ( $offset = -self::WINDOW; $offset <= self::WINDOW; $offset++ ) {
            $expected = self::calculate_totp( $secret_bytes, $counter + $offset );
            if ( hash_equals( $expected, $code ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula el TOTP para un contador dado (RFC 4226 HOTP con T = counter).
     *
     * @param string $secret  Secret binario.
     * @param int    $counter Contador de tiempo.
     * @return string Código de 6 dígitos.
     */
    private static function calculate_totp( string $secret, int $counter ): string {
        $binary_counter = pack( 'N*', 0 ) . pack( 'N*', $counter );
        $hash = hash_hmac( 'sha1', $binary_counter, $secret, true );

        $offset = ord( $hash[strlen( $hash ) - 1] ) & 0x0F;
        $binary = (
            ( ( ord( $hash[$offset] ) & 0x7F ) << 24 ) |
            ( ( ord( $hash[$offset + 1] ) & 0xFF ) << 16 ) |
            ( ( ord( $hash[$offset + 2] ) & 0xFF ) << 8 ) |
            ( ord( $hash[$offset + 3] ) & 0xFF )
        );

        $otp = $binary % pow( 10, self::DIGITS );
        return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
    }

    /**
     * Genera códigos de backup (10 códigos de 8 dígitos hex).
     *
     * @return array 10 códigos de backup.
     */
    public static function generate_backup_codes(): array {
        $codes = [];
        for ( $i = 0; $i < self::BACKUP_CODES_COUNT; $i++ ) {
            $bytes = random_bytes( 4 );
            $codes[] = bin2hex( $bytes ); // 8 hex chars.
        }
        return $codes;
    }

    /**
     * Verifica un código de backup (y lo consume si es válido).
     *
     * @param int    $user_id ID del usuario.
     * @param string $code    Código de backup.
     * @return bool True si el código era válido (y se consumió).
     */
    public static function verify_backup_code( int $user_id, string $code ): bool {
        $codes = get_user_meta( $user_id, '_ltms_2fa_backup_codes', true );
        if ( ! is_array( $codes ) || empty( $codes ) ) {
            return false;
        }

        // Los códigos se almacenan hasheados con wp_hash_password.
        foreach ( $codes as $index => $hashed ) {
            if ( wp_check_password( $code, $hashed ) ) {
                // Consumir el código (remover del array).
                unset( $codes[ $index ] );
                update_user_meta( $user_id, '_ltms_2fa_backup_codes', array_values( $codes ) );
                return true;
            }
        }
        return false;
    }

    // ================================================================
    // INTERCEPTACIÓN DE LOGIN.
    // ================================================================

    /**
     * Intercepta el login para verificar 2FA si el usuario lo tiene activado.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       Usuario.
     */
    public static function intercept_login_for_2fa( string $user_login, \WP_User $user ): void {
        $has_2fa = get_user_meta( $user->ID, '_ltms_2fa_enabled', true ) === 'yes';
        if ( ! $has_2fa ) {
            // Verificar si 2FA es obligatorio para este usuario.
            $required = self::is_2fa_required( $user );
            if ( ! $required ) {
                return;
            }
            // Si es obligatorio pero no está configurado → forzar configuración.
            // Por ahora, solo bloquear si está configurado.
            return;
        }

        // El usuario tiene 2FA activado → destruir sesión actual y redirigir a challenge.
        $secret = get_user_meta( $user->ID, '_ltms_2fa_secret', true );
        if ( empty( $secret ) ) {
            return; // Secret perdido, permitir login (debería reconfigurar).
        }

        // Marcar sesión como pendiente de 2FA.
        wp_set_current_user( 0 ); // Logout temporal.
        wp_clear_auth_cookie();

        // Guardar user_id en transient para recuperar tras verify.
        $session_token = wp_generate_password( 32, false );
        set_transient(
            'ltms_2fa_pending_' . $session_token,
            [
                'user_id' => $user->ID,
                'created' => time(),
            ],
            10 * MINUTE_IN_SECONDS // 10 minutos para completar 2FA.
        );

        // Redirigir a página de challenge.
        $redirect_url = wp_login_url() . '?action=ltms_2fa&token=' . $session_token;
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Verifica si 2FA es obligatorio para un usuario.
     */
    private static function is_2fa_required( \WP_User $user ): bool {
        // Auditors: obligatorio.
        if ( in_array( 'ltms_external_auditor', $user->roles, true ) ) {
            return LTMS_Core_Config::get( 'ltms_2fa_required_auditors', 'yes' ) === 'yes';
        }

        // Vendors con payouts recientes: obligatorio.
        if ( in_array( 'vendor', $user->roles, true ) ) {
            if ( LTMS_Core_Config::get( 'ltms_2fa_required_vendors', 'yes' ) === 'yes' ) {
                return self::vendor_has_recent_payouts( $user->ID );
            }
        }

        // Admins: recomendado pero no forzado por defecto.
        if ( in_array( 'administrator', $user->roles, true ) ) {
            return LTMS_Core_Config::get( 'ltms_2fa_required_admins', 'no' ) === 'yes';
        }

        return false;
    }

    /**
     * Verifica si un vendor ha tenido payouts en los últimos 30 días.
     */
    private static function vendor_has_recent_payouts( int $vendor_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        $since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d AND created_at >= %s",
            $vendor_id, $since
        ) );
    }

    // ================================================================
    // PÁGINA DE CHALLENGE 2FA.
    // ================================================================

    /**
     * Renderiza la página intermedia de verificación 2FA.
     */
    public static function render_2fa_challenge_page(): void {
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( empty( $token ) ) {
            wp_die( esc_html__( 'Token 2FA inválido.', 'ltms' ) );
        }

        $pending = get_transient( 'ltms_2fa_pending_' . $token );
        if ( ! $pending || ! isset( $pending['user_id'] ) ) {
            wp_die( esc_html__( 'Sesión 2FA expirada. Inicia sesión nuevamente.', 'ltms' ) );
        }

        // Verificar expiración (10 minutos).
        if ( time() - $pending['created'] > 600 ) {
            delete_transient( 'ltms_2fa_pending_' . $token );
            wp_die( esc_html__( 'Sesión 2FA expirada. Inicia sesión nuevamente.', 'ltms' ) );
        }

        $user = get_userdata( $pending['user_id'] );
        if ( ! $user ) {
            wp_die( esc_html__( 'Usuario no encontrado.', 'ltms' ) );
        }

        // Verificar rate limiting.
        $attempts = (int) get_transient( 'ltms_2fa_attempts_' . $pending['user_id'] );
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            wp_die( esc_html__( 'Demasiados intentos de 2FA. Espera 5 minutos.', 'ltms' ) );
        }

        login_header(
            __( 'Verificación 2FA — Lo Tengo', 'ltms' ),
            '',
            new \WP_Error( '2fa_required', __( 'Ingresa el código de 6 dígitos de tu app autenticadora.', 'ltms' ) )
        );
        ?>
        <form name="ltms-2fa-form" id="ltms-2fa-form" method="post">
            <p>
                <label for="ltms-2fa-code"><?php esc_html_e( 'Código de 6 dígitos', 'ltms' ); ?><br>
                <input type="text" name="ltms_2fa_code" id="ltms-2fa-code"
                       class="input" size="6" maxlength="6" pattern="[0-9]{6}"
                       inputmode="numeric" autocomplete="one-time-code" autofocus required />
                </label>
            </p>
            <p>
                <label for="ltms-2fa-backup"><?php esc_html_e( 'O código de backup (opcional)', 'ltms' ); ?><br>
                <input type="text" name="ltms_2fa_backup" id="ltms-2fa-backup"
                       class="input" size="20" maxlength="8" />
                </label>
            </p>
            <input type="hidden" name="ltms_2fa_token" value="<?php echo esc_attr( $token ); ?>" />
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
                       value="<?php esc_attr_e( 'Verificar', 'ltms' ); ?>" />
            </p>
        </form>
        <p id="backtoblog">
            <a href="<?php echo esc_url( wp_logout_url() ); ?>">← <?php esc_html_e( 'Cancelar', 'ltms' ); ?></a>
        </p>
        <script>
        document.getElementById('ltms-2fa-form').addEventListener('submit', function(e) {
            var code = document.getElementById('ltms-2fa-code').value;
            var backup = document.getElementById('ltms-2fa-backup').value;
            if (!code && !backup) { e.preventDefault(); alert('Ingresa un código.'); }
        });
        </script>
        <?php
        login_footer();
        exit;
    }

    // ================================================================
    // AJAX ENDPOINTS.
    // ================================================================

    /**
     * AJAX: verificar código 2FA (desde página challenge).
     */
    public static function ajax_verify_2fa(): void {
        $token = sanitize_text_field( $_POST['token'] ?? '' );
        $code  = sanitize_text_field( $_POST['code'] ?? '' );
        $backup = sanitize_text_field( $_POST['backup'] ?? '' );

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Token inválido.', 'ltms' ) ], 400 );
        }

        $pending = get_transient( 'ltms_2fa_pending_' . $token );
        if ( ! $pending || ! isset( $pending['user_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Sesión expirada.', 'ltms' ) ], 401 );
        }

        $user_id = (int) $pending['user_id'];

        // Rate limiting.
        $attempts_key = 'ltms_2fa_attempts_' . $user_id;
        $attempts = (int) get_transient( $attempts_key );
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados intentos. Espera 5 minutos.', 'ltms' ) ], 429 );
        }

        $secret = get_user_meta( $user_id, '_ltms_2fa_secret', true );
        $verified = false;

        // Intentar código TOTP.
        if ( ! empty( $code ) && ! empty( $secret ) ) {
            $verified = self::verify_code( $secret, $code );
        }

        // Intentar código de backup.
        if ( ! $verified && ! empty( $backup ) ) {
            $verified = self::verify_backup_code( $user_id, $backup );
        }

        if ( ! $verified ) {
            // Incrementar intentos.
            set_transient( $attempts_key, $attempts + 1, self::LOCKOUT_WINDOW );
            wp_send_json_error( [ 'message' => __( 'Código incorrecto.', 'ltms' ) ], 401 );
        }

        // Éxito: limpiar transients y autenticar.
        delete_transient( 'ltms_2fa_pending_' . $token );
        delete_transient( $attempts_key );

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        update_user_meta( $user_id, '_ltms_2fa_last_verified', current_time( 'mysql', true ) );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( '2FA_VERIFY_SUCCESS', sprintf( 'User #%d verificó 2FA exitosamente.', $user_id ) );
        }

        wp_send_json_success( [ 'redirect' => admin_url() ] );
    }

    /**
     * v2.9.31: Verifica nonce del admin o del dashboard del vendor.
     *
     * Los endpoints 2FA son usados tanto desde el admin (nonce ltms_admin_nonce)
     * como desde el dashboard del vendedor (nonce ltms_dashboard_nonce). Este
     * helper acepta cualquiera de los dos para no duplicar lógica.
     *
     * @return void  Emite 403 si el nonce es inválido.
     */
    private static function check_2fa_nonce(): void {
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'ltms_admin_nonce' ) && ! wp_verify_nonce( $nonce, 'ltms_dashboard_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Nonce inválido. Recarga la página e intenta de nuevo.', 'ltms' ) ], 403 );
        }
    }

    /**
     * AJAX: configurar 2FA (genera secret + QR URI).
     */
    public static function ajax_setup_2fa(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }
        // v2.9.31: aceptar nonce del admin o del dashboard del vendor.
        self::check_2fa_nonce();

        $user_id = get_current_user_id();

        // v2.9.62 DEEP-AUDIT-002 P2-17: Rate limit para setup (5/hora).
        $throttle_key = 'ltms_2fa_setup_attempts_' . $user_id;
        $attempts = (int) get_transient( $throttle_key );
        if ( $attempts >= 5 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados intentos de configuración. Espera una hora.', 'ltms' ) ], 429 );
        }
        set_transient( $throttle_key, $attempts + 1, HOUR_IN_SECONDS );

        $secret = self::generate_secret();
        $user = wp_get_current_user();

        // Guardar secret temporalmente (no activar hasta confirmación).
        update_user_meta( $user_id, '_ltms_2fa_pending_secret', $secret );

        $uri = self::generate_otpauth_uri( $secret, $user->user_login );

        wp_send_json_success( [
            'secret' => $secret,
            'uri'    => $uri,
            'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode( $uri ),
        ] );
    }

    /**
     * AJAX: confirmar 2FA (verifica primer código y activa).
     */
    public static function ajax_confirm_2fa(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }
        self::check_2fa_nonce();

        $user_id = get_current_user_id();

        // v2.9.61 DEEP-AUDIT-002 P1 FIX: Rate limit para prevenir brute force de códigos TOTP.
        // TOTP tiene 1,000,000 combinaciones (6 dígitos) con ventana de 30s.
        // Sin rate limit, un atacante con el session cookie podría probar 100 códigos/min.
        $throttle_key = 'ltms_2fa_confirm_attempts_' . $user_id;
        $attempts = (int) get_transient( $throttle_key );
        if ( $attempts >= 10 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados intentos. Espera 15 minutos.', 'ltms' ) ], 429 );
        }

        $code = sanitize_text_field( $_POST['code'] ?? '' );
        $secret = get_user_meta( $user_id, '_ltms_2fa_pending_secret', true );

        if ( empty( $secret ) ) {
            wp_send_json_error( [ 'message' => __( 'No hay secret pendiente. Genera uno nuevo.', 'ltms' ) ], 400 );
        }

        if ( ! self::verify_code( $secret, $code ) ) {
            // Incrementar contador de intentos fallidos.
            set_transient( $throttle_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            $remaining = 10 - $attempts - 1;
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %d: intentos restantes */
                    _n( 'Código incorrecto. %d intento restante.', 'Código incorrecto. %d intentos restantes.', $remaining, 'ltms' ),
                    $remaining
                ),
            ], 401 );
        }

        // Limpiar contador en éxito.
        delete_transient( $throttle_key );

        // Activar 2FA.
        update_user_meta( $user_id, '_ltms_2fa_secret', $secret );
        update_user_meta( $user_id, '_ltms_2fa_enabled', 'yes' );
        update_user_meta( $user_id, '_ltms_2fa_enabled_at', current_time( 'mysql', true ) );
        delete_user_meta( $user_id, '_ltms_2fa_pending_secret' );

        // Generar códigos de backup.
        $backup_codes = self::generate_backup_codes();
        $hashed_codes = array_map( 'wp_hash_password', $backup_codes );
        update_user_meta( $user_id, '_ltms_2fa_backup_codes', $hashed_codes );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( '2FA_SETUP_COMPLETE', sprintf( 'User #%d configuró 2FA exitosamente.', $user_id ) );
        }

        wp_send_json_success( [
            'message'      => __( '2FA activado. Guarda tus códigos de backup.', 'ltms' ),
            'backup_codes' => $backup_codes, // Solo se muestran una vez.
        ] );
    }

    /**
     * AJAX: desactivar 2FA (requiere verificación de código actual).
     */
    public static function ajax_disable_2fa(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }
        self::check_2fa_nonce();

        $user_id = get_current_user_id();

        // v2.9.61 DEEP-AUDIT-002 P1 FIX: Rate limit para prevenir brute force.
        $throttle_key = 'ltms_2fa_disable_attempts_' . $user_id;
        $attempts = (int) get_transient( $throttle_key );
        if ( $attempts >= 10 ) {
            wp_send_json_error( [ 'message' => __( 'Demasiados intentos. Espera 15 minutos.', 'ltms' ) ], 429 );
        }

        $code = sanitize_text_field( $_POST['code'] ?? '' );
        $secret = get_user_meta( $user_id, '_ltms_2fa_secret', true );

        if ( empty( $secret ) ) {
            wp_send_json_error( [ 'message' => __( '2FA no está configurado.', 'ltms' ) ], 400 );
        }

        // Verificar código antes de desactivar.
        if ( ! self::verify_code( $secret, $code ) && ! self::verify_backup_code( $user_id, $code ) ) {
            set_transient( $throttle_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            $remaining = 10 - $attempts - 1;
            wp_send_json_error( [
                'message' => sprintf(
                    _n( 'Código incorrecto. %d intento restante.', 'Código incorrecto. %d intentos restantes.', $remaining, 'ltms' ),
                    $remaining
                ),
            ], 401 );
        }

        // Limpiar contador en éxito.
        delete_transient( $throttle_key );

        // Desactivar.
        delete_user_meta( $user_id, '_ltms_2fa_secret' );
        delete_user_meta( $user_id, '_ltms_2fa_enabled' );
        delete_user_meta( $user_id, '_ltms_2fa_enabled_at' );
        delete_user_meta( $user_id, '_ltms_2fa_backup_codes' );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning( '2FA_DISABLED', sprintf( 'User #%d desactivó 2FA.', $user_id ) );
        }

        wp_send_json_success( [ 'message' => __( '2FA desactivado.', 'ltms' ) ] );
    }

    /**
     * v2.9.68 DEEP-AUDIT-002 P2-18: Admin force-disable 2FA (lost phone recovery).
     * Permite a un admin desactivar el 2FA de un vendor que perdió su teléfono.
     * Requiere capability manage_options y nonce ltms_admin_nonce.
     */
    public static function ajax_admin_reset_2fa(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }

        $user_id = absint( $_POST['user_id'] ?? 0 ); // phpcs:ignore
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( 'Usuario inválido.', 'ltms' ) ], 400 );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( [ 'message' => __( 'Usuario no encontrado.', 'ltms' ) ], 404 );
        }

        // Eliminar todos los metas de 2FA.
        delete_user_meta( $user_id, '_ltms_2fa_secret' );
        delete_user_meta( $user_id, '_ltms_2fa_enabled' );
        delete_user_meta( $user_id, '_ltms_2fa_enabled_at' );
        delete_user_meta( $user_id, '_ltms_2fa_backup_codes' );
        delete_user_meta( $user_id, '_ltms_2fa_pending_secret' );

        // Log de auditoría.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                '2FA_ADMIN_RESET',
                sprintf(
                    'Admin #%d reseteó 2FA del usuario #%d (%s) — lost phone recovery',
                    get_current_user_id(),
                    $user_id,
                    $user->user_login
                )
            );
        }

        wp_send_json_success( [ 'message' => sprintf( __( '2FA desactivado para %s.', 'ltms' ), $user->display_name ) ] );
    }

    // ================================================================
    // BASE32 ENCODING/DECODING (RFC 4648).
    // ================================================================

    /**
     * Codifica bytes a Base32.
     */
    private static function base32_encode( string $data ): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $bits = 0;
        $value = 0;

        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $value = ( $value << 8 ) | ord( $data[$i] );
            $bits += 8;
            while ( $bits >= 5 ) {
                $result .= $alphabet[( $value >> ( $bits - 5 ) ) & 0x1F];
                $bits -= 5;
            }
        }
        if ( $bits > 0 ) {
            $result .= $alphabet[( $value << ( 5 - $bits ) ) & 0x1F];
        }
        return $result;
    }

    /**
     * Decodifica Base32 a bytes.
     */
    private static function base32_decode( string $data ): string|false {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper( $data );
        $result = '';
        $bits = 0;
        $value = 0;

        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $pos = strpos( $alphabet, $data[$i] );
            if ( $pos === false ) {
                continue; // Skip invalid chars (padding, etc).
            }
            $value = ( $value << 5 ) | $pos;
            $bits += 5;
            if ( $bits >= 8 ) {
                $result .= chr( ( $value >> ( $bits - 8 ) ) & 0xFF );
                $bits -= 8;
            }
        }
        return $result;
    }
}
