<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_Media_Guard
 * Handles secure file storage in Backblaze B2 for KYC and private documents.
 * Routes /ltms-vault/* requests through access control.
 */
class LTMS_Media_Guard {

    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
        add_action( 'template_redirect', [ __CLASS__, 'intercept_vault_request' ] );
        add_action( 'wp_ajax_ltms_upload_kyc_document', [ __CLASS__, 'handle_kyc_upload_ajax' ] );
        add_action( 'wp_ajax_nopriv_ltms_upload_kyc_document', function() {
            wp_send_json_error( __( 'Debes iniciar sesión para subir documentos.', 'ltms' ), 401 );
        } );
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^ltms-vault/([^/]+)/([^/]+)/?$',
            'index.php?ltms_vault_entity=$matches[1]&ltms_vault_key=$matches[2]',
            'top'
        );
        add_rewrite_tag( '%ltms_vault_entity%', '([^/]+)' );
        add_rewrite_tag( '%ltms_vault_key%', '(.+)' );
    }

    public static function intercept_vault_request(): void {
        $entity = get_query_var( 'ltms_vault_entity' );
        $key    = get_query_var( 'ltms_vault_key' );

        if ( ! $entity || ! $key ) {
            return;
        }

        // Authenticate the request
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_die( esc_html__( 'Acceso denegado. Debes iniciar sesión.', 'ltms' ), 403 );
        }

        // Validate access
        if ( ! self::validate_access( $user_id, $entity, $key ) ) {
            LTMS_Core_Logger::warning( 'VAULT_ACCESS_DENIED', sprintf( 'User #%d denied access to vault: %s/%s', $user_id, $entity, $key ) );
            wp_die( esc_html__( 'No tienes permiso para acceder a este archivo.', 'ltms' ), 403 );
        }

        // Generate signed URL and redirect
        try {
            $b2     = LTMS_Api_Factory::get( 'backblaze' );
            $bucket = LTMS_Core_Config::get( 'ltms_backblaze_private_bucket', '' );
            $url    = $b2->get_signed_url( $bucket, sanitize_text_field( $key ), 300 ); // 5 min TTL
            wp_redirect( esc_url_raw( $url ) );
            exit;
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'VAULT_SIGNED_URL_FAILED', $e->getMessage() );
            wp_die( esc_html__( 'Error al acceder al archivo. Por favor intenta de nuevo.', 'ltms' ), 500 );
        }
    }

    public static function handle_kyc_upload_ajax(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! isset( $_FILES['ltms_kyc_file'] ) ) {
            wp_send_json_error( __( 'No se recibió ningún archivo.', 'ltms' ) );
        }

        $upload_data = $_FILES['ltms_kyc_file']; // phpcs:ignore
        $result      = self::handle_kyc_upload( $upload_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handles a KYC file upload: validates, stores in B2, records in lt_media_files.
     *
     * @param array $upload_data $_FILES array entry.
     * @return array|WP_Error
     */
    public static function handle_kyc_upload( array $upload_data ) {
        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            return new \WP_Error( 'not_logged_in', __( 'Debes iniciar sesión.', 'ltms' ) );
        }

        $allowed_types = [ 'image/jpeg', 'image/png', 'application/pdf' ];
        $max_size      = 10 * 1024 * 1024; // 10 MB

        $file = $upload_data;

        if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new \WP_Error( 'invalid_upload', __( 'Archivo inválido.', 'ltms' ) );
        }

        $mime = mime_content_type( $file['tmp_name'] );
        if ( ! in_array( $mime, $allowed_types, true ) ) {
            return new \WP_Error( 'invalid_type', __( 'Solo se permiten imágenes JPEG, PNG y PDF.', 'ltms' ) );
        }

        if ( $file['size'] > $max_size ) {
            return new \WP_Error( 'file_too_large', __( 'El archivo no puede superar 10 MB.', 'ltms' ) );
        }

        // Generate unique key
        $ext     = pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION );
        $key     = sprintf( 'kyc/%d/%s.%s', $vendor_id, wp_generate_uuid4(), strtolower( $ext ) );
        $content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore
        $hash    = hash( 'sha256', $content );
        $bucket  = LTMS_Core_Config::get( 'ltms_backblaze_private_bucket', '' );

        try {
            $b2     = LTMS_Api_Factory::get( 'backblaze' );
            $result = $b2->upload_file( $bucket, $key, $content, $mime, [
                'vendor_id'     => (string) $vendor_id,
                'original_name' => sanitize_file_name( $file['name'] ),
                'file_hash'     => $hash,
            ] );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'KYC_UPLOAD_B2_FAILED', $e->getMessage() );
            return new \WP_Error( 'upload_failed', __( 'Error al subir el archivo. Por favor intenta de nuevo.', 'ltms' ) );
        }

        // Record in lt_media_files
        global $wpdb;
        $table = $wpdb->prefix . 'lt_media_files';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'file_key'      => $key,
            'bucket'        => $bucket,
            'original_name' => sanitize_file_name( $file['name'] ),
            'mime_type'     => $mime,
            'file_size'     => (int) $file['size'],
            'file_hash'     => $hash,
            'entity_type'   => 'kyc',
            'entity_id'     => $vendor_id,
            'is_private'    => 1,
            'uploader_id'   => $vendor_id,
            'b2_file_id'    => $result['fileId'] ?? '',
            'created_at'    => LTMS_Utils::now_utc(),
        ], [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ] );

        LTMS_Core_Logger::info( 'KYC_UPLOAD_SUCCESS', sprintf( 'Vendor #%d uploaded KYC: %s', $vendor_id, $key ) );

        return [
            'file_id'  => (int) $wpdb->insert_id,
            'file_key' => $key,
            'vault_url' => site_url( 'ltms-vault/kyc/' . rawurlencode( $key ) ),
        ];
    }

    /**
     * Validates whether a user can access a vault file.
     *
     * @param int    $user_id     User ID.
     * @param string $entity_type Entity type (e.g. 'kyc').
     * @param string $entity_key  File key or encoded key.
     * @return bool
     */
    public static function validate_access( int $user_id, string $entity_type, string $entity_key ): bool {
        // Admins with KYC capability can access all
        if ( current_user_can( 'ltms_manage_kyc' ) ) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_media_files';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, entity_type, entity_id, uploader_id FROM `{$table}` WHERE file_key = %s LIMIT 1",
            sanitize_text_field( $entity_key )
        ) );

        if ( ! $row ) {
            return false;
        }

        // Uploader always has access
        if ( (int) $row->uploader_id === $user_id ) {
            return true;
        }

        // For KYC: vendor can only see their own documents
        if ( $row->entity_type === 'kyc' && (int) $row->entity_id === $user_id ) {
            return true;
        }

        return false;
    }
}
