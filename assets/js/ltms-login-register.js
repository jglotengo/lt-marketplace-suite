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

        // M-57: helper para mostrar/ocultar estado "procesando" sin destruir
        // los <span> internos del botón (.ltms-btn-text / .ltms-btn-spinner).
        setBtnProcessing($btn, isProcessing) {
            if (isProcessing) {
                if (!$btn.data('ltms-original-html')) {
                    $btn.data('ltms-original-html', $btn.html());
                }
                $btn.prop('disabled', true).text(ltmsAuth.i18n.processing);
            } else {
                const original = $btn.data('ltms-original-html');
                if (original) {
                    $btn.html(original);
                }
                $btn.prop('disabled', false);
            }
        },

        bindLoginForm() {
            $(document).on('submit', '#ltms-login-form', function (e) {
                e.preventDefault();
                const $form = $(this);
                const $btn  = $form.find('[type="submit"]');

                LTMS.Auth.setBtnProcessing($btn, true);

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
                        LTMS.Auth.setBtnProcessing($btn, false);
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            const msg = (typeof response.data === 'string')
                                ? response.data
                                : (response.data && response.data.message) || 'Error en el inicio de sesión.';
                            LTMS.Auth.showFormError('#ltms-login-form', msg);
                        }
                    },
                    error(xhr) {
                        LTMS.Auth.setBtnProcessing($btn, false);
                        let msg = 'Error de conexión. Intenta de nuevo.';
                        if (xhr && xhr.status === 429) msg = 'Demasiados intentos. Espera 15 minutos.';
                        if (xhr && xhr.status === 0)   msg = 'No se pudo contactar el servidor. Verifica tu conexión.';
                        LTMS.Auth.showFormError('#ltms-login-form', msg);
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

                // M-7: normalizar referral code a uppercase antes de enviar.
                const $ref = $form.find('[name="referral_code"]');
                if ($ref.length) $ref.val(($ref.val() || '').toUpperCase().trim());

                // Limpiar errores previos por campo.
                $form.find('.ltms-input-error').removeClass('ltms-input-error');
                $form.find('.ltms-field-error').removeClass('ltms-field-error');

                // Validar contraseñas
                const pass1 = $form.find('[name="password"]').val();
                const pass2 = $form.find('[name="password_confirm"]').val();

                if (pass1 !== pass2) {
                    LTMS.Auth.showFormError('#ltms-register-form', ltmsAuth.i18n.password_mismatch);
                    return;
                }

                const $btn = $form.find('[type="submit"]');
                LTMS.Auth.setBtnProcessing($btn, true);

                $.ajax({
                    url: ltmsAuth.ajax_url,
                    method: 'POST',
                    data: $form.serialize() + '&action=ltms_vendor_register&nonce=' + ltmsAuth.nonce,
                    success(response) {
                        LTMS.Auth.setBtnProcessing($btn, false);
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            // M-10: el handler puede devolver un objeto con {message, errors:[{field,message}]}
                            // o un string plano. Soportar ambos formatos.
                            const payload = response.data || {};
                            let msg = '';
                            let fieldErrors = [];

                            if (typeof payload === 'string') {
                                msg = payload;
                            } else {
                                msg = payload.message || 'Error en el registro.';
                                fieldErrors = Array.isArray(payload.errors) ? payload.errors : [];
                            }

                            LTMS.Auth.showFormError('#ltms-register-form', msg);

                            // Resaltar campos específicos y enfocar el primero.
                            if (fieldErrors.length) {
                                let firstField = null;
                                fieldErrors.forEach(function (err) {
                                    const $field = $form.find('[name="' + err.field + '"]');
                                    if (!$field.length) return;
                                    if ($field.is(':checkbox')) {
                                        $field.closest('.ltms-form-group').addClass('ltms-field-error');
                                    } else {
                                        $field.addClass('ltms-input-error');
                                    }
                                    if (!firstField) firstField = $field;
                                });

                                if (firstField) {
                                    // Saltar al wizard step que contiene el primer campo en error.
                                    const $page = firstField.closest('.ltms-wizard-page');
                                    if ($page.length) {
                                        const stepNum = parseInt($page.data('page'), 10);
                                        $form.find('.ltms-wizard-page').hide();
                                        $page.show();
                                        $form.closest('.ltms-auth-card').find('.ltms-step').each(function () {
                                            const step = parseInt($(this).data('step'), 10);
                                            $(this).toggleClass('active', step === stepNum);
                                            $(this).toggleClass('completed', step < stepNum);
                                        });
                                    }
                                    firstField.focus();
                                }
                            }
                        }
                    },
                    error(xhr) {
                        LTMS.Auth.setBtnProcessing($btn, false);
                        let msg = 'Error de conexión.';
                        if (xhr && xhr.status === 429) msg = 'Demasiados intentos. Intenta más tarde.';
                        if (xhr && xhr.status === 0)   msg = 'No se pudo contactar el servidor. Verifica tu conexión.';
                        if (xhr && xhr.status === 403) msg = 'Sesión expirada. Recarga la página.';
                        LTMS.Auth.showFormError('#ltms-register-form', msg);
                    },
                });
            });
        },

        initPasswordStrength() {
            // Only the register form has a strength meter (#ltms-reg-password).
            // The .ltms-password-strength div is a sibling of .ltms-input-group, not of the input.
            // Use closest('.ltms-form-group').find() to locate it correctly.
            $(document).on('input', '#ltms-reg-password', function () {
                const val    = $(this).val();
                const $group = $(this).closest('.ltms-form-group');
                const $meter = $group.find('.ltms-password-strength');
                if ($meter.length === 0) return;

                let strength = 0;
                if (val.length >= 8)            strength++;
                if (/[A-Z]/.test(val))          strength++;
                if (/[0-9]/.test(val))          strength++;
                if (/[^A-Za-z0-9]/.test(val))  strength++;

                const classes = ['', 'weak', 'fair', 'good', 'strong'];
                const labels  = ['', 'Débil', 'Regular', 'Buena', 'Fuerte'];

                // Update the bar width and label text separately — don't overwrite the inner HTML
                $meter
                    .removeClass('weak fair good strong')
                    .addClass(classes[strength]);
                $meter.find('.ltms-strength-label').text(labels[strength]);
                $meter.find('.ltms-strength-bar').css('width', (strength * 25) + '%');
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
