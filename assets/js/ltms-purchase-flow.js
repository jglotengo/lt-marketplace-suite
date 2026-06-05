/**
 * LTMS Purchase Flow JS — v1.0.0
 * Social proof en vivo, trust bar, qty controller, category pills
 */
(function($) {
    'use strict';

    // ─── TRUST BAR ─────────────────────────────────────────────
    function injectTrustBar() {
        if ($('.ltms-pf-trust-bar').length) return;
        var html = '<div class="ltms-pf-trust-bar">' +
            '<div class="ltms-pf-trust-bar__inner">' +
            '<span class="ltms-pf-trust-bar__item"><svg viewBox="0 0 16 16"><path d="M8 1l1.8 3.6L14 5.4l-3 2.9.7 4.1L8 10.4l-3.7 1.9L5 8.3 2 5.4l4.2-.8z"/></svg> KYC verificado</span>' +
            '<span class="ltms-pf-trust-bar__item"><svg viewBox="0 0 16 16"><path d="M2 4h12l-1 8H3L2 4zm4 4h4"/></svg> Envío nacional</span>' +
            '<span class="ltms-pf-trust-bar__item"><svg viewBox="0 0 16 16"><path d="M2 8a6 6 0 1012 0A6 6 0 002 8zm6-3v3l2 2"/></svg> Devolución 30 días</span>' +
            '<span class="ltms-pf-trust-bar__item"><svg viewBox="0 0 16 16"><rect x="2" y="6" width="12" height="8" rx="1"/><path d="M5 6V4a3 3 0 016 0v2"/></svg> Pago seguro</span>' +
            '</div></div>';
        // Insert after ltms-trust-bar if exists, else before first section
        if ($('.ltms-trust-bar').length) {
            $('.ltms-trust-bar').after(html);
        } else {
            $('body').prepend(html);
        }
    }

    // ─── SOCIAL PROOF LIVE BADGE ───────────────────────────────
    function initSocialProof() {
        var $badge = $('.ltms-pf-live-badge');
        if (!$badge.length) return;

        // Simulate live viewing count updates
        var base = parseInt($badge.data('viewing') || 3, 10);
        setInterval(function() {
            var delta = Math.floor(Math.random() * 3) - 1; // -1, 0, +1
            base = Math.max(1, base + delta);
            $badge.find('.ltms-live-viewing').text(base + ' viendo ahora');
        }, 8000);
    }

    // ─── QUANTITY CONTROLLER ───────────────────────────────────
    function initQtyController() {
        $(document).on('click', '.ltms-pf-qty-btn', function() {
            var $btn = $(this);
            var $input = $btn.siblings('.ltms-pf-qty-input');
            var val = parseInt($input.val(), 10) || 1;
            var max = parseInt($input.data('max') || 99, 10);

            if ($btn.hasClass('ltms-pf-qty-btn--plus')) {
                if (val < max) $input.val(val + 1).trigger('change');
            } else {
                if (val > 1) $input.val(val - 1).trigger('change');
            }
        });
    }

    // ─── CATEGORY PILLS ────────────────────────────────────────
    function initCategoryPills() {
        $(document).on('click', '.ltms-pf-pill', function(e) {
            var $pill = $(this);
            if ($pill.attr('href') && $pill.attr('href') !== '#') return; // let link work
            e.preventDefault();
            $('.ltms-pf-pill').removeClass('ltms-pf-pill--active');
            $pill.addClass('ltms-pf-pill--active');
        });
    }

    // ─── CHECKOUT STEPS ────────────────────────────────────────
    function initCheckoutSteps() {
        $(document).on('click', '.ltms-pf-checkout-next', function() {
            var $btn = $(this);
            var targetStep = parseInt($btn.data('to'), 10);
            setCheckoutStep(targetStep);
        });
    }

    function setCheckoutStep(step) {
        $('.ltms-pf-step').each(function(i) {
            var $s = $(this);
            var n = i + 1;
            $s.removeClass('ltms-pf-step--active ltms-pf-step--done');
            if (n < step) $s.addClass('ltms-pf-step--done');
            if (n === step) $s.addClass('ltms-pf-step--active');
        });
        $('.ltms-pf-checkout__section').hide();
        $('#ltms-pf-step-' + step).show();
    }

    // ─── PAYMENT OPTIONS ───────────────────────────────────────
    function initPaymentOptions() {
        $(document).on('click', '.ltms-pf-payment-opt', function() {
            $('.ltms-pf-payment-opt').removeClass('ltms-pf-payment-opt--selected');
            $(this).addClass('ltms-pf-payment-opt--selected');
            var method = $(this).data('method');
            $('.ltms-pf-payment-detail').hide();
            if (method) $('#ltms-pf-pay-' + method).show();
        });
    }

    // ─── ADD TO CART FEEDBACK ──────────────────────────────────
    function initAddToCart() {
        $(document).on('click', '.ltms-pf-add-to-cart', function() {
            var $btn = $(this);
            var origText = $btn.text();
            $btn.text('✓ Agregado').css({ background: '#5B2D8E', borderColor: '#5B2D8E' }).prop('disabled', true);
            setTimeout(function() {
                $btn.text(origText).css({ background: '', borderColor: '' }).prop('disabled', false);
            }, 2000);
        });
    }

    // ─── INIT ──────────────────────────────────────────────────
    $(document).ready(function() {
        injectTrustBar();
        initSocialProof();
        initQtyController();
        initCategoryPills();
        initCheckoutSteps();
        initPaymentOptions();
        initAddToCart();
    });

})(jQuery);
