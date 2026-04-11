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

        // Verificar token de seguridad ZapSign
        $api_token    = LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' );
        $req_token    = $request->get_header( 'x-zapsign-token' ) ?: '';
        if ( $api_token && ! hash_equals( $api_token, $req_token ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid token' ], 401 );
        }

        // El external_id almacena el vendor_id
        $vendor_id = absint( $signer['external_id'] ?? 0 );

        if ( $vendor_id && 'doc_signed' === $event ) {
            update_user_meta( $vendor_id, '_ltms_zapsign_doc_token', $doc_token );
            update_user_meta( $vendor_id, '_ltms_zapsign_signed_at', gmdate( 'Y-m-d H:i:s' ) );

            // Avanzar KYC si el documento era el contrato de vendedor
            $doc_type = sanitize_key( $body['document_type'] ?? 'vendor_contract' );
            if ( 'vendor_contract' === $doc_type ) {
                $current_kyc = get_user_meta( $vendor_id, '_ltms_kyc_status', true );
                if ( 'pending_signature' === $current_kyc ) {
                    update_user_meta( $vendor_id, '_ltms_kyc_status', 'approved' );
                }
            }
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'ZAPSIGN_WEBHOOK', "event={$event}, vendor={$vendor_id}, doc={$doc_token}" );
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }
}
