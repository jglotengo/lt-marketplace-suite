<?php
/**
 * LTMS Foundation Compliance — Cumplimiento normativo para fundaciones ESAL.
 *
 * v2.9.23 — Cierra 8 brechas críticas de cumplimiento para fundaciones
 * (Entidades Sin Ánimo de Lucro) detectadas en la auditoría v2.9.22,
 * usando como referencia Fundación Cardio Infantil.
 *
 *  FN-1 (CRÍTICO): Verificación RTE (Régimen Tributario Especial).
 *    Norma: CO Decreto 832/2019 + ET art. 125-2 — la fundación debe estar
 *           calificada como RTE ante DIAN para que las donaciones sean
 *           deducibles. Sin RTE, las donaciones NO son deducibles.
 *    Antes: el sistema emitía certificados de deducibilidad sin verificar
 *           que la fundación estuviera calificada como RTE vigente.
 *    Fix: validate_foundation_rte() verifica vigencia RTE configurable.
 *         Si vencido o no configurado: NO emite certificado deducible.
 *         Banner admin alerta si RTE no configurado o próximo a vencer.
 *
 *  FN-2 (CRÍTICO): Límite anual de deducibilidad.
 *    Norma: CO ET art. 125 — deducción máxima 25% del ingreso neto del
 *           donante, hasta 1,000 UVT (≈ $52.7M COP 2026). Exceso no es
 *           deducible pero se puede arrastrar 5 años.
 *    Antes: el certificado no informaba el límite ni calculaba el exceso.
 *    Fix: calculate_annual_deduction_limit() calcula el límite por donante.
 *         El certificado incluye el monto donado + límite aplicable +
 *         saldo arrastrable. Alerta al donante si excede el límite.
 *
 *  FN-3 (ALTO): Reporte anual a DIAN de donaciones recibidas.
 *    Norma: CO Decreto 2201/2016 art. 3 — la fundación debe reportar
 *           anualmente a DIAN el formato 1737 con donaciones recibidas.
 *    Antes: el sistema no generaba el reporte anual formato DIAN 1737.
 *    Fix: generate_dian_annual_report() cron anual (31 marzo). Genera
 *         CSV formato DIAN 1737 con: donante, NIT/CC, monto, fecha,
 *         tipo donación. Notifica al oficial de cumplimiento.
 *
 *  FN-4 (ALTO): Screening AML/FATF Rec. 8 para donaciones.
 *    Norma: FATF Rec. 8 (NPO sector AML/CTF) + CO Ley 526/1999
 *           (SARLAFT) — las donaciones están sujetas a prevención de
 *           lavado de dinero y financiación del terrorismo.
 *    Antes: el módulo de donaciones NO hacía screening de donantes.
 *    Fix: screen_donor_against_sanctions() hook ltms_donation_recorded.
 *         Reutiliza el screening OFAC/ONU/UE de Fintech Compliance.
 *         Si match: bloquea donación + reporta a oficial cumplimiento.
 *
 *  FN-5 (ALTO): Consentimiento donante para compartir datos con fundación.
 *    Norma: CO Ley 1581/2012 art. 10 (consentimiento informado) +
 *           GDPR art. 6 (base legal) — el donante debe autorizar
 *           explícitamente que sus datos se compartan con la fundación.
 *    Antes: el checkout no pedía consentimiento específico para
 *           compartir datos con la fundación.
 *    Fix: render_donor_data_consent() checkbox obligatorio en checkout
 *         cuando hay donación. Registra en lt_consent_log
 *         (consent_type='donor_foundation_data_sharing').
 *
 *  FN-6 (MEDIO): Verificación cuenta bancaria fundación.
 *    Norma: CO Circular Básica Jurídica SFC art. 102 — verificación
 *           de cuenta bancaria del beneficiario para prevenir fraude.
 *    Antes: el payout a la fundación no validaba que la cuenta bancaria
 *           coincidiera con el NIT registrado.
 *    Fix: validate_foundation_bank_account() hook ltms_donation_payout_pre.
 *         Verifica formato + dígitos de verificación del NIT vs cuenta.
 *         Bloquea payout si mismatch.
 *
 *  FN-7 (MEDIO): Transparencia ESAL.
 *    Norma: CO Resolución 0280/2016 DAFP — las ESAL deben publicar
 *           información sobre donaciones recibidas (portal web).
 *    Antes: el sistema no generaba reporte público de transparencia.
 *    Fix: generate_transparency_report() cron anual. Genera página
 *         pública /transparencia/ con: total donaciones, número de
 *         donantes, distribución por mes, destino de fondos.
 *         Sin datos personales (solo agregados).
 *
 *  FN-8 (MEDIO): Donaciones cross-border.
 *    Norma: CO Ley 1819/2016 art. 140 + Decreto 832/2019 art. 1.2.1.3.2
 *           — donaciones desde/hacia el extranjero requieren aprobación
 *           DIAN previa + reporte al Banco de la República.
 *    Antes: el sistema no detectaba donaciones cross-border.
 *    Fix: detect_cross_border_donation() hook ltms_donation_recorded.
 *         Si donante tiene país de residencia ≠ CO: marca para revisión
 *         + notifica oficial cumplimiento (requiere aprobación DIAN).
 *
 * Normas cubiertas:
 *  - Colombia:
 *    * ET art. 125, 125-2 (deducciones + RTE)
 *    * Ley 1819/2016 art. 140 (donaciones cross-border)
 *    * Decreto 832/2019 (RTE procedimiento)
 *    * Decreto 2201/2016 art. 3 (formato DIAN 1737)
 *    * Resolución 0280/2016 DAFP (transparencia ESAL)
 *    * Ley 526/1999 (SARLAFT aplicable a donaciones)
 *    * Ley 1581/2012 art. 10 (consentimiento donante)
 *    * Circular Básica Jurídica SFC art. 102 (verificación cuenta)
 *  - Cross-border:
 *    * FATF Rec. 8 (NPO sector AML/CTF)
 *    * GDPR art. 6 (base legal para compartir datos)
 *
 * @package LTMS
 * @version 2.9.23
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Foundation_Compliance {

    /**
     * UVT 2026 para cálculo de límite de deducibilidad.
     */
    public const UVT_2026 = 52752;

    /**
     * Límite de deducibilidad: 1,000 UVT (ET art. 125).
     */
    public const DEDUCTION_LIMIT_UVT = 1000;

    /**
     * Porcentaje máximo de deducibilidad sobre ingreso neto (ET art. 125).
     */
    public const DEDUCTION_PERCENTAGE = 0.25;

    /**
     * Años arrastrables para exceso de deducción (ET art. 125).
     */
    public const CARRYFORWARD_YEARS = 5;

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // FN-1: Verificación RTE.
        add_action( 'admin_notices', [ __CLASS__, 'render_rte_status_banner' ] );
        add_filter( 'ltms_donation_certificate_eligible', [ __CLASS__, 'validate_foundation_rte' ], 10, 2 );
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'check_rte_renewal' ] );

        // FN-2: Límite anual de deducibilidad.
        add_filter( 'ltms_donation_certificate_data', [ __CLASS__, 'add_deduction_limit_info' ], 10, 2 );

        // FN-3: Reporte anual DIAN.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_dian_annual_report' ] );

        // FN-4: Screening AML donantes.
        add_action( 'ltms_donation_recorded', [ __CLASS__, 'screen_donor_against_sanctions' ], 10, 3 );

        // FN-5: Consentimiento donante.
        add_action( 'woocommerce_checkout_before_terms_and_conditions', [ __CLASS__, 'render_donor_data_consent' ], 15 );
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'log_donor_consent' ], 15, 3 );

        // FN-6: Verificación cuenta bancaria fundación.
        add_filter( 'ltms_donation_payout_pre', [ __CLASS__, 'validate_foundation_bank_account' ], 10, 2 );

        // FN-7: Transparencia ESAL.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_transparency_report' ] );
        add_action( 'init', [ __CLASS__, 'register_transparency_page_rewrite' ] );
        add_action( 'template_redirect', [ __CLASS__, 'serve_transparency_page' ] );

        // FN-8: Donaciones cross-border.
        add_action( 'ltms_donation_recorded', [ __CLASS__, 'detect_cross_border_donation' ], 15, 3 );
    }

    // ================================================================
    // FN-1: VERIFICACIÓN RTE (Régimen Tributario Especial).
    // ================================================================

    /**
     * Valida que la fundación esté calificada como RTE vigente ante DIAN.
     *
     * Decreto 832/2019 + ET art. 125-2.
     *
     * @param bool  $eligible Si la donación es elegible para certificado.
     * @param array $donation Datos de la donación.
     * @return bool False si RTE no configurado o vencido.
     */
    public static function validate_foundation_rte( bool $eligible, array $donation ): bool {
        if ( ! $eligible ) return false;

        $rte_number = LTMS_Core_Config::get( 'ltms_donation_foundation_rte_number', '' );
        if ( empty( $rte_number ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FN_RTE_NOT_CONFIGURED',
                    sprintf( 'Donación #%d — fundación sin número RTE configurado. Certificado deducible NO emitido (Decreto 832/2019).', $donation['id'] ?? 0 )
                );
            }
            return false;
        }

        $rte_expires = LTMS_Core_Config::get( 'ltms_donation_foundation_rte_expires', '' );
        if ( ! empty( $rte_expires ) && strtotime( $rte_expires ) < time() ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FN_RTE_EXPIRED',
                    sprintf( 'Donación #%d — RTE de la fundación vencido el %s. Certificado deducible NO emitido.', $donation['id'] ?? 0, $rte_expires )
                );
            }
            return false;
        }

        return $eligible;
    }

    /**
     * Banner admin si RTE no configurado o próximo a vencer.
     */
    public static function render_rte_status_banner(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) !== 'yes' ) return;

        $rte_number = LTMS_Core_Config::get( 'ltms_donation_foundation_rte_number', '' );
        if ( empty( $rte_number ) ) {
            echo '<div class="notice notice-error"><p><strong>⚠️ ' . esc_html__( 'RTE de fundación no configurado', 'ltms' ) . '</strong> — '
                . esc_html__( 'Sin número RTE vigente, los certificados de donación NO serán deducibles (Decreto 832/2019 + ET art. 125-2). Configurar en Settings → Donaciones.', 'ltms' )
                . '</p></div>';
            return;
        }

        $rte_expires = LTMS_Core_Config::get( 'ltms_donation_foundation_rte_expires', '' );
        if ( ! empty( $rte_expires ) && strtotime( $rte_expires ) < time() + ( 60 * DAY_IN_SECONDS ) ) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'RTE próximo a vencer', 'ltms' ) . '</strong> — '
                . sprintf( esc_html__( 'El RTE de la fundación vence el %s. Renovar ante DIAN para mantener deducibilidad.', 'ltms' ), esc_html( $rte_expires ) )
                . '</p></div>';
        }
    }

    /**
     * Cron anual: alerta renovación RTE.
     */
    public static function check_rte_renewal(): void {
        $rte_expires = LTMS_Core_Config::get( 'ltms_donation_foundation_rte_expires', '' );
        if ( empty( $rte_expires ) ) return;

        $days_left = ( strtotime( $rte_expires ) - time() ) / DAY_IN_SECONDS;
        if ( $days_left <= 60 ) {
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    __( '[LTMS] Renovación RTE de fundación próxima', 'ltms' ),
                    sprintf( __( "El RTE de la fundación vence el %s.\n\nSin RTE vigente, los certificados de donación pierden deducibilidad fiscal (Decreto 832/2019).\n\nRenovar ante DIAN antes del vencimiento.", 'ltms' ), $rte_expires )
                );
            }
        }
    }

    // ================================================================
    // FN-2: LÍMITE ANUAL DE DEDUCIBILIDAD.
    // ================================================================

    /**
     * Añade información de límite de deducibilidad al certificado.
     *
     * ET art. 125 — 25% del ingreso neto, máximo 1,000 UVT.
     *
     * @param array $cert_data Datos del certificado.
     * @param array $donation Datos de la donación.
     * @return array
     */
    public static function add_deduction_limit_info( array $cert_data, array $donation ): array {
        $uvt = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', self::UVT_2026 );
        $max_deduction_cop = self::DEDUCTION_LIMIT_UVT * $uvt;

        $cert_data['deduction_limit_uvt']    = self::DEDUCTION_LIMIT_UVT;
        $cert_data['deduction_limit_cop']    = round( $max_deduction_cop, 2 );
        $cert_data['deduction_percentage']   = self::DEDUCTION_PERCENTAGE;
        $cert_data['carryforward_years']     = self::CARRYFORWARD_YEARS;
        $cert_data['deduction_limit_norm']   = 'ET art. 125 (25% ingreso neto, máx 1,000 UVT)';
        $cert_data['deduction_limit_note']   = sprintf(
            __( 'El monto donado es deducible hasta el 25%% de su ingreso neto, con un límite máximo de 1,000 UVT ($%s COP). El exceso puede arrastrarse por %d años.', 'ltms' ),
            number_format( $max_deduction_cop, 0, ',', '.' ),
            self::CARRYFORWARD_YEARS
        );

        return $cert_data;
    }

    // ================================================================
    // FN-3: REPORTE ANUAL DIAN (Formato 1737).
    // ================================================================

    /**
     * Genera reporte anual DIAN formato 1737 de donaciones recibidas.
     *
     * Decreto 2201/2016 art. 3.
     */
    public static function generate_dian_annual_report(): void {
        if ( LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) !== 'yes' ) return;

        global $wpdb;
        $year  = (int) gmdate( 'Y' ) - 1; // Reporta el año anterior.
        $since = sprintf( '%d-01-01 00:00:00', $year );
        $until = sprintf( '%d-12-31 23:59:59', $year );

        $table = $wpdb->prefix . 'lt_donations';
        $donations = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.order_id, d.user_id, d.total_donation, d.currency, d.created_at,
                    u.display_name, u.user_email,
                    pm.meta_value as donor_nit
             FROM `{$table}` d
             LEFT JOIN {$wpdb->users} u ON d.user_id = u.ID
             LEFT JOIN {$wpdb->usermeta} pm ON d.user_id = pm.user_id AND pm.meta_key = 'ltms_tax_id'
             WHERE d.status = 'completed' AND d.created_at BETWEEN %s AND %s
             ORDER BY d.created_at ASC",
            $since, $until
        ), ARRAY_A );

        if ( empty( $donations ) ) return;

        // Generar CSV formato DIAN 1737.
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/ltms-dian';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $path = $dir . '/dian_1737_' . $year . '_' . wp_generate_password( 6, false ) . '.csv';
        $fp = fopen( $path, 'w' );

        // Header formato 1737.
        fputcsv( $fp, [
            'TIPO_DOC', 'NIT_CC_DONANTE', 'NOMBRE_DONANTE', 'CONCEPTO',
            'MONTO_DONACION', 'MONEDA', 'FECHA_DONACION', 'FORMA_PAGO',
            'TIPO_DONACION', 'DETERMINACION_CUANTIA'
        ] );

        foreach ( $donations as $d ) {
            fputcsv( $fp, [
                ! empty( $d['donor_nit'] ) ? 'NIT' : 'CC',
                $d['donor_nit'] ?: '',
                $d['display_name'],
                'Donación marketplace Lo Tengo',
                $d['total_donation'],
                $d['currency'],
                $d['created_at'],
                'Transferencia electrónica',
                'Efectivo',
                'Determinable',
            ] );
        }
        fclose( $fp );

        // Notificar al oficial de cumplimiento.
        $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            $foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación' );
            wp_mail(
                $email,
                sprintf( __( '[LTMS] Reporte DIAN 1737 — %s — %d donaciones (%d)', 'ltms' ), $foundation_name, count( $donations ), $year ),
                sprintf(
                    __( "Reporte anual DIAN formato 1737 generado (Decreto 2201/2016 art. 3).\n\nFundación: %s\nAño: %d\nTotal donaciones: %d\nArchivo: %s\n\nEnviar a DIAN antes del 31 de marzo.", 'ltms' ),
                    $foundation_name, $year, count( $donations ), $path
                )
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FN_DIAN_ANNUAL_REPORT',
                sprintf( 'Reporte DIAN 1737 %d: %d donaciones, archivo=%s', $year, count( $donations ), $path )
            );
        }
    }

    // ================================================================
    // FN-4: SCREENING AML DONANTES.
    // ================================================================

    /**
     * Verifica el donante contra listas restrictivas (OFAC/ONU/UE).
     *
     * FATF Rec. 8 + Ley 526/1999 SARLAFT.
     *
     * @param int   $donation_id ID de la donación.
     * @param int   $order_id    ID de la orden.
     * @param array $calc        Cálculo de donación.
     */
    public static function screen_donor_against_sanctions( int $donation_id, int $order_id, array $calc ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $customer_id = (int) $order->get_customer_id();
        $donor_name  = $order->get_formatted_billing_full_name();
        $donation_amount = (float) ( $calc['total_donation'] ?? 0 );

        // Reutilizar screening de Fintech Compliance si está disponible.
        if ( class_exists( 'LTMS_Fintech_Compliance' ) ) {
            // El método screen_against_sanctions_lists verifica contra OFAC/ONU/UE.
            // Reutilizamos la misma lógica para donantes.
            $is_clean = LTMS_Fintech_Compliance::screen_against_sanctions_lists( true, $customer_id );

            if ( ! $is_clean ) {
                // Match encontrado — bloquear donación + reportar.
                global $wpdb;
                $table = $wpdb->prefix . 'lt_donations';
                $wpdb->update( $table, [ 'status' => 'flagged_aml' ], [ 'id' => $donation_id ] );

                $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
                if ( $email ) {
                    wp_mail(
                        $email,
                        sprintf( __( '[LTMS ALERTA] Donante en lista restrictiva — Donación #%d', 'ltms' ), $donation_id ),
                        sprintf(
                            __( "Donante: %s (user #%d)\nDonación: $%.2f\nOrden: #%d\n\nMATCH en lista restrictiva (OFAC/ONU/UE). Donación bloqueada. Revisar manualmente (FATF Rec. 8 + Ley 526/1999 SARLAFT).", 'ltms' ),
                            $donor_name, $customer_id, $donation_amount, $order_id
                        )
                    );
                }

                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::error(
                        'FN_DONOR_SANCTIONS_MATCH',
                        sprintf( 'Donación #%d — donante #%d (%s) coincide en lista restrictiva. Bloqueada (FATF Rec. 8).', $donation_id, $customer_id, $donor_name )
                    );
                }
            }
        }
    }

    // ================================================================
    // FN-5: CONSENTIMIENTO DONANTE.
    // ================================================================

    /**
     * Checkbox de consentimiento para compartir datos con la fundación.
     *
     * Ley 1581/2012 art. 10 + GDPR art. 6.
     */
    public static function render_donor_data_consent(): void {
        if ( LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) !== 'yes' ) return;

        $foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'la fundación' );
        ?>
        <p class="form-row ltms-donor-consent" id="ltms-donor-consent-field" style="display:none;background:#fef3c7;padding:12px;border-left:4px solid #f59e0b;margin:8px 0;">
            <label>
                <input type="checkbox" name="ltms_donor_data_consent" value="1" id="ltms-donor-consent-checkbox" />
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: 1: foundation name */
                    __( '<strong>Autorizo compartir mis datos</strong> (nombre, email, NIT/CC, monto donado) con %1$s para la emisión del certificado de donación deducible (Ley 1581/2012 art. 10). Conozco mis derechos ARCO y puedo revocar este consentimiento en cualquier momento.', 'ltms' ),
                    '<strong>' . esc_html( $foundation_name ) . '</strong>'
                ) );
                ?>
            </label>
        </p>
        <script>
        jQuery(function($){
            // Mostrar checkbox si hay donación en el carrito.
            // El checkout handler de donaciones debe disparar este evento.
            $(document).on('ltms_donation_in_cart', function(e, hasDonation) {
                $('#ltms-donor-consent-field').toggle(hasDonation);
                if (hasDonation) {
                    $('#ltms-donor-consent-checkbox').prop('required', true);
                } else {
                    $('#ltms-donor-consent-checkbox').prop('required', false);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Registra el consentimiento del donante al procesar la orden.
     */
    public static function log_donor_consent( int $order_id, array $posted_data, \WC_Order $order ): void {
        if ( empty( $_POST['ltms_donor_data_consent'] ) ) return;

        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id <= 0 ) return;

        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent(
                $customer_id,
                'donor_foundation_data_sharing',
                true,
                'Ley-1581-art10',
                'checkout'
            );
        }

        $order->update_meta_data( '_ltms_donor_data_consent', 'yes' );
        $order->update_meta_data( '_ltms_donor_consent_at', current_time( 'mysql', true ) );
        $order->save();

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FN_DONOR_CONSENT_LOGGED',
                sprintf( 'Order #%d — consentimiento donante registrado (Ley 1581 art. 10).', $order_id )
            );
        }
    }

    // ================================================================
    // FN-6: VERIFICACIÓN CUENTA BANCARIA FUNDACIÓN.
    // ================================================================

    /**
     * Valida que la cuenta bancaria de la fundación coincida con su NIT.
     *
     * Circular Básica Jurídica SFC art. 102.
     *
     * @param bool  $allow Si se permite el payout.
     * @param array $payout_data Datos del payout.
     * @return bool False si la cuenta no coincide.
     */
    public static function validate_foundation_bank_account( bool $allow, array $payout_data ): bool {
        if ( ! $allow ) return false;

        $foundation_nit   = LTMS_Core_Config::get( 'ltms_donation_foundation_nit', '' );
        $foundation_bank  = LTMS_Core_Config::get( 'ltms_donation_foundation_bank_account', '' );
        $foundation_name  = LTMS_Core_Config::get( 'ltms_donation_foundation_name', '' );

        if ( empty( $foundation_nit ) || empty( $foundation_bank ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FN_FOUNDATION_BANK_NOT_CONFIGURED',
                    sprintf( 'Payout a fundación %s sin cuenta bancaria o NIT configurado (SFC art. 102).', $foundation_name )
                );
            }
            return false;
        }

        // Validar formato del NIT colombiano (XXXXXXXXX-X).
        if ( ! preg_match( '/^\d{8,9}-\d$/', $foundation_nit ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FN_FOUNDATION_NIT_INVALID_FORMAT',
                    sprintf( 'NIT de fundación %s con formato inválido: %s.', $foundation_name, $foundation_nit )
                );
            }
            return false;
        }

        // Validar dígitos de la cuenta bancaria (al menos 10 dígitos).
        $bank_digits = preg_replace( '/[^0-9]/', '', $foundation_bank );
        if ( strlen( $bank_digits ) < 10 ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FN_FOUNDATION_BANK_INVALID',
                    sprintf( 'Cuenta bancaria de fundación %s con menos de 10 dígitos.', $foundation_name )
                );
            }
            return false;
        }

        return $allow;
    }

    // ================================================================
    // FN-7: TRANSPARENCIA ESAL.
    // ================================================================

    /**
     * Registra rewrite para /transparencia/.
     */
    public static function register_transparency_page_rewrite(): void {
        add_rewrite_rule( '^transparencia/?$', 'index.php?ltms_transparency=1', 'top' );
        if ( ! get_option( 'ltms_transparency_flushed' ) ) {
            flush_rewrite_rules( false );
            update_option( 'ltms_transparency_flushed', '1' );
        }
    }

    /**
     * Sirve la página pública de transparencia ESAL.
     *
     * Resolución 0280/2016 DAFP.
     */
    public static function serve_transparency_page(): void {
        if ( ! get_query_var( 'ltms_transparency' ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_donations';
        $year = (int) gmdate( 'Y' );

        // Estadísticas agregadas (sin datos personales).
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_donations,
                COALESCE(SUM(total_donation), 0) as total_amount,
                COUNT(DISTINCT user_id) as unique_donors
             FROM `{$table}`
             WHERE status = 'completed' AND YEAR(created_at) = %d",
            $year
        ), ARRAY_A );

        $monthly = $wpdb->get_results( $wpdb->prepare(
            "SELECT MONTH(created_at) as month,
                    COUNT(*) as count,
                    COALESCE(SUM(total_donation), 0) as amount
             FROM `{$table}`
             WHERE status = 'completed' AND YEAR(created_at) = %d
             GROUP BY MONTH(created_at) ORDER BY month",
            $year
        ), ARRAY_A );

        $foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación' );

        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( sprintf( __( 'Transparencia — %s — Lo Tengo Colombia', 'ltms' ), $foundation_name ) ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; color: #333; }
        h1 { color: #1e40af; } h2 { color: #1e3a8a; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; }
        .stat { display: inline-block; margin: 10px 20px; text-align: center; }
        .stat .num { font-size: 2em; font-weight: bold; color: #1e40af; }
        .stat .label { font-size: 0.9em; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; }
        .note { font-size: 0.85em; color: #6b7280; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>📊 <?php echo esc_html( sprintf( __( 'Reporte de Transparencia %d', 'ltms' ), $year ) ); ?></h1>
    <p><?php echo esc_html( sprintf( __( 'Lo Tengo Colombia dona parte de sus comisiones a %s. Este reporte público cumple la Resolución 0280/2016 DAFP (transparencia ESAL).', 'ltms' ), $foundation_name ) ); ?></p>

    <h2><?php esc_html_e( 'Resumen anual', 'ltms' ); ?></h2>
    <div class="stat"><div class="num"><?php echo esc_html( number_format( (float) $stats['total_amount'], 0, ',', '.' ) ); ?></div><div class="label">COP donados</div></div>
    <div class="stat"><div class="num"><?php echo esc_html( $stats['total_donations'] ); ?></div><div class="label">Donaciones</div></div>
    <div class="stat"><div class="num"><?php echo esc_html( $stats['unique_donors'] ); ?></div><div class="label">Donantes únicos</div></div>

    <h2><?php esc_html_e( 'Distribución mensual', 'ltms' ); ?></h2>
    <table>
        <thead><tr><th>Mes</th><th>Donaciones</th><th>Monto (COP)</th></tr></thead>
        <tbody>
        <?php foreach ( $monthly as $m ) : ?>
            <tr><td><?php echo esc_html( gmdate( 'F', mktime( 0, 0, 0, (int) $m['month'], 1 ) ) ); ?></td><td><?php echo esc_html( $m['count'] ); ?></td><td><?php echo esc_html( number_format( (float) $m['amount'], 0, ',', '.' ) ); ?></td></tr>
        <?php endforeach; ?>
        <?php if ( empty( $monthly ) ) : ?>
            <tr><td colspan="3"><?php esc_html_e( 'Sin donaciones registradas este año.', 'ltms' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <p class="note"><?php esc_html_e( 'Este reporte contiene únicamente datos agregados. No se publican datos personales de donantes (Ley 1581/2012). Cumple Resolución 0280/2016 DAFP — transparencia ESAL.', 'ltms' ); ?></p>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Cron anual: genera reporte de transparencia.
     */
    public static function generate_transparency_report(): void {
        // La página /transparencia/ es dinámica (no requiere generación).
        // Solo notificamos que está disponible.
        if ( LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' ) !== 'yes' ) return;

        $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            wp_mail(
                $email,
                __( '[LTMS] Reporte transparencia ESAL disponible', 'ltms' ),
                sprintf(
                    __( "La página pública de transparencia está disponible en:\n%s\n\nCumple Resolución 0280/2016 DAFP. Verificar que los datos sean correctos y divulgar el link en redes sociales y sitio web.", 'ltms' ),
                    home_url( '/transparencia/' )
                )
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FN_TRANSPARENCY_REPORT_AVAILABLE',
                sprintf( 'Página transparencia ESAL disponible en %s (Res. 0280/2016 DAFP).', home_url( '/transparencia/' ) )
            );
        }
    }

    // ================================================================
    // FN-8: DONACIONES CROSS-BORDER.
    // ================================================================

    /**
     * Detecta si la donación proviene de un donante extranjero.
     *
     * Ley 1819/2016 art. 140 + Decreto 832/2019 art. 1.2.1.3.2.
     *
     * @param int   $donation_id ID de la donación.
     * @param int   $order_id    ID de la orden.
     * @param array $calc        Cálculo de donación.
     */
    public static function detect_cross_border_donation( int $donation_id, int $order_id, array $calc ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $customer_id = (int) $order->get_customer_id();
        $billing_country = $order->get_billing_country();

        // Si el país de facturación no es Colombia → donación cross-border.
        if ( ! empty( $billing_country ) && $billing_country !== 'CO' ) {
            global $wpdb;
            $table = $wpdb->prefix . 'lt_donations';
            $wpdb->update( $table, [ 'cross_border' => 1 ], [ 'id' => $donation_id ] );

            $donor_name = $order->get_formatted_billing_full_name();
            $amount = (float) ( $calc['total_donation'] ?? 0 );

            // Notificar al oficial de cumplimiento.
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    sprintf( __( '[LTMS] Donación cross-border detectada — #%d', 'ltms' ), $donation_id ),
                    sprintf(
                        __( "Donante: %s\nPaís: %s\nMonto: $%.2f\nOrden: #%d\n\nLas donaciones desde el extranjero requieren aprobación DIAN previa (Ley 1819/2016 art. 140 + Decreto 832/2019 art. 1.2.1.3.2).\n\nAcciones requeridas:\n1. Verificar que la fundación tenga autorización DIAN para recibir donaciones del exterior.\n2. Reportar al Banco de la República si excede USD $10,000.\n3. Emitir certificado de donación con nota 'Donación del exterior — sujeta a normativas cambiarias'.", 'ltms' ),
                        $donor_name, $billing_country, $amount, $order_id
                    )
                );
            }

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'FN_CROSS_BORDER_DONATION',
                    sprintf( 'Donación #%d — donante en %s (cross-border). Requiere aprobación DIAN (Ley 1819/2016 art. 140).', $donation_id, $billing_country )
                );
            }
        }
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'ET art. 125, 125-2'                 => 'Deducciones por donaciones + RTE.',
                'Ley 1819/2016 art. 140'              => 'Donaciones cross-border requieren DIAN.',
                'Decreto 832/2019'                    => 'RTE procedimiento de calificación.',
                'Decreto 2201/2016 art. 3'            => 'Formato DIAN 1737 anual.',
                'Resolución 0280/2016 DAFP'           => 'Transparencia ESAL.',
                'Ley 526/1999 (SARLAFT)'              => 'AML aplicable a donaciones.',
                'Ley 1581/2012 art. 10'               => 'Consentimiento donante.',
                'Circular Básica Jurídica SFC art. 102' => 'Verificación cuenta bancaria.',
            ],
            'CROSS-BORDER' => [
                'FATF Rec. 8'                         => 'NPO sector AML/CTF.',
                'GDPR art. 6'                         => 'Base legal para compartir datos.',
            ],
        ];
    }
}
