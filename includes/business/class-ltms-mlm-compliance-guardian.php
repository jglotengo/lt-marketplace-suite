<?php
/**
 * LTMS MLM Compliance Guardian — Cumplimiento multinivel/afiliados.
 *
 * Cierra 5 brechas críticas de cumplimiento normativo:
 *
 *  NA-1: Disclaimer "PUEDEN SER CERO" — Ley 1700/2013 art. 8 (CO) + FTC (MX).
 *  NA-2: Módulo anti-pirámide con risk_score + auto-freeze ≥ 70 — Ley 1700/2013 art. 10.
 *  NA-3: Consentimiento explícito de marketing al registrarse — Ley 1581 art. 9.
 *  NA-4: Verificación de "no compra obligatoria para unirse" — Ley 1700/2013 art. 7.
 *  NA-5: Reporte anual de ingresos MLM por participante — ISR art. 113-A / Estatuto Tributario.
 *
 * @package LTMS
 * @version 2.9.10
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_MLM_Compliance_Guardian {

    public static function init(): void {
        // NA-1: Disclaimer en registro de afiliados.
        add_action( 'woocommerce_register_form_end', [ __CLASS__, 'render_mlm_disclaimer' ] );
        add_action( 'ltms_vendor_dashboard_affiliates_top', [ __CLASS__, 'render_mlm_disclaimer' ] );
        add_filter( 'ltms_contract_pdf_extra_clauses', [ __CLASS__, 'add_disclaimer_to_contract' ] );

        // NA-2: Anti-pirámide — cron diario de risk scoring.
        add_action( 'ltms_daily_cron', [ __CLASS__, 'run_anti_pyramid_scan' ] );
        add_action( 'wp_ajax_ltms_view_pyramid_alerts', [ __CLASS__, 'ajax_view_alerts' ] );

        // NA-3: Consentimiento de marketing en registro.
        add_action( 'woocommerce_register_form', [ __CLASS__, 'render_marketing_consent_field' ] );
        add_action( 'woocommerce_created_customer', [ __CLASS__, 'save_marketing_consent' ], 10, 2 );

        // NA-4: Verificación de no compra obligatoria.
        add_action( 'ltms_mlm_before_activate', [ __CLASS__, 'verify_no_mandatory_purchase' ], 10, 2 );

        // NA-5: Reporte anual de ingresos MLM.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_annual_mlm_reports' ] );
        add_action( 'wp_ajax_ltms_generate_mlm_report', [ __CLASS__, 'ajax_generate_report' ] );
    }

    // ================================================================
    // NA-1: DISCLAIMER "PUEDEN SER CERO"
    // ================================================================

    /**
     * Renderiza el disclaimer obligatorio de MLM.
     *
     * Ley 1700/2013 art. 8 (Colombia): "La información sobre posibles
     * ingresos debe incluir declaración de que los ingresos pueden ser cero."
     * FTC (México/US): "Income disclosure must be truthful and not misleading."
     */
    public static function render_mlm_disclaimer(): void {
        $country = LTMS_Core_Config::get_country();
        $disclaimer = $country === 'MX'
            ? __( 'IMPORTANTE: Las bonificaciones del programa de afiliados NO están garantizadas. Los ingresos dependen exclusivamente de las ventas reales generadas por tu red de referidos. Las comisiones PUEDEN SER CERO si no hay actividad comercial. Este no es un esquema de enriquecimiento rápido. No se requiere compra inicial para participar. (FTC — Federal Trade Commission / PROFECO)', 'ltms' )
            : __( 'IMPORTANTE: Las bonificaciones del programa de afiliados NO están garantizadas. Los ingresos dependen exclusivamente de las ventas reales generadas por tu red de referidos. Las comisiones PUEDEN SER CERO si no hay actividad comercial. Este no es un esquema de enriquecimiento rápido. No se requiere compra inicial para participar. (Ley 1700/2013 art. 8 — Estatuto del Consumidor)', 'ltms' );
        ?>
        <div class="ltms-mlm-disclaimer" style="margin:16px 0;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;line-height:1.5;">
            <strong>&#x26A0;</strong> <?php echo esc_html( $disclaimer ); ?>
        </div>
        <?php
    }

    /**
     * Añade el disclaimer al contrato PDF.
     */
    public static function add_disclaimer_to_contract( string $extra ): string {
        $extra .= '<div class="clause-title">Cláusula Adicional — Disclaimer Programa de Afiliados (Ley 1700/2013 art. 8)</div>';
        $extra .= '<p class="clause-body"><strong>DISCLAIMER DE INGRESOS:</strong> El VENDEDOR reconoce y acepta que las bonificaciones del programa de afiliados / red MLM / TPTC <strong>NO están garantizadas</strong> y <strong>PUEDEN SER CERO</strong>. Los ingresos dependen exclusivamente de las ventas reales generadas por la red de referidos del VENDEDOR. Este programa no constituye un esquema de enriquecimiento rápido ni una promesa de ingresos. No se requiere compra inicial, inventario ni pago de membresía para participar en el programa de afiliados. La participación es voluntaria y puede cancelarse en cualquier momento.</p>';
        return $extra;
    }

    // ================================================================
    // NA-2: MÓDULO ANTI-PIRÁMIDE CON RISK_SCORE
    // ================================================================

    /**
     * Ejecuta el escaneo anti-pirámide diario.
     *
     * Indicadores de riesgo (Ley 1700/2013 art. 10):
     *  1. Ratio ingresos por reclutamiento vs venta real (> 70% = alto riesgo).
     *  2. Niveles sin ventas reales (> 2 niveles vacíos = riesgo).
     *  3. Concentración de ingresos en top 1% (desigualdad extrema).
     *  4. Crecimiento exponencial sin ventas (solo reclutamiento).
     *  5. Vendor con 0 ventas propias pero comisiones MLM altas.
     *
     * Risk score: 0-100. Si ≥ 70 → auto-freeze + alerta.
     */
    public static function run_anti_pyramid_scan(): void {
        if ( LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) !== 'yes' ) return;
        if ( LTMS_Core_Config::get( 'ltms_anti_pyramid_enabled', 'yes' ) !== 'yes' ) return;

        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        $tx_table  = $wpdb->prefix . 'lt_wallet_transactions';
        $alerts_table = $wpdb->prefix . 'lt_anti_pyramid_alerts';

        // Crear tabla si no existe (idempotente).
        self::ensure_alerts_table();

        // Últimos 30 días.
        $since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

        // Obtener todos los vendors en la red MLM.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $members = $wpdb->get_results(
            "SELECT vendor_id, sponsor_id, ancestor_path, level FROM `{$ref_table}` ORDER BY level ASC",
            ARRAY_A
        );

        if ( empty( $members ) ) return;

        $risk_weights = [
            'recruitment_ratio'   => (float) LTMS_Core_Config::get( 'ltms_pyramid_weight_recruitment', 35 ),
            'empty_levels'        => (float) LTMS_Core_Config::get( 'ltms_pyramid_weight_empty_levels', 20 ),
            'income_concentration' => (float) LTMS_Core_Config::get( 'ltms_pyramid_weight_concentration', 20 ),
            'no_own_sales'        => (float) LTMS_Core_Config::get( 'ltms_pyramid_weight_no_own_sales', 15 ),
            'rapid_growth'        => (float) LTMS_Core_Config::get( 'ltms_pyramid_weight_rapid_growth', 10 ),
        ];

        $new_alerts = 0;

        foreach ( $members as $m ) {
            $vendor_id = (int) $m['vendor_id'];
            $risk_score = 0;
            $risk_factors = [];

            // 1. Ratio reclutamiento vs venta real.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $mlm_income = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
                 WHERE vendor_id = %d AND type = 'credit' AND created_at >= %s
                 AND metadata LIKE '%\"type\":\"referral\"%'",
                $vendor_id, $since
            ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $own_sales = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
                 WHERE vendor_id = %d AND type IN ('credit','hold') AND created_at >= %s
                 AND (metadata LIKE '%\"type\":\"commission\"%' OR metadata LIKE '%\"type\":\"commission_held\"%')",
                $vendor_id, $since
            ) );

            $total_income = $mlm_income + $own_sales;
            if ( $total_income > 0 ) {
                $recruitment_ratio = $mlm_income / $total_income;
                if ( $recruitment_ratio > 0.70 ) {
                    $risk_score += $risk_weights['recruitment_ratio'];
                    $risk_factors[] = sprintf( 'recruitment_ratio=%.0f%% (>70%%)', $recruitment_ratio * 100 );
                }
            }

            // 5. Vendor con 0 ventas propias pero comisiones MLM.
            if ( $own_sales == 0 && $mlm_income > 0 ) {
                $risk_score += $risk_weights['no_own_sales'];
                $risk_factors[] = 'no_own_sales (solo ingresos MLM)';
            }

            // 2. Niveles sin ventas reales en la descendencia.
            $descendants = self::count_descendants_with_sales( $vendor_id, $since );
            $total_descendants = self::count_descendants( $vendor_id );
            if ( $total_descendants > 5 && $descendants < ( $total_descendants * 0.3 ) ) {
                $risk_score += $risk_weights['empty_levels'];
                $risk_factors[] = sprintf( 'empty_levels: %d/%d descendientes sin ventas', $total_descendants - $descendants, $total_descendants );
            }

            // 3. Concentración de ingresos (top vs bottom).
            if ( $total_income > 100000 ) {
                $concentration = self::calculate_income_concentration( $vendor_id, $since );
                if ( $concentration > 0.80 ) {
                    $risk_score += $risk_weights['income_concentration'];
                    $risk_factors[] = sprintf( 'income_concentration=%.0f%% (>80%%)', $concentration * 100 );
                }
            }

            // 4. Crecimiento rápido sin ventas (más de 10 referidos en 7 días).
            $recent_referrals = self::count_recent_referrals( $vendor_id, 7 );
            if ( $recent_referrals > 10 && $own_sales == 0 ) {
                $risk_score += $risk_weights['rapid_growth'];
                $risk_factors[] = sprintf( 'rapid_growth: %d referidos en 7 días sin ventas', $recent_referrals );
            }

            $risk_score = min( 100, $risk_score );

            // Si risk_score ≥ 70 → alerta + auto-freeze.
            if ( $risk_score >= 70 ) {
                $freeze_threshold = (float) LTMS_Core_Config::get( 'ltms_pyramid_freeze_threshold', 70 );
                $should_freeze = LTMS_Core_Config::get( 'ltms_pyramid_auto_freeze', 'yes' ) === 'yes';

                // Insertar alerta.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert( $alerts_table, [
                    'vendor_id'     => $vendor_id,
                    'risk_score'    => $risk_score,
                    'risk_factors'  => wp_json_encode( $risk_factors ),
                    'mlm_income'    => $mlm_income,
                    'own_sales'     => $own_sales,
                    'descendants'   => $total_descendants,
                    'status'        => $should_freeze ? 'frozen' : 'alert',
                    'created_at'    => current_time( 'mysql', true ),
                ] );
                $new_alerts++;

                if ( $should_freeze ) {
                    self::freeze_vendor_mlm( $vendor_id, $risk_score, $risk_factors );
                }

                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'PYRAMID_RISK_DETECTED',
                        sprintf( 'Vendor #%d risk_score=%d (≥70). Factores: %s. Auto-freeze: %s',
                            $vendor_id, $risk_score, implode( '; ', $risk_factors ), $should_freeze ? 'SÍ' : 'NO' ),
                        [ 'vendor_id' => $vendor_id, 'risk_score' => $risk_score, 'factors' => $risk_factors ]
                    );
                }
            }
        }

        if ( $new_alerts > 0 && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'PYRAMID_SCAN_COMPLETE',
                sprintf( 'Escaneo anti-pirámide: %d alertas nuevas de %d miembros evaluados.', $new_alerts, count( $members ) )
            );
        }
    }

    /**
     * Congela el MLM de un vendor (no su wallet completa, solo MLM).
     */
    private static function freeze_vendor_mlm( int $vendor_id, float $risk_score, array $factors ): void {
        update_user_meta( $vendor_id, '_ltms_mlm_frozen', 1 );
        update_user_meta( $vendor_id, '_ltms_mlm_frozen_reason', sprintf(
            'Anti-pirámide: risk_score=%.0f. Factores: %s',
            $risk_score, implode( '; ', $factors )
        ) );
        update_user_meta( $vendor_id, '_ltms_mlm_frozen_at', current_time( 'mysql', true ) );

        // Notificar al admin.
        $admin_email = get_option( 'admin_email' );
        if ( $admin_email ) {
            wp_mail(
                $admin_email,
                sprintf( '[Lo Tengo] ALERTA Anti-pirámide — Vendor #%d (risk=%d)', $vendor_id, $risk_score ),
                sprintf(
                    "El sistema anti-pirámide ha detectado actividad sospechosa.\n\nVendor #%d\nRisk Score: %d/100\nFactores: %s\n\nEl MLM del vendor ha sido congelado. Revisar manualmente.\n\nPanel: %s",
                    $vendor_id, $risk_score, implode( "\n", $factors ),
                    admin_url( 'admin.php?page=ltms-dashboard' )
                )
            );
        }
    }

    private static function count_descendants( int $vendor_id ): int {
        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$ref_table}` WHERE CONCAT('/', ancestor_path, '/') LIKE %s",
            '%/' . $vendor_id . '/%'
        ) );
    }

    private static function count_descendants_with_sales( int $vendor_id, string $since ): int {
        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        $tx_table  = $wpdb->prefix . 'lt_wallet_transactions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT r.vendor_id) FROM `{$ref_table}` r
             INNER JOIN `{$tx_table}` t ON t.vendor_id = r.vendor_id
             WHERE CONCAT('/', r.ancestor_path, '/') LIKE %s
             AND t.type = 'credit' AND t.created_at >= %s
             AND t.metadata LIKE '%\"type\":\"commission\"%'",
            '%/' . $vendor_id . '/%', $since
        ) );
    }

    private static function calculate_income_concentration( int $vendor_id, string $since ): float {
        // Simplificado: si el vendor gana > 80% del total de su downline → concentración.
        global $wpdb;
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';
        $ref_table = $wpdb->prefix . 'lt_referral_network';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $vendor_income = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
             WHERE vendor_id = %d AND type = 'credit' AND created_at >= %s",
            $vendor_id, $since
        ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $downline_income = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(t.amount), 0) FROM `{$tx_table}` t
             INNER JOIN `{$ref_table}` r ON r.vendor_id = t.vendor_id
             WHERE CONCAT('/', r.ancestor_path, '/') LIKE %s
             AND t.type = 'credit' AND t.created_at >= %s",
            '%/' . $vendor_id . '/%', $since
        ) );

        $total = $vendor_income + $downline_income;
        return $total > 0 ? $vendor_income / $total : 0.0;
    }

    private static function count_recent_referrals( int $vendor_id, int $days ): int {
        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$ref_table}` WHERE sponsor_id = %d AND created_at >= %s",
            $vendor_id, $since
        ) );
    }

    /**
     * Crea la tabla lt_anti_pyramid_alerts si no existe.
     */
    private static function ensure_alerts_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_anti_pyramid_alerts';
        $charset = $wpdb->get_charset_collate();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id`     BIGINT NOT NULL,
            `risk_score`    DECIMAL(5,2) NOT NULL DEFAULT 0,
            `risk_factors`  LONGTEXT DEFAULT NULL,
            `mlm_income`    DECIMAL(15,2) NOT NULL DEFAULT 0,
            `own_sales`     DECIMAL(15,2) NOT NULL DEFAULT 0,
            `descendants`   INT UNSIGNED NOT NULL DEFAULT 0,
            `status`        VARCHAR(20) NOT NULL DEFAULT 'alert',
            `resolved_by`   BIGINT UNSIGNED DEFAULT NULL,
            `resolved_at`   DATETIME DEFAULT NULL,
            `resolution`    TEXT DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vendor` (`vendor_id`),
            KEY `idx_status` (`status`),
            KEY `idx_risk` (`risk_score`),
            KEY `idx_created` (`created_at`)
        ) {$wpdb->get_charset_collate()}" );
    }

    /**
     * AJAX: ver alertas anti-pirámide.
     */
    public static function ajax_view_alerts(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lt_anti_pyramid_alerts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $alerts = $wpdb->get_results(
            "SELECT * FROM `{$table}` WHERE status IN ('alert','frozen') ORDER BY risk_score DESC, created_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [];
        wp_send_json_success( [ 'alerts' => $alerts ] );
    }

    // ================================================================
    // NA-3: CONSENTIMIENTO EXPLÍCITO DE MARKETING
    // ================================================================

    /**
     * Renderiza el checkbox de consentimiento de marketing en el registro.
     *
     * Ley 1581/2012 art. 9: consentimiento previo, expreso e informado.
     * LFPDPPP art. 8: consentimiento explícito para tratamiento de datos.
     *
     * Es OPCIONAL — no se requiere para registrarse, solo para recibir marketing.
     */
    public static function render_marketing_consent_field(): void {
        $country = LTMS_Core_Config::get_country();
        $label = $country === 'MX'
            ? __( 'Acepto recibir comunicaciones comerciales y autorizo el tratamiento de mis datos para fines publicitarios (LFPDPPP art. 8). Puedo revocar este consentimiento en cualquier momento.', 'ltms' )
            : __( 'Acepto recibir comunicaciones comerciales y autorizo el tratamiento de mis datos para fines de marketing (Ley 1581/2012 art. 9). Puedo revocar este consentimiento en cualquier momento.', 'ltms' );
        ?>
        <p class="form-row" style="margin:12px 0;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
                       name="ltms_marketing_consent" id="ltms_marketing_consent" value="yes" />
                <span style="font-size:12px;color:#4b5563;line-height:1.4;"><?php echo esc_html( $label ); ?></span>
            </label>
        </p>
        <?php
    }

    /**
     * Guarda el consentimiento de marketing al crear el usuario.
     */
    public static function save_marketing_consent( int $user_id, array $customer_data ): void {
        $consented = isset( $_POST['ltms_marketing_consent'] ) && $_POST['ltms_marketing_consent'] === 'yes';

        update_user_meta( $user_id, 'ltms_marketing_consent', $consented ? 'yes' : 'no' );
        update_user_meta( $user_id, 'ltms_marketing_consent_at', current_time( 'mysql', true ) );

        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent(
                $user_id,
                'marketing',
                $consented,
                LTMS_Legal_Compliance::PRIVACY_VERSION,
                'web_registration'
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'MARKETING_CONSENT_LOGGED',
                sprintf( 'Vendor #%d: consentimiento de marketing = %s', $user_id, $consented ? 'SÍ' : 'NO' )
            );
        }
    }

    // ================================================================
    // NA-4: VERIFICACIÓN DE NO COMPRA OBLIGATORIA
    // ================================================================

    /**
     * Verifica que no se exija compra inicial para unirse al MLM.
     *
     * Ley 1700/2013 art. 7: "Ninguna persona podrá ser obligada a adquirir
     * bienes o servicios como condición para participar en un sistema de
     * venta multinivel."
     *
     * Este hook se dispara antes de activar el MLM para un vendor.
     * Si se detecta que el vendor tuvo que comprar para unirse, se bloquea.
     */
    public static function verify_no_mandatory_purchase( int $vendor_id, string $referral_code ): bool {
        // Verificar si el vendor realizó una compra ANTES de registrarse en MLM.
        // Si la única forma de unirse fue via compra → es pirámide.
        global $wpdb;
        $mlm_register_date = get_user_meta( $vendor_id, '_ltms_mlm_registered_at', true );
        $mlm_register_ts = $mlm_register_date ? strtotime( $mlm_register_date ) : 0;

        // Buscar órdenes del vendor ANTES del registro MLM.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $orders_before_mlm = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = 'shop_order'
             AND post_date < %s",
            $vendor_id,
            $mlm_register_date ?: gmdate( 'Y-m-d H:i:s' )
        ) );

        // Si el vendor tuvo exactamente 1 orden antes del registro MLM y esa
        // orden fue la que le permitió registrarse → posible compra obligatoria.
        // Sin embargo, no podemos determinar esto con 100% certeza solo con el
        // conteo. Lo que sí hacemos es:
        // 1. Verificar que NO haya un meta _ltms_mandatory_purchase_required.
        // 2. Log de la verificación.

        $mandatory = get_user_meta( $vendor_id, '_ltms_mandatory_purchase_required', true );
        if ( $mandatory === 'yes' ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'MLM_MANDATORY_PURCHASE_BLOCKED',
                    sprintf( 'Vendor #%d tiene flag _ltms_mandatory_purchase_required=yes. MLM bloqueado (Ley 1700/2013 art. 7).', $vendor_id )
                );
            }
            return false;
        }

        // Verificar que el registro MLM sea gratuito (no haya cobro por unirse).
        $mlm_join_fee = (float) LTMS_Core_Config::get( 'ltms_mlm_join_fee', 0 );
        if ( $mlm_join_fee > 0 ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'MLM_JOIN_FEE_DETECTED',
                    sprintf( 'ltms_mlm_join_fee = %.2f (>0). Esto viola Ley 1700/2013 art. 7. Configurar a 0.', $mlm_join_fee )
                );
            }
            // Auto-corregir: forzar a 0.
            LTMS_Core_Config::set( 'ltms_mlm_join_fee', 0 );
        }

        return true; // OK: no hay compra obligatoria.
    }

    // ================================================================
    // NA-5: REPORTE ANUAL DE INGRESOS MLM
    // ================================================================

    /**
     * Genera reportes anuales de ingresos MLM para todos los participantes.
     *
     * ISR art. 113-A (México): ingresos por plataformas deben reportarse.
     * Estatuto Tributario art. 103 (Colombia): rentas de trabajo/capital.
     *
     * El reporte incluye:
     *  - Total comisiones MLM por nivel
     *  - Total referidos activos
     *  - Total ventas generadas por la red
     *  - Retenciones de ISR aplicadas
     */
    public static function generate_annual_mlm_reports( int $year = 0 ): array {
        if ( ! $year ) $year = (int) current_time( 'Y' ) - 1; // Año anterior.

        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        $tx_table  = $wpdb->prefix . 'lt_wallet_transactions';

        $date_from = sprintf( '%04d-01-01', $year );
        $date_to   = sprintf( '%04d-12-31', $year );

        // Obtener todos los miembros MLM.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $members = $wpdb->get_results(
            "SELECT vendor_id, level FROM `{$ref_table}` ORDER BY vendor_id",
            ARRAY_A
        );

        if ( empty( $members ) ) return [ 'error' => 'Sin miembros MLM' ];

        $reports = [];

        foreach ( $members as $m ) {
            $vendor_id = (int) $m['vendor_id'];

            // Total comisiones MLM recibidas en el año.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $mlm_total = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
                 WHERE vendor_id = %d AND type = 'credit' AND created_at BETWEEN %s AND %s
                 AND metadata LIKE '%\"type\":\"referral\"%'",
                $vendor_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59'
            ) );

            // Comisiones por nivel.
            $by_level = [];
            for ( $lvl = 1; $lvl <= 3; $lvl++ ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $level_amount = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
                     WHERE vendor_id = %d AND type = 'credit' AND created_at BETWEEN %s AND %s
                     AND metadata LIKE '%\"type\":\"referral\"%' AND metadata LIKE %s",
                    $vendor_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59',
                    '%level\":' . $lvl . '%'
                ) );
                $by_level[ $lvl ] = round( $level_amount, 2 );
            }

            // Retenciones ISR aplicadas.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $isr_withheld = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
                 WHERE vendor_id = %d AND type = 'tax_withholding' AND created_at BETWEEN %s AND %s
                 AND metadata LIKE '%\"type\":\"referral\"%'",
                $vendor_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59'
            ) );

            // Número de referidos activos.
            $active_referrals = self::count_descendants( $vendor_id );

            // Ventas totales generadas por la red.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $network_sales = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(t.amount), 0) FROM `{$tx_table}` t
                 INNER JOIN `{$ref_table}` r ON r.vendor_id = t.vendor_id
                 WHERE CONCAT('/', r.ancestor_path, '/') LIKE %s
                 AND t.type = 'credit' AND t.created_at BETWEEN %s AND %s
                 AND t.metadata LIKE '%\"type\":\"commission\"%'",
                '%/' . $vendor_id . '/%', $date_from . ' 00:00:00', $date_to . ' 23:59:59'
            ) );

            $report = [
                'vendor_id'        => $vendor_id,
                'year'             => $year,
                'mlm_total'        => round( $mlm_total, 2 ),
                'by_level'         => $by_level,
                'isr_withheld'     => round( $isr_withheld, 2 ),
                'net_after_tax'    => round( $mlm_total - $isr_withheld, 2 ),
                'active_referrals' => $active_referrals,
                'network_sales'    => round( $network_sales, 2 ),
                'generated_at'     => current_time( 'mysql', true ),
            ];

            // Guardar en user_meta para consulta del vendor.
            update_user_meta( $vendor_id, '_ltms_mlm_annual_report_' . $year, $report );

            $reports[] = $report;
        }

        // Guardar resumen general.
        $summary = [
            'year'           => $year,
            'total_members'  => count( $members ),
            'total_mlm_paid' => round( array_sum( array_column( $reports, 'mlm_total' ) ), 2 ),
            'total_isr'      => round( array_sum( array_column( $reports, 'isr_withheld' ) ), 2 ),
            'generated_at'   => current_time( 'mysql', true ),
        ];
        update_option( 'ltms_mlm_annual_summary_' . $year, $summary, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'MLM_ANNUAL_REPORTS_GENERATED',
                sprintf( 'Reportes anuales MLM %d: %d miembros, total pagado=$%.2f, ISR retenido=$%.2f',
                    $year, count( $members ), $summary['total_mlm_paid'], $summary['total_isr'] )
            );
        }

        return [ 'summary' => $summary, 'reports' => $reports ];
    }

    /**
     * AJAX: generar reporte anual manualmente.
     */
    public static function ajax_generate_report(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        $year = (int) ( $_POST['year'] ?? 0 );
        $result = self::generate_annual_mlm_reports( $year );
        wp_send_json_success( $result );
    }
}
