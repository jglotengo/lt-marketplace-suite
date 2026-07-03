<?php
/**
 * LTMS Frontend Customer Bookings
 *
 * Añade una sección "Mis Reservas" en Mi Cuenta de WooCommerce (/mi-cuenta/mis-reservas/).
 * El comprador puede:
 *   - Ver el listado paginado de sus reservas (estado, fechas, noche, precio, política).
 *   - Ver el reembolso estimado según la política de cancelación del vendedor.
 *   - Cancelar la reserva por su cuenta cuando el estado y la política lo permiten.
 *
 * @package LTMS\Frontend
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Frontend_Customer_Bookings {

    use LTMS_Logger_Aware;

    private const ENDPOINT         = 'mis-reservas';
    private const ITEMS_PER_PAGE   = 10;

    // ─── Init ────────────────────────────────────────────────────────────────

    public static function init(): void {
        $instance = new self();

        // WooCommerce My Account endpoint
        add_action( 'init',                                      [ $instance, 'register_endpoint' ], 5 );
        add_filter( 'woocommerce_account_menu_items',            [ $instance, 'add_menu_item' ], 20 );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $instance, 'render_page' ] );

        // AJAX handlers
        add_action( 'wp_ajax_ltms_get_customer_bookings',        [ $instance, 'ajax_get_bookings' ] );
        add_action( 'wp_ajax_ltms_customer_cancel_booking',      [ $instance, 'ajax_cancel_booking' ] );

        // Enqueue assets only on the my-account page
        add_action( 'wp_enqueue_scripts',                        [ $instance, 'enqueue_assets' ] );
    }

    // ─── Endpoint registration ────────────────────────────────────────────────

    public function register_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public function add_menu_item( array $items ): array {
        // Insert before "Log out"
        $new = [];
        foreach ( $items as $key => $label ) {
            if ( 'customer-logout' === $key ) {
                $new[ self::ENDPOINT ] = __( '🏨 Mis Reservas', 'ltms' );
            }
            $new[ $key ] = $label;
        }
        return $new;
    }

    // ─── Assets ──────────────────────────────────────────────────────────────

    public function enqueue_assets(): void {
        if ( ! is_account_page() ) return;
        // M-FIX-BOOKINGS-02: la vista se renderiza enteramente en PHP (render_page())
        // y el botón de cancelar usa un <script> inline auto-contenido — no hay
        // ningún consumidor real de un archivo JS externo hoy. El enqueue previo
        // apuntaba a assets/js/ltms-customer-bookings.js, que nunca se creó (404
        // silencioso en cada carga de Mi Cuenta). Se retira hasta que exista un
        // caso de uso real (ej. refresco en vivo vía ajax_get_bookings()).
    }

    // ─── Page render ─────────────────────────────────────────────────────────

    public function render_page(): void {
        $user_id  = get_current_user_id();
        $page     = max( 1, absint( get_query_var( 'paged', 1 ) ) );
        $nonce    = wp_create_nonce( 'ltms_customer_bookings' );

        $result   = $this->get_bookings_for_user( $user_id, $page, self::ITEMS_PER_PAGE );
        $bookings = $result['items'];
        $total    = $result['total'];
        $pages    = (int) ceil( $total / self::ITEMS_PER_PAGE );

        $status_labels = [
            'pending'   => __( '⏳ Pendiente de pago', 'ltms' ),
            'confirmed' => __( '✅ Confirmada', 'ltms' ),
            'cancelled' => __( '✗ Cancelada', 'ltms' ),
            'completed' => __( '☑ Completada', 'ltms' ),
        ];
        $status_colors = [
            'pending'   => '#f59e0b',
            'confirmed' => '#10b981',
            'cancelled' => '#6b7280',
            'completed' => '#2563eb',
        ];
        ?>
        <style>
            .ltms-cb-wrap { font-family: inherit; max-width: 900px; }
            .ltms-cb-header { margin-bottom: 20px; }
            .ltms-cb-header h2 { font-size: 1.3rem; color: #111827; margin: 0 0 4px; }
            .ltms-cb-header p { color: #6b7280; font-size: .875rem; margin: 0; }
            .ltms-cb-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 16px; overflow: hidden; }
            .ltms-cb-card-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; flex-wrap: wrap; gap: 10px; }
            .ltms-cb-card-head-left h3 { margin: 0 0 4px; font-size: .95rem; color: #111827; }
            .ltms-cb-card-head-left p { margin: 0; font-size: .8rem; color: #6b7280; }
            .ltms-cb-badge { display: inline-block; padding: 3px 12px; border-radius: 99px; font-size: .78rem; font-weight: 700; }
            .ltms-cb-card-body { padding: 16px 20px; border-top: 1px solid #f3f4f6; }
            .ltms-cb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px 20px; margin-bottom: 14px; }
            .ltms-cb-grid-item label { display: block; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; margin-bottom: 3px; }
            .ltms-cb-grid-item span { font-size: .9rem; color: #111827; font-weight: 500; }
            .ltms-cb-refund { background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; font-size: .82rem; color: #92400e; margin-bottom: 14px; }
            .ltms-cb-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .ltms-cb-btn { display: inline-flex; align-items: center; gap: 5px; padding: 8px 18px; border-radius: 7px; font-size: .85rem; font-weight: 600; border: none; cursor: pointer; transition: all .15s; text-decoration: none; }
            .ltms-cb-btn-danger { background: #fee2e2; color: #991b1b; }
            .ltms-cb-btn-danger:hover { background: #fecaca; }
            .ltms-cb-btn-outline { background: transparent; color: #374151; border: 1.5px solid #d1d5db; }
            .ltms-cb-btn-outline:hover { border-color: #9ca3af; }
            .ltms-cb-empty { text-align: center; padding: 48px 20px; color: #9ca3af; }
            .ltms-cb-empty .icon { font-size: 3rem; margin-bottom: 12px; }
            .ltms-cb-pagination { display: flex; gap: 8px; margin-top: 20px; }
            .ltms-cb-page-btn { padding: 6px 14px; border: 1.5px solid #d1d5db; border-radius: 6px; font-size: .85rem; background: #fff; cursor: pointer; color: #374151; }
            .ltms-cb-page-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
            .ltms-cb-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: .875rem; }
            .ltms-cb-notice.error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
            .ltms-cb-notice.success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        </style>

        <div class="ltms-cb-wrap">

            <div class="ltms-cb-header">
                <h2>🏨 <?php esc_html_e( 'Mis Reservas', 'ltms' ); ?></h2>
                <p><?php esc_html_e( 'Aquí puedes consultar y gestionar todas tus reservas de alojamiento y servicios en Lo Tengo.', 'ltms' ); ?></p>
            </div>

            <div id="ltms-cb-notice" style="display:none;"></div>

            <?php if ( empty( $bookings ) ) : ?>
                <div class="ltms-cb-empty">
                    <div class="icon">🏨</div>
                    <p><strong><?php esc_html_e( 'Aún no tienes reservas.', 'ltms' ); ?></strong></p>
                    <p><?php esc_html_e( 'Cuando reserves un alojamiento o servicio en Lo Tengo, aparecerá aquí.', 'ltms' ); ?></p>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ltms-cb-btn ltms-cb-btn-outline">
                        🔍 <?php esc_html_e( 'Explorar alojamientos', 'ltms' ); ?>
                    </a>
                </div>

            <?php else : ?>

                <?php foreach ( $bookings as $b ) :
                    $status      = $b['status'] ?? 'pending';
                    $status_lbl  = $status_labels[ $status ] ?? $status;
                    $status_col  = $status_colors[ $status ] ?? '#9ca3af';
                    $checkin     = $b['checkin_date']  ? date_i18n( get_option( 'date_format' ), strtotime( $b['checkin_date'] ) )  : '—';
                    $checkout    = $b['checkout_date'] ? date_i18n( get_option( 'date_format' ), strtotime( $b['checkout_date'] ) ) : '—';
                    $nights      = ( $b['checkin_date'] && $b['checkout_date'] )
                        ? (int) ( ( strtotime( $b['checkout_date'] ) - strtotime( $b['checkin_date'] ) ) / DAY_IN_SECONDS )
                        : 0;
                    $product     = $b['product_id'] ? get_post( (int) $b['product_id'] ) : null;
                    $prod_name   = $product ? $product->post_title : __( 'Alojamiento', 'ltms' );
                    $order_url   = $b['wc_order_id'] ? get_permalink( wc_get_page_id( 'myaccount' ) ) . 'view-order/' . $b['wc_order_id'] . '/' : '';
                    $can_cancel  = in_array( $status, [ 'pending', 'confirmed' ], true );
                    $refund_info = $can_cancel && $b['policy_id']
                        ? $this->estimate_refund( $b )
                        : null;
                ?>
                <div class="ltms-cb-card" id="ltms-cb-booking-<?php echo esc_attr( $b['id'] ); ?>">
                    <div class="ltms-cb-card-head">
                        <div class="ltms-cb-card-head-left">
                            <h3><?php echo esc_html( $prod_name ); ?></h3>
                            <p>
                                <?php printf(
                                    esc_html__( 'Reserva #%d', 'ltms' ),
                                    (int) $b['id']
                                ); ?>
                                <?php if ( $b['wc_order_id'] ) : ?>
                                    · <?php printf(
                                        esc_html__( 'Pedido #%d', 'ltms' ),
                                        (int) $b['wc_order_id']
                                    ); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="ltms-cb-badge"
                              style="background:<?php echo esc_attr( $status_col ); ?>22;color:<?php echo esc_attr( $status_col ); ?>;">
                            <?php echo esc_html( $status_lbl ); ?>
                        </span>
                    </div>

                    <div class="ltms-cb-card-body">
                        <div class="ltms-cb-grid">
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Check-in', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $checkin ); ?></span>
                                <?php if ( $b['checkin_time'] ) : ?>
                                    <br><span style="font-size:.75rem;color:#6b7280;"><?php echo esc_html( $b['checkin_time'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Check-out', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $checkout ); ?></span>
                                <?php if ( $b['checkout_time'] ) : ?>
                                    <br><span style="font-size:.75rem;color:#6b7280;"><?php echo esc_html( $b['checkout_time'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Noches', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $nights ?: '—' ); ?></span>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Huéspedes', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $b['guests'] ?? '—' ); ?></span>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Total pagado', 'ltms' ); ?></label>
                                <span><?php echo wp_kses_post( wc_price( (float) $b['total_price'] ) ); ?></span>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Política', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $b['policy_name'] ?? __( 'Estándar', 'ltms' ) ); ?></span>
                            </div>
                        </div>

                        <?php if ( $refund_info ) : ?>
                        <div class="ltms-cb-refund">
                            <?php if ( $refund_info['amount'] > 0 ) : ?>
                                ⚠️ <?php printf(
                                    /* translators: 1: formatted currency amount */
                                    esc_html__( 'Si cancelas ahora, recibirías un reembolso de %s según la política de cancelación del alojamiento.', 'ltms' ),
                                    wp_kses_post( wc_price( $refund_info['amount'] ) )
                                ); ?>
                            <?php else : ?>
                                ⚠️ <?php esc_html_e( 'Si cancelas ahora, no recibirías reembolso según la política de cancelación del alojamiento.', 'ltms' ); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="ltms-cb-actions">
                            <?php if ( $order_url ) : ?>
                            <a href="<?php echo esc_url( $order_url ); ?>" class="ltms-cb-btn ltms-cb-btn-outline">
                                📄 <?php esc_html_e( 'Ver pedido', 'ltms' ); ?>
                            </a>
                            <?php endif; ?>

                            <?php if ( $can_cancel ) : ?>
                            <button
                                type="button"
                                class="ltms-cb-btn ltms-cb-btn-danger ltms-cb-cancel-btn"
                                data-booking-id="<?php echo esc_attr( $b['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            >
                                ✗ <?php esc_html_e( 'Cancelar reserva', 'ltms' ); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ( $pages > 1 ) : ?>
                <div class="ltms-cb-pagination">
                    <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
                    <a href="<?php echo esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) . ( $i > 1 ? '?paged=' . $i : '' ) ); ?>"
                       class="ltms-cb-page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo esc_html( $i ); ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>

        <script>
        /* global jQuery, ltmsCustomerBookings */
        jQuery(function($){
            var i18n = (typeof ltmsCustomerBookings !== 'undefined') ? ltmsCustomerBookings.i18n : {};

            function showNotice(msg, type) {
                var $n = $('#ltms-cb-notice');
                $n.attr('class', 'ltms-cb-notice ' + type).html(msg).show();
                setTimeout(function(){ $n.fadeOut(); }, 4000);
            }

            $(document).on('click', '.ltms-cb-cancel-btn', function(){
                if ( ! confirm(i18n.cancel_confirm || 'Cancelar reserva?') ) return;
                var $btn      = $(this).prop('disabled', true).text(i18n.cancelling || 'Cancelando…');
                var bookingId = $(this).data('booking-id');
                var nonce     = $(this).data('nonce');

                $.post('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action:     'ltms_customer_cancel_booking',
                    booking_id: bookingId,
                    nonce:      nonce,
                }, function(r) {
                    if ( r.success ) {
                        $('#ltms-cb-booking-' + bookingId).fadeOut(300, function(){ $(this).remove(); });
                        showNotice(r.data.message || '<?php echo esc_js( __( "Reserva cancelada.", "ltms" ) ); ?>', 'success');
                    } else {
                        $btn.prop('disabled', false).text('✗ <?php echo esc_js( __( "Cancelar reserva", "ltms" ) ); ?>');
                        showNotice(r.data || '<?php echo esc_js( __( "Error al cancelar.", "ltms" ) ); ?>', 'error');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ─── AJAX: obtener reservas (para uso futuro / extensión SPA) ────────────

    public function ajax_get_bookings(): void {
		// SEC-4 FIX (v2.9.26): auth required.
		if ( ! is_user_logged_in() ) { wp_send_json_error( [ 'message' => __( 'Login requerido.', 'ltms' ) ], 401 ); }
        check_ajax_referer( 'ltms_customer_bookings', 'nonce' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ) );
        }
        $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
        $result = $this->get_bookings_for_user( $user_id, $page, self::ITEMS_PER_PAGE );
        wp_send_json_success( $result );
    }

    // ─── AJAX: cancelar reserva propia ───────────────────────────────────────

    public function ajax_cancel_booking(): void {
        check_ajax_referer( 'ltms_customer_bookings', 'nonce' );

        $user_id    = get_current_user_id();
        $booking_id = absint( $_POST['booking_id'] ?? 0 );

        if ( ! $user_id || ! $booking_id ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        // Verificar que la reserva pertenezca al comprador (anti cross-user)
        global $wpdb;
        $table   = $wpdb->prefix . 'lt_bookings';
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, customer_id FROM `{$table}` WHERE id = %d LIMIT 1",
                $booking_id
            ),
            ARRAY_A
        );

        if ( ! $booking || (int) $booking['customer_id'] !== $user_id ) {
            wp_send_json_error( __( 'Reserva no encontrada.', 'ltms' ) );
        }

        if ( ! in_array( $booking['status'], [ 'pending', 'confirmed' ], true ) ) {
            wp_send_json_error( __( 'Esta reserva no puede cancelarse en su estado actual.', 'ltms' ) );
        }

        $result = LTMS_Booking_Manager::cancel_booking( $booking_id, 'customer', 'Cancelado por el comprador' );

        if ( is_wp_error( $result ) ) {
            self::log_warning_static( 'customer_bookings', 'cancel failed: ' . $result->get_error_message(), [ 'booking_id' => $booking_id, 'user_id' => $user_id ] );
            wp_send_json_error( $result->get_error_message() );
        }

        self::log_info_static( 'customer_bookings', 'Booking #' . $booking_id . ' cancelled by customer #' . $user_id );
        wp_send_json_success( [
            'message' => __( 'Reserva cancelada. Si aplica reembolso, lo procesaremos en los próximos días hábiles.', 'ltms' ),
        ] );
    }

    // ─── Data layer ──────────────────────────────────────────────────────────

    /**
     * Obtiene las reservas del comprador con información de política incluida.
     *
     * @param int $user_id
     * @param int $page
     * @param int $per_page
     * @return array{ items: array, total: int }
     */
    private function get_bookings_for_user( int $user_id, int $page, int $per_page ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'lt_bookings';
        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE customer_id = %d", $user_id )
        );

        if ( 0 === $total ) {
            return [ 'items' => [], 'total' => 0 ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, bp.name AS policy_name, bp.policy_type,
                        bp.free_cancel_hours, bp.partial_refund_pct, bp.partial_refund_hours
                 FROM `{$table}` b
                 LEFT JOIN `{$wpdb->prefix}lt_booking_policies` bp ON bp.id = b.policy_id
                 WHERE b.customer_id = %d
                 ORDER BY b.checkin_date DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return [
            'items' => $rows ?: [],
            'total' => $total,
        ];
    }

    /**
     * Calcula el reembolso estimado si el comprador cancela ahora.
     * Replica la lógica de LTMS_Booking_Policy_Handler::calculate_refund().
     */
    private function estimate_refund( array $booking ): array {
        $total         = (float) ( $booking['total_price'] ?? 0 );
        $policy_type   = $booking['policy_type'] ?? 'flexible';
        $free_cancel_h = (int)   ( $booking['free_cancel_hours']  ?? 24 );
        $partial_pct   = (float) ( $booking['partial_refund_pct'] ?? 50 );
        $partial_h     = (int)   ( $booking['partial_refund_hours'] ?? 0 );

        if ( ! $booking['checkin_date'] ) {
            return [ 'amount' => $total ];
        }

        $hours_to_checkin = ( strtotime( $booking['checkin_date'] ) - time() ) / HOUR_IN_SECONDS;

        if ( 'strict' === $policy_type ) {
            $amount = 0.0;
        } elseif ( 'flexible' === $policy_type ) {
            $amount = $hours_to_checkin >= $free_cancel_h ? $total : 0.0;
        } else { // moderate
            if ( $hours_to_checkin >= $free_cancel_h ) {
                $amount = $total;
            } elseif ( $partial_h > 0 && $hours_to_checkin >= $partial_h ) {
                $amount = round( $total * ( $partial_pct / 100 ), 2 );
            } else {
                $amount = 0.0;
            }
        }

        return [ 'amount' => $amount ];
    }
}
