<?php
/**
 * LTMS Fiscal Annual Close — Cierre fiscal anual + GMF + PAC adapters.
 *
 * LF-3: Gravamen a los Movimientos Financieros (GMF 4x1000) Colombia.
 * LF-4: Cierre fiscal anual — certificado de retenciones para cada vendor.
 * LF-5: PAC adapters para CFDI México (Facturama, SW Sapien, Edicom).
 *
 * @package LTMS
 * @version 2.9.11
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Fiscal_Annual_Close {

    public static function init(): void {
        // LF-3: GMF 4x1000 en retiros de wallet.
        add_action( 'ltms_payout_completed', [ __CLASS__, 'calculate_gmf_on_payout' ], 10, 2 );

        // LF-4: Cierre fiscal anual — cron.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_annual_withholding_certificates' ] );
        add_action( 'wp_ajax_ltms_generate_withholding_cert', [ __CLASS__, 'ajax_generate_cert' ] );
        add_action( 'wp_ajax_ltms_download_withholding_cert', [ __CLASS__, 'ajax_download_cert' ] );

        // LF-5: PAC adapter — hook para timbrado CFDI.
        add_action( 'ltms_cfdi_request', [ __CLASS__, 'process_cfdi_via_pac' ], 10, 3 );
    }

    // ================================================================
    // LF-3: GMF 4x1000 (Gravamen a los Movimientos Financieros)
    // ================================================================

    /**
     * Calcula el GMF (4x1000) sobre retiros de wallet a cuenta bancaria.
     *
     * Estatuto Tributario art. 871: 4x1000 sobre transacciones financieras.
     * El marketplace actúa como agente retenedor cuando transfiere a la cuenta
     * bancaria del vendor.
     *
     * Tasa: 0.4% = 4 por mil.
     * Base: monto del retiro (transferencia bancaria).
     * Exenciones: las primeras 350 UVT mensuales (~$18.4M COP 2026) están exentas
     * para cuentas de ahorro (art. 871 inc. 1 ET).
     *
     * @param int   $vendor_id
     * @param float $amount
     */
    public static function calculate_gmf_on_payout( int $vendor_id, float $amount ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' ) return; // GMF solo aplica en Colombia.

        $gmf_rate = (float) LTMS_Core_Config::get( 'ltms_gmf_rate', 0.004 ); // 4x1000.
        $gmf_amount = round( $amount * $gmf_rate, 2 );

        if ( $gmf_amount <= 0 ) return;

        // Verificar exención mensual (350 UVT para cuentas de ahorro).
        $uvt = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 52752.0 );
        $monthly_exemption = $uvt * (float) LTMS_Core_Config::get( 'ltms_gmf_monthly_exemption_uvt', 350 );
        $month = current_time( 'Y-m' );
        $monthly_gmf_key = '_ltms_gmf_accumulated_' . $month;
        $accumulated = (float) get_user_meta( $vendor_id, $monthly_gmf_key, true );

        if ( ( $accumulated + $amount ) <= $monthly_exemption ) {
            // Dentro de la exención: no se aplica GMF.
            update_user_meta( $vendor_id, $monthly_gmf_key, $accumulated + $amount );
            return;
        }

        // Por encima de la exención: aplicar GMF solo sobre el exceso.
        $taxable_base = max( 0, ( $accumulated + $amount ) - $monthly_exemption );
        $taxable_base = min( $taxable_base, $amount ); // No puede ser mayor al retiro actual.
        $gmf_amount = round( $taxable_base * $gmf_rate, 2 );

        // Actualizar acumulado.
        update_user_meta( $vendor_id, $monthly_gmf_key, $accumulated + $amount );

        if ( $gmf_amount <= 0 ) return;

        // Debitar GMF de la wallet del vendor.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            try {
                $idem_key = sprintf( 'gmf_payout_v%d_%s', $vendor_id, $month );
                LTMS_Business_Wallet::debit(
                    $vendor_id,
                    $gmf_amount,
                    sprintf( __( 'GMF 4x1000 — Retiro del mes %s (base: $%s)', 'ltms' ), $month, number_format( $taxable_base, 0, ',', '.' ) ),
                    [
                        'type'        => 'gmf_withholding',
                        'rate'        => $gmf_rate,
                        'base'        => $taxable_base,
                        'month'       => $month,
                        'exemption'   => $monthly_exemption,
                    ],
                    0,
                    '',
                    $idem_key
                );
            } catch ( \Throwable $e ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning( 'GMF_DEBIT_FAILED', sprintf( 'Vendor #%d: %s', $vendor_id, $e->getMessage() ) );
                }
            }
        }

        // Guardar certificado de retención GMF para el cierre anual.
        $year = (int) current_time( 'Y' );
        $cert_key = '_ltms_gmf_cert_' . $year;
        $cert = get_user_meta( $vendor_id, $cert_key, true ) ?: [ 'total' => 0, 'details' => [] ];
        $cert['total'] += $gmf_amount;
        $cert['details'][] = [
            'date'   => current_time( 'mysql', true ),
            'amount' => $amount,
            'base'   => $taxable_base,
            'gmf'    => $gmf_amount,
            'month'  => $month,
        ];
        update_user_meta( $vendor_id, $cert_key, $cert );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'GMF_WITHHELD',
                sprintf( 'Vendor #%d: GMF 4x1000=$%.2f sobre base=$%.2f (retiro=$%.2f, exención mensual=$%.2f)', $vendor_id, $gmf_amount, $taxable_base, $amount, $monthly_exemption )
            );
        }
    }

    // ================================================================
    // LF-4: CIERRE FISCAL ANUAL — CERTIFICADO DE RETENCIONES
    // ================================================================

    /**
     * Genera certificados de retenciones anuales para todos los vendors.
     *
     * Estatuto Tributario art. 381 + Res. DIAN 0227/2020:
     *  El agente retenedor debe entregar certificado de retenciones
     *  antes del 15 de marzo del año siguiente.
     *
     * ISR art. 118 LISR (México):
     *  constancia de retenciones antes del 15 de febrero.
     *
     * El certificado incluye:
     *  - ReteFuente total del año
     *  - ReteIVA total del año
     *  - ReteICA total del año (CO)
     *  - ISR retenido total (MX)
     *  - IVA retenido total (MX)
     *  - IEPS retenido total (MX)
     *  - GMF 4x1000 total del año (CO)
     */
    public static function generate_annual_withholding_certificates( int $year = 0 ): array {
        if ( ! $year ) $year = (int) current_time( 'Y' ) - 1; // Año anterior.

        global $wpdb;
        $c_table = $wpdb->prefix . 'lt_commissions';
        $country = LTMS_Core_Config::get_country();

        $date_from = sprintf( '%04d-01-01', $year );
        $date_to   = sprintf( '%04d-12-31', $year );

        // Obtener todos los vendors con comisiones en el año.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $vendors = $wpdb->get_results( $wpdb->prepare(
            "SELECT vendor_id,
                    SUM(tax_withholding) as total_retefuente,
                    SUM(iva_amount) as total_iva,
                    SUM(ieps_amount) as total_ieps,
                    SUM(iva_retenido) as total_iva_retenido,
                    SUM(ieps_retenido) as total_ieps_retenido,
                    COUNT(*) as total_ops,
                    SUM(gross_amount) as total_bruto,
                    SUM(vendor_amount) as total_neto
             FROM `{$c_table}`
             WHERE created_at BETWEEN %s AND %s
             GROUP BY vendor_id",
            $date_from . ' 00:00:00', $date_to . ' 23:59:59'
        ), ARRAY_A );

        if ( empty( $vendors ) ) return [ 'error' => 'Sin datos' ];

        $certificates = [];

        foreach ( $vendors as $v ) {
            $vendor_id = (int) $v['vendor_id'];

            $cert = [
                'vendor_id'       => $vendor_id,
                'year'            => $year,
                'country'         => $country,
                'generated_at'    => current_time( 'mysql', true ),
                'total_operaciones' => (int) $v['total_ops'],
                'total_bruto'     => round( (float) $v['total_bruto'], 2 ),
                'total_neto'      => round( (float) $v['total_neto'], 2 ),
            ];

            if ( $country === 'CO' ) {
                // Certificado de retenciones Colombia.
                $cert['retefuente'] = round( (float) $v['total_retefuente'], 2 );
                $cert['reteiva']    = round( (float) 0, 2 ); // ReteIVA se calcula en tax engine, no en commissions.
                $cert['reteica']    = round( (float) 0, 2 ); // ReteICA se calcula en tax engine.
                $cert['iva_trasladado'] = round( (float) $v['total_iva'], 2 );

                // GMF acumulado del año.
                $gmf_cert = get_user_meta( $vendor_id, '_ltms_gmf_cert_' . $year, true );
                $cert['gmf_4x1000'] = $gmf_cert ? round( (float) $gmf_cert['total'], 2 ) : 0.0;

                $cert['norma'] = 'Estatuto Tributario art. 381 + Res. DIAN 0227/2020';
            } else {
                // Constancia de retenciones México.
                $cert['isr_retenido']    = round( (float) $v['total_retefuente'], 2 ); // ISR = tax_withholding.
                $cert['iva_retenido']    = round( (float) $v['total_iva_retenido'], 2 );
                $cert['ieps_retenido']   = round( (float) $v['total_ieps_retenido'], 2 );
                $cert['iva_trasladado']  = round( (float) $v['total_iva'], 2 );
                $cert['ieps_trasladado'] = round( (float) $v['total_ieps'], 2 );
                $cert['norma'] = 'LISR art. 118 + CFF art. 29-A';
            }

            // Guardar certificado en user_meta.
            update_user_meta( $vendor_id, '_ltms_withholding_cert_' . $year, $cert );

            $certificates[] = $cert;

            // Enviar email al vendor notificando que su certificado está disponible.
            self::notify_cert_available( $vendor_id, $year, $cert );
        }

        // Guardar resumen general.
        $summary = [
            'year'            => $year,
            'total_vendors'   => count( $certificates ),
            'total_bruto'     => round( array_sum( array_column( $certificates, 'total_bruto' ) ), 2 ),
            'total_neto'      => round( array_sum( array_column( $certificates, 'total_neto' ) ), 2 ),
            'generated_at'    => current_time( 'mysql', true ),
        ];
        update_option( 'ltms_fiscal_close_' . $year, $summary, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FISCAL_ANNUAL_CLOSE',
                sprintf( 'Cierre fiscal %d: %d certificados generados. Bruto total=$%.2f', $year, count( $certificates ), $summary['total_bruto'] )
            );
        }

        return [ 'summary' => $summary, 'certificates' => $certificates ];
    }

    /**
     * Notifica al vendor que su certificado de retenciones está disponible.
     */
    private static function notify_cert_available( int $vendor_id, int $year, array $cert ): void {
        $user = get_userdata( $vendor_id );
        if ( ! $user || ! $user->user_email ) return;

        $country = LTMS_Core_Config::get_country();
        $subject = $country === 'MX'
            ? sprintf( '[Lo Tengo] Constancia de retenciones %d disponible', $year )
            : sprintf( '[Lo Tengo] Certificado de retenciones %d disponible', $year );

        $body = sprintf(
            __( "Hola %s,\n\nTu certificado de retenciones del año %d está disponible en tu panel de vendedor.\n\nResumen:\n- Total operaciones: %d\n- Ingreso bruto: $%.2f\n- Ingreso neto: $%.2f\n\nPuedes descargarlo desde: Panel → Billetera → Certificado de Retenciones\n\nEste documento es válido para tu declaración de %s.\n\n— Equipo Lo Tengo", 'ltms' ),
            $user->display_name,
            $year,
            $cert['total_operazioni'] ?? $cert['total_operaciones'],
            $cert['total_bruto'],
            $cert['total_neto'],
            $country === 'MX' ? 'ISR anual' : 'renta'
        );

        wp_mail( $user->user_email, $subject, $body );
    }

    /**
     * AJAX: generar certificado manualmente.
     */
    public static function ajax_generate_cert(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        $year = (int) ( $_POST['year'] ?? 0 );
        $result = self::generate_annual_withholding_certificates( $year );
        wp_send_json_success( $result );
    }

    /**
     * AJAX: descargar certificado de un vendor.
     */
    public static function ajax_download_cert(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
        $year = (int) ( $_POST['year'] ?? 0 );

        if ( ! $vendor_id || ! $year ) {
            wp_send_json_error( [ 'message' => 'Faltan parámetros' ] );
        }

        $cert = get_user_meta( $vendor_id, '_ltms_withholding_cert_' . $year, true );
        if ( ! $cert ) {
            wp_send_json_error( [ 'message' => 'Certificado no encontrado' ] );
        }

        wp_send_json_success( [ 'certificate' => $cert ] );
    }

    // ================================================================
    // LF-5: PAC ADAPTERS PARA CFDI MÉXICO
    // ================================================================

    /**
     * Procesa el timbrado CFDI via PAC (Proveedor Autorizado de Certificación).
     *
     * SAT requiere que los CFDI sean timbrados por un PAC autorizado.
     * El marketplace puede actuar como intermediario enviando el XML al PAC.
     *
     * PACs soportados:
     *  - Facturama (https://api.facturama.mx)
     *  - SW Sapien (https://sw.sw.com.mx)
     *  - Edicom (https://api.edicom.mx)
     *
     * @param int    $order_id
     * @param string $xml_base64  XML del CFDI 4.0 en base64.
     * @param array  $cfdi_data   Datos del CFDI (emisor, receptor, conceptos).
     */
    public static function process_cfdi_via_pac( int $order_id, string $xml_base64, array $cfdi_data ): array {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'MX' ) return [ 'success' => false, 'error' => 'Solo México' ];

        $pac_provider = LTMS_Core_Config::get( 'ltms_mx_pac_provider', '' );
        if ( ! $pac_provider ) {
            // Sin PAC configurado: el vendor debe timbrar por su cuenta.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'CFDI_NO_PAC',
                    sprintf( 'Order #%d: sin PAC configurado. El vendor debe timbrar manualmente.', $order_id )
                );
            }
            return [ 'success' => false, 'error' => 'No PAC configured' ];
        }

        $pac_token = LTMS_Core_Config::get( 'ltms_mx_pac_token', '' );
        if ( ! $pac_token ) {
            return [ 'success' => false, 'error' => 'No PAC token' ];
        }

        // Dispatch al PAC correspondiente.
        switch ( strtolower( $pac_provider ) ) {
            case 'facturama':
                return self::timbrar_via_facturama( $order_id, $xml_base64, $pac_token );
            case 'sw_sapien':
            case 'swsapien':
                return self::timbrar_via_sw_sapien( $order_id, $xml_base64, $pac_token );
            case 'edicom':
                return self::timbrar_via_edicom( $order_id, $xml_base64, $pac_token );
            default:
                return [ 'success' => false, 'error' => 'PAC no soportado: ' . $pac_provider ];
        }
    }

    /**
     * Timbra CFDI via Facturama.
     */
    private static function timbrar_via_facturama( int $order_id, string $xml_base64, string $token ): array {
        $url = 'https://api.facturama.mx/cfdi33/issue/json';

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $token ),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'xml' => $xml_base64 ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['uuid'] ) ) {
            // Guardar UUID en la orden.
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_cfdi_uuid', sanitize_text_field( $body['uuid'] ) );
                $order->update_meta_data( '_ltms_cfdi_pac', 'facturama' );
                $order->save();
            }

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'CFDI_TIMBRADO_OK',
                    sprintf( 'Order #%d: CFDI timbrado via Facturama. UUID=%s', $order_id, $body['uuid'] )
                );
            }

            return [ 'success' => true, 'uuid' => $body['uuid'], 'pac' => 'facturama' ];
        }

        return [ 'success' => false, 'error' => $body['message'] ?? 'Error desconocido Facturama' ];
    }

    /**
     * Timbra CFDI via SW Sapien.
     */
    private static function timbrar_via_sw_sapien( int $order_id, string $xml_base64, string $token ): array {
        $url = 'https://sw.sw.com.mx/api/v3/cfdi33/issue/json/v1';

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'xml' => $xml_base64 ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['data']['uuid'] ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_cfdi_uuid', sanitize_text_field( $body['data']['uuid'] ) );
                $order->update_meta_data( '_ltms_cfdi_pac', 'sw_sapien' );
                $order->save();
            }

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'CFDI_TIMBRADO_OK',
                    sprintf( 'Order #%d: CFDI timbrado via SW Sapien. UUID=%s', $order_id, $body['data']['uuid'] )
                );
            }

            return [ 'success' => true, 'uuid' => $body['data']['uuid'], 'pac' => 'sw_sapien' ];
        }

        return [ 'success' => false, 'error' => $body['message'] ?? 'Error desconocido SW Sapien' ];
    }

    /**
     * Timbra CFDI via Edicom.
     */
    private static function timbrar_via_edicom( int $order_id, string $xml_base64, string $token ): array {
        $url = 'https://api.edicom.mx/cfdi/issue';

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'xml' => $xml_base64 ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['uuid'] ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_ltms_cfdi_uuid', sanitize_text_field( $body['uuid'] ) );
                $order->update_meta_data( '_ltms_cfdi_pac', 'edicom' );
                $order->save();
            }

            return [ 'success' => true, 'uuid' => $body['uuid'], 'pac' => 'edicom' ];
        }

        return [ 'success' => false, 'error' => $body['message'] ?? 'Error desconocido Edicom' ];
    }
}
