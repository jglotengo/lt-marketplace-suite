/**
 * LTMS view-vtex — extracted from inline <script>.
 * CSP FIX (patrón FASE2B): external file for CSP compliance.
 */
(function($){
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    // FIX-P1-BATCH-A: escape API-controlled text before injecting into HTML.
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var $div = $('<div/>');
        $div.text(String(text));
        return $div.html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Acordeón
    $('.ltms-vtex-accordion-header').on('click', function(){
        var $body = $(this).next('.ltms-vtex-accordion-body');
        var $icon = $(this).find('.ltms-vtex-accordion-icon');
        $body.slideToggle(200);
        $icon.text($body.is(':visible') ? '▲' : '▼');
    });

    // Guardar credenciales
    $('#ltms-vtex-config-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_vtex_credentials',
            nonce: nonce,
            account_name: $('#ltms-vtex-account-name').val(),
            environment: $('#ltms-vtex-environment').val(),
            app_key: $('#ltms-vtex-app-key').val(),
            app_token: $('#ltms-vtex-app-token').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            if (resp.success) {
                LTMS.UX.toastSuccess('Exito', resp.data.message);
                LTMS.Dashboard.loadView('vtex', true);
            } else {
                LTMS.UX.toastError('Error', resp.data.message || resp.data);
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // Guardar categorías
    $('#ltms-vtex-categories-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_vtex_categories',
            nonce: nonce,
            category_ids: $('#ltms-vtex-category-ids').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar categorías seleccionadas');
            if (resp.success) {
                LTMS.UX.toastSuccess('Exito', resp.data.message);
            } else {
                LTMS.UX.toastError('Error', resp.data.message || resp.data);
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar categorías seleccionadas');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // === Cargar categorías VTEX (árbol → checkboxes) ===

    var selectedCatIds = $('#ltms-vtex-category-ids').val().split(',').filter(function(v){ return v.trim() !== ''; });

    function renderCategoriesList(categories) {
        var $container = $('#ltms-vtex-cats-container');
        $container.empty();

        if (!categories || categories.length === 0) {
            $container.html('<p style="text-align:center;color:#9ca3af;padding:24px 0;margin:0;">No se encontraron categorías en tu VTEX.</p>');
            return;
        }

        var html = '';
        categories.forEach(function(cat) {
            var catId    = escapeHtml(cat.id);
            var catName  = escapeHtml(cat.nombre);
            var checked = selectedCatIds.indexOf(cat.id) !== -1 ? 'checked' : '';
            html += '<label style="display:flex;align-items:center;padding:8px 12px;border-radius:6px;cursor:pointer;background:#fff;margin-bottom:4px;border:1px solid #e5e7eb;">';
            html += '<input type="checkbox" class="ltms-vtex-cat-checkbox" value="' + catId + '" ' + checked + ' style="margin-right:8px;width:18px;height:18px;">';
            html += '<span style="flex:1;"><strong>' + catName + '</strong><br><span style="font-size:0.7rem;color:#9ca3af;">ID: ' + catId + '</span></span>';
            html += '</label>';
        });
        $container.html(html);

        $('#ltms-vtex-refresh-cats, #ltms-vtex-select-all-cats, #ltms-vtex-clear-cats').show();
        $('#ltms-vtex-load-cats').hide();

        $('.ltms-vtex-cat-checkbox').on('change', function(){
            updateSelectedCats();
        });
    }

    function updateSelectedCats() {
        selectedCatIds = [];
        $('.ltms-vtex-cat-checkbox:checked').each(function(){
            selectedCatIds.push($(this).val());
        });
        $('#ltms-vtex-category-ids').val(selectedCatIds.join(','));
        var count = selectedCatIds.length;
        $('#ltms-vtex-cats-status').text(count === 0 ? 'Ninguna seleccionada (se sincronizará TODO)' : count + ' seleccionada(s)');
    }

    function loadCategories(forceRefresh) {
        var $status = $('#ltms-vtex-cats-status');
        $status.text('Cargando categorías...');

        $.post(ajaxUrl, {
            action: 'ltms_get_vtex_categories',
            nonce: nonce,
            force_refresh: forceRefresh ? 'yes' : 'no'
        }).done(function(resp){
            if (resp.success) {
                renderCategoriesList(resp.data.categories);
                $('#ltms-vtex-cats-status').text(resp.data.message);
                updateSelectedCats();
            } else {
                $('#ltms-vtex-cats-status').text('Error: ' + (resp.data.message || resp.data));
                $('#ltms-vtex-cats-container').html('<p style="text-align:center;color:#dc2626;padding:24px 0;margin:0;">✗ ' + escapeHtml(resp.data.message || resp.data) + '<br><br>Verifica tus credenciales en la sección "Credenciales VTEX" arriba.</p>');
            }
        }).fail(function(){
            $('#ltms-vtex-cats-status').text('Error de red.');
        });
    }

    $('#ltms-vtex-load-cats, #ltms-vtex-refresh-cats').on('click', function(){
        loadCategories($(this).attr('id') === 'ltms-vtex-refresh-cats');
    });

    $('#ltms-vtex-select-all-cats').on('click', function(){
        $('.ltms-vtex-cat-checkbox').prop('checked', true);
        updateSelectedCats();
    });
    $('#ltms-vtex-clear-cats').on('click', function(){
        $('.ltms-vtex-cat-checkbox').prop('checked', false);
        updateSelectedCats();
    });

    // Cargar categorías automáticamente si ya tiene credenciales configuradas
    if ($('#ltms-vtex-account-name').val() && $('#ltms-vtex-app-token').val()) {
        loadCategories(false);
    }

    // Guardar reglas de precio
    $('#ltms-vtex-rules-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_vtex_rules',
            nonce: nonce,
            is_redi: $('#ltms-vtex-is-redi').is(':checked') ? 'yes' : 'no',
            transport_pct: $('input[name="transport_pct"]').val(),
            advertising_pct: $('input[name="advertising_pct"]').val(),
            returns_pct: $('input[name="returns_pct"]').val(),
            margin_pct: $('input[name="margin_pct"]').val(),
            lotengo_commission_pct: $('input[name="lotengo_commission_pct"]').val(),
            iva_pct: $('select[name="iva_pct"]').val(),
            redi_cost_pct: $('input[name="redi_cost_pct"]').val(),
            round_multiple: $('select[name="round_multiple"]').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar reglas de precio');
            if (resp.success) {
                LTMS.UX.toastSuccess('Exito', resp.data.message);
                updatePriceExample();
            } else {
                LTMS.UX.toastError('Error', resp.data.message || resp.data);
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar reglas de precio');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // Guardar SEO
    $('#ltms-vtex-seo-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_vtex_seo',
            nonce: nonce,
            seo_template: $('#ltms-vtex-seo-template').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar plantilla SEO');
            if (resp.success) {
                LTMS.UX.toastSuccess('Exito', resp.data.message);
                updateSeoPreview();
            } else {
                LTMS.UX.toastError('Error', resp.data.message || resp.data);
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar plantilla SEO');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // Probar conexión
    $('#ltms-vtex-test-btn').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Probando...');

        $.post(ajaxUrl, {
            action: 'ltms_test_vtex_connection',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔍 Probar conexión');
            var $result = $('#ltms-vtex-test-result');
            if (resp.success) {
                $result.html('<div style="padding:12px 16px;background:#dcfce7;border-radius:8px;color:#166534;">✓ ' + escapeHtml(resp.data.message) + '</div>').show();
            } else {
                $result.html('<div style="padding:12px 16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + escapeHtml(resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔍 Probar conexión');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // Sincronizar productos
    $('#ltms-vtex-sync-btn').on('click', function(){
        var $btn = $(this);
        var $result = $('#ltms-vtex-sync-result');

        $btn.prop('disabled', true).text('Sincronizando...');
        $result.html('<div style="padding:16px;background:#f0f9ff;border-radius:8px;color:#1e40af;">⏳ Sincronizando productos... No cierres esta página.</div>').show();

        $.post(ajaxUrl, {
            action: 'ltms_sync_vtex_products',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            if (resp.success) {
                var d = resp.data;
                var html = '<div style="padding:16px;background:#dcfce7;border-radius:8px;color:#166534;">';
                html += '<div style="font-weight:600;margin-bottom:8px;">✓ ' + escapeHtml(d.message) + '</div>';
                if (d.errors && d.errors.length > 0) {
                    html += '<div style="margin-top:8px;font-size:0.85rem;color:#7f1d1d;">';
                    html += '<strong>Errores (' + parseInt(d.errors.length, 10) + '):</strong><ul style="margin:4px 0;padding-left:20px;">';
                    d.errors.slice(0, 10).forEach(function(e){ html += '<li>' + escapeHtml(e) + '</li>'; });
                    if (d.errors.length > 10) { html += '<li>... y ' + parseInt(d.errors.length - 10, 10) + ' más</li>'; }
                    html += '</ul></div>';
                }
                html += '</div>';
                $result.html(html).show();
                setTimeout(function(){ LTMS.Dashboard.loadView('vtex', true); }, 6000);
            } else {
                $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + escapeHtml(resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ Error de red.</div>').show();
        });
    });

    // Update price example (misma fórmula que el backend)
    function updatePriceExample() {
        var cost = 50000;
        var transport = parseFloat($('input[name="transport_pct"]').val()) || 0;
        var advertising = parseFloat($('input[name="advertising_pct"]').val()) || 0;
        var returns = parseFloat($('input[name="returns_pct"]').val()) || 0;
        var margin = parseFloat($('input[name="margin_pct"]').val()) || 0;
        var commission = parseFloat($('input[name="lotengo_commission_pct"]').val()) || 0;
        var iva = parseFloat($('select[name="iva_pct"]').val()) || 0;
        var redi = $('#ltms-vtex-is-redi').is(':checked') ? (parseFloat($('input[name="redi_cost_pct"]').val()) || 0) : 0;
        var round = parseInt($('select[name="round_multiple"]').val()) || 1000;

        var t = cost * transport / 100;
        var a = cost * advertising / 100;
        var r = cost * redi / 100;
        var sub1 = cost + t + a + r;
        var m = sub1 * margin / 100;
        var sub2 = sub1 + m;
        var c = commission > 0 ? (sub2 / (1 - commission/100) - sub2) : 0;
        var pw = sub2 + c;
        var ret = pw * returns / 100;
        var base = pw + ret;
        var iv = base * iva / 100;
        var final = base + iv;
        var rounded = Math.ceil(final / round) * round;

        var html = 'Costo: $' + cost.toLocaleString() + '<br>';
        html += '+ Transporte (' + transport + '%): $' + Math.round(t).toLocaleString() + '<br>';
        html += '+ Publicidad (' + advertising + '%): $' + Math.round(a).toLocaleString() + '<br>';
        if (redi > 0) { html += '+ ReDi (' + redi + '%): $' + Math.round(r).toLocaleString() + '<br>'; }
        html += '= Subtotal gastos: $' + Math.round(sub1).toLocaleString() + '<br>';
        html += '+ Margen (' + margin + '%): $' + Math.round(m).toLocaleString() + '<br>';
        html += '+ Comisión LT (' + commission + '%): $' + Math.round(c).toLocaleString() + '<br>';
        html += '+ Devoluciones (' + returns + '%): $' + Math.round(ret).toLocaleString() + '<br>';
        html += '+ IVA (' + iva + '%): $' + Math.round(iv).toLocaleString() + '<br>';
        html += '<strong>= Precio final: $' + Math.round(final).toLocaleString() + '</strong><br>';
        html += '<strong style="color:#16a34a;">→ Precio redondeado: $' + rounded.toLocaleString() + '</strong>';

        $('#ltms-vtex-price-example').html(html);
    }

    // Update SEO preview
    function updateSeoPreview() {
        var template = $('#ltms-vtex-seo-template').val() || '{nombre} {marca} {categoria}';
        var preview = template
            .replace('{nombre}', 'Monopoly Clásico')
            .replace('{marca}', 'Hasbro')
            .replace('{categoria}', 'Juegos de Mesa')
            .replace('{modelo}', 'Monopoly-001')
            .replace('{codigo}', 'ABC123')
            .replace(/\s+/g, ' ')
            .trim();
        $('#ltms-vtex-seo-preview').text(preview);
    }

    // Live updates on input change
    $('#ltms-vtex-rules-form input, #ltms-vtex-rules-form select, #ltms-vtex-is-redi').on('input change', updatePriceExample);
    $('#ltms-vtex-seo-template').on('input', updateSeoPreview);

    // Initial render
    updatePriceExample();
    updateSeoPreview();

})(jQuery);