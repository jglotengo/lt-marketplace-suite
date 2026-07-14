<?php
class LTMS_Bank_Reconciler {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        $instance = new self();
        add_action( 'wp_ajax_ltms_import_bank_statement', [ $instance, 'ajax_import_statement' ] );
        add_action( 'wp_ajax_ltms_get_reconciliation',    [ $instance, 'ajax_get_reconciliation' ] );
        add_action( 'wp_ajax_ltms_mark_reconciled',       [ $instance, 'ajax_mark_reconciled' ] );
    }

    /**
     * AJAX: importa extracto bancario en CSV.
     *
     * @return void
     */
    public function ajax_import_statement(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        if ( empty( $_FILES['csv_file'] ) ) { // phpcs:ignore
            wp_send_json_error( __( 'No se recibió archivo CSV.', 'ltms' ) );
        }

        $tmp = sanitize_text_field( $_FILES['csv_file']['tmp_name'] ); // phpcs:ignore
        if ( ! file_exists( $tmp ) ) {
            wp_send_json_error( __( 'Error al subir el archivo.', 'ltms' ) );
        }

        // B2 FIX: validar MIME type para prevenir upload de archivos maliciosos.
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $tmp );
        finfo_close( $finfo );
        $allowed_mimes = [ 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ];
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_send_json_error( sprintf( __( 'Tipo de archivo no permitido: %s. Solo CSV.', 'ltms' ), $mime ) );
        }

        $rows    = [];
        $handle  = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        if ( ! $handle ) {
            wp_send_json_error( __( 'No se pudo abrir el archivo CSV.', 'ltms' ) );
        }

        $headers = fgetcsv( $handle );

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) >= 3 ) {
                $raw_amount = $row[2] ?? '0';
                $rows[] = [
                    'date'        => sanitize_text_field( $row[0] ?? '' ),
                    'description' => sanitize_text_field( $row[1] ?? '' ),
                    'amount'      => self::parse_bank_amount( $raw_amount ),
                    'reference'   => sanitize_text_field( $row[3] ?? '' ),
                    'raw_amount'  => sanitize_text_field( $raw_amount ),
                ];
            }
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

        // B3 FIX: limitar número de filas para evitar OOM en archivos grandes.
        if ( count( $rows ) > 5000 ) {
            wp_send_json_error( __( 'El CSV excede 5000 filas. Divídelo en archivos más pequeños.', 'ltms' ) );
        }

        // Almacenar en sesión transitoria para la reconciliación.
        set_transient( 'ltms_bank_import_' . get_current_user_id(), $rows, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'rows_imported' => count( $rows ),
            'message'       => sprintf( __( '%d transacciones importadas.', 'ltms' ), count( $rows ) ),
        ] );
    }

    /**
     * B1 FIX: parsea montos bancarios en formato colombiano o mexicano.
     *
     * Formatos soportados:
     *  - "1.234.567,89" (Colombia) → 1234567.89
     *  - "1,234,567.89" (México/US) → 1234567.89
     *  - "1234567.89" → 1234567.89
     *  - "1234567,89" → 1234567.89
     *  - "-50000"     → -50000.0 (débito)
     *  - "(50000)"    → -50000.0 (débito en formato contable)
     *
     * @param string $raw
     * @return float
     */
    public static function parse_bank_amount( string $raw ): float {
        $raw = trim( $raw );
        if ( $raw === '' ) return 0.0;

        // Detectar formato contable negativo: (1234.56).
        $is_negative = false;
        if ( preg_match( '/^\((.+)\)$/', $raw, $m ) ) {
            $is_negative = true;
            $raw = $m[1];
        } elseif ( strpos( $raw, '-' ) === 0 ) {
            $is_negative = true;
            $raw = ltrim( $raw, '-' );
        }

        // Remover símbolos de moneda y espacios.
        $raw = str_replace( [ '$', 'COP', 'MXN', 'USD', ' ', "\t", "\xc2\xa0" ], '', $raw );

        // Detectar formato: si tiene ',' como último separador decimal → formato CO.
        // Si tiene '.' como último separador decimal → formato MX/US.
        $has_comma = strrpos( $raw, ',' );
        $has_dot   = strrpos( $raw, '.' );

        if ( $has_comma !== false && $has_dot !== false ) {
            // Ambos presentes: el que aparece último es el decimal.
            if ( $has_comma > $has_dot ) {
                // Formato CO: 1.234.567,89 → quitar puntos, reemplazar coma por punto.
                $raw = str_replace( '.', '', $raw );
                $raw = str_replace( ',', '.', $raw );
            } else {
                // Formato MX/US: 1,234,567.89 → quitar comas.
                $raw = str_replace( ',', '', $raw );
            }
        } elseif ( $has_comma !== false ) {
            // Solo coma → decimal (formato CO sin miles).
            $raw = str_replace( ',', '.', $raw );
        }
        // Si solo punto o ninguno → ya está en formato correcto.

        $amount = (float) $raw;
        return $is_negative ? -$amount : $amount;
    }

    /**
     * AJAX: ejecuta la reconciliación y devuelve el reporte de diferencias.
     *
     * B4 FIX: algoritmo de matching mejorado:
     *  - 3 niveles de matching: reference exacta → monto+cercanía fecha → solo monto.
     *  - Marca filas bancarias ya usadas (evita matchear 1 row contra N payouts).
     *  - Detecta transacciones bancarias SIN payout correspondiente (extra deposits).
     *  - Tolerancia configurable para monto (default $1).
     *
     * @return void
     */
    public function ajax_get_reconciliation(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        // v2.9.115 PAYOUT-AUDIT P2-3 FIX: use ltms_manage_platform_settings for consistency
        // with ajax_import_statement and ajax_mark_reconciled. Before, this endpoint used
        // ltms_access_dashboard (a broader cap) — inconsistent with the other reconciliation
        // endpoints, allowing users with dashboard access to view reconciliation data but
        // not import statements or mark reconciled.
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $bank_rows = get_transient( 'ltms_bank_import_' . get_current_user_id() );
        if ( ! $bank_rows ) {
            wp_send_json_error( __( 'No hay datos bancarios importados. Sube un CSV primero.', 'ltms' ) );
        }

        $date_from = sanitize_text_field( $_POST['date_from'] ?? gmdate( 'Y-m-01' ) );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? gmdate( 'Y-m-d' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';

        // B4 FIX: el status original era 'paid' pero el scheduler marca como 'completed'.
        // Incluir ambos para soportar ambos esquemas.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ltms_payouts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status IN ('completed','paid')
             AND (processed_at BETWEEN %s AND %s OR requested_at BETWEEN %s AND %s)",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59',
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ) );

        // Tolerancia de monto (configurable).
        $amount_tolerance = (float) LTMS_Core_Config::get( 'ltms_reconcile_amount_tolerance', 1.0 );

        // B4 FIX: algoritmo de matching en 3 niveles.
        // 1. Match por reference exacta (más confiable).
        // 2. Match por monto + cercanía de fecha (±3 días).
        // 3. Match por monto único (sin otros payouts del mismo monto en el período).
        $matched   = [];
        $unmatched = [];
        $used_bank_indices = []; // Índices de bank_rows ya matcheados.

        // Nivel 1: match por reference.
        foreach ( $ltms_payouts as $payout ) {
            $payout_ref = trim( $payout->reference ?? '' );
            $payout_gw_ref = trim( $payout->gateway_ref ?? '' );
            if ( ! $payout_ref && ! $payout_gw_ref ) continue;

            foreach ( $bank_rows as $idx => $row ) {
                if ( isset( $used_bank_indices[ $idx ] ) ) continue;
                $row_ref = trim( $row['reference'] ?? '' );
                if ( ! $row_ref ) continue;
                if ( $payout_ref === $row_ref || $payout_gw_ref === $row_ref ) {
                    $matched[] = [
                        'payout'    => $payout,
                        'bank_row'  => $row,
                        'match_by'  => 'reference',
                        'confidence'=> 'high',
                    ];
                    $used_bank_indices[ $idx ] = true;
                    continue 2; // Siguiente payout.
                }
            }
        }

        // Nivel 2: match por monto + cercanía de fecha (±3 días).
        $date_tolerance_days = (int) LTMS_Core_Config::get( 'ltms_reconcile_date_tolerance_days', 3 );

        // Construir set de payout_ids ya matcheados en nivel 1.
        $matched_payout_ids = [];
        foreach ( $matched as $m ) {
            $matched_payout_ids[ $m['payout']->id ] = true;
        }

        foreach ( $ltms_payouts as $payout ) {
            // Saltar si ya fue matcheado en nivel 1.
            if ( isset( $matched_payout_ids[ $payout->id ] ) ) continue;

            $payout_amount = (float) $payout->amount;
            $payout_date = strtotime( $payout->requested_at ?? $payout->processed_at ?? '' );

            $best_idx = null;
            $best_diff = PHP_INT_MAX;
            foreach ( $bank_rows as $idx => $row ) {
                if ( isset( $used_bank_indices[ $idx ] ) ) continue;
                $row_amount = abs( (float) $row['amount'] );
                if ( abs( $payout_amount - $row_amount ) > $amount_tolerance ) continue;

                // Verificar cercanía de fecha si ambas tienen.
                $row_date_str = $row['date'] ?? '';
                if ( $payout_date && $row_date_str ) {
                    $row_date = strtotime( $row_date_str );
                    if ( $row_date ) {
                        $days_diff = abs( ( $payout_date - $row_date ) / DAY_IN_SECONDS );
                        if ( $days_diff > $date_tolerance_days ) continue;
                        if ( $days_diff < $best_diff ) {
                            $best_diff = $days_diff;
                            $best_idx = $idx;
                        }
                    }
                } else {
                    // Sin fecha para comparar, aceptar por monto.
                    $best_idx = $idx;
                    break;
                }
            }

            if ( $best_idx !== null ) {
                $matched[] = [
                    'payout'    => $payout,
                    'bank_row'  => $bank_rows[ $best_idx ],
                    'match_by'  => 'amount+date',
                    'confidence'=> $best_diff <= 1 ? 'high' : 'medium',
                ];
                $used_bank_indices[ $best_idx ] = true;
            } else {
                $unmatched[] = $payout;
            }
        }

        // B4 FIX: detectar transacciones bancarias SIN payout (extra deposits).
        $extra = [];
        foreach ( $bank_rows as $idx => $row ) {
            if ( ! isset( $used_bank_indices[ $idx ] ) ) {
                $extra[] = $row;
            }
        }

        wp_send_json_success( [
            'matched'         => count( $matched ),
            'unmatched'       => count( $unmatched ),
            'extra_deposits'  => count( $extra ),
            'unmatched_items' => $unmatched,
            'extra_items'     => array_slice( $extra, 0, 20 ),
            'matched_details' => array_map( function( $m ) {
                return [
                    'payout_id'  => $m['payout']->id,
                    'vendor_id'  => $m['payout']->vendor_id,
                    'amount'     => (float) $m['payout']->amount,
                    'match_by'   => $m['match_by'],
                    'confidence' => $m['confidence'],
                ];
            }, $matched ),
            'summary'     => sprintf(
                __( '%d de %d pagos conciliados. %d sin conciliar. %d depósitos extra detectados.', 'ltms' ),
                count( $matched ),
                count( $ltms_payouts ),
                count( $unmatched ),
                count( $extra )
            ),
        ] );
    }

    /**
     * AJAX: marca un pago como conciliado manualmente.
     *
     * @return void
     */
    public function ajax_mark_reconciled(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = absint( $_POST['payout_id'] ?? 0 );
        if ( ! $payout_id ) {
            wp_send_json_error( __( 'ID de pago requerido.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_payout_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, [ 'reconciled' => 1, 'reconciled_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $payout_id ], [ '%d', '%s' ], [ '%d' ] );

        wp_send_json_success( [ 'message' => __( 'Pago marcado como conciliado.', 'ltms' ) ] );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// LEGAL EVIDENCE HANDLER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Legal_Evidence_Handler
 *
 * Gestiona la recopilación y almacenamiento de evidencias legales:
 * snapshots de pedidos, contratos firmados, logs de acceso y exportaciones
 * para cumplimiento de la Superintendencia de Industria y Comercio (SIC).
 */
