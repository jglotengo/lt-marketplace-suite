<?php
/**
 * LTMS Dashboard Logic - Lógica del Panel de Vendedor
 *
 * Maneja toda la lógica PHP del dashboard SPA del vendedor:
 * - Renderizado del wrapper principal
 * - Handlers AJAX para las vistas del SPA
 * - Shortcode [ltms_vendor_dashboard]
 * - Handlers de formularios (producto, retiro, configuración)
 * - Endpoints REST para el panel
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Dashboard_Logic
 */
final class LTMS_Dashboard_Logic {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks de WordPress.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        // Shortcode del dashboard
        add_shortcode( 'ltms_vendor_dashboard', [ $instance, 'render_dashboard_shortcode' ] );
        add_shortcode( 'ltms_vendor_store',     [ $instance, 'render_store_shortcode' ] );
        add_shortcode( 'ltms_vendor_orders',    [ $instance, 'render_orders_shortcode' ] );
        add_shortcode( 'ltms_vendor_wallet',    [ $instance, 'render_wallet_shortcode' ] );
        add_shortcode( 'ltms_vendor_kyc',       [ $instance, 'render_kyc_shortcode' ] );
        add_shortcode( 'ltms_vendor_insurance', [ $instance, 'render_insurance_shortcode' ] );
        add_shortcode( 'ltms_vendor_bookings',  [ $instance, 'render_bookings_shortcode' ] );  // M-QA-PAGES-01
        add_shortcode( 'ltms_vendor_rnt',       [ $instance, 'render_rnt_shortcode' ] );       // M-QA-PAGES-01

        // AJAX handlers autenticados
        add_action( 'wp_ajax_ltms_get_dashboard_data',    [ $instance, 'ajax_get_dashboard_data' ] );
        add_action( 'wp_ajax_ltms_get_orders_data',       [ $instance, 'ajax_get_orders_data' ] );
        add_action( 'wp_ajax_ltms_get_order_detail',      [ $instance, 'ajax_get_order_detail' ] );
        add_action( 'wp_ajax_ltms_update_order_status',   [ $instance, 'ajax_update_order_status' ] );
        // NOTE M-6 FIX: ltms_get_wallet_data y ltms_request_payout ya están registrados
        // en LTMS_Frontend_Payout_Handler (handler completo). Eliminados aquí para evitar conflicto.
        add_action( 'wp_ajax_ltms_get_notifications',     [ $instance, 'ajax_get_notifications' ] );
        add_action( 'wp_ajax_ltms_mark_notification_read', [ $instance, 'ajax_mark_notification_read' ] );
        add_action( 'wp_ajax_ltms_get_analytics_data',    [ $instance, 'ajax_get_analytics_data' ] );
        add_action( 'wp_ajax_ltms_submit_kyc',            [ $instance, 'ajax_submit_kyc' ] );
        add_action( 'wp_ajax_ltms_upload_kyc_document',   [ $instance, 'ajax_upload_kyc_document' ] );

        // v1.6.0 — Nuevos módulos enterprise
        add_action( 'wp_ajax_ltms_get_insurance_data',    [ $instance, 'ajax_get_insurance_data' ] );
        add_action( 'wp_ajax_ltms_get_redi_data',         [ $instance, 'ajax_get_redi_data' ] );
        add_action( 'wp_ajax_ltms_adopt_redi_product',    [ $instance, 'ajax_adopt_redi_product' ] );
        add_action( 'wp_ajax_ltms_get_shipping_quotes',   [ $instance, 'ajax_get_shipping_quotes' ] );
        add_action( 'wp_ajax_nopriv_ltms_get_shipping_quotes', [ $instance, 'ajax_get_shipping_quotes' ] );

        // v2.9.31: Marketing — tracking de descargas de banners promocionales.
        add_action( 'wp_ajax_ltms_track_banner_download', [ $instance, 'ajax_track_banner_download' ] );

        // v2.9.31: PosGold — sincronización de catálogo.
        add_action( 'wp_ajax_ltms_save_posgold_credentials',   [ $instance, 'ajax_save_posgold_credentials' ] );
        add_action( 'wp_ajax_ltms_test_posgold_connection',     [ $instance, 'ajax_test_posgold_connection' ] );
        add_action( 'wp_ajax_ltms_sync_posgold_products',       [ $instance, 'ajax_sync_posgold_products' ] );
        add_action( 'wp_ajax_ltms_save_posgold_categories',     [ $instance, 'ajax_save_posgold_categories' ] );
        add_action( 'wp_ajax_ltms_save_posgold_rules',          [ $instance, 'ajax_save_posgold_rules' ] );
        add_action( 'wp_ajax_ltms_save_posgold_seo',            [ $instance, 'ajax_save_posgold_seo' ] );
        add_action( 'wp_ajax_ltms_get_posgold_categories',      [ $instance, 'ajax_get_posgold_categories' ] );

