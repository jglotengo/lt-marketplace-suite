/**
 * LTMS Header Nav v2.1.0
 * Fix: detecta Hello Elementor mobile, fallback con barra completa no iconos sueltos.
 * Fix: dropdown no tapado por botón VENDER (z-index correcto en menu items).
 */
(function($) {
    'use strict';

    var ICONS = {
        seller:    '<svg class="ltms-btn-icon" viewBox="0 0 24 24"><path d="M20 7h-4V5c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2zM10 5h4v2h-4V5zm10 15H4V9h16v11z"/><path d="M13 13h-2v-2H9l3-3 3 3h-2z"/></svg>',
        cliente:   '<svg class="ltms-btn-icon" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>',
        dashboard: '<svg viewBox="0 0 24 24"><path d="M3 13h8V3H3zm0 8h8v-6H3zm10 0h8v-10h-8zm0-18v6h8V3z"/></svg>',
        orders:    '<svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>',
        wallet:    '<svg viewBox="0 0 24 24"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
        logout:    '<svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>',
        account:   '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>'
    };

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(' ');
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return parts[0].slice(0, 2).toUpperCase();
    }

    function buildSellerBtn(url) {
        var d = ltmsHeaderNav;
        if (d.is_vendor) {
            return '<div class="ltms-user-dropdown-wrap" id="ltms-vendor-chip-wrap">' +
                '<button class="ltms-user-chip" type="button" aria-haspopup="true" aria-expanded="false">' +
                    '<span class="ltms-avatar-initials">' + getInitials(d.display_name) + '</span>' +
                    '<span class="ltms-chip-name">' + d.display_name + '</span>' +
                    '<svg class="ltms-chip-arrow" viewBox="0 0 24 24" width="12" height="12" style="fill:currentColor;margin-left:2px;transition:transform .2s"><path d="M7 10l5 5 5-5z"/></svg>' +
                '</button>' +
                '<div class="ltms-user-dropdown" role="menu">' +
                    '<a href="' + d.dashboard_url + '">' + ICONS.dashboard + ' Mi Panel</a>' +
                    '<a href="' + d.orders_url + '">' + ICONS.orders + ' Mis Pedidos</a>' +
                    '<a href="' + d.wallet_url + '">' + ICONS.wallet + ' Mi Billetera</a>' +
                    '<div class="ltms-dropdown-divider"></div>' +
                    '<a href="' + d.logout_url + '">' + ICONS.logout + ' Cerrar Sesión</a>' +
                '</div>' +
            '</div>';
        }
        return '<a href="' + url + '" class="ltms-nav-btn ltms-btn-seller">' +
            ICONS.seller + '<span class="ltms-btn-label">Vender</span>' +
            '<span class="ltms-badge">GRATIS</span>' +
        '</a>';
    }

    function buildClienteBtn(url) {
        var d = ltmsHeaderNav;
        if (d.is_vendor) return '';
        if (d.is_logged_in) {
            return '<div class="ltms-user-dropdown-wrap" id="ltms-cliente-chip-wrap">' +
                '<button class="ltms-user-chip" type="button" aria-haspopup="true" aria-expanded="false">' +
                    '<span class="ltms-avatar-initials">' + getInitials(d.display_name) + '</span>' +
                    '<span class="ltms-chip-name">' + d.display_name + '</span>' +
                    '<svg class="ltms-chip-arrow" viewBox="0 0 24 24" width="12" height="12" style="fill:currentColor;margin-left:2px;transition:transform .2s"><path d="M7 10l5 5 5-5z"/></svg>' +
                '</button>' +
                '<div class="ltms-user-dropdown" role="menu">' +
                    '<a href="' + url + '">' + ICONS.account + ' Mi Cuenta</a>' +
                    '<a href="' + d.orders_url + '">' + ICONS.orders + ' Mis Pedidos</a>' +
                    '<div class="ltms-dropdown-divider"></div>' +
                    '<a href="' + d.logout_url + '">' + ICONS.logout + ' Cerrar Sesión</a>' +
                '</div>' +
            '</div>';
        }
        return '<a href="' + url + '" class="ltms-nav-btn ltms-btn-cliente">' +
            ICONS.cliente + '<span class="ltms-btn-label">Mi Cuenta</span>' +
        '</a>';
    }

    function initDropdowns() {
        var $overlay = $('<div id="ltms-dd-overlay"></div>').css({
            position:'fixed', inset:0, zIndex:99998, display:'none'
        }).appendTo('body');

        function closeAll() {
            $('.ltms-user-dropdown-wrap.is-open').removeClass('is-open')
                .find('.ltms-user-chip').attr('aria-expanded','false')
                .find('.ltms-chip-arrow').css('transform','');
            $overlay.hide();
        }

        $overlay.on('click touchstart', function(e) { e.preventDefault(); closeAll(); });

        $(document).on('click touchstart', '.ltms-user-chip', function(e) {
            e.preventDefault(); e.stopPropagation();
            var $wrap = $(this).closest('.ltms-user-dropdown-wrap');
            var wasOpen = $wrap.hasClass('is-open');
            closeAll();
            if (!wasOpen) {
                $wrap.addClass('is-open')
                    .find('.ltms-user-chip').attr('aria-expanded','true')
                    .find('.ltms-chip-arrow').css('transform','rotate(180deg)');
                $overlay.show();
            }
        });

        $(document).on('keydown', function(e) { if (e.key === 'Escape') closeAll(); });
        $(document).on('click touchstart', '.ltms-user-dropdown', function(e) { e.stopPropagation(); });
    }

    function injectButtons() {
        if ($('#ltms-floating-access').length || $('.ltms-header-access').length) return;

        var d          = ltmsHeaderNav;
        var sellerUrl  = d.sellers_url   || '/sellers/';
        var clienteUrl = d.mi_cuenta_url || '/mi-cuenta/';

        // Construir HTML de botones
        var $wrap = $('<div class="ltms-header-access" id="ltms-header-access"></div>');
        $wrap.append(buildSellerBtn(sellerUrl));
        var cHTML = buildClienteBtn(clienteUrl);
        if (cHTML) $wrap.append(cHTML);

        // Buscar elementos existentes del tema
        var $sellerEl = $('a').filter(function() {
            var href = ($(this).attr('href') || '').toLowerCase();
            var text = $(this).text().trim().toLowerCase();
            return href.includes('/sellers') || href.includes('/vender') ||
                   text === 'seller' || text === 'vendedor' || text === 'vender' ||
                   text.includes('vender gratis');
        }).first();

        var $clienteEl = $('a').filter(function() {
            var href = ($(this).attr('href') || '').toLowerCase();
            var text = $(this).text().trim().toLowerCase();
            return (href.includes('mi-cuenta') || href.includes('my-account') ||
                    text === 'mi cuenta' || text === 'my account') &&
                   !$(this).closest('#ltms-header-access, .ltms-header-access, #ltms-hello-access').length;
        }).first();

        // Zonas de header de temas conocidos
        var $headerZone = $(
            '.site-header__actions, .header-actions, .header__right,' +
            '.nav-bar__actions, .header-end, .header-cta, .header__cta,' +
            '.masthead-actions, header .right, .header-tools'
        ).first();

        // Hello Elementor: menú principal
        var $helloMenu = $('.elementor-nav-menu--main > .elementor-nav-menu').first();
        var $helloHeader = $('.site-header').first();

        function wrapInLi(html) {
            return $('<li class="menu-item ltms-menu-item" style="list-style:none;display:flex;align-items:center;"></li>').append(html);
        }

        if ($sellerEl.length && $sellerEl.is(':visible')) {
            // Reemplazar SOLO si visible (no oculto en burger menu)
            var $liSeller = $sellerEl.closest('li.menu-item').length
                ? $sellerEl.closest('li.menu-item')
                : $sellerEl.closest('li, .menu-item').first();

            if ($liSeller.length) {
                $liSeller.replaceWith(wrapInLi(
                    $('<div class="ltms-header-access ltms-header-access--seller"></div>').append(buildSellerBtn(sellerUrl))
                ));
            } else {
                $sellerEl.replaceWith(
                    $('<div class="ltms-header-access ltms-header-access--seller"></div>').append(buildSellerBtn(sellerUrl))
                );
            }

            if ($clienteEl.length && $clienteEl.is(':visible')) {
                var $liCliente = $clienteEl.closest('li.menu-item').length
                    ? $clienteEl.closest('li.menu-item')
                    : $clienteEl.closest('li, .menu-item').first();
                var $cWrap = $('<div class="ltms-header-access ltms-header-access--cliente"></div>').append(buildClienteBtn(clienteUrl));
                if ($liCliente.length) $liCliente.replaceWith(wrapInLi($cWrap));
                else $clienteEl.replaceWith($cWrap);
            }

        } else if ($helloMenu.length) {
            // Hello Elementor sin seller — agregar al final del menú
            $helloMenu.append(wrapInLi($wrap));

        } else if ($clienteEl.length) {
            var $liC2 = $clienteEl.closest('li.menu-item').length
                ? $clienteEl.closest('li.menu-item')
                : $clienteEl.closest('li, .menu-item').first();
            if ($liC2.length) $liC2.replaceWith(wrapInLi($wrap));
            else $clienteEl.replaceWith($wrap);

        } else if ($headerZone.length) {
            $headerZone.append($wrap);

        } else if ($helloHeader.length) {
            // Hello Elementor sin menú visible — insertar dentro del .site-header
            $helloHeader.append($('<div id="ltms-hello-access"></div>').append($wrap));

        } else {
            // Fallback: barra superior completa con contexto visual
            $('body').append(
                $('<div id="ltms-floating-access" role="navigation" aria-label="Acceso vendedor/cuenta"></div>').append($wrap)
            );
        }

        initDropdowns();
    }

    $(document).ready(function() {
        if (typeof ltmsHeaderNav === 'undefined') return;
        injectButtons();
    });

    // En móvil, si los botones quedaron dentro del menú colapsado (ancho restringido),
    // moverlos al .site-header directamente.
    $(window).on('load resize', function() {
        if (typeof ltmsHeaderNav === 'undefined') return;
        var $btn = $('.ltms-nav-btn, .ltms-header-access--seller, .ltms-header-access--cliente').first();
        if (!$btn.length) return;
        var w = window.innerWidth || document.documentElement.clientWidth;
        if (w <= 768) {
            // Si el botón no es visible o tiene ancho ≤ 40px (solo ícono), moverlo al header
            var btnRect = $btn[0].getBoundingClientRect();
            if (btnRect.width <= 42 || btnRect.height === 0) {
                // Mover todos los botones LTMS al site-header como bloque flotante
                if (!$('#ltms-hello-access').length) {
                    var $access = $('.ltms-header-access, .ltms-header-access--seller, .ltms-header-access--cliente');
                    var $container = $('<div id="ltms-hello-access"></div>');
                    var $combined = $('<div class="ltms-header-access"></div>');
                    $combined.append(buildSellerBtn(ltmsHeaderNav.sellers_url));
                    var cHTML = buildClienteBtn(ltmsHeaderNav.mi_cuenta_url);
                    if (cHTML) $combined.append(cHTML);
                    $container.append($combined);
                    $access.each(function(){ $(this).closest('li.ltms-menu-item, li').hide(); });
                    $('.site-header').first().append($container);
                    initDropdowns();
                }
            }
        }
    });

})(jQuery);
