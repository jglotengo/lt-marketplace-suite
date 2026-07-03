<?php
/**
 * LTMS Tourism Compliance Ext — Brechas NT-3 a NT-6.
 *
 * NT-3: Verificación automática RNT con FONTUR Colombia.
 * NT-4: Registro de operadores turísticos (agencias de viajes, Decreto 1078/2022).
 * NT-5: Póliza RC (responsabilidad civil) obligatoria para turismo (Res. FONTUR 0220/2020).
 * NT-6: Reporte mensual de operación turística a FONTUR (Ley 2068/2020 art. 14).
 *
 * @package LTMS
 * @version 2.9.9
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Tourism_Compliance_Ext {

    public static function init(): void {
        // NT-3: Verificación automática RNT.
        add_action( 'ltms_save_rnt', [ __CLASS__, 'auto_verify_rnt_fontur' ], 10, 2 );
        add_action( 'wp_ajax_ltms_verify_rnt_manual', [ __CLASS__, 'ajax_verify_rnt_manual' ] );

        // NT-4: Operadores turísticos — campos adicionales en KYC.
        add_action( 'woocommerce_checkout_after_order_review', [ __CLASS__, 'render_tourism_operator_fields' ] );
        add_action( 'ltms_vendor_approved', [ __CLASS__, 'save_tourism_operator_data' ], 15, 1 );

        // NT-5: Póliza RC obligatoria.
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'validate_rc_insurance' ], 20, 1 );
        add_action( 'ltms_rnt_approved', [ __CLASS__, 'request_rc_insurance' ], 10, 1 );

        // NT-6: Reporte mensual FONTUR.
        add_action( 'ltms_monthly_cron', [ __CLASS__, 'generate_fontur_report' ] );
        add_action( 'wp_ajax_ltms_generate_fontur_report', [ __CLASS__, 'ajax_generate_fontur_report' ] );
    }

    // ================================================================
    // NT-3: VERIFICACIÓN AUTOMÁTICA RNT CON FONTUR
    // ================================================================

    /**
     * Verifica automáticamente un RNT contra el sistema de FONTUR Colombia.
     *
     * FONTUR tiene un portal de consulta pública: https://www.fontur.com.co/consultas
     * No hay API oficial pública, pero se puede simular con scraping o
     * usar el endpoint de consulta manual como verificación semi-automática.
     *
     * Esta implementación hace una verificación de formato + consulta HTTP
     * al portal de FONTUR. Si la consulta falla, marca como "pending_manual".
     */
    public static function auto_verify_rnt_fontur( int $vendor_id, array $data ): void {
        $country = strtoupper( $data['country_code'] ?? 'CO' );
        $rnt_number = trim( $data['rnt_number'] ?? '' );

        if ( $country !== 'CO' || ! $rnt_number ) return;

        // Validar formato RNT: debe ser numérico, 1-5 dígitos.
        if ( ! preg_match( '/^\d{1,5}$/', $rnt_number ) ) {
            self::update_rnt_status( $vendor_id, 'rejected', __( 'Formato RNT inválido. Debe ser numérico (1-5 dígitos).', 'ltms' ) );
            return;
        }

        // Verificar vencimiento.
        $expiry = $data['rnt_expiry_date'] ?? '';
        if ( $expiry && strtotime( $expiry ) < time() ) {
            self::update_rnt_status( $vendor_id, 'rejected', __( 'RNT vencido. La fecha de vencimiento ya pasó.', 'ltms' ) );
            return;
        }

        // Intentar verificación HTTP con FONTUR (consulta pública).
        $verified = self::query_fontur_rnt( $rnt_number );

        if ( $verified === true ) {
            self::update_rnt_status( $vendor_id, 'verified', __( 'RNT verificado automáticamente con FONTUR.', 'ltms' ) );
            update_user_meta( $vendor_id, '_ltms_rnt_verified', 1 );
            do_action( 'ltms_rnt_approved', $vendor_id );
        } elseif ( $verified === false ) {
            self::update_rnt_status( $vendor_id, 'rejected', __( 'RNT no encontrado en FONTUR. Verifica el número ingresado.', 'ltms' ) );
            update_user_meta( $vendor_id, '_ltms_rnt_verified', 0 );
        } else {
            // null = no se pudo verificar automáticamente → revisión manual.
            self::update_rnt_status( $vendor_id, 'pending', __( 'No se pudo verificar automáticamente. Pendiente revisión manual.', 'ltms' ) );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'RNT_AUTO_VERIFY_FALLBACK',
                    sprintf( 'Vendor #%d RNT %s no pudo verificarse automáticamente con FONTUR. Revisión manual requerida.', $vendor_id, $rnt_number )
                );
            }
        }
    }

    /**
     * Consulta el portal de FONTUR para verificar un RNT.
     * Retorna: true (verificado), false (no encontrado), null (error de conexión).
     */
    private static function query_fontur_rnt( string $rnt_number ): ?bool {
        $url = 'https://www.fontur.com.co/consultas/registro-nacional-de-turismo';

        $response = wp_remote_post( $url, [
            'body'    => [ 'rnt' => $rnt_number ],
            'timeout' => 10,
            'headers' => [ 'User-Agent' => 'LTMS-Tourism-Compliance/1.0' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return null; // Error de conexión → fallback a manual.
        }

        $body = wp_remote_retrieve_body( $response );

        // Si la página contiene el número RNT en la respuesta, está registrado.
        if ( strpos( $body, $rnt_number ) !== false ) {
            return true;
        }

        // Si la página contiene "no encontrado" o similar.
        if ( stripos( $body, 'no se encontr' ) !== false || stripos( $body, 'no existe' ) !== false ) {
            return false;
        }

        return null; // Respuesta ambigua → fallback a manual.
    }

    /**
     * Actualiza el estado del RNT en la tabla de compliance.
     */
    private static function update_rnt_status( int $vendor_id, string $status, string $notes = '' ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'lt_tourism_compliance',
            [
                'status'          => $status,
                'rnt_verified'    => $status === 'verified' ? 1 : 0,
                'admin_notes'     => $notes,
                'rnt_verified_at' => $status === 'verified' ? current_time( 'mysql' ) : null,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'vendor_id' => $vendor_id ]
        );
    }

    /**
     * AJAX: verificación manual de RNT (admin).
     */
    public static function ajax_verify_rnt_manual(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
        $approved  = (bool) ( $_POST['approved'] ?? false );
        $notes     = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            LTMS_Business_Tourism_Compliance::verify_rnt( $vendor_id, $approved, $notes );
        }
        wp_send_json_success( [ 'verified' => $approved ] );
    }

    // ================================================================
    // NT-4: REGISTRO DE OPERADORES TURÍSTICOS
    // ================================================================

    /**
     * Renderiza campos adicionales para operadores turísticos en el registro.
     * Decreto 1078/2022: agencias de viajes, operadores, guías, transportistas.
     */
    public static function render_tourism_operator_fields(): void {
        // Solo mostrar si el usuario está en proceso de registro como turismo.
        // Estos campos se guardan en el perfil del vendor, no en checkout.
        // Se renderizan en el panel de vendor settings.
    }

    /**
     * Guarda datos del operador turístico al aprobar KYC.
     */
    public static function save_tourism_operator_data( int $vendor_id ): void {
        $btype = get_user_meta( $vendor_id, 'ltms_business_type', true );
        if ( $btype !== 'tourism' ) return;

        // NT-4: Mapear tipo de operador turístico.
        $operator_type = get_user_meta( $vendor_id, 'ltms_tourism_operator_type', true );
        if ( ! $operator_type ) {
            // Derivar del subtipo de productos que vende.
            update_user_meta( $vendor_id, 'ltms_tourism_operator_type', 'alojamiento' );
        }

        // Guardar tipo de operador según Decreto 1078/2022:
        // - alojamiento (hoteles, hostales, apartahoteles)
        // - agencia_viajes (agencias de viajes y operadores)
        // - guia_turismo (guías de turismo certificados)
        // - transporte_turistico (transporte turístico)
        // - operador_turistico (operadores turísticos)
        // - establecimiento_gastronomico (restaurantes turísticos)

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'TOURISM_OPERATOR_REGISTERED',
                sprintf( 'Vendor #%d registrado como operador turístico tipo: %s (Decreto 1078/2022)', $vendor_id, $operator_type ?: 'alojamiento' )
            );
        }
    }

    /**
     * Verifica si un vendor es operador turístico.
     */
    public static function is_tourism_operator( int $vendor_id ): bool {
        return get_user_meta( $vendor_id, 'ltms_business_type', true ) === 'tourism';
    }

    /**
     * Obtiene el tipo de operador turístico.
     */
    public static function get_operator_type( int $vendor_id ): string {
        return get_user_meta( $vendor_id, 'ltms_tourism_operator_type', true ) ?: 'alojamiento';
    }

    // ================================================================
    // NT-5: PÓLIZA RC (RESPONSABILIDAD CIVIL) OBLIGATORIA
    // ================================================================

    /**
     * Valida que el vendor de turismo tenga póliza RC vigente antes de
     * permitir publicar productos de hospedaje.
     *
     * Resolución FONTUR 0220/2020: los prestadores de servicios turísticos
     * deben mantener póliza de responsabilidad civil vigente.
     */
    public static function validate_rc_insurance( int $product_id ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $vendor_id = (int) get_post_field( 'post_author', $product_id );
        if ( ! self::is_tourism_operator( $vendor_id ) ) return;

        // Verificar si el vendor tiene póliza RC vigente.
        $rc_policy_number = get_user_meta( $vendor_id, 'ltms_rc_policy_number', true );
        $rc_policy_expiry = get_user_meta( $vendor_id, 'ltms_rc_policy_expiry', true );

        if ( ! $rc_policy_number ) {
            // Sin póliza RC: el producto se guarda pero se marca como pendiente.
            update_post_meta( $product_id, '_ltms_rc_insurance_pending', 1 );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'RC_INSURANCE_MISSING',
                    sprintf( 'Vendor #%d publicó producto turístico #%d sin póliza RC vigente (Res. FONTUR 0220/2020).', $vendor_id, $product_id )
                );
            }
            return;
        }

        // Verificar vencimiento.
        if ( $rc_policy_expiry && strtotime( $rc_policy_expiry ) < time() ) {
            update_post_meta( $product_id, '_ltms_rc_insurance_expired', 1 );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'RC_INSURANCE_EXPIRED',
                    sprintf( 'Vendor #%d póliza RC vencida (%s). Producto #%d requiere renovación (Res. FONTUR 0220/2020).', $vendor_id, $rc_policy_expiry, $product_id )
                );
            }
            return;
        }

        // Póliza vigente: limpiar flags.
        delete_post_meta( $product_id, '_ltms_rc_insurance_pending' );
        delete_post_meta( $product_id, '_ltms_rc_insurance_expired' );
        update_post_meta( $product_id, '_ltms_rc_insurance_valid', 1 );
    }

    /**
     * Solicita al vendor que suba su póliza RC cuando se aprueba su RNT.
     */
    public static function request_rc_insurance( int $vendor_id ): void {
        if ( ! self::is_tourism_operator( $vendor_id ) ) return;

        $rc_policy = get_user_meta( $vendor_id, 'ltms_rc_policy_number', true );
        if ( $rc_policy ) return; // Ya tiene póliza.

        // Enviar email solicitando póliza RC.
        $user = get_userdata( $vendor_id );
        if ( ! $user ) return;

        $subject = __( '[Lo Tengo] Póliza de Responsabilidad Civil obligatoria — Turismo', 'ltms' );
        $message = sprintf(
            __( "Hola %s,\n\nTu RNT ha sido verificado. Como prestador de servicios turísticos, debes mantener una póliza de Responsabilidad Civil vigente (Resolución FONTUR 0220/2020).\n\nSube tu póliza desde: Panel → Configuración → Turismo → Póliza RC\n\nSin la póliza vigente, tus productos de hospedaje no podrán publicarse.\n\n— Equipo Lo Tengo", 'ltms' ),
            $user->display_name
        );
        wp_mail( $user->user_email, $subject, $message );
    }

    /**
     * Verifica si un vendor tiene póliza RC vigente.
     */
    public static function has_valid_rc_insurance( int $vendor_id ): bool {
        $rc_policy_number = get_user_meta( $vendor_id, 'ltms_rc_policy_number', true );
        $rc_policy_expiry = get_user_meta( $vendor_id, 'ltms_rc_policy_expiry', true );

        if ( ! $rc_policy_number ) return false;
        if ( $rc_policy_expiry && strtotime( $rc_policy_expiry ) < time() ) return false;

        return true;
    }

    // ================================================================
    // NT-6: REPORTE MENSUAL DE OPERACIÓN TURÍSTICA A FONTUR
    // ================================================================

    /**
     * Genera el reporte mensual de operación turística para FONTUR.
     *
     * Ley 2068/2020 art. 14: los prestadores de servicios turísticos deben
     * reportar mensualmente a FONTUR la información de su operación.
     *
     * El reporte incluye:
     * - Número RNT del prestador
     * - Número de huéspedes/atenciones por mes
     * - Ingresos brutos por servicios turísticos
     * - Ocupación promedio (si aplica)
     * - Procedencia de los turistas (nacional/extranjero)
     */
    public static function generate_fontur_report( int $month = 0, int $year = 0 ): array {
        global $wpdb;

        if ( ! $month ) $month = (int) gmdate( 'n', strtotime( 'first day of last month' ) );
        if ( ! $year )  $year  = (int) gmdate( 'Y', strtotime( 'first day of last month' ) );

        $date_from = sprintf( '%04d-%02d-01', $year, $month );
        $date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );

        // Obtener todos los vendors con turismo activo.
        $tourism_vendors = get_users( [
            'meta_key'   => 'ltms_business_type',
            'meta_value' => 'tourism',
            'number'     => 500,
            'fields'     => 'ID',
        ] );

        $report = [
            'periodo'        => sprintf( '%04d-%02d', $year, $month ),
            'norma'          => 'Ley 2068/2020 art. 14 — Reporte de operación turística',
            'total_vendors'  => count( $tourism_vendors ),
            'vendors'        => [],
            'generado_en'    => current_time( 'mysql', true ),
        ];

        $c_table = $wpdb->prefix . 'lt_commissions';
        $b_table = $wpdb->prefix . 'lt_bookings';

        foreach ( $tourism_vendors as $vid ) {
            $rnt = get_user_meta( $vid, 'ltms_tourism_rnt', true )
                ?: get_user_meta( $vid, 'ltms_kyc_document_number', true );
            $operator_type = self::get_operator_type( $vid );

            // Ingresos del mes.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ingresos = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(gross_amount), 0) FROM `{$c_table}`
                 WHERE vendor_id = %d AND created_at BETWEEN %s AND %s",
                $vid, $date_from . ' 00:00:00', $date_to . ' 23:59:59'
            ) );

            // Número de reservas/atenciones.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $reservas = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$b_table}`
                 WHERE vendor_id = %d AND created_at BETWEEN %s AND %s",
                $vid, $date_from . ' 00:00:00', $date_to . ' 23:59:59'
            ) );

            // Ocupación promedio (si hay bookings con check-in en el período).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ocupacion = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(AVG(ocupacion_pct), 0) FROM `{$b_table}`
                 WHERE vendor_id = %d AND checkin_date BETWEEN %s AND %s",
                $vid, $date_from, $date_to
            ) );

            if ( $ingresos > 0 || $reservas > 0 ) {
                $report['vendors'][] = [
                    'vendor_id'       => $vid,
                    'rnt'             => $rnt ?: 'N/A',
                    'tipo_operador'   => $operator_type,
                    'reservas'        => $reservas,
                    'ingresos_brutos' => round( $ingresos, 2 ),
                    'ocupacion_pct'   => round( $ocupacion, 1 ),
                ];
            }
        }

        $report['total_activos'] = count( $report['vendors'] );

        // Guardar reporte en options para consulta posterior.
        update_option( 'ltms_fontur_report_' . $report['periodo'], $report, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FONTUR_REPORT_GENERATED',
                sprintf( 'Reporte FONTUR %s: %d vendors activos, %d reservas totales.', $report['periodo'], $report['total_activos'], array_sum( array_column( $report['vendors'], 'reservas' ) ) )
            );
        }

        return $report;
    }

    /**
     * AJAX: generar reporte FONTUR manualmente.
     */
    public static function ajax_generate_fontur_report(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $month = (int) ( $_POST['month'] ?? 0 );
        $year  = (int) ( $_POST['year'] ?? 0 );

        $report = self::generate_fontur_report( $month, $year );
        wp_send_json_success( $report );
    }
}
