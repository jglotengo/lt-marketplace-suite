<?php
/**
 * Vista SPA: Reservas del Vendedor
 *
 * Lista de reservas, detalle en modal, y acción de cancelar con motivo.
 * Los datos se cargan vía AJAX desde LTMS_Frontend_Booking_Handler.
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Reservas', 'ltms' ); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <select id="ltms-bk-status-filter" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="cursor:pointer;">
                <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'ltms' ); ?></option>
                <option value="confirmed"><?php esc_html_e( 'Confirmada', 'ltms' ); ?></option>
                <option value="checked_in"><?php esc_html_e( 'En curso', 'ltms' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completada', 'ltms' ); ?></option>
                <option value="cancelled"><?php esc_html_e( 'Cancelada', 'ltms' ); ?></option>
            </select>
            <input type="date" id="ltms-bk-date-from"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"
                   placeholder="<?php esc_attr_e( 'Desde', 'ltms' ); ?>"
                   style="cursor:pointer;">
            <input type="date" id="ltms-bk-date-to"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"
                   placeholder="<?php esc_attr_e( 'Hasta', 'ltms' ); ?>"
                   style="cursor:pointer;">
        </div>
    </div>

    <!-- Tarjetas de resumen -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#1a5276;" id="ltms-bk-stat-total">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Total reservas', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#f59e0b;" id="ltms-bk-stat-pending">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Pendientes', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#16a34a;" id="ltms-bk-stat-confirmed">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Confirmadas', 'ltms' ); ?></div>
        </div>
        <div class="ltms-card" style="text-align:center;padding:16px 12px;">
            <div style="font-size:1.7rem;font-weight:700;color:#1a5276;" id="ltms-bk-stat-revenue">—</div>
            <div style="font-size:0.75rem;color:#6b7280;margin-top:4px;"><?php esc_html_e( 'Ingresos (neto)', 'ltms' ); ?></div>
        </div>
    </div>

    <!-- Tabla de reservas -->
    <div class="ltms-card">
        <div class="ltms-card-body ltms-table-scroll" style="padding:0;">
            <table class="ltms-dtable" style="width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( '#', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Huésped', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Huéspedes', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-bk-tbody">
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando reservas...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
        <!-- Paginación -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid #e5e7eb;">
            <span style="font-size:0.8rem;color:#6b7280;" id="ltms-bk-count-label"></span>
            <div style="display:flex;gap:8px;">
                <button class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-bk-prev" disabled>
                    ← <?php esc_html_e( 'Anterior', 'ltms' ); ?>
                </button>
                <button class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-bk-next" disabled>
                    <?php esc_html_e( 'Siguiente', 'ltms' ); ?> →
                </button>
            </div>
        </div>
    </div>

</div>

<!-- Modal: Detalle de reserva -->
<div id="ltms-modal-booking-detail" class="ltms-modal-overlay" style="display:none;">
    <div class="ltms-modal" style="max-width:560px;">
        <div class="ltms-modal-header">
            <h3 id="ltms-bk-modal-title"><?php esc_html_e( 'Detalle de Reserva', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" data-close-modal="ltms-modal-booking-detail">✕</button>
        </div>
        <div class="ltms-modal-body" id="ltms-bk-modal-body" style="font-size:0.9rem;line-height:1.7;"></div>
        <div class="ltms-modal-footer">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" data-close-modal="ltms-modal-booking-detail">
                <?php esc_html_e( 'Cerrar', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-sm" style="background:#dc2626;color:#fff;" id="ltms-bk-cancel-btn">
                <?php esc_html_e( 'Cancelar Reserva', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Confirmar cancelación -->
<div id="ltms-modal-booking-cancel" class="ltms-modal-overlay" style="display:none;">
    <div class="ltms-modal" style="max-width:440px;">
        <div class="ltms-modal-header">
            <h3><?php esc_html_e( 'Cancelar Reserva', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" data-close-modal="ltms-modal-booking-cancel">✕</button>
        </div>
        <div class="ltms-modal-body">
            <p style="color:#6b7280;margin-bottom:12px;">
                <?php esc_html_e( 'Esta acción generará un reembolso según la política de cancelación del producto y notificará al huésped.', 'ltms' ); ?>
            </p>
            <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:6px;">
                <?php esc_html_e( 'Motivo de cancelación', 'ltms' ); ?>
            </label>
            <textarea id="ltms-bk-cancel-reason" rows="3"
                      placeholder="<?php esc_attr_e( 'Ej: Mantenimiento programado, overbooking, etc.', 'ltms' ); ?>"
                      style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:0.875rem;resize:vertical;"></textarea>
            <div id="ltms-bk-cancel-notice" style="margin-top:8px;font-size:0.82rem;"></div>
        </div>
        <div class="ltms-modal-footer">
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" data-close-modal="ltms-modal-booking-cancel">
                <?php esc_html_e( 'Volver', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-sm" style="background:#dc2626;color:#fff;" id="ltms-bk-confirm-cancel-btn">
                <?php esc_html_e( 'Confirmar Cancelación', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function($){
    'use strict';

    // ── Estado ─────────────────────────────────────────────────────────────
    var BK = {
        page: 1,
        perPage: 20,
        total: 0,
        activeBookingId: null,
        stats: { total:0, pending:0, confirmed:0, revenue:0 },

        statusLabels: {
            pending:    '⏳ <?php esc_html_e("Pendiente","ltms"); ?>',
            confirmed:  '✅ <?php esc_html_e("Confirmada","ltms"); ?>',
            checked_in: '🏠 <?php esc_html_e("En curso","ltms"); ?>',
            completed:  '🎉 <?php esc_html_e("Completada","ltms"); ?>',
            cancelled:  '❌ <?php esc_html_e("Cancelada","ltms"); ?>',
        },
        statusColors: {
            pending:    '#f59e0b',
            confirmed:  '#16a34a',
            checked_in: '#2563eb',
            completed:  '#6b7280',
            cancelled:  '#dc2626',
        }
    };

    // ── Helpers ────────────────────────────────────────────────────────────
    function fmtDate(d) {
        if (!d || d === '0000-00-00') return '—';
        var p = d.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function fmtCOP(v) {
        return 'COP ' + parseFloat(v||0).toLocaleString('es-CO', {minimumFractionDigits:0});
    }
    function statusBadge(s) {
        var lbl = BK.statusLabels[s] || s;
        var col = BK.statusColors[s] || '#6b7280';
        return '<span style="background:' + col + '18;color:' + col + ';font-weight:600;font-size:0.75rem;padding:3px 8px;border-radius:20px;white-space:nowrap;">' + lbl + '</span>';
    }

    // ── Carga principal ────────────────────────────────────────────────────
    function loadBookings() {
        $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;"><?php esc_html_e("Cargando…","ltms"); ?></td></tr>');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:      'ltms_get_vendor_bookings',
                nonce:       ltmsDashboard.nonce,
                status:      $('#ltms-bk-status-filter').val(),
                date_from:   $('#ltms-bk-date-from').val(),
                date_to:     $('#ltms-bk-date-to').val(),
                page:        BK.page,
                per_page:    BK.perPage,
            },
            success: function(res) {
                if (!res.success) {
                    $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;">' + (res.data || '<?php esc_html_e("Error al cargar reservas.","ltms"); ?>') + '</td></tr>');
                    return;
                }
                var d = res.data;
                BK.total = d.total || 0;
                renderTable(d.bookings || []);
                renderStats(d.stats || {});
                renderPagination();
            },
            error: function() {
                $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;"><?php esc_html_e("Error de conexión.","ltms"); ?></td></tr>');
            }
        });
    }

    function renderTable(bookings) {
        if (!bookings.length) {
            $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">🏨 <?php esc_html_e("Sin reservas para los filtros seleccionados.","ltms"); ?></td></tr>');
            return;
        }
        var rows = '';
        $.each(bookings, function(i, b) {
            var canCancel = (b.status === 'pending' || b.status === 'confirmed');
            rows += '<tr>'
                + '<td><a href="#" class="ltms-bk-detail-link" data-id="' + b.id + '" style="font-weight:600;color:#1a5276;">#' + b.id + '</a></td>'
                + '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (b.product_name || '—') + '</td>'
                + '<td>' + (b.customer_name || '—') + '</td>'
                + '<td>' + fmtDate(b.checkin_date) + '</td>'
                + '<td>' + fmtDate(b.checkout_date) + '</td>'
                + '<td style="text-align:center;">' + (b.guests || 1) + '</td>'
                + '<td style="white-space:nowrap;">' + fmtCOP(b.total_price) + '</td>'
                + '<td>' + statusBadge(b.status) + '</td>'
                + '<td style="white-space:nowrap;">'
                +   '<button class="ltms-btn ltms-btn-outline ltms-btn-xs ltms-bk-detail-link" data-id="' + b.id + '" style="margin-right:4px;">Ver</button>'
                +   (canCancel ? '<button class="ltms-btn ltms-btn-xs ltms-bk-quick-cancel" data-id="' + b.id + '" style="background:#dc2626;color:#fff;border-color:#dc2626;">Cancelar</button>' : '')
                + '</td>'
                + '</tr>';
        });
        $('#ltms-bk-tbody').html(rows);
    }

    function renderStats(s) {
        $('#ltms-bk-stat-total').text(s.total || 0);
        $('#ltms-bk-stat-pending').text(s.pending || 0);
        $('#ltms-bk-stat-confirmed').text(s.confirmed || 0);
        $('#ltms-bk-stat-revenue').text(fmtCOP(s.vendor_net || 0));
    }

    function renderPagination() {
        var pages = Math.ceil(BK.total / BK.perPage) || 1;
        var from  = Math.min( (BK.page - 1) * BK.perPage + 1, BK.total );
        var to    = Math.min( BK.page * BK.perPage, BK.total );
        $('#ltms-bk-count-label').text(BK.total
            ? from + '–' + to + ' de ' + BK.total
            : '<?php esc_html_e("Sin resultados","ltms"); ?>'
        );
        $('#ltms-bk-prev').prop('disabled', BK.page <= 1);
        $('#ltms-bk-next').prop('disabled', BK.page >= pages);
    }

    // ── Detalle ────────────────────────────────────────────────────────────
    function openDetail(bookingId) {
        BK.activeBookingId = bookingId;
        $('#ltms-bk-modal-title').text('<?php esc_html_e("Reserva","ltms"); ?> #' + bookingId);
        $('#ltms-bk-modal-body').html('<div style="text-align:center;padding:20px;color:#9ca3af;"><?php esc_html_e("Cargando…","ltms"); ?></div>');
        LTMS.Modal.open('ltms-modal-booking-detail');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: { action:'ltms_get_vendor_booking_detail', nonce:ltmsDashboard.nonce, booking_id: bookingId },
            success: function(res) {
                if (!res.success) { $('#ltms-bk-modal-body').html('<p style="color:#dc2626;">' + (res.data||'Error') + '</p>'); return; }
                var b = res.data;
                var canCancel = (b.status === 'pending' || b.status === 'confirmed');
                $('#ltms-bk-cancel-btn').toggle(canCancel);

                var nights = 0;
                if (b.checkin_date && b.checkout_date && b.checkin_date !== '0000-00-00') {
                    var d1 = new Date(b.checkin_date), d2 = new Date(b.checkout_date);
                    nights = Math.max(0, Math.round((d2 - d1) / 86400000));
                }

                $('#ltms-bk-modal-body').html(
                    '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">' +
                    row('<?php esc_html_e("Producto","ltms"); ?>', '<strong>' + (b.product_name||'—') + '</strong>') +
                    row('<?php esc_html_e("Estado","ltms"); ?>', statusBadge(b.status)) +
                    row('<?php esc_html_e("Huésped","ltms"); ?>', (b.customer_name||'—') + (b.customer_email ? ' &lt;' + b.customer_email + '&gt;' : '')) +
                    row('<?php esc_html_e("Check-in","ltms"); ?>', fmtDate(b.checkin_date) + (b.checkin_time ? ' · ' + b.checkin_time : '')) +
                    row('<?php esc_html_e("Check-out","ltms"); ?>', fmtDate(b.checkout_date) + (b.checkout_time ? ' · ' + b.checkout_time : '')) +
                    row('<?php esc_html_e("Noches","ltms"); ?>', nights || '—') +
                    row('<?php esc_html_e("Huéspedes","ltms"); ?>', b.guests || 1) +
                    row('<?php esc_html_e("Total","ltms"); ?>', fmtCOP(b.total_price)) +
                    row('<?php esc_html_e("Neto vendedor","ltms"); ?>', fmtCOP(b.vendor_net)) +
                    row('<?php esc_html_e("Modo de pago","ltms"); ?>', b.payment_mode || '—') +
                    row('<?php esc_html_e("Orden WC","ltms"); ?>', b.wc_order_id ? '#' + b.wc_order_id : '—') +
                    (b.notes ? row('<?php esc_html_e("Notas","ltms"); ?>', b.notes) : '') +
                    (b.cancel_notes ? row('<?php esc_html_e("Motivo cancel.","ltms"); ?>', '<span style="color:#dc2626;">' + b.cancel_notes + '</span>') : '') +
                    '</table>'
                );
            }
        });
    }

    function row(label, val) {
        return '<tr style="border-bottom:1px solid #f3f4f6;">' +
            '<td style="padding:8px 4px;color:#6b7280;font-weight:600;white-space:nowrap;width:40%;">' + label + '</td>' +
            '<td style="padding:8px 4px;">' + val + '</td>' +
            '</tr>';
    }

    // ── Cancelación ────────────────────────────────────────────────────────
    function openCancel(bookingId) {
        BK.activeBookingId = bookingId;
        $('#ltms-bk-cancel-reason').val('');
        $('#ltms-bk-cancel-notice').text('').css('color','');
        LTMS.Modal.open('ltms-modal-booking-cancel');
    }

    function doCancel() {
        var reason = $.trim($('#ltms-bk-cancel-reason').val());
        if (!reason) {
            $('#ltms-bk-cancel-notice').css('color','#dc2626').text('<?php esc_html_e("Por favor indica el motivo de la cancelación.","ltms"); ?>');
            return;
        }
        $('#ltms-bk-confirm-cancel-btn').prop('disabled', true).text('<?php esc_html_e("Procesando…","ltms"); ?>');
        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:     'ltms_vendor_cancel_booking',
                nonce:      ltmsDashboard.nonce,
                booking_id: BK.activeBookingId,
                reason:     reason,
            },
            success: function(res) {
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('<?php esc_html_e("Confirmar Cancelación","ltms"); ?>');
                if (res.success) {
                    LTMS.Modal.close('ltms-modal-booking-cancel');
                    LTMS.Modal.close('ltms-modal-booking-detail');
                    BK.page = 1;
                    loadBookings();
                } else {
                    $('#ltms-bk-cancel-notice').css('color','#dc2626').text(res.data || '<?php esc_html_e("Error al cancelar.","ltms"); ?>');
                }
            },
            error: function() {
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('<?php esc_html_e("Confirmar Cancelación","ltms"); ?>');
                $('#ltms-bk-cancel-notice').css('color','#dc2626').text('<?php esc_html_e("Error de conexión.","ltms"); ?>');
            }
        });
    }

    // ── Eventos ────────────────────────────────────────────────────────────
    $(document).on('click', '#ltms-view-bookings .ltms-bk-detail-link', function(e){
        e.preventDefault();
        openDetail($(this).data('id'));
    });

    $(document).on('click', '#ltms-view-bookings .ltms-bk-quick-cancel', function(e){
        e.preventDefault();
        openCancel($(this).data('id'));
    });

    $('#ltms-bk-cancel-btn').on('click', function(){
        LTMS.Modal.close('ltms-modal-booking-detail');
        openCancel(BK.activeBookingId);
    });

    $('#ltms-bk-confirm-cancel-btn').on('click', doCancel);

    // Paginación
    $('#ltms-bk-prev').on('click', function(){ if (BK.page > 1) { BK.page--; loadBookings(); } });
    $('#ltms-bk-next').on('click', function(){
        var pages = Math.ceil(BK.total / BK.perPage);
        if (BK.page < pages) { BK.page++; loadBookings(); }
    });

    // Filtros
    var filterTimer;
    $('#ltms-bk-status-filter, #ltms-bk-date-from, #ltms-bk-date-to').on('change', function(){
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function(){ BK.page = 1; loadBookings(); }, 300);
    });

    // ── Inicialización al entrar en la pestaña ─────────────────────────────
    $(document).on('click', '[data-view="bookings"]', function(){
        if (BK.total === 0 && BK.page === 1) loadBookings();
    });

    // Si la pestaña se abre directamente vía hash
    if ( window.location.hash === '#bookings' ) {
        setTimeout(loadBookings, 400);
    }

})(jQuery);
</script>
