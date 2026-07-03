/**
 * LTMS Checkout Mexico JS
 * Flujos de pago específicos para México: OXXO, SPEI, MSI (Meses Sin Intereses)
 * Integración con Openpay MX y Conekta
 * Version: 1.5.0
 */

/* global ltmsCheckout, OpenPay, Conekta */
'use strict';

window.LTMS = window.LTMS || {};

LTMS.CheckoutMX = (function ($) {

    // ── State ──────────────────────────────────────────────────────
    const state = {
        selectedMsi: null,
        oxxoReference: null,
        speiClabe: null,
        selectedBank: null,
    };

    // ── Config ─────────────────────────────────────────────────────
    const config = {
        ajaxUrl:   (typeof ltmsCheckout !== 'undefined') ? ltmsCheckout.ajax_url   : '/wp-admin/admin-ajax.php',
        nonce:     (typeof ltmsCheckout !== 'undefined') ? ltmsCheckout.nonce      : '',
        currency:  'MXN',
        country:   'MX',
        orderTotal: (typeof ltmsCheckout !== 'undefined') ? parseFloat(ltmsCheckout.order_total) || 0 : 0,
    };

    // ── Init ───────────────────────────────────────────────────────

    function init() {
        bindOxxoEvents();
        bindSpeiEvents();
        bindMsiEvents();
        initCopyButtons();
        checkUrlForReference();
    }

    // ── OXXO ───────────────────────────────────────────────────────

    function bindOxxoEvents() {
        $(document).on('click', '#ltms-oxxo-generate-btn', function (e) {
            e.preventDefault();
            generateOxxoReference();
        });

        $(document).on('click', '#ltms-oxxo-download-btn', function () {
            downloadOxxoVoucher();
        });
    }

    /**
     * Genera una referencia de pago OXXO vía AJAX.
     */
    function generateOxxoReference() {
        const $btn = $('#ltms-oxxo-generate-btn');
        setLoading($btn, true);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_create_oxxo_reference',
                nonce: config.nonce,
                order_id: getOrderId(),
            },
        })
        .done(function (res) {
            if (res.success) {
                state.oxxoReference = res.data;
                renderOxxoVoucher(res.data);
            } else {
                showError(res.data?.message || 'Error al generar referencia OXXO.');
            }
        })
        .fail(function () {
            showError('Error de conexión. Por favor intenta de nuevo.');
        })
        .always(function () {
            setLoading($btn, false);
        });
    }

    /**
     * Renderiza el cupón OXXO con referencia y monto.
     *
     * @param {Object} data - { reference, amount, expiry_date, barcode_url }
     */
    function renderOxxoVoucher(data) {
        const formattedAmount = formatMoney(data.amount);
        const $voucher = $('#ltms-oxxo-voucher');

        $voucher.find('.ltms-oxxo-ref-number').text(formatOxxoRef(data.reference));
        $voucher.find('.ltms-oxxo-amount').text(formattedAmount);
        $voucher.find('.ltms-oxxo-expiry').text('Pagar antes del: ' + data.expiry_date);

        if (data.barcode_url) {
            $voucher.find('.ltms-oxxo-barcode').attr('src', data.barcode_url).show();
        }

        $voucher.slideDown(300);
        $('#ltms-oxxo-generate-btn').hide();
    }

    /**
     * Formatea la referencia OXXO en grupos de 4 dígitos.
     *
     * @param  {string} ref
     * @return {string}
     */
    function formatOxxoRef(ref) {
        return ref.replace(/(.{4})/g, '$1 ').trim();
    }

    /**
     * Descarga el comprobante OXXO como PDF.
     */
    function downloadOxxoVoucher() {
        if (!state.oxxoReference) return;

        const $btn = $('#ltms-oxxo-download-btn');
        setLoading($btn, true);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_download_oxxo_voucher',
                nonce: config.nonce,
                reference: state.oxxoReference.reference,
                order_id: getOrderId(),
            },
        })
        .done(function (res) {
            if (res.success && res.data.pdf_url) {
                const link = document.createElement('a');
                link.href = res.data.pdf_url;
                link.download = 'oxxo-pago-' + state.oxxoReference.reference + '.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        })
        .always(function () {
            setLoading($btn, false);
        });
    }

    // ── SPEI ───────────────────────────────────────────────────────

    function bindSpeiEvents() {
        $(document).on('click', '#ltms-spei-generate-btn', function (e) {
            e.preventDefault();
            generateSpeiClabe();
        });
    }

    /**
     * Genera una CLABE SPEI para transferencia.
     */
    function generateSpeiClabe() {
        const $btn = $('#ltms-spei-generate-btn');
        setLoading($btn, true);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_create_spei_reference',
                nonce: config.nonce,
                order_id: getOrderId(),
            },
        })
        .done(function (res) {
            if (res.success) {
                state.speiClabe = res.data;
                renderSpeiInstructions(res.data);
            } else {
                showError(res.data?.message || 'Error al generar CLABE SPEI.');
            }
        })
        .fail(function () {
            showError('Error de conexión. Por favor intenta de nuevo.');
        })
        .always(function () {
            setLoading($btn, false);
        });
    }

    /**
     * Renderiza las instrucciones de transferencia SPEI.
     *
     * @param {Object} data - { clabe, bank_name, beneficiary, amount, expiry_date }
     */
    function renderSpeiInstructions(data) {
        const $box = $('#ltms-spei-clabe-box');

        $box.find('[data-spei="clabe"]').text(formatClabe(data.clabe));
        $box.find('[data-spei="bank"]').text(data.bank_name);
        $box.find('[data-spei="beneficiary"]').text(data.beneficiary);
        $box.find('[data-spei="amount"]').text(formatMoney(data.amount));
        $box.find('[data-spei="expiry"]').text(data.expiry_date);
        $box.find('[data-copy="clabe"]').attr('data-value', data.clabe);

        $box.slideDown(300);
        $('#ltms-spei-generate-btn').hide();
    }

    /**
     * Formatea una CLABE (18 dígitos) en grupos para legibilidad.
     *
     * @param  {string} clabe
     * @return {string}
     */
    function formatClabe(clabe) {
        return clabe.replace(/(\d{3})(\d{3})(\d{11})(\d{1})/, '$1 $2 $3 $4');
    }

    // ── MSI — Meses Sin Intereses ───────────────────────────────────

    function bindMsiEvents() {
        $(document).on('click', '.ltms-msi-option', function () {
            const months = parseInt($(this).data('months'), 10);
            selectMsiPlan(months, $(this));
        });
    }

    /**
     * Selecciona un plan MSI.
     *
     * @param {number} months
     * @param {jQuery} $el
     */
    function selectMsiPlan(months, $el) {
        $('.ltms-msi-option').removeClass('selected');
        $el.addClass('selected');
        state.selectedMsi = months;

        // Notificar al módulo principal si existe
        if (LTMS.Checkout && typeof LTMS.Checkout.setMsiMonths === 'function') {
            LTMS.Checkout.setMsiMonths(months);
        }

        // Actualizar hidden input
        $('#ltms-msi-months-input').val(months);

        // Animar selección
        $el.addClass('ltms-msi-pulse');
        setTimeout(() => $el.removeClass('ltms-msi-pulse'), 400);
    }

    /**
     * Carga las opciones MSI disponibles para el banco seleccionado.
     *
     * @param {string} bankCode
     */
    function loadMsiOptions(bankCode) {
        state.selectedBank = bankCode;

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ltms_get_msi_options',
                nonce: config.nonce,
                bank_code: bankCode,
                amount: config.orderTotal,
            },
        })
        .done(function (res) {
            if (res.success) {
                renderMsiGrid(res.data.plans);
            }
        });
    }

    /**
     * Renderiza la grilla de opciones MSI.
     *
     * @param {Array} plans - [{ months, monthly_amount, interest_rate }]
     */
    function renderMsiGrid(plans) {
        const $grid = $('.ltms-msi-grid');
        $grid.empty();

        plans.forEach(function (plan) {
            const monthlyFormatted = formatMoney(plan.monthly_amount);
            const $option = $('<div>', {
                class: 'ltms-msi-option',
                'data-months': plan.months,
                html: `
                    <div class="ltms-msi-months">${plan.months}</div>
                    <div class="ltms-msi-label">meses</div>
                    <div class="ltms-msi-monthly">${monthlyFormatted}/mes</div>
                    ${plan.interest_rate === 0 ? '<div class="ltms-msi-badge">Sin intereses</div>' : ''}
                `,
            });
            $grid.append($option);
        });

        if (plans.length > 0) {
            $('.ltms-msi-section').show();
        }
    }

    // ── Copy Buttons ────────────────────────────────────────────────

    function initCopyButtons() {
        $(document).on('click', '[data-copy]', function () {
            const value = $(this).data('value') || $(this).prev().text().replace(/\s/g, '');
            copyToClipboard(value, $(this));
        });
    }

    /**
     * Copia texto al portapapeles y muestra feedback.
     *
     * @param {string} text
     * @param {jQuery} $btn
     */
    function copyToClipboard(text, $btn) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                showCopySuccess($btn);
            });
        } else {
            // Fallback
            const $temp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
            showCopySuccess($btn);
        }
    }

    function showCopySuccess($btn) {
        const originalText = $btn.text();
        $btn.text('¡Copiado!').addClass('ltms-copied');
        setTimeout(function () {
            $btn.text(originalText).removeClass('ltms-copied');
        }, 2000);
    }

    // ── Utilities ───────────────────────────────────────────────────

    function getOrderId() {
        return $('#ltms-order-id').val() || (typeof ltmsCheckout !== 'undefined' ? ltmsCheckout.order_id : 0);
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            minimumFractionDigits: 2,
        }).format(amount);
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true).data('original-text', $btn.text()).text('Procesando...');
        } else {
            $btn.prop('disabled', false).text($btn.data('original-text') || 'Continuar');
        }
    }

    function showError(message) {
        const $notice = $('#ltms-checkout-notice');
        if ($notice.length) {
            $notice.text(message).removeClass('ltms-notice-success').addClass('ltms-notice-error').show();
            $('html, body').animate({ scrollTop: $notice.offset().top - 80 }, 300);
        }
    }

    /**
     * Verifica si hay una referencia en la URL (retorno de Openpay).
     */
    function checkUrlForReference() {
        const params = new URLSearchParams(window.location.search);
        const ref = params.get('ltms_oxxo_ref');
        if (ref) {
            $('#ltms-oxxo-voucher').find('.ltms-oxxo-ref-number').text(formatOxxoRef(ref));
            $('#ltms-oxxo-voucher').show();
        }
    }

    // ── Public API ──────────────────────────────────────────────────

    return {
        init,
        loadMsiOptions,
        getSelectedMsi: () => state.selectedMsi,
        generateOxxoReference,
        generateSpeiClabe,
    };

})(jQuery);

// Auto-init on DOM ready
jQuery(function () {
    if (typeof ltmsCheckout !== 'undefined' && ltmsCheckout.country === 'MX') {
        LTMS.CheckoutMX.init();
    }
});
