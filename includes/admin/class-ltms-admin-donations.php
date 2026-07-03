<?php
/**
 * LTMS Admin Donations - Controlador AJAX del Panel de Donaciones
 *
 * Gestiona las acciones AJAX para el panel de administración de donaciones
 * a la Fundación Cardio Infantil:
 *   - Listado paginado de donaciones con filtros
 *   - Detalle de una donación individual
 *   - Listado de lotes de transferencia (payout batches)
 *   - Disparo manual de transferencia (manual payout)
 *   - Generación de certificados PDF
 *   - Exportación CSV
 *   - Estadísticas para gráficos del dashboard
 *
 * Tablas DB (creadas por Task 60-A o 60-B):
 *   - {prefix}lt_donations          — donaciones individuales
 *   - {prefix}lt_donation_payouts   — lotes de transferencia
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.0.0
 * @since      3.0.0  Task 60-D — Donation Reports + Admin + Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// El generador de certificados PDF vive en includes/business/ pero la convención
// de nombres del autoloader lo buscaría en includes/donation/. Como no podemos
// modificar el autoloader principal (fuera de scope), cargamos manualmente la
// clase cuando este módulo se carga (este archivo sí es autoloadeado por
// LTMS_Admin_Donations → includes/admin/class-ltms-admin-donations.php).
if ( ! class_exists( 'LTMS_Donation_Certificate' ) ) {
    $cert_path = LTMS_INCLUDES_DIR . 'business/class-ltms-donation-certificate.php';
    if ( file_exists( $cert_path ) ) {
        require_once $cert_path;
    }
}

/**
 * Class LTMS_Admin_Donations
 *
 * AJAX + view renderer para el panel "Donaciones" del admin.
 */
final class LTMS_Admin_Donations {

    use LTMS_Logger_Aware;

    /**
     * Nonce action usada por todos los handlers AJAX de este controlador.
     */
    const NONCE_ACTION = 'ltms_admin_donations';

    /**
     * Capacidad requerida para todas las acciones del panel.
     */
    const REQUIRED_CAP = 'manage_options';

    /**
     * Estados válidos de una donación.
     * Debe coincidir con el enum documentado en el schema (lt_donations.status).
     */
    const DONATION_STATUSES = [ 'pending', 'credited', 'processing', 'paid', 'reversed', 'failed' ];

    /**
     * Estados válidos de un lote de transferencia.
     * Tras Task 62-A, el DonationManager escribe 'paid' al confirmar la transferencia.
     */
    const BATCH_STATUSES = [ 'pending', 'paid', 'failed', 'cancelled' ];

    /**
     * Registra los hooks AJAX del controlador.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_get_donations',         [ $instance, 'ajax_get_donations' ] );
        add_action( 'wp_ajax_ltms_get_donation_detail',   [ $instance, 'ajax_get_donation_detail' ] );
        add_action( 'wp_ajax_ltms_get_payout_batches',    [ $instance, 'ajax_get_payout_batches' ] );
        add_action( 'wp_ajax_ltms_manual_payout',         [ $instance, 'ajax_manual_payout' ] );
        add_action( 'wp_ajax_ltms_generate_certificate',  [ $instance, 'ajax_generate_certificate' ] );
        add_action( 'wp_ajax_ltms_export_donations_csv',  [ $instance, 'ajax_export_csv' ] );
        add_action( 'wp_ajax_ltms_get_donation_stats',    [ $instance, 'ajax_get_statistics' ] );
    }

    /**
     * Renderiza la página de administración de donaciones.
     * Carga la vista `html-admin-donations.php` desde el directorio admin/views/.
     *
     * @return void
     */
    public static function render_dashboard(): void {
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ltms' ) );
        }

