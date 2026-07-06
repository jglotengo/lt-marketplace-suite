/* LTMS Product Enhancements — JS v2.9.2
 * Cart Drawer + Bundle AJAX + Video controls
 */

(function($) {
    'use strict';

    // ============================================================
    // CART DRAWER
    // ============================================================

    var LTMS_Drawer = {
        nonce: '',
        isOpen: false,

        init: function() {
            this.nonce = typeof ltmsDrawerData !== 'undefined' ? ltmsDrawerData.nonce : '';

            // Open drawer on add-to-cart.
            $(document.body).on('added_to_cart', this.openAfterAdd.bind(this));
            $(document.body).on('wc_cart_fragment_refreshed', this.refresh.bind(this));

            // Close drawer.
            $('#ltms-drawer-close').on('click', this.close.bind(this));
            $('#ltms-cart-drawer-overlay').on('click', this.close.bind(this));

            // Remove item.
            $(document).on('click', '.ltms-drawer-item__remove', this.removeItem.bind(this));

            // Update quantity.
            $(document).on('click', '.ltms-drawer-qty-minus', this.decreaseQty.bind(this));
            $(document).on('click', '.ltms-drawer-qty-plus', this.increaseQty.bind(this));

            // Open drawer on cart icon click.
            $(document).on('click', '.cart-contents, .ltms-open-cart-drawer', function(e) {
                e.preventDefault();
                LTMS_Drawer.open();
            });
        },

        openAfterAdd: function() {
            this.refresh();
            this.open();
        },

        open: function() {
            $('#ltms-cart-drawer-overlay').show().css('opacity', '0');
            setTimeout(function() {
                $('#ltms-cart-drawer-overlay').css('opacity', '1').addClass('ltms-overlay-show');
                $('#ltms-cart-drawer').addClass('ltms-drawer-open');
            }, 10);
            this.isOpen = true;
            $('body').css('overflow', 'hidden');
        },

        close: function() {
            $('#ltms-cart-drawer').removeClass('ltms-drawer-open');
            $('#ltms-cart-drawer-overlay').css('opacity', '0').removeClass('ltms-overlay-show');
            setTimeout(function() {
                $('#ltms-cart-drawer-overlay').hide();
            }, 300);
            this.isOpen = false;
            $('body').css('overflow', '');
        },

        refresh: function() {
            if (!this.nonce) return;
            $.post(ltmsDrawerData.ajaxUrl, {
                action: 'ltms_refresh_drawer',
                nonce: this.nonce
            }, function(response) {
                if (response.success) {
                    LTMS_Drawer.render(response.data);
                }
            });
        },

        render: function(data) {
            // Count badge.
            $('#ltms-drawer-count').text(data.count > 0 ? '(' + data.count + ')' : '');

            // Shipping bar.
            if (data.shipping_bar && data.shipping_bar.show) {
                var sb = data.shipping_bar;
                var html = '<div style="font-size:12px;color:#16a34a;font-weight:600;margin-bottom:4px;">' + sb.message + '</div>';
                html += '<div class="ltms-drawer-shipping-bar__track"><div class="ltms-drawer-shipping-bar__fill" style="width:' + sb.percentage + '%;"></div></div>';
                $('#ltms-drawer-shipping-bar').html(html);
            } else {
                $('#ltms-drawer-shipping-bar').empty();
            }

            // Items.
            if (data.items && data.items.length > 0) {
                var itemsHtml = '';
                data.items.forEach(function(item) {
                    itemsHtml += '<div class="ltms-drawer-item">' +
                        '<div class="ltms-drawer-item__img">' + item.image + '</div>' +
                        '<div class="ltms-drawer-item__info">' +
                            '<a href="' + item.permalink + '" class="ltms-drawer-item__name">' + item.name + '</a>' +
                            '<div class="ltms-drawer-item__vendor">' + item.vendor_name + '</div>' +
                            '<div class="ltms-drawer-item__price">' + item.subtotal + '</div>' +
                            '<div class="ltms-drawer-item__qty">' +
                                '<button class="ltms-drawer-qty-minus" data-key="' + item.key + '">&minus;</button>' +
                                '<span>' + item.qty + '</span>' +
                                '<button class="ltms-drawer-qty-plus" data-key="' + item.key + '">&plus;</button>' +
                                '<span class="ltms-drawer-item__remove" data-key="' + item.key + '">' + ltmsDrawerData.i18n.remove + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                $('#ltms-drawer-items').html(itemsHtml);
            } else {
                $('#ltms-drawer-items').html(
                    '<div class="ltms-drawer-empty">' +
                        '<div class="ltms-drawer-empty__icon">&#x1F6D2;</div>' +
                        '<p>' + ltmsDrawerData.i18n.empty + '</p>' +
                    '</div>'
                );
            }

            // Upsells — F2: Upsell card premium con precio comparativo + gradiente.
            if (data.upsells && data.upsells.length > 0) {
                var upsellHtml = '<div class="ltms-drawer-upsell-label">&#x1F4E6; ' + ltmsDrawerData.i18n.upsells + '</div>';
                data.upsells.forEach(function(u) {
                    upsellHtml += '<div class="ltms-drawer-upsell-card">' +
                        '<a href="' + u.permalink + '" class="ltms-drawer-upsell-card__img">' + u.image + '</a>' +
                        '<div class="ltms-drawer-upsell-card__content">' +
                            '<a href="' + u.permalink + '" class="ltms-drawer-upsell-card__name">' + u.name + '</a>' +
                            '<div class="ltms-drawer-upsell-card__price">' + u.price + '</div>' +
                        '</div>' +
                        '<div class="ltms-drawer-upsell-card__actions">' +
                            '<a href="' + u.add_to_cart_url + '" class="ltms-drawer-upsell-card__btn">' + ltmsDrawerData.i18n.add + '</a>' +
                        '</div>' +
                    '</div>';
                });
                $('#ltms-drawer-upsells').show().html(upsellHtml);
            } else {
                $('#ltms-drawer-upsells').hide().empty();
            }

            // Footer — F3 (T&C) + F4 (Countdown) + F5 (Checkout note) + F6 (Payment badges).
            if (data.count > 0) {
                var footerHtml = '';

                // F4: Countdown timer de reserva.
                footerHtml += '<div class="ltms-drawer-countdown" id="ltms-drawer-countdown-bar">' +
                    '<span class="ltms-drawer-countdown__icon">&#x23F1;</span> ' +
                    ltmsDrawerData.i18n.reserved + ' <span id="ltms-drawer-timer">05:00</span>' +
                '</div>';

                // Subtotal.
                footerHtml += '<div class="ltms-drawer-subtotal-row">' +
                    '<span>' + ltmsDrawerData.i18n.subtotal + '</span>' +
                    '<strong>' + data.subtotal + '</strong>' +
                '</div>';

                // F3: Checkbox T&C (compliance Ley 1480).
                if (data.tnc_required) {
                    footerHtml += '<div class="ltms-drawer-tnc">' +
                        '<label class="ltms-drawer-tnc__label">' +
                            '<input type="checkbox" id="ltms-drawer-tnc-checkbox" /> ' +
                            '<span>' + ltmsDrawerData.i18n.tncText + ' ' +
                                '<a href="' + (data.tnc_url || '#') + '" target="_blank" rel="noopener">' + ltmsDrawerData.i18n.tncLink + '</a>' +
                            '</span>' +
                        '</label>' +
                        '<div class="ltms-drawer-tnc__warning" id="ltms-drawer-tnc-warning">' + ltmsDrawerData.i18n.tncWarning + '</div>' +
                    '</div>';
                }

                // F5: Checkout note.
                if (data.checkout_note) {
                    footerHtml += '<div class="ltms-drawer-checkout-note">' + data.checkout_note + '</div>';
                }

                // Checkout button.
                var checkoutDisabled = data.tnc_required ? ' ltms-drawer-checkout-disabled' : '';
                footerHtml += '<a href="' + data.checkout_url + '" class="ltms-drawer-checkout-btn' + checkoutDisabled + '" id="ltms-drawer-checkout-btn">' + ltmsDrawerData.i18n.checkout + '</a>';

                // F6: Payment badges.
                if (data.payment_badges && data.payment_badges.length > 0) {
                    footerHtml += '<div class="ltms-drawer-payment-badges">';
                    data.payment_badges.forEach(function(pb) {
                        footerHtml += '<span class="ltms-drawer-payment-badge" title="' + pb.name + '">' + pb.icon + ' ' + pb.name + '</span>';
                    });
                    footerHtml += '</div>';
                }

                // View full cart link.
                footerHtml += '<a href="' + data.cart_url + '" class="ltms-drawer-cart-link">' + ltmsDrawerData.i18n.viewCart + '</a>';

                $('#ltms-drawer-footer').html(footerHtml);

                // F3: Inicializar T&C checkbox handler.
                if (data.tnc_required) {
                    LTMS_Drawer.initTncCheckbox();
                }

                // F4: Inicializar countdown timer.
                LTMS_Drawer.startCountdown();
            } else {
                $('#ltms-drawer-footer').empty();
            }
        },

        removeItem: function(e) {
            e.preventDefault();
            var key = $(e.currentTarget).data('key');
            $.post(ltmsDrawerData.ajaxUrl, {
                action: 'ltms_drawer_remove_item',
                nonce: this.nonce,
                cart_item_key: key
            }, function(response) {
                if (response.success) {
                    LTMS_Drawer.render(response.data);
                    $(document.body).trigger('wc_fragment_refresh');
                }
            });
        },

        decreaseQty: function(e) {
            e.preventDefault();
            var key = $(e.currentTarget).data('key');
            var span = $(e.currentTarget).siblings('span');
            var qty = parseInt(span.text()) - 1;
            if (qty < 1) return;
            this.updateQty(key, qty);
        },

        increaseQty: function(e) {
            e.preventDefault();
            var key = $(e.currentTarget).data('key');
            var span = $(e.currentTarget).siblings('span');
            var qty = parseInt(span.text()) + 1;
            this.updateQty(key, qty);
        },

        updateQty: function(key, qty) {
            $.post(ltmsDrawerData.ajaxUrl, {
                action: 'ltms_drawer_update_qty',
                nonce: this.nonce,
                cart_item_key: key,
                qty: qty
            }, function(response) {
                if (response.success) {
                    LTMS_Drawer.render(response.data);
                    $(document.body).trigger('wc_fragment_refresh');
                }
            });
        },

        // F3: T&C checkbox handler — bloquea checkout si no se acepta.
        initTncCheckbox: function() {
            var $checkbox = $('#ltms-drawer-tnc-checkbox');
            var $btn = $('#ltms-drawer-checkout-btn');
            var $warning = $('#ltms-drawer-tnc-warning');

            $checkbox.on('change', function() {
                if ($(this).is(':checked')) {
                    $btn.removeClass('ltms-drawer-checkout-disabled');
                    $warning.hide();
                } else {
                    $btn.addClass('ltms-drawer-checkout-disabled');
                    $warning.show();
                }
            });

            // Prevenir click en checkout si no acepta T&C.
            $btn.on('click', function(e) {
                if (!$checkbox.is(':checked')) {
                    e.preventDefault();
                    $warning.show();
                    $checkbox.focus();
                    return false;
                }
            });
        },

        // F4: Countdown timer de 5 minutos.
        timerInterval: null,
        timerSeconds: 300,

        startCountdown: function() {
            // Solo iniciar si no hay timer ya corriendo.
            if (this.timerInterval) return;

            // Restaurar tiempo del sessionStorage si existe.
            var stored = sessionStorage.getItem('ltms_drawer_timer');
            if (stored) {
                var elapsed = Math.floor((Date.now() - parseInt(stored)) / 1000);
                this.timerSeconds = Math.max(0, 300 - elapsed);
            } else {
                sessionStorage.setItem('ltms_drawer_timer', Date.now());
                this.timerSeconds = 300;
            }

            if (this.timerSeconds <= 0) {
                this.resetCountdown();
                return;
            }

            this.updateTimerDisplay();

            var self = this;
            this.timerInterval = setInterval(function() {
                self.timerSeconds--;
                self.updateTimerDisplay();

                if (self.timerSeconds <= 0) {
                    clearInterval(self.timerInterval);
                    self.timerInterval = null;
                    sessionStorage.removeItem('ltms_drawer_timer');
                    // Mostrar mensaje de expiración.
                    $('#ltms-drawer-countdown-bar').html(
                        '<span class="ltms-drawer-countdown__icon">&#x26A0;</span> ' +
                        ltmsDrawerData.i18n.reservedExpired
                    ).css('color', '#dc2626').css('background', '#fef2f2');
                }
            }, 1000);
        },

        updateTimerDisplay: function() {
            var min = Math.floor(this.timerSeconds / 60);
            var sec = this.timerSeconds % 60;
            var display = (min < 10 ? '0' : '') + min + ':' + (sec < 10 ? '0' : '') + sec;
            $('#ltms-drawer-timer').text(display);

            // Cambiar color cuando quedan menos de 60s.
            if (this.timerSeconds <= 60) {
                $('#ltms-drawer-countdown-bar').addClass('ltms-drawer-countdown--urgent');
            }
        },

        resetCountdown: function() {
            sessionStorage.removeItem('ltms_drawer_timer');
            this.timerSeconds = 300;
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
        }
    };

    // ============================================================
    // BUNDLE ADD TO CART
    // ============================================================

    $(document).on('click', '.ltms-add-bundle-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var bundleId = $btn.data('bundle-id');
        var nonce = $btn.data('nonce');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(ltmsDrawerData.i18n.adding);

        $.post(ltmsDrawerData.ajaxUrl, {
            action: 'ltms_add_bundle_to_cart',
            bundle_id: bundleId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                $btn.text('&#x2705; ' + response.data.message);
                $(document.body).trigger('wc_fragment_refresh');
                $(document.body).trigger('added_to_cart');
                setTimeout(function() {
                    $btn.prop('disabled', false).text(originalText);
                }, 3000);
            } else {
                $btn.text(response.data.message || ltmsDrawerData.i18n.error);
                setTimeout(function() {
                    $btn.prop('disabled', false).text(originalText);
                }, 3000);
            }
        }).fail(function() {
            $btn.text(ltmsDrawerData.i18n.error);
            setTimeout(function() {
                $btn.prop('disabled', false).text(originalText);
            }, 3000);
        });
    });

    // ============================================================
    // F7: STICKY HEADER CART COUNTER
    // ============================================================

    var LTMS_StickyCart = {

        init: function() {
            // Envolver el icono de carrito existente del theme con badge counter.
            this.wrapCartIcon();

            // Actualizar contador en fragment refresh.
            $(document.body).on('wc_cart_fragment_refreshed wc_fragments_refreshed', this.updateCounter.bind(this));
            $(document.body).on('added_to_cart removed_from_cart', this.bounce.bind(this));

            // Hacer el header sticky si no lo es ya (CSS).
            this.makeHeaderSticky();
        },

        wrapCartIcon: function() {
            // Buscar el icono de carrito del theme (varíos selectores comunes).
            var selectors = [
                '.cart-contents',
                '.header-cart',
                '.site-header-cart a',
                '.wcmenucart',
                'a[href*="cart"] .count',
                '.ltms-cart-trigger'
            ];

            var $cartLink = null;
            for (var i = 0; i < selectors.length; i++) {
                $cartLink = $(selectors[i]).first();
                if ($cartLink.length) break;
            }

            if (!$cartLink || !$cartLink.length) return;

            // Si ya tiene el wrapper, no duplicar.
            if ($cartLink.find('.ltms-sticky-cart-counter__badge').length) return;

            // Envolver y añadir badge.
            $cartLink.addClass('ltms-sticky-cart-counter');
            $cartLink.append('<span class="ltms-sticky-cart-counter__badge" style="display:none;">0</span>');

            this.updateCounter();
        },

        updateCounter: function() {
            var count = 0;
            // Intentar obtener el count de WooCommerce.
            if (typeof wc_cart_fragments_params !== 'undefined') {
                try {
                    var cart_hash = sessionStorage.getItem(wc_cart_fragments_params.cart_hash_key);
                    if (cart_hash) {
                        var parsed = JSON.parse(cart_hash);
                        if (parsed && parsed.cart_contents_count !== undefined) {
                            count = parsed.cart_contents_count;
                        }
                    }
                } catch(e) {}
            }

            // Fallback: leer del fragment HTML.
            if (count === 0) {
                var $fragCount = $('.ltms-drawer-fragments');
                if ($fragCount.length) {
                    count = parseInt($fragCount.attr('data-cart-count') || '0');
                }
            }

            var $badge = $('.ltms-sticky-cart-counter__badge');
            if (count > 0) {
                $badge.text(count > 99 ? '99+' : count).show();
            } else {
                $badge.hide();
            }
        },

        bounce: function() {
            var $badge = $('.ltms-sticky-cart-counter__badge');
            $badge.removeClass('ltms-sticky-cart-counter__badge--bounce');
            setTimeout(function() {
                $badge.addClass('ltms-sticky-cart-counter__badge--bounce');
            }, 50);
        },

        makeHeaderSticky: function() {
            // Detectar si el header ya es sticky (algunos themes lo hacen).
            var $header = $('header.site-header, header#masthead, .site-header, #header').first();
            if (!$header.length) return;

            // Si ya tiene position:fixed/sticky, no interferir.
            var pos = $header.css('position');
            if (pos === 'fixed' || pos === 'sticky') return;

            // Aplicar sticky via clase CSS (no inline para no romper media queries).
            $header.addClass('ltms-sticky-header');
        }
    };

    // ============================================================
    // INIT
    // ============================================================

    $(document).ready(function() {
        LTMS_Drawer.init();
        LTMS_StickyCart.init();
    });

    // ============================================================
    // QUICK VIEW MODAL
    // ============================================================

    var LTMS_QuickView = {

        init: function() {
            // Crear modal container en el body si no existe.
            if ($('#ltms-quick-view-modal').length === 0) {
                $('body').append(
                    '<div id="ltms-quick-view-modal" class="ltms-quick-view-modal">' +
                        '<div class="ltms-quick-view-modal__inner">' +
                            '<button class="ltms-quick-view-modal__close" id="ltms-qv-close">&times;</button>' +
                            '<div id="ltms-qv-content" style="text-align:center;padding:40px;"><p>Cargando...</p></div>' +
                        '</div>' +
                    '</div>'
                );
            }

            // Click en botón quick view.
            $(document).on('click', '.ltms-quick-view-btn', this.open.bind(this));

            // Cerrar modal.
            $('#ltms-qv-close').on('click', this.close.bind(this));
            $('#ltms-quick-view-modal').on('click', function(e) {
                if (e.target === this) LTMS_QuickView.close();
            });

            // ESC para cerrar.
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) LTMS_QuickView.close();
            });
        },

        open: function(e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var productId = $btn.data('product-id');
            var nonce = $btn.data('nonce');

            $('#ltms-qv-content').html('<p style="text-align:center;padding:40px;">Cargando...</p>');
            $('#ltms-quick-view-modal').addClass('ltms-quick-view-modal--open');
            $('body').css('overflow', 'hidden');

            $.post(ltmsDrawerData.ajaxUrl, {
                action: 'ltms_quick_view',
                product_id: productId,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    $('#ltms-qv-content').html(response.data.html);
                } else {
                    $('#ltms-qv-content').html('<p style="text-align:center;padding:40px;color:#dc2626;">' + (response.data.message || 'Error') + '</p>');
                }
            }).fail(function() {
                $('#ltms-qv-content').html('<p style="text-align:center;padding:40px;color:#dc2626;">Error de conexión</p>');
            });
        },

        close: function() {
            $('#ltms-quick-view-modal').removeClass('ltms-quick-view-modal--open');
            $('body').css('overflow', '');
        }
    };

    // ============================================================
    // WISHLIST TOGGLE
    // ============================================================

    $(document).on('click', '.ltms-wishlist-btn, .ltms-wishlist-btn-single', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var productId = $btn.data('product-id');
        var nonce = $btn.data('nonce');

        $.post(ltmsDrawerData.ajaxUrl, {
            action: 'ltms_toggle_wishlist',
            product_id: productId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                var added = response.data.added;

                // Actualizar todos los botones de este producto.
                $('.ltms-wishlist-btn[data-product-id="' + productId + '"], .ltms-wishlist-btn-single[data-product-id="' + productId + '"]').each(function() {
                    var $b = $(this);
                    if (added) {
                        $b.addClass($b.hasClass('ltms-wishlist-btn-single') ? 'ltms-wishlist-btn-single--active' : 'ltms-wishlist-btn--active');
                        $b.find('.ltms-wishlist-btn__icon, .ltms-wishlist-btn-single__icon').html('&#x2764;');
                        $b.find('.ltms-wishlist-btn-single__text').text(ltmsDrawerData.i18n.inWishlist);
                    } else {
                        $b.removeClass($b.hasClass('ltms-wishlist-btn-single') ? 'ltms-wishlist-btn-single--active' : 'ltms-wishlist-btn--active');
                        $b.find('.ltms-wishlist-btn__icon, .ltms-wishlist-btn-single__icon').html('&#x2661;');
                        $b.find('.ltms-wishlist-btn-single__text').text(ltmsDrawerData.i18n.addToWishlist);
                    }
                });

                // Si estamos en la página de wishlist y se quitó, remover el item.
                if (!added && $b.hasClass('ltms-wishlist-remove')) {
                    $b.closest('.ltms-wishlist-item').fadeOut(300, function() { $(this).remove(); });
                }

                // Bounce animation en el badge del header.
                LTMS_StickyCart.bounce();
            }
        });
    });

    // ============================================================
    // MEGA MENU (hover behavior)
    // ============================================================

    $(document).ready(function() {
        // Envolver items de menú con subcategorías en hover mega menu.
        $('.ltms-mega-menu-trigger').each(function() {
            var $trigger = $(this);
            var $menu = $trigger.find('.ltms-mega-menu').first();

            $trigger.on('mouseenter', function() {
                $menu.addClass('ltms-mega-menu--open');
            });

            $trigger.on('mouseleave', function() {
                $menu.removeClass('ltms-mega-menu--open');
            });
        });
    });

    // ============================================================
    // INIT QUICK VIEW
    // ============================================================

    $(document).ready(function() {
        LTMS_QuickView.init();
    });

})(jQuery);
