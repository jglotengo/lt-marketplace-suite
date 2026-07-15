(function($) {
    'use strict';

    const nonce   = '';
    const ajaxUrl = '';
    let deleteTarget = null;
    let modalPreviousFocus = null;

    // ── Security helpers (EV-BUG-2 / EV-BUG-3) ───────────────────────────

    // EV-BUG-2: Escape Aveonline API data before inserting into HTML to
    // prevent stored XSS via recipient search. Escapes &, <, >, ", ' — safe
    // for both text content AND attribute values.
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = (text === null || text === undefined) ? '' : String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // EV-BUG-3: Block javascript:, data:, vbscript: URLs from being injected
    // into href attributes. Only http(s) is allowed; anything else becomes '#'.
    function safeUrl(url) {
        if (!url) return '#';
        if (/^https?:\/\//i.test(url)) return url;
        return '#';
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    function showMsg(el, html, type) {
        const colors = {
            success: { bg: '#f0fdf4', border: '#bbf7d0', color: '#166534' },
            error:   { bg: '#fef2f2', border: '#fecaca', color: '#991b1b' },
            info:    { bg: '#eff6ff', border: '#bfdbfe', color: '#1d4ed8' },
        };
        const c = colors[type] || colors.info;
        $(el).html(`<div style="padding:12px 16px;background:${c.bg};border:1px solid ${c.border};border-radius:8px;color:${c.color};font-size:0.85rem;">${html}</div>`).show();
    }

    function formatDate(dt) {
        if (!dt) return '—';
        return dt.replace('T', ' ').substring(0, 16);
    }

    // ── Cargar mis relaciones ─────────────────────────────────────────────
    function loadRelations() {
        $('#ltms-envios-tbody').html('<tr><td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">Cargando…</td></tr>');
        $.post(ajaxUrl, { action: 'ltms_vendor_list_relations', nonce }, function(res) {
            if (!res.success || !res.data.relations.length) {
                $('#ltms-envios-tbody').html('<tr><td colspan="5" style="padding:32px;text-align:center;color:#9ca3af;">Sin relaciones registradas.</td></tr>');
                return;
            }
            let rows = '';
            res.data.relations.forEach(r => {
                // FIX-P1-BATCH-A: escape every Aveonline-provided field before
                // injecting into HTML — both text content and attributes
                // (data-relacion, guias badges) — to prevent stored XSS if the
                // API returns HTML/JS in any field. Mirrors the escapeHtml()
                // already applied to the recipient-search results.
                const guias = (r.guias || '').split(',').map(g => g.trim()).filter(Boolean);
                const guiasBadge = guias.slice(0, 2).map(g =>
                    `<span style="display:inline-block;padding:2px 6px;background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:0.72rem;font-family:monospace;margin:1px;">${escapeHtml(g)}</span>`
                ).join('') + (guias.length > 2 ? `<span style="font-size:0.72rem;color:#9ca3af;"> +${guias.length - 2}</span>` : '');
                const relEsc    = escapeHtml(r.relacionenvio);
                const transpEsc = escapeHtml(r.transportadora || '—');
                const fechaEsc  = escapeHtml(formatDate(r.fecha_aveonline || r.created_at));

                rows += `<tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 16px;font-family:monospace;font-size:0.78rem;color:#4b5563;">${relEsc}</td>
                    <td style="padding:10px 16px;color:#374151;">${transpEsc}</td>
                    <td style="padding:10px 16px;">${guiasBadge}</td>
                    <td style="padding:10px 16px;color:#6b7280;white-space:nowrap;">${fechaEsc}</td>
                    <td style="padding:10px 16px;text-align:center;">
                        <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                            ${r.rutaimpresion ? `<a href="${safeUrl(r.rutaimpresion)}" target="_blank" rel="noopener noreferrer" style="padding:4px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:0.75rem;text-decoration:none;white-space:nowrap;">🖨 Imprimir</a>` : ''}
                            <button type="button" class="ltms-envios-delete-btn" data-relacion="${relEsc}"
                                    style="padding:4px 10px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:5px;font-size:0.75rem;cursor:pointer;white-space:nowrap;">
                                🗑 Eliminar
                            </button>
                        </div>
                    </td>
                </tr>`;
            });
            $('#ltms-envios-tbody').html(rows);
        });
    }

    // ── Crear relación ────────────────────────────────────────────────────
    $('#ltms-envios-create-btn').on('click', function() {
        const carrier = $('#ltms-envios-carrier').val();
        const guias   = $('#ltms-envios-guias').val().trim();
        const $res    = $('#ltms-envios-create-result');

        if (!carrier || !guias) {
            showMsg($res, 'Selecciona una transportadora e ingresa al menos un número de guía.', 'error');
            return;
        }

        $(this).prop('disabled', true).text('Creando…');
        $res.hide();

        $.post(ajaxUrl, { action: 'ltms_vendor_create_relation', nonce, transportadora: carrier, guias }, function(res) {
            $('#ltms-envios-create-btn').prop('disabled', false).text('✅ Crear relación de envío');
            if (!res.success) {
                // v2.9.99 REG-4 FIX: escape server message before HTML injection.
                var errMsg = (res.data && res.data.message) ? res.data.message : 'Error desconocido.';
                showMsg($res, '❌ ' + escapeHtml(errMsg), 'error');
                return;
            }
            const d = res.data;
            // FIX-P1-BATCH-A: escape Aveonline-controlled fields before
            // interpolating into the success toast HTML.
            const relEsc   = escapeHtml(d.relacionenvio);
            const fechaEsc = escapeHtml(d.fecha);
            const printBtn = d.rutaimpresion
                ? `<a href="${safeUrl(d.rutaimpresion)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:10px;padding:7px 14px;background:#1d4ed8;color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;">🖨 Imprimir manifiesto</a>`
                : '';
            showMsg($res, `✅ Relación creada: <strong style="font-family:monospace;">${relEsc}</strong><br><span style="font-size:0.8rem;color:#6b7280;">${fechaEsc}</span>${printBtn}`, 'success');
            $('#ltms-envios-guias').val('');
            loadRelations();
        });
    });

    // ── Buscar destinatarios (autocomplete) ──────────────────────────────
    let searchTimer;
    $('#ltms-envios-recipient-search').on('input', function() {
        clearTimeout(searchTimer);
        const val = $(this).val().trim();
        if (val.length < 3) { $('#ltms-envios-recipient-results').hide(); return; }
        searchTimer = setTimeout(() => searchRecipients(val), 400);
    });

    $('#ltms-envios-recipient-btn').on('click', function() {
        const val = $('#ltms-envios-recipient-search').val().trim();
        if (val.length >= 3) searchRecipients(val);
    });

    function searchRecipients(param) {
        $('#ltms-envios-recipient-results').html('<span style="font-size:0.78rem;color:#6b7280;">Buscando…</span>').show();
        $.get(ajaxUrl, { action: 'ltms_aveonline_search_recipients', nonce, param }, function(res) {
            const $r = $('#ltms-envios-recipient-results');
            if (!res.success || !res.data.destinatarios.length) {
                $r.html('<span style="font-size:0.78rem;color:#9ca3af;">Sin resultados.</span>').show();
                return;
            }
            let html = '<div style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;">';
            res.data.destinatarios.forEach(d => {
                // EV-BUG-2: escape all Aveonline-provided fields before inserting
                // into HTML (both attributes and text content) to prevent stored XSS.
                // role="button" + tabindex="0" make each result keyboard-accessible
                // (WCAG 2.1 — see Enter/Space handler below).
                html += `<div class="ltms-recipient-item" role="button" tabindex="0" style="padding:8px 10px;background:#fff;border:1px solid #e0f2fe;border-radius:6px;cursor:pointer;font-size:0.8rem;" data-nombre="${escapeHtml(d.nombre)}" data-direccion="${escapeHtml(d.direccion)}" data-telefono="${escapeHtml(d.telefono || '')}">
                    <strong>${escapeHtml(d.nombre)}</strong> — ${escapeHtml(d.direccion)} <span style="color:#9ca3af;font-size:0.72rem;">${escapeHtml(d.telefono || '')}</span>
                </div>`;
            });
            html += '</div>';
            $r.html(html).show();
        });
    }

    // Al seleccionar un destinatario, poner su nombre en el campo de guías como referencia
    $(document).on('click', '.ltms-recipient-item', function() {
        const nombre = $(this).data('nombre');
        // Insertar referencia del destinatario en las notas del formulario (informativo)
        $('#ltms-envios-recipient-search').val(nombre);
        $('#ltms-envios-recipient-results').hide();
    });

    // EV-BUG-4 (WCAG 2.1): keyboard support for recipient items — Enter or Space
    // activates the item the same as a click.
    $(document).on('keydown', '.ltms-recipient-item', function(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

    // ── Eliminar relación ─────────────────────────────────────────────────
    // EV-BUG-4 (WCAG 2.1): modal management with focus restoration + Escape
    // to close + basic Tab focus trap. openDeleteModal() saves the currently
    // focused element so we can return focus to it on close.
    function openDeleteModal(relacion) {
        deleteTarget = relacion;
        $('#ltms-envios-delete-msg').text(`¿Eliminar la relación ${relacion} en Aveonline? Esta acción no se puede deshacer.`);
        modalPreviousFocus = document.activeElement;
        $('#ltms-envios-delete-modal').css('display', 'flex');
        // Move focus into the modal so screen readers announce it and keyboard
        // users can immediately Tab through the dialog actions.
        setTimeout(function() { $('#ltms-envios-delete-cancel').trigger('focus'); }, 0);
        $(document).on('keydown.ltms-modal', handleModalKeydown);
    }

    function closeDeleteModal() {
        $('#ltms-envios-delete-modal').hide();
        $(document).off('keydown.ltms-modal', handleModalKeydown);
        deleteTarget = null;
        if (modalPreviousFocus && typeof modalPreviousFocus.focus === 'function') {
            modalPreviousFocus.focus();
            modalPreviousFocus = null;
        }
    }

    function handleModalKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeDeleteModal();
            return;
        }
        if (e.key === 'Tab') {
            // Focus trap: keep Tab within the modal's focusable elements.
            const $focusable = $('#ltms-envios-delete-modal').find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
                .filter(':visible:not(:disabled)');
            if (!$focusable.length) return;
            const first = $focusable.first()[0];
            const last  = $focusable.last()[0];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    $(document).on('click', '.ltms-envios-delete-btn', function() {
        openDeleteModal($(this).data('relacion'));
    });

    $('#ltms-envios-delete-cancel').on('click', function() {
        closeDeleteModal();
    });

    $('#ltms-envios-delete-confirm').on('click', function() {
        if (!deleteTarget) return;
        $(this).prop('disabled', true).text('Eliminando…');

        $.post(ajaxUrl, { action: 'ltms_vendor_delete_relation', nonce, numero_relacion: deleteTarget }, function(res) {
            $('#ltms-envios-delete-confirm').prop('disabled', false).text('Eliminar');
            closeDeleteModal();
            if (res.success) {
                loadRelations();
            } else {
                LTMS.UX.toastError('Error', res.data?.message || 'Error al eliminar.');
            }
        });
    });

    $('#ltms-envios-refresh-btn').on('click', loadRelations);

    // ── Init ──────────────────────────────────────────────────────────────
    loadRelations();

})(jQuery);
