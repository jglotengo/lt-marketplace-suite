/**
 * LTMS Security — 2FA setup/confirm/disable modal handlers.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-security.php.
 */
(function ($) {
    'use strict';

    var nonce = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce) || '';
    var ajaxUrl = (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) || ajaxurl;

    function showError(sel, msg) { $(sel).text(msg).show(); }

    function openModal(id) {
        if (typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.open === 'function') { LTMS.Modal.open(id); }
        else { $('#' + id).css('display', 'flex').attr('aria-hidden', 'false'); }
    }
    function closeModal(id) {
        if (typeof LTMS !== 'undefined' && LTMS.Modal && typeof LTMS.Modal.close === 'function') { LTMS.Modal.close(id); }
        else { $('#' + id).css('display', 'none').attr('aria-hidden', 'true'); }
    }

    $('#ltms-setup-2fa-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Generando...');
        $.post(ajaxUrl, { action: 'ltms_setup_2fa', nonce: nonce }).done(function (resp) {
            $btn.prop('disabled', false).html('🔐 Activar 2FA');
            if (resp.success) {
                $('#ltms-2fa-qr-img').attr('src', resp.data.qr_url);
                $('#ltms-2fa-secret').text(resp.data.secret);
                $('#ltms-2fa-error').hide();
                $('#ltms-2fa-verify-code').val('');
                openModal('ltms-2fa-setup-modal');
            } else { LTMS.UX.toastError('Error', resp.data.message || 'Error al generar código 2FA'); }
        }).fail(function () {
            $btn.prop('disabled', false).html('🔐 Activar 2FA');
            LTMS.UX.toastError('Error', 'Error de red. Intenta de nuevo.');
        });
    });

    $('#ltms-2fa-confirm-btn').on('click', function () {
        var code = $('#ltms-2fa-verify-code').val().trim();
        if (!/^\d{6}$/.test(code)) { showError('#ltms-2fa-error', 'El código debe tener 6 dígitos'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Verificando...');
        $.post(ajaxUrl, { action: 'ltms_confirm_2fa', nonce: nonce, code: code }).done(function (resp) {
            $btn.prop('disabled', false).html('✓ Confirmar y activar');
            if (resp.success) {
                closeModal('ltms-2fa-setup-modal');
                LTMS.UX.toastSuccess('Éxito', '2FA activado. Guarda tus códigos de respaldo.');
                LTMS.Dashboard.loadView('security', true);
            } else { showError('#ltms-2fa-error', resp.data.message || 'Código incorrecto'); }
        }).fail(function () {
            $btn.prop('disabled', false).html('✓ Confirmar y activar');
            showError('#ltms-2fa-error', 'Error de red. Intenta de nuevo.');
        });
    });

    $('#ltms-2fa-cancel-btn, #ltms-2fa-close-modal').on('click', function () { closeModal('ltms-2fa-setup-modal'); });

    $('#ltms-disable-2fa-btn').on('click', function () {
        $('#ltms-2fa-disable-error').hide();
        $('#ltms-2fa-disable-code').val('');
        openModal('ltms-2fa-disable-modal');
    });

    $('#ltms-2fa-disable-confirm-btn').on('click', function () {
        var code = $('#ltms-2fa-disable-code').val().trim();
        if (!code) { showError('#ltms-2fa-disable-error', 'Ingresa un código'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Desactivando...');
        $.post(ajaxUrl, { action: 'ltms_disable_2fa', nonce: nonce, code: code }).done(function (resp) {
            $btn.prop('disabled', false).html('Desactivar');
            if (resp.success) {
                closeModal('ltms-2fa-disable-modal');
                LTMS.UX.toastSuccess('Éxito', '2FA desactivado.');
                LTMS.Dashboard.loadView('security', true);
            } else { showError('#ltms-2fa-disable-error', resp.data.message || 'Código incorrecto'); }
        }).fail(function () {
            $btn.prop('disabled', false).html('Desactivar');
            showError('#ltms-2fa-disable-error', 'Error de red. Intenta de nuevo.');
        });
    });

    $('#ltms-2fa-disable-cancel-btn').on('click', function () { closeModal('ltms-2fa-disable-modal'); });
})(jQuery);
