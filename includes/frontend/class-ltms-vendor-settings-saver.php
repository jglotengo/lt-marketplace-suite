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
