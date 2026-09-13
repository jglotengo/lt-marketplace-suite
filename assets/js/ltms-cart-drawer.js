/* =============================================================================
 * LTMS Cart Drawer — JS autónomo (vanilla + XHR, sin dependencia de jQuery).
 *
 * CART-UX-NEXT (2026-09-10): reimplementación robusta del mini-cart. Usa
 * XMLHttpRequest (no fetch) porque mod_security de SiteGround ha soltado
 * requests fetch() POST; NO inyecta script inline (el HTML lo renderiza PHP en
 * wp_footer y la config viaja por wp_localize_script). Abre/actualiza el drawer
 * al añadir al carrito (evento added_to_cart de WC + data-pv-add-to-cart) y
 * desde el icono del carrito (Elementor menu-cart + topbar). Ver
 * includes/frontend/class-ltms-cart-drawer.php.
 * ========================================================================== */
(function () {
    'use strict';

    var cfg = window.ltmsCartDrawer || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce = cfg.nonce || '';
    var cartUrl = cfg.cartUrl || '';
    var checkoutUrl = cfg.checkoutUrl || '';

    var overlay = null;
    var drawer = null;
    var body = null;

    function $(sel) { return document.querySelector(sel); }
    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ajax(params, onDone, onFail) {
        if (!ajaxUrl) { if (onFail) onFail(); return; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.withCredentials = true;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status >= 200 && xhr.status < 300) {
                var json = null;
                try { json = JSON.parse(xhr.responseText); } catch (e) {}
                if (json && json.success) { if (onDone) onDone(json.data || {}); }
                else { if (onFail) onFail(); }
            } else { if (onFail) onFail(); }
        };
        xhr.onerror = function () { if (onFail) onFail(); };
        var form = new URLSearchParams();
        form.append('nonce', nonce);
        for (var k in params) { form.append(k, params[k]); }
        xhr.send(form.toString());
    }

    function renderItems(items) {
        if (!body) return;
        if (!items || !items.length) {
            body.innerHTML = '<div class="ltms-minicart__empty">' +
                '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>' +
                '<div>Tu carrito está vacío</div></div>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var key = esc(it.key);
            var img = it.image ? '<img src="' + esc(it.image) + '" alt="' + esc(it.name) + '">' : '';
            var meta = it.variation ? '<p class="ltms-minicart__item-meta">' + esc(it.variation) + '</p>' : '';
            html += '<div class="ltms-minicart__item" data-cart-item-key="' + key + '">' +
                '<div class="ltms-minicart__item-img">' + img + '</div>' +
                '<div class="ltms-minicart__item-info">' +
                '<a class="ltms-minicart__item-name" href="' + esc(it.product_url || '#') + '">' + esc(it.name) + '</a>' +
                meta +
                '<div class="ltms-minicart__item-line">' +
                '<span class="ltms-minicart__item-price">' + esc(it.price_formatted || '') + '</span>' +
                '<span class="ltms-minicart__qty">' +
                '<button type="button" data-ltms-qty="-1" data-key="' + key + '" aria-label="Disminuir">&minus;</button>' +
                '<span>' + (it.quantity || 1) + '</span>' +
                '<button type="button" data-ltms-qty="1" data-key="' + key + '" aria-label="Aumentar">+</button>' +
                '</span>' +
                '</div>' +
                '</div>' +
                '<button type="button" class="ltms-minicart__item-remove" data-ltms-remove="' + key + '" aria-label="Eliminar">&times;</button>' +
                '</div>';
        }
        body.innerHTML = html;
    }

    // CART-UX-NEXT-UPSells (2026-09-11): render de upsells (mismo vendor) en el
    // drawer. Se piden solo al abrir (full=1); qty/remove refrescan sin upsells.
    function renderUpsells(upsells) {
        var host = $('.ltms-minicart__upsells');
        if (!host) return;
        var list = host.querySelector('.ltms-minicart__upsells-list');
        if (!list) return;
        if (!upsells || !upsells.length) {
            host.setAttribute('hidden', '');
            list.innerHTML = '';
            return;
        }
        host.removeAttribute('hidden');
        var html = '';
        for (var i = 0; i < upsells.length; i++) {
            var u = upsells[i];
            var img = u.image ? '<img src="' + esc(u.image) + '" alt="' + esc(u.name) + '" loading="lazy">' : '';
            html += '<a class="ltms-minicart__upsell" href="' + esc(u.permalink || '#') + '">' +
                '<span class="ltms-minicart__upsell-img">' + img + '</span>' +
                '<span class="ltms-minicart__upsell-name">' + esc(u.name) + '</span>' +
                '<span class="ltms-minicart__upsell-price">' + esc(u.price || '') + '</span>' +
                '</a>';
        }
        list.innerHTML = html;
    }

    function renderCart(data) {
        renderItems(data.items || []);
        renderUpsells(data.upsells);
        var countEl = $('.ltms-minicart__count');
        if (countEl) countEl.textContent = data.count != null ? String(data.count) : '';
        var totalEl = $('.ltms-minicart__subtotal-value');
        if (totalEl) totalEl.textContent = data.total_formatted || '';
        var checkoutBtn = $('.ltms-minicart__checkout');
        if (checkoutBtn) checkoutBtn.setAttribute('href', data.checkout_url || checkoutUrl);
        var continueBtn = $('.ltms-minicart__continue');
        if (continueBtn) continueBtn.setAttribute('href', data.cart_url || cartUrl);
        if (data.count != null) {
            var counters = document.querySelectorAll('.ltms-sf-cart-count, .ltms-cart-count, .cart-count, [data-cart-count]');
            for (var j = 0; j < counters.length; j++) counters[j].textContent = String(data.count);
        }
    }

    function refresh(full) {
        if (!body) return;
        var params = { action: 'ltms_get_cart' };
        if (full) params.full = '1';
        ajax(params, renderCart);
    }

    // CART-COUNT-SYNC FIX (2026-09-11): tras eliminar/cambiar cantidad, además
    // de refrescar el drawer también disparamos el refresh de fragmentos de WC
    // para que el contador del carrito del header (Elementor menu-cart, topbar,
    // etc.) se actualice. Sin esto, el valor "al lado del carrito" quedaba viejo.
    function refreshWcFragments() {
        if (window.jQuery && window.jQuery(document.body)) {
            window.jQuery(document.body).trigger('wc_fragment_refresh');
        }
    }

    function open() {
        if (!overlay || !drawer) return;
        overlay.classList.add('is-open');
        drawer.classList.add('is-open');
        document.body.classList.add('ltms-minicart-locked');
    }

    function close() {
        if (!overlay || !drawer) return;
        overlay.classList.remove('is-open');
        drawer.classList.remove('is-open');
        document.body.classList.remove('ltms-minicart-locked');
    }

    var openTimer = null;
    function openAndRefresh() {
        open();
        refresh(true);
        // Re-refresh a los 500ms para capturar el item recién agregado por el
        // path PV (data-pv-add-to-cart) cuyo AJAX puede aún estar en vuelo.
        if (openTimer) clearTimeout(openTimer);
        openTimer = setTimeout(function () { refresh(true); }, 500);
    }

    function cacheDom() {
        overlay = $('.ltms-minicart-overlay');
        drawer = $('.ltms-minicart');
        body = $('.ltms-minicart__body');
    }

    // Selectores de "icono del carrito" que deben abrir el drawer (no navegar):
    //  - [data-ltms-open-cart] : opt-in explícito (topbar del storefront, etc.)
    //  - .ltms-sf-topbar-cart  : icono del storefront LTMS
    //  - .elementor-menu-cart__toggle : icono del menú-cart de Elementor (header)
    function isCartIcon(el) {
        if (!el || typeof el.closest !== 'function') return null;
        return el.closest('[data-ltms-open-cart], .ltms-sf-topbar-cart, .elementor-menu-cart__toggle');
    }

    function bind() {
        // Bubble: acciones internas del drawer (qty / remove / close).
        document.addEventListener('click', function (e) {
            var el = e.target;
            if (!el || typeof el.closest !== 'function') return;

            var qtyBtn = el.closest('[data-ltms-qty]');
            if (qtyBtn) {
                e.preventDefault();
                var key = qtyBtn.getAttribute('data-key');
                var itemEl = qtyBtn.closest('[data-cart-item-key]');
                var span = itemEl ? itemEl.querySelector('.ltms-minicart__qty span') : null;
                var cur = span ? parseInt(span.textContent, 10) || 1 : 1;
                var next = Math.max(1, cur + parseInt(qtyBtn.getAttribute('data-ltms-qty'), 10));
                if (span) span.textContent = next;
                ajax({ action: 'ltms_drawer_update_qty', cart_item_key: key, qty: String(next) }, function () { refresh(false); refreshWcFragments(); }, function () { refresh(false); });
                return;
            }

            var removeBtn = el.closest('[data-ltms-remove]');
            if (removeBtn) {
                e.preventDefault();
                ajax({ action: 'ltms_drawer_remove_item', cart_item_key: removeBtn.getAttribute('data-ltms-remove') }, function () { refresh(false); refreshWcFragments(); }, function () { refresh(false); });
                return;
            }

            if (el.closest('.ltms-minicart__close') || el.closest('.ltms-minicart-overlay')) {
                close();
                return;
            }
        });

        // Capture: icono del carrito -> abrir drawer (y frenar el panel de cart
        // nativo de Elementor para que solo se vea el nuestro).
        document.addEventListener('click', function (e) {
            var icon = isCartIcon(e.target);
            if (!icon) return;
            e.preventDefault();
            e.stopPropagation();
            openAndRefresh();
        }, true);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });

        // Path 1: WC native add-to-cart (PDP form submit + archive ajax_add_to_cart)
        document.addEventListener('added_to_cart', openAndRefresh);
        if (window.jQuery && window.jQuery(document.body)) {
            window.jQuery(document.body).on('added_to_cart', openAndRefresh);
        }

        // Path 2: LTMS design system (buttons data-pv-add-to-cart)
        document.addEventListener('click', function (e) {
            var el = e.target;
            if (!el || typeof el.closest !== 'function') return;
            if (el.closest('[data-pv-add-to-cart]')) openAndRefresh();
        }, true);
    }

    function init() {
        cacheDom();
        // DIAG (temporal): ver si el drawer se cachea y bindea.
        console.log('[ltms-cart-drawer] init. overlay=', !!overlay, 'drawer=', !!drawer, 'body=', !!body);
        if (!overlay || !drawer) return;
        bind();
        console.log('[ltms-cart-drawer] bound ok');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();