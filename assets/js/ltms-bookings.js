/**
 * LTMS view-bookings — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
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
            pending:    '⏳ Pendiente',
            confirmed:  '✅ Confirmada',
            checked_in: '🏠 En curso',
            completed:  '🎉 Completada',
            cancelled:  '❌ Cancelada',
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
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var $div = $('<div/>');
        $div.text(String(text));
        return $div.html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function statusBadge(s) {
        var lbl = BK.statusLabels[s] || s;
        var col = BK.statusColors[s] || '#6b7280';
        return '<span style="background:' + col + '18;color:' + col + ';font-weight:600;font-size:0.75rem;padding:3px 8px;border-radius:20px;white-space:nowrap;">' + lbl + '</span>';
    }

    // ── Carga principal ────────────────────────────────────────────────────
    function loadBookings() {
        $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;"></td></tr>');

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
                    $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;">' + escapeHtml(res.data || '') + '</td></tr>');
                    return;
                }
                var d = res.data;
                BK.total = d.total || 0;
                renderTable(d.bookings || []);
                renderStats(d.stats || {});
                renderPagination();
            },
            error: function() {
                $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:20px;color:#dc2626;"></td></tr>');
            }
        });
    }

    function renderTable(bookings) {
        if (!bookings.length) {
            $('#ltms-bk-tbody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">🏨 </td></tr>');
            return;
        }
        var rows = '';
        $.each(bookings, function(i, b) {
            var canCancel = (b.status === 'pending' || b.status === 'confirmed');
            var bId = escapeHtml(b.id);
            rows += '<tr>'
                + '<td><a href="#" class="ltms-bk-detail-link" data-id="' + bId + '" style="font-weight:600;color:#1a5276;">#' + bId + '</a></td>'
                + '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(b.product_name || '—') + '</td>'
                + '<td>' + escapeHtml(b.customer_name || '—') + '</td>'
                + '<td>' + escapeHtml(fmtDate(b.checkin_date)) + '</td>'
                + '<td>' + escapeHtml(fmtDate(b.checkout_date)) + '</td>'
                + '<td style="text-align:center;">' + escapeHtml(b.guests || 1) + '</td>'
                + '<td style="white-space:nowrap;">' + escapeHtml(fmtCOP(b.total_price)) + '</td>'
                + '<td>' + statusBadge(b.status) + '</td>'
                + '<td style="white-space:nowrap;">'
                +   '<button class="ltms-btn ltms-btn-outline ltms-btn-xs ltms-bk-detail-link" data-id="' + bId + '" style="margin-right:4px;">Ver</button>'
                +   (canCancel ? '<button class="ltms-btn ltms-btn-xs ltms-bk-quick-cancel" data-id="' + bId + '" style="background:#dc2626;color:#fff;border-color:#dc2626;">Cancelar</button>' : '')
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
            : ''
        );
        $('#ltms-bk-prev').prop('disabled', BK.page <= 1);
        $('#ltms-bk-next').prop('disabled', BK.page >= pages);
    }

    // ── Detalle ────────────────────────────────────────────────────────────
    function openDetail(bookingId) {
        BK.activeBookingId = bookingId;
        $('#ltms-bk-modal-title').text(' #' + bookingId);
        $('#ltms-bk-modal-body').html('<div style="text-align:center;padding:20px;color:#9ca3af;"></div>');
        LTMS.Modal.open('ltms-modal-booking-detail');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: { action:'ltms_get_vendor_booking_detail', nonce:ltmsDashboard.nonce, booking_id: bookingId },
            success: function(res) {
                if (!res.success) { $('#ltms-bk-modal-body').html('<p style="color:#dc2626;">' + escapeHtml(res.data||'Error') + '</p>'); return; }
                var b = res.data;
                var canCancel = (b.status === 'pending' || b.status === 'confirmed');
                $('#ltms-bk-cancel-btn').toggle(canCancel);
                $('#ltms-bk-cancel-unavailable-note').toggle(!canCancel);

                var nights = 0;
                if (b.checkin_date && b.checkout_date && b.checkin_date !== '0000-00-00') {
                    var d1 = new Date(b.checkin_date), d2 = new Date(b.checkout_date);
                    nights = Math.max(0, Math.round((d2 - d1) / 86400000));
                }

                $('#ltms-bk-modal-body').html(
                    '<table style="width:100%;border-collapse:collapse;font-size:0.875rem;">' +
                    row('', '<strong>' + escapeHtml(b.product_name||'—') + '</strong>') +
                    row('', statusBadge(b.status)) +
                    row('', escapeHtml(b.customer_name||'—') + (b.customer_email ? ' &lt;' + escapeHtml(b.customer_email) + '&gt;' : '')) +
                    row('', escapeHtml(fmtDate(b.checkin_date) + (b.checkin_time ? ' · ' + b.checkin_time : ''))) +
                    row('', escapeHtml(fmtDate(b.checkout_date) + (b.checkout_time ? ' · ' + b.checkout_time : ''))) +
                    row('', nights || '—') +
                    row('', escapeHtml(b.guests || 1)) +
                    row('', escapeHtml(fmtCOP(b.total_price))) +
                    row('', escapeHtml(fmtCOP(b.vendor_net))) +
                    row('', escapeHtml(b.payment_mode || '—')) +
                    row('', b.wc_order_id ? '#' + escapeHtml(b.wc_order_id) : '—') +
                    (b.notes ? row('', escapeHtml(b.notes)) : '') +
                    (b.cancel_notes ? row('', '<span style="color:#dc2626;">' + escapeHtml(b.cancel_notes) + '</span>') : '') +
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
            $('#ltms-bk-cancel-notice').css('color','#dc2626').text('');
            return;
        }
        $('#ltms-bk-confirm-cancel-btn').prop('disabled', true).text('');
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
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('');
                if (res.success) {
                    LTMS.Modal.close('ltms-modal-booking-cancel');
                    LTMS.Modal.close('ltms-modal-booking-detail');
                    BK.page = 1;
                    loadBookings();
                } else {
                    $('#ltms-bk-cancel-notice').css('color','#dc2626').text(res.data || '');
                }
            },
            error: function() {
                $('#ltms-bk-confirm-cancel-btn').prop('disabled', false).text('');
                $('#ltms-bk-cancel-notice').css('color','#dc2626').text('');
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

    // M-BOOKING-UI-02: exportar CSV respetando los filtros activos.
    $('#ltms-bk-export-csv').on('click', function(e){
        e.preventDefault();
        var params = new URLSearchParams();
        params.append('action', 'ltms_export_vendor_bookings_csv');
        params.append('nonce', ltmsDashboard.export_nonce || ltmsDashboard.nonce);
        var status = $('#ltms-bk-status-filter').val();
        var from   = $('#ltms-bk-date-from').val();
        var to     = $('#ltms-bk-date-to').val();
        if (status) params.append('status', status);
        if (from)   params.append('date_from', from);
        if (to)     params.append('date_to', to);
        window.location.href = ltmsDashboard.ajax_url + '?' + params.toString();
    });

    // ── Inicialización al entrar en la pestaña ─────────────────────────────
    $(document).on('click', '[data-view="bookings"]', function(){
        if (BK.total === 0 && BK.page === 1) loadBookings();
    });

    // Si la pestaña se abre directamente vía hash
    if ( window.location.hash === '#bookings' ) {
        setTimeout(loadBookings, 400);
    }

    // M-FIX-BOOKINGS-03: en la página standalone /mis-reservas/ no existe ningún
    // elemento [data-view="bookings"] en el que el vendedor pueda hacer clic —
    // esta vista ES la página completa, no una pestaña del SPA — así que
    // loadBookings() nunca se disparaba y la tabla quedaba pegada en
    // "Cargando reservas..." indefinidamente. Si no hay nav del SPA en el DOM,
    // cargamos de una vez. Dentro de /panel-vendedor/ esta condición es falsa
    // (el nav sí existe) y se preserva la carga perezosa al hacer clic.
    if ( $('[data-view="bookings"]').length === 0 ) {
        loadBookings();
    }

})(jQuery);
/* global jQuery, ltmsDashboard */
(function($) {
    'use strict';

    var productsLoaded = false;

    $(document).on('click', '.ltms-booking-tab', function() {
        var target = $(this).data('target');
        $('.ltms-booking-tab').css({ color: '#6b7280', 'border-bottom-color': 'transparent' }).removeClass('ltms-booking-tab-active');
        $(this).css({ color: '#1a5276', 'border-bottom-color': '#1a5276' }).addClass('ltms-booking-tab-active');
        $('#ltms-bk-reservas, #ltms-bk-seasons, #ltms-bk-policies, #ltms-bk-calendar').hide();
        $('#' + target).show();
        if (target === 'ltms-bk-seasons') {
            if (!productsLoaded) ltmsLoadVendorProducts();
            if (!$('#ltms-seasons-tbody').data('loaded')) ltmsLoadSeasons();
        }
        if (target === 'ltms-bk-policies' && !$('#ltms-policies-list').data('loaded')) ltmsLoadPolicies();
        // v2.9.93 P2: Load calendar
        if (target === 'ltms-bk-calendar') ltmsLoadCalendar();
    });

    // v2.9.93 P2: Calendar logic
    var calDate = new Date();
    var calBookings = [];

    function ltmsLoadCalendar() {
        calDate.setDate(1);
        renderCalendar();
        // Fetch bookings for this month
        var monthStr = calDate.getFullYear() + '-' + String(calDate.getMonth() + 1).padStart(2, '0');
        $.ajax({
            url: ltmsDashboard.ajax_url, method: 'POST',
            data: { action: 'ltms_get_vendor_bookings', nonce: ltmsDashboard.nonce, per_page: 50 },
            success: function(res) {
                if (res.success && res.data && res.data.bookings) {
                    calBookings = res.data.bookings;
                    renderCalendar();
                }
            }
        });
    }

    function renderCalendar() {
        var year = calDate.getFullYear();
        var month = calDate.getMonth();
        var monthNames = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $('#ltms-cal-month-label').text(monthNames[month] + ' ' + year);

        var firstDay = new Date(year, month, 1).getDay();
        // Convert Sunday(0) to Monday-based (0=Mon, 6=Sun)
        firstDay = firstDay === 0 ? 6 : firstDay - 1;
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

        var statusColors = {
            'pending': '#dbeafe', 'confirmed': '#d1fae5',
            'checked_in': '#fef3c7', 'completed': '#e5e7eb', 'cancelled': '#fee2e2'
        };

        var html = '';
        var day = 1;
        for (var w = 0; w < 6; w++) {
            if (day > daysInMonth) break;
            html += '<tr>';
            for (var d = 0; d < 7; d++) {
                if (w === 0 && d < firstDay) {
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;min-height:60px;background:#f9fafb;">&nbsp;</td>';
                } else if (day <= daysInMonth) {
                    var dateStr = year + '-' + String(month+1).padStart(2,'0') + '-' + String(day).padStart(2,'0');
                    var dayBookings = calBookings.filter(function(b) {
                        return (b.checkin_date || '').startsWith(dateStr) || (b.checkout_date || '').startsWith(dateStr);
                    });
                    var bg = dateStr === todayStr ? '#eff6ff' : '#fff';
                    var bookingHtml = dayBookings.map(function(b) {
                        var color = statusColors[b.status] || '#f3f4f6';
                        return '<div style="background:' + color + ';border-radius:4px;padding:2px 4px;margin:2px 0;font-size:0.65rem;cursor:pointer;" data-cal-booking="1">' +
                            escapeHtml(b.customer_name || 'Reserva') + '</div>';
                    }).join('');
                    var isWeekend = d >= 5;
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;vertical-align:top;min-height:60px;background:' + bg + ';' + (isWeekend ? 'background:#fafafa;' : '') + '">' +
                        '<div style="font-weight:600;font-size:0.75rem;' + (dateStr === todayStr ? 'color:#2563eb;' : 'color:#374151;') + '">' + day + '</div>' +
                        bookingHtml +
                    '</td>';
                    day++;
                } else {
                    html += '<td style="padding:4px;border:1px solid #e5e7eb;background:#f9fafb;">&nbsp;</td>';
                }
            }
            html += '</tr>';
        }
        $('#ltms-cal-tbody').html(html);
    }

    $('#ltms-cal-prev').on('click', function() {
        calDate.setMonth(calDate.getMonth() - 1);
        ltmsLoadCalendar();
    });
    $('#ltms-cal-next').on('click', function() {
        calDate.setMonth(calDate.getMonth() + 1);
        ltmsLoadCalendar();
    });
    $('#ltms-cal-today').on('click', function() {
        calDate = new Date();
        ltmsLoadCalendar();
    });
    // v2.9.93: Click en reserva del calendario → ir a tab reservas
    $(document).on('click', '[data-cal-booking]', function() {
        $('[data-target="ltms-bk-reservas"]').click();
    });

    function ltmsSeasonNotice(msg, type) {
        var ok = type === 'success';
        $('#ltms-season-notice').css({ background: ok ? '#f0fdf4' : '#fef2f2', color: ok ? '#166534' : '#991b1b',
            border: '1px solid ' + (ok ? '#86efac' : '#fca5a5'), 'border-radius': '6px' }).text(msg).show();
    }
    function ltmsPolicyNotice(msg, type) {
        var ok = type === 'success';
        $('#ltms-policy-notice').css({ background: ok ? '#f0fdf4' : '#fef2f2', color: ok ? '#166534' : '#991b1b',
            border: '1px solid ' + (ok ? '#86efac' : '#fca5a5'), 'border-radius': '6px' }).text(msg).show();
    }

    function ltmsLoadVendorProducts() {
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_products_data', nonce: ltmsDashboard.nonce }, function(res) {
            productsLoaded = true;
            var $sel = $('#ltms-season-product').empty();
            $sel.append('<option value="0">— Todos mis alojamientos —</option>');
            if (res.success && res.data.products.length) {
                res.data.products.forEach(function(p) {
                    $sel.append('<option value="' + p.id + '">' + $('<span/>').text(p.name).html() + '</option>');
                });
            }
        });
    }

    function ltmsLoadSeasons() {
        $('#ltms-seasons-tbody').html('<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Cargando...</td></tr>');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_vendor_seasons', nonce: ltmsDashboard.nonce }, function(res) {
            $('#ltms-seasons-tbody').data('loaded', true);
            if (!res.success || !res.data.length) {
                $('#ltms-seasons-tbody').html('<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">Sin temporadas. Crea una para aplicar precios especiales a un alojamiento.</td></tr>');
                return;
            }
            var html = res.data.map(function(s) {
                var mod = parseFloat(s.price_modifier || 1).toFixed(2);
                var pct = Math.round((mod - 1) * 100);
                var modHtml = pct > 0 ? '<span style="color:#16a34a;">+' + pct + '% (' + mod + 'x)</span>'
                    : (pct < 0 ? '<span style="color:#dc2626;">' + pct + '% (' + mod + 'x)</span>'
                                : '<span style="color:#6b7280;">Sin cambio</span>');
                return '<tr style="border-top:1px solid #f3f4f6;"><td style="padding:10px 12px;">' + $('<span/>').text(s.season_name).html() + '</td>' +
                    '<td style="padding:10px 12px;font-size:.82rem;color:#6b7280;">' + (s.product_name ? $('<span/>').text(s.product_name).html() : '—') + '</td>' +
                    '<td style="padding:10px 12px;font-size:.85rem;">' + s.date_from + '</td>' +
                    '<td style="padding:10px 12px;font-size:.85rem;">' + s.date_to + '</td>' +
                    '<td style="padding:10px 12px;">' + modHtml + '</td>' +
                    '<td style="padding:10px 12px;"><button class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-season-edit" data-id="' + s.id + '" aria-label=""' +
                    ' data-name="' + encodeURIComponent(s.season_name) + '" data-from="' + s.date_from + '" data-to="' + s.date_to + '"' +
                    ' data-mod="' + s.price_modifier + '" data-pid="' + (s.product_id || 0) + '">✏️</button> ' +
                    '<button class="ltms-btn ltms-btn-sm ltms-season-del" data-id="' + s.id + '" aria-label="" style="background:#fee2e2;color:#991b1b;">🗑️</button></td></tr>';
            }).join('');
            $('#ltms-seasons-tbody').html(html);
        });
    }

    $(document).on('click', '#ltms-season-add-btn', function() {
        $('#ltms-season-id').val('0');
        $('#ltms-season-name,#ltms-season-from,#ltms-season-to').val('');
        $('#ltms-season-modifier').val('1.50'); $('#ltms-season-product').val('0');
        $('#ltms-season-form-title').text('Nueva temporada'); $('#ltms-season-notice').hide();
        $('#ltms-season-form').show(); $('#ltms-season-name').focus();
    });
    $(document).on('click', '.ltms-season-edit', function() {
        var $b = $(this);
        $('#ltms-season-id').val($b.data('id')); $('#ltms-season-name').val(decodeURIComponent($b.data('name')));
        $('#ltms-season-from').val($b.data('from')); $('#ltms-season-to').val($b.data('to'));
        $('#ltms-season-modifier').val($b.data('mod')); $('#ltms-season-product').val($b.data('pid') || '0');
        $('#ltms-season-form-title').text('Editar temporada'); $('#ltms-season-notice').hide();
        $('#ltms-season-form').show(); $('#ltms-season-name').focus();
    });
    $(document).on('click', '#ltms-season-save-btn', function() {
        var name = $('#ltms-season-name').val().trim(), from = $('#ltms-season-from').val(), to = $('#ltms-season-to').val();
        var pid = $('#ltms-season-product').val();
        if (!name || !from || !to) { ltmsSeasonNotice('El nombre y las fechas son obligatorios.', 'error'); return; }
        if (pid === '' || pid === null) { ltmsSeasonNotice('Espera a que carguen tus alojamientos e intenta de nuevo.', 'error'); return; }
        $(this).prop('disabled', true).text('Guardando...');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_save_vendor_season', nonce: ltmsDashboard.nonce,
            rule_id: $('#ltms-season-id').val(), season_name: name, date_from: from, date_to: to,
            price_modifier: $('#ltms-season-modifier').val(), product_id: pid },
        function(res) {
            $('#ltms-season-save-btn').prop('disabled', false).text('Guardar');
            if (res.success) { ltmsSeasonNotice('✅ ' + res.data.message, 'success'); $('#ltms-seasons-tbody').removeData('loaded');
                setTimeout(function() { $('#ltms-season-form').hide(); ltmsLoadSeasons(); }, 1200); }
            else { ltmsSeasonNotice('✗ ' + (res.data || 'Error'), 'error'); }
        });
    });
    $(document).on('click', '#ltms-season-cancel-btn', function() { $('#ltms-season-form').hide(); });
    $(document).on('click', '.ltms-season-del', function() {
        ltmsConfirmDelete('season', $(this).data('id'), '');
    });

    function ltmsLoadPolicies() {
        $('#ltms-policies-list').html('<div style="text-align:center;padding:30px;color:#9ca3af;">Cargando...</div>');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_get_vendor_policies', nonce: ltmsDashboard.nonce }, function(res) {
            $('#ltms-policies-list').data('loaded', true);
            if (!res.success || !res.data.length) {
                $('#ltms-policies-list').html('<div style="text-align:center;padding:30px;color:#9ca3af;">Sin políticas. Crea una para asignarla a tus alojamientos.</div>');
                return;
            }
            var tl = { flexible: 'Flexible ✅', moderate: 'Moderada ⚖️', strict: 'Estricta 🔒', non_refundable: 'Sin reembolso ❌' };
            var tc = { flexible: '#16a34a', moderate: '#f59e0b', strict: '#ef4444', non_refundable: '#6b7280' };
            var html = res.data.map(function(p) {
                return '<div class="ltms-card" style="padding:16px;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">' +
                    '<div><div style="font-weight:700;font-size:.95rem;">' + $('<span/>').text(p.name).html() +
                    (p.is_default == 1 ? ' <span style="font-size:.72rem;background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:99px;margin-left:6px;">Por defecto</span>' : '') + '</div>' +
                    '<div style="font-size:.8rem;color:' + (tc[p.policy_type]||'#6b7280') + ';margin-top:4px;">' + (tl[p.policy_type]||p.policy_type) + '</div>' +
                    '<div style="font-size:.8rem;color:#6b7280;margin-top:6px;">Gratis hasta ' + p.free_cancel_hours + 'h · ' + p.partial_refund_pct + '% dentro de ' + p.partial_refund_hours + 'h</div></div>' +
                    '<div style="display:flex;gap:6px;flex-shrink:0;">' +
                    '<button class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-policy-edit" data-id="' + p.id + '" data-name="' + encodeURIComponent(p.name) + '"' +
                    ' data-type="' + p.policy_type + '" data-free="' + p.free_cancel_hours + '" data-pct="' + p.partial_refund_pct + '"' +
                    ' data-phours="' + p.partial_refund_hours + '" data-default="' + p.is_default + '">✏️ Editar</button>' +
                    '<button class="ltms-btn ltms-btn-sm ltms-policy-del" data-id="' + p.id + '" aria-label="" style="background:#fee2e2;color:#991b1b;">🗑️</button>' +
                    '</div></div></div>';
            }).join('');
            $('#ltms-policies-list').html(html);
        });
    }

    $(document).on('click', '#ltms-policy-add-btn', function() {
        $('#ltms-policy-id').val('0'); $('#ltms-policy-name').val(''); $('#ltms-policy-type').val('flexible');
        $('#ltms-policy-free-hours').val('24'); $('#ltms-policy-partial-pct').val('50');
        $('#ltms-policy-partial-hours').val('48'); $('#ltms-policy-default').prop('checked', false);
        $('#ltms-policy-form-title').text('Nueva política de cancelación'); $('#ltms-policy-notice').hide();
        $('#ltms-policy-form').show(); $('#ltms-policy-name').focus();
    });
    $(document).on('click', '.ltms-policy-edit', function() {
        var $b = $(this);
        $('#ltms-policy-id').val($b.data('id')); $('#ltms-policy-name').val(decodeURIComponent($b.data('name')));
        $('#ltms-policy-type').val($b.data('type')); $('#ltms-policy-free-hours').val($b.data('free'));
        $('#ltms-policy-partial-pct').val($b.data('pct')); $('#ltms-policy-partial-hours').val($b.data('phours'));
        $('#ltms-policy-default').prop('checked', $b.data('default') == 1);
        $('#ltms-policy-form-title').text('Editar política'); $('#ltms-policy-notice').hide();
        $('#ltms-policy-form').show(); $('#ltms-policy-name').focus();
    });
    $(document).on('click', '#ltms-policy-save-btn', function() {
        var name = $('#ltms-policy-name').val().trim();
        if (!name) { ltmsPolicyNotice('El nombre es obligatorio.', 'error'); return; }
        $(this).prop('disabled', true).text('Guardando...');
        $.post(ltmsDashboard.ajax_url, { action: 'ltms_save_vendor_policy', nonce: ltmsDashboard.nonce,
            policy_id: $('#ltms-policy-id').val(), policy_name: name, policy_type: $('#ltms-policy-type').val(),
            free_cancel_hours: $('#ltms-policy-free-hours').val(), partial_refund_pct: $('#ltms-policy-partial-pct').val(),
            partial_refund_hours: $('#ltms-policy-partial-hours').val(), is_default: $('#ltms-policy-default').is(':checked') ? 1 : 0 },
        function(res) {
            $('#ltms-policy-save-btn').prop('disabled', false).text('Guardar');
            if (res.success) { ltmsPolicyNotice('✅ ' + res.data.message, 'success'); $('#ltms-policies-list').removeData('loaded');
                setTimeout(function() { $('#ltms-policy-form').hide(); ltmsLoadPolicies(); }, 1200); }
            else { ltmsPolicyNotice('✗ ' + (res.data || 'Error'), 'error'); }
        });
    });
    $(document).on('click', '#ltms-policy-cancel-btn', function() { $('#ltms-policy-form').hide(); });
    $(document).on('click', '.ltms-policy-del', function() {
        ltmsConfirmDelete('policy', $(this).data('id'), '');
    });

    // ── Confirmación de eliminación (Temporada / Política), modal compartido ──
    var pendingDelete = null; // { type: 'season'|'policy', id: number }

    function ltmsConfirmDelete(type, id, message) {
        pendingDelete = { type: type, id: id };
        var title = type === 'season'
            ? ''
            : '';
        $('#ltms-bk-confirm-delete-title').text(title);
        $('#ltms-bk-confirm-delete-body').text(message);
        $('#ltms-bk-confirm-delete-btn').prop('disabled', false).text('');
        LTMS.Modal.open('ltms-modal-booking-confirm-delete');
    }

    $('#ltms-bk-confirm-delete-btn').on('click', function() {
        if (!pendingDelete) return;
        var $btn = $(this).prop('disabled', true).text('');
        var action = pendingDelete.type === 'season' ? 'ltms_delete_vendor_season' : 'ltms_delete_vendor_policy';
        var data = { action: action, nonce: ltmsDashboard.nonce };
        if (pendingDelete.type === 'season') { data.rule_id = pendingDelete.id; } else { data.policy_id = pendingDelete.id; }

        $.post(ltmsDashboard.ajax_url, data, function(res) {
            $btn.prop('disabled', false).text('');
            if (res.success) {
                LTMS.Modal.close('ltms-modal-booking-confirm-delete');
                if (pendingDelete.type === 'season') {
                    $('#ltms-seasons-tbody').removeData('loaded'); ltmsLoadSeasons();
                } else {
                    $('#ltms-policies-list').removeData('loaded'); ltmsLoadPolicies();
                }
            } else {
                var errMsg = res.data || '';
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                    LTMS.UX.toastError('Error', errMsg);
                }
            }
            pendingDelete = null;
        }).fail(function() {
            $btn.prop('disabled', false).text('');
            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                LTMS.UX.toastError('Error', '');
            }
        });
    });

})(jQuery);
