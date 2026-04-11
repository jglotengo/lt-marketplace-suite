<?php
class LTMS_Secure_Downloads {

    /** Tiempo de vida del token en segundos */
    const TOKEN_TTL = 300; // 5 minutos

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'init',                              [ $instance, 'handle_download_request' ] );
        add_action( 'wp_ajax_ltms_generate_download_token', [ $instance, 'ajax_generate_token' ] );
    }

    /**
     * Intercepta las solicitudes de descarga segura.
     *
     * @return void
     */
    public function handle_download_request(): void {
        $token = sanitize_text_field( $_GET['ltms_download'] ?? '' ); // phpcs:ignore
        if ( ! $token ) {
            return;
        }

        $data = get_transient( 'ltms_dl_' . md5( $token ) );
        if ( ! $data ) {
            wp_die( esc_html__( 'El enlace de descarga ha expirado o no es válido.', 'ltms' ), 403 );
        }

        // Verificar que el usuario actual es el propietario
        if ( (int) $data['user_id'] !== get_current_user_id() ) {
            wp_die( esc_html__( 'Acceso denegado.', 'ltms' ), 403 );
        }

        // Enviar el archivo
        $file_path = sanitize_text_field( $data['file_path'] );
        if ( ! file_exists( $file_path ) ) {
            wp_die( esc_html__( 'Archivo no encontrado.', 'ltms' ), 404 );
        }

        // Invalidar el token (uso único)
        delete_transient( 'ltms_dl_' . md5( $token ) );

        $filename = basename( $file_path );
        $mime     = mime_content_type( $file_path ) ?: 'application/octet-stream';

        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        exit;
    }

    /**
     * AJAX: genera un token de descarga segura.
     *
     * @return void
     */
    public function ajax_generate_token(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id    = get_current_user_id();
        $file_type  = sanitize_key( $_POST['file_type'] ?? '' );
        $ref_id     = absint( $_POST['ref_id'] ?? 0 );

        if ( ! $user_id || ! $file_type || ! $ref_id ) {
            wp_send_json_error( __( 'Parámetros inválidos.', 'ltms' ) );
        }

        // Resolver la ruta del archivo según el tipo
        $file_path = self::resolve_file_path( $file_type, $ref_id, $user_id );
        if ( ! $file_path ) {
            wp_send_json_error( __( 'Archivo no disponible.', 'ltms' ) );
        }

        $token = wp_generate_password( 48, false );
        set_transient( 'ltms_dl_' . md5( $token ), [
            'user_id'   => $user_id,
            'file_path' => $file_path,
            'file_type' => $file_type,
            'ref_id'    => $ref_id,
        ], self::TOKEN_TTL );

        wp_send_json_success( [
            'url' => add_query_arg( 'ltms_download', $token, home_url( '/' ) ),
            'ttl' => self::TOKEN_TTL,
        ] );
    }

    /**
     * Resuelve la ruta del archivo a descargar.
     *
     * @param string $file_type Tipo: invoice, payout_receipt, contract.
     * @param int    $ref_id    ID del recurso asociado.
     * @param int    $user_id   ID del usuario solicitante.
     * @return string|null Ruta absoluta o null si no aplica.
     */
    private static function resolve_file_path( string $file_type, int $ref_id, int $user_id ): ?string {
        $upload_dir = wp_upload_dir();
        $ltms_dir   = trailingslashit( $upload_dir['basedir'] ) . 'ltms/';

        switch ( $file_type ) {
            case 'invoice':
                $path = $ltms_dir . 'invoices/' . $user_id . '/invoice-' . $ref_id . '.pdf';
                break;
            case 'payout_receipt':
                $path = $ltms_dir . 'payouts/' . $user_id . '/payout-' . $ref_id . '.pdf';
                break;
            case 'contract':
                $path = $ltms_dir . 'contracts/' . $user_id . '/contract-' . $ref_id . '.pdf';
                break;
            default:
                return null;
        }

        return file_exists( $path ) ? $path : null;
    }
}
