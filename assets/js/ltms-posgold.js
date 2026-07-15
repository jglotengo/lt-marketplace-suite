/**
 * LTMS view-posgold — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
(function($){
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    // FIX-P1-BATCH-A: escape API-controlled text before injecting into HTML
    // (categories, AJAX error/success messages, sync error list). Without
    // this, a PosGold category name or server error message containing
    // HTML/JS would execute in the vendor's browser. Uses jQuery's
    // .text()/.html() trick to escape &, <, >, then normalises quotes.
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var $div = $('<div/>');
        $div.text(String(text));
        return $div.html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Acordeón
    $('.ltms-posgold-accordion-header').on('click', function(){
        var $body = $(this).next('.ltms-posgold-accordion-body');
        var $icon = $(this).find('.ltms-posgold-accordion-icon');
        $body.slideToggle(200);
        $icon.text($body.is(':visible') ? '▲' : '▼');
    });

    // Guardar credenciales
    $('#ltms-posgold-config-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_credentials',
            nonce: nonce,
            subdomain: $('#ltms-posgold-subdomain').val(),
            token: $('#ltms-posgold-token').val(),
            empresaid: $('#ltms-posgold-empresaid').val(),
            usuarioid: $('#ltms-posgold-usuarioid').val(),
            bodegaid: $('#ltms-posgold-bodegaid').val()
        }).done(function(resp){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            if (resp.success) {
                LTMS.UX.toastSuccess('Exito', resp.data.message);
                LTMS.Dashboard.loadView('posgold', true);
            } else {
                LTMS.UX.toastError('Error', resp.data.message || resp.data);
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('💾 Guardar credenciales');
            LTMS.UX.toastError('Error', 'Error de red.');
        });
    });

    // Guardar categorías
    $('#ltms-posgold-categories-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_categories',
            nonce: nonce,
            category_ids: $('#ltms-posgold-category-ids').val()
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

    // === Cargar categorías PosGold (dropdown con checkboxes) ===

    var selectedCatIds = $('#ltms-posgold-category-ids').val().split(',').filter(function(v){ return v.trim() !== ''; });

    function renderCategoriesList(categories) {
        var $container = $('#ltms-posgold-cats-container');
        $container.empty();

        if (!categories || categories.length === 0) {
            $container.html('<p style="text-align:center;color:#9ca3af;padding:24px 0;margin:0;">No se encontraron categorías en tu PosGold.</p>');
            return;
        }

        var html = '';
        categories.forEach(function(cat) {
            // FIX-P1-BATCH-A: escape all PosGold-provided fields before
            // interpolating into HTML to prevent stored XSS via a malicious
            // category name/id injected by the upstream API.
            var catId    = escapeHtml(cat.id);
            var catName  = escapeHtml(cat.nombre);
            var catCount = parseInt(cat.count, 10);
            var checked = selectedCatIds.indexOf(cat.id) !== -1 ? 'checked' : '';
            var countLabel = cat.count > 0 ? ' <span style="color:#9ca3af;font-size:0.8rem;">(' + catCount + ' productos)</span>' : '';
            html += '<label style="display:flex;align-items:center;padding:8px 12px;border-radius:6px;cursor:pointer;background:#fff;margin-bottom:4px;border:1px solid #e5e7eb;">';
            html += '<input type="checkbox" class="ltms-posgold-cat-checkbox" value="' + catId + '" ' + checked + ' style="margin-right:8px;width:18px;height:18px;">';
            html += '<span style="flex:1;"><strong>' + catName + '</strong>' + countLabel + '<br><span style="font-size:0.7rem;color:#9ca3af;">ID: ' + catId + '</span></span>';
            html += '</label>';
        });
        $container.html(html);

        // Mostrar botones de acción
        $('#ltms-posgold-refresh-cats, #ltms-posgold-select-all-cats, #ltms-posgold-clear-cats').show();
        $('#ltms-posgold-load-cats').hide();

        // Manejar cambios en checkboxes
        $('.ltms-posgold-cat-checkbox').on('change', function(){
            updateSelectedCats();
        });
    }

    function updateSelectedCats() {
        selectedCatIds = [];
        $('.ltms-posgold-cat-checkbox:checked').each(function(){
            selectedCatIds.push($(this).val());
        });
        $('#ltms-posgold-category-ids').val(selectedCatIds.join(','));
        var count = selectedCatIds.length;
        $('#ltms-posgold-cats-status').text(count === 0 ? 'Ninguna seleccionada (se sincronizará TODO)' : count + ' seleccionada(s)');
    }

    function loadCategories(forceRefresh) {
        var $status = $('#ltms-posgold-cats-status');
        $status.text('Cargando categorías...');

        $.post(ajaxUrl, {
            action: 'ltms_get_posgold_categories',
            nonce: nonce,
            force_refresh: forceRefresh ? 'yes' : 'no'
        }).done(function(resp){
            if (resp.success) {
                renderCategoriesList(resp.data.categories);
                var source = resp.data.source === 'cache' ? ' (cache)' : (resp.data.source === 'fallback' ? ' (extraídas de productos)' : ' (endpoint)');
                $('#ltms-posgold-cats-status').text(resp.data.message + source);
                updateSelectedCats();
            } else {
                $('#ltms-posgold-cats-status').text('Error: ' + (resp.data.message || resp.data));
                $('#ltms-posgold-cats-container').html('<p style="text-align:center;color:#dc2626;padding:24px 0;margin:0;">✗ ' + escapeHtml(resp.data.message || resp.data) + '<br><br>Verifica tus credenciales en la sección "Credenciales PosGold" arriba.</p>');
            }
        }).fail(function(){
            $('#ltms-posgold-cats-status').text('Error de red.');
        });
    }

    // Cargar categorías al hacer click
    $('#ltms-posgold-load-cats, #ltms-posgold-refresh-cats').on('click', function(){
        loadCategories($(this).attr('id') === 'ltms-posgold-refresh-cats');
    });

    // Seleccionar todas / ninguna
    $('#ltms-posgold-select-all-cats').on('click', function(){
        $('.ltms-posgold-cat-checkbox').prop('checked', true);
        updateSelectedCats();
    });
    $('#ltms-posgold-clear-cats').on('click', function(){
        $('.ltms-posgold-cat-checkbox').prop('checked', false);
        updateSelectedCats();
    });

    // Cargar categorías automáticamente si ya tiene credenciales configuradas
    if ($('#ltms-posgold-subdomain').val() && $('#ltms-posgold-token').val()) {
        loadCategories(false);
    }

    // Guardar reglas de precio
    $('#ltms-posgold-rules-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_rules',
            nonce: nonce,
            is_redi: $('#ltms-posgold-is-redi').is(':checked') ? 'yes' : 'no',
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
    $('#ltms-posgold-seo-form').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');

        $.post(ajaxUrl, {
            action: 'ltms_save_posgold_seo',
            nonce: nonce,
            seo_template: $('#ltms-posgold-seo-template').val()
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
    $('#ltms-posgold-test-btn').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).text('Probando...');

        $.post(ajaxUrl, {
            action: 'ltms_test_posgold_connection',
            nonce: nonce
        }).done(function(resp){
            $btn.prop('disabled', false).html('🔍 Probar conexión');
            var $result = $('#ltms-posgold-test-result');
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
    $('#ltms-posgold-sync-btn').on('click', function(){
        var $btn = $(this);
        var $result = $('#ltms-posgold-sync-result');

        // v2.9.99 P1 FIX: eliminado native confirm() — el botón "Sincronizar ahora" es explícito.
        // El feedback visual (progress bar + resultado) confirma que la acción se ejecutó.
        $btn.prop('disabled', true).text('Sincronizando...');
        $result.html('<div style="padding:16px;background:#f0f9ff;border-radius:8px;color:#1e40af;">⏳ Sincronizando productos... No cierres esta página.</div>').show();

        $.post(ajaxUrl, {
            action: 'ltms_sync_posgold_products',
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
                setTimeout(function(){ LTMS.Dashboard.loadView('posgold', true); }, 6000);
            } else {
                $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ ' + escapeHtml(resp.data.message || resp.data) + '</div>').show();
            }
        }).fail(function(){
            $btn.prop('disabled', false).html('🔄 Sincronizar ahora');
            $result.html('<div style="padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;">✗ Error de red.</div>').show();
        });
    });

    // Update price example
    function updatePriceExample() {
        var cost = 50000;
        var transport = parseFloat($('input[name="transport_pct"]').val()) || 0;
        var advertising = parseFloat($('input[name="advertising_pct"]').val()) || 0;
        var returns = parseFloat($('input[name="returns_pct"]').val()) || 0;
        var margin = parseFloat($('input[name="margin_pct"]').val()) || 0;
        var commission = parseFloat($('input[name="lotengo_commission_pct"]').val()) || 0;
        var iva = parseFloat($('select[name="iva_pct"]').val()) || 0;
        var redi = $('#ltms-posgold-is-redi').is(':checked') ? (parseFloat($('input[name="redi_cost_pct"]').val()) || 0) : 0;
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

        $('#ltms-posgold-price-example').html(html);
    }

    // Update SEO preview
    function updateSeoPreview() {
        var template = $('#ltms-posgold-seo-template').val() || '{nombre} {marca} {categoria}';
        var preview = template
            .replace('{nombre}', 'Monopoly Clásico')
            .replace('{marca}', 'Hasbro')
            .replace('{categoria}', 'Juegos de Mesa')
            .replace('{modelo}', 'Monopoly-001')
            .replace('{codigo}', 'ABC123')
            .replace(/\s+/g, ' ')
            .trim();
        $('#ltms-posgold-seo-preview').text(preview);
    }

    // Live updates on input change
    $('#ltms-posgold-rules-form input, #ltms-posgold-rules-form select, #ltms-posgold-is-redi').on('input change', updatePriceExample);
    $('#ltms-posgold-seo-template').on('input', updateSeoPreview);

    // Initial render
    updatePriceExample();
    updateSeoPreview();

})(jQuery);
