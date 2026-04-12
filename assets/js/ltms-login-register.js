/**
 * LT Marketplace Suite - Login / Register JavaScript
 * Manejo de formularios de autenticación del frontend
 * Version: 1.5.0
 */

/* global ltmsAuth, jQuery */

(function ($) {
    'use strict';

    // Asegurar que ltmsAuth tenga i18n aunque se haya sobrescrito
    if (typeof ltmsAuth !== 'undefined' && !ltmsAuth.i18n) {
        ltmsAuth.i18n = {
            password_mismatch: 'Las contraseñas no coinciden.',
            required_fields:   'Por favor completa todos los campos requeridos.',
            processing:        'Procesando...',
        };
    }

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
            // Wizard: avanzar pasos
            $(document).on('click', '.ltms-wizard-next', function () {
                const $btn     = $(this);
                const nextPage = parseInt($btn.data('next'));
                const currPage = nextPage - 1;
                const $currPage = $('[data-page="' + currPage + '"]');
                // Validar campos requeridos del paso actual
                let valid = true;
                $currPage.find('[required]').each(function () {
                    if (!$(this).val().trim()) {
                        $(this).addClass('ltms-field-error');
                        valid = false;
                    } else {
                        $(this).removeClass('ltms-field-error');
                    }
                });
                if (!valid) {
                    LTMS.Auth.showFormError('#ltms-register-form', ltmsAuth.i18n.required_fields);
                    return;
                }
                // Avanzar al siguiente paso
                $currPage.hide();
                $('[data-page="' + nextPage + '"]').show();
                // Actualizar indicadores
                $('.ltms-step').removeClass('active');
                $('.ltms-step[data-step="' + nextPage + '"]').addClass('active');
            });
            // Wizard: retroceder pasos
            $(document).on('click', '.ltms-wizard-prev', function () {
                const $btn     = $(this);
                const prevPage = parseInt($btn.data('prev'));
                const currPage = prevPage + 1;
                $('[data-page="' + currPage + '"]').hide();
                $('[data-page="' + prevPage + '"]').show();
                $('.ltms-step').removeClass('active');
                $('.ltms-step[data-step="' + prevPage + '"]').addClass('active');
            });
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

// Fallback: si jQuery ready ya disparó, inicializar directamente
(function() {
    function ltmsInitAuth() {
        if (typeof jQuery === 'undefined' || typeof ltmsAuth === 'undefined') return;
        if (typeof LTMS !== 'undefined' && LTMS.Auth) {
            jQuery(document).off('click.ltmsWizard', '.ltms-wizard-next');
            jQuery(document).off('click.ltmsWizard', '.ltms-wizard-prev');
            jQuery(document).on('click.ltmsWizard', '.ltms-wizard-next', function() {
                var nextPage = parseInt(jQuery(this).data('next'));
                var currPage = nextPage - 1;
                var $curr = jQuery('[data-page="' + currPage + '"]');
                var valid = true;
                $curr.find('[required]').each(function() {
                    if (!jQuery(this).val().trim()) {
                        jQuery(this).addClass('ltms-field-error');
                        valid = false;
                    } else {
                        jQuery(this).removeClass('ltms-field-error');
                    }
                });
                if (!valid) return;
                $curr.hide();
                jQuery('[data-page="' + nextPage + '"]').show();
                jQuery('.ltms-step').removeClass('active');
                jQuery('.ltms-step[data-step="' + nextPage + '"]').addClass('active');
            });
            jQuery(document).on('click.ltmsWizard', '.ltms-wizard-prev', function() {
                var prevPage = parseInt(jQuery(this).data('prev'));
                var currPage = prevPage + 1;
                jQuery('[data-page="' + currPage + '"]').hide();
                jQuery('[data-page="' + prevPage + '"]').show();
                jQuery('.ltms-step').removeClass('active');
                jQuery('.ltms-step[data-step="' + prevPage + '"]').addClass('active');
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ltmsInitAuth);
    } else {
        ltmsInitAuth();
    }
})();
