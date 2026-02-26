/**
 * LT Marketplace Suite - Checkout JavaScript
 * Tokenización de tarjetas con Openpay + manejo de PSE/OXXO
 * Version: 1.5.0
 */

/* global ltmsCheckout, jQuery, OpenPay */

(function ($) {
    'use strict';

    /**
     * LTMS.Checkout - Módulo de checkout con Openpay
     */
    window.LTMS = window.LTMS || {};

    LTMS.Checkout = {

        /** Indica si Openpay ya fue inicializado */
        openpayReady: false,

        /**
         * Inicializa el módulo de checkout.
         */
        init() {
            if (typeof ltmsCheckout === 'undefined') return;

            this.initOpenpay();
            this.bindPaymentMethodToggle();
            this.bindCardForm();
            this.bindPseForm();
            this.bindCheckoutSubmit();
            this.initCardFormatting();
        },

        /**
         * Inicializa el SDK de Openpay.
         */
        initOpenpay() {
            if (typeof OpenPay === 'undefined') {
                console.warn('[LTMS Checkout] OpenPay SDK no cargado.');
                return;
            }

            try {
                OpenPay.setId(ltmsCheckout.merchant_id);
                OpenPay.setApiKey(ltmsCheckout.public_key);
                OpenPay.setSandboxMode(ltmsCheckout.is_sandbox);
                this.openpayReady = true;
            } catch (e) {
                console.error('[LTMS Checkout] Error inicializando OpenPay:', e);
            }
        },

        /**
         * Vincula el toggle de métodos de pago.
         */
        bindPaymentMethodToggle() {
            $(document).on('change', 'input[name="ltms_payment_method"]', function () {
                const method = $(this).val();
                $('.ltms-payment-panel').hide();
                $('#ltms-payment-panel-' + method).show();
            });

            // Mostrar el primer método por defecto
            $('input[name="ltms_payment_method"]:first').trigger('change');
        },

        /**
         * Vincula el formulario de tarjeta de crédito.
         */
        bindCardForm() {
            // Formateo en tiempo real del número de tarjeta
            $(document).on('input', '#ltms-card-number', function () {
                let val = $(this).val().replace(/\D/g, '').substring(0, 16);
                val = val.match(/.{1,4}/g)?.join(' ') || val;
                $(this).val(val);
            });

            // Formateo fecha de expiración
            $(document).on('input', '#ltms-card-expiry', function () {
                let val = $(this).val().replace(/\D/g, '').substring(0, 4);
                if (val.length > 2) {
                    val = val.substring(0, 2) + '/' + val.substring(2);
                }
                $(this).val(val);
            });

            // Solo números en CVV
            $(document).on('input', '#ltms-card-cvv', function () {
                $(this).val($(this).val().replace(/\D/g, '').substring(0, 4));
            });
        },

        /**
         * Vincula el formulario PSE (Colombia).
         */
        bindPseForm() {
            if (!ltmsCheckout.pse_enabled) return;

            // Cargar lista de bancos PSE al seleccionar el método
            $(document).on('change', 'input[name="ltms_payment_method"]', function () {
                if ($(this).val() === 'pse') {
                    LTMS.Checkout.loadPseBanks();
                }
            });
        },

        /**
         * Carga la lista de bancos PSE.
         */
        loadPseBanks() {
            const $select = $('#ltms-pse-bank');
            if ($select.find('option').length > 1) return; // Ya cargados

            $select.append('<option value="" disabled selected>Cargando bancos...</option>');

            $.ajax({
                url: ltmsCheckout.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_pse_banks',
                    nonce: ltmsCheckout.nonce,
                },
                success(response) {
                    $select.find('option:disabled').remove();
                    if (response.success && response.data.banks) {
                        response.data.banks.forEach(bank => {
                            $select.append(`<option value="${bank.id}">${bank.name}</option>`);
                        });
                    }
                },
            });
        },

        /**
         * Vincula el evento de submit del checkout.
         */
        bindCheckoutSubmit() {
            $(document).on('submit', '#ltms-checkout-form', (e) => {
                e.preventDefault();
                this.processPayment();
            });

            $(document).on('click', '#ltms-place-order-btn', (e) => {
                e.preventDefault();
                this.processPayment();
            });
        },

        /**
         * Procesa el pago según el método seleccionado.
         */
        processPayment() {
            const method = $('input[name="ltms_payment_method"]:checked').val();

            if (!method) {
                this.showError(ltmsCheckout.i18n.payment_error);
                return;
            }

            this.setLoading(true);

            const handlers = {
                card:    () => this.processCardPayment(),
                pse:     () => this.processPsePayment(),
                oxxo:    () => this.processOxxoPayment(),
                addi:    () => this.processAddiPayment(),
                nequi:   () => this.processNequiPayment(),
            };

            if (handlers[method]) {
                handlers[method]();
            } else {
                this.submitToServer({ payment_method: method });
            }
        },

        /**
         * Procesa pago con tarjeta de crédito/débito via Openpay.
         */
        processCardPayment() {
            if (!this.openpayReady) {
                this.setLoading(false);
                this.showError('Error: Módulo de pago no disponible.');
                return;
            }

            const cardData = {
                card_number:      $('#ltms-card-number').val().replace(/\s/g, ''),
                holder_name:      $('#ltms-card-name').val(),
                expiration_year:  $('#ltms-card-expiry').val().split('/')[1]?.trim(),
                expiration_month: $('#ltms-card-expiry').val().split('/')[0]?.trim(),
                cvv2:             $('#ltms-card-cvv').val(),
            };

            // Validar datos de la tarjeta
            if (!OpenPay.card.validateCardNumber(cardData.card_number)) {
                this.setLoading(false);
                this.showError('Número de tarjeta inválido.');
                return;
            }

            if (!OpenPay.card.validateCVC(cardData.cvv2, cardData.card_number)) {
                this.setLoading(false);
                this.showError('CVV inválido.');
                return;
            }

            // Obtener token de Openpay
            OpenPay.token.create(cardData, (response) => {
                // Éxito
                this.submitToServer({
                    payment_method:         'card',
                    openpay_token_id:       response.data.id,
                    device_session_id:      OpenPay.deviceData.setup('ltms-checkout-form', 'deviceIdHiddenFieldName'),
                    card_type:              response.data.card ? response.data.card.type : '',
                    card_brand:             response.data.card ? response.data.card.brand : '',
                    installments:           $('#ltms-card-installments').val() || 1,
                });
            }, (error) => {
                // Error en tokenización
                this.setLoading(false);
                const errorMessages = {
                    1001: 'Tarjeta inválida.',
                    1004: 'Servicio no disponible. Intenta más tarde.',
                    3001: 'La tarjeta fue rechazada.',
                    3002: 'La tarjeta ha vencido.',
                    3003: 'Fondos insuficientes.',
                    3005: 'Tarjeta bloqueada por sospecha de fraude.',
                };
                this.showError(errorMessages[error.data.error_code] || ltmsCheckout.i18n.card_error);
            });
        },

        /**
         * Procesa pago vía PSE (Colombia).
         */
        processPsePayment() {
            const bankCode    = $('#ltms-pse-bank').val();
            const personType  = $('input[name="ltms_pse_person_type"]:checked').val() || 'natural';
            const document    = $('#ltms-pse-document').val();
            const documentType = $('#ltms-pse-document-type').val() || 'CC';

            if (!bankCode || !document) {
                this.setLoading(false);
                this.showError('Completa los datos de PSE.');
                return;
            }

            this.submitToServer({
                payment_method:      'pse',
                bank_code:           bankCode,
                person_type:         personType,
                document_number:     document,
                document_type:       documentType,
                redirect_url:        window.location.href,
            });
        },

        /**
         * Procesa pago vía OXXO (México).
         */
        processOxxoPayment() {
            this.submitToServer({ payment_method: 'oxxo' });
        },

        /**
         * Redirige al checkout de Addi BNPL.
         */
        processAddiPayment() {
            this.submitToServer({ payment_method: 'addi' });
        },

        /**
         * Procesa pago vía Nequi (Colombia).
         */
        processNequiPayment() {
            const phone = $('#ltms-nequi-phone').val();
            if (!phone) {
                this.setLoading(false);
                this.showError('Ingresa tu número de Nequi.');
                return;
            }
            this.submitToServer({ payment_method: 'nequi', phone });
        },

        /**
         * Envía los datos de pago al servidor para procesamiento.
         *
         * @param {Object} paymentData Datos del método de pago.
         */
        submitToServer(paymentData) {
            const formData = Object.assign(
                {},
                this.serializeCheckoutForm(),
                paymentData,
                {
                    action: 'ltms_process_checkout',
                    nonce:  ltmsCheckout.nonce,
                }
            );

            $.ajax({
                url: ltmsCheckout.ajax_url,
                method: 'POST',
                data: formData,
                success: (response) => {
                    this.setLoading(false);
                    if (response.success) {
                        if (response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            this.showSuccess(response.data.message || ltmsCheckout.i18n.payment_success);
                        }
                    } else {
                        this.showError(response.data || ltmsCheckout.i18n.payment_error);
                    }
                },
                error: () => {
                    this.setLoading(false);
                    this.showError(ltmsCheckout.i18n.payment_error);
                },
            });
        },

        // ── Helpers ───────────────────────────────────────────────

        /**
         * Serializa los campos del formulario de checkout.
         *
         * @returns {Object}
         */
        serializeCheckoutForm() {
            const data = {};
            $('#ltms-checkout-form').find('input, select, textarea').each(function () {
                const name = $(this).attr('name');
                if (name && !name.includes('payment_method') && !name.includes('ltms_card')) {
                    data[name] = $(this).val();
                }
            });
            return data;
        },

        /**
         * Inicializa el formateo de campos de la tarjeta.
         */
        initCardFormatting() {
            // Detectar tipo de tarjeta al escribir
            $(document).on('input', '#ltms-card-number', function () {
                const num = $(this).val().replace(/\D/g, '');
                const $logo = $('#ltms-card-brand-logo');

                if (/^4/.test(num))               $logo.attr('src', '').text('Visa');
                else if (/^5[1-5]/.test(num))      $logo.attr('src', '').text('Mastercard');
                else if (/^3[47]/.test(num))        $logo.attr('src', '').text('Amex');
                else                               $logo.text('');
            });
        },

        /**
         * Controla el estado de loading del botón de pago.
         *
         * @param {boolean} loading Si está en loading.
         */
        setLoading(loading) {
            const $btn = $('#ltms-place-order-btn');
            if (loading) {
                $btn.prop('disabled', true).data('original-text', $btn.text());
                $btn.html('<span class="ltms-spinner"></span> ' + ltmsCheckout.i18n.processing);
            } else {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Pagar');
            }
        },

        /**
         * Muestra un mensaje de error en el checkout.
         *
         * @param {string} message Mensaje de error.
         */
        showError(message) {
            const $notice = $('#ltms-checkout-notice');
            $notice.removeClass('ltms-notice-success').addClass('ltms-notice-error')
                   .html('<p>' + message + '</p>').show();
            $('html, body').animate({ scrollTop: $notice.offset().top - 80 }, 300);
        },

        /**
         * Muestra un mensaje de éxito en el checkout.
         *
         * @param {string} message Mensaje de éxito.
         */
        showSuccess(message) {
            const $notice = $('#ltms-checkout-notice');
            $notice.removeClass('ltms-notice-error').addClass('ltms-notice-success')
                   .html('<p>' + message + '</p>').show();
        },
    };

    // ── Inicializar ───────────────────────────────────────────────
    $(document).ready(function () {
        if (typeof ltmsCheckout !== 'undefined') {
            LTMS.Checkout.init();
        }
    });

})(jQuery);
