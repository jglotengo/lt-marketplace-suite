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

    // ─── WC SINGLE PRODUCT ENHANCEMENTS ───────────────────────
    function initProductPage() {
        if (!document.querySelector('.single-product')) return;

        // Qty buttons: wrap WC native qty input
        var $qty = $('.quantity .qty');
        if ($qty.length && !$qty.parent().find('.ltms-pf-qty-btn').length) {
            $qty.wrap('<div class="ltms-qty-wrapper" style="display:flex;align-items:center;gap:0;"></div>');
            $qty.before('<button type="button" class="ltms-pf-qty-btn ltms-pf-qty-btn--minus" style="width:36px;height:40px;border:2px solid #E0E0E0;border-right:none;border-radius:8px 0 0 8px;background:#fff;font-size:18px;font-weight:700;cursor:pointer;color:#1A1A1A;">−</button>');
            $qty.after('<button type="button" class="ltms-pf-qty-btn ltms-pf-qty-btn--plus" style="width:36px;height:40px;border:2px solid #E0E0E0;border-left:none;border-radius:0 8px 8px 0;background:#fff;font-size:18px;font-weight:700;cursor:pointer;color:#1A1A1A;">+</button>');
            $qty.css({borderRadius:'0', textAlign:'center', width:'48px', height:'40px'});
        }

        // Add to cart button — visual feedback
        $(document).on('click', '.single_add_to_cart_button', function() {
            var $btn = $(this);
            if ($btn.hasClass('ltms-adding')) return;
            $btn.addClass('ltms-adding').text('Agregando…');
            setTimeout(function() {
                $btn.removeClass('ltms-adding').text('✓ Agregado al carrito');
                setTimeout(function() {
                    $btn.text('Agregar al carrito');
                }, 2500);
            }, 800);
        });
    }

    // ─── CHECKOUT PROGRESS ─────────────────────────────────────
    function initNativeCheckout() {
        if (!document.querySelector('.woocommerce-checkout')) return;

        // Inject step indicator above form
        if (!document.querySelector('.ltms-pf-steps')) {
            var stepsHtml = '<div class="ltms-pf-steps" style="max-width:860px;margin:0 auto 24px;padding:0 20px;">' +
                '<div class="ltms-pf-step ltms-pf-step--done"><span class="ltms-pf-step__num">✓</span><span>Carrito</span></div>' +
                '<div class="ltms-pf-step__sep"></div>' +
                '<div class="ltms-pf-step ltms-pf-step--active"><span class="ltms-pf-step__num">2</span><span>Datos</span></div>' +
                '<div class="ltms-pf-step__sep"></div>' +
                '<div class="ltms-pf-step"><span class="ltms-pf-step__num">3</span><span>Pago</span></div>' +
            '</div>';
            var $form = $('.woocommerce-checkout');
            $form.before(stepsHtml);
        }

        // Security bar above place order button
        if (!document.querySelector('.ltms-pf-security-bar')) {
            var secHtml = '<div class="ltms-pf-security-bar">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="1"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>' +
                '<span>Transacción cifrada SSL · Tus datos están protegidos · Ley 1581/2012</span>' +
            '</div>';
            $('#place_order').before(secHtml);
        }
    }

    // ─── CART PAGE ─────────────────────────────────────────────
    function initCartPage() {
        if (!document.querySelector('.woocommerce-cart')) return;
        // Add proceed button styling is handled via CSS
        // Trust bar already injected globally
    }

    $(document).ready(function() {
        initProductPage();
        initNativeCheckout();
        initCartPage();
    });

})(jQuery);