        // REST API endpoints del vendor dashboard
        add_action( 'rest_api_init', [ $instance, 'register_rest_routes' ] );
    }

    /**
     * Renderiza el shortcode del dashboard.
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML del dashboard.
     */

    public function render_store_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-home.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }

    public function render_orders_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-orders.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }

    public function render_wallet_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-wallet.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }

    public function render_kyc_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-kyc.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }

    public function render_insurance_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-insurance.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }
    /**
     * Shortcode [ltms_vendor_bookings] — M-QA-PAGES-01
     * Renderiza view-bookings.php directamente (sin pasar por el SPA del dashboard).
     * Útil si el admin crea una página con este shortcode como acceso directo.
     */
    public function render_bookings_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/view-bookings.php';
        if ( file_exists( $view_path ) ) include $view_path;
        return ob_get_clean();
    }

    /**
     * Shortcode [ltms_vendor_rnt] — M-QA-PAGES-01
     * Renderiza el formulario RNT/SECTUR de LTMS_Business_Tourism_Compliance.
     */
    public function render_rnt_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_login_redirect();
        if ( ! LTMS_Utils::is_ltms_vendor( get_current_user_id() ) ) return $this->render_not_vendor_notice();
        ob_start();
        if ( class_exists( 'LTMS_Business_Tourism_Compliance' ) ) {
            LTMS_Business_Tourism_Compliance::render_account_rnt_form();
        }
        return ob_get_clean();
    }

    public function render_dashboard_shortcode( array $atts = [] ): string {
        // Verificar que el usuario esté autenticado y sea vendedor
        if ( ! is_user_logged_in() ) {
            return $this->render_login_redirect();
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            return $this->render_not_vendor_notice();
        }

        ob_start();
        $view_path = LTMS_INCLUDES_DIR . 'frontend/views/dashboard-wrapper.php';
        if ( file_exists( $view_path ) ) {
            include $view_path;
        }
        return ob_get_clean();
    }

    /**
     * AJAX: Datos del Home del dashboard (métricas, ventas recientes).
     *
     * @return void
     */
    public function ajax_get_dashboard_data(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $data = $this->get_vendor_home_metrics( $user_id );
        wp_send_json_success( $data );
    }

    /**
     * AJAX: Datos de pedidos del vendedor con paginación.
     *
     * @return void
     */
    public function ajax_get_orders_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) ); // phpcs:ignore
        $per_page = min( 50, max( 10, (int) ( $_POST['per_page'] ?? 20 ) ) ); // phpcs:ignore
        $status   = sanitize_text_field( $_POST['status'] ?? '' ); // phpcs:ignore

        $orders = $this->get_vendor_orders( $user_id, $page, $per_page, $status );
        wp_send_json_success( $orders );
    }

    /**
     * AJAX: Datos de la billetera y movimientos.
     *
     * @return void
     */
    public function ajax_get_wallet_data(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $wallet       = LTMS_Business_Wallet::get_or_create( $user_id );
        $transactions = $this->get_wallet_transactions( $user_id );

        wp_send_json_success([
            'balance'      => (float) $wallet['balance'],
            'held'         => (float) ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 ),
            'available'    => (float) $wallet['balance'] - (float) ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 ),
            'currency'     => LTMS_Core_Config::get_currency(),
            'formatted'    => LTMS_Utils::format_money( (float) $wallet['balance'] ),
            'transactions' => $transactions,
        ]);
    }

    /**
     * AJAX: Solicitar retiro de fondos.
     *
     * @return void
     */
    public function ajax_request_payout(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id    = get_current_user_id();
        $amount     = (float) ( $_POST['amount'] ?? 0 ); // phpcs:ignore
        $account_id = sanitize_text_field( $_POST['bank_account_id'] ?? '' ); // phpcs:ignore
        $method     = sanitize_text_field( $_POST['method'] ?? 'bank_transfer' ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) || $amount <= 0 ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        $result = LTMS_Payout_Scheduler::create_request( $user_id, $amount, $account_id, $method );
        wp_send_json( $result );
    }

    /**
     * AJAX: Obtener notificaciones no leídas.
     *
     * @return void
     */
    public function ajax_get_notifications(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        // HI-8 FIX: capability check — notifications are vendor-only data.
        // Without this, any logged-in user could read the notification stream
        // for their own user_id (even if they are not a vendor) and pollute
        // the notifications table via other endpoints.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
        }

        $user_id = get_current_user_id();
        $since   = sanitize_text_field( $_POST['since'] ?? '' ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        $where_sql = 'WHERE user_id = %d AND is_read = 0';
        $args      = [ $user_id ];

        if ( $since ) {
            $where_sql .= ' AND created_at > %s';
            $args[]     = $since;
        }

        $args[] = 20; // LIMIT placeholder

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, title, message, data, created_at FROM `{$table}` {$where_sql} ORDER BY created_at DESC LIMIT %d",
                ...$args
            ),
            ARRAY_A
        );

        // M-15 FIX: devolver el total REAL de no leídas (sin filtro `since`) para que el badge
        // del topbar siempre refleje el número correcto y se ponga en 0 cuando no hay ninguna.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_unread = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND is_read = 0",
                $user_id
            )
        );

        wp_send_json_success([
            'notifications' => $notifications,
            'count'         => $total_unread,    // Total real para el badge del topbar
            'new_count'     => count( $notifications ), // Nuevas desde `since` para renderizar
        ]);
    }

    /**
     * AJAX: Marcar notificación como leída.
     *
     * @return void
     */
    public function ajax_mark_notification_read(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        // HI-8 FIX: capability check — marking notifications as read is a
        // vendor-only action.
        if ( ! current_user_can( 'ltms_vendor' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'ltms' ) ], 403 );
        }

        $user_id         = get_current_user_id();
        $notification_id = (int) ( $_POST['notification_id'] ?? 0 ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'is_read' => 1, 'read_at' => LTMS_Utils::now_utc() ],
            [ 'id' => $notification_id, 'user_id' => $user_id ],
            [ '%d', '%s' ],
            [ '%d', '%d' ]
        );

        wp_send_json_success();
    }


    /**
     * AJAX: Envía la solicitud de verificación KYC del vendedor.
     *
     * Inserta o actualiza el registro en lt_vendor_kyc y actualiza
     * el user meta ltms_kyc_status a 'pending'.
     *
     * @return void
     */
    /**
     * AJAX: Sube un documento KYC a Backblaze B2 (bucket lotengo-kyc-docs, privado).
     *
     * El JS llama a este endpoint ANTES de ltms_submit_kyc para obtener las rutas
     * de los archivos en el vault. Valida tipo/tamaño, sube con firma AWS Sig V4
     * y devuelve la ruta en el bucket para que el frontend la adjunte al submit.
     *
     * Bucket:  lotengo-kyc-docs (privado, endpoint s3.us-east-005.backblazeb2.com)
     * Key:     kyc/{vendor_id}/{timestamp}_{original_sanitized_name}
     * Acceso:  sólo vía URL pre-firmada (1 hora) — nunca URL pública directa.
     *
     * @return void  Responde con wp_send_json_success/error.
     */
    public function ajax_upload_kyc_document(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        // ── Validar archivo recibido ──────────────────────────────────────────
        if ( empty( $_FILES['kyc_doc'] ) || UPLOAD_ERR_OK !== $_FILES['kyc_doc']['error'] ) { // phpcs:ignore
            $upload_err = $_FILES['kyc_doc']['error'] ?? -1; // phpcs:ignore
            $err_map = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el límite del formulario.',
                UPLOAD_ERR_NO_FILE    => 'No se recibió ningún archivo.',
            ];
            wp_send_json_error( $err_map[ $upload_err ] ?? 'Error al recibir el archivo (código ' . $upload_err . ').' );
        }

        $tmp_path  = $_FILES['kyc_doc']['tmp_name']; // phpcs:ignore
        $orig_name = sanitize_file_name( $_FILES['kyc_doc']['name'] ); // phpcs:ignore
        $file_size = (int) $_FILES['kyc_doc']['size']; // phpcs:ignore

        // Máx 10 MB
        if ( $file_size > 10 * 1024 * 1024 ) {
            wp_send_json_error( __( 'El archivo supera el límite de 10 MB.', 'ltms' ) );
        }

        // Tipos permitidos: imagen o PDF
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ];
        $mime          = mime_content_type( $tmp_path );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_send_json_error( __( 'Tipo de archivo no permitido. Solo JPG, PNG, WEBP o PDF.', 'ltms' ) );
        }

        // ── Leer contenido binario ────────────────────────────────────────────
        $content = file_get_contents( $tmp_path ); // phpcs:ignore
        if ( false === $content ) {
            wp_send_json_error( __( 'No se pudo leer el archivo temporal.', 'ltms' ) );
        }

        // ── Construir clave en el bucket ──────────────────────────────────────
        $ext       = pathinfo( $orig_name, PATHINFO_EXTENSION );
        $safe_name = preg_replace( '/[^a-zA-Z0-9_\-]/', '_', pathinfo( $orig_name, PATHINFO_FILENAME ) );
        $key       = sprintf( 'kyc/%d/%d_%s.%s', $vendor_id, time(), $safe_name, $ext );

        // ── Subir a Backblaze B2 ──────────────────────────────────────────────
        try {
            $b2 = new LTMS_Api_Backblaze();

            // Bucket KYC privado — lotengo-kyc-docs
            $kyc_bucket = LTMS_Core_Config::get( 'ltms_backblaze_kyc_bucket', 'lotengo-kyc-docs' );

            $result = $b2->upload_file(
                $kyc_bucket,
                $key,
                $content,
                $mime,
                [
                    'vendor-id'   => (string) $vendor_id,
                    'doc-type'    => 'kyc',
                    'upload-date' => gmdate( 'Y-m-d' ),
                ]
            );

            // Log vault de acceso
            if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
                LTMS_Legal_Compliance::log_vault_access(
                    $vendor_id,
                    $vendor_id,
                    'kyc_document_upload',
                    LTMS_Legal_Compliance::VAULT_OP_UPLOAD,
                    'ajax_upload_kyc_document'
                );
            }

            wp_send_json_success( [
                'file_path' => $result['Key'],
                'vault_url' => $result['Location'],
                'bucket'    => $result['Bucket'],
                'mime'      => $mime,
                'size'      => $file_size,
            ] );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'KYC_UPLOAD_FAILED',
                sprintf( 'Error subiendo doc KYC para vendor #%d: %s', $vendor_id, $e->getMessage() ),
                [ 'vendor_id' => $vendor_id, 'key' => $key ]
            );
            wp_send_json_error(
                __( 'Error al almacenar el documento. Por favor intenta de nuevo.', 'ltms' ) .
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ? ' (' . $e->getMessage() . ')' : '' )
            );
        }
    }

    public function ajax_submit_kyc(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_kyc';

        // Sanitise inputs
        $full_name       = sanitize_text_field( wp_unslash( $_POST['full_name']       ?? '' ) ); // phpcs:ignore
        $document_type   = sanitize_key(        $_POST['document_type']   ?? 'cc' );             // phpcs:ignore
        $document_number = sanitize_text_field( wp_unslash( $_POST['document_number'] ?? '' ) ); // phpcs:ignore
        $file_path        = sanitize_text_field( wp_unslash( $_POST['file_path']        ?? '' ) ); // phpcs:ignore
        $file_path_rut    = sanitize_text_field( wp_unslash( $_POST['file_path_rut']    ?? '' ) ); // phpcs:ignore
        $file_path_camara = sanitize_text_field( wp_unslash( $_POST['file_path_camara'] ?? '' ) ); // phpcs:ignore
        // KYC-BANCO-1: Certificación bancaria — representante legal
        $file_path_banco     = sanitize_text_field( wp_unslash( $_POST['file_path_banco']     ?? '' ) ); // phpcs:ignore
        $bank_rep_legal_name = sanitize_text_field( wp_unslash( $_POST['bank_rep_legal_name'] ?? '' ) ); // phpcs:ignore
        $bank_name           = sanitize_text_field( wp_unslash( $_POST['bank_name']           ?? '' ) ); // phpcs:ignore
        $bank_account_number = sanitize_text_field( wp_unslash( $_POST['bank_account_number'] ?? '' ) ); // phpcs:ignore

        $allowed_types = [ 'cc', 'ce', 'nit', 'passport' ];
        if ( ! in_array( $document_type, $allowed_types, true ) ) {
            $document_type = 'cc';
        }

        if ( empty( $full_name ) || empty( $document_number ) ) {
            wp_send_json_error( __( 'El nombre completo y número de documento son obligatorios.', 'ltms' ) );
        }

        // KYC-BANCO-1: Validar datos bancarios obligatorios (certificación representante legal)
        if ( empty( $bank_rep_legal_name ) || empty( $bank_name ) || empty( $bank_account_number ) || empty( $file_path_banco ) ) {
            wp_send_json_error( __( 'La certificación bancaria es obligatoria: nombre del representante legal, entidad bancaria, número de cuenta y archivo del certificado.', 'ltms' ) );
        }

        // Block re-submission if already approved or pending
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM `{$table}` WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
            $vendor_id
        ) );

        if ( $existing && in_array( $existing->status, [ 'approved', 'pending' ], true ) ) {
            $msg = 'pending' === $existing->status
                ? __( 'Ya tienes una solicitud en revisión.', 'ltms' )
                : __( 'Tu identidad ya fue verificada.', 'ltms' );
            wp_send_json_error( $msg );
        }

        // Insert KYC record
        $inserted = $wpdb->insert( $table, [
            'vendor_id'       => $vendor_id,
            'document_type'   => $document_type,
            'document_number' => $document_number,
            'full_name'       => $full_name,
            'file_path'       => $file_path,
            'status'          => 'pending',
            'submitted_at'    => current_time( 'mysql' ),
            'country_code'    => defined( 'LTMS_COUNTRY' ) ? LTMS_COUNTRY : 'CO',
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

        if ( false === $inserted ) {
            wp_send_json_error( __( 'Error al guardar la solicitud. Intenta de nuevo.', 'ltms' ) );
        }

        // Sync user meta so dashboard/settings show correct status immediately
        update_user_meta( $vendor_id, 'ltms_kyc_status', 'pending' );
        update_user_meta( $vendor_id, 'ltms_full_name',   $full_name );

        // M-120: guardar documentos adicionales (RUT, Cámara de Comercio) como user_meta
        $file_path_rut    = sanitize_text_field( wp_unslash( $_POST['file_path_rut']    ?? '' ) ); // phpcs:ignore
        $file_path_camara = sanitize_text_field( wp_unslash( $_POST['file_path_camara'] ?? '' ) ); // phpcs:ignore
        if ( $file_path_rut )    update_user_meta( $vendor_id, 'ltms_kyc_file_rut',    $file_path_rut );
        if ( $file_path_camara ) update_user_meta( $vendor_id, 'ltms_kyc_file_camara', $file_path_camara );
        // KYC-BANCO-1: guardar certificación bancaria y datos del representante legal
        if ( $file_path_banco )     update_user_meta( $vendor_id, 'ltms_kyc_file_banco',         $file_path_banco );
        if ( $bank_rep_legal_name ) update_user_meta( $vendor_id, 'ltms_kyc_bank_rep_legal',     $bank_rep_legal_name );
        if ( $bank_name )           update_user_meta( $vendor_id, 'ltms_kyc_bank_name',          $bank_name );
        if ( $bank_account_number ) update_user_meta( $vendor_id, 'ltms_kyc_bank_account',       $bank_account_number );
        // Snapshot de titularidad: debe coincidir con el representante legal al momento del KYC
        update_user_meta( $vendor_id, 'ltms_kyc_bank_verified_at', current_time( 'mysql', true ) );

        // L-8: registrar consentimiento de Habeas Data con timestamp e IP (trazabilidad legal)
        $privacy_consent = sanitize_text_field( wp_unslash( $_POST['privacy_consent'] ?? '' ) ); // phpcs:ignore
        if ( '1' === $privacy_consent ) {
            update_user_meta( $vendor_id, 'ltms_kyc_consent',      '1' );
            update_user_meta( $vendor_id, 'ltms_kyc_consent_date', gmdate( 'Y-m-d H:i:s' ) );
            update_user_meta( $vendor_id, 'ltms_kyc_consent_ip',   sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
            update_user_meta( $vendor_id, 'ltms_kyc_consent_ver',  '1581-2012-v1' ); // versión de la política aceptada
        }


        // L-1/L-6/L-8 FIX: Log de vault (Habeas Data) + consentimiento KYC.
        // Ley 1581/2012, arts. 9 y 15 — trazabilidad de acceso a datos sensibles y
        // registro del consentimiento explícito de tratamiento de datos personales.
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            $vendor_id_for_log = $vendor_id ?? get_current_user_id();
            // K-03 FIX: firma correcta = log_vault_access(user_id, accessor_id, document, action, context).
            // Antes: (vendor_id, 'cedula', VAULT_OP_UPLOAD, vendor_id) → 2° arg era string, no int.
            LTMS_Legal_Compliance::log_vault_access( $vendor_id_for_log, $vendor_id_for_log, 'cedula', LTMS_Legal_Compliance::VAULT_OP_UPLOAD, 'kyc_submit' );
            if ( ! empty( $file_path_rut ) ) {
                LTMS_Legal_Compliance::log_vault_access( $vendor_id_for_log, $vendor_id_for_log, 'rut', LTMS_Legal_Compliance::VAULT_OP_UPLOAD, 'kyc_submit' );
            }
            if ( ! empty( $file_path_camara ) ) {
                LTMS_Legal_Compliance::log_vault_access( $vendor_id_for_log, $vendor_id_for_log, 'camara_comercio', LTMS_Legal_Compliance::VAULT_OP_UPLOAD, 'kyc_submit' );
            }
            if ( ! empty( $file_path_banco ) ) {
                LTMS_Legal_Compliance::log_vault_access( $vendor_id_for_log, $vendor_id_for_log, 'certificacion_bancaria', LTMS_Legal_Compliance::VAULT_OP_UPLOAD, 'kyc_submit' );
            }
            LTMS_Legal_Compliance::log_consent(
                $vendor_id_for_log,
                LTMS_Legal_Compliance::PURPOSE_KYC,
                'KYC submission — vendor dashboard — ' . LTMS_Utils::now_utc(),
                true
            );
        }

        wp_send_json_success( [ 'message' => __( 'Solicitud enviada. Recibirás una respuesta en 1-2 días hábiles.', 'ltms' ) ] );
    }

    /**
     * AJAX: Datos analíticos del vendedor (gráficas).
     *
     * @return void
     */
    public function ajax_get_analytics_data(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $period  = sanitize_text_field( $_POST['period'] ?? 'month' ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $data = $this->build_analytics_chart_data( $user_id, $period );
        wp_send_json_success( $data );
    }

    /**
     * Registra los endpoints REST del dashboard del vendedor.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        register_rest_route( 'ltms/v1', '/vendor/metrics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_metrics' ],
            'permission_callback' => fn() => LTMS_Utils::is_ltms_vendor( get_current_user_id() ),
        ]);
    }

    /**
     * REST: Métricas del vendedor.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function rest_get_metrics( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $data    = $this->get_vendor_home_metrics( $user_id );
        return new \WP_REST_Response( $data, 200 );
    }

    // ── Helpers privados de datos ──────────────────────────────────

    /**
     * Construye las métricas de la home del dashboard.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array
     */
    private function get_vendor_home_metrics( int $vendor_id ): array {
        global $wpdb;

        $commissions_table = $wpdb->prefix . 'lt_commissions';
        $now               = LTMS_Utils::now_utc();
        $month_start       = gmdate( 'Y-m-01 00:00:00' );

        // Ventas del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_sales = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(gross_amount) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Total de pedidos del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_orders = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Comisiones del mes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $monthly_commissions = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(vendor_amount) FROM `{$commissions_table}` WHERE vendor_id = %d AND created_at >= %s",
                $vendor_id, $month_start
            )
        );

        // Billetera
        $wallet = LTMS_Business_Wallet::get_or_create( $vendor_id );

        return [
            'monthly_sales'       => $monthly_sales,
            'monthly_orders'      => $monthly_orders,
            'monthly_commissions' => $monthly_commissions,
            'wallet_balance'      => (float) $wallet['balance'],
            'wallet_held'         => (float) ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 ),
            'currency'            => LTMS_Core_Config::get_currency(),
            'commission_rate_summary' => class_exists( 'LTMS_Commission_Strategy' )
                ? LTMS_Commission_Strategy::get_rate_summary( $vendor_id )
                : [],
            'onboarding'          => $this->get_onboarding_status( $vendor_id ),
        ];
    }

    /**
     * M-AUDIT-REG-07: estado de onboarding del vendedor para el banner
     * informativo del home. Puramente de lectura — no bloquea nada.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array{
     *     email_verified: bool,
     *     kyc_status: string,
     *     kyc_url: string,
     *     has_products: bool,
     *     all_done: bool
     * }
     */
    private function get_onboarding_status( int $vendor_id ): array {
        global $wpdb;

        $email_verified = (bool) get_user_meta( $vendor_id, 'ltms_email_verified', true );

        $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kyc_status = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM `{$kyc_table}` WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
                $vendor_id
            )
        );
        $kyc_status = $kyc_status ?: 'none';

        $has_products = (bool) count_user_posts( $vendor_id, 'product', true );

        $pages   = get_option( 'ltms_installed_pages', [] );
        $kyc_url = ! empty( $pages['ltms-kyc'] )
            ? get_permalink( $pages['ltms-kyc'] )
            : wc_get_account_endpoint_url( 'ltms-kyc' );

        $all_done = $email_verified && 'approved' === $kyc_status && $has_products;

        return [
            'email_verified' => $email_verified,
            'kyc_status'     => $kyc_status,
            'kyc_url'        => $kyc_url,
            'has_products'   => $has_products,
            'all_done'       => $all_done,
        ];
    }

    /**
     * Obtiene los pedidos del vendedor paginados.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param int    $page      Página.
     * @param int    $per_page  Items por página.
     * @param string $status    Filtro de estado.
     * @return array
     */
    private function get_vendor_orders( int $vendor_id, int $page, int $per_page, string $status ): array {
        // AUDIT-REDI-UX-GAPS GAP-7 FIX: usar meta_query OR para retornar
        // también pedidos donde el vendor es el origin ReDi (no solo el
        // reseller). Antes el origin vendor nunca veía pedidos ReDi en su
        // dashboard a pesar de ser quien debe enviar el producto.
        $args = [
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => '_ltms_vendor_id',
                    'value'   => $vendor_id,
                    'compare' => '=',
                ],
                [
                    'key'     => '_ltms_redi_origin_vendor_id',
                    'value'   => $vendor_id,
                    'compare' => '=',
                ],
            ],
            'limit'       => $per_page,
            'paged'       => $page,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'type'        => 'shop_order',
            'paginate'    => true,
        ];

        if ( $status ) {
            $args['status'] = sanitize_text_field( $status );
        }

        $result       = wc_get_orders( $args );
        $orders_query = $result->orders ?? [];
        $orders       = [];

        foreach ( $orders_query as $order ) {
            // P-01: detectar si el método de envío es pickup para mostrar icono y datos de tienda.
            $is_pickup       = false;
            $shipping_label  = '';
            foreach ( $order->get_shipping_methods() as $method ) {
                if ( str_contains( $method->get_method_id(), 'ltms_pickup' ) ) {
                    $is_pickup      = true;
                    $shipping_label = __( 'Recogida en Tienda', 'ltms' );
                    break;
                }
                $shipping_label = $method->get_name();
            }

            $order_vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            $store_info      = ( $is_pickup && $order_vendor_id )
                ? LTMS_Business_Pickup_Handler::get_vendor_store_info( $order_vendor_id )
                : [];

            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

            // AUDIT-REDI-UX-GAPS GAP-7: determinar el rol ReDi del vendor
            // en este pedido (origin, reseller, o null si no es ReDi).
            $redi_origin_id = (int) $order->get_meta( '_ltms_redi_origin_vendor_id' );
            $redi_role      = null;
            $is_redi        = false;
            if ( $redi_origin_id > 0 ) {
                $is_redi = true;
                if ( $redi_origin_id === $vendor_id ) {
                    $redi_role = 'origin';
                } elseif ( $order_vendor_id === $vendor_id ) {
                    $redi_role = 'reseller';
                }
            }

            // AUDIT-REDI-UX-GAPS GAP-7: PII masking para el reseller.
            // El reseller NO necesita la dirección completa del cliente
            // (el origin vendor envía directamente). El reseller solo ve
            // nombre + ciudad. El origin vendor ve la dirección completa.
            $customer_city = $order->get_billing_city();
            $masked_customer = $customer_name;
            if ( $redi_role === 'reseller' && $customer_city ) {
                $masked_customer .= ' (' . $customer_city . ')';
            }

            $orders[] = [
                'id'             => $order->get_id(),
                'number'         => $order->get_order_number(),
                'status'         => $order->get_status(),
                'total'          => (float) $order->get_total(),
                'formatted'      => LTMS_Utils::format_money( (float) $order->get_total() ),
                'customer'       => $masked_customer !== '' ? $masked_customer : __( 'Cliente sin nombre', 'ltms' ),
                'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                'items_count'    => count( $order->get_items() ),
                'is_pickup'      => $is_pickup,
                'shipping_label' => $shipping_label ?: __( 'Envío estándar', 'ltms' ),
                'store_info'     => $store_info,
                // AUDIT-REDI-UX-GAPS GAP-7: campos ReDi para el frontend.
                'is_redi'        => $is_redi,
                'redi_role'      => $redi_role,
                'redi_origin_id' => $redi_origin_id,
                'redi_reseller_id' => $order_vendor_id,
            ];
        }

        $total_results = (int) ( $result->total ?? count( $orders ) );

        return [
            'orders'        => $orders,
            'total'         => $total_results,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => (int) ( $result->max_num_pages ?? max( 1, (int) ceil( $total_results / max( 1, $per_page ) ) ) ),
        ];
    }

    /**
     * AJAX: Detalle completo de un pedido del vendedor (para el modal de detalle).
     *
     * Verifica ownership (el pedido debe pertenecer al vendedor autenticado)
     * antes de devolver ítems, direcciones, totales y notas.
     *
     * @return void
     */
    public function ajax_get_order_detail(): void {
                // SEC-4 FIX (v2.9.26): auth required.
                if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id  = get_current_user_id();
        $order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore

        if ( ! $order_id || ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        $order = wc_get_order( $order_id );
        // AUDIT-REDI-UX-GAPS GAP-7 FIX: permitir acceso al origin vendor
        // además del reseller. Antes solo `_ltms_vendor_id === user_id`
        // bloqueaba al origin vendor de ver el detalle del pedido ReDi.
        $order_vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        $redi_origin_id  = (int) $order->get_meta( '_ltms_redi_origin_vendor_id' );
        if ( ! $order || ( $order_vendor_id !== $user_id && $redi_origin_id !== $user_id ) ) {
            wp_send_json_error( __( 'Pedido no encontrado o no te pertenece.', 'ltms' ), 404 );
        }

        // AUDIT-REDI-UX-GAPS GAP-7: determinar rol ReDi para PII masking.
        $redi_role = null;
        if ( $redi_origin_id > 0 ) {
            if ( $redi_origin_id === $user_id ) {
                $redi_role = 'origin';
            } elseif ( $order_vendor_id === $user_id ) {
                $redi_role = 'reseller';
            }
        }

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'qty'      => $item->get_quantity(),
                'subtotal' => LTMS_Utils::format_money( (float) $item->get_subtotal() ),
                'total'    => LTMS_Utils::format_money( (float) $item->get_total() ),
                'sku'      => $product ? $product->get_sku() : '',
            ];
        }

        $is_pickup      = false;
        $shipping_label = '';
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( str_contains( $method->get_method_id(), 'ltms_pickup' ) ) {
                $is_pickup      = true;
                $shipping_label = __( 'Recogida en Tienda', 'ltms' );
                break;
            }
            $shipping_label = $method->get_name();
        }
        $store_info = $is_pickup ? LTMS_Business_Pickup_Handler::get_vendor_store_info( $user_id ) : [];

        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

        // AUDIT-REDI-UX-GAPS GAP-7: PII masking para el reseller en el detalle.
        // El reseller NO ve la dirección de envío completa, email ni teléfono
        // del cliente (el origin vendor envía directamente). Solo ve nombre
        // + ciudad. El origin vendor ve TODO.
        $billing_address   = trim( $order->get_formatted_billing_address() ?: '' );
        $shipping_address  = trim( $order->get_formatted_shipping_address() ?: '' );
        $customer_email    = $order->get_billing_email();
        $customer_phone    = $order->get_billing_phone();

        if ( $redi_role === 'reseller' ) {
            $billing_address  = $order->get_billing_city() ?: '';
            $shipping_address = $order->get_shipping_city() ?: '';
            $customer_email   = '';
            $customer_phone   = '';
            if ( $customer_name && $billing_address ) {
                $customer_name .= ' (' . $billing_address . ')';
            }
        }

        // Notas del pedido visibles al cliente (no las internas/privadas del admin).
        $notes = [];
        foreach ( wc_get_order_notes( [ 'order_id' => $order_id, 'type' => 'customer' ] ) as $note ) {
            $notes[] = [
                'content' => $note->content,
                'date'    => $note->date_created ? $note->date_created->date( 'Y-m-d H:i' ) : '',
            ];
        }

        wp_send_json_success( [
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            'status_label'    => wc_get_order_status_name( $order->get_status() ),
            'date'            => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
            'customer'        => $customer_name !== '' ? $customer_name : __( 'Cliente sin nombre', 'ltms' ),
            'customer_email'  => $customer_email,
            'customer_phone'  => $customer_phone,
            'billing_address' => $billing_address,
            'shipping_address'=> $shipping_address,
            'is_pickup'       => $is_pickup,
            'shipping_label'  => $shipping_label ?: __( 'Envío estándar', 'ltms' ),
            'store_info'      => $store_info,
            'items'           => $items,
            'subtotal'        => LTMS_Utils::format_money( (float) $order->get_subtotal() ),
            'shipping_total'  => LTMS_Utils::format_money( (float) $order->get_shipping_total() ),
            'total'           => LTMS_Utils::format_money( (float) $order->get_total() ),
            'customer_note'   => $order->get_customer_note(),
            'notes'           => $notes,
            // AUDIT-REDI-UX-GAPS GAP-7: campos ReDi para el frontend.
            'is_redi'         => $redi_origin_id > 0,
            'redi_role'       => $redi_role,
            'allowed_transitions' => $this->get_allowed_status_transitions( $order->get_status() ),
            'edit_url'        => $order->get_edit_order_url(),
        ] );
    }

    /**
     * Transiciones de estado que un vendedor puede aplicar manualmente desde el panel.
     * Whitelist intencionalmente conservadora: nunca permite saltar a 'refunded' ni
     * reabrir un pedido 'completed'/'cancelled' (eso requiere flujo de reembolso aparte).
     *
     * @param string $current_status Estado actual del pedido (sin prefijo 'wc-').
     * @return array<string> Estados destino permitidos.
     */
    private function get_allowed_status_transitions( string $current_status ): array {
        $map = [
            'pending'           => [ 'processing', 'cancelled' ],
            'on-hold'           => [ 'processing', 'cancelled' ],
            'processing'        => [ 'completed', 'cancelled' ],
            'ready-for-pickup'  => [ 'completed', 'cancelled' ],
        ];

        return $map[ $current_status ] ?? [];
    }

    /**
     * AJAX: Cambiar el estado de un pedido propio, respetando la whitelist
     * de transiciones permitidas (ver get_allowed_status_transitions()).
     *
     * @return void
     */
    public function ajax_update_order_status(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $user_id    = get_current_user_id();
        $order_id   = absint( $_POST['order_id'] ?? 0 );    // phpcs:ignore
        $new_status = sanitize_text_field( $_POST['status'] ?? '' ); // phpcs:ignore

        if ( ! $order_id || ! $new_status || ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        $order = wc_get_order( $order_id );
        // AUDIT-REDI-UX-GAPS GAP-7 FIX: permitir al origin vendor cambiar
        // estado del pedido ReDi (ej: marcar como completado cuando envía).
        $order_vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        $redi_origin_id  = (int) $order->get_meta( '_ltms_redi_origin_vendor_id' );
        if ( ! $order || ( $order_vendor_id !== $user_id && $redi_origin_id !== $user_id ) ) {
            wp_send_json_error( __( 'Pedido no encontrado o no te pertenece.', 'ltms' ), 404 );
        }

        $allowed = $this->get_allowed_status_transitions( $order->get_status() );
        if ( ! in_array( $new_status, $allowed, true ) ) {
            wp_send_json_error( __( 'Esa transición de estado no está permitida desde el estado actual.', 'ltms' ) );
        }

        $order->update_status( $new_status, __( 'Cambiado por el vendedor desde el panel.', 'ltms' ) );

        wp_send_json_success( [
            'message'      => __( 'Estado actualizado.', 'ltms' ),
            'status'       => $order->get_status(),
            'status_label' => wc_get_order_status_name( $order->get_status() ),
        ] );
    }

    /**
     * Obtiene los movimientos de la billetera del vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     * @return array
     */
    private function get_wallet_transactions( int $vendor_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, amount, description, created_at FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
                $vendor_id
            ),
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            $row['formatted_amount'] = LTMS_Utils::format_money( (float) $row['amount'] );
        }

        return $rows;
    }

    /**
     * Construye los datos para gráficas del vendedor.
     *
     * @param int    $vendor_id ID del vendedor.
     * @param string $period    Período.
     * @return array
     */
    private function build_analytics_chart_data( int $vendor_id, string $period ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commissions';

        // Últimos 12 meses o últimas 4 semanas
        $labels = [];
        $sales  = [];
        $commissions = [];

        if ( $period === 'month' ) {
            for ( $i = 11; $i >= 0; $i-- ) {
                $date    = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
                $labels[] = $date;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $sales[]  = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(gross_amount) FROM `{$table}` WHERE vendor_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
                    $vendor_id, $date
                ));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $commissions[] = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(vendor_amount) FROM `{$table}` WHERE vendor_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
                    $vendor_id, $date
                ));
            }
        }

        return [
            'labels'      => $labels,
            'sales'       => $sales,
            'commissions' => $commissions,
        ];
    }

    // ── v1.6.0 AJAX handlers ──────────────────────────────────────

    /**
     * AJAX: Datos de pólizas de seguro del vendedor.
     *
     * @return void
     */
    public function ajax_get_insurance_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_insurance_policies';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $policies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_id, policy_id, policy_number, certificate_url, insurance_type, premium_amount, currency, status, created_at FROM `{$table}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 20",
                $user_id
            ),
            ARRAY_A
        );

        wp_send_json_success( [ 'policies' => $policies ] );
    }

    /**
     * AJAX: Datos ReDi del vendedor (acuerdos + comisiones).
     *
     * @return void
     */
    public function ajax_get_redi_data(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( __( 'Acceso denegado.', 'ltms' ), 403 );
        }

        global $wpdb;
        // My active ReDi agreements (as reseller)
        $agreements = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT a.*, p.post_title AS origin_product_name FROM `{$wpdb->prefix}lt_redi_agreements` a LEFT JOIN `{$wpdb->posts}` p ON a.origin_product_id = p.ID WHERE a.reseller_vendor_id = %d AND a.status = 'active' LIMIT 50",
                $user_id
            ),
            ARRAY_A
        );

        // Origin products available for ReDi adoption
        $available = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT p.ID, p.post_title, pm.meta_value AS redi_rate FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` pm ON p.ID = pm.post_id AND pm.meta_key = '_ltms_redi_rate' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value != '' LIMIT 50",
            ARRAY_A
        );

        wp_send_json_success( [ 'agreements' => $agreements, 'available_products' => $available ] );
    }

    /**
     * AJAX: Adoptar un producto ReDi (como revendedor).
     *
     * @return void
     */
    public function ajax_adopt_redi_product(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
        $user_id    = get_current_user_id();
        $product_id = absint( $_POST['origin_product_id'] ?? 0 ); // phpcs:ignore

        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) || ! $product_id ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        if ( ! class_exists( 'LTMS_Business_Redi_Manager' ) ) {
            wp_send_json_error( __( 'Módulo ReDi no disponible.', 'ltms' ) );
        }

        try {
            $new_pid = LTMS_Business_Redi_Manager::adopt_product( $user_id, $product_id );
            wp_send_json_success( [
                'product_id' => $new_pid,
                'message'    => __( 'Producto adoptado como ReDi exitosamente.', 'ltms' ),
            ] );
        } catch ( \Throwable $e ) {
            // HI-9 FIX: do not leak the raw exception message to the client.
            // The real error is logged via LTMS_Core_Logger with full context.
            LTMS_Core_Logger::error(
                'REDI_ADOPT_FAILED',
                $e->getMessage(),
                [ 'user_id' => $user_id, 'product_id' => $product_id, 'trace' => $e->getTraceAsString() ]
            );
            wp_send_json_error(
                [ 'message' => __( 'An error occurred. Please try again.', 'ltms' ) ],
                500
            );
        }
    }

    /**
     * AJAX: Cotizaciones de envío para comparación en checkout.
     *
     * @return void
     */
    public function ajax_get_shipping_quotes(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );

        $cart    = WC()->cart;
        $session = WC()->session;
        if ( ! $cart || ! $session ) {
            wp_send_json_error( __( 'Carrito no disponible.', 'ltms' ) );
        }

        $packages  = $cart->get_shipping_packages();
        $package   = reset( $packages );
        $quotes    = [];
        $providers = [
            'uber'      => [ 'class' => 'LTMS_Shipping_Method_Uber_Direct', 'label' => 'Uber Direct' ],
            'aveonline' => [ 'class' => 'LTMS_Shipping_Method_Aveonline',   'label' => 'Aveonline' ],
            'heka'      => [ 'class' => 'LTMS_Shipping_Method_Heka',        'label' => 'Heka Entrega' ],
            'pickup'    => [ 'class' => 'LTMS_Shipping_Method_Pickup',      'label' => 'Recogida en Tienda' ],
        ];

        foreach ( $providers as $slug => $info ) {
            if ( ! class_exists( $info['class'] ) ) continue;
            try {
                $method = new $info['class']();
                $method->calculate_shipping( $package ?? [] );
                $rates  = $method->get_rates_for_package( $package ?? [] );
                $rate   = reset( $rates );
                if ( $rate ) {
                    $quotes[ $slug ] = [
                        'price'         => (float) $rate->get_cost(),
                        'price_display' => LTMS_Utils::format_money( (float) $rate->get_cost() ),
                        'label'         => $rate->get_label(),
                        'rate_id'       => $rate->get_id(),
                    ];
                }
            } catch ( \Throwable $e ) {
                // Provider unavailable — skip silently
            }
        }

        // Pickup always available at $0
        if ( ! isset( $quotes['pickup'] ) ) {
            $quotes['pickup'] = [
                'price'         => 0,
                'price_display' => __( 'Gratis', 'ltms' ),
                'label'         => __( 'Recogida en Tienda', 'ltms' ),
                'rate_id'       => 'ltms_pickup',
            ];
        }

        wp_send_json_success( $quotes );
    }

    /**
     * Renderiza un mensaje de redirección al login.
     *
     * @return string
     */
    private function render_login_redirect(): string {
        $pages    = get_option( 'ltms_installed_pages', [] );
        $login_id = $pages['ltms-login'] ?? 0;
        $login_url = $login_id ? get_permalink( $login_id ) : wp_login_url( get_permalink() );

        return sprintf(
            '<div class="ltms-notice ltms-notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'Debes iniciar sesión para acceder al panel.', 'ltms' ),
            esc_url( $login_url ),
            esc_html__( 'Iniciar sesión', 'ltms' )
        );
    }

    /**
     * Renderiza un aviso para usuarios que no son vendedores.
     *
     * @return string
     */
    private function render_not_vendor_notice(): string {
        $current_user = wp_get_current_user();
        $user_roles   = (array) $current_user->roles;
        $is_admin     = in_array( 'administrator', $user_roles, true )
                     || in_array( 'editor', $user_roles, true );

        // Si es admin, mostrar mensaje contextual y link al WP-admin, no "regístrate"
        if ( $is_admin ) {
            return '<div class="ltms-notice ltms-notice-info" style="max-width:560px;margin:40px auto;padding:20px 24px;background:#f0f6fc;border-left:4px solid #0073aa;border-radius:6px;">'
                . '<p style="font-size:15px;margin:0 0 10px;font-weight:600;">👋 Hola, administrador</p>'
                . '<p style="margin:0 0 12px;">Estás viendo el panel del vendedor como administrador. '
                . 'Este panel es exclusivo para usuarios con rol <strong>ltms_vendor</strong> o <strong>ltms_vendor_premium</strong>.</p>'
                . '<p style="margin:0;"><a href="' . esc_url( admin_url( 'admin.php?page=ltms-dashboard' ) ) . '" class="button" style="margin-right:8px;">← Ir al Admin</a>'
                . '<a href="' . esc_url( admin_url( 'admin.php?page=ltms-vendors' ) ) . '" class="button button-primary">Ver Vendedores</a></p>'
                . '</div>';
        }

        $pages        = get_option( 'ltms_installed_pages', [] );
        $register_id  = $pages['ltms-vendor-register'] ?? 0;
        $register_url = $register_id ? get_permalink( $register_id ) : '';

        $msg = esc_html__( 'Esta página es exclusiva para vendedores registrados.', 'ltms' );
        if ( $register_url ) {
            $msg .= sprintf(
                ' <a href="%s">%s</a>',
                esc_url( $register_url ),
                esc_html__( 'Regístrate como vendedor', 'ltms' )
            );
        }

        return '<div class="ltms-notice ltms-notice-warning"><p>' . $msg . '</p></div>';
    }

    /**
     * v2.9.31 — AJAX: Track vendor download of promotional banner.
     *
     * Incrementa el contador download_count en lt_marketing_banners cuando
     * un vendedor descarga material promocional desde su dashboard.
     *
     * @return void
     */
    public function ajax_track_banner_download(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $banner_id = absint( $_POST['banner_id'] ?? 0 );
        if ( ! $banner_id ) {
            wp_send_json_error( [ 'message' => __( 'Banner ID inválido.', 'ltms' ) ], 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_marketing_banners';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET download_count = download_count + 1 WHERE id = %d",
                $banner_id
            )
        );
        // phpcs:enable

        wp_send_json_success( [ 'message' => __( 'Descarga registrada.', 'ltms' ) ] );
    }

    /**
     * v2.9.31 — AJAX: Guardar credenciales PosGold del vendor.
     */
    public function ajax_save_posgold_credentials(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $subdomain = sanitize_text_field( $_POST['subdomain'] ?? '' );
        $token     = sanitize_text_field( $_POST['token'] ?? '' );
        $empresaid = absint( $_POST['empresaid'] ?? 1 ) ?: 1;
        $usuarioid = absint( $_POST['usuarioid'] ?? 1 ) ?: 1;
        $bodegaid  = absint( $_POST['bodegaid']  ?? 1 ) ?: 1;

        if ( empty( $subdomain ) || empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Subdominio y Token son obligatorios.', 'ltms' ) ], 400 );
        }

        // Cifrar el token antes de guardarlo (si LTMS_Core_Security está disponible).
        $token_to_save = $token;
        if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'encrypt' ) ) {
            $encrypted = LTMS_Core_Security::encrypt( $token );
            if ( $encrypted ) {
                $token_to_save = $encrypted;
            }
        }

        update_user_meta( $user_id, 'ltms_posgold_subdomain', $subdomain );
        update_user_meta( $user_id, 'ltms_posgold_token',     $token_to_save );
        update_user_meta( $user_id, 'ltms_posgold_empresaid', $empresaid );
        update_user_meta( $user_id, 'ltms_posgold_usuarioid', $usuarioid );
        update_user_meta( $user_id, 'ltms_posgold_bodegaid',  $bodegaid );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'POSGOLD_CREDENTIALS_SAVED', sprintf( 'Vendor #%d guardó credenciales PosGold (subdomain=%s)', $user_id, $subdomain ) );
        }

        wp_send_json_success( [ 'message' => __( 'Credenciales guardadas correctamente.', 'ltms' ) ] );
    }

    /**
     * v2.9.31 — AJAX: Probar conexión PosGold del vendor.
     */
    public function ajax_test_posgold_connection(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_PosGold_Sync' ) || ! class_exists( 'LTMS_Api_PosGold' ) ) {
            wp_send_json_error( [ 'message' => __( 'Módulo PosGold no disponible.', 'ltms' ) ], 500 );
        }

        $creds = LTMS_PosGold_Sync::get_vendor_credentials( $user_id );
        if ( ! $creds['configured'] ) {
            wp_send_json_error( [ 'message' => __( 'No has configurado tus credenciales.', 'ltms' ) ], 400 );
        }

        $result = LTMS_Api_PosGold::test_connection(
            $creds['subdomain'],
            $creds['token'],
            $creds['empresaid'],
            $creds['usuarioid']
        );

        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * v2.9.31 — AJAX: Sincronizar productos PosGold → WooCommerce.
     */
    public function ajax_sync_posgold_products(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_PosGold_Sync' ) ) {
            wp_send_json_error( [ 'message' => __( 'Módulo PosGold no disponible.', 'ltms' ) ], 500 );
        }

        // Aumentar tiempo límite para sync grandes.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 ); // 10 minutos.
        }

        $result = LTMS_PosGold_Sync::sync_vendor_products( $user_id );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'message'      => $result['message'],
                'created'      => $result['created'],
                'updated'      => $result['updated'],
                'skipped'      => $result['skipped'],
                'duplicates'   => $result['duplicates'] ?? 0,
                'filtered_out' => $result['filtered_out'] ?? 0,
                'errors'       => $result['errors'],
            ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * v2.9.31 — AJAX: Guardar filtro de categorías PosGold del vendor.
     */
    public function ajax_save_posgold_categories(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        // Sanitizar comma-separated list de IDs.
        $raw       = sanitize_text_field( $_POST['category_ids'] ?? '' );
        $ids       = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $sanitized = [];
        foreach ( $ids as $id ) {
            if ( is_numeric( $id ) ) {
                $sanitized[] = (string) absint( $id );
            }
        }
        $clean = implode( ',', $sanitized );

        update_user_meta( $user_id, 'ltms_posgold_category_ids', $clean );

        wp_send_json_success( [
            'message' => __( 'Categorías guardadas correctamente.', 'ltms' ),
            'category_ids' => $clean,
        ] );
    }

    /**
     * v2.9.31 — AJAX: Guardar reglas de precio PosGold del vendor.
     */
    public function ajax_save_posgold_rules(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_PosGold_Price_Calculator' ) ) {
            wp_send_json_error( [ 'message' => __( 'Módulo PosGold no disponible.', 'ltms' ) ], 500 );
        }

        $rules = [
            'is_redi'                => sanitize_text_field( $_POST['is_redi'] ?? 'no' ) === 'yes',
            'transport_pct'          => (float) ( $_POST['transport_pct'] ?? 0 ),
            'advertising_pct'        => (float) ( $_POST['advertising_pct'] ?? 0 ),
            'returns_pct'            => (float) ( $_POST['returns_pct'] ?? 0 ),
            'margin_pct'             => (float) ( $_POST['margin_pct'] ?? 30 ),
            'lotengo_commission_pct' => (float) ( $_POST['lotengo_commission_pct'] ?? 10 ),
            'iva_pct'                => (float) ( $_POST['iva_pct'] ?? 19 ),
            'redi_cost_pct'          => (float) ( $_POST['redi_cost_pct'] ?? 0 ),
            'round_multiple'         => (int)   ( $_POST['round_multiple'] ?? 1000 ),
        ];

        // Validar rangos.
        $rules['transport_pct']          = max( 0, min( 100, $rules['transport_pct'] ) );
        $rules['advertising_pct']        = max( 0, min( 100, $rules['advertising_pct'] ) );
        $rules['returns_pct']            = max( 0, min( 100, $rules['returns_pct'] ) );
        $rules['margin_pct']             = max( 0, min( 500, $rules['margin_pct'] ) );
        $rules['lotengo_commission_pct'] = max( 0, min( 50, $rules['lotengo_commission_pct'] ) );
        $rules['redi_cost_pct']          = max( 0, min( 100, $rules['redi_cost_pct'] ) );
        $rules['round_multiple']         = max( 1, $rules['round_multiple'] );

        LTMS_PosGold_Price_Calculator::save_vendor_rules( $user_id, $rules );

        wp_send_json_success( [ 'message' => __( 'Reglas de precio guardadas correctamente.', 'ltms' ) ] );
    }

    /**
     * v2.9.31 — AJAX: Guardar plantilla SEO PosGold del vendor.
     */
    public function ajax_save_posgold_seo(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        $template = sanitize_text_field( $_POST['seo_template'] ?? '' );
        if ( empty( $template ) ) {
            $template = '{nombre} {marca} {categoria}';
        }

        // Validar que solo contenga placeholders permitidos y texto plano.
        $allowed_placeholders = [ '{nombre}', '{marca}', '{categoria}', '{modelo}', '{codigo}' ];
        $check                = $template;
        foreach ( $allowed_placeholders as $ph ) {
            $check = str_replace( $ph, '', $check );
        }
        // Si después de quitar placeholders quedan caracteres raros, rechazar.
        if ( preg_match( '/[<>{}]/', $check ) ) {
            wp_send_json_error( [ 'message' => __( 'Plantilla inválida. Solo se permiten los placeholders {nombre}, {marca}, {categoria}, {modelo}, {codigo} y texto plano.', 'ltms' ) ], 400 );
        }

        update_user_meta( $user_id, 'ltms_posgold_seo_template', $template );

        wp_send_json_success( [ 'message' => __( 'Plantilla SEO guardada correctamente.', 'ltms' ) ] );
    }

    /**
     * v2.9.31 — AJAX: Obtener categorías PosGold del vendor (para dropdown).
     *
     * Llama a LTMS_Api_PosGold::get_categories() que intenta primero el
     * endpoint dedicado /apiGold/CategoriaApi/GetCategoria y hace fallback
     * extrayendo categorías de los productos si aquel no existe.
     *
     * Cachea el resultado 1 hora para no llamar a la API en cada render.
     *
     * @return void
     */
    public function ajax_get_posgold_categories(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 );
        }

        $user_id = get_current_user_id();
        if ( ! LTMS_Utils::is_ltms_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Acceso denegado.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_PosGold_Sync' ) || ! class_exists( 'LTMS_Api_PosGold' ) ) {
            wp_send_json_error( [ 'message' => __( 'Módulo PosGold no disponible.', 'ltms' ) ], 500 );
        }

        $creds = LTMS_PosGold_Sync::get_vendor_credentials( $user_id );
        if ( ! $creds['configured'] ) {
            wp_send_json_error( [ 'message' => __( 'No has configurado tus credenciales PosGold.', 'ltms' ) ], 400 );
        }

        // Cache transitorio (1 hora) — las categorías no cambian frecuentemente.
        $cache_key = 'ltms_posgold_cats_' . $user_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            wp_send_json_success( [
                'categories' => $cached,
                'source'     => 'cache',
                'message'    => __( 'Categorías cargadas desde cache.', 'ltms' ),
            ] );
        }

        // Forzar refresco si se pidió.
        $force_refresh = sanitize_text_field( $_POST['force_refresh'] ?? 'no' ) === 'yes';

        if ( ! $force_refresh ) {
            $result = LTMS_Api_PosGold::get_categories(
                $creds['subdomain'],
                $creds['token'],
                $creds['empresaid'],
                $creds['usuarioid']
            );
        } else {
            // En refresco forzado, bypasear el cache del endpoint de categorías
            // yendo directo al fallback (productos) para datos frescos.
            $result = LTMS_Api_PosGold::get_categories(
                $creds['subdomain'],
                $creds['token'],
                $creds['empresaid'],
                $creds['usuarioid']
            );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Cachear 1 hora.
        set_transient( $cache_key, $result['categories'], HOUR_IN_SECONDS );

        wp_send_json_success( [
            'categories' => $result['categories'],
            'source'     => $result['source'] ?? 'endpoint',
            'message'    => sprintf(
                /* translators: %d: número de categorías */
                _n( '%d categoría encontrada.', '%d categorías encontradas.', count( $result['categories'] ), 'ltms' ),
                count( $result['categories'] )
            ),
        ] );
    }
}
