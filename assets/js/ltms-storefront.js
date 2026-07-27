/**
 * LTMS Vendor Storefront — interacciones
 * v2.9.270 — UX Audit Sprint 1+2+3 improvements
 * @since 2.9.2
 */
(function ($) {
        'use strict';

        // ── AJAX helper ──
        function sfAjax(action, data, cb) {
                data = data || {};
                data.action = action;
                data.nonce = (typeof ltmsSF !== 'undefined') ? ltmsSF.nonce : '';
                $.post((typeof ltmsSF !== 'undefined') ? ltmsSF.ajaxUrl : '/wp-admin/admin-ajax.php', data, cb);
        }

        /* ── Feedback botones "Agregar al carrito" ── */
        $(document).on('click', '.ltms-sf-add-to-cart.ajax_add_to_cart', function () {
                $(this).removeClass('added').addClass('loading');
        });
        $(document.body).on('added_to_cart', function (event, fragments, hash, $btn) {
                if ($btn && $btn.hasClass('ltms-sf-add-to-cart')) {
                        $btn.removeClass('loading').addClass('added');
                        setTimeout(function () { $btn.removeClass('added'); }, 2200);
                }
        });

        /* ── Navegar al producto al hacer clic en imagen ── */
        $(document).on('click.ltms-card-nav', '.ltms-sf-card-img-link', function (e) {
                var href = $(this).attr('href');
                if (href && href !== '#') {
                        e.stopImmediatePropagation();
                        window.location.href = href;
                }
        });

        /* ── Collapsible filtros del sidebar ── */
        $(document).on('click', '.ltms-sf-filter-heading', function () {
                var $btn  = $(this);
                var $body = $btn.next('.ltms-sf-filter-body');
                var expanded = $btn.attr('aria-expanded') === 'true';
                $btn.attr('aria-expanded', String(!expanded));
                $body.toggleClass('is-collapsed', expanded);
        });

        /* ── Toggle sidebar en mobile ── */
        $(document).on('click', '#ltms-sf-sidebar-toggle', function () {
                $('#ltms-sf-sidebar').toggleClass('is-open');
        });

        /* ── Reactivar imágenes lazy al volver con botón Atrás (bfcache) ── */
        window.addEventListener('pageshow', function (e) {
                if (!e.persisted) return;
                document.querySelectorAll('.ltms-sf-img-main').forEach(function (img) {
                        if (img.naturalWidth === 0) { var s = img.src; img.src = ''; img.src = s; }
                });
        });

        /* ── Navegación por filtros (CSP compliance) ── */
        $(document).on('change', '[data-ltms-nav-url]', function () {
                var url = $(this).attr('data-ltms-nav-url');
                if (url) { window.location.href = url; }
        });
        $(document).on('change', 'select[data-ltms-nav-select]', function () {
                var url = $(this).val();
                if (url) { window.location.href = url; }
        });

        // ════════════════════════════════════════════════════════════
        // UX AUDIT IMPROVEMENTS v2.9.270
        // ════════════════════════════════════════════════════════════

        /* ── P1-2: Quick View modal ── */
        $(document).on('click', '.ltms-sf-action-quickview', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var productId = $(this).data('product-id');
                if (!productId) return;

                var $overlay = $('#ltms-sf-qv-overlay');
                var $content = $('#ltms-sf-qv-content');
                $content.html('<div style="padding:40px;text-align:center;color:#6B7280;">Cargando...</div>');
                $overlay.addClass('is-open').attr('aria-hidden', 'false');
                $('body').css('overflow', 'hidden');

                sfAjax('ltms_sf_quick_view', { product_id: productId }, function (res) {
                        if (res.success) {
                                var p = res.data;
                                var ratingHtml = '';
                                if (p.rating > 0) {
                                        var stars = '★★★★★'.split('').map(function(s, i) {
                                                return i < Math.round(p.rating) ? '<span style="color:#FBBF24">★</span>' : '<span style="color:#D1D5DB">★</span>';
                                        }).join('');
                                        ratingHtml = '<div style="margin-bottom:8px">' + ratingHtml + ' <span style="color:#6B7280;font-size:13px">(' + p.reviews + ' reseñas)</span></div>';
                                }
                                var stockHtml = p.in_stock
                                        ? '<span style="color:#15803D;font-size:13px;font-weight:600">✓ En stock</span>'
                                        : '<span style="color:#6B7280;font-size:13px;font-weight:600">Agotado</span>';
                                $content.html(
                                        '<div class="ltms-sf-qv-img">' +
                                                (p.image ? '<img src="' + p.image + '" alt="' + p.name + '">' : '📦') +
                                        '</div>' +
                                        '<div class="ltms-sf-qv-body">' +
                                                '<h2 class="ltms-sf-qv-name">' + p.name + '</h2>' +
                                                ratingHtml +
                                                stockHtml +
                                                '<div class="ltms-sf-qv-price">' + p.price + '</div>' +
                                                (p.description ? '<p class="ltms-sf-qv-desc">' + p.description + '</p>' : '') +
                                                '<a href="' + p.permalink + '" class="ltms-sf-qv-cta">' + p.cart_text + '</a>' +
                                        '</div>'
                                );
                        } else {
                                $content.html('<div style="padding:40px;text-align:center;color:#E80001;">Error al cargar el producto</div>');
                        }
                });
        });

        $(document).on('click', '#ltms-sf-qv-close, #ltms-sf-qv-overlay', function (e) {
                if (e.target === this) {
                        $('#ltms-sf-qv-overlay').removeClass('is-open').attr('aria-hidden', 'true');
                        $('body').css('overflow', '');
                }
        });
        $(document).on('keydown', function (e) {
                if (e.key === 'Escape') {
                        $('#ltms-sf-qv-overlay').removeClass('is-open').attr('aria-hidden', 'true');
                        $('body').css('overflow', '');
                }
        });

        /* ── P2-2: Wishlist toggle (AJAX + localStorage for guests) ── */
        function getWishlist() {
                try { return JSON.parse(localStorage.getItem('ltms-sf-wishlist') || '[]'); }
                catch (e) { return []; }
        }
        function setWishlist(arr) {
                try { localStorage.setItem('ltms-sf-wishlist', JSON.stringify(arr)); } catch (e) {}
                $('#ltms-sf-wishlist-count').text(arr.length);
        }
        function initWishlist() {
                var wl = getWishlist();
                $('#ltms-sf-wishlist-count').text(wl.length);
                wl.forEach(function (pid) {
                        $('.ltms-sf-action-wishlist[data-product-id="' + pid + '"]').addClass('is-active');
                });
        }
        $(document).on('click', '.ltms-sf-action-wishlist', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!productId) return;

                var wl = getWishlist();
                var idx = wl.indexOf(productId);

                if (idx > -1) {
                        wl.splice(idx, 1);
                        $btn.removeClass('is-active');
                } else {
                        wl.push(productId);
                        $btn.addClass('is-active');
                }
                setWishlist(wl);

                // Sync con server si usuario logueado
                sfAjax('ltms_sf_toggle_wishlist', { product_id: productId }, function () {});
        });

        /* ── P2-2: Topbar wishlist button — show panel with saved products ── */
        $(document).on('click', '#ltms-sf-topbar-wishlist-btn', function (e) {
                e.preventDefault();
                var wl = getWishlist();
                if (wl.length === 0) {
                        alert('Tu lista de deseos está vacía.\n\nPara agregar productos, haz clic en el icono ♥ de cualquier tarjeta de producto.');
                        return;
                }
                // Show a simple panel with saved product IDs
                var html = '<div class="ltms-sf-qv-overlay is-open" id="ltms-sf-wishlist-panel" style="display:flex">' +
                        '<div class="ltms-sf-qv-modal" style="max-width:500px">' +
                        '<button type="button" class="ltms-sf-qv-close" onclick="jQuery(\'#ltms-sf-wishlist-panel\').remove();jQuery(\'body\').css(\'overflow\',\'\')">&times;</button>' +
                        '<div class="ltms-sf-qv-body" style="grid-column:1/-1">' +
                        '<h2 class="ltms-sf-qv-name">Mi lista de deseos (' + wl.length + ')</h2>' +
                        '<p style="color:#6B7280;font-size:13px">Tienes ' + wl.length + ' producto(s) guardado(s).</p>' +
                        '<div id="ltms-sf-wishlist-items">Cargando...</div>' +
                        '</div></div></div>';
                $('body').append(html).css('overflow', 'hidden');

                // Load product data for each wishlist item via AJAX
                var loaded = 0;
                var itemsHtml = '';
                wl.forEach(function (pid) {
                        sfAjax('ltms_sf_quick_view', { product_id: pid }, function (res) {
                                loaded++;
                                if (res.success) {
                                        var p = res.data;
                                        itemsHtml += '<div style="display:flex;gap:10px;padding:10px;border-bottom:1px solid #F3F4F6">' +
                                                (p.image ? '<img src="' + p.image + '" alt="" style="width:50px;height:50px;object-fit:cover;border-radius:6px">' : '') +
                                                '<div style="flex:1"><div style="font-weight:600;font-size:13px">' + p.name + '</div>' +
                                                '<div style="color:#E80001;font-weight:700;font-size:13px">' + p.price + '</div></div>' +
                                                '<a href="' + p.permalink + '" style="padding:6px 12px;background:#E80001;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none">Ver</a>' +
                                                '</div>';
                                }
                                if (loaded === wl.length) {
                                        $('#ltms-sf-wishlist-items').html(itemsHtml || '<p>No se pudieron cargar los productos.</p>');
                                }
                        });
                });
        });

        /* ── P1-4: Sticky header compact on scroll ── */
        var $topbar = $('.ltms-sf-topbar');
        var lastScroll = 0;
        $(window).on('scroll', function () {
                var scroll = $(window).scrollTop();
                if (scroll > 200) {
                        $topbar.addClass('is-compact');
                } else {
                        $topbar.removeClass('is-compact');
                }
                lastScroll = scroll;
        });

        /* ── P2-1: Autocomplete search (debounced AJAX) ── */
        var acTimer = null;
        $(document).on('input', '.ltms-sf-search-input', function () {
                var $input = $(this);
                var q = $input.val().trim();
                var $form = $input.closest('.ltms-sf-search-form');

                // Remove existing autocomplete
                $('.ltms-sf-autocomplete').remove();

                if (q.length < 2) return;
                if (typeof ltmsSF === 'undefined' || !ltmsSF.vendorId) return;

                clearTimeout(acTimer);
                acTimer = setTimeout(function () {
                        sfAjax('ltms_sf_autocomplete', { q: q, vendor_id: ltmsSF.vendorId }, function (res) {
                                $('.ltms-sf-autocomplete').remove();
                                if (!res.success || !res.data.products || res.data.products.length === 0) return;

                                var html = '<div class="ltms-sf-autocomplete is-open">';
                                html += '<div class="ltms-sf-ac-section">Productos sugeridos</div>';
                                res.data.products.forEach(function (p) {
                                        html += '<a href="' + p.url + '" class="ltms-sf-ac-item">' +
                                                (p.image ? '<img class="ltms-sf-ac-img" src="' + p.image + '" alt="">' : '<div class="ltms-sf-ac-img" style="background:#F3F4F6;display:flex;align-items:center;justify-content:center">📦</div>') +
                                                '<div class="ltms-sf-ac-info"><div class="ltms-sf-ac-name">' + p.name + '</div>' +
                                                '<div class="ltms-sf-ac-price">' + p.price + '</div></div></a>';
                                });
                                html += '</div>';
                                $form.append(html);
                        });
                }, 300);
        });
        $(document).on('click', function (e) {
                if (!$(e.target).closest('.ltms-sf-search-form').length) {
                        $('.ltms-sf-autocomplete').remove();
                }
        });

        /* ── P2-3: Load more button (AJAX append) ── */
        $(document).on('click', '#ltms-sf-load-more', function () {
                var $btn = $(this);
                var $wrap = $('#ltms-sf-load-more-wrap');
                var nextPage = parseInt($wrap.data('paged')) + 1;
                var pages = parseInt($wrap.data('pages'));

                if (nextPage > pages) {
                        $wrap.hide();
                        return;
                }

                $btn.addClass('is-loading').text('Cargando...');

                sfAjax('ltms_sf_load_more', {
                        vendor_id: $wrap.data('vendor-id'),
                        paged: nextPage,
                        cat: $wrap.data('cat'),
                        order: $wrap.data('order'),
                        s: $wrap.data('s'),
                        instock: $wrap.data('instock'),
                        view: $wrap.data('view')
                }, function (res) {
                        $btn.removeClass('is-loading').text('Cargar más productos');
                        if (res.success) {
                                $('.ltms-sf-grid').append(res.data.html);
                                $wrap.data('paged', nextPage);
                                if (!res.data.has_more) {
                                        $wrap.hide();
                                }
                        } else {
                                $btn.text('Error. Intenta de nuevo');
                        }
                });
        });

        /* ── P1-1: Price filter apply ── */
        $(document).on('click', '#ltms-sf-price-apply', function () {
                var minPrice = $('input[name="min_price"]').val();
                var maxPrice = $('input[name="max_price"]').val();
                var url = window.location.href.split('?')[0];
                var params = new URLSearchParams(window.location.search);

                if (minPrice) params.set('min_price', minPrice); else params.delete('min_price');
                if (maxPrice) params.set('max_price', maxPrice); else params.delete('max_price');

                params.delete('pg'); // Reset pagination
                var queryString = params.toString();
                window.location.href = url + (queryString ? '?' + queryString : '');
        });

        /* ── P1-7: Contact vendor modal ── */
        $(document).on('click', '#ltms-sf-contact-vendor-btn', function (e) {
                e.preventDefault();
                var vendorId = $(this).data('vendor-id');

                var modalHtml =
                        '<div class="ltms-sf-qv-overlay is-open" id="ltms-sf-contact-overlay" style="display:flex">' +
                        '<div class="ltms-sf-qv-modal" style="max-width:500px">' +
                        '<button type="button" class="ltms-sf-qv-close" onclick="jQuery(\'#ltms-sf-contact-overlay\').remove();jQuery(\'body\').css(\'overflow\',\'\')">&times;</button>' +
                        '<div class="ltms-sf-qv-body" style="grid-column:1/-1">' +
                        '<h2 class="ltms-sf-qv-name">Contactar vendedor</h2>' +
                        '<form id="ltms-sf-contact-form">' +
                        '<div style="margin-bottom:12px"><input type="text" name="name" placeholder="Tu nombre" required style="width:100%;padding:10px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px"></div>' +
                        '<div style="margin-bottom:12px"><input type="email" name="email" placeholder="Tu email" required style="width:100%;padding:10px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px"></div>' +
                        '<div style="margin-bottom:12px"><textarea name="message" placeholder="Tu mensaje" rows="4" required style="width:100%;padding:10px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px"></textarea></div>' +
                        '<button type="submit" class="ltms-sf-qv-cta" style="width:100%">Enviar mensaje</button>' +
                        '</form>' +
                        '</div></div></div>';

                $('body').append(modalHtml).css('overflow', 'hidden');

                $(document).off('submit', '#ltms-sf-contact-form').on('submit', '#ltms-sf-contact-form', function (e) {
                        e.preventDefault();
                        var $form = $(this);
                        var $btn = $form.find('button[type="submit"]');
                        $btn.text('Enviando...').prop('disabled', true);

                        sfAjax('ltms_sf_contact_vendor', {
                                vendor_id: vendorId,
                                name: $form.find('input[name="name"]').val(),
                                email: $form.find('input[name="email"]').val(),
                                message: $form.find('textarea[name="message"]').val()
                        }, function (res) {
                                if (res.success) {
                                        $form.html('<div style="text-align:center;padding:20px;color:#15803D;font-size:16px;font-weight:600">✓ ' + res.data.message + '</div>');
                                        setTimeout(function () {
                                                $('#ltms-sf-contact-overlay').remove();
                                                $('body').css('overflow', '');
                                        }, 2000);
                                } else {
                                        $btn.text('Enviar mensaje').prop('disabled', false);
                                        alert(res.data || 'Error al enviar el mensaje');
                                }
                        });
                });
        });

        /* ── P2-5: Sticky add-to-cart bar mobile ── */
        var $stickyCart = $('#ltms-sf-sticky-cart');
        var stickyLastScroll = 0;
        $(window).on('scroll', function () {
                var scroll = $(window).scrollTop();
                var isMobile = $(window).width() <= 767;

                // Show sticky cart when scrolled past products and scrolling up
                if (isMobile && scroll > 600 && scroll < stickyLastScroll) {
                        // Get last hovered/visible product
                        var $cards = $('.ltms-sf-card:visible');
                        if ($cards.length > 0) {
                                // Find card in viewport
                                var $visibleCard = null;
                                $cards.each(function () {
                                        var rect = this.getBoundingClientRect();
                                        if (rect.top > 0 && rect.top < window.innerHeight / 2) {
                                                $visibleCard = $(this);
                                                return false;
                                        }
                                });
                                if ($visibleCard) {
                                        var name = $visibleCard.find('.ltms-sf-card-name').text().trim();
                                        var price = $visibleCard.find('.ltms-sf-card-price').text().trim();
                                        var cartUrl = $visibleCard.find('.ltms-sf-add-to-cart').attr('href');
                                        $('#ltms-sf-sticky-cart-name').text(name);
                                        $('#ltms-sf-sticky-cart-price').text(price);
                                        $('#ltms-sf-sticky-cart-btn').attr('href', cartUrl || '#');
                                        $stickyCart.addClass('is-visible').show();
                                }
                        }
                } else {
                        $stickyCart.removeClass('is-visible').hide();
                }
                stickyLastScroll = scroll;
        });

        /* ── P0-4: Modal priority — delay newsletter & notifications ── */
        function delayModals() {
                // Hide newsletter overlay for 30s or until first scroll
                var $newsletter = $('.ltms-newsletter-overlay');
                if ($newsletter.length && !sessionStorage.getItem('ltms-nl-shown')) {
                        $newsletter.removeClass('visible').css('display', 'none');
                        sessionStorage.setItem('ltms-nl-shown', '1');

                        setTimeout(function () {
                                if (!$('.ltms-cookie-consent:visible').length) {
                                        $newsletter.addClass('visible').css('display', '');
                                } else {
                                        // Wait for cookie to close first
                                        $(document).on('click', '.ltms-cookie-consent .close, .ltms-cookie-consent button', function () {
                                                setTimeout(function () { $newsletter.addClass('visible').css('display', ''); }, 500);
                                        });
                                }
                        }, 30000);
                }

                // Delay push notification toast
                var $notif = $('.ltms-push-notification, [class*="notification-toast"]');
                if ($notif.length && !sessionStorage.getItem('ltms-push-shown')) {
                        $notif.hide();
                        sessionStorage.setItem('ltms-push-shown', '1');
                        setTimeout(function () { $notif.show(); }, 15000);
                }
        }

        /* ── Init on DOM ready ── */
        $(document).ready(function () {
                initWishlist();
                delayModals();
        });

})(jQuery);
