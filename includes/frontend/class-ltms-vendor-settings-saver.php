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
            'ltms_store_name'        => 'text_field',
            'ltms_store_description' => 'textarea_field',
            'ltms_store_city'        => 'text_field',
            'ltms_store_address'     => 'text_field',
            'ltms_store_phone'       => 'text_field',
            'ltms_store_schedule'    => 'textarea_field',
            'ltms_store_categories'  => 'text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore
                $value = 'textarea_field' === $sanitizer
                    ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) // phpcs:ignore
                    : sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore
                update_user_meta( $vendor_id, $key, $value );
            }
        }

        self::log_info( 'VENDOR_PROFILE_SAVED', "Perfil del vendedor #{$vendor_id} actualizado." );

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

        if ( empty( $_FILES['banner'] ) ) { // phpcs:ignore
            wp_send_json_error( __( 'No se recibió ningún archivo.', 'ltms' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'banner', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( $attachment_id->get_error_message() );
        }

        update_user_meta( $vendor_id, 'ltms_store_banner_id', $attachment_id );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
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
                'bank_info'         => get_user_meta( $vendor_id, 'ltms_bank_info',         true ),
                // Campos de perfil público — usados en "Perfil Público de la Tienda"
                'store_name'        => get_user_meta( $vendor_id, 'ltms_store_name',        true ),
                'store_phone'       => $phone,
                'store_description' => get_user_meta( $vendor_id, 'ltms_store_description', true ),
                'store_city'        => get_user_meta( $vendor_id, 'ltms_store_city',        true ),
                'store_address'     => get_user_meta( $vendor_id, 'ltms_store_address',     true ),
                'store_schedule'    => get_user_meta( $vendor_id, 'ltms_store_schedule',    true ),
                'store_categories'  => get_user_meta( $vendor_id, 'ltms_store_categories',  true ),
                'store_banner_url'  => get_user_meta( $vendor_id, 'ltms_store_banner_url',  true ),
                // Zona de despacho
                'delivery_zone'     => $zone,
            ],
        ] );
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
