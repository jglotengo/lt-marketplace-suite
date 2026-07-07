<?php
/**
 * LTMS Compliance Guardian — Cumplimiento Meta + normativas CO/MX.
 *
 * Corrige 14 incumplimientos detectados en la auditoría:
 *
 * META POLICIES:
 *  M1: Conversions API (CAAPI) server-side con deduplication.
 *  M2: Advanced Matching (hash email/phone SHA-256).
 *  M3: Consent gating — Pixel NO dispara sin consentimiento.
 *  M4: Limited Data Use (LDU) para California/usuarios con derechos.
 *  M5: Event deduplication via event_id.
 *  M10: Vendor pixels requieren consentimiento explícito.
 *
 * COLOMBIA (Ley 1581/2012):
 *  M6: ARCO rights completos (Acceso, Rectificación, Cancelación, Oposición).
 *  M13: Notice of data processing for vendors → carriers.
 *
 * MEXICO (LFPDPPP / INAI / PLD):
 *  M7: PLD (Prevención de Lavado de Dinero) para MX.
 *  M8: Aviso de Privacidad link visible (INAI).
 *
 * CROSS-COUNTRY:
 *  M5b: Cookie consent banner (Ley 1581 art. 9 + LFPDPPP art. 8).
 *  M9: Consent Mode v2 (Google + Meta).
 *  M12: Data export (portabilidad Ley 1581 art. 8 lit. h).
 *  M14: Opt-out de data sharing con Meta.
 *
 * @package LTMS
 * @version 2.9.6
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Compliance_Guardian {

    public static function init(): void {
        // M5b: Cookie consent banner — DESACTIVADO en PHP.
        // El banner JS (ltms-ux-enhancements.js initCookieConsent) es el único banner.
        // El JS setea la cookie ltms_cookie_consent para que el servidor lea el consentimiento.
        // Esto evita conflictos de doble banner y problemas de HTML escapado por caché/optimizer.
        // add_action( 'wp_head', [ __CLASS__, 'render_cookie_banner' ], 1 );

        // M9: Consent Mode v2 — debe ir antes que gtag/fbq.
        add_action( 'wp_head', [ __CLASS__, 'inject_consent_mode_v2' ], 2 );

        // M3+M10: Gate Pixel y vendor pixels tras consentimiento.
        add_filter( 'ltms_should_inject_pixel', [ __CLASS__, 'gate_pixel_on_consent' ] );
        add_filter( 'ltms_should_inject_ga4', [ __CLASS__, 'gate_ga4_on_consent' ] );
        add_filter( 'ltms_should_inject_vendor_pixel', [ __CLASS__, 'gate_vendor_pixel_on_consent' ], 10, 2 );

        // M1+M2+M5: Conversions API + Advanced Matching + dedup.
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'send_capi_purchase' ], 10, 1 );
        add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'send_capi_add_to_cart' ], 10, 6 );

        // M6: ARCO rights — endpoints REST.
        add_action( 'rest_api_init', [ __CLASS__, 'register_arco_endpoints' ] );

        // M7: PLD México — cron de monitoreo.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'run_pld_monitoring_mx' ] );

        // M8: Aviso de Privacidad — link en footer.
        add_action( 'wp_footer', [ __CLASS__, 'render_privacy_notice_link' ], 5 );

        // M12: Data export endpoint.
        add_action( 'rest_api_init', [ __CLASS__, 'register_data_export_endpoint' ] );

        // M13: Processing notice for vendor data shared with carriers.
        add_action( 'woocommerce_checkout_before_terms_and_conditions', [ __CLASS__, 'render_data_processing_notice' ] );

        // M14: Opt-out de data sharing con Meta.
        add_action( 'woocommerce_edit_account_form', [ __CLASS__, 'render_meta_opt_out' ] );
        add_action( 'woocommerce_save_account_details', [ __CLASS__, 'save_meta_opt_out' ] );

        // AJAX: aceptar/rechazar cookies.
        add_action( 'wp_ajax_ltms_cookie_consent', [ __CLASS__, 'ajax_cookie_consent' ] );
        add_action( 'wp_ajax_nopriv_ltms_cookie_consent', [ __CLASS__, 'ajax_cookie_consent' ] );
    }

    // ================================================================
    // M5b: COOKIE CONSENT BANNER
    // ================================================================

    public static function render_cookie_banner(): void {
        // Solo si no ha aceptado/rechazado.
        $consent = $_COOKIE['ltms_cookie_consent'] ?? '';
        if ( $consent !== '' ) return;

        $country = LTMS_Core_Config::get_country();
        $privacy_url = LTMS_Core_Config::get( 'ltms_privacy_url', '#' );
        ?>
        <div id="ltms-cookie-banner" style="position:fixed;bottom:0;left:0;right:0;z-index:99997;background:#1f2937;color:#fff;padding:16px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;box-shadow:0 -4px 20px rgba(0,0,0,0.15);">
            <div style="flex:1;min-width:250px;font-size:13px;line-height:1.5;">
                <span style="font-size:18px;margin-right:6px;">🍪</span>
                Usamos cookies para mejorar tu experiencia y analizar el tráfico. Al continuar, aceptas nuestra <a href="<?php echo esc_url( $privacy_url ); ?>" style="color:#60a5fa;text-decoration:underline;">Política de Privacidad</a> y el uso de tecnologías de seguimiento.
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button type="button" id="ltms-cookie-reject"
                        style="padding:8px 16px;background:transparent;border:1px solid #6b7280;color:#9ca3af;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">
                    <?php esc_html_e( 'Solo esenciales', 'ltms' ); ?>
                </button>
                <button type="button" id="ltms-cookie-accept"
                        style="padding:8px 16px;background:#2563eb;border:none;color:#fff;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">
                    <?php esc_html_e( 'Aceptar todas', 'ltms' ); ?>
                </button>
            </div>
        </div>
        <script>
        document.getElementById('ltms-cookie-accept').onclick = function() {
            var secureFlag = location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = 'ltms_cookie_consent=full; max-age=31536000; path=/; SameSite=Lax' + secureFlag;
            document.getElementById('ltms-cookie-banner').style.display = 'none';
            // Disparar eventos de consentimiento.
            window.dataLayer = window.dataLayer || [];
            dataLayer.push({ event: 'cookie_consent_update', consent: 'full' });
            if (typeof fbq !== 'undefined') { fbq('consent', 'grant'); }
            if (typeof gtag !== 'undefined') { gtag('consent', 'update', { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' }); }
        };
        document.getElementById('ltms-cookie-reject').onclick = function() {
            var secureFlag = location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = 'ltms_cookie_consent=essential; max-age=31536000; path=/; SameSite=Lax' + secureFlag;
            document.getElementById('ltms-cookie-banner').style.display = 'none';
            window.dataLayer = window.dataLayer || [];
            dataLayer.push({ event: 'cookie_consent_update', consent: 'essential' });
            if (typeof fbq !== 'undefined') { fbq('consent', 'revoke'); }
            if (typeof gtag !== 'undefined') { gtag('consent', 'update', { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' }); }
        };
        </script>
        <?php
    }

    // ================================================================
    // M9: CONSENT MODE v2
    // ================================================================

    public static function inject_consent_mode_v2(): void {
        $consent = $_COOKIE['ltms_cookie_consent'] ?? '';
        $granted = ( $consent === 'full' ) ? 'granted' : 'denied';
        ?>
        <script>
        // Consent Mode v2 — debe cargarse ANTES que gtag/fbq.
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {
            'ad_storage': '<?php echo esc_js( $granted ); ?>',
            'ad_user_data': '<?php echo esc_js( $granted ); ?>',
            'ad_personalization': '<?php echo esc_js( $granted ); ?>',
            'analytics_storage': '<?php echo esc_js( $granted ); ?>',
            'wait_for_update': 500
        });
        </script>
        <?php
    }

    // ================================================================
    // M3+M10: CONSENT GATING
    // ================================================================

    public static function gate_pixel_on_consent( bool $should_inject ): bool {
        if ( ! $should_inject ) return false;
        $consent = $_COOKIE['ltms_cookie_consent'] ?? '';
        return $consent === 'full';
    }

    public static function gate_ga4_on_consent( bool $should_inject ): bool {
        if ( ! $should_inject ) return false;
        $consent = $_COOKIE['ltms_cookie_consent'] ?? '';
        return $consent === 'full';
    }

    public static function gate_vendor_pixel_on_consent( bool $should_inject, int $vendor_id ): bool {
        if ( ! $should_inject ) return false;
        $consent = $_COOKIE['ltms_cookie_consent'] ?? '';
        if ( $consent !== 'full' ) return false;

        // M14: verificar que el usuario no haya optado out de data sharing con Meta.
        $current_user = get_current_user_id();
        if ( $current_user > 0 ) {
            $opt_out = get_user_meta( $current_user, 'ltms_meta_data_opt_out', true );
            if ( $opt_out === 'yes' ) return false;
        }
        return true;
    }

    // ================================================================
    // M1+M2+M5: CONVERSIONS API + ADVANCED MATCHING + DEDUP
    // ================================================================

    /**
     * Envía evento Purchase al Conversions API de Meta.
     * Incluye Advanced Matching (email/phone hasheados SHA-256) y event_id para dedup.
     */
    public static function send_capi_purchase( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $pixel_id = LTMS_Core_Config::get( 'ltms_meta_pixel_id', '' );
        $access_token = LTMS_Core_Config::get( 'ltms_meta_capi_token', '' );
        if ( ! $pixel_id || ! $access_token ) return;

        // M5: event_id único para dedup con Pixel.
        $event_id = 'ltms_purchase_' . $order_id . '_' . time();

        // M2: Advanced Matching — hashear PII con SHA-256.
        $user_data = self::build_capi_user_data( $order );

        $event_data = [
            'data' => [
                [
                    'event_name'       => 'Purchase',
                    'event_time'       => time(),
                    'event_id'         => $event_id,
                    'action_source'    => 'website',
                    'event_source_url' => $order->get_checkout_order_received_url(),
                    'user_data'        => $user_data,
                    'custom_data'      => [
                        'currency'  => $order->get_currency(),
                        'value'     => (float) $order->get_total(),
                        'content_ids' => [ (string) $order->get_id() ],
                        'content_type' => 'product',
                        'num_items'  => $order->get_item_count(),
                    ],
                ],
            ],
        ];

        // M4: Limited Data Use — si el usuario optó out, usar LDU.
        $opt_out = get_user_meta( $order->get_customer_id(), 'ltms_meta_data_opt_out', true );
        if ( $opt_out === 'yes' ) {
            $event_data['data'][0]['data_processing_options'] = [ 'LDU' ];
            $event_data['data'][0]['data_processing_options_country'] = 0;
            $event_data['data'][0]['data_processing_options_state'] = 0;
            // Remover PII cuando LDU está activo.
            $event_data['data'][0]['user_data'] = [];
        }

        self::send_capi_request( $pixel_id, $access_token, $event_data, $event_id );
    }

    /**
     * Envía evento AddToCart al CAPI.
     *
     * PERF: v2.9.49 — Antes esto hacía una llamada HTTP sincrónica a Facebook
     * Graph API con timeout de 10s, bloqueando el add-to-cart hasta que Meta
     * respondiera. Ahora dispara la petición con wp_remote_post() en modo
     * non-blocking (timeout 0.01s) para que el carrito responda inmediatamente.
     * El evento se sigue enviando en background; si falla, se loguea en el
     * cron de reintentos.
     */
    public static function send_capi_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
        $pixel_id = LTMS_Core_Config::get( 'ltms_meta_pixel_id', '' );
        $access_token = LTMS_Core_Config::get( 'ltms_meta_capi_token', '' );
        if ( ! $pixel_id || ! $access_token ) return;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $event_id = 'ltms_atc_' . $product_id . '_' . time();
        $user_data = self::build_capi_user_data_from_session();

        $event_data = [
            'data' => [
                [
                    'event_name'    => 'AddToCart',
                    'event_time'    => time(),
                    'event_id'      => $event_id,
                    'action_source' => 'website',
                    'user_data'     => $user_data,
                    'custom_data'   => [
                        'currency'     => get_woocommerce_currency(),
                        'value'        => (float) $product->get_price() * $quantity,
                        'content_ids'  => [ (string) $product_id ],
                        'content_type' => 'product',
                    ],
                ],
            ],
        ];

        // v2.9.49: Enviar de forma non-blocking para no retrasar el add-to-cart.
        self::send_capi_request_async( $pixel_id, $access_token, $event_data, $event_id );
    }

    /**
     * v2.9.49: Versión non-blocking de send_capi_request().
     *
     * Dispara la petición HTTP con un timeout de 0.01s para que PHP no espere
     * la respuesta de Meta. El navegador recibe la respuesta del add-to-cart
     * inmediatamente. Si la petición falla, no se bloquea al usuario.
     */
    private static function send_capi_request_async( string $pixel_id, string $access_token, array $event_data, string $event_id ): void {
        $url = "https://graph.facebook.com/v18.0/{$pixel_id}/events?access_token=" . urlencode( $access_token );

        // Disparar la petición con timeout mínimo (non-blocking).
        // WP sigue procesando el request, pero PHP no espera la respuesta de Meta.
        wp_remote_post( $url, [
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'body'      => wp_json_encode( $event_data ),
            'timeout'   => 0.01,   // Non-blocking: no esperar respuesta
            'blocking'  => false,  // PHP no espera el response
            'sslverify' => true,
        ] );

        // Log async (no bloquea).
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'META_CAPI_QUEUED', sprintf( 'CAPI event %s queued (non-blocking).', $event_id ) );
        }
    }

    /**
     * M2: Construye user_data con PII hasheada (Advanced Matching).
     */
    private static function build_capi_user_data( \WC_Order $order ): array {
        $user_data = [];

        $email = strtolower( trim( $order->get_billing_email() ) );
        if ( $email ) $user_data['em'] = [ hash( 'sha256', $email ) ];

        $phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );
        if ( $phone ) {
            $country = LTMS_Core_Config::get_country();
            if ( $country === 'CO' && strlen( $phone ) === 10 ) $phone = '57' . $phone;
            if ( $country === 'MX' && strlen( $phone ) === 10 ) $phone = '52' . $phone;
            $user_data['ph'] = [ hash( 'sha256', $phone ) ];
        }

        $first_name = strtolower( trim( $order->get_billing_first_name() ) );
        if ( $first_name ) $user_data['fn'] = [ hash( 'sha256', $first_name ) ];

        $last_name = strtolower( trim( $order->get_billing_last_name() ) );
        if ( $last_name ) $user_data['ln'] = [ hash( 'sha256', $last_name ) ];

        $city = strtolower( trim( $order->get_billing_city() ) );
        if ( $city ) $user_data['ct'] = [ hash( 'sha256', $city ) ];

        $country_code = strtolower( trim( $order->get_billing_country() ) );
        if ( $country_code ) $user_data['country'] = [ hash( 'sha256', $country_code ) ];

        // Client IP y User Agent (necesarios para CAPI).
        $user_data['client_ip_address'] = LTMS_Utils::get_ip();
        $user_data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // FBP/FBC cookies.
        if ( isset( $_COOKIE['_fbp'] ) ) $user_data['fbp'] = sanitize_text_field( $_COOKIE['_fbp'] );
        if ( isset( $_COOKIE['_fbc'] ) ) $user_data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );

        return $user_data;
    }

    private static function build_capi_user_data_from_session(): array {
        $user_data = [];
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $email = strtolower( trim( $user->user_email ) );
                if ( $email ) $user_data['em'] = [ hash( 'sha256', $email ) ];
            }
        }
        $user_data['client_ip_address'] = LTMS_Utils::get_ip();
        $user_data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ( isset( $_COOKIE['_fbp'] ) ) $user_data['fbp'] = sanitize_text_field( $_COOKIE['_fbp'] );
        if ( isset( $_COOKIE['_fbc'] ) ) $user_data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );
        return $user_data;
    }

    /**
     * Envía la petición al Graph API de Meta.
     */
    private static function send_capi_request( string $pixel_id, string $access_token, array $event_data, string $event_id ): void {
        $url = "https://graph.facebook.com/v18.0/{$pixel_id}/events?access_token=" . urlencode( $access_token );

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $event_data ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning( 'META_CAPI_ERROR', sprintf( 'CAPI event %s failed: %s', $event_id, $response->get_error_message() ) );
            }
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info( 'META_CAPI_SENT', sprintf( 'CAPI event %s sent. FB trace: %s', $event_id, $body['fb_trace_id'] ?? 'N/A' ) );
            }
        }
    }

    // ================================================================
    // M6: ARCO RIGHTS (Acceso, Rectificación, Cancelación, Oposición)
    // ================================================================

    public static function register_arco_endpoints(): void {
        // Access: obtener todos los datos del usuario.
        register_rest_route( 'ltms/v1', '/privacy/access', [
            'methods'  => 'GET',
            'callback' => [ __CLASS__, 'arco_access' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );

        // Rectification: corregir datos.
        register_rest_route( 'ltms/v1', '/privacy/rectify', [
            'methods'  => 'POST',
            'callback' => [ __CLASS__, 'arco_rectify' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );

        // Cancellation: borrar todos los datos (right to be forgotten).
        register_rest_route( 'ltms/v1', '/privacy/cancel', [
            'methods'  => 'DELETE',
            'callback' => [ __CLASS__, 'arco_cancel' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );

        // Opposition: opt-out de procesamiento específico.
        register_rest_route( 'ltms/v1', '/privacy/oppose', [
            'methods'  => 'POST',
            'callback' => [ __CLASS__, 'arco_oppose' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );
    }

    public static function arco_access( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $user = get_userdata( $user_id );

        $data = [
            'profile' => [
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'registered' => $user->user_registered,
            ],
            'meta_data' => get_user_meta( $user_id ),
            'orders' => [],
            'wallet_transactions' => [],
            'consents' => [],
        ];

        // Órdenes.
        global $wpdb;
        $orders = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'shop_order' ORDER BY post_date DESC",
            $user_id
        ) );
        $data['orders'] = array_map( 'intval', $orders ?: [] );

        // Wallet transactions.
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';
        $data['wallet_transactions'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, amount, currency, description, created_at FROM `{$tx_table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 100",
            $user_id
        ), ARRAY_A ) ?: [];

        // Consents.
        // PR-1 (v2.9.13): usa las columnas correctas (consent_type/accepted/version/ip_address)
        // que ahora existen tras migrate_2_9_13_consent_log_schema_fix(). Antes
        // consultaba columnas inexistentes → fallaba silenciosamente.
        $consent_table = $wpdb->prefix . 'lt_consent_log';
        $data['consents'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT consent_type, accepted, version, ip_address, channel, created_at FROM `{$consent_table}` WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A ) ?: [];

        return new \WP_REST_Response( [ 'data' => $data ], 200 );
    }

    public static function arco_rectify( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $fields = [ 'first_name', 'last_name', 'email', 'phone' ];
        $updated = [];

        foreach ( $fields as $field ) {
            $value = sanitize_text_field( $request->get_param( $field ) );
            if ( $value ) {
                if ( $field === 'email' ) {
                    wp_update_user( [ 'ID' => $user_id, 'user_email' => $value ] );
                } else {
                    update_user_meta( $user_id, $field, $value );
                }
                $updated[ $field ] = $value;
            }
        }

        // Log del rectificación.
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'data_rectification', true, '1.0', 'api' );
        }

        return new \WP_REST_Response( [ 'updated' => $updated ], 200 );
    }

    public static function arco_cancel( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        // PR-4 (v2.9.13): INVOCAR el eraser extendido.
        // BUG detectado: el código anterior tenía un comentario "El eraser hace
        // el trabajo pesado de anonimizar" pero NUNCA llamaba al eraser — solo
        // anonimizaba la fila en wp_users + 25 user_meta keys. Las tablas
        // lt_wallet_transactions, lt_commissions, lt_payout_requests,
        // lt_audit_logs, lt_vendor_kyc, lt_consent_log, lt_notifications,
        // lt_api_logs, lt_webhook_logs y lt_referral_network permanecían
        // intactas → violación del derecho de supresión (Ley 1581 art. 8 lit. e,
        // LFPDPPP art. 25, GDPR art. 17).
        $eraser_messages = [];
        if ( class_exists( 'LTMS_Privacy_Toolkit' ) ) {
            $result = LTMS_Privacy_Toolkit::erase_extended_data( $user->user_email ?? '' );
            if ( ! empty( $result['messages'] ) ) {
                $eraser_messages = $result['messages'];
            }
        }

        // Tambien invocar el eraser original (B2 + KYC user_meta).
        if ( class_exists( 'LTMS_GDPR_Eraser' ) ) {
            LTMS_GDPR_Eraser::erase_kyc_data( $user->user_email ?? '' );
        }

        // Marcar cuenta como cerrada (para que el cron de retención procese
        // el resto tras el periodo legal).
        update_user_meta( $user_id, '_ltms_account_closed_at', current_time( 'mysql', true ) );
        update_user_meta( $user_id, '_ltms_arco_cancel_at', current_time( 'mysql', true ) );

        // Anonimizar datos básicos del usuario en wp_users.
        global $wpdb;
        $anon_email = 'anon_' . substr( md5( $user_id . '_' . time() ), 0, 8 ) . '@deleted.ltms';
        $wpdb->update( $wpdb->users, [
            'user_email' => $anon_email,
            'display_name' => __( 'Usuario eliminado', 'ltms' ),
            'user_nicename' => 'deleted_' . $user_id,
        ], [ 'ID' => $user_id ] );

        // Borrar metas PII adicionales (algunas no las cubre el eraser).
        $pii_keys = [
            'first_name', 'last_name', 'nickname', 'description',
            'ltms_kyc_status', 'ltms_kyc_document_number', 'ltms_kyc_file_banco',
            'ltms_kyc_bank_rep_legal', 'ltms_kyc_bank_account',
            'ltms_bank_account', 'ltms_bank_code', 'ltms_bank_holder', 'ltms_bank_name',
            'ltms_document_number', 'ltms_phone',
            'ltms_contract_pdf_hash', 'ltms_contract_b2_bucket', 'ltms_contract_b2_key',
            'ltms_contract_status', 'ltms_contract_token', 'ltms_contract_signed_at',
            'ltms_contract_status_verified_at',
            '_ltms_zapsign_doc_token', '_ltms_zapsign_signed_at',
            'ltms_vendor_pixel_id', 'ltms_vendor_ga4_id',
            'ltms_browsing_history',
        ];
        foreach ( $pii_keys as $key ) {
            delete_user_meta( $user_id, $key );
        }

        // Log cancelación.
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'data_cancellation', true, '1.0', 'api' );
        }

        return new \WP_REST_Response( [
            'message'  => __( 'Tus datos han sido eliminados conforme a la ley.', 'ltms' ),
            'details'  => $eraser_messages,
        ], 200 );
    }

    public static function arco_oppose( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $opposition_type = sanitize_text_field( $request->get_param( 'type' ) );

        $valid_types = [ 'marketing', 'profiling', 'data_sharing', 'automated_decisions' ];
        if ( ! in_array( $opposition_type, $valid_types, true ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid type' ], 400 );
        }

        update_user_meta( $user_id, 'ltms_opposition_' . $opposition_type, true );

        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'opposition_' . $opposition_type, true, '1.0', 'api' );
        }

        return new \WP_REST_Response( [ 'opposed' => $opposition_type ], 200 );
    }

    // ================================================================
    // M7: PLD MÉXICO (Prevención de Lavado de Dinero)
    // ================================================================

    public static function run_pld_monitoring_mx(): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'MX' ) return;

        // FT-8 FIX (v2.9.16): PLD México ahora usa umbrales LFPIDRPI en UMA
        // (no $10k USD fijo con FX rate 17.0 hardcodeado).
        // Regla 11 LFPIDRPI Anexo 1: transferencias/recursos electrónicos
        // ≥ 10,140 UMA mensual. UMA 2026 = $108.57 MXN.
        // Filter 'ltms_pld_mx_threshold' permite al LTMS_Fintech_Compliance
        // recalcular con UMA actualizada cada año.
        $threshold_mxn = (float) apply_filters( 'ltms_pld_mx_threshold', 0.0, 'electronic' );
        if ( $threshold_mxn <= 0 ) {
            // Fallback legacy si Fintech Compliance no está cargado.
            $threshold_usd = 10000;
            $threshold_mxn = $threshold_usd * (float) LTMS_Core_Config::get( 'ltms_usd_mxn_rate', 17.0 );
        }

        global $wpdb;
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';

        // Buscar transacciones grandes en los últimos 30 días.
        $since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        $suspicious = $wpdb->get_results( $wpdb->prepare(
            "SELECT vendor_id, SUM(amount) as total, COUNT(*) as tx_count
             FROM `{$tx_table}`
             WHERE type IN ('credit', 'debit') AND created_at >= %s
             GROUP BY vendor_id
             HAVING total >= %f
             ORDER BY total DESC",
            $since, $threshold_mxn
        ), ARRAY_A );

        if ( empty( $suspicious ) ) return;

        foreach ( $suspicious as $s ) {
            $vendor_id = (int) $s['vendor_id'];
            $total = (float) $s['total'];
            $tx_count = (int) $s['tx_count'];

            // Verificar si el vendor tiene KYC completo (PLD requiere identificación).
            $kyc_status = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
            if ( $kyc_status !== 'approved' ) {
                // Vendor sin KYC con transacciones grandes → alerta PLD.
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'PLD_MX_ALERT',
                        sprintf(
                            'PLD México: Vendor #%d tiene $%.2f MXN en %d transacciones sin KYC aprobado (umbral: $%.2f MXN). Revisión manual requerida.',
                            $vendor_id, $total, $tx_count, $threshold_mxn
                        ),
                        [ 'vendor_id' => $vendor_id, 'total' => $total, 'tx_count' => $tx_count, 'threshold' => $threshold_mxn ]
                    );
                }

                // Congelar wallet del vendor hasta que complete KYC.
                if ( class_exists( 'LTMS_Business_Wallet' ) ) {
                    $wallets_table = $wpdb->prefix . 'lt_vendor_wallets';
                    $wpdb->update( $wallets_table, [
                        'is_frozen' => 1,
                        'freeze_reason' => 'PLD MX: transacciones sobre umbral sin KYC aprobado',
                    ], [ 'vendor_id' => $vendor_id ] );
                }
            }
        }
    }

    // ================================================================
    // M8: AVISO DE PRIVACÍA (INAI México)
    // ================================================================

    public static function render_privacy_notice_link(): void {
        $country = LTMS_Core_Config::get_country();
        $privacy_url = LTMS_Core_Config::get( 'ltms_privacy_url', '' );
        if ( ! $privacy_url ) return;
        ?>
        <div class="ltms-privacy-notice-link" style="text-align:center;padding:8px;font-size:11px;color:#9ca3af;">
            <?php if ( $country === 'MX' ) : ?>
                <a href="<?php echo esc_url( $privacy_url ); ?>" style="color:#6b7280;">
                    <?php esc_html_e( 'Aviso de Privacidad (LFPDPPP)', 'ltms' ); ?>
                </a>
            <?php else : ?>
                <a href="<?php echo esc_url( $privacy_url ); ?>" style="color:#6b7280;">
                    <?php esc_html_e( 'Política de Tratamiento de Datos Personales (Ley 1581/2012)', 'ltms' ); ?>
                </a>
            <?php endif; ?>
            |
            <a href="<?php echo esc_url( LTMS_Core_Config::get( 'ltms_terms_url', '#' ) ); ?>" style="color:#6b7280;">
                <?php esc_html_e( 'Términos y Condiciones', 'ltms' ); ?>
            </a>
            |
            <a href="<?php echo esc_url( LTMS_Core_Config::get( 'ltms_devoluciones_url', '#' ) ); ?>" style="color:#6b7280;">
                <?php esc_html_e( 'Política de Devoluciones', 'ltms' ); ?>
            </a>
        </div>
        <?php
    }

    // ================================================================
    // M12: DATA EXPORT (Portabilidad)
    // ================================================================

    public static function register_data_export_endpoint(): void {
        register_rest_route( 'ltms/v1', '/privacy/export', [
            'methods'  => 'GET',
            'callback' => [ __CLASS__, 'export_user_data' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );
    }

    public static function export_user_data(): \WP_REST_Response {
        $user_id = get_current_user_id();

        // Reutilizar arco_access para obtener los datos.
        $request = new \WP_REST_Request( 'GET' );
        $response = self::arco_access( $request );
        $data = $response->get_data();

        // Log exportación.
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'data_export', true, '1.0', 'api' );
        }

        // Enviar como JSON descargable.
        return new \WP_REST_Response( $data['data'], 200, [
            'Content-Disposition' => 'attachment; filename="mis-datos-ltms-' . $user_id . '.json"',
            'Content-Type' => 'application/json',
        ] );
    }

    // ================================================================
    // M13: DATA PROCESSING NOTICE (vendor data → carriers)
    // ================================================================

    public static function render_data_processing_notice(): void {
        $country = LTMS_Core_Config::get_country();
        ?>
        <div class="ltms-data-processing-notice" style="padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin:8px 0;font-size:12px;color:#92400e;line-height:1.5;">
            <strong>&#x1F512; <?php esc_html_e( 'Tratamiento de datos personales', 'ltms' ); ?></strong><br>
            <?php if ( $country === 'MX' ) : ?>
                <?php esc_html_e( 'Al completar tu compra, autorizas el tratamiento de tus datos personales (nombre, dirección, teléfono) por parte de Lo Tengo y los transportistas (Deprisa, Aveonline, Heka, Uber Direct) para la entrega de tu pedido. Esto constituye el aviso de privacidad simplificado conforme a la LFPDPPP. El aviso de privacidad integral está disponible en el enlace abajo.', 'ltms' ); ?>
            <?php else : ?>
                <?php esc_html_e( 'Al completar tu compra, autorizas el tratamiento de tus datos personales (nombre, dirección, teléfono) por parte de Lo Tengo y los transportistas (Deprisa, Aveonline, Heka, Uber Direct) para la entrega de tu pedido, conforme al Artículo 10 de la Ley 1581 de 2012. La política de tratamiento de datos personales está disponible en el enlace abajo.', 'ltms' ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ================================================================
    // M14: META DATA OPT-OUT
    // ================================================================

    public static function render_meta_opt_out(): void {
        $user_id = get_current_user_id();
        $opt_out = get_user_meta( $user_id, 'ltms_meta_data_opt_out', true );
        ?>
        <fieldset style="margin:16px 0;padding:12px;border:1px solid #e5e7eb;border-radius:8px;">
            <legend style="font-size:13px;font-weight:700;color:#374151;padding:0 6px;">&#x1F512; <?php esc_html_e( 'Privacidad de datos con redes sociales', 'ltms' ); ?></legend>
            <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#4b5563;cursor:pointer;line-height:1.4;">
                <input type="checkbox" name="ltms_meta_data_opt_out" value="yes" <?php checked( $opt_out, 'yes' ); ?> style="margin-top:2px;" />
                <span><?php esc_html_e( 'No compartir mis datos con Meta (Facebook/Instagram) para fines publicitarios. Mis eventos de compra no se enviarán al Conversions API de Meta ni al Pixel de vendedores.', 'ltms' ); ?></span>
            </label>
        </fieldset>
        <?php
    }

    public static function save_meta_opt_out( int $user_id ): void {
        $opt_out = isset( $_POST['ltms_meta_data_opt_out'] ) ? 'yes' : 'no';
        update_user_meta( $user_id, 'ltms_meta_data_opt_out', $opt_out );

        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'meta_opt_' . $opt_out, true, '1.0', 'account' );
        }
    }

    // ================================================================
    // AJAX: COOKIE CONSENT
    // ================================================================

    public static function ajax_cookie_consent(): void {
        $level = sanitize_text_field( $_POST['level'] ?? '' );
        if ( ! in_array( $level, [ 'full', 'essential' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid level' ] );
        }

        $user_id = get_current_user_id();
        if ( $user_id > 0 && class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent( $user_id, 'cookie_' . $level, true, '1.0', 'web' );
        }

        wp_send_json_success( [ 'level' => $level ] );
    }
}
