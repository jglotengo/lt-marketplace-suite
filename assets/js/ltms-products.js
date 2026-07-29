/**
 * LTMS view-products — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
(function($){
    'use strict';

    // ── Imagen: click en preview o botón ─────────────────────────
    $('#ltms-np-img-preview, #ltms-np-img-btn').on('click', function(){
        $('#ltms-np-img-input').trigger('click');
    });

    // v2.9.77 P0-UI-3: ReDi toggle bindings fuera del click handler (memory leak fix).
    $('#ltms-np-redi-enabled').on('change', function(){
        $('#ltms-np-redi-rate-wrap').toggle($(this).is(':checked'));
    });
    $('#ltms-ep-redi-enabled').on('change', function(){
        $('#ltms-ep-redi-rate-wrap').toggle($(this).is(':checked'));
    });

    // v2.9.88 P1: Gallery upload (multiple images)
    var galleryIds = [];
    $('#ltms-np-gallery-btn, #ltms-np-gallery-preview').on('click', function(){
        if (galleryIds.length >= 5) {
            LTMS.UX.toastError('Límite', 'Máximo 5 imágenes en la galería.');
            return;
        }
        $('#ltms-np-gallery-input').trigger('click');
    });
    $('#ltms-np-gallery-input').on('change', function(){
        var files = this.files;
        if (!files || !files.length) return;
        var remaining = 5 - galleryIds.length;
        var toUpload = Math.min(files.length, remaining);
        if (files.length > remaining) {
            LTMS.UX.toastWarning('Límite', 'Solo se subirán ' + remaining + ' imágenes más (máx 5).');
        }
        for (var i = 0; i < toUpload; i++) {
            (function(file) {
                var fd = new FormData();
                fd.append('action', 'ltms_upload_product_image');
                fd.append('nonce', ltmsDashboard.nonce);
                fd.append('file', file);
                $.ajax({
                    url: ltmsDashboard.ajax_url, method: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function(res) {
                        if (res.success && res.data.attachment_id) {
                            galleryIds.push(res.data.attachment_id);
                            $('#ltms-np-gallery-ids').val(galleryIds.join(','));
                            var $preview = $('#ltms-np-gallery-preview');
                            $preview.find('span').remove(); // Remove placeholder
                            $preview.append(
                                '<div style="position:relative;width:50px;height:50px;border-radius:6px;overflow:hidden;">' +
                                '<img src="' + res.data.url + '" style="width:100%;height:100%;object-fit:cover;">' +
                                '<button type="button" data-gallery-remove="' + res.data.attachment_id + '" style="position:absolute;top:0;right:0;background:rgba(239,68,68,0.9);color:#fff;border:none;font-size:0.6rem;cursor:pointer;width:16px;height:16px;line-height:1;">✕</button>' +
                                '</div>'
                            );
                        }
                    }
                });
            })(files[i]);
        }
        $(this).val(''); // Reset input
    });
    // Remove gallery image
    $(document).on('click', '[data-gallery-remove]', function(e) {
        e.preventDefault();
        var id = parseInt($(this).data('gallery-remove'));
        galleryIds = galleryIds.filter(function(v) { return v !== id; });
        $('#ltms-np-gallery-ids').val(galleryIds.join(','));
        $(this).parent().remove();
        if (galleryIds.length === 0) {
            $('#ltms-np-gallery-preview').html('<span style="color:#d1d5db;font-size:0.8rem;">Click para añadir imágenes</span>');
        }
    });

    $('#ltms-np-img-input').on('change', function(){
        const file = this.files[0];
        if (!file) return;

        const $status = $('#ltms-np-img-status');
        $status.text('');

        const formData = new FormData();
        formData.append('action', 'ltms_upload_product_image');
        formData.append('nonce',  ltmsDashboard.nonce);
        formData.append('image',  file);

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res){
                if (res.success){
                    $('#ltms-np-image-id').val(res.data.attachment_id);
                    $('#ltms-np-img-preview').html(
                        '<img src="' + res.data.url + '" style="width:100%;height:100%;object-fit:cover;">'
                    );
                    $status.text('✓');
                } else {
                    $status.text('');
                }
            },
            error: function(){
                $status.text('');
            }
        });
    });

    // ── Crear producto ────────────────────────────────────────────
    $('#ltms-np-submit').on('click', function(){
        const name  = $('#ltms-np-name').val().trim();
        const price = parseFloat($('#ltms-np-price').val());
        const $notice = $('#ltms-np-notice');

        if (!name || isNaN(price) || price <= 0){
            $notice.removeClass('ltms-notice-success')
                   .addClass('ltms-notice-error')
                   .text('Nombre y precio son obligatorios.')
                   .show();
            return;
        }

        const $btn     = $(this);
        const origText = $btn.html();
        $btn.prop('disabled', true).text('Guardando...');

        // AUDIT-PROD-044: recopilar atributos de variaciones (paridad con loadNewProductView eliminado).
        var npVarAttributes = [];
        if ( $('input[name="ltms_np_tipo"]:checked').val() === 'variable' ) {
            $('#ltms-np-attributes > div').each(function() {
                var aName = $(this).find('.ltms-np-attr-name').val();
                var aValues = $(this).find('.ltms-np-attr-values').val();
                if (aName && aValues) {
                    npVarAttributes.push({
                        name: aName,
                        values: aValues.split('|').map(function(v){ return v.trim(); }).filter(Boolean)
                    });
                }
            });
        }

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:           'ltms_create_product',
                nonce:            ltmsDashboard.nonce,
                name:             name,
                description:      $('#ltms-np-desc').val(),
                short_description: $('#ltms-np-short-desc').val(),
                price:            price,
                sale_price:       $('#ltms-np-sale-price').val(),
                stock:            $('#ltms-np-stock').val(),
                sku:              $('#ltms-np-sku').val(),
                category_id:      $('#ltms-np-category').val(),
                image_id:         $('#ltms-np-image-id').val(),
                gallery_ids:      $('#ltms-np-gallery-ids').val(),
                status:           $('#ltms-np-status').val(),
                product_type:     $('input[name="ltms_np_tipo"]:checked').val() || 'physical',
                weight:           $('#ltms-np-weight').val(),
                dim_length:       $('#ltms-np-length').val(),
                dim_width:        $('#ltms-np-width').val(),
                dim_height:       $('#ltms-np-height').val(),
                shipping_class_id: $('#ltms-np-shipping-class').val(),
                tags:             $('#ltms-np-tags').val(),
                download_url:     $('#ltms-np-download-url').val(),
                download_limit:   $('#ltms-np-download-limit').val(),
                download_expiry:  $('#ltms-np-download-expiry').val(),
                redi_enabled:     $('#ltms-np-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:        parseFloat($('#ltms-np-redi-rate').val()) || 0,
                // v2.9.285: campos de booking/turismo
                booking_type:     $('#ltms-np-booking-type').val() || 'accommodation',
                min_nights:       parseInt($('#ltms-np-min-nights').val()) || 1,
                max_nights:       parseInt($('#ltms-np-max-nights').val()) || 0,
                booking_capacity: parseInt($('#ltms-np-booking-capacity').val()) || 1,
                checkin_time:     $('#ltms-np-checkin-time').val() || '15:00',
                checkout_time:    $('#ltms-np-checkout-time').val() || '11:00',
                payment_mode:     $('#ltms-np-payment-mode').val() || 'full',
                deposit_pct:      parseFloat($('#ltms-np-deposit-pct').val()) || 0,
                // AUDIT-PROD-044: visibilidad + variaciones
                catalog_visibility: $('#ltms-np-visibility').val() || 'visible',
                variation_attributes: npVarAttributes.length ? JSON.stringify(npVarAttributes) : '',
            },
            success: function(res){
                $btn.prop('disabled', false).html(origText);

                if (res.success){
                    $notice.removeClass('ltms-notice-error')
                           .addClass('ltms-notice-success')
                           .text('✅ ')
                           .show();
                    // Reset form
                    $('#ltms-np-name,#ltms-np-desc,#ltms-np-stock').val('');
                    $('#ltms-np-price').val('');
                    $('#ltms-np-image-id').val('');
                    $('#ltms-np-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
                    $('#ltms-np-img-status').text('');
                    $('input[name="ltms_np_tipo"][value="physical"]').prop('checked', true).trigger('change');
                    // v2.9.77 P0-UI-1: Usar loadView en vez de location.reload (SPA).
                    setTimeout(function(){ LTMS.Dashboard.loadView('products', true); }, 1500);
                } else {
                    $notice.removeClass('ltms-notice-success')
                           .addClass('ltms-notice-error')
                           .text(res.data || '')
                           .show();
                }
            },
            error: function(){
                $btn.prop('disabled', false).html(origText);
                $notice.removeClass('ltms-notice-success')
                       .addClass('ltms-notice-error')
                       .text('')
                       .show();
            }
        });
    });

    // ── Botones del estado inicial PHP → abrir modal PHP ─────────
    // AUDIT-PROD-044 FIX: eliminar la rama `if (typeof LTMS.Dashboard.loadNewProductView === 'function')`
    // queprefería la versión JS atajo (ltms-dashboard.js) sobre el modal PHP source of truth.
    // Al quitar ltms-dashboard.js#loadNewProductView, este handler ahora abre siempre el modal PHP
    // (ltms-modal-new-product en view-products.php), que tiene todos los campos avanzados.
    $(document).on('click', '#ltms-add-product-btn, #ltms-add-product-btn-empty', function(e){
        e.preventDefault();
        LTMS.Modal.open('ltms-modal-new-product');
    });

    // ── Limpiar modal al cerrar ───────────────────────────────────
    $(document).on('click', '.ltms-modal-backdrop, .ltms-modal-close', function(){
        $('#ltms-np-notice').hide().text('');
        $('#ltms-np-name, #ltms-np-desc, #ltms-np-stock, #ltms-np-price').val('');
        $('#ltms-np-image-id').val('');
        $('#ltms-np-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
        $('#ltms-np-img-status').text('');
        $('input[name="ltms_np_tipo"][value="physical"]').prop('checked', true);
        // CS-04: reset visual de los tipos — AUDIT-PROD-044: incluir 'restaurant' y 'variable'.
        ['physical','digital','service','booking','restaurant','variable'].forEach(function(t){
            $('#ltms-np-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        });
        $('#ltms-np-tipo-physical-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
        // AUDIT-PROD-044: limpiar atributos de variaciones al cerrar el modal.
        $('#ltms-np-attributes').empty();
    });

    // CS-04/CS-06: highlight para los tipos — AUDIT-PROD-044: incluir 'restaurant' y 'variable'.
    $(document).on('change', 'input[name="ltms_np_tipo"]', function(){
        ['physical','digital','service','booking','restaurant','variable'].forEach(function(t){
            $('#ltms-np-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        });
        $('#ltms-np-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });
    // Estado inicial del highlight
    var _initTipo = $('input[name="ltms_np_tipo"]:checked').val() || 'physical';
    $('#ltms-np-tipo-'+_initTipo+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});

    // ── CS-07: Editar producto inline ────────────────────────────
    $(document).on('click', '.ltms-edit-product-btn', function(){
        var pid = $(this).data('product-id');
        $('#ltms-ep-notice').hide().text('');
        $('#ltms-ep-product-id').val(pid);
        $('#ltms-ep-name,#ltms-ep-desc,#ltms-ep-price,#ltms-ep-stock,#ltms-ep-sale-price,#ltms-ep-short-desc,#ltms-ep-sku,#ltms-ep-tags').val('');
        $('#ltms-ep-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
        $('#ltms-ep-image-id').val('');
        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: { action:'ltms_get_product', nonce:ltmsDashboard.nonce, product_id:pid },
            success: function(res){
                if(!res.success) return;
                var d = res.data;
                $('#ltms-ep-name').val(d.name);
                $('#ltms-ep-desc').val(d.description);
                $('#ltms-ep-price').val(d.price);
                $('#ltms-ep-stock').val(d.stock !== null ? d.stock : '');
                // AUDIT-PROD-H4 (re-auditoría): poblar precio de oferta.
                $('#ltms-ep-sale-price').val(d.sale_price || '');
                // AUDIT-PROD-H3 (re-auditoría): poblar short_desc, sku, shipping_class.
                $('#ltms-ep-short-desc').val(d.short_description || '');
                $('#ltms-ep-sku').val(d.sku || '');
                if (d.shipping_class_id) { $('#ltms-ep-shipping-class').val(d.shipping_class_id); }
                // AUDIT-PROD-H7 (re-auditoría): poblar tags como CSV desde get_product().
                // ANTES este input quedaba vacío siempre → update_product recibía `tags: ''`
                // → wp_set_post_terms reemplazaba todos los tags existentes por []. Bug silencioso
                // que borraba los tags del producto en cada edición. Ver LECCIONES_APRENDIDAS.md #130.
                $('#ltms-ep-tags').val(d.tags || '');
                $('#ltms-ep-category').val(d.category_id);
                $('#ltms-ep-status').val(d.status);
                $('#ltms-ep-image-id').val(d.image_id);
                if(d.image_url){ $('#ltms-ep-img-preview').html('<img src="'+d.image_url+'" style="width:100%;height:100%;object-fit:cover;">'); }
                // Tipo
                var tipo = d.product_type || 'physical';
                $('input[name="ltms_ep_tipo"][value="'+tipo+'"]').prop('checked',true).trigger('change');
                // M-QA-11: poblar estado real de ReDi (antes el modal siempre arrancaba
                // sin marcar, ignorando si el producto ya tenía ReDi activo y a qué tasa).
                // d.redi_rate llega ya en porcentaje (backend hace ×100 en get_product()).
                var rediOn = d.redi_enabled === 'yes';
                $('#ltms-ep-redi-enabled').prop('checked', rediOn);
                $('#ltms-ep-redi-rate-wrap').toggle(rediOn);
                if (d.redi_rate) { $('#ltms-ep-redi-rate').val(d.redi_rate); }
                // AUDIT-PROD-044: poblar visibilidad + booking fields (paridad con get_product extended).
                if (d.catalog_visibility) { $('#ltms-ep-visibility').val(d.catalog_visibility); }
                if (d.booking_type) { $('#ltms-ep-booking-type').val(d.booking_type); }
                if (d.booking_capacity) { $('#ltms-ep-booking-capacity').val(d.booking_capacity); }
                if (d.min_nights) { $('#ltms-ep-min-nights').val(d.min_nights); }
                if (d.max_nights !== undefined) { $('#ltms-ep-max-nights').val(d.max_nights); }
                if (d.checkin_time) { $('#ltms-ep-checkin-time').val(d.checkin_time); }
                if (d.checkout_time) { $('#ltms-ep-checkout-time').val(d.checkout_time); }
                if (d.payment_mode) { $('#ltms-ep-payment-mode').val(d.payment_mode); }
                if (d.deposit_pct) { $('#ltms-ep-deposit-pct').val(d.deposit_pct); }
                $('#ltms-ep-deposit-pct-wrap').toggle(d.payment_mode === 'deposit');
                // AUDIT-PROD-044: limpiar atributos de variaciones al abrir el modal Edit
                // (no se devuelven desde get_product para evitar complejidad de parsearlos;
                // el vendor puede redefinirlos y, al guardar, el backend recrea las variaciones).
                $('#ltms-ep-attributes').empty();
                updateEditProductTypeFields();
                LTMS.Modal.open('ltms-modal-edit-product');
            }
        });
    });

    // Imagen modal edición
    $('#ltms-ep-img-preview, #ltms-ep-img-btn').on('click', function(){ $('#ltms-ep-img-input').trigger('click'); });
    $('#ltms-ep-img-input').on('change', function(){
        var file = this.files[0]; if(!file) return;
        var $s = $('#ltms-ep-img-status'); $s.text('Subiendo...');
        var fd = new FormData();
        fd.append('action','ltms_upload_product_image');
        fd.append('nonce', ltmsDashboard.nonce);
        fd.append('image', file);
        $.ajax({ url:ltmsDashboard.ajax_url, method:'POST', data:fd, processData:false, contentType:false,
            success:function(r){ if(r.success){ $('#ltms-ep-image-id').val(r.data.attachment_id); $('#ltms-ep-img-preview').html('<img src="'+r.data.url+'" style="width:100%;height:100%;object-fit:cover;">'); $s.text('✓'); } else { $s.text('Error'); } },
            error:function(){ $s.text('Error'); }
        });
    });

    // Highlight tipo en modal edición
    $(document).on('change','input[name="ltms_ep_tipo"]',function(){
        ['physical','digital','service','booking','restaurant','variable'].forEach(function(t){ $('#ltms-ep-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'}); });
        $('#ltms-ep-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });

    // Guardar cambios edición
    $('#ltms-ep-submit').on('click', function(){
        var name  = $('#ltms-ep-name').val().trim();
        var price = parseFloat($('#ltms-ep-price').val());
        var $n    = $('#ltms-ep-notice');
        if(!name || isNaN(price) || price<=0){ $n.removeClass('ltms-notice-success').addClass('ltms-notice-error').text('Nombre y precio son obligatorios.').show(); return; }
        var $btn = $(this); $btn.prop('disabled',true).text('Guardando...');

        // AUDIT-PROD-044: recopilar atributos de variaciones en edición (paridad con create_product).
        var epVarAttributes = [];
        if ( $('input[name="ltms_ep_tipo"]:checked').val() === 'variable' ) {
            $('#ltms-ep-attributes > div').each(function() {
                var aName = $(this).find('.ltms-ep-attr-name').val();
                var aValues = $(this).find('.ltms-ep-attr-values').val();
                if (aName && aValues) {
                    epVarAttributes.push({
                        name: aName,
                        values: aValues.split('|').map(function(v){ return v.trim(); }).filter(Boolean)
                    });
                }
            });
        }

        $.ajax({
            url: ltmsDashboard.ajax_url, method:'POST',
            data:{
                action:'ltms_update_product', nonce:ltmsDashboard.nonce,
                product_id: $('#ltms-ep-product-id').val(),
                name:name, description:$('#ltms-ep-desc').val(),
                price:price, stock:$('#ltms-ep-stock').val(),
                sale_price:$('#ltms-ep-sale-price').val(),
                short_description:$('#ltms-ep-short-desc').val(),
                sku:$('#ltms-ep-sku').val(),
                tags:$('#ltms-ep-tags').val(),
                shipping_class_id:$('#ltms-ep-shipping-class').val(),
                category_id:$('#ltms-ep-category').val(),
                status:$('#ltms-ep-status').val(),
                image_id:$('#ltms-ep-image-id').val(),
                product_type:$('input[name="ltms_ep_tipo"]:checked').val()||'physical',
                redi_enabled:$('#ltms-ep-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:parseFloat($('#ltms-ep-redi-rate').val())||0,
                // AUDIT-PROD-044: visibilidad + variaciones + booking (paridad con create_product).
                catalog_visibility: $('#ltms-ep-visibility').val() || 'visible',
                variation_attributes: epVarAttributes.length ? JSON.stringify(epVarAttributes) : '',
                booking_type:     $('#ltms-ep-booking-type').val() || 'accommodation',
                min_nights:       parseInt($('#ltms-ep-min-nights').val()) || 1,
                max_nights:       parseInt($('#ltms-ep-max-nights').val()) || 0,
                booking_capacity: parseInt($('#ltms-ep-booking-capacity').val()) || 1,
                checkin_time:     $('#ltms-ep-checkin-time').val() || '15:00',
                checkout_time:    $('#ltms-ep-checkout-time').val() || '11:00',
                payment_mode:     $('#ltms-ep-payment-mode').val() || 'full',
                deposit_pct:      parseFloat($('#ltms-ep-deposit-pct').val()) || 0,
                // AUDIT-PROD-H1 (re-auditoría): download_url (cuando tipo=digital) — paridad con create_product.
                download_url:     $('#ltms-ep-download-url').val() || '',
                download_limit:   $('#ltms-ep-download-limit').val() || '',
                download_expiry:  $('#ltms-ep-download-expiry').val() || '',
            },
            success:function(res){
                $btn.prop('disabled',false).text('Guardar Cambios');
                if(res.success){
                    $n.removeClass('ltms-notice-error').addClass('ltms-notice-success').text('✅ Cambios guardados. Recargando...').show();
                    // v2.9.77 P0-UI-1: Usar loadView en vez de location.reload (SPA).
                    setTimeout(function(){ LTMS.Dashboard.loadView('products', true); }, 1500);
                } else {
                    $n.removeClass('ltms-notice-success').addClass('ltms-notice-error').text(res.data||'Error al guardar.').show();
                }
            },
            error:function(){ $btn.prop('disabled',false).text('Guardar Cambios'); $n.addClass('ltms-notice-error').text('Error de conexión.').show(); }
        });
    });

    // CS-07: Eliminar producto
    // FIX-P1-BATCH-A: native confirm() is a blocking dialog, can't be styled,
    // breaks SPA flow, and is on the CSP no-no list. Replaced with an inline
    // WCAG-compliant modal (mirrors view-envios.php delete modal pattern).
    // State is held in `deleteTarget` while the modal is open.
    var deleteTarget = { pid: null, name: '' };

    function openDeleteProductModal(pid, name) {
        deleteTarget.pid  = pid;
        deleteTarget.name = name;
        $('#ltms-dp-name').text(name);
        $('#ltms-dp-notice').hide().text('');
        if (typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.open === 'function') {
            LTMS.Modal.open('ltms-modal-delete-product');
        } else {
            $('#ltms-modal-delete-product').addClass('ltms-modal-open');
            $('body').addClass('ltms-modal-body-lock');
        }
    }

    function closeDeleteProductModal() {
        if (typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.close === 'function') {
            LTMS.Modal.close('ltms-modal-delete-product');
        } else {
            $('#ltms-modal-delete-product').removeClass('ltms-modal-open');
            $('body').removeClass('ltms-modal-body-lock');
        }
        deleteTarget.pid  = null;
        deleteTarget.name = '';
    }

    $(document).on('click', '.ltms-delete-product-btn', function(){
        var pid  = $(this).data('product-id');
        var name = $(this).data('product-name');
        if (!pid) return;
        openDeleteProductModal(pid, name);
    });

    $('#ltms-dp-confirm').on('click', function(){
        var $btn = $(this);
        var pid  = deleteTarget.pid;
        if (!pid) return;
        $btn.prop('disabled', true).text('');
        $.ajax({
            url: ltmsDashboard.ajax_url, method: 'POST',
            data: { action: 'ltms_delete_product', nonce: ltmsDashboard.nonce, product_id: pid },
            success: function(res){
                $btn.prop('disabled', false).text('');
                if (res.success) {
                    closeDeleteProductModal();
                    if (typeof LTMS !== 'undefined' && LTMS.UX && typeof LTMS.UX.toastSuccess === 'function') {
                        LTMS.UX.toastSuccess('Eliminado', 'El producto fue eliminado correctamente.');
                    }
                    LTMS.Dashboard.loadView('products', true);
                } else {
                    var msg = res.data || '';
                    $('#ltms-dp-notice').text(msg).show();
                    if (typeof LTMS !== 'undefined' && LTMS.UX && typeof LTMS.UX.toastError === 'function') {
                        LTMS.UX.toastError('Error', msg);
                    }
                }
            },
            error: function(){
                $btn.prop('disabled', false).text('');
                $('#ltms-dp-notice').text('').show();
                if (typeof LTMS !== 'undefined' && LTMS.UX && typeof LTMS.UX.toastError === 'function') {
                    LTMS.UX.toastError('Error', 'No se pudo eliminar el producto. Intenta de nuevo.');
                }
            }
        });
    });

    // v2.9.283: botón Volver en vista de productos
    $(document).on('click', '#ltms-products-back-btn', function() {
        if (typeof LTMS !== 'undefined' && LTMS.Dashboard) {
            LTMS.Dashboard.loadView('home');
        }
    });

    // ── PROD-01: Campos condicionales según tipo de producto ─────
    // v2.9.283 FIX: mover DENTRO del IIFE para que $ esté disponible.
    // v2.9.285: añadir soporte para booking (turismo) fields.
    // AUDIT-PROD-044: añadir soporte para 'variable' en ambos modales (New + Edit).
    function updateProductTypeFields() {
        var tipo = $('input[name="ltms_np_tipo"]:checked').val() || 'physical';
        var showPhysical = (tipo === 'physical' || tipo === 'restaurant');
        $('#ltms-np-physical-fields').toggle(showPhysical);
        $('#ltms-np-digital-fields').toggle(tipo === 'digital');
        // v2.9.285: mostrar campos de booking/turismo
        $('#ltms-np-booking-fields').toggle(tipo === 'booking');
        // AUDIT-PROD-044: mostrar campos de variaciones
        $('#ltms-np-variable-fields').toggle(tipo === 'variable');
        var showStock = (tipo === 'physical' || tipo === 'restaurant');
        $('#ltms-np-stock').closest('div').toggle(showStock);
    }

    // AUDIT-PROD-044: campos condicionales para modal Edit (mismo patrón que New).
    // AUDIT-PROD-H1 (re-auditoría): también mostrar/ocultar el bloque digital.
    function updateEditProductTypeFields() {
        var tipo = $('input[name="ltms_ep_tipo"]:checked').val() || 'physical';
        var showPhysical = (tipo === 'physical' || tipo === 'restaurant');
        $('#ltms-ep-digital-fields').toggle(tipo === 'digital');
        $('#ltms-ep-booking-fields').toggle(tipo === 'booking');
        $('#ltms-ep-variable-fields').toggle(tipo === 'variable');
    }

    $(function() {
        $('input[name="ltms_np_tipo"]').on('change', updateProductTypeFields);
        updateProductTypeFields();
        $('input[name="ltms_ep_tipo"]').on('change', function(){
            ['physical','digital','service','booking','restaurant','variable'].forEach(function(t){
                $('#ltms-ep-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
            });
            $('#ltms-ep-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
            updateEditProductTypeFields();
        });
        // v2.9.285: toggle depósito % según modo de pago (modal New)
        $('#ltms-np-payment-mode').on('change', function() {
            $('#ltms-np-deposit-pct-wrap').toggle($(this).val() === 'deposit');
        });
        // AUDIT-PROD-044: toggle depósito % en modal Edit.
        $('#ltms-ep-payment-mode').on('change', function() {
            $('#ltms-ep-deposit-pct-wrap').toggle($(this).val() === 'deposit');
        });
        // AUDIT-PROD-044: boton "+ Agregar atributo" para variaciones (modal New).
        var npAttrCount = 0;
        $(document).on('click', '#ltms-np-add-attribute', function() {
            npAttrCount++;
            var attrId = 'ltms-np-attr-' + npAttrCount;
            var html = '<div id="' + attrId + '" style="margin-bottom:10px;padding:10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;">' +
                '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">' +
                '<input type="text" class="ltms-np-attr-name" placeholder="Nombre (ej: Talla)" style="flex:1;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;">' +
                '<button type="button" class="ltms-np-attr-remove" style="padding:4px 8px;border:none;background:#fee;color:#c00;border-radius:4px;cursor:pointer;font-size:0.8rem;">✕</button>' +
                '</div>' +
                '<input type="text" class="ltms-np-attr-values" placeholder="Valores separados por | (ej: S|M|L|XL)" style="width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;">' +
                '</div>';
            $('#ltms-np-attributes').append(html);
        });
        $(document).on('click', '.ltms-np-attr-remove', function() {
            $(this).closest('div').parent().remove();
        });
        // AUDIT-PROD-044: boton "+ Agregar atributo" para variaciones (modal Edit).
        var epAttrCount = 0;
        $(document).on('click', '#ltms-ep-add-attribute', function() {
            epAttrCount++;
            var attrId = 'ltms-ep-attr-' + epAttrCount;
            var html = '<div id="' + attrId + '" style="margin-bottom:10px;padding:10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;">' +
                '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">' +
                '<input type="text" class="ltms-ep-attr-name" placeholder="Nombre (ej: Talla)" style="flex:1;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;">' +
                '<button type="button" class="ltms-ep-attr-remove" style="padding:4px 8px;border:none;background:#fee;color:#c00;border-radius:4px;cursor:pointer;font-size:0.8rem;">✕</button>' +
                '</div>' +
                '<input type="text" class="ltms-ep-attr-values" placeholder="Valores separados por | (ej: S|M|L|XL)" style="width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;">' +
                '</div>';
            $('#ltms-ep-attributes').append(html);
        });
        $(document).on('click', '.ltms-ep-attr-remove', function() {
            $(this).closest('div').parent().remove();
        });
    });

})(jQuery);
