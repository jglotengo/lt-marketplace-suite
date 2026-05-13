/**
 * LTMS Header Nav — Botones Seller / Cliente
 * Reemplaza los botones existentes con versión mejorada UX/UI.
 * Compatible con cualquier tema WordPress.
 */
(function($) {
    'use strict';

    // SVG Icons
    var ICONS = {
        seller: '<svg class="ltms-btn-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 7h-4V5c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2zM10 5h4v2h-4V5zm10 15H4V9h16v11z"/><path d="M13 13h-2v-2H9l3-3 3 3h-2z"/></svg>',
        cliente: '<svg class="ltms-btn-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>',
        dashboard: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 13h8V3H3zm0 8h8v-6H3zm10 0h8v-10h-8zm0-18v6h8V3z"/></svg>',
        orders: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>',
        wallet: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
        logout: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>',
        account: '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>'
    };

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(' ');
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return parts[0].slice(0, 2).toUpperCase();
    }

    function buildSellerBtn(url) {
        var isVendor = ltmsHeaderNav.is_vendor;
        if (isVendor) {
            return '<div class="ltms-user-dropdown-wrap">' +
                '<a href="' + ltmsHeaderNav.dashboard_url + '" class="ltms-user-chip">' +
                    '<span class="ltms-avatar-initials">' + getInitials(ltmsHeaderNav.display_name) + '</span>' +
                    '<span class="ltms-chip-name">' + ltmsHeaderNav.display_name + '</span>' +
                '</a>' +
                '<div class="ltms-user-dropdown">' +
                    '<a href="' + ltmsHeaderNav.dashboard_url + '">' + ICONS.dashboard + ' Mi Panel</a>' +
                    '<a href="' + ltmsHeaderNav.orders_url + '">' + ICONS.orders + ' Mis Pedidos</a>' +
                    '<a href="' + ltmsHeaderNav.wallet_url + '">' + ICONS.wallet + ' Mi Billetera</a>' +
                    '<div class="ltms-dropdown-divider"></div>' +
                    '<a href="' + ltmsHeaderNav.logout_url + '">' + ICONS.logout + ' Cerrar Sesión</a>' +
                '</div>' +
            '</div>';
        }
        return '<div class="ltms-nav-btn-wrap">' +
            '<a href="' + url + '" class="ltms-nav-btn ltms-btn-seller">' +
                ICONS.seller +
                '<span class="ltms-btn-label">Vender</span>' +
                '<span class="ltms-badge">GRATIS</span>' +
            '</a>' +
            '<div class="ltms-nav-tooltip">Registra tu tienda y empieza a vender</div>' +
        '</div>';
    }

    function buildClienteBtn(url) {
        var isLoggedIn = ltmsHeaderNav.is_logged_in;
        var isVendor   = ltmsHeaderNav.is_vendor;
        if (isLoggedIn && !isVendor) {
            return '<div class="ltms-user-dropdown-wrap">' +
                '<a href="' + url + '" class="ltms-user-chip">' +
                    '<span class="ltms-avatar-initials">' + getInitials(ltmsHeaderNav.display_name) + '</span>' +
                    '<span class="ltms-chip-name">' + ltmsHeaderNav.display_name + '</span>' +
                '</a>' +
                '<div class="ltms-user-dropdown">' +
                    '<a href="' + url + '">' + ICONS.account + ' Mi Cuenta</a>' +
                    '<a href="' + ltmsHeaderNav.orders_url + '">' + ICONS.orders + ' Mis Pedidos</a>' +
                    '<div class="ltms-dropdown-divider"></div>' +
                    '<a href="' + ltmsHeaderNav.logout_url + '">' + ICONS.logout + ' Cerrar Sesión</a>' +
                '</div>' +
            '</div>';
        }
        if (isVendor) {
            // Vendor ya está cubierto arriba — botón cliente simplificado
            return '';
        }
        return '<div class="ltms-nav-btn-wrap">' +
            '<a href="' + url + '" class="ltms-nav-btn ltms-btn-cliente">' +
                ICONS.cliente +
                '<span class="ltms-btn-label">Mi Cuenta</span>' +
            '</a>' +
            '<div class="ltms-nav-tooltip">Accede o crea tu cuenta de comprador</div>' +
        '</div>';
    }

    function injectButtons() {
        var data = ltmsHeaderNav;

        // Intentar encontrar los botones Seller/Cliente del tema
        var $sellerEl = $('a').filter(function() {
            var href = ($(this).attr('href') || '').toLowerCase();
            var text = $(this).text().trim().toLowerCase();
            return (href.includes('seller') || href.includes('vendors')) ||
                   (text === 'seller' || text === 'vendedor' || text === 'vender');
        }).first();

        var $clienteEl = $('a').filter(function() {
            var href = ($(this).attr('href') || '').toLowerCase();
            var text = $(this).text().trim().toLowerCase();
            return (href.includes('mi-cuenta') || href.includes('my-account')) ||
                   (text === 'cliente' || text === 'mi cuenta' || text === 'cliente');
        }).first();

        var sellerUrl  = data.sellers_url  || '/sellers/';
        var clienteUrl = data.mi_cuenta_url || '/mi-cuenta/';

        var $wrap = $('<div class="ltms-header-access"></div>');
        $wrap.append(buildSellerBtn(sellerUrl));
        var clienteHTML = buildClienteBtn(clienteUrl);
        if (clienteHTML) $wrap.append(clienteHTML);

        if ($sellerEl.length) {
            // Reemplazar botones existentes del tema
            $sellerEl.closest('li, div, span').first().replaceWith($wrap);
            if ($clienteEl.length) {
                $clienteEl.closest('li, div, span').first().remove();
            }
        } else if ($clienteEl.length) {
            $clienteEl.closest('li, div, span').first().replaceWith($wrap);
        } else {
            // Fallback: barra flotante fija en esquina superior derecha
            $('body').append($('<div id="ltms-floating-access"></div>').append($wrap));
        }
    }

    $(document).ready(function() {
        if (typeof ltmsHeaderNav === 'undefined') return;
        injectButtons();
    });

})(jQuery);
