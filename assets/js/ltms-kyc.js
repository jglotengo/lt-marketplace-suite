/**
 * LTMS KYC — document upload + submit handler.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-kyc.php.
 * Uses ltmsDashboard.nonce and ltmsDashboard.ajax_url (already localized).
 */
(function ($) {
    'use strict';
    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;
    var $notice = $('#ltms-kyc-notice');

    function showNotice(msg, type) {
        var bg = type === 'success' ? '#f0fdf4' : '#fef2f2';
        var color = type === 'success' ? '#16a34a' : '#dc2626';
        $notice.css({ background: bg, color: color, border: '1px solid ' + color + '44' }).text(msg).show();
        if (type === 'success') setTimeout(function () { $notice.hide(); }, 4000);
    }

    function uploadSingleDoc(inputId, statusId, pathId, label, callback) {
        var file = $('#' + inputId)[0] && $('#' + inputId)[0].files[0];
        if (!file) { callback(null); return; }
        var $status = $('#' + statusId);
        $status.text('Subiendo ' + label + '...').show();
        var fd = new FormData();
        fd.append('action', 'ltms_upload_kyc_document');
        fd.append('nonce', nonce);
        fd.append('kyc_doc', file);
        $.ajax({ url: ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false,
            success: function (r) {
                if (r.success) {
                    $status.text(label + ' subido ✓');
                    $('#' + pathId).val(r.data.file_path || r.data.vault_url || '');
                    callback(r.data.file_path || r.data.vault_url || '');
                } else { $status.text('Error ' + label + ': ' + (r.data || 'intente de nuevo')); callback(null); }
            },
            error: function () { $status.text('Error de conexión al subir ' + label + '.'); callback(null); }
        });
    }

    function uploadDocument(callback) {
        uploadSingleDoc('ltms-kyc-file', 'ltms-kyc-upload-status', 'ltms-kyc-file-path', 'Cédula/NIT', function (cedula) {
            uploadSingleDoc('ltms-kyc-file-rut', 'ltms-kyc-status-rut', 'ltms-kyc-path-rut', 'RUT', function (rut) {
                uploadSingleDoc('ltms-kyc-file-camara', 'ltms-kyc-status-camara', 'ltms-kyc-path-camara', 'Cámara de Comercio', function (camara) {
                    uploadSingleDoc('ltms-kyc-file-banco', 'ltms-kyc-status-banco', 'ltms-kyc-path-banco', 'Certificación Bancaria', function (banco) {
                        callback(cedula, rut, camara, banco);
                    });
                });
            });
        });
    }

    $('#ltms-kyc-submit-btn').on('click', function () {
        var fullName = $.trim($('#ltms-kyc-full-name').val());
        var docType = $('#ltms-kyc-doc-type').val();
        var docNumber = $.trim($('#ltms-kyc-doc-number').val());
        if (!fullName || !docNumber) { showNotice('Por favor completa todos los campos obligatorios.', 'error'); return; }
        var cedulaFile = $('#ltms-kyc-file')[0] && $('#ltms-kyc-file')[0].files[0];
        if (!cedulaFile && !$('#ltms-kyc-file-path').val()) { showNotice('Debes subir tu documento de identidad (cédula/INE/pasaporte).', 'error'); return; }
        var repLegalName = $.trim($('#ltms-kyc-rep-legal-name').val());
        var bankName = $.trim($('#ltms-kyc-bank-name').val());
        var accountNumber = $.trim($('#ltms-kyc-account-number').val());
        var accountType = $('#ltms-kyc-account-type').val() || 'ahorros';
        var bancoFile = $('#ltms-kyc-file-banco')[0] && $('#ltms-kyc-file-banco')[0].files[0];
        if (!repLegalName || !bankName || !accountNumber || !bancoFile) { showNotice('La certificación bancaria es obligatoria.', 'error'); return; }
        if (!$('#ltms-kyc-consent').is(':checked')) { showNotice('Debes aceptar la autorización de tratamiento de datos.', 'error'); return; }
        var sanitaryReg = $.trim($('input[name="ltms_sanitary_registration"]').val() || '');
        var sanitaryExpires = $.trim($('input[name="ltms_sanitary_registration_expires"]').val() || '');
        if (sanitaryReg && !sanitaryExpires) { showNotice('La fecha de vencimiento del registro sanitario es obligatoria.', 'error'); return; }
        var fiscalRegimeMx = $.trim($('#ltms-kyc-fiscal-regime-mx').val() || '');
        var domicilioFiscalMx = $.trim($('#ltms-kyc-domicilio-fiscal-mx').val() || '');

        var $btn = $(this).prop('disabled', true).text('Procesando...');
        uploadDocument(function (cedulaPath, rutPath, camaraPath, bancoPath) {
            if (!cedulaPath && !$('#ltms-kyc-file-path').val()) { $btn.prop('disabled', false).text('Enviar para Verificación'); showNotice('Error al subir el documento de identidad.', 'error'); return; }
            if (!bancoPath) { $btn.prop('disabled', false).text('Enviar para Verificación'); showNotice('Error al subir la certificación bancaria.', 'error'); return; }
            var filePath = cedulaPath || $('#ltms-kyc-file-path').val() || '';
            $.ajax({ url: ajaxUrl, method: 'POST',
                data: {
                    action: 'ltms_submit_kyc', nonce: nonce,
                    full_name: fullName, document_type: docType, document_number: docNumber,
                    file_path: filePath, file_path_rut: rutPath || '', file_path_camara: camaraPath || '', file_path_banco: bancoPath || '',
                    bank_rep_legal_name: repLegalName, bank_name: bankName, bank_account_number: accountNumber, bank_account_type: accountType,
                    privacy_consent: '1', consent_ts: new Date().toISOString(),
                    sanitary_registration: sanitaryReg, sanitary_registration_expires: sanitaryExpires,
                    fiscal_regime_mx: fiscalRegimeMx, domicilio_fiscal_mx: domicilioFiscalMx
                },
                success: function (r) {
                    $btn.prop('disabled', false).text('Enviar para Verificación');
                    if (r.success) { showNotice('✓ Solicitud enviada. Te notificaremos por correo cuando sea revisada.', 'success'); setTimeout(function () { LTMS.Dashboard.loadView('home', true); }, 2500); }
                    else { showNotice('Error: ' + (r.data || 'intente de nuevo'), 'error'); }
                },
                error: function () { $btn.prop('disabled', false).text('Enviar para Verificación'); showNotice('Error de conexión. Intenta de nuevo.', 'error'); }
            });
        });
    });
})(jQuery);
