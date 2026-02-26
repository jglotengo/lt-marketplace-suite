/* global jQuery, ltmsShipping */
(function($) {
    'use strict';

    window.LTMS = window.LTMS || {};

    LTMS.ShippingSelector = {
        quotes: {},
        loading: false,

        init: function() {
            if ( typeof ltmsShipping === 'undefined' ) return;
            $(document.body).on('updated_checkout', this.onCheckoutUpdate.bind(this));
            $(document.body).on('init_checkout', this.onCheckoutUpdate.bind(this));
        },

        onCheckoutUpdate: function() {
            if ( this.loading ) return;
            this.loading = true;
            this.renderSkeleton();
            this.fetchQuotes();
        },

        renderSkeleton: function() {
            if ( $('#ltms-shipping-comparison').length ) return;
            var html = '<div id="ltms-shipping-comparison" class="ltms-shipping-comparison" style="margin-bottom:20px;padding:15px;border:1px solid #ddd;border-radius:6px;">' +
                '<h4 style="margin-top:0;margin-bottom:12px;">' + (ltmsShipping.i18n ? ltmsShipping.i18n.compare_title : 'Comparar Opciones de Envío') + '</h4>' +
                '<div id="ltms-shipping-cards" style="display:flex;gap:10px;flex-wrap:wrap;">' +
                    '<div class="ltms-shipping-card ltms-loading" data-provider="uber" style="flex:1;min-width:120px;padding:12px;border:2px solid #ddd;border-radius:4px;cursor:pointer;text-align:center;">' +
                        '<strong>Uber Direct</strong><br><span class="ltms-price">...</span>' +
                    '</div>' +
                    '<div class="ltms-shipping-card ltms-loading" data-provider="aveonline" style="flex:1;min-width:120px;padding:12px;border:2px solid #ddd;border-radius:4px;cursor:pointer;text-align:center;">' +
                        '<strong>Aveonline</strong><br><span class="ltms-price">...</span>' +
                    '</div>' +
                    '<div class="ltms-shipping-card ltms-loading" data-provider="heka" style="flex:1;min-width:120px;padding:12px;border:2px solid #ddd;border-radius:4px;cursor:pointer;text-align:center;">' +
                        '<strong>Heka</strong><br><span class="ltms-price">...</span>' +
                    '</div>' +
                    '<div class="ltms-shipping-card" data-provider="pickup" style="flex:1;min-width:120px;padding:12px;border:2px solid #ddd;border-radius:4px;cursor:pointer;text-align:center;">' +
                        '<strong>Recogida</strong><br><span class="ltms-price">Gratis</span>' +
                    '</div>' +
                '</div>' +
            '</div>';

            // Insert before WC shipping methods table
            if ( $('table.woocommerce-shipping-methods').length ) {
                $('table.woocommerce-shipping-methods').before(html);
            } else {
                $('#shipping_method').before(html);
            }
        },

        fetchQuotes: function() {
            var self = this;
            $.ajax({
                url:    ltmsShipping.ajax_url,
                method: 'POST',
                data: {
                    action: 'ltms_get_shipping_quotes',
                    nonce:  ltmsShipping.nonce
                },
                success: function(res) {
                    self.loading = false;
                    if ( res.success && res.data ) {
                        self.quotes = res.data;
                        self.renderCards(res.data);
                    } else {
                        self.renderError();
                    }
                },
                error: function() {
                    self.loading = false;
                    self.renderError();
                }
            });
        },

        renderCards: function(data) {
            var providers = ['uber', 'aveonline', 'heka', 'pickup'];
            providers.forEach(function(provider) {
                var card  = $('#ltms-shipping-cards .ltms-shipping-card[data-provider="' + provider + '"]');
                var quote = data[provider];
                card.removeClass('ltms-loading');
                if ( quote && quote.price !== undefined ) {
                    var priceHtml = quote.price === 0
                        ? '<strong style="color:green;">Gratis</strong>'
                        : '<strong>' + quote.price_display + '</strong>';
                    card.find('.ltms-price').html(priceHtml);
                    if ( quote.estimated_time ) {
                        card.append('<br><small>' + quote.estimated_time + '</small>');
                    }
                    card.on('click', function() {
                        LTMS.ShippingSelector.selectProvider(provider, quote);
                    });
                } else if ( provider !== 'pickup' ) {
                    card.find('.ltms-price').html('<small style="color:#999;">No disponible</small>');
                    card.css('opacity', '0.5').css('cursor', 'default');
                }
            });
        },

        renderError: function() {
            $('#ltms-shipping-cards .ltms-loading').each(function() {
                $(this).find('.ltms-price').html('<small style="color:#999;">Error</small>');
                $(this).removeClass('ltms-loading');
            });
        },

        selectProvider: function(provider, quote) {
            // Visual selection
            $('#ltms-shipping-cards .ltms-shipping-card').css('border-color', '#ddd').css('background','');
            $('#ltms-shipping-cards .ltms-shipping-card[data-provider="' + provider + '"]')
                .css('border-color', '#1a5276')
                .css('background', '#f0f8ff');

            // Select corresponding WC shipping radio
            var rateIdPrefix = 'ltms_' + provider;
            $('input[name="shipping_method[0]"]').each(function() {
                if ( $(this).val().indexOf(rateIdPrefix) !== -1 ) {
                    $(this).prop('checked', true).trigger('change');
                    return false; // break
                }
            });
        }
    };

    $(document).ready(function() {
        LTMS.ShippingSelector.init();
    });

}(jQuery));
