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
                $new[ self::ENDPOINT ] = __( 'Mis Reservas', 'ltms' );
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

        // AUDIT-FE-UIUX3-MA-02 FIX: los rótulos de estado pierden la iconografia
        // emoji — el significado ya lo comunica el badge con su variante de color.
        $status_labels = [
            'pending'   => __( 'Pendiente de pago', 'ltms' ),
            'confirmed' => __( 'Confirmada', 'ltms' ),
            'cancelled' => __( 'Cancelada', 'ltms' ),
            'completed' => __( 'Completada', 'ltms' ),
        ];
        // AUDIT-FE-UIUX3-MA-05 FIX: el mapa de colores y el estilo inline del badge
        // se retiraron; el estado se expresa con clases modificadoras definidas
        // en el bloque <style> de abajo (receta -50/-700 del design system).
        ?>
        <style>
            /* AUDIT-FE-UIUX3-MA-01 FIX: paleta migrada a los tokens globales del design
               system Plaza Viva (:root en ltms-plaza-viva.css, encolado en todo el
               frontend). Antes cada regla traia su propio hex, desincronizado del DS. */
            .ltms-cb-wrap { font-family: inherit; max-width: 900px; }
            .ltms-cb-header { margin-bottom: 20px; }
            .ltms-cb-header h2 { font-size: 1.3rem; color: var(--text); margin: 0 0 4px; }
            .ltms-cb-header p { color: var(--text-2); font-size: .875rem; margin: 0; }
            .ltms-cb-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 16px; overflow: hidden; }
            .ltms-cb-card-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; flex-wrap: wrap; gap: 10px; }
            .ltms-cb-card-head-left h3 { margin: 0 0 4px; font-size: .95rem; color: var(--text); }
            .ltms-cb-card-head-left p { margin: 0; font-size: .8rem; color: var(--text-2); }
            .ltms-cb-badge { display: inline-block; padding: 3px 12px; border-radius: 99px; font-size: .78rem; font-weight: 700; }
            /* AUDIT-FE-UIUX3-MA-05 FIX: variantes de estado con receta del DS
               (fondo -50 + texto -700), paridad con .pv-badge--* de plaza-viva. */
            .ltms-cb-badge--pending   { background: var(--warn-50); color: var(--warn-700); }
            .ltms-cb-badge--confirmed { background: var(--accent-50); color: var(--accent-700); }
            .ltms-cb-badge--cancelled { background: var(--bg-2); color: var(--text-2); }
            .ltms-cb-badge--completed { background: var(--primary-50); color: var(--primary-700); }
            .ltms-cb-card-body { padding: 16px 20px; border-top: 1px solid var(--border); }
            .ltms-cb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px 20px; margin-bottom: 14px; }
            .ltms-cb-grid-item label { display: block; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: var(--text-3); margin-bottom: 3px; }
            .ltms-cb-grid-item span { font-size: .9rem; color: var(--text); font-weight: 500; }
            .ltms-cb-refund { background: var(--warn-50); border: 1px solid var(--warn); border-radius: 8px; padding: 10px 14px; font-size: .82rem; color: var(--warn-700); margin-bottom: 14px; }
            .ltms-cb-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            /* AUDIT-FE-UIUX3-MA-03 FIX: transicion limitada a propiedades visuales explicitas
               (patron D-27 del ciclo 2: nada de transiciones comodin que animen layout).
               AUDIT-FE-UIUX3-MA-04 FIX: altura minima 44px para target tactil (patron D-06). */
            .ltms-cb-btn { display: inline-flex; align-items: center; gap: 5px; min-height: 44px; padding: 8px 18px; border-radius: 7px; font-size: .85rem; font-weight: 600; border: none; cursor: pointer; transition: background .15s, border-color .15s, color .15s; text-decoration: none; }
            .ltms-cb-btn-danger { background: var(--danger-50); color: var(--danger-700); }
            .ltms-cb-btn-danger:hover { background: var(--danger); color: #fff; }
            .ltms-cb-btn-outline { background: transparent; color: var(--text-2); border: 1.5px solid var(--border-2); }
            .ltms-cb-btn-outline:hover { border-color: var(--text-3); }
            .ltms-cb-empty { text-align: center; padding: 48px 20px; color: var(--text-3); }
            .ltms-cb-empty .icon { margin-bottom: 12px; }
            .ltms-cb-pagination { display: flex; gap: 8px; margin-top: 20px; }
            /* AUDIT-FE-UIUX3-MA-04 FIX: pills de paginacion con area tactil de 44px. */
            .ltms-cb-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 0 14px; border: 1.5px solid var(--border-2); border-radius: var(--r-pill, 999px); font-size: .85rem; background: var(--surface); cursor: pointer; color: var(--text-2); text-decoration: none; }
            .ltms-cb-page-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
            /* AUDIT-FE-UIUX3-MA-06 FIX: foco visible para teclado (WCAG 2.4.7),
               misma receta del fix D-03 del ciclo 2. */
            .ltms-cb-btn:focus-visible,
            .ltms-cb-page-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
            /* AUDIT-FE-UIUX3-MA-01 FIX: notices con receta D-31 (borde izquierdo de color). */
            .ltms-cb-notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: .875rem; background: var(--surface); border: 1px solid var(--border); }
            .ltms-cb-notice.error { border-left: 4px solid var(--danger); color: var(--danger-700); }
            .ltms-cb-notice.success { border-left: 4px solid var(--accent); color: var(--accent-700); }
        </style>

        <div class="ltms-cb-wrap">

            <div class="ltms-cb-header">
                <!-- AUDIT-FE-UIUX3-MA-02 FIX: icono de edificio en SVG stroke (antes iconografia emoji). -->
                <h2>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-4px;margin-right:6px;"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 7h.01M14 7h.01M9 11h.01M14 11h.01M9 15h.01M14 15h.01"/></svg>
                    <?php esc_html_e( 'Mis Reservas', 'ltms' ); ?>
                </h2>
                <p><?php esc_html_e( 'Aquí puedes consultar y gestionar todas tus reservas de alojamiento y servicios en Lo Tengo.', 'ltms' ); ?></p>
            </div>

            <div id="ltms-cb-notice" style="display:none;"></div>

            <?php if ( empty( $bookings ) ) : ?>
                <div class="ltms-cb-empty">
                    <div class="icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 7h.01M14 7h.01M9 11h.01M14 11h.01M9 15h.01M14 15h.01"/></svg>
                    </div>
                    <p><strong><?php esc_html_e( 'Aún no tienes reservas.', 'ltms' ); ?></strong></p>
                    <p><?php esc_html_e( 'Cuando reserves un alojamiento o servicio en Lo Tengo, aparecerá aquí.', 'ltms' ); ?></p>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ltms-cb-btn ltms-cb-btn-outline">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <?php esc_html_e( 'Explorar alojamientos', 'ltms' ); ?>
                    </a>
                </div>

            <?php else : ?>

                <?php foreach ( $bookings as $b ) :
                    $status      = $b['status'] ?? 'pending';
                    $status_lbl  = $status_labels[ $status ] ?? $status;
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
                        <span class="ltms-cb-badge ltms-cb-badge--<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( $status_lbl ); ?>
                        </span>
                    </div>

                    <div class="ltms-cb-card-body">
                        <div class="ltms-cb-grid">
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Check-in', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $checkin ); ?></span>
                                <?php if ( $b['checkin_time'] ) : ?>
                                    <br><span style="font-size:.75rem;color:var(--text-2);"><?php echo esc_html( $b['checkin_time'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ltms-cb-grid-item">
                                <label><?php esc_html_e( 'Check-out', 'ltms' ); ?></label>
                                <span><?php echo esc_html( $checkout ); ?></span>
                                <?php if ( $b['checkout_time'] ) : ?>
                                    <br><span style="font-size:.75rem;color:var(--text-2);"><?php echo esc_html( $b['checkout_time'] ); ?></span>
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
                                <!-- AUDIT-FE-UIUX3-MA-02 FIX: aviso con SVG alerta (antes iconografia emoji). -->
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px;margin-right:4px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <?php printf(
                                    /* translators: 1: formatted currency amount */
                                    esc_html__( 'Si cancelas ahora, recibirías un reembolso de %s según la política de cancelación del alojamiento.', 'ltms' ),
                                    wp_kses_post( wc_price( $refund_info['amount'] ) )
                                ); ?>
                            <?php else : ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px;margin-right:4px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <?php esc_html_e( 'Si cancelas ahora, no recibirías reembolso según la política de cancelación del alojamiento.', 'ltms' ); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="ltms-cb-actions">
                            <?php if ( $order_url ) : ?>
                            <a href="<?php echo esc_url( $order_url ); ?>" class="ltms-cb-btn ltms-cb-btn-outline">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                <?php esc_html_e( 'Ver pedido', 'ltms' ); ?>
                            </a>
                            <?php endif; ?>

                            <?php if ( $can_cancel ) : ?>
                            <button
                                type="button"
                                class="ltms-cb-btn ltms-cb-btn-danger ltms-cb-cancel-btn"
                                data-booking-id="<?php echo esc_attr( $b['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <span class="ltms-cb-btn-label"><?php esc_html_e( 'Cancelar reserva', 'ltms' ); ?></span>
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
            /* AUDIT-FE-UIUX3-MA-07 FIX: se respeta la preferencia de movimiento
               reducido del usuario (paridad D-07 del ciclo 2). */
            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function showNotice(msg, type) {
                var $n = $('#ltms-cb-notice');
                $n.attr('class', 'ltms-cb-notice ' + type).html(msg).show();
                setTimeout(function(){ reduceMotion ? $n.hide() : $n.fadeOut(); }, 4000);
            }

            $(document).on('click', '.ltms-cb-cancel-btn', function(){
                if ( ! confirm(i18n.cancel_confirm || '¿Cancelar reserva?') ) return;
                var $btn      = $(this).prop('disabled', true);
                /* AUDIT-FE-UIUX3-MA-02 FIX: el label vive en un span para que la
                   carga/restore no borre el SVG del boton. */
                $btn.find('.ltms-cb-btn-label').text(i18n.cancelling || 'Cancelando…');
                var bookingId = $(this).data('booking-id');
                var nonce     = $(this).data('nonce');

                $.post('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action:     'ltms_customer_cancel_booking',
                    booking_id: bookingId,
                    nonce:      nonce,
                }, function(r) {
                    if ( r.success ) {
                        var $card = $('#ltms-cb-booking-' + bookingId);
                        if ( reduceMotion ) { $card.remove(); } else { $card.fadeOut(300, function(){ $(this).remove(); }); }
                        showNotice(r.data.message || '<?php echo esc_js( __( "Reserva cancelada.", "ltms" ) ); ?>', 'success');
                    } else {
                        $btn.prop('disabled', false).find('.ltms-cb-btn-label').text('<?php echo esc_js( __( "Cancelar reserva", "ltms" ) ); ?>');
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
