<?php
class LTMS_Vendor_Settings_Saver {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_save_vendor_profile',    [ $instance, 'save_profile' ] );
        add_action( 'wp_ajax_ltms_upload_store_banner',    [ $instance, 'upload_banner' ] );
        add_action( 'wp_ajax_ltms_save_delivery_zone',     [ $instance, 'save_delivery_zone' ] );
        add_action( 'wp_ajax_ltms_delete_store_banner',    [ $instance, 'delete_banner' ] );
        add_action( 'wp_ajax_ltms_get_vendor_settings',    [ $instance, 'get_vendor_settings' ] );
    }

    /**
     * AJAX: guarda el perfil público de la tienda.
     *
     * @return void
     */
    public function save_profile(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $fields = [
            'ltms_store_name'            => 'text_field',
            'ltms_store_description'     => 'textarea_field',
            'ltms_store_city'            => 'text_field',
            'ltms_store_address'         => 'text_field',
            'ltms_store_phone'           => 'text_field',
            'ltms_store_schedule'        => 'textarea_field',
            'ltms_store_categories'      => 'text_field',
            'ltms_vendor_ga4_id'         => 'text_field',
            'ltms_vendor_pixel_id'       => 'text_field',
            // Datos bancarios para retiros
            'ltms_bank_name'             => 'text_field',
            'ltms_bank_account_number'   => 'text_field',
            'ltms_bank_account_type'     => 'text_field',
            'ltms_bank_account_holder'   => 'text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
                $value = 'textarea_field' === $sanitizer
                    ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) // phpcs:ignore
                    : sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
                update_user_meta( $vendor_id, $key, $value );
            }
        }

        // v2.9.62 DEEP-AUDIT-002 P2-12: Validar formatos de GA4 y Pixel IDs.
        $ga4_id = get_user_meta( $vendor_id, 'ltms_vendor_ga4_id', true );
        if ( ! empty( $ga4_id ) && ! preg_match( '/^G-[A-Z0-9]{6,}$/', $ga4_id ) ) {
            // GA4 inválido — limpiar para no inyectar script roto.
            delete_user_meta( $vendor_id, 'ltms_vendor_ga4_id' );
            $this->log_info( 'VENDOR_GA4_INVALID', sprintf( 'Vendor #%d GA4 ID inválido limpiado: %s', $vendor_id, $ga4_id ) );
        }
        $pixel_id = get_user_meta( $vendor_id, 'ltms_vendor_pixel_id', true );
        if ( ! empty( $pixel_id ) && ! preg_match( '/^\d{15,16}$/', $pixel_id ) ) {
            // Pixel inválido — limpiar.
            delete_user_meta( $vendor_id, 'ltms_vendor_pixel_id' );
            $this->log_info( 'VENDOR_PIXEL_INVALID', sprintf( 'Vendor #%d Pixel ID inválido limpiado: %s', $vendor_id, $pixel_id ) );
        }

        $this->log_info( 'VENDOR_PROFILE_SAVED', "Perfil del vendedor #{$vendor_id} actualizado." );

        wp_send_json_success( [ 'message' => __( 'Perfil guardado exitosamente.', 'ltms' ) ] );
    }

    /**
     * AJAX: sube el banner de la tienda.
     *
     * @return void
     */
    public function upload_banner(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        // HI-5 FIX: validate the upload completed cleanly. Previously only the
        // presence of the 'banner' key was checked, so partial / failed uploads
        // (network errors, exceeding max_file_size) were passed straight to
        // media_handle_upload, which produced confusing error messages.
        if ( ! isset( $_FILES['banner'] ) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( [ 'message' => __( 'Upload failed', 'ltms' ) ], 400 );
        }

        // HI-5 FIX: MIME allowlist — match the pattern used in
        // upload_product_image (only image types). Banners should never be PDF,
        // video, or other binary formats.
        $file         = $_FILES['banner']; // phpcs:ignore
        $allowed_mime = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $finfo        = new finfo( FILEINFO_MIME_TYPE );
        $real_mime    = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $real_mime, $allowed_mime, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid file type', 'ltms' ) ], 415 );
        }

        // HI-5 FIX: explicit size limit (10 MB) — matches upload_product_image.
        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => __( 'File too large (max 10MB)', 'ltms' ) ], 413 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'banner', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            // HI-9 FIX: do not expose the raw WP_Error message — log server-side.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'VENDOR_BANNER_UPLOAD_ERROR',
                    $attachment_id->get_error_message()
                );
            }
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
        }

        $banner_url = wp_get_attachment_url( $attachment_id );
        update_user_meta( $vendor_id, 'ltms_store_banner_id',  $attachment_id );
        // M-47: también guardar la URL directamente para que get_vendor_settings la retorne sin una consulta extra.
        update_user_meta( $vendor_id, 'ltms_store_banner_url', $banner_url );
        // fix(storefront): el storefront público lee 'ltms_store_banner' (class-ltms-vendor-storefront.php),
        // no 'ltms_store_banner_url'. Sin esta línea el banner se sube pero nunca se refleja en /vendedor/{slug}/.
        update_user_meta( $vendor_id, 'ltms_store_banner',     $banner_url );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $banner_url,
        ] );
    }

    /**
     * AJAX: guarda la zona de despacho del vendedor.
     *
     * @return void
     */
    public function save_delivery_zone(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $zone_data = [
            'cities'    => array_map( 'sanitize_text_field', (array) ( $_POST['cities'] ?? [] ) ), // phpcs:ignore
            'radius_km' => absint( $_POST['radius_km'] ?? 0 ), // phpcs:ignore
            'free_from' => (float) ( $_POST['free_from'] ?? 0 ), // phpcs:ignore
        ];

        update_user_meta( $vendor_id, '_ltms_delivery_zone', wp_json_encode( $zone_data ) );

        wp_send_json_success( [ 'message' => __( 'Zona de despacho guardada.', 'ltms' ) ] );
    }

    /**
     * AJAX: devuelve los datos de configuración actuales del vendedor.
     * Requerido por loadSettingsView() en ltms-dashboard.js.
     *
     * @return void
     */
    public function get_vendor_settings(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $user        = get_userdata( $vendor_id );
        $kyc_status  = get_user_meta( $vendor_id, 'ltms_kyc_status',         true ) ?: 'pending';
        $phone       = get_user_meta( $vendor_id, 'ltms_store_phone',         true )
                    ?: get_user_meta( $vendor_id, 'ltms_phone',               true );
        $zone_raw    = get_user_meta( $vendor_id, '_ltms_delivery_zone',      true );
        $zone        = $zone_raw ? json_decode( $zone_raw, true ) : [];

        // HI-6 FIX: never return the bank account number in plaintext — it can
        // be exfiltrated via XSS or browser extensions with broad DOM access.
        // The vendor only needs to see the masked value (e.g. "****1234") to
        // confirm which account is on file; the plaintext stays server-side.
        // Handles both plaintext storage (save_profile flow) and encrypted
        // storage (products-ajax.php save_vendor_settings — stored with
        // LTMS_Core_Security::encrypt, prefixed 'v1:').
        $bank_account_raw = (string) get_user_meta( $vendor_id, 'ltms_bank_account_number', true );
        if ( $bank_account_raw && 0 === strpos( $bank_account_raw, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) ) {
            try {
                $decrypted = LTMS_Core_Security::decrypt( $bank_account_raw );
                if ( is_string( $decrypted ) ) {
                    $bank_account_raw = $decrypted;
                }
            } catch ( \Throwable $e ) {
                // Decryption failed (key rotation, corrupt data) — fall through
                // to masking the encrypted blob, which produces a harmless
                // "****" value instead of leaking anything.
                $bank_account_raw = '';
            }
        }
        if ( $bank_account_raw && class_exists( 'LTMS_Data_Masking' ) ) {
            $masked_bank_account = LTMS_Data_Masking::mask_bank_account( $bank_account_raw );
        } elseif ( $bank_account_raw ) {
            // Fallback simple mask if LTMS_Data_Masking is not available.
            $masked_bank_account = strlen( $bank_account_raw ) > 8
                ? substr( $bank_account_raw, 0, 4 ) . '****' . substr( $bank_account_raw, -4 )
                : '****';
        } else {
            $masked_bank_account = '';
        }

        // Estructura compatible con renderSettingsView() en ltms-dashboard.js:
        // El JS lee data.store.name, data.store.phone, data.store.store_name, etc.
        wp_send_json_success( [
            'kyc_status'    => $kyc_status,
            'referral_code' => get_user_meta( $vendor_id, 'ltms_referral_code', true ),
            'store'         => [
                // Campos "básicos" — usados en la sección "Datos de la Tienda"
                'name'              => get_user_meta( $vendor_id, 'ltms_store_name',        true ),
                'phone'             => $phone,
                'description'       => get_user_meta( $vendor_id, 'ltms_store_description', true ),
                // v2.9.68 P2-10: Removido ltms_bank_info (dead field) — los datos bancarios
                // completos están en bank_name, bank_account_number, bank_account_type, etc.
                // Campos bancarios completos para retiros
                'bank_name'            => get_user_meta( $vendor_id, 'ltms_bank_name',            true ),
                // HI-6: masked bank account number — never plaintext to the client.
                'bank_account_number'  => $masked_bank_account,
                'bank_account_type'    => get_user_meta( $vendor_id, 'ltms_bank_account_type',    true ) ?: 'ahorros',
                'bank_account_holder'  => get_user_meta( $vendor_id, 'ltms_bank_account_holder',  true ),
                // Campos de perfil público — usados en "Perfil Público de la Tienda"
                'store_name'        => get_user_meta( $vendor_id, 'ltms_store_name',        true ),
                'store_phone'       => $phone,
                'store_description' => get_user_meta( $vendor_id, 'ltms_store_description', true ),
                'store_city'        => get_user_meta( $vendor_id, 'ltms_store_city',        true ),
                'store_address'     => get_user_meta( $vendor_id, 'ltms_store_address',     true ),
                'store_schedule'    => get_user_meta( $vendor_id, 'ltms_store_schedule',    true ),
                'store_categories'  => get_user_meta( $vendor_id, 'ltms_store_categories',  true ),
                'store_banner_url'  => get_user_meta( $vendor_id, 'ltms_store_banner_url',  true )
                                        ?: get_user_meta( $vendor_id, 'ltms_store_banner',      true ),
                // Zona de despacho
                'delivery_zone'        => $zone,
            'vendor_ga4_enabled'   => get_option( 'ltms_vendor_ga4_enabled', 'yes' ) === 'yes',
            'vendor_pixel_enabled' => get_option( 'ltms_vendor_pixel_enabled', 'yes' ) === 'yes',
            'vendor_ga4_id'        => get_user_meta( $vendor_id, 'ltms_vendor_ga4_id',    true ),
            'vendor_pixel_id'      => get_user_meta( $vendor_id, 'ltms_vendor_pixel_id',  true ),
            ],
        ] );
    }
    /**
     * AJAX: elimina el banner de la tienda.
     *
     * @return void
     */
    public function delete_banner(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
        }

        $attachment_id = (int) get_user_meta( $vendor_id, 'ltms_store_banner_id', true );
        if ( $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }

        delete_user_meta( $vendor_id, 'ltms_store_banner_id' );
        delete_user_meta( $vendor_id, 'ltms_store_banner_url' );
        delete_user_meta( $vendor_id, 'ltms_store_banner' );

        $this->log_info( 'VENDOR_BANNER_DELETED', "Banner eliminado por vendedor #{$vendor_id}." );

        wp_send_json_success( [ 'message' => __( 'Banner eliminado correctamente.', 'ltms' ) ] );
    }

}

// ─────────────────────────────────────────────────────────────────────────────
// SECURE DOWNLOADS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Secure_Downloads
 *
 * Protege las descargas de archivos digitales (facturas, contratos, recibos)
 * generando URLs con token de tiempo limitado. Solo el usuario propietario
 * puede descargar el archivo.
 *
 * URL: /ltms-download/?token=XXXX
 */

