/**
 * LTMS view-settings — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
// v2.9.99 P1 FIX: manejar checkboxes correctamente (antes .val() siempre enviaba 'yes').
jQuery('#ltms-save-settings-btn').on('click', function() {
    var settings = {};
    jQuery('[name^="ltms_"]').each(function() {
        var $el = jQuery(this);
        var name = $el.attr('name');
        if ( $el.is(':checkbox') ) {
            // Solo incluir si está marcado; el server usa isset() para decidir.
            if ( $el.is(':checked') ) {
                settings[name] = $el.val() || 'yes';
            }
            // Si no está marcado, NO se incluye en settings → el server lo interpreta como 'no'.
        } else if ( $el.is(':radio') ) {
            if ( $el.is(':checked') ) {
                settings[name] = $el.val();
            }
        } else {
            settings[name] = $el.val();
        }
    });

    var $btn = jQuery(this).prop('disabled', true).text('Guardando…');
    jQuery.ajax({
        url: ltmsDashboard.ajax_url,
        method: 'POST',
        data: { action: 'ltms_save_vendor_settings', nonce: ltmsDashboard.nonce, settings: settings },
        success: function(response) {
            $btn.prop('disabled', false).text('Guardar Configuración');
            var $notice = jQuery('#ltms-settings-notice');
            if (response.success) {
                $notice.attr('class', 'ltms-form-notice ltms-notice-success').text(response.data.message).show();
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                    LTMS.UX.toastSuccess('Guardado', 'Configuración actualizada.');
                }
            } else {
                $notice.attr('class', 'ltms-form-notice ltms-notice-error').text(response.data).show();
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                    LTMS.UX.toastError('Error', response.data || 'No se pudo guardar.');
                }
            }
            setTimeout(function() { $notice.fadeOut(); }, 4000);
        },
        error: function() {
            $btn.prop('disabled', false).text('Guardar Configuración');
            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                LTMS.UX.toastError('Error', 'Error de red.');
            }
        }
    });
});

// v2.3.0 — Guardar solo campos de analytics
jQuery('#ltms-save-analytics-btn').on('click', function() {
    var settings = {};
    jQuery('[name="ltms_vendor_ga4_id"], [name="ltms_vendor_pixel_id"]').each(function() {
        settings[jQuery(this).attr('name')] = jQuery(this).val();
    });
    var $btn    = jQuery(this).prop('disabled', true).text('Guardando…');
    var $notice = jQuery('#ltms-analytics-notice');
    jQuery.ajax({
        url: ltmsDashboard.ajax_url,
        method: 'POST',
        data: { action: 'ltms_save_vendor_settings', nonce: ltmsDashboard.nonce, settings: settings },
        success: function(response) {
            if (response.success) {
                $notice.css('color','#16a34a').text('✅ Guardado').show();
            } else {
                $notice.css('color','#dc2626').text('❌ Error al guardar').show();
            }
            setTimeout(function() { $notice.fadeOut(); }, 3000);
        },
        complete: function() { $btn.prop('disabled', false).text('💾 Guardar Analytics'); }
    });
});
// v2.9.99 P1 FIX: handlers para botones que faltaban (KYC, logo, copy-referral).
(function($) {
    // 1. Botón "Completar KYC" → navegar a la vista KYC del SPA.
    $(document).on('click', '[data-action="goto-kyc"]', function() {
        if (typeof LTMS !== 'undefined' && LTMS.Dashboard && LTMS.Dashboard.loadView) {
            LTMS.Dashboard.loadView('kyc');
        } else {
            // Fallback: redirigir a la URL del shortcode si el SPA no está disponible.
            window.location.href = '/mi-tienda/?view=kyc';
        }
    });

    // 2. Subir logo vía WP media uploader (con fallback a input file si wp.media no está cargado).
    $('#ltms-upload-logo-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true).text('Subiendo…');
        if (typeof wp !== 'undefined' && wp.media) {
            var frame = wp.media({
                title: 'Seleccionar logo de tienda',
                button: { text: 'Usar como logo' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#ltms-store-logo-id').val(attachment.id);
                var previewUrl = attachment.url;
                if (attachment.sizes && attachment.sizes.thumbnail) {
                    previewUrl = attachment.sizes.thumbnail.url;
                }
                $('#ltms-logo-preview').html('<img src="' + previewUrl + '" alt="Logo" style="width:100%;height:100%;object-fit:cover;">');
                $btn.prop('disabled', false).text('Subir logo');
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                    LTMS.UX.toastSuccess('Logo cargado', 'No olvides guardar los cambios.');
                }
            });
            frame.on('close', function() {
                $btn.prop('disabled', false).text('Subir logo');
            });
            frame.open();
        } else {
            // Fallback: file input + AJAX upload propio.
            var $input = $('<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">');
            $input.appendTo('body').on('change', function() {
                var file = this.files[0];
                if (!file) { $btn.prop('disabled', false).text('Subir logo'); return; }
                if (file.size > 2 * 1024 * 1024) {
                    $btn.prop('disabled', false).text('Subir logo');
                    if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                        LTMS.UX.toastError('Error', 'El archivo excede 2MB.');
                    }
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'ltms_upload_store_logo');
                fd.append('nonce', ltmsDashboard.nonce);
                fd.append('file', file);
                $.ajax({
                    url: ltmsDashboard.ajax_url,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        $btn.prop('disabled', false).text('Subir logo');
                        if (resp.success) {
                            $('#ltms-store-logo-id').val(resp.data.attachment_id);
                            $('#ltms-logo-preview').html('<img src="' + resp.data.url + '" alt="Logo" style="width:100%;height:100%;object-fit:cover;">');
                            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                                LTMS.UX.toastSuccess('Logo cargado', 'No olvides guardar los cambios.');
                            }
                        } else {
                            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                                LTMS.UX.toastError('Error', resp.data || 'No se pudo subir.');
                            }
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Subir logo');
                        if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                            LTMS.UX.toastError('Error', 'Error de red.');
                        }
                    }
                });
            });
            $input.click();
            setTimeout(function() { $input.remove(); }, 60000);
        }
    });

    // 3. Quitar logo (limpiar el hidden field + preview).
    $('#ltms-remove-logo-btn').on('click', function(e) {
        e.preventDefault();
        $('#ltms-store-logo-id').val('');
        $('#ltms-logo-preview').html('<span style="font-size:1.5rem;color:#d1d5db;">📷</span>');
        if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
            LTMS.UX.toastSuccess('Logo quitado', 'No olvides guardar los cambios.');
        }
    });

    // 4. Copy referral code (arregla bug: antes buscaba <input> pero el código está en <code>).
    $(document).on('click', '[data-action="copy-referral"]', function(e) {
        e.preventDefault();
        var $code = $(this).closest('.ltms-card').find('code');
        if (!$code.length) return;
        var text = $code.text().trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                    LTMS.UX.toastSuccess('Copiado', 'Código: ' + text);
                }
            }).catch(function() {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    });

    function fallbackCopy(text) {
        var $temp = $('<input>').val(text).appendTo('body').select();
        try {
            document.execCommand('copy');
            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastSuccess) {
                LTMS.UX.toastSuccess('Copiado', 'Código: ' + text);
            }
        } catch(err) {
            if (typeof LTMS !== 'undefined' && LTMS.UX && LTMS.UX.toastError) {
                LTMS.UX.toastError('Error', 'No se pudo copiar.');
            }
        }
        $temp.remove();
    }
})(jQuery);
