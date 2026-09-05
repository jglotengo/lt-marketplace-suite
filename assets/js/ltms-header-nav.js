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
        account:   '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>',
        products:  '<svg viewBox="0 0 24 24"><path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm6 16H6V8h2v2c0 .55.45 1 1 1s1-.45 1-1V8h4v2c0 .55.45 1 1 1s1-.45 1-1V8h2v12z"/></svg>',
        settings:  '<svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>',
        kyc:       '<svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>'
    };

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(' ');
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return parts[0].slice(0, 2).toUpperCase();
    }

    function buildSellerBtn(url) {
        // HEADER-NAV-FIX (2026-09-04): el boton VENDER siempre visible para TODOS
        // (antes, para vendors logueados se reemplazaba por el chip -> el usuario
        // reportaba "se oculto el boton vender"). El chip de cuenta va aparte via
        // buildClienteBtn() cuando hay sesion.
        return '<a href="' + url + '" class="ltms-nav-btn ltms-btn-seller">' +
            ICONS.seller + '<span class="ltms-btn-label">Vender</span>' +
            '<span class="ltms-badge">GRATIS</span>' +
        '</a>';
    }

    function buildClienteBtn(url) {
        var d = ltmsHeaderNav;
        // HEADER-NAV-FIX: si hay sesion (vendor O cliente), el boton "Mi Cuenta"
        // se convierte en el chip de cuenta con menu. El menu incluye los enlaces
        // del rol (vendor: panel/pedidos/billetera/productos/config/kyc; cliente:
        // cuenta/pedidos). Todos con URL de ltmsHeaderNav o fallback del panel.
        if (d.is_logged_in) {
            var links = '';
            if (d.is_vendor) {
                links += '<a href="' + (d.dashboard_url || '/panel-vendedor/') + '">' + ICONS.dashboard + ' Mi Panel</a>' +
                    '<a href="' + (d.orders_url || '/mis-pedidos/') + '">' + ICONS.orders + ' Mis Pedidos</a>' +
                    '<a href="' + (d.wallet_url || '/mi-billetera/') + '">' + ICONS.wallet + ' Mi Billetera</a>' +
                    '<a href="' + (d.products_url || '/panel-vendedor/?view=products') + '">' + ICONS.products + ' Mis Productos</a>' +
                    '<a href="' + (d.settings_url || '/panel-vendedor/?view=settings') + '">' + ICONS.settings + ' Configuración</a>' +
                    '<a href="' + (d.kyc_url || '/panel-vendedor/?view=kyc') + '">' + ICONS.kyc + ' Verificación KYC</a>';
            } else {
                links += '<a href="' + (url || '/mi-cuenta/') + '">' + ICONS.account + ' Mi Cuenta</a>' +
                    '<a href="' + (d.orders_url || '/mis-pedidos/') + '">' + ICONS.orders + ' Mis Pedidos</a>';
            }
            return '<div class="ltms-user-dropdown-wrap">' +
                '<button class="ltms-user-chip" type="button" aria-haspopup="true" aria-expanded="false">' +
                    '<span class="ltms-avatar-initials">' + getInitials(d.display_name) + '</span>' +
                    '<span class="ltms-chip-name">' + d.display_name + '</span>' +
                    '<svg class="ltms-chip-arrow" viewBox="0 0 24 24" width="12" height="12" style="fill:currentColor;margin-left:2px;transition:transform .2s"><path d="M7 10l5 5 5-5z"/></svg>' +
                '</button>' +
                '<div class="ltms-user-dropdown" role="menu">' + links +
                    '<div class="ltms-dropdown-divider"></div>' +
                    '<a href="' + (d.logout_url || '/wp-login.php?action=logout') + '" class="ltms-dropdown-logout">' + ICONS.logout + ' Cerrar Sesión</a>' +
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

        $overlay.on('click', function(e) { e.preventDefault(); closeAll(); });

        // v2.9.283 FIX: solo 'click' (no 'touchstart') para evitar double-fire
        // en mobile que cerraba el dropdown inmediatamente.
        $(document).on('click', '.ltms-user-chip', function(e) {
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
        $(document).on('click', '.ltms-user-dropdown', function(e) { e.stopPropagation(); });
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
