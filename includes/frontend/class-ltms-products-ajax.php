<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Products_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ltms_get_products_data',    [ $this, 'get_products_data' ] );
        add_action( 'wp_ajax_ltms_save_vendor_settings', [ $this, 'save_vendor_settings' ] );
        // C5-1 FIX: ltms_get_vendor_settings eliminado — lo maneja LTMS_Vendor_Settings_Saver
        // con respuesta más completa (bank_info, delivery_zone, store_address, etc).
        add_action( 'wp_ajax_ltms_create_product',        [ $this, 'create_product' ] );
        add_action( 'wp_ajax_ltms_get_categories',        [ $this, 'get_categories' ] );
        add_action( 'wp_ajax_ltms_upload_product_image',  [ $this, 'upload_product_image' ] );
        add_action( 'wp_ajax_ltms_get_product',           [ $this, 'get_product' ] );
        add_action( 'wp_ajax_ltms_update_product',        [ $this, 'update_product' ] );
        add_action( 'wp_ajax_ltms_delete_product',        [ $this, 'delete_product' ] );
        add_action( 'wp_ajax_ltms_toggle_product_status', [ $this, 'toggle_product_status' ] );
    }

    private function check_nonce() {
        if ( ! check_ajax_referer( 'ltms_dashboard_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in', 401 );
        }
        // HI-2 FIX: most product handlers (update_product, create_product,
        // delete_product, upload_product_image, ...) mutate vendor data. Without
        // a capability check, any logged-in user (subscriber, customer) could
        // call them. Require ltms_vendor or manage_options.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
        }
    }

    public function get_products_data() {
        $this->check_nonce();
        $user_id  = get_current_user_id();
        $args     = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => 50,
        ];
        $query    = new WP_Query( $args );
        $products = [];
        foreach ( $query->posts as $p ) {
            $product    = wc_get_product( $p->ID );
            $products[] = [
                'id'       => $p->ID,
                'name'     => $p->post_title,
                'status'   => $p->post_status,
                'price'    => $product ? (float) $product->get_price() : 0,
                'stock'    => $product ? $product->get_stock_quantity() : null,
                    'image'        => ( $product && $product->get_image_id() ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
                    'edit_url'     => get_edit_post_link( $p->ID, 'raw' ),
                    'product_type' => get_post_meta( $p->ID, '_ltms_product_type', true ) ?: 'product',
            ];
        }
        wp_send_json_success( [ 'products' => $products ] );
    }

    public function get_vendor_settings() {
        $this->check_nonce();
        $user_id    = get_current_user_id();
        $kyc_status = get_user_meta( $user_id, 'ltms_kyc_status', true ) ?: 'pending';
        $dz_raw = get_user_meta( $user_id, '_ltms_delivery_zone', true );
        $store  = [
            'name'             => get_user_meta( $user_id, 'ltms_store_name',        true ),
            'phone'            => get_user_meta( $user_id, 'ltms_store_phone',       true ),
            'description'      => get_user_meta( $user_id, 'ltms_store_description', true ),
            'bank_info'        => get_user_meta( $user_id, 'ltms_bank_info',         true ),
            // Extended profile fields (Vendor_Settings_Saver)
            'store_name'       => get_user_meta( $user_id, 'ltms_store_name',        true ),
            'store_phone'      => get_user_meta( $user_id, 'ltms_store_phone',       true ),
            'store_address'    => get_user_meta( $user_id, 'ltms_store_address',     true ),
            'store_city'       => get_user_meta( $user_id, 'ltms_store_city',        true ),
            'store_schedule'   => get_user_meta( $user_id, 'ltms_store_schedule',    true ),
            'store_categories' => get_user_meta( $user_id, 'ltms_store_categories',  true ),
            'delivery_zone'    => $dz_raw ? json_decode( $dz_raw, true ) : [ 'cities' => [], 'radius_km' => 0, 'free_from' => 0 ],
        ];
        wp_send_json_success( [
            'kyc_status'           => $kyc_status,
            'store'                => $store,
            // v2.3.0 — Analytics por vendedor
            'vendor_ga4_enabled'   => get_option( 'ltms_vendor_ga4_enabled',   'yes' ) === 'yes',
            'vendor_pixel_enabled' => get_option( 'ltms_vendor_pixel_enabled', 'yes' ) === 'yes',
            'vendor_ga4_id'        => get_user_meta( $user_id, 'ltms_vendor_ga4_id',   true ),
            'vendor_pixel_id'      => get_user_meta( $user_id, 'ltms_vendor_pixel_id', true ),
        ] );
    }

    public function save_vendor_settings() {
        $this->check_nonce();
        $user_id = get_current_user_id();

        // Support two call formats:
        // 1. Flat POST fields: store_name, store_phone, store_description, bank_info (from renderSettingsView JS)
        // 2. Nested settings object: settings[ltms_store_name], etc. (from view-settings.php inline JS)
        $settings_map = [
            'ltms_store_name'        => $_POST['store_name']        ?? ( $_POST['settings']['ltms_store_name']        ?? null ), // phpcs:ignore
            'ltms_store_phone'       => $_POST['store_phone']       ?? ( $_POST['settings']['ltms_store_phone']       ?? null ), // phpcs:ignore
            'ltms_store_description' => $_POST['store_description'] ?? ( $_POST['settings']['ltms_store_description'] ?? null ), // phpcs:ignore
            'ltms_bank_info'         => $_POST['bank_info']         ?? ( $_POST['settings']['ltms_bank_info']         ?? null ), // phpcs:ignore
            'ltms_bank_name'         => null,
            'ltms_bank_account_type' => null,
            'ltms_shipping_policy'   => null,
            'ltms_return_policy'     => null,
        ];

        // Also handle any remaining ltms_* fields from the nested settings object
        if ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) { // phpcs:ignore
            // M-101: campos fiscales agregados a la lista de permitidos
            $allowed = [
                'ltms_bank_name', 'ltms_bank_account_type', 'ltms_bank_account_holder', 'ltms_payment_method',
                'ltms_shipping_policy', 'ltms_return_policy',
                'ltms_tax_regime', 'ltms_nit', 'ltms_ciiu_code', 'ltms_municipality',
                // v2.3.0 — Analytics por vendedor
                'ltms_vendor_ga4_id', 'ltms_vendor_pixel_id',
            ];
            foreach ( $allowed as $field ) {
                if ( isset( $_POST['settings'][ $field ] ) ) { // phpcs:ignore
                    $settings_map[ $field ] = $_POST['settings'][ $field ]; // phpcs:ignore
                }
            }
            // Handle encrypted bank account number
            if ( ! empty( $_POST['settings']['ltms_bank_account_number'] ) ) { // phpcs:ignore
                update_user_meta(
                    $user_id,
                    'ltms_bank_account_number',
                    LTMS_Core_Security::encrypt( sanitize_text_field( $_POST['settings']['ltms_bank_account_number'] ) ) // phpcs:ignore
                );
            }
        }

        foreach ( $settings_map as $meta_key => $value ) {
            if ( $value !== null ) {
                update_user_meta( $user_id, $meta_key, sanitize_text_field( wp_unslash( $value ) ) );
            }
        }

        // M-101: manejar checkbox gran contribuyente (solo llega si está marcado)
        if ( isset( $_POST['settings']['ltms_is_gran_contribuyente'] ) ) { // phpcs:ignore
            update_user_meta( $user_id, 'ltms_is_gran_contribuyente', 1 );
        } else {
            update_user_meta( $user_id, 'ltms_is_gran_contribuyente', 0 );
        }

        wp_send_json_success( [ 'message' => __( 'Configuración guardada exitosamente.', 'ltms' ) ] );
    }

    public function get_product() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado', 404 );
        }
        $cats = $product->get_category_ids();
        wp_send_json_success( [
            'id'                  => $product_id,
            'name'                => $product->get_name(),
            'description'         => $product->get_description(),
            'price'               => $product->get_regular_price(),
            'sale_price'          => $product->get_sale_price(),
            'stock'               => $product->get_stock_quantity(),
            'status'              => $product->get_status(),
            'catalog_visibility'  => $product->get_catalog_visibility(),
            'weight'              => $product->get_weight(),
            'length'              => $product->get_length(),
            'width'               => $product->get_width(),
            'height'              => $product->get_height(),
            'category_id'         => ! empty( $cats ) ? $cats[0] : 0,
            'image_id'            => $product->get_image_id(),
            'image_url'           => $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
            'gallery_ids'         => $product->get_gallery_image_ids(),
            'gallery_urls'        => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
            // CS-07: tipo para pre-llenar selector en edición (mapeo legacy)
            'product_type'        => ( function( $t ) { return ( $t === 'product' || $t === '' ) ? 'physical' : $t; } )( get_post_meta( $product_id, '_ltms_product_type', true ) ),
            // CS-08: ReDi
            'redi_enabled'        => get_post_meta( $product_id, '_ltms_redi_enabled', true ) ?: 'no',
            'redi_rate'           => (float) get_post_meta( $product_id, '_ltms_redi_rate', true ) * 100,
        ] );
    }

    public function update_product() {
        $this->check_nonce();
        $product_id  = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado', 404 );
        }
        $name               = sanitize_text_field( $_POST['name'] ?? '' );
        $description        = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price              = floatval( $_POST['price'] ?? 0 );
        $sale_price_raw     = isset( $_POST['sale_price'] ) && $_POST['sale_price'] !== '' ? floatval( $_POST['sale_price'] ) : null;
        $stock              = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id        = intval( $_POST['category_id'] ?? 0 );
        $image_id           = intval( $_POST['image_id'] ?? 0 );
        $status             = sanitize_text_field( $_POST['status'] ?? $product->get_status() );
        $catalog_visibility = sanitize_key( $_POST['catalog_visibility'] ?? '' );
        $weight             = isset( $_POST['weight'] ) && $_POST['weight'] !== '' ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : null;
        $dim_length         = isset( $_POST['dim_length'] ) && $_POST['dim_length'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_length'] ) ) : null;
        $dim_width          = isset( $_POST['dim_width'] )  && $_POST['dim_width']  !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_width'] ) )  : null;
        $dim_height         = isset( $_POST['dim_height'] ) && $_POST['dim_height'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_height'] ) ) : null;

        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }

        // v2.9.62 DEEP-AUDIT-002 P2-6: Validar que la categoría existe.
        if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
            wp_send_json_error( 'Categoría inválida', 400 );
        }

        // HI-1 FIX: validate status against an allowlist before applying it.
        $allowed_statuses = [ 'publish', 'pending', 'draft' ];
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product status', 'ltms' ) ], 400 );
        }

        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        // CS-09: precio de oferta — vacío = sin oferta activa
        if ( $sale_price_raw !== null && $sale_price_raw > 0 && $sale_price_raw < $price ) {
            $product->set_sale_price( (string) $sale_price_raw );
        } else {
            $product->set_sale_price( '' ); // limpiar oferta si se dejó vacío
        }
        $product->set_status( $status );
        if ( in_array( $catalog_visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $product->set_catalog_visibility( $catalog_visibility );
        }
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
        if ( $weight !== null )     $product->set_weight( $weight );
        if ( $dim_length !== null ) $product->set_length( $dim_length );
        if ( $dim_width  !== null ) $product->set_width( $dim_width );
        if ( $dim_height !== null ) $product->set_height( $dim_height );
        if ( $category_id ) $product->set_category_ids( [ $category_id ] );
        if ( $image_id )    $product->set_image_id( $image_id );
        $gallery_ids = isset( $_POST['gallery_ids'] ) ? array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) ) : null;
        if ( $gallery_ids !== null ) { $product->set_gallery_image_ids( $gallery_ids ); }
        $product->save();

        // M-23 FIX: re-guardar _ltms_vendor_id después de cada actualización.
        // $product->save() de WooCommerce puede eliminar metas no gestionadas
        // por WC, lo que haría que el producto dejara de aparecer en pedidos del dashboard.
        update_post_meta( $product_id, '_ltms_vendor_id', get_current_user_id() );

        // CS-05: actualizar tipo si viene en la petición
        if ( isset( $_POST['product_type'] ) ) { // phpcs:ignore
            $upd_type = sanitize_key( $_POST['product_type'] ); // phpcs:ignore
            // Mapeo legacy: 'product' → 'physical'
            if ( $upd_type === 'product' ) { $upd_type = 'physical'; }
            if ( in_array( $upd_type, [ 'physical', 'digital', 'service', 'booking' ], true ) ) {
                update_post_meta( $product_id, '_ltms_product_type', $upd_type );
            }
        }

        // CS-08: ReDi toggle + tasa con validación de rango (independiente de CS-05)
        if ( 'yes' === get_option( 'ltms_redi_enabled' ) ) {
            $redi_enabled = ( isset( $_POST['redi_enabled'] ) && 'yes' === sanitize_key( $_POST['redi_enabled'] ) ) // phpcs:ignore
                ? 'yes' : 'no';
            update_post_meta( $product_id, '_ltms_redi_enabled', $redi_enabled );
            if ( isset( $_POST['redi_rate'] ) ) { // phpcs:ignore
                $redi_rate_pct = (float) sanitize_text_field( wp_unslash( $_POST['redi_rate'] ) ); // phpcs:ignore
                // redi_rate llega en % desde el frontend (ej: 15), convertir a decimal y clampar
                $redi_rate = LTMS_Business_Redi_Manager::clamp_redi_rate( $redi_rate_pct / 100 );
                update_post_meta( $product_id, '_ltms_redi_rate', $redi_rate );
            }
        }

        // CS-07: commission_rate es de exclusiva gestión del admin — no se acepta desde el frontend.

        wp_send_json_success( [ 'message' => 'Producto actualizado' ] );
    }

    public function get_categories() {
        $this->check_nonce();
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ] );
        $cats  = [];
        foreach ( $terms as $t ) {
            $cats[] = [ 'id' => $t->term_id, 'name' => $t->name ];
        }
        wp_send_json_success( [ 'categories' => $cats ] );
    }

    /**
     * Sube y optimiza una imagen de producto antes de guardarla en la Media Library.
     *
     * Proceso:
     *   1. Valida tipo MIME y tamaño (máx 10 MB)
     *   2. Redimensiona a máx 1200px de ancho manteniendo proporción
     *   3. Convierte a WebP si el servidor lo soporta (GD o Imagick)
     *   4. Si no soporta WebP, comprime JPEG/PNG al 82%
     *   5. Guarda el archivo optimizado y crea el attachment en WordPress
     *
     * Reducción típica: de 500 KB → 80-120 KB (75-85% menos peso)
     *
     * @return void
     */
    public function upload_product_image(): void {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        if ( empty( $_FILES['image'] ) || $_FILES['image']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore
            wp_send_json_error( 'No se recibió ninguna imagen.', 400 );
        }

        $file = $_FILES['image']; // phpcs:ignore

        // ── 1. Validar tipo MIME ──────────────────────────────────────────────
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $finfo         = new finfo( FILEINFO_MIME_TYPE );
        $real_mime     = $finfo->file( $file['tmp_name'] );
        if ( ! in_array( $real_mime, $allowed_mimes, true ) ) {
            wp_send_json_error( sprintf( 'Tipo de archivo no permitido: %s', esc_html( $real_mime ) ), 415 );
        }

        // ── 2. Validar tamaño (máx 10 MB) ───────────────────────────────────
        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( 'La imagen supera el límite de 10 MB.', 413 );
        }

        // ── 3. Optimizar con GD o Imagick ───────────────────────────────────
        $optimized_path = $this->optimize_image( $file['tmp_name'], $real_mime );

        if ( $optimized_path && $optimized_path !== $file['tmp_name'] ) {
            // Reemplazar el archivo temporal con la versión optimizada
            // para que media_handle_upload procese la imagen comprimida.
            $original_tmp  = $file['tmp_name'];
            $original_name = $file['name'];

            // Cambiar extensión a .webp si se convirtió
            $new_ext = pathinfo( $optimized_path, PATHINFO_EXTENSION );
            if ( $new_ext === 'webp' ) {
                $file['name'] = pathinfo( $original_name, PATHINFO_FILENAME ) . '.webp';
            }

            // Sobrescribir el tmp_name para que WordPress lea el archivo optimizado
            copy( $optimized_path, $original_tmp );
            @unlink( $optimized_path ); // phpcs:ignore
            $file['size'] = filesize( $original_tmp );
            $_FILES['image'] = $file; // phpcs:ignore
        }

        // ── 4. Guardar en Media Library ──────────────────────────────────────
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'image', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            // HI-9 FIX: do not expose the raw WP_Error message — can leak
            // server paths (e.g. wp-content/uploads/...). Log server-side and
            // return a generic message.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'PRODUCT_IMAGE_UPLOAD_ERROR',
                    $attachment_id->get_error_message()
                );
            }
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
        }

        $final_url  = wp_get_attachment_url( $attachment_id );
        $final_size = filesize( get_attached_file( $attachment_id ) );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $final_url,
            'size_kb'       => round( $final_size / 1024 ),
        ] );
    }

    /**
     * Optimiza una imagen: redimensiona a máx 1200px y convierte a WebP (o comprime JPEG).
     *
     * @param string $src_path  Ruta del archivo original.
     * @param string $mime      Tipo MIME original.
     * @return string|null      Ruta del archivo optimizado, o null si no fue posible.
     */
    private function optimize_image( string $src_path, string $mime ): ?string {
        $max_width   = 1200;
        $max_height  = 1200;
        $jpeg_quality = 82;
        $webp_quality = 82;

        // ── Intentar con Imagick (mejor calidad) ─────────────────────────────
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $imagick = new \Imagick( $src_path );
                $imagick->setImageOrientation( \Imagick::ORIENTATION_UNDEFINED );
                $imagick->autoOrient();

                $w = $imagick->getImageWidth();
                $h = $imagick->getImageHeight();

                // Redimensionar solo si supera el máximo
                if ( $w > $max_width || $h > $max_height ) {
                    $imagick->thumbnailImage( $max_width, $max_height, true, false );
                }

                $imagick->stripImage(); // Eliminar EXIF, GPS, etc.

                // Intentar WebP
                if ( $imagick->queryFormats( 'WEBP' ) ) {
                    $out_path = $src_path . '_opt.webp';
                    $imagick->setImageFormat( 'webp' );
                    $imagick->setImageCompressionQuality( $webp_quality );
                    $imagick->writeImage( $out_path );
                    $imagick->clear();
                    return $out_path;
                }

                // Fallback: comprimir JPEG
                $out_path = $src_path . '_opt.jpg';
                $imagick->setImageFormat( 'jpeg' );
                $imagick->setImageCompressionQuality( $jpeg_quality );
                $imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
                $imagick->writeImage( $out_path );
                $imagick->clear();
                return $out_path;

            } catch ( \Throwable $e ) {
                // Si Imagick falla, intentar con GD
            }
        }

        // ── Fallback: GD ─────────────────────────────────────────────────────
        if ( ! extension_loaded( 'gd' ) ) {
            return null; // Sin GD ni Imagick — no optimizar
        }

        $src_image = null;
        switch ( $mime ) {
            case 'image/jpeg': $src_image = @imagecreatefromjpeg( $src_path ); break; // phpcs:ignore
            case 'image/png':  $src_image = @imagecreatefrompng( $src_path );  break; // phpcs:ignore
            case 'image/gif':  $src_image = @imagecreatefromgif( $src_path );  break; // phpcs:ignore
            case 'image/webp': $src_image = @imagecreatefromwebp( $src_path ); break; // phpcs:ignore
        }

        if ( ! $src_image ) {
            return null;
        }

        $orig_w = imagesx( $src_image );
        $orig_h = imagesy( $src_image );

        // Calcular nuevas dimensiones manteniendo proporción
        $ratio  = min( $max_width / $orig_w, $max_height / $orig_h, 1.0 );
        $new_w  = (int) round( $orig_w * $ratio );
        $new_h  = (int) round( $orig_h * $ratio );

        $dst_image = imagecreatetruecolor( $new_w, $new_h );

        // Preservar transparencia para PNG
        if ( $mime === 'image/png' ) {
            imagealphablending( $dst_image, false );
            imagesavealpha( $dst_image, true );
            $transparent = imagecolorallocatealpha( $dst_image, 255, 255, 255, 127 );
            imagefilledrectangle( $dst_image, 0, 0, $new_w, $new_h, $transparent );
        }

        imagecopyresampled( $dst_image, $src_image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
        imagedestroy( $src_image );

        // Guardar como WebP si GD lo soporta
        if ( function_exists( 'imagewebp' ) ) {
            $out_path = $src_path . '_opt.webp';
            imagewebp( $dst_image, $out_path, $webp_quality );
            imagedestroy( $dst_image );
            return $out_path;
        }

        // Fallback final: JPEG comprimido
        $out_path = $src_path . '_opt.jpg';
        imagejpeg( $dst_image, $out_path, $jpeg_quality );
        imagedestroy( $dst_image );
        return $out_path;
    }

    public function create_product() {
        $this->check_nonce();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }
        $name               = sanitize_text_field( $_POST['name'] ?? '' );
        $description        = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price              = floatval( $_POST['price'] ?? 0 );
        $sale_price_raw     = isset( $_POST['sale_price'] ) && $_POST['sale_price'] !== '' ? floatval( $_POST['sale_price'] ) : null;
        $stock              = isset( $_POST['stock'] ) && $_POST['stock'] !== '' ? intval( $_POST['stock'] ) : null;
        $category_id        = intval( $_POST['category_id'] ?? 0 );
        $image_id           = intval( $_POST['image_id'] ?? 0 );
        $status             = sanitize_text_field( $_POST['status'] ?? 'pending' );
        $catalog_visibility = sanitize_key( $_POST['catalog_visibility'] ?? 'visible' );
        $weight             = isset( $_POST['weight'] ) && $_POST['weight'] !== '' ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : null;
        $dim_length         = isset( $_POST['dim_length'] ) && $_POST['dim_length'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_length'] ) ) : null;
        $dim_width          = isset( $_POST['dim_width'] )  && $_POST['dim_width']  !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_width'] ) )  : null;
        $dim_height         = isset( $_POST['dim_height'] ) && $_POST['dim_height'] !== '' ? sanitize_text_field( wp_unslash( $_POST['dim_height'] ) ) : null;

        if ( empty( $name ) || $price <= 0 ) {
            wp_send_json_error( 'Nombre y precio son requeridos', 400 );
        }

        // v2.9.62 DEEP-AUDIT-002 P2-6: Validar que la categoría existe.
        if ( $category_id && ! term_exists( $category_id, 'product_cat' ) ) {
            wp_send_json_error( 'Categoría inválida', 400 );
        }

        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_description( $description );
        $product->set_regular_price( $price );
        // CS-09: precio de oferta
        if ( $sale_price_raw !== null && $sale_price_raw > 0 && $sale_price_raw < $price ) {
            $product->set_sale_price( (string) $sale_price_raw );
        }
        $product->set_status( $status );
        if ( in_array( $catalog_visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $product->set_catalog_visibility( $catalog_visibility );
        }
        if ( $stock !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $stock );
        }
        if ( $weight !== null )     $product->set_weight( $weight );
        if ( $dim_length !== null ) $product->set_length( $dim_length );
        if ( $dim_width  !== null ) $product->set_width( $dim_width );
        if ( $dim_height !== null ) $product->set_height( $dim_height );
        if ( $category_id ) {
            $product->set_category_ids( [ $category_id ] );
        }
        if ( $image_id ) {
            $product->set_image_id( $image_id );
        }
        $gallery_ids = isset( $_POST['gallery_ids'] ) ? array_filter( array_map( 'intval', explode( ',', $_POST['gallery_ids'] ) ) ) : [];
        if ( ! empty( $gallery_ids ) ) { $product->set_gallery_image_ids( $gallery_ids ); }
        // Asignar al vendedor actual
        $product_id = $product->save();
        $current_user_id = get_current_user_id();
        wp_update_post( [ 'ID' => $product_id, 'post_author' => $current_user_id ] );
        // M-12 FIX: guardar _ltms_vendor_id para que los pedidos del producto
        // aparezcan en el dashboard del vendedor (get_vendor_orders filtra por esta meta).
        update_post_meta( $product_id, '_ltms_vendor_id', $current_user_id );

        // CS-05: guardar tipo (physical/digital/service/booking) para lógica de comisiones
        // Mapeo legacy: 'product' → 'physical'
        $product_type = sanitize_key( $_POST['product_type'] ?? 'physical' );
        if ( $product_type === 'product' || ! in_array( $product_type, [ 'physical', 'digital', 'service', 'booking' ], true ) ) {
            $product_type = 'physical';
        }
        update_post_meta( $product_id, '_ltms_product_type', $product_type );

        // CS-08: ReDi toggle + tasa con validación de rango
        if ( 'yes' === get_option( 'ltms_redi_enabled' ) ) {
            $redi_enabled = ( isset( $_POST['redi_enabled'] ) && 'yes' === sanitize_key( $_POST['redi_enabled'] ) ) // phpcs:ignore
                ? 'yes' : 'no';
            update_post_meta( $product_id, '_ltms_redi_enabled', $redi_enabled );
            if ( 'yes' === $redi_enabled && isset( $_POST['redi_rate'] ) ) { // phpcs:ignore
                $redi_rate_pct = (float) sanitize_text_field( wp_unslash( $_POST['redi_rate'] ) ); // phpcs:ignore
                $redi_rate = LTMS_Business_Redi_Manager::clamp_redi_rate( $redi_rate_pct / 100 );
                update_post_meta( $product_id, '_ltms_redi_rate', $redi_rate );
            }
        }

        // CS-07: commission_rate solo configurable por admin (LTMS_Commission_Strategy),
        // nunca desde el panel del vendedor. Se elimina la escritura desde el frontend.

        wp_send_json_success( [
            'product_id'   => $product_id,
            'product_type' => $product_type,
            'message'      => 'Producto creado exitosamente',
        ] );
    }


    public function toggle_product_status() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $new_status = sanitize_text_field( $_POST['new_status'] ?? '' );
        if ( ! in_array( $new_status, [ 'publish', 'draft', 'pending' ] ) ) {
            wp_send_json_error( 'Estado no valido', 400 );
        }
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado o sin permiso', 403 );
        }
        $product->set_status( $new_status );
        $product->save();
        wp_send_json_success( [ 'message' => 'Estado actualizado', 'status' => $new_status ] );
    }

    public function delete_product() {
        $this->check_nonce();
        $product_id = intval( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_post_data()->post_author != get_current_user_id() ) {
            wp_send_json_error( 'Producto no encontrado o sin permiso', 403 );
        }
        // v2.9.62 DEEP-AUDIT-002 P2-7: Usar wp_trash_post en vez de wp_delete_post(true).
        // Antes se hacía force-delete (true) lo que borraba permanentemente el producto
        // sin posibilidad de recuperación. Ahora va a la papelera de reciclaje.
        $result = wp_trash_post( $product_id );
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Producto movido a papelera' ] );
        } else {
            wp_send_json_error( 'No se pudo eliminar el producto' );
        }
    }

}

// Nota: LTMS_Products_Ajax se instancia en LTMS_Core_Kernel::boot_frontend().
// No instanciar aquí para evitar el registro triple de hooks AJAX.

// C5-2 FIX: Solo añadir 'read' para que WP procese el AJAX de productos.
// NO permitir acceso al wp-admin UI — solo wp-admin/admin-ajax.php.
add_filter( 'user_has_cap', function( $caps, $cap_list, $args ) {
    if ( ! empty( $caps['edit_products'] ) && LTMS_Utils::is_ltms_vendor( $args[1] ?? 0 ) ) {
        $caps['read'] = true;
    }
    return $caps;
}, 10, 3 );

// C5-2 FIX: Bloquear acceso al wp-admin para vendedores LTMS.
// WooCommerce llama a este filtro en admin_init; si devuelve true, redirige al frontend.
// Permitimos únicamente las peticiones AJAX (wp-admin/admin-ajax.php).
add_filter( 'woocommerce_prevent_admin_access', function( $prevent ) {
    if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
        // No es AJAX — si el usuario es vendedor LTMS, bloquear acceso al panel.
        if ( LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) {
            return true; // Prevenir acceso al wp-admin
        }
    }
    return $prevent;
} );

