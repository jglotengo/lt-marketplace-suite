/* LTMS Product Enhancements — JS v2.9.2
 * Cart Drawer + Bundle AJAX + Video controls
 */

(function($) {
    'use strict';

    // ============================================================
    // CART DRAWER — ELIMINADO (E2E-BUYER-LEGACY-DRAWER-DEAD, 2026-11-24).
    // El objeto del drawer legacy (~337 líneas: render/qty/remove/T&C/countdown)
    // operaba sobre markup #ltms-drawer-* que ningún template renderiza desde
    // v2.9.208 (drawer eliminado tras 4 versiones fallidas). Los endpoints
    // ltms_drawer_update_qty/ltms_drawer_remove_item siguen vivos en
    // class-ltms-cart-drawer.php (los consume el drawer nuevo ltms-cart-drawer.js).
    // ============================================================
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
        // El init del drawer legacy se eliminó con el objeto (E2E-BUYER-LEGACY-DRAWER-DEAD).
        LTMS_StickyCart.init();
    });

    // ============================================================
    // QUICK VIEW MODAL — ELIMINADO (E2E-BUYER-QV-BROKEN-BTN, 2026-11-24).
    // El modal legacy jQuery esperaba response.data.html (contrato que el
    // handler estructurado ltms_quick_view nunca devolvió) y posteba un nonce
    // por producto que el handler vivo rechaza con 403 en cada click.
    // El botón .ltms-quick-view-btn ahora emite data-pv-quickview y abre el
    // modal canonical del design system (ltms-plaza-viva.js → ltms_pv_quick_view).
    // ============================================================

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

    // INIT QUICK VIEW — eliminado con el modal legacy (E2E-BUYER-QV-BROKEN-BTN).

})(jQuery);
