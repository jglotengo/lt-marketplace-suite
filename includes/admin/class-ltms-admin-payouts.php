<?php
/**
 * LTMS Admin Payouts - Controlador AJAX de Retiros
 *
 * Gestiona las acciones AJAX para la administración de retiros:
 * - Aprobar solicitudes de retiro
 * - Rechazar solicitudes de retiro
 * - Aprobar KYC de vendedores
 * - Congelar/descongelar billeteras
 * - Exportar reportes de retiros
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Payouts
 */
final class LTMS_Admin_Payouts {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks AJAX del controlador de retiros.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_approve_payout',  [ $instance, 'ajax_approve_payout' ] );
        add_action( 'wp_ajax_ltms_reject_payout',   [ $instance, 'ajax_reject_payout' ] );
        add_action( 'wp_ajax_ltms_approve_kyc',       [ $instance, 'ajax_approve_kyc' ] );
        add_action( 'wp_ajax_ltms_quick_approve_kyc', [ $instance, 'ajax_quick_approve_kyc' ] ); // A-5
        add_action( 'wp_ajax_ltms_reject_kyc',      [ $instance, 'ajax_reject_kyc' ] );
        add_action( 'wp_ajax_ltms_get_kyc_details', [ $instance, 'ajax_get_kyc_details' ] ); // Modal docs
        add_action( 'wp_ajax_ltms_freeze_wallet',   [ $instance, 'ajax_freeze_wallet' ] );
        add_action( 'wp_ajax_ltms_unfreeze_wallet', [ $instance, 'ajax_unfreeze_wallet' ] );
        add_action( 'wp_ajax_ltms_export_payouts',  [ $instance, 'ajax_export_payouts' ] );
    }

    /**
     * AJAX: Aprobar solicitud de retiro.
     *
     * @return void
     */
    public function ajax_approve_payout(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = (int) ( $_POST['payout_id'] ?? 0 ); // phpcs:ignore
        if ( ! $payout_id ) {
            wp_send_json_error( __( 'ID de retiro inválido.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::approve( $payout_id, get_current_user_id() );
        wp_send_json( $result );
    }

    /**
     * AJAX: Rechazar solicitud de retiro.
     *
     * @return void
     */
    public function ajax_reject_payout(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $payout_id = (int) ( $_POST['payout_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore

        if ( ! $payout_id || ! $reason ) {
            wp_send_json_error( __( 'Datos inválidos. El motivo es requerido.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::reject( $payout_id, $reason, get_current_user_id() );
        wp_send_json( $result );
    }

    /**
     * AJAX: Aprobar verificación KYC de un vendedor.
     *
     * @return void
     */
    public function ajax_approve_kyc(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $kyc_id    = (int) ( $_POST['kyc_id'] ?? 0 ); // phpcs:ignore
        $vendor_id = $this->get_vendor_id_by_kyc( $kyc_id );

        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'KYC no encontrado.', 'ltms' ) );
        }

        // RB-9 FIX (v2.9.19): Disparar filter ltms_kyc_pre_approve para que
        // los listeners (FT-2 screen_against_sanctions_lists, RT-2 validate_sanitary_registration)
        // puedan BLOQUEAR la aprobación si el vendor está en listas restrictivas o
        // no cumple requisitos sanitarios. Antes de este fix, FT-2 y RT-2 eran
        // silent dead code desde v2.9.14/16. Recibe (true, $vendor_id); retornar false bloquea.
        $country = class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_country() : 'CO';
        $allow   = (bool) apply_filters( 'ltms_kyc_pre_approve', true, $vendor_id );
        if ( ! $allow ) {
            LTMS_Core_Logger::warning(
                'KYC_APPROVE_BLOCKED_BY_FILTER',
                sprintf( 'KYC del vendedor #%d bloqueado por filter ltms_kyc_pre_approve (sanctions screening / sanitary reg / otros).', $vendor_id ),
                [ 'vendor_id' => $vendor_id, 'admin_id' => get_current_user_id(), 'country' => $country ]
            );
            wp_send_json_error( __( 'Aprobación bloqueada por política de cumplimiento (screening listas restrictivas o registro sanitario). Revisar logs.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'      => 'approved',
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $kyc_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        update_user_meta( $vendor_id, 'ltms_kyc_status', 'approved' );
        update_user_meta( $vendor_id, 'ltms_kyc_approved_at', LTMS_Utils::now_utc() );

        // L-1: Registrar acceso/revisión de documentos KYC en vault log (Ley 1581/2012 art. 8)
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_vault_access(
                $vendor_id,
                get_current_user_id(),
                'kyc_documents',
                'view',
                'admin_approve_kyc'
            );
        }

        // M-45: disparar ltms_vendor_approved para que listeners (tourism compliance, kernel) procesen la aprobación.
        do_action( 'ltms_vendor_approved', $vendor_id );

        LTMS_Core_Logger::security(
            'KYC_APPROVED',
            sprintf( 'KYC #%d del vendedor #%d aprobado por admin #%d', $kyc_id, $vendor_id, get_current_user_id() )
        );

        // E-14 FIX: email al vendedor cuando se aprueba KYC
        if ( get_option( 'ltms_email_kyc_approved', 'yes' ) === 'yes' ) {
            $vendor_user = get_userdata( $vendor_id );
            if ( $vendor_user && $vendor_user->user_email ) {
                $k_subject = __( '[Lo Tengo] ¡Tu identidad fue verificada exitosamente!', 'ltms' );
                $k_body    = sprintf(
                    /* translators: 1: nombre, 2: URL panel */
                    __( 'Hola %1$s,\n\n¡Buenas noticias! Tu verificación de identidad (KYC) fue aprobada.\n\nYa puedes acceder a todas las funcionalidades de tu panel de vendedor, incluyendo solicitudes de retiro.\n\nIngresa a tu panel:\n%2$s', 'ltms' ),
                    $vendor_user->display_name,
                    home_url( '/panel-vendedor/' )
                );
                wp_mail( $vendor_user->user_email, $k_subject, $k_body );
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: ID del vendedor */
                __( 'KYC del vendedor #%d aprobado exitosamente.', 'ltms' ),
                $vendor_id
            ),
        ]);
    }

    /**
     * AJAX: Aprobación rápida de KYC desde la lista de vendedores.
     * A-5 FIX: Permite aprobar directamente sin un documento KYC previo enviado.
     *
     * @return void
     */
    public function ajax_quick_approve_kyc(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        if ( ! $vendor_id || ! get_userdata( $vendor_id ) ) {
            wp_send_json_error( __( 'Vendedor no encontrado.', 'ltms' ) );
        }

        update_user_meta( $vendor_id, 'ltms_kyc_status', 'approved' );
        update_user_meta( $vendor_id, 'ltms_vendor_status', 'approved' );
        update_user_meta( $vendor_id, 'ltms_kyc_approved_at', LTMS_Utils::now_utc() );
        update_user_meta( $vendor_id, 'ltms_kyc_approved_by', get_current_user_id() );

        // Disparar acción para listeners (notificaciones, compliance, etc.)
        do_action( 'ltms_vendor_approved', $vendor_id );

        LTMS_Core_Logger::security(
            'KYC_QUICK_APPROVED',
            sprintf( 'Vendedor #%d aprobado rápidamente por admin #%d desde lista de vendedores', $vendor_id, get_current_user_id() )
        );

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: ID del vendedor */
                __( 'Vendedor #%d aprobado exitosamente.', 'ltms' ),
                $vendor_id
            ),
        ] );
    }

    /**
     * AJAX: Rechazar verificación KYC.
     *
     * @return void
     */
    public function ajax_reject_kyc(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $kyc_id    = (int) ( $_POST['kyc_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore
        $vendor_id = $this->get_vendor_id_by_kyc( $kyc_id );

        if ( ! $kyc_id || ! $reason || ! $vendor_id ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'       => 'rejected',
                'notes'        => $reason,
                'reviewed_by'  => get_current_user_id(),
                'reviewed_at'  => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $kyc_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        update_user_meta( $vendor_id, 'ltms_kyc_status', 'rejected' );

        // M-100: enviar email de notificación al vendedor cuando se rechaza KYC
        $vendor_user = get_userdata( $vendor_id );
        if ( $vendor_user ) {
            $subject = __( '[Lo Tengo] Tu verificación de identidad necesita correcciones', 'ltms' );
            $body    = sprintf(
                /* translators: 1: nombre, 2: motivo, 3: URL */
                __( "Hola %1\$s,\n\nTu solicitud de verificación de identidad fue revisada y necesita correcciones.\n\nMotivo: %2\$s\n\nPor favor ingresa a tu panel y envía una nueva solicitud con los documentos correctos:\n%3\$s\n\nSi tienes dudas, contáctanos.", 'ltms' ),
                $vendor_user->display_name,
                $reason,
                home_url( '/panel-vendedor/' )
            );
            wp_mail( $vendor_user->user_email, $subject, $body );
        }

        // M-100: disparar action para que otros listeners puedan reaccionar al rechazo
        do_action( 'ltms_vendor_kyc_rejected', $vendor_id, $reason );

        wp_send_json_success([
            'message' => __( 'KYC rechazado y vendedor notificado.', 'ltms' ),
        ]);
    }

    /**
     * AJAX: Congelar billetera de un vendedor por cumplimiento.
     *
     * @return void
     */
    public function ajax_freeze_wallet(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_freeze_wallets' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        $reason    = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore

        if ( ! $vendor_id || ! $reason ) {
            wp_send_json_error( __( 'Datos inválidos. El motivo es requerido.', 'ltms' ) );
        }

        try {
            LTMS_Business_Wallet::freeze( $vendor_id, $reason );
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: ID del vendedor */
                    __( 'Billetera del vendedor #%d congelada.', 'ltms' ),
                    $vendor_id
                ),
            ]);
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Descongelar billetera de un vendedor.
     *
     * @return void
     */
    public function ajax_unfreeze_wallet(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_freeze_wallets' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'ID de vendedor inválido.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_wallets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'is_frozen' => 0, 'freeze_reason' => null ],
            [ 'user_id' => $vendor_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        LTMS_Core_Logger::security(
            'WALLET_UNFROZEN',
            sprintf( 'Billetera del vendedor #%d descongelada por admin #%d', $vendor_id, get_current_user_id() )
        );

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: ID del vendedor */
                __( 'Billetera del vendedor #%d descongelada.', 'ltms' ),
                $vendor_id
            ),
        ]);
    }

    /**
     * AJAX: Exportar retiros a CSV.
     *
     * @return void
     */
    public function ajax_export_payouts(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_approve_payouts' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'lt_payout_requests';
        $status = sanitize_key( $_POST['status'] ?? '' ); // phpcs:ignore

        $where_sql = '';
        $args      = [];

        if ( $status ) {
            $where_sql = 'WHERE p.status = %s';
            $args[]    = $status;
        }

        $args[] = 1000; // LIMIT placeholder

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.user_email, u.display_name FROM `{$table}` p LEFT JOIN `{$wpdb->users}` u ON u.ID = p.vendor_id {$where_sql} ORDER BY p.created_at DESC LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );

        // Sanitize a CSV field: escape formula injection chars (=, +, -, @, TAB, CR).
        $csv_field = static function ( $v ): string {
            $v = (string) $v;
            if ( '' !== $v && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
                $v = "'" . $v;
            }
            return $v;
        };

        $csv  = "ID,Vendedor,Email,Monto,Fee,Neto,Método,Estado,Referencia,Fecha\n";
        foreach ( $payouts as $row ) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $csv_field( $row['id'] ),
                $csv_field( $row['display_name'] ),
                $csv_field( $row['user_email'] ),
                $csv_field( $row['amount'] ),
                $csv_field( $row['fee'] ),
                $csv_field( $row['net_amount'] ),
                $csv_field( $row['method'] ),
                $csv_field( $row['status'] ),
                $csv_field( $row['reference'] ),
                $csv_field( $row['created_at'] )
            );
        }

        LTMS_Core_Logger::info(
            'PAYOUTS_EXPORTED',
            sprintf( 'Exportación de %d retiros por admin #%d', count( $payouts ), get_current_user_id() )
        );

        wp_send_json_success([
            'csv'      => base64_encode( $csv ), // phpcs:ignore
            'filename' => 'ltms-retiros-' . gmdate( 'Y-m-d' ) . '.csv',
            'count'    => count( $payouts ),
        ]);
    }

    /**
     * AJAX: Devuelve los detalles completos de un registro KYC para el modal de admin.
     * Incluye URLs de documentos (cédula, RUT, Cámara) y datos del vendedor.
     * L-1: Registra acceso en vault log (Ley 1581/2012 art. 8).
     */
    public function ajax_get_kyc_details(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
        }

        $kyc_id = (int) ( $_POST['kyc_id'] ?? 0 );
        if ( ! $kyc_id ) {
            wp_send_json_error( [ 'message' => 'kyc_id requerido.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kyc = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT k.*, u.display_name, u.user_email, u.user_registered
                 FROM `{$table}` k
                 LEFT JOIN `{$wpdb->users}` u ON u.ID = k.vendor_id
                 WHERE k.id = %d",
                $kyc_id
            ),
            ARRAY_A
        );

        if ( ! $kyc ) {
            wp_send_json_error( [ 'message' => 'KYC no encontrado.' ] );
        }

        $vendor_id = (int) $kyc['vendor_id'];

        // L-1: Vault access log
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_vault_access(
                $vendor_id,
                get_current_user_id(),
                'kyc_documents_modal',
                'view',
                'admin_kyc_modal'
            );
        }

        // HD-7 (v2.9.21): Bitácora de acceso a datos personales (Ley 1581 art. 15).
        // Disparar hook ltms_personal_data_accessed para que LTMS_Data_Protection_Compliance
        // registre el acceso en lt_personal_data_access_log. Antes de este fix,
        // el listener estaba registrado pero NUNCA se disparaba → silent dead code.
        do_action( 'ltms_personal_data_accessed', $vendor_id, get_current_user_id(), 'kyc_documents', 'admin_kyc_modal' );

        // Construir URLs de documentos — leer todos los rows del vendor por document_type
        $kyc_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT document_type, file_path FROM `{$table}` WHERE vendor_id = %d AND file_path IS NOT NULL AND file_path != ''",
                $vendor_id
            ),
            ARRAY_A
        );
        $docs_by_type = [];
        foreach ( (array) $kyc_rows as $row ) {
            $docs_by_type[ strtolower( $row['document_type'] ) ] = $row['file_path'];
        }
        // Intentar B2 presigned URL si el path no es una URL completa
        $b2_bucket = LTMS_Core_Config::get( 'ltms_b2_kyc_bucket', 'lotengo-kyc-docs' );
        $sign_doc  = static function( string $path ) use ( $b2_bucket ): string {
            if ( empty( $path ) ) return '';
            // Convertir URLs legacy ltms-vault a key B2 relativa
            // Ej: https://lo-tengo.com.co/ltms-vault/kyc/168/file.pdf → kyc/168/file.pdf
            if ( filter_var( $path, FILTER_VALIDATE_URL ) ) {
                if ( str_contains( $path, '/ltms-vault/' ) ) {
                    $path = preg_replace( '#^.*/ltms-vault/#', '', $path );
                } else {
                    // URL externa real (ya es una URL pública/firmada) — devolverla tal cual
                    return $path;
                }
            }
            if ( class_exists( 'LTMS_Api_Factory' ) ) {
                try {
                    $b2  = LTMS_Api_Factory::get( 'backblaze' );
                    $ttl = (int) LTMS_Core_Config::get( 'ltms_vault_signed_url_ttl_seconds', 300 );
                    return $b2->get_signed_url( $b2_bucket, $path, $ttl );
                } catch ( \Throwable $e ) {}
            }
            return $path;
        };
        $doc_url_cedula = $sign_doc( $docs_by_type['cc'] ?? $docs_by_type['cedula'] ?? $kyc['file_path'] ?? get_user_meta( $vendor_id, 'ltms_kyc_file_cedula', true ) ?: get_user_meta( $vendor_id, 'ltms_kyc_doc_path', true ) );
        $doc_url_rut    = $sign_doc( $docs_by_type['rut'] ?? get_user_meta( $vendor_id, 'ltms_kyc_file_rut', true ) );
        $doc_url_camara = $sign_doc( $docs_by_type['camara'] ?? $docs_by_type['camara_comercio'] ?? get_user_meta( $vendor_id, 'ltms_kyc_file_camara', true ) );
        $doc_url_selfie = $sign_doc( $docs_by_type['selfie'] ?? get_user_meta( $vendor_id, 'ltms_kyc_selfie_url', true ) );
        $doc_url_nit    = $sign_doc( $docs_by_type['nit'] ?? '' );
        $doc_url_banco  = $sign_doc( $docs_by_type['banco'] ?? get_user_meta( $vendor_id, 'ltms_kyc_file_banco', true ) );

        // Datos de perfil del vendedor
        $store_name      = get_user_meta( $vendor_id, 'ltms_store_name',     true );
        $phone           = get_user_meta( $vendor_id, 'ltms_phone',          true );
        $city            = get_user_meta( $vendor_id, 'ltms_city',           true );
        $doc_type        = get_user_meta( $vendor_id, 'ltms_document_type',  true );
        $doc_masked      = class_exists( 'LTMS_Legal_Compliance' )
            ? LTMS_Legal_Compliance::get_masked_document( $vendor_id )
            : '****';
        $kyc_consent_at  = get_user_meta( $vendor_id, 'ltms_kyc_consent_at',  true );
        $kyc_consent_ip  = get_user_meta( $vendor_id, 'ltms_kyc_consent_ip',  true );

        wp_send_json_success([
            'kyc_id'        => $kyc_id,
            'vendor_id'     => $vendor_id,
            'display_name'  => $kyc['display_name'],
            'email'         => $kyc['user_email'],
            'store_name'    => $store_name,
            'phone'         => $phone,
            'city'          => $city,
            'doc_type'      => strtoupper( $doc_type ?: 'CC' ),
            'doc_masked'    => $doc_masked,
            'status'        => $kyc['status'],
            'submitted_at'  => $kyc['submitted_at'],
            'notes'         => $kyc['notes'] ?? '',
            'rejection_reason' => $kyc['rejection_reason'] ?? '',
            'kyc_consent_at'=> $kyc_consent_at,
            'kyc_consent_ip'=> $kyc_consent_ip,
            'docs' => [
                'cedula'  => $doc_url_cedula  ? esc_url( $doc_url_cedula )  : '',
                'rut'     => $doc_url_rut     ? esc_url( $doc_url_rut )     : '',
                'camara'  => $doc_url_camara  ? esc_url( $doc_url_camara )  : '',
                'selfie'  => $doc_url_selfie  ? esc_url( $doc_url_selfie )  : '',
                'banco'   => $doc_url_banco   ? esc_url( $doc_url_banco )   : '',
            ],
        ]);
    }

    /**
     * Obtiene el vendor_id a partir de un ID de registro KYC.
     *
     * @param int $kyc_id ID del registro KYC.
     * @return int
     */
    private function get_vendor_id_by_kyc( int $kyc_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $vendor_id = $wpdb->get_var(
            $wpdb->prepare( "SELECT vendor_id FROM `{$table}` WHERE id = %d", $kyc_id )
        );

        return (int) $vendor_id;
    }
}