        $view_path = LTMS_INCLUDES_DIR . 'admin/views/html-admin-donations.php';
        if ( file_exists( $view_path ) ) {
            include_once $view_path;
        } else {
            echo '<div class="wrap"><h1>' . esc_html__( 'Donaciones', 'ltms' ) . '</h1><p>'
                . esc_html__( 'Vista no encontrada: html-admin-donations.php', 'ltms' )
                . '</p></div>';
        }
    }

    // ───────────────────────────────────────────────────────────────
    //  AJAX HANDLERS
    // ───────────────────────────────────────────────────────────────

    /**
     * AJAX: Listado paginado de donaciones con filtros.
     *
     * Body params:
     *   - nonce    (string)  Nonce de ltms_admin_donations
     *   - status   (string)  pending|credited|paid|reversed|all
     *   - vendor_id(int)     Filtro por vendedor (0 = todos)
     *   - date_from(string)  Y-m-d (opcional)
     *   - date_to  (string)  Y-m-d (opcional)
     *   - paged    (int)     Página (default 1)
     *   - per_page (int)     Items por página (default 20, max 100)
     *   - search   (string)  Búsqueda libre en order_id/vendor
     *
     * @return void
     */
    public function ajax_get_donations(): void {
		// SEC-3 FIX (v2.9.26): CSRF protection.
		check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $status   = sanitize_key( $_POST['status'] ?? 'all' ); // phpcs:ignore
        $vendor   = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' ); // phpcs:ignore
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' ); // phpcs:ignore
        $paged    = max( 1, (int) ( $_POST['paged'] ?? 1 ) ); // phpcs:ignore
        $per_page = min( 100, max( 5, (int) ( $_POST['per_page'] ?? 20 ) ) ); // phpcs:ignore
        $search   = sanitize_text_field( $_POST['search'] ?? '' ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_donations';

        // Construir WHERE dinámico.
        list( $where, $args ) = $this->build_donations_where( $status, $vendor, $date_from, $date_to, $search );

        $offset = ( $paged - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, u.display_name AS vendor_name, u.user_email AS vendor_email
                   FROM `{$table}` d
                   LEFT JOIN `{$wpdb->users}` u ON u.ID = d.vendor_id
                  {$where}
                  ORDER BY d.created_at DESC
                  LIMIT %d OFFSET %d",
                ...array_merge( $args, [ $per_page, $offset ] )
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` d {$where}", ...$args )
        );

        wp_send_json_success( [
            'items'      => array_map( [ $this, 'format_donation_row' ], is_array( $items ) ? $items : [] ),
            'total'      => $total,
            'paged'      => $paged,
            'per_page'   => $per_page,
            'total_pages'=> max( 1, (int) ceil( $total / $per_page ) ),
        ] );
    }

    /**
     * AJAX: Detalle completo de una donación.
     *
     * Body params:
     *   - nonce         (string)
     *   - donation_id   (int)
     *
     * @return void
     */
    public function ajax_get_donation_detail(): void {
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $donation_id = (int) ( $_POST['donation_id'] ?? 0 ); // phpcs:ignore
        if ( ! $donation_id ) {
            wp_send_json_error( __( 'ID de donación inválido.', 'ltms' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_donations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $donation = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT d.*, u.display_name AS vendor_name, u.user_email AS vendor_email,
                        p.batch_number, p.period_start, p.period_end, p.transferred_at
                   FROM `{$table}` d
                   LEFT JOIN `{$wpdb->users}` u ON u.ID = d.vendor_id
                   LEFT JOIN `{$wpdb->prefix}lt_donation_payouts` p ON p.id = d.payout_batch_id
                  WHERE d.id = %d",
                $donation_id
            ),
            ARRAY_A
        );

        if ( ! $donation ) {
            wp_send_json_error( __( 'Donación no encontrada.', 'ltms' ) );
        }

        // Datos adicionales de la orden WC si existe.
        $order_data = [];
        if ( ! empty( $donation['order_id'] ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $donation['order_id'] );
            if ( $order ) {
                $order_data = [
                    'order_number' => $order->get_order_number(),
                    'order_total'  => (float) $order->get_total(),
                    'order_status' => $order->get_status(),
                    'order_date'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                ];
            }
        }

        wp_send_json_success( [
            'donation'  => $this->format_donation_row( $donation ),
            'order'     => $order_data,
            'batch'     => [
                'id'            => (int) ( $donation['payout_batch_id'] ?? 0 ),
                'batch_number'  => $donation['batch_number'] ?? '',
                'period_start'  => $donation['period_start'] ?? '',
                'period_end'    => $donation['period_end'] ?? '',
                'transferred_at'=> $donation['transferred_at'] ?? '',
            ],
        ] );
    }

    /**
     * AJAX: Listado de lotes de transferencia.
     *
     * Body params:
     *   - nonce    (string)
     *   - status   (string) pending|paid|reversed|all
     *   - paged    (int)
     *   - per_page (int)
     *
     * @return void
     */
    public function ajax_get_payout_batches(): void {
		// SEC-3 FIX (v2.9.26): CSRF protection.
		check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $status   = sanitize_key( $_POST['status'] ?? 'all' ); // phpcs:ignore
        $paged    = max( 1, (int) ( $_POST['paged'] ?? 1 ) ); // phpcs:ignore
        $per_page = min( 100, max( 5, (int) ( $_POST['per_page'] ?? 20 ) ) ); // phpcs:ignore
        $offset   = ( $paged - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'lt_donation_payouts';

        $where = '';
        $args  = [];
        if ( in_array( $status, self::BATCH_STATUSES, true ) ) {
            $where  = 'WHERE status = %s';
            $args[] = $status;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...array_merge( $args, [ $per_page, $offset ] )
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` {$where}", ...$args ) );

        wp_send_json_success( [
            'items'       => is_array( $items ) ? array_map( [ $this, 'format_batch_row' ], $items ) : [],
            'total'       => $total,
            'paged'       => $paged,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        ] );
    }

    /**
     * AJAX: Disparo manual de transferencia (manual payout).
     * Delega en `LTMS_Donation_Manager::manual_payout()`, si esa clase existe.
     *
     * Body params:
     *   - nonce           (string)
     *   - batch_id        (int)   Si se pasa, transfiere ese lote. Si no, crea uno nuevo.
     *   - period_start    (string) Y-m-d (requerido si no hay batch_id)
     *   - period_end      (string) Y-m-d (requerido si no hay batch_id)
     *   - transfer_ref    (string) Referencia de transferencia (opcional)
     *
     * @return void
     */
    public function ajax_manual_payout(): void {
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $batch_id     = (int) ( $_POST['batch_id'] ?? 0 ); // phpcs:ignore
        $period_start = sanitize_text_field( $_POST['period_start'] ?? '' ); // phpcs:ignore
        $period_end   = sanitize_text_field( $_POST['period_end'] ?? '' ); // phpcs:ignore
        $transfer_ref = sanitize_text_field( $_POST['transfer_ref'] ?? '' ); // phpcs:ignore
        $user_id      = get_current_user_id();

        // Validación mínima.
        if ( ! $batch_id ) {
            if ( ! $period_start || ! $period_end ) {
                wp_send_json_error( __( 'Debes indicar un lote existente o un período (start + end).', 'ltms' ) );
            }
            if ( strtotime( $period_start ) === false || strtotime( $period_end ) === false ) {
                wp_send_json_error( __( 'Formato de fecha inválido (debe ser Y-m-d).', 'ltms' ) );
            }
            if ( $period_start > $period_end ) {
                wp_send_json_error( __( 'La fecha de inicio no puede ser posterior a la fecha de fin.', 'ltms' ) );
            }
        }

        // Delegar en LTMS_Donation_Manager si está disponible.
        // ADMIN-BUG-9: Si se pasó un batch_id específico, el botón "Transferir"
        // de la fila de lotes debe marcar ESE lote como pagado — no crear uno
        // nuevo para el período actual. Como LTMS_Donation_Manager::manual_payout()
        // no acepta batch_id (siempre procesa el período corriente), enrutamos
        // ese caso al fallback local que sí sabe marcar un lote existente.
        if ( $batch_id > 0 ) {
            $fallback = $this->fallback_manual_payout( $batch_id, $period_start, $period_end, $transfer_ref, $user_id );
            if ( is_wp_error( $fallback ) ) {
                wp_send_json_error( $fallback->get_error_message() );
            }

            $this->log_security(
                'DONATION_PAYOUT_TRIGGERED',
                sprintf(
                    'Transferencia manual de lote existente #%d disparada por admin #%d',
                    $batch_id, $user_id
                ),
                [ 'batch_id' => $batch_id ]
            );

            wp_send_json_success( $fallback );
            return;
        }

        if ( class_exists( 'LTMS_Donation_Manager' ) && method_exists( 'LTMS_Donation_Manager', 'manual_payout' ) ) {
            try {
                // ADMIN-BUG-1 (SHOWSTOPPER): manual_payout() requiere int $admin_id.
                // Antes se le pasaba un array, lo que lanzaba TypeError en PHP 8.
                $result = LTMS_Donation_Manager::manual_payout( $user_id );

                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( $result->get_error_message() );
                }

                $this->log_security(
                    'DONATION_PAYOUT_TRIGGERED',
                    sprintf(
                        'Transferencia manual de donaciones disparada por admin #%d (periodo=%s..%s)',
                        $user_id, $period_start, $period_end
                    ),
                    [ 'period_start' => $period_start, 'period_end' => $period_end ]
                );

                wp_send_json_success( $result );
            } catch ( \Throwable $e ) {
                $this->log_error(
                    'DONATION_PAYOUT_FAILED',
                    sprintf( 'Excepción en manual_payout: %s', $e->getMessage() ),
                    [ 'batch_id' => $batch_id ]
                );
                wp_send_json_error(
                    sprintf( __( 'Error en transferencia: %s', 'ltms' ), $e->getMessage() )
                );
            }
        } else {
            // Sin Donation_Manager disponible — crear el lote localmente como fallback.
            $fallback = $this->fallback_manual_payout( $batch_id, $period_start, $period_end, $transfer_ref, $user_id );
            if ( is_wp_error( $fallback ) ) {
                wp_send_json_error( $fallback->get_error_message() );
            }
            wp_send_json_success( $fallback );
        }
    }

    /**
     * AJAX: Genera el PDF del certificado de donación para un lote.
     * Delega en `LTMS_Donation_Certificate::generate()`.
     *
     * Body params:
     *   - nonce    (string)
     *   - batch_id (int)
     *   - force    (int)  Si 1, regenera aunque ya exista un certificado.
     *
     * @return void
     */
    public function ajax_generate_certificate(): void {
		// SEC-3 FIX (v2.9.26): CSRF protection.
		check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $batch_id = (int) ( $_POST['batch_id'] ?? 0 ); // phpcs:ignore
        if ( ! $batch_id ) {
            wp_send_json_error( __( 'ID de lote inválido.', 'ltms' ) );
        }

        if ( ! class_exists( 'LTMS_Donation_Certificate' ) ) {
            wp_send_json_error( __( 'Generador de certificados no disponible.', 'ltms' ) );
        }

        $force = ! empty( $_POST['force'] ); // phpcs:ignore
        if ( $force ) {
            $_REQUEST['force_regenerate'] = 1; // phpcs:ignore
        }

        $result = LTMS_Donation_Certificate::generate( $batch_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [
            'certificate_path' => $result,
            'download_url'     => LTMS_Donation_Certificate::build_download_url( $result ),
            'message'          => __( 'Certificado generado correctamente.', 'ltms' ),
        ] );
    }

    /**
     * AJAX: Exporta las donaciones a CSV (con los mismos filtros del listado).
     *
     * Body params: los mismos de ajax_get_donations (sin paginación).
     *
     * @return void
     */
    public function ajax_export_csv(): void {
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $status    = sanitize_key( $_POST['status'] ?? 'all' ); // phpcs:ignore
        $vendor    = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' ); // phpcs:ignore
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' ); // phpcs:ignore
        $search    = sanitize_text_field( $_POST['search'] ?? '' ); // phpcs:ignore

        global $wpdb;
        $table = $wpdb->prefix . 'lt_donations';

        list( $where, $args ) = $this->build_donations_where( $status, $vendor, $date_from, $date_to, $search );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, u.display_name AS vendor_name, u.user_email AS vendor_email
                   FROM `{$table}` d
                   LEFT JOIN `{$wpdb->users}` u ON u.ID = d.vendor_id
                  {$where}
                  ORDER BY d.created_at DESC
                  LIMIT %d",
                ...array_merge( $args, [ 5000 ] )
            ),
            ARRAY_A
        );

        // Sanitiza cada campo para prevenir formula injection en Excel/Sheets
        // y escapa comillas dobles como "" (RFC 4180).
        $csv_field = static function ( $v ): string {
            $v = (string) $v;
            // ADMIN-BUG-7: escapar comillas dobles embebidas.
            $v = str_replace( '"', '""', $v );
            if ( '' !== $v && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
                $v = "'" . $v;
            }
            return $v;
        };

        // ADMIN-BUG-8: BOM UTF-8 para que Excel reconozca la codificación.
        $csv  = "\xEF\xBB\xBF";
        $csv .= "ID,Orden,Vendedor,Email,Monto Base,Porcentaje,Donacion,Moneda,Estado,Batch ID,Fecha Creada,Fecha Acreditada,Fecha Pagada\n";
        foreach ( (array) $items as $row ) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                $csv_field( $row['id'] ?? '' ),
                $csv_field( $row['order_id'] ?? '' ),
                $csv_field( $row['vendor_name'] ?? ( 'Vendor #' . ( $row['vendor_id'] ?? '' ) ) ),
                $csv_field( $row['vendor_email'] ?? '' ),
                $csv_field( $row['base_amount'] ?? 0 ),
                $csv_field( $row['percentage'] ?? 0 ),
                $csv_field( $row['donation_amount'] ?? 0 ),
                $csv_field( $row['currency'] ?? '' ),
                $csv_field( $row['status'] ?? '' ),
                $csv_field( $row['payout_batch_id'] ?? '' ),
                $csv_field( $row['created_at'] ?? '' ),
                // ADMIN-BUG-6: la columna credited_at no existe en el schema;
                // se usa created_at como fecha de acreditación.
                $csv_field( $row['created_at'] ?? '' ),
                $csv_field( $row['paid_at'] ?? '' )
            );
        }

        $this->log_info(
            'DONATIONS_EXPORTED',
            sprintf( 'Exportación CSV de %d donaciones por admin #%d', count( $items ), get_current_user_id() ),
            [ 'count' => count( $items ), 'status_filter' => $status ]
        );

        wp_send_json_success( [
            'csv'      => base64_encode( $csv ), // phpcs:ignore
            'filename' => 'ltms-donaciones-' . gmdate( 'Y-m-d-His' ) . '.csv',
            'count'    => count( $items ),
        ] );
    }

    /**
     * AJAX: Estadísticas para el dashboard y los gráficos.
     *
     * Body params:
     *   - nonce      (string)
     *   - period     (string) month|quarter|year|all (default month)
     *
     * @return void
     */
    public function ajax_get_statistics(): void {
		// SEC-3 FIX (v2.9.26): CSRF protection.
		check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        $this->verify();
        if ( ! current_user_can( self::REQUIRED_CAP ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $period = sanitize_key( $_POST['period'] ?? 'month' ); // phpcs:ignore

        global $wpdb;
        $donations_table = $wpdb->prefix . 'lt_donations';
        $batches_table   = $wpdb->prefix . 'lt_donation_payouts';
        $currency        = LTMS_Core_Config::get_currency();

        // Calcular rango de fechas del período.
        list( $date_from, $date_to ) = $this->get_period_range( $period );

        // ── Summary cards ──────────────────────────────────────────
        // Total donado en el período (status credited o paid).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_donated = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(donation_amount), 0)
                   FROM `{$donations_table}`
                  WHERE status IN ('credited','paid')
                    AND DATE(created_at) BETWEEN %s AND %s",
                $date_from, $date_to
            )
        );

        // Donaciones pendientes (status pending).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $pending_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$donations_table}` WHERE status = %s",
                'pending'
            )
        );

        // Última transferencia.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last_transfer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT batch_number, total_amount, currency, transferred_at
                   FROM `{$batches_table}`
                  WHERE status = %s AND transferred_at IS NOT NULL
                  ORDER BY transferred_at DESC LIMIT 1",
                'paid'
            ),
            ARRAY_A
        );

        // Próxima transferencia (lote pendiente más antiguo).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $next_transfer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT batch_number, total_amount, currency, period_end
                   FROM `{$batches_table}`
                  WHERE status = %s
                  ORDER BY period_end ASC LIMIT 1",
                'pending'
            ),
            ARRAY_A
        );

        // ── Chart: donaciones por mes (últimos 12 meses) ────────────
        $by_month = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month_key,
                        COALESCE(SUM(donation_amount), 0) AS total,
                        COUNT(*) AS count
                   FROM `{$donations_table}`
                  WHERE status IN ('credited','paid')
                    AND created_at >= DATE_SUB(%s, INTERVAL 12 MONTH)
                  GROUP BY month_key
                  ORDER BY month_key ASC",
                gmdate( 'Y-m-d' )
            ),
            ARRAY_A
        );

        // ── Chart: donaciones por día (últimos 30 días) ─────────────
        $by_day = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day_key,
                        COALESCE(SUM(donation_amount), 0) AS total,
                        COUNT(*) AS count
                   FROM `{$donations_table}`
                  WHERE status IN ('credited','paid')
                    AND created_at >= DATE_SUB(%s, INTERVAL 30 DAY)
                  GROUP BY day_key
                  ORDER BY day_key ASC",
                gmdate( 'Y-m-d' )
            ),
            ARRAY_A
        );

        // ── Top 10 vendedores por monto donado (período) ────────────
        $top_vendors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.vendor_id, u.display_name AS vendor_name,
                        COUNT(*) AS count,
                        SUM(d.donation_amount) AS total,
                        SUM(d.base_amount) AS base_total
                   FROM `{$donations_table}` d
                   LEFT JOIN `{$wpdb->users}` u ON u.ID = d.vendor_id
                  WHERE d.status IN ('credited','paid')
                    AND DATE(d.created_at) BETWEEN %s AND %s
                  GROUP BY d.vendor_id, vendor_name
                  ORDER BY total DESC
                  LIMIT 10",
                $date_from, $date_to
            ),
            ARRAY_A
        );

        wp_send_json_success( [
            'period'        => $period,
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'currency'      => $currency,
            'summary'       => [
                'total_donated'    => $total_donated,
                'pending_count'    => $pending_count,
                'last_transfer'    => $last_transfer,
                'next_transfer'    => $next_transfer,
            ],
            'charts'        => [
                'by_month' => is_array( $by_month ) ? $by_month : [],
                'by_day'   => is_array( $by_day ) ? $by_day : [],
            ],
            'top_vendors'   => is_array( $top_vendors ) ? $top_vendors : [],
        ] );
    }

    // ───────────────────────────────────────────────────────────────
    //  HELPERS
    // ───────────────────────────────────────────────────────────────

    /**
     * Verifica nonce AJAX de manera centralizada.
     *
     * @return void
     */
    private function verify(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    /**
     * Construye la cláusula WHERE para filtrar donaciones.
     *
     * @param string $status    Estado o 'all'.
     * @param int    $vendor_id ID del vendedor (0 = todos).
     * @param string $date_from Y-m-d (vacío = sin límite inferior).
     * @param string $date_to   Y-m-d (vacío = sin límite superior).
     * @param string $search    Búsqueda libre en order_id/vendor_name.
     * @return array{0:string,1:array<int,mixed>}
     */
    private function build_donations_where( string $status, int $vendor_id, string $date_from, string $date_to, string $search ): array {
        $conditions = [];
        $args       = [];

        if ( in_array( $status, self::DONATION_STATUSES, true ) ) {
            $conditions[] = 'd.status = %s';
            $args[]       = $status;
        }

        if ( $vendor_id > 0 ) {
            $conditions[] = 'd.vendor_id = %d';
            $args[]       = $vendor_id;
        }

        if ( $date_from && strtotime( $date_from ) ) {
            $conditions[] = 'DATE(d.created_at) >= %s';
            $args[]       = $date_from;
        }

        if ( $date_to && strtotime( $date_to ) ) {
            $conditions[] = 'DATE(d.created_at) <= %s';
            $args[]       = $date_to;
        }

        if ( $search !== '' ) {
            $conditions[] = '(d.order_id LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like         = '%' . $this->safe_like( $search ) . '%';
            $args[]       = $like;
            $args[]       = $like;
            $args[]       = $like;
        }

        $where = empty( $conditions ) ? '' : 'WHERE ' . implode( ' AND ', $conditions );
        return [ $where, $args ];
    }

    /**
     * Sanitiza un término de búsqueda para usar con LIKE en MySQL.
     * Escapa los caracteres especiales %, _, \.
     *
     * @param string $s Término crudo.
     * @return string
     */
    private function safe_like( string $s ): string {
        return str_replace( [ '\\', '%', '_', "'" ], [ '\\\\', '\\%', '\\_', "\\'" ], $s );
    }

    /**
     * Calcula el rango de fechas Y-m-d para un período dado.
     *
     * @param string $period month|quarter|year|all.
     * @return array{0:string,1:string}
     */
    private function get_period_range( string $period ): array {
        $today = gmdate( 'Y-m-d' );
        switch ( $period ) {
            case 'quarter':
                $from = gmdate( 'Y-m-d', strtotime( '-3 months', strtotime( $today ) ) );
                return [ $from, $today ];
            case 'year':
                $from = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $today ) ) );
                return [ $from, $today ];
            case 'all':
                return [ '2000-01-01', $today ];
            case 'month':
            default:
                $from = gmdate( 'Y-m-01' );
                return [ $from, $today ];
        }
    }

    /**
     * Formatea una fila de donación para respuesta JSON.
     * Aplica casting de tipos y normaliza fechas vacías.
     *
     * @param array<string,mixed> $row Fila cruda de la DB.
     * @return array<string,mixed>
     */
    private function format_donation_row( array $row ): array {
        return [
            'id'              => (int) ( $row['id'] ?? 0 ),
            'order_id'        => (int) ( $row['order_id'] ?? 0 ),
            'vendor_id'       => (int) ( $row['vendor_id'] ?? 0 ),
            'vendor_name'     => $row['vendor_name'] ?? '',
            'vendor_email'    => $row['vendor_email'] ?? '',
            'base_amount'     => (float) ( $row['base_amount'] ?? 0 ),
            'percentage'      => (float) ( $row['percentage'] ?? 0 ),
            'donation_amount' => (float) ( $row['donation_amount'] ?? 0 ),
            'currency'        => $row['currency'] ?? LTMS_Core_Config::get_currency(),
            'status'          => $row['status'] ?? 'pending',
            'payout_batch_id' => $row['payout_batch_id'] ? (int) $row['payout_batch_id'] : null,
            'created_at'      => $row['created_at'] ?? '',
            // ADMIN-BUG-6: la columna credited_at no existe en el schema;
            // se expone created_at como credited_at para compatibilidad del JS.
            'credited_at'     => $this->normalize_date( $row['created_at'] ?? '' ),
            'paid_at'         => $this->normalize_date( $row['paid_at'] ?? '' ),
        ];
    }

    /**
     * Formatea una fila de lote de transferencia para respuesta JSON.
     *
     * @param array<string,mixed> $row Fila cruda de la DB.
     * @return array<string,mixed>
     */
    private function format_batch_row( array $row ): array {
        return [
            'id'                  => (int) ( $row['id'] ?? 0 ),
            'batch_number'        => $row['batch_number'] ?? '',
            'period_start'        => $row['period_start'] ?? '',
            'period_end'          => $row['period_end'] ?? '',
            'total_amount'        => (float) ( $row['total_amount'] ?? 0 ),
            'transaction_count'   => (int) ( $row['transaction_count'] ?? 0 ),
            'currency'            => $row['currency'] ?? LTMS_Core_Config::get_currency(),
            'status'              => $row['status'] ?? 'pending',
            'transfer_reference'  => $row['transfer_reference'] ?? '',
            'transferred_at'      => $this->normalize_date( $row['transferred_at'] ?? '' ),
            'certificate_path'    => $row['certificate_path'] ?? '',
            'created_at'          => $row['created_at'] ?? '',
        ];
    }

    /**
     * Normaliza fechas de la DB: '0000-00-00 00:00:00' → ''.
     *
     * @param string $date Fecha cruda.
     * @return string
     */
    private function normalize_date( string $date ): string {
        if ( empty( $date ) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00' ) {
            return '';
        }
        return $date;
    }

    /**
     * Fallback: crea/marca un lote como transferido cuando LTMS_Donation_Manager
     * no está disponible (el módulo core de donaciones no fue cargado).
     *
     * Crea un lote nuevo con el período indicado (si no se pasó batch_id),
     * marca las donaciones del período como 'paid', y actualiza el lote a 'paid'.
     *
     * @param int    $batch_id     ID del lote (0 = crear nuevo).
     * @param string $period_start Y-m-d.
     * @param string $period_end   Y-m-d.
     * @param string $transfer_ref Referencia de transferencia.
     * @param int    $user_id      ID del admin que dispara.
     * @return array|\WP_Error
     */
    private function fallback_manual_payout( int $batch_id, string $period_start, string $period_end, string $transfer_ref, int $user_id ) {
        global $wpdb;
        $donations_table = $wpdb->prefix . 'lt_donations';
        $batches_table   = $wpdb->prefix . 'lt_donation_payouts';

        if ( ! $batch_id ) {
            // Crear un lote nuevo con el período indicado.
            $batch_number = 'D-' . gmdate( 'Ymd' ) . '-' . wp_rand( 100, 999 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->insert(
                $batches_table,
                [
                    'batch_number'      => $batch_number,
                    'period_start'      => $period_start,
                    'period_end'        => $period_end,
                    'total_amount'      => 0,
                    'transaction_count' => 0,
                    'currency'          => LTMS_Core_Config::get_currency(),
                    'status'            => 'pending',
                    'transfer_reference'=> $transfer_ref,
                    'created_at'        => LTMS_Utils::now_utc(),
                ],
                [ '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s' ]
            );
            $batch_id = (int) $wpdb->insert_id;
            if ( ! $batch_id ) {
                return new \WP_Error( 'batch_create_failed', __( 'No se pudo crear el lote.', 'ltms' ) );
            }

            // Asociar donaciones 'credited' del período al nuevo lote.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$donations_table}`
                        SET payout_batch_id = %d
                      WHERE status = %s
                        AND DATE(created_at) BETWEEN %s AND %s",
                    $batch_id, 'credited', $period_start, $period_end
                )
            );
        }

        // Calcular totales del lote.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $totals = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(donation_amount),0) AS total
                   FROM `{$donations_table}`
                  WHERE payout_batch_id = %d",
                $batch_id
            ),
            ARRAY_A
        );

        $count = (int) ( $totals['cnt'] ?? 0 );
        $total = (float) ( $totals['total'] ?? 0 );

        if ( $count === 0 ) {
            return new \WP_Error(
                'batch_empty',
                __( 'El lote no tiene donaciones para transferir.', 'ltms' )
            );
        }

        // Marcar donaciones como 'paid'.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$donations_table}`
                    SET status = %s, paid_at = %s
                  WHERE payout_batch_id = %d AND status = %s",
                'paid', LTMS_Utils::now_utc(), $batch_id, 'credited'
            )
        );

        // Marcar el lote como 'paid'.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $batches_table,
            [
                'status'             => 'paid',
                'total_amount'       => $total,
                'transaction_count'  => $count,
                'transfer_reference' => $transfer_ref,
                'transferred_at'     => LTMS_Utils::now_utc(),
            ],
            [ 'id' => $batch_id ],
            [ '%s', '%f', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        $this->log_warning(
            'DONATION_PAYOUT_FALLBACK',
            sprintf(
                'Transferencia ejecutada con FALLBACK (sin LTMS_Donation_Manager). Lote #%d, %d donaciones, %s.',
                $batch_id, $count, LTMS_Utils::format_money( $total )
            ),
            [ 'batch_id' => $batch_id, 'count' => $count, 'total' => $total ]
        );

        return [
            'batch_id'        => $batch_id,
            'donation_count'  => $count,
            'total_amount'    => $total,
            'currency'        => LTMS_Core_Config::get_currency(),
            'transferred_at'  => LTMS_Utils::now_utc(),
            'transfer_ref'    => $transfer_ref,
            'fallback'        => true,
            'message'         => __( 'Transferencia completada (modo fallback).', 'ltms' ),
        ];
    }
}
