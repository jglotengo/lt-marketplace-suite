<?php
/**
 * LTMS Admin Deposits - Panel de Gestión de Depósitos Manuales
 *
 * Controlador AJAX y de menú para que el admin pueda revisar,
 * aprobar o rechazar las solicitudes de depósito manual de los vendedores.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Deposits
 */
final class LTMS_Admin_Deposits {

    use LTMS_Logger_Aware;

    /**
     * Registra hooks de admin.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();

        // Submenú en el panel LTMS
        add_action( 'admin_menu', [ $instance, 'register_menu' ] );

        // AJAX handlers
        add_action( 'wp_ajax_ltms_approve_deposit', [ $instance, 'ajax_approve_deposit' ] );
        add_action( 'wp_ajax_ltms_reject_deposit',  [ $instance, 'ajax_reject_deposit' ] );
        add_action( 'wp_ajax_ltms_get_deposit',     [ $instance, 'ajax_get_deposit' ] );
    }

    /**
     * Registra la página de depósitos en el menú admin.
     *
     * @return void
     */
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

    /**
     * Renderiza la página de depósitos.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Permisos insuficientes.', 'ltms' ) );
        }

        $status    = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore
        $method    = sanitize_text_field( wp_unslash( $_GET['method'] ?? '' ) ); // phpcs:ignore
        $date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ); // phpcs:ignore
        $date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ); // phpcs:ignore
        $search    = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore
        $paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
        $per_page  = 25;

        $data  = LTMS_Deposit::get_all( compact( 'status', 'method', 'date_from', 'date_to', 'search', 'paged', 'per_page' ) );
        $items = $data['items'];
        $total = $data['total'];
        $pages = (int) ceil( $total / $per_page );

        $nonce = wp_create_nonce( 'ltms_admin_nonce' );
        ?>
        <div class="wrap ltms-deposits-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Depósitos Manuales', 'ltms' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Filtros -->
            <form method="get" class="ltms-filters">
                <input type="hidden" name="page" value="ltms-deposits">
                <div class="ltms-filter-row" style="display:flex;gap:10px;align-items:center;margin:15px 0;flex-wrap:wrap;">
                    <select name="status">
                        <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                        <option value="pending"  <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                        <option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Aprobado', 'ltms' ); ?></option>
                        <option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rechazado', 'ltms' ); ?></option>
                    </select>
                    <select name="method">
                        <option value=""><?php esc_html_e( 'Todos los métodos', 'ltms' ); ?></option>
                        <option value="pse"           <?php selected( $method, 'pse' ); ?>>PSE</option>
                        <option value="nequi"         <?php selected( $method, 'nequi' ); ?>>Nequi</option>
                        <option value="transferencia" <?php selected( $method, 'transferencia' ); ?>><?php esc_html_e( 'Transferencia', 'ltms' ); ?></option>
                    </select>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="Desde">
                    <input type="date" name="date_to"   value="<?php echo esc_attr( $date_to ); ?>"   placeholder="Hasta">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar vendedor o ref...">
                    <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
                </div>
            </form>

            <!-- Stats rápidas -->
            <?php
            $pending_count = LTMS_Deposit::count_pending();
            if ( $pending_count > 0 ) :
                ?>
                <div class="notice notice-warning inline" style="margin:0 0 15px;">
                    <p><?php printf( esc_html__( 'Hay %d depósito(s) pendiente(s) de revisión.', 'ltms' ), $pending_count ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Tabla -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px">#ID</th>
                        <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Referencia', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:20px;"><?php esc_html_e( 'No hay depósitos que coincidan.', 'ltms' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $items as $d ) : ?>
                        <?php
                        $status_labels = [
                            'pending'  => '<span style="color:#f0a500;font-weight:bold">⏳ Pendiente</span>',
                            'approved' => '<span style="color:#46b450;font-weight:bold">✅ Aprobado</span>',
                            'rejected' => '<span style="color:#dc3232;font-weight:bold">❌ Rechazado</span>',
                        ];
                        $method_labels = [
                            'pse'           => 'PSE',
                            'nequi'         => 'Nequi',
                            'transferencia' => 'Transferencia',
                        ];
                        ?>
                        <tr id="deposit-row-<?php echo (int) $d['id']; ?>">
                            <td><strong>#<?php echo (int) $d['id']; ?></strong></td>
                            <td>
                                <?php echo esc_html( $d['vendor_name'] ?? "#{$d['vendor_id']}" ); ?><br>
                                <small style="color:#666"><?php echo esc_html( $d['vendor_email'] ?? '' ); ?></small>
                            </td>
                            <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $d['amount'], $d['currency'] ) ); ?></strong></td>
                            <td><?php echo esc_html( $method_labels[ $d['method'] ] ?? $d['method'] ); ?></td>
                            <td>
                                <?php echo esc_html( $d['reference'] ?: '—' ); ?>
                                <?php if ( $d['receipt_url'] ) : ?>
                                    <br><a href="<?php echo esc_url( $d['receipt_url'] ); ?>" target="_blank" style="font-size:11px">
                                        📎 <?php esc_html_e( 'Ver comprobante', 'ltms' ); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo wp_kses_post( $status_labels[ $d['status'] ] ?? esc_html( $d['status'] ) ); ?></td>
                            <td>
                                <?php echo esc_html( substr( $d['created_at'], 0, 16 ) ); ?>
                                <?php if ( $d['notes'] ) : ?>
                                    <br><small title="<?php echo esc_attr( $d['notes'] ); ?>" style="color:#888;cursor:help">💬 <?php esc_html_e( 'Ver nota', 'ltms' ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $d['status'] === 'pending' ) : ?>
                                    <button
                                        class="button button-primary ltms-approve-deposit"
                                        data-id="<?php echo (int) $d['id']; ?>"
                                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                        <?php esc_html_e( 'Aprobar', 'ltms' ); ?>
                                    </button>
                                    <button
                                        class="button ltms-reject-deposit"
                                        data-id="<?php echo (int) $d['id']; ?>"
                                        data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                        style="color:#dc3232;border-color:#dc3232;">
                                        <?php esc_html_e( 'Rechazar', 'ltms' ); ?>
                                    </button>
                                <?php elseif ( $d['status'] === 'approved' ) : ?>
                                    <small style="color:#46b450">
                                        <?php
                                        printf(
                                            esc_html__( 'Por admin #%d', 'ltms' ),
                                            (int) $d['approved_by']
                                        );
                                        ?>
                                    </small>
                                <?php elseif ( $d['status'] === 'rejected' ) : ?>
                                    <small style="color:#dc3232" title="<?php echo esc_attr( $d['reject_reason'] ?? '' ); ?>">
                                        <?php esc_html_e( 'Ver motivo', 'ltms' ); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Paginación -->
            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom" style="margin-top:10px;">
                    <?php
                    echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ] );
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal rechazo -->
        <div id="ltms-reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:30px;border-radius:8px;max-width:500px;width:90%;">
                <h2><?php esc_html_e( 'Rechazar depósito', 'ltms' ); ?></h2>
                <input type="hidden" id="ltms-reject-deposit-id" value="">
                <label for="ltms-reject-reason"><strong><?php esc_html_e( 'Motivo del rechazo:', 'ltms' ); ?></strong></label>
                <textarea id="ltms-reject-reason" rows="4" style="width:100%;margin:10px 0;" placeholder="Ej: Comprobante ilegible, referencia incorrecta..."></textarea>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button class="button" id="ltms-reject-cancel"><?php esc_html_e( 'Cancelar', 'ltms' ); ?></button>
                    <button class="button button-primary" id="ltms-reject-confirm" style="background:#dc3232;border-color:#dc3232;">
                        <?php esc_html_e( 'Confirmar rechazo', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            // Aprobar
            $(document).on('click', '.ltms-approve-deposit', function() {
                if (!confirm('¿Confirmar aprobación? Se acreditará el monto en la billetera del vendedor.')) return;
                const btn      = $(this);
                const id       = btn.data('id');
                const nonce    = btn.data('nonce');
                const notes    = prompt('Notas del admin (opcional):') || '';
                btn.prop('disabled', true).text('Procesando...');
                $.post(ajaxurl, { action: 'ltms_approve_deposit', deposit_id: id, admin_notes: notes, nonce: nonce }, function(res) {
                    if (res.success) {
                        alert('✅ ' + res.data.message);
                        location.reload();
                    } else {
                        alert('❌ Error: ' + (res.data || 'Error desconocido'));
                        btn.prop('disabled', false).text('Aprobar');
                    }
                });
            });

            // Rechazar — abrir modal
            $(document).on('click', '.ltms-reject-deposit', function() {
                $('#ltms-reject-deposit-id').val($(this).data('id'));
                $('#ltms-reject-reason').val('');
                $('#ltms-reject-modal').css('display','flex');
            });

            // Modal — cancelar
            $('#ltms-reject-cancel').on('click', function() {
                $('#ltms-reject-modal').hide();
            });

            // Modal — confirmar rechazo
            $('#ltms-reject-confirm').on('click', function() {
                const id     = $('#ltms-reject-deposit-id').val();
                const reason = $('#ltms-reject-reason').val().trim();
                const nonce  = $('.ltms-reject-deposit[data-id="'+id+'"]').data('nonce');
                if (!reason) { alert('Debes indicar el motivo.'); return; }
                $(this).prop('disabled', true).text('Rechazando...');
                $.post(ajaxurl, { action: 'ltms_reject_deposit', deposit_id: id, reason: reason, nonce: nonce }, function(res) {
                    if (res.success) {
                        alert('Depósito rechazado.');
                        location.reload();
                    } else {
                        alert('Error: ' + (res.data || 'Error desconocido'));
                        $('#ltms-reject-confirm').prop('disabled', false).text('Confirmar rechazo');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: Aprobar depósito.
     *
     * @return void
     */
    public function ajax_approve_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $deposit_id  = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $admin_notes = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) ); // phpcs:ignore

        if ( ! $deposit_id ) {
            wp_send_json_error( __( 'ID de depósito inválido.', 'ltms' ) );
        }

        $result = LTMS_Deposit::approve( $deposit_id, get_current_user_id(), $admin_notes );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Rechazar depósito.
     *
     * @return void
     */
    public function ajax_reject_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $deposit_id = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $reason     = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore

        if ( ! $deposit_id ) {
            wp_send_json_error( __( 'ID de depósito inválido.', 'ltms' ) );
        }

        $result = LTMS_Deposit::reject( $deposit_id, get_current_user_id(), $reason );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Obtener datos de un depósito (para modal de detalle).
     *
     * @return void
     */
    public function ajax_get_deposit(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $deposit_id = (int) ( $_POST['deposit_id'] ?? 0 ); // phpcs:ignore
        $deposit    = LTMS_Deposit::get( $deposit_id );

        if ( ! $deposit ) {
            wp_send_json_error( __( 'Depósito no encontrado.', 'ltms' ) );
        }

        wp_send_json_success( $deposit );
    }
}
