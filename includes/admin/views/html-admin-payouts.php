<?php
/**
 * Vista: Admin Payouts - Solicitudes de Retiro
 *
 * F-05: Panel completo con aprobar/rechazar, modal de motivo, JS AJAX,
 * paginación, filtros por estado y columnas net_amount/fee/gateway_ref.
 *
 * @package LTMS
 * @version 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$status_filter = sanitize_key( $_GET['status'] ?? 'pending' ); // phpcs:ignore
$page_num      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );      // phpcs:ignore
$per_page      = 20;
$offset        = ( $page_num - 1 ) * $per_page;

$table = $wpdb->prefix . 'lt_payout_requests';

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$valid_statuses = [ 'pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled' ];
if ( $status_filter && in_array( $status_filter, $valid_statuses, true ) ) {
    $payouts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.*, u.display_name, u.user_email
               FROM `{$table}` p
               LEFT JOIN `{$wpdb->users}` u ON u.ID = p.vendor_id
              WHERE p.status = %s
              ORDER BY p.created_at DESC
              LIMIT %d OFFSET %d",
            $status_filter, $per_page, $offset
        ),
        ARRAY_A
    );
    $total = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", $status_filter )
    );
} else {
    $payouts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.*, u.display_name, u.user_email
               FROM `{$table}` p
               LEFT JOIN `{$wpdb->users}` u ON u.ID = p.vendor_id
              ORDER BY p.created_at DESC
              LIMIT %d OFFSET %d",
            $per_page, $offset
        ),
        ARRAY_A
    );
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
}
// phpcs:enable

$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$status_labels = [
    'pending'    => [ 'label' => 'Pendiente',   'class' => 'ltms-badge-warning' ],
    'approved'   => [ 'label' => 'Aprobado',    'class' => 'ltms-badge-info' ],
    'processing' => [ 'label' => 'Procesando',  'class' => 'ltms-badge-info' ],
    'completed'  => [ 'label' => 'Completado',  'class' => 'ltms-badge-success' ],
    'rejected'   => [ 'label' => 'Rechazado',   'class' => 'ltms-badge-danger' ],
    'cancelled'  => [ 'label' => 'Cancelado',   'class' => 'ltms-badge-danger' ],
];

$nonce = wp_create_nonce( 'ltms_admin_nonce' );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>💸 Solicitudes de Retiro</h1>
    </div>

    <!-- Filtros de estado -->
    <div style="margin-bottom:20px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-payouts' ) ); ?>"
           class="ltms-btn <?php echo ! $status_filter ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-btn-sm">
            Todos
        </a>
        <?php foreach ( $status_labels as $s => $info ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-payouts&status=' . $s ) ); ?>"
           class="ltms-btn <?php echo $status_filter === $s ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-btn-sm">
            <?php echo esc_html( $info['label'] ); ?>
        </a>
        <?php endforeach; ?>
        <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-export-payouts" style="margin-left:auto"
                data-status="<?php echo esc_attr( $status_filter ); ?>"
                data-nonce="<?php echo esc_attr( $nonce ); ?>">
            📥 Exportar CSV
        </button>
    </div>

    <div class="ltms-table-wrap">
        <div class="ltms-table-title" style="padding:12px 16px;font-weight:600;">
            <?php printf( 'Retiros %s — %d en total', esc_html( $status_labels[ $status_filter ]['label'] ?? 'Todos' ), $total ); ?>
        </div>

        <input type="text" id="ltms-payout-search"
               placeholder="Buscar por vendedor, email o referencia…"
               style="margin:0 16px 12px;padding:8px 12px;width:320px;border:1px solid #ddd;border-radius:4px;display:block;">

        <div style="overflow-x:auto;">
        <table class="ltms-table" id="ltms-payouts-table" style="min-width:900px;width:100%;">
            <thead>
                <tr>
                    <th style="width:50px">ID</th>
                    <th>Vendedor</th>
                    <th>Monto</th>
                    <th>Fee</th>
                    <th>Neto</th>
                    <th>Método</th>
                    <th>Estado</th>
                    <th>Referencia / Gateway</th>
                    <th>Fecha</th>
                    <th style="width:140px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $payouts ) ) : ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:#888;">
                    No hay solicitudes de retiro con este filtro.
                </td></tr>
                <?php else : ?>
                <?php foreach ( $payouts as $payout ) :
                    $badge = $status_labels[ $payout['status'] ] ?? [ 'label' => $payout['status'], 'class' => 'ltms-badge-pending' ];
                    $gateway_ref = $payout['gateway_ref'] ?: $payout['external_ref'] ?: '—';
                ?>
                <tr data-search="<?php echo esc_attr( strtolower( $payout['display_name'] . ' ' . $payout['user_email'] . ' ' . $payout['reference'] ) ); ?>">
                    <td><strong>#<?php echo esc_html( $payout['id'] ); ?></strong></td>
                    <td>
                        <?php echo esc_html( $payout['display_name'] ?: '—' ); ?><br>
                        <small style="color:#888"><?php echo esc_html( $payout['user_email'] ); ?></small>
                    </td>
                    <td><?php echo esc_html( number_format( (float) $payout['amount'], 0, ',', '.' ) ); ?></td>
                    <td style="color:#c00"><?php echo $payout['fee'] > 0 ? esc_html( number_format( (float) $payout['fee'], 0, ',', '.' ) ) : '—'; ?></td>
                    <td><strong><?php echo esc_html( number_format( (float) $payout['net_amount'], 0, ',', '.' ) ); ?></strong></td>
                    <td><?php echo esc_html( strtoupper( $payout['method'] ) ); ?></td>
                    <td><span class="ltms-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span></td>
                    <td>
                        <code style="font-size:0.75rem"><?php echo esc_html( $payout['reference'] ?: '—' ); ?></code>
                        <?php if ( $gateway_ref !== '—' ) : ?>
                        <br><small style="color:#888"><?php echo esc_html( $gateway_ref ); ?></small>
                        <?php endif; ?>
                        <?php if ( ! empty( $payout['rejection_reason'] ) ) : ?>
                        <br><small style="color:#c00" title="<?php echo esc_attr( $payout['rejection_reason'] ); ?>">
                            ⚠ <?php echo esc_html( mb_substr( $payout['rejection_reason'], 0, 40 ) ); ?>…
                        </small>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?php echo esc_html( gmdate( 'd/m/Y H:i', strtotime( $payout['created_at'] ) ) ); ?></td>
                    <td class="ltms-actions">
                        <?php if ( $payout['status'] === 'pending' ) : ?>
                        <button type="button"
                                class="ltms-btn ltms-btn-success ltms-btn-sm ltms-approve-payout"
                                data-payout-id="<?php echo esc_attr( $payout['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            ✓ Aprobar
                        </button>
                        <button type="button"
                                class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-reject-payout"
                                data-payout-id="<?php echo esc_attr( $payout['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            ✗ Rechazar
                        </button>
                        <?php else : ?>
                        <span style="color:#888;font-size:0.8rem">Procesado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Paginación -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="padding:16px;display:flex;gap:8px;align-items:center;justify-content:center;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-payouts&status=' . $status_filter . '&paged=' . $p ) ); ?>"
               style="padding:6px 12px;border:1px solid <?php echo $p === $page_num ? '#2271b1' : '#ddd'; ?>;
                      background:<?php echo $p === $page_num ? '#2271b1' : '#fff'; ?>;
                      color:<?php echo $p === $page_num ? '#fff' : '#333'; ?>;
                      border-radius:4px;text-decoration:none;font-size:0.85rem;">
                <?php echo esc_html( $p ); ?>
            </a>
            <?php endfor; ?>
            <span style="color:#888;font-size:0.85rem">
                Página <?php echo esc_html( $page_num ); ?> de <?php echo esc_html( $total_pages ); ?>
                (<?php echo esc_html( $total ); ?> registros)
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- v2.9.115 PAYOUT-AUDIT P2-1: Modal de aprobación (reemplaza native confirm) -->
<div id="ltms-approve-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:460px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 style="margin:0 0 16px">✓ Aprobar Retiro #<span id="ltms-approve-payout-id"></span></h3>
        <p style="margin:0 0 12px;color:#555">Esto ejecutará el pago al vendedor de forma irreversible. ¿Confirmar?</p>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" id="ltms-approve-cancel"
                    class="ltms-btn ltms-btn-outline ltms-btn-sm">Cancelar</button>
            <button type="button" id="ltms-approve-confirm"
                    class="ltms-btn ltms-btn-success ltms-btn-sm"
                    data-payout-id="" data-nonce="">Confirmar Aprobación</button>
        </div>
    </div>
</div>

<!-- Modal de Rechazo -->
<div id="ltms-reject-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px;width:460px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 style="margin:0 0 16px">✗ Rechazar Retiro #<span id="ltms-reject-payout-id"></span></h3>
        <p style="margin:0 0 12px;color:#555">Por favor indica el motivo del rechazo. El vendedor será notificado por email.</p>
        <textarea id="ltms-reject-reason"
                  rows="4"
                  placeholder="Ej: Cuenta bancaria no coincide con los datos registrados en KYC."
                  style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:0.9rem;resize:vertical;"></textarea>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" id="ltms-reject-cancel"
                    class="ltms-btn ltms-btn-outline ltms-btn-sm">Cancelar</button>
            <button type="button" id="ltms-reject-confirm"
                    class="ltms-btn ltms-btn-danger ltms-btn-sm"
                    data-payout-id="" data-nonce="">
                Confirmar Rechazo
            </button>
        </div>
        <p id="ltms-reject-error" style="display:none;color:#c00;margin:10px 0 0;font-size:0.85rem"></p>
    </div>
</div>

<script>
(function($){
    'use strict';

    // ── Búsqueda local ────────────────────────────────────────────────
    $('#ltms-payout-search').on('input', function(){
        var q = $(this).val().toLowerCase();
        $('#ltms-payouts-table tbody tr').each(function(){
            var hay = $(this).data('search') || '';
            $(this).toggle( !q || hay.indexOf(q) !== -1 );
        });
    });

    // ── Aprobar ───────────────────────────────────────────────────────
    // v2.9.115 PAYOUT-AUDIT P2-1 FIX: replace native confirm() with modal dialog.
    // The native confirm() is blocked by some browsers and violates UIUX-AUDIT-001.
    $(document).on('click', '.ltms-approve-payout', function(){
        var $btn     = $(this);
        var payoutId = $btn.data('payout-id');
        var nonce    = $btn.data('nonce');

        // Show confirm modal instead of native confirm().
        $('#ltms-approve-payout-id').text(payoutId);
        $('#ltms-approve-confirm').data('payout-id', payoutId).data('nonce', nonce);
        $('#ltms-approve-modal').css('display', 'flex');
    });

    $('#ltms-approve-cancel').on('click', function(){
        $('#ltms-approve-modal').hide();
    });

    $('#ltms-approve-modal').on('click', function(e){
        if ( $(e.target).is('#ltms-approve-modal') ) $(this).hide();
    });

    $('#ltms-approve-confirm').on('click', function(){
        var $btn      = $(this);
        var payoutId  = $btn.data('payout-id');
        var nonce     = $btn.data('nonce');
        var $origBtn  = $('.ltms-approve-payout[data-payout-id="' + payoutId + '"]');

        $btn.prop('disabled', true).text('Procesando…');

        $.post(ajaxurl, {
            action:    'ltms_approve_payout',
            nonce:     nonce,
            payout_id: payoutId
        })
        .done(function(res){
            $('#ltms-approve-modal').hide();
            $btn.prop('disabled', false).text('Confirmar Aprobación');
            if ( res.success ) {
                if ( $origBtn.length ) {
                    $origBtn.closest('tr').find('.ltms-actions')
                        .html('<span style="color:green;font-weight:600">✓ Aprobado</span>');
                    $origBtn.closest('tr').find('.ltms-badge')
                        .removeClass().addClass('ltms-badge ltms-badge-success').text('Aprobado');
                }
                ltmsNotify('success', 'Retiro #' + payoutId + ' aprobado correctamente.');
            } else {
                ltmsNotify('error', res.data || 'Error al aprobar.');
            }
        })
        .fail(function(){
            $('#ltms-approve-modal').hide();
            $btn.prop('disabled', false).text('Confirmar Aprobación');
            ltmsNotify('error', 'Error de conexión.');
        });
    });

    // v2.9.115 P2-1: ESC closes any open modal.
    $(document).on('keydown', function(e){
        if ( e.key === 'Escape' ) {
            $('#ltms-approve-modal, #ltms-reject-modal').hide();
        }
    });

    // ── Abrir modal de rechazo ────────────────────────────────────────
    $(document).on('click', '.ltms-reject-payout', function(){
        var payoutId = $(this).data('payout-id');
        var nonce    = $(this).data('nonce');

        $('#ltms-reject-payout-id').text(payoutId);
        $('#ltms-reject-reason').val('');
        $('#ltms-reject-error').hide();
        $('#ltms-reject-confirm').data('payout-id', payoutId).data('nonce', nonce);
        $('#ltms-reject-modal').css('display', 'flex');
        setTimeout(function(){ $('#ltms-reject-reason').focus(); }, 100);
    });

    $('#ltms-reject-cancel').on('click', function(){
        $('#ltms-reject-modal').hide();
    });

    // Cerrar al hacer clic fuera
    $('#ltms-reject-modal').on('click', function(e){
        if ( $(e.target).is('#ltms-reject-modal') ) $(this).hide();
    });

    // ── Confirmar rechazo ─────────────────────────────────────────────
    $('#ltms-reject-confirm').on('click', function(){
        var $btn     = $(this);
        var payoutId = $btn.data('payout-id');
        var nonce    = $btn.data('nonce');
        var reason   = $('#ltms-reject-reason').val().trim();

        if ( ! reason ) {
            $('#ltms-reject-error').text('El motivo es obligatorio.').show();
            return;
        }

        $btn.prop('disabled', true).text('Procesando…');
        $('#ltms-reject-error').hide();

        $.post(ajaxurl, {
            action:    'ltms_reject_payout',
            nonce:     nonce,
            payout_id: payoutId,
            reason:    reason
        })
        .done(function(res){
            if ( res.success ) {
                $('#ltms-reject-modal').hide();
                // Actualizar fila
                var $row = $('[data-payout-id="' + payoutId + '"]').closest('tr');
                $row.find('.ltms-actions').html('<span style="color:#c00;font-weight:600">✗ Rechazado</span>');
                $row.find('.ltms-badge').removeClass().addClass('ltms-badge ltms-badge-danger').text('Rechazado');
                ltmsNotify('success', 'Retiro #' + payoutId + ' rechazado. Vendedor notificado.');
            } else {
                $btn.prop('disabled', false).text('Confirmar Rechazo');
                $('#ltms-reject-error').text(res.data || 'Error al rechazar.').show();
            }
        })
        .fail(function(){
            $btn.prop('disabled', false).text('Confirmar Rechazo');
            $('#ltms-reject-error').text('Error de conexión.').show();
        });
    });

    // ── Exportar CSV ──────────────────────────────────────────────────
    $(document).on('click', '.ltms-export-payouts', function(){
        var $btn   = $(this);
        var status = $btn.data('status');
        var nonce  = $btn.data('nonce');

        $btn.prop('disabled', true).text('Exportando…');

        $.post(ajaxurl, {
            action: 'ltms_export_payouts',
            nonce:  nonce,
            status: status
        })
        .done(function(res){
            if ( res.success && res.data.csv ) {
                var binary = atob(res.data.csv);
                var bytes  = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                var blob = new Blob([bytes], {type:'text/csv;charset=utf-8;'});
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = res.data.filename;
                a.click();
                URL.revokeObjectURL(url);
                ltmsNotify('success', res.data.count + ' retiros exportados.');
            } else {
                ltmsNotify('error', res.data || 'Error al exportar.');
            }
        })
        .fail(function(){
            ltmsNotify('error', 'Error de conexión al exportar.');
        })
        .always(function(){
            $btn.prop('disabled', false).text('📥 Exportar CSV');
        });
    });

    // ── Notificaciones inline ─────────────────────────────────────────
    function ltmsNotify(type, msg) {
        var color = type === 'success' ? '#00a32a' : '#d63638';
        var $n = $('<div>')
            .text(msg)
            .css({
                position:    'fixed',
                top:         '40px',
                right:       '24px',
                background:  color,
                color:       '#fff',
                padding:     '12px 20px',
                borderRadius:'6px',
                zIndex:      99998,
                boxShadow:   '0 4px 12px rgba(0,0,0,.2)',
                fontSize:    '0.9rem',
                maxWidth:    '360px'
            })
            .appendTo('body');
        setTimeout(function(){ $n.fadeOut(400, function(){ $n.remove(); }); }, 4000);
    }

})(jQuery);
</script>
