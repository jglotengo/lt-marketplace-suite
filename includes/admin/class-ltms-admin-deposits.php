<?php
/**
 * LTMS Admin Deposits - Panel de Gestión de Depósitos Manuales
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LTMS_Admin_Deposits {

    use LTMS_Logger_Aware;

    public static function init(): void {
        $instance = new self();
        add_action( 'admin_menu', [ $instance, 'register_menu' ] );
        add_action( 'wp_ajax_ltms_approve_deposit', [ $instance, 'ajax_approve_deposit' ] );
        add_action( 'wp_ajax_ltms_reject_deposit',  [ $instance, 'ajax_reject_deposit' ] );
        add_action( 'wp_ajax_ltms_get_deposit',     [ $instance, 'ajax_get_deposit' ] );
    }

    public function register_menu(): void {
        $pending = LTMS_Deposit::count_pending();
        $badge   = $pending > 0
            ? ' <span class="awaiting-mod">' . esc_html( $pending ) . '</span>'
            : '';
        add_submenu_page(
            'ltms-dashboard',
            __( 'Depósitos Manuales', 'ltms' ),
            __( 'Depósitos', 'ltms' ) . $badge,
            'manage_woocommerce',
            'ltms-deposits',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Permisos insuficientes.', 'ltms' ) );
        }

        $status    = sanitize_text_field( wp_unslash( $_GET['status']    ?? '' ) ); // phpcs:ignore
        $method    = sanitize_text_field( wp_unslash( $_GET['method']    ?? '' ) ); // phpcs:ignore
        $date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ); // phpcs:ignore
        $date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) ); // phpcs:ignore
        $search    = sanitize_text_field( wp_unslash( $_GET['s']         ?? '' ) ); // phpcs:ignore
        $paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
        $per_page  = 25;

        $data  = LTMS_Deposit::get_all( compact( 'status', 'method', 'date_from', 'date_to', 'search', 'paged', 'per_page' ) );
        $items = $data['items'];
        $total = $data['total'];
        $pages = (int) ceil( $total / $per_page );
        $nonce = wp_create_nonce( 'ltms_admin_nonce' );

        // Stats globales — por status (sin traer todos los rows)
        $pending_count  = LTMS_Deposit::count_by_status( 'pending' );
        $approved_count = LTMS_Deposit::count_by_status( 'approved' );
        $rejected_count = LTMS_Deposit::count_by_status( 'rejected' );
        $total_approved = LTMS_Deposit::sum_approved();

        $status_badge = [
            'pending'  => 'ltms-badge-warning',
            'approved' => 'ltms-badge-success',
            'rejected' => 'ltms-badge-danger',
        ];
        $status_label = [
            'pending'  => '⏳ ' . __( 'Pendiente', 'ltms' ),
            'approved' => '✅ ' . __( 'Aprobado',  'ltms' ),
            'rejected' => '❌ ' . __( 'Rechazado', 'ltms' ),
        ];
        $method_label = [
            'pse'           => 'PSE',
            'nequi'         => 'Nequi',
            'transferencia' => 'Transferencia',
        ];
        $active_filters = $status || $method || $date_from || $date_to || $search;
        ?>
        <div class="wrap ltms-admin-wrap">

            <div class="ltms-header">
                <h1>&#x1F4B3; <?php esc_html_e( 'Depósitos Manuales', 'ltms' ); ?></h1>
            </div>

            <!-- Stats -->
            <div class="ltms-stats-grid" style="margin-bottom:20px;">
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Total depósitos', 'ltms' ); ?></span>
                    <span class="ltms-stat-value"><?php echo esc_html( number_format( $data['total'] ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( $pending_count ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Aprobados', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $approved_count ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Rechazados', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( $rejected_count ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Total acreditado (COP)', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#2563eb;">
                        $<?php echo esc_html( number_format( $total_approved, 0, ',', '.' ) ); ?>
                    </span>
                </div>
            </div>

            <!-- Filtros -->
            <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                <input type="hidden" name="page" value="ltms-deposits">
                <select name="status" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
                    <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                    <option value="pending"  <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                    <option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Aprobado', 'ltms' ); ?></option>
                    <option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rechazado', 'ltms' ); ?></option>
                </select>
                <select name="method" style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
                    <option value=""><?php esc_html_e( 'Todos los métodos', 'ltms' ); ?></option>
                    <option value="pse"           <?php selected( $method, 'pse' ); ?>>PSE</option>
                    <option value="nequi"         <?php selected( $method, 'nequi' ); ?>>Nequi</option>
                    <option value="transferencia" <?php selected( $method, 'transferencia' ); ?>><?php esc_html_e( 'Transferencia', 'ltms' ); ?></option>
                </select>
                <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
                       style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
                <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
                       style="padding:7px 10px;border:1px solid #ddd;border-radius:4px;">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Buscar vendedor o ref...', 'ltms' ); ?>"
                       style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;width:200px;">
                <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
                    &#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
                </button>
                <?php if ( $active_filters ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-deposits' ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm">
                    &#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
                </a>
                <?php endif; ?>
                <span style="font-size:12px;color:#888;margin-left:auto;">
                    <?php printf( esc_html__( '%d registros', 'ltms' ), $total ); ?>
                </span>
            </form>

            <!-- Tabla -->
            <div class="ltms-table-wrap">

                <?php if ( $pages > 1 ) : ?>
                <div style="display:flex;justify-content:flex-end;gap:4px;padding:8px 0;flex-wrap:wrap;">
                    <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"
                       class="ltms-btn ltms-btn-sm <?php echo $p === $paged ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                       style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

                <table class="ltms-table">
                    <thead><tr>
                        <th><?php esc_html_e( '#ID', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Referencia', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#888;">
                            <div style="font-size:32px;margin-bottom:8px;">&#x1F4B3;</div>
                            <?php esc_html_e( 'No hay depósitos que coincidan.', 'ltms' ); ?>
                        </td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $d ) :
                            $st  = $d['status'] ?? '';
                            $bdg = $status_badge[ $st ] ?? 'ltms-badge-secondary';
                            $lbl = $status_label[ $st ] ?? strtoupper( $st );
                        ?>
                        <tr id="deposit-row-<?php echo (int) $d['id']; ?>">
                            <td style="font-size:12px;color:#888;"><strong>#<?php echo esc_html( $d['id'] ); ?></strong></td>
                            <td>
                                <strong><?php echo esc_html( $d['vendor_name'] ?? "#{$d['vendor_id']}" ); ?></strong>
                                <?php if ( ! empty( $d['vendor_email'] ) ) : ?>
                                <br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html( $d['vendor_email'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>$<?php echo esc_html( number_format( (float) $d['amount'], 0, ',', '.' ) ); ?></strong>
                                <span style="font-size:11px;color:#888;"><?php echo esc_html( $d['currency'] ?? '' ); ?></span>
                            </td>
                            <td>
                                <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:11px;">
                                    <?php echo esc_html( $method_label[ $d['method'] ] ?? $d['method'] ); ?>
                                </code>
                            </td>
                            <td style="font-size:12px;">
                                <?php echo esc_html( $d['reference'] ?: '—' ); ?>
                                <?php if ( ! empty( $d['receipt_url'] ) ) : ?>
                                <br><a href="<?php echo esc_url( $d['receipt_url'] ); ?>" target="_blank"
                                       style="font-size:11px;color:#2563eb;">
                                    &#x1F4CE; <?php esc_html_e( 'Ver comprobante', 'ltms' ); ?>
                                </a>
                                <?php endif; ?>
                            </td>
                            <td><span class="ltms-badge <?php echo esc_attr( $bdg ); ?>"><?php echo esc_html( $lbl ); ?></span></td>
                            <td style="white-space:nowrap;font-size:12px;color:#6b7280;">
                                <?php echo esc_html( substr( $d['created_at'] ?? '', 0, 16 ) ); ?>
                                <?php if ( ! empty( $d['notes'] ) ) : ?>
                                <br><span title="<?php echo esc_attr( $d['notes'] ); ?>"
                                          style="cursor:help;color:#9ca3af;">&#x1F4AC; <?php esc_html_e( 'Ver nota', 'ltms' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="display:flex;gap:4px;flex-wrap:wrap;">
                                <?php if ( $st === 'pending' ) : ?>
                                <button class="ltms-btn ltms-btn-success ltms-btn-sm ltms-approve-deposit"
                                        data-id="<?php echo esc_attr( $d['id'] ); ?>"
                                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                    &#x2713; <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                                </button>
                                <button class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-reject-deposit"
                                        data-id="<?php echo esc_attr( $d['id'] ); ?>"
                                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                    &#x2717; <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                                </button>
                                <?php elseif ( $st === 'approved' ) : ?>
                                <span style="font-size:11px;color:#16a34a;">
                                    <?php printf( esc_html__( 'Admin #%d', 'ltms' ), (int) $d['approved_by'] ); ?>
                                </span>
                                <?php elseif ( $st === 'rejected' ) : ?>
                                <?php if ( ! empty( $d['reject_reason'] ) ) : ?>
                                <details style="font-size:11px;color:#dc2626;cursor:pointer;">
                                    <summary style="list-style:none;cursor:pointer;">🔴 <?php esc_html_e( 'Ver motivo', 'ltms' ); ?></summary>
                                    <p style="margin:4px 0 0;font-size:11px;background:#fee2e2;padding:4px 8px;border-radius:4px;color:#991b1b;max-width:200px;word-wrap:break-word;">
                                        <?php echo esc_html( $d['reject_reason'] ); ?>
                                    </p>
                                </details>
                                <?php else : ?>
                                <span style="font-size:11px;color:#dc2626;">❌ <?php esc_html_e( 'Rechazado', 'ltms' ); ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap;">
                    <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"
                       class="ltms-btn ltms-btn-sm <?php echo $p === $paged ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                       style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            </div><!-- .ltms-table-wrap -->
        </div><!-- .ltms-admin-wrap -->

        <!-- Modal aprobación LTMS -->
        <div id="ltms-approve-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:28px;border-radius:8px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <h2 style="margin:0 0 8px;font-size:16px;font-weight:700;">✅ <?php esc_html_e( 'Aprobar depósito', 'ltms' ); ?></h2>
                <p style="margin:0 0 16px;font-size:13px;color:#6b7280;"><?php esc_html_e( 'Se acreditará el monto en la billetera del vendedor. Esta acción no se puede deshacer.', 'ltms' ); ?></p>
                <input type="hidden" id="ltms-approve-deposit-id" value="">
                <input type="hidden" id="ltms-approve-deposit-nonce" value="">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
                    <?php esc_html_e( 'Notas del admin (opcional):', 'ltms' ); ?>
                </label>
                <textarea id="ltms-approve-notes" rows="3"
                          style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:16px;resize:vertical;"
                          placeholder="<?php esc_attr_e( 'Ej: Verificado contra extracto bancario...', 'ltms' ); ?>"></textarea>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="ltms-btn ltms-btn-outline" id="ltms-approve-cancel">
                        <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </button>
                    <button class="ltms-btn ltms-btn-success" id="ltms-approve-confirm">
                        ✓ <?php esc_html_e( 'Confirmar aprobación', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>

                <!-- Modal rechazo LTMS -->
        <div id="ltms-reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:28px;border-radius:8px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <h2 style="margin:0 0 16px;font-size:16px;font-weight:700;">&#x274C; <?php esc_html_e( 'Rechazar depósito', 'ltms' ); ?></h2>
                <input type="hidden" id="ltms-reject-deposit-id" value="">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">
                    <?php esc_html_e( 'Motivo del rechazo:', 'ltms' ); ?>
                </label>
                <textarea id="ltms-reject-reason" rows="4"
                          style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:16px;resize:vertical;"
                          placeholder="<?php esc_attr_e( 'Ej: Comprobante ilegible, referencia incorrecta...', 'ltms' ); ?>"></textarea>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="ltms-btn ltms-btn-outline" id="ltms-reject-cancel">
                        <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </button>
                    <button class="ltms-btn ltms-btn-danger" id="ltms-reject-confirm">
                        &#x2717; <?php esc_html_e( 'Confirmar rechazo', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        /* global jQuery, ajaxurl */
        ( function( $ ) {
            // Aprobar
            // Abrir modal de aprobación
            $( document ).on( 'click', '.ltms-approve-deposit', function() {
                $( '#ltms-approve-deposit-id' ).val( $( this ).data( 'id' ) );
                $( '#ltms-approve-deposit-nonce' ).val( $( this ).data( 'nonce' ) );
                $( '#ltms-approve-notes' ).val( '' );
                $( '#ltms-approve-modal' ).css( 'display', 'flex' );
            } );

            // Cancelar modal aprobación
            $( '#ltms-approve-cancel' ).on( 'click', function() { $( '#ltms-approve-modal' ).hide(); } );
            $( '#ltms-approve-modal' ).on( 'click', function( e ) { if ( $( e.target ).is( this ) ) $( this ).hide(); } );

            // Confirmar aprobación
            $( '#ltms-approve-confirm' ).on( 'click', function() {
                var id    = $( '#ltms-approve-deposit-id' ).val();
                var nonce = $( '#ltms-approve-deposit-nonce' ).val();
                var notes = $( '#ltms-approve-notes' ).val().trim();
                var $btn  = $( this ).prop( 'disabled', true ).text( '<?php echo esc_js( __( "Procesando...", "ltms" ) ); ?>' );
                $.post( ajaxurl, { action: 'ltms_approve_deposit', deposit_id: id, admin_notes: notes, nonce: nonce }, function( res ) {
                    $( '#ltms-approve-modal' ).hide();
                    if ( res.success ) { location.reload(); }
                    else { alert( '❌ ' + ( res.data || '<?php echo esc_js( __( "Error desconocido", "ltms" ) ); ?>' ) ); $btn.prop( 'disabled', false ).text( '✓ <?php echo esc_js( __( "Confirmar", "ltms" ) ); ?>' ); }
                } );
            } );

            // Abrir modal rechazo
            $( document ).on( 'click', '.ltms-reject-deposit', function() {
                $( '#ltms-reject-deposit-id' ).val( $( this ).data( 'id' ) );
                $( '#ltms-reject-reason' ).val( '' );
                $( '#ltms-reject-modal' ).css( 'display', 'flex' );
            } );

            // Cerrar modal
            $( '#ltms-reject-cancel' ).on( 'click', function() { $( '#ltms-reject-modal' ).hide(); } );
            $( '#ltms-reject-modal' ).on( 'click', function( e ) { if ( $( e.target ).is( this ) ) $( this ).hide(); } );

            // Confirmar rechazo
            $( '#ltms-reject-confirm' ).on( 'click', function() {
                var id     = $( '#ltms-reject-deposit-id' ).val();
                var reason = $( '#ltms-reject-reason' ).val().trim();
                var nonce  = $( '.ltms-reject-deposit[data-id="' + id + '"]' ).data( 'nonce' );
                if ( ! reason ) { alert( '<?php echo esc_js( __( "Debes indicar el motivo.", "ltms" ) ); ?>' ); return; }
                var $btn = $( this ).prop( 'disabled', true ).text( '<?php echo esc_js( __( "Rechazando...", "ltms" ) ); ?>' );
                $.post( ajaxurl, { action: 'ltms_reject_deposit', deposit_id: id, reason: reason, nonce: nonce }, function( res ) {
                    if ( res.success ) { alert( '<?php echo esc_js( __( "Depósito rechazado.", "ltms" ) ); ?>' ); location.reload(); }
                    else { alert( '<?php echo esc_js( __( "Error:", "ltms" ) ); ?> ' + ( res.data || '<?php echo esc_js( __( "Error desconocido", "ltms" ) ); ?>' ) ); $btn.prop( 'disabled', false ).text( '✗ <?php echo esc_js( __( "Confirmar rechazo", "ltms" ) ); ?>' ); }
                } );
            } );
        } )( jQuery );
        </script>
        <?php
    }

    public function ajax_approve_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 ); }
        $deposit_id  = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $admin_notes = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) ); // phpcs:ignore
        if ( ! $deposit_id ) { wp_send_json_error( __( 'ID de depósito inválido.', 'ltms' ) ); }
        $result = LTMS_Deposit::approve( $deposit_id, get_current_user_id(), $admin_notes );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result['message'] );
    }

    public function ajax_reject_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 ); }
        $deposit_id = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $reason     = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore
        if ( ! $deposit_id ) { wp_send_json_error( __( 'ID de depósito inválido.', 'ltms' ) ); }
        $result = LTMS_Deposit::reject( $deposit_id, get_current_user_id(), $reason );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result['message'] );
    }

    public function ajax_get_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        $deposit_id = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $deposit    = LTMS_Deposit::get( $deposit_id );
        $deposit ? wp_send_json_success( $deposit ) : wp_send_json_error( __( 'Depósito no encontrado.', 'ltms' ) );
    }
}
