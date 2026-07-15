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
                   .text('')
                   .show();
            return;
        }

        const $btn     = $(this);
        const origText = $btn.html();
        $btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:       'ltms_create_product',
                nonce:        ltmsDashboard.nonce,
                name:         name,
                description:  $('#ltms-np-desc').val(),
                price:        price,
                stock:        $('#ltms-np-stock').val(),
                category_id:  $('#ltms-np-category').val(),
                image_id:     $('#ltms-np-image-id').val(),
                gallery_ids:  $('#ltms-np-gallery-ids').val(),
                status:       $('#ltms-np-status').val(),
                product_type:    $('input[name="ltms_np_tipo"]:checked').val() || 'physical',
                redi_enabled:    $('#ltms-np-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:       parseFloat($('#ltms-np-redi-rate').val()) || 0,
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

    // ── Botones del estado inicial PHP → SPA fallback ────────────
    $(document).on('click', '#ltms-add-product-btn, #ltms-add-product-btn-empty', function(e){
        e.preventDefault();
        if (typeof LTMS !== 'undefined' && LTMS.Dashboard && typeof LTMS.Dashboard.loadNewProductView === 'function') {
            LTMS.Dashboard.loadNewProductView();
        } else {
            LTMS.Modal.open('ltms-modal-new-product');
        }
    });

    // ── Limpiar modal al cerrar ───────────────────────────────────
    $(document).on('click', '.ltms-modal-backdrop, .ltms-modal-close', function(){
        $('#ltms-np-notice').hide().text('');
        $('#ltms-np-name, #ltms-np-desc, #ltms-np-stock, #ltms-np-price').val('');
        $('#ltms-np-image-id').val('');
        $('#ltms-np-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
        $('#ltms-np-img-status').text('');
        $('input[name="ltms_np_tipo"][value="physical"]').prop('checked', true);
        // CS-04: reset visual de los 4 tipos
        ['physical','digital','service','booking'].forEach(function(t){
            $('#ltms-np-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        });
        $('#ltms-np-tipo-physical-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });

    // CS-04/CS-06: highlight para los 4 tipos
    $(document).on('change', 'input[name="ltms_np_tipo"]', function(){
        ['physical','digital','service','booking'].forEach(function(t){
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
        $('#ltms-ep-name,#ltms-ep-desc,#ltms-ep-price,#ltms-ep-stock').val('');
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
        ['physical','digital','service','booking'].forEach(function(t){ $('#ltms-ep-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'}); });
        $('#ltms-ep-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });

    // Guardar cambios edición
    $('#ltms-ep-submit').on('click', function(){
        var name  = $('#ltms-ep-name').val().trim();
        var price = parseFloat($('#ltms-ep-price').val());
        var $n    = $('#ltms-ep-notice');
        if(!name || isNaN(price) || price<=0){ $n.removeClass('ltms-notice-success').addClass('ltms-notice-error').text('Nombre y precio son obligatorios.').show(); return; }
        var $btn = $(this); $btn.prop('disabled',true).text('Guardando...');
        $.ajax({
            url: ltmsDashboard.ajax_url, method:'POST',
            data:{
                action:'ltms_update_product', nonce:ltmsDashboard.nonce,
                product_id: $('#ltms-ep-product-id').val(),
                name:name, description:$('#ltms-ep-desc').val(),
                price:price, stock:$('#ltms-ep-stock').val(),
                category_id:$('#ltms-ep-category').val(),
                status:$('#ltms-ep-status').val(),
                image_id:$('#ltms-ep-image-id').val(),
                product_type:$('input[name="ltms_ep_tipo"]:checked').val()||'physical',
                redi_enabled:$('#ltms-ep-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:parseFloat($('#ltms-ep-redi-rate').val())||0,
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
                        LTMS.UX.toastSuccess('', '');
                    }
                    LTMS.Dashboard.loadView('products', true);
                } else {
                    var msg = res.data || '';
                    $('#ltms-dp-notice').text(msg).show();
                    if (typeof LTMS !== 'undefined' && LTMS.UX && typeof LTMS.UX.toastError === 'function') {
                        LTMS.UX.toastError('', msg);
                    }
                }
            },
            error: function(){
                $btn.prop('disabled', false).text('');
                $('#ltms-dp-notice').text('').show();
                if (typeof LTMS !== 'undefined' && LTMS.UX && typeof LTMS.UX.toastError === 'function') {
                    LTMS.UX.toastError('', '');
                }
            }
        });
    });

})(jQuery);
