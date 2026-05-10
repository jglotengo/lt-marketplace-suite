/**
 * LT Marketplace Suite - Login / Register JavaScript
 * Manejo de formularios de autenticación del frontend
 * Version: 1.5.0
 */

/* global ltmsAuth, jQuery */

(function ($) {
    'use strict';

    window.LTMS = window.LTMS || {};

    /**
     * LTMS.Auth - Módulo de autenticación del frontend
     */
    LTMS.Auth = {

        init() {
            this.bindLoginForm();
            this.bindRegisterForm();
            this.initPasswordStrength();
            this.initTogglePassword();
        },

        bindLoginForm() {
            $(document).on('submit', '#ltms-login-form', function (e) {
                e.preventDefault();
                const $form = $(this);
                const $btn  = $form.find('[type="submit"]');

                $btn.prop('disabled', true).text(ltmsAuth.i18n.processing);

                $.ajax({
                    url: ltmsAuth.ajax_url,
                    method: 'POST',
                    data: {
                        action:   'ltms_vendor_login',
                        nonce:    ltmsAuth.nonce,
                        username: $form.find('[name="username"]').val(),
                        password: $form.find('[name="password"]').val(),
                        remember: $form.find('[name="rememberme"]').is(':checked'),
                    },
                    success(response) {
                        $btn.prop('disabled', false).text('Iniciar Sesión');
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            LTMS.Auth.showFormError('#ltms-login-form', response.data);
                        }
                    },
                    error() {
                        $btn.prop('disabled', false);
                        LTMS.Auth.showFormError('#ltms-login-form', 'Error de conexión. Intenta de nuevo.');
                    },
                });
            });
        },

        bindRegisterForm() {
            // ── Wizard: Siguiente ──────────────────────────────────────────────
            $(document).on('click', '.ltms-wizard-next', function () {
                const $btn      = $(this);
                const nextPage  = parseInt($btn.data('next'), 10);
                const $form     = $btn.closest('form');
                const $curPage  = $btn.closest('.ltms-wizard-page');

                // Validate required fields in current page before advancing
                let valid = true;
                $curPage.find('[required]').each(function () {
                    const $field = $(this);
                    if ($field.is(':checkbox')) {
                        if (!$field.is(':checked')) { valid = false; $field.closest('.ltms-form-group').addClass('ltms-field-error'); }
                        else { $field.closest('.ltms-form-group').removeClass('ltms-field-error'); }
                    } else {
                        if (!$field.val().trim()) { valid = false; $field.addClass('ltms-input-error'); }
                        else { $field.removeClass('ltms-input-error'); }
                    }
                });

                if (!valid) {
                    LTMS.Auth.showFormError('#ltms-register-form', ltmsAuth.i18n.required_fields || 'Por favor completa todos los campos requeridos.');
                    return;
                }

                // Hide all pages, show target page
                $form.find('.ltms-wizard-page').hide();
                $form.find('.ltms-wizard-page[data-page="' + nextPage + '"]').show();

                // Update step indicators
                $form.closest('.ltms-auth-card').find('.ltms-step').each(function () {
                    const step = parseInt($(this).data('step'), 10);
                    $(this).toggleClass('active', step === nextPage);
                    $(this).toggleClass('completed', step < nextPage);
                });
            });

            // ── Wizard: Atrás ──────────────────────────────────────────────────
            $(document).on('click', '.ltms-wizard-back', function () {
                const $btn     = $(this);
                const prevPage = parseInt($btn.data('back'), 10);
                const $form    = $btn.closest('form');

                $form.find('.ltms-wizard-page').hide();
                $form.find('.ltms-wizard-page[data-page="' + prevPage + '"]').show();

                $form.closest('.ltms-auth-card').find('.ltms-step').each(function () {
                    const step = parseInt($(this).data('step'), 10);
                    $(this).toggleClass('active', step === prevPage);
                    $(this).removeClass('completed');
                });
            });

            // ── Submit ─────────────────────────────────────────────────────────
            $(document).on('submit', '#ltms-register-form', function (e) {
                e.preventDefault();
                const $form = $(this);

                // Validar contraseñas
                const pass1 = $form.find('[name="password"]').val();
                const pass2 = $form.find('[name="password_confirm"]').val();

                if (pass1 !== pass2) {
                    LTMS.Auth.showFormError('#ltms-register-form', ltmsAuth.i18n.password_mismatch);
                    return;
                }

                const $btn = $form.find('[type="submit"]');
                $btn.prop('disabled', true).text(ltmsAuth.i18n.processing);

                $.ajax({
                    url: ltmsAuth.ajax_url,
                    method: 'POST',
                    data: $form.serialize() + '&action=ltms_vendor_register&nonce=' + ltmsAuth.nonce,
                    success(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            LTMS.Auth.showFormError('#ltms-register-form', response.data);
                        }
                    },
                    error() {
                        $btn.prop('disabled', false);
                        LTMS.Auth.showFormError('#ltms-register-form', 'Error de conexión.');
                    },
                });
            });
        },

        initPasswordStrength() {
            $(document).on('input', '[name="password"]', function () {
                const val      = $(this).val();
                const $meter   = $(this).siblings('.ltms-password-strength');
                if ($meter.length === 0) return;

                let strength = 0;
                if (val.length >= 8)  strength++;
                if (/[A-Z]/.test(val)) strength++;
                if (/[0-9]/.test(val)) strength++;
                if (/[^A-Za-z0-9]/.test(val)) strength++;

                const classes  = ['', 'weak', 'fair', 'good', 'strong'];
                const labels   = ['', 'Débil', 'Regular', 'Buena', 'Fuerte'];
                $meter.removeClass('weak fair good strong').addClass(classes[strength]).text(labels[strength]);
            });
        },

        initTogglePassword() {
            $(document).on('click', '.ltms-toggle-password', function () {
                const $input = $(this).siblings('input');
                const type   = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
                $(this).text(type === 'password' ? '👁' : '🙈');
            });
        },

        showFormError(formSelector, message) {
            // Notice divs use IDs: #ltms-login-notice / #ltms-register-notice
            // They live outside the <form> tag, so search in the parent card wrapper
            const $form   = $(formSelector);
            const $card   = $form.closest('.ltms-auth-card');
            const $notice = $card.length
                ? $card.find('.ltms-notice')
                : $form.find('.ltms-notice');
            if ($notice.length) {
                $notice.removeClass('ltms-notice-success')
                       .addClass('ltms-notice-error')
                       .text(message)
                       .show();
            }
        },
    };

    $(document).ready(function () {
        if (typeof ltmsAuth !== 'undefined') {
            LTMS.Auth.init();
        }
    });

})(jQuery);
