/**
 * LTMS view-ordenes-compra — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
(function($) {
    'use strict';

    const nonce   = ''; // v2.9.99 P0-3 fix: era ltms_vendor_nonce, mismatch con handler
    const ajaxUrl = '';
    let lineaCount = 0;

    // ── Helpers ───────────────────────────────────────────────────────────────
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var $div = $('<div/>');
        $div.text(String(text));
        return $div.html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function openModal( id ) {
        if ( typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.open === 'function' ) {
            LTMS.Modal.open( id );
        } else {
            $( '#' + id ).css( 'display', 'flex' ).attr( 'aria-hidden', 'false' );
        }
    }
    function closeModal( id ) {
        if ( typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.close === 'function' ) {
            LTMS.Modal.close( id );
        } else {
            $( '#' + id ).css( 'display', 'none' ).attr( 'aria-hidden', 'true' );
        }
    }

    function showMsg(el, html, type) {
        const c = {
            success: { bg:'#f0fdf4', border:'#bbf7d0', color:'#166534' },
            error:   { bg:'#fef2f2', border:'#fecaca', color:'#991b1b' },
            info:    { bg:'#eff6ff', border:'#bfdbfe', color:'#1d4ed8' },
        }[type] || { bg:'#eff6ff', border:'#bfdbfe', color:'#1d4ed8' };
        $(el).html(`<div style="padding:12px 16px;background:${c.bg};border:1px solid ${c.border};border-radius:8px;color:${c.color};font-size:0.85rem;">${html}</div>`).show();
    }

    function formatDate(dt) {
        return dt ? dt.replace('T',' ').substring(0,16) : '—';
    }

    function estadoBadge(estado) {
        const map = {
            GENERADA: { bg:'#d1fae5', color:'#065f46' },
            ERROR:    { bg:'#fee2e2', color:'#991b1b' },
        };
        const s = map[estado] || { bg:'#f3f4f6', color:'#374151' };
        return `<span style="display:inline-block;padding:2px 8px;background:${s.bg};color:${s.color};border-radius:4px;font-size:0.72rem;font-weight:700;">${estado}</span>`;
    }

    // ── Tabs ──────────────────────────────────────────────────────────────────
    $('.ltms-oc-tab').on('click', function() {
        const tab = $(this).data('tab');
        $('.ltms-oc-tab').each(function() {
            const active = $(this).data('tab') === tab;
            $(this).css({
                'border-bottom-color': active ? '#059669' : 'transparent',
                'color': active ? '#059669' : '#6b7280',
            });
        });
        $('#ltms-oc-tab-nueva, #ltms-oc-tab-historial').hide();
        $(`#ltms-oc-tab-${tab}`).show();
        if (tab === 'historial') loadHistorial();
    });

    // ── Cargar proveedores ────────────────────────────────────────────────────
    function loadProveedores() {
        $.post(ajaxUrl, { action: 'ltms_aveonline_oc_proveedores', nonce }, function(res) {
            const $sel = $('#ltms-oc-proveedor');
            if (!res.success || !res.data.proveedores.length) {
                $sel.html('<option value="">— Sin proveedores —</option>');
                return;
            }
            let opts = '<option value="">— Selecciona proveedor —</option>';
            res.data.proveedores.forEach(p => {
                opts += `<option value="${escapeHtml(p.idproveedor)}">${escapeHtml(p.nombreproveedor)} (${escapeHtml(p.ciudadproveedor)})</option>`;
            });
            $sel.html(opts);
        }).fail(function() {
            $('#ltms-oc-proveedor').html('<option value="">— Error al cargar —</option>');
        });
    }

    // ── Agregar línea ─────────────────────────────────────────────────────────
    function addLinea() {
        lineaCount++;
        const idx = lineaCount;
        const row = `<tr id="ltms-oc-linea-${idx}" style="border-bottom:1px solid #f3f4f6;">
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="pedido" placeholder="Pedido"
                       style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;box-sizing:border-box;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="plu" placeholder="PLU"
                       style="width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="ean" placeholder="EAN" style="margin-top:3px;width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="nombre_articulo" placeholder="Nombre del artículo"
                       style="width:150px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;text-align:center;">
                <input type="number" class="ltms-oc-f" data-f="cantidad_solicitada" value="1" min="1"
                       style="width:55px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;text-align:center;" />
            </td>
            <td style="padding:8px 10px;text-align:right;">
                <input type="number" class="ltms-oc-f" data-f="precio" placeholder="0"
                       style="width:80px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;text-align:right;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="cliente" placeholder="Nombre cliente"
                       style="width:130px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="tel" placeholder="Teléfono" style="margin-top:3px;width:130px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;">
                <input type="text" class="ltms-oc-f" data-f="ciudad" placeholder="Ciudad"
                       style="width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
                <input type="text" class="ltms-oc-f" data-f="direccion" placeholder="Dirección" style="margin-top:3px;width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.78rem;" />
            </td>
            <td style="padding:8px 10px;text-align:center;">
                <button type="button" class="ltms-oc-remove-linea" data-idx="${idx}"
                        style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:4px;padding:4px 8px;font-size:0.75rem;cursor:pointer;">✕</button>
            </td>
        </tr>`;
        $('#ltms-oc-lineas-tbody').append(row);
        $('#ltms-oc-empty-msg').hide();
    }

    $('#ltms-oc-add-linea').on('click', addLinea);

    $(document).on('click', '.ltms-oc-remove-linea', function() {
        const idx = $(this).data('idx');
        $(`#ltms-oc-linea-${idx}`).remove();
        if ($('#ltms-oc-lineas-tbody tr').length === 0) {
            $('#ltms-oc-empty-msg').show();
        }
    });

    // ── Leer líneas del DOM ───────────────────────────────────────────────────
    function getLineas() {
        const lineas = [];
        $('#ltms-oc-lineas-tbody tr').each(function() {
            const linea = {};
            $(this).find('.ltms-oc-f').each(function() {
                linea[$(this).data('f')] = $(this).val().trim();
            });
            // Calcular total = precio * cantidad si está vacío
            const qty   = parseInt(linea.cantidad_solicitada, 10) || 1;
            const price = parseInt(linea.precio, 10) || 0;
            linea.total     = linea.total     || String(price * qty);
            linea.valoracion= linea.valoracion|| String(price * qty);
            linea.peso      = linea.peso      || '1';
            lineas.push(linea);
        });
        return lineas;
    }

    // ── Generar OC ────────────────────────────────────────────────────────────
    $('#ltms-oc-generar-btn').on('click', function() {
        const ordencompra  = $('#ltms-oc-numero').val().trim();
        const idproveedor  = $('#ltms-oc-proveedor').val();
        const modoenvio    = $('#ltms-oc-modoenvio').val();
        const lineas       = getLineas();
        const $res         = $('#ltms-oc-generar-result');

        if (!ordencompra) {
            showMsg($res, '❌ Ingresa el número de la orden de compra.', 'error');
            return;
        }
        if (!idproveedor) {
            showMsg($res, '❌ Selecciona un proveedor.', 'error');
            return;
        }
        if (!lineas.length) {
            showMsg($res, '❌ Agrega al menos una línea de detalle.', 'error');
            return;
        }

        $(this).prop('disabled', true).text('Generando…');
        $res.hide();

        $.post(ajaxUrl, {
            action:       'ltms_aveonline_oc_generar',
            nonce,
            ordencompra,
            idproveedor,
            modoenvio,
            detalle: JSON.stringify(lineas),
        }, function(res) {
            $('#ltms-oc-generar-btn').prop('disabled', false).text('✅ Generar Orden de Compra');
            if (!res.success) {
                showMsg($res, '❌ ' + escapeHtml(res.data?.message || 'Error desconocido.'), 'error');
                return;
            }
            showMsg($res, '✅ OC <strong style="font-family:monospace;">' + escapeHtml(res.data.ordencompra) + '</strong> generada correctamente en Aveonline.', 'success');
            // Reset form
            $('#ltms-oc-numero').val('');
            $('#ltms-oc-lineas-tbody').empty();
            $('#ltms-oc-empty-msg').show();
            lineaCount = 0;
        });
    });

    // ── Cargar historial ──────────────────────────────────────────────────────
    function loadHistorial() {
        $('#ltms-oc-historial-tbody').html('<tr><td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">Cargando…</td></tr>');
        $.post(ajaxUrl, { action: 'ltms_aveonline_oc_mis_ordenes', nonce }, function(res) {
            if (!res.success || !res.data.ordenes.length) {
                $('#ltms-oc-historial-tbody').html('<tr><td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">Sin órdenes registradas.</td></tr>');
                return;
            }
            let rows = '';
            res.data.ordenes.forEach((o, i) => {
                rows += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 16px;font-family:monospace;font-size:0.78rem;color:#4b5563;">${escapeHtml(o.ordencompra)}</td>
                    <td style="padding:10px 16px;color:#374151;">${escapeHtml(o.nombreproveedor || '—')}</td>
                    <td style="padding:10px 16px;text-align:center;color:#374151;">${escapeHtml((o.detalle||[]).length)}</td>
                    <td style="padding:10px 16px;text-align:center;">${estadoBadge(o.estado)}</td>
                    <td style="padding:10px 16px;color:#6b7280;white-space:nowrap;">${escapeHtml(formatDate(o.created_at))}</td>
                    <td style="padding:10px 16px;text-align:center;">
                        <button type="button" class="ltms-oc-ver-detalle" data-oc-idx="${escapeHtml(o.id || o.ordencompra || i)}"
                                style="padding:4px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:0.75rem;cursor:pointer;">
                            🔍 Ver
                        </button>
                    </td>
                </tr>`;
            });
            $('#ltms-oc-historial-tbody').html(rows);
            // Caché de órdenes para que el botón "Ver" pueda recuperar el
            // objeto completo sin necesidad de interpolar JSON en el DOM (lo
            // que sería un vector de XSS si la respuesta contiene comillas
            // simples o dobles).
            $('#ltms-oc-historial-tbody').data('ordenes', res.data.ordenes);
        });
    }

    $('#ltms-oc-refresh-btn').on('click', loadHistorial);

    // ── Modal detalle ─────────────────────────────────────────────────────────
    $(document).on('click', '.ltms-oc-ver-detalle', function() {
        const idx  = String( $(this).attr('data-oc-idx') ); // v2.9.99 REG-3 FIX: .attr() preserves string, .data() auto-converts numeric
        const ordenes = $('#ltms-oc-historial-tbody').data('ordenes') || [];
        let o = null;
        if (typeof idx === 'number') {
            o = ordenes[idx] || null;
        } else {
            for (let i = 0; i < ordenes.length; i++) {
                if (String(ordenes[i].id || ordenes[i].ordencompra) === String(idx)) {
                    o = ordenes[i];
                    break;
                }
            }
        }
        o = o || {};
        $('#ltms-oc-detail-title').text('OC ' + (o.ordencompra || '') + ' — ' + (o.nombreproveedor || ''));

        const detalle = o.detalle || [];
        let html = '';
        if (detalle.length) {
            html += `<div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Pedido</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Artículo</th>
                            <th style="padding:8px 12px;text-align:center;color:#6b7280;">Cant.</th>
                            <th style="padding:8px 12px;text-align:right;color:#6b7280;">Precio</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Cliente</th>
                            <th style="padding:8px 12px;text-align:left;color:#6b7280;">Ciudad</th>
                            <th style="padding:8px 12px;text-align:center;color:#6b7280;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>`;
            detalle.forEach(d => {
                html += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:8px 12px;font-family:monospace;font-size:0.75rem;">${escapeHtml(d.pedido||'—')}</td>
                    <td style="padding:8px 12px;">${escapeHtml(d.nombre_articulo||'—')}</td>
                    <td style="padding:8px 12px;text-align:center;">${escapeHtml(d.cantidad||1)}</td>
                    <td style="padding:8px 12px;text-align:right;">$${escapeHtml(Number(d.precio||0).toLocaleString('es-CO'))}</td>
                    <td style="padding:8px 12px;">${escapeHtml(d.cliente||'—')}</td>
                    <td style="padding:8px 12px;">${escapeHtml(d.ciudad||'—')}</td>
                    <td style="padding:8px 12px;text-align:center;"><span style="padding:2px 6px;background:#dbeafe;color:#1e40af;border-radius:4px;font-size:0.7rem;">${escapeHtml(d.estado_linea||'PENDIENTE')}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        } else {
            html = '<p style="color:#9ca3af;text-align:center;padding:24px;">Sin líneas registradas.</p>';
        }

        if (o.mensaje) {
            html += '<div style="margin-top:12px;padding:10px 14px;background:#f9fafb;border-radius:6px;font-size:0.8rem;color:#6b7280;">💬 ' + escapeHtml(o.mensaje) + '</div>';
        }

        $('#ltms-oc-detail-body').html(html);
        openModal('ltms-oc-detail-modal');
    });

    $('#ltms-oc-detail-close').on('click', function() {
        closeModal('ltms-oc-detail-modal');
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    loadProveedores();

})(jQuery);
