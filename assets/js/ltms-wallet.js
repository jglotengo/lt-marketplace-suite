/**
 * LTMS Wallet — deposit receipt upload + deposit request.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-wallet.php.
 */
(function ($) {
    'use strict';

    var depositReceiptUrl = '';

    $('#ltms-deposit-receipt').on('change', function () {
        var file = this.files[0];
        if (!file) return;
        var statusEl = $('#ltms-deposit-receipt-status');
        statusEl.text('Subiendo comprobante...');
        var formData = new FormData();
        formData.append('action', 'ltms_upload_receipt');
        formData.append('nonce', ltmsDashboard.nonce);
        formData.append('receipt', file);
        $.ajax({
            url: ltmsDashboard.ajax_url, type: 'POST', data: formData,
            processData: false, contentType: false,
            success: function (res) {
                if (res.success) {
                    depositReceiptUrl = res.data.url;
                    statusEl.css('color', '#27ae60').text('✅ Comprobante subido correctamente.');
                } else {
                    statusEl.css('color', '#e74c3c').text('❌ Error: ' + (res.data || 'No se pudo subir.'));
                }
            },
            error: function () { statusEl.css('color', '#e74c3c').text('❌ Error de conexión.'); }
        });
    });

    $('#ltms-deposit-submit').on('click', function () {
        var amount = parseFloat($('#ltms-deposit-amount').val());
        var method = $('#ltms-deposit-method').val();
        var reference = $('#ltms-deposit-reference').val().trim();
        var notes = $('#ltms-deposit-notes').val().trim();
        var errEl = $('.ltms-deposit-error');
        var okEl = $('.ltms-deposit-success');
        var btn = $(this);
        errEl.hide(); okEl.hide();
        if (!amount || amount <= 0) { errEl.text('Ingresa un monto válido.').show(); return; }
        btn.prop('disabled', true).text('Enviando...');
        $.post(ltmsDashboard.ajax_url, {
            action: 'ltms_create_deposit', nonce: ltmsDashboard.nonce,
            amount: amount, method: method, reference: reference,
            receipt_url: depositReceiptUrl, notes: notes
        }, function (res) {
            if (res.success) {
                okEl.text(res.data.message).show();
                $('#ltms-deposit-amount, #ltms-deposit-reference, #ltms-deposit-notes').val('');
                $('#ltms-deposit-receipt').val('');
                $('#ltms-deposit-receipt-status').text('');
                depositReceiptUrl = '';
                btn.text('✅ Solicitud enviada');
                setTimeout(function () { LTMS.Dashboard.loadView('wallet', true); }, 3000);
            } else {
                errEl.text(res.data || 'Error desconocido.').show();
                btn.prop('disabled', false).text('Enviar Solicitud de Depósito');
            }
        }).fail(function () {
            errEl.text('Error de conexión.').show();
            btn.prop('disabled', false).text('Enviar Solicitud de Depósito');
        });
    });
})(jQuery);
