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
                        remember: $form.find('[name="remember"]').is(':checked'),
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
            $(document).on('submit', '#ltms-register-form', function (e) {
                e.preventDefault();
                const $form = $(this);

                // Validar contraseñas
                const pass1 = $form.find('[name="password"]').val();
                const pass2 = $form.find('[name="confirm_password"]').val();

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
            const $notice = $(formSelector).find('.ltms-form-notice');
            if ($notice.length) {
                $notice.removeClass('ltms-notice-success').addClass('ltms-notice-error').text(message).show();
            }
        },
    };

    $(document).ready(function () {
        if (typeof ltmsAuth !== 'undefined') {
            LTMS.Auth.init();
        }
    });

})(jQuery);
