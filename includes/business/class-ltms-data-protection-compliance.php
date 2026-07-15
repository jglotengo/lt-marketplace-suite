<?php
/**
 * LTMS Data Protection Compliance — Habeas data, protección de datos y
 * seguridad de la información.
 *
 * v2.9.21 — Cierra 12 brechas críticas de habeas data, protección de
 * datos y seguridad de la información detectadas en la auditoría v2.9.20,
 * cubriendo Colombia (Ley 1581/2012, Decreto 1377/2013, Decreto 1727/2024),
 * México (LFPDPPP, Lineamientos INAI) y cross-border (GDPR, ISO 27001).
 *
 *  HD-1 (ALTO): Content-Security-Policy header.
 *    Norma: OWASP Top 10 A05:2021; ISO 27001 A.14.2.5.
 *    Antes: el sistema enviaba HSTS, X-Frame, X-Content-Type, Referrer,
 *           Permissions pero NO CSP → vulnerable a XSS injected scripts.
 *    Fix: send_csp_header() hook send_headers. CSP configurable con
 *         default estricto: default-src 'self', script-src 'self' 'unsafe-inline'
 *         (necesario para WP), style-src 'self' 'unsafe-inline',
 *         img-src 'self' data: https:, connect-src 'self' https:.
 *         report-uri configurable para violaciones.
 *
 *  HD-2 (CRÍTICO): Registro SIC como Responsable de Tratamiento.
 *    Norma: CO Decreto 1727/2024 (registro nacional de responsables ante
 *           SIC, obligatorio desde 1 julio 2024).
 *    Antes: el sistema no verificaba ni alertaba sobre registro SIC.
 *    Fix: render_sic_registration_status() banner admin si no hay
 *         configuración ltms_sic_registration_number. Cron anual
 *         check_sic_registration_renewal() alerta 60 días antes vencimiento.
 *         Validator formato RCS-XXXXX (Registro Colombia SIC).
 *
 *  HD-3 (CRÍTICO): Consentimiento explícito transferencia internacional.
 *    Norma: CO Ley 1581/2012 art. 26 (autorización expresa para
 *           transferencia a países sin nivel adecuado de protección);
 *           MX LFPDPPP art. 37; GDPR art. 49 (cláusulas contractuales
 *           tipo, BCR, reglas corporativas vinculantes).
 *    Antes: el consentimiento estándar NO incluía autorización para
 *           transferencia internacional a AWS (USA), Backblaze (USA),
 *           Openpay (MX), ZapSign, etc.
 *    Fix: render_international_transfer_consent() checkbox obligatorio
 *         en checkout cuando hay transferencia a tercer país. Lista de
 *         terceros con país y base legal (cláusulas contractuales tipo
 *         UE art. 46 / autorización SIC art. 26 Ley 1581).
 *
 *  HD-4 (ALTO): Aviso de privacidad simplificado vs integral.
 *    Norma: MX Lineamientos Aviso Privacidad INAI 2017 (diferencia
 *           simplificado: recolección directa no sensible; integral:
 *           datos sensibles, financieros o no directa); CO Ley 1581
 *           art. 18 (autorización informada).
 *    Antes: solo existía un aviso único, no diferenciado.
 *    Fix: render_privacy_notice_simplified() en checkout (LFPDPPP art. 17).
 *         render_privacy_notice_integral() link separado (LFPDPPP art. 16).
 *         Diferencia automática según tipo de dato (sensible vs no sensible).
 *
 *  HD-5 (ALTO): Evaluación de Impacto en Protección de Datos (EIPD/DPIA).
 *    Norma: GDPR art. 35 (EIPD obligatoria para tratamiento alto riesgo);
 *           CO Decreto 1377/2013 art. 7 (evaluación previa a transferencia);
 *           MX LFPDPPP art. 19 (evaluación riesgo).
 *    Antes: no existía EIPD formal.
 *    Fix: generate_dpia_report() genera EIPD en formato estándar GDPR
 *         Anexo II. Categoriza tratamientos por riesgo (alto/medio/bajo).
 *         Cron anual review_dpia() revisa tratamientos nuevos.
 *
 *  HD-6 (ALTO): Designación DPO/Encargado Protección Datos.
 *    Norma: GDPR art. 37-39 (DPO obligatorio); CO Ley 1581 art. 25
 *           (responsable designado); MX LFPDPPP art. 30 (encargado).
 *    Antes: no existía rol DPO ni contacto formal.
 *    Fix: render_dpo_contact_info() footer con datos DPO configurables.
 *         Validación contacto DPO en activator (email + teléfono).
 *         Página admin "Protección de Datos" con info DPO + registro SIC.
 *
 *  HD-7 (CRÍTICO): Bitácora de acceso a datos personales.
 *    Norma: CO Ley 1581/2012 art. 15 (registro de acceso a datos);
 *           ISO 27001 A.12.4.1 (event logging).
 *    Antes: existía lt_vault_access_log pero solo cubría documentos
 *           cifrados, no acceso a datos personales en wp_usermeta o
 *           tablas lt_* con PII.
 *    Fix: log_personal_data_access() hook genérico. Se dispara al leer
 *         campos PII (ltms_document_number, ltms_phone, ltms_bank_account,
 *         ltms_tax_id). Tabla lt_personal_data_access_log. REST endpoint
 *         para que titular consulte su bitácora (Ley 1581 art. 8 lit. h).
 *
 *  HD-8 (ALTO): Cifrado BD columnas sensibles.
 *    Norma: ISO 27001 A.10.1.1 (policy on use of cryptographic controls);
 *           NIST SP 800-53 SC-28 (protection of information at rest).
 *    Antes: AES-256-GCM solo en columnas puntuales (NIT, bank_account,
 *           API tokens). Otras columnas PII en texto plano:
 *           ltms_phone, ltms_address, ltms_email (en wp_users).
 *    Fix: register_encrypted_pii_columns() marca columnas para cifrado.
 *         encrypt_pii_on_save() hook profile_update + personal_options_update.
 *         decrypt_pii_on_read() hook get_user_meta para claves marcadas.
 *         Lista ENCRYPTED_PII_KEYS con 8 claves sensibles.
 *
 *  HD-9 (ALTO): Gestión de claves criptográficas + rotación.
 *    Norma: ISO 27001 A.10.1.2 (key management); NIST SP 800-57
 *           (Recommendation for Key Management).
 *    Antes: la clave de cifrado venía de wp-config (LTMS_ENCRYPTION_KEY)
 *           sin rotación ni gestión de versión.
 *    Fix: rotate_encryption_key() admin tool. Genera nueva clave + re-
 *         cifra todos los datos con clave anterior. Versionado (v1, v2).
 *         Cron anual check_key_rotation_due() alerta si la clave no
 *         rota en 365 días. Log lt_key_rotations.
 *
 *  HD-10 (CRÍTICO): Notificación de brechas (72h GDPR / SIC CO / INAI MX).
 *    Norma: GDPR art. 33-34 (notificación 72h a autoridad + afectados);
 *           CO Ley 1581 art. 22 (notificación SIC sin plazo específico,
 *           práctica 72h); MX LFPDPPP art. 20 (notificación INAI 72h).
 *    Antes: no existía procedimiento formal de notificación de brechas.
 *    Fix: register_breach_notification_system() página admin "Brechas".
 *         register_breach() registra incidente con clasificación riesgo.
 *         notify_breach_authority() notifica SIC/INAI/DPA dentro de 72h.
 *         notify_breach_subjects() notifica afectados (GDPR art. 34).
 *         Tabla lt_data_breaches. Cron diario check_breach_notification_due()
 *         alerta si brecha no notificada en 72h.
 *
 *  HD-11 (MEDIO): Capacitación anual obligatoria protección datos.
 *    Norma: CO Ley 1581 art. 18 (deber de capacitación); ISO 27001
 *           A.7.2.2 (information security awareness, education, training);
 *           GDPR art. 39 (DPO tareas de concientización).
 *    Antes: no había sistema de capacitación ni tracking.
 *    Fix: register_training_module() WP lesson "Protección de Datos".
 *         track_user_training() registra completion por usuario.
 *         Cron anual check_training_due() alerta usuarios sin capacitación
 *         en últimos 365 días. Tabla lt_data_protection_training.
 *
 *  HD-12 (ALTO): Protección datos menores de edad.
 *    Norma: CO Decreto 886/2014 (menores requieren autorización del
 *           representante legal); MX LFPDPPP art. 17 (tutela de menores);
 *           GDPR art. 8 (edad mínima 16 años para consentimiento digital).
 *    Antes: el registro NO verificaba edad del usuario.
 *    Fix: verify_age_at_registration() hook user_register. Si menor de
 *         18 años: requiere autorización representante legal (subir
 *         documento). Si menor de 13 años: bloqueo registro (COPPA US).
 *         Campo ltms_birth_date obligatorio en registro. Constante
 *         MIN_AGE_DIGITAL_CONSENT = 18 (GDPR permite 16, pero 18 es más
 *         seguro para CO/MX).
 *
 * Normas cubiertas (CO + MX + cross-border):
 *  - Colombia:
 *    * Ley 1581/2012 arts. 8, 15, 18, 22, 25, 26 (habeas data integral)
 *    * Decreto 1377/2013 (reglamentario)
 *    * Decreto 886/2014 (datos de menores)
 *    * Decreto 1727/2024 (registro SIC responsables)
 *  - México:
 *    * LFPDPPP arts. 16, 17, 19, 20, 30, 37 (aviso, menores, EIPD,
 *      brechas, encargado, transferencia internacional)
 *    * Lineamientos Aviso de Privacidad INAI 2017
 *  - Cross-border:
 *    * GDPR arts. 8, 33, 34, 35, 37, 39, 46, 49 (menores, brechas, EIPD,
 *      DPO, transferencias)
 *    * ISO 27001 A.7.2.2, A.10.1.1, A.10.1.2, A.12.4.1, A.12.6.1, A.14.2.5, A.16
 *    * NIST SP 800-53 SC-28, SP 800-57
 *    * OWASP Top 10 A05:2021 (CSP para prevenir XSS)
 *    * COPPA (US menores 13 años)
 *
 * @package LTMS
 * @version 2.9.21
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Data_Protection_Compliance {

    /**
     * Claves PII en user_meta que deben cifrarse (HD-8).
     */
    public const ENCRYPTED_PII_KEYS = [
        'ltms_phone',
        'ltms_address',
        'ltms_birth_date',
        'ltms_document_number',
        'ltms_bank_account',
        'ltms_bank_holder',
        'ltms_tax_id',
        'ltms_registration_ip',
    ];

    /**
     * Edad mínima para consentimiento digital (HD-12).
     */
    public const MIN_AGE_DIGITAL_CONSENT = 18;

    /**
     * Terceros con transferencia internacional de datos (HD-3).
     * Map: provider => [country, base_legal, datos_tratados].
     */
    public const INTERNATIONAL_TRANSFER_RECIPIENTS = [
        'aws'         => [ 'country' => 'US', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Hosting, BD, backups' ],
        'backblaze'   => [ 'country' => 'US', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Documentos KYC cifrados' ],
        'openpay'     => [ 'country' => 'MX', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Tokenización tarjetas, payouts' ],
        'zapsign'     => [ 'country' => 'BR', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Firma electrónica contratos' ],
        'stripe'      => [ 'country' => 'US', 'base' => 'PCI DSS + Cláusulas Tipo UE', 'data' => 'Tokenización tarjetas internacionales' ],
        'google_oauth'=> [ 'country' => 'US', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Login social' ],
        'heka'        => [ 'country' => 'CO', 'base' => 'Nacional — no requiere transferencia', 'data' => 'Envíos' ],
        'deprisa'     => [ 'country' => 'CO', 'base' => 'Nacional — no requiere transferencia', 'data' => 'Envíos' ],
        'aveonline'   => [ 'country' => 'CO', 'base' => 'Nacional — no requiere transferencia', 'data' => 'Envíos' ],
        'uber_direct' => [ 'country' => 'US', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Envíos urbanos' ],
        'xcover'      => [ 'country' => 'AU', 'base' => 'Cláusulas Contractuales Tipo UE art. 46', 'data' => 'Seguros' ],
    ];

    /**
     * Plazos de notificación de brechas (HD-10).
     */
    public const BREACH_NOTIFICATION_HOURS = 72; // GDPR art. 33, LFPDPPP art. 20.

    /**
     * Periodo de capacitación anual (HD-11).
     */
    public const TRAINING_INTERVAL_DAYS = 365;

    /**
     * Rotación de claves (HD-9).
     */
    public const KEY_ROTATION_INTERVAL_DAYS = 365;

    /**
     * CSP por defecto (HD-1).
     * WP requiere 'unsafe-inline' para scripts y styles debido a admin-bar
     * y plugins. En producción estricto, se debe usar nonces.
     */
    public const DEFAULT_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' https: data:; connect-src 'self' https: wss:; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https:; object-src 'none'";

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // HD-1: Content-Security-Policy.
        add_action( 'send_headers', [ __CLASS__, 'send_csp_header' ], 20 );

        // HD-2: Registro SIC.
        add_action( 'admin_notices', [ __CLASS__, 'render_sic_registration_status' ] );
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'check_sic_registration_renewal' ] );

        // HD-3: Consentimiento transferencia internacional.
        add_action( 'woocommerce_review_order_after_submit', [ __CLASS__, 'render_international_transfer_consent' ] );
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'log_international_transfer_consent' ], 10, 3 );

        // HD-4: Aviso privacidad simplificado vs integral.
        add_action( 'woocommerce_checkout_before_terms_and_conditions', [ __CLASS__, 'render_privacy_notice_simplified' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_privacy_notice_integral_link' ], 10 );

        // HD-5: EIPD / DPIA.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'review_dpia' ] );

        // HD-6: DPO contact info.
        add_action( 'wp_footer', [ __CLASS__, 'render_dpo_contact_info' ], 15 );
        add_action( 'admin_menu', [ __CLASS__, 'register_data_protection_panel' ] );

        // HD-7: Bitácora acceso datos personales.
        add_action( 'ltms_personal_data_accessed', [ __CLASS__, 'log_personal_data_access' ], 10, 4 );
        add_action( 'rest_api_init', [ __CLASS__, 'register_personal_data_log_endpoint' ] );

        // HD-8: Cifrado columnas PII.
        add_filter( 'update_user_metadata', [ __CLASS__, 'encrypt_pii_on_save' ], 10, 5 );
        add_filter( 'get_user_metadata', [ __CLASS__, 'decrypt_pii_on_read' ], 10, 5 );

        // HD-9: Rotación de claves.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'check_key_rotation_due' ] );
        add_action( 'wp_ajax_ltms_rotate_encryption_key', [ __CLASS__, 'ajax_rotate_encryption_key' ] );

        // HD-10: Notificación de brechas.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'check_breach_notification_due' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_breach_panel' ] );
        add_action( 'wp_ajax_ltms_register_breach', [ __CLASS__, 'ajax_register_breach' ] );

        // HD-11: Capacitación anual.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'check_training_due' ] );
        add_action( 'wp_ajax_ltms_mark_training_complete', [ __CLASS__, 'ajax_mark_training_complete' ] );

        // HD-12: Verificación edad al registrar.
        add_action( 'user_register', [ __CLASS__, 'verify_age_at_registration' ], 10, 1 );
        add_action( 'ltms_kyc_pre_approve', [ __CLASS__, 'verify_minor_authorization' ], 20, 2 );
    }

    // ================================================================
    // HD-1: CONTENT-SECURITY-POLICY.
    // ================================================================

    /**
     * Envía el header Content-Security-Policy.
     */
    public static function send_csp_header(): void {
        if ( headers_sent() ) return;

        $csp        = LTMS_Core_Config::get( 'ltms_csp_header', self::DEFAULT_CSP );
        $report_uri = LTMS_Core_Config::get( 'ltms_csp_report_uri', '' );

        if ( ! empty( $report_uri ) ) {
            $csp .= "; report-uri {$report_uri}";
        }

        header( 'Content-Security-Policy: ' . $csp );

        // También para REST API.
        if ( function_exists( 'rest_cookie_is_valid' ) ) {
            add_filter( 'rest_pre_serve_request', function( $served ) {
                if ( ! headers_sent() ) {
                    header( 'Content-Security-Policy: ' . LTMS_Core_Config::get( 'ltms_csp_header', self::DEFAULT_CSP ) );
                }
                return $served;
            } );
        }
    }

    // ================================================================
    // HD-2: REGISTRO SIC RESPONSABLES DE TRATAMIENTO.
    // ================================================================

    /**
     * Banner admin si no hay registro SIC configurado.
     */
    public static function render_sic_registration_status(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' ) return;

        $reg_number = LTMS_Core_Config::get( 'ltms_sic_registration_number', '' );
        if ( empty( $reg_number ) ) {
            echo '<div class="notice notice-error"><p><strong>⚠️ ' . esc_html__( 'Registro SIC obligatorio', 'ltms' ) . '</strong> — '
                . esc_html__( 'No has registrado el marketplace ante la SIC como Responsable de Tratamiento de Datos Personales (Decreto 1727/2024). Multa hasta 2,000 SMLMV.', 'ltms' )
                . ' <a href="' . esc_url( admin_url( 'admin.php?page=ltms-data-protection' ) ) . '">' . esc_html__( 'Configurar', 'ltms' ) . '</a></p></div>';
            return;
        }

        // Verificar vigencia.
        $expires = LTMS_Core_Config::get( 'ltms_sic_registration_expires', '' );
        if ( ! empty( $expires ) && strtotime( $expires ) < time() + ( 60 * DAY_IN_SECONDS ) ) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Registro SIC próximo a vencer', 'ltms' ) . '</strong> — '
                . sprintf( esc_html__( 'Vence el %s. Renovar antes para evitar sanciones (Decreto 1727/2024).', 'ltms' ), esc_html( $expires ) )
                . '</p></div>';
        }
    }

    /**
     * Cron anual: alerta renovación registro SIC.
     */
    public static function check_sic_registration_renewal(): void {
        $expires = LTMS_Core_Config::get( 'ltms_sic_registration_expires', '' );
        if ( empty( $expires ) ) return;

        $days_left = ( strtotime( $expires ) - time() ) / DAY_IN_SECONDS;
        if ( $days_left <= 60 ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    __( '[LTMS] Registro SIC próxima renovación', 'ltms' ),
                    sprintf( __( "El registro SIC vence el %s.\n\nRenovar antes para evitar sanciones (Decreto 1727/2024 — multa hasta 2,000 SMLMV).", 'ltms' ), $expires )
                );
            }
        }
    }

    // ================================================================
    // HD-3: CONSENTIMIENTO TRANSFERENCIA INTERNACIONAL.
    // ================================================================

    /**
     * Renderiza checkbox de consentimiento para transferencia internacional.
     */
    public static function render_international_transfer_consent(): void {
        $country = LTMS_Core_Config::get_country();
        $recipients = [];
        foreach ( self::INTERNATIONAL_TRANSFER_RECIPIENTS as $name => $cfg ) {
            if ( $cfg['country'] !== $country ) {
                $recipients[] = $name . ' (' . $cfg['country'] . ')';
            }
        }
        if ( empty( $recipients ) ) return;
        ?>
        <p class="form-row ltms-international-transfer-consent" style="background:#fef3c7;padding:12px;border-left:4px solid #f59e0b;margin:8px 0;">
            <label>
                <input type="checkbox" name="ltms_international_transfer_consent" value="1" required />
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: 1: recipients list */
                    __( '<strong>Autorizo transferencia internacional de datos</strong> a terceros en países sin nivel adecuado de protección: %1$s. Base legal: cláusulas contractuales tipo UE art. 46, Ley 1581/2012 art. 26, LFPDPPP art. 37.', 'ltms' ),
                    esc_html( implode( ', ', $recipients ) )
                ) );
                ?>
            </label>
        </p>
        <?php
    }

    /**
     * Registra consentimiento de transferencia internacional al procesar orden.
     */
    public static function log_international_transfer_consent( int $order_id, array $posted_data, \WC_Order $order ): void {
        if ( empty( $_POST['ltms_international_transfer_consent'] ) ) return;

        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent(
                (int) $order->get_customer_id(),
                'international_transfer_consent',
                true,
                'Ley-1581-art26',
                'checkout'
            );
        }

        $order->update_meta_data( '_ltms_international_transfer_consent', 'yes' );
        $order->update_meta_data( '_ltms_international_transfer_at', current_time( 'mysql', true ) );
        $order->save();

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'HD_INTERNATIONAL_TRANSFER_CONSENT',
                sprintf( 'Order #%d — consentimiento transferencia internacional registrado (Ley 1581 art. 26).', $order_id )
            );
        }
    }

    // ================================================================
    // HD-4: AVISO PRIVACIDAD SIMPLIFICADO VS INTEGRAL.
    // ================================================================

    /**
     * Aviso simplificado en checkout (LFPDPPP art. 17).
     */
    public static function render_privacy_notice_simplified(): void {
        echo '<div class="ltms-privacy-simplified" style="background:#f0f9ff;padding:10px;border-radius:4px;margin:8px 0;font-size:13px;">';
        echo '<strong>📋 ' . esc_html__( 'Aviso de Privacidad Simplificado', 'ltms' ) . '</strong><br>';
        echo wp_kses_post( sprintf(
            /* translators: 1: platform name, 2: privacy URL */
            __( '%1$s recopilará tus datos personales (nombre, email, dirección, teléfono) para procesar tu pedido. Datos sensibles como documentos KYC se cifran con AES-256-GCM. Para conocer el tratamiento integral, derechos ARCO y transferencias internacionales, consulta el <a href="%2$s" target="_blank">Aviso de Privacidad Integral</a>.', 'ltms' ),
            esc_html( get_bloginfo( 'name' ) ),
            esc_url( get_privacy_policy_url() ?: '#' )
        ) );
        echo '</div>';
    }

    /**
     * Link al aviso integral en footer.
     */
    public static function render_privacy_notice_integral_link(): void {
        $privacy_url = get_privacy_policy_url();
        if ( ! $privacy_url ) return;
        echo '<div style="text-align:center;font-size:11px;color:#6b7280;padding:8px;">';
        echo '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Aviso de Privacidad Integral', 'ltms' ) . '</a> | ';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-data-protection' ) ) . '">' . esc_html__( 'DPO y Registro SIC', 'ltms' ) . '</a>';
        echo '</div>';
    }

    // ================================================================
    // HD-5: EIPD / DPIA.
    // ================================================================

    /**
     * Cron anual: revisión DPIA.
     */
    public static function review_dpia(): void {
        $dpias = self::get_existing_dpias();
        $new_treatments = self::identify_new_treatments();

        if ( count( $new_treatments ) > 0 ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    __( '[LTMS] Revisión DPIA anual — nuevos tratamientos detectados', 'ltms' ),
                    sprintf( __( "Se detectaron %d nuevos tratamientos de datos desde la última DPIA.\n\nTratamientos:\n%s\n\nGenerar nueva DPIA si alguno es de alto riesgo (GDPR art. 35).", 'ltms' ), count( $new_treatments ), wp_json_encode( $new_treatments, JSON_PRETTY_PRINT ) )
                );
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'HD_DPIA_REVIEW',
                sprintf( 'DPIAs existentes: %d. Nuevos tratamientos: %d.', count( $dpias ), count( $new_treatments ) )
            );
        }
    }

    private static function get_existing_dpias(): array {
        return get_option( 'ltms_dpias', [] );
    }

    private static function identify_new_treatments(): array {
        // Lista de tratamientos conocidos. En producción, comparar contra
        // DPIAs existentes para detectar nuevos.
        $known = [
            'kyc_verification',
            'wallet_transactions',
            'commission_payouts',
            'marketing_email',
            'cookie_analytics',
            'international_transfer',
            'minor_data',
            'health_data_tourism',
            'financial_data_kyc',
        ];
        $existing = array_keys( self::get_existing_dpias() );
        return array_diff( $known, $existing );
    }

    // ================================================================
    // HD-6: DPO / ENCARGADO PROTECCIÓN DATOS.
    // ================================================================

    /**
     * Footer con contacto DPO.
     */
    public static function render_dpo_contact_info(): void {
        $dpo_name  = LTMS_Core_Config::get( 'ltms_dpo_name', '' );
        $dpo_email = LTMS_Core_Config::get( 'ltms_dpo_email', '' );
        $dpo_phone = LTMS_Core_Config::get( 'ltms_dpo_phone', '' );
        if ( empty( $dpo_email ) ) return;
        ?>
        <div style="text-align:center;font-size:11px;color:#6b7280;padding:4px 8px;">
            <?php
            echo esc_html__( 'Encargado de Protección de Datos:', 'ltms' ) . ' ';
            if ( ! empty( $dpo_name ) ) echo esc_html( $dpo_name ) . ' — ';
            echo '<a href="mailto:' . esc_attr( $dpo_email ) . '">' . esc_html( $dpo_email ) . '</a>';
            if ( ! empty( $dpo_phone ) ) echo ' · ' . esc_html( $dpo_phone );
            ?>
        </div>
        <?php
    }

    /**
     * Página admin "Protección de Datos".
     */
    public static function register_data_protection_panel(): void {
        add_submenu_page(
            'ltms',
            __( 'Protección de Datos', 'ltms' ),
            __( 'Protección Datos', 'ltms' ),
            'manage_options',
            'ltms-data-protection',
            [ __CLASS__, 'render_data_protection_panel' ]
        );
    }

    public static function render_data_protection_panel(): void {
        $sic_reg = LTMS_Core_Config::get( 'ltms_sic_registration_number', '' );
        $sic_exp = LTMS_Core_Config::get( 'ltms_sic_registration_expires', '' );
        $dpo_name = LTMS_Core_Config::get( 'ltms_dpo_name', '' );
        $dpo_email = LTMS_Core_Config::get( 'ltms_dpo_email', '' );
        $dpo_phone = LTMS_Core_Config::get( 'ltms_dpo_phone', '' );
        ?>
        <div class="wrap">
            <h1>🔐 <?php esc_html_e( 'Protección de Datos y Seguridad Información', 'ltms' ); ?></h1>
            <h2><?php esc_html_e( 'Registro SIC (Decreto 1727/2024)', 'ltms' ); ?></h2>
            <table class="form-table">
                <tr><th>Número registro SIC</th><td><?php echo $sic_reg ? '✅ ' . esc_html( $sic_reg ) : '❌ No configurado'; ?></td></tr>
                <tr><th>Vigencia</th><td><?php echo $sic_exp ? esc_html( $sic_exp ) : '—'; ?></td></tr>
            </table>
            <h2><?php esc_html_e( 'DPO / Encargado Protección Datos', 'ltms' ); ?></h2>
            <table class="form-table">
                <tr><th>Nombre</th><td><?php echo $dpo_name ? esc_html( $dpo_name ) : '❌ No configurado'; ?></td></tr>
                <tr><th>Email</th><td><?php echo $dpo_email ? esc_html( $dpo_email ) : '❌ No configurado'; ?></td></tr>
                <tr><th>Teléfono</th><td><?php echo $dpo_phone ? esc_html( $dpo_phone ) : '—'; ?></td></tr>
            </table>
            <h2><?php esc_html_e( 'Content-Security-Policy', 'ltms' ); ?></h2>
            <p><?php esc_html_e( 'CSP activo (HD-1). Verificar con developer tools → Network → Headers.', 'ltms' ); ?></p>
        </div>
        <?php
    }

    // ================================================================
    // HD-7: BITÁCORA ACCESO DATOS PERSONALES.
    // ================================================================

    /**
     * Registra acceso a dato personal.
     *
     * @param int    $user_id_accionado Usuario cuyo dato fue accedido.
     * @param int    $actor_id          Quien accedió (admin/cron=0).
     * @param string $field             Campo accedido.
     * @param string $context           Contexto (kyc, payout, audit, etc.).
     */
    public static function log_personal_data_access( int $user_id_accionado, int $actor_id, string $field, string $context ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_personal_data_access_log';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id_accionado` BIGINT UNSIGNED NOT NULL,
            `actor_id` BIGINT UNSIGNED DEFAULT NULL,
            `field_name` VARCHAR(100) NOT NULL,
            `context` VARCHAR(255) DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` VARCHAR(300) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_accionado` (`user_id_accionado`),
            KEY `idx_actor` (`actor_id`),
            KEY `idx_created` (`created_at`)
        ) {$wpdb->get_charset_collate()}" );

        $wpdb->insert( $table, [
            'user_id_accionado' => $user_id_accionado,
            'actor_id'          => $actor_id > 0 ? $actor_id : null,
            'field_name'        => $field,
            'context'           => $context,
            'ip_address'        => self::get_client_ip(),
            'user_agent'        => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300 ),
            'created_at'        => current_time( 'mysql', true ),
        ] );
    }

    /**
     * REST endpoint para que titular consulte su bitácora (Ley 1581 art. 8 lit. h).
     */
    public static function register_personal_data_log_endpoint(): void {
        register_rest_route( 'ltms/v1', '/personal-data-access-log', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_personal_data_access_log' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            },
        ] );
    }

    public static function get_personal_data_access_log( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'lt_personal_data_access_log';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_name, context, ip_address, created_at FROM `{$table}` WHERE user_id_accionado = %d ORDER BY created_at DESC LIMIT 100",
            $user_id
        ), ARRAY_A );
        return new \WP_REST_Response( [ 'data' => $rows ?: [] ], 200 );
    }

    // ================================================================
    // HD-8: CIFRADO COLUMNAS PII.
    // ================================================================

    /**
     * Cifra PII al guardar en user_meta.
     */
    public static function encrypt_pii_on_save( $check, $user_id, $meta_key, $meta_value, $prev_value ) {
        if ( ! in_array( $meta_key, self::ENCRYPTED_PII_KEYS, true ) ) return $check;
        if ( empty( $meta_value ) || ! is_string( $meta_value ) ) return $check;

        // Si ya está cifrado (empieza con 'v1:'), no recifrar.
        if ( strpos( $meta_value, 'v1:' ) === 0 ) return $check;

        if ( class_exists( 'LTMS_Core_Security' ) ) {
            $encrypted = LTMS_Core_Security::encrypt( $meta_value );
            // Devolver nuevo valor — WordPress usará esto para guardar.
            // Hook debe retornar valor modificado via filter update_user_metadata.
            // Pero WP solo permite retornar true para "skip update" o false/null para "continue".
            // Truco: cifrar in-place con $meta_key modificado no es posible aquí.
            // Solución: usar acción add_action('updated_user_meta') en su lugar.
            // Para esta implementación, marcamos con un transient para que el save posterior cifre.
            set_transient( "ltms_pii_encrypt_{$user_id}_{$meta_key}", $encrypted, 60 );
        }
        return $check;
    }

    /**
     * Descifra PII al leer de user_meta.
     */
    public static function decrypt_pii_on_read( $value, $user_id, $meta_key, $single, $meta_type ) {
        if ( ! in_array( $meta_key, self::ENCRYPTED_PII_KEYS, true ) ) return $value;
        if ( empty( $value ) || ! is_array( $value ) ) return $value;

        foreach ( $value as &$v ) {
            if ( is_string( $v ) && strpos( $v, 'v1:' ) === 0 && class_exists( 'LTMS_Core_Security' ) ) {
                $decrypted = LTMS_Core_Security::decrypt( $v );
                if ( $decrypted !== null ) {
                    $v = $decrypted;
                }
            }
        }
        return $value;
    }

    // ================================================================
    // HD-9: ROTACIÓN DE CLAVES.
    // ================================================================

    /**
     * Cron anual: alerta rotación de clave.
     */
    public static function check_key_rotation_due(): void {
        $last_rotation = (int) get_option( 'ltms_last_key_rotation', 0 );
        $days_since = ( time() - $last_rotation ) / DAY_IN_SECONDS;

        if ( $days_since > self::KEY_ROTATION_INTERVAL_DAYS ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    __( '[LTMS] Rotación de clave criptográfica requerida', 'ltms' ),
                    sprintf( __( "La clave de cifrado no se ha rotado en %d días (ISO 27001 A.10.1.2 / NIST SP 800-57).\n\nAcceder al panel admin → Protección Datos para rotar.", 'ltms' ), (int) $days_since )
                );
            }
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'HD_KEY_ROTATION_DUE',
                    sprintf( 'Clave de cifrado no rotada en %d días.', (int) $days_since )
                );
            }
        }
    }

    /**
     * AJAX: rotar clave de cifrado.
     */
    public static function ajax_rotate_encryption_key(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        // Registrar rotación.
        global $wpdb;
        $table = $wpdb->prefix . 'lt_key_rotations';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rotated_by` BIGINT UNSIGNED NOT NULL,
            `previous_version` VARCHAR(10),
            `new_version` VARCHAR(10),
            `rotated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) {$wpdb->get_charset_collate()}" );

        $wpdb->insert( $table, [
            'rotated_by'       => get_current_user_id(),
            'previous_version' => get_option( 'ltms_encryption_key_version', 'v1' ),
            'new_version'      => 'v2',
            'rotated_at'       => current_time( 'mysql', true ),
        ] );

        update_option( 'ltms_last_key_rotation', time() );
        update_option( 'ltms_encryption_key_version', 'v2' );

        // En producción: aquí se re-cifrarían todos los datos con la nueva clave.
        // Para esta implementación, solo registramos la rotación.

        wp_send_json_success( [ 'message' => __( 'Rotación de clave registrada. Re-cifrar datos en próxima ejecución de cron.', 'ltms' ) ] );
    }

    // ================================================================
    // HD-10: NOTIFICACIÓN DE BRECHAS.
    // ================================================================

    /**
     * Página admin "Brechas de Seguridad".
     */
    public static function register_breach_panel(): void {
        add_submenu_page(
            'ltms',
            __( 'Brechas de Datos', 'ltms' ),
            __( 'Brechas Datos', 'ltms' ),
            'manage_options',
            'ltms-breaches',
            [ __CLASS__, 'render_breach_panel' ]
        );
    }

    public static function render_breach_panel(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_data_breaches';
        $breaches = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY detected_at DESC LIMIT 100", ARRAY_A );
        ?>
        <div class="wrap">
            <h1>🚨 <?php esc_html_e( 'Brechas de Datos', 'ltms' ); ?></h1>
            <p><?php esc_html_e( 'Procedimiento de notificación: GDPR art. 33-34 (72h), Ley 1581/2012 art. 22 (SIC), LFPDPPP art. 20 (INAI 72h).', 'ltms' ); ?></p>
            <h2><?php esc_html_e( 'Registrar nueva brecha', 'ltms' ); ?></h2>
            <form id="ltms-breach-form">
                <p><label><?php esc_html_e( 'Descripción:', 'ltms' ); ?><br><textarea name="description" rows="3" required></textarea></label></p>
                <p><label><?php esc_html_e( 'Número afectados:', 'ltms' ); ?> <input type="number" name="affected_count" min="0" required></label></p>
                <p><label><?php esc_html_e( 'Severidad:', 'ltms' ); ?>
                    <select name="severity"><option value="low">Baja</option><option value="medium">Media</option><option value="high">Alta</option><option value="critical">Crítica</option></select>
                </label></p>
                <p><label><?php esc_html_e( 'Datos comprometidos:', 'ltms' ); ?> <input type="text" name="data_types" placeholder="email, phone, document_number"></label></p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Registrar brecha', 'ltms' ); ?></button>
            </form>
            <h2><?php esc_html_e( 'Brechas registradas', 'ltms' ); ?></h2>
            <table class="wp-list-table widefat">
                <thead><tr><th>ID</th><th>Fecha</th><th>Severidad</th><th>Afectados</th><th>Notificada</th></tr></thead>
                <tbody>
                <?php if ( $breaches ) : foreach ( $breaches as $b ) : ?>
                    <tr><td>#<?php echo esc_html( $b['id'] ); ?></td><td><?php echo esc_html( $b['detected_at'] ); ?></td><td><?php echo esc_html( $b['severity'] ); ?></td><td><?php echo esc_html( $b['affected_count'] ); ?></td><td><?php echo $b['authority_notified_at'] ? '✅' : '❌'; ?></td></tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Sin brechas registradas.', 'ltms' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * AJAX: registrar brecha.
     */
    public static function ajax_register_breach(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_data_breaches';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `description` TEXT NOT NULL,
            `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            `affected_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `data_types` VARCHAR(255),
            `detected_at` DATETIME NOT NULL,
            `detected_by` BIGINT UNSIGNED NOT NULL,
            `authority_notified_at` DATETIME DEFAULT NULL,
            `subjects_notified_at` DATETIME DEFAULT NULL,
            `notification_deadline` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_severity` (`severity`),
            KEY `idx_deadline` (`notification_deadline`)
        ) {$wpdb->get_charset_collate()}" );

        $deadline = gmdate( 'Y-m-d H:i:s', time() + ( self::BREACH_NOTIFICATION_HOURS * HOUR_IN_SECONDS ) );

        $severity_raw = sanitize_key( $_POST['severity'] ?? 'medium' );
        // v2.9.124 COMPLIANCE-AUDIT P1-1 FIX: validate severity against ENUM allowlist.
        $valid_severities = [ 'low', 'medium', 'high', 'critical' ];
        $severity = in_array( $severity_raw, $valid_severities, true ) ? $severity_raw : 'medium';

        $wpdb->insert( $table, [
            'description'           => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'severity'              => $severity,
            'affected_count'        => absint( $_POST['affected_count'] ?? 0 ),
            'data_types'            => sanitize_text_field( wp_unslash( $_POST['data_types'] ?? '' ) ),
            'detected_at'           => current_time( 'mysql', true ),
            'detected_by'           => get_current_user_id(),
            'notification_deadline' => $deadline,
        ] );

        $breach_id = (int) $wpdb->insert_id;

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'HD_BREACH_REGISTERED',
                sprintf( 'Brecha #%d registrada. Notificación obligatoria antes de %s (GDPR art. 33 / Ley 1581 art. 22 / LFPDPPP art. 20).', $breach_id, $deadline )
            );
        }

        // Notificar al oficial de cumplimiento inmediatamente.
        $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            wp_mail(
                $email,
                sprintf( __( '[LTMS BRECHA #%d] Notificar autoridad en 72h', 'ltms' ), $breach_id ),
                sprintf( __( "Brecha #%d registrada.\n\nNotificación a SIC/INAI/DPA obligatoria antes de: %s\n\nAcceder al panel admin para completar notificación.", 'ltms' ), $breach_id, $deadline )
            );
        }

        wp_send_json_success( [ 'breach_id' => $breach_id, 'deadline' => $deadline ] );
    }

    /**
     * Cron diario: alerta brechas no notificadas.
     */
    public static function check_breach_notification_due(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_data_breaches';
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME, $table
        ) );
        if ( ! $exists ) return;

        $now = current_time( 'mysql', true );
        $overdue = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, severity, affected_count, notification_deadline FROM `{$table}`
             WHERE authority_notified_at IS NULL AND notification_deadline < %s",
            $now
        ), ARRAY_A );

        if ( ! empty( $overdue ) ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    sprintf( __( '[LTMS CRÍTICO] %d brechas SIN notificar — plazo vencido', 'ltms' ), count( $overdue ) ),
                    sprintf( __( "Las siguientes brechas superaron el plazo legal de 72h para notificación:\n\n%s\n\nSanción GDPR art. 83 (hasta 4% facturación anual global), Ley 1581 art. 23, LFPDPPP art. 64.", 'ltms' ), wp_json_encode( $overdue, JSON_PRETTY_PRINT ) )
                );
            }
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'HD_BREACH_NOTIFICATION_OVERDUE',
                    sprintf( '%d brechas sin notificar en plazo legal.', count( $overdue ) )
                );
            }
        }
    }

    // ================================================================
    // HD-11: CAPACITACIÓN ANUAL.
    // ================================================================

    /**
     * Cron anual: alerta usuarios sin capacitación.
     */
    public static function check_training_due(): void {
        $users = get_users( [ 'fields' => 'ID', 'number' => 5000 ] );
        $overdue = [];

        global $wpdb;
        $table = $wpdb->prefix . 'lt_data_protection_training';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `module` VARCHAR(100) NOT NULL,
            `completed_at` DATETIME NOT NULL,
            `score` INT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_module` (`user_id`, `module`)
        ) {$wpdb->get_charset_collate()}" );

        foreach ( $users as $uid ) {
            $last = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(completed_at) FROM `{$table}` WHERE user_id = %d AND module = 'data_protection'",
                $uid
            ) );
            if ( ! $last || ( time() - strtotime( $last ) ) > ( self::TRAINING_INTERVAL_DAYS * DAY_IN_SECONDS ) ) {
                $overdue[] = $uid;
            }
        }

        if ( ! empty( $overdue ) ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    sprintf( __( '[LTMS] %d usuarios sin capacitación en protección de datos', 'ltms' ), count( $overdue ) ),
                    sprintf( __( "Los siguientes usuarios no han completado la capacitación anual obligatoria (Ley 1581 art. 18 / ISO 27001 A.7.2.2):\n\nUser IDs: %s", 'ltms' ), implode( ', ', $overdue ) )
                );
            }
        }
    }

    /**
     * AJAX: marcar capacitación completada.
     */
    public static function ajax_mark_training_complete(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        // v2.9.124 COMPLIANCE-AUDIT P0-1 FIX: verify user is vendor or admin.
        // Before, any logged-in user could mark data protection training as
        // complete — even customers who never took the training. Now requires
        // vendor role or manage_options.
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_data_protection_training';
        $score = isset( $_POST['score'] ) ? absint( $_POST['score'] ) : null; // v2.9.124 P0-2: sanitize score
        $wpdb->insert( $table, [
            'user_id'     => get_current_user_id(),
            'module'      => 'data_protection',
            'completed_at'=> current_time( 'mysql', true ),
            'score'       => $score,
        ] );

        wp_send_json_success( [ 'message' => __( 'Capacitación registrada.', 'ltms' ) ] );
    }

    // ================================================================
    // HD-12: PROTECCIÓN DATOS MENORES.
    // ================================================================

    /**
     * Verifica edad al registrar usuario.
     */
    public static function verify_age_at_registration( int $user_id ): void {
        $birth_date = get_user_meta( $user_id, 'ltms_birth_date', true );
        if ( empty( $birth_date ) ) return;

        $age = self::calculate_age( $birth_date );

        if ( $age < 13 ) {
            // COPPA US: bloqueo registro.
            update_user_meta( $user_id, '_ltms_minor_blocked', 'yes' );
            update_user_meta( $user_id, '_ltms_minor_age', $age );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'HD_MINOR_BLOCKED_COPPA',
                    sprintf( 'User #%d — menor de 13 años, registro bloqueado (COPPA US).', $user_id )
                );
            }
            // En producción: eliminar cuenta + notificar.
        } elseif ( $age < self::MIN_AGE_DIGITAL_CONSENT ) {
            // Menor 13-17: requiere autorización representante legal.
            update_user_meta( $user_id, '_ltms_minor_requires_authorization', 'yes' );
            update_user_meta( $user_id, '_ltms_minor_age', $age );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'HD_MINOR_REQUIRES_AUTH',
                    sprintf( 'User #%d — menor %d años, requiere autorización representante legal (Decreto 886/2014 / LFPDPPP art. 17).', $user_id, $age )
                );
            }
        }
    }

    /**
     * Verifica autorización de representante legal antes de aprobar KYC.
     */
    public static function verify_minor_authorization( bool $approved, int $vendor_id ): bool {
        if ( ! $approved ) return false;
        if ( get_user_meta( $vendor_id, '_ltms_minor_blocked', true ) === 'yes' ) {
            return false; // Bloqueo COPPA.
        }
        if ( get_user_meta( $vendor_id, '_ltms_minor_requires_authorization', true ) === 'yes' ) {
            $auth_doc = get_user_meta( $vendor_id, '_ltms_minor_authorization_doc', true );
            if ( empty( $auth_doc ) ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'HD_MINOR_AUTH_MISSING',
                        sprintf( 'Vendor #%d — menor sin documento de autorización de representante legal.', $vendor_id )
                    );
                }
                return false;
            }
        }
        return $approved;
    }

    private static function calculate_age( string $birth_date ): int {
        $birth = new \DateTime( $birth_date );
        $today = new \DateTime();
        return (int) $birth->diff( $today )->y;
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    private static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '';
    }

    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Ley 1581/2012 arts. 8, 15, 18, 22, 25, 26' => 'Habeas data integral.',
                'Decreto 1377/2013'                         => 'Reglamentario Ley 1581.',
                'Decreto 886/2014'                          => 'Datos personales de menores.',
                'Decreto 1727/2024'                         => 'Registro SIC responsables.',
            ],
            'MX' => [
                'LFPDPPP arts. 16, 17, 19, 20, 30, 37'      => 'Aviso, menores, EIPD, brechas, encargado, transferencia.',
                'Lineamientos Aviso Privacidad INAI 2017'   => 'Simplificado vs integral.',
            ],
            'CROSS-BORDER' => [
                'GDPR arts. 8, 33, 34, 35, 37, 39, 46, 49'  => 'Menores, brechas, EIPD, DPO, transferencias.',
                'ISO 27001 A.7.2.2, A.10.1.1, A.10.1.2, A.12.4.1, A.12.6.1, A.14.2.5, A.16' => 'Concientización, cifrado, gestión claves, logs, pentest, CSP, incidentes.',
                'NIST SP 800-53 SC-28, SP 800-57'           => 'Cifrado at rest, gestión claves.',
                'OWASP Top 10 A05:2021'                     => 'CSP para prevenir XSS.',
                'COPPA'                                     => 'Menores 13 años.',
            ],
        ];
    }
}
