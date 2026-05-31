<?php
class LTMS_Admin_Marketing_Manager {

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
        add_action( 'wp_ajax_ltms_create_promo_coupon',    [ $instance, 'ajax_create_promo_coupon' ] );
        add_action( 'wp_ajax_ltms_get_mlm_tree_data',      [ $instance, 'ajax_get_mlm_tree_data' ] );
        add_action( 'wp_ajax_ltms_toggle_mlm_enabled',     [ $instance, 'ajax_toggle_mlm' ] );
        add_action( 'wp_ajax_ltms_get_campaign_stats',     [ $instance, 'ajax_get_campaign_stats' ] );
        add_action( 'wp_ajax_ltms_upload_banner',          [ $instance, 'ajax_upload_banner' ] );
        add_action( 'wp_ajax_ltms_delete_banner',          [ $instance, 'ajax_delete_banner' ] );
        add_action( 'wp_ajax_ltms_toggle_banner',          [ $instance, 'ajax_toggle_banner' ] );
    }

    /**
     * AJAX: crea un cupón promocional masivo.
     *
     * @return void
     */
    public function ajax_create_promo_coupon(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $code        = strtoupper( sanitize_text_field( $_POST['code'] ?? '' ) );
        $discount    = (float) ( $_POST['discount'] ?? 0 );
        $type        = sanitize_key( $_POST['type'] ?? 'percent' ); // percent | fixed_cart
        $expires     = sanitize_text_field( $_POST['expires'] ?? '' );
        $usage_limit = absint( $_POST['usage_limit'] ?? 0 );

        if ( ! $code || $discount <= 0 ) {
            wp_send_json_error( __( 'Código y descuento son requeridos.', 'ltms' ) );
        }

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $discount );
        if ( $expires ) {
            $coupon->set_date_expires( strtotime( $expires ) );
        }
        if ( $usage_limit ) {
            $coupon->set_usage_limit( $usage_limit );
        }
        $coupon->save();

        wp_send_json_success( [
            'coupon_id' => $coupon->get_id(),
            'code'      => $code,
            'message'   => __( 'Cupón creado exitosamente.', 'ltms' ),
        ] );
    }

    /**
     * AJAX: devuelve datos del árbol MLM para visualización.
     *
     * @return void
     */
    public function ajax_get_mlm_tree_data(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $vendor_id = absint( $_POST['vendor_id'] ?? 0 );

        if ( class_exists( 'LTMS_Referral_Tree' ) ) {
            $tree  = LTMS_Referral_Tree::get_descendant_tree( $vendor_id );
            $stats = LTMS_Referral_Tree::get_network_stats( $vendor_id );
            wp_send_json_success( [ 'tree' => $tree, 'stats' => $stats ] );
        }

        wp_send_json_success( [ 'tree' => [] ] );
    }

    /**
     * AJAX: activa/desactiva el sistema MLM.
     *
     * @return void
     */
    public function ajax_toggle_mlm(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }
        $enabled = sanitize_key( $_POST['enabled'] ?? 'no' );
        update_option( 'ltms_mlm_enabled', in_array( $enabled, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no' );
        wp_send_json_success( [ 'mlm_enabled' => get_option( 'ltms_mlm_enabled' ) ] );
    }

    /**
     * AJAX: estadísticas de campaña.
     *
     * @return void
     */
    public function ajax_get_campaign_stats(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $coupon_code = sanitize_text_field( $_POST['coupon_code'] ?? '' );
        $coupon      = new WC_Coupon( $coupon_code );

        wp_send_json_success( [
            'usage_count' => $coupon->get_usage_count(),
            'usage_limit' => $coupon->get_usage_limit(),
            'amount'      => $coupon->get_amount(),
            'type'        => $coupon->get_discount_type(),
        ] );
    
    /**
     * AJAX: Sube un banner/material a Backblaze B2 y registra en lt_marketing_banners.
     *
     * POST params: title, type, category, dimensions
     * FILE: banner_file
     *
     * @return void
     */
    public function ajax_upload_banner(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        if ( empty( $_FILES['banner_file'] ) || $_FILES['banner_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'No se recibió ningún archivo o hubo un error en la subida.', 'ltms' ) );
        }

        $file      = $_FILES['banner_file']; // phpcs:ignore
        $title     = sanitize_text_field( $_POST['title']      ?? '' ); // phpcs:ignore
        $type      = sanitize_key(        $_POST['type']       ?? 'banner' ); // phpcs:ignore
        $category  = sanitize_text_field( $_POST['category']   ?? '' ); // phpcs:ignore
        $dimensions= sanitize_text_field( $_POST['dimensions'] ?? '' ); // phpcs:ignore

        $valid_types = [ 'banner', 'flyer', 'social_post', 'email_template', 'video' ];
        if ( ! in_array( $type, $valid_types, true ) ) {
            $type = 'banner';
        }

        if ( empty( $title ) ) {
            wp_send_json_error( __( 'El título es obligatorio.', 'ltms' ) );
        }

        // Validar tipo MIME
        $allowed_mimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'application/pdf', 'video/mp4', 'video/quicktime',
        ];
        $finfo    = new \finfo( FILEINFO_MIME_TYPE );
        $mime     = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_send_json_error( sprintf( __( 'Tipo de archivo no permitido: %s', 'ltms' ), esc_html( $mime ) ) );
        }

        // Límite 50 MB
        if ( $file['size'] > 50 * 1024 * 1024 ) {
            wp_send_json_error( __( 'El archivo supera el límite de 50 MB.', 'ltms' ) );
        }

        try {
            $b2     = new LTMS_Api_Backblaze();
            $bucket = LTMS_Core_Config::get( 'ltms_backblaze_marketing_bucket', 'lotengo-marketing' );
            $ext    = pathinfo( $file['name'], PATHINFO_EXTENSION );
            $key    = 'marketing/' . $type . '/' . gmdate( 'Y/m' ) . '/' . wp_generate_uuid4() . '.' . strtolower( $ext );

            $content_bin = file_get_contents( $file['tmp_name'] ); // phpcs:ignore
            $result      = $b2->upload_file( $bucket, $key, $content_bin, $mime, [
                'uploaded-by' => 'ltms-admin',
                'title'       => $title,
            ] );

            $file_url = $result['Location'];

            // Guardar en BD
            global $wpdb;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $wpdb->prefix . 'lt_marketing_banners',
                [
                    'title'          => $title,
                    'type'           => $type,
                    'file_url'       => $file_url,
                    'thumbnail_url'  => in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ? $file_url : null,
                    'dimensions'     => $dimensions ?: null,
                    'category'       => $category ?: null,
                    'is_active'      => 1,
                    'download_count' => 0,
                    'created_at'     => gmdate( 'Y-m-d H:i:s' ),
                    'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );
            // phpcs:enable

            $banner_id = $wpdb->insert_id;

            LTMS_Core_Logger::info( 'BANNER_UPLOADED', sprintf( 'Banner #%d "%s" subido a B2 por usuario #%d', $banner_id, $title, get_current_user_id() ) );

            wp_send_json_success( [
                'banner_id' => $banner_id,
                'file_url'  => $file_url,
                'message'   => __( 'Material subido exitosamente.', 'ltms' ),
            ] );

        } catch ( \Throwable $e ) {
            wp_send_json_error( sprintf( __( 'Error al subir a Backblaze: %s', 'ltms' ), $e->getMessage() ) );
        }
    }

    /**
     * AJAX: Elimina un banner de B2 y de la BD.
     *
     * @return void
     */
    public function ajax_delete_banner(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $banner_id = absint( $_POST['banner_id'] ?? 0 ); // phpcs:ignore
        if ( ! $banner_id ) {
            wp_send_json_error( __( 'ID de banner inválido.', 'ltms' ) );
        }

        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $banner = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}lt_marketing_banners` WHERE id = %d", $banner_id ), ARRAY_A );
        if ( ! $banner ) {
            wp_send_json_error( __( 'Banner no encontrado.', 'ltms' ) );
        }

        // Intentar eliminar de B2
        try {
            $b2     = new LTMS_Api_Backblaze();
            $bucket = LTMS_Core_Config::get( 'ltms_backblaze_marketing_bucket', 'lotengo-marketing' );
            $url    = $banner['file_url'];
            $base   = LTMS_Core_Config::get( 'ltms_backblaze_endpoint', '' );
            if ( $base && strpos( $url, $base ) === 0 ) {
                $path = ltrim( str_replace( $base . '/' . $bucket, '', $url ), '/' );
                $b2->delete_file( $bucket, $path );
            }
        } catch ( \Throwable $e ) {
            // Si falla B2, igual eliminamos de BD
            LTMS_Core_Logger::info( 'BANNER_B2_DELETE_FAIL', $e->getMessage() );
        }

        $wpdb->delete( $wpdb->prefix . 'lt_marketing_banners', [ 'id' => $banner_id ], [ '%d' ] );
        // phpcs:enable

        wp_send_json_success( [ 'message' => __( 'Banner eliminado.', 'ltms' ) ] );
    }

    /**
     * AJAX: Activa/desactiva un banner.
     *
     * @return void
     */
    public function ajax_toggle_banner(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $banner_id = absint( $_POST['banner_id'] ?? 0 ); // phpcs:ignore
        if ( ! $banner_id ) {
            wp_send_json_error( __( 'ID de banner inválido.', 'ltms' ) );
        }

        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM `{$wpdb->prefix}lt_marketing_banners` WHERE id = %d", $banner_id ) );
        $new_val = $current ? 0 : 1;
        $wpdb->update( $wpdb->prefix . 'lt_marketing_banners', [ 'is_active' => $new_val ], [ 'id' => $banner_id ], [ '%d' ], [ '%d' ] );
        // phpcs:enable

        wp_send_json_success( [ 'is_active' => $new_val ] );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BANK RECONCILER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Bank_Reconciler
 *
 * Conciliación bancaria: compara las transacciones registradas en LTMS
 * con extractos bancarios importados (CSV) para detectar inconsistencias.
 */
