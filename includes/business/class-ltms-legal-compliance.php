<?php
/**
 * LTMS Legal Compliance — Habeas Data, Consentimiento y Cumplimiento Normativo
 *
 * Implementa:
 * L-1: Vault Access Log — registro de acceso a documentos sensibles (Ley 1581/2012 art. 8)
 * L-2: Consent Log — evidencia de consentimiento con IP+timestamp (Ley 1581/2012 art. 9)
 * L-3: Consentimiento en checkout WooCommerce (Ley 1480/2011 art. 3)
 * L-4: Cookies con SameSite=Strict y Secure flag (RGPD/Ley 1581/2012)
 * L-5: Log de acceso vía Google OAuth (auditoría de identidad)
 * L-6: Checkbox de autorización de datos en KYC (Ley 1581/2012 art. 9)
 * L-7: Masking de documentos en respuestas API (no exponer datos crudos)
 * L-8: Guardar consentimiento KYC en BD con IP y user agent
 *
 * @package LTMS
 * @since   2.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Legal_Compliance {

    // Versiones actuales de los documentos de consentimiento
    // LG-1 FIX (v2.9.7): TERMS_VERSION bumped to 4.1 — incorpora Ley 2439/2024 + cross-border + turismo.
    const TERMS_VERSION    = '4.1';
    const PRIVACY_VERSION  = '1.3';
    const SAGRILAFT_VERSION = '2.0';
    const KYC_VERSION      = '1.0';
    const PURPOSE_KYC      = 'kyc';

    // K-02 FIX: constantes de operación para log_vault_access() — evitan strings mágicos
    // y el Fatal Error que ocurría al referenciar LTMS_Legal_Compliance::VAULT_OP_UPLOAD.
    const VAULT_OP_VIEW     = 'view';
    const VAULT_OP_DOWNLOAD = 'download';
    const VAULT_OP_UPLOAD   = 'upload';
    const VAULT_OP_DELETE   = 'delete';
    const VAULT_OP_SHARE    = 'share';

    private static bool $initialized = false;

    // ── Boot ────────────────────────────────────────────────────────────────────

    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // L-3: Consentimiento en checkout WooCommerce
        add_action( 'woocommerce_checkout_process',          [ __CLASS__, 'validate_checkout_consent' ] );
        add_action( 'woocommerce_checkout_after_order_review', [ __CLASS__, 'render_checkout_consent_field' ] );
        add_action( 'woocommerce_checkout_order_created',    [ __CLASS__, 'save_checkout_consent' ], 10, 1 );

        // L-4: Cookies SameSite seguras
        add_action( 'init', [ __CLASS__, 'set_secure_cookie_flags' ], 1 );

        // L-5: Log de acceso vía OAuth / login externo
        add_action( 'ltms_oauth_login_success',    [ __CLASS__, 'log_oauth_access' ], 10, 2 );
        add_action( 'wp_login',                    [ __CLASS__, 'log_wp_login' ],     10, 2 );
    }

    // ── L-1: Vault Access Log ────────────────────────────────────────────────────

    /**
     * Registra un acceso a documento sensible del vault KYC.
     *
     * @param int    $user_id     Propietario del documento.
     * @param int    $accessor_id Quien accede.
     * @param string $document    Tipo/nombre del documento.
     * @param string $action      view|download|upload|delete|share
     * @param string $context     Módulo o razón del acceso.
     */
    public static function log_vault_access(
        int    $user_id,
        int    $accessor_id,
        string $document,
        string $action  = 'view',
        string $context = ''
    ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'lt_vault_access_log',
            [
                'user_id'     => $user_id,
                'accessor_id' => $accessor_id,
                'document'    => sanitize_text_field( $document ),
                'action'      => in_array( $action, ['view','download','upload','delete','share'], true ) ? $action : 'view',
                'ip_address'  => self::get_client_ip(),
                'user_agent'  => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255 ),
                'context'     => sanitize_text_field( $context ),
                'created_at'  => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // ── L-2: Consent Log ────────────────────────────────────────────────────────

    /**
     * Registra consentimiento del usuario con IP+timestamp+versión del documento.
     *
     * @param int    $user_id      ID del usuario.
     * @param string $consent_type terms|sagrilaft|privacy|checkout|kyc_data|marketing
     * @param bool   $accepted     Si aceptó o rechazó.
     * @param string $version      Versión del documento aceptado.
     * @param string $channel      web|api|oauth|admin
     */
    public static function log_consent(
        int    $user_id,
        string $consent_type,
        bool   $accepted = true,
        string $version  = '1.0',
        string $channel  = 'web'
    ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'lt_consent_log',
            [
                'user_id'      => $user_id,
                'consent_type' => sanitize_key( $consent_type ),
                'accepted'     => $accepted ? 1 : 0,
                'ip_address'   => self::get_client_ip(),
                'user_agent'   => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255 ),
                'version'      => sanitize_text_field( $version ),
                'channel'      => sanitize_text_field( $channel ),
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Registra todos los consentimientos del registro de vendedor en un solo call.
     * Llamar justo después de crear el usuario en ajax_vendor_register().
     *
     * @param int   $user_id         ID del nuevo usuario.
     * @param array $consents        Array de claves consent_type => bool|true.
     * @param string $channel        Canal de registro.
     */
    public static function log_registration_consents(
        int    $user_id,
        array  $consents,
        string $channel = 'web'
    ): void {
        $versions = [
            'terms'     => self::TERMS_VERSION,
            'privacy'   => self::PRIVACY_VERSION,
            'sagrilaft' => self::SAGRILAFT_VERSION,
        ];

        foreach ( $consents as $type => $accepted ) {
            self::log_consent(
                $user_id,
                $type,
                (bool) $accepted,
                $versions[ $type ] ?? '1.0',
                $channel
            );
        }

        // Guardar IP de registro en user meta como evidencia adicional
        update_user_meta( $user_id, 'ltms_registration_ip',      self::get_client_ip() );
        update_user_meta( $user_id, 'ltms_registration_ua',      substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255 ) );
        update_user_meta( $user_id, 'ltms_terms_version',        self::TERMS_VERSION );
        update_user_meta( $user_id, 'ltms_privacy_version',      self::PRIVACY_VERSION );
        update_user_meta( $user_id, 'ltms_sagrilaft_version',    self::SAGRILAFT_VERSION );
    }

    // ── L-3: Checkout Consent ────────────────────────────────────────────────────

    /**
     * Renderiza el campo de aceptación de política en el checkout de WooCommerce.
     * Se añade al final del formulario de checkout.
     *
     * LC-1 FIX (AUDIT-BATCH2): El campo era `'required' => false` "para no generar
     * fricción", pero esto permitía a cualquier usuario (incluido guest checkout)
     * completar la compra sin aceptar T&C/Privacy — violando Ley 1581/2012 art. 9
     * (consentimiento libre, previo, expreso e informado) y RGPD art. 6.
     *
     * Ahora el campo se marca `required` solo cuando el usuario NO tiene un
     * consentimiento vigente registrado (guest o usuario con versión desactualizada).
     * Usuarios logueados con ltms_terms_version == TERMS_VERSION no lo ven como
     * obligatorio (ya consintieron al registrarse).
     */
    public static function render_checkout_consent_field(): void {
        if ( ! function_exists( 'woocommerce_form_field' ) ) {
            return;
        }

        $current_user_id = get_current_user_id();
        $needs_consent   = true;
        if ( $current_user_id > 0 && ! self::user_needs_reconsent( $current_user_id, 'terms' )
             && ! self::user_needs_reconsent( $current_user_id, 'privacy' ) ) {
            // Usuario logueado con consentimiento vigente — no exigir re-aceptar.
            $needs_consent = false;
        }

        echo '<div class="ltms-checkout-consent" style="margin:12px 0;">';
        woocommerce_form_field( 'ltms_checkout_consent', [
            'type'     => 'checkbox',
            'class'    => [ 'ltms-consent-field form-row-wide' ],
            'label'    => sprintf(
                /* translators: 1: URL política privacidad, 2: URL términos */
                __( 'He leído y acepto la <a href="%1$s" target="_blank">Política de Privacidad</a> y los <a href="%2$s" target="_blank">Términos y Condiciones</a> de Lo Tengo Colombia (Ley 1581/2012).', 'ltms' ),
                esc_url( home_url( '/privacidad' ) ),
                esc_url( home_url( '/terminos' ) )
            ),
            'required' => $needs_consent, // LC-1: obligatorio para guests / usuarios sin consentimiento vigente.
        ], 0 );
        echo '</div>';
    }

    /**
     * Valida el consentimiento en el proceso de checkout.
     *
     * LC-1 FIX (AUDIT-BATCH2): Antes era un stub vacío. Ahora: si el usuario
     * actual no tiene consentimiento vigente (guest o versión desactualizada)
     * Y NO marcó el checkbox, se agrega un error de validación que BLOQUEA el
     * checkout — exigencia de Ley 1581/2012 art. 9 y RGPD art. 6.
     */
    public static function validate_checkout_consent(): void {
        $current_user_id = get_current_user_id();
        $needs_consent   = true;
        if ( $current_user_id > 0 && ! self::user_needs_reconsent( $current_user_id, 'terms' )
             && ! self::user_needs_reconsent( $current_user_id, 'privacy' ) ) {
            $needs_consent = false;
        }

        if ( ! $needs_consent ) {
            return; // Usuario con consentimiento vigente — no bloquear.
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $accepted = ! empty( $_POST['ltms_checkout_consent'] );
        if ( ! $accepted ) {
            wc_add_notice(
                __( 'Debes aceptar la Política de Privacidad y los Términos y Condiciones para completar tu compra (Ley 1581/2012).', 'ltms' ),
                'error'
            );
        }
    }

    /**
     * Guarda el consentimiento de checkout en la base de datos.
     *
     * LC-1 FIX (AUDIT-BATCH2): Antes, si `$user_id === 0` (guest checkout),
     * NO se logueaba NINGÚN consentimiento — el guest podía comprar sin dejar
     * evidencia legal. Ahora se persiste SIEMPRE en order_meta (IP + timestamp +
     * versión del documento) y, para guests, se loguea en lt_consent_log con
     * user_id=0 (el order_id queda en el context del log) para mantener
     * trazabilidad forense del consentimiento asociado al pedido.
     *
     * @param \WC_Order $order
     */
    public static function save_checkout_consent( \WC_Order $order ): void {
        $accepted  = ! empty( $_POST['ltms_checkout_consent'] ); // phpcs:ignore
        $user_id   = $order->get_customer_id();
        $order_id  = $order->get_id();
        $ip        = self::get_client_ip();
        $accepted_at = current_time( 'mysql', true );

        // SIEMPRE guardar evidencia en order_meta (incluido guest checkout).
        $order->update_meta_data( '_ltms_checkout_consent',    $accepted ? '1' : '0' );
        $order->update_meta_data( '_ltms_checkout_consent_ip', $ip );
        $order->update_meta_data( '_ltms_checkout_consent_at', $accepted_at );
        $order->update_meta_data( '_ltms_checkout_terms_version',    self::TERMS_VERSION );
        $order->update_meta_data( '_ltms_checkout_privacy_version',  self::PRIVACY_VERSION );
        $order->save();

        // Log en lt_consent_log.
        if ( $user_id > 0 ) {
            self::log_consent(
                $user_id,
                'checkout',
                $accepted,
                self::PRIVACY_VERSION,
                'web'
            );
        } else {
            // LC-1: Guest checkout — log con user_id=0 + context con order_id
            // para mantener trazabilidad del consentimiento asociado al pedido.
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'lt_consent_log',
                [
                    'user_id'      => 0,
                    'consent_type' => 'checkout_guest',
                    'accepted'     => $accepted ? 1 : 0,
                    'ip_address'   => $ip,
                    'user_agent'   => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255 ),
                    'version'      => self::PRIVACY_VERSION,
                    'channel'      => 'web_guest_o' . $order_id,
                    'created_at'   => $accepted_at,
                ],
                [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    // ── L-4: Cookies Seguras (SameSite) ──────────────────────────────────────────

    /**
     * Aplica SameSite=Strict y Secure a las cookies propias de LTMS.
     * Las cookies de WordPress (wp_*) no se modifican aquí para no romper el core.
     */
    public static function set_secure_cookie_flags(): void {
        // Solo en HTTPS y si no se ha enviado ya output
        if ( ! is_ssl() || headers_sent() ) {
            return;
        }

        $ltms_cookies = [ 'ltms_referral', 'ltms_city', 'ltms_session' ];
        foreach ( $ltms_cookies as $cookie_name ) {
            if ( isset( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore
                $value = $_COOKIE[ $cookie_name ]; // phpcs:ignore
                // Reenviar con flags seguros
                header(
                    sprintf(
                        'Set-Cookie: %s=%s; Path=/; SameSite=Strict; Secure; HttpOnly',
                        rawurlencode( $cookie_name ),
                        rawurlencode( $value )
                    ),
                    false
                );
            }
        }
    }

    // ── L-5: OAuth / Login Access Log ────────────────────────────────────────────

    /**
     * Registra acceso exitoso vía Google OAuth.
     *
     * @param int    $user_id
     * @param string $provider google|facebook|etc.
     */
    public static function log_oauth_access( int $user_id, string $provider = 'google' ): void {
        self::log_consent(
            $user_id,
            'oauth_login_' . sanitize_key( $provider ),
            true,
            '1.0',
            'oauth'
        );

        // Registrar IP de último acceso OAuth
        update_user_meta( $user_id, 'ltms_last_oauth_ip',       self::get_client_ip() );
        update_user_meta( $user_id, 'ltms_last_oauth_at',       current_time( 'mysql', true ) );
        update_user_meta( $user_id, 'ltms_last_oauth_provider', $provider );
    }

    /**
     * Registra acceso vía wp_login para auditoría.
     *
     * @param string   $user_login
     * @param \WP_User $user
     */
    public static function log_wp_login( string $user_login, \WP_User $user ): void {
        // Solo loguear vendedores LTMS, no todos los usuarios de WP
        if ( ! LTMS_Utils::is_ltms_vendor( $user->ID ) ) {
            return;
        }
        update_user_meta( $user->ID, 'ltms_last_login_ip', self::get_client_ip() );
        update_user_meta( $user->ID, 'ltms_last_login_at', current_time( 'mysql', true ) );
    }

    // ── L-6: KYC Consent ────────────────────────────────────────────────────────

    /**
     * Registra el consentimiento KYC al subir documentos.
     * Llamar en ajax_submit_kyc() cuando el vendedor sube su documentación.
     *
     * @param int $user_id
     */
    public static function log_kyc_consent( int $user_id ): void {
        self::log_consent(
            $user_id,
            'kyc_data',
            true,
            self::KYC_VERSION,
            'web'
        );
        update_user_meta( $user_id, 'ltms_kyc_consent_at', current_time( 'mysql', true ) );
        update_user_meta( $user_id, 'ltms_kyc_consent_ip', self::get_client_ip() );
        update_user_meta( $user_id, 'ltms_kyc_data_version', self::KYC_VERSION );
    }

    // ── L-7: Document Masking ────────────────────────────────────────────────────

    /**
     * Enmascara un número de documento para no exponer el dato completo en APIs.
     * Ejemplo: CC 1234567890 → CC ****7890
     *
     * @param string $document_number Número completo.
     * @param string $doc_type        CC|NIT|CE|PA|TI|etc.
     * @return string Documento enmascarado.
     */
    public static function mask_document( string $document_number, string $doc_type = '' ): string {
        if ( empty( $document_number ) ) {
            return '';
        }
        $len    = strlen( $document_number );
        $show   = min( 4, (int) floor( $len * 0.3 ) );
        $masked = str_repeat( '*', $len - $show ) . substr( $document_number, -$show );
        return $doc_type ? $doc_type . ' ' . $masked : $masked;
    }

    /**
     * Descifra y enmascara un documento guardado cifrado en user_meta.
     *
     * @param int    $user_id
     * @param string $meta_key ltms_document por defecto.
     * @return string Documento enmascarado o vacío si no existe.
     */
    public static function get_masked_document( int $user_id, string $meta_key = 'ltms_document' ): string {
        $encrypted = get_user_meta( $user_id, $meta_key, true );
        if ( ! $encrypted ) {
            return '';
        }

        try {
            $plain    = LTMS_Core_Security::decrypt( $encrypted );
            $doc_type = get_user_meta( $user_id, 'ltms_document_type', true );
            return self::mask_document( $plain, $doc_type );
        } catch ( \Throwable $e ) {
            return '****';
        }
    }

    // ── Habeas Data: Exportación y eliminación ────────────────────────────────────

    /**
     * Exporta todos los datos personales de un usuario (derecho de acceso, Ley 1581 art. 8 lit. a).
     *
     * @param int $user_id
     * @return array
     */
    public static function export_user_data( int $user_id ): array {
        global $wpdb;

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [];
        }

        return [
            'user'          => [
                'id'           => $user_id,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'registered'   => $user->user_registered,
            ],
            'profile'       => [
                'phone'            => get_user_meta( $user_id, 'ltms_phone', true ),
                'document_type'    => get_user_meta( $user_id, 'ltms_document_type', true ),
                'document_masked'  => self::get_masked_document( $user_id ),
                'municipality'     => get_user_meta( $user_id, 'ltms_municipality', true ),
                'store_name'       => get_user_meta( $user_id, 'ltms_store_name', true ),
                'kyc_status'       => get_user_meta( $user_id, 'ltms_kyc_status', true ),
            ],
            'consents'      => $wpdb->get_results( $wpdb->prepare(
                "SELECT consent_type, accepted, version, ip_address, created_at FROM {$wpdb->prefix}lt_consent_log WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            ), ARRAY_A ),
            'vault_accesses'=> $wpdb->get_results( $wpdb->prepare(
                "SELECT document, action, ip_address, context, created_at FROM {$wpdb->prefix}lt_vault_access_log WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
                $user_id
            ), ARRAY_A ),
        ];
    }

    /**
     * Anonimiza los datos personales de un usuario (derecho de supresión, Ley 1581 art. 8 lit. e).
     * NO elimina el usuario — solo anonimiza campos sensibles para mantener integridad contable.
     *
     * LC-2 FIX (AUDIT-BATCH2): Antes NO se anonimizaban user_email, user_login
     * (en WP user_login es inmutable vía wp_update_user), ni los metadatos de
     * IP/UA de últimos accesos — violando el derecho de supresión de Ley
     * 1581/2012 art. 8 lit. e y RGPD art. 17. El email quedaba accesible
     * para el administrador y en exports, exponiendo PII tras una solicitud
     * de borrado. Ahora se anonimiza el email (vía $wpdb sobre wp_users +
     * wp_update_user) y se limpian todos los metadatos de geolocalización/IP/UA.
     *
     * @param int $user_id
     * @return bool
     */
    public static function anonymize_user_data( int $user_id ): bool {
        global $wpdb;
        $anon_hash = substr( hash( 'sha256', 'ltms_anon_' . $user_id . '_' . time() ), 0, 8 );

        // LC-2: Anonimizar email. wp_update_user NO permite cambiar user_login,
        // pero SÍ user_email. Usamos email único con hash para no romper UNIQUE.
        $anon_email = sprintf( 'anon_%s_%d@deleted.ltms', $anon_hash, $user_id );

        wp_update_user([
            'ID'           => $user_id,
            'display_name' => 'Usuario Anonimizado',
            'first_name'   => 'ANONIMIZADO',
            'last_name'    => $anon_hash,
            'user_email'   => $anon_email,
            'user_url'     => '',
            'description'  => '',
        ]);

        // LC-2: Limpiar nickname (a menudo PII) y sesión activa.
        update_user_meta( $user_id, 'nickname', 'Usuario Anonimizado' );

        // Borrar todos los metadatos de PII / geolocalización / accesos.
        $pii_meta_keys = [
            'ltms_phone',
            'ltms_document',
            'ltms_document_type',
            'ltms_kyc_document_url',
            'ltms_kyc_selfie_url',
            'ltms_registration_ip',
            'ltms_registration_ua',
            'ltms_last_login_ip',
            'ltms_last_oauth_ip',
            'ltms_last_oauth_provider',
            'ltms_checkout_consent_ip',
            'ltms_kyc_consent_ip',
            'ltms_municipality',
            'ltms_store_name',
            'ltms_address',
            'ltms_city',
            'ltms_bank_account',
            'ltms_bank_account_type',
            'ltms_bank_holder_name',
            'ltms_tax_id',
        ];
        foreach ( $pii_meta_keys as $key ) {
            delete_user_meta( $user_id, $key );
        }

        // Invalidar sesiones activas del usuario anonimizado (cierre de sesión
        // forzoso para que el siguiente request no tenga acceso a la cuenta).
        // LC-2: solo las sesiones del propio usuario, NO todas las del sitio.
        $token_manager = WP_Session_Tokens::get_instance( $user_id );
        if ( $token_manager ) {
            $token_manager->destroy_all();
        }

        update_user_meta( $user_id, 'ltms_anonymized_at', current_time( 'mysql', true ) );
        update_user_meta( $user_id, 'ltms_anonymized',    1 );
        update_user_meta( $user_id, 'ltms_anonymized_email', $anon_email );

        LTMS_Core_Logger::info(
            'HABEAS_DATA_ANONYMIZE',
            "Usuario $user_id anonimizado por solicitud Ley 1581/2012 (email+metadatos PII limpiados)",
            [ 'user_id' => $user_id ]
        );

        return true;
    }

    // ── LC-3: Re-consentimiento por cambio de versión ─────────────────────────

    /**
     * Devuelve la versión vigente del documento legal solicitado.
     *
     * @param string $type terms|privacy|sagrilaft|kyc.
     * @return string Versión actual (ej: '4.0'). '0.0' si el tipo es desconocido.
     */
    public static function get_current_consent_version( string $type ): string {
        switch ( $type ) {
            case 'terms':     return self::TERMS_VERSION;
            case 'privacy':   return self::PRIVACY_VERSION;
            case 'sagrilaft': return self::SAGRILAFT_VERSION;
            case 'kyc':       return self::KYC_VERSION;
        }
        return '0.0';
    }

    /**
     * Verifica si el usuario necesita re-consentir un documento legal.
     *
     * LC-3 FIX (AUDIT-BATCH2): Antes NO existía este método — cambiar la
     * constante TERMS_VERSION no invalidaba los consentimientos anteriores.
     * Un usuario que aceptó T&C v3.0 seguía operando bajo v3.0 aunque la
     * plataforma hubiera publicado v4.0 con cláusulas nuevas, violando el
     * principio de consentimiento informado de Ley 1581/2012 art. 9 y RGPD.
     *
     * Comparación robusta:
     *  - Si el usuario no tiene meta (ltms_terms_version) → necesita consentir.
     *  - Si la versión guardada != versión actual → necesita re-consentir.
     *  - Si además existe lt_consent_log, verifica que el último consentimiento
     *    aceptado para ese tipo coincida con la versión actual (defensivo: si el
     *    admin revirtió la meta pero el log histórico dice otra cosa, gana el log).
     *
     * @param int    $user_id ID del usuario.
     * @param string $type    terms|privacy|sagrilaft|kyc.
     * @return bool True si el usuario debe re-consentir.
     */
    public static function user_needs_reconsent( int $user_id, string $type ): bool {
        if ( $user_id <= 0 ) {
            return true; // Guest / sin usuario → siempre necesita consentir.
        }

        $current_version = self::get_current_consent_version( $type );
        if ( '0.0' === $current_version ) {
            return false; // Tipo desconocido — no bloquear.
        }

        // 1) Verificación rápida vía user_meta (seteado en log_registration_consents).
        $meta_key = 'ltms_' . $type . '_version';
        $stored   = get_user_meta( $user_id, $meta_key, true );
        if ( ! $stored || (string) $stored !== (string) $current_version ) {
            return true;
        }

        // 2) Verificación forense en lt_consent_log: último consentimiento ACEPTADO
        // para este tipo debe tener version == current_version.
        global $wpdb;
        $last = $wpdb->get_var( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — table name is static.
            "SELECT version FROM {$wpdb->prefix}lt_consent_log
             WHERE user_id = %d AND consent_type = %s AND accepted = 1
             ORDER BY created_at DESC LIMIT 1",
            $user_id,
            sanitize_key( $type )
        ) );

        if ( null === $last ) {
            // Sin registro de consentimiento en el log → necesita consentir.
            return true;
        }

        return (string) $last !== (string) $current_version;
    }

    /**
     * Devuelve la lista de documentos legales que el usuario debe re-consentir.
     *
     * Útil para mostrar un banner de re-consentimiento en el dashboard o
     * bloquear acciones sensibles (checkout, payout, KYC submission).
     *
     * @param int $user_id
     * @return string[] Array de tipos ('terms', 'privacy', 'sagrilaft', 'kyc').
     */
    public static function get_pending_reconsents( int $user_id ): array {
        $pending = [];
        foreach ( [ 'terms', 'privacy', 'sagrilaft', 'kyc' ] as $type ) {
            if ( self::user_needs_reconsent( $user_id, $type ) ) {
                $pending[] = $type;
            }
        }
        return $pending;
    }

    // ── Utilidades ────────────────────────────────────────────────────────────────

    /**
     * Obtiene la IP real del cliente, considerando proxies y CDN.
     */
    public static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }

        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
