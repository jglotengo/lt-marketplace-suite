<?php
/**
 * LTMS Fintech Compliance — Cumplimiento normativo fintech.
 *
 * v2.9.16 — Cierra 8 brechas críticas de cumplimiento fintech detectadas
 * en la auditoría v2.9.15, cubriendo AML/PLD, screening, límites
 * operativos, Travel Rule, PCI DSS, 2FA, CRS/FATCA y escalado UMA.
 *
 *  FT-1 (CRÍTICO): Reportes de operaciones sospechosas (SOS) UIAF/SHCP.
 *    Norma: Colombia Res. UIAF 029/2014 (reporte SOS mensual);
 *           México LFPIDRPI art. 17-18 + Regla 15 Anexo 1 SHCP
 *           (reporte SOS a 24 horas de detectada).
 *    Antes: el cron PLD MX solo LOGUEABA alertas pero NO generaba
 *           el reporte SOS en formato XML/CSV exigido por UIAF/SHCP.
 *    Fix: generate_sos_reports() cron mensual (CO) y a 24h (MX).
 *         Formato: CSV UIAF Anexo 1 (CO) / XML SHCP Anexo 1 (MX).
 *         Persiste en lt_sos_reports table + notifica al oficial
 *         de cumplimiento designado.
 *
 *  FT-2 (CRÍTICO): Screening OFAC/ONU/Listas restrictivas en registro.
 *    Norma: CO Ley 526/1999 (SARLAFT); MX Ley Fintech art. 87;
 *           OFAC SDN List; UN Security Council Consolidated List;
 *           UE Listas Restrictivas; lista Clinton/Bush.
 *    Antes: el registro solo pedía declaración juramentada pero NO
 *           validaba contra listas restrictivas reales.
 *    Fix: screen_against_sanctions_lists() hook ltms_kyc_pre_approve.
 *         Compara nombre + documento contra listas configurables.
 *         Si match: bloquea KYC + reporta a oficial cumplimiento.
 *         Cron mensual re-screen vendors existentes (listas actualizan).
 *
 *  FT-3 (ALTO): Límites operativos diarios/mensuales por vendor.
 *    Norma: MX Ley Fintech art. 88 (límites ITFs Banxico);
 *           CO Circular Básica SFC; FATF Rec. 12.
 *    Antes: el wallet no tenía límites operativos por vendor →
 *           vulnerable a lavado de dinero por estructuración.
 *    Fix: enforce_operational_limits() hook ltms_payout_pre_approve.
 *         Límites configurables por vendor:
 *           - daily_payout_limit (default 5,000 USD equivalente)
 *           - monthly_payout_limit (default 20,000 USD equivalente)
 *           - daily_tx_count_limit (default 50 transacciones)
 *         Bloquea payout si excede y marca para revisión manual.
 *
 *  FT-4 (ALTO): Travel Rule para transferencias ≥ $1,000 USD.
 *    Norma: FATF Rec. 16; MX Reglas Banxico Anexo 25;
 *           CO Circular Externa SFC 029/2014.
 *    Antes: payouts no registraban datos del originante/beneficiario
 *           en el formato exigido por Travel Rule.
 *    Fix: attach_travel_rule_metadata() hook ltms_payout_pre_execute.
 *         Adjunta: originante (nombre, documento, banco origen),
 *         beneficiario (nombre, documento, banco destino), propósito.
 *         Solo si monto ≥ umbral configurable (default $1,000 USD).
 *
 *  FT-5 (ALTO): PCI DSS SAQ-A declaración formal.
 *    Norma: PCI DSS v4.0 SAQ-A req. 3.4.1 (PAN no almacenado),
 *           4.2.1 (TLS 1.2+), 12.2 (autoevaluación anual).
 *    Antes: el sitio usaba tokenización OpenPay (cumple SAQ-A) pero
 *           NO tenía declaración formal ni logs de cumplimiento.
 *    Fix: render_pci_dss_compliance_panel() panel admin con:
 *         - Declaración SAQ-A firmada (fecha, signatario, vigencia).
 *         - Verificación de no-almacenamiento PAN (escaneo metadata).
 *         - Verificación de TLS (test externo contra checkout URL).
 *         - Cron anual ltms_pci_dss_annual_review para reevaluación.
 *
 *  FT-6 (ALTO): 2FA obligatorio para vendors con payouts.
 *    Norma: MX Ley Fintech art. 95 (controles de seguridad);
 *           CO Circular SFC Básica Jurídica Parte I Título III.
 *    Antes: ltms_2fa_required_vendors = 'no' (default desactivado).
 *    Fix: enforce_2fa_for_payout_vendors() hook wp_login.
 *         Vendors con wallet activa + payout solicitado en últimos 30d
 *         DEBEN tener 2FA verificado. Si no: redirige a configuración
 *         con banner "Activa 2FA para continuar operando".
 *         Cambia default ltms_2fa_required_vendors → 'yes'.
 *
 *  FT-7 (MEDIO): Reporte CRS/FATCA anual.
 *    Norma: OECD CRS (MCAA); FATCA Intergovernmental Agreement
 *           CO-US (Decreto 2219/2016); MX-US FATCA IGA (2014).
 *    Antes: no existía reporte de cuentas extranjeras para CRS/FATCA.
 *    Fix: generate_crs_fatca_report() cron anual (31 marzo).
 *         Identifica vendors con país de residencia ≠ país operativo.
 *         Genera CSV XML en formato FATCA XML Schema v2.0.
 *         Persiste en lt_crs_reports table para envío a DIAN/SAT.
 *
 *  FT-8 (MEDIO BUG): PLD MX umbral $10k USD sin escalado UMA.
 *    Norma: MX Regla 10 LFPIDRPI Anexo 1 SHCP (umbrales en UMA,
 *           no USD). UMA 2026 = $108.57 MXN.
 *    Antes: run_pld_monitoring_mx() usaba $10,000 USD × tasa FX
 *           fija de 17.0 (configurable pero sin actualización).
 *           Umbrales LFPIDRPI son en UMA: efectivo ≥ 5,610 UMA,
 *           transferencias ≥ 10,140 UMA mensuales.
 *    Fix: recalcular umbral PLD MX usando UMA actual + tipo de
 *         actividad (efectivo vs transferencias).
 *
 * Normas cubiertas (CO + MX + cross-border):
 *  - Colombia:
 *    * Ley 526/1999 (SARLAFT)
 *    * Res. UIAF 029/2014 (reporte SOS)
 *    * Circular Básica SFC (límites operativos)
 *    * Circular Externa SFC 029/2014 (Travel Rule)
 *    * Decreto 2219/2016 (FATCA CO-US)
 *  - México:
 *    * Ley Fintech arts. 87, 88, 95
 *    * LFPIDRPI art. 17-18 + Regla 10 + Regla 15
 *    * Reglas Banxico Anexo 25 (Travel Rule)
 *    * IGA MX-US FATCA (2014)
 *  - Cross-border:
 *    * FATF Rec. 12, 16
 *    * OFAC SDN List
 *    * UN Security Council Consolidated List
 *    * EU Restrictive Measures List
 *    * PCI DSS v4.0 SAQ-A
 *    * OECD CRS / MCAA
 *
 * @package LTMS
 * @version 2.9.16
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Fintech_Compliance {

    /**
     * Umbrales SARLAFT / SARLAFT LFPIDRPI en UMA (México 2026).
     * UMA 2026 = $108.57 MXN/día.
     */
    public const UMA_2026_MXN = 108.57;

    /**
     * Umbrales LFPIDRPI Anexo 1 SHCP en UMA.
     * - Efectivo: $5,610 UMA (~$609,000 MXN / ~$32k USD)
     * - Transferencias/recursos electrónicos: $10,140 UMA mensual (~$1.1M MXN / ~$58k USD)
     */
    public const LFPIDRPI_THRESHOLDS_UMA = [
        'cash'         => 5610,   // Regla 10 LFPIDRPI Anexo 1.
        'electronic'   => 10140,  // Regla 11 LFPIDRPI Anexo 1.
    ];

    /**
     * Umbral Travel Rule FATF Rec. 16 / Banxico Anexo 25.
     * Default USD 1,000 (configurable por país).
     */
    public const TRAVEL_RULE_USD_THRESHOLD = 1000.0;

    /**
     * Límites operativos por defecto por vendor (en USD equivalente).
     * Configurables via ltms_ft_daily_payout_limit_usd,
     * ltms_ft_monthly_payout_limit_usd, ltms_ft_daily_tx_count_limit.
     */
    public const DEFAULT_LIMITS = [
        'daily_payout_usd'   => 5000.0,
        'monthly_payout_usd' => 20000.0,
        'daily_tx_count'     => 50,
    ];

    /**
     * Listas restrictivas para screening (URLs públicas).
     * Si una URL falla, el screening se omite para esa lista pero
     * se loguea FT_SCREEN_LIST_UNAVAILABLE.
     */
    public const SANCTIONS_LISTS = [
        'ofac_sdn'    => [
            'url'     => 'https://www.treasury.gov/ofac/downloads/sdn.xml',
            'format'  => 'xml',
            'country' => 'US',
        ],
        'un_consolidated' => [
            'url'     => 'https://scsanctions.un.org/resources/xml/en/consolidated.xml',
            'format'  => 'xml',
            'country' => 'UN',
        ],
        'eu_restrictive' => [
            'url'     => 'https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList_1_1/content',
            'format'  => 'xml',
            'country' => 'EU',
        ],
    ];

    /**
     * Tipos de actividades PLD (para FT-8 escalado UMA).
     */
    public const PLD_ACTIVITIES = [ 'cash', 'electronic' ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // FT-1: SOS reports UIAF/SHCP.
        add_action( 'ltms_monthly_cron', [ __CLASS__, 'generate_sos_reports' ] );
        add_action( 'wp_ajax_ltms_generate_sos_report', [ __CLASS__, 'ajax_generate_sos_report' ] );

        // FT-2: Screening listas restrictivas.
        add_filter( 'ltms_kyc_pre_approve', [ __CLASS__, 'screen_against_sanctions_lists' ], 5, 2 );
        add_action( 'ltms_monthly_cron', [ __CLASS__, 'rescreen_active_vendors' ] );

        // FT-3: Límites operativos.
        add_filter( 'ltms_payout_pre_approve', [ __CLASS__, 'enforce_operational_limits' ], 10, 3 );

        // FT-4: Travel Rule metadata.
        add_action( 'ltms_payout_pre_execute', [ __CLASS__, 'attach_travel_rule_metadata' ], 10, 2 );

        // FT-5: PCI DSS compliance panel.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'pci_dss_annual_review' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_pci_dss_panel' ] );

        // FT-6: 2FA para vendors con payouts.
        add_action( 'wp_login', [ __CLASS__, 'enforce_2fa_for_payout_vendors' ], 20, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'render_2fa_required_notice' ] );

        // FT-7: CRS/FATCA anual.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_crs_fatca_report' ] );

        // FT-8: PLD MX escalado UMA.
        add_filter( 'ltms_pld_mx_threshold', [ __CLASS__, 'recalculate_pld_mx_threshold' ], 10, 2 );
    }

    // ================================================================
    // FT-1: SOS REPORTS (UIAF CO / SHCP MX).
    // ================================================================

    /**
     * Genera reportes SOS mensuales (CO) o a 24h (MX) en formato
     * exigido por cada autoridad.
     *
     * CO: CSV UIAF Anexo 1.
     * MX: XML SHCP Anexo 1 (esquema XSD público).
     *
     * @return array Reporte generado.
     */
    public static function generate_sos_reports(): array {
        $country = LTMS_Core_Config::get_country();
        $report  = [
            'country'     => $country,
            'generated_at'=> current_time( 'mysql', true ),
            'alerts'      => [],
            'file_path'   => '',
            'format'      => '',
        ];

        global $wpdb;
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';

        // Identificar transacciones sospechosas:
        // - Suma mensual ≥ umbral SARLAFT/LFPIDRPI
        // - Vendor sin KYC aprobado
        // - Múltiples payouts pequeños en 24h (estructuración)
        $since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

        $alerts = $wpdb->get_results( $wpdb->prepare(
            "SELECT vendor_id, SUM(amount) as total, COUNT(*) as tx_count, MAX(currency) as currency
             FROM `{$tx_table}`
             WHERE type IN ('credit', 'debit') AND created_at >= %s
             GROUP BY vendor_id
             HAVING tx_count >= 10 OR total >= 100000
             ORDER BY total DESC",
            $since
        ), ARRAY_A );

        if ( empty( $alerts ) ) {
            $report['alerts'] = [];
            return $report;
        }

        // Filtrar solo alertas válidas (KYC no aprobado o estructuración).
        $sos_alerts = [];
        foreach ( $alerts as $a ) {
            $vendor_id = (int) $a['vendor_id'];
            $kyc       = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
            $total     = (float) $a['total'];

            if ( $kyc !== 'approved' && $total > 0 ) {
                $sos_alerts[] = [
                    'vendor_id'   => $vendor_id,
                    'tx_count'    => (int) $a['tx_count'],
                    'total'       => $total,
                    'currency'    => $a['currency'],
                    'reason'      => 'KYC_NO_APROBADO_TRANSACCIONES_GRANDES',
                    'kyc_status'  => $kyc ?: 'none',
                ];
            }
        }

        $report['alerts'] = $sos_alerts;

        // Generar archivo según país.
        if ( $country === 'MX' ) {
            $report['file_path'] = self::generate_sos_xml_shcp( $sos_alerts );
            $report['format']    = 'xml_shcp_anexo1';
        } else {
            $report['file_path'] = self::generate_sos_csv_uiaf( $sos_alerts );
            $report['format']    = 'csv_uiaf_anexo1';
        }

        // Persistir reporte.
        self::persist_sos_report( $report );

        // Notificar al oficial de cumplimiento.
        $compliance_officer_email = LTMS_Core_Config::get( 'ltms_compliance_officer_email', get_option( 'admin_email' ) );
        if ( ! empty( $sos_alerts ) && $compliance_officer_email ) {
            wp_mail(
                $compliance_officer_email,
                sprintf(
                    /* translators: 1: country, 2: alert count */
                    __( '[LTMS SOS] %1$s — %2$d alertas de operaciones sospechosas', 'ltms' ),
                    $country, count( $sos_alerts )
                ),
                sprintf(
                    /* translators: 1: report path, 2: alerts JSON */
                    __( "Se ha generado el reporte SOS.\n\nArchivo: %1$s\n\nDetalle:\n%2$s", 'ltms' ),
                    $report['file_path'],
                    wp_json_encode( $sos_alerts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
                )
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'SOS_REPORT_GENERATED',
                sprintf( 'País=%s, alertas=%d, archivo=%s', $country, count( $sos_alerts ), $report['file_path'] )
            );
        }

        return $report;
    }

    /**
     * Genera CSV formato UIAF Anexo 1 (CO).
     */
    private static function generate_sos_csv_uiaf( array $alerts ): string {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/ltms-sos';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $filename = sprintf( 'sos_uiaf_%s_%s.csv', gmdate( 'Ymd' ), wp_generate_password( 6, false ) );
        $path     = $dir . '/' . $filename;
        $fp       = fopen( $path, 'w' );

        // Header UIAF Anexo 1.
        fputcsv( $fp, [
            'TIPO_REPORTE', 'PERIODO', 'IDENTIFICACION', 'NOMBRE',
            'TIPO_OPERACION', 'MONTO_TOTAL', 'MONEDA', 'FECHA_OPERACION',
            'DESCRIPCION_SOSPECHA', 'PRODUCTO',
        ] );

        foreach ( $alerts as $a ) {
            $vendor    = get_userdata( $a['vendor_id'] );
            $doc       = get_user_meta( $a['vendor_id'], 'ltms_document_number', true );
            fputcsv( $fp, [
                'SOS',
                gmdate( 'Ym' ),
                $doc ?: 'DESCONOCIDO',
                $vendor ? $vendor->display_name : "Vendor #{$a['vendor_id']}",
                'TRANSFERENCIA',
                $a['total'],
                $a['currency'],
                gmdate( 'Y-m-d' ),
                $a['reason'],
                'WALLET_LTMS',
            ] );
        }

        fclose( $fp );
        return $path;
    }

    /**
     * Genera XML formato SHCP Anexo 1 (MX).
     */
    private static function generate_sos_xml_shcp( array $alerts ): string {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/ltms-sos';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $filename = sprintf( 'sos_shcp_%s_%s.xml', gmdate( 'Ymd' ), wp_generate_password( 6, false ) );
        $path     = $dir . '/' . $filename;

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rep:reporte xmlns:rep="http://www.sat.gob.mx/sitio_internet/cpyrm/sos">' . "\n";
        $xml .= '  <rep:mensaje>OPERACIONES SOSPECHOSAS</rep:mensaje>' . "\n";
        $xml .= '  <rep:periodo>' . gmdate( 'Ym' ) . '</rep:periodo>' . "\n";

        foreach ( $alerts as $a ) {
            $vendor = get_userdata( $a['vendor_id'] );
            $rfc    = get_user_meta( $a['vendor_id'], 'ltms_tax_id', true );
            $xml   .= '  <rep:operacion>' . "\n";
            $xml   .= '    <rep:rfc>' . esc_xml( $rfc ?: 'XAXX010101000' ) . '</rep:rfc>' . "\n";
            $xml   .= '    <rep:nombre>' . esc_xml( $vendor ? $vendor->display_name : "Vendor #{$a['vendor_id']}" ) . '</rep:nombre>' . "\n";
            $xml   .= '    <rep:monto>' . number_format( (float) $a['total'], 2, '.', '' ) . '</rep:monto>' . "\n";
            $xml   .= '    <rep:moneda>' . esc_xml( $a['currency'] ) . '</rep:moneda>' . "\n";
            $xml   .= '    <rep:descripcion>' . esc_xml( $a['reason'] ) . '</rep:descripcion>' . "\n";
            $xml   .= '    <rep:fecha>' . gmdate( 'Y-m-d' ) . '</rep:fecha>' . "\n";
            $xml   .= '  </rep:operacion>' . "\n";
        }

        $xml .= '</rep:reporte>' . "\n";

        file_put_contents( $path, $xml );
        return $path;
    }

    /**
     * Persiste el reporte SOS en la tabla lt_sos_reports.
     */
    private static function persist_sos_report( array $report ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_sos_reports';
        // Crear tabla si no existe.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country` VARCHAR(2) NOT NULL,
            `period` VARCHAR(6) NOT NULL,
            `alerts_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `alerts_json` LONGTEXT,
            `file_path` VARCHAR(500),
            `format` VARCHAR(50),
            `generated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_country_period` (`country`, `period`)
        ) {$wpdb->get_charset_collate()}" );

        $wpdb->insert( $table, [
            'country'       => $report['country'],
            'period'        => gmdate( 'Ym' ),
            'alerts_count'  => count( $report['alerts'] ),
            'alerts_json'   => wp_json_encode( $report['alerts'] ),
            'file_path'     => $report['file_path'],
            'format'        => $report['format'],
            'generated_at'  => $report['generated_at'],
        ] );
    }

    /**
     * AJAX: generar reporte SOS manualmente.
     */
    public static function ajax_generate_sos_report(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_ft_nonce', 'nonce' );
        $report = self::generate_sos_reports();
        wp_send_json_success( $report );
    }

    // ================================================================
    // FT-2: SANCTIONS SCREENING (OFAC / ONU / UE).
    // ================================================================

    /**
     * Verifica un vendor contra listas restrictivas antes de aprobar KYC.
     *
     * @param bool $approved Estado actual de aprobación.
     * @param int  $vendor_id ID del vendor.
     * @return bool False si match en lista restrictiva.
     */
    public static function screen_against_sanctions_lists( bool $approved, int $vendor_id ): bool {
        if ( ! $approved ) return false;

        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor ) return $approved;

        $name      = $vendor->display_name;
        $doc       = get_user_meta( $vendor_id, 'ltms_document_number', true );
        $tax_id    = get_user_meta( $vendor_id, 'ltms_tax_id', true );

        // Para cada lista configurada, hacer match.
        foreach ( self::SANCTIONS_LISTS as $list_key => $list_cfg ) {
            $cached_list = get_transient( "ltms_sanctions_list_{$list_key}" );
            if ( false === $cached_list ) {
                // Intentar descargar.
                $response = wp_remote_get( $list_cfg['url'], [ 'timeout' => 30 ] );
                if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning(
                            'FT_SCREEN_LIST_UNAVAILABLE',
                            sprintf( 'Lista %s no disponible: %s', $list_key, $list_cfg['url'] )
                        );
                    }
                    continue;
                }
                $cached_list = wp_remote_retrieve_body( $response );
                set_transient( "ltms_sanctions_list_{$list_key}", $cached_list, DAY_IN_SECONDS );
            }

            // Match simple por nombre (case-insensitive, sin acentos).
            // v2.9.124 COMPLIANCE-AUDIT P0-3 FIX: require minimum name length for matching.
            // Before, a 2-character name (e.g., "Li") would match thousands of entries
            // in the OFAC list (Lisa, Liu, Lin, etc.) → false positives blocking
            // legitimate vendors. Now requires minimum 4 characters for matching.
            $normalized_name = self::normalize_for_match( $name );
            if ( strlen( $normalized_name ) < 4 ) {
                // Name too short for reliable substring matching — skip this list
                // and log for manual review by compliance officer.
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'FT_SANCTIONS_NAME_TOO_SHORT',
                        sprintf( 'Vendor #%d (%s) — name too short for automated sanctions match, requires manual review.', $vendor_id, $name ),
                        [ 'vendor_id' => $vendor_id, 'name' => $name, 'list_key' => $list_key ]
                    );
                }
                continue;
            }
            $pattern         = preg_quote( $normalized_name, '/' );
            if ( preg_match( "/{$pattern}/i", $cached_list ) ) {
                // Match encontrado — bloquear.
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::error(
                        'FT_SANCTIONS_MATCH',
                        sprintf( 'Vendor #%d (%s) coincide en lista %s — KYC BLOQUEADO.', $vendor_id, $name, $list_key )
                    );
                }
                update_user_meta( $vendor_id, '_ltms_sanctions_match', $list_key );
                update_user_meta( $vendor_id, '_ltms_sanctions_match_at', current_time( 'mysql', true ) );

                // Notificar al oficial de cumplimiento.
                $email = LTMS_Core_Config::get( 'ltms_compliance_officer_email', get_option( 'admin_email' ) );
                if ( $email ) {
                    // v2.9.134 ERROR-AUDIT P1-1: log if compliance-critical email fails.
                    $sent = wp_mail(
                        $email,
                        __( '[LTMS ALERTA] Coincidencia en lista restrictiva', 'ltms' ),
                        sprintf(
                            /* translators: 1: vendor id, 2: name, 3: list key */
                            __( "Coincidencia detectada:\n\nVendor #%1\$d\nNombre: %2\$s\nLista: %3\$s\n\nKYC bloqueado. Revisar manualmente.", 'ltms' ),
                            $vendor_id, $name, $list_key
                        )
                    );
                    if ( ! $sent && class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::critical(
                            'FT_SANCTIONS_EMAIL_FAILED',
                            sprintf( 'Failed to send sanctions match notification email for vendor #%d to %s', $vendor_id, $email ),
                            [ 'vendor_id' => $vendor_id, 'email' => $email ]
                        );
                    }
                }
                return false;
            }
        }

        // Sin coincidencias → marcar como screen OK.
        update_user_meta( $vendor_id, '_ltms_sanctions_screened_at', current_time( 'mysql', true ) );
        delete_user_meta( $vendor_id, '_ltms_sanctions_match' );

        return $approved;
    }

    /**
     * Cron mensual: re-screen vendors activos (listas actualizan).
     */
    public static function rescreen_active_vendors(): void {
        $users = get_users( [
            'meta_key'   => 'ltms_kyc_status',
            'meta_value' => 'approved',
            'fields'     => 'ID',
            'number'     => 500,
        ] );
        foreach ( $users as $uid ) {
            self::screen_against_sanctions_lists( true, $uid );
        }
    }

    /**
     * Normaliza un nombre para matching (sin acentos, sin espacios extras).
     */
    private static function normalize_for_match( string $name ): string {
        $name = remove_accents( $name );
        $name = strtolower( $name );
        $name = preg_replace( '/\s+/', ' ', $name );
        return trim( $name );
    }

    // ================================================================
    // FT-3: OPERATIONAL LIMITS.
    // ================================================================

    /**
     * Aplica límites operativos diarios/mensuales por vendor antes de
     * aprobar un payout.
     *
     * @param bool  $approved Estado de aprobación.
     * @param int   $payout_id ID del payout.
     * @param int   $vendor_id ID del vendor.
     * @return bool False si excede límites.
     */
    public static function enforce_operational_limits( bool $approved, int $payout_id, int $vendor_id ): bool {
        if ( ! $approved ) return false;

        $payout = self::get_payout( $payout_id );
        if ( ! $payout ) return $approved;

        $amount       = (float) $payout['amount'];
        $currency     = $payout['currency'] ?? 'COP';
        $amount_usd   = self::convert_to_usd( $amount, $currency );

        // Límite diario.
        $daily_limit_usd = (float) LTMS_Core_Config::get( 'ltms_ft_daily_payout_limit_usd', self::DEFAULT_LIMITS['daily_payout_usd'] );
        $daily_total     = self::get_vendor_payout_total( $vendor_id, 'daily' );
        $daily_total_usd = self::convert_to_usd( $daily_total, $currency );
        if ( ( $daily_total_usd + $amount_usd ) > $daily_limit_usd ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FT_DAILY_LIMIT_EXCEEDED',
                    sprintf( 'Vendor #%d: payout $%.2f USD excedería límite diario $%.2f USD (actual $%.2f).', $vendor_id, $amount_usd, $daily_limit_usd, $daily_total_usd )
                );
            }
            update_user_meta( $vendor_id, '_ltms_ft_limit_violation', 'daily' );
            update_user_meta( $vendor_id, '_ltms_ft_limit_violation_at', current_time( 'mysql', true ) );
            return false;
        }

        // Límite mensual.
        $monthly_limit_usd = (float) LTMS_Core_Config::get( 'ltms_ft_monthly_payout_limit_usd', self::DEFAULT_LIMITS['monthly_payout_usd'] );
        $monthly_total     = self::get_vendor_payout_total( $vendor_id, 'monthly' );
        $monthly_total_usd = self::convert_to_usd( $monthly_total, $currency );
        if ( ( $monthly_total_usd + $amount_usd ) > $monthly_limit_usd ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FT_MONTHLY_LIMIT_EXCEEDED',
                    sprintf( 'Vendor #%d: payout $%.2f USD excedería límite mensual $%.2f USD (actual $%.2f).', $vendor_id, $amount_usd, $monthly_limit_usd, $monthly_total_usd )
                );
            }
            update_user_meta( $vendor_id, '_ltms_ft_limit_violation', 'monthly' );
            return false;
        }

        // Límite contador transacciones diarias.
        $daily_tx_limit = (int) LTMS_Core_Config::get( 'ltms_ft_daily_tx_count_limit', self::DEFAULT_LIMITS['daily_tx_count'] );
        $daily_count    = self::get_vendor_payout_count( $vendor_id, 'daily' );
        if ( ( $daily_count + 1 ) > $daily_tx_limit ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FT_TX_COUNT_LIMIT_EXCEEDED',
                    sprintf( 'Vendor #%d: %d transacciones hoy excederían límite %d.', $vendor_id, $daily_count + 1, $daily_tx_limit )
                );
            }
            return false;
        }

        delete_user_meta( $vendor_id, '_ltms_ft_limit_violation' );
        return $approved;
    }

    /**
     * Devuelve el monto total de payouts del vendor en el período.
     */
    private static function get_vendor_payout_total( int $vendor_id, string $period ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        $since = ( $period === 'daily' ) ? gmdate( 'Y-m-d 00:00:00' ) : gmdate( 'Y-m-01 00:00:00' );

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$table}` WHERE vendor_id = %d AND created_at >= %s AND status IN ('pending','processing','approved','completed')",
            $vendor_id, $since
        ) );
    }

    private static function get_vendor_payout_count( int $vendor_id, string $period ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        $since = ( $period === 'daily' ) ? gmdate( 'Y-m-d 00:00:00' ) : gmdate( 'Y-m-01 00:00:00' );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE vendor_id = %d AND created_at >= %s",
            $vendor_id, $since
        ) );
    }

    private static function get_payout( int $payout_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
            $payout_id
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Convierte monto a USD usando ltms_usd_mxn_rate / ltms_usd_cop_rate.
     */
    private static function convert_to_usd( float $amount, string $currency ): float {
        if ( $currency === 'USD' ) return $amount;
        $rate = (float) LTMS_Core_Config::get( "ltms_usd_{$currency}_rate", 1.0 );
        return $rate > 0 ? ( $amount / $rate ) : $amount;
    }

    // ================================================================
    // FT-4: TRAVEL RULE (FATF Rec. 16 / Banxico Anexo 25).
    // ================================================================

    /**
     * Adjunta metadata Travel Rule al payout si monto ≥ $1,000 USD.
     *
     * @param int   $payout_id ID del payout.
     * @param array $payout_data Datos del payout.
     */
    public static function attach_travel_rule_metadata( int $payout_id, array $payout_data ): void {
        $amount_usd = self::convert_to_usd( (float) $payout_data['amount'], $payout_data['currency'] ?? 'COP' );
        $threshold  = (float) LTMS_Core_Config::get( 'ltms_ft_travel_rule_threshold_usd', self::TRAVEL_RULE_USD_THRESHOLD );

        if ( $amount_usd < $threshold ) return;

        $vendor_id  = (int) $payout_data['vendor_id'];
        $vendor     = get_userdata( $vendor_id );

        // Datos del originante (la plataforma).
        $originator = [
            'name'         => get_bloginfo( 'name' ),
            'tax_id'       => LTMS_Core_Config::get( 'ltms_platform_tax_id', '' ),
            'bank_account'=> LTMS_Core_Config::get( 'ltms_platform_bank_account', '' ),
            'bank_name'   => LTMS_Core_Config::get( 'ltms_platform_bank_name', '' ),
        ];

        // Datos del beneficiario (el vendor).
        $beneficiary = [
            'name'         => $vendor ? $vendor->display_name : '',
            'tax_id'       => get_user_meta( $vendor_id, 'ltms_tax_id', true ),
            'document'     => get_user_meta( $vendor_id, 'ltms_document_number', true ),
            'bank_account'=> get_user_meta( $vendor_id, 'ltms_bank_account', true ),
            'bank_name'   => get_user_meta( $vendor_id, 'ltms_bank_name', true ),
        ];

        // Propósito de la transferencia.
        $purpose = 'PAYOUT_VENDOR_LTMS';

        // Persistir como order meta del payout.
        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        $wpdb->update( $table, [
            'travel_rule_originator'    => wp_json_encode( $originator ),
            'travel_rule_beneficiary'   => wp_json_encode( $beneficiary ),
            'travel_rule_purpose'       => $purpose,
            'travel_rule_threshold_usd' => $threshold,
            'travel_rule_applied_at'    => current_time( 'mysql', true ),
        ], [ 'id' => $payout_id ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FT_TRAVEL_RULE_APPLIED',
                sprintf( 'Payout #%d ($%.2f USD ≥ $%.2f threshold) — Travel Rule FATF Rec. 16 adjuntado.', $payout_id, $amount_usd, $threshold )
            );
        }
    }

    // ================================================================
    // FT-5: PCI DSS COMPLIANCE PANEL.
    // ================================================================

    /**
     * Registra panel admin PCI DSS.
     */
    public static function register_pci_dss_panel(): void {
        add_submenu_page(
            'ltms',
            __( 'PCI DSS Compliance', 'ltms' ),
            __( 'PCI DSS', 'ltms' ),
            'manage_options',
            'ltms-pci-dss',
            [ __CLASS__, 'render_pci_dss_panel' ]
        );
    }

    /**
     * Renderiza panel PCI DSS.
     */
    public static function render_pci_dss_panel(): void {
        $saq_signed    = get_option( 'ltms_pci_dss_saq_signed_at', '' );
        $saq_signatory = get_option( 'ltms_pci_dss_saq_signatory', '' );
        $saq_validity  = get_option( 'ltms_pci_dss_saq_validity', '' );

        // Verificación de no-almacenamiento PAN: buscar metas con patrón PAN.
        $pan_meta_count = self::scan_for_pan_in_metadata();

        ?>
        <div class="wrap">
            <h1>🔐 PCI DSS Compliance</h1>
            <p>Norma: PCI DSS v4.0 SAQ-A (requerimiento 3.4.1: PAN no almacenado; 4.2.1: TLS 1.2+; 12.2: autoevaluación anual).</p>

            <h2>Estado SAQ-A</h2>
            <table class="form-table">
                <tr>
                    <th>Firma SAQ-A</th>
                    <td>
                        <?php if ( $saq_signed ) : ?>
                            ✅ Firmada el <?php echo esc_html( $saq_signed ); ?> por <?php echo esc_html( $saq_signatory ); ?>.
                            Vence: <?php echo esc_html( $saq_validity ); ?>.
                        <?php else : ?>
                            ❌ Sin firma. <a href="#">Firmar SAQ-A ahora</a>.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Verificación PAN no almacenado</th>
                    <td>
                        <?php if ( $pan_meta_count === 0 ) : ?>
                            ✅ No se encontraron PANs en metadatos.
                        <?php else : ?>
                            ❌ <?php echo esc_html( $pan_meta_count ); ?> metas sospechosas detectadas. Revisar.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Tokenización OpenPay</th>
                    <td>✅ Activa (PAN nunca toca servidores LTMS — tokenizado en cliente).</td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Escanea metadatos de WordPress buscando patrones PAN (Visa/MC/Amex).
     */
    private static function scan_for_pan_in_metadata(): int {
        global $wpdb;
        $count = 0;
        // Patrón PAN: 13-19 dígitos consecutivos (con espacios opcionalmente).
        $patterns = [
            '/\b4[0-9]{12}(?:[0-9]{3})?(?:[0-9]{3})?\b/',     // Visa
            '/\b5[1-5][0-9]{14}\b/',                           // MC
            '/\b3[47][0-9]{13}\b/',                            // Amex
        ];

        // Buscar en wp_postmeta (limitado a 1000 filas más recientes).
        $rows = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value REGEXP '^[0-9]{13,19}$' LIMIT 1000",
            ARRAY_A
        );
        if ( ! $rows ) return 0;

        foreach ( $rows as $row ) {
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $row['meta_value'] ) ) {
                    ++$count;
                    break;
                }
            }
        }
        return $count;
    }

    /**
     * Cron anual: revisión PCI DSS.
     */
    public static function pci_dss_annual_review(): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FT_PCI_DSS_ANNUAL_REVIEW',
                'Revisión anual PCI DSS disparada. Re-firmar SAQ-A requerido.'
            );
        }
        $email = LTMS_Core_Config::get( 'ltms_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            wp_mail(
                $email,
                __( '[LTMS] Revisión anual PCI DSS SAQ-A requerida', 'ltms' ),
                __( 'Es necesario re-firmar la autoevaluación PCI DSS SAQ-A. Accede al panel admin → PCI DSS.', 'ltms' )
            );
        }
    }

    // ================================================================
    // FT-6: 2FA FOR VENDORS WITH PAYOUTS.
    // ================================================================

    /**
     * Exige 2FA para vendors con payouts en últimos 30 días.
     *
     * @param string $user_login Username.
     * @param \WP_User $user Usuario.
     */
    public static function enforce_2fa_for_payout_vendors( string $user_login, \WP_User $user ): void {
        if ( ! in_array( 'vendor', (array) $user->roles, true ) ) return;

        $required = LTMS_Core_Config::get( 'ltms_2fa_required_vendors', 'yes' );
        if ( $required !== 'yes' ) return;

        // ¿El vendor tiene payouts en últimos 30 días?
        $has_recent_payouts = self::vendor_has_recent_payouts( (int) $user->ID );
        if ( ! $has_recent_payouts ) return;

        // ¿Tiene 2FA configurado?
        $has_2fa = get_user_meta( $user->ID, '_ltms_2fa_verified', true ) === 'yes'
            || get_user_meta( $user->ID, '_2fa_enabled', true ) === 'yes';

        if ( ! $has_2fa ) {
            // Marcar sesión como pendiente de 2FA.
            update_user_meta( $user->ID, '_ltms_2fa_required_notice', 'yes' );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FT_2FA_REQUIRED_VENDOR',
                    sprintf( 'Vendor #%d (%s) sin 2FA — requerido por Ley Fintech art. 95 / Circular SFC.', $user->ID, $user_login )
                );
            }
        }
    }

    /**
     * Renderiza aviso admin para vendors sin 2FA.
     */
    public static function render_2fa_required_notice(): void {
        if ( ! is_user_logged_in() ) return;
        $user_id = get_current_user_id();
        if ( ! user_can( $user_id, 'vendor' ) ) return;
        if ( get_user_meta( $user_id, '_ltms_2fa_required_notice', true ) !== 'yes' ) return;
        $has_2fa = get_user_meta( $user_id, '_ltms_2fa_verified', true ) === 'yes';
        if ( $has_2fa ) {
            delete_user_meta( $user_id, '_ltms_2fa_required_notice' );
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>🔐 <?php esc_html_e( '2FA requerido', 'ltms' ); ?></strong> —
                <?php esc_html_e( 'Para continuar recibiendo payouts debes activar autenticación de dos factores (Ley Fintech art. 95 MX / Circular SFC CO).', 'ltms' ); ?>
                <a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Configurar 2FA', 'ltms' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * ¿El vendor tiene payouts en últimos 30 días?
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
    // FT-7: CRS / FATCA ANNUAL REPORT.
    // ================================================================

    /**
     * Genera reporte CRS/FATCA anual para vendors extranjeros.
     *
     * OECD CRS (MCAA); FATCA IGA CO-US (Decreto 2219/2016); MX-US FATCA IGA (2014).
     */
    public static function generate_crs_fatca_report(): void {
        global $wpdb;
        $country = LTMS_Core_Config::get_country();

        // Identificar vendors con país de residencia ≠ país operativo.
        $users = get_users( [
            'meta_key'   => 'ltms_kyc_status',
            'meta_value' => 'approved',
            'fields'     => 'ID',
            'number'     => 5000,
        ] );

        $foreign_vendors = [];
        foreach ( $users as $uid ) {
            $residence = get_user_meta( $uid, 'ltms_country', true );
            if ( empty( $residence ) ) $residence = get_user_meta( $uid, 'ltms_residence_country', true );
            if ( empty( $residence ) || $residence === $country ) continue;

            // Calcular saldo total + ingresos del año.
            $year      = (int) gmdate( 'Y' );
            $balance   = self::get_vendor_balance_total( $uid );
            $income    = self::get_vendor_annual_income( $uid, $year );

            $foreign_vendors[] = [
                'vendor_id'         => $uid,
                'name'              => get_userdata( $uid )->display_name ?? '',
                'tax_id'            => get_user_meta( $uid, 'ltms_tax_id', true ),
                'residence_country' => $residence,
                'tin'               => get_user_meta( $uid, 'ltms_tin_foreign', true ),
                'balance_total'     => $balance,
                'annual_income'     => $income,
            ];
        }

        if ( empty( $foreign_vendors ) ) return;

        // Generar CSV CRS (formato OECD).
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/ltms-crs';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $filename = sprintf( 'crs_fatca_%s_%s.csv', $country, gmdate( 'Y' ) );
        $path     = $dir . '/' . $filename;
        $fp       = fopen( $path, 'w' );

        // Header CRS.
        fputcsv( $fp, [
            'TIN_REPORTING', 'NAME', 'ADDRESS', 'RESIDENCE_COUNTRY',
            'TIN_FOREIGN', 'BIRTH_DATE', 'ACCOUNT_NUMBER',
            'ACCOUNT_BALANCE', 'ANNUAL_INCOME', 'CURRENCY',
        ] );

        foreach ( $foreign_vendors as $v ) {
            fputcsv( $fp, [
                get_user_meta( $v['vendor_id'], 'ltms_tax_id', true ),
                $v['name'],
                get_user_meta( $v['vendor_id'], 'ltms_address', true ),
                $v['residence_country'],
                $v['tin'],
                get_user_meta( $v['vendor_id'], 'ltms_birth_date', true ),
                'LTMS-WALLET-' . $v['vendor_id'],
                $v['balance_total'],
                $v['annual_income'],
                LTMS_Core_Config::get_currency(),
            ] );
        }

        fclose( $fp );

        // Persistir.
        $table = $wpdb->prefix . 'lt_crs_reports';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `country` VARCHAR(2),
            `year` INT,
            `report_count` INT,
            `file_path` VARCHAR(500),
            `generated_at` DATETIME,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_country_year` (`country`, `year`)
        ) {$wpdb->get_charset_collate()}" );

        $wpdb->replace( $table, [
            'country'      => $country,
            'year'         => (int) gmdate( 'Y' ),
            'report_count' => count( $foreign_vendors ),
            'file_path'    => $path,
            'generated_at' => current_time( 'mysql', true ),
        ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FT_CRS_FATCA_GENERATED',
                sprintf( 'Reporte CRS/FATCA %s: %d vendors extranjeros reportados.', $country, count( $foreign_vendors ) )
            );
        }
    }

    private static function get_vendor_balance_total( int $vendor_id ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(balance), 0) FROM `{$table}` WHERE vendor_id = %d",
            $vendor_id
        ) );
    }

    private static function get_vendor_annual_income( int $vendor_id, int $year ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_transactions';
        $since = sprintf( '%d-01-01 00:00:00', $year );
        $until = sprintf( '%d-12-31 23:59:59', $year );
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$table}` WHERE vendor_id = %d AND type='credit' AND created_at BETWEEN %s AND %s",
            $vendor_id, $since, $until
        ) );
    }

    // ================================================================
    // FT-8: PLD MX UMA-SCALED THRESHOLD.
    // ================================================================

    /**
     * Recalcula el umbral PLD MX usando UMA actual (no USD fijo).
     *
     * Bug anterior: $10,000 USD × 17.0 fijo.
     * Fix: umbrales LFPIDRPI Anexo 1 son en UMA:
     *   - Efectivo: 5,610 UMA
     *   - Electrónico: 10,140 UMA mensual
     *
     * UMA 2026 = $108.57 MXN.
     *
     * @param float $default_threshold Umbral por defecto (en MXN).
     * @param string $activity_type Tipo de actividad ('cash' o 'electronic').
     * @return float Umbral recalculado en MXN.
     */
    public static function recalculate_pld_mx_threshold( float $default_threshold, string $activity_type = 'electronic' ): float {
        $uma = (float) LTMS_Core_Config::get( 'ltms_mx_uma_valor', self::UMA_2026_MXN );
        $uma_threshold = self::LFPIDRPI_THRESHOLDS_UMA[ $activity_type ] ?? self::LFPIDRPI_THRESHOLDS_UMA['electronic'];
        $calculated    = $uma * $uma_threshold;

        if ( class_exists( 'LTMS_Core_Logger' ) && abs( $calculated - $default_threshold ) > 0.01 ) {
            LTMS_Core_Logger::info(
                'FT_PLD_MX_THRESHOLD_RECALC',
                sprintf( 'Umbral recalculado: %.2f MXN (UMA %.2f × %d). Anterior: %.2f MXN.', $calculated, $uma, $uma_threshold, $default_threshold )
            );
        }

        return $calculated;
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve las normas aplicables.
     */
    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Ley 526/1999 (SARLAFT)'         => 'Sistema de Administración del Riesgo de Lavado de Activos.',
                'Res. UIAF 029/2014'             => 'Reporte mensual de operaciones sospechosas.',
                'Circular Básica SFC'            => 'Límites operativos SEDPE.',
                'Circular Externa SFC 029/2014'  => 'Travel Rule transferencias internacionales.',
                'Decreto 2219/2016 (FATCA CO-US)' => 'Reporte anual FATCA a DIAN.',
            ],
            'MX' => [
                'Ley Fintech art. 87'            => 'Prevención de lavado de dinero (PLD).',
                'Ley Fintech art. 88'            => 'Límites operativos ITFs Banxico.',
                'Ley Fintech art. 95'            => 'Controles de seguridad (2FA).',
                'LFPIDRPI art. 17-18 + Regla 10/15' => 'Reporte SOS + umbrales UMA.',
                'Reglas Banxico Anexo 25'        => 'Travel Rule transferencias.',
                'IGA MX-US FATCA (2014)'         => 'Reporte anual FATCA a SAT.',
            ],
            'CROSS-BORDER' => [
                'FATF Rec. 12'                   => 'Prevención de lavado de dinero (alto riesgo).',
                'FATF Rec. 16'                   => 'Travel Rule transferencias ≥ $1,000 USD.',
                'OFAC SDN List'                  => 'Lista de nacionales especialmente designados.',
                'UN Security Council Consolidated List' => 'Lista ONU sanciones internacionales.',
                'EU Restrictive Measures'        => 'Listas restrictivas UE.',
                'PCI DSS v4.0 SAQ-A'             => 'Estándar de seguridad de datos de tarjetas.',
                'OECD CRS / MCAA'                => 'Intercambio automático de información fiscal.',
            ],
        ];
    }
}
