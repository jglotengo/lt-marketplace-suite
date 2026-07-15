/**
 * LTMS view-aveonline-onboarding — extracted from inline <script>.
 * FASE2B P0 FIX (CSP): moved to external file for CSP compliance.
 */
(function($) {
    var nonce = '';
    var currentStep = 0;

    function showStep(step) {
        $('.ltms-ave-step').hide();
        $('.ltms-ave-step[data-step="' + step + '"]').show();
    }

    function showError(msg) {
        $('#ave-ob-error-text').text(msg);
        $('#ave-ob-error').show();
        $('#ave-ob-loading').hide();
    }

    function showLoading(text) {
        $('#ave-ob-loading-text').text(text || 'Procesando...');
        $('#ave-ob-loading').show();
        $('#ave-ob-error').hide();
    }

    function hideLoading() {
        $('#ave-ob-loading').hide();
    }

    // Toggle persona natural/jurídica
    $('#ave-ob-doc-type').on('change', function() {
        var isJuridica = $(this).val() === '3';
        $('#ave-ob-natural-fields').toggle(!isJuridica);
        $('#ave-ob-juridica-fields').toggle(isJuridica);
    });

    // Helper: convert file to base64
    function fileToBase64(inputId) {
        var file = $('#' + inputId)[0];
        if (!file || !file.files[0]) return null;
        return new Promise(function(resolve) {
            var reader = new FileReader();
            reader.onload = function(e) { resolve({ base64: e.target.result, name: file.files[0].name }); };
            reader.readAsDataURL(file.files[0]);
        });
    }

    // Paso 1
    $('#ave-ob-step1-btn').on('click', function() {
        showLoading('Aceptando términos...');
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_aveonline_onboarding_step1',
            nonce: nonce,
            email: $('#ave-ob-email').val(),
            phone: $('#ave-ob-phone').val()
        }, function(r) {
            hideLoading();
            if (r.success) { showStep(2); } else { showError(r.data || 'Error'); }
        }).fail(function() { hideLoading(); showError('Error de conexión'); });
    });

    // Paso 2
    $('#ave-ob-step2-btn').on('click', function() {
        showLoading('Creando cuenta...');
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_aveonline_onboarding_step2',
            nonce: nonce,
            name: $('#ave-ob-name').val(),
            email: $('#ave-ob-email').val(),
            phone: $('#ave-ob-phone').val(),
            password: $('#ave-ob-password').val()
        }, function(r) {
            hideLoading();
            if (r.success) { showStep(3); } else { showError(r.data || 'Error'); }
        }).fail(function() { hideLoading(); showError('Error de conexión'); });
    });

    // Paso 3
    $('#ave-ob-step3-btn').on('click', async function() {
        showLoading('Subiendo documentos...');
        var isJuridica = $('#ave-ob-doc-type').val() === '3';
        var data = {
            action: 'ltms_aveonline_onboarding_step3',
            nonce: nonce,
            documentType: $('#ave-ob-doc-type').val(),
            idDocument: $('#ave-ob-id-document').val(),
            phone: $('#ave-ob-phone').val()
        };

        if (isJuridica) {
            data.businessName = $('#ave-ob-business-name').val();
            data.nombrelegal = $('#ave-ob-nombre-legal').val();
            data.cedulalegal = $('#ave-ob-cedula-legal').val();
            data.rut = await fileToBase64('ave-ob-rut');
            data.camara_comercio = await fileToBase64('ave-ob-camara');
        } else {
            data.fullName = $('#ave-ob-full-name').val();
            data.lastname = $('#ave-ob-lastname').val();
            data.cedulaFront = await fileToBase64('ave-ob-cedula-front');
            data.cedulaBack = await fileToBase64('ave-ob-cedula-back');
        }

        $.post(ltmsDashboard.ajax_url, data, function(r) {
            hideLoading();
            if (r.success) { showStep(4); } else { showError(r.data || 'Error'); }
        }).fail(function() { hideLoading(); showError('Error de conexión'); });
    });

    // Paso 4
    $('#ave-ob-step4-btn').on('click', function() {
        showLoading('Completando registro...');
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_aveonline_onboarding_step4',
            nonce: nonce,
            tradename: $('#ave-ob-tradename').val(),
            address: $('#ave-ob-address').val(),
            city: $('#ave-ob-city').val()
        }, function(r) {
            hideLoading();
            if (r.success) {
                $('.ltms-ave-step').hide();
                $('#ave-ob-success').show();
                setTimeout(function() { LTMS.Dashboard.loadView('envios', true); }, 2000);
            } else { showError(r.data || 'Error'); }
        }).fail(function() { hideLoading(); showError('Error de conexión'); });
    });
})(jQuery);
