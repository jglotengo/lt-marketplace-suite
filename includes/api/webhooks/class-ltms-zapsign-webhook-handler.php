<?php
class LTMS_Zapsign_Webhook_Handler {

    public static function init(): void {}

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        $doc_token = sanitize_text_field( $body['token'] ?? '' );
        $event     = sanitize_key( $body['event_type'] ?? '' );
        $signer    = $body['signer'] ?? [];

        if ( ! $doc_token || ! $event ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        // Verificar token de seguridad ZapSign.
        // ZapSign puede enviar el api_token O un webhook_secret separado en x-zapsign-token.
        // La opción ltms_zapsign_webhook_secret tiene prioridad; si no está configurada,
        // se usa el api_token descifrado como fallback.
        $webhook_secret = LTMS_Core_Config::get( 'ltms_zapsign_webhook_secret', '' );
        if ( $webhook_secret ) {
            $expected_token = str_starts_with( $webhook_secret, 'v1:' ) && class_exists( 'LTMS_Core_Security' )
                ? LTMS_Core_Security::decrypt( $webhook_secret )
                : $webhook_secret;
        } else {
            $raw_api_token  = LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' );
            $expected_token = str_starts_with( $raw_api_token, 'v1:' ) && class_exists( 'LTMS_Core_Security' )
                ? LTMS_Core_Security::decrypt( $raw_api_token )
                : $raw_api_token;
        }

        $req_token = $request->get_header( 'x-zapsign-token' ) ?: '';
        if ( $expected_token && ! hash_equals( $expected_token, $req_token ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid token' ], 401 );
        }

        // El external_id almacena el vendor_id
        $vendor_id = absint( $signer['external_id'] ?? 0 );

        if ( $vendor_id && 'doc_signed' === $event ) {
            update_user_meta( $vendor_id, '_ltms_zapsign_doc_token', $doc_token );
            update_user_meta( $vendor_id, '_ltms_zapsign_signed_at', gmdate( 'Y-m-d H:i:s' ) );

            // BC-01: respaldar el PDF firmado en Backblaze B2 (continuidad de negocio).
            // No-bloqueante: cualquier fallo se loguea dentro del método y nunca debe
            // impedir que el KYC avance a 'approved' más abajo.
            if ( class_exists( 'LTMS_ZapSign_Manager' ) ) {
                try {
                    LTMS_ZapSign_Manager::backup_signed_contract( $vendor_id, $doc_token );
                } catch ( \Throwable $e ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning( 'B2_CONTRACT_BACKUP_UNEXPECTED',
                            sprintf( 'Excepción inesperada respaldando contrato de vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
                    }
                }
            }

            // Avanzar KYC si el documento era el contrato de vendedor
            $doc_type = sanitize_key( $body['document_type'] ?? 'vendor_contract' );
            if ( 'vendor_contract' === $doc_type ) {
                // M-99: usar 'ltms_kyc_status' sin guion bajo — '_ltms_kyc_status' era key incorrecta
                $current_kyc = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
                if ( in_array( $current_kyc, [ 'pending', 'pending_signature' ], true ) ) {
                    update_user_meta( $vendor_id, 'ltms_kyc_status', 'approved' );
                    update_user_meta( $vendor_id, 'ltms_kyc_approved_at', gmdate( 'Y-m-d H:i:s' ) );
                    // Disparar la misma acción que el flujo manual de admin
                    do_action( 'ltms_vendor_approved', $vendor_id );
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::info( 'ZAPSIGN_KYC_AUTO_APPROVED',
                            sprintf( 'Vendor #%d KYC auto-aprobado por firma ZapSign', $vendor_id )
                        );
                    }
                }
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'ZAPSIGN_WEBHOOK', "event={$event}, vendor={$vendor_id}, doc={$doc_token}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }
}
